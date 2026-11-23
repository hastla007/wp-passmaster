<?php
/**
 * Order list as WP_List_Table (A411): bulk action "Zahlung bestätigen" (bank transfer), sortable date
 * and total, views by state, screen option "Einträge pro Seite", empty state with "Filter zurücksetzen".
 * Cells, filters, sorting and the bulk action live in OrdersPage, so the plain fallback table (when
 * WP_List_Table cannot be loaded) shows the same content.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

defined( 'ABSPATH' ) || exit;

// Loaded only after OrdersPage::list_table_available() found the core class or its file.
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class OrdersListTable extends \WP_List_Table {
	/** @var array<int,bool> Orders whose buyer data is masked on this page (decided once, PERF-15). */
	private $masked = array();

	/** @var array Filters of the list (OrdersPage::list_filters()). */
	private $filters = array();

	public function __construct( $args = array() ) {
		parent::__construct( array_merge( array( 'singular' => 'order', 'plural' => 'orders', 'ajax' => false ), (array) $args ) );
	}

	public function get_columns() {
		$columns = array( 'cb' => '<input type="checkbox">' );
		return $columns + OrdersPage::list_columns();
	}

	protected function get_sortable_columns() {
		return array(
			'date'  => array( 'date', true ),
			'total' => array( 'total', false ),
		);
	}

	protected function get_primary_column_name() {
		return 'order';
	}

	protected function get_bulk_actions() {
		return current_user_can( OrdersPage::CAP ) ? array( OrdersPage::BULK_CONFIRM => __( 'Zahlung bestätigen', 'pass-captain' ) ) : array();
	}

	protected function get_views() {
		$views = array();
		foreach ( OrdersPage::list_views( $this->filters ) as $key => $view ) {
			$views[ $key ] = '<a href="' . esc_url( $view['url'] ) . '"' . ( $view['current'] ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $view['label'] ) . '</a>';
		}
		return $views;
	}

	public function prepare_items() {
		$this->filters = OrdersPage::list_filters();
		$per_page = $this->get_items_per_page( OrdersPage::PER_PAGE_OPTION, OrdersPage::PER_PAGE_DEFAULT );
		$data = OrdersPage::list_data( $this->filters, $this->get_pagenum(), $per_page, OrdersPage::list_sort() );
		$this->items = $data['rows'];
		$this->masked = $data['masked'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
		$this->set_pagination_args( array( 'total_items' => (int) $data['total'], 'per_page' => $per_page, 'total_pages' => (int) ceil( (int) $data['total'] / max( 1, $per_page ) ) ) );
	}

	public function no_items() {
		echo OrdersPage::empty_state( $this->filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in empty_state().
	}

	protected function column_cb( $item ) {
		$ref = \PassCaptain\Frontend\Format::order_reference( $item->public_id );
		/* translators: %s: order number */
		return '<label class="screen-reader-text" for="wpcap-order-' . (int) $item->order_id . '">' . esc_html( sprintf( __( 'Bestellung %s auswählen', 'pass-captain' ), $ref ) ) . '</label><input type="checkbox" id="wpcap-order-' . (int) $item->order_id . '" name="order[]" value="' . (int) $item->order_id . '">';
	}

	protected function column_default( $item, $column_name ) {
		return OrdersPage::list_cell( (string) $column_name, $item, ! empty( $this->masked[ (int) $item->order_id ] ) );
	}
}
