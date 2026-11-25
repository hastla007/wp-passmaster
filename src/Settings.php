<?php
/**
 * Plugin settings (option wpcap_settings). Provider secrets (Secrets::KEYS) are never stored here:
 * Settings::update() routes them into the encrypted store (Secrets), all()/get() return their
 * empty defaults. Read secrets with Secrets::get().
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Settings {
	const OPTION = 'wpcap_settings';

	/**
	 * Uninstall opt-in ('delete' or 'keep'), stored outside wpcap_settings: the uninstall.php of the old
	 * copy "WP Pass Captain" (up to 1.4) reads wpcap_settings['delete_data_on_uninstall'], and on a multisite
	 * network it runs for every site, also where this copy's pre_uninstall_plugin guard is not loaded. The
	 * setting key delete_data_on_uninstall stays the interface (settings page, Settings::get()), but it is
	 * read from and written to this option; in wpcap_settings it is always 0.
	 */
	const UNINSTALL_POLICY = 'wpcap_uninstall_policy';

	/** Per-request cache of defaults() (module defaults contain translated texts). */
	private static $defaults = null;

	/** Per-request cache of all(): [stored option value, merged result]. */
	private static $all = null;

	/** Default values for every setting (core plus modules); built once per request. */
	public static function defaults() {
		if ( null === self::$defaults ) {
			$defaults = array_merge( self::core_defaults(), Modules::settings_defaults() );
			/**
			 * Add-on settings outside modules: key => default. Core and module keys cannot be replaced
			 * (only new keys are taken over). Edit them through wp_pass_captain_settings_sections_v1.
			 */
			$extra = apply_filters( 'wp_pass_captain_settings_defaults_v1', array() );
			self::$defaults = is_array( $extra ) ? $defaults + $extra : $defaults;
		}
		return self::$defaults;
	}

	/** Drops the memoized defaults/values (locale switch, text domain loaded, settings updated). */
	public static function flush() {
		self::$defaults = null;
		self::$all = null;
		if ( class_exists( Terms::class, false ) ) { Terms::flush(); }
		if ( class_exists( SettingsSchema::class, false ) ) { SettingsSchema::flush(); }
	}

	/** Invalidates the per-request cache when translations change. */
	public static function register() {
		add_action( 'change_locale', array( __CLASS__, 'flush' ), 0, 0 );
		add_action(
			'load_textdomain',
			static function ( $domain ) {
				if ( 'pass-captain' === $domain ) { self::flush(); }
			},
			0,
			1
		);
	}

	private static function core_defaults() {
		return array(
			'hold_minutes'              => 10,
			'campaign_max_codes'        => 3,
			'campaign_stacking'         => 'cumulative',
			'manual_hold_hours'         => 72,
			'manual_cutoff_hours'       => 0,
			// A634: unpaid bank transfer orders hold at most this share of a ticket type / date (0 = no bound).
			'manual_hold_max_percent'   => 50,
			// Absolute bound per event for tickets held by unpaid bank transfer orders (0 = off, the former behaviour).
			'manual_hold_max_event_tickets' => 0,
			// No payment method by default (A265): manual payment needs a bank account or instructions first.
			// Installs from before schema 3.10.9 keep 'manual' (Installer::pin_legacy_manual_provider()).
			'enabled_providers'         => array(),
			'manual_instructions'       => '',
			'bank_recipient'            => '',
			'bank_iban'                 => '',
			'bank_bic'                  => '',
			'stripe_mode'               => 'test',
			'stripe_secret_key'         => '',
			'stripe_webhook_secret'     => '',
			'paypal_mode'               => 'sandbox',
			'paypal_client_id'          => '',
			'paypal_client_secret'      => '',
			'paypal_webhook_id'         => '',
			'terms_text'                => '',
			'consumer_info_text'        => '',
			'require_terms'             => 0,
			// Withdrawal notices next to the order button (Frontend\LegalNotice); defaults, to be checked by the operator.
			'withdrawal_notice'         => 1,
			'withdrawal_notice_text'    => __( 'Hinweis: Bei Tickets für Veranstaltungen mit festem Termin besteht in der Regel kein Widerrufsrecht (§ 312g Abs. 2 Nr. 9 BGB / § 18 Abs. 1 Z 10 FAGG). Deine Bestellung ist nach dem Absenden verbindlich.', 'pass-captain' ),
			'withdrawal_right_text'     => __( 'Für Produkte ohne festen Veranstaltungstermin (z. B. Mitgliedspässe oder Gutscheine) besteht ein gesetzliches Widerrufsrecht. Einzelheiten findest du in der Widerrufsbelehrung in den Verbraucherinformationen.', 'pass-captain' ),
			'refund_approval_threshold' => 0,
			// A425: presale/service fee of the own online checkout (gross, minor units; 0 = off).
			'service_fee_ticket_minor'  => 0,
			'service_fee_order_minor'   => 0,
			// Owner decision 23.11.2026: tax of the fee 'ticket' (like its ticket line, ancillary service) or
			// 'own' (tax class of the tax profiles; rate fallback when no active profile matches, '' = ticket rate).
			'service_fee_tax'           => 'ticket',
			'service_fee_tax_class'     => 'service',
			'service_fee_tax_rate'      => '',
			// Owner decision 23.11.2026: refunds the buyer asked for (not caused by a cancellation) keep the fee
			// ('retain') or refund it ('refund'); organizer cancellations always refund it.
			'service_fee_refund'        => 'retain',
			// Owner decision 23.11.2026: the fee is the operator's revenue; on = it counts as organizer revenue in
			// partner settlements (and in the deal basis of the settlement sheet).
			'service_fee_to_partner'    => 0,
			// Postponed occurrence: buyers may request a refund in the order portal (with approval) until
			// the deadline (hours before the new start); the note goes into the reschedule mail ({refund_note}).
			'reschedule_refund_request' => 1,
			'reschedule_refund_hours'   => 0,
			/* translators: Keep the placeholder {refund_deadline} unchanged. */
			'reschedule_refund_note'    => __( 'Kannst du den neuen Termin nicht wahrnehmen? Dann kannst du bis {refund_deadline} über den persönlichen Link zu deiner Bestellung eine Erstattung beantragen; der Veranstalter prüft den Antrag.', 'pass-captain' ),
			// Legal texts of further languages: locale => [terms_text, consumer_info_text]. The plain
			// terms_text / consumer_info_text keys hold the texts of the default language (legal_default_locale()).
			'legal_texts'               => array(),
			// A643: off = approvers execute their own refunds above the threshold (previous behaviour).
			'refund_four_eyes_approvers' => 0,
			'require_2fa_for_refunds'   => 0,
			// Buyer cancellation/refund requests in the order portal until X hours before the start (0 = off).
			'portal_refund_request_hours' => 0,
			'attendee_retention_days'   => 0,
			'retention_unpaid_customer_email_days' => 90,
			'retention_message_content_days' => 30,
			'retention_message_rows_days' => 365,
			'retention_checkin_staff_days' => 365,
			'retention_audit_logs_days' => 730,
			'retention_waitlist_days'   => 30,
			'retention_partner_codes_days' => 30,
			'retention_financial_years' => 0,
			'attendee_edit_hours'       => 24,
			// Privacy\Retention: order personal data after the statutory period (years from the end of
			// the order's calendar year), waitlists after the date, member passes after valid_until.
			'retention_order_years'     => 10,
			'retention_pass_days'       => 365,
			'seller_name'               => '',
			'seller_address'            => '',
			'seller_vat_id'             => '',
			'seller_tax_number'         => '',
			'seller_country'            => 'DE',
			// A115: whoever created/last edited a tax profile draft may not activate it (administrators exempt).
			'tax_profile_four_eyes'     => 1,
			// Platform operator is a small business (DE § 19 UStG / AT § 6 Abs. 1 Z 27 UStG): no VAT on its invoices.
			'seller_small_business'     => 0,
			'seller_small_business_note' => '',
			'invoice_prefix'            => 'WPC',
			'billing_required_threshold_minor' => 25000,
			'credit_note_prefix'        => 'RK', // Rechnungskorrektur; installs from before schema 3.8.3 keep 'GS' (Installer).
			'auto_invoice'              => 0,
			// A380: chart of accounts of the DATEV booking batch (skr03|skr04) and an optional own money account.
			'datev_chart'               => 'skr03',
			'datev_contra_account'      => '',
			// SET-PC-37: own accounts per VAT rate (revenue, bu_keys, revenue_default) and money account per payment
			// provider (contra_by_provider); empty = the accounts of the chart (former behaviour).
			'datev_accounts'            => array(),
			// SET-PC-15: service date of invoice lines without an occurrence: 'none' (no date, former behaviour),
			// 'payment' (payment date), 'valid_from' (start of validity), 'period' (validity period).
			'invoice_service_date_fallback' => 'none',
			// SET-PC-03: number patterns per document kind (defaults = the former format PREFIX-YYYY-000123, yearly).
			'invoice_number_pattern'    => '{prefix}-{year}-{number}',
			'invoice_number_digits'     => 6,
			'invoice_number_start'      => 0,
			'invoice_number_yearly'     => 1,
			'credit_number_pattern'     => '{prefix}-{year}-{number}',
			'credit_number_digits'      => 6,
			'credit_number_start'       => 0,
			'credit_number_yearly'      => 1,
			'receipt_number_pattern'    => '{prefix}-{year}-{number}',
			'receipt_number_digits'     => 6,
			'receipt_number_start'      => 0,
			'receipt_number_yearly'     => 1,
			'settlement_number_pattern' => '{prefix}-{year}-{number}',
			'settlement_number_digits'  => 6,
			'settlement_number_start'   => 0,
			'settlement_number_yearly'  => 1,
			'self_billing_number_pattern' => '{prefix}-{year}-{number}',
			'self_billing_number_digits' => 6,
			'self_billing_number_start' => 0,
			'self_billing_number_yearly' => 1,
			// SET-PC-18: minimum age rule. default_min_age pre-fills new ticket types (0 = none); min_age_checkbox
			// makes the buyer confirm the age in the own checkout when a line has a minimum age; the label text
			// ('' = built-in text) has translations per language in legal_texts.
			'default_min_age'           => 0,
			'min_age_checkbox'          => 0,
			'min_age_notice_text'       => '',
			// Voucher Captain redemptions without purpose data: 'multi' (payment, VAT at redemption) or 'single'.
			'voucher_default_purpose'   => 'multi',
			'delete_data_on_uninstall'  => 0,
			// A327: support log also stores debug entries (ends 24 h after switching on; constant WPCAP_DEBUG overrides).
			'debug_logging'             => 0,
			// SET-PC-20: buyer rules (defaults = the former fixed values; the filters of the same name keep precedence).
			'order_access_days'         => 30,
			'payment_resume_link'       => 'show',
			'max_attendee_name_changes' => 3,
			'max_holder_changes'        => 1,
			'max_line_quantity'         => 100,
			'manual_open_orders_per_email' => 2,
			// SET-PC-21: logs and periods (defaults = the former fixed values; filters keep precedence).
			'log_retention_days'        => 30,
			'log_min_level'             => 'info',
			'connector_log_days'        => 30,
			'print_batch_keep_days'     => 7,
			'review_no_show_grace_hours' => 6,
			'offline_default_hours'     => 12,
			'scanner_show_attendee'     => 0,
			// A690: terms for the people a ticket is for ('' = Teilnehmende, guests, visitors, custom) and own lines "Alt = Neu".
			'attendee_terms'            => '',
			'term_replacements'         => '',
			// Signed QR payloads (PC1.…) instead of the plain ticket code in new QR codes; scanners accept both.
			'signed_qr'                 => 0,
			'device_max_hours'          => 24,
			'admission_day_cutoff_hour' => 0,
			'auto_refund_cancelled'     => 0,
			// Payments\Cases: refund double payments and payments for cancelled/expired orders automatically.
			'auto_refund_unmatched_payments' => 0,
			// Payments\Reconcile: ask the provider about pending orders whose webhook did not arrive (minutes).
			'payment_poll_after_minutes' => 15,
			// A273: payments that arrive after the hold and can no longer be allocated are refunded automatically.
			'late_payment_auto_refund'  => 0,
			// Reverse proxies / CDN whose forwarding header is trusted (ClientIp); empty = REMOTE_ADDR only.
			'trusted_proxies'           => '',
			'client_ip_header'          => 'x-forwarded-for',
			// Entry window for ticket types without their own admission rule: 2 h before start until the end.
			'default_entry_window'      => 1,
			'entry_opens_before_minutes' => 120,
			'entry_closes_after_minutes' => 0,
			// Forwarding header read from a trusted proxy (RateLimit::client_ip(); client_ip_header above is honoured
			// while this is the default).
			'trusted_proxy_header'      => 'x_forwarded_for',
			// Fiscal services on the local network (plain http, loopback/private addresses) are blocked unless enabled.
			'fiscal_allow_local_service' => 0,
			// Sender of customer mails (Mail\Mailer): From address/name, Reply-To. Empty address: WordPress
			// default (wordpress@<host>) unless an SMTP plugin sets one; empty name: site title.
			'mail_from_email'           => '',
			'mail_from_name'            => '',
			'mail_reply_to'             => '',
			'alert_email'               => '', // A400: recipient of operational alerts ('' = admin e-mail).
			// Bounce/complaint webhook of the sending service (Mail\Suppression), off by default.
			'mail_bounce_webhook'       => 0,
			// Frontend\EventPage: event detail pages /{event_base}/{slug}/ with schema.org/Event.
			'event_pages'               => 1,
			'event_base'                => 'event',
			// Admin\Dashboard: modules whose menu entries are hidden (they keep running).
			'hidden_modules'            => array(),
			'disabled_modules'          => array(), // A299: modules switched off on the settings screen.
			// A535: occurrences without an end time count as over after this many hours (Occurrence).
			'occurrence_default_duration_hours' => 6,
			// A296: mail_from_name / mail_reply_to above ('' = seller name or site name / admin e-mail).
			'mail_rate_per_minute'      => 0,
			// SET-PC-08: offered currencies ('' = built-in list) and the currency of new prices.
			'currencies'                => '',
			'default_currency'          => 'EUR',
			// SET-PC-19: imprint and privacy page ('' privacy = WordPress privacy page, '' imprint = no link).
			'imprint_url'               => '',
			'privacy_url'               => '',
			// SET-PC-24: countries offered in the billing address ('' = built-in list) and the optional region field.
			'billing_countries'         => '',
			'billing_region_field'      => 0,
			// SET-PC-29: WooCommerce (0 = automatic hold: core hold, at least WooCommerce "hold stock").
			'woo_hold_minutes'          => 0,
			'woo_issue_on_completed_gateways' => 'cod',
			'woo_collect_attendees'     => 'fields',
			// SET-PC-40: payment method rules, one per line ("stripe | events=1,2 | min=10 | max=500 | countries=DE,AT").
			'payment_rules'             => '',
			// SET-PC-45: ticket prices entered gross (default) or net; buyers always see gross prices.
			'price_entry'               => 'gross',
			// SET-PC-36: date/time format of mails, tickets and documents in the site language ('' = WordPress setting).
			'document_date_format'      => '',
			'document_time_format'      => '',
			// SET-PC-38: rate limits for operators (per hour); the settings register sets safe lower bounds.
			'rate_checkout_ip_per_hour' => 30,
			'rate_checkout_email_per_hour' => 8,
			'rate_checkout_unpaid_per_network' => 10,
			'rate_waitlist_ip_per_hour' => 60,
			'rate_waitlist_email_per_hour' => 5,
			'rate_portal_resend_per_hour' => 3,
			'rate_portal_assign_per_hour' => 20,
			'rate_portal_pdf_per_hour'  => 20,
			// Bot check of the public checkout form (honeypot + minimum fill time; only requests of the plugin's own form).
			'checkout_bot_check'        => 1,
			'checkout_min_fill_seconds' => 3,
		);
	}

	/** All settings merged with defaults. */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		// Secrets are never exposed through the general settings (legacy plaintext/ciphertext before migration included).
		$stored = array_diff_key( $stored, array_flip( Secrets::KEYS ) );
		$stored['delete_data_on_uninstall'] = self::deletes_data_on_uninstall() ? 1 : 0;
		// The option is re-read every time (another site, direct update_option), the merge is reused.
		if ( null === self::$all || self::$all[0] !== $stored ) {
			self::$all = array( $stored, array_merge( self::defaults(), $stored ) );
		}
		return self::$all[1];
	}

	/** One setting; $fallback when it is unknown (not stored and no default in defaults()). */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * Settings that hold provider secrets. Secrets::KEYS live in the credential store (Secrets); further
	 * keys registered by add-ons are stored encrypted in wpcap_settings (Security\Vault, "pcv1:" values).
	 * get()/all() never return a plaintext secret, only secret() does.
	 */
	const SECRET_KEYS = array(
		'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret',
		'mollie_api_key', 'twilio_auth_token', 'sms_http_secret',
		'apple_pass_cert', 'apple_pass_key', 'apple_pass_key_password', 'apple_wwdr_cert', 'google_wallet_service_account',
	);

	/** Secret setting keys (filter wpcap_secret_settings lets add-ons register their own). */
	public static function secret_keys() {
		$keys = function_exists( 'apply_filters' ) ? (array) apply_filters( 'wpcap_secret_settings', self::SECRET_KEYS ) : self::SECRET_KEYS;
		return array_values( array_unique( array_map( 'strval', array_merge( Secrets::KEYS, $keys ) ) ) );
	}

	public static function is_secret( $key ) {
		return in_array( (string) $key, self::secret_keys(), true );
	}

	/**
	 * Persist already sanitised values (unknown keys are ignored). Provider secrets (Secrets::KEYS) are
	 * encrypted into the credential store ('' removes them) and never written to wpcap_settings; secret
	 * keys registered by add-ons (filter wpcap_secret_settings) are encrypted in place (Security\Vault).
	 */
	public static function update( array $values ) {
		$values = array_intersect_key( $values, self::defaults() );
		if ( array_key_exists( 'delete_data_on_uninstall', $values ) ) {
			update_option( self::UNINSTALL_POLICY, empty( $values['delete_data_on_uninstall'] ) ? 'keep' : 'delete', false );
		}
		// One header choice for both client address resolvers (RateLimit::client_ip(), ClientIp::get()).
		if ( isset( $values['trusted_proxy_header'] ) && is_string( $values['trusted_proxy_header'] ) ) {
			$map = array( 'x_forwarded_for' => 'x-forwarded-for', 'forwarded' => 'forwarded', 'cf_connecting_ip' => 'cf-connecting-ip' );
			$values['client_ip_header'] = $map[ $values['trusted_proxy_header'] ] ?? 'x-forwarded-for';
		}
		// Plaintext / "pcv1:" values from before the store would otherwise be dropped below without being moved.
		self::migrate_secrets_to_store();
		if ( Secrets::migration_pending() ) { Secrets::migrate(); }
		$secret_changes = array(); // SET-PC-13: logged as "changed"/"removed", never with a value.
		foreach ( Secrets::KEYS as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$secret_changes[ $key ] = ! is_scalar( $values[ $key ] ) || '' === (string) $values[ $key ];
				Secrets::set( $key, is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '' );
				unset( $values[ $key ] );
			}
		}
		foreach ( self::secret_keys() as $key ) {
			if ( array_key_exists( $key, $values ) && ! isset( $secret_changes[ $key ] ) ) { $secret_changes[ $key ] = ! is_string( $values[ $key ] ) || '' === $values[ $key ]; }
			if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$values[ $key ] = Security\Vault::encrypt( $values[ $key ], $key );
			}
		}
		$stored = array_diff_key( self::all(), array_flip( Secrets::KEYS ) );
		// SET-PC-11: persist only keys that are stored or saved now, so reset keys keep following their defaults.
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? array_diff_key( $raw, array_flip( Secrets::KEYS ) ) : array();
		$merged = array_merge( $raw, $values );
		$merged['delete_data_on_uninstall'] = 0; // Never in the old key (see UNINSTALL_POLICY).
		update_option( self::OPTION, $merged, true ); // Autoloaded (PERF-11): read on every request; no plaintext secrets inside.
		self::$all = null;
		Security\Redact::reset();
		PageCache::settings_changed( $stored, $values ); // A446: legal texts are part of every listing.
		if ( class_exists( SettingsManager::class ) ) { SettingsManager::log_update( $stored, $values, $secret_changes ); } // SET-PC-13
		// A568: every wording that comes into force is archived (append-only) for later proof.
		if ( array_intersect_key( $values, array_flip( array_merge( self::LEGAL_KEYS, array( 'legal_texts' ) ) ) ) ) {
			LegalVersions::archive_all();
		}
	}

	/**
	 * Removes stored values so their defaults apply again (SET-PC-11). Secrets are never removed here.
	 *
	 * @param string[] $keys     Keys to remove.
	 * @param array    $defaults key => default, only for the change log.
	 */
	public static function forget( array $keys, array $defaults = array() ) {
		$keys = array_values( array_diff( array_map( 'strval', $keys ), self::secret_keys(), array( 'delete_data_on_uninstall' ) ) );
		// The derived client_ip_header follows trusted_proxy_header (otherwise the old header would stay trusted).
		if ( in_array( 'trusted_proxy_header', $keys, true ) ) { $keys[] = 'client_ip_header'; }
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$before = array_diff_key( self::all(), array_flip( Secrets::KEYS ) );
		$after = array_diff_key( $stored, array_flip( $keys ) );
		if ( $after === $stored ) { return; }
		update_option( self::OPTION, $after, true );
		self::$all = null;
		Security\Redact::reset();
		PageCache::settings_changed( $before, array_intersect_key( $defaults, array_flip( $keys ) ) );
		SettingsManager::log_update( $before, array_intersect_key( self::all(), array_flip( $keys ) ) );
		if ( array_intersect( $keys, array_merge( self::LEGAL_KEYS, array( 'legal_texts' ) ) ) ) { LegalVersions::archive_all(); }
	}

	/** Option holding "pcv1:" values that could not be decrypted while moving them into Secrets (ciphertext only). */
	const UNREADABLE_SECRETS_OPTION = 'wpcap_secrets_unreadable_legacy';

	/**
	 * Upgrade step (idempotent, checks the data, not a version): moves provider secrets that are still in
	 * wpcap_settings – plaintext (releases up to 1.4) or encrypted in place with Security\Vault ("pcv1:",
	 * audit line 3.11–3.17) – into the credential store (Secrets). Must run before Secrets::migrate(), which
	 * would otherwise seal the "pcv1:" ciphertext as if it were the secret. A value that cannot be
	 * decrypted (key changed) is parked as ciphertext in UNREADABLE_SECRETS_OPTION and retried on later
	 * runs (e.g. once PASS_CAPTAIN_SECRET_KEY_PREVIOUS is configured); it never overwrites a stored value.
	 *
	 * @return string[] Names moved into the store.
	 */
	public static function migrate_secrets_to_store() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$parked = get_option( self::UNREADABLE_SECRETS_OPTION, array() );
		$parked = is_array( $parked ) ? $parked : array();
		$found = array_intersect_key( $raw, array_flip( Secrets::KEYS ) );
		if ( ! $found && ! $parked ) { return array(); }
		$moved = array();
		$parked_before = $parked;
		foreach ( $found as $key => $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( Security\Vault::is_encrypted( $value ) ) {
				$plain = Security\Vault::decrypt( $value, $key );
				if ( null === $plain ) {
					$parked[ $key ] = $value;
					$value = '';
				} else {
					$value = (string) $plain;
				}
			}
			if ( '' !== $value ) {
				Secrets::set( $key, $value );
				$moved[] = $key;
			}
			unset( $raw[ $key ] );
		}
		foreach ( $parked as $key => $value ) {
			if ( in_array( $key, $moved, true ) ) { unset( $parked[ $key ] ); continue; }
			$plain = Security\Vault::decrypt( (string) $value, (string) $key );
			if ( null === $plain ) { continue; }
			if ( '' !== (string) $plain && Secrets::is_secret( $key ) && '' === (string) Secrets::stored( $key ) ) {
				Secrets::set( $key, (string) $plain );
				$moved[] = (string) $key;
			}
			unset( $parked[ $key ] );
		}
		// Store first: if the request dies in between, the old value is still there and the next run repeats.
		if ( $parked !== $parked_before ) {
			if ( $parked ) { update_option( self::UNREADABLE_SECRETS_OPTION, $parked, false ); } else { delete_option( self::UNREADABLE_SECRETS_OPTION ); }
		}
		if ( $found ) {
			update_option( self::OPTION, $raw, true );
			self::flush();
		}
		if ( $moved || $parked !== $parked_before ) {
			Audit::record( 'secrets_moved_to_store', 'settings', 0, array( 'keys' => $moved, 'unreadable' => array_keys( $parked ) ) );
		}
		return $moved;
	}

	/** Whether the operator opted in to removing all data when the plugin is deleted (uninstall.php). */
	public static function deletes_data_on_uninstall() {
		return 'delete' === get_option( self::UNINSTALL_POLICY, 'keep' );
	}

	/**
	 * Upgrade step (per site, idempotent): moves an opt-in still stored in wpcap_settings to
	 * UNINSTALL_POLICY and sets the old key to 0, so the old copy's uninstall.php keeps the data.
	 */
	public static function migrate_uninstall_policy() {
		$settings = get_option( self::OPTION, array() );
		// LegacySlug parked the opt-in (set the old key to 0) while the old copy existed: it was an opt-in.
		$parked = (bool) get_option( 'wpcap_uninstall_delete_parked', false ) && false === get_option( self::UNINSTALL_POLICY, false );
		if ( $parked ) {
			update_option( self::UNINSTALL_POLICY, 'delete', false );
			delete_option( 'wpcap_uninstall_delete_parked' );
		}
		if ( is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] ) ) {
			update_option( self::UNINSTALL_POLICY, 'delete', false );
			$settings['delete_data_on_uninstall'] = 0;
			update_option( self::OPTION, $settings, true );
			self::$all = null;
		} elseif ( false === get_option( self::UNINSTALL_POLICY, false ) ) {
			update_option( self::UNINSTALL_POLICY, 'keep', false );
		}
	}

	/**
	 * Marks the settings option as autoloaded on existing installs (it was stored with autoload=no,
	 * which cost an extra query on every request). Idempotent; runs with the schema upgrade.
	 */
	public static function ensure_autoload() {
		if ( false === get_option( self::OPTION, false ) ) { return false; }
		if ( function_exists( 'wp_set_option_autoload' ) ) { return (bool) wp_set_option_autoload( self::OPTION, true ); }
		return false;
	}

	/** Name of the constant/environment variable (WPCAP_<KEY>) that overrides a key, or ''. */
	public static function override_source( $key ) {
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return $constant; }
		$env = getenv( $constant );
		return is_string( $env ) && '' !== $env ? $constant : '';
	}

	/**
	 * Secret lookup: constant (e.g. WPCAP_STRIPE_SECRET_KEY) > environment variable > stored option
	 * (decrypted). A stored value that cannot be decrypted (key lost) counts as empty.
	 *
	 * @param string $key Settings key, e.g. "paypal_client_id".
	 */
	public static function secret( $key ) {
		if ( Secrets::is_secret( $key ) ) { return Secrets::get( $key ); }
		$constant = 'WPCAP_' . strtoupper( $key );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) { return (string) constant( $constant ); }
		$env = getenv( $constant );
		if ( is_string( $env ) && '' !== $env ) { return $env; }
		$stored = self::get( $key );
		if ( ! is_string( $stored ) ) { return is_scalar( $stored ) ? (string) $stored : ''; }
		return (string) Security\Vault::decrypt( $stored, $key );
	}

	/**
	 * State of a secret for admin screens (never the value itself).
	 *
	 * @return array{state:string,hint:string,source:string} state: override|stored|undecryptable|empty.
	 */
	public static function secret_status( $key ) {
		$override = self::override_source( $key );
		if ( '' !== $override ) { return array( 'state' => 'override', 'hint' => '', 'source' => $override ); }
		if ( Secrets::is_secret( $key ) ) {
			$plain = Secrets::stored( $key );
			if ( false === $plain ) { return array( 'state' => 'undecryptable', 'hint' => '', 'source' => '' ); }
			if ( '' === (string) $plain ) { return array( 'state' => 'empty', 'hint' => '', 'source' => '' ); }
			return array( 'state' => 'stored', 'hint' => Security\Vault::mask( (string) $plain ), 'source' => '' );
		}
		$stored = self::get( $key );
		if ( ! is_string( $stored ) || '' === $stored ) { return array( 'state' => 'empty', 'hint' => '', 'source' => '' ); }
		$plain = Security\Vault::decrypt( $stored, $key );
		if ( null === $plain ) { return array( 'state' => 'undecryptable', 'hint' => '', 'source' => '' ); }
		return array( 'state' => 'stored', 'hint' => Security\Vault::mask( $plain ), 'source' => '' );
	}

	/** Keys of the legal texts that exist per language. */
	const LEGAL_KEYS = array( 'terms_text', 'consumer_info_text' );

	/**
	 * Texts with translations per language in legal_texts: the legal texts (versioned with each order,
	 * LEGAL_KEYS) plus short checkout texts that are not part of the contract version (SET-PC-18).
	 */
	const TEXT_KEYS = array( 'terms_text', 'consumer_info_text', 'min_age_notice_text' );

	/**
	 * Locale of the default-language legal texts (the plain terms_text / consumer_info_text keys):
	 * Polylang's or WPML's default language, otherwise the site locale. Filter: wpcap_legal_default_locale.
	 */
	public static function legal_default_locale() {
		$locale = '';
		if ( function_exists( 'pll_default_language' ) ) {
			$locale = (string) pll_default_language( 'locale' );
		}
		$code = '' === $locale ? (string) apply_filters( 'wpml_default_language', '' ) : '';
		if ( '' !== $code ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $language ) {
				if ( is_array( $language ) && $code === (string) ( $language['code'] ?? '' ) ) { $locale = (string) ( $language['default_locale'] ?? '' ); }
			}
		}
		if ( '' === $locale ) { $locale = Mail\Mailer::site_locale(); }
		$locale = Mail\Mailer::normalize_locale( (string) apply_filters( 'wpcap_legal_default_locale', $locale ) );
		return '' !== $locale ? $locale : 'de_DE';
	}

	/**
	 * Locales offered for legal texts: the default locale first, then the languages of Polylang/WPML,
	 * installed language packs and locales that already have texts. Filter: wpcap_legal_locales.
	 *
	 * @return string[]
	 */
	public static function legal_locales() {
		$default = self::legal_default_locale();
		$list = array( $default );
		if ( function_exists( 'pll_languages_list' ) ) {
			$list = array_merge( $list, (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
		}
		foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $language ) {
			if ( is_array( $language ) && ! empty( $language['default_locale'] ) ) { $list[] = (string) $language['default_locale']; }
		}
		if ( function_exists( 'get_available_languages' ) ) {
			$list = array_merge( $list, (array) get_available_languages() );
		}
		$list = array_merge( $list, array_map( 'strval', array_keys( (array) self::get( 'legal_texts' ) ) ) );
		$list = (array) apply_filters( 'wpcap_legal_locales', $list, $default );
		$out = array( $default );
		foreach ( $list as $locale ) {
			$locale = Mail\Mailer::normalize_locale( is_string( $locale ) ? $locale : '' );
			if ( '' !== $locale && ! in_array( $locale, $out, true ) ) { $out[] = $locale; }
		}
		return $out;
	}

	/** Stored texts of the further languages: locale => [key => text] (only non-empty texts). */
	public static function legal_translations() {
		$out = array();
		foreach ( (array) self::get( 'legal_texts' ) as $locale => $texts ) {
			$locale = Mail\Mailer::normalize_locale( (string) $locale );
			if ( '' === $locale || ! is_array( $texts ) ) { continue; }
			foreach ( self::TEXT_KEYS as $key ) {
				if ( isset( $texts[ $key ] ) && is_scalar( $texts[ $key ] ) && '' !== trim( (string) $texts[ $key ] ) ) { $out[ $locale ][ $key ] = (string) $texts[ $key ]; }
			}
		}
		return $out;
	}

	/**
	 * Locale whose stored text is used for $key when a buyer uses $locale: the exact locale, else
	 * another locale of the same language (en_GB -> en_US), else the default language.
	 *
	 * @param string      $key    terms_text or consumer_info_text.
	 * @param string|null $locale Buyer locale; null = determine_locale().
	 */
	public static function legal_source_locale( $key, $locale = null ) {
		$default = self::legal_default_locale();
		$locale = Mail\Mailer::normalize_locale( null === $locale ? Mail\Mailer::current_locale() : (string) $locale );
		if ( '' === $locale || $locale === $default ) { return $default; }
		$translations = self::legal_translations();
		if ( isset( $translations[ $locale ][ $key ] ) ) { return $locale; }
		$language = strtok( $locale, '_' );
		// Same language as the default (de_AT with default de_DE): the default text is the right one.
		if ( strtok( $default, '_' ) === $language ) { return $default; }
		foreach ( $translations as $candidate => $texts ) {
			if ( isset( $texts[ $key ] ) && strtok( (string) $candidate, '_' ) === $language ) { return (string) $candidate; }
		}
		return $default;
	}

	/**
	 * Legal text for a buyer locale with fallback to the default language (see legal_source_locale()).
	 *
	 * @param string      $key    terms_text or consumer_info_text.
	 * @param string|null $locale Buyer locale; null = determine_locale().
	 */
	public static function legal_text( $key, $locale = null ) {
		if ( ! in_array( $key, self::TEXT_KEYS, true ) ) { return ''; }
		$source = self::legal_source_locale( $key, $locale );
		if ( $source === self::legal_default_locale() ) { return (string) self::get( $key ); }
		return self::legal_translations()[ $source ][ $key ] ?? (string) self::get( $key );
	}

	/**
	 * SET-PC-18: label of the age confirmation in the checkout for a buyer locale: the text of that language
	 * (legal_texts, fallback as legal_text()), else the built-in text in the current language. {min_age} is
	 * replaced by the highest minimum age of the lines.
	 *
	 * @param int         $min_age Highest minimum age of the checkout lines.
	 * @param string|null $locale  Buyer locale; null = determine_locale().
	 */
	public static function min_age_notice( $min_age, $locale = null ) {
		$text = trim( wp_strip_all_tags( self::legal_text( 'min_age_notice_text', $locale ) ) );
		if ( '' === $text ) {
			/* translators: keep the placeholder {min_age} (minimum age in years) */
			$text = __( 'Ich bestätige, dass alle Personen, für die ich Tickets kaufe, mindestens {min_age} Jahre alt sind.', 'pass-captain' );
		}
		return str_replace( '{min_age}', (string) max( 0, (int) $min_age ), $text );
	}

	/**
	 * Version hash of the legal texts a buyer of $locale is shown in the checkout (stored with each
	 * order). Hashes exactly the texts legal_text() returns, so the default language keeps the
	 * hash it had before texts per language existed.
	 *
	 * @param string|null $locale Buyer locale; null = determine_locale().
	 */
	public static function terms_version( $locale = null ) {
		$text = trim( self::legal_text( 'terms_text', $locale ) ) . "\n--\n" . trim( self::legal_text( 'consumer_info_text', $locale ) );
		return "\n--\n" === $text ? '' : substr( hash( 'sha256', $text ), 0, 16 );
	}
}
