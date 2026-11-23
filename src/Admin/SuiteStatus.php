<?php
/**
 * Section "Captain-Suite" on the "Betrieb" page (A670, A678): which versioned contracts the core
 * offers and whether a suite plugin (Workflow Captain, Review Captain or any other provider) is
 * registered for them – contract available, incompatible (other major version) or no provider –,
 * plus per contract the last accepted event, pending and failed events, the last error, the workflow
 * commands and mail handoffs, and what happens without a provider (native fallback).
 *
 * Read-only diagnosis: the core contains no licence or paid-feature check; a provider decides itself
 * whether a feature of it is paid. The handed-off mail types (A677) are chosen here
 * (capability manage_options, audited in MailHandoff::save()).
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

use PassCaptain\Workflow\MailHandoff;
use PassCaptain\Workflow\Outbox;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SuiteStatus {
	const CAP = 'manage_options';
	const HANDOFF_ACTION = 'wpcap_workflow_mail_handoff';

	/** Contracts shown: name => [contract class, label]. */
	public static function contracts() {
		return array(
			'workflow' => array( \PassCaptain\Workflow\Contract::class, __( 'Workflow (z. B. Workflow Captain)', 'pass-captain' ) ),
			'review' => array( \PassCaptain\Review\Contract::class, __( 'Bewertungen (z. B. Review Captain)', 'pass-captain' ) ),
		);
	}

	/**
	 * State of every contract.
	 *
	 * @return array[] name => {label, version, filter, state (missing|incompatible|ready), provider_version,
	 *                 code, pending, failed, delivered, last_delivered_at, last_error, last_error_at}
	 */
	public static function data() {
		global $wpdb;
		$out = array();
		foreach ( self::contracts() as $name => list( $class, $label ) ) {
			$class::provider( $status );
			$row = array(
				'label' => $label,
				'version' => $class::VERSION,
				'filter' => $class::FILTER,
				'state' => (string) ( $status['state'] ?? 'missing' ),
				'provider_version' => (string) ( $status['version'] ?? '' ),
				'code' => (string) ( $status['code'] ?? '' ),
				'pending' => 0,
				'failed' => 0,
				'delivered' => 0,
				'last_delivered_at' => '',
				'last_error' => '',
				'last_error_at' => '',
			);
			$multi = Outbox::multi_ready();
			if ( Outbox::ready() && ( $multi || 'workflow' === $name ) ) {
				$t = Outbox::table();
				$stats = $multi
					? $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n,MAX(delivered_at) AS last FROM %i WHERE contract=%s GROUP BY status', $t, $name ) )
					: $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n,MAX(delivered_at) AS last FROM %i GROUP BY status', $t ) );
				foreach ( (array) $stats as $s ) {
					if ( in_array( $s->status, array( 'pending', 'delivering' ), true ) ) { $row['pending'] += (int) $s->n; }
					if ( 'failed' === $s->status ) { $row['failed'] = (int) $s->n; }
					if ( 'delivered' === $s->status ) { $row['delivered'] = (int) $s->n; $row['last_delivered_at'] = (string) $s->last; }
				}
				$err = $multi
					? $wpdb->get_row( $wpdb->prepare( "SELECT last_error,COALESCE(next_attempt_at,created_at) AS at FROM %i WHERE contract=%s AND last_error<>'' ORDER BY outbox_id DESC LIMIT 1", $t, $name ) )
					: $wpdb->get_row( $wpdb->prepare( "SELECT last_error,COALESCE(next_attempt_at,created_at) AS at FROM %i WHERE last_error<>'' ORDER BY outbox_id DESC LIMIT 1", $t ) );
				if ( $err ) { $row['last_error'] = (string) $err->last_error; $row['last_error_at'] = (string) $err->at; }
			}
			$out[ $name ] = $row;
		}
		return $out;
	}

	/** Last workflow commands (A676): command, result, time – no arguments. */
	public static function commands( $limit = 5 ) {
		global $wpdb;
		if ( ! \PassCaptain\Workflow\Commands::ready() ) { return array(); }
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT command,status,created_at,finished_at FROM %i ORDER BY command_id DESC LIMIT %d', \PassCaptain\Workflow\Commands::table(), max( 1, (int) $limit ) ) );
	}

	/** Label of a provider state. */
	public static function state_label( array $row ) {
		if ( 'ready' === $row['state'] ) {
			/* translators: %s: contract version the provider speaks */
			return sprintf( __( 'Vertrag verfügbar – Provider aktiv (spricht %s)', 'pass-captain' ), $row['provider_version'] );
		}
		if ( 'incompatible' === $row['state'] ) {
			/* translators: 1: version the provider speaks, 2: version of the core */
			return sprintf( __( 'Inkompatibel – der Provider spricht %1$s, der Kern bietet %2$s an; er wird nicht aufgerufen', 'pass-captain' ), '' !== $row['provider_version'] ? $row['provider_version'] : '?', $row['version'] );
		}
		return __( 'Vertrag verfügbar – kein Plugin registriert', 'pass-captain' );
	}

	/** Saves the handed-off mail types (admin-post). */
	public static function handle_handoff() {
		$in = Forms::post();
		Forms::verify( self::HANDOFF_ACTION );
		$result = MailHandoff::save( array_map( 'strval', (array) ( $in['types'] ?? array() ) ) );
		Forms::finish( $result, __( 'Mail-Übergabe gespeichert.', 'pass-captain' ), OperationsPage::url() . '#wpcap-suite' );
	}

	/**
	 * Registered connectors (A705): key, own version, opt-in, capabilities and the connector contract version
	 * of the core, plus the open and dead-letter deliveries. No licence state: a connector that needs a
	 * licence checks and shows it itself.
	 */
	public static function render_connectors() {
		$connectors = \PassCaptain\Integrations\ConnectorEvents::registered();
		$backlog = \PassCaptain\Integrations\ConnectorEvents::backlog();
		echo '<h3 id="wpcap-suite-connectors">' . esc_html__( 'Connectoren', 'pass-captain' ) . '</h3>';
		/* translators: %s: version of the connector contract offered by the core */
		echo '<p class="description">' . esc_html( sprintf( __( 'Connector-Vertrag %s (wp_pass_captain_connectors_v1). Ein Connector erhält Ereignisse nur, wenn er unter „Konnektoren“ bzw. in seinem Plugin freigegeben ist.', 'pass-captain' ), \PassCaptain\Integrations\ConnectorEvents::CONTRACT_VERSION ) ) . '</p>';
		if ( ! $connectors ) {
			echo '<p>' . esc_html__( 'Kein Connector registriert.', 'pass-captain' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
			foreach ( array( __( 'Connector', 'pass-captain' ), __( 'Version', 'pass-captain' ), __( 'Freigegeben', 'pass-captain' ), __( 'Fähigkeiten', 'pass-captain' ) ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $connectors as $c ) {
				echo '<tr data-connector="' . esc_attr( $c['key'] ) . '" data-enabled="' . ( $c['enabled'] ? '1' : '0' ) . '">';
				echo '<td><code>' . esc_html( $c['key'] ) . '</code></td>';
				echo '<td>' . esc_html( '' !== $c['version'] ? $c['version'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $c['enabled'] ? __( 'Ja', 'pass-captain' ) : __( 'Nein', 'pass-captain' ) ) . '</td>';
				echo '<td>' . esc_html( $c['capabilities'] ? implode( ', ', $c['capabilities'] ) : '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		/* translators: 1: open connector deliveries, 2: deliveries that failed for good (dead letter) */
		echo '<p>' . esc_html( sprintf( __( 'Zustellungen offen: %1$d · endgültig gescheitert: %2$d', 'pass-captain' ), (int) $backlog['pending'], (int) $backlog['dead_letter'] ) ) . '</p>';
	}

	public static function render() {
		$data = self::data();
		echo '<h2 id="wpcap-suite">' . esc_html__( 'Captain-Suite: Verträge und Provider', 'pass-captain' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Pass Captain bietet versionierte Verträge für Erweiterungen an und läuft ohne sie vollständig weiter. Ist kein kompatibles Plugin registriert, bleiben alle Abläufe nativ; Ereignisse warten 30 Tage in der Outbox und erreichen ein später installiertes Plugin. Lizenzen prüft der Kern nicht.', 'pass-captain' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:80em"><thead><tr>';
		foreach ( array( __( 'Vertrag', 'pass-captain' ), __( 'Status', 'pass-captain' ), __( 'Letzte angenommene Zustellung', 'pass-captain' ), __( 'Ausstehend / fehlgeschlagen', 'pass-captain' ), __( 'Letzter Fehler', 'pass-captain' ), __( 'Ohne Provider', 'pass-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $data as $name => $row ) {
			$color = 'ready' === $row['state'] ? '#007017' : ( 'incompatible' === $row['state'] ? '#b32d2e' : '#50575e' );
			echo '<tr data-contract="' . esc_attr( $name ) . '" data-state="' . esc_attr( $row['state'] ) . '">';
			echo '<td><strong>' . esc_html( $row['label'] ) . '</strong><br><code>' . esc_html( $name . ' ' . $row['version'] ) . '</code> · <code>' . esc_html( $row['filter'] ) . '</code></td>';
			echo '<td><strong style="color:' . esc_attr( $color ) . '">' . esc_html( self::state_label( $row ) ) . '</strong></td>';
			echo '<td>' . esc_html( '' !== $row['last_delivered_at'] ? Forms::datetime( $row['last_delivered_at'], wp_timezone_string() ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $row['pending'] . ' / ' . (string) $row['failed'] ) . '</td>';
			echo '<td>' . ( '' !== $row['last_error'] ? '<code>' . esc_html( $row['last_error'] ) . '</code><br>' . esc_html( Forms::datetime( $row['last_error_at'], wp_timezone_string() ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( 'workflow' === $name ? __( 'Erinnerungen und Mails versendet Pass Captain selbst; Commands stehen dann nicht zur Verfügung.', 'pass-captain' ) : __( 'Keine Bewertungseinladungen; Teilnahmen werden nur festgehalten.', 'pass-captain' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::render_connectors();

		$commands = self::commands();
		if ( $commands ) {
			echo '<h3>' . esc_html__( 'Letzte Workflow-Commands', 'pass-captain' ) . '</h3><ul>';
			foreach ( $commands as $c ) {
				echo '<li><code>' . esc_html( $c->command ) . '</code> – ' . esc_html( Forms::status_label( (string) $c->status ) ) . ' – ' . esc_html( Forms::datetime( $c->created_at, wp_timezone_string() ) ) . '</li>';
			}
			echo '</ul>';
		}

		$types = MailHandoff::types();
		$enabled = MailHandoff::enabled();
		$stats = MailHandoff::stats();
		echo '<h3>' . esc_html__( 'Optionale Mails an den Workflow-Provider übergeben', 'pass-captain' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Nur optionale Mails. Ticket-, Zahlungs-, Erstattungs- und Absagemails versendet immer Pass Captain. Ist kein kompatibler Provider aktiv oder lehnt er ab, sendet Pass Captain die Mail selbst.', 'pass-captain' ) . '</p>';
		Forms::open_form( self::HANDOFF_ACTION );
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Übergebene Mailtypen', 'pass-captain' ) . '</legend>';
		foreach ( $types as $type => $label ) {
			echo '<label><input type="checkbox" name="types[]" value="' . esc_attr( $type ) . '"' . ( in_array( $type, $enabled, true ) ? ' checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '</fieldset>';
		/* translators: 1: number of mails taken by the provider, 2: number of native fallbacks */
		echo '<p>' . esc_html( sprintf( __( 'Übergeben: %1$d · nativ versendet (Rückfall): %2$d', 'pass-captain' ), (int) $stats['accepted'], (int) $stats['fallback'] ) ) . ( ! empty( $stats['last']['reason'] ) ? ' · <code>' . esc_html( (string) $stats['last']['reason'] ) . '</code>' : '' ) . '</p>';
		submit_button( __( 'Mail-Übergabe speichern', 'pass-captain' ), 'secondary' );
		echo '</form>';
	}
}
