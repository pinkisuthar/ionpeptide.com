<?php
/**
 * Commission Handler Premium class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Commissions_Premium' ) ) {
	/**
	 * WooCommerce Commission Handler Premium
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Commissions_Premium extends YITH_WCAF_Commissions {

		/* === HELPER METHODS === */

		/**
		 * Retrieves a list of commissions for a specific affiliate, that matches a specified amount
		 * The amount will match the one submitted, as far as there are enough commissions to match it; if sum of existing
		 * commissions doesn't match the amount, a commission will be split accordingly
		 * Commissions retrieved by this method can be filtered using $args param
		 *
		 * @param int   $affiliate_id Affiliate id.
		 * @param float $amount       Amount to match.
		 * @param array $args         Array of filtering criteria (@see \YITH_WCAF_Commission_Data_store::query).
		 */
		public function get_commissions_for_amount( $affiliate_id, $amount, $args = array() ) {
			$defaults = array(
				'include'        => array(),
				'exclude'        => array(),
				'order_id'       => false,
				'status'         => 'pending',
				'status__not_in' => false,
				'product_id'     => false,
				'product_name'   => false,
				'rate'           => false,
				'amount'         => false,
				'interval'       => false,
			);

			$args = wp_parse_args( $args, $defaults );

			// set up arguments required by the method.
			$args['affiliate_id'] = $affiliate_id;
			$args['orderby']      = 'ID';
			$args['order']        = 'ASC';

			// retrieve affiliate.
			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_id( $affiliate_id );

			if ( ! $affiliate ) {
				return false;
			}

			// retrieve commissions.
			$commissions = YITH_WCAF_Commission_Factory::get_commissions( $args );

			if ( empty( $commissions ) ) {
				return false;
			}

			// check that we can reach desired amount.
			$total_commissions_amount = $commissions->get_total_amount();
			$affiliate_balance        = $affiliate->get_balance();

			if ( $total_commissions_amount > $affiliate_balance ) {
				$total_commissions_amount = $affiliate_balance;
			}

			// affiliate has not enough commissions for specified amount; return.
			if ( $total_commissions_amount < $amount - 0.01 ) {
				return false;
			}

			$commissions_for_amount       = array();
			$commissions_for_amount_total = 0;
			$edge_commission              = false;

			// cycle through commissions, until we get just above desired amount.
			foreach ( $commissions as $commission_id => $commission ) {
				$commissions_for_amount[ $commission_id ] = $commission;

				$commissions_for_amount_total += $commission->get_amount();
				$edge_commission               = $commission;

				if ( abs( $commissions_for_amount_total - $amount ) < 0.01 || $commissions_for_amount_total > $amount ) {
					break;
				}
			}

			// if amount matches, we can return commissions, no additional operation is required.
			if ( abs( $commissions_for_amount_total - $amount ) < 0.01 ) {
				return new YITH_WCAF_Commissions_Collection( array_keys( $commissions_for_amount ) );
			}

			// otherwise, we'll need to split edge commission in half.
			$exceeding             = $commissions_for_amount_total - $amount;
			$new_commission_amount = $edge_commission->get_amount() - $exceeding;

			// update the existing commission to have the amount required to match requested total.
			$edge_commission->set_amount( $new_commission_amount );
			$edge_commission->save();

			// prepare data for the new commission.
			$edge_commission_data               = $edge_commission->get_data();
			$edge_commission_data['amount']     = $exceeding;
			$edge_commission_data['line_total'] = 0;// set total to 0, as line total is already registered in original commission.

			unset( $edge_commission_data['id'] );

			// create a new commissions for the amount that we removed from edge commissions (affiliate balance is left untouched).
			$new_commission = YITH_WCAF_Commission_Factory::create_commission( $edge_commission_data );
			$new_commission->save();

			// translators: 1. Original commission id.
			$edge_commission->add_note( sprintf( _x( 'This commission was split into two parts; the remaining amount was assigned to #%d commission.', '[ADMIN] Commission note', 'yith-woocommerce-affiliates' ), $new_commission->get_id() ) );
			// translators: 1. Original commission id.
			$new_commission->add_note( sprintf( _x( 'This commission was created splitting #%d commission.', '[ADMIN] Commission note', 'yith-woocommerce-affiliates' ), $edge_commission->get_id() ) );

			return new YITH_WCAF_Commissions_Collection( array_keys( $commissions_for_amount ) );
		}
	}
}
