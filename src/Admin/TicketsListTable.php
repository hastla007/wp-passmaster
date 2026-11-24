<?php
/**
 * Ticket list as WP_List_Table (A411): status views, sortable ticket number and date, screen option
 * "Tickets pro Seite". No bulk action of the core selector: the rows hold their own action forms (so the
 * table is not inside a form) and the bulk check-in/out (A688) keeps its own confirmation form, which the
 * row checkboxes join through form="…". Search, masking and audit run in TicketsPage::render(), the cells
 * come from TicketsPage, so the plain fallback table shows the same content.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

defined( 'ABSPATH' ) || exit;

// Loaded only after ListTables::available() found the core class or its file.
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * @internal Not part of the public add-on API (A402): may change in any release.
 */
final class TicketsListTable extends \WP_List_Table {
	/** @var array List filters (TicketsPage::list_filters()). */
	private $filters = array();

	/** @var int Current page. */
	private $page = 1;

	/** @var bool Whether "Umbuchen" is offered (exchange module). */
	private $exchange = false;

	public function __construct( $args = array() ) {
		parent::__construct( array_merge( array( 'singular' => 'ticket', 'plural' => 'tickets', 'ajax' => false ), (array) $args ) );
	}

	/** Page size of the user's screen option (default TicketsPage::PER_PAGE_DEFAULT). */
	public function per_page() {
		return ListTables::clamp_per_page( $this->get_items_per_page( TicketsPage::PER_PAGE_OPTION, TicketsPage::PER_PAGE_DEFAULT ) );
	}

	/**
	 * Takes the page TicketsPage::render() searched (masked and audited there).
	 *
	 * @param array $filters  List filters.
	 * @param int   $page     Current page.
	 * @param array $data     TicketsPage::list_data() result.
	 * @param int   $per_page Page size.
	 * @param bool  $exchange Whether "Umbuchen" is offered.
	 */
	public function load( array $filters, $page, array $data, $per_page, $exchange ) {
		$this->filters = $filters;
		$this->page = max( 1, (int) $page );
		$this->exchange = (bool) $exchange;
		$this->items = $data['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
		$this->set_pagination_args( array( 'total_items' => (int) $data['total'], 'per_page' => (int) $per_page, 'total_pages' => (int) ceil( (int) $data['total'] / max( 1, (int) $per_page ) ) ) );
	}

	public function prepare_items() {
		// The data is handed over by load(); nothing is read here.
	}

	public function get_columns() {
		// Selection column only for check-in staff (bulk check-in/out, A688).
		$columns = current_user_can( 'wpcap_checkin' ) ? array( 'cb' => '<input type="checkbox">' ) : array();
		return $columns + TicketsPage::list_columns();
	}

	protected function get_sortable_columns() {
		return array(
			'ticket' => array( 'ticket', true ),
			'event'  => array( 'date', false ),
		);
	}

	protected function get_primary_column_name() {
		return 'ticket';
	}

	protected function get_bulk_actions() {
		return array();
	}

	protected function get_views() {
		return ListTables::view_links( TicketsPage::list_views( $this->filters ) );
	}

	/**
	 * The column headers read the sort from the query string. The sort select and the return after an
	 * action use the order keys (newest, oldest, date), so the headers get the matching column form.
	 */
	public function display() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters, restored below.
		$saved = $_GET; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restored unchanged.
		list( $orderby, $order ) = TicketsPage::header_sort( $this->filters );
		unset( $_GET['orderby'], $_GET['order'] );
		if ( '' !== $orderby ) {
			$_GET['orderby'] = $orderby;
			$_GET['order'] = $order;
		}
		try {
			parent::display();
		} finally {
			$_GET = $saved;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Navigation above/below the table without the bulk selector and its nonce (no bulk action here). */
	protected function display_tablenav( $which ) {
		if ( 'bottom' === $which && ! $this->has_items() ) { return; }
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		ob_start();
		$this->pagination( $which );
		echo ListTables::link_paging_input( (string) ob_get_clean(), TicketsPage::PAGING_FORM ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core pagination markup, only a form attribute added.
		echo '<br class="clear"></div>';
	}

	/** Caption of the table (accessible name); the sorted column carries aria-sort. */
	public function print_table_description() {
		echo '<caption class="screen-reader-text">' . esc_html( TicketsPage::list_caption() ) . '</caption>';
	}

	public function no_items() {
		esc_html_e( 'Keine Tickets gefunden.', 'pass-captain' );
	}

	/**
	 * @param object $item Ticket row.
	 */
	protected function column_cb( $item ) {
		return TicketsPage::checkbox_cell( $item );
	}

	/**
	 * @param object $item        Ticket row.
	 * @param string $column_name Column key.
	 */
	protected function column_default( $item, $column_name ) {
		return TicketsPage::list_cell( (string) $column_name, $item, $this->filters, $this->page, $this->exchange );
	}
}
