<?php
/**
 * Orders Handler class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Orders_Premium' ) ) {
	/**
	 * Orders handler
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Orders_Premium extends YITH_WCAF_Orders {

		/**
		 * Whether persistent commission calculation is enabled
		 *
		 * @var bool
		 * @since 1.0.0
		 */
		protected $persistent_calculation;

		/**
		 * Whether system should avoid referral change or not
		 *
		 * @var bool
		 * @since 1.0.0
		 */
		protected $avoid_referral_change;

		/**
		 * Array of products excluded from affiliation program
		 *
		 * @var array
		 * @since 1.2.5
		 */
		protected $excluded_products;

		/**
		 * Array of product categories excluded from affiliation program
		 *
		 * @var array
		 * @since 2.0.0
		 */
		protected $excluded_product_categories;

		/**
		 * Array of product tags excluded from affiliation program
		 *
		 * @var array
		 * @since 2.0.0
		 */
		protected $excluded_product_tags;

		/**
		 * Constructor method
		 */
		public function __construct() {
			parent::__construct();

			// register order completed/processing handling.
			add_action( 'woocommerce_order_status_completed', array( $this, 'register_persistent_affiliate' ), 10, 1 );
			add_action( 'woocommerce_order_status_processing', array( $this, 'register_persistent_affiliate' ) );
		}

		/* === ORDER COMMISSION HANDLING === */

		/**
		 * Create orders commissions, on process checkout action, and when an order is untrashed
		 *
		 * @param int    $order_id     Order id.
		 * @param string $token        Referral token.
		 * @param string $token_origin Referral token origin.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function create_commissions( $order_id, $token, $token_origin = 'undefined' ) {
			$order     = wc_get_order( $order_id );
			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_token( $token );

			// if no order or user, return.
			/**
			 * APPLY_FILTERS: yith_wcaf_create_order_commissions
			 *
			 * Filters whether to create the order commissions.
			 *
			 * @param bool   $create_commissions Whether to create commissions or not.
			 * @param int    $order_id           Order id.
			 * @param string $token              Referral token.
			 * @param string $token_origin       Referral token origin.
			 */
			if ( ! $order || ! $affiliate || ! $affiliate->is_valid() || ! apply_filters( 'yith_wcaf_create_order_commissions', true, $order_id, $token, $token_origin ) ) {
				return;
			}

			// map commission status on order status.
			$commission_status = $this->map_commission_status( $order->get_status() );

			// saves current token into order metadata.
			$order->update_meta_data( '_yith_wcaf_referral', $token );

			// process commission, add order item meta, register order as processed.
			$items = $order->get_items();

			if ( ! empty( $items ) ) {
				foreach ( $items as $item_id => $item ) {
					/**
					 * Each of order's line items
					 *
					 * @var $item WC_Order_Item_Product
					 */
					$product_id   = $item->get_product_id();
					$variation_id = $item->get_variation_id();

					// retrieves current product id and rate to use for commissions.
					$product_id = $variation_id ? $variation_id : $product_id;

					if ( ! $product_id ) {
						continue;
					}

					// checks if product is should be processed.
					if ( ! $this->should_create_product_commission( $product_id, $order_id, $token, $token_origin ) ) {
						continue;
					}

					// retrieves rate to use for commissions.
					$rate = (float) $item->get_meta( '_yith_wcaf_commission_rate' );

					if ( ! $rate ) {
						$rate = YITH_WCAF_Rate_Handler_Premium::get_rate( $affiliate, intval( $product_id ), $order_id );

						// correct commission rate, when persistent calculation is enabled.
						if ( $this->persistent_calculation && 'persistent' === $token_origin ) {
							$rate = YITH_WCAF_Rate_Handler_Premium::get_persistent_rate( $rate, $token, $item );
						}
					}

					$commission_amount = $this->calculate_line_item_commission( $order, $item_id, $item, $rate );

					/**
					 * APPLY_FILTERS: yith_wcaf_create_order_commission_skip_zero_commissions
					 *
					 * Allow to skip creation of commissions with amount 0.
					 *
					 * @param bool $skip_zero_commissions Whether to skip 0 commissions or not (default: true).
					 */
					if ( $commission_amount < 0.01 && apply_filters( 'yith_wcaf_create_order_commission_skip_zero_commissions', true ) ) {
						continue;
					}

					$commission      = null;
					$commission_args = array(
						'order_id'     => $order_id,
						'affiliate_id' => $affiliate['ID'],
						'line_item_id' => $item_id,
						'line_total'   => $this->get_line_item_total( $order, $item, $rate ),
						'product_id'   => $product_id,
						'product_name' => wp_strip_all_tags( $item->get_product()->get_name() ),
						'rate'         => $rate,
						'amount'       => $commission_amount,
						'status'       => $commission_status,
						/**
						 * APPLY_FILTERS: yith_wcaf_create_order_commission_use_current_date
						 *
						 * Filters whether to use the current date for the commission when it is created.
						 *
						 * @param bool $use_current_date Whether to use the current date for the commission, use the order created date when false.
						 */
						'created_at'   => apply_filters( 'yith_wcaf_create_order_commission_use_current_date', true ) ? current_time( 'mysql' ) : $order->get_date_created()->format( 'Y-m-d H:i:s' ),
					);

					/**
					 * APPLY_FILTERS: yith_wcaf_create_item_commission
					 *
					 * Filters whether to create the commissions for the order item.
					 *
					 * @param bool                  $create_commission Whether to create commission for the order item or not.
					 * @param WC_Order_Item_Product $item              Order item object.
					 * @param int                   $item_id           Order item id.
					 * @param int                   $product_id        Product id.
					 * @param array                 $commission_args   Array of arguments for the commission generation.
					 */
					if ( ! apply_filters( 'yith_wcaf_create_item_commission', true, $item, $item_id, $product_id, $commission_args ) ) {
						continue;
					}

					// checks whether a commission already exists for current item.
					$old_id = (int) $item->get_meta( '_yith_wcaf_commission_id' );

					if ( $old_id ) {
						$commission = YITH_WCAF_Commission_Factory::get_commission( $old_id );
					}

					// create or update commission with new props.
					if ( empty( $commission ) ) {
						$commission = YITH_WCAF_Commission_Factory::create_commission( $commission_args );
					} else {
						$commission->set_props( $commission_args );
					}

					// save commissions.
					$commission->save();
				}
			}

			$order->save();
		}

		/**
		 * Assign affiliate to an order and create commissions
		 *
		 * @param int $order_id        Order id.
		 * @param int $affiliate_token Affiliate token.
		 *
		 * @return void
		 * @since 1.0.9
		 */
		public function assign_commissions( $order_id, $affiliate_token ) {
			$order     = wc_get_order( $order_id );
			$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate_by_token( $affiliate_token );

			if ( ! $order || ! $affiliate ) {
				return;
			}

			$referral_history = $order->get_meta( '_yith_wcaf_referral_history' );
			$referral_history = ! empty( $referral_history ) ? $referral_history : array();

			$new_token = $affiliate->get_token();

			$referral_history[] = $new_token;

			$order->update_meta_data( '_yith_wcaf_referral', $new_token );
			$order->update_meta_data( '_yith_wcaf_referral_history', $referral_history );

			$order->save();

			$this->regenerate_commissions( $order_id, $new_token );
		}

		/**
		 * Unassign affiliate from an order and delete commissions
		 *
		 * Redirect to order edit page once completed
		 *
		 * @param int $order_id Order id.
		 *
		 * @return void
		 * @since 1.0.9
		 */
		public function unassign_commissions( $order_id ) {
			$order = wc_get_order( $order_id );

			$this->delete_commissions( $order_id, true, true );

			$order->delete_meta_data( '_yith_wcaf_referral' );
			$order->delete_meta_data( '_yith_wcaf_referral_history' );
			$order->save();
		}

		/**
		 * Checks whether commission can be created for a specific product
		 *
		 * @param int    $product_id   Product id.
		 * @param int    $order_id     Order id.
		 * @param string $token        Affiliate token.
		 * @param string $token_origin Token origin.
		 *
		 * @return bool Whether to create commission or not.
		 */
		protected function should_create_product_commission( $product_id, $order_id = false, $token = false, $token_origin = false ) {
			$is_excluded = false;

			// retrieve parent id when dealing with variations, use submitted product_id otherwise.
			$parent_id = wp_get_post_parent_id( $product_id );
			$parent_id = $parent_id ? $parent_id : $product_id;

			// search for product terms.
			$product_categories = wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'ids' ) );
			$product_tags       = wp_get_post_terms( $parent_id, 'product_tag', array( 'fields' => 'ids' ) );

			// check if product or any of its terms can be found in one of the exclusion lists.
			if ( array_intersect( array( $product_id, $parent_id ), $this->excluded_products ) ) {
				$is_excluded = true;
			} elseif ( array_intersect( $product_categories, $this->excluded_product_categories ) ) {
				$is_excluded = true;
			} elseif ( array_intersect( $product_tags, $this->excluded_product_tags ) ) {
				$is_excluded = true;
			}

			/**
			 * APPLY_FILTERS: yith_wcaf_create_product_commission
			 *
			 * Filters whether create commissions for specific products.
			 *
			 * @param bool   $create_product_commission Whether to create commissions for products or not.
			 * @param int    $product_id                Product id.
			 * @param int    $order_id                  Order id.
			 * @param string $token                     Referral token.
			 * @param string $token_origin              Referral token origin.
			 */
			return apply_filters( 'yith_wcaf_create_product_commission', ! $is_excluded, $product_id, $order_id, $token, $token_origin );
		}

		/* === STATUS CHANGE HANDLING === */

		/**
		 * Register persistent affiliate, if option enabled
		 *
		 * @param int $order_id Order id.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function register_persistent_affiliate( $order_id ) {
			if ( ! $this->persistent_calculation ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return;
			}

			$customer = $order->get_user_id();
			$referral = $order->get_meta( '_yith_wcaf_referral' );

			if ( ! $customer || ! $referral ) {
				return;
			}

			$previous_persistent_token = get_user_meta( $customer, '_yith_wcaf_persistent_token', true );

			if ( $previous_persistent_token && $this->avoid_referral_change ) {
				return;
			}

			/**
			 * DO_ACTION: yith_wcaf_updated_persistent_token
			 *
			 * Allows to trigger some action when the persistent token is saved.
			 *
			 * @param int    $customer Current user id.
			 * @param string $referral Current referral token.
			 * @param int    $order_id Current order id (if any; null otherwise).
			 */
			do_action( 'yith_wcaf_updated_persistent_token', $customer, $referral, $order_id );

			update_user_meta( $customer, '_yith_wcaf_persistent_token', $referral );
		}

		/* === UTILITIES === */

		/**
		 * Retrieve options needed to generate commissions
		 *
		 * @return void
		 */
		protected function retrieve_options() {
			parent::retrieve_options();

			$enable_product_exclusions = get_option( 'yith_wcaf_product_exclusions_enabled', 'no' );

			if ( 'yes' === $enable_product_exclusions ) {
				$excluded_products           = get_option( 'yith_wcaf_excluded_products', array() );
				$excluded_product_categories = get_option( 'yith_wcaf_excluded_product_categories', array() );
				$excluded_product_tags       = get_option( 'yith_wcaf_excluded_product_tags', array() );
			}

			if ( ! isset( $excluded_products ) || ! is_array( $excluded_products ) ) {
				$excluded_products = array();
			}

			if ( ! isset( $excluded_product_categories ) || ! is_array( $excluded_product_categories ) ) {
				$excluded_product_categories = array();
			}

			if ( ! isset( $excluded_product_tags ) || ! is_array( $excluded_product_tags ) ) {
				$excluded_product_tags = array();
			}

			$this->persistent_calculation      = 'yes' === get_option( 'yith_wcaf_commission_persistent_calculation', 'no' );
			$this->avoid_referral_change       = 'yes' === get_option( 'yith_wcaf_avoid_referral_change', 'no' );
			$this->excluded_products           = array_map( 'intval', $excluded_products );
			$this->excluded_product_categories = array_map( 'intval', $excluded_product_categories );
			$this->excluded_product_tags       = array_map( 'intval', $excluded_product_tags );
		}
	}
}
