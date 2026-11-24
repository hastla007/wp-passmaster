<?php
/**
 * Optional "Workflow Captain" box on the system status page (suite task "Providerstatus, aktive Workflowlinks und
 * gemeinsame Run-/Correlation-IDs in Suite-Admins anzeigen"). Pass Captain neither requires nor configures Workflow
 * Captain: the box only reads workflow_captain_status_for() when that function exists. The status holds counts and
 * links only (adapter state, contract version, active workflows, events per state, last correlation ID), no
 * personal data.
 */
namespace PassCaptain\Integrations\WorkflowCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class Status {
	/** Product id of Pass Captain in Workflow Captain. */
	const PRODUCT = 'pass-captain';

	/** Public status function of Workflow Captain (no class of it is used). */
	const SOURCE = 'workflow_captain_status_for';

	/** Link keys of the status, in display order. */
	const LINKS = array( 'workflows', 'runs', 'events', 'providers' );

	public static function available() {
		return function_exists( self::SOURCE );
	}

	/**
	 * Status of Pass Captain in Workflow Captain, or null when Workflow Captain is missing or not loaded yet.
	 *
	 * @param callable|null $source Status function (tests); default workflow_captain_status_for().
	 * @return array<string, mixed>|null
	 */
	public static function data( $source = null ) {
		if ( null === $source ) {
			if ( ! self::available() ) { return null; }
			$source = self::SOURCE;
		}
		$status = call_user_func( $source, self::PRODUCT );
		return is_array( $status ) ? $status : null;
	}

	/** Labels of the event states of Workflow Captain; unknown states are shown by their key. */
	private static function state_label( $state ) {
		$labels = array(
			'received'     => __( 'empfangen', 'pass-captain' ),
			'processing'   => __( 'in Bearbeitung', 'pass-captain' ),
			'done'         => __( 'erledigt', 'pass-captain' ),
			'retry_wait'   => __( 'wartet auf Wiederholung', 'pass-captain' ),
			'dead_letter'  => __( 'fehlgeschlagen', 'pass-captain' ),
			'discarded'    => __( 'verworfen', 'pass-captain' ),
			'loop_blocked' => __( 'als Schleife gestoppt', 'pass-captain' ),
			'paused'       => __( 'pausiert', 'pass-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/** Only absolute http(s) links of the status are used. */
	private static function link( array $status, $key ) {
		$url = $status['links'][ $key ] ?? '';
		return is_string( $url ) && 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	private static function anchor( $url, $text ) {
		return '' === $url ? $text : '<a href="' . esc_url( $url ) . '">' . $text . '</a>';
	}

	/**
	 * HTML of the box (escaped).
	 *
	 * @param callable|null $source Status function (tests); default workflow_captain_status_for().
	 */
	public static function html( $source = null ) {
		$html = '<h2 id="wpcap-workflow-captain">' . esc_html__( 'Workflow Captain', 'pass-captain' ) . '</h2>';
		if ( null === $source && ! self::available() ) {
			return $html . '<p>' . esc_html__( 'Workflow Captain ist nicht aktiv. Mit Workflow Captain können Ereignisse aus Pass Captain (z. B. bezahlte Bestellungen) eigene Abläufe auslösen. Pass Captain funktioniert ohne Einschränkung.', 'pass-captain' ) . '</p>';
		}
		$status = self::data( $source );
		if ( null === $status ) {
			return $html . '<p>' . esc_html__( 'Workflow Captain ist aktiv, meldet aber noch keinen Status.', 'pass-captain' ) . '</p>';
		}
		$adapter = (string) ( $status['adapter'] ?? 'missing' );
		$reason  = is_string( $status['reason'] ?? null ) ? $status['reason'] : '';
		if ( 'registered' === $adapter ) {
			$adapter_text = esc_html__( 'angemeldet', 'pass-captain' );
		} elseif ( 'refused' === $adapter ) {
			$adapter_text = esc_html__( 'abgelehnt', 'pass-captain' ) . ( '' !== $reason ? ': ' . esc_html( $reason ) : '' );
		} else {
			$adapter_text = esc_html__( 'nicht angemeldet – Workflow Captain kennt noch keinen Adapter für Pass Captain.', 'pass-captain' );
		}
		$contract = is_scalar( $status['contract_version'] ?? null ) && '' !== (string) $status['contract_version'] ? '<code>' . esc_html( (string) $status['contract_version'] ) . '</code>' : '–';
		$events_url = self::link( $status, 'events' );
		$events = array();
		foreach ( (array) ( $status['events'] ?? array() ) as $state => $count ) {
			$state    = sanitize_key( (string) $state );
			$url      = '' !== $events_url && '' !== $state ? add_query_arg( 'state', $state, $events_url ) : '';
			$events[] = self::anchor( $url, esc_html( self::state_label( $state ) . ': ' . number_format_i18n( (int) $count ) ) );
		}
		$correlation = is_string( $status['last_correlation_id'] ?? null ) && '' !== $status['last_correlation_id'] ? self::anchor( $events_url, '<code>' . esc_html( $status['last_correlation_id'] ) . '</code>' ) : '–';
		$labels = array(
			'workflows' => __( 'Workflows', 'pass-captain' ),
			'runs'      => __( 'Läufe', 'pass-captain' ),
			'events'    => __( 'Ereignisse', 'pass-captain' ),
			'providers' => __( 'Anbieter', 'pass-captain' ),
		);
		$links = array();
		foreach ( self::LINKS as $key ) {
			$url = self::link( $status, $key );
			if ( '' !== $url ) { $links[] = self::anchor( $url, esc_html( $labels[ $key ] ) ); }
		}
		$rows = array(
			__( 'Adapter', 'pass-captain' )                 => $adapter_text,
			__( 'Vertragsversion', 'pass-captain' )         => $contract,
			__( 'Aktive Workflows', 'pass-captain' )        => esc_html( number_format_i18n( (int) ( $status['active_workflows'] ?? 0 ) ) ),
			__( 'Ereignisse je Zustand', 'pass-captain' )   => $events ? implode( ' · ', $events ) : esc_html__( 'noch keine', 'pass-captain' ),
			__( 'Letzte Korrelations-ID', 'pass-captain' )  => $correlation,
		);
		$html .= '<table class="widefat striped wpcap-workflow-captain"><tbody>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( $links ) {
			$html .= '<p>' . esc_html__( 'In Workflow Captain öffnen:', 'pass-captain' ) . ' ' . implode( ' · ', $links ) . '</p>';
		}
		return $html;
	}

	public static function render() {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}
}
