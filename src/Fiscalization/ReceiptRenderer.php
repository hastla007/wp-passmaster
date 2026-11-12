<?php
/**
 * Safe rendering of normalized provider receipt results for receipt views.
 *
 * Provider-supplied HTML, SVG, image URLs, and scripts are never rendered.
 */
namespace PassCaptain\Fiscalization;

use PassCaptain\TicketPdf;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ReceiptRenderer {
	/** Completed fiscal receipts for a Pass Captain order, one block per legal seller. */
	public static function render( $order_id ) {
		global $wpdb;
		$docs = $wpdb->get_results( $wpdb->prepare(
			"SELECT seller_id,provider_result,snapshot FROM {$wpdb->prefix}wpcap_fiscal_documents WHERE order_id=%d AND status='submitted' ORDER BY seller_id",
			absint( $order_id )
		) );
		if ( ! $docs ) { return ''; }
		$html = '<section class="wpcap-fiscal-receipts"><h2>' . esc_html__( 'Fiskalbelege', 'wp-pass-captain' ) . '</h2>';
		foreach ( $docs as $doc ) {
			$snapshot = json_decode( $doc->snapshot, true );
			$result = json_decode( $doc->provider_result, true );
			if ( ! is_array( $snapshot ) || ! is_array( $result ) ) { continue; }
			$seller = (array) ( $snapshot['seller'] ?? array() );
			$receipt = is_array( $result['receipt'] ?? null ) ? $result['receipt'] : array();
			$html .= '<div class="wpcap-fiscal-receipt"><h3>' . esc_html( (string) ( $seller['legal_name'] ?? __( 'Verkäufer', 'wp-pass-captain' ) ) ) . '</h3>';
			if ( ! empty( $result['reference'] ) && is_scalar( $result['reference'] ) ) {
				$html .= '<p>' . esc_html__( 'Fiskalreferenz:', 'wp-pass-captain' ) . ' ' . esc_html( (string) $result['reference'] ) . '</p>';
			}
			$fields = is_array( $receipt['fields'] ?? null ) ? array_slice( $receipt['fields'], 0, 16, true ) : array();
			if ( $fields ) {
				$html .= '<dl>';
				foreach ( $fields as $label => $value ) {
					if ( ! is_scalar( $value ) ) { continue; }
					$html .= '<dt>' . esc_html( sanitize_text_field( (string) $label ) ) . '</dt><dd>' . esc_html( sanitize_text_field( (string) $value ) ) . '</dd>';
				}
				$html .= '</dl>';
			}
			$payload = is_string( $receipt['qr_payload'] ?? null ) ? $receipt['qr_payload'] : '';
			if ( '' !== $payload && strlen( $payload ) <= 4096 ) {
				$html .= '<div class="wpcap-fiscal-qr" aria-label="' . esc_attr__( 'Fiskal-QR-Code', 'wp-pass-captain' ) . '">' . self::qr_svg( $payload ) . '</div>';
				if ( '' === self::qr_svg( $payload ) ) { $html .= '<code>' . esc_html( $payload ) . '</code>'; }
			}
			$html .= '</div>';
		}
		return $html . '</section>';
	}

	/** Render a provider payload as a local SVG using the installed QR matrix encoder. */
	private static function qr_svg( $payload ) {
		$matrix = TicketPdf::qr_matrix( $payload );
		if ( ! $matrix ) { return ''; }
		$count = count( $matrix );
		$size = $count + 8;
		$commands = array();
		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) { $commands[] = 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z'; }
			}
		}
		$d = implode( '', $commands );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . (int) $size . ' ' . (int) $size . '" role="img" aria-label="' . esc_attr__( 'Fiskal-QR-Code', 'wp-pass-captain' ) . '" style="width:28mm;height:28mm;shape-rendering:crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . esc_attr( $d ) . '" fill="#000"/></svg>';
	}
}
