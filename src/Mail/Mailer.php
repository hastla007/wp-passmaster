<?php
/**
 * Central mailer for customer e-mails.
 *
 * Every customer-facing mail is identified by a type from the registry (Types::all()). The text
 * comes from an admin override (Templates, per type and locale) or from the built-in default, which
 * is the translatable German text the plugin always sent – so without overrides the content is
 * unchanged. Built-in defaults are sent as HTML with layout and plain-text alternative
 * (Templates::default_format(), filter `wpcap_mail_default_format`); overrides keep their format. Placeholders ({site_name}, {order_id}, …) are replaced in one pass; unknown ones become
 * empty. In HTML mode values are escaped and the content is wrapped in a simple layout (Layout)
 * with an automatic plain-text alternative (PHPMailer AltBody); in text mode nothing is escaped.
 *
 * Variable values may be
 *   - scalars (escaped and nl2br'd in HTML mode),
 *   - Mailer::block( $text, $html ) arrays with pre-rendered, trusted variants per format,
 *   - callables returning either of the above; they run inside the recipient's locale, so
 *     translated fragments (e.g. a refund note) follow the recipient's language.
 *
 * Hooks: `wpcap_mail_types` (registry), `wpcap_mail` (message before sending),
 * `wpcap_mail_before_send` (action with the final wp_mail arguments).
 */
namespace PassCaptain\Mail;

use PassCaptain\Orders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mailer {
	/** Plain-text alternative for the mail currently being sent (consumed by phpmailer_init). */
	private static $alt_body = null;

	/** Mail type registry (key => definition). */
	public static function types() {
		return Types::all();
	}

	/** Definition of one type, or null. */
	public static function type( $type ) {
		$types = self::types();
		return $types[ (string) $type ] ?? null;
	}

	/** A value with separate plain-text and HTML representations (HTML must already be safe). */
	public static function block( $text, $html ) {
		return array( 'text' => (string) $text, 'html' => (string) $html );
	}

	/**
	 * Renders and sends a mail of the given type.
	 *
	 * @param string   $type        Registry key.
	 * @param string   $to          Recipient address.
	 * @param array    $vars        Placeholder values (without braces).
	 * @param string[] $attachments File paths.
	 * @param string   $locale      Recipient locale (e.g. the order's locale); '' keeps the current one.
	 * @return bool Whether wp_mail accepted the message.
	 */
	/**
	 * Whether every recipient is a placeholder address of the reserved domain invalid.example (sales
	 * without a buyer e-mail) or another reserved .invalid address – such mails are never sent.
	 *
	 * @param string|string[] $to Recipient(s).
	 */
	public static function is_placeholder( $to ) {
		$list = array_filter( array_map( 'trim', is_array( $to ) ? $to : explode( ',', (string) $to ) ) );
		if ( ! $list ) { return false; }
		foreach ( $list as $address ) {
			$domain = strtolower( (string) substr( strrchr( $address, '@' ) ?: '', 1 ) );
			if ( 'invalid.example' !== $domain && 'invalid' !== $domain && '.invalid' !== substr( $domain, -8 ) ) { return false; }
		}
		return true;
	}

	/**
	 * Composes and sends a mail.
	 *
	 * @param int $order_id Order the mail belongs to (support log/order history, A328); 0 = Log scope.
	 */
	public static function send( $type, $to, array $vars, array $attachments = array(), $locale = '', $order_id = 0 ) {
		$message = self::compose( $type, $vars, $locale );
		if ( ! $message ) { return false; }
		$message['attachments'] = $attachments;
		if ( absint( $order_id ) ) { $message['order_id'] = absint( $order_id ); }
		return self::deliver( $to, $message );
	}

	/**
	 * Renders a mail without sending it.
	 *
	 * @return array|null subject, body (content without layout), format (text|html), type, locale, attachments.
	 */
	public static function compose( $type, array $vars, $locale = '' ) {
		if ( ! self::type( $type ) ) { return null; }
		return self::with_locale( $locale, static function () use ( $type, $vars, $locale ) {
			return self::render_template( $type, Templates::resolve( $type, $locale ), $vars, $locale );
		} );
	}

	/**
	 * Renders a given template (subject/body/format) with variables. Must run inside the target locale.
	 *
	 * @param array $template subject, body, format.
	 */
	public static function render_template( $type, array $template, array $vars, $locale = '' ) {
		$html = 'html' === ( $template['format'] ?? 'text' );
		$def = self::type( $type );
		// Optional per-type derived values (e.g. payment details of an order); passed values win.
		$derived = $def && isset( $def['vars'] ) && is_callable( $def['vars'] ) ? (array) call_user_func( $def['vars'], $vars ) : array();
		$vars = self::resolve_vars( array_merge( self::global_vars( $locale ), $derived, $vars ) );
		$body = (string) ( $template['body'] ?? '' );
		$text_body = null;
		if ( $html && ! self::has_tags( $body ) ) {
			// Plain text in HTML mode (e.g. a built-in default): treat it as text. The same template
			// rendered in text mode becomes the plain-text alternative, so it reads exactly like the
			// historic text mail instead of an HTML-to-text conversion.
			$text_body = self::replace( $body, $vars, false );
			$body = nl2br( esc_html( $body ) );
		}
		$message = array(
			'type' => (string) $type,
			'subject' => self::clean_subject( self::replace( (string) ( $template['subject'] ?? '' ), $vars, false ) ),
			'body' => self::replace( $body, $vars, $html ),
			'format' => $html ? 'html' : 'text',
			'locale' => (string) $locale,
			'attachments' => array(),
		);
		return null === $text_body ? $message : self::with_text_body( $message, $text_body );
	}

	/**
	 * Attaches a plain-text rendering to an HTML message. It is bound to the current HTML body: if
	 * a filter replaces the body later, prepare() falls back to converting the HTML.
	 */
	public static function with_text_body( array $message, $text ) {
		$message['text_body'] = (string) $text;
		$message['text_for'] = sha1( (string) $message['body'] );
		return $message;
	}

	/** The plain-text rendering of an HTML message if it still matches its body, else null. */
	public static function text_body( array $message ) {
		if ( ! isset( $message['text_body'], $message['text_for'] ) ) { return null; }
		return sha1( (string) ( $message['body'] ?? '' ) ) === $message['text_for'] ? (string) $message['text_body'] : null;
	}

	/**
	 * Applies the result of a legacy array filter (subject/body/attachments) to a composed message.
	 * Plain text appended to an HTML body by such filters (e.g. seat list, wallet links) is
	 * converted to HTML so it keeps its line breaks and cannot inject markup; appended HTML is
	 * passed through wp_kses_post.
	 */
	public static function apply_filtered( array $message, $filtered ) {
		if ( ! is_array( $filtered ) ) { return $message; }
		$original = (string) $message['body'];
		$body = (string) ( $filtered['body'] ?? $original );
		$text = self::text_body( $message );
		if ( 'html' === $message['format'] && $body !== $original ) {
			$base = rtrim( $original );
			if ( '' !== $base && str_starts_with( $body, $base ) ) {
				$suffix = substr( $body, strlen( $base ) );
				$body = $base . ( self::has_tags( $suffix ) ? wp_kses_post( $suffix ) : self::text_to_html( $suffix ) );
				// Keep the plain-text part in step: the appended text (or its text form) goes there too.
				$text = null === $text ? null : rtrim( $text ) . ( self::has_tags( $suffix ) ? "\n\n" . Layout::to_text( $suffix ) : $suffix );
			} else {
				$body = self::has_tags( $body ) ? wp_kses_post( $body ) : self::text_to_html( $body );
				$text = null;
			}
		}
		$message['subject'] = self::clean_subject( (string) ( $filtered['subject'] ?? $message['subject'] ) );
		$message['body'] = $body;
		unset( $message['text_body'], $message['text_for'] );
		if ( null !== $text ) { $message = self::with_text_body( $message, $text ); }
		$message['attachments'] = (array) ( $filtered['attachments'] ?? $message['attachments'] );
		return $message;
	}

	/**
	 * Sends a composed message: wraps HTML mails in the layout and adds the plain-text alternative.
	 *
	 * @param array $message subject, body, format, attachments, locale, type (optional: headers).
	 */
	public static function deliver( $to, array $message ) {
		if ( self::is_placeholder( $to ) ) {
			// Box office, guest list or partner sale without a buyer address: nothing to deliver. Done
			// here, not only in pre_wp_mail, because SMTP plugins may replace wp_mail() entirely.
			do_action( 'wpcap_mail_suppressed', $to, (string) ( $message['type'] ?? '' ) );
			return true;
		}
		// A677: an optional mail type the site owner handed to the workflow provider is not sent natively
		// when the provider takes it; otherwise (no provider, refusal, error) it is sent here as always.
		if ( \PassCaptain\Workflow\MailHandoff::hand_off( $to, $message ) ) { return true; }
		$message = apply_filters( 'wpcap_mail', $message, $to );
		if ( ! is_array( $message ) ) { return false; }
		list( $to, $message ) = self::recipients( $to, $message );
		$prepared = self::prepare( $to, $message );
		$from_name = self::sender()['name'];
		$apply_name = static function () use ( $from_name ) { return $from_name; };
		if ( '' !== $from_name ) { add_filter( 'wp_mail_from_name', $apply_name, 10, 0 ); }
		$alt = $prepared['alt_body'];
		if ( null !== $alt ) {
			self::$alt_body = $alt;
			add_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ), 10, 1 );
		}
		$sent = false;
		try {
			do_action( 'wpcap_mail_before_send', $prepared, $message );
			\PassCaptain\Operations\Monitor::mail_begin();
			$sent = wp_mail( $prepared['to'], $prepared['subject'], $prepared['body'], $prepared['headers'], $prepared['attachments'] );
		} finally {
			// Failure counter for the Site Health check (A164): type and error code only, no recipient.
			\PassCaptain\Operations\Monitor::mail_end( (bool) $sent, (string) ( $message['type'] ?? '' ) );
			if ( '' !== $from_name ) { remove_filter( 'wp_mail_from_name', $apply_name, 10 ); }
			if ( null !== $alt ) {
				remove_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ), 10 );
				self::$alt_body = null;
			}
		}
		self::log_outcome( (bool) $sent, $to, $message );
		return (bool) $sent;
	}

	/**
	 * Filter wpcap_mail_recipients (A331): recipients of a customer mail as ['to' => string|string[],
	 * 'cc' => string[], 'bcc' => string[]] (e.g. a copy to the organiser). Invalid addresses are
	 * dropped, at most 10 per field; without a valid "to" the original recipient stays. Cc/Bcc become
	 * mail headers.
	 *
	 * @return array [to, message]
	 */
	private static function recipients( $to, array $message ) {
		$filtered = apply_filters( 'wpcap_mail_recipients', array( 'to' => $to, 'cc' => array(), 'bcc' => array() ), $message );
		if ( ! is_array( $filtered ) ) { return array( $to, $message ); }
		$clean = static function ( $list ) {
			$list = is_array( $list ) ? $list : explode( ',', (string) $list );
			$out = array();
			foreach ( $list as $address ) {
				$address = is_string( $address ) ? sanitize_email( $address ) : '';
				if ( is_email( $address ) && ! self::is_placeholder( $address ) ) { $out[ strtolower( $address ) ] = $address; }
			}
			return array_slice( array_values( $out ), 0, 10 );
		};
		$new_to = $clean( $filtered['to'] ?? array() );
		if ( $new_to ) { $to = 1 === count( $new_to ) && ! is_array( $to ) ? $new_to[0] : $new_to; }
		$headers = (array) ( $message['headers'] ?? array() );
		foreach ( array( 'cc' => 'Cc', 'bcc' => 'Bcc' ) as $field => $header ) {
			$list = $clean( $filtered[ $field ] ?? array() );
			if ( $list ) { $headers[] = $header . ': ' . implode( ', ', $list ); }
		}
		$message['headers'] = $headers;
		return array( $to, $message );
	}

	/**
	 * Support log entry of a plugin mail (A328): type, number of recipients and outcome, attributed to
	 * the order of $message['order_id'] or of the current Log scope – never addresses or content.
	 */
	private static function log_outcome( $sent, $to, array $message ) {
		$recipients = count( array_filter( array_map( 'trim', is_array( $to ) ? $to : explode( ',', (string) $to ) ) ) );
		$type = sanitize_key( (string) ( $message['type'] ?? '' ) );
		$context = array( 'mail_type' => $type, 'recipients' => $recipients );
		if ( ! empty( $message['order_id'] ) ) { $context['order_id'] = absint( $message['order_id'] ); }
		if ( $sent ) {
			\PassCaptain\Log::info( 'mail', 'Mail sent: ' . $type, $context );
			return;
		}
		$failure = \PassCaptain\Operations\Monitor::last_mail_failure_text();
		\PassCaptain\Log::warning( 'mail', 'Mail not sent: ' . $type . ( '' !== $failure ? ' – ' . $failure : '' ), $context );
	}

	/**
	 * Final wp_mail arguments of a message (layout applied for HTML).
	 *
	 * @return array to, subject, body, headers, attachments, alt_body (null for text mails).
	 */
	public static function prepare( $to, array $message ) {
		$subject = self::clean_subject( (string) ( $message['subject'] ?? '' ) );
		$body = (string) ( $message['body'] ?? '' );
		$headers = self::sender_headers( (array) ( $message['headers'] ?? array() ) );
		$alt = null;
		if ( 'html' === ( $message['format'] ?? 'text' ) ) {
			$locale = (string) ( $message['locale'] ?? '' );
			$text = self::text_body( $message );
			list( $body, $alt ) = self::with_locale( $locale, static function () use ( $body, $subject, $locale, $text ) {
				return array( Layout::wrap( $body, $subject, $locale ), Layout::alt_text( $body, $text ) );
			} );
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		return array(
			'to' => $to,
			'subject' => $subject,
			'body' => $body,
			'headers' => $headers,
			'attachments' => array_values( (array) ( $message['attachments'] ?? array() ) ),
			'alt_body' => $alt,
		);
	}

	/**
	 * Sender of buyer mails (A296): display name (setting mail_from_name, else the seller name, else
	 * the site name) and reply-to address (setting mail_reply_to, else the admin e-mail). The From
	 * address stays WordPress'/the SMTP plugin's, so SPF/DKIM keep matching. Filter `wpcap_mail_sender`.
	 *
	 * @return array name, reply_to ('' = none).
	 */
	public static function sender() {
		$name = trim( (string) \PassCaptain\Settings::get( 'mail_from_name' ) );
		if ( '' === $name ) { $name = trim( (string) \PassCaptain\Settings::get( 'seller_name' ) ); }
		if ( '' === $name ) { $name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ); }
		$reply = trim( (string) \PassCaptain\Settings::get( 'mail_reply_to' ) );
		if ( '' === $reply ) { $reply = trim( (string) get_option( 'admin_email' ) ); }
		$sender = (array) apply_filters( 'wpcap_mail_sender', array( 'name' => $name, 'reply_to' => $reply ) );
		$reply = sanitize_email( (string) ( $sender['reply_to'] ?? '' ) );
		return array(
			// No line breaks or angle brackets in a header value.
			'name' => trim( preg_replace( '/[\r\n<>"]+/', ' ', (string) ( $sender['name'] ?? '' ) ) ),
			'reply_to' => is_email( $reply ) ? $reply : '',
		);
	}

	/**
	 * Mail headers with the buyer-mail Reply-To added (A296); a Reply-To already present (template,
	 * filter) is kept.
	 *
	 * @param string[] $headers Headers.
	 */
	public static function sender_headers( array $headers = array() ) {
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && 0 === stripos( ltrim( $header ), 'reply-to:' ) ) { return $headers; }
		}
		$reply = self::sender()['reply_to'];
		if ( '' !== $reply ) { $headers[] = 'Reply-To: ' . $reply; }
		return $headers;
	}

	/**
	 * Sends a buyer mail that does not go through deliver() (e.g. service bookings) with the sender
	 * name and Reply-To of sender().
	 */
	public static function send_plain( $to, $subject, $body ) {
		$name = self::sender()['name'];
		$apply = static function () use ( $name ) { return $name; };
		if ( '' !== $name ) { add_filter( 'wp_mail_from_name', $apply, 10, 0 ); }
		try {
			return (bool) wp_mail( $to, $subject, $body, self::sender_headers() );
		} finally {
			if ( '' !== $name ) { remove_filter( 'wp_mail_from_name', $apply, 10 ); }
		}
	}

	/** phpmailer_init (only registered while an HTML mail is sent): sets the plain-text part. */
	public static function apply_alt_body( $phpmailer ) {
		if ( null !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer property.
		}
	}

	/* ---------------------------------------------------------------- Rendering helpers */

	/** Placeholders available in every mail type. */
	public static function global_vars( $locale = '' ) {
		$locale = self::normalize_locale( $locale );
		$locale = '' !== $locale ? $locale : self::current_locale();
		$legal = static function ( $key ) use ( $locale ) {
			return static function () use ( $key, $locale ) {
				$text = \PassCaptain\Settings::legal_text( $key, $locale );
				return '' === trim( $text ) ? '' : self::block( Layout::to_text( $text ), wp_kses_post( function_exists( 'wpautop' ) ? wpautop( $text ) : $text ) );
			};
		};
		return array(
			'site_name' => Orders::site_name(),
			'site_url' => self::link( home_url( '/' ) ),
			// Legal texts in the recipient's language (fallback: default language), built only when used.
			'terms_text' => $legal( 'terms_text' ),
			'consumer_info_text' => $legal( 'consumer_info_text' ),
		);
	}

	/** Evaluates callables (inside the current locale). */
	private static function resolve_vars( array $vars ) {
		foreach ( $vars as $key => $value ) {
			if ( $value instanceof \Closure ) {
				$vars[ $key ] = call_user_func( $value );
			}
		}
		return $vars;
	}

	/**
	 * Replaces {placeholders} in a single pass (values are not scanned again); unknown ones become ''.
	 *
	 * @param bool $html Escape values for HTML.
	 */
	public static function replace( $template, array $vars, $html ) {
		return (string) preg_replace_callback( '/\{([a-z0-9_]+)\}/', static function ( $m ) use ( $vars, $html ) {
			return array_key_exists( $m[1], $vars ) ? self::value( $vars[ $m[1] ], $html ) : '';
		}, (string) $template );
	}

	/** One placeholder value in the requested format. */
	private static function value( $value, $html ) {
		if ( is_array( $value ) ) {
			return (string) ( $html ? ( $value['html'] ?? esc_html( (string) ( $value['text'] ?? '' ) ) ) : \PassCaptain\Text::plain( $value['text'] ?? '' ) );
		}
		if ( is_bool( $value ) || null === $value || is_object( $value ) ) { return ''; }
		// Text mails and subjects are plain text: stored entities ("Kids &lt;3") are decoded (A497).
		return $html ? nl2br( esc_html( (string) $value ) ) : \PassCaptain\Text::plain( (string) $value );
	}

	/** Whether a string contains common HTML markup (anything else, e.g. "<x>", counts as text). */
	public static function has_tags( $text ) {
		return 1 === preg_match( '#</?(?:a|abbr|b|blockquote|br|center|code|div|em|font|h[1-6]|hr|i|img|li|ol|p|pre|small|span|strong|sub|sup|table|tbody|td|th|thead|tr|u|ul)\b[^>]*>#i', (string) $text );
	}

	/** Subjects are single-line plain text. */
	public static function clean_subject( $subject ) {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) $subject ) );
	}

	/** Plain text to safe HTML: escaped, URLs linked, line breaks kept. */
	public static function text_to_html( $text ) {
		$html = esc_html( (string) $text );
		$html = (string) preg_replace_callback( '#https?://[^\s<>"\']+#', static function ( $m ) {
			return '<a href="' . $m[0] . '">' . $m[0] . '</a>';
		}, $html );
		return nl2br( $html );
	}

	/** Link value for templates (URL as text, <a> in HTML). */
	public static function link( $url, $label = '' ) {
		$url = esc_url_raw( (string) $url );
		$label = '' !== (string) $label ? (string) $label : $url;
		return self::block( $url, '<a href="' . esc_attr( $url ) . '">' . esc_html( $label ) . '</a>' );
	}

	/* ---------------------------------------------------------------- Locale */

	/** Locale WordPress currently uses. */
	public static function current_locale() {
		if ( function_exists( 'determine_locale' ) ) { return (string) determine_locale(); }
		return function_exists( 'get_locale' ) ? (string) get_locale() : '';
	}

	/**
	 * The site's default locale. Inside with_locale() get_locale() reports the switched locale, so the
	 * site locale captured before the outermost switch is returned there (A384, A518).
	 */
	public static function site_locale() {
		if ( self::$active_locales && '' !== self::$outer_site_locale ) { return self::$outer_site_locale; }
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : self::current_locale();
		return '' !== $locale ? $locale : 'de_DE';
	}

	/** @var string Site locale before the outermost with_locale() switched (see site_locale()). */
	private static $outer_site_locale = '';

	/** Sanitised locale code (e.g. de_DE, de_DE_formal) or ''. */
	public static function normalize_locale( $locale ) {
		$locale = (string) $locale;
		return preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}$/', $locale ) ? $locale : '';
	}

	/**
	 * Runs $callback in the given locale and restores the previous one afterwards.
	 *
	 * switch_to_locale() refuses locales without an installed WordPress core language pack. The plugin
	 * ships its own catalogues (languages/pass-captain-<locale>.mo), so the buyer's mail would fall back to
	 * the site language although a translation exists (A306). In that case only the plugin's text domain
	 * is loaded for the buyer's locale (a translate.wordpress.org language pack in wp-content/languages/plugins
	 * wins over the bundled file) and the site's catalogue is restored afterwards. Dates and numbers then
	 * keep the site's format (WordPress' own strings stay in the site language).
	 */
	public static function with_locale( $locale, callable $callback ) {
		$locale = self::normalize_locale( $locale );
		$switched = false;
		$plugin_only = false;
		if ( ! self::$active_locales ) { self::$outer_site_locale = self::site_locale(); }
		if ( '' !== $locale && $locale !== self::current_locale() && function_exists( 'switch_to_locale' ) ) {
			$switched = (bool) switch_to_locale( $locale );
			if ( ! $switched ) { $plugin_only = self::load_plugin_catalog( $locale ); }
		}
		// A384: dates, times and the reading direction of what the callback builds follow this locale.
		self::$active_locales[] = '' !== $locale ? $locale : self::active_locale();
		try {
			return $callback();
		} finally {
			array_pop( self::$active_locales );
			if ( $switched && function_exists( 'restore_previous_locale' ) ) { restore_previous_locale(); }
			if ( $plugin_only ) { self::restore_plugin_catalog(); }
		}
	}

	/** @var string[] Locales of the with_locale() calls in progress (innermost last). */
	private static $active_locales = array();

	/**
	 * A384: locale of the mail or document currently being built (innermost with_locale()); '' outside
	 * of with_locale() (site/user language).
	 */
	public static function active_locale() {
		return self::$active_locales ? (string) end( self::$active_locales ) : '';
	}

	/** The plugin catalogue (.mo) for a locale: language pack first, then the bundled file; '' when none. */
	public static function plugin_catalog( $locale ) {
		$locale = self::normalize_locale( $locale );
		if ( '' === $locale ) { return ''; }
		$candidates = array();
		if ( defined( 'WP_LANG_DIR' ) ) { $candidates[] = WP_LANG_DIR . '/plugins/pass-captain-' . $locale . '.mo'; }
		$candidates[] = WPCAP_PATH . 'languages/pass-captain-' . $locale . '.mo';
		foreach ( $candidates as $file ) {
			if ( is_readable( $file ) ) { return $file; }
		}
		return '';
	}

	/** Loads only the plugin's text domain in $locale (core language pack missing). */
	private static function load_plugin_catalog( $locale ) {
		$file = self::plugin_catalog( $locale );
		if ( '' === $file || ! function_exists( 'load_textdomain' ) || ! function_exists( 'unload_textdomain' ) ) { return false; }
		unload_textdomain( 'pass-captain', true );
		if ( ! load_textdomain( 'pass-captain', $file, $locale ) ) {
			self::restore_plugin_catalog();
			return false;
		}
		\PassCaptain\Settings::flush();
		return true;
	}

	/** Reloads the plugin's text domain for the current (site/user) locale. */
	private static function restore_plugin_catalog() {
		unload_textdomain( 'pass-captain', true );
		if ( function_exists( 'load_plugin_textdomain' ) && function_exists( 'plugin_basename' ) ) {
			load_plugin_textdomain( 'pass-captain', false, dirname( plugin_basename( WPCAP_FILE ) ) . '/languages' );
		}
		\PassCaptain\Settings::flush();
	}
}

