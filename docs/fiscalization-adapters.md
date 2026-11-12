# Fiscalization adapters

This document describes the first implementation layer for seller-aware fiscalization in WP Pass Captain. It is infrastructure for AT, DE, and HU; it is not a certified fiscal product and does not include a production provider adapter.

## Model

- A legal seller is separate from an organizer/partner account.
- A ticket type maps to exactly one legal seller and one seller-of-record mode: `organizer_seller` or `platform_seller`.
- A mixed cart may contain ticket types belonging to different sellers. During the existing `wpcap_order_creating` transaction, the module stores an immutable seller/profile snapshot per mapped ticket type and freezes one fiscal document with its lines per seller where fiscalization applies.
- Each document gets a stable SHA-256 idempotency key based on the Pass Captain order and seller. Gross, tax, currency, seller identity, seller mode, and the payment channel/method are snapshotted; later profile edits do not change an existing document.
- Ticket issue is gated by `wpcap_order_fiscal_ready`. Mapped orders do not issue tickets until every seller document has status `submitted`. An explicit reviewed `not_required` profile is supported; without any seller mapping, existing sales keep their previous behavior. A missing or unsupported active profile blocks the mapped order before payment.
- Failed calls retry with exponential delays, capped at eight automatic attempts. The admin-only retry endpoint can restart terminal failures.

## Provider adapter contract

Implement `PassCaptain\\Fiscalization\\AdapterInterface` and register the implementation with the `wpcap_fiscal_adapters` filter. The adapter must:

1. Return a stable lowercase provider ID from `id()`.
2. Declare the supported country codes from `countries()`.
3. Implement `submit($document, $seller, $configuration)`.
4. Use `$document['idempotency_key']` for every remote submission and reconcile ambiguous timeouts with the provider before attempting another create.
5. Return an array with a non-empty stable `reference` on success, or a `WP_Error` on failure. Printable QR/signature/receipt fields belong in that result and should be rendered only by a later provider-aware ticket/receipt view.
6. Keep API secrets out of the general configuration table. Load credentials from adapter-owned secret storage, constants, environment, or another protected credential store.

No vendor is selected by the core. fiskaly, efsta, a local certified device connector, or a customer-specific adapter can be added independently, subject to that provider's country support and legal approval.

## Admin REST endpoints

All endpoints require an authenticated user with `manage_options`:

- `GET /wp-json/wp-pass-captain/v1/fiscal/sellers`
- `POST /wp-json/wp-pass-captain/v1/fiscal/sellers` with `legal_name`, `country_code` (`AT`, `DE`, or `HU`), and seller tax/address details
- `POST /wp-json/wp-pass-captain/v1/fiscal/sellers/{seller_id}/profiles` with the seller/channel scope, environment, status, reviewed rule-set/version, and legal reference
- `PUT /wp-json/wp-pass-captain/v1/fiscal/sellers/{seller_id}/provider` with `provider_id`, `enabled`, and non-secret `configuration`
- `PUT /wp-json/wp-pass-captain/v1/fiscal/ticket-types/{ticket_type_id}/seller` with `seller_id` and `seller_mode`
- `POST /wp-json/wp-pass-captain/v1/fiscal/orders/{order_id}/retry` to retry terminal failures

Example seller modes are `organizer_seller` (organizer is seller and invoice issuer) and `platform_seller` (central operating company is seller and issuer). The selected entity must be a legally correct seller for the transaction. Profile activation records the reviewer, reference, and profile version. `not_required` must be an explicit reviewed decision; the system does not infer an exemption from the country alone.

## Operational behavior

The POS accepts an optional `payment_method` (`cash`, `card`, `voucher`, or `other`) and snapshots it for adapters. WooCommerce snapshots its configured payment gateway ID. The POS response adds `tickets_issued` and `ticket_issuance_pending`. WooCommerce receives an order note when payment is accepted but the fiscal adapter has not yet completed and tickets are still withheld. Operators must monitor these orders and resolve terminal failures with the retry endpoint after confirming the provider's status.

## Remaining implementation before production

- Build country-specific adapters and secure provider credential setup; the registry has no built-in provider.
- Improve the initial admin screen with seller editing/deactivation, provider credential setup through protected secret storage, and richer order-level fiscal diagnostics. Current configuration supports seller creation, provider selection/configuration, ticket-type mapping, and retrying terminal failures.
- Add provider-aware QR/signature data to ticket/receipt templates and the required archive/export flows.
- Implement partial refunds, credit notes, cancellation/void protocols, operator/day-end exports, and HU NAV reporting; a full-order refund is not yet translated into a provider credit note.
- Define and validate accounting allocations for mixed ticket/non-ticket WooCommerce carts, discounts, fees, and centrally collected organizer proceeds.
- Run the repository's PHP, integration, concurrency, security, and country acceptance checks before deploying or enabling a live adapter.

The national legal requirements and provider certification must be validated for each exact seller, sales channel, payment method, and deployment. This adapter layer does not make those determinations.
