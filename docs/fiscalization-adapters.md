# Fiscalization adapters

This document describes the seller-aware fiscalization layer and the built-in selectable provider adapters. It is not a certified fiscal product. Provider credentials, seller account setup, country mappings, deployment topology, sandbox acceptance, and legal approval are still required before production.

## Model

- A legal seller is separate from an organizer/partner account.
- A ticket type maps to exactly one legal seller and one seller-of-record mode: `organizer_seller` or `platform_seller`.
- A mixed cart may contain ticket types belonging to different sellers. During the existing `wpcap_order_creating` transaction, the module stores an immutable seller/profile snapshot per mapped ticket type and freezes one fiscal document with its lines per seller where fiscalization applies.
- Each document gets a stable SHA-256 idempotency key based on the Pass Captain order and seller. Gross, tax, currency, seller identity, seller mode, payment channel/method, provider ID, and non-secret provider configuration are snapshotted. Retries and manual reconciliation use this original configuration even if the seller later switches providers; credentials are resolved separately at call time. Later profile edits do not change an existing document.
- Ticket issue is gated by `wpcap_order_fiscal_ready`. Mapped orders do not issue tickets until every seller document has status `submitted`. An explicit reviewed `not_required` profile is supported; without any seller mapping, existing sales keep their previous behavior. A missing or unsupported active profile blocks the mapped order before payment.
- Failed calls retry with exponential delays, capped at eight automatic attempts. An append-only event table records submission, status lookup, and reconciliation checkpoints without storing raw provider response bodies. The admin screen can reconcile ambiguous operations and restart terminal failures.

## Provider adapter contract

Implement `PassCaptain\\Fiscalization\\AdapterInterface` (contract version 2) and register it with the `wpcap_fiscal_adapters` filter. An adapter reports country codes and machine-readable capabilities, implements a non-mutating health check and an idempotency-key status lookup, and submits normalized receipts. Every adapter must declare the core capabilities `receipt_submit` and `operation_status`; profiles may require more. The adapter must:

1. Return a stable lowercase provider ID from `id()`.
2. Declare the supported country codes from `countries()`.
3. Implement `submit($document, $seller, $configuration)`.
4. Use `$document['idempotency_key']` for every remote submission. On retries the core calls `query_status()` first; only an explicit `not_found` permits another submit. An unknown status blocks ticket issuance and submission.
5. Return an array with a non-empty stable `reference` on success, or a `WP_Error` on failure. Printable QR/signature/receipt fields belong in that result and should be rendered only by a later provider-aware ticket/receipt view.
6. Keep API secrets out of the general configuration table. The core supports an admin-managed encrypted credential vault per seller/provider. It uses AES-256-GCM and derives its encryption key from the WordPress `auth` and `secure_auth` salts. Credential values are never redisplayed, returned through REST, or written to the audit log. Salt rotation makes existing entries undecryptable; administrators must enter the credentials again. Back up and protect `wp-config.php` accordingly.
7. The core also resolves optional external credentials at call time through `wpcap_fiscal_credentials` and adds them as `$configuration['credentials']`. The filter receives `( $credentials, $seller_id, $provider_id )`; return only the requested seller/provider's secrets. Filter-provided values override the same vault keys. Adapters must not log credentials or include them in provider error messages.

The core never selects a vendor automatically. Each legal seller can select its own provider and non-secret configuration; credentials are held separately in the encrypted vault.

## Built-in provider adapters

| Adapter ID | Countries declared by this adapter | Required setup |
| --- | --- | --- |
| `fiskaly_sign_at` | AT | SIGN AT account credentials, cash register ID, reviewed receipt schema |
| `fiskaly_sign_de` | DE | SIGN DE credentials, TSS/client IDs, VAT-rate and payment-type mappings |
| `fiskaly_sign_unified` | FR, IT, PT, SE | Unified API credentials, system/scope IDs, country VAT and payment mappings |
| `efsta_efr` | AT, BE, CZ, DE, DK, ES, FR, HR, HU, IT, LT, NO, PL, PT, SE, SI, SK | Reachable EFR endpoint, register/terminal IDs, tax-group and payment-group mappings |
| `fiskaltrust_pos` | AT, BE, DE, ES, FR, GR, IT, PT | Cashbox ID/access token, POS system ID, receipt case, charge-item and payment mappings |

These country lists describe adapter routing declarations only. They do not mean every deployment, sales channel, ticket format, refund path, or legal regime is supported or certified. In particular, this implementation does not claim fiskaltrust coverage for HU, nor fiskaly SIGN ES. The customer chooses among adapters that declare the seller's country; unsupported combinations remain blocked.

The efsta and fiskaltrust adapters call an endpoint configured by the site operator. That endpoint must be reachable from the WordPress host and use HTTPS; plain HTTP is permitted only for localhost/loopback. A local EFR/FT service therefore needs a secure reachable endpoint or a local loopback arrangement supported by the hosting topology. No local daemon is installed by this plugin.

fiskaly has multiple API families, not one interchangeable endpoint: SIGN AT and SIGN DE are separate adapters; FR/IT/PT/SE use the Unified API adapter. For Unified API ambiguous retries, the adapter only repeats creation calls within a conservative 20-hour window; older operations require manual provider-dashboard reconciliation to avoid duplicate fiscal records.

Each adapter currently supports receipt submission and status recovery to the extent declared in its capabilities. Country-specific correction/void and refund credit-note workflows, authority exports, provider certification, customer sandbox acceptance, and hardware commissioning remain separate production work. Run only the exact provider/country/seller/channel/payment combinations that have passed those checks.

## Admin REST endpoints

All endpoints require an authenticated user with `manage_options`:

- `GET /wp-json/wp-pass-captain/v1/fiscal/sellers`
- `POST /wp-json/wp-pass-captain/v1/fiscal/sellers` with `legal_name`, `country_code` (supported ISO country code), and seller tax/address details
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

- Complete provider sandbox acceptance and country/profile qualification for each customer-selected built-in adapter. The admin can store credentials in the encrypted per-seller/provider vault or a trusted site integration can resolve them through `wpcap_fiscal_credentials`.
- Improve the initial admin screen with seller editing/deactivation, clear credential-availability status, richer order-level fiscal diagnostics, and credential vault backup/rotation procedures.
- Complete provider-aware QR/signature output for all receipt paths and country-required archive/export flows. The first renderer shows normalized receipt fields and a separate fiscal QR on POS receipts and WooCommerce order details; a bounded normalized CSV export is available for operations, but it is not an AT, DE, or HU authority format. QR is omitted from the existing POS storno receipt until a fiscal correction exists.
- Implement and validate seller-specific fiscal adjustments for partial/full refunds, credit notes, cancellation/void protocols, operator/day-end exports, and HU NAV reporting. WooCommerce/native refund accounting and ticket revocation are implemented, but they do not yet submit a fiscal credit note. Do not enable production profiles that require correction support until the selected adapter reports and passes the required correction capability.
- Define and validate accounting allocations for mixed ticket/non-ticket WooCommerce carts, discounts, fees, and centrally collected organizer proceeds.
- Run the repository's PHP, integration, concurrency, security, vendor sandbox, and country acceptance checks before deploying or enabling a live adapter. The implementation does not make provider or tax-authority calls until the seller enables a reviewed profile.

The national legal requirements and provider certification must be validated for each exact seller, sales channel, payment method, and deployment. This adapter layer does not make those determinations.
