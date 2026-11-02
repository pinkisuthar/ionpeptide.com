<?php
/**
 * Registers Order Referral Commission meta box
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Order_Referral_History_Meta_Box' ) ) {
	/**
	 * Class that manages meta boxes.
	 */
	class YITH_WCAF_Order_Referral_History_Meta_Box {
		/**
		 * Print commission order metabox
		 *
		 * @param WP_POST $post Current order post object.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function print( $post ) {
			// set order id.
			$order_id = $post instanceof WC_Order ? $post->get_id() : $post->ID;

			// if we're on wc subscription page, use subscription parent order.
			if ( $post instanceof WP_Post && 'shop_subscription' === $post->post_type ) {
				$order_id = $post->post_parent;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return;
			}

			// define variables to be used on template.
			$referral_history       = $order->get_meta( '_yith_wcaf_referral_history' );
			$referral_history_users = array();

			if ( $referral_history ) {
				foreach ( $referral_history as $referral ) {
					$user      = array();
					$affiliate = YITH_WCAF_Affiliates()->get_affiliate_by_token( $referral );

					if ( ! $affiliate ) {
						continue;
					}

					$user_data = $affiliate->get_user();

					if ( ! $user_data ) {
						return;
					}

					$user['user_email'] = $user_data->user_email;
					$user['username']   = $affiliate->get_formatted_name();

					$referral_history_users[] = $user;
				}
			}

			include YITH_WCAF_DIR . 'views/meta-boxes/referral-history-metabox.php';
		}
	}
}
