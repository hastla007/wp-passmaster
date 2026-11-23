<?php
/**
 * Optional "Workflow Captain" box on the system status page: without Workflow Captain one neutral sentence, with it
 * adapter state, contract version, counts, last correlation ID and links, every value escaped.
 *
 * @package PassCaptain
 */

// phpcs:ignoreFile

use PassCaptain\Integrations\WorkflowCaptain\Status as WorkflowCaptainStatus;

if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); } }

echo "Workflow Captain status box\n";
check( 'Workflow Captain status: missing function is reported as not active, no table, no links', ! function_exists( 'workflow_captain_status_for' ) && null === WorkflowCaptainStatus::data() && false !== strpos( WorkflowCaptainStatus::html(), 'Workflow Captain ist nicht aktiv.' ) && false === strpos( WorkflowCaptainStatus::html(), '<table' ) && false === strpos( WorkflowCaptainStatus::html(), 'href=' ) );
check( 'Workflow Captain status: the not-active sentence uses no licence or sales wording', 0 === preg_match( '/lizenz|licen[cs]e|pro\b|kaufen|upgrade|premium/i', WorkflowCaptainStatus::html() ) );

$wfc_asked = array();
$wfc_base  = 'https://example.test/wp-admin/admin.php';
$wfc_full  = static function ( $product ) use ( &$wfc_asked, $wfc_base ) {
	$wfc_asked[] = $product;
	return array(
		'version'             => 1,
		'product'             => $product,
		'adapter'             => 'registered',
		'reason'              => null,
		'contract_version'    => '1.2.0',
		'active_workflows'    => 3,
		'events'              => array( 'done' => 12, 'dead_letter' => 1 ),
		'last_correlation_id' => '7d3f-<script>alert(1)</script>',
		'links'               => array(
			'workflows' => $wfc_base . '?page=workflow-captain-workflows',
			'runs'      => $wfc_base . '?page=workflow-captain-runs',
			'events'    => $wfc_base . '?page=workflow-captain-events&product=pass-captain',
			'providers' => 'javascript:alert(1)',
		),
	);
};
$wfc_html = WorkflowCaptainStatus::html( $wfc_full );
check( 'Workflow Captain status: asks for the product pass-captain', array( 'pass-captain' ) === $wfc_asked );
check( 'Workflow Captain status: adapter, contract version, active workflows and events per state are shown', false !== strpos( $wfc_html, 'angemeldet' ) && false !== strpos( $wfc_html, '<code>1.2.0</code>' ) && false !== strpos( $wfc_html, '<td>3</td>' ) && false !== strpos( $wfc_html, 'erledigt: 12' ) && false !== strpos( $wfc_html, 'fehlgeschlagen: 1' ) );
check( 'Workflow Captain status: each state links to the events page with its state filter', false !== strpos( $wfc_html, 'state=dead_letter' ) && false !== strpos( $wfc_html, 'state=done' ) );
check( 'Workflow Captain status: the correlation ID is escaped and links to the events of Pass Captain', false === strpos( $wfc_html, '<script>' ) && false !== strpos( $wfc_html, '&lt;script&gt;' ) && false !== strpos( $wfc_html, 'product=pass-captain">' . '<code>7d3f-' ) );
check( 'Workflow Captain status: links to workflows, runs and events; a non-http link is dropped', false !== strpos( $wfc_html, 'page=workflow-captain-workflows' ) && false !== strpos( $wfc_html, 'page=workflow-captain-runs' ) && false === strpos( $wfc_html, 'javascript:' ) && false === strpos( $wfc_html, '>Anbieter<' ) );

$wfc_refused = WorkflowCaptainStatus::html( static function () {
	return array( 'adapter' => 'refused', 'reason' => 'contract <b>2.0</b> is not supported', 'contract_version' => null, 'active_workflows' => 0, 'events' => array(), 'last_correlation_id' => null, 'links' => array() );
} );
check( 'Workflow Captain status: a refused adapter shows its escaped reason, no events and no links', false !== strpos( $wfc_refused, 'abgelehnt: contract &lt;b&gt;2.0&lt;/b&gt; is not supported' ) && false !== strpos( $wfc_refused, 'noch keine' ) && false === strpos( $wfc_refused, 'href=' ) );
$wfc_missing = WorkflowCaptainStatus::html( static function () { return array( 'adapter' => 'missing', 'links' => array() ); } );
check( 'Workflow Captain status: a missing adapter says so', false !== strpos( $wfc_missing, 'nicht angemeldet' ) );
check( 'Workflow Captain status: a status function that returns null (not loaded yet) is reported, not shown as a table', false !== strpos( WorkflowCaptainStatus::html( static function () { return null; } ), 'meldet aber noch keinen Status' ) );

// With the global function defined, the default path reads it (declared conditionally so it exists only from here on).
if ( ! function_exists( 'workflow_captain_status_for' ) ) {
	function workflow_captain_status_for( string $product ): ?array {
		return array( 'product' => $product, 'adapter' => 'registered', 'contract_version' => '1.2.0', 'active_workflows' => 1, 'events' => array(), 'last_correlation_id' => null, 'links' => array() );
	}
}
check( 'Workflow Captain status: with workflow_captain_status_for() the box reads it by default', WorkflowCaptainStatus::available() && 'pass-captain' === ( WorkflowCaptainStatus::data()['product'] ?? '' ) && false !== strpos( WorkflowCaptainStatus::html(), '<table' ) );
check( 'Workflow Captain status: the system status page renders the box', false !== strpos( (string) file_get_contents( WPCAP_PATH . 'src/Admin/StatusPage.php' ), 'WorkflowCaptain\\Status::render()' ) );
