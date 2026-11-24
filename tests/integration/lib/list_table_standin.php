<?php
// phpcs:ignoreFile
/**
 * A411: small stand-in of WP_List_Table for the harness (no wp-admin here). It calls the same hooks of a
 * subclass as the core class (columns, sortable columns, primary column, views, bulk actions, column_cb,
 * column_{name}/column_default, display_tablenav, pagination, print_table_description) and marks the
 * sorted column from $_GET like print_column_headers(). Output is reduced to what the tests read.
 */

if ( ! class_exists( 'WP_List_Table', false ) ) {
	class WP_List_Table {
		public $items = array();
		protected $_args = array();
		protected $_column_headers = array();
		protected $_pagination_args = array();
		public function __construct( $args = array() ) { $this->_args = $args; }
		protected function get_items_per_page( $option, $default = 20 ) { $v = (int) ( $GLOBALS['z3l_user_options'][ $option ] ?? 0 ); return $v > 0 ? $v : $default; }
		public function get_pagenum() { return max( 1, absint( $_REQUEST['paged'] ?? ( $_GET['paged'] ?? 1 ) ) ); }
		protected function set_pagination_args( $args ) { $this->_pagination_args = $args; }
		public function get_pagination_arg( $key ) { return $this->_pagination_args[ $key ] ?? 0; }
		public function has_items() { return ! empty( $this->items ); }
		public function get_columns() { return array(); }
		protected function get_views() { return array(); }
		protected function get_bulk_actions() { return array(); }
		protected function get_sortable_columns() { return array(); }
		protected function get_primary_column_name() { return ''; }
		protected function column_default( $item, $column_name ) { return ''; }
		protected function column_cb( $item ) { return ''; }
		protected function extra_tablenav( $which ) {}
		protected function get_table_classes() { return array( 'widefat', 'striped', $this->_args['plural'] ?? '' ); }
		public function print_table_description() {}
		public function views() { echo '<ul class="subsubsub">' . implode( ' | ', $this->get_views() ) . '</ul>'; }
		public function no_items() { echo 'No items'; }
		protected function bulk_actions( $which = '' ) {
			if ( 'top' !== $which ) { return; }
			echo '<select name="action"><option value="-1">-</option>';
			foreach ( $this->get_bulk_actions() as $k => $l ) { echo '<option value="' . $k . '">' . $l . '</option>'; }
			echo '</select>';
		}
		protected function pagination( $which ) {
			if ( 'bottom' === $which ) {
				echo '<span class="pages">' . (int) ( $this->_pagination_args['total_items'] ?? 0 ) . '/' . (int) ( $this->_pagination_args['per_page'] ?? 0 ) . '</span>';
			} elseif ( (int) ( $this->_pagination_args['total_pages'] ?? 0 ) > 1 ) {
				echo "<input class='current-page' id='current-page-selector' type='text' name='paged' value='" . $this->get_pagenum() . "' size='1' />";
			}
		}
		protected function display_tablenav( $which ) {
			if ( 'top' === $which ) { echo '<input type="hidden" name="_wpnonce" value="bulk-' . $this->_args['plural'] . '">'; }
			$this->bulk_actions( $which );
			$this->extra_tablenav( $which );
			$this->pagination( $which );
		}
		public function print_column_headers() {
			list( $columns, , $sortable, $primary ) = $this->_column_headers + array( array(), array(), array(), '' );
			$current = isset( $_GET['orderby'] ) ? (string) $_GET['orderby'] : '';
			$order = isset( $_GET['order'] ) && 'desc' === $_GET['order'] ? 'desc' : 'asc';
			foreach ( $columns as $k => $l ) {
				if ( 'cb' === $k ) { echo '<td class="check-column"><input id="cb-select-all-1" type="checkbox"></td>'; continue; }
				$aria = '';
				$class = 'column-' . $k . ( $k === $primary ? ' column-primary' : '' );
				if ( isset( $sortable[ $k ] ) ) {
					$key = $sortable[ $k ][0];
					if ( $current === $key ) {
						$class .= ' sorted ' . $order;
						$aria = ' aria-sort="' . ( 'asc' === $order ? 'ascending' : 'descending' ) . '"';
						$next = 'asc' === $order ? 'desc' : 'asc';
					} else {
						$class .= ' sortable';
						$next = ! empty( $sortable[ $k ][1] ) ? 'desc' : 'asc';
					}
					$l = '<a href="?orderby=' . $key . '&amp;order=' . $next . '">' . $l . '</a>';
				}
				echo '<th scope="col" class="' . $class . '"' . $aria . '>' . $l . '</th>';
			}
		}
		public function display() {
			$this->display_tablenav( 'top' );
			echo '<table class="wp-list-table ' . implode( ' ', $this->get_table_classes() ) . '">';
			$this->print_table_description();
			echo '<thead><tr>';
			$this->print_column_headers();
			echo '</tr></thead><tbody>';
			list( $columns, , , $primary ) = $this->_column_headers + array( array(), array(), array(), '' );
			if ( ! $this->items ) { echo '<tr class="no-items"><td>'; $this->no_items(); echo '</td></tr>'; }
			foreach ( $this->items as $item ) {
				echo '<tr>';
				foreach ( array_keys( $columns ) as $k ) {
					if ( 'cb' === $k ) { echo '<td class="check-column">' . $this->column_cb( $item ) . '</td>'; continue; }
					$cell = method_exists( $this, 'column_' . $k ) ? $this->{'column_' . $k}( $item ) : $this->column_default( $item, $k );
					echo $k === $primary ? '<th scope="row" class="column-' . $k . ' column-primary">' . $cell . '</th>' : '<td class="column-' . $k . '">' . $cell . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			$this->display_tablenav( 'bottom' );
		}
	}
}
