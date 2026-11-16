<?php
/**
 * Minimal stand-in for the public Form Captain contract v1 (functions only), for the Form Captain
 * adapter test. Behaviour follows docs/spec/suite-kontext.md of Form Captain: purposes are
 * registered once, a context is only signed for a form that accepts the purpose (settings or filter
 * form_captain_accepted_context_purposes_v1), for an allowed ref type and when issue_check agrees.
 * The real signature/verification is covered by tests/form-captain/ against Form Captain itself.
 */

// phpcs:ignoreFile

$GLOBALS['fcfake'] = array(
	'version'    => '1.1.0',
	'purposes'   => array(),
	'registered' => array(),
	'forms'      => array(),
	'renders'    => array(),
	'signs'      => array(),
	'actions'    => array(),
);

function form_captain_contract_version() { return $GLOBALS['fcfake']['version']; }

function form_captain_register_context_purpose_v1( $purpose, array $definition ) {
	$GLOBALS['fcfake']['registered'][] = $purpose;
	if ( isset( $GLOBALS['fcfake']['purposes'][ $purpose ] ) ) {
		return new WP_Error( 'context_purpose_duplicate', 'duplicate' );
	}
	$GLOBALS['fcfake']['purposes'][ $purpose ] = $definition;
	return true;
}

function form_captain_get_form_v1( $form_id ) {
	foreach ( $GLOBALS['fcfake']['forms'] as $form ) {
		if ( $form['id'] === $form_id || $form['public_id'] === $form_id ) {
			return $form;
		}
	}
	return new WP_Error( 'form_not_found', 'not found' );
}

function fcfake_accepts( $form_id, $purpose ) {
	$settings = array();
	foreach ( $GLOBALS['fcfake']['forms'] as $form ) {
		if ( $form['id'] === $form_id ) { $settings = $form['accepted_context_purposes'] ?? array(); }
	}
	return in_array( $purpose, (array) apply_filters( 'form_captain_accepted_context_purposes_v1', $settings, $form_id ), true );
}

function form_captain_sign_context_v1( $purpose, $form_id, $ref_type, $ref_id, $ttl = 0 ) {
	$GLOBALS['fcfake']['signs'][] = compact( 'purpose', 'form_id', 'ref_type', 'ref_id', 'ttl' );
	$def = $GLOBALS['fcfake']['purposes'][ $purpose ] ?? null;
	if ( ! $def ) { return array( 'success' => false, 'code' => 'context_purpose_unknown' ); }
	if ( ! fcfake_accepts( $form_id, $purpose ) ) { return array( 'success' => false, 'code' => 'context_purpose_not_allowed' ); }
	if ( ! in_array( $ref_type, $def['ref_types'], true ) ) { return array( 'success' => false, 'code' => 'context_ref_invalid' ); }
	if ( isset( $def['issue_check'] ) && ! call_user_func( $def['issue_check'], $ref_type, $ref_id, $form_id ) ) { return array( 'success' => false, 'code' => 'context_ref_rejected' ); }
	return array( 'success' => true, 'code' => 'ok', 'context' => 'fakectx.' . base64_encode( wp_json_encode( compact( 'purpose', 'form_id', 'ref_type', 'ref_id' ) ) ) );
}

function form_captain_get_render_v1( $form_id, array $args = array() ) {
	$GLOBALS['fcfake']['renders'][] = array( 'form_id' => $form_id, 'args' => $args );
	$form = form_captain_get_form_v1( $form_id );
	if ( is_wp_error( $form ) || 'published' !== $form['status'] ) { return ''; }
	return '<form class="fc-form" data-fc-form="' . esc_attr( $form['public_id'] ) . '">' . ( isset( $args['context'] ) ? '<input type="hidden" name="_fc_ctx" value="' . esc_attr( $args['context'] ) . '">' : '' ) . '</form>';
}

function form_captain_register_action_v1( $key, array $definition, $callback ) {
	$GLOBALS['fcfake']['actions'][] = $key;
	return true;
}
