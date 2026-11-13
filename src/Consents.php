<?php
/**
 * Consent records for participant fields marked as consent (A116).
 *
 * A participant field with `consent=1` (type checkbox, purpose required) is a consent. Every grant
 * and every withdrawal is appended to `wpcap_consent_log` (never updated, except the anonymisation
 * on privacy erasure); the current state of a ticket's consent is the action of its latest log row.
 * Each record names the exact consent text shown (`wpcap_consent_texts`: label + purpose, version =
 * SHA-256 of both), the locale, the time, the source (checkout, woocommerce, box_office, api, portal,
 * ticket_page, admin, …) and a pseudonymous person reference (keyed hash of the e-mail address). The
 * IP address is only kept as keyed hash for the buyer's own checkout, never in clear.
 *
 * Grants collected in a checkout travel with the attendee data of the order (`consent` entry, see
 * annotate()) and are recorded when the tickets are issued, with the time of the checkout. Hooks
 * `wpcap_consent_granted` / `wpcap_consent_withdrawn` fire after the data is committed.
 *
 * @package PassCaptain
 */

namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Consents {
	const GRANTED = 'granted';
	const WITHDRAWN = 'withdrawn';
	/** Sources a consent record can name (unknown channel sources are kept as sanitized key). */
	const SOURCES = array( 'checkout', 'woocommerce', 'box_office', 'api', 'portal', 'ticket_page', 'admin', 'exchange', 'transfer', 'holder_change', 'privacy_erasure' );

	/** Log IDs of checkout grants recorded inside the issuance transaction; hooks fire after commit. */
	private static $pending = array();

	public static function register() {
		add_action( 'wpcap_admin_order_detail', array( __CLASS__, 'render_admin_section' ), 20, 2 );
	}

	/** Schema 3.9.5 present (older schema during a deferred upgrade: feature off). Only true is cached. */
	public static function ready() {
		static $ready = array();
		global $wpdb;
		$key = (string) $wpdb->prefix;
		if ( empty( $ready[ $key ] ) ) {
			$ready[ $key ] = null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_participant_fields LIKE 'consent'" ) && null !== $wpdb->get_row( "SHOW COLUMNS FROM {$wpdb->prefix}wpcap_consent_log LIKE 'log_id'" );
		}
		return $ready[ $key ];
	}

	/* ------------------------------------------------------------------ Texts and fields */

	/** Version of a consent text: SHA-256 of the exact label and purpose shown. */
	public static function text_version( $label, $purpose ) {
		return hash( 'sha256', (string) $label . "\n" . (string) $purpose );
	}

	/**
	 * Text row (text_id, text_version) of the field's current label and purpose; a changed text
	 * creates a new version, an unchanged one reuses the existing row.
	 *
	 * @param object $field Participant field row (field_id, label, purpose).
	 * @return array{text_id:int,text_version:string}
	 */
	public static function ensure_text( $field ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcap_consent_texts';
		$version = self::text_version( $field->label, $field->purpose );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT text_id FROM $table WHERE field_id=%d AND text_version=%s", (int) $field->field_id, $version ) );
		if ( ! $id ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (field_id,text_version,label,purpose,created_at) VALUES (%d,%s,%s,%s,%s)", (int) $field->field_id, $version, (string) $field->label, (string) $field->purpose, current_time( 'mysql', true ) ) );
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT text_id FROM $table WHERE field_id=%d AND text_version=%s", (int) $field->field_id, $version ) );
		}
		return array( 'text_id' => $id, 'text_version' => $version );
	}

	/** Consent field rows by field_id (any status) for the given IDs. */
	private static function fields_by_id( array $field_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $field_ids ) ) ) );
		if ( ! $ids || ! self::ready() ) { return array(); }
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT field_id,ticket_type_id,field_key,label,purpose,required,status FROM {$wpdb->prefix}wpcap_participant_fields WHERE consent=1 AND field_id IN (" . implode( ',', $ids ) . ')' ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
			$out[ (int) $row->field_id ] = $row;
		}
		return $out;
	}

	/** Active consent fields of a ticket type. */
	public static function fields_for_type( $ticket_type_id ) {
		global $wpdb;
		if ( ! self::ready() ) { return array(); }
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_id,ticket_type_id,field_key,label,purpose,required,status FROM {$wpdb->prefix}wpcap_participant_fields WHERE ticket_type_id=%d AND consent=1 AND status='active' ORDER BY position,field_id", absint( $ticket_type_id ) ) );
	}

	/** Source label stored with a record: channel sources of Orders::create() mapped to consent sources. */
	public static function source_for_channel( $channel ) {
		$channel = sanitize_key( (string) $channel );
		$map = array( '' => 'checkout', 'native' => 'checkout', 'pos' => 'box_office', 'partner' => 'api' );
		$source = $map[ $channel ] ?? $channel;
		return '' !== $source ? substr( $source, 0, 24 ) : 'checkout';
	}

	/** Keyed hash of an e-mail address (pseudonymous person reference; '' for none/placeholders). */
	public static function subject_hash( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! is_email( $email ) || Privacy::is_placeholder_email( $email ) ) { return ''; }
		return hash_hmac( 'sha256', $email, 'wpcap-consent|' . wp_salt( 'auth' ) );
	}

	/** Keyed hash of the client IP (same approach as the checkout rate limit, own key); never the IP itself. */
	public static function ip_hash( $ip ) {
		$ip = trim( (string) $ip );
		return '' === $ip ? '' : hash_hmac( 'sha256', $ip, 'wpcap-consent-ip|' . wp_salt( 'nonce' ) );
	}

	/* ------------------------------------------------------------------ Checkout */

	/**
	 * Adds the consent proof to validated attendee entries (Orders::validate_attendees() output):
	 * for every ticked consent field the text version shown, plus time, source, locale and IP hash.
	 * Stored with the order's attendee data and recorded at issuance (record_checkout()).
	 */
	public static function annotate( array $attendees, $source, $locale, $ip_hash = '' ) {
		$ids = array();
		foreach ( $attendees as $attendee ) { $ids = array_merge( $ids, array_keys( (array) ( $attendee['answers'] ?? array() ) ) ); }
		$fields = self::fields_by_id( $ids );
		if ( ! $fields ) { return $attendees; }
		$now = current_time( 'mysql', true );
		foreach ( $attendees as $index => $attendee ) {
			$texts = array();
			foreach ( (array) ( $attendee['answers'] ?? array() ) as $field_id => $value ) {
				if ( isset( $fields[ (int) $field_id ] ) && '1' === (string) $value ) {
					$texts[ (int) $field_id ] = self::ensure_text( $fields[ (int) $field_id ] )['text_id'];
				}
			}
			if ( $texts ) {
				$attendees[ $index ]['consent'] = array( 'at' => $now, 'source' => self::source_for_channel( $source ), 'locale' => substr( (string) $locale, 0, 20 ), 'ip' => (string) $ip_hash, 'user' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'texts' => $texts );
			}
		}
		return $attendees;
	}

	/**
	 * Records the checkout grants of one issued ticket (inside the issuance transaction: plain
	 * inserts only, hooks are queued for flush_pending()).
	 */
	public static function record_checkout( $ticket_id, $order, array $attendee ) {
		global $wpdb;
		$proof = is_array( $attendee['consent'] ?? null ) ? $attendee['consent'] : array();
		$texts = is_array( $proof['texts'] ?? null ) ? $proof['texts'] : array();
		if ( ! $texts ) { return; }
		$fields = self::fields_by_id( array_keys( $texts ) );
		$email = is_email( (string) ( $attendee['email'] ?? '' ) ) ? (string) $attendee['email'] : (string) $order->customer_email;
		foreach ( $texts as $field_id => $text_id ) {
			$field = $fields[ (int) $field_id ] ?? null;
			if ( ! $field ) { continue; }
			$version = (string) $wpdb->get_var( $wpdb->prepare( "SELECT text_version FROM {$wpdb->prefix}wpcap_consent_texts WHERE text_id=%d", absint( $text_id ) ) );
			$log_id = self::insert( array(
				'ticket_id' => (int) $ticket_id,
				'order_id' => (int) $order->order_id,
				'field_id' => (int) $field->field_id,
				'field_key' => (string) $field->field_key,
				'text_id' => absint( $text_id ),
				'text_version' => $version,
				'action' => self::GRANTED,
				'source' => sanitize_key( (string) ( $proof['source'] ?? 'checkout' ) ),
				'locale' => (string) ( $proof['locale'] ?? '' ),
				'subject_hash' => self::subject_hash( $email ),
				'ip_hash' => (string) ( $proof['ip'] ?? '' ),
				'actor_user_id' => absint( $proof['user'] ?? 0 ),
				'created_at' => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $proof['at'] ?? '' ) ) ? (string) $proof['at'] : current_time( 'mysql', true ),
			) );
			if ( $log_id ) { self::$pending[] = $log_id; }
		}
	}

	/** Fires the hooks of checkout grants of an order once its issuance is committed. */
	public static function flush_pending( $order_id ) {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'absint', self::$pending ) ) );
		self::$pending = array();
		if ( ! $ids ) { return; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_consent_log WHERE order_id=%d AND log_id IN (" . implode( ',', $ids ) . ') ORDER BY log_id', absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( (array) $rows as $row ) { self::fire( $row ); }
	}

	/* ------------------------------------------------------------------ Log */

	/** Appends a log row; returns the log ID (0 on failure). */
	private static function insert( array $row ) {
		global $wpdb;
		$row = array_merge( array( 'order_id' => 0, 'text_id' => 0, 'text_version' => '', 'locale' => '', 'subject_hash' => '', 'ip_hash' => '', 'actor_user_id' => 0, 'note' => '', 'created_at' => current_time( 'mysql', true ) ), $row );
		$row['source'] = substr( sanitize_key( (string) $row['source'] ), 0, 24 );
		$row['locale'] = substr( (string) $row['locale'], 0, 20 );
		$row['note'] = mb_substr( (string) $row['note'], 0, 190 );
		$ok = $wpdb->insert( $wpdb->prefix . 'wpcap_consent_log', $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Log rows of the tickets, oldest first, with field and text details. */
	public static function history( array $ticket_ids ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ids || ! self::ready() ) { return array(); }
		return (array) $wpdb->get_results( "SELECT l.*,COALESCE(ct.label,f.label,l.field_key) AS label,COALESCE(ct.purpose,f.purpose,'') AS purpose FROM {$p}wpcap_consent_log l LEFT JOIN {$p}wpcap_consent_texts ct ON ct.text_id=l.text_id LEFT JOIN {$p}wpcap_participant_fields f ON f.field_id=l.field_id WHERE l.ticket_id IN (" . implode( ',', $ids ) . ') ORDER BY l.log_id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/**
	 * Current state per ticket and field, derived from the log: latest row wins.
	 *
	 * @return array<int,array<int,object>> ticket_id => field_id => latest log row.
	 */
	public static function states( array $ticket_ids ) {
		$out = array();
		foreach ( self::history( $ticket_ids ) as $row ) { $out[ (int) $row->ticket_id ][ (int) $row->field_id ] = $row; }
		return $out;
	}

	/** Whether the ticket's consent for the field is currently granted. */
	public static function is_granted( $ticket_id, $field_id ) {
		$state = self::states( array( $ticket_id ) )[ (int) $ticket_id ][ (int) $field_id ] ?? null;
		return $state && self::GRANTED === $state->action;
	}

	/** Ticket row with order and the current holder's e-mail. */
	private static function ticket( $ticket_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT t.ticket_id,t.public_id,t.order_id,t.ticket_type_id,t.status,t.attendee_email,ta.attendee_email AS assigned_email,o.customer_email,o.locale FROM {$p}wpcap_tickets t INNER JOIN {$p}wpcap_orders o ON o.order_id=t.order_id LEFT JOIN {$p}wpcap_ticket_assignments ta ON ta.ticket_id=t.ticket_id WHERE t.ticket_id=%d", absint( $ticket_id ) ) );
	}

	/** E-mail of the person the ticket's consents belong to (attendee, else buyer). */
	private static function holder_email( $ticket ) {
		foreach ( array( $ticket->assigned_email ?? '', $ticket->attendee_email ?? '', $ticket->customer_email ?? '' ) as $email ) {
			if ( '' !== (string) $email ) { return (string) $email; }
		}
		return '';
	}

	/**
	 * Grants a consent for a ticket (self-service re-grant). Appends a log row unless already granted.
	 *
	 * @param array $ctx locale, ip_hash, note.
	 * @return array|\WP_Error Log row as array.
	 */
	public static function grant( $ticket_id, $field_id, $source, array $ctx = array() ) {
		$ticket = self::ticket( $ticket_id );
		$field = self::fields_by_id( array( $field_id ) )[ absint( $field_id ) ] ?? null;
		if ( ! $ticket || ! $field || (int) $field->ticket_type_id !== (int) $ticket->ticket_type_id ) {
			return new \WP_Error( 'wpcap_consent_invalid', __( 'Diese Einwilligung gibt es für dieses Ticket nicht.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== $field->status || ! in_array( $ticket->status, array( 'valid', 'checked_in' ), true ) ) {
			return new \WP_Error( 'wpcap_consent_closed', __( 'Diese Einwilligung kann für dieses Ticket nicht mehr erteilt werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		return self::append( $ticket, $field, self::GRANTED, $source, $ctx );
	}

	/**
	 * Withdraws a consent (self-service, admin, transfer, erasure). Appends a log row if it is
	 * currently granted and removes the ticked answer.
	 *
	 * @param array $ctx locale, ip_hash, note.
	 * @return array|\WP_Error Log row as array.
	 */
	public static function withdraw( $ticket_id, $field_id, $source, array $ctx = array() ) {
		$ticket = self::ticket( $ticket_id );
		$field = self::fields_by_id( array( $field_id ) )[ absint( $field_id ) ] ?? null;
		if ( ! $ticket || ! $field || (int) $field->ticket_type_id !== (int) $ticket->ticket_type_id ) {
			return new \WP_Error( 'wpcap_consent_invalid', __( 'Diese Einwilligung gibt es für dieses Ticket nicht.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		return self::append( $ticket, $field, self::WITHDRAWN, $source, $ctx );
	}

	/** Appends a state change if it changes the state (atomic check in the INSERT … SELECT). */
	private static function append( $ticket, $field, $action, $source, array $ctx ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$text = self::ensure_text( $field );
		$subject = self::subject_hash( self::holder_email( $ticket ) );
		if ( self::WITHDRAWN === $action ) {
			// A withdrawal refers to the grant: its text version and its person (after a transfer the
			// ticket already names the new holder).
			$current = self::states( array( (int) $ticket->ticket_id ) )[ (int) $ticket->ticket_id ][ (int) $field->field_id ] ?? null;
			if ( $current && self::GRANTED === $current->action ) {
				$text = array( 'text_id' => (int) $current->text_id, 'text_version' => (string) $current->text_version );
				$subject = (string) $current->subject_hash;
			}
		}
		$now = current_time( 'mysql', true );
		$condition = self::GRANTED === $action ? "COALESCE(( SELECT x.action FROM {$p}wpcap_consent_log x WHERE x.ticket_id=%d AND x.field_id=%d ORDER BY x.log_id DESC LIMIT 1 ),'')<>'granted'" : "( SELECT x.action FROM {$p}wpcap_consent_log x WHERE x.ticket_id=%d AND x.field_id=%d ORDER BY x.log_id DESC LIMIT 1 )='granted'";
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$p}wpcap_consent_log (ticket_id,order_id,field_id,field_key,text_id,text_version,action,source,locale,subject_hash,ip_hash,actor_user_id,note,created_at) SELECT %d,%d,%d,%s,%d,%s,%s,%s,%s,%s,%s,%d,%s,%s FROM DUAL WHERE $condition", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static condition.
			(int) $ticket->ticket_id, (int) $ticket->order_id, (int) $field->field_id, (string) $field->field_key, (int) $text['text_id'], (string) $text['text_version'], $action,
			substr( sanitize_key( (string) $source ), 0, 24 ), substr( (string) ( $ctx['locale'] ?? $ticket->locale ), 0, 20 ),
			$subject, (string) ( $ctx['ip_hash'] ?? '' ), function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			mb_substr( sanitize_text_field( (string) ( $ctx['note'] ?? '' ) ), 0, 190 ), $now,
			(int) $ticket->ticket_id, (int) $field->field_id
		) );
		if ( 1 !== (int) $inserted ) {
			return self::GRANTED === $action
				? new \WP_Error( 'wpcap_consent_unchanged', __( 'Diese Einwilligung ist bereits erteilt.', 'pass-captain' ), array( 'status' => 409 ) )
				: new \WP_Error( 'wpcap_consent_unchanged', __( 'Diese Einwilligung ist nicht erteilt und kann daher nicht widerrufen werden.', 'pass-captain' ), array( 'status' => 409 ) );
		}
		$log_id = (int) $wpdb->insert_id;
		// The answer mirrors the state, so attendee lists and exports show the current consent.
		if ( self::GRANTED === $action ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}wpcap_participant_answers (ticket_id,field_id,answer,created_at,updated_at) VALUES (%d,%d,'1',%s,%s) ON DUPLICATE KEY UPDATE answer='1',updated_at=VALUES(updated_at)", (int) $ticket->ticket_id, (int) $field->field_id, $now, $now ) );
		} else {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}wpcap_participant_answers WHERE ticket_id=%d AND field_id=%d", (int) $ticket->ticket_id, (int) $field->field_id ) );
		}
		Audit::record( self::GRANTED === $action ? 'consent_granted' : 'consent_withdrawn', 'ticket', (int) $ticket->ticket_id, array( 'order_id' => (int) $ticket->order_id, 'field_key' => (string) $field->field_key, 'source' => sanitize_key( (string) $source ), 'log_id' => $log_id ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wpcap_consent_log WHERE log_id=%d", $log_id ) );
		self::fire( $row, array_key_exists( 'email', $ctx ) ? $ctx['email'] : false );
		return (array) $row;
	}

	/**
	 * Ends every granted consent of a ticket (transfer, holder change, erasure); returns the count.
	 *
	 * @param string|null|false $previous_email E-mail of the person whose consents end, for the hook
	 *                                          (false: the ticket's current holder; null: none).
	 */
	public static function end_for_ticket( $ticket_id, $source, $previous_email = false ) {
		$count = 0;
		$ctx = false === $previous_email ? array() : array( 'email' => null === $previous_email ? null : Privacy::public_email( $previous_email ) );
		foreach ( self::states( array( $ticket_id ) )[ absint( $ticket_id ) ] ?? array() as $field_id => $row ) {
			if ( self::GRANTED === $row->action && ! is_wp_error( self::withdraw( $ticket_id, $field_id, $source, $ctx ) ) ) { ++$count; }
		}
		return $count;
	}

	/** E-mail of the ticket's current holder (attendee, else buyer); '' if unknown. */
	public static function holder_email_of( $ticket_id ) {
		$ticket = self::ticket( $ticket_id );
		return $ticket ? self::holder_email( $ticket ) : '';
	}

	/**
	 * Carries the granted consents of an exchanged ticket over to its replacement (same field key on
	 * the target type). Plain inserts: runs inside the exchange transaction; no hooks (no new consent).
	 */
	public static function carry_over( $old_ticket_id, $new_ticket_id, $target_type_id ) {
		global $wpdb;
		$targets = array();
		foreach ( self::fields_for_type( $target_type_id ) as $field ) { $targets[ $field->field_key ] = $field; }
		$new = self::ticket( $new_ticket_id );
		if ( ! $targets || ! $new ) { return 0; }
		$count = 0;
		foreach ( self::states( array( $old_ticket_id ) )[ absint( $old_ticket_id ) ] ?? array() as $row ) {
			if ( self::GRANTED !== $row->action || ! isset( $targets[ $row->field_key ] ) ) { continue; }
			$count += self::insert( array( 'ticket_id' => (int) $new_ticket_id, 'order_id' => (int) $new->order_id, 'field_id' => (int) $targets[ $row->field_key ]->field_id, 'field_key' => (string) $row->field_key, 'text_id' => (int) $row->text_id, 'text_version' => (string) $row->text_version, 'action' => self::GRANTED, 'source' => 'exchange', 'locale' => (string) $row->locale, 'subject_hash' => (string) $row->subject_hash, 'note' => 'log:' . (int) $row->log_id ) ) ? 1 : 0;
		}
		return $count;
	}

	/**
	 * Fires wpcap_consent_granted / wpcap_consent_withdrawn for a log row.
	 *
	 * @param string|null|false $email E-mail for the record (false: the ticket's current holder).
	 */
	private static function fire( $row, $email = false ) {
		if ( ! $row ) { return; }
		$ticket = self::ticket( (int) $row->ticket_id );
		$record = array(
			'log_id' => (int) $row->log_id,
			'ticket_id' => (int) $row->ticket_id,
			'ticket_public_id' => $ticket ? (string) $ticket->public_id : '',
			'order_id' => (int) $row->order_id,
			'field_id' => (int) $row->field_id,
			'field_key' => (string) $row->field_key,
			'text_version' => (string) $row->text_version,
			'source' => (string) $row->source,
			'locale' => (string) $row->locale,
			'created_at' => (string) $row->created_at,
			'email' => false !== $email ? $email : ( $ticket ? Privacy::public_email( self::holder_email( $ticket ) ) : null ),
		);
		if ( self::GRANTED === $row->action ) {
			/** A consent was granted (after commit). $record: see docs/HOOKS.md. */
			do_action( 'wpcap_consent_granted', $record );
		} else {
			/** A consent was withdrawn or ended (after commit). $record: see docs/HOOKS.md. */
			do_action( 'wpcap_consent_withdrawn', $record );
		}
	}

	/* ------------------------------------------------------------------ Privacy */

	/** Privacy export rows of one ticket's consent history ([] without records). */
	public static function export_value( $ticket_id ) {
		$items = array();
		foreach ( self::history( array( $ticket_id ) ) as $row ) {
			$items[] = array( 'field' => (string) $row->field_key, 'text' => (string) $row->label, 'purpose' => (string) $row->purpose, 'text_version' => (string) $row->text_version, 'action' => (string) $row->action, 'source' => (string) $row->source, 'locale' => (string) $row->locale, 'time_utc' => (string) $row->created_at );
		}
		return $items;
	}

	/**
	 * Privacy erasure: ends granted consents and removes the person reference (e-mail hash, IP hash,
	 * note) from all records of the tickets. The minimal proof (field, text version, action, source,
	 * time) stays; see docs/BETREIBERPFLICHTEN.md.
	 */
	public static function anonymize_tickets( array $ticket_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ticket_ids ) ) ) );
		if ( ! $ids || ! self::ready() ) { return 0; }
		foreach ( $ids as $id ) { self::end_for_ticket( $id, 'privacy_erasure', null ); }
		return (int) $wpdb->query( "UPDATE {$wpdb->prefix}wpcap_consent_log SET subject_hash='',ip_hash='',note='' WHERE ticket_id IN (" . implode( ',', $ids ) . ") AND (subject_hash<>'' OR ip_hash<>'' OR note<>'')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
	}

	/* ------------------------------------------------------------------ Self-service */

	/** Nonce action of the self-service form (bound to the order or ticket the link opens). */
	public static function nonce_action( $scope ) {
		return 'wpcap_consent_' . sanitize_key( (string) $scope );
	}

	/**
	 * Handles a self-service grant/withdrawal posted from the order portal or ticket page. The caller
	 * has verified the access link; $tickets are the tickets that link may manage (public_id => row
	 * with ticket_id). Nonce field: `_wpcap_consent_nonce`.
	 *
	 * @return string|\WP_Error 'granted' or 'withdrawn'.
	 */
	public static function handle_self_service( array $tickets, array $post, $scope, $source ) {
		$nonce = sanitize_text_field( (string) ( $post['_wpcap_consent_nonce'] ?? '' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::nonce_action( $scope ) ) ) {
			return new \WP_Error( 'wpcap_consent_nonce', __( 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$op = sanitize_key( (string) ( $post['wpcap_consent_op'] ?? '' ) );
		$ticket = $tickets[ sanitize_text_field( (string) ( $post['wpcap_consent_ticket'] ?? '' ) ) ] ?? null;
		if ( ! $ticket || ! in_array( $op, array( 'grant', 'withdraw' ), true ) ) {
			return new \WP_Error( 'wpcap_consent_invalid', __( 'Diese Einwilligung gibt es für dieses Ticket nicht.', 'pass-captain' ), array( 'status' => 404 ) );
		}
		$key = sanitize_key( (string) ( $post['wpcap_consent_field'] ?? '' ) );
		$field_id = 0;
		foreach ( self::fields_for_type( (int) $ticket->ticket_type_id ) as $field ) {
			if ( $field->field_key === $key ) { $field_id = (int) $field->field_id; }
		}
		// Withdrawal also works for fields deactivated meanwhile.
		if ( ! $field_id && 'withdraw' === $op ) {
			foreach ( self::states( array( (int) $ticket->ticket_id ) )[ (int) $ticket->ticket_id ] ?? array() as $row ) {
				if ( $row->field_key === $key ) { $field_id = (int) $row->field_id; }
			}
		}
		$ctx = array( 'ip_hash' => self::ip_hash( Orders::client_ip() ) );
		$result = 'grant' === $op ? self::grant( (int) $ticket->ticket_id, $field_id, $source, $ctx ) : self::withdraw( (int) $ticket->ticket_id, $field_id, $source, $ctx );
		return is_wp_error( $result ) ? $result : ( 'grant' === $op ? 'granted' : 'withdrawn' );
	}

	/** Notice text for the result flag after a self-service redirect ('' for none). */
	public static function result_notice( $flag ) {
		$flag = sanitize_key( (string) $flag );
		if ( 'withdrawn' === $flag ) { return __( 'Deine Einwilligung wurde widerrufen. Der Widerruf gilt ab jetzt.', 'pass-captain' ); }
		if ( 'granted' === $flag ) { return __( 'Deine Einwilligung wurde gespeichert. Du kannst sie hier jederzeit widerrufen.', 'pass-captain' ); }
		if ( 'error' === $flag ) { return __( 'Die Einwilligung konnte nicht geändert werden. Bitte lade die Seite neu und versuche es erneut.', 'pass-captain' ); }
		return '';
	}

	/**
	 * Self-service card: consent fields of the tickets with state and a grant/withdraw button
	 * (plain POST forms, nonce-protected; '' when no ticket has a consent field or record).
	 *
	 * @param object[] $tickets Rows with ticket_id, public_id, ticket_type_id, status, title.
	 * @param string   $scope   Nonce scope (order or ticket public ID).
	 * @param string   $notice  Result notice.
	 */
	public static function self_service_html( array $tickets, $scope, $notice = '' ) {
		$states = self::states( array_map( static function ( $t ) { return (int) $t->ticket_id; }, $tickets ) );
		$nonce = wp_create_nonce( self::nonce_action( $scope ) );
		$cards = '';
		foreach ( $tickets as $ticket ) {
			$fields = array();
			foreach ( self::fields_for_type( (int) $ticket->ticket_type_id ) as $field ) { $fields[ (int) $field->field_id ] = $field; }
			foreach ( $states[ (int) $ticket->ticket_id ] ?? array() as $field_id => $row ) {
				if ( ! isset( $fields[ $field_id ] ) && self::GRANTED === $row->action ) { $fields[ $field_id ] = (object) array( 'field_id' => $field_id, 'field_key' => $row->field_key, 'label' => $row->label, 'purpose' => $row->purpose, 'inactive' => true ); }
			}
			if ( ! $fields ) { continue; }
			$open = in_array( (string) $ticket->status, array( 'valid', 'checked_in' ), true );
			$cards .= '<h3>' . esc_html( (string) ( $ticket->title ?? '' ) ) . '</h3><ul class="wpcap-consents">';
			foreach ( $fields as $field_id => $field ) {
				$row = $states[ (int) $ticket->ticket_id ][ $field_id ] ?? null;
				$granted = $row && self::GRANTED === $row->action;
				if ( $granted ) {
					/* translators: %s: date and time */
					$state = sprintf( __( 'Erteilt am %s', 'pass-captain' ), Frontend\Format::site_datetime( $row->created_at ) );
				} elseif ( $row ) {
					/* translators: %s: date and time */
					$state = sprintf( __( 'Widerrufen am %s', 'pass-captain' ), Frontend\Format::site_datetime( $row->created_at ) );
				} else {
					$state = __( 'Nicht erteilt', 'pass-captain' );
				}
				$cards .= '<li><strong>' . esc_html( (string) $field->label ) . '</strong>';
				if ( '' !== (string) $field->purpose ) { $cards .= '<br><small>' . esc_html( (string) $field->purpose ) . '</small>'; }
				$cards .= '<br><span class="status">' . esc_html( $state ) . '</span>';
				$op = $granted ? 'withdraw' : ( $open && empty( $field->inactive ) ? 'grant' : '' );
				if ( '' !== $op ) {
					$cards .= '<form method="post" class="wpcap-consent-form"><input type="hidden" name="_wpcap_consent_nonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="wpcap_consent_op" value="' . esc_attr( $op ) . '"><input type="hidden" name="wpcap_consent_ticket" value="' . esc_attr( (string) $ticket->public_id ) . '"><input type="hidden" name="wpcap_consent_field" value="' . esc_attr( (string) $field->field_key ) . '">';
					$cards .= '<button type="submit"' . ( 'withdraw' === $op ? ' class="danger"' : '' ) . '>' . esc_html( 'withdraw' === $op ? __( 'Einwilligung widerrufen', 'pass-captain' ) : __( 'Einwilligung erteilen', 'pass-captain' ) ) . '</button></form>';
				}
				$cards .= '</li>';
			}
			$cards .= '</ul>';
		}
		if ( '' === $cards ) { return ''; }
		$html = '<section class="card" id="wpcap-consents" aria-labelledby="wpcap-consents-title"><h2 id="wpcap-consents-title">' . esc_html__( 'Einwilligungen', 'pass-captain' ) . '</h2>';
		$html .= '<p class="muted"><small>' . esc_html__( 'Du kannst jede Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen. Was bis dahin auf ihrer Grundlage geschehen ist, bleibt rechtmäßig.', 'pass-captain' ) . '</small></p>';
		if ( '' !== $notice ) { $html .= '<p role="status"><strong>' . esc_html( $notice ) . '</strong></p>'; }
		return $html . $cards . '</section>';
	}

	/* ------------------------------------------------------------------ Admin */

	/** Order detail (admin): consent state and history per ticket, withdrawal form. */
	public static function render_admin_section( $order, $tickets = array() ) {
		$ids = array_map( static function ( $t ) { return (int) $t->ticket_id; }, (array) $tickets );
		$history = self::history( $ids );
		if ( ! $history ) { return; }
		$by_ticket = array();
		foreach ( $history as $row ) { $by_ticket[ (int) $row->ticket_id ][ (int) $row->field_id ][] = $row; }
		$public = array();
		foreach ( (array) $tickets as $t ) { $public[ (int) $t->ticket_id ] = (string) $t->public_id; }
		$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		echo '<h2 id="wpcap-consents">' . esc_html__( 'Einwilligungen', 'pass-captain' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Ticket', 'pass-captain' ) . '</th><th>' . esc_html__( 'Einwilligung', 'pass-captain' ) . '</th><th>' . esc_html__( 'Stand', 'pass-captain' ) . '</th><th>' . esc_html__( 'Verlauf', 'pass-captain' ) . '</th><th>' . esc_html__( 'Aktion', 'pass-captain' ) . '</th></tr></thead><tbody>';
		foreach ( $by_ticket as $ticket_id => $fields ) {
			foreach ( $fields as $field_id => $rows ) {
				$last = end( $rows );
				$granted = self::GRANTED === $last->action;
				echo '<tr><td><code>' . esc_html( $public[ $ticket_id ] ?? (string) $ticket_id ) . '</code></td>';
				echo '<td><strong>' . esc_html( (string) $last->label ) . '</strong>' . ( '' !== (string) $last->purpose ? '<br><span class="description">' . esc_html( (string) $last->purpose ) . '</span>' : '' ) . '</td>';
				echo '<td>' . esc_html( $granted ? __( 'Erteilt', 'pass-captain' ) : __( 'Widerrufen', 'pass-captain' ) ) . '</td><td><ul style="margin:0">';
				foreach ( $rows as $row ) {
					$label = self::GRANTED === $row->action ? __( 'erteilt', 'pass-captain' ) : __( 'widerrufen', 'pass-captain' );
					/* translators: 1: date and time, 2: granted/withdrawn, 3: source, 4: short text version */
					echo '<li>' . esc_html( sprintf( __( '%1$s: %2$s (Quelle: %3$s, Textversion %4$s)', 'pass-captain' ), Admin\Forms::datetime( $row->created_at, $tz ), $label, self::source_label( $row->source ), substr( (string) $row->text_version, 0, 12 ) ) ) . ( '' !== (string) $row->note && 0 !== strpos( (string) $row->note, 'log:' ) ? ' – ' . esc_html( (string) $row->note ) : '' ) . '</li>';
				}
				echo '</ul></td><td>';
				if ( $granted ) {
					Admin\Forms::open_form( 'wpcap_consent_withdraw', 'wpcap_consent_withdraw_' . (int) $order->order_id );
					Admin\Forms::hidden( 'order_id', (int) $order->order_id );
					Admin\Forms::hidden( 'ticket_id', (int) $ticket_id );
					Admin\Forms::hidden( 'field_id', (int) $field_id );
					echo '<label class="screen-reader-text" for="wpcap-cw-' . (int) $last->log_id . '">' . esc_html__( 'Notiz zum Widerruf', 'pass-captain' ) . '</label><input id="wpcap-cw-' . (int) $last->log_id . '" type="text" name="note" maxlength="190" placeholder="' . esc_attr__( 'z. B. per E-Mail vom 3. 5.', 'pass-captain' ) . '"> ';
					echo '<button class="button" type="submit">' . esc_html__( 'Widerruf erfassen', 'pass-captain' ) . '</button></form>';
				} else {
					echo '—';
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	/** Readable source label. */
	public static function source_label( $source ) {
		$labels = array(
			'checkout' => __( 'Checkout', 'pass-captain' ),
			'woocommerce' => __( 'WooCommerce-Checkout', 'pass-captain' ),
			'box_office' => __( 'Abendkasse', 'pass-captain' ),
			'api' => __( 'API', 'pass-captain' ),
			'portal' => __( 'Bestellübersicht', 'pass-captain' ),
			'ticket_page' => __( 'Ticketseite', 'pass-captain' ),
			'admin' => __( 'Verwaltung', 'pass-captain' ),
			'exchange' => __( 'Ticketumtausch', 'pass-captain' ),
			'transfer' => __( 'Ticketübertragung', 'pass-captain' ),
			'holder_change' => __( 'Inhaberwechsel', 'pass-captain' ),
			'privacy_erasure' => __( 'Datenlöschung', 'pass-captain' ),
		);
		return $labels[ (string) $source ] ?? (string) $source;
	}

	/**
	 * Admin withdrawal (e.g. received by e-mail or phone).
	 *
	 * @return array|\WP_Error
	 */
	public static function admin_withdraw( array $in ) {
		$order_id = Admin\Forms::int( $in, 'order_id' );
		if ( ! Admin\Queries::can_access_order( $order_id, Admin\OrdersPage::CAP ) ) { return Admin\Forms::forbidden( __( 'Keine Berechtigung für diese Bestellung.', 'pass-captain' ) ); }
		$ticket = self::ticket( Admin\Forms::int( $in, 'ticket_id' ) );
		if ( ! $ticket || (int) $ticket->order_id !== $order_id ) { return Admin\Forms::error( 'wpcap_consent_invalid', __( 'Diese Einwilligung gibt es für dieses Ticket nicht.', 'pass-captain' ), 404 ); }
		$result = self::withdraw( (int) $ticket->ticket_id, Admin\Forms::int( $in, 'field_id' ), 'admin', array( 'note' => Admin\Forms::text( $in, 'note' ) ) );
		return is_wp_error( $result ) ? $result : array( 'order_id' => $order_id );
	}

	public static function handle_admin_withdraw() {
		$in = Admin\Forms::post();
		$order_id = Admin\Forms::int( $in, 'order_id' );
		Admin\Forms::verify( 'wpcap_consent_withdraw_' . $order_id );
		Admin\Forms::finish( self::admin_withdraw( $in ), __( 'Widerruf erfasst.', 'pass-captain' ), Admin\OrdersPage::order_url( $order_id ) . '#wpcap-consents' );
	}

	/**
	 * CSV rows (header first) of all consent records of an event, with the current state per
	 * ticket and field. Writes an audit entry.
	 *
	 * @return array|\WP_Error
	 */
	public static function export_rows( $event_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$event_id = absint( $event_id );
		if ( ! $event_id || ! Access::can_access_event( $event_id, Admin\OrdersPage::CAP ) ) {
			return new \WP_Error( 'wpcap_forbidden', __( 'Keine Berechtigung für dieses Event.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*,t.public_id AS ticket_public_id,t.status AS ticket_status,ord.public_id AS order_public_id,tt.name AS ticket_name,o.starts_at,COALESCE(ct.label,f.label,l.field_key) AS label,COALESCE(ct.purpose,f.purpose,'') AS purpose FROM {$p}wpcap_consent_log l INNER JOIN {$p}wpcap_tickets t ON t.ticket_id=l.ticket_id INNER JOIN {$p}wpcap_orders ord ON ord.order_id=t.order_id INNER JOIN {$p}wpcap_ticket_types tt ON tt.ticket_type_id=t.ticket_type_id INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id LEFT JOIN {$p}wpcap_consent_texts ct ON ct.text_id=l.text_id LEFT JOIN {$p}wpcap_participant_fields f ON f.field_id=l.field_id WHERE o.event_id=%d ORDER BY l.ticket_id,l.field_id,l.log_id",
			$event_id
		) );
		$latest = array();
		foreach ( $rows as $row ) { $latest[ (int) $row->ticket_id . ':' . (int) $row->field_id ] = (int) $row->log_id; }
		$out = array( array( __( 'Zeitpunkt (UTC)', 'pass-captain' ), __( 'Bestellung', 'pass-captain' ), __( 'Ticket', 'pass-captain' ), __( 'Ticketstatus', 'pass-captain' ), __( 'Termin (UTC)', 'pass-captain' ), __( 'Ticketart', 'pass-captain' ), __( 'Feld', 'pass-captain' ), __( 'Einwilligungstext', 'pass-captain' ), __( 'Zweck', 'pass-captain' ), __( 'Textversion', 'pass-captain' ), __( 'Aktion', 'pass-captain' ), __( 'Quelle', 'pass-captain' ), __( 'Sprache', 'pass-captain' ), __( 'Personenbezug (Hash)', 'pass-captain' ), __( 'Aktueller Stand', 'pass-captain' ) ) );
		foreach ( $rows as $row ) {
			$is_latest = $latest[ (int) $row->ticket_id . ':' . (int) $row->field_id ] === (int) $row->log_id;
			$out[] = array( (string) $row->created_at, (string) $row->order_public_id, (string) $row->ticket_public_id, (string) $row->ticket_status, (string) $row->starts_at, (string) $row->ticket_name, (string) $row->field_key, (string) $row->label, (string) $row->purpose, (string) $row->text_version, (string) $row->action, (string) $row->source, (string) $row->locale, '' !== (string) $row->subject_hash ? substr( (string) $row->subject_hash, 0, 16 ) : '', $is_latest ? (string) $row->action : '' );
		}
		Audit::record( 'consents_exported', 'event', $event_id, array( 'rows' => count( $rows ) ) );
		return $out;
	}

	public static function handle_export() {
		Admin\Forms::verify( 'wpcap_export_consents' );
		$event_id = absint( Admin\Forms::query_arg( 'event_id' ) );
		$rows = self::export_rows( $event_id );
		if ( is_wp_error( $rows ) ) { wp_die( esc_html( $rows->get_error_message() ), '', array( 'response' => 403 ) ); }
		Reports\Scope::send_download( Reports\Scope::csv( $rows ), 'einwilligungen-' . $event_id . '.csv', 'text/csv; charset=utf-8' );
	}

	/** Admin URL of the consent CSV export of an event (nonce included). */
	public static function export_url( $event_id ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'wpcap_export_consents', 'event_id' => absint( $event_id ) ), admin_url( 'admin-post.php' ) ), 'wpcap_export_consents' );
	}
}
