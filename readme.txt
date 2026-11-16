=== Pass Captain ===
Contributors: hastla007
Tags: events, tickets, check-in, booking, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone event, ticket and admission management for WordPress, with optional WooCommerce integration.

== Description ==

Pass Captain manages events, dates, ticket types, orders, tickets and admission directly in WordPress. WooCommerce is optional and not required for any core feature. Every feature in this plugin is free: there is no licence key, no trial period and no locked functionality.

* Events as single dates or recurring series, displayed with the shortcode `[wpcap_events]` or a block; calendar via `[wpcap_calendar]`, block and iCal feed (the older names `[wp_pass_captain_events]` and `[wp_pass_captain_calendar]` keep working).
* Orders with a time-limited reservation and idempotent checkout; free orders issue tickets immediately.
* Payments by bank transfer/manual confirmation, Stripe, PayPal, Mollie or the WooCommerce adapter.
* Tickets with QR code, PDF and Apple/Google Wallet passes; order portal for buyers (attendee assignment, one-time transfer, resend).
* Admission: web scanner via `[wpcap_scanner]` (or `[wp_pass_captain_scanner]`), a mobile scanner API with device pairing and an installable offline scanner with signed packages; admission rules, check-out and re-entry, staff per event or per date.
* Sales rules: automatic price rules (early bird, last minute, demand, weekday/time window), campaign codes with stacking rules, per-buyer ticket limits enforced across orders.
* Versioned tax profiles per country and tax class, frozen on each order line for invoices and refunds (rates must be reviewed by the site owner; none are shipped).
* Waiting lists with automatic offers and reservation when capacity frees up.
* Service bookings for courses, rentals and similar resources: time slots, booking rules (cut-off, lead time, per-customer limits, rebooking and cancellation deadlines), provider confirmation, no-show handling, slot waiting lists and service passes; a public booking page via `[wpcap_service_booking]` or a block, free or paid.
* Seating plans, box office, memberships, invoices and credit notes, merchant and agent sales models per organizer, vouchers from WP Voucher Captain, refunds with approval, reports and CSV exports.
* WooCommerce (optional): HPOS and legacy order storage, classic and block checkout, capacity reserved at checkout, tickets after payment, revocation on cancellation and refunds; carts with tickets from several sellers can be rejected or split into one order per seller.
* Email templates per language, reminders by email and SMS, signed webhooks, partner API and read-only developer keys.
* Security: provider credentials encrypted at rest (libsodium) with the key kept outside the database, role-based event scoping and data minimisation, step-up two-factor checks, time-limited support access and an audit log with viewer and CSV export.
* Operations: system status page with cron and worker health, queues and retry/discard actions, consistency checks with safe repairs, and WP-CLI commands `wp pass-captain status` and `wp pass-captain diagnose`.
* Privacy export and erasure through the WordPress privacy tools, retention periods and a privacy policy suggestion.
* Multisite: data kept separate per site, plus a network overview of all sites.
* Runs on MariaDB 10.6+ and MySQL 8.0+.

The plugin's user interface is written in German and fully translated into English, French, Italian, Spanish and Dutch.

Data is only removed when the plugin is deleted if "Remove all data when deleting the plugin" is enabled in the settings.

== External services ==

Pass Captain does not contact any external service on its own. The services below are only used after a site administrator has enabled and configured them (credentials or endpoint entered in the settings). No data is sent to the plugin author.

= Stripe (payments) =
Used when "Stripe" is enabled as a payment method. When a buyer chooses Stripe at checkout, the plugin creates a Stripe Checkout session: order reference, amount, currency, a line item summary (event and ticket names, quantities), the buyer's email address and the return/cancel URLs of your site are sent to api.stripe.com. Payment status is read back and refunds are requested through the same API when an administrator issues a refund. Stripe sends signed webhooks to your site; incoming webhooks are reconciled by reading the payment or refund back from Stripe. The "Verbindung testen" (test connection) button only reads the account ID and the webhook endpoints.
Terms: https://stripe.com/legal/ssa — Privacy policy: https://stripe.com/privacy

= PayPal (payments) =
Used when "PayPal" is enabled as a payment method. At checkout the plugin obtains an OAuth token and creates a PayPal order: order reference, amount, currency, a line item summary and the return URLs are sent to api-m.paypal.com (api-m.sandbox.paypal.com in sandbox mode). Payment status is read back and refunds are requested through the same API. PayPal sends webhooks to your site; each one is checked with PayPal's verify-webhook-signature API. The "Verbindung testen" (test connection) button signs in and reads the configured webhook.
Terms: https://www.paypal.com/legalhub/paypal/useragreement-full — Privacy policy: https://www.paypal.com/legalhub/paypal/privacy-full

= Mollie (payments) =
Used when the Mollie payment method is enabled and an API key is entered. At checkout the plugin creates a payment at api.mollie.com with order reference, amount, currency, description, the selected payment method and the redirect and webhook URLs of your site. Payment status is read back when Mollie calls the webhook, and refunds are requested through the same API.
Before that, when the checkout (or the payment method selector) is rendered for an amount, the plugin asks api.mollie.com which payment methods are available (GET /v2/methods with amount, currency, the sequence type "oneoff" and, if configured, the billing country; no buyer data). The answer is cached for one hour.
Terms: https://www.mollie.com/user-agreement — Privacy policy: https://www.mollie.com/privacy

= Twilio (SMS) =
Used when SMS is enabled with Twilio as provider and the buyer has explicitly consented to SMS. For each message (for example a reminder) the recipient's mobile number, the message text and a status callback URL of your site are sent to api.twilio.com. With number verification switched on (default), the mobile number and a one-time confirmation code are sent when the buyer requests the code in the checkout.
Terms: https://www.twilio.com/en-us/legal/tos — Privacy policy: https://www.twilio.com/en-us/legal/privacy

= Generic HTTP SMS provider (SMS-HTTP) =
Used when SMS is enabled with the "HTTP" provider. The recipient's mobile number, the message text (also the one-time confirmation code of the number verification) and an internal reference are sent to the endpoint URL entered by the administrator. Which service receives the data, and its terms and privacy policy, depend on the provider the administrator configures.

= Apple Push Notification service (Apple Wallet) =
Used when Apple Wallet passes are enabled and a pass certificate is uploaded. When a ticket changes (for example revoked, transferred or the date moved) the plugin sends an empty push notification to api.push.apple.com (api.sandbox.push.apple.com in sandbox mode) with the device push token registered by the buyer's Wallet app, so the device fetches the updated pass from your site. Apple devices that added a pass contact your site's pass web service directly.
Terms: https://developer.apple.com/support/terms/ — Privacy policy: https://www.apple.com/legal/privacy/

= Google Wallet API =
Used when Google Wallet passes are enabled and a service account is entered. The plugin requests an access token from oauth2.googleapis.com and creates or updates the event ticket class and object at walletobjects.googleapis.com: event name, date, venue, ticket type, ticket number, seat, ticket status, the ticket code for the barcode and, if given, the attendee name. The "Add to Google Wallet" link opens pay.google.com in the buyer's browser with a signed token containing the same pass data.
Terms: https://developers.google.com/terms — Privacy policy: https://policies.google.com/privacy

= Webhooks to URLs you configure =
Used only when an administrator adds a webhook subscription. Order, ticket, check-in and refund events (IDs, status, amounts, event and ticket type data) are sent as signed HTTP POST requests to the URL entered by the administrator. Email addresses of buyers or attendees are only included if the subscription is explicitly configured to include them. The receiving service is chosen by the administrator.

= HTTP/JSON connector (CRM, Zapier, Make, n8n or your own service) =
Used only when an administrator creates and activates an endpoint under Pass Captain → Connectors. For each event the endpoint subscribes to (order paid, refunded or cancelled, refund completed, tickets issued, ticket checked in, date moved or cancelled) the plugin sends a JSON POST request over HTTPS to the URL entered by the administrator: event name, time, an idempotency key and the order, ticket and event identifiers and states, or the fields chosen in the endpoint's field mapping. Buyer email addresses and attendee names are only sent when the endpoint's personal data option is enabled. An optional authentication header entered by the administrator is sent with each request. The "Test" button sends one sample request. The receiving service, and its terms and privacy policy, are chosen by the administrator.

= Fiscal services (fiskaly, efsta, fiskaltrust) =
Used only when an administrator creates a legal seller under Pass Captain → Fiscalization, selects and enables a fiscal provider for it, saves a reviewed production profile and maps ticket types (or WooCommerce products) to that seller. Nothing is sent before that. When a mapped order is paid (online, WooCommerce or box office), the plugin sends one receipt per seller to the selected provider before tickets are issued: seller legal name, country, tax number/VAT ID, order reference and a derived idempotency key, line items (descriptions, quantities, gross amounts, tax rates and tax amounts), currency, payment method and, at the box office, the register ID. No buyer name, email address or ticket code is sent. Status lookups, connection checks ("Verbindung prüfen") and, for efsta, an operator-triggered one-time void send the same identifiers. Credentials are sent only to the respective provider. Endpoints:
- fiskaly SIGN AT: rksv.fiskaly.com; fiskaly SIGN DE: kassensichv-middleware.fiskaly.com; fiskaly Unified API (FR, IT, PT, SE): test.api.fiskaly.com or live.api.fiskaly.com.
- efsta EFR: the EFR endpoint entered by the administrator (a local EFR, e.g. on 127.0.0.1, only after "Lokalen Fiskaldienst erlauben" is enabled, or an efsta cloud endpoint).
- fiskaltrust POS System API: possystem-api-sandbox.fiskaltrust.eu or possystem-api.fiskaltrust.eu (or an endpoint entered by the administrator).
fiskaly (fiskaly GmbH): Terms: https://www.fiskaly.com/legal-terms-of-use — Privacy policy: https://www.fiskaly.com/privacy-policy (API use is additionally governed by the contract the operator concludes with fiskaly).
efsta (efsta IT Services GmbH): Privacy policy: https://en.efsta.eu/datenschutz — Terms: the contract the operator concludes with efsta for the EFR (https://www.efsta.eu/en).
fiskaltrust (fiskaltrust consulting gmbh): Terms: https://fiskaltrust.eu/en-eu/terms-and-conditions/ — Privacy policy: https://fiskaltrust.eu/en-eu/privacy-policy/

= Third-party libraries =
Bundled unmodified: bacon/bacon-qr-code and dasprid/enum (BSD-2-Clause, in vendor/, see composer.json), jsQR (Apache-2.0, assets/vendor/jsqr/jsQR.js, unminified, source: https://github.com/cozmo/jsQR) and tweetnacl-js 1.0.3 (Unlicense, assets/vendor/tweetnacl/: the offline scanner loads nacl-fast.min.js, the readable nacl-fast.js of the same version ships next to it; version, origin and checksums in assets/vendor/tweetnacl/SOURCE.txt; source: https://github.com/dchest/tweetnacl-js).
Fonts: DejaVu Sans and DejaVu Sans Bold 2.37 (Bitstream Vera licence, DejaVu changes public domain, https://dejavu-fonts.github.io/), unmodified in assets/fonts/ with the licence text in assets/fonts/LICENSE-DejaVu.txt; PDFs (tickets, invoices, print layouts) embed only the glyphs they use.

The optional integrations with WooCommerce, WP Voucher Captain and WP Affiliate Captain run inside your WordPress installation through those plugins' PHP APIs and do not contact external services. All scripts, fonts and libraries (including those of the scanner and the offline scanner) ship with the plugin and are never loaded from a CDN.

== Installation ==

1. Upload the ZIP under Plugins → Add New → Upload Plugin and activate it.
2. Under Pass Captain → Settings, enter your seller details, payment methods and legal texts.
3. For reliable email, SMS and webhook delivery, trigger WP-Cron from a system cron job (see "Operations" below).

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. WooCommerce is an optional integration; checkout, payment and tickets work without it.

= Do I need a licence key? =

No. Pass Captain is free software under the GPL. All features in this plugin work without a licence key or account.

= How do database upgrades run after an update? =

The schema upgrade runs in the admin area, via WP-Cron or WP-CLI, protected by a database lock so parallel requests cannot start it twice. Front-end requests only schedule the upgrade.

= Where does Pass Captain store its data? =

In its own database tables (prefix `wpcap_`, created on activation and removed on uninstall only if you opt in), not in posts or post meta: orders, tickets, check-ins and seat claims need transactions, row locks and unique keys that the post tables cannot provide. All queries on these tables go through `$wpdb->prepare()`; values are never concatenated into SQL. Because the tables are the source of truth for availability and payments, their reads are not cached in the object cache.

= Operations =

Queues (emails, SMS, jobs, webhooks) are processed via WP-Cron with a time budget of about 20 seconds per run; if a backlog remains, another run follows immediately. If Action Scheduler is available (for example with WooCommerce), follow-up runs are scheduled through it. For busy shops, set `define( 'DISABLE_WP_CRON', true );` in wp-config.php and run `wp cron event run --due-now` every minute from a system cron job. On Multisite this command only runs the main site's events; run it for every site instead, e.g. `wp site list --field=url | xargs -I{} wp cron event run --due-now --url={}`.

= The plugin used to be called "WP Pass Captain". What changed? =

Only the name, the main file (`pass-captain.php`), the folder and the text domain (`pass-captain`). If the old copy `wp-pass-captain/wp-pass-captain.php` is still active, the new copy takes over its activation; data, settings, shortcodes, blocks, hooks and the REST namespace `wp-pass-captain/v1` stay the same. Afterwards the old copy can be deleted without losing data. Old webhook and API addresses keep working. Details: docs/RENAME.md in the source repository.

= Does the plugin work with page caches and CDNs? =

Yes. The ticket page, order portal, waiting list and invoice links, payment return and web scanner are never cached (DONOTCACHEPAGE and no-cache headers). Pages with an event list or calendar are purged automatically when events, dates, ticket types, prices, sales windows or legal texts change, on "sold out"/"available again" and at the start and end of sales: WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Cloudflare (official plugin, including APO) and optionally Varnish. Exclusion rules per product and the WAF/bot protection exceptions needed for payment webhooks and Apple Wallet are described in docs/OPERATIONS.md, section "Seitencache und CDN".

== Changelog ==

= 1.5.0 =
* Extension points for add-ons (admin menu, invoices, branding, checkout gate, API scopes, card terminal devices) with the contract version constant `WPCAP_CONTRACT_VERSION`.
* Renamed to "Pass Captain" (main file `pass-captain.php`, text domain `pass-captain`); automatic takeover from the old "WP Pass Captain" copy.
* Shared scanner with WP Voucher Captain, default admission window, deferred privacy erasure for upcoming dates.

= 1.4.0 =
* Sales models per organizer (merchant or agent), invoices addressed to companies, single- and multi-purpose vouchers.

= 1.3.0 =
* Security, refund, admission, privacy and operations improvements from an expert review; database upgrades behind a lock.

= 1.2.0 =
* Convert redeemed vouchers into tickets (WP Voucher Captain); "Partner" module renamed to "Organizer".

= 1.1.0 =
* Translations (fr_FR, it_IT, es_ES, nl_NL, en_US), series price tiers in the WooCommerce cart, email templates and WP Affiliate Captain integration.

The full change log is in CHANGELOG.md in the source repository.

== Upgrade Notice ==

= 1.5.0 =
Large update: database schema 3.18.0 is installed automatically, credentials are stored encrypted. New name "Pass Captain" (formerly "WP Pass Captain"): on activation the old copy is switched off; data, webhooks and API addresses are kept. The new default admission window stays off for existing installations. Please read "Wichtige Verhaltensänderungen beim Update" in CHANGELOG.md and back up the database first.

= 1.4.0 =
Adds sales models, company invoices and voucher tax treatment. Database schema 3.5.0 is installed automatically.
