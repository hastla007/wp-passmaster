# State machines

This document describes the persisted states of orders, payments, tickets, refunds, check-ins,
reservations (holds), seat claims, box office sales and the background queues, which transitions
the code allows, which it refuses, and how duplicate, late and out-of-order events are handled.
It is derived from the code (`src/Orders.php`, `src/Payments/Webhooks.php`, `src/Refunds.php`,
`src/Checkin.php`, `src/Admission/Engine.php`, `src/Seating/Reservations.php`,
`src/BoxOffice/Service.php`, `src/Jobs.php`, `src/Communications.php`, `src/Webhooks/Dispatcher.php`).

The transition tables are enforced by tests:

- `tests/integration/modules/zzzz_w6_state_machines.php` – named out-of-order scenarios and a seeded
  property test that runs random event sequences (paid, duplicate paid, failed, refunded, partial
  refund event, cancel, expiry, manual confirmation, check-in, refund, undo, provider refund) and
  checks every observed transition against the tables below plus the invariants at the end.
- `tests/integration/modules/zzzz_w6_faults.php` – every statement of the critical flows fails once
  (error, deadlock, lock wait timeout, lost connection): no success without persisted state, no
  half state, retries converge.
- `tests/integration/modules/zzzz_w6_workers.php` – queue claims, crashes, backoff and dead letters.

General rules:

- **Every state change is a conditional update under a row lock** (`SELECT … FOR UPDATE` on the
  order/ticket/refund/sale row, or `UPDATE … WHERE status=<expected>`), so concurrent requests and
  redelivered events cannot apply a transition twice.
- **Database errors never change the state.** A failed statement, deadlock (MariaDB rolls the whole
  transaction back), lock wait timeout or lost connection rolls back and returns a retryable error
  (`wpcap_db_busy`, `wpcap_checkin_retry`, `wpcap_issue_failed`, `wpcap_refund_db`, `wpcap_pos_busy`,
  HTTP 503; payment webhooks answer 5xx so the provider retries). Reads that decide a transition
  are checked as well: a failed read is never treated as "not found", "no policy" or "no entries".
- **Side effects run after the commit** (mails, `wpcap_*` hooks, outgoing webhooks, audit entries).

## Order and payment

An order has a `status` and a `payment_status`; they always change together, so the pair is one
state:

| Code | `status` | `payment_status` | Meaning |
|---|---|---|---|
| P | `pending_payment` | `pending` | Created, waiting for payment; active holds reserve capacity. |
| C | `complete` | `paid` | Paid; tickets issued (or issuance pending, see below). Free orders start here. |
| E | `expired` | `expired` | Hold lapsed without payment (cron `Orders::expire_holds()`). |
| N | `needs_review` | `paid_unallocated` | Paid after the hold lapsed and no capacity was left. No tickets; admin alerted. |
| X | `cancelled` | `cancelled` | Payment failed / cancelled; holds released, valid tickets revoked. |
| R | `refunded` | `refunded` | Fully refunded; holds released, valid tickets revoked. |

Allowed transitions:

| From | To | Trigger |
|---|---|---|
| P | C | `paid` webhook, `confirm_external_payment()` (WooCommerce, box office), `confirm_manual_payment()` |
| P | E | expiry sweep (only while still `pending` and without an active hold – conditional UPDATE) |
| P | X | `failed` webhook of the current payment attempt, `cancel_order()` (admin, WooCommerce), full `refunded` webhook (refund or chargeback) for the never-paid order – e.g. out of order **before** the `paid` webhook (`cancelled_unpaid`, audit `provider_refund_unpaid_cancelled`) |
| P | R | only a direct `mark_refunded()` call (never a provider webhook: an unpaid order has no refund ledger entry) |
| P, E | N | late payment whose capacity re-check or buyer-limit re-check (`Sales\BuyerLimits`) under locks fails |
| E | C | late payment / manual confirmation with free capacity (late recovery under locks) |
| E | X | `cancel_order()`; full `refunded` webhook for the never-paid order (`cancelled_unpaid`) |
| E | R | only a direct `mark_refunded()` call |
| N | C | payment confirmed again after capacity was freed |
| N | X, R | admin cancels / refunds the unallocated payment |
| C | R | full refund (native refund of the last tickets, provider dashboard refund, WooCommerce full refund) |
| C | X | `cancel_order()` of a paid order (e.g. WooCommerce cancellation); valid tickets revoked |
| X | R | provider refund of a cancelled order's payment |

Forbidden (refused or ignored, state unchanged):

- Anything out of **R** – refunded is final (`cancel_order()` and `mark_refunded()` are no-ops,
  `confirm_*` return `wpcap_order_state`, a `paid` webhook answers `paid_for_closed_order` and alerts
  the admin).
- **X → C/P/E/N**: a payment for a cancelled order issues nothing (`paid_for_closed_order`, admin alert).
- **C → P/E/N**: the expiry sweep only touches `pending` orders; a `failed` webhook after payment is
  `ignored`; a second `paid` webhook is `already_paid` (same attempt) or `double_payment` (other
  attempt, admin alert to refund it).
- Partial refunds never change the order state; they raise `refunded_minor` (≤ `total_minor`).

Duplicate and out-of-order payment events (`Payments\Webhooks::process()`):

| Situation | Answer | Effect |
|---|---|---|
| Same provider event id again, already processed | `duplicate` (2xx) | nothing |
| Same event id while the first delivery is still `processing` (running, or crashed and its claim could not be released) | retryable 5xx `wpcap_webhook_in_progress` | nothing; after 10 minutes the claim is taken over |
| Database error while claiming / looking up the order | retryable 5xx | claim released |
| `paid` with wrong amount or currency | `amount_mismatch` | nothing issued, admin alert |
| `paid` after `refunded`/`failed` | `paid_for_closed_order` | nothing issued, admin alert |
| `paid` for an order already paid, same attempt | `already_paid` | missing tickets issued if an issuance failed, else nothing |
| `paid` for an order already paid, other attempt | `double_payment` | admin alert |
| `failed` for a non-pending order or an older attempt | `ignored` / `stale_attempt` | nothing |
| `refunded` with amount < remaining total | `partial_refund_logged` | audit only (also for unpaid orders) |
| full `refunded` for an order that was never paid (P or E) | `cancelled_unpaid` | order cancelled (X) under the row lock, holds released, no refund record, `refunded_minor` unchanged, audit `provider_refund_unpaid_cancelled`; a payment booked in the meantime is treated as a paid order (→ R) |
| Booked payment, ticket issuance failed | retryable 5xx | redelivery (`already_paid`) or the `ticket_issue` job issues the tickets |

Ticket issuance after a payment is idempotent (order row locked, only missing tickets are created,
all inserts in one transaction). If the issuance fails, a `ticket_issue` job retries it; if even
that job cannot be queued (connection lost), `Orders::reconcile_issuance()` (job queue cron) issues
the missing tickets of paid orders. A paid order therefore always has either all or none of its
tickets, never some.

## Reservation (hold)

| From | To | Trigger |
|---|---|---|
| `active` | `converted` | payment confirmed |
| `active` | `released` | cancellation, refund |
| `active` | `expired` | expiry sweep, late payment without capacity (N) |
| `expired`, `released` | `converted` | late recovery of the payment |

Only orders in state P have active holds. `converted` is final.

## Ticket

| From | To | Trigger |
|---|---|---|
| (new) | `valid` | issuance (only for paid orders) |
| `valid` | `checked_in` | single-entry check-in (`Checkin::perform()`) |
| `valid` | `revoked` | cancellation, refund of the ticket, event/occurrence cancellation, admin revoke |
| `checked_in` | `valid` | explicit undo of a check-in by staff – **only** while the ticket is not refunded and the order is still C |

Forbidden: `revoked` → anything; `checked_in` → `revoked` (a refunded or cancelled checked-in ticket
keeps `checked_in` as history and gets `refunded=1`); `refunded` 1 → 0. A refunded ticket is never
`valid`. Code rotation (`resend_tickets()`, `reissue_ticket()`) and transfers only apply to
`valid` tickets and do not change the status.

Tickets with a repeatable admission policy (`daily`, `multi_day`, `multi_entry`, `reentry`) stay
`valid`; their entries live in `wpcap_admission_entries` / `wpcap_admission_state`.

## Check-in

Each scan writes one `wpcap_checkins` row with a `result`. `checked_in` (and `checked_out`) are
admissions; everything else is a refusal that changes nothing:

| Result | Meaning |
|---|---|
| `checked_in` | admitted (ticket `valid` → `checked_in`, or a new admission entry) |
| `already_checked_in`, `revoked` | ticket not valid any more |
| `not_valid_today`, `daily_limit_reached`, `entry_limit_reached`, `outside_entry_window`, `already_inside` | admission policy refused |
| `checked_out` / `not_checked_in` | check-out / refused check-out |
| `undone` | staff undid the last admission |

Rules:

- The ticket row is locked for the whole decision; the admission log row, the admission entry/state
  and the ticket status are written in one transaction and every write is checked.
- **Idempotency:** a scan with the same `idempotency_key` (per user) replays the first result
  (`replayed: true`) instead of counting again. Double scans with different keys are refused
  (`already_checked_in`).
- For one ticket, `checked_in` minus `undone` rows is 1 while the ticket is `checked_in`, else 0.
- Offline uploads (`/app/scan/sync`): a refusal of a scan the device already admitted is recorded
  as an admission conflict. A transient server error is returned with `retryable: true`; the scanner
  keeps such scans and uploads them again (they are not marked synced).

## Refund

| From | To | Trigger |
|---|---|---|
| (new) | `pending_approval` | request above the approval threshold by a user without approval capability |
| (new) | `approved` | request below the threshold or by an approver |
| `pending_approval` | `approved` → … | approval by a different user |
| `pending_approval` | `rejected` | rejection |
| `approved`, `failed` | `processing` | durable claim (conditional single-row update, committed) before any money moves |
| `processing` | `failed` | provider refused (nothing paid out) or the order changed meanwhile (`execution_conflict`) |
| `processing` (with provider reference) | `completed` | booking: tickets revoked/flagged, order item and order totals updated – one transaction |
| (new) | `completed` | external refunds (WooCommerce, provider) via `record_external()` – row and booking in one transaction |

- A refund with a provider reference that is still `processing` was paid out but not booked; it is
  only ever booked (`Refunds::reconcile()`, cron), never paid out again.
- `processing` without provider reference after a provider call (the payout note could not be
  stored) stays bound for a manual check and is never retried automatically.
- A read error during validation keeps the refund `approved` (it is not a conflict); retrying the
  same request (`idempotency_key`) continues an `approved` refund.
- `rejected`, `completed` are final; a `failed` refund can be executed again.

## Seat claim

`held` (with the order's hold expiry) → `sold` (payment) → `released` (expiry, cancellation, refund,
ticket revoked). A claim whose order is not paid and whose hold lapsed is released by the stale
sweep; after a late payment the previous seats are re-claimed if still free. A failed seat map
lookup aborts the order (`db_busy`) instead of creating it without seats.

## Box office sale

`pending` (form key claimed) → `completed` (order paid, all tickets issued, receipt number
reserved under the session lock) → `storno_pending` → `voided`, or `storno_pending` → `completed`
(storno rejected). `pending` → `failed` only when the cash session was closed meanwhile (the order
is then cancelled). A transient database error leaves the sale `pending`; resubmitting the same form
key or "Nachbuchen" resumes it (also a claim whose order creation failed). Receipt numbers come from
a checked sequence update, so a number is never handed out twice.

## Queues (jobs, e-mails, outgoing webhooks)

| Queue | States | Max attempts |
|---|---|---|
| `wpcap_jobs` | `queued` → `running` → `complete` / `queued` (backoff 2^n min, max 1 day) / `dead_letter` | 8 |
| `wpcap_message_queue` | `queued` → `sending` → `sent` / `queued` (backoff 2^n min, max 1 h) / `failed` | 5 |
| `wpcap_webhook_deliveries` | `pending` → `delivering` → `succeeded` / `pending` (backoff 60 s·2^(n-1), max 6 h) / `failed`; `cancelled` | 8 (filter `wpcap_webhooks_max_attempts`) |

- The side effect (handler, mail, HTTP request) only runs after a **durable claim**: transaction
  started, exactly this row moved to the processing state with the attempt counted, commit succeeded.
- A worker that dies in the handler releases its claim on shutdown; a hard-killed worker's claim
  expires after 15 minutes. Both count as an attempt: once the attempts are used up the item goes to
  `dead_letter` / `failed` instead of looping forever.
- Enqueueing/emitting is idempotent (unique idempotency key / event id per subscription).

## Invariants (checked after every step of the property test)

1. A paid order (C) has all its tickets; tickets only exist for orders that were paid.
2. Cancelled (X) and refunded (R) orders have no `valid` tickets.
3. Only pending orders (P) have active holds.
4. A refunded ticket is never `valid`; `refunded` never goes back to 0.
5. Effective admissions (`checked_in` − `undone`) of a single-entry ticket are 1 when it is
   `checked_in`, else 0.
6. `refunded_minor` ≤ `total_minor`, and equals the sum of `completed` refunds (except when a
   provider-dashboard refund marked the order R without a refund ledger entry).
