<?php
/**
 * Participant field contract (A552, A680, PASS-FE-01): field types, choices, help text, scope (per
 * ticket or once per order), required attendee name per ticket type, versioned definitions and the
 * normalisation of one answer. Used by every channel that collects attendee data (own checkout, box
 * office, WooCommerce classic/blocks, waitlist offers, ticket assignment, order portal).
 *
 * - Types: TYPES. select/multiselect take their choices from `options` (JSON list, one label per
 *   choice); a multi-select answer is stored as the chosen labels joined with ", " (labels of a
 *   multi-select contain no comma). tel: digits, spaces and + ( ) / - . (5–30 characters, at least 5
 *   digits). date: YYYY-MM-DD. number: an integer or decimal number.
 * - Scope: "ticket" (asked for every ticket) or "order" (asked once per order and ticket type; the
 *   answer is stored on every ticket of that type, so exports, retention and deletion see it per ticket).
 * - Versioning: a change of label, type, choices, required flag or scope raises `version`; the audit
 *   log keeps the definition of every version and each answer stores the version it was given under
 *   (`wpcap_participant_answers.field_version`, 0 = before schema 3.10.27).
 *
 * Schema 3.10.27: the new columns are used only once they exist (ready()).
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class ParticipantFields {
	const TYPES = array( 'text', 'email', 'textarea', 'checkbox', 'select', 'multiselect', 'tel', 'date', 'number', 'radio', 'consent' ); // radio/consent: field types of the audit line (Orders::validate_attendees() checks them).
	const CHOICE_TYPES = array( 'select', 'multiselect' );
	const SCOPES = array( 'ticket', 'order' );
	const MAX_OPTIONS = 50;
	const MULTI_SEPARATOR = ', ';

	/** Definition columns that start a new version when they change. */
	const VERSIONED = array( 'label', 'field_type', 'options', 'required', 'scope' );

	/** Whether the columns of schema 3.10.27 exist (options, help_text, scope, version). */
	public static function ready() {
		return Log::has_column( 'wpcap_participant_fields', 'version' );
	}

	/** Whether answers record the field version (schema 3.10.27). */
	public static function answers_versioned() {
		return Log::has_column( 'wpcap_participant_answers', 'field_version' );
	}

	/** Whether fields carry the self-service lock and deadline (schema 3.10.27, A683). */
	public static function locks_ready() {
		return Log::has_column( 'wpcap_participant_fields', 'self_service' );
	}

	/** Whether ticket types carry the "attendee name required" switch (schema 3.10.27). */
	public static function name_switch_ready() {
		return Log::has_column( 'wpcap_ticket_types', 'attendee_name_required' );
	}

	/** Columns to select for a field definition (with alias prefix, e.g. "f."). */
	public static function columns( $alias = '' ) {
		$cols = array( 'field_id', 'ticket_type_id', 'field_key', 'label', 'field_type', 'required', 'purpose' );
		if ( self::ready() ) { $cols = array_merge( $cols, array( 'options', 'help_text', 'scope', 'version' ) ); }
		if ( self::locks_ready() ) { $cols = array_merge( $cols, array( 'self_service', 'edit_hours' ) ); }
		return implode( ',', array_map( static function ( $c ) use ( $alias ) { return $alias . $c; }, $cols ) );
	}

	/** Type of a stored field (unknown types are read as text). */
	public static function type( $field ) {
		$type = (string) ( is_object( $field ) ? ( $field->field_type ?? 'text' ) : ( $field['field_type'] ?? $field['type'] ?? 'text' ) );
		return in_array( $type, self::TYPES, true ) ? $type : 'text';
	}

	/** Scope of a stored field: ticket or order. */
	public static function scope( $field ) {
		$scope = (string) ( is_object( $field ) ? ( $field->scope ?? 'ticket' ) : ( $field['scope'] ?? 'ticket' ) );
		return in_array( $scope, self::SCOPES, true ) ? $scope : 'ticket';
	}

	/** Choices of a select/multi-select field. */
	public static function options( $field ) {
		$raw = is_object( $field ) ? ( $field->options ?? '' ) : ( $field['options'] ?? '' );
		$list = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		// Radio buttons (audit line) store their choices one per line.
		if ( ! is_array( $list ) && '' !== trim( (string) $raw ) ) { $list = array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $raw ) ); }
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ), 'strlen' ) ) : array();
	}

	/**
	 * Choices entered by an admin (one per line, or a list): trimmed, unique, at most MAX_OPTIONS of at
	 * most 190 characters. A multi-select choice must not contain a comma (answers are joined with ", ").
	 *
	 * @return string[]|\WP_Error
	 */
	public static function parse_options( $raw, $type ) {
		$lines = is_array( $raw ) ? $raw : preg_split( '/\r\n|\r|\n/', (string) $raw );
		$out = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( Http::str( $line ) ) );
			if ( '' === $line || in_array( $line, $out, true ) ) { continue; }
			if ( 'multiselect' === $type && false !== strpos( $line, ',' ) ) {
				return new \WP_Error( 'wpcap_field_options', __( 'Auswahlmöglichkeiten einer Mehrfachauswahl dürfen kein Komma enthalten.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$out[] = mb_substr( $line, 0, 190 );
		}
		if ( in_array( $type, self::CHOICE_TYPES, true ) && ! $out ) {
			return new \WP_Error( 'wpcap_field_options', __( 'Bitte mindestens eine Auswahlmöglichkeit angeben (eine je Zeile).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) && 1 === count( $out ) ) {
			// Audit R6 #8: a single-choice field with one option is no choice (use a checkbox or a text).
			return new \WP_Error( 'wpcap_field_options', __( 'Bitte mindestens zwei Auswahlmöglichkeiten angeben (eine je Zeile).', 'pass-captain' ), array( 'status' => 400 ) );
		}
		if ( count( $out ) > self::MAX_OPTIONS ) {
			/* translators: %d: maximum number of choices */
			return new \WP_Error( 'wpcap_field_options', sprintf( __( 'Höchstens %d Auswahlmöglichkeiten.', 'pass-captain' ), self::MAX_OPTIONS ), array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * One answer normalised for its field: [value, error]. error is '' (valid, value may be ''), or
	 * 'invalid' (input that does not fit the type or is no allowed choice – never stored as empty).
	 *
	 * @param object|array $field Field definition.
	 * @param mixed        $value Raw input (a multi-select also takes a list).
	 * @return array{0:string,1:string}
	 */
	public static function normalize( $field, $value ) {
		$type = self::type( $field );
		if ( 'multiselect' === $type ) {
			$picked = is_array( $value ) ? $value : ( is_scalar( $value ) && '' !== trim( (string) $value ) ? explode( ',', (string) $value ) : array() );
			$picked = array_values( array_unique( array_filter( array_map( static function ( $v ) { return is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : ''; }, $picked ), 'strlen' ) ) );
			$options = self::options( $field );
			if ( array_diff( $picked, $options ) ) { return array( '', 'invalid' ); }
			// Stored in the order of the choices, so the same selection always reads the same.
			return array( implode( self::MULTI_SEPARATOR, array_values( array_intersect( $options, $picked ) ) ), '' );
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		switch ( $type ) {
			case 'email':
				$email = Http::optional_email( $value );
				return false === $email ? array( '', 'invalid' ) : array( (string) $email, '' );
			case 'textarea':
				return array( sanitize_textarea_field( $value ), '' );
			case 'checkbox':
				return array( $value && '0' !== $value ? '1' : '', '' );
			case 'select':
				$value = sanitize_text_field( $value );
				return '' === $value || in_array( $value, self::options( $field ), true ) ? array( $value, '' ) : array( '', 'invalid' );
			case 'tel':
				$value = sanitize_text_field( $value );
				if ( '' === $value ) { return array( '', '' ); }
				$digits = preg_replace( '/\D/', '', $value );
				return preg_match( '#^[0-9 +()/.\-]{5,30}$#', $value ) && strlen( $digits ) >= 5 ? array( $value, '' ) : array( '', 'invalid' );
			case 'date':
				if ( '' === $value ) { return array( '', '' ); }
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return array( '', 'invalid' ); }
				return array( $value, '' );
			case 'number':
				if ( '' === $value ) { return array( '', '' ); }
				$value = str_replace( ',', '.', $value );
				return preg_match( '/^-?\d{1,12}(\.\d{1,6})?$/', $value ) ? array( $value, '' ) : array( '', 'invalid' );
		}
		return array( sanitize_text_field( $value ), '' );
	}

	/** Error message for an answer that does not fit its field. */
	public static function invalid_message( $field ) {
		$label = (string) ( is_object( $field ) ? $field->label : ( $field['label'] ?? '' ) );
		switch ( self::type( $field ) ) {
			case 'email':
				/* translators: %s: field label */
				return sprintf( __( '„%s“ ist keine gültige E-Mail-Adresse.', 'pass-captain' ), $label );
			case 'tel':
				/* translators: %s: field label */
				return sprintf( __( '„%s“ ist keine gültige Telefonnummer.', 'pass-captain' ), $label );
			case 'date':
				/* translators: %s: field label */
				return sprintf( __( '„%s“ ist kein gültiges Datum (JJJJ-MM-TT).', 'pass-captain' ), $label );
			case 'number':
				/* translators: %s: field label */
				return sprintf( __( '„%s“ ist keine gültige Zahl.', 'pass-captain' ), $label );
			case 'select':
			case 'multiselect':
				/* translators: %s: field label */
				return sprintf( __( 'Bitte für „%s“ nur angebotene Auswahlmöglichkeiten wählen.', 'pass-captain' ), $label );
		}
		/* translators: %s: field label */
		return sprintf( __( '„%s“ ist ungültig.', 'pass-captain' ), $label );
	}

	/**
	 * Whether every ticket of the type needs an attendee name (A552).
	 *
	 * @param int         $ticket_type_id Ticket type ID.
	 * @param object|null $row            Loaded ticket type row (saves the query when it has the column).
	 */
	public static function name_required( $ticket_type_id, $row = null ) {
		global $wpdb;
		$ticket_type_id = absint( $ticket_type_id );
		if ( is_object( $row ) && property_exists( $row, 'attendee_name_required' ) ) {
			$required = (bool) (int) $row->attendee_name_required;
		} else {
			$required = self::name_switch_ready() && (bool) (int) $wpdb->get_var( $wpdb->prepare( "SELECT attendee_name_required FROM {$wpdb->prefix}wpcap_ticket_types WHERE ticket_type_id=%d", $ticket_type_id ) );
		}
		/**
		 * Whether a ticket type needs an attendee name on every ticket (A552).
		 *
		 * @param bool $required Ticket type setting.
		 * @param int  $type_id  Ticket type ID.
		 */
		return (bool) apply_filters( 'wpcap_attendee_name_required', $required, $ticket_type_id );
	}

	/** Field definition as shown to the checkout (no answers): key, label, type, required, purpose, help, scope, options. */
	public static function public_definition( $row ) {
		$row = (array) $row;
		$type = self::type( $row );
		$out = array(
			'key' => (string) ( $row['field_key'] ?? $row['key'] ?? '' ),
			'label' => (string) ( $row['label'] ?? '' ),
			'type' => $type,
			'required' => (int) ( $row['required'] ?? 0 ),
			'purpose' => (string) ( $row['purpose'] ?? '' ),
			'help' => (string) ( $row['help_text'] ?? '' ),
			'scope' => self::scope( $row ),
		);
		if ( in_array( $type, self::CHOICE_TYPES, true ) || 'radio' === $type ) { $out['options'] = self::options( $row ); }
		return $out;
	}

	/**
	 * Input control of a select, multi-select, phone, date or number field for the server-rendered
	 * forms (ticket page, order portal, waitlist offer). $attrs: id plus name (posted forms; a
	 * multi-select posts name[]) or data-answer (portal script; a multi-select is a group with
	 * data-multi). Other types return '' (the forms keep their own text/textarea/checkbox markup).
	 *
	 * @param object|array $field Field definition.
	 * @param array        $attrs Base attributes.
	 * @param string       $value Stored answer.
	 */
	public static function control_html( $field, array $attrs, $value = '' ) {
		$type = self::type( $field );
		$required = ! empty( ( (array) $field )['required'] );
		$attr = static function ( array $a ) {
			$out = '';
			foreach ( $a as $k => $v ) { $out .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"'; }
			return $out;
		};
		if ( 'select' === $type ) {
			$html = '<select' . $attr( $attrs ) . ( $required ? ' required' : '' ) . '><option value="">' . esc_html__( 'Bitte wählen', 'pass-captain' ) . '</option>';
			foreach ( self::options( $field ) as $option ) { $html .= '<option value="' . esc_attr( $option ) . '"' . ( (string) $value === $option ? ' selected' : '' ) . '>' . esc_html( $option ) . '</option>'; }
			return $html . '</select>';
		}
		if ( 'multiselect' === $type ) {
			$chosen = '' === (string) $value ? array() : explode( self::MULTI_SEPARATOR, (string) $value );
			$id = (string) ( $attrs['id'] ?? 'wpcap-ms-' . wp_rand( 1000, 999999 ) );
			$group = isset( $attrs['data-answer'] ) ? array( 'id' => $id, 'data-answer' => $attrs['data-answer'], 'data-multi' => '1' ) : array( 'id' => $id );
			$html = '<span role="group"' . $attr( $group ) . '>';
			foreach ( self::options( $field ) as $n => $option ) {
				$box = array( 'type' => 'checkbox', 'id' => $id . '-o' . $n, 'value' => $option );
				if ( isset( $attrs['name'] ) ) { $box['name'] = $attrs['name'] . '[]'; }
				$html .= '<label for="' . esc_attr( $id . '-o' . $n ) . '"><input' . $attr( $box ) . ( in_array( $option, $chosen, true ) ? ' checked' : '' ) . '> ' . esc_html( $option ) . '</label> ';
			}
			return $html . '</span>';
		}
		$types = array( 'tel' => 'tel', 'date' => 'date', 'number' => 'text' );
		if ( ! isset( $types[ $type ] ) ) { return ''; }
		$extra = 'number' === $type ? array( 'inputmode' => 'decimal' ) : array();
		return '<input type="' . $types[ $type ] . '"' . $attr( $attrs + $extra + array( 'value' => (string) $value ) ) . ( $required ? ' required' : '' ) . '>';
	}

	/**
	 * Stores (or replaces) one answer with the version of its field. Returns false on a database error.
	 */
	public static function save_answer( $ticket_id, $field_id, $value, $now = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$now = $now ? $now : Clock::mysql();
		if ( self::answers_versioned() && self::ready() ) {
			$version = (int) $wpdb->get_var( $wpdb->prepare( "SELECT version FROM {$p}wpcap_participant_fields WHERE field_id=%d", absint( $field_id ) ) );
			return false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,field_version,created_at,updated_at) VALUES (%d,%d,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE answer=VALUES(answer),field_version=VALUES(field_version),updated_at=VALUES(updated_at)", absint( $ticket_id ), absint( $field_id ), (string) $value, $version, $now, $now ) );
		}
		return false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE answer=VALUES(answer),updated_at=VALUES(updated_at)", absint( $ticket_id ), absint( $field_id ), (string) $value, $now, $now ) );
	}

	/**
	 * A682: what is still missing per valid ticket – the attendee name (ticket types with "name
	 * required") and required, active participant fields without an answer (consents are never
	 * requested afterwards: they are voluntary). Tickets without gaps are left out.
	 *
	 * @param int[] $ticket_ids Ticket IDs.
	 * @param array<int,array<int,string>>|null $answers Answers already loaded by the caller (ticket_id => field_id => answer); null = read them.
	 * @return array<int,string[]> ticket_id => labels of the missing data.
	 */
	public static function missing( array $ticket_ids, $answers = null ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ids ) { return array(); }
		$in = implode( ',', $ids );
		$tickets = (array) $wpdb->get_results( "SELECT t.ticket_id,t.ticket_type_id,COALESCE(ta.attendee_name,'') AS attendee_name FROM {$p}wpcap_tickets t LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_id IN ($in) AND t.status='valid' ORDER BY t.ticket_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		if ( ! $tickets ) { return array(); }
		$types = implode( ',', array_unique( array_map( static function ( $t ) { return (int) $t->ticket_type_id; }, $tickets ) ) );
		$fields = array();
		foreach ( (array) $wpdb->get_results( "SELECT field_id,ticket_type_id,label FROM {$p}wpcap_participant_fields WHERE ticket_type_id IN ($types) AND status='active' AND required=1" . ( Consents::ready() ? ' AND consent=0' : '' ) . ' ORDER BY position,field_id' ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$fields[ (int) $f->ticket_type_id ][ (int) $f->field_id ] = (string) $f->label;
		}
		$names = array();
		if ( self::name_switch_ready() ) {
			foreach ( (array) $wpdb->get_results( "SELECT ticket_type_id,attendee_name_required FROM {$p}wpcap_ticket_types WHERE ticket_type_id IN ($types)" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
				$names[ (int) $row->ticket_type_id ] = self::name_required( (int) $row->ticket_type_id, $row );
			}
		}
		$answered = array();
		if ( is_array( $answers ) ) {
			// Answers the caller already loaded (ticket_id => field_id => answer, A442: no second query).
			foreach ( $answers as $tid => $by_field ) {
				foreach ( (array) $by_field as $fid => $answer ) { if ( '' !== (string) $answer ) { $answered[ (int) $tid ][ (int) $fid ] = true; } }
			}
		} else foreach ( (array) $wpdb->get_results( "SELECT ticket_id,field_id FROM {$p}wpcap_participant_answers WHERE ticket_id IN ($in) AND answer<>''" ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$answered[ (int) $a->ticket_id ][ (int) $a->field_id ] = true;
		}
		$out = array();
		foreach ( $tickets as $t ) {
			$labels = array();
			if ( ! empty( $names[ (int) $t->ticket_type_id ] ) && '' === trim( (string) $t->attendee_name ) ) { $labels[] = __( 'Name', 'pass-captain' ); }
			foreach ( $fields[ (int) $t->ticket_type_id ] ?? array() as $field_id => $label ) {
				if ( empty( $answered[ (int) $t->ticket_id ][ $field_id ] ) ) { $labels[] = $label; }
			}
			if ( $labels ) { $out[ (int) $t->ticket_id ] = $labels; }
		}
		return $out;
	}

	/** Minimum time between two requests for missing data of the same order (A682). */
	const REQUEST_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * A682: asks the buyer for the missing attendee data – a mail that names, per ticket, what is
	 * missing and carries a new personal order link (the previous order link stops working; the
	 * portal lets the buyer fill in the data per ticket). Admin action (capability of the orders
	 * page, checked by the caller), at most once per REQUEST_INTERVAL per order, audited without
	 * personal data.
	 *
	 * @return array|\WP_Error {order_id, tickets, sent}
	 */
	public static function request_missing( $order_id ) {
		global $wpdb;
		$order = Orders::get( $order_id );
		if ( ! $order ) { return new \WP_Error( 'wpcap_order_missing', __( 'Bestellung nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( 'paid' !== (string) $order->payment_status ) { return new \WP_Error( 'wpcap_order_unpaid', __( 'Angaben können erst nach bestätigter Zahlung angefordert werden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$tickets = Orders::tickets_for_order( (int) $order->order_id );
		$missing = self::missing( array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
		if ( ! $missing ) { return new \WP_Error( 'wpcap_attendee_data_complete', __( 'Für diese Bestellung fehlen keine Angaben zu Teilnehmenden.', 'pass-captain' ), array( 'status' => 409 ) ); }
		if ( Mail\Mailer::is_placeholder( (string) $order->customer_email ) || ! is_email( (string) $order->customer_email ) ) {
			return new \WP_Error( 'wpcap_attendee_request_no_email', __( 'Für diese Bestellung ist keine E-Mail-Adresse hinterlegt.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$since = gmdate( 'Y-m-d H:i:s', Clock::now() - self::REQUEST_INTERVAL );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_audit_logs WHERE action='attendee_data_requested' AND object_type='order' AND object_id=%d AND created_at>=%s", (int) $order->order_id, $since ) ) ) {
			return new \WP_Error( 'wpcap_attendee_request_recent', __( 'Die Angaben wurden für diese Bestellung gerade erst angefordert. Bitte später erneut versuchen.', 'pass-captain' ), array( 'status' => 429 ) );
		}
		$by_id = array();
		foreach ( $tickets as $t ) { $by_id[ (int) $t->ticket_id ] = $t; }
		$list = array();
		foreach ( $missing as $ticket_id => $labels ) {
			$t = $by_id[ $ticket_id ] ?? null;
			if ( ! $t ) { continue; }
			/* translators: 1: event and ticket type, 2: date, 3: missing fields */
			$list[] = sprintf( __( '%1$s (%2$s): %3$s', 'pass-captain' ), $t->event_name . ' — ' . $t->ticket_name, Orders::format_start( $t->starts_at, $t->timezone ), implode( ', ', $labels ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->update( $wpdb->prefix . 'wpcap_orders', array( 'access_token_hash' => hash( 'sha256', $token ), 'access_expires_at' => gmdate( 'Y-m-d H:i:s', Clock::now() + 30 * DAY_IN_SECONDS ) ), array( 'order_id' => (int) $order->order_id ), array( '%s', '%s' ), array( '%d' ) );
		$sent = Mail\Mailer::send( 'attendee_data_request', (string) $order->customer_email, array(
			'order_id' => Frontend\Format::order_reference( (string) $order->public_id ),
			'missing_list' => Mail\Mailer::block( implode( "\n", $list ) . "\n", '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $list ) ) . '</li></ul>' ),
			'access_link' => Mail\Mailer::link( PortalSelfService::portal_url( (string) $order->public_id, $token ) ),
		), array(), (string) $order->locale, (int) $order->order_id );
		Audit::record( 'attendee_data_requested', 'order', (int) $order->order_id, array( 'tickets' => count( $list ), 'sent' => (bool) $sent ) );
		return array( 'order_id' => (int) $order->order_id, 'tickets' => count( $list ), 'sent' => (bool) $sent );
	}

	/**
	 * A683: whether buyers/holders may still change a field themselves (order portal, ticket page).
	 * "locked" fields never; otherwise until the field's own deadline (edit_hours before the start,
	 * 0 = none) – the general deadline (setting attendee_edit_hours) closes the whole form anyway.
	 * Admin corrections are not affected.
	 *
	 * @param object|array $field     Field definition.
	 * @param string       $starts_at Start of the occurrence (UTC, Y-m-d H:i:s).
	 */
	public static function self_editable( $field, $starts_at ) {
		$field = (object) $field;
		if ( 'locked' === (string) ( $field->self_service ?? 'edit' ) ) { return false; }
		$hours = (int) ( $field->edit_hours ?? 0 );
		return $hours <= 0 || strtotime( $starts_at . ' UTC' ) - $hours * HOUR_IN_SECONDS > Clock::now();
	}

	/**
	 * A683: field keys a holder may no longer change on this ticket type/occurrence.
	 *
	 * @return string[]
	 */
	public static function locked_keys( $ticket_type_id, $starts_at ) {
		global $wpdb;
		if ( ! self::locks_ready() ) { return array(); }
		$keys = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_key,self_service,edit_hours FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND status='active'", absint( $ticket_type_id ) ) ) as $f ) {
			if ( ! self::self_editable( $f, $starts_at ) ) { $keys[] = (string) $f->field_key; }
		}
		return $keys;
	}

	/**
	 * A683: refuses a self-service change of a locked field. Locked fields that were submitted with
	 * their stored value (or not at all) are fine – they are left out of the update.
	 *
	 * @param int      $ticket_id Ticket.
	 * @param string[] $locked    Locked field keys.
	 * @param array    $answers   Submitted answers (field_key => value).
	 * @return true|\WP_Error
	 */
	public static function check_locked( $ticket_id, array $locked, array $answers ) {
		global $wpdb;
		if ( ! $locked ) { return true; }
		$p = $wpdb->prefix;
		foreach ( $locked as $key ) {
			if ( ! array_key_exists( $key, $answers ) ) { continue; }
			$field = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::columns( 'f.' ) . ",a.answer FROM {$p}wpcap_participant_fields f INNER JOIN {$p}wpcap_tickets t ON t.ticket_type_id=f.ticket_type_id LEFT JOIN {$p}wpcap_participant_answers a ON a.field_id=f.field_id AND a.ticket_id=t.ticket_id WHERE t.ticket_id=%d AND f.field_key=%s", absint( $ticket_id ), (string) $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static column list.
			if ( ! $field ) { continue; }
			list( $value ) = self::normalize( $field, $answers[ $key ] );
			if ( $value !== (string) ( $field->answer ?? '' ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'wpcap_attendee_field_locked', sprintf( __( '„%s“ kann nicht mehr selbst geändert werden. Bitte wende dich an den Veranstalter.', 'pass-captain' ), $field->label ), array( 'status' => 403, 'field_key' => (string) $key ) );
			}
		}
		return true;
	}

	/**
	 * Whether a saved definition differs from the stored one in a versioned column.
	 *
	 * @param object $old  Stored row.
	 * @param array  $data New column values.
	 */
	public static function version_changed( $old, array $data ) {
		foreach ( self::VERSIONED as $col ) {
			if ( array_key_exists( $col, $data ) && (string) ( $old->$col ?? '' ) !== (string) $data[ $col ] ) { return true; }
		}
		return false;
	}
}
