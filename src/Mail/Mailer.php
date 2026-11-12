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
		try {
			do_action( 'wpcap_mail_before_send', $prepared, $message );
			$sent = wp_mail( $prepared['to'], $prepared['subject'], $prepared['body'], $prepared['headers'], $prepared['attachments'] );
		} finally {
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
		return array(
			'to' => $to,
			'subject' => $subject,
			'body' => $body,
			'headers' => $headers,
			'attachments' => array_values( (array) ( $message['attachments'] ?? array() ) ),
			'alt_body' => $alt,
		);
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

