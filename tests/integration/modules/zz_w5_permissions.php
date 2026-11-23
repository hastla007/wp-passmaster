<?php
/**
 * W5: permission matrix for every REST route and every admin-post / admin-ajax action.
 *
 * The routes are collected from all registrars through the register_rest_route() fake; the table
 * below declares each one. The test fails when a route/action is missing from the table, or when a
 * guest, a subscriber, staff of another event, a revoked/expired/foreign developer key, a revoked/
 * expired device or a key of another partner gets a successful response or changes data.
 * Included from run.php (after the module tests).
 */
// phpcs:ignoreFile
use PassCaptain\Orders;

echo "W5 security: permission matrix\n";
global $wpdb;
$p = $wpdb->prefix;

require_once dirname( __DIR__ ) . '/channels.php'; // register_rest_route() fake and WordPress stubs shared with A119/A145.
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE'; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return get_current_user_id() > 0; } }

/* ------------------------------------------------------------------ Declarations */

// Registrars of REST routes. A new file with register_rest_route() must be added here.
$pm_registrars = array(
	'src/Rest/Controller.php' => array( \PassCaptain\Rest\Controller::class, 'register_routes' ),
	'src/Mail/Suppression.php' => array( \PassCaptain\Mail\Suppression::class, 'routes' ),
	'src/Mobile/Api.php' => array( \PassCaptain\Mobile\Api::class, 'routes' ),
	'src/Admission/Rest.php' => array( \PassCaptain\Admission\Rest::class, 'routes' ),
	'src/OfflineScanner/Rest.php' => array( \PassCaptain\OfflineScanner\Rest::class, 'routes' ),
	'src/Partners/Api.php' => array( \PassCaptain\Partners\Api::class, 'register_routes' ),
	'src/Seating/Rest.php' => array( \PassCaptain\Seating\Rest::class, 'register_routes' ),
	'src/WalletPasses/WebService.php' => array( \PassCaptain\WalletPasses\WebService::class, 'routes' ),
	'src/Modules/Sms.php' => array( \PassCaptain\Modules\Sms::class, 'register_routes' ),
	'src/Services/Api.php' => array( \PassCaptain\Services\Api::class, 'register_routes' ),
	'src/Modules/Fiscalization.php' => array( \PassCaptain\Modules\Fiscalization::class, 'register_routes' ),
	// Registrars of the Arbeitsliste line (merged).
	'src/GuestLists/Rest.php' => array( \PassCaptain\GuestLists\Rest::class, 'routes' ),
	'src/Exchange/Portal.php' => array( \PassCaptain\Exchange\Portal::class, 'register_routes' ),
	'src/PortalSelfService.php' => array( \PassCaptain\PortalSelfService::class, 'register_routes' ),
	'src/ScannerDesk.php' => array( \PassCaptain\ScannerDesk::class, 'routes' ),
	'src/TicketRecovery.php' => array( \PassCaptain\TicketRecovery::class, 'register_routes' ),
);

/*
 * "METHOD route" => spec:
 *   public:<why>        no authentication by design
 *   token:<why>         public permission callback, the handler requires a secret (access token, signature, code)
 *   cap:<capability>    logged-in user with the capability (event scoped in the handler where it has an event);
 *                       suffix |scoped-list: a list filtered to the user's events – staff of another event may get
 *                       an answer, but never an object of event A
 *   report              cap wpcap_view_reports for the event OR developer key with reports.read for the event
 *   device              paired scanner device token (Authorization: Bearer wpcapd_…)
 *   devkey:<scope>      developer API key (X-WPCAP-Key) for the event
 *   partner:<scope>     partner API key (Authorization: Bearer wpcap_pk_…), limited to the partner's events
 */
// REST namespace of the merged plugin: pass-captain/v1 (LegacySlug routes the former wp-pass-captain/v1 here).
$pm_routes = array(
	'GET pass-captain/v1/events' => 'public:published events and prices (shop front)',
	'POST pass-captain/v1/checkout' => 'public:anonymous checkout; rate limited per IP and e-mail, prices from the database',
	'GET pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})' => 'token:order access token (64 hex) required',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/transfer' => 'token:order access token required',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/resend' => 'token:order access token required',
	'POST pass-captain/v1/tickets/recover' => 'public:A622 ticket link recovery; same answer for every address, rate limited, only a single-use confirmation link to the holder address',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/refund-request' => 'token:order access token required; postponement refund request, always pending staff approval',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/assign' => 'token:order access token required',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/billing' => 'token:order access token required',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/invoice' => 'cap:wpcap_manage_orders',
	'POST pass-captain/v1/tickets/validate' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/tickets/check-in' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/tickets/offline-sync' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/pos/sales' => 'cap:wpcap_manage_orders',
	'POST pass-captain/v1/passes/redeem' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/member-passes' => 'cap:wpcap_manage_orders',
	'POST pass-captain/v1/member-passes/(?P<pass_id>\d+)/revoke' => 'cap:wpcap_manage_orders',
	// A529 (merged): the old seat API (events/{id}/seats, seat-maps, seat-holds) was replaced by Seating\Rest.
	'GET pass-captain/v1/events/(?P<event_id>\d+)/service-slots' => 'public:open slots of a published event',
	'POST pass-captain/v1/events/(?P<event_id>\d+)/resources' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/resources/(?P<resource_id>\d+)/slots' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/service-slots/(?P<slot_id>\d+)/bookings' => 'public:slot reservation like checkout; rate limited per e-mail and IP',
	'POST pass-captain/v1/occurrences/(?P<occurrence_id>\d+)/admission-days' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/events/(?P<event_id>\d+)/campaigns' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/payments/(?P<provider>[a-z0-9_-]+)/webhook' => 'token:provider signature (Stripe HMAC, PayPal verification, Mollie re-fetch)',
	'GET pass-captain/v1/checkout/options' => 'public:enabled payment methods and legal texts',
	'GET pass-captain/v1/availability' => 'public:remaining tickets per ticket type for cached event pages; no personal data, rate limited',
	'POST pass-captain/v1/events/(?P<event_id>\d+)/waitlist' => 'public:waitlist sign-up; response does not reveal existing entries, rate limited',
	'POST pass-captain/v1/events/(?P<event_id>\d+)/waitlist/offer' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/accept' => 'token:offer token from the e-mail required',
	'GET pass-captain/v1/events/(?P<event_id>\d+)/reports/summary' => 'report',
	'POST pass-captain/v1/developer/keys' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/developer/keys/(?P<key_id>\d+)/revoke' => 'cap:wpcap_manage_events',
	'GET pass-captain/v1/developer/events/(?P<event_id>\d+)' => 'devkey:events.read',
	'GET pass-captain/v1/events/(?P<event_id>\d+)/translations' => 'public:translation of a published event',
	'POST pass-captain/v1/events/(?P<event_id>\d+)/translations' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/tax-profiles' => 'cap:manage_options',
	'POST pass-captain/v1/app/pair' => 'token:one-time pairing code; rate limited',
	'GET pass-captain/v1/app/me' => 'device',
	'GET pass-captain/v1/app/occurrences' => 'device',
	'GET pass-captain/v1/app/occurrences/(?P<occurrence_id>\d+)/stats' => 'device',
	'POST pass-captain/v1/app/scan/validate' => 'device',
	'POST pass-captain/v1/app/scan/check-in' => 'device',
	'POST pass-captain/v1/app/scan/check-out' => 'device',
	'POST pass-captain/v1/app/scan/undo' => 'device',
	'POST pass-captain/v1/app/scan/sync' => 'device',
	'POST pass-captain/v1/app/scan/batch' => 'device',
	'GET pass-captain/v1/app/info' => 'public:API/core version, minimum app version and feature flags for compatibility checks before pairing; no event data',
	'POST pass-captain/v1/app/logout' => 'device',
	'POST pass-captain/v1/admission/check-in' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/admission/check-out' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/admission/offline-sync' => 'cap:wpcap_checkin',
	'GET pass-captain/v1/app/offline/keys' => 'device',
	'GET pass-captain/v1/app/offline/occurrences' => 'device',
	'GET pass-captain/v1/app/offline/package' => 'device',
	'GET pass-captain/v1/app/offline/i18n' => 'public:static UI strings for the scanner app, no data',
	'GET pass-captain/v1/app/offline/manifest' => 'public:web app manifest of the scanner app (brand name, icons), no data',
	// Fiscalization (PR #14): legal sellers, profiles, providers, documents – administrators only.
	'GET pass-captain/v1/fiscal/sellers' => 'cap:manage_options',
	'POST pass-captain/v1/fiscal/sellers' => 'cap:manage_options',
	'POST pass-captain/v1/fiscal/sellers/(?P<seller_id>\d+)/profiles' => 'cap:manage_options',
	'POST pass-captain/v1/fiscal/sellers/(?P<seller_id>\d+)/health-check' => 'cap:manage_options',
	'PUT pass-captain/v1/fiscal/sellers/(?P<seller_id>\d+)/provider' => 'cap:manage_options',
	'POST pass-captain/v1/fiscal/documents/(?P<document_id>\d+)/reconcile' => 'cap:manage_options',
	'POST pass-captain/v1/fiscal/events/(?P<event_id>\d+)/manual-resolution' => 'cap:manage_options',
	'POST pass-captain/v1/fiscal/orders/(?P<order_id>\d+)/retry' => 'cap:manage_options',
	'PUT pass-captain/v1/fiscal/ticket-types/(?P<ticket_type_id>\d+)/seller' => 'cap:manage_options',
	// Service bookings and waitlists (W4).
	'GET pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/service-bookings' => 'token:order access token required',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/service-bookings/(?P<booking_id>\d+)/cancel' => 'token:order access token required',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/service-bookings/(?P<booking_id>\d+)/rebook' => 'token:order access token required',
	'GET pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/service-bookings/(?P<booking_id>\d+)/rebook-options' => 'token:order access token required',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/attach-order' => 'token:booking manage token and order access token required',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/confirm' => 'token:booking manage token required',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/cancel' => 'token:booking manage token required',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/rebook' => 'token:booking manage token required',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/decide' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/check-in' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/service-bookings/(?P<booking_id>\d+)/no-show' => 'cap:wpcap_manage_events',
	'POST pass-captain/v1/service-slots/(?P<slot_id>\d+)/waitlist' => 'public:slot waitlist sign-up; rate limited per e-mail and IP, response reveals no other entries',
	'GET pass-captain/v1/service-waitlist/(?P<entry_id>\d+)' => 'token:waitlist manage token required',
	'POST pass-captain/v1/service-waitlist/(?P<entry_id>\d+)/accept' => 'token:waitlist manage token required',
	'POST pass-captain/v1/service-waitlist/(?P<entry_id>\d+)/decline' => 'token:waitlist manage token required',
	'POST pass-captain/v1/service-waitlist/(?P<entry_id>\d+)/leave' => 'token:waitlist manage token required',
	'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/decline' => 'token:offer token from the e-mail required',
	'POST pass-captain/v1/waitlist/(?P<waitlist_id>\d+)/unsubscribe' => 'token:waitlist manage token required',
	'POST pass-captain/v1/member-passes/redeem-service' => 'token:member pass code required; rate limited per IP',
	'GET pass-captain/v1/partner/me' => 'partner:',
	'GET pass-captain/v1/partner/events' => 'partner:events:read',
	'GET pass-captain/v1/partner/events/(?P<event_id>\d+)' => 'partner:events:read',
	'GET pass-captain/v1/partner/orders' => 'partner:orders:read',
	'GET pass-captain/v1/partner/tickets' => 'partner:tickets:read',
	'GET pass-captain/v1/partner/checkins' => 'partner:checkins:read',
	'GET pass-captain/v1/partner/reports' => 'partner:reports:read',
	'GET pass-captain/v1/partner/settlements' => 'partner:settlements:read',
	'GET pass-captain/v1/partner/settlements/(?P<settlement_id>\d+)' => 'partner:settlements:read',
	'GET pass-captain/v1/seating/occurrences/(?P<occurrence_id>\d+)' => 'public:seat plan of a published occurrence',
	'POST pass-captain/v1/wallet/apple/v1/devices/(?P<device>[A-Za-z0-9]{1,128})/registrations/(?P<pass_type>[A-Za-z0-9.\-]{1,190})/(?P<serial>[A-Za-z0-9\-]{1,190})' => 'token:PassKit ApplePass authentication token',
	'DELETE pass-captain/v1/wallet/apple/v1/devices/(?P<device>[A-Za-z0-9]{1,128})/registrations/(?P<pass_type>[A-Za-z0-9.\-]{1,190})/(?P<serial>[A-Za-z0-9\-]{1,190})' => 'token:PassKit ApplePass authentication token',
	'GET pass-captain/v1/wallet/apple/v1/devices/(?P<device>[A-Za-z0-9]{1,128})/registrations/(?P<pass_type>[A-Za-z0-9.\-]{1,190})' => 'public:unauthenticated by Apple PassKit spec; returns only serials registered by that device id',
	'GET pass-captain/v1/wallet/apple/v1/passes/(?P<pass_type>[A-Za-z0-9.\-]{1,190})/(?P<serial>[A-Za-z0-9\-]{1,190})' => 'token:PassKit ApplePass authentication token',
	'POST pass-captain/v1/wallet/apple/v1/log' => 'public:unauthenticated by Apple PassKit spec; tight rate limits, small buffer',
	'GET pass-captain/v1/wallet/apple/download/(?P<serial>[A-Za-z0-9\-]{1,190})' => 'token:signed download link (HMAC)',
	'GET pass-captain/v1/wallet/order/(?P<order>[A-Za-z0-9\-]{8,64})' => 'token:signed collection link of an order (HMAC, expiry)',
	'POST pass-captain/v1/sms/twilio/status' => 'token:X-Twilio-Signature',
	'POST pass-captain/v1/sms/twilio/inbound' => 'token:X-Twilio-Signature',
	'POST pass-captain/v1/sms/verify/request' => 'public:SMS code for the checkout opt-in (PRIV-16); same-origin check, rate limited per number, address and site, allowed mobile ranges only',
	'POST pass-captain/v1/sms/verify/confirm' => 'token:verification token from the code request plus the 6-digit code (5 attempts, 10 minutes)',
	'POST pass-captain/v1/mail/bounce' => 'token:bounce webhook token of the mail service (header or query, constant-time compare); off unless mail_bounce_webhook is enabled (R3)',
	// Arbeitsliste line (merged): order portal, member pass approval, service bookings, campaigns, live state, waitlist,
	// developer slots, scanner app search, partner sales API, wallet links, generic SMS gateway, guest lists, exchange,
	// web scanner desk.
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})' => 'token:order access token in the body instead of the URL (A645)',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/payout-account' => 'token:order access token required (payout account for refunds, encrypted)',
	'POST pass-captain/v1/orders/(?P<public_id>[a-f0-9]{32})/exchange' => 'token:order access token required (ticket exchange in the portal)',
	'GET pass-captain/v1/member-passes' => 'cap:wpcap_manage_orders|scoped-list',
	'POST pass-captain/v1/member-passes/(?P<pass_id>\d+)/approve' => 'cap:wpcap_approve_comps',
	'POST pass-captain/v1/member-passes/(?P<pass_id>\d+)/reject' => 'cap:wpcap_approve_comps',
	'GET pass-captain/v1/service-bookings/(?P<booking_id>\d+)' => 'token:booking token verified by the permission callback (ServiceBookingFlow::token_permission)',
	'POST pass-captain/v1/campaigns/preview' => 'public:non-binding discount preview of the checkout estimate; rate limited per client, no personal data',
	'GET pass-captain/v1/checkout/live' => 'public:live purchase state of cached event cards (public data only, no-store)',
	'GET pass-captain/v1/events/(?P<event_id>\d+)/waitlist/entries' => 'cap:wpcap_manage_events',
	'GET pass-captain/v1/developer/events/(?P<event_id>\d+)/service-slots' => 'devkey:slots.read',
	'GET pass-captain/v1/app/search' => 'device',
	'POST pass-captain/v1/partner/orders' => 'partner:orders:write',
	'GET pass-captain/v1/partner/catalog' => 'partner:orders:write',
	'GET pass-captain/v1/partner/orders/(?P<order_id>[a-f0-9]{32})' => 'partner:orders:write',
	'POST pass-captain/v1/partner/orders/(?P<order_id>[a-f0-9]{32})/confirm' => 'partner:orders:write',
	'POST pass-captain/v1/partner/orders/(?P<order_id>[a-f0-9]{32})/cancel' => 'partner:orders:cancel',
	'GET pass-captain/v1/partner/orders/(?P<order_id>[a-f0-9]{32})/tickets' => 'partner:tickets:issue',
	'GET pass-captain/v1/partner/sales/statement' => 'partner:settlements:read',
	'GET pass-captain/v1/wallet/apple/bundle' => 'token:signed wallet link (o, p, sig) checked by Service::link_tickets(); rate limited',
	'GET pass-captain/v1/wallet/google/save' => 'token:signed wallet link (t or o/p, sig) checked by Service::link_tickets(); rate limited',
	'POST pass-captain/v1/sms/http/status' => 'token:HMAC-SHA256 body signature of the generic HTTP SMS gateway (A141)',
	'POST pass-captain/v1/sms/http/inbound' => 'token:HMAC-SHA256 body signature of the generic HTTP SMS gateway (A141)',
	'GET pass-captain/v1/guestlists/search' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/guestlists/entries/(?P<entry_id>\d+)/arrive' => 'cap:wpcap_checkin',
	'GET pass-captain/v1/guestlists/will-call' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/scanner/undo' => 'cap:wpcap_checkin',
	'GET pass-captain/v1/scanner/stats' => 'cap:wpcap_checkin',
	'GET pass-captain/v1/scanner/search' => 'cap:wpcap_checkin',
	'POST pass-captain/v1/scanner/check-in-ticket' => 'cap:wpcap_checkin',
);

// admin-post / admin-ajax actions: action => required capability, or 'public:<why>' / 'token:<why>'.
$pm_admin = array(
	'wpcap_create_event' => 'wpcap_manage_events', 'wpcap_update_event' => 'wpcap_manage_events', 'wpcap_cancel_event' => 'wpcap_manage_events',
	'wpcap_save_occurrence' => 'wpcap_manage_events', 'wpcap_cancel_occurrence' => 'wpcap_manage_events', 'wpcap_save_ticket_type' => 'wpcap_manage_events',
	'wpcap_save_participant_field' => 'wpcap_manage_events', 'wpcap_participant_field_status' => 'wpcap_manage_events',
	'wpcap_assign_staff' => 'wpcap_manage_events', 'wpcap_remove_staff' => 'wpcap_manage_events',
	'wpcap_confirm_payment' => 'wpcap_manage_orders', 'wpcap_cancel_order' => 'wpcap_manage_orders', 'wpcap_resend_tickets' => 'wpcap_manage_orders', 'wpcap_send_order_link' => 'wpcap_manage_orders',
	'wpcap_refund_order' => 'wpcap_refund', 'wpcap_approve_refund' => 'wpcap_approve_refunds', 'wpcap_reject_refund' => 'wpcap_approve_refunds',
	// R7: complimentary tickets, duplicate, CSV import/exports.
	'wpcap_orders_csv' => 'wpcap_manage_orders', 'wpcap_issue_comps' => 'wpcap_manage_orders', 'wpcap_duplicate_event' => 'wpcap_manage_events',
	'wpcap_duplicate_occurrence' => 'wpcap_manage_events', 'wpcap_import_events' => 'wpcap_manage_events', 'wpcap_campaign_codes_csv' => 'wpcap_manage_events',
	'wpcap_revoke_ticket' => 'wpcap_manage_orders', 'wpcap_undo_checkin' => 'wpcap_checkin', 'wpcap_reissue_ticket' => 'wpcap_manage_orders',
	'wpcap_save_settings' => 'manage_options', 'wpcap_create_pairing' => 'wpcap_checkin', 'wpcap_revoke_device' => 'wpcap_checkin',
	'wpcap_api_key_action' => 'wpcap_manage_events', 'wpcap_service_action' => 'wpcap_manage_events', 'wpcap_waitlist_action' => 'wpcap_manage_events',
	'wpcap_waitlist_export' => 'wpcap_manage_events', 'wpcap_campaign_action' => 'wpcap_manage_events', 'wpcap_membership_action' => 'wpcap_manage_orders',
	'wpcap_admission_save_policy' => 'wpcap_manage_events', 'wpcap_admission_save_area' => 'wpcap_manage_events', 'wpcap_admission_area_status' => 'wpcap_manage_events',
	'wpcap_admission_add_staff' => 'wpcap_manage_events', 'wpcap_admission_remove_staff' => 'wpcap_manage_events', 'wpcap_admission_save_settings' => 'manage_options',
	'wpcap_admission_resolve_conflict' => 'wpcap_manage_events',
	// System status (W2) and add-on export types (W7).
	'wpcap_ops_queue_action' => 'manage_options', 'wpcap_ops_queue_bulk_retry' => 'manage_options', 'wpcap_ops_diagnose' => 'manage_options', 'wpcap_ops_repair' => 'manage_options',
	'wpcap_ops_refund_resolve' => 'manage_options', 'wpcap_ops_schema_retry' => 'manage_options', 'wpcap_ops_payment_case' => 'manage_options', 'wpcap_reports_export' => 'wpcap_view_reports',
	// Sales rules (W8): tax profiles, event sections (tax country, price rules, buyer limits).
	'wpcap_tax_profile_action' => 'manage_options', 'wpcap_sales_event_action' => 'wpcap_manage_events',
	'wpcap_pos_open' => 'wpcap_manage_orders', 'wpcap_pos_sell' => 'wpcap_manage_orders', 'wpcap_pos_storno' => 'wpcap_manage_orders', 'wpcap_pos_storno_reject' => 'wpcap_approve_refunds',
	'wpcap_pos_finalize' => 'wpcap_manage_orders', 'wpcap_pos_close' => 'wpcap_manage_orders', 'wpcap_pos_receipt' => 'wpcap_manage_orders', 'wpcap_pos_tickets' => 'wpcap_manage_orders',
	'wpcap_pos_availability' => 'wpcap_manage_orders', 'wpcap_pos_settings' => 'manage_options',
	// Card-terminal payments (add-on devices): admin-post fallback and the POS status poll/cancel via admin-ajax; cashier of the sale or administrator.
	'wpcap_pos_device_cancel' => 'wpcap_manage_orders', 'ajax:wpcap_pos_device_status' => 'wpcap_manage_orders', 'ajax:wpcap_pos_device_cancel' => 'wpcap_manage_orders',
	'wpcap_calendar_save' => 'manage_options', 'wpcap_mail_template_save' => 'manage_options', 'wpcap_mail_template_reset' => 'manage_options',
	'wpcap_mail_template_test' => 'manage_options', 'wpcap_mail_layout_save' => 'manage_options', 'wpcap_mollie_save' => 'manage_options',
	'wpcap_offline_save_rules' => 'wpcap_manage_events', 'wpcap_offline_assign' => 'wpcap_manage_events', 'wpcap_offline_unassign' => 'wpcap_manage_events',
	'wpcap_offline_rotate_key' => 'manage_options', 'wpcap_qr_rotate_key' => 'manage_options',
	'wpcap_partner_action' => 'manage_options', 'wpcap_partner_settlement_doc' => 'wpcap_view_finance',
	'wpcap_print_save_template' => 'manage_options', 'wpcap_print_starter' => 'wpcap_manage_events', 'wpcap_print_preview' => 'wpcap_manage_events', 'wpcap_print_assign' => 'wpcap_manage_events', 'wpcap_print_batch' => 'wpcap_manage_orders',
	'wpcap_attendee_list' => 'wpcap_manage_orders', 'wpcap_print_settings' => 'manage_options', 'wpcap_invoice_pdf' => 'wpcap_view_finance',
	'wpcap_reminders_save' => 'manage_options', 'wpcap_reports_csv' => 'wpcap_view_reports',
	'wpcap_seating_create' => 'wpcap_manage_events', 'wpcap_seating_save' => 'wpcap_manage_events', 'wpcap_seating_link' => 'wpcap_manage_events', 'wpcap_seating_unlink' => 'wpcap_manage_events',
	'wpcap_series_create' => 'wpcap_manage_events', 'wpcap_series_rule_save' => 'wpcap_manage_events', 'wpcap_series_rule_delete' => 'wpcap_manage_events',
	'wpcap_series_pool_save' => 'wpcap_manage_events', 'wpcap_series_pool_update' => 'wpcap_manage_events', 'wpcap_series_pool_delete' => 'wpcap_manage_events',
	'wpcap_sms_save' => 'manage_options', 'wpcap_wallet_save' => 'manage_options', 'wpcap_wallet_test_pass' => 'manage_options', 'wpcap_webhook_action' => 'manage_options',
	'wpcap_connector_action' => 'manage_options',
	// Registered outside Menu::handlers().
	'wpcap_print_invoice' => 'wpcap_view_finance', 'wpcap_export_accounting' => 'wpcap_view_finance',
	'wpcap_accept_waitlist' => 'token:waitlist offer token from the e-mail (admin_post_nopriv)',
	'wpcap_payment_retry' => 'token:order return token from the payment return URL (admin_post_nopriv)',
	'wpcap_payment_release' => 'token:order return token from the payment return URL (admin_post_nopriv)',
	'wpcap_service_portal' => 'token:order access token (service booking portal forms, admin_post and admin_post_nopriv)',
	// W9: audit log, per-case unmask, support access, date-level staff, buyer language.
	'wpcap_audit_export' => 'wpcap_view_audit', 'wpcap_pii_unmask' => 'wpcap_manage_orders',
	'wpcap_support_grant' => 'manage_options', 'wpcap_support_revoke' => 'manage_options',
	'wpcap_assign_occurrence_staff' => 'wpcap_manage_events', 'wpcap_remove_occurrence_staff' => 'wpcap_manage_events',
	'wpcap_portal_language' => 'token:order access token (capability link of the buyer portal; admin_post_nopriv)',
	'ajax:wpcap_mail_template_preview' => 'manage_options',
	// Fiscalization (PR #14).
	'wpcap_fiscal_save_seller' => 'manage_options', 'wpcap_fiscal_save_provider' => 'manage_options', 'wpcap_fiscal_save_credentials' => 'manage_options',
	'wpcap_fiscal_save_profile' => 'manage_options', 'wpcap_fiscal_assign_seller' => 'manage_options', 'wpcap_fiscal_retry_order' => 'manage_options',
	'wpcap_fiscal_health_check' => 'manage_options', 'wpcap_fiscal_reconcile' => 'manage_options', 'wpcap_fiscal_export' => 'manage_options',
	'wpcap_fiscal_manual_resolution' => 'manage_options', 'wpcap_fiscal_submit_efsta_void' => 'manage_options',
	// Arbeitsliste line (merged).
	'wpcap_schema_retry' => 'manage_options', 'wpcap_entry_window_dismiss' => 'manage_options', 'wpcap_resume_bulk_run' => 'wpcap_manage_events',
	'wpcap_retry_allocation' => 'wpcap_manage_orders', 'wpcap_settle_unallocated' => 'wpcap_manage_orders', 'wpcap_request_attendee_data' => 'wpcap_manage_orders',
	'wpcap_ticket_pdf' => 'wpcap_manage_orders', 'wpcap_resolve_refund' => 'wpcap_refund', 'wpcap_consent_withdraw' => 'wpcap_manage_orders',
	'wpcap_export_consents' => 'wpcap_manage_orders', 'wpcap_correct_attendee' => 'wpcap_manage_orders', 'wpcap_checkin_ticket' => 'wpcap_checkin', 'wpcap_bulk_checkin' => 'wpcap_checkin',
	'wpcap_create_scanner_page' => 'publish_pages', 'wpcap_test_provider_connection' => 'manage_options', 'wpcap_campaign_create' => 'wpcap_manage_events',
	'wpcap_campaign_status' => 'wpcap_manage_events', 'wpcap_member_pass' => 'wpcap_manage_orders', 'wpcap_tax_profile_save' => 'wpcap_view_finance',
	'wpcap_tax_profile_activate' => 'wpcap_view_finance', 'wpcap_tax_profile_retire' => 'wpcap_view_finance', 'wpcap_getting_started' => 'manage_options',
	'wpcap_create_sales_page' => 'manage_options', 'wpcap_operations_action' => 'manage_options', 'wpcap_doctor_repair' => 'manage_options',
	'wpcap_developer_key_create' => 'manage_options', 'wpcap_developer_key_update' => 'manage_options',
	'wpcap_developer_key_revoke' => 'manage_options', 'wpcap_service_resource_save' => 'wpcap_manage_events', 'wpcap_service_resource_delete' => 'wpcap_manage_events',
	'wpcap_service_slot_save' => 'wpcap_manage_events', 'wpcap_service_slot_delete' => 'wpcap_manage_events', 'wpcap_service_slots_generate' => 'wpcap_manage_events',
	'wpcap_service_provider_save' => 'wpcap_manage_events', 'wpcap_service_resource_providers' => 'wpcap_manage_events', 'wpcap_service_booking_action' => 'wpcap_manage_events',
	'wpcap_service_booking_status' => 'wpcap_service_bookings', 'wpcap_assignment_add' => 'wpcap_manage_events', 'wpcap_assignment_remove' => 'wpcap_manage_events',
	'wpcap_assignment_area_add' => 'wpcap_manage_events', 'wpcap_assignment_area_remove' => 'wpcap_manage_events',
	'wpcap_alloc_create' => 'wpcap_manage_events', 'wpcap_alloc_release' => 'wpcap_manage_events', 'wpcap_alloc_update' => 'wpcap_manage_events',
	'wpcap_alloc_seats' => 'wpcap_manage_events', 'wpcap_alloc_limits' => 'wpcap_manage_events', 'wpcap_alloc_settings' => 'wpcap_manage_events',
	'wpcap_pos_count' => 'wpcap_manage_orders', 'wpcap_pos_movement' => 'wpcap_manage_orders', 'wpcap_pos_cashier' => 'wpcap_manage_orders',
	'wpcap_pos_handover' => 'wpcap_manage_orders', 'wpcap_pos_handover_cancel' => 'wpcap_manage_orders', 'wpcap_pos_register' => 'manage_options',
	'wpcap_pos_correction' => 'wpcap_manage_orders', 'wpcap_pos_zreport' => 'wpcap_manage_orders', 'wpcap_pos_zreport_csv' => 'wpcap_manage_orders',
	'wpcap_pos_payout_receipt' => 'wpcap_manage_orders', 'wpcap_pos_pay_out' => 'wpcap_manage_orders',
	'wpcap_exchange_ticket' => 'wpcap_manage_orders', 'wpcap_exchange_settings' => 'manage_options', 'wpcap_exchange_event_policy' => 'wpcap_manage_events',
	'wpcap_gl_save_list' => 'wpcap_manage_events', 'wpcap_gl_archive_list' => 'wpcap_manage_events', 'wpcap_gl_add_entry' => 'wpcap_manage_events',
	'wpcap_gl_import' => 'wpcap_manage_events', 'wpcap_gl_entry_action' => 'wpcap_manage_events', 'wpcap_gl_arrive' => 'wpcap_checkin',
	'wpcap_gl_export' => 'wpcap_manage_events', 'wpcap_gl_print' => 'wpcap_checkin', 'wpcap_partner_statement' => 'manage_options',
	'wpcap_pass_create' => 'wpcap_manage_events', 'wpcap_pass_save' => 'wpcap_manage_events', 'wpcap_pass_remove' => 'wpcap_manage_events', 'wpcap_pass_quota' => 'wpcap_manage_events',
	'wpcap_print_batch_download' => 'wpcap_manage_orders', 'wpcap_print_batch_cancel' => 'wpcap_manage_orders', 'wpcap_settlement_sheet' => 'wpcap_view_reports',
	'wpcap_series_update' => 'wpcap_manage_events', 'wpcap_series_rule_update' => 'wpcap_manage_events', 'wpcap_sms_release_held' => 'manage_options',
	'wpcap_waitlist_entry' => 'wpcap_manage_orders', 'wpcap_waitlist_settings' => 'manage_options',
	'wpcap_form_captain_event_form' => 'wpcap_manage_events', 'wpcap_form_captain_request_book' => 'wpcap_manage_orders', 'wpcap_form_captain_request_reject' => 'wpcap_manage_events',
	'wpcap_dismiss_notice' => 'manage_options',
	'wpcap_payment_return' => 'token:return key wpcap_rk of the payment return URL (A554, admin_post_nopriv)',
	'wpcap_waitlist_unsubscribe' => 'token:waitlist unsubscribe token from the e-mail (admin_post_nopriv)',
	'wpcap_waitlist_confirm' => 'token:double opt-in confirmation token from the e-mail (A564, admin_post_nopriv)',
);

// Actions that are not bound to an event: any holder of the capability may use them for their own data.
$pm_unscoped = array(
	'wpcap_pos_open' => 'opens the cashier\'s own register session; every sale in it is checked per event',
	// A143/A145: a new provider record belongs to no event; once assigned to resources only people who manage every
	// event of those resources may change it (ServiceProviders::save), and assigning checks the resource's event.
	'wpcap_service_provider_save' => 'creates a provider record without event; changing an assigned one is event-scoped',
);

/* ------------------------------------------------------------------ Route collection */

$registrar_files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() && false !== strpos( (string) file_get_contents( $file->getPathname() ), 'register_rest_route(' ) ) {
		$registrar_files[] = substr( $file->getPathname(), strlen( WPCAP_PATH ) );
	}
}
sort( $registrar_files );
$known_files = array_keys( $pm_registrars );
sort( $known_files );
check( 'every source file that registers REST routes is covered (' . implode( ', ', array_diff( $registrar_files, $known_files ) ) . ')', $registrar_files === $known_files );

$GLOBALS['wpcap_test_routes'] = array();
foreach ( $pm_registrars as $registrar ) { call_user_func( $registrar ); }
$pm_endpoints = array();
foreach ( $GLOBALS['wpcap_test_routes'] as $route => $args ) {
	foreach ( isset( $args['callback'] ) ? array( $args ) : $args as $endpoint ) {
		if ( is_array( $endpoint ) && isset( $endpoint['callback'] ) ) { $pm_endpoints[ $endpoint['methods'] . ' ' . $route ] = $endpoint; }
	}
}
$missing = array_diff( array_keys( $pm_endpoints ), array_keys( $pm_routes ) );
$stale = array_diff( array_keys( $pm_routes ), array_keys( $pm_endpoints ) );
check( count( $pm_endpoints ) . ' REST endpoints, every one declared in the matrix' . ( $missing ? ' – missing: ' . implode( ', ', $missing ) : '' ), ! $missing );
check( 'matrix has no stale routes' . ( $stale ? ' – ' . implode( ', ', $stale ) : '' ), ! $stale );
$bad_public = array_filter( $pm_endpoints, static fn( $e ) => '__return_true' === $e['permission_callback'] );
$undeclared_public = array_filter( array_keys( $bad_public ), static fn( $k ) => ! preg_match( '/^(public|token):.{10,}/', $pm_routes[ $k ] ?? '' ) );
check( 'every route with an open permission callback is declared public/token with a justification' . ( $undeclared_public ? ' – ' . implode( ', ', $undeclared_public ) : '' ), ! $undeclared_public );

/* ------------------------------------------------------------------ Fixtures */

$GLOBALS['wpcap_test_user_id'] = 1;
$GLOBALS['wpcap_test_denied_caps'] = array();
unset( $GLOBALS['wpcap_test_user_can'] );
wpcap_test_reset_transients();
$pm_type_a = make_type( 50, 10, 0 );
$pm_type_b = make_type( 50, 10, 0 );
$pm_occ_a = (int) $wpdb->get_var( "SELECT occurrence_id FROM {$p}wpcap_ticket_types WHERE ticket_type_id=$pm_type_a" );
$pm_event_a = (int) $wpdb->get_var( "SELECT event_id FROM {$p}wpcap_occurrences WHERE occurrence_id=$pm_occ_a" );
$pm_event_b = (int) $wpdb->get_var( "SELECT o.event_id FROM {$p}wpcap_ticket_types tt JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=$pm_type_b" );
$pm_order = Orders::create( array( 'email' => 'pm-a@example.com', 'items' => array( array( 'ticket_type_id' => $pm_type_a, 'quantity' => 2 ) ), 'idempotency_key' => 'pm-a-1' ) );
$pm_order_id = (int) $pm_order['order_id'];
$pm_public_id = (string) Orders::get( $pm_order_id )->public_id;
$pm_codes = ticket_code_for( $pm_order_id );
$pm_ticket = $wpdb->get_row( "SELECT ticket_id,public_id FROM {$p}wpcap_tickets WHERE order_id=$pm_order_id ORDER BY ticket_id LIMIT 1" );
$wpdb->insert( "{$p}wpcap_resources", array( 'event_id' => $pm_event_a, 'name' => 'PM Raum', 'capacity' => 2, 'buffer_before' => 0, 'buffer_after' => 0, 'status' => 'active' ) );
$pm_resource = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_slots", array( 'resource_id' => $pm_resource, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 90000 ), 'capacity' => 2, 'status' => 'open' ) );
$pm_slot = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}wpcap_service_bookings", array( 'slot_id' => $pm_slot, 'customer_email' => 'pm-book@example.com', 'idempotency_key' => hash( 'sha256', 'pm-booking' ), 'quantity' => 1, 'status' => 'confirmed', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$pm_booking = (int) $wpdb->insert_id;
$pm_pass = \PassCaptain\Memberships::issue( 'pm-pass@example.com', gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() + 30 * 86400 ), array( array( 'event_id' => $pm_event_a, 'ticket_type_id' => $pm_type_a, 'max_redemptions' => 5 ) ) );
$pm_pass_id = is_array( $pm_pass ) ? (int) $pm_pass['pass_id'] : 0;
$pm_devkey = \PassCaptain\DeveloperKeys::issue( $pm_event_a, array( 'events.read', 'reports.read' ), 'pm' );
$pm_devkey_b = \PassCaptain\DeveloperKeys::issue( $pm_event_b, array( 'events.read', 'reports.read' ), 'pm-b' );
$pm_devkey_revoked = \PassCaptain\DeveloperKeys::issue( $pm_event_a, array( 'events.read', 'reports.read' ), 'pm-revoked' );
\PassCaptain\DeveloperKeys::revoke( $pm_devkey_revoked['api_key_id'] );
$pm_devkey_expired = \PassCaptain\DeveloperKeys::issue( $pm_event_a, array( 'events.read', 'reports.read' ), 'pm-expired' );
$wpdb->query( "UPDATE {$p}wpcap_api_keys SET expires_at='2000-01-01 00:00:00' WHERE api_key_id=" . (int) $pm_devkey_expired['api_key_id'] );
$pm_pair = \PassCaptain\Mobile\Devices::create_pairing( array( $pm_event_a ), 'PM', 12 );
$pm_device = \PassCaptain\Mobile\Devices::pair( $pm_pair['code'], 'PM device', 'ios', '1.0' );
$pm_pair2 = \PassCaptain\Mobile\Devices::create_pairing( array( $pm_event_a ), 'PM revoked', 12 );
$pm_device_revoked = \PassCaptain\Mobile\Devices::pair( $pm_pair2['code'], 'PM revoked', 'ios', '1.0' );
$pm_rev_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT device_id FROM {$p}wpcap_scanner_devices WHERE secret_hash=%s", hash( 'sha256', substr( $pm_device_revoked['token'], -64 ) ) ) );
if ( ! $pm_rev_id ) { $pm_rev_id = (int) $wpdb->get_var( "SELECT MAX(device_id) FROM {$p}wpcap_scanner_devices" ); }
\PassCaptain\Mobile\Devices::revoke( $pm_rev_id );
$pm_partner_a = \PassCaptain\Partners\Repository::save( array( 'name' => 'PM Partner A', 'currency' => 'EUR' ) );
$pm_partner_b = \PassCaptain\Partners\Repository::save( array( 'name' => 'PM Partner B', 'currency' => 'EUR' ) );
\PassCaptain\Partners\Repository::set_events( $pm_partner_a, array( $pm_event_a ) );
\PassCaptain\Partners\Repository::set_events( $pm_partner_b, array( $pm_event_b ) );
$pm_all_scopes = array( 'events:read', 'orders:read', 'tickets:read', 'checkins:read', 'reports:read', 'settlements:read', 'attendees:read' );
$pm_pk_b = \PassCaptain\Partners\Keys::issue( $pm_partner_b, $pm_all_scopes );
$pm_pk_revoked = \PassCaptain\Partners\Keys::issue( $pm_partner_a, $pm_all_scopes );
\PassCaptain\Partners\Keys::revoke( $pm_pk_revoked['key_id'] );
$pm_pk_expired = \PassCaptain\Partners\Keys::issue( $pm_partner_a, $pm_all_scopes );
$wpdb->query( "UPDATE {$p}wpcap_partner_keys SET expires_at='2000-01-01 00:00:00' WHERE key_id=" . (int) $pm_pk_expired['key_id'] );
$pm_settlement_a = 0;
check( 'matrix fixtures: event A with order/tickets, pass, devices, developer and partner keys', $pm_order_id && $pm_codes && $pm_pass_id && ! is_wp_error( $pm_devkey ) && ! is_wp_error( $pm_device ) && ! is_wp_error( $pm_pk_b ) && $pm_partner_a && $pm_partner_b );

/* ------------------------------------------------------------------ Personas and oracle */

$pm_caps = array( 'manage_options', 'manage_woocommerce', 'edit_posts' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() && preg_match_all( "/'(wpcap_(?:manage|view|checkin|refund|approve|box_office|finance|support|partner)[a-z_]*)'/", (string) file_get_contents( $file->getPathname() ), $m ) ) {
		$pm_caps = array_merge( $pm_caps, $m[1] );
	}
}
$pm_caps = array_values( array_unique( $pm_caps ) );
$pm_staff_user = 9902;
$wpdb->query( "DELETE FROM {$p}wpcap_event_staff WHERE user_id=$pm_staff_user" );
// Staff of another event: holds the event-scoped capabilities (EventsPage::STAFF_CAPS) and is staff of event B only.
foreach ( \PassCaptain\Admin\EventsPage::STAFF_CAPS as $cap ) {
	$wpdb->insert( "{$p}wpcap_event_staff", array( 'event_id' => $pm_event_b, 'user_id' => $pm_staff_user, 'capability' => $cap ) );
}

function pm_persona( $name ) {
	global $pm_caps, $pm_staff_user;
	unset( $GLOBALS['wpcap_test_user_can'] );
	$GLOBALS['wpcap_test_denied_caps'] = array();
	$GLOBALS['wpcap_test_user_id'] = 1;
	if ( 'guest' === $name || 'subscriber' === $name ) {
		$GLOBALS['wpcap_test_user_id'] = 'guest' === $name ? 0 : 9901;
		$GLOBALS['wpcap_test_denied_caps'] = $pm_caps;
		$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => false;
	} elseif ( 'staff_other_event' === $name ) {
		// Event-scoped role capabilities (check-in, events, orders, reports) – but staff of event B only, no administrator.
		$GLOBALS['wpcap_test_user_id'] = $pm_staff_user;
		$GLOBALS['wpcap_test_denied_caps'] = array_values( array_diff( $pm_caps, \PassCaptain\Admin\EventsPage::STAFF_CAPS ) );
		$GLOBALS['wpcap_test_user_can'] = static fn( $u, $c ) => in_array( $c, \PassCaptain\Admin\EventsPage::STAFF_CAPS, true );
	}
}

/** Fingerprint of all plugin tables (audit log excluded: denied attempts may be audited) and options. */
function pm_snapshot() {
	global $wpdb;
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', str_replace( '_', '\\_', $wpdb->prefix . 'wpcap_' ) . '%' ) );
	$sums = array();
	foreach ( $tables as $table ) {
		// last_used/audit/rate-limit bookkeeping only; the unified support log (wpcap_logs) records refused attempts too.
		if ( preg_match( '/wpcap_(audit_logs|logs|partner_keys|api_keys|rate_limits)$/', $table ) ) { continue; }
		$row = $wpdb->get_row( "CHECKSUM TABLE `$table`", ARRAY_A );
		$sums[ $table ] = $row['Checksum'] ?? '';
	}
	$options = $GLOBALS['wpcap_test_options'];
	// Monitoring counter of failed payment webhooks (Operations\Monitor, A-line): a refused signature is counted.
	unset( $options['wpcap_wallet_device_log'], $options[ \PassCaptain\Operations\Monitor::WEBHOOK_OPTION ] );
	return array( 'tables' => $sums, 'options' => md5( serialize( $options ) ), 'raw' => $options );
}
function pm_changed( $before, $after ) {
	$diff = array();
	foreach ( $after['tables'] as $t => $sum ) { if ( ( $before['tables'][ $t ] ?? null ) !== $sum ) { $diff[] = preg_replace( '/^.*wpcap_/', '', $t ); } }
	if ( $before['options'] !== $after['options'] ) {
		$keys = array();
		foreach ( array_unique( array_merge( array_keys( $before['raw'] ), array_keys( $after['raw'] ) ) ) as $k ) {
			if ( serialize( $before['raw'][ $k ] ?? null ) !== serialize( $after['raw'][ $k ] ?? null ) ) { $keys[] = $k; }
		}
		$diff[] = 'options(' . implode( ',', $keys ) . ')';
	}
	return $diff;
}

function pm_request( $key, array $headers = array() ) {
	global $pm_event_a, $pm_occ_a, $pm_type_a, $pm_public_id, $pm_codes, $pm_ticket, $pm_resource, $pm_slot, $pm_pass_id, $pm_devkey, $pm_order_id, $pm_booking;
	list( $method, $route ) = explode( ' ', $key, 2 );
	$r = new WP_REST_Request( explode( ',', $method )[0], $route );
	$params = array(
		'event_id' => $pm_event_a, 'occurrence_id' => $pm_occ_a, 'ticket_type_id' => $pm_type_a, 'public_id' => $pm_public_id,
		'resource_id' => $pm_resource, 'slot_id' => $pm_slot, 'pass_id' => $pm_pass_id, 'key_id' => (int) $pm_devkey['api_key_id'],
		'waitlist_id' => 1, 'booking_id' => $pm_booking, 'entry_id' => 1, 'settlement_id' => 1, 'provider' => 'stripe', 'token' => $pm_codes[0] ?? '', 'ticket_public_id' => $pm_ticket->public_id,
		'serial' => 'PMSERIAL1', 'pass_type' => 'pass.test.wpcap', 'device' => 'pmdevice1', 'access_token' => str_repeat( '0', 64 ), 'sig' => 'x', 'code' => 'NOTAVALIDCODE0000000',
	);
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	$r->set_body( json_encode( array(
		'event_id' => $pm_event_a, 'occurrence_id' => $pm_occ_a, 'ticket_type_id' => $pm_type_a, 'order_id' => $pm_order_id,
		'items' => array( array( 'ticket_type_id' => $pm_type_a, 'quantity' => 1 ) ), 'email' => 'pm-attack@example.com', 'idempotency_key' => 'pm-' . md5( $key . microtime() ),
		'rules' => array( array( 'event_id' => $pm_event_a, 'ticket_type_id' => $pm_type_a ) ), 'valid_from' => gmdate( 'Y-m-d' ), 'valid_until' => gmdate( 'Y-m-d', time() + 86400 ),
		'name' => 'PM', 'seats' => array( array( 'seat_key' => 'a1', 'seat_label' => 'A1' ) ), 'days' => array( array( 'date' => gmdate( 'Y-m-d', time() + 7 * 86400 ), 'policy' => 'daily' ) ),
		'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', time() + 90000 ), 'scopes' => array( 'events.read' ), 'locale' => 'en_US',
		'tokens' => array( $pm_codes[0] ?? '' ), 'scans' => array( array( 'token' => $pm_codes[0] ?? '', 'idempotency_key' => 'pm-scan' ) ), 'code' => 'PMCODE', 'kind' => 'percent', 'value' => 10,
		'country_code' => 'DE', 'rate_basis_points' => 1900, 'effective_from' => '2030-01-01', 'version' => 'pm1',
	) ) );
	foreach ( $headers as $h => $v ) { $r->set_header( $h, $v ); }
	return $r;
}

/** Runs permission callback + handler. Returns [allowed (bool), response, changed tables]. */
function pm_call( array $endpoint, WP_REST_Request $r ) {
	$before = pm_snapshot();
	$allowed = true;
	$response = null;
	ob_start();
	try {
		$perm = '__return_true' === $endpoint['permission_callback'] ? true : call_user_func( $endpoint['permission_callback'], $r );
		$allowed = true === $perm;
		if ( $allowed ) { $response = call_user_func( $endpoint['callback'], $r ); }
	} catch ( Throwable $e ) {
		$response = new WP_Error( 'pm_exception', get_class( $e ) . ': ' . $e->getMessage() );
	}
	ob_end_clean();
	return array( $allowed, $response, pm_changed( $before, pm_snapshot() ) );
}
function pm_succeeded( $response ) {
	if ( is_wp_error( $response ) ) { return false; }
	if ( $response instanceof WP_REST_Response && $response->get_status() >= 300 ) { return false; }
	$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
	if ( is_array( $data ) && isset( $data['results'] ) && is_array( $data['results'] ) ) {
		// Batch endpoints answer 200 with one result per item: only an admitted item counts as success.
		foreach ( $data['results'] as $item ) {
			if ( in_array( (string) ( is_array( $item ) ? ( $item['status'] ?? '' ) : '' ), array( 'checked_in', 'checked_out', 'valid', 'ok' ), true ) ) { return true; }
		}
		return false;
	}
	return null !== $response;
}

/* ------------------------------------------------------------------ REST matrix */

$pm_failures = array();
$pm_admin_ok = array();
$pm_checked = 0;
foreach ( $pm_endpoints as $key => $endpoint ) {
	$spec = $pm_routes[ $key ] ?? '';
	list( $kind, $arg ) = array_pad( explode( ':', $spec, 2 ), 2, '' );
	$attempts = array();
	if ( 'cap' === $kind || 'report' === $kind ) {
		foreach ( array( 'guest', 'subscriber', 'staff_other_event' ) as $persona ) { $attempts[ $persona ] = array( $persona, array() ); }
		pm_persona( 'admin' );
		$perm = call_user_func( $endpoint['permission_callback'], pm_request( $key ) );
		if ( true !== $perm ) { $pm_admin_ok[] = $key; }
	}
	if ( 'report' === $kind || 'devkey' === $kind ) {
		$attempts['no key'] = array( 'guest', array() );
		$attempts['revoked key'] = array( 'guest', array( 'X-WPCAP-Key' => $pm_devkey_revoked['key'] ) );
		$attempts['expired key'] = array( 'guest', array( 'X-WPCAP-Key' => $pm_devkey_expired['key'] ) );
		$attempts['key of another event'] = array( 'guest', array( 'X-WPCAP-Key' => $pm_devkey_b['key'] ) );
	}
	if ( 'device' === $kind ) {
		$attempts['no device token'] = array( 'guest', array() );
		$attempts['invalid device token'] = array( 'guest', array( 'Authorization' => 'Bearer wpcapd_' . str_repeat( '1', 64 ) ) );
		$attempts['revoked device'] = array( 'guest', array( 'Authorization' => 'Bearer ' . $pm_device_revoked['token'] ) );
	}
	if ( 'partner' === $kind ) {
		$attempts['no partner key'] = array( 'guest', array() );
		$attempts['revoked partner key'] = array( 'guest', array( 'Authorization' => 'Bearer ' . $pm_pk_revoked['key'] ) );
		$attempts['expired partner key'] = array( 'guest', array( 'Authorization' => 'Bearer ' . $pm_pk_expired['key'] ) );
	}
	if ( 'token' === $kind ) {
		$attempts['guest without valid token'] = array( 'guest', array() );
	}
	foreach ( $attempts as $label => list( $persona, $headers ) ) {
		wpcap_test_reset_transients(); // Rate limits/lockouts must not mask the real decision.
		pm_persona( $persona );
		list( $allowed, $response, $changed ) = pm_call( $endpoint, pm_request( $key, $headers ) );
		++$pm_checked;
		if ( ! $changed && 'staff_other_event' === $label && false !== strpos( $arg, '|scoped-list' ) && $allowed && pm_succeeded( $response ) ) {
			$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
			$json = (string) json_encode( $data );
			if ( ! preg_match( '/"(event_id|pass_id)":(' . (int) $pm_event_a . '|' . (int) $pm_pass_id . ')\b/', $json ) && false === strpos( $json, 'pm-pass@' ) && false === strpos( $json, 'pm-a@' ) ) { continue; }
		}
		if ( ( $allowed && pm_succeeded( $response ) ) || $changed ) {
			$pm_failures[] = "$key as $label" . ( $changed ? ' changed ' . implode( ',', $changed ) : ' succeeded' );
		}
	}
	// A partner key must never expose another partner's event.
	if ( 'partner' === $kind ) {
		wpcap_test_reset_transients();
		pm_persona( 'guest' );
		$r = pm_request( $key, array( 'Authorization' => 'Bearer ' . $pm_pk_b['key'] ) );
		list( $allowed, $response ) = pm_call( $endpoint, $r );
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
		$json = is_wp_error( $data ) ? '' : json_encode( $data );
		if ( $allowed && ! is_wp_error( $data ) && preg_match( '/"(event_id|id)":' . $pm_event_a . '\b/', $json ) ) { $pm_failures[] = "$key with key of another partner exposes event A"; }
		++$pm_checked;
	}
}
pm_persona( 'admin' );
wpcap_test_reset_transients();
check( "REST: no unauthorized success or data change ($pm_checked attempts)" . ( $pm_failures ? ' – ' . implode( '; ', $pm_failures ) : '' ), ! $pm_failures );
check( 'REST: administrators pass every capability route (positive control)' . ( $pm_admin_ok ? ' – ' . implode( ', ', $pm_admin_ok ) : '' ), ! $pm_admin_ok );

/* ------------------------------------------------------------------ admin-post / admin-ajax matrix */

$pm_handlers = \PassCaptain\Admin\Menu::handlers() + array(
	'wpcap_print_invoice' => array( \PassCaptain\Invoices::class, 'handle_admin_print' ),
	'wpcap_export_accounting' => array( \PassCaptain\Invoices::class, 'handle_export' ),
	'wpcap_accept_waitlist' => array( \PassCaptain\Waitlists::class, 'handle_accept_request' ),
	'wpcap_payment_retry' => array( \PassCaptain\Payments\ReturnNotice::class, 'handle_retry' ),
	'wpcap_payment_release' => array( \PassCaptain\Payments\ReturnNotice::class, 'handle_release' ),
	'wpcap_service_portal' => array( \PassCaptain\Services\Portal::class, 'handle_post' ),
	'wpcap_portal_language' => array( \PassCaptain\Frontend\Language::class, 'handle_portal_language' ),
	'ajax:' . \PassCaptain\Mail\Admin::ACTION_PREVIEW => array( \PassCaptain\Mail\Admin::class, 'handle_preview_ajax' ),
	'ajax:wpcap_pos_device_status' => array( \PassCaptain\BoxOffice\Page::class, 'ajax_device_status' ),
	'ajax:wpcap_pos_device_cancel' => array( \PassCaptain\BoxOffice\Page::class, 'ajax_device_cancel' ),
	// Registered with class constants (Arbeitsliste line).
	'wpcap_payment_return' => array( \PassCaptain\Payments\ReturnNotice::class, 'handle_action' ),
	\PassCaptain\Waitlists::UNSUBSCRIBE_ACTION => array( \PassCaptain\Waitlists::class, 'handle_unsubscribe_request' ),
	\PassCaptain\Waitlists::CONFIRM_ACTION => array( \PassCaptain\Waitlists::class, 'handle_confirm_request' ),
	\PassCaptain\Admin\Notices::ACTION => array( \PassCaptain\Admin\Notices::class, 'handle_dismiss' ),
);
$src_actions = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WPCAP_PATH . 'src' ) ) as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() && preg_match_all( "/add_action\(\s*'(admin_post_(?:nopriv_)?|wp_ajax_(?:nopriv_)?)(?!nopriv_')([a-z0-9_]+)'/", (string) file_get_contents( $file->getPathname() ), $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) { $src_actions[] = ( 0 === strpos( $hit[1], 'wp_ajax' ) ? 'ajax:' : '' ) . $hit[2]; }
	}
}
$src_actions[] = 'ajax:' . \PassCaptain\Mail\Admin::ACTION_PREVIEW;
$missing = array_diff( array_unique( array_merge( array_keys( $pm_handlers ), $src_actions ) ), array_keys( $pm_admin ) );
check( count( $pm_handlers ) . ' admin-post/ajax actions, every one declared' . ( $missing ? ' – missing: ' . implode( ', ', $missing ) : '' ), ! $missing );

$pm_post_ids = array(
	'event_id' => $pm_event_a, 'occurrence_id' => $pm_occ_a, 'ticket_type_id' => $pm_type_a, 'type_id' => $pm_type_a, 'order_id' => $pm_order_id,
	'ticket_id' => (int) $pm_ticket->ticket_id, 'pass_id' => $pm_pass_id, 'resource_id' => $pm_resource, 'slot_id' => $pm_slot, 'key_id' => (int) $pm_devkey['api_key_id'],
	'id' => $pm_event_a, 'user_id' => 9902, 'user' => 'pm-staff', 'capability' => 'wpcap_manage_events', 'name' => 'PM changed', 'status' => 'cancelled', 'email' => 'pm-attack@example.com',
	'quantity' => 1, 'amount' => '1,00', 'reason' => 'pm', 'starts_at' => gmdate( 'Y-m-d\TH:i', time() + 86400 ), 'capacity' => 5, 'price' => '1,00', 'scopes' => array( 'events.read' ),
	'url' => 'https://pm-hook.example/in', 'events' => array( 'order.paid' ), 'retention_days' => 7, 'description' => 'pm',
	'partner_id' => $pm_partner_a, 'stripe_secret_key' => 'sk_test_pmattack', 'hold_minutes' => 99, 'code' => 'PMCODE', 'kind' => 'percent', 'value' => 10, 'confirm' => '1',
);
register_shutdown_function( static function () {
	if ( ! empty( $GLOBALS['pm_in_handler'] ) ) {
		echo "  FAIL admin action {$GLOBALS['pm_in_handler']} terminated the request (exit) for an unauthorized user\n";
		exit( 1 );
	}
} );
if ( ! class_exists( 'Pm_Download' ) ) { class Pm_Download extends Exception { public $body = ''; } }
$pm_capture = static function ( $pre, $body ) { $e = new Pm_Download( 'download' ); $e->body = (string) $body; throw $e; };
add_filter( 'wpcap_pre_send_download', $pm_capture, 10, 2 );
// A handler that ends the response itself (printable page, token page) ends through Http::end(): observe it.
$pm_end = static function () { throw new Pm_Download( 'response' ); };
add_action( 'wpcap_response_end', $pm_end );
$pm_admin_failures = array();
$pm_admin_checked = 0;
foreach ( $pm_handlers as $action => $handler ) {
	$spec = $pm_admin[ $action ] ?? '';
	// Every "do" sub-action of dispatching handlers is tried, so checks after the dispatch are covered too.
	$class = is_array( $handler ) && is_string( $handler[0] ) ? $handler[0] : '';
	$dos = array( '' );
	if ( $class ) {
		$source = (string) file_get_contents( ( new ReflectionClass( $class ) )->getFileName() );
		if ( preg_match_all( "/case '([a-z_]+)'/", $source, $m ) ) { $dos = array_merge( $dos, array_unique( $m[1] ) ); }
	}
	foreach ( 0 === strpos( $spec, 'token:' ) ? array( 'guest' ) : array( 'guest', 'subscriber', 'staff_other_event' ) as $persona ) {
		foreach ( $dos as $do ) {
			if ( 'staff_other_event' === $persona && 'manage_options' !== $spec && '' === $do && count( $dos ) > 1 ) { continue; }
			if ( 'staff_other_event' === $persona && isset( $pm_unscoped[ $action ] ) ) { continue; }
			wpcap_test_reset_transients();
			pm_persona( $persona );
			$_POST = $pm_post_ids + array( 'do' => $do, '_wpnonce' => 'n', 'token' => str_repeat( '0', 64 ), 'waitlist_id' => 1 );
			$_GET = $_POST;
			$_REQUEST = $_POST;
			$before = pm_snapshot();
			$GLOBALS['pm_in_handler'] = $action . ( '' !== $do ? "/$do" : '' ) . " as $persona";
			ob_start();
			$download = null;
			try { call_user_func( $handler ); } catch ( Pm_Download $e ) { $download = $e->body; } catch ( Throwable $e ) { /* wp_die / redirect fakes throw. */ }
			$output = (string) ob_get_clean();
			// A download (or printed document) may only contain data of the user's own events.
			// Token actions (visitor pages: service portal, waitlist links) answer every request with their own page
			// (an error page for a wrong token): only data of event A in it counts as a leak there.
			$by_content = 'staff_other_event' === $persona || 0 === strpos( $spec, 'token:' );
			$leak = null !== $download && ( ! $by_content || false !== strpos( $download, 'pm-a@example.com' ) || false !== strpos( $download, $pm_public_id ) );
			$leak = $leak || false !== strpos( $output, 'pm-a@example.com' );
			if ( $leak ) { $pm_admin_failures[] = $action . ( '' !== $do ? "/$do" : '' ) . " as $persona delivered data of event A"; }
			$GLOBALS['pm_in_handler'] = '';
			$changed = pm_changed( $before, pm_snapshot() );
			++$pm_admin_checked;
			if ( $changed ) { $pm_admin_failures[] = $action . ( '' !== $do ? "/$do" : '' ) . " as $persona changed " . implode( ',', $changed ); }
		}
	}
}
$_POST = $_GET = $_REQUEST = array();
remove_filter( 'wpcap_pre_send_download', $pm_capture, 10 );
remove_action( 'wpcap_response_end', $pm_end );
pm_persona( 'admin' );
wpcap_test_reset_transients();
check( "admin actions: unauthorized users change nothing ($pm_admin_checked attempts)" . ( $pm_admin_failures ? ' – ' . implode( '; ', array_slice( $pm_admin_failures, 0, 15 ) ) : '' ), ! $pm_admin_failures );
