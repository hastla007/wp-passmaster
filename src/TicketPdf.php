<?php
/**
 * Dependency-free PDF renderer for tickets (one A4 page per ticket, Helvetica, vector QR code).
 *
 * The QR matrix comes from the bundled bacon/bacon-qr-code encoder (installed with Composer in the
 * release ZIP). Without it the PDF still contains the ticket code as text.
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TicketPdf {
	/**
	 * @param array $tickets List of ['event','ticket','when','location','attendee','token'] arrays.
	 * @param array $meta    ['title' => string, 'footer' => string].
	 * @return string PDF bytes.
	 */
	public static function render( array $tickets, array $meta = array() ) {
		$pages = array();
		foreach ( $tickets as $ticket ) {
			$pages[] = self::page( $ticket, $meta );
		}
		return self::document( $pages );
	}

	/** Content stream for one ticket page. */
	private static function page( array $t, array $meta ) {
		$ops = array();
		$y = 780;
		$text = static function ( $str, $size, $x, $y, $bold = false ) {
			return sprintf( "BT /%s %d Tf %.2F %.2F Td (%s) Tj ET", $bold ? 'F2' : 'F1', $size, $x, $y, self::escape( $str ) );
		};
		$ops[] = $text( (string) ( $meta['title'] ?? 'Ticket' ), 11, 50, $y );
		$ops[] = $text( (string) ( $t['event'] ?? '' ), 22, 50, $y -= 36, true );
		$ops[] = $text( (string) ( $t['ticket'] ?? '' ), 14, 50, $y -= 26 );
		$ops[] = $text( (string) ( $t['when'] ?? '' ), 12, 50, $y -= 22 );
		if ( ! empty( $t['location'] ) ) { $ops[] = $text( (string) $t['location'], 12, 50, $y -= 18 ); }
		if ( ! empty( $t['attendee'] ) ) { $ops[] = $text( (string) $t['attendee'], 12, 50, $y -= 18 ); }
		$matrix = self::qr_matrix( (string) ( $t['token'] ?? '' ) );
		$top = $y - 40;
		if ( $matrix ) {
			$count = count( $matrix );
			$module = min( 8.0, 260 / ( $count + 8 ) );
			$size = $module * ( $count + 8 );
			$x0 = ( 595 - $size ) / 2;
			$y0 = $top - $size;
			$ops[] = '0 0 0 rg';
			foreach ( $matrix as $row_index => $row ) {
				foreach ( $row as $col_index => $dark ) {
					if ( $dark ) {
						$ops[] = sprintf( '%.2F %.2F %.2F %.2F re f', $x0 + ( $col_index + 4 ) * $module, $y0 + $size - ( $row_index + 5 ) * $module, $module, $module );
					}
				}
			}
			$top = $y0 - 20;
		}
		$ops[] = $text( 'Code:', 9, 50, $top );
		$ops[] = $text( substr( (string) ( $t['token'] ?? '' ), 0, 32 ), 9, 80, $top );
		$ops[] = $text( substr( (string) ( $t['token'] ?? '' ), 32 ), 9, 80, $top - 12 );
		if ( ! empty( $meta['footer'] ) ) { $ops[] = $text( (string) $meta['footer'], 9, 50, 40 ); }
		return implode( "\n", $ops );
	}

	/** QR modules as rows of booleans, or empty array when the encoder is unavailable. */
	public static function qr_matrix( $payload ) {
		if ( '' === $payload || ! class_exists( '\\BaconQrCode\\Encoder\\Encoder' ) ) { return array(); }
		try {
			$qr = \BaconQrCode\Encoder\Encoder::encode( $payload, \BaconQrCode\Common\ErrorCorrectionLevel::M(), 'UTF-8' );
			$matrix = $qr->getMatrix();
			$rows = array();
			for ( $y = 0; $y < $matrix->getHeight(); $y++ ) {
				for ( $x = 0; $x < $matrix->getWidth(); $x++ ) { $rows[ $y ][ $x ] = 1 === $matrix->get( $x, $y ); }
			}
			return $rows;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** Assembles objects and the xref table. */
	private static function document( array $pages ) {
		$objects = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$kids = array();
		$next = 5;
		foreach ( $pages as $content ) {
			$page_id = $next++;
			$content_id = $next++;
			$kids[] = $page_id . ' 0 R';
			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
			$objects[ $content_id ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		ksort( $objects );
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) { $pdf .= sprintf( "%010d 00000 n \n", $offset ); }
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	/** UTF-8 to WinAnsi (Helvetica) with PDF string escaping; unsupported characters become "?". */
	private static function escape( $str ) {
		$str = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $str, 'Windows-1252', 'UTF-8' ) : $str;
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $str );
	}
}
