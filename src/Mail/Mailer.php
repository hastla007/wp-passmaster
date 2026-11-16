<?php
/**
 * Central mailer for customer e-mails.
 *
 * Every customer-facing mail is identified by a type from the registry (Types::all()). The text
 * comes from an admin override (Templates, per type and locale) or from the built-in default, which
 * is the translatable German text the plugin always sent – so without overrides the content is
 * unchanged. Placeholders ({site_name}, {order_id}, …) are replaced in one pass; unknown ones become
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
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Mailer {
	/** Plain-text alternative for the mail currently being sent (consumed by phpmailer_init). */
	private static $alt_body = null;

	/** Envelope sender (Return-Path) for the mail currently being sent (consumed by phpmailer_init). */
	private static $envelope_sender = null;

	/** From name for the mail currently being sent (wp_mail_from_name, early priority). */
	private static $from_name = null;

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
	public static function send( $type, $to, array $vars, array $attachments = array(), $locale = '' ) {
		$message = self::compose( $type, $vars, $locale );
		if ( ! $message ) { return false; }
		$message['attachments'] = $attachments;
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
		$vars = self::resolve_vars( array_merge( self::global_vars(), $derived, $vars ) );
		$body = (string) ( $template['body'] ?? '' );
		if ( $html && ! self::has_tags( $body ) ) {
			// Plain text in HTML mode (e.g. a built-in default): treat it as text.
			$body = nl2br( esc_html( $body ) );
		}
		return array(
			'type' => (string) $type,
			'subject' => self::clean_subject( self::replace( (string) ( $template['subject'] ?? '' ), $vars, false ) ),
			'body' => self::replace( $body, $vars, $html ),
			'format' => $html ? 'html' : 'text',
			'locale' => (string) $locale,
			'attachments' => array(),
		);
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
		if ( 'html' === $message['format'] && $body !== $original ) {
			$base = rtrim( $original );
			if ( '' !== $base && str_starts_with( $body, $base ) ) {
				$suffix = substr( $body, strlen( $base ) );
				$body = $base . ( self::has_tags( $suffix ) ? wp_kses_post( $suffix ) : self::text_to_html( $suffix ) );
			} else {
				$body = self::has_tags( $body ) ? wp_kses_post( $body ) : self::text_to_html( $body );
			}
		}
		$message['subject'] = self::clean_subject( (string) ( $filtered['subject'] ?? $message['subject'] ) );
		$message['body'] = $body;
		$message['attachments'] = (array) ( $filtered['attachments'] ?? $message['attachments'] );
		return $message;
	}

	/**
	 * Sends a composed message: wraps HTML mails in the layout and adds the plain-text alternative.
	 *
	 * @param array $message subject, body, format, attachments, locale, type (optional: headers).
	 */
	public static function deliver( $to, array $message ) {
		$message = apply_filters( 'wpcap_mail', $message, $to );
		if ( ! is_array( $message ) ) { return false; }
		$prepared = self::prepare( $to, $message );
		$alt = $prepared['alt_body'];
		if ( null !== $alt ) {
			self::$alt_body = $alt;
			add_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ), 10, 1 );
		}
		// Sender settings only for the plugin's own mails, at an early priority: SMTP plugins (which hook
		// later or force their own sender) keep the last word.
		$sender = self::sender();
		self::$envelope_sender = '' !== $sender['from_email'] ? $sender['from_email'] : null;
		self::$from_name = $sender['from_name'];
		add_action( 'phpmailer_init', array( __CLASS__, 'apply_sender' ), 5, 1 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 5, 1 );
		try {
			do_action( 'wpcap_mail_before_send', $prepared, $message );
			$sent = wp_mail( $prepared['to'], $prepared['subject'], $prepared['body'], $prepared['headers'], $prepared['attachments'] );
		} finally {
			remove_action( 'phpmailer_init', array( __CLASS__, 'apply_sender' ), 5 );
			remove_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 5 );
			self::$envelope_sender = null;
			self::$from_name = null;
			if ( null !== $alt ) {
				remove_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ), 10 );
				self::$alt_body = null;
			}
		}
		return (bool) $sent;
	}

	/**
	 * Final wp_mail arguments of a message (layout applied for HTML).
	 *
	 * @return array to, subject, body, headers, attachments, alt_body (null for text mails).
	 */
	public static function prepare( $to, array $message ) {
		$subject = self::clean_subject( (string) ( $message['subject'] ?? '' ) );
		$body = (string) ( $message['body'] ?? '' );
		$headers = (array) ( $message['headers'] ?? array() );
		$alt = null;
		if ( 'html' === ( $message['format'] ?? 'text' ) ) {
			$locale = (string) ( $message['locale'] ?? '' );
			list( $body, $alt ) = self::with_locale( $locale, static function () use ( $body, $subject, $locale ) {
				return array( Layout::wrap( $body, $subject, $locale ), Layout::alt_text( $body ) );
			} );
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}
		$headers = self::sender_headers( $headers );
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
	 * Configured sender of customer mails.
	 *
	 * @return array{from_email:string,from_name:string,reply_to:string} Validated values; from_email and
	 *               reply_to are '' when not configured, from_name falls back to the site title.
	 */
	public static function sender() {
		$email = sanitize_email( (string) Settings::get( 'mail_from_email' ) );
		$reply = sanitize_email( (string) Settings::get( 'mail_reply_to' ) );
		$name = trim( (string) preg_replace( '/[\r\n<>"]+/', ' ', (string) Settings::get( 'mail_from_name' ) ) );
		return array(
			'from_email' => is_email( $email ) ? $email : '',
			'from_name' => '' !== $name ? $name : Orders::site_name(),
			'reply_to' => is_email( $reply ) ? $reply : '',
		);
	}

	/** Adds From / Reply-To headers from the settings unless the message already sets them. */
	private static function sender_headers( array $headers ) {
		$has = static function ( $name ) use ( $headers ) {
			foreach ( $headers as $header ) {
				if ( 0 === stripos( ltrim( (string) $header ), $name . ':' ) ) { return true; }
			}
			return false;
		};
		$sender = self::sender();
		if ( '' !== $sender['from_email'] && ! $has( 'From' ) ) {
			$headers[] = 'From: ' . self::quote_name( $sender['from_name'] ) . ' <' . $sender['from_email'] . '>';
		}
		if ( '' !== $sender['reply_to'] && ! $has( 'Reply-To' ) ) {
			$headers[] = 'Reply-To: ' . $sender['reply_to'];
		}
		return $headers;
	}

	/** Display name for a From header (quoted when it contains specials). */
	private static function quote_name( $name ) {
		$name = trim( str_replace( array( '"', '\\' ), '', (string) $name ) );
		return preg_match( '/[()<>\[\]:;@,.]/', $name ) ? '"' . $name . '"' : $name;
	}

	/** wp_mail_from_name (only while the plugin sends): site title / configured name instead of "WordPress". */
	public static function filter_from_name( $name ) {
		return null !== self::$from_name && '' !== self::$from_name ? self::$from_name : $name;
	}

	/**
	 * phpmailer_init (only while the plugin sends, priority 5): envelope sender (Return-Path) = configured
	 * From address, so SPF checks the sender domain. Left alone when something already set one.
	 */
	public static function apply_sender( $phpmailer ) {
		if ( null === self::$envelope_sender || ! is_object( $phpmailer ) ) { return; }
		if ( '' === (string) ( $phpmailer->Sender ?? '' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer property.
			$phpmailer->Sender = self::$envelope_sender; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer property.
		}
	}

	/**
	 * Sender problems for the system status page: no configured address (mails go out as
	 * wordpress@<host> unless an SMTP plugin sets a sender) or a sender domain that differs from the
	 * site domain (DMARC alignment needs SPF/DKIM for that domain).
	 *
	 * @return array[] {severity, message}
	 */
	public static function sender_issues() {
		$sender = self::sender();
		$host = strtolower( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : parse_url( home_url(), PHP_URL_HOST ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback before WordPress is loaded.
		$host = (string) preg_replace( '/^www\./', '', $host );
		if ( '' === $sender['from_email'] ) {
			return array( array( 'severity' => 'warning', 'message' => __( 'Für Kunden-E-Mails ist keine Absenderadresse eingestellt (Einstellungen → E-Mail-Versand). Ohne SMTP-Plugin gehen Tickets als „wordpress@…“ raus und landen eher im Spam; Antworten der Kund:innen gehen verloren.', 'pass-captain' ) ) );
		}
		$domain = strtolower( (string) substr( strrchr( $sender['from_email'], '@' ), 1 ) );
		if ( '' !== $host && $domain !== $host && ! str_ends_with( $host, '.' . $domain ) && ! str_ends_with( $domain, '.' . $host ) ) {
			/* translators: 1: sender domain, 2: site domain */
			return array( array( 'severity' => 'warning', 'message' => sprintf( __( 'Die Absenderadresse nutzt die Domain %1$s, die Website %2$s. Damit die Mails zugestellt werden, müssen SPF und DKIM für %1$s den Versand über diesen Server bzw. Ihren SMTP-Dienst erlauben (DMARC).', 'pass-captain' ), $domain, $host ) ) );
		}
		return array();
	}

	/** phpmailer_init (only registered while an HTML mail is sent): sets the plain-text part. */
	public static function apply_alt_body( $phpmailer ) {
		if ( null !== self::$alt_body && is_object( $phpmailer ) ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName -- PHPMailer property.
		}
	}

	/* ---------------------------------------------------------------- Rendering helpers */

	/** Placeholders available in every mail type. */
	public static function global_vars() {
		return array(
			'site_name' => Orders::site_name(),
			'site_url' => home_url( '/' ),
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
			return (string) ( $html ? ( $value['html'] ?? esc_html( (string) ( $value['text'] ?? '' ) ) ) : ( $value['text'] ?? '' ) );
		}
		if ( is_bool( $value ) || null === $value || is_object( $value ) ) { return ''; }
		return $html ? nl2br( esc_html( (string) $value ) ) : (string) $value;
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

	/** The site's default locale. */
	public static function site_locale() {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : self::current_locale();
		return '' !== $locale ? $locale : 'de_DE';
	}

	/** Sanitised locale code (e.g. de_DE, de_DE_formal) or ''. */
	public static function normalize_locale( $locale ) {
		$locale = (string) $locale;
		return preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}$/', $locale ) ? $locale : '';
	}

	/** Runs $callback in the given locale and restores the previous one afterwards. */
	public static function with_locale( $locale, callable $callback ) {
		$locale = self::normalize_locale( $locale );
		$switched = false;
		if ( '' !== $locale && $locale !== self::current_locale() && function_exists( 'switch_to_locale' ) ) {
			$switched = (bool) switch_to_locale( $locale );
		}
		try {
			return $callback();
		} finally {
			if ( $switched && function_exists( 'restore_previous_locale' ) ) { restore_previous_locale(); }
		}
	}
}

