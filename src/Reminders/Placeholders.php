<?php
/** Placeholder rendering shared by reminder e-mails and SMS texts. */
namespace PassCaptain\Reminders;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Placeholders {
	/** Documented placeholders (key => description). */
	public static function available() {
		return array(
			'{event}' => __( 'Name des Events', 'pass-captain' ),
			'{date}' => __( 'Datum (Zeitzone des Termins)', 'pass-captain' ),
			'{time}' => __( 'Uhrzeit (Zeitzone des Termins)', 'pass-captain' ),
			'{timezone}' => __( 'Zeitzone', 'pass-captain' ),
			'{location}' => __( 'Ort', 'pass-captain' ),
			'{hours}' => __( 'Stunden bis Beginn (Erinnerung)', 'pass-captain' ),
			'{tickets}' => __( 'Anzahl Tickets', 'pass-captain' ),
			'{old_date}' => __( 'Bisheriges Datum und Uhrzeit (Verschiebung)', 'pass-captain' ),
			'{ics_url}' => __( 'Link zur Kalenderdatei', 'pass-captain' ),
			'{site}' => __( 'Name der Website', 'pass-captain' ),
		);
	}

	/** Variables of an occurrence row (starts_at UTC, timezone, location, event_name). */
	public static function occurrence_vars( $occ ) {
		try {
			$tz = new \DateTimeZone( $occ->timezone ?: 'UTC' );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		$dt = new \DateTime( (string) $occ->starts_at, new \DateTimeZone( 'UTC' ) );
		$dt->setTimezone( $tz );
		return array(
			'event' => (string) $occ->event_name,
			'date' => $dt->format( 'd.m.Y' ),
			'time' => $dt->format( 'H:i' ),
			'timezone' => (string) $occ->timezone,
			'location' => (string) $occ->location,
			'ics_url' => \PassCaptain\Calendar\Ics::occurrence_url( (int) $occ->occurrence_id ),
			'site' => (string) get_bloginfo( 'name' ),
		);
	}

	/** Replaces {key} placeholders; unknown placeholders stay untouched. */
	public static function render( $template, array $vars ) {
		$map = array();
		foreach ( $vars as $key => $value ) { $map[ '{' . $key . '}' ] = (string) $value; }
		return strtr( (string) $template, $map );
	}
}
