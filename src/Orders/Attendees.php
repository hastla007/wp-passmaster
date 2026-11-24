<?php
/**
 * Attendee data of a checkout: validation against the participant fields and storage per ticket.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Orders;

use PassCaptain\Clock;
use PassCaptain\Consents;
use PassCaptain\Http;
use PassCaptain\Orders;
use PassCaptain\ParticipantFields;
use PassCaptain\Sales;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Implementation behind Orders::validate_attendees(), Orders::attendee_name_mode(), Orders::field_options(),
 * Orders::clean_answer() and the attendee step of ticket issuance. Moved out of Orders without behaviour
 * change (TODO "Gott-Klasse Orders").
 *
 * @internal Not part of the add-on API: only Orders calls it; use the Orders facade (same signatures).
 */
final class Attendees {
	/** Stores attendee name/e-mail and custom field answers for one ticket. */
	public static function store( $ticket_id, $order, array $attendee ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = Clock::mysql();
		// Stored attendee data of orders from before the entrance checks (A492/A493) may hold values that do
		// not fit the columns: they are dropped/shortened instead of blocking the issuance for good.
		$name = mb_substr( sanitize_text_field( Http::str( $attendee['name'] ?? '' ) ), 0, 190 );
		$email = Http::email( $attendee['email'] ?? '' );
		$email = Http::valid_email( $email ) ? $email : '';
		if ( $name || '' !== $email ) {
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_ticket_assignments (ticket_id,buyer_email,attendee_email,attendee_name,status,created_at) VALUES (%d,%s,%s,%s,'assigned',%s) ON DUPLICATE KEY UPDATE attendee_email=VALUES(attendee_email),attendee_name=VALUES(attendee_name)", $ticket_id, $order->customer_email, $email, $name, $now ) ) ) { return false; }
			if ( '' !== $email && false === $wpdb->update( "{$p}wpcap_tickets", array( 'attendee_email' => $email ), array( 'ticket_id' => $ticket_id ) ) ) {
				return false;
			}
		}
		foreach ( (array) ( $attendee['answers'] ?? array() ) as $field_id => $value ) {
			if ( ! ParticipantFields::save_answer( $ticket_id, $field_id, (string) $value, $now ) ) { return false; }
		}
		Consents::record_checkout( $ticket_id, $order, $attendee );
		return '' === $wpdb->last_error;
	}

	/**
	 * Validates attendee data against the participant fields configured per ticket type.
	 * Only configured fields are kept (data minimisation); required fields must be filled for every ticket.
	 *
	 * Consent fields (A116) are checkboxes: a required consent must be ticked ('1') for every ticket.
	 * With $options['skip_consent'] consent fields are neither checked nor returned (order portal
	 * corrections; consents are granted/withdrawn there through Consents). With
	 * $options['defer_required'] required fields may stay empty (waitlist offer orders: the data is
	 * collected and fully validated when the offer is accepted, A536).
	 * $options['allow_missing']: tickets without any attendee entry are accepted even with required
	 * fields (trusted adapters only; entries that are given are still validated).
	 * $options['skip_required']: given entries may leave required fields empty (complimentary tickets:
	 * staff only know the guest's name; the guest completes the rest in the portal).
	 * For backwards compatibility the third/fourth parameters may also be the booleans
	 * ($allow_missing, $skip_required) of the earlier signature.
	 *
	 * Field types, choices, order-level fields and versions: ParticipantFields (A680). Ticket types
	 * with "attendee name required" need a name on every ticket (A552; $options['skip_name'] for
	 * corrections of answers alone); the name mode of a ticket type (attendee_name: off, optional,
	 * required, DB 3.17.5) is honoured as well ("off": the name is neither asked nor stored). An entry
	 * with `same_as_buyer` takes name/e-mail from $options['buyer'] (['email', 'name']). With
	 * $options['strict'] (A681) entries of other ticket types and unknown or deactivated field keys are
	 * refused instead of dropped.
	 *
	 * Errors carry which attendee is meant (A551): `ticket_type_id`, `position` (0-based n-th ticket
	 * of that type), `index` (0-based position in the submitted list, when that entry was sent) and
	 * `field_key` (participant field) in the error data.
	 *
	 * @param array      $lines         ticket_type_id => quantity.
	 * @param mixed      $attendees     Submitted list.
	 * @param array|bool $options       Options (see above) or the legacy $allow_missing flag.
	 * @param bool       $skip_required Legacy flag (only with a boolean third parameter).
	 * @return array|\WP_Error Normalised list [{ticket_type_id, name, email, answers{field_id:value}}].
	 */
	public static function validate( array $lines, $attendees, $options = array(), $skip_required = false ) {
		global $wpdb;
		if ( ! is_array( $options ) ) {
			$options = array( 'allow_missing' => (bool) $options, 'skip_required' => (bool) $skip_required );
		}
		$attendees = is_array( $attendees ) ? array_values( $attendees ) : array();
		$out = array();
		$skip_consent = ! empty( $options['skip_consent'] ) && Consents::ready();
		$defer_required = ! empty( $options['defer_required'] );
		$allow_missing = ! empty( $options['allow_missing'] );
		$skip_required_fields = $defer_required || ! empty( $options['skip_required'] );
		// A681: strict channels (WooCommerce) refuse what they cannot store instead of dropping it silently.
		$strict = ! empty( $options['strict'] );
		$buyer = is_array( $options['buyer'] ?? null ) ? $options['buyer'] : array();
		$name_modes = Orders::schema_at_least( '3.17.5' ); // attendee_name column of ticket types (DB 3.17.5).
		$known_types = array_map( 'intval', array_keys( $lines ) );
		if ( $strict ) {
			foreach ( $attendees as $index => $entry ) {
				if ( ! is_array( $entry ) || ! in_array( absint( $entry['ticket_type_id'] ?? 0 ), $known_types, true ) ) {
					return new \WP_Error( 'wpcap_attendees_invalid', __( 'Angaben zu Teilnehmenden passen nicht zu den bestellten Tickets.', 'pass-captain' ), array( 'status' => 400, 'index' => (int) $index, 'reason' => 'unknown_ticket_type' ) );
				}
			}
		}
		// Add-on products (Sales\Addons) get no tickets, so no attendee data is asked or kept for them.
		$addon_types = Sales\Addons::addon_ids( $known_types );
		foreach ( $lines as $type_id => $quantity ) {
			if ( in_array( (int) $type_id, $addon_types, true ) ) { continue; }
			$fields = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . ParticipantFields::columns() . ",status FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d" . ( $strict ? '' : " AND status='active'" ) . ( $skip_consent ? ' AND consent=0' : '' ) . ' ORDER BY position,field_id', $type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static column list.
			// Inactive fields are only read to tell "no longer offered" from "unknown" (A681).
			$inactive = array();
			// A683: fields locked for self-service are neither validated nor returned (stored value stays).
			$locked = array_map( 'strval', (array) ( $options['locked'] ?? array() ) );
			foreach ( $fields as $k => $field ) {
				if ( 'active' !== $field->status ) { $inactive[] = (string) $field->field_key; unset( $fields[ $k ] ); }
				elseif ( in_array( (string) $field->field_key, $locked, true ) ) { unset( $fields[ $k ] ); }
			}
			$fields = array_values( $fields );
			// Name per ticket: off (not asked, not stored), optional (default) or required (ticket type option).
			$name_mode = $name_modes ? self::name_mode( $wpdb->get_var( $wpdb->prepare( 'SELECT attendee_name FROM %i WHERE ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $type_id ) ) ) : 'optional';
			// Corrections of answers alone (ticket page, assignment) leave the name as it is: skip_name.
			$name_required = ! $defer_required && empty( $options['skip_name'] ) && ( 'required' === $name_mode || ParticipantFields::name_required( $type_id ) );
			$given = array_filter( $attendees, static function ( $a ) use ( $type_id ) { return is_array( $a ) && absint( $a['ticket_type_id'] ?? 0 ) === (int) $type_id; } );
			// Position of each entry in the submitted list: error data names the attendee (A551).
			$given_index = array_keys( $given );
			$given = array_values( $given );
			$where = static function ( $i, $field = null ) use ( $type_id, $given_index ) {
				$data = array( 'status' => 400, 'ticket_type_id' => (int) $type_id, 'position' => (int) $i );
				if ( isset( $given_index[ $i ] ) ) { $data['index'] = (int) $given_index[ $i ]; }
				if ( $field ) { $data['field_key'] = is_object( $field ) ? (string) $field->field_key : (string) $field; }
				return $data;
			};
			if ( count( $given ) > $quantity ) {
				return new \WP_Error( 'wpcap_attendees_invalid', __( 'Es wurden mehr Teilnehmende angegeben als Tickets bestellt.', 'pass-captain' ), $where( (int) $quantity ) );
			}
			if ( $strict ) {
				$keys = array_map( static function ( $f ) { return (string) $f->field_key; }, $fields );
				foreach ( $given as $i => $entry ) {
					$sent = is_array( $entry['answers'] ?? null ) ? array_keys( $entry['answers'] ) : array();
					foreach ( array_diff( array_map( 'strval', $sent ), $keys ) as $unknown ) {
						$closed = in_array( $unknown, $inactive, true );
						return new \WP_Error(
							'wpcap_attendee_field_unknown',
							$closed ? __( 'Ein Teilnehmerfeld wird nicht mehr abgefragt. Bitte lade die Seite neu und prüfe die Angaben.', 'pass-captain' ) : __( 'Die Angaben zu Teilnehmenden enthalten ein unbekanntes Feld. Bitte lade die Seite neu.', 'pass-captain' ),
							$where( $i, sanitize_key( $unknown ) ) + array( 'reason' => $closed ? 'field_inactive' : 'field_unknown' )
						);
					}
				}
			}
			// A680: order-level fields are asked once per order and ticket type (first entry of the type).
			$order_fields = array_values( array_filter( $fields, static function ( $f ) { return 'order' === ParticipantFields::scope( $f ); } ) );
			$ticket_fields = array_values( array_filter( $fields, static function ( $f ) { return 'order' !== ParticipantFields::scope( $f ); } ) );
			$order_clean = array();
			$first = is_array( $given[0]['answers'] ?? null ) ? $given[0]['answers'] : array();
			foreach ( $order_fields as $field ) {
				list( $value, $error ) = self::normalize_answer( $field, $first[ $field->field_key ] ?? '' );
				if ( $error ) { return new \WP_Error( 'wpcap_attendee_field_invalid', ParticipantFields::invalid_message( $field ), $where( 0, $field ) ); }
				if ( (int) $field->required && '' === $value && ! $skip_required_fields && ( $given || ! $allow_missing ) ) {
					/* translators: %s: field label */
					return new \WP_Error( 'wpcap_attendee_field_required', sprintf( __( 'Bitte „%s“ für die Bestellung ausfüllen.', 'pass-captain' ), $field->label ), $where( 0, $field ) );
				}
				if ( '' !== $value ) { $order_clean[ (int) $field->field_id ] = mb_substr( $value, 0, 2000 ); }
			}
			$needs_all = ! $defer_required && ! $allow_missing && ( $name_required || (bool) array_filter( $ticket_fields, static function ( $f ) { return (int) $f->required; } ) );
			for ( $i = 0; $i < $quantity; $i++ ) {
				$entry = $given[ $i ] ?? null;
				if ( ! $entry && ! $needs_all && ! $order_clean ) { continue; }
				$present = (bool) $entry;
				$entry = is_array( $entry ) ? $entry : array();
				$answers = is_array( $entry['answers'] ?? null ) ? $entry['answers'] : array();
				$clean = array();
				foreach ( $ticket_fields as $field ) {
					// A498: sanitize_email() turns "kein-mail" into "" – an entered but invalid value must not pass as empty.
					list( $value, $error ) = self::normalize_answer( $field, $answers[ $field->field_key ] ?? '' );
					if ( $error ) {
						return new \WP_Error( 'wpcap_attendee_field_invalid', ParticipantFields::invalid_message( $field ), $where( $i, $field ) );
					}
					if ( (int) $field->required && '' === $value && ! $skip_required_fields && ( $present || ! $allow_missing ) ) {
						/* translators: %s: field label */
						return new \WP_Error( 'wpcap_attendee_field_required', sprintf( __( 'Bitte „%s“ für jedes Ticket ausfüllen.', 'pass-captain' ), $field->label ), $where( $i, $field ) );
					}
					if ( '' !== $value ) { $clean[ (int) $field->field_id ] = mb_substr( $value, 0, 2000 ); }
				}
				// A552: "same as buyer" takes the buyer's address (and billing name) for this ticket.
				$same = ! empty( $entry['same_as_buyer'] ) && $buyer;
				$name = 'off' === $name_mode ? '' : mb_substr( sanitize_text_field( Http::str( $entry['name'] ?? '' ) ), 0, 190 );
				$raw_email = $entry['email'] ?? '';
				if ( $same ) {
					if ( '' === $name && 'off' !== $name_mode ) { $name = mb_substr( sanitize_text_field( Http::str( $buyer['name'] ?? '' ) ), 0, 190 ); }
					if ( '' === trim( Http::str( $raw_email ) ) ) { $raw_email = $buyer['email'] ?? ''; }
				}
				if ( $name_required && '' === $name && ( $present || ! $allow_missing ) ) {
					return new \WP_Error( 'wpcap_attendee_field_required', __( 'Bitte den Namen für jedes Ticket angeben.', 'pass-captain' ), $where( $i, '__name' ) );
				}
				$email = Http::email( $raw_email );
				if ( Http::email_too_long( $email ) ) {
					// A493: a valid but overlong address would fail at the tickets/assignments column at issuance.
					return new \WP_Error( 'wpcap_attendees_invalid', Orders::email_too_long_message(), $where( $i ) + array( 'reason' => 'email_too_long' ) );
				}
				// A599: an entered but invalid address is refused (it was dropped silently, so the ticket
				// went to the buyer instead of the attendee). field_key "__email" names the field of the block.
				if ( false === Http::optional_email( $entry['email'] ?? '' ) ) {
					$label = __( 'E-Mail der Teilnehmer:in', 'pass-captain' );
					/* translators: %s: field label */
					$message = sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'pass-captain' ), $label );
					return new \WP_Error( 'wpcap_attendee_field_invalid', $message, $where( $i, (object) array( 'field_key' => '__email' ) ) + array( 'reason' => 'email_invalid' ) );
				}
				$out[] = array(
					'ticket_type_id' => (int) $type_id,
					'name' => $name,
					'email' => is_email( $email ) ? $email : '',
					'answers' => $clean + $order_clean,
				);
			}
		}
		return $out;
	}

	/**
	 * One answer normalised for its field ([value, error], see ParticipantFields::normalize()). Field types
	 * that only the newline-option variant knows ("radio", "consent"; choices one per line in `options`)
	 * are validated by clean_answer(), everything else by ParticipantFields.
	 */
	private static function normalize_answer( $field, $value ) {
		$type = (string) ( $field->field_type ?? '' );
		$json_options = in_array( $type, array( 'select', 'multiselect' ), true ) && is_array( json_decode( (string) ( $field->options ?? '' ), true ) );
		if ( in_array( $type, array( 'radio', 'consent' ), true ) || ( 'select' === $type && ! $json_options && '' !== (string) ( $field->options ?? '' ) ) ) {
			$clean = self::clean_answer( $field, $value );
			return null === $clean ? array( '', 'invalid' ) : array( $clean, '' );
		}
		return ParticipantFields::normalize( $field, $value );
	}

	/** Normalised attendee-name mode of a ticket type: off, optional (default) or required. */
	public static function name_mode( $value ) {
		return in_array( (string) $value, array( 'off', 'optional', 'required' ), true ) ? (string) $value : 'optional';
	}

	/**
	 * Choices of a select/radio participant field (one per line in the options column).
	 *
	 * @return string[]
	 */
	public static function field_options( $field ) {
		$raw = is_object( $field ) ? ( $field->options ?? '' ) : ( $field['options'] ?? '' );
		// Two stored formats: a JSON list (ParticipantFields, A680) or one choice per line.
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$choices = is_array( $list ) ? array_map( 'strval', $list ) : (array) preg_split( '/\R/', (string) $raw );
		return array_values( array_unique( array_filter( array_map( 'trim', $choices ), static function ( $choice ) { return '' !== $choice; } ) ) );
	}

	/**
	 * Sanitised answer for one participant field; null when the value is not valid for the field type
	 * (e-mail, choice outside the options, impossible date, phone number with other characters).
	 */
	public static function clean_answer( $field, $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		switch ( (string) $field->field_type ) {
			case 'email':
				$value = sanitize_email( $value );
				return '' === $value || is_email( $value ) ? $value : null;
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'checkbox':
			case 'consent':
				return $value && '0' !== $value ? '1' : '';
			case 'select':
			case 'radio':
				$value = sanitize_text_field( $value );
				return '' === $value || in_array( $value, self::field_options( $field ), true ) ? $value : null;
			case 'date':
				if ( '' === $value ) { return ''; }
				$date = \DateTime::createFromFormat( '!Y-m-d', $value );
				return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
			case 'tel':
				if ( '' === $value ) { return ''; }
				return preg_match( '/^\+?[0-9 ()\/.\-]{3,40}$/', $value ) && preg_match_all( '/[0-9]/', $value ) >= 3 ? $value : null;
			default:
				return sanitize_text_field( $value );
		}
	}
}
