<?php
/**
 * Simple, mail-client friendly HTML wrapper (table layout, inline styles) for HTML templates,
 * and the plain-text alternative derived from the content.
 *
 * Settings (module MailTemplates, key `mail_layout`): logo_url (falls back to the theme's custom
 * logo, then to the site name as text), color (accent colour), footer (plain text).
 */
namespace PassCaptain\Mail;

use PassCaptain\Orders;
use PassCaptain\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Layout {
	const DEFAULT_COLOR = '#2271b1';

	/** Layout settings merged with defaults. */
	public static function settings() {
		$stored = Settings::get( 'mail_layout' );
		$s = array_merge( array( 'logo_url' => '', 'color' => self::DEFAULT_COLOR, 'footer' => '' ), is_array( $stored ) ? $stored : array() );
		$s['color'] = self::sanitize_color( $s['color'] );
		return $s;
	}

	/** #rgb / #rrggbb or the default colour. */
	public static function sanitize_color( $color ) {
		$color = trim( (string) $color );
		return preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color ) ? strtolower( $color ) : self::DEFAULT_COLOR;
	}

	/** Logo URL: configured, theme custom logo or ''. */
	public static function logo_url() {
		$url = (string) self::settings()['logo_url'];
		if ( '' === $url && function_exists( 'get_theme_mod' ) && function_exists( 'wp_get_attachment_image_url' ) ) {
			$id = (int) get_theme_mod( 'custom_logo' );
			$url = $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
		}
		return '' !== $url ? esc_url_raw( $url ) : '';
	}

	/** Languages written right to left (ISO 639 codes of WordPress locales). */
	const RTL_LANGUAGES = array( 'ar', 'arc', 'azb', 'ckb', 'dv', 'fa', 'haz', 'he', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi' );

	/**
	 * A522: reading direction of a mail: 'rtl' for right-to-left languages, otherwise 'ltr'. Without a
	 * locale the mail being built (Mailer::active_locale()), else the site language.
	 *
	 * @param string|null $locale WordPress locale.
	 */
	public static function direction( $locale = null ) {
		$locale = null !== $locale && '' !== (string) $locale ? (string) $locale : Mailer::active_locale();
		if ( '' === $locale ) { $locale = Mailer::site_locale(); }
		$lang = strtolower( (string) strtok( $locale, '_' ) );
		$rtl = in_array( $lang, self::RTL_LANGUAGES, true );
		/**
		 * Reading direction of a mail (A522).
		 *
		 * @param bool   $rtl    Whether the language is written right to left.
		 * @param string $locale Locale of the mail.
		 */
		return apply_filters( 'wpcap_mail_rtl', $rtl, $locale ) ? 'rtl' : 'ltr';
	}

	/** A522: the "start" side of a line ('left' in LTR, 'right' in RTL) for inline styles. */
	public static function start_side( $locale = null ) {
		return 'rtl' === self::direction( $locale ) ? 'right' : 'left';
	}

	/** A522: the "end" side of a line ('right' in LTR, 'left' in RTL). */
	public static function end_side( $locale = null ) {
		return 'rtl' === self::direction( $locale ) ? 'left' : 'right';
	}

	/** Full HTML document around already safe content. */
	public static function wrap( $content, $subject = '', $locale = '' ) {
		$s = self::settings();
		$color = $s['color'];
		$site = Orders::site_name();
		$logo = self::logo_url();
		$lang = str_replace( '_', '-', '' !== (string) $locale ? (string) $locale : Mailer::site_locale() );
		$dir = self::direction( '' !== (string) $locale ? (string) $locale : null );
		$align = 'rtl' === $dir ? 'right' : 'left';
		$header = '' !== $logo
			? '<img src="' . esc_attr( $logo ) . '" alt="' . esc_attr( $site ) . '" style="max-width:200px;max-height:80px;height:auto;border:0;display:block">'
			: '<span style="font-size:20px;font-weight:bold;color:' . esc_attr( $color ) . '">' . esc_html( $site ) . '</span>';
		$footer = '' !== trim( (string) $s['footer'] ) ? Mailer::text_to_html( (string) $s['footer'] ) : esc_html( $site );
		$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		// A625: color-scheme meta + dark-mode overrides (Apple Mail, Outlook.com/app, Gmail apps honour
		// them), and an MSO ghost table that gives desktop Outlook (Word engine, ignores max-width) the
		// fixed 600 px column.
		$dark = '@media (prefers-color-scheme:dark){.wpcap-bg{background:#101517 !important}.wpcap-card{background:#1d2327 !important}.wpcap-text{color:#f0f0f1 !important}.wpcap-muted{color:#c3c4c7 !important;border-color:#3c434a !important}}'
			. '[data-ogsc] .wpcap-text{color:#f0f0f1 !important}[data-ogsb] .wpcap-card{background:#1d2327 !important}';
		$html = '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $dir ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . esc_html( $subject ) . '</title>'
			. '<style>:root{color-scheme:light dark;supported-color-schemes:light dark}a{color:' . esc_attr( $color ) . '}code{font-family:Menlo,Consolas,monospace;font-size:13px;word-break:break-all}' . $dark . '</style></head>'
			. '<body dir="' . esc_attr( $dir ) . '" class="wpcap-bg" style="margin:0;padding:0;background:#f4f4f5">'
			. '<table role="presentation" class="wpcap-bg" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5"><tr><td align="center" style="padding:24px 12px">'
			. '<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0"><tr><td><![endif]-->'
			. '<table role="presentation" class="wpcap-card" dir="' . esc_attr( $dir ) . '" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-top:4px solid ' . esc_attr( $color ) . ';border-radius:4px">'
			. '<tr><td style="padding:24px 28px 0;text-align:' . $align . '">' . $header . '</td></tr>'
			. '<tr><td class="wpcap-text" style="padding:16px 28px 24px;text-align:' . $align . ';font-family:' . esc_attr( $font ) . ';font-size:15px;line-height:1.55;color:#1d2327">' . $content . '</td></tr>'
			. '<tr><td class="wpcap-muted" style="padding:16px 28px;text-align:' . $align . ';border-top:1px solid #e5e5e5;font-family:' . esc_attr( $font ) . ';font-size:12px;line-height:1.5;color:#50575e">' . $footer . '</td></tr>'
			. '</table>'
			. '<!--[if mso]></td></tr></table><![endif]-->'
			. '</td></tr></table></body></html>';
		return (string) apply_filters( 'wpcap_mail_layout_html', $html, $content, $subject );
	}

	/**
	 * Plain-text alternative of the content (plus footer text).
	 *
	 * @param string      $content HTML content.
	 * @param string|null $text    Ready plain-text rendering of the same content (built-in texts), or null to convert.
	 */
	public static function alt_text( $content, $text = null ) {
		$text = null !== $text ? (string) $text : self::to_text( $content );
		$footer = trim( (string) self::settings()['footer'] );
		return '' !== $footer ? rtrim( $text ) . "\n\n-- \n" . $footer : $text;
	}

	/** Converts HTML to readable plain text: links as "text (url)", paragraphs and breaks kept. */
	public static function to_text( $html ) {
		$t = (string) preg_replace( '#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$t = (string) preg_replace_callback( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function ( $m ) {
			$url = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$label = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return ( '' === $label || $label === $url ) ? $url : $label . ' (' . $url . ')';
		}, $t );
		$t = (string) preg_replace( '#<br\s*/?>[ \t]*\r?\n?#i', "\n", $t );
		$t = (string) preg_replace( '#<li\b[^>]*>#i', '- ', $t );
		$t = (string) preg_replace( '#</li>#i', "\n", $t );
		// Table rows (payment details, order lines): "label: value" per line.
		$t = (string) preg_replace( '#</t[dh]>\s*(?=<t[dh]\b)#i', ': ', $t );
		$t = (string) preg_replace( '#</tr>#i', "\n", $t );
		$t = (string) preg_replace( '#</(p|div|h[1-6]|ul|ol|table|tr|blockquote)>#i', "\n\n", $t );
		$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = (string) preg_replace( "/[ \t]+\n/", "\n", $t );
		$t = (string) preg_replace( "/\n{3,}/", "\n\n", $t );
		return trim( $t );
	}
}
