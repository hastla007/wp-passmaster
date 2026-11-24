<?php
/**
 * Membership and season-pass issue/approve/redeem operations.
 *
 * Pass rules have a scope: "event" rules admit to events (redeem(), scanner/check-in), "service"
 * rules are used for service resources (redeem_service()), "any" rules work for both. Service rules
 * are slot-free (used directly at the resource, e.g. open training) or slot-bound (the use books a
 * place in a slot, capacity-checked like a paid booking). Single use = uses_total 1, multi use =
 * uses_total > 1, unlimited = 0; uses_per_day caps daily uses; a rule may expire before the pass.
 *
 * Issuing (A481): a pass is free admission, i.e. a complimentary ticket of its own. A request by staff
 * with `wpcap_manage_orders` creates a pass in status `pending` (no code exists yet); a second person
 * with `wpcap_approve_comps` and event scope approves it (four-eyes principle: never the requester),
 * only then the code is created and mailed. Site administrators (`manage_options`) issue directly.
 * Lists and actions: admin page "Mitgliedspässe" (Admin\MembershipsPage) and REST.
 *
 * Redeeming (A480, A482): the pass row is locked FOR UPDATE as the first statement of the
 * transaction, so the usage counts read afterwards see every redemption committed before (a plain
 * read before the lock would fix the snapshot and miss concurrent redemptions). Each event use is
 * bound to the occurrence of the scanned ticket type (`wpcap_pass_uses.occurrence_id`, schema
 * 3.10.18): the occurrence must be scheduled and take place today (event time zone, day cutoff), the
 * doors-open time and the default entry window apply, a full occurrence admits no further member, and
 * the optional quota `wpcap_member_pass_occurrence_quota` limits member entries per occurrence. Member
 * entries appear in the admission statistics of the reports (`member_entries`).
 */
namespace PassCaptain;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Memberships {
	const SCOPES = array( 'event', 'service', 'any' );
	const SLOT_MODES = array( 'slot_free', 'slot_bound' );
	/** Capability that approves passes (same right as complimentary tickets). */
	const APPROVE_CAP = 'wpcap_approve_comps';
	/** Capability that requests passes. */
	const REQUEST_CAP = 'wpcap_manage_orders';

	/** Whether the columns of schema 3.10.18 exist (deferred upgrades). */
	public static function schema_ready() {
		return Log::has_column( 'wpcap_pass_uses', 'occurrence_id' ) && Log::has_column( 'wpcap_member_passes', 'requested_by' );
	}

	private static function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/** Validated scope fields of a rule definition. */
	private static function rule_scope( array $rule ) {
		$scope = in_array( $rule['scope'] ?? 'event', self::SCOPES, true ) ? (string) ( $rule['scope'] ?? 'event' ) : 'event';
		$expires = sanitize_text_field( (string) ( $rule['expires_at'] ?? '' ) );
		return array(
			'scope' => $scope,
			'resource_id' => 'event' === $scope ? 0 : absint( $rule['resource_id'] ?? 0 ),
			'slot_mode' => in_array( $rule['slot_mode'] ?? 'slot_free', self::SLOT_MODES, true ) ? (string) ( $rule['slot_mode'] ?? 'slot_free' ) : 'slot_free',
			'expires_at' => '' !== $expires && strtotime( $expires . ' UTC' ) ? gmdate( 'Y-m-d H:i:s', strtotime( $expires . ' UTC' ) ) : null,
		);
	}

	/** Capability for pass rules of an event (REST POST /member-passes and the admin screen). */
	const RULE_CAP = 'wpcap_manage_orders';

	/**
	 * Validates one rule definition for the current user and returns it normalized (scope, event_id,
	 * ticket_type_id, resource_id resolved; other keys kept) or a WP_Error. Shared by the admin screen
	 * and REST so both enforce the same scope:
	 * - "service": one resource the user manages (ServiceResources::can_manage); all resources
	 *   (resource_id 0) only for administrators. Event and ticket type are cleared.
	 * - "any": administrators only (admits to events and resources together).
	 * - "event": the ticket type must belong to the event (the event is taken from the ticket type);
	 *   the user needs the order capability for that event; rules for all events only administrators.
	 *
	 * @param array $in Rule input (scope, event_id, ticket_type_id, resource_id, quotas, slot_mode, expires_at).
	 * @return array|\WP_Error
	 */
	public static function validate_rule_for_current_user( array $in ) {
		$raw_scope = $in['scope'] ?? 'event';
		$scope = is_scalar( $raw_scope ) && in_array( (string) $raw_scope, self::SCOPES, true ) ? (string) $raw_scope : 'event';
		$int = static function ( $key ) use ( $in ) { return is_scalar( $in[ $key ] ?? 0 ) ? absint( $in[ $key ] ?? 0 ) : 0; };
		$is_admin = current_user_can( 'manage_options' );
		if ( 'service' === $scope ) {
			$resource_id = $int( 'resource_id' );
			$resource = $resource_id ? ServiceResources::resource( $resource_id ) : null;
			if ( $resource_id ? ! ( $resource && ServiceResources::can_manage( $resource ) ) : ! $is_admin ) {
				return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für eine Passregel dieser Ressource. Regeln für alle Ressourcen dürfen nur Administratoren anlegen.', 'pass-captain' ), array( 'status' => 403 ) );
			}
			return array( 'scope' => 'service', 'event_id' => 0, 'ticket_type_id' => 0, 'resource_id' => $resource_id ) + $in;
		}
		if ( 'any' === $scope && ! $is_admin ) {
			return new \WP_Error( 'wpcap_pass_forbidden', __( 'Regeln für Events und Ressourcen gemeinsam dürfen nur Administratoren anlegen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$type_id = $int( 'ticket_type_id' );
		$event_id = $int( 'event_id' );
		if ( $type_id ) {
			global $wpdb;
			$type_event = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT o.event_id FROM %i tt INNER JOIN %i o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d', $wpdb->prefix . 'wpcap_ticket_types', $wpdb->prefix . 'wpcap_occurrences', $type_id ) );
			if ( ! $type_event || ( $event_id && $event_id !== $type_event ) ) {
				return new \WP_Error( 'wpcap_pass_rule_scope', __( 'Die Ticketart gehört nicht zum gewählten Event.', 'pass-captain' ), array( 'status' => 400 ) );
			}
			$event_id = $type_event;
		}
		if ( $event_id ? ! Access::can_access_event( $event_id, self::RULE_CAP ) : ! $is_admin ) {
			return new \WP_Error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für eine Passregel. Regeln für alle Events dürfen nur Administratoren anlegen.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		return array( 'scope' => $scope, 'event_id' => $event_id, 'ticket_type_id' => $type_id, 'resource_id' => 'any' === $scope ? $int( 'resource_id' ) : 0 ) + $in;
	}

	/**
	 * Whether the current user may manage (revoke, change) a pass: every rule must be within their
	 * scope – event rules need the order capability for the event, service rules a managed resource,
	 * rules without event/resource and "any" rules need an administrator.
	 */
	public static function current_user_can_manage_pass( $pass_id ) {
		global $wpdb;
		$rules = $wpdb->get_results( $wpdb->prepare( 'SELECT scope,event_id,resource_id FROM %i WHERE pass_id=%d', $wpdb->prefix . 'wpcap_pass_rules', absint( $pass_id ) ) );
		$is_admin = current_user_can( 'manage_options' );
		if ( ! $rules ) { return $is_admin; }
		foreach ( $rules as $rule ) {
			if ( 'service' === $rule->scope ) {
				$resource = (int) $rule->resource_id ? ServiceResources::resource( (int) $rule->resource_id ) : null;
				$ok = $resource ? ServiceResources::can_manage( $resource ) : $is_admin;
			} elseif ( 'any' === $rule->scope ) {
				$ok = $is_admin;
			} else {
				$ok = (int) $rule->event_id ? Access::can_access_event( (int) $rule->event_id, self::RULE_CAP ) : $is_admin;
			}
			if ( ! $ok ) { return false; }
		}
		return true;
	}

	/**
	 * Whether the current user holds $cap for every event the rules name (rules without event =
	 * all events: administrators only).
	 *
	 * @param int[] $event_ids Event ids of the rules (0 = all events).
	 */
	public static function in_scope( array $event_ids, $cap ) {
		if ( ! $event_ids ) { return false; }
		foreach ( $event_ids as $event_id ) {
			if ( (int) $event_id ? ! Access::can_access_event( (int) $event_id, $cap ) : ! current_user_can( 'manage_options' ) ) { return false; }
		}
		return true;
	}

	/** Event ids of a pass's rules. */
	public static function event_ids( $pass_id ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT event_id FROM {$wpdb->prefix}wpcap_pass_rules WHERE pass_id=%d", absint( $pass_id ) ) ) );
	}

	/** Whether the current user may approve or reject the pass (approve cap, scope, not the requester). */
	public static function can_decide( $pass ) {
		if ( ! $pass || ! current_user_can( self::APPROVE_CAP ) || ! self::in_scope( self::event_ids( $pass->pass_id ), self::APPROVE_CAP ) ) { return false; }
		return ! self::self_approval( $pass );
	}

	/** The current user requested this pass and is no administrator: four-eyes principle forbids the approval. */
	public static function self_approval( $pass ) {
		return ! current_user_can( 'manage_options' ) && (int) ( $pass->requested_by ?? 0 ) && (int) $pass->requested_by === get_current_user_id();
	}

	public static function revoke( $pass_id ) {
		global $wpdb;
		$pass_id = absint( $pass_id );
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_member_passes SET status='revoked',token_hash=%s WHERE pass_id=%d AND status IN ('active','pending')", hash( 'sha256', 'revoked|' . bin2hex( random_bytes( 32 ) ) ), $pass_id ) );
		if ( false === $result ) { return new \WP_Error( 'wpcap_pass_revoke_failed', __( 'Pass konnte nicht widerrufen werden.', 'pass-captain' ) ); }
		Audit::record( 'member_pass_revoked', 'member_pass', $pass_id );
		return true;
	}

	/**
	 * Issues (administrators) or requests (everyone else) a pass.
	 *
	 * @return array|\WP_Error pass_id, status (active|pending), token (only when active).
	 */
	/**
	 * A524: a validity given as a pure date (Y-m-d) covers whole days in the site time zone – "from" starts at
	 * 00:00, "until" ends at 23:59:59 local time (stored in UTC). Date-times are taken as UTC as before.
	 *
	 * @param string $value Date or UTC date-time.
	 * @param bool   $end   Whether it is the end of the validity.
	 */
	public static function normalize_validity( $value, $end ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return $value; }
		try {
			$tz = new \DateTimeZone( Reports\Scope::site_timezone() );
			$local = new \DateTimeImmutable( $value . ( $end ? ' 23:59:59' : ' 00:00:00' ), $tz );
			return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return $value;
		}
	}

	public static function issue( $email, $valid_from, $valid_until, array $rules ) {
		$valid_from = self::normalize_validity( $valid_from, false );
		$valid_until = self::normalize_validity( $valid_until, true );
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! $rules || strtotime( $valid_until . ' UTC' ) <= strtotime( $valid_from . ' UTC' ) ) { return new \WP_Error( 'wpcap_pass_invalid', __( 'Passdaten ungültig.', 'pass-captain' ) ); }
		$ready = self::schema_ready();
		// Before the 3.10.18 upgrade there is no approval state: only administrators may issue.
		$direct = current_user_can( 'manage_options' ) || ( ! $ready && current_user_can( self::APPROVE_CAP ) );
		if ( ! $direct && ! $ready ) { return self::error( 'wpcap_pass_forbidden', __( 'Mitgliedspässe können erst nach dem Datenbank-Update beantragt werden.', 'pass-captain' ), 503 ); }
		$token = bin2hex( random_bytes( 32 ) );
		$passes = $wpdb->prefix . 'wpcap_member_passes';
		$now = Clock::mysql();
		$row = array( 'public_id' => bin2hex( random_bytes( 16 ) ), 'member_email' => $email, 'token_hash' => hash( 'sha256', $direct ? $token : 'pending|' . bin2hex( random_bytes( 32 ) ) ), 'valid_from' => sanitize_text_field( $valid_from ), 'valid_until' => sanitize_text_field( $valid_until ), 'status' => $direct ? 'active' : 'pending', 'created_at' => $now );
		if ( $ready ) {
			$row['requested_by'] = get_current_user_id();
			if ( $direct ) { $row['approved_by'] = get_current_user_id(); $row['approved_at'] = $now; }
		}
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert( $passes, $row );
		$pass_id = (int) $wpdb->insert_id;
		if ( ! $pass_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_save_failed', __( 'Pass konnte nicht angelegt werden.', 'pass-captain' ) ); }
		foreach ( $rules as $rule ) {
			$scope = self::rule_scope( (array) $rule );
			$wpdb->insert( $wpdb->prefix . 'wpcap_pass_rules', array( 'pass_id' => $pass_id, 'event_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['ticket_type_id'] ?? 0 ), 'uses_total' => absint( $rule['uses_total'] ?? 0 ), 'uses_per_day' => absint( $rule['uses_per_day'] ?? 1 ) ) + $scope, array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
			if ( ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_rule_failed', __( 'Passregeln konnten nicht gespeichert werden.', 'pass-captain' ) ); }
		}
		$wpdb->query( 'COMMIT' );
		if ( ! $direct ) {
			Audit::record( 'member_pass_requested', 'member_pass', $pass_id, array( 'rule_count' => count( $rules ) ) );
			return array( 'pass_id' => $pass_id, 'status' => 'pending' );
		}
		Mail\Mailer::send( 'member_pass', $email, array( 'pass_code' => $token, 'valid_from' => (string) $valid_from, 'valid_until' => (string) $valid_until ) );
		Audit::record( 'member_pass_issued', 'member_pass', $pass_id, array( 'rule_count' => count( $rules ) ) );
		return array( 'pass_id' => $pass_id, 'status' => 'active', 'token' => $token );
	}

	/**
	 * Approves a requested pass: creates and mails the code. Needs wpcap_approve_comps and event scope
	 * for every rule; the requester can never approve their own request (administrators excepted).
	 *
	 * @return array|\WP_Error pass_id, status, token.
	 */
	public static function approve( $pass_id ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return self::error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( self::self_approval( $pass ) ) { return self::error( 'wpcap_pass_four_eyes', __( 'Einen selbst beantragten Mitgliedspass muss eine andere Person freigeben.', 'pass-captain' ), 403 ); }
		if ( ! self::can_decide( $pass ) ) { return self::error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ), 403 ); }
		if ( 'pending' !== $pass->status ) { return self::error( 'wpcap_pass_state', __( 'Nur beantragte Pässe können freigegeben werden.', 'pass-captain' ), 409 ); }
		$token = bin2hex( random_bytes( 32 ) );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_member_passes SET status='active',token_hash=%s,approved_by=%d,approved_at=%s WHERE pass_id=%d AND status='pending'", hash( 'sha256', $token ), get_current_user_id(), Clock::mysql(), (int) $pass->pass_id ) );
		if ( 1 !== (int) $updated ) { return self::error( 'wpcap_pass_state', __( 'Nur beantragte Pässe können freigegeben werden.', 'pass-captain' ), 409 ); }
		Mail\Mailer::send( 'member_pass', $pass->member_email, array( 'pass_code' => $token, 'valid_from' => (string) $pass->valid_from, 'valid_until' => (string) $pass->valid_until ) );
		Audit::record( 'member_pass_approved', 'member_pass', (int) $pass->pass_id, array( 'requested_by' => (int) $pass->requested_by ) );
		return array( 'pass_id' => (int) $pass->pass_id, 'status' => 'active', 'token' => $token );
	}

	/** Rejects a requested pass (same rights as approve()). */
	public static function reject( $pass_id ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return self::error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), 404 ); }
		if ( ! current_user_can( self::APPROVE_CAP ) || ! self::in_scope( self::event_ids( $pass->pass_id ), self::APPROVE_CAP ) ) { return self::error( 'wpcap_pass_forbidden', __( 'Keine Berechtigung für diesen Pass.', 'pass-captain' ), 403 ); }
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_member_passes SET status='rejected',approved_by=%d,approved_at=%s WHERE pass_id=%d AND status='pending'", get_current_user_id(), Clock::mysql(), (int) $pass->pass_id ) );
		if ( 1 !== (int) $updated ) { return self::error( 'wpcap_pass_state', __( 'Nur beantragte Pässe können abgelehnt werden.', 'pass-captain' ), 409 ); }
		Audit::record( 'member_pass_rejected', 'member_pass', (int) $pass->pass_id );
		return true;
	}

	/**
	 * Passes for the admin list, newest first; only passes whose rules are all in the user's scope.
	 *
	 * @param string $status '' = all.
	 * @return object[] Rows with rules, uses and requester/approver.
	 */
	public static function list_passes( $status = '', $limit = 200 ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$limit = max( 1, min( 1000, (int) $limit ) );
		$rows = (array) ( '' !== $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT m.*,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_pass_uses u WHERE u.pass_id=m.pass_id) AS uses FROM {$wpdb->prefix}wpcap_member_passes m WHERE m.status=%s ORDER BY m.pass_id DESC LIMIT %d", sanitize_key( $status ), $limit ) )
			: $wpdb->get_results( $wpdb->prepare( "SELECT m.*,(SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_pass_uses u WHERE u.pass_id=m.pass_id) AS uses FROM {$wpdb->prefix}wpcap_member_passes m ORDER BY m.pass_id DESC LIMIT %d", $limit ) ) );
		$out = array();
		foreach ( $rows as $row ) {
			$events = self::event_ids( $row->pass_id );
			if ( ! self::in_scope( $events, self::REQUEST_CAP ) && ! self::in_scope( $events, self::APPROVE_CAP ) ) { continue; }
			$row->rules = self::rules( $row->pass_id );
			$out[] = $row;
		}
		return $out;
	}

	/** Ticket type with its occurrence (for redeem). */
	private static function scanned_type( $ticket_type_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.ticket_type_id,tt.status AS type_status,o.occurrence_id,o.event_id,o.starts_at,o.ends_at,o.timezone,o.status AS occurrence_status,o.capacity,e.status AS event_status FROM {$p}wpcap_ticket_types tt INNER JOIN {$p}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id INNER JOIN {$p}wpcap_events e ON e.event_id=o.event_id WHERE tt.ticket_type_id=%d", $ticket_type_id ) );
	}

	/** Whether $ts lies on a local day of the occurrence (start day … end day, day cutoff applied). */
	private static function on_occurrence_day( $type, $ts ) {
		$context = array( 'occurrence_id' => (int) $type->occurrence_id, 'event_id' => (int) $type->event_id );
		$day = Admission\Engine::service_date( $ts, $type->timezone, $context );
		$tz = Admission\Engine::timezone( $type->timezone );
		$first = ( new \DateTimeImmutable( $type->starts_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$end = ! empty( $type->ends_at ) && '0000-00-00 00:00:00' !== $type->ends_at ? $type->ends_at : $type->starts_at;
		$last = ( new \DateTimeImmutable( $end, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
		return $day >= $first && $day <= max( $first, $last );
	}

	public static function redeem( $token, $ticket_type_id, $idempotency_key ) {
		global $wpdb;
		$token = Security\Input::hex( $token );
		$ticket_type_id = absint( $ticket_type_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( 64 !== strlen( $token ) || ! $ticket_type_id || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_pass_redeem_invalid', __( 'Passcode oder Ticketart ungültig.', 'pass-captain' ) ); }
		$key = hash( 'sha256', $token . '|' . $idempotency_key );
		$uses = $wpdb->prefix . 'wpcap_pass_uses';
		$type = self::scanned_type( $ticket_type_id );
		if ( ! $type ) { return self::error( 'wpcap_pass_scope', __( 'Dieser Pass gilt nicht für dieses Event oder diese Ticketart.', 'pass-captain' ), 403 ); }
		$bound = self::schema_ready();
		$wpdb->query( 'START TRANSACTION' );
		// A480: the lock comes first. Every read below runs after it and sees all redemptions of this
		// pass committed before (a plain read here would fix the transaction's snapshot too early).
		$pass = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s FOR UPDATE', $wpdb->prefix . 'wpcap_member_passes', hash( 'sha256', $token ) ) );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT pass_use_id,pass_id FROM %i WHERE idempotency_key=%s', $uses, $key ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id ); }
		$now = Clock::mysql();
		if ( ! $pass || 'active' !== $pass->status || $pass->valid_from > $now || $pass->valid_until < $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_inactive', __( 'Pass ist ungültig oder außerhalb der Laufzeit.', 'pass-captain' ), array( 'status' => 403 ) ); }
		// Only event rules ("event" or "any" scope) that have not expired admit to an event.
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT r.* FROM %i r WHERE r.pass_id=%d AND r.scope IN ('event','any') AND (r.expires_at IS NULL OR r.expires_at>%s) AND (r.ticket_type_id=%d OR r.ticket_type_id=0) AND (r.event_id=%d OR r.event_id=0) ORDER BY r.ticket_type_id DESC,r.event_id DESC LIMIT 1 FOR UPDATE", $wpdb->prefix . 'wpcap_pass_rules', $pass->pass_id, $now, $ticket_type_id, (int) $type->event_id ) );
		if ( ! $rule ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_scope', __( 'Dieser Pass gilt nicht für dieses Event oder diese Ticketart.', 'pass-captain' ), array( 'status' => 403 ) ); }
		// A482: the entry belongs to a real, scheduled occurrence that takes place today.
		$ts = Checkin::now();
		if ( 'scheduled' !== $type->occurrence_status || 'cancelled' === $type->event_status || in_array( (string) $type->type_status, array( 'archived', 'deleted' ), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pass_occurrence', __( 'Dieser Termin findet nicht statt; der Pass kann dafür nicht eingelöst werden.', 'pass-captain' ), 409 );
		}
		$found = array( 'occurrence_id' => (int) $type->occurrence_id, 'event_id' => (int) $type->event_id, 'starts_at' => $type->starts_at, 'ends_at' => $type->ends_at, 'timezone' => $type->timezone );
		if ( ! self::on_occurrence_day( $type, $ts ) || '' !== Admission\Engine::default_window_block( $found, $ts ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'wpcap_pass_not_today', __( 'Der Pass kann nur am Tag des Termins und innerhalb der Einlasszeit eingelöst werden.', 'pass-captain' ), 409 );
		}
		$day = Admission\Engine::service_date( $ts, $type->timezone, $found );
		$today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pass_id=%d AND rule_id=%d AND service_date=%s', $uses, $pass->pass_id, $rule->rule_id, $day ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pass_id=%d AND rule_id=%d', $uses, $pass->pass_id, $rule->rule_id ) );
		if ( (int) $rule->uses_per_day > 0 && $today >= (int) $rule->uses_per_day || (int) $rule->uses_total > 0 && $total >= (int) $rule->uses_total ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_quota', __( 'Das Nutzungskontingent dieses Passes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$row = array( 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'service_date' => $day, 'user_id' => get_current_user_id(), 'idempotency_key' => $key, 'created_at' => $now );
		if ( $bound ) {
			// Member entries of all passes for this occurrence are serialised on the occurrence row.
			$wpdb->get_var( $wpdb->prepare( 'SELECT occurrence_id FROM %i WHERE occurrence_id=%d FOR UPDATE', $wpdb->prefix . 'wpcap_occurrences', (int) $type->occurrence_id ) );
			$members = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE occurrence_id=%d', $uses, (int) $type->occurrence_id ) );
			/**
			 * Optional quota of member-pass entries per occurrence (0 = no own quota). Members also never
			 * exceed the remaining capacity of the scanned ticket type / occurrence.
			 */
			$quota = max( 0, (int) apply_filters( 'wpcap_member_pass_occurrence_quota', 0, (int) $type->occurrence_id, (int) $ticket_type_id ) );
			$remaining = Orders::availability( $ticket_type_id );
			if ( ( $quota && $members >= $quota ) || ( null !== $remaining && (int) $remaining - $members <= 0 ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'wpcap_pass_occurrence_full', __( 'Für diesen Termin sind keine Plätze für Mitgliedspässe mehr frei.', 'pass-captain' ), 409 );
			}
			$row['occurrence_id'] = (int) $type->occurrence_id;
			$row['ticket_type_id'] = $ticket_type_id;
		}
		$wpdb->insert( $uses, $row );
		$use_id = (int) $wpdb->insert_id;
		if ( ! $use_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_redeem_failed', __( 'Pass konnte nicht eingelöst werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		Audit::record( 'member_pass_redeemed', 'member_pass', $pass->pass_id, array( 'ticket_type_id' => $ticket_type_id, 'occurrence_id' => (int) $type->occurrence_id, 'pass_use_id' => $use_id ) );
		/** A member pass was redeemed for an occurrence (after COMMIT). */
		do_action( 'wpcap_member_pass_redeemed', (int) $pass->pass_id, $use_id, (int) $type->occurrence_id, $ticket_type_id );
		return array( 'status' => 'redeemed', 'pass_id' => (int) $pass->pass_id, 'occurrence_id' => (int) $type->occurrence_id );
	}

	/** Member entries per occurrence (admission statistics), occurrence id => count. */
	public static function entries_by_occurrence( array $occurrence_ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $occurrence_ids ) );
		if ( ! $ids || ! self::schema_ready() ) { return array(); }
		$out = array();
		$ids = array_values( $ids );
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT occurrence_id,COUNT(*) AS n FROM %i WHERE occurrence_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') GROUP BY occurrence_id', array_merge( array( $wpdb->prefix . 'wpcap_pass_uses' ), $ids ) ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- replacements passed as one array.
			$out[ (int) $row->occurrence_id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Uses a pass for a service resource. Only rules with scope "service" or "any" apply (an event
	 * entry pass cannot be used here unless configured with scope "any"). Slot-free rules record the
	 * use directly ($slot_id must be 0); slot-bound rules need a slot of the resource and book it
	 * (confirmed, capacity and collision checked) in the same call. Idempotent per key.
	 *
	 * @return array|\WP_Error status (redeemed|already_redeemed), pass_id, rule_id, booking_id (slot-bound).
	 */
	public static function redeem_service( $token, $resource_id, $idempotency_key, $slot_id = 0, $quantity = 1 ) {
		global $wpdb;
		$token = Security\Input::hex( $token );
		$resource_id = absint( $resource_id );
		$slot_id = absint( $slot_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( 64 !== strlen( $token ) || ! $resource_id || '' === $idempotency_key ) { return new \WP_Error( 'wpcap_pass_redeem_invalid', __( 'Passcode oder Ressource ungültig.', 'pass-captain' ) ); }
		$resource = ServiceResources::resource( $resource_id );
		if ( ! $resource || 'active' !== $resource->status ) { return new \WP_Error( 'wpcap_pass_scope', __( 'Diese Ressource ist nicht verfügbar.', 'pass-captain' ), array( 'status' => 404 ) ); }
		if ( $slot_id ) {
			$slot = ServiceResources::slot( $slot_id );
			if ( ! $slot || (int) $slot->resource_id !== $resource_id ) { return new \WP_Error( 'wpcap_pass_slot', __( 'Das Zeitfenster gehört nicht zu dieser Ressource.', 'pass-captain' ), array( 'status' => 400 ) ); }
		}
		$key = hash( 'sha256', $token . '|service|' . $idempotency_key );
		$uses = $wpdb->prefix . 'wpcap_pass_uses';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT pass_use_id,pass_id,rule_id,booking_id FROM %i WHERE idempotency_key=%s', $uses, $key ) );
		if ( $existing ) { return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id, 'rule_id' => (int) $existing->rule_id, 'booking_id' => (int) $existing->booking_id ); }
		$wpdb->query( 'START TRANSACTION' );
		$pass = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash=%s FOR UPDATE', $wpdb->prefix . 'wpcap_member_passes', hash( 'sha256', $token ) ) );
		$now = Clock::mysql();
		if ( ! $pass || 'active' !== $pass->status || $pass->valid_from > $now || $pass->valid_until < $now ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_inactive', __( 'Pass ist ungültig oder außerhalb der Laufzeit.', 'pass-captain' ), array( 'status' => 403 ) ); }
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT pass_use_id,pass_id,rule_id,booking_id FROM %i WHERE idempotency_key=%s', $uses, $key ) );
		if ( $existing ) { $wpdb->query( 'COMMIT' ); return array( 'status' => 'already_redeemed', 'pass_id' => (int) $existing->pass_id, 'rule_id' => (int) $existing->rule_id, 'booking_id' => (int) $existing->booking_id ); }
		$mode = $slot_id ? 'slot_bound' : 'slot_free';
		$rule = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE pass_id=%d AND scope IN ('service','any') AND slot_mode=%s AND (resource_id=%d OR resource_id=0) AND (expires_at IS NULL OR expires_at>%s) ORDER BY resource_id DESC,rule_id LIMIT 1 FOR UPDATE", $wpdb->prefix . 'wpcap_pass_rules', (int) $pass->pass_id, $mode, $resource_id, $now ) );
		if ( ! $rule ) {
			$wpdb->query( 'ROLLBACK' );
			$other = $slot_id ? __( 'Dieser Pass gilt nur ohne Terminbuchung.', 'pass-captain' ) : __( 'Dieser Pass erfordert die Buchung eines Zeitfensters.', 'pass-captain' );
			$any = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE pass_id=%d AND scope IN ('service','any') AND (resource_id=%d OR resource_id=0) AND (expires_at IS NULL OR expires_at>%s)", $wpdb->prefix . 'wpcap_pass_rules', (int) $pass->pass_id, $resource_id, $now ) );
			return new \WP_Error( 'wpcap_pass_scope', $any ? $other : __( 'Dieser Pass gilt nicht für diese Ressource.', 'pass-captain' ), array( 'status' => 403 ) );
		}
		$tz = ServiceResources::timezone_of( $resource );
		$day = wp_date( 'Y-m-d', $slot_id ? strtotime( $slot->starts_at . ' UTC' ) : Clock::now(), new \DateTimeZone( $tz ) );
		$today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pass_id=%d AND rule_id=%d AND service_date=%s', $uses, $pass->pass_id, $rule->rule_id, $day ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pass_id=%d AND rule_id=%d', $uses, $pass->pass_id, $rule->rule_id ) );
		if ( ( (int) $rule->uses_per_day > 0 && $today >= (int) $rule->uses_per_day ) || ( (int) $rule->uses_total > 0 && $total >= (int) $rule->uses_total ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_quota', __( 'Das Nutzungskontingent dieses Passes ist ausgeschöpft.', 'pass-captain' ), array( 'status' => 409 ) ); }
		$wpdb->insert( $uses, array( 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'service_date' => $day, 'user_id' => get_current_user_id(), 'idempotency_key' => $key, 'resource_id' => $resource_id, 'slot_id' => $slot_id, 'created_at' => $now ), array( '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s' ) );
		$use_id = (int) $wpdb->insert_id;
		if ( ! $use_id ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'wpcap_pass_redeem_failed', __( 'Pass konnte nicht eingelöst werden.', 'pass-captain' ), array( 'status' => 500 ) ); }
		$wpdb->query( 'COMMIT' );
		$booking_id = 0;
		if ( $slot_id ) {
			// Book the place; a full slot or a rule violation undoes the use.
			$booking = ServiceBookings::reserve( $slot_id, (string) $pass->member_email, $quantity, 0, 15, 'pass-' . $key, array( 'actor' => 'customer', 'skip_rate_limit' => true, 'status' => 'confirmed', 'pass_use_id' => $use_id ) );
			if ( is_wp_error( $booking ) ) {
				$wpdb->delete( $uses, array( 'pass_use_id' => $use_id ), array( '%d' ) );
				return $booking;
			}
			$booking_id = (int) $booking['booking_id'];
			$wpdb->update( $uses, array( 'booking_id' => $booking_id ), array( 'pass_use_id' => $use_id ), array( '%d' ), array( '%d' ) );
		}
		Audit::record( 'member_pass_service_redeemed', 'member_pass', (int) $pass->pass_id, array( 'resource_id' => $resource_id, 'slot_id' => $slot_id, 'rule_id' => (int) $rule->rule_id, 'pass_use_id' => $use_id, 'booking_id' => $booking_id ) );
		return array( 'status' => 'redeemed', 'pass_id' => (int) $pass->pass_id, 'rule_id' => (int) $rule->rule_id, 'booking_id' => $booking_id );
	}

	/** Gives a use back (slot-bound booking cancelled in time). */
	public static function release_use( $pass_use_id ) {
		global $wpdb;
		$use = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pass_use_id=%d', $wpdb->prefix . 'wpcap_pass_uses', absint( $pass_use_id ) ) );
		if ( ! $use ) { return false; }
		$wpdb->delete( $wpdb->prefix . 'wpcap_pass_uses', array( 'pass_use_id' => (int) $use->pass_use_id ), array( '%d' ) );
		Audit::record( 'member_pass_use_released', 'member_pass', (int) $use->pass_id, array( 'pass_use_id' => (int) $use->pass_use_id, 'booking_id' => (int) $use->booking_id ) );
		return true;
	}

	/** Pass row without its token hash (the code is never read back). */
	public static function get( $pass_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pass_id=%d', $wpdb->prefix . 'wpcap_member_passes', absint( $pass_id ) ) );
		if ( $row ) { unset( $row->token_hash ); }
		return $row;
	}

	/** Rules of a pass with their use counts. */
	public static function rules( $pass_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT r.*,(SELECT COUNT(*) FROM %i u WHERE u.rule_id=r.rule_id) AS used FROM %i r WHERE r.pass_id=%d ORDER BY r.rule_id', $wpdb->prefix . 'wpcap_pass_uses', $wpdb->prefix . 'wpcap_pass_rules', absint( $pass_id ) ) );
	}

	/**
	 * Changes the validity period of a pass (UTC datetimes).
	 *
	 * @return true|\WP_Error
	 */
	public static function update_validity( $pass_id, $valid_from, $valid_until ) {
		$valid_from = self::normalize_validity( $valid_from, false );
		$valid_until = self::normalize_validity( $valid_until, true );
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return new \WP_Error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$valid_from = sanitize_text_field( (string) $valid_from );
		$valid_until = sanitize_text_field( (string) $valid_until );
		if ( ! strtotime( $valid_from . ' UTC' ) || strtotime( $valid_until . ' UTC' ) <= strtotime( $valid_from . ' UTC' ) ) { return new \WP_Error( 'wpcap_pass_invalid', __( 'Passdaten ungültig.', 'pass-captain' ) ); }
		$wpdb->update( $wpdb->prefix . 'wpcap_member_passes', array( 'valid_from' => $valid_from, 'valid_until' => $valid_until ), array( 'pass_id' => (int) $pass->pass_id ), array( '%s', '%s' ), array( '%d' ) );
		Audit::record( 'member_pass_updated', 'member_pass', (int) $pass->pass_id, array( 'valid_from' => $valid_from, 'valid_until' => $valid_until, 'previous_from' => $pass->valid_from, 'previous_until' => $pass->valid_until ) );
		return true;
	}

	/**
	 * Adds a rule (rule_id 0) or changes the quotas of an existing rule. The scope (event, ticket
	 * type) of an existing rule stays fixed so recorded uses keep their meaning.
	 *
	 * @return int|\WP_Error Rule id.
	 */
	public static function save_rule( $pass_id, $rule_id, array $rule ) {
		global $wpdb;
		$pass = self::get( $pass_id );
		if ( ! $pass ) { return new \WP_Error( 'wpcap_pass_not_found', __( 'Pass nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
		$values = array( 'uses_total' => min( 65535, absint( $rule['uses_total'] ?? 0 ) ), 'uses_per_day' => min( 65535, absint( $rule['uses_per_day'] ?? 1 ) ) ); // A524: 0 = unlimited per day.
		$table = $wpdb->prefix . 'wpcap_pass_rules';
		if ( $rule_id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT rule_id FROM %i WHERE rule_id=%d AND pass_id=%d', $table, absint( $rule_id ), (int) $pass->pass_id ) );
			if ( ! $existing ) { return new \WP_Error( 'wpcap_pass_rule_missing', __( 'Passregel nicht gefunden.', 'pass-captain' ), array( 'status' => 404 ) ); }
			$wpdb->update( $table, $values, array( 'rule_id' => (int) $existing->rule_id ), array( '%d', '%d' ), array( '%d' ) );
			Audit::record( 'member_pass_rule_updated', 'member_pass', (int) $pass->pass_id, array( 'rule_id' => (int) $existing->rule_id ) + $values );
			return (int) $existing->rule_id;
		}
		$scope = self::rule_scope( $rule );
		$wpdb->insert( $table, array( 'pass_id' => (int) $pass->pass_id, 'event_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => 'service' === $scope['scope'] ? 0 : absint( $rule['ticket_type_id'] ?? 0 ) ) + $values + $scope, array( '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' ) );
		if ( ! $wpdb->insert_id ) { return new \WP_Error( 'wpcap_pass_rule_failed', __( 'Passregeln konnten nicht gespeichert werden.', 'pass-captain' ) ); }
		$new_id = (int) $wpdb->insert_id;
		Audit::record( 'member_pass_rule_added', 'member_pass', (int) $pass->pass_id, array( 'rule_id' => $new_id, 'event_id' => absint( $rule['event_id'] ?? 0 ), 'ticket_type_id' => absint( $rule['ticket_type_id'] ?? 0 ) ) + $values + $scope );
		return $new_id;
	}
}
