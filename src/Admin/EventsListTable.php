<?php
/**
 * Event list as WP_List_Table (A411): status views with counts, sortable name and status, screen option
 * "Events pro Seite" and the bulk action "Als Entwurf duplizieren". Cells, state and the bulk action
 * live in EventsPage, so the plain fallback table (when WP_List_Table cannot be loaded) shows the same
 * content.
 *
 * @package PassCaptain
 */

namespace PassCaptain\Admin;

defined( 'ABSPATH' ) || exit;

// Loaded only after ListTables::available() found the core class or its file.
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class EventsListTable extends \WP_List_Table {
	/** @var array List state (EventsPage::list_state()). */
	private $state = array();

	/** @var array<int,array> Sold/revenue figures per event of the page. */
	private $figures = array();

	public function __construct( $args = array() ) {
		parent::__construct( array_merge( array( 'singular' => 'event', 'plural' => 'events', 'ajax' => false ), (array) $args ) );
	}

	public function get_columns() {
		$columns = current_user_can( EventsPage::CAP ) ? array( 'cb' => '<input type="checkbox">' ) : array();
		return $columns + EventsPage::list_columns();
	}

	protected function get_sortable_columns() {
		return array(
			'event'  => array( 'name', false ),
			'status' => array( 'status', false ),
		);
	}

	protected function get_primary_column_name() {
		return 'event';
	}

	protected function get_bulk_actions() {
		return current_user_can( EventsPage::CAP ) ? array( EventsPage::BULK_DUPLICATE => __( 'Als Entwurf duplizieren', 'pass-captain' ) ) : array();
	}

	protected function get_views() {
		return ListTables::view_links( EventsPage::list_views( $this->state ) );
	}

	public function prepare_items() {
		$this->state = EventsPage::list_state();
		$per_page = $this->get_items_per_page( EventsPage::PER_PAGE_OPTION, EventsPage::PER_PAGE_DEFAULT );
		$data = EventsPage::list_data( $this->state, Forms::current_page(), $per_page );
		$this->items = $data['rows'];
		$this->figures = $data['figures'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
		$this->set_pagination_args( array( 'total_items' => (int) $data['total'], 'per_page' => $per_page, 'total_pages' => (int) ceil( (int) $data['total'] / max( 1, $per_page ) ) ) );
	}

	/** Core classes plus the former class of the event table. */
	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), array( 'wpcap-events-list' ) );
	}

	/** Caption of the table (accessible name); the sorted column carries aria-sort. */
	public function print_table_description() {
		echo '<caption class="screen-reader-text">' . esc_html( EventsPage::list_caption() ) . '</caption>';
	}

	public function no_items() {
		echo EventsPage::empty_state( $this->state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in empty_state().
	}

	/**
	 * @param object $item Event row.
	 */
	protected function column_cb( $item ) {
		$id = 'wpcap-event-' . (int) $item->event_id;
		/* translators: %s: event name */
		return '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Event %s auswählen', 'pass-captain' ), $item->name ) ) . '</label><input type="checkbox" id="' . esc_attr( $id ) . '" name="event[]" value="' . (int) $item->event_id . '">';
	}

	/**
	 * @param object $item        Event row.
	 * @param string $column_name Column key.
	 */
	protected function column_default( $item, $column_name ) {
		return EventsPage::list_cell( (string) $column_name, $item, $this->figures[ (int) $item->event_id ] ?? array( 'sold' => 0, 'capacity' => 0, 'revenue' => array() ) );
	}
}
