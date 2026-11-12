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

	/** Full HTML document around already safe content. */
	public static function wrap( $content, $subject = '', $locale = '' ) {
		$s = self::settings();
		$color = $s['color'];
		$site = Orders::site_name();
		$logo = self::logo_url();
		$lang = str_replace( '_', '-', '' !== (string) $locale ? (string) $locale : Mailer::site_locale() );
		$header = '' !== $logo
			? '<img src="' . esc_attr( $logo ) . '" alt="' . esc_attr( $site ) . '" style="max-width:200px;max-height:80px;height:auto;border:0;display:block">'
			: '<span style="font-size:20px;font-weight:bold;color:' . esc_attr( $color ) . '">' . esc_html( $site ) . '</span>';
		$footer = '' !== trim( (string) $s['footer'] ) ? Mailer::text_to_html( (string) $s['footer'] ) : esc_html( $site );
		$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		$html = '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title>'
			. '<style>a{color:' . esc_attr( $color ) . '}code{font-family:Menlo,Consolas,monospace;font-size:13px;word-break:break-all}</style></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f5">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-top:4px solid ' . esc_attr( $color ) . ';border-radius:4px">'
			. '<tr><td style="padding:24px 28px 0">' . $header . '</td></tr>'
			. '<tr><td style="padding:16px 28px 24px;font-family:' . esc_attr( $font ) . ';font-size:15px;line-height:1.55;color:#1d2327">' . $content . '</td></tr>'
			. '<tr><td style="padding:16px 28px;border-top:1px solid #e5e5e5;font-family:' . esc_attr( $font ) . ';font-size:12px;line-height:1.5;color:#50575e">' . $footer . '</td></tr>'
			. '</table></td></tr></table></body></html>';
		return (string) apply_filters( 'wpcap_mail_layout_html', $html, $content, $subject );
	}

	/** Plain-text alternative of the content (plus footer text). */
	public static function alt_text( $content ) {
		$text = self::to_text( $content );
		$footer = trim( (string) self::settings()['footer'] );
		return '' !== $footer ? $text . "\n\n-- \n" . $footer : $text;
	}

	/** Converts HTML to readable plain text: links as "text (url)", paragraphs and breaks kept. */
	public static function to_text( $html ) {
		$t = (string) preg_replace( '#<(script|style|head|title)\b[^>]*>.*?</\1>#is', '', (string) $html );
		$t = (string) preg_replace_callback( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', static function ( $m ) {
			$url = html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$label = trim( html_entity_decode( strip_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return ( '' === $label || $label === $url ) ? $url : $label . ' (' . $url . ')';
		}, $t );
		$t = (string) preg_replace( '#<br\s*/?>[ \t]*\r?\n?#i', "\n", $t );
		$t = (string) preg_replace( '#<li\b[^>]*>#i', '- ', $t );
		$t = (string) preg_replace( '#</li>#i', "\n", $t );
		$t = (string) preg_replace( '#</(p|div|h[1-6]|ul|ol|table|tr|blockquote)>#i', "\n\n", $t );
		$t = html_entity_decode( strip_tags( $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = (string) preg_replace( "/[ \t]+\n/", "\n", $t );
		$t = (string) preg_replace( "/\n{3,}/", "\n\n", $t );
		return trim( $t );
	}
}
