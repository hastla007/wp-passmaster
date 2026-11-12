# Fiscalization adapters

This document describes the first implementation layer for seller-aware fiscalization in WP Pass Captain. It is infrastructure for AT, DE, and HU; it is not a certified fiscal product and does not include a production provider adapter.

## Model

- A legal seller is separate from an organizer/partner account.
- A ticket type maps to exactly one legal seller and one seller-of-record mode: `organizer_seller` or `platform_seller`.
- A mixed cart may contain ticket types belonging to different sellers. During the existing `wpcap_order_creating` transaction, the module stores an immutable seller/profile snapshot per mapped ticket type and freezes one fiscal document with its lines per seller where fiscalization applies.
- Each document gets a stable SHA-256 idempotency key based on the Pass Captain order and seller. Gross, tax, currency, seller identity, seller mode, and the payment channel/method are snapshotted; later profile edits do not change an existing document.
- Ticket issue is gated by `wpcap_order_fiscal_ready`. Mapped orders do not issue tickets until every seller document has status `submitted`. An explicit reviewed `not_required` profile is supported; without any seller mapping, existing sales keep their previous behavior. A missing or unsupported active profile blocks the mapped order before payment.
- Failed calls retry with exponential delays, capped at eight automatic attempts. An append-only event table records submission, status lookup, and reconciliation checkpoints without storing raw provider response bodies. The admin screen can reconcile ambiguous operations and restart terminal failures.

## Provider adapter contract

Implement `PassCaptain\\Fiscalization\\AdapterInterface` (contract version 2) and register it with the `wpcap_fiscal_adapters` filter. An adapter reports country codes and machine-readable capabilities, implements a non-mutating health check and an idempotency-key status lookup, and submits normalized receipts. Every adapter must declare the core capabilities `receipt_submit` and `operation_status`; profiles may require more. The adapter must:

1. Return a stable lowercase provider ID from `id()`.
2. Declare the supported country codes from `countries()`.
3. Implement `submit($document, $seller, $configuration)`.
4. Use `$document['idempotency_key']` for every remote submission. On retries the core calls `query_status()` first; only an explicit `not_found` permits another submit. An unknown status blocks ticket issuance and submission.
5. Return an array with a non-empty stable `reference` on success, or a `WP_Error` on failure. Printable QR/signature/receipt fields belong in that result and should be rendered only by a later provider-aware ticket/receipt view.
6. Keep API secrets out of the general configuration table. Load credentials from adapter-owned secret storage, constants, environment, or another protected credential store.
7. The core resolves external credentials at call time through `wpcap_fiscal_credentials` and adds the result as `$configuration['credentials']`. The filter receives `( $credentials, $seller_id, $provider_id )`; return only the requested seller/provider's secrets. Values are never stored or returned by the core. Adapters must not log them.

No vendor is selected by the core. fiskaly, efsta, a local certified device connector, or a customer-specific adapter can be added independently, subject to that provider's country support and legal approval.

## Admin REST endpoints

All endpoints require an authenticated user with `manage_options`:

- `GET /wp-json/wp-pass-captain/v1/fiscal/sellers`
- `POST /wp-json/wp-pass-captain/v1/fiscal/sellers` with `legal_name`, `country_code` (`AT`, `DE`, or `HU`), and seller tax/address details
- `POST /wp-json/wp-pass-captain/v1/fiscal/sellers/{seller_id}/profiles` with the seller/channel scope, optional `location_id` and `register_id`, environment, status, reviewed rule-set/version, legal reference, and additional required capability keys
- `PUT /wp-json/wp-pass-captain/v1/fiscal/sellers/{seller_id}/provider` with `provider_id`, `enabled`, and non-secret `configuration`
- `POST /wp-json/wp-pass-captain/v1/fiscal/sellers/{seller_id}/health-check` runs a provider health check without creating a receipt
- `PUT /wp-json/wp-pass-captain/v1/fiscal/ticket-types/{ticket_type_id}/seller` with `seller_id` and `seller_mode`
- `POST /wp-json/wp-pass-captain/v1/fiscal/documents/{document_id}/reconcile` queries an ambiguous provider result
- `POST /wp-json/wp-pass-captain/v1/fiscal/orders/{order_id}/retry` to retry terminal failures

Example seller modes are `organizer_seller` (organizer is seller and invoice issuer) and `platform_seller` (central operating company is seller and issuer). The selected entity must be a legally correct seller for the transaction. Profile activation records the reviewer, reference, and profile version. `not_required` must be an explicit reviewed decision; the system does not infer an exemption from the country alone.

## Operational behavior

The profile selector uses exact `location_id` and `register_id` values. A trusted deployment or POS integration can provide them through `wpcap_fiscal_profile_scope( $scope, $channel, $internal, $order_id )`; customer request data is not used to choose a fiscal profile. For POS sales the default scope comes from the active cash session's `location_id` and `register_id`; other channels default to location `0` and an empty register ID unless an integration supplies a scope. An unmatched scope blocks mapped sales rather than falling back to a broader profile. Administrators can manage location/register pairs from the box-office settings using one `location_id|register_id|display_name` row per terminal. Once the directory contains entries, the cashier selects a listed register; the server validates the pair on session creation and on every sale. Removing a register blocks new sales from an open session using that register, while still allowing the cashier to close it. Empty configuration preserves existing installations' manual IDs until the directory is enabled. Existing sessions keep their frozen scope, and removing a register does not rewrite past session records.

The POS accepts an optional `payment_method` (`cash`, `card`, `voucher`, or `other`) and snapshots it for adapters. WooCommerce snapshots its configured payment gateway ID. The POS response adds `tickets_issued` and `ticket_issuance_pending`. WooCommerce receives an order note when payment is accepted but the fiscal adapter has not yet completed and tickets are still withheld. Operators must monitor these orders and resolve terminal failures with the retry endpoint after confirming the provider's status. Permanent configuration/capability failures stop retrying immediately. Terminal failures are written to the audit log, emit `wpcap_fiscal_terminal_failure`, and send a notification to the WordPress admin email; notifications contain identifiers and error codes, not provider response bodies or credentials.

## Remaining implementation before production

- Build country-specific adapters. The versioned registry has no built-in provider; credentials can be resolved by a trusted site integration through `wpcap_fiscal_credentials` (for example, from environment variables or a hosting secret manager). The admin can run a non-mutating adapter health check and reconcile ambiguous submissions by idempotency key.
- Improve the initial admin screen with seller editing/deactivation, clear credential-availability status, and richer order-level fiscal diagnostics. The core deliberately does not persist credentials; site integrations supply them through a protected secret store and `wpcap_fiscal_credentials`.
- Complete provider-aware QR/signature output for all receipt paths and country-required archive/export flows. The first renderer shows normalized receipt fields and a separate fiscal QR on POS receipts and WooCommerce order details; a bounded normalized CSV export is available for operations, but it is not an AT, DE, or HU authority format. QR is omitted from the existing POS storno receipt until a fiscal correction exists.
- Implement partial refunds, credit notes, cancellation/void protocols, operator/day-end exports, and HU NAV reporting; a full-order refund is not yet translated into a provider credit note.
- Define and validate accounting allocations for mixed ticket/non-ticket WooCommerce carts, discounts, fees, and centrally collected organizer proceeds.
- Add a concrete provider sandbox adapter and run the repository's PHP, integration, concurrency, security, and country acceptance checks before deploying or enabling a live adapter.

The national legal requirements and provider certification must be validated for each exact seller, sales channel, payment method, and deployment. This adapter layer does not make those determinations.
