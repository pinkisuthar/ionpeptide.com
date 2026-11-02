<?php
/**
 * Affiliate Profile Fields Table class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium' ) ) {
	/**
	 * WooCommerce Affiliates Table
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium extends YITH_WCAF_Affiliates_Profile_Fields_Admin_Table {

		/**
		 * Returns content for column Action
		 *
		 * @param array $item Current item.
		 * @return string Column content.
		 */
		public function column_actions( $item ) {
			$available_actions = array(
				'drag' => array(
					'label' => _x( 'Drag', '[ADMIN] Affiliate fields table', 'yith-woocommerce-affiliates' ),
					'icon'  => 'drag',
				),
			);

			if ( $item['editable'] ) {
				$available_actions = yith_wcaf_append_items(
					array(
						'edit' => array(
							'label' => _x( 'Edit', '[ADMIN] Affiliate fields table', 'yith-woocommerce-affiliates' ),
							'icon'  => 'edit',
						),
					),
					'drag',
					$available_actions,
					'before'
				);
			}

			if ( $item['editable'] && ! $item['reserved'] ) {
				$available_actions = yith_wcaf_append_items(
					array(
						'clone'  => array(
							'label' => _x( 'Duplicate', '[ADMIN] Affiliate fields table', 'yith-woocommerce-affiliates' ),
							'icon'  => 'clone',
						),
						'delete' => array(
							'label'        => _x( 'Delete', '[ADMIN] Affiliate fields table', 'yith-woocommerce-affiliates' ),
							'icon'         => 'trash',
							'confirm_data' => array(
								'title'               => _x( 'Confirm delete', '[ADMIN] Confirmation popup before deleting an item', 'yith-woocommerce-affiliates' ),
								'message'             => _x( 'Are you sure you want to delete this item?', '[ADMIN] Confirmation popup before deleting an item', 'yith-woocommerce-affiliates' ),
								'confirm-button'      => _x( 'Delete', '[ADMIN] Confirmation popup before deleting an item', 'yith-woocommerce-affiliates' ),
								'confirm-button-type' => 'delete',
							),
						),
					),
					'drag',
					$available_actions,
					'before'
				);
			}

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
		 * Returns columns available in table
		 *
		 * @return array Array of columns of the table
		 * @since 1.0.0
		 */
		public function get_columns() {
			$columns = array(
				'name'     => _x( 'Name', '[ADMIN] Affiliates profile fields table headings', 'yith-woocommerce-affiliates' ),
				'type'     => _x( 'Type', '[ADMIN] Affiliates profile fields table headings', 'yith-woocommerce-affiliates' ),
				'label'    => _x( 'Label', '[ADMIN] Affiliates profile fields table headings', 'yith-woocommerce-affiliates' ),
				'required' => _x( 'Required', '[ADMIN] Affiliates profile fields table headings', 'yith-woocommerce-affiliates' ),
				'enabled'  => _x( 'Active', '[ADMIN] Affiliates profile fields table headings', 'yith-woocommerce-affiliates' ),
				'actions'  => '',
			);

			return $columns;
		}

		/**
		 * Print table's action button
		 *
		 * @param string $which Top / Bottom.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function extra_tablenav( $which ) {
			if ( 'top' !== $which ) {
				return;
			}

			?>
			<a href="" class="button button-primary" id="add_field"><?php echo esc_html_x( '+ Add field', '[ADMIN] Affiliate fields table', 'yith-woocommerce-affiliates' ); ?></a>
			<a href="" class="button button-secondary" id="restore_defaults"><?php echo esc_html_x( 'Restore defaults', '[ADMIN] Affiliate fields table', 'yith-woocommerce-affiliates' ); ?></a>
			<?php
		}
	}
}
