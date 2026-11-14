=== Pass Captain ===
Contributors: hastla007
Tags: events, tickets, check-in, booking, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone event, ticket and admission management for WordPress, with optional WooCommerce integration.

== Description ==

Pass Captain manages events, dates, ticket types, orders, tickets and admission directly in WordPress. WooCommerce is optional and not required for any core feature. Every feature in this plugin is free: there is no licence key, no trial period and no locked functionality.

* Events as single dates or recurring series, displayed with the shortcode `[wp_pass_captain_events]` or a block; calendar via `[wp_pass_captain_calendar]`, block and iCal feed.
* Orders with a time-limited reservation; free orders issue tickets immediately.
* Payments by bank transfer/manual confirmation, Stripe, PayPal, Mollie or the WooCommerce adapter (HPOS and block checkout).
* Tickets with QR code, PDF and Apple/Google Wallet passes; web scanner via `[wp_pass_captain_scanner]`, a mobile scanner API and an installable offline scanner.
* Seating plans, admission rules, box office, waiting lists, campaign codes, memberships, invoices and credit notes, refunds with approval, reports and CSV exports.
* Email templates per language, reminders by email and SMS, signed webhooks for external systems.
* Privacy export and erasure through the WordPress privacy tools, retention periods and a privacy policy suggestion; multisite compatible (data kept separate per site).

The plugin's user interface is written in German and fully translated into English, French, Italian, Spanish and Dutch.

Data is only removed when the plugin is deleted if "Remove all data when deleting the plugin" is enabled in the settings.

== External services ==

Pass Captain does not contact any external service on its own. The services below are only used after a site administrator has enabled and configured them (credentials or endpoint entered in the settings). No data is sent to the plugin author.

= Stripe (payments) =
Used when "Stripe" is enabled as a payment method. When a buyer chooses Stripe at checkout, the plugin creates a Stripe Checkout session: order reference, amount, currency, a line item summary (event and ticket names, quantities), the buyer's email address and the return/cancel URLs of your site are sent to api.stripe.com. Payment status is read back and refunds are requested through the same API when an administrator issues a refund. Stripe sends signed webhooks to your site.
Terms: https://stripe.com/legal/ssa — Privacy policy: https://stripe.com/privacy

= PayPal (payments) =
Used when "PayPal" is enabled as a payment method. At checkout the plugin obtains an OAuth token and creates a PayPal order: order reference, amount, currency, a line item summary and the return URLs are sent to api-m.paypal.com (api-m.sandbox.paypal.com in sandbox mode). Payment status is read back and refunds are requested through the same API. PayPal sends webhooks to your site.
Terms: https://www.paypal.com/legalhub/paypal/useragreement-full — Privacy policy: https://www.paypal.com/legalhub/paypal/privacy-full

= Mollie (payments) =
Used when the Mollie payment method is enabled and an API key is entered. At checkout the plugin creates a payment at api.mollie.com with order reference, amount, currency, description, the selected payment method and the redirect and webhook URLs of your site. Payment status is read back when Mollie calls the webhook, and refunds are requested through the same API.
Terms: https://www.mollie.com/user-agreement — Privacy policy: https://www.mollie.com/privacy

= Twilio (SMS) =
Used when SMS is enabled with Twilio as provider and the buyer has explicitly consented to SMS. For each message (for example a reminder) the recipient's mobile number, the message text and a status callback URL of your site are sent to api.twilio.com.
Terms: https://www.twilio.com/en-us/legal/tos — Privacy policy: https://www.twilio.com/en-us/legal/privacy

= Generic HTTP SMS provider =
Used when SMS is enabled with the "HTTP" provider. The recipient's mobile number, the message text and an internal reference are sent to the endpoint URL entered by the administrator. Which service receives the data, and its terms and privacy policy, depend on the provider the administrator configures.

= Apple Push Notification service (Apple Wallet) =
Used when Apple Wallet passes are enabled and a pass certificate is uploaded. When a ticket changes (for example revoked, transferred or the date moved) the plugin sends an empty push notification to api.push.apple.com (api.sandbox.push.apple.com in sandbox mode) with the device push token registered by the buyer's Wallet app, so the device fetches the updated pass from your site. Apple devices that added a pass contact your site's pass web service directly.
Terms: https://developer.apple.com/support/terms/ — Privacy policy: https://www.apple.com/legal/privacy/

= Google Wallet API =
Used when Google Wallet passes are enabled and a service account is entered. The plugin requests an access token from oauth2.googleapis.com and creates or updates the event ticket class and object at walletobjects.googleapis.com: event name, date, venue, ticket type, ticket number, seat, ticket status, the ticket code for the barcode and, if given, the attendee name. The "Add to Google Wallet" link opens pay.google.com in the buyer's browser with a signed token containing the same pass data.
Terms: https://developers.google.com/terms — Privacy policy: https://policies.google.com/privacy

= Webhooks to URLs you configure =
Used only when an administrator adds a webhook subscription. Order, ticket, check-in and refund events (IDs, status, amounts, event and ticket type data) are sent as signed HTTP POST requests to the URL entered by the administrator. Email addresses of buyers or attendees are only included if the subscription is explicitly configured to include them. The receiving service is chosen by the administrator.

= Fiscal services (fiskaltrust, efsta) =
The plugin contains adapters for the fiscalisation services fiskaltrust (possystem-api-sandbox.fiskaltrust.eu or a configured endpoint) and efsta (a local EFR at 127.0.0.1 or a configured efsta cloud endpoint). They are not used unless a fiscal provider is configured. When used, receipt data (receipt number, line items with amounts and tax rates, payment method) and the seller's tax number are sent to the configured endpoint.
fiskaltrust terms and privacy: https://www.fiskaltrust.eu/ — efsta terms and privacy: https://www.efsta.eu/

The optional integrations with WooCommerce, WP Voucher Captain and WP Affiliate Captain run inside your WordPress installation through those plugins' PHP APIs and do not contact external services.

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

= Operations =

Queues (emails, SMS, jobs, webhooks) are processed via WP-Cron with a time budget of about 20 seconds per run; if a backlog remains, another run follows immediately. If Action Scheduler is available (for example with WooCommerce), follow-up runs are scheduled through it. For busy shops, set `define( 'DISABLE_WP_CRON', true );` in wp-config.php and run `wp cron event run --due-now` every minute from a system cron job.

= The plugin used to be called "WP Pass Captain". What changed? =

Only the name, the main file (`pass-captain.php`), the folder and the text domain (`pass-captain`). If the old copy `wp-pass-captain/wp-pass-captain.php` is still active, the new copy takes over its activation; data, settings, shortcodes, blocks, hooks and the REST namespace `wp-pass-captain/v1` stay the same. Afterwards the old copy can be deleted without losing data.

== Changelog ==

= Unreleased =
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

= 1.4.0 =
Adds sales models, company invoices and voucher tax treatment. Database schema 3.5.0 is installed automatically.
