<?php
/**
 * Registers metaboxes for the plugin
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Admin_Meta_Boxes_Premium' ) ) {
	/**
	 * Class that manages meta boxes.
	 */
	class YITH_WCAF_Admin_Meta_Boxes_Premium extends YITH_WCAF_Admin_Meta_Boxes {

		/**
		 * Init method.
		 *
		 * @since 2.0.0
		 */
		public static function init() {
			parent::init();

			// register meta box related actions.
			add_action( 'woocommerce_process_shop_order_meta', array( 'YITH_WCAF_Order_Referral_Commissions_Meta_Box', 'add_order_affiliate' ), 10, 1 );
			add_action( 'admin_action_yith_wcaf_delete_order_affiliate', array( 'YITH_WCAF_Order_Referral_Commissions_Meta_Box', 'delete_order_affiliate' ) );
		}

		/**
		 * Init internal list of meta boxes
		 *
		 * @return array Array of defined metaboxes
		 */
		protected static function init_meta_boxes() {
			self::$meta_boxes = array_merge(
				parent::init_meta_boxes(),
				array(
					'yith_wcaf_order_referral_history' => array(
						'title'   => _x( 'Referral history', '[ADMIN] MetaBox title', 'yith-woocommerce-affiliates' ),
						'screens' => array( 'shop_order', 'woocommerce_page_wc-orders', 'shop_subscription' ),
						'context' => 'side',
					),
				)
			);

			return self::$meta_boxes;
		}
	}
}
