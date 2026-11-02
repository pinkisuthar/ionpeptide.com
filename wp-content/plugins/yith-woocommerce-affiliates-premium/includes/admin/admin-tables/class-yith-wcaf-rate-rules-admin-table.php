<?php
/**
 * Rate Rules Table class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Rate_Rules_Admin_Table' ) ) {
	/**
	 * WooCommerce Rate Rules Table
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Rate_Rules_Admin_Table extends YITH_WCAF_Abstract_Admin_Table {
		/**
		 * Class constructor method
		 *
		 * @param array $args Arguments for the parent constructor.
		 * @since 1.0.0
		 */
		public function __construct( $args = array() ) {
			// Set parent defaults.
			parent::__construct(
				array_merge(
					$args,
					array(
						'singular'      => 'rule',
						'plural'        => 'rules',
						'ajax'          => false,
						'empty_message' => _x( 'Sorry! There is no rate rule registered yet.', '[ADMIN] Affiliate empty table message', 'yith-woocommerce-affiliates' ),
					)
				)
			);
		}

		/* === DISPLAY METHODS === */

		/**
		 * Shows empty message for items in the table
		 *
		 * @return void
		 */
		public function display_empty_message() {
			parent::display_empty_message();
			?>
			<a href="#" role="button" class="button yith-add-button yith-plugin-fw__button--xxl" >
				<?php echo esc_html_x( 'Add rule', '[ADMIN] Add new rate rule button, in Rates tab', 'yith-woocommerce-affiliates' ); ?>
			</a>
			<?php
		}

		/* === COLUMNS METHODS === */

		/**
		 * Returns content for column Name
		 *
		 * @param YITH_WCAF_Rate_Rule $item Current item.
		 * @return string Column content.
		 */
		public function column_name( $item ) {
			if ( empty( $item->get_name() ) ) {
				return _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			}

			return $item->get_name();
		}

		/**
		 * Returns content for column Type
		 *
		 * @param YITH_WCAF_Rate_Rule $item Current item.
		 * @return string Column content.
		 */
		public function column_type( $item ) {
			$types = YITH_WCAF_Rate_Handler_Premium::get_supported_rule_types();
			$type  = $item->get_type();

			if ( ! $type || empty( $types[ $type ] ) ) {
				return _x( 'N/A', '[ADMIN] Empty admin table column', 'yith-woocommerce-affiliates' );
			}

			switch ( $type ) {
				case 'affiliate_product_ids':
					$details  = implode( ', ', $item->get_formatted_affiliates() );
					$details .= '<br/>';
					$details .= implode( ', ', $item->get_formatted_products() );
					break;
				default:
					$getter  = 'get_formatted_' . str_replace( '_ids', 's', $type );
					$details = method_exists( $item, $getter ) ? implode( ', ', (array) $item->$getter() ) : '';
			}

			$label  = $types[ $type ];
			$column = <<<EOHTML
				<b>$label:</b>
				<p>$details</p>
			EOHTML;

			return $column;
		}

		/**
		 * Returns content for column Rate
		 *
		 * @param YITH_WCAF_Rate_Rule $item Current item.
		 * @return string Column content.
		 */
		public function column_rate( $item ) {
			return $item->get_formatted_rate();
		}

		/**
		 * Returns content for column Action
		 *
		 * @param YITH_WCAF_Rate_Rule $item Current item.
		 * @return string Column content.
		 */
		public function column_actions( $item ) {
			$available_actions = array(
				'edit'   => array(
					'label' => _x( 'Edit', '[ADMIN] Rate rules table', 'yith-woocommerce-affiliates' ),
					'icon'  => 'edit',
				),
				'clone'  => array(
					'label' => _x( 'Duplicate', '[ADMIN] Rate rules table', 'yith-woocommerce-affiliates' ),
					'icon'  => 'clone',
				),
				'delete' => array(
					'label'        => _x( 'Delete', '[ADMIN] Rate rules table', 'yith-woocommerce-affiliates' ),
					'icon'         => 'trash',
					'confirm_data' => array(
						'title'               => _x( 'Confirm delete', '[ADMIN] Confirmation popup before deleting an item', 'yith-woocommerce-affiliates' ),
						'message'             => _x( 'Are you sure you want to delete this item?', '[ADMIN] Confirmation popup before deleting an item', 'yith-woocommerce-affiliates' ),
						'confirm-button'      => _x( 'Delete', '[ADMIN] Confirmation popup before deleting an item', 'yith-woocommerce-affiliates' ),
						'confirm-button-type' => 'delete',
					),
				),
				'drag'   => array(
					'label' => _x( 'Drag', '[ADMIN] Rate rules table', 'yith-woocommerce-affiliates' ),
					'icon'  => 'drag',
				),
			);

			$links = '';

			foreach ( $available_actions as $action_id => $action_details ) {
				$links .= yith_plugin_fw_get_component(
					array_merge(
						array(
							'type'  => 'action-button',
							'url'   => '#',
							'class' => $action_id,
						),
						$action_details
					),
					false
				);
			}

			return $links;
		}

		/**
		 * Returns content for column Enabled
		 *
		 * @param YITH_WCAF_Rate_Rule $item Current item.
		 * @return string Column content.
		 */
		public function column_enabled( $item ) {
			$column = yith_plugin_fw_get_field(
				array(
					'id'    => '',
					'name'  => '',
					'value' => $item->is_enabled() ? 'yes' : 'no',
					'type'  => 'onoff',
				)
			);

			return $column;
		}

		/**
		 * Returns columns available in table
		 *
		 * @return array Array of columns of the table
		 * @since 1.0.0
		 */
		public function get_columns() {
			$columns = array(
				'name'    => _x( 'Name', '[ADMIN] Affiliate Rates table headings', 'yith-woocommerce-affiliates' ),
				'type'    => _x( 'Type', '[ADMIN] Affiliate Rates table headings', 'yith-woocommerce-affiliates' ),
				'rate'    => _x( 'Rate', '[ADMIN] Affiliate Rates table headings', 'yith-woocommerce-affiliates' ),
				'enabled' => _x( 'Active', '[ADMIN] Affiliate Rates table headings', 'yith-woocommerce-affiliates' ),
				'actions' => '',
			);

			return $columns;
		}

		/**
		 * Returns hidden columns for current table
		 *
		 * @return mixed Array of hidden columns
		 * @since 1.0.0
		 */
		public function get_hidden_columns() {
			return array();
		}

		/**
		 * Generates content for a single row of the table.
		 *
		 * @since 3.1.0
		 *
		 * @param YITH_WCAF_Rate_Rule $item Current item row.
		 */
		public function single_row( $item ) {
			$serialize_array = $item->to_array();

			foreach ( $serialize_array as $key => $value ) {
				$serialize_array[ $key ] = is_scalar( $value ) ? $value : wp_json_encode( $value );
			}

			echo '<tr class="rate-rule-item" data-id="' . esc_attr( $item->get_id() ) . '" data-item="' . esc_attr( wc_esc_json( wp_json_encode( $serialize_array ) ) ) . '">';
			$this->single_row_columns( $item );
			echo '</tr>';
		}

		/**
		 * Prepare items for table
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function prepare_items() {
			// sets pagination arguments.
			$per_page     = $this->get_items_per_page( 'edit_rates_per_page' );
			$current_page = $this->get_pagenum();

			// sets columns headers.
			$columns  = $this->get_columns();
			$hidden   = $this->get_hidden_columns();
			$sortable = $this->get_sortable_columns();

			$this->_column_headers = array( $columns, $hidden, $sortable );

			// retrieve data store.
			try {
				$data_store = WC_Data_Store::load( 'rate_rule' );
			} catch ( Exception $e ) {
				$data_store = false;
			}

			// sets pagination arguments.
			$rules = $data_store ? $data_store->query(
				array(
					'orderby' => 'priority',
					'order'   => 'ASC',
					'limit'   => $per_page,
					'offset'  => ( ( $current_page - 1 ) * $per_page ),
				)
			) : array();

			// retrieve data for table.
			$this->items = $rules;

			$this->set_pagination_args(
				array(
					'total_items' => $rules->get_total_items(),
					'per_page'    => $per_page,
					'total_pages' => $rules->get_total_pages(),
				)
			);
		}
	}
}
