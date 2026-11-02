<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * BWFAN_Public class
 */
#[AllowDynamicProperties]
class BWFAN_Abandoned_Cart {

	public static $is_cart_changed = false;
	private static $ins = null;
	public $is_cart_restored = false;
	public $is_aerocheckout_page = false;

	protected $aero_product_data = [];
	protected $items = array();
	protected $coupon_data = array();
	protected $fees = array();
	protected $restored_cart_details = array();
	protected $user_id = 0;
	protected $added_to_cart = null;

	/**
	 *   Construct
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'bwfan_global_setting_page', [ $this, 'display_cart_wc_deactivate_notice' ] );
			add_filter( 'bwfan_main_tab_array', [ $this, 'remove_carts_tab' ] );

			return;
		}
		add_action( 'wfacp_get_fragments', [ $this, 'update_items_in_abandoned_table' ], 10, 2 );
		add_action( 'wc_ajax_bwfan_insert_abandoned_cart', [ $this, 'insert_abandoned_cart' ] );
		add_action( 'wc_ajax_bwfan_delete_abandoned_cart', [ $this, 'delete_abandoned_cart' ] );
		add_action( 'bwfan_remove_abandoned_data_from_table', [ $this, 'remove_abandoned_data_from_table' ] );
		add_action( 'bwfan_wc_order_status_changed', [ $this, 'recheck_abandoned_row' ], 10, 3 );
		if ( is_admin() || false === BWFAN_Common::is_cart_abandonment_active() ) {
			return;
		}

		add_action( 'woocommerce_checkout_order_processed', [ $this, 'unset_session_key' ], 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'unset_session_key' ], 1 );

		add_action( 'woocommerce_checkout_order_processed', [ $this, 'attach_order_id_to_abandoned_row' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'attach_order_id_to_abandoned_row' ] );

		add_action( 'woocommerce_checkout_order_processed', [ $this, 'maybe_set_recovered_key' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'maybe_set_recovered_key' ] );

		add_action( 'wfocu_offer_accepted_and_processed', [ $this, 'save_order_total_base_after_upsell_accepted' ], 999, 3 );

		// update events for cart
		// Keep at priority 10 — do NOT move later. Some pricing plugins add/remove hooks on
		// woocommerce_add_to_cart mid-dispatch, which reshuffles WP_Hook's pointer and skips the first
		// callback above prio 21; at 300 this one got skipped so the shutdown capture never ran (#4729).
		add_action( 'woocommerce_add_to_cart', [ $this, 'woocommerce_add_to_cart' ] );
		add_action( 'woocommerce_applied_coupon', [ $this, 'cart_updated' ] );
		add_action( 'woocommerce_removed_coupon', [ $this, 'cart_updated' ] );
		add_action( 'woocommerce_cart_item_removed', [ $this, 'cart_updated' ] );
		add_action( 'woocommerce_cart_item_restored', [ $this, 'cart_updated' ] );
		add_action( 'woocommerce_after_cart_item_quantity_update', [ $this, 'cart_updated' ] );

		add_action( 'wp_login', [ $this, 'cart_updated_with_cookie' ], 20, 2 );
		add_action( 'wp', [ $this, 'check_for_cart_update_cookie' ], 99 );

		// restore cart when user clicks on restore cart link and lands on site
		add_action( 'wp', [ $this, 'set_session_for_recovered_cart' ], 1 );
		add_action( 'wp', [ $this, 'handle_restore_cart' ], 5 );

		add_action( 'woocommerce_after_calculate_totals', [ $this, 'trigger_update_on_cart_and_checkout_pages' ] );
		// prefill the checkout fields after the cart is restored
		add_filter( 'woocommerce_billing_fields', [ $this, 'prefill_billing_fields' ], 20 );
		add_filter( 'woocommerce_shipping_fields', [ $this, 'prefill_shipping_fields' ], 20 );
		/**
		 * Block-checkout recovery prefill: the woocommerce_*_fields filters above do not run
		 * for the Gutenberg Checkout block. Instead, hydrate WC()->customer from the restored
		 * session so the Store API (wc/store/v1/cart) serializes the prefilled values into
		 * the block's initial render.
		 *
		 * Runs after handle_restore_cart() (priority 5 on `wp`).
		 */
		add_action( 'wp', [ $this, 'block_prefill_customer_from_restored_cart' ], 10 );
		add_filter( 'woocommerce_ship_to_different_address_checked', [ $this, 'wc_check_different_shipping' ], 20 );
		add_filter( 'wfacp_ship_to_different_address_checked', [ $this, 'wfacp_check_different_shipping' ], 20 );

		add_action( 'bwfanac_checkout_data', [ $this, 'set_data_for_js' ], 10, 3 );
		add_action( 'bwfanac_cart_details', [ $this, 'remove_data_js' ] );

		add_filter( 'wfacp_default_values', [ $this, 'prefill_embed_forms' ], 15, 2 );
		add_filter( 'wfacp_skip_add_to_cart', [ $this, 'check_aerocheckout_page' ], 12, 2 );
		add_action( 'wfacp_after_checkout_page_found', [ $this, 'disable_geolocation_recovery' ] );
		add_action( 'wfacp_after_checkout_page_found', [ $this, 'wfacp_country_fields_on_recovery' ] );

		/** Remove cart item key from session */
		add_action( 'woocommerce_remove_cart_item', [ $this, 'remove_session' ], 99 );

		/** Priority 21: run after WC_Session_Handler::save_data (20) so a failure here cannot block the WC session save */
		add_action( 'shutdown', [ $this, 'add_to_cart' ], 21 );
	}

	/**
	 * Check if "billing same as shipping" is enabled in the restored cart
	 *
	 * @param bool $checked The current checkbox state
	 *
	 * @return bool
	 */
	public function wc_check_different_shipping( $checked ) {
		$data = WC()->session->get( 'restored_cart_details' );
		if ( ! is_array( $data ) || 0 === count( $data ) ) {
			return $checked;
		}
		$checkout_data = $this->get_checkout_data( $data );

		if ( isset( $checkout_data['fields'] ) && isset( $checkout_data['fields']['ship_to_different_address'] ) ) {
			return $checkout_data['fields']['ship_to_different_address'];
		}

		return '';
	}

	/**
	 * Advanced checkbox state detection for AeroCheckout pages
	 *
	 * @param bool $checked The current checkbox state
	 *
	 * @return mixed
	 */
	public function wfacp_check_different_shipping( $checked ) {
		$data = WC()->session->get( 'restored_cart_details' );
		if ( ! is_array( $data ) || 0 === count( $data ) ) {
			return $checked;
		}
		$checkout_data = $this->get_checkout_data( $data );

		if ( isset( $checkout_data['fields'] ) && isset( $checkout_data['fields']['billing_same_as_shipping'] ) ) {
			return $checkout_data['fields']['billing_same_as_shipping'];
		}

		if ( isset( $checkout_data['fields'] ) && isset( $checkout_data['fields']['shipping_same_as_billing'] ) ) {
			return $checkout_data['fields']['shipping_same_as_billing'];
		}

		return null;
	}

	/**
	 * Disable Geolocation Recovery
	 *
	 * @since 1.0.0
	 */
	public function disable_geolocation_recovery() {
		if ( ! isset( $_GET['bwfan-ab-id'] ) && ! isset( $_GET['bwfan-cart-restored'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link opened from an email; such links cannot carry a nonce
			return;
		}

		if ( isset( $_GET['bwfan-ab-id'] ) && ( empty( $_GET['bwfan-ab-id'] ) || wp_doing_ajax() || is_admin() ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( isset( $_GET['bwfan-cart-restored'] ) && 'success' !== sanitize_text_field( wp_unslash( $_GET['bwfan-cart-restored'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link opened from an email; such links cannot carry a nonce
			return;
		}

		if ( ! function_exists( 'wfacp_template' ) || ! class_exists( 'WFACP_Template_Common' ) ) {
			return;
		}

		$template = wfacp_template();
		if ( $template instanceof WFACP_Template_Common ) {
			remove_action( 'wfacp_outside_header', [ $template, 'get_base_country' ] );
		}
	}

	/**
	 * Wfacp Country Fields On Recovery
	 *
	 * @since 1.0.0
	 */
	public function wfacp_country_fields_on_recovery() {
		add_filter( 'default_checkout_billing_country', [ $this, 'wfacp_assign_country' ], 10, 2 );
		add_filter( 'default_checkout_shipping_country', [ $this, 'wfacp_assign_country' ], 10, 2 );
	}

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self;
		}

		return self::$ins;
	}

	/**
	 * Whether the WooCommerce checkout page uses the Gutenberg Checkout block.
	 * Cached per request.
	 *
	 * @return bool
	 */
	public static function is_block_checkout() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		if ( ! function_exists( 'has_block' ) ) {
			return $cache = false;
		}

		/** Current post (e.g. /checkout-2/) embeds the block. */
		$queried_id = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
		if ( $queried_id > 0 && has_block( 'woocommerce/checkout', $queried_id ) ) {
			return $cache = true;
		}
		global $post;
		if ( $post instanceof WP_Post && has_block( 'woocommerce/checkout', $post ) ) {
			return $cache = true;
		}

		/** WC-designated checkout page renders the block. */
		if ( function_exists( 'wc_get_page_id' ) ) {
			$page_id = wc_get_page_id( 'checkout' );
			if ( $page_id > 0 && has_block( 'woocommerce/checkout', $page_id ) ) {
				return $cache = true;
			}
		}

		return $cache = false;
	}

	/**
	 * Display Cart Wc Deactivate Notice
	 *
	 * @since 1.0.0
	 */
	public function display_cart_wc_deactivate_notice() {
		echo '<fieldset class="bwfan-tab-content bwfan-activeTab" setting-id="tab-abandonment">';
		echo '<div><strong>' . esc_html__( 'Cart abandonment tracking is a feature associated with WooCommerce, kindly enable the WooCommerce to use it.', 'wp-marketing-automations' ) . '</strong></div>';
		echo '</fieldset>';
	}

	/**
	 * Remove Carts Tab
	 *
	 * @since 1.0.0
	 */
	public function remove_carts_tab( $tabs ) {
		if ( isset( $tabs['carts'] ) ) {
			unset( $tabs['carts'] );
		}

		return $tabs;
	}

	/**
	 * Saving order base total for displaying correct revenue in cart analytics screen
	 *
	 * @param $order_id
	 * @param $order
	 *
	 * @return void
	 */
	public function save_order_total_base_in_order_meta( $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		$total_base = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $order->get_total(), BWF_WC_Compatibility::get_order_currency( $order ) );

		BWFAN_Common::save_order_meta( $order_id, '_bwfan_order_total_base', $total_base );
	}

	/**
	 * Saving order base total after upsell offer accepted
	 *
	 * @param $offer_id
	 * @param $package
	 * @param $order WC_Order
	 */
	public function save_order_total_base_after_upsell_accepted( $offer_id, $package, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->save_order_total_base_in_order_meta( $order->get_id(), $order );
	}

	/**
	 * Recheck Abandoned Row
	 *
	 * @since 1.0.0
	 */
	public function recheck_abandoned_row( $order, $form, $to ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$failed_statuses = [ 'pending', 'failed', 'cancelled' ];
		if ( in_array( $form, $failed_statuses, true ) && ! in_array( $to, $failed_statuses, true ) ) {
			bwf_schedule_single_action( time(), 'bwfan_remove_abandoned_data_from_table', [ 'order_id' => $order->get_id() ], 'abandoned' );
			BWFAN_Common::ping_woofunnels_worker();
		}
	}

	/**
	 * Order already credited with recovering the given abandoned cart, if any.
	 *
	 * Recovery attribution is written from several places (checkout session, the paid-order cleanup and
	 * the abandoned event's validation), none of which see each other. Callers use this to avoid crediting
	 * one cart to a second order, which double counts it in Recovered carts and Cart Analytics.
	 *
	 * @param $cart_id - abandoned cart row id
	 *
	 * @return int order id already credited with this cart, 0 when none
	 */
	public static function get_attributed_order_id( $cart_id ) {
		global $wpdb;

		$cart_id = absint( $cart_id );
		if ( 0 === $cart_id ) {
			return 0;
		}

		if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
			$query = $wpdb->prepare( "SELECT `order_id` FROM {$wpdb->prefix}wc_orders_meta WHERE `meta_key` = %s AND `meta_value` = %d LIMIT 1", '_bwfan_recovered_ab_id', $cart_id );
		} else {
			$query = $wpdb->prepare( "SELECT `post_id` FROM {$wpdb->prefix}postmeta WHERE `meta_key` = %s AND `meta_value` = %d LIMIT 1", '_bwfan_recovered_ab_id', $cart_id );
		}

		return absint( $wpdb->get_var( $query ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
	}

	/**
	 * Remove abandoned data from table and also delete it's tasks
	 *
	 * @param $order_id
	 */
	public function remove_abandoned_data_from_table( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->save_order_total_base_in_order_meta( $order->get_id(), $order );

		$ab_cart_id   = $order->get_meta( 'bwfan_cart_id', true );
		$cart_details = [];
		if ( empty( $ab_cart_id ) ) {
			$sql_where     = 'email = %s';
			$billing_email = BWFAN_Woocommerce_Compatibility::get_billing_email( $order );
			global $wpdb;
			$sql_where = $wpdb->prepare( $sql_where, $billing_email ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$cart_details = $this->get_cart_by_multiple_key( $sql_where );
			if ( is_array( $cart_details ) && isset( $cart_details['ID'] ) ) {
				$ab_cart_id = $cart_details['ID'];
			}
		}

		if ( empty( $ab_cart_id ) ) {
			return;
		}

		/** Cart recovered attribution code */
		$recovered_cart_id     = 0;
		$recovered_cart_cids   = [];
		$order_meta_automation = $order->get_meta( '_bwfan_ab_cart_recovered_a_id' );
		if ( ! empty( $order_meta_automation ) ) {
			$order_recovered_id = $order->get_meta( '_bwfan_recovered_ab_id' );
			$attributed_to      = self::get_attributed_order_id( $ab_cart_id );
			if ( empty( $order_recovered_id ) && ( 0 === $attributed_to || $attributed_to === absint( $order_id ) ) ) {
				/** save abandoned id in order meta */
				BWFAN_Common::save_order_meta( $order_id, '_bwfan_recovered_ab_id', $ab_cart_id );

				if ( empty( $cart_details ) ) {
					$cart_details = BWFAN_Model_Abandonedcarts::get( $ab_cart_id );
				}

				do_action( 'abandoned_cart_recovered', $cart_details, $order_id, $order );
			}
		} elseif ( empty( $order->get_meta( '_bwfan_recovered_ab_id' ) ) ) {
			/**
			 * Fallback: the session that placed the order carried no recovery link (direct purchase, or the link was
			 * opened in another browser), so credit the cart an automation was working for this buyer, if any.
			 * Skipped once the order is credited, since this runs again on every pending/failed/cancelled -> paid change.
			 */
			$recovered_cart = $this->get_cart_in_automation_for_order( $order, $ab_cart_id );
			if ( ! empty( $recovered_cart ) ) {
				/** Skip when this cart is already credited to another order, otherwise it double counts. */
				$attributed_to = self::get_attributed_order_id( $recovered_cart['ID'] );
				if ( 0 === $attributed_to || $attributed_to === absint( $order_id ) ) {
					$recovered_cart_id   = absint( $recovered_cart['ID'] );
					$recovered_cart_cids = $this->get_cart_contact_ids( $recovered_cart, $order );
					BWFAN_Common::save_order_meta( $order_id, '_bwfan_recovered_ab_id', $recovered_cart_id );

					/**
					 * Recovered carts lists orders by this meta. Only write a genuine automation id: 0 would list the
					 * order there while Cart Analytics (meta_value > 0) skips it, and the two views would diverge.
					 */
					$automation_id = BWFAN_Model_Automation_Contact::get_automation_id_by_ab_id( $recovered_cart_id, $recovered_cart_cids );
					if ( $automation_id > 0 ) {
						BWFAN_Common::save_order_meta( $order_id, '_bwfan_ab_cart_recovered_a_id', $automation_id );
					}
					do_action( 'abandoned_cart_recovered', $recovered_cart, $order_id, $order );
				}
			}
		}

		/** Preserve visitor fingerprint in order meta before deleting cart row */
		if ( empty( $cart_details ) ) {
			$cart_details = BWFAN_Model_Abandonedcarts::get( $ab_cart_id );
		}
		if ( ! empty( $cart_details['checkout_data'] ) ) {
			$checkout_decoded = is_string( $cart_details['checkout_data'] ) ? json_decode( $cart_details['checkout_data'], true ) : $cart_details['checkout_data'];
			if ( is_array( $checkout_decoded ) && isset( $checkout_decoded['_visitor_fingerprint'] ) ) {
				BWFAN_Common::save_order_meta( $order_id, '_bwfan_visitor_fingerprint', wp_json_encode( $checkout_decoded['_visitor_fingerprint'] ) );
			}
		}

		/**
		 * The order's own cart and, when checkout started a new one, the earlier cart it recovered, each with the
		 * contacts its automation can sit under. Collected before the rows are deleted.
		 */
		$cart_cids = [ absint( $ab_cart_id ) => $this->get_cart_contact_ids( $cart_details, $order ) ];
		if ( $recovered_cart_id > 0 ) {
			$cart_cids[ $recovered_cart_id ] = $recovered_cart_cids;
		}
		foreach ( array_keys( $cart_cids ) as $cart_id ) {
			BWFAN_Model_Abandonedcarts::delete( $cart_id );

			/** Delete v1 automation tasks if present */
			if ( BWFAN_Common::is_automation_v1_active() ) {
				BWFAN_Common::delete_abandoned_cart_tasks( $cart_id );
			}
		}

		/** Maybe remove tags and lists after cart is deleted */
		BWFAN_Common::bwfan_remove_abandoned_cart_tags( $order );

		/** If automation's contact exists, then end the automation */
		foreach ( $cart_cids as $cart_id => $cids ) {
			$this->end_cart_recovered_automation( $cart_id, $cids );
		}
	}

	/**
	 * End the automation a recovered cart was in, unless it waits on a WooCommerce order goal.
	 *
	 * @param int $cart_id - abandoned cart row id
	 * @param int[] $cids - contact ids the cart's automation can sit under
	 */
	protected function end_cart_recovered_automation( $cart_id, $cids ) {
		$result = [];
		foreach ( $cids as $cid ) {
			$result = BWFAN_Model_Automation_Contact::get_automation_contact_by_ab_id( $cart_id, $cid );
			if ( ! empty( $result ) && isset( $result['ID'] ) ) {
				break;
			}
		}
		if ( empty( $result ) || ! isset( $result['ID'] ) ) {
			return;
		}

		/** Add automation ended from */
		$reason = "Automation ended because cart is recovered";
		$reason = [
			'type' => BWFAN_Automation_Controller::$CART_RECOVERED_END,
		];
		$result = BWFAN_Common::set_automation_ended_reason( $reason, $result );

		/** Is wc new order goal in automation */
		$goal_checking = BWFAN_Common::is_wc_order_goal( $result['aid'] );
		if ( $goal_checking ) {
			return;
		}

		/** End automation */
		BWFAN_Common::end_v2_automation( 0, $result );
	}

	/**
	 * Cart an abandoned cart automation was working when the order was placed.
	 *
	 * Usually the order's own cart. When checkout started a new cart, the order's cart was never abandoned and the
	 * automation is still working the earlier cart for the same billing email, so that one is returned instead.
	 *
	 * @param WC_Order $order
	 * @param int $ab_cart_id - the order's own cart row id
	 *
	 * @return array cart row, empty when none is in an automation
	 */
	protected function get_cart_in_automation_for_order( $order, $ab_cart_id ) {
		$cart = BWFAN_Model_Abandonedcarts::get( absint( $ab_cart_id ) );
		if ( is_array( $cart ) && 1 === intval( $cart['status'] ) ) {
			return $cart;
		}

		$billing_email = BWFAN_Woocommerce_Compatibility::get_billing_email( $order );
		$date_created  = $order->get_date_created();
		if ( ! is_email( $billing_email ) || ! $date_created instanceof WC_DateTime ) {
			return [];
		}

		global $wpdb;
		$where = $wpdb->prepare( 'WHERE `status` = 1 AND `email` = %s AND `created_time` <= %s', $billing_email, gmdate( 'Y-m-d H:i:s', $date_created->getTimestamp() ) );
		$carts = BWFAN_Model_Abandonedcarts::get_abandoned_data( $where, 0, 1, 'ID', ARRAY_A );

		return ( is_array( $carts ) && ! empty( $carts ) ) ? $carts[0] : [];
	}

	/**
	 * Contacts the automation for the given cart can sit under.
	 *
	 * The cart row stores no automation id; the automation's contact row does. A cart can sit under the order's
	 * contact, the cart user's or the cart email's (a logged-in cart is stored under the user's, and a logged-in
	 * order's contact follows the account email, not the billing email), so all are returned.
	 *
	 * @param array|null $cart - abandoned cart row, empty when the row is gone
	 * @param WC_Order $order
	 *
	 * @return int[] contact ids, without zeros or duplicates
	 */
	protected function get_cart_contact_ids( $cart, $order ) {
		$cids = [ absint( $order->get_meta( '_woofunnel_cid' ) ) ];
		if ( is_array( $cart ) && ! empty( $cart['user_id'] ) ) {
			$cids[] = absint( bwf_get_contact( absint( $cart['user_id'] ), '' )->get_id() );
		}
		if ( is_array( $cart ) && ! empty( $cart['email'] ) ) {
			$cids[] = absint( bwf_get_contact( 0, $cart['email'] )->get_id() );
		}

		return array_values( array_unique( array_filter( $cids ) ) );
	}

	/**
	 * Get Cart By Multiple Key
	 *
	 * @since 1.0.0
	 */
	public function get_cart_by_multiple_key( $where ) {
		// $where must be a caller-prepared clause (every caller passes a $wpdb->prepare()'d fragment). Never pass raw input here.
		$query        = "SELECT * FROM {table_name} WHERE $where AND status != 2";
		$cart_details = BWFAN_Model_Abandonedcarts::get_results( $query );
		if ( ! is_array( $cart_details ) || 0 === count( $cart_details ) ) {
			return false;
		}

		return $cart_details[0];
	}

	/**
	 * Cart Updated With Cookie
	 *
	 * @since 1.0.0
	 */
	public function cart_updated_with_cookie( $user_login = false, $user = false ) {
		if ( headers_sent() ) {
			return;
		}

		$user = BWFAN_Common::get_user( $user_login, $user );
		if ( empty( $user ) || ! $user instanceof WP_User ) {
			return;
		}

		BWFAN_Common::set_cookie( 'bwfan_do_cart_update', 1 );

		$contact = new WooFunnels_Contact( $user->ID, $user->user_email );
		if ( ! $contact instanceof WooFunnels_Contact || $contact->get_id() < 1 ) {
			return;
		}
		$uid = $contact->get_uid();
		if ( ! empty( $uid ) ) {
			BWFAN_Common::set_cookie( '_fk_contact_uid', $uid, time() + ( 10 * YEAR_IN_SECONDS ) );
		}
	}

	/**
	 * Check For Cart Update Cookie
	 *
	 * @since 1.0.0
	 */
	public function check_for_cart_update_cookie() {
		if ( BWFAN_Common::get_cookie( 'bwfan_do_cart_update' ) ) {
			$this->cart_updated();
			BWFAN_Common::clear_cookie( 'bwfan_do_cart_update' );
		}
	}

	/**
	 * Cart Updated
	 *
	 * @since 1.0.0
	 */
	public function cart_updated() {
		self::$is_cart_changed = true;

		/** Bail when the WC cart is unavailable (e.g. admin/REST/cron context) so is_empty() does not dereference a null cart */
		if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
			return;
		}

		/** When cart is empty, remove abandoned cart record so FKA does not send emails for an empty cart */
		if ( $this->is_empty() ) {
			$this->maybe_delete_abandoned_cart_if_empty();
		}
	}

	/**
	 * Delete the abandoned cart record for the current visitor when their cart is empty.
	 * Stops FKA from sending abandoned cart emails after the user has removed all products.
	 *
	 * @return void
	 */
	public function maybe_delete_abandoned_cart_if_empty() {
		static $already_checked = false;

		// Prevent multiple executions within the same request to reduce database queries.
		if ( $already_checked ) {
			return;
		}
		$already_checked = true;

		// WC check.
		if ( ! class_exists( 'WooCommerce' ) || is_null( WC()->cart ) ) {
			return;
		}

		$tracking_cookie = $this->get_tracking_cookie();
		if ( empty( $tracking_cookie ) ) {
			return;
		}

		// Use lightweight query to get only ID and status instead of full cart data.
		$cart_details = $this->get_cart_id_and_status_by_cookie( $tracking_cookie );
		if ( false === $cart_details || ! isset( $cart_details['ID'] ) ) {
			return;
		}

		// Only remove if cart status is pending (0). Do not remove recovered, lost, or other statuses.
		$cart_status = isset( $cart_details['status'] ) ? (int) $cart_details['status'] : 0;
		if ( 0 !== $cart_status ) {
			return;
		}

		$ab_cart_id = (int) $cart_details['ID'];
		// Delete the abandoned cart row.
		BWFAN_Model_Abandonedcarts::delete( $ab_cart_id );
	}

	/**
	 * Get the tracking cookie value with caching.
	 *
	 * Checks cookie, session, and global fallback in that order.
	 * Results are cached for the current request to avoid repeated lookups.
	 *
	 * @return string|false The tracking cookie value or false if not found.
	 */
	private function get_tracking_cookie() {
		static $cached_cookie = null;

		if ( null !== $cached_cookie ) {
			return $cached_cookie;
		}

		$tracking_cookie = BWFAN_Common::get_cookie( 'bwfan_visitor' );
		if ( empty( $tracking_cookie ) && ! is_null( WC()->session ) ) {
			$tracking_cookie = WC()->session->get( 'bwfan_visitor' );
		}
		if ( empty( $tracking_cookie ) ) {
			global $cookie_set;
			if ( is_array( $cookie_set ) && isset( $cookie_set['bwfan_visitor'] ) ) {
				$tracking_cookie = $cookie_set['bwfan_visitor'];
			}
		}

		$cached_cookie = empty( $tracking_cookie ) ? false : $tracking_cookie;

		return $cached_cookie;
	}

	/**
	 * Get cart ID and status by cookie key using lightweight query.
	 *
	 * This method only selects the necessary columns (ID, status) instead of
	 * all columns to reduce memory usage and query time.
	 *
	 * @param string $cookie_key The cookie key to search for.
	 *
	 * @return array|false Cart data with ID and status, or false if not found.
	 * @since 1.0.0
	 */
	private function get_cart_id_and_status_by_cookie( $cookie_key ) {
		global $wpdb;

		$query = $wpdb->prepare( "SELECT ID, status FROM {table_name} WHERE cookie_key = %s AND status != 2 ORDER BY ID DESC LIMIT 0,1", $cookie_key );

		$cart_details = BWFAN_Model_Abandonedcarts::get_results( $query );

		return ( is_array( $cart_details ) && count( $cart_details ) > 0 ) ? $cart_details[0] : false;
	}

	/**
	 * Trigger Update On Cart And Checkout Pages
	 *
	 * @since 1.0.0
	 */
	public function trigger_update_on_cart_and_checkout_pages() {
		if ( defined( 'WOOCOMMERCE_CART' ) || is_checkout() || did_action( 'woocommerce_before_checkout_form' ) //  support for one page checkout plugins
		) {
			$this->cart_updated();
		}
	}

	/**
	 * Create session cookies for tracking logged in and users
	 */
	public function set_session_cookies() {
		if ( 1 === intval( BWFAN_Common::get_cookie( 'bwfan_session' ) ) || 1 === intval( WC()->session->get( 'bwfan_session' ) ) ) {
			return;
		}

		$token = BWFAN_Common::create_token( 16 );
		BWFAN_Common::set_cookie( 'bwfan_session', 1, time() + DAY_IN_SECONDS * 365 ); // set tracking cookie for 1 year from now
		BWFAN_Common::set_cookie( 'bwfan_visitor', $token, time() + DAY_IN_SECONDS * 365 );

		global $cookie_set;
		$cookie_set = [ 'bwfan_session' => 1, 'bwfan_visitor' => $token ];
		WC()->session->set( 'bwfan_session', 1 );
		WC()->session->set( 'bwfan_visitor', $token );
	}

	/**
	 * Handle Restore Cart
	 *
	 * @since 1.0.0
	 */
	public function handle_restore_cart() {
		if ( ! isset( $_GET['bwfan-ab-id'] ) || empty( $_GET['bwfan-ab-id'] ) || wp_doing_ajax() || is_admin() ) { //phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		add_filter( 'wfacp_skip_add_to_cart', '__return_true', 999 );

		$this->restore_cart();
	}

	/**
	 * Restore cart when users land on site after clicking on abandoned cart link.
	 */
	public function restore_cart() {
		/** in case of getting cart_restore_test from url then add dummy product to cart **/
		if ( isset( $_GET['cart_restore_test'] ) && 'yes' === $_GET['cart_restore_test'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cart_restore_test flag from a recovery test link; only fills the visitor own cart
			$args            = array(
				'posts_per_page' => 2,
				'orderby'        => 'rand',
				'post_type'      => 'product',
				'fields'         => 'ids',
			);
			$random_products = get_posts( $args );

			WC()->cart->empty_cart();

			$this->is_cart_restored = true;
			foreach ( $random_products as $product ) {
				WC()->cart->add_to_cart( $product, 1 );
			}

			$url = wc_get_page_permalink( 'checkout' );
			$url = add_query_arg( array(
				'bwfan-cart-restored' => 'success',
			), $url );

			/** Clear show notices for added products */
			if ( ! is_null( WC()->session ) ) {
				WC()->session->set( 'wc_notices', array() );
			}

			wp_safe_redirect( $url );
			exit;
		}

		$token    = sanitize_text_field( $_GET['bwfan-ab-id'] ); //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$restored = $this->restore_abandoned_cart( $token );

		/** Redirect to shop page if cart restore failed. */
		$shop_url = wc_get_page_permalink( 'shop' );
		$url      = ! empty( $shop_url ) ? $shop_url : home_url();

		if ( false === $restored || 'item_not_added' === $restored ) {
			/**
			 * Restore was refused, so drop the recovery attribution stamped by set_session_for_recovered_cart().
			 * Left in the session it would tag the customer's next unrelated order with
			 * '_bwfan_ab_cart_recovered_a_id', listing it in Recovered carts and crediting its revenue.
			 */
			if ( ! is_null( WC()->session ) ) {
				WC()->session->set( 'bwfan_abandoned_order_data', [] );
			}

			if ( false === $restored ) {
				$global_settings = BWFAN_Common::get_global_settings();
				if ( ! empty( $global_settings['bwfan_ab_restore_cart_message_failure'] ) ) {
					wc_add_notice( $global_settings['bwfan_ab_restore_cart_message_failure'], 'notice' );
				}
			}

			$url = add_query_arg( array(
				'bwfan-cart-restored' => 'fail',
			), $url );
			wp_safe_redirect( $url );
			exit;
		}

		$checkout_data = $this->get_checkout_data( $this->restored_cart_details );
		if ( isset( $checkout_data['aero_data'] ) && ! is_null( WC()->session ) ) {
			foreach ( $checkout_data['aero_data'] as $key => $value ) {
				$value = maybe_unserialize( $value );
				if ( ! is_array( $value ) || 0 === count( $value ) ) {
					continue;
				}
				if ( false !== strpos( $key, 'wfacp_product_data_' ) ) {
					foreach ( $value as $k => $v ) {
						if ( isset( $this->aero_product_data[ $k ] ) ) {
							$value[ $k ]['is_added_cart'] = $this->aero_product_data[ $k ];
						}
					}
				}

				WC()->session->set( $key, $value );
			}
		}

		/** Restore fields data for Gutenberg checkout block */
		if ( ! empty( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) && WC()->customer instanceof WC_Customer ) {
			$data = [];
			foreach ( $checkout_data['fields'] as $key => $value ) {
				$data[ $key ] = $value;
			}
			try {
				WC()->customer->set_props( $data );
			} catch ( Error $e ) {

			}
		}

		$url = wc_get_checkout_url();

		/** If DOB fields exist */
		if ( ! empty( $checkout_data['fields']['dob_fields']['bwfan_birthday_date_yy'] ) && ! empty( $checkout_data['fields']['dob_fields']['bwfan_birthday_date_mm'] ) && ! empty( $checkout_data['fields']['dob_fields']['bwfan_birthday_date_dd'] ) ) {
			WC()->session->set( 'bwfan_birthday_date_yy', $checkout_data['fields']['dob_fields']['bwfan_birthday_date_yy'] );
			WC()->session->set( 'bwfan_birthday_date_dd', $checkout_data['fields']['dob_fields']['bwfan_birthday_date_dd'] );
			WC()->session->set( 'bwfan_birthday_date_mm', $checkout_data['fields']['dob_fields']['bwfan_birthday_date_mm'] );
		}

		do_action( 'bwfan_ab_handle_checkout_data_externally', $checkout_data );

		if ( is_array( $checkout_data ) ) {
			$page_id = isset( $checkout_data['current_page_id'] ) ? intval( $checkout_data['current_page_id'] ) : 0;
			do_action( 'bwfanac_checkout_data', $page_id, $checkout_data, $this->restored_cart_details );

			$is_global_checkout = $checkout_data['aero_data']['wfacp_is_checkout_override'] ?? false;
			if ( 1 === intval( $is_global_checkout ) ) {
				// keep global checkout url
			} elseif ( $page_id > 0 ) {
				$url = get_permalink( $page_id );
			}
		}

		$global_settings = BWFAN_Common::get_global_settings();
		if ( ! empty( $global_settings['bwfan_ab_restore_cart_message_success'] ) ) {
			wc_add_notice( $global_settings['bwfan_ab_restore_cart_message_success'] );
		}

		/** @var array $url_utm_args - passing utm parameter if available in cart recovery link */

		$url_utm_args                        = [];
		$url_utm_args['bwfan-cart-restored'] = 'success';

		/** Preserve bwfan-coupon parameter in redirect URL for auto-application on next page load */
		$bwfan_coupon = filter_input( INPUT_GET, 'bwfan-coupon' );
		if ( ! empty( $bwfan_coupon ) ) {
			$url_utm_args['bwfan-coupon'] = sanitize_text_field( $bwfan_coupon );
		}

		$url_utm_args = apply_filters( 'bwfan_cart_restore_link_args', $url_utm_args );
		$url          = BWFAN_Common::append_extra_url_arguments( $url, $url_utm_args );

		$is_redirect = apply_filters( 'bwfan_after_cart_restored_redirect', false );
		if ( false === $is_redirect ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Whether an order still leaves its abandoned cart recoverable.
	 *
	 * A cart stops being recoverable once its linked order moves past pending/failed/cancelled/trash,
	 * i.e. the purchase went through. Status list kept in sync with BWFAN_AB_Cart_Abandoned::validate_event().
	 *
	 * @param $order - order object or order id linked to the abandoned row
	 *
	 * @return bool true when no order is linked, the order no longer exists, or it is still recoverable
	 */
	private function is_order_cart_recoverable( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = absint( $order ) > 0 ? wc_get_order( absint( $order ) ) : false;
		}

		if ( ! $order instanceof WC_Order ) {
			return true;
		}

		return in_array( $order->get_status(), [ 'pending', 'failed', 'cancelled', 'trash' ], true );
	}

	/**
	 * Restore the cart.
	 *
	 * @param $token
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function restore_abandoned_cart( $token ) {
		$cart_details = $this->get_cart_by_key( 'token', $token, '%s' );
		if ( false === $cart_details ) {
			return false;
		}

		/**
		 * Don't restore a cart whose linked order is already through; prevents the same recovery link
		 * from generating a second order/Payment Intent (#4857).
		 */
		$linked_order = ! empty( $cart_details['order_id'] ) ? wc_get_order( absint( $cart_details['order_id'] ) ) : false;
		if ( ! $this->is_order_cart_recoverable( $linked_order ) ) {
			return false;
		}

		$coupons    = maybe_unserialize( $cart_details['coupons'] );
		$cart_items = maybe_unserialize( $cart_details['items'] );
		WC()->cart->empty_cart();

		if ( ! is_array( $cart_items ) || 0 === count( $cart_items ) ) {
			return false;
		}

		$this->is_cart_restored = true;

		/** Before adding products to cart */
		do_action( 'bwfan_pre_abandoned_cart_restored', $cart_details );

		if ( class_exists( 'WFCH_Public' ) && method_exists( WFCH_Public::get_instance(), 'woocommerce_add_to_cart' ) ) {
			remove_action( 'woocommerce_add_to_cart', array( WFCH_Public::get_instance(), 'woocommerce_add_to_cart' ), 99 );
		}

		$item_added = false;
		foreach ( $cart_items as $item_key => $item_data ) {
			/**
			 * Exclude cart items to restore for devs
			 */
			if ( true === apply_filters( 'bwfan_exclude_cart_items_to_restore', false, $item_key, $item_data ) ) {
				continue;
			}

			/** If product from FK Cart  */
			if ( isset( $item_data['_fkcart_free_gift'] ) && ! empty( $item_data['_fkcart_free_gift'] ) ) {
				$is_freegift = $this->is_product_in_freegift( $item_data['product_id'] );
				if ( false === $is_freegift ) {
					continue;
				}
			}

			/** If product from FK Cart Special Addon */
			if ( ! empty( $item_data['_fkcart_spl_addon'] ) && true === $item_data['_fkcart_spl_addon'] ) {
				continue;
			}

			$product_id     = 0;
			$quantity       = 0;
			$variation_id   = 0;
			$variation_data = [];

			if ( isset( $item_data['product_id'] ) ) {
				$product_id = $item_data['product_id'];
				unset( $item_data['product_id'] );
			}
			if ( isset( $item_data['quantity'] ) ) {
				$quantity = $item_data['quantity'];
				unset( $item_data['quantity'] );
			}
			if ( isset( $item_data['variation_id'] ) ) {
				$variation_id = $item_data['variation_id'];
				unset( $item_data['variation_id'] );
			}
			if ( isset( $item_data['variation'] ) ) {
				$variation_data = $item_data['variation'];
				unset( $item_data['variation'] );
			}

			$item_data = apply_filters( 'bwfan_abandoned_modify_cart_item_data', $item_data );
			try {
				$hash = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation_data, $item_data );
				if ( empty( $hash ) ) {
					/** product item not added to the cart */
					continue;
				}
				$item_added = true;
			} catch ( Error|Exception $e ) {
				BWFAN_Common::log_test_data( array(
					'Error'          => $e->getMessage(),
					'Product ID'     => $product_id,
					'Quantity'       => $quantity,
					'Variation ID'   => $variation_id,
					'Variation Data' => $variation_data,
					'Item Data'      => $item_data
				), 'cart-restore-error', true );
				continue;
			}

			if ( isset( $item_data['_wfacp_product_key'] ) ) {
				$this->aero_product_data[ $item_data['_wfacp_product_key'] ] = $hash;
			}
		}

		if ( false === $item_added ) {
			if ( ! ( isset( WC()->session ) && WC()->session instanceof WC_Session && WC()->session->has_session() ) ) {
				WC()->session->set_customer_session_cookie( true );
			}

			return 'item_not_added';
		}

		/** Restore coupons */
		if ( is_array( $coupons ) && count( $coupons ) > 0 ) {
			$coupons = array_keys( $coupons );
			foreach ( $coupons as $coupon_code ) {
				if ( ! WC()->cart->has_discount( $coupon_code ) ) {
					WC()->cart->add_discount( $coupon_code );
				}
			}
		}

		/** Restore fees */
		if ( isset( $cart_details['fees'] ) && is_array( $cart_details['fees'] ) && count( $cart_details['fees'] ) > 0 ) {
			foreach ( $cart_details['fees'] as $fee ) {
				WC()->cart->add_fee( $fee['name'], $fee['amount'], $fee['taxable'], $fee['tax_class'] );
			}
		}

		/** Clear show notices for added coupons or products */
		if ( ! is_null( WC()->session ) ) {
			WC()->session->set( 'wc_notices', array() );
			WC()->session->set( 'bwfan_session', '' );
			WC()->session->set( 'bwfan_visitor', '' );
		}

		BWFAN_Common::clear_cookie( 'bwfan_visitor' );
		BWFAN_Common::clear_cookie( 'bwfan_session' );

		BWFAN_Common::set_cookie( 'bwfan_visitor', $cart_details['cookie_key'], time() + DAY_IN_SECONDS * 365 );
		WC()->session->set( 'bwfan_visitor', $cart_details['cookie_key'] );
		BWFAN_Common::set_cookie( 'bwfan_session', 1, time() + DAY_IN_SECONDS * 365 ); // set tracking cookie for 1 year from now
		WC()->session->set( 'bwfan_session', 1 );
		BWFAN_Common::set_cookie( 'bwfan_cart_restored', 1, time() + MINUTE_IN_SECONDS * 30 ); // set restored tracking cookie for 30 minutes, this will help in firing cart recovered event

		$uid = sanitize_text_field( filter_input( INPUT_GET, 'bwfan-uid' ) );
		if ( ! empty( $uid ) && ! is_user_logged_in() ) {
			BWFAN_Common::set_cookie( '_fk_contact_uid', $uid, time() + ( 10 * YEAR_IN_SECONDS ) );
		}

		/** If any order_id is found for this abandoned row, then set this order_id in woocommerce session */
		if ( $linked_order instanceof WC_Order && ! is_null( WC()->session ) && 'pending' === $linked_order->get_status() ) {
			WC()->session->set( 'order_awaiting_payment', $linked_order->get_id() );
		}

		$this->restored_cart_details = $cart_details;
		if ( ! is_null( WC()->session ) ) {
			WC()->session->set( 'restored_cart_details', $cart_details );
		}
		if ( isset( $cart_details['email'] ) ) {
			WC()->customer->set_billing_email( $cart_details['email'] );
		}
		/** Apply coupon if available through link - code is in common class auto_apply_wc_coupon() */
		do_action( 'bwfan_abandoned_cart_restored', $cart_details );

		return true;
	}

	/**
	 * Get Checkout Data
	 *
	 * @since 1.0.0
	 */
	public function get_checkout_data( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['checkout_data'] ) ) {
			return array();
		}
		$checkout_data = $data['checkout_data'];
		if ( empty( $checkout_data ) ) {
			return array();
		}

		$checkout_data = json_decode( $checkout_data, true );

		return is_array( $checkout_data ) ? $checkout_data : array();
	}

	/**
	 * Set Session For Recovered Cart
	 *
	 * @since 1.0.0
	 */
	public function set_session_for_recovered_cart() {
		if ( isset( $_GET['bwfan-ab-id'] ) && isset( $_GET['automation-id'] ) && ! is_null( WC()->session ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			$ab_data = array(
				'automation_id' => sanitize_text_field( wp_unslash( $_GET['automation-id'] ) ), //phpcs:ignore WordPress.Security.NonceVerification
			);
			if ( isset( $_GET['track-id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
				$ab_data['track_id'] = sanitize_text_field( wp_unslash( $_GET['track-id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification
			}
			WC()->session->set( 'bwfan_abandoned_order_data', $ab_data );
		}
	}

	/**
	 * Add order_id to abandoned row after checkout is processed.
	 *
	 * @param $order - order object or order id
	 */
	public function attach_order_id_to_abandoned_row( $order ) {
		global $wpdb;

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		/** Cast at the source: the raw POST value used to be saved as order meta and later reach SQL */
		$ab_cart_id = intval( filter_input( INPUT_POST, 'bwfan_cart_id' ) );
		if ( 0 === $ab_cart_id ) {
			$tracking_cookie = BWFAN_Common::get_cookie( 'bwfan_visitor' );
			$tracking_cookie = empty( $tracking_cookie ) ? WC()->session->get( 'bwfan_visitor' ) : $tracking_cookie;
			$billing_email   = BWFAN_Woocommerce_Compatibility::get_billing_email( $order );
			$sql_where       = 'email = %s OR cookie_key = %s';
			$sql_where       = $wpdb->prepare( $sql_where, $billing_email, $tracking_cookie ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$cart_details    = $this->get_cart_by_multiple_key( $sql_where );
			$ab_cart_id      = ( is_array( $cart_details ) && isset( $cart_details['ID'] ) ) ? $cart_details['ID'] : 0;
		}
		if ( empty( $ab_cart_id ) ) {
			return;
		}

		$cart_row = BWFAN_Model_Abandonedcarts::get( $ab_cart_id );
		if ( empty( $cart_row ) ) {
			/** Row was already recovered and deleted. */
			return;
		}

		/**
		 * Row is already linked to a different order that went through and is only waiting on the async
		 * cleanup. Re-pointing it at this order would revive the recovery link and smear attribution.
		 */
		$linked_order_id = ! empty( $cart_row['order_id'] ) ? absint( $cart_row['order_id'] ) : 0;
		if ( $linked_order_id > 0 && $linked_order_id !== $order->get_id() && ! $this->is_order_cart_recoverable( $linked_order_id ) ) {
			return;
		}

		$row_affected = BWFAN_Model_Abandonedcarts::update( [ 'order_id' => $order->get_id() ], [ 'ID' => $ab_cart_id ] );
		if ( empty( $row_affected ) ) {
			/** Row deleted between the fetch above and this update, or this order is already attached (meta written on the earlier call). Either way, don't write a stale cart id. */
			return;
		}

		BWFAN_Common::save_order_meta( $order->get_id(), 'bwfan_cart_id', $ab_cart_id );
	}

	/**
	 * Set order meta to know it was restored from abandoned cart
	 *
	 * @param $order - Order object or order id
	 */
	public function maybe_set_recovered_key( $order ) {
		if ( is_null( WC()->session ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$abandoned_data = WC()->session->get( 'bwfan_abandoned_order_data', 'no' );
		if ( ! is_array( $abandoned_data ) || empty( $abandoned_data ) ) {
			return;
		}

		/**
		 * Only credit the order that actually owns the cart row. attach_order_id_to_abandoned_row() runs
		 * first on this same hook and refuses to re-link a row whose order already went through, so a
		 * second order placed from the same session (double submit, or a re-opened recovery link) finds
		 * the row still pointing at the first order and must not be credited too.
		 */
		$ab_cart_id = absint( filter_input( INPUT_POST, 'bwfan_cart_id' ) );
		if ( 0 === $ab_cart_id ) {
			$restored_details = WC()->session->get( 'restored_cart_details' );
			$ab_cart_id       = ( is_array( $restored_details ) && isset( $restored_details['ID'] ) ) ? absint( $restored_details['ID'] ) : 0;
		}

		if ( $ab_cart_id > 0 ) {
			$cart_row        = BWFAN_Model_Abandonedcarts::get( $ab_cart_id );
			$linked_order_id = ( is_array( $cart_row ) && ! empty( $cart_row['order_id'] ) ) ? absint( $cart_row['order_id'] ) : 0;
			if ( $linked_order_id > 0 && $linked_order_id !== $order->get_id() ) {
				/** Cart already recovered by another order. */
				WC()->session->set( 'bwfan_abandoned_order_data', [] );

				return;
			}
		}

		BWFAN_Common::save_order_meta( $order->get_id(), '_bwfan_ab_cart_recovered_a_id', $abandoned_data['automation_id'] );
		if ( isset( $abandoned_data['track_id'] ) ) {
			BWFAN_Common::save_order_meta( $order->get_id(), '_bwfan_ab_cart_recovered_t_id', $abandoned_data['track_id'] );
		}
		// no need to save as it will be saved after create order hook

		WC()->session->set( 'bwfan_abandoned_order_data', [] );
	}

	/**
	 * Prefill billing fields on checkout page.
	 *
	 * @param $address_fields
	 *
	 * @return mixed
	 */
	public function prefill_billing_fields( $address_fields ) {
		if ( is_null( WC()->session ) ) {
			return $address_fields;
		}
		$data = WC()->session->get( 'restored_cart_details' );
		if ( ! is_array( $data ) || 0 === count( $data ) ) {
			return $address_fields;
		}

		$address_fields['billing_email']['default'] = $data['email'];

		$checkout_data = $this->get_checkout_data( $data );
		if ( is_array( $checkout_data ) && count( $checkout_data ) > 0 && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) ) {
			foreach ( $checkout_data['fields'] as $field_name => $field_value ) {
				if ( false !== strpos( $field_name, 'shipping' ) ) {
					continue;
				}

				if ( ! isset( $address_fields[ $field_name ] ) ) {
					continue;
				}
				$address_fields[ $field_name ]['default'] = $field_value;
			}
		}

		return $address_fields;
	}

	/**
	 * Prefill shipping fields on checkout page.
	 *
	 * @param $address_fields
	 *
	 * @return mixed
	 */
	public function prefill_shipping_fields( $address_fields ) {
		if ( is_null( WC()->session ) ) {
			return $address_fields;
		}
		$data = WC()->session->get( 'restored_cart_details' );

		if ( ! is_array( $data ) || 0 === count( $data ) ) {
			return $address_fields;
		}

		$checkout_data = $this->get_checkout_data( $data );
		if ( is_array( $checkout_data ) && count( $checkout_data ) > 0 && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) ) {
			unset( $checkout_data['current_page_id'] );
			foreach ( $checkout_data['fields'] as $field_name => $field_value ) {
				if ( false !== strpos( $field_name, 'billing' ) ) {
					continue;
				}

				if ( ! isset( $address_fields[ $field_name ] ) ) {
					continue;
				}
				$address_fields[ $field_name ]['default'] = $field_value;
			}
		}

		return $address_fields;
	}

	/**
	 * Hydrate WC()->customer from the restored cart session so the WC Checkout block
	 * picks the values up via wc/store/v1/cart (Store API).
	 *
	 * The block does NOT use woocommerce_billing_fields / woocommerce_shipping_fields
	 * filters; it reads the customer payload that CartController serializes from
	 * WC()->customer. Setting + saving the customer here covers both the initial render
	 * and subsequent Store API pushes within the same session.
	 *
	 * @return void
	 */
	public function block_prefill_customer_from_restored_cart() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! self::is_block_checkout() ) {
			return;
		}
		if ( is_null( WC()->session ) || is_null( WC()->customer ) ) {
			return;
		}

		$data = WC()->session->get( 'restored_cart_details' );
		if ( ! is_array( $data ) || 0 === count( $data ) ) {
			return;
		}

		$checkout_data = $this->get_checkout_data( $data );
		$fields        = ( is_array( $checkout_data ) && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) ) ? $checkout_data['fields'] : array();

		$customer = WC()->customer;
		$keys     = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

		/** Email (billing only on customer object) */
		if ( ! empty( $data['email'] ) && method_exists( $customer, 'set_billing_email' ) ) {
			$customer->set_billing_email( $data['email'] );
		} elseif ( ! empty( $fields['billing_email'] ) && method_exists( $customer, 'set_billing_email' ) ) {
			$customer->set_billing_email( $fields['billing_email'] );
		}

		foreach ( $keys as $key ) {
			$billing_setter  = 'set_billing_' . $key;
			$shipping_setter = 'set_shipping_' . $key;
			$billing_field   = 'billing_' . $key;
			$shipping_field  = 'shipping_' . $key;

			if ( ! empty( $fields[ $billing_field ] ) && method_exists( $customer, $billing_setter ) ) {
				$customer->{$billing_setter}( $fields[ $billing_field ] );
			}
			if ( ! empty( $fields[ $shipping_field ] ) && method_exists( $customer, $shipping_setter ) ) {
				$customer->{$shipping_setter}( $fields[ $shipping_field ] );
			}
		}

		$customer->save();
	}

	/**
	 * Expose the restored payment method slug (if any) so the JS layer can re-select
	 * the matching radio on the block checkout after render. Read by class-bwfan-public.php
	 * when localizing bwfanParamspublic.
	 *
	 * @return string
	 */
	public static function get_restored_payment_method_slug() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return '';
		}

		if ( is_null( WC()->session ) ) {
			return '';
		}
		$data = WC()->session->get( 'restored_cart_details' );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '';
		}
		$instance      = self::get_instance();
		$checkout_data = $instance->get_checkout_data( $data );
		$fields        = ( is_array( $checkout_data ) && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) ) ? $checkout_data['fields'] : array();

		return ! empty( $fields['payment_method'] ) ? sanitize_key( $fields['payment_method'] ) : '';
	}

	/**
	 * Set Data For Js
	 *
	 * @since 1.0.0
	 */
	public function set_data_for_js( $page_id, $checkout_data, $restored_data ) {
		if ( ! is_null( WC()->session ) ) {
			$checkout_data['billing_email'] = $restored_data['email'];
			WC()->session->set( 'bwfan_data_for_js', $checkout_data );
		}
	}

	/**
	 * Remove Data Js
	 *
	 * @since 1.0.0
	 */
	public function remove_data_js( $abandoned_cart_id ) {
		if ( ! is_null( WC()->session ) ) {
			WC()->session->set( 'bwfan_data_for_js', [] );
		}
	}

	/**
	 * Prefill Embed Forms
	 *
	 * @since 1.0.0
	 */
	public function prefill_embed_forms( $field_value, $key ) {
		if ( is_null( WC()->session ) ) {
			return $field_value;
		}
		$restored_data = WC()->session->get( 'bwfan_data_for_js' );

		if ( 'billing_email' === $key && isset( $restored_data['billing_email'] ) && ! empty( $restored_data['billing_email'] ) ) {
			return $restored_data['billing_email'];
		}
		if ( isset( $restored_data['fields'] ) && is_array( $restored_data['fields'] ) && isset( $restored_data['fields'][ $key ] ) ) {
			return $restored_data['fields'][ $key ];
		}

		return $field_value;
	}

	/**
	 * Check Aerocheckout Page
	 *
	 * @since 1.0.0
	 */
	public function check_aerocheckout_page( $bool, $obj ) {
		/** This filter hook run on every AeroCheckout page */
		$this->is_aerocheckout_page = true;
		if ( isset( $_GET['bwfan-cart-restored'] ) && 'success' === sanitize_text_field( wp_unslash( $_GET['bwfan-cart-restored'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link opened from an email; such links cannot carry a nonce
			$bool = true;
		}

		return $bool;
	}

	/**
	 * Woocommerce Add To Cart
	 *
	 * @param string $cart_item_key
	 *
	 * @since 1.0.0
	 */
	public function woocommerce_add_to_cart( $cart_item_key ) {
		if ( empty( $cart_item_key ) ) {
			return;
		}

		// Mark cart updated (and delete abandoned-cart row if the cart is now empty).
		$this->cart_updated();

		$cookie_key    = BWFAN_Common::get_cookie( 'bwfan_visitor' );
		$cookie_uid    = BWFAN_Common::get_cookie( '_fk_contact_uid' );
		$is_cookie_set = ! empty( $cookie_key ) ? $cookie_key : $cookie_uid;

		if ( empty( $is_cookie_set ) ) {
			if ( is_user_logged_in() ) {
				$this->set_session_cookies();
			} else {
				return;
			}
		}
		/** check if the cart item key exists in cart contents before accessing it */
		if ( ! isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}
		/** Set item key and price in wc session because maybe woocommerce_add_to_cart can trigger multiple time */
		$item       = WC()->cart->cart_contents[ $cart_item_key ];
		$line_total = isset( $item['line_total'] ) ? floatval( $item['line_total'] ) : 0;
		$line_tax   = isset( $item['line_tax'] ) ? floatval( $item['line_tax'] ) : 0;

		$item_price   = $line_total + $line_tax;
		$session_data = WC()->session->get( 'bwfan_add_to_cart' );

		/** If same item added into cart with 0 or less price */
		if ( isset( $session_data[ $cart_item_key ] ) && $item_price < $session_data[ $cart_item_key ] ) {
			return;
		}

		$session_data = empty( $session_data ) ? [] : $session_data;

		$session_data[ $cart_item_key ] = $item_price;
		WC()->session->set( 'bwfan_add_to_cart', $session_data );

		$this->added_to_cart = true;
	}

	/**
	 * Unset Session Key
	 *
	 * @since 1.0.0
	 */
	public function unset_session_key() {
		if ( is_null( WC()->session ) ) {
			return;
		}
		WC()->session->set( 'bwfan_generated_cart_session', false );
	}

	/**
	 * Update Items In Abandoned Table
	 *
	 * @since 1.0.0
	 */
	public function update_items_in_abandoned_table( $wfacp_id, $request ) {
		$tracking_cookie = BWFAN_Common::get_cookie( 'bwfan_visitor' );
		$tracking_cookie = empty( $tracking_cookie ) ? WC()->session->get( 'bwfan_visitor' ) : $tracking_cookie;
		$cart_details    = $this->get_cart_by_key( 'cookie_key', $tracking_cookie, '%s' ); // check cart by cookie

		if ( false === $cart_details || ! is_array( $cart_details ) || empty( $cart_details ) ) {
			return;
		}
		$this->update_abandoned_cart( $cart_details );
	}

	/**
	 * Single source of truth for abandoned-cart capture exclusion.
	 *
	 * Returns true when the cart should NOT be tracked because either the email
	 * matches an excluded pattern, or the owning user has an excluded role. The
	 * target user is resolved from the logged-in session, the account owning the
	 * given email, or the passed user id — so exclusion works for guest/incognito
	 * checkouts and server-side capture paths, not just logged-in sessions.
	 *
	 * @param string $email   Email associated with the cart (may be empty).
	 * @param int    $user_id WP user id associated with the cart (may be 0).
	 *
	 * @return bool
	 */
	public static function is_capture_excluded( $email = '', $user_id = 0 ) {
		$global_settings = BWFAN_Common::get_global_settings();

		/** Excluded email patterns */
		if ( ! empty( $email ) && isset( $global_settings['bwfan_ab_exclude_emails'] ) && ! empty( $global_settings['bwfan_ab_exclude_emails'] ) ) {
			/** Normalize line breaks to commas and explode */
			$exclude_emails = preg_split( '/[\r\n,]+/', $global_settings['bwfan_ab_exclude_emails'], - 1, PREG_SPLIT_NO_EMPTY );
			$exclude_emails = array_map( 'trim', $exclude_emails );
			$exclude_emails = array_unique( $exclude_emails );

			if ( self::get_instance()->email_exists_in_patterns( $email, $exclude_emails ) ) {
				return true;
			}
		}

		/** Excluded user roles */
		if ( isset( $global_settings['bwfan_ab_exclude_users_cart_tracking'] ) && 0 !== absint( $global_settings['bwfan_ab_exclude_users_cart_tracking'] ) && ! empty( $global_settings['bwfan_ab_exclude_roles'] ) ) {
			/** Resolve the target user from session, the email's owning account, or the passed user id */
			$target_user = null;
			if ( is_user_logged_in() ) {
				$target_user = wp_get_current_user();
			} elseif ( ! empty( $email ) ) {
				/**
				 * Resolve roles from the account owning the submitted email so guest/incognito
				 * checkouts using an excluded-role account (e.g. staff) are still excluded.
				 *
				 * Known tradeoff: a visitor could suppress their own cart tracking by entering
				 * an excluded-role user's email. Accepted — impact is limited to skipping a
				 * marketing capture, and the excluded-roles feature is opt-in.
				 */
				$wp_user = get_user_by( 'email', $email );
				if ( $wp_user instanceof WP_User ) {
					$target_user = $wp_user;
				}
			}
			if ( ! ( $target_user instanceof WP_User ) && ! empty( $user_id ) ) {
				$wp_user = get_user_by( 'id', $user_id );
				if ( $wp_user instanceof WP_User ) {
					$target_user = $wp_user;
				}
			}

			if ( $target_user instanceof WP_User ) {
				$exclude_roles = array_intersect( (array) $target_user->roles, (array) $global_settings['bwfan_ab_exclude_roles'] );
				if ( ! empty( $exclude_roles ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sanitize a single checkout field value, rejecting PHP-serialized strings so a
	 * serialized object payload can never be stored and later unserialized (object injection).
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public static function sanitize_checkout_field( $value ) {
		/** Sanitize first: tag/octet stripping could otherwise rebuild a serialized string (O<x>:8:... becomes O:8:...). */
		$value = sanitize_text_field( $value );

		return is_serialized( $value ) ? '' : $value;
	}

	/**
	 * Insert Abandoned Cart
	 *
	 * @since 1.0.0
	 */
	public function insert_abandoned_cart() {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
		if ( ! wp_verify_nonce( $nonce, 'bwfan-action-public' ) ) {
			wp_send_json( array( 'status' => false, 'msg' => __( 'Invalid request, security validation failed.', 'wp-marketing-automations' ) ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( empty( $email ) ) {
			wp_send_json( array(
				'success' => false,
			) );
		}

		if ( true === $this->is_empty() ) {
			wp_send_json( array(
				'success' => false,
				'id'      => 0,
				'message' => esc_html__( 'Cart is empty', 'wp-marketing-automations' ),
			) );
		}

		global $cookie_set;
		$cookie_set = false;
		$this->set_session_cookies();

		/** Check excluded emails or user roles */
		if ( self::is_capture_excluded( $email ) ) {
			wp_send_json( array(
				'success' => false,
			) );
		}

		/** Validate email structure and domain */
		if ( ! $this->validate_cart_email( $email ) ) {
			wp_send_json( array(
				'success' => false,
			) );
		}

		$exclude_checkout_fields = apply_filters( 'bwfan_ab_exclude_checkout_fields', array() );
		$data                    = [
			'fields'               => isset( $_POST['checkout_fields_data'] ) && is_array( $_POST['checkout_fields_data'] ) ? array_map( array( __CLASS__, 'sanitize_checkout_field' ), wp_unslash( $_POST['checkout_fields_data'] ) ) : [], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitised by sanitize_checkout_field()
			//phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'current_page_id'      => isset( $_POST['current_page_id'] ) ? sanitize_text_field( $_POST['current_page_id'] ) : '',
			//phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'aerocheckout_page_id' => isset( $_POST['aerocheckout_page_id'] ) ? sanitize_text_field( $_POST['aerocheckout_page_id'] ) : '',
			//phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'last_edit_field'      => isset( $_POST['last_edit_field'] ) ? sanitize_text_field( $_POST['last_edit_field'] ) : '',
			//phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'current_step'         => isset( $_POST['current_step'] ) ? sanitize_text_field( $_POST['current_step'] ) : '',
			//phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		];
		if ( isset( $_POST['pushengage_token'] ) && ! empty( $_POST['pushengage_token'] ) ) {
			$data['pushengage_token'] = sanitize_text_field( $_POST['pushengage_token'] ); //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}

		if ( isset( $data['fields']['bwfan_cart_id'] ) && intval( $data['fields']['bwfan_cart_id'] ) > 0 ) {
			$data['bwfan_cart_id'] = intval( $data['fields']['bwfan_cart_id'] );
			unset( $data['fields']['bwfan_cart_id'] );
		}

		if ( ! empty( $data['fields']['billing_phone'] ) ) {
			$country = $data['fields']['billing_country'] ?? '';
			/** Filter to use shipping country instead of billing country for billing phone country code. */
			if ( apply_filters( 'bwfan_checkout_pick_shipping_country', false ) ) {
				$country = $data['fields']['shipping_country'] ?? $country;
			}
			if ( ! empty( $country ) ) {
				$data['fields']['billing_phone'] = BWFAN_Phone_Numbers::add_country_code( $data['fields']['billing_phone'], $country );
			}
		}

		if ( ! empty( $data['fields']['shipping_phone'] ) ) {
			$country = $data['fields']['shipping_country'] ?? '';
			if ( ! empty( $country ) ) {
				$data['fields']['shipping_phone'] = BWFAN_Phone_Numbers::add_country_code( $data['fields']['shipping_phone'], $country );
			}
		}

		if ( ! empty( $exclude_checkout_fields ) ) {
			foreach ( $exclude_checkout_fields as $field ) {
				unset( $data['fields'][ $field ] );
			}
		}

		/** Remove empty fields */
		$data['fields'] = array_filter( $data['fields'] );
		$dob_fields     = [];
		if ( ! empty( $data['fields']['bwfan_birthday_date_yy'] ) && ! empty( $data['fields']['bwfan_birthday_date_mm'] ) && ! empty( $data['fields']['bwfan_birthday_date_dd'] ) && checkdate( intval( $data['fields']['bwfan_birthday_date_mm'] ), intval( $data['fields']['bwfan_birthday_date_dd'] ), intval( $data['fields']['bwfan_birthday_date_yy'] ) ) ) {
			$dob_fields = [
				'dob_fields' => [
					'bwfan_birthday_date_yy' => $data['fields']['bwfan_birthday_date_yy'],
					'bwfan_birthday_date_dd' => $data['fields']['bwfan_birthday_date_dd'],
					'bwfan_birthday_date_mm' => $data['fields']['bwfan_birthday_date_mm'],
				],
			];
		}
		$data['fields'] = array_intersect_key( $data['fields'], self::get_woocommerce_default_checkout_nice_names() );
		$data['fields'] = array_merge( $data['fields'], $dob_fields );

		/** Consent checkbox not rendered (setting off / contact already subscribed): treat as granted, same as the hidden input on classic checkout. Block checkout posts nothing in that case. */
		if ( ! isset( $data['fields']['bwfan_user_consent'] ) && ! in_array( 'bwfan_user_consent', (array) $exclude_checkout_fields, true ) && BWFAN_Common::is_marketing_consent_implicit() ) {
			$data['fields']['bwfan_user_consent'] = '1';
		}

		/**
		 * Set AeroCheckout session keys
		 */
		if ( class_exists( 'WFACP_Common' ) && ! is_null( WC()->session ) ) {
			$aero_id              = WFACP_Common::get_id();
			$aero_hash            = WC()->session->get( 'wfacp_cart_hash' );
			$aero_product_objects = WC()->session->get( 'wfacp_product_objects_' . $aero_id );
			$aero_product_data    = WC()->session->get( 'wfacp_product_data_' . $aero_id );
			$checkout_override    = WFACP_Core()->public->is_checkout_override();
			$data['aero_data']    = array(
				'wfacp_id'                          => maybe_serialize( $aero_id ),
				'wfacp_cart_hash'                   => maybe_serialize( $aero_hash ),
				'wfacp_product_objects_' . $aero_id => maybe_serialize( $aero_product_objects ),
				'wfacp_product_data_' . $aero_id    => maybe_serialize( $aero_product_data ),
				'wfacp_is_checkout_override'        => $checkout_override,
			);
		}

		$data['fields']['timezone'] = isset( $_POST['timezone'] ) ? sanitize_text_field( wp_unslash( $_POST['timezone'] ) ) : '';
		$data                       = apply_filters( 'bwfan_ab_change_checkout_data_for_external_use', array_filter( $data ) );

		$lang = self::get_current_language_code();
		if ( null !== $lang ) {
			$data['lang'] = $lang;
		}

		$abandoned_cart_id = $this->process_abandoned_cart( $email, $data );

		if ( 0 === absint( $abandoned_cart_id ) ) {
			wp_send_json( array(
				'success'                                    => false,
				'id'                                         => 0,
				/* translators: 1: Dynamic Data */ 'message' => esc_html( sprintf( __( 'Unable to create cart for this email `%1$s`.', 'wp-marketing-automations' ), $email ) ),
			) );
		}

		do_action( 'bwfan_insert_abandoned_cart', $abandoned_cart_id );

		/** Allow compatibility layers to restore cookies before AJAX response is sent */
		do_action( 'bwfan_before_abandoned_cart_ajax_response' );

		$resp = array(
			'id'     => $abandoned_cart_id,
			'status' => true,
		);

		wp_send_json( $resp );
	}

	/**
	 * Validate email for abandoned cart capture.
	 *
	 * Runs is_email() check and optional DNS/MX domain validation.
	 *
	 * @param string $email
	 *
	 * @return bool True if email is valid, false otherwise.
	 */
	public function validate_cart_email( $email ) {
		if ( ! is_email( $email ) ) {
			return false;
		}

		$validate_domain = apply_filters( 'bwfan_ab_validate_email_domain', true );

		if ( ! $validate_domain ) {
			return true;
		}

		$domain = substr( $email, strrpos( $email, '@' ) + 1 );
		if ( empty( $domain ) ) {
			return false;
		}

		/** Check transient cache for domain validation result */
		$cache_key = 'bwfan_mx_' . md5( $domain );
		$cached    = get_transient( $cache_key );

		if ( 'valid' === $cached ) {
			return true;
		}
		if ( 'invalid' === $cached ) {
			return false;
		}

		/** Pick the best available DNS facility; fail open if neither is callable. */
		if ( function_exists( 'checkdnsrr' ) ) {
			$has_mx = checkdnsrr( $domain, 'MX' );
			$has_a  = $has_mx ? true : checkdnsrr( $domain, 'A' );
		} elseif ( function_exists( 'dns_get_record' ) && defined( 'DNS_MX' ) && defined( 'DNS_A' ) ) {
			$has_mx = ! empty( dns_get_record( $domain, DNS_MX ) );
			$has_a  = $has_mx ? true : ! empty( dns_get_record( $domain, DNS_A ) );
		} else {
			/** No DNS facility available — return true to avoid blocking valid emails */
			return true;
		}

		if ( $has_mx || $has_a ) {
			set_transient( $cache_key, 'valid', DAY_IN_SECONDS );

			return true;
		}

		set_transient( $cache_key, 'invalid', HOUR_IN_SECONDS );

		return false;
	}

	/**
	 * Email Exists In Patterns
	 *
	 * @since 1.0.0
	 */
	public function email_exists_in_patterns( $email, $email_patterns ) {
		foreach ( $email_patterns as $pattern ) {
			if ( false !== strpos( strtolower( $email ), strtolower( trim( $pattern ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function get_woocommerce_default_checkout_nice_names() {
		return apply_filters( 'bwfan_ab_default_checkout_nice_names', array(
			'billing_first_name' => __( 'First Name', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_last_name'  => __( 'Last Name', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_company'    => __( 'Company', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_address_1'  => __( 'Address 1', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_address_2'  => __( 'Address 2', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_city'       => __( 'City', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_postcode'   => __( 'Postal/Zip Code', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_state'      => __( 'State', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_country'    => __( 'Country', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_phone'      => __( 'Phone Number', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'billing_email'      => __( 'Email Address', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

			'shipping_first_name' => __( 'First Name', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_last_name'  => __( 'Last Name', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_company'    => __( 'Company', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_address_1'  => __( 'Address 1', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_address_2'  => __( 'Address 2', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_city'       => __( 'City', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_postcode'   => __( 'Postal/Zip Code', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_state'      => __( 'State', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_country'    => __( 'Country', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			'shipping_phone'      => __( 'Phone Number', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

			'last_edit_field'           => __( 'Checkout Form Last Edit Field', 'wp-marketing-automations' ),
			'current_step'              => __( 'Checkout Form Current Step', 'wp-marketing-automations' ),
			'aerocheckout_page_id'      => __( 'Checkout Page ID', 'wp-marketing-automations' ),
			'current_page_id'           => __( 'WordPress Page ID', 'wp-marketing-automations' ),
			'wfacp_source'              => __( 'Checkout Page Source', 'wp-marketing-automations' ),
			'payment_method'            => __( 'Payment Method', 'wp-marketing-automations' ),
			'ship_to_different_address' => __( 'Ship to a different address', 'wp-marketing-automations' ),
			'shipping_same_as_billing'  => __( 'Shipping same as billing address', 'wp-marketing-automations' ),
			'billing_same_as_shipping'  => __( 'Billing same as shipping address', 'wp-marketing-automations' ),
			'bwfan_user_consent'        => __( 'Marketing Consent', 'wp-marketing-automations' ),
		) );
	}

	/**
	 * Return cart ID or INT 0
	 *
	 * @param $email
	 * @param $checkout_fields_data
	 *
	 * @return int
	 */
	public function process_abandoned_cart( $email, $checkout_fields_data ) {
		if ( '1' === BWFAN_Common::get_cookie( 'bwfan_session' ) || 1 === intval( WC()->session->get( 'bwfan_session' ) ) ) {
			return $this->process_guest_cart_details( $email, $checkout_fields_data );
		}

		global $cookie_set;
		if ( is_array( $cookie_set ) && isset( $cookie_set['bwfan_session'] ) ) {
			return $this->process_guest_cart_details( $email, $checkout_fields_data );
		}

		return 0;
	}

	/**
	 * Process the abandoned cart for guest users
	 *
	 * @param null $email
	 *
	 * @return int|mixed
	 */
	public function process_guest_cart_details( $email = null, $checkout_fields_data = null ) {
		$data = [];

		/** Attach visitor fingerprint inline — avoids extra SELECT + UPDATE via hooks */
		if ( is_array( $checkout_fields_data ) ) {
			$fingerprint = BWFAN_Common::get_visitor_fingerprint();
			if ( ! empty( $fingerprint ) ) {
				$checkout_fields_data['_visitor_fingerprint'] = $fingerprint;
			}
		}

		if ( is_array( $checkout_fields_data ) && isset( $checkout_fields_data['bwfan_cart_id'] ) && intval( $checkout_fields_data['bwfan_cart_id'] ) > 0 ) {
			/** Cart ID found */
			$cart_details = BWFAN_Model_Abandonedcarts::get( $checkout_fields_data['bwfan_cart_id'] );
			if ( isset( $cart_details['ID'] ) && intval( $cart_details['ID'] ) === intval( $checkout_fields_data['bwfan_cart_id'] ) ) {
				$cart_details['email'] = ( ! is_null( $email ) ) ? $email : $cart_details['email'];
				if ( is_array( $checkout_fields_data ) && count( $checkout_fields_data ) > 0 ) {
					$cart_details['checkout_data'] = $checkout_fields_data;
				}

				$this->update_abandoned_cart( $cart_details );

				return intval( $cart_details['ID'] );
			}
		}

		/** First time cart */
		$tracking_cookie = BWFAN_Common::get_cookie( 'bwfan_visitor' );
		$tracking_cookie = empty( $tracking_cookie ) ? WC()->session->get( 'bwfan_visitor' ) : $tracking_cookie;
		if ( empty( $tracking_cookie ) ) {
			global $cookie_set;
			if ( is_array( $cookie_set ) && isset( $cookie_set['bwfan_visitor'] ) ) {
				$tracking_cookie = $cookie_set['bwfan_visitor'];
			}
		}

		$cart_details = $this->get_cart_by_key( 'cookie_key', $tracking_cookie, '%s' ); // check cart by cookie
		if ( ! empty( $email ) ) {
			$data['email'] = $email;

			/** check cart by guest email id */
			if ( false === $cart_details ) {
				global $wpdb;
				$sql_where    = 'email = %s';
				$sql_where    = $wpdb->prepare( $sql_where, $email ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cart_details = $this->get_cart_by_multiple_key( $sql_where );
				if ( is_array( $cart_details ) && count( $cart_details ) > 0 ) {
					$cart_details['cookie_key'] = $tracking_cookie;
				} else {
					$cart_details = false;
				}
			}
		}

		/** There is no abandoned cart row for this user, create a new row and insert in table */
		if ( false === $cart_details && false === $this->is_empty() ) {
			$data['cookie_key'] = $tracking_cookie;
			if ( is_array( $checkout_fields_data ) && count( $checkout_fields_data ) > 0 ) {
				$data['checkout_data']    = $checkout_fields_data;
				$data['checkout_page_id'] = $checkout_fields_data['current_page_id'] ?? 0;
			}

			if ( ! apply_filters( 'execute_cart_abandonment_for_email', true, $email, $cart_details ) ) {
				return 0;
			}

			return $this->create_abandoned_cart( $data );
		}

		/** This won't run in most cases */
		$cart_details['email'] = ( ! empty( $email ) ) ? $email : $cart_details['email'];
		if ( is_array( $checkout_fields_data ) && count( $checkout_fields_data ) > 0 ) {
			$cart_details['checkout_data'] = $checkout_fields_data;
		}

		$this->update_abandoned_cart( $cart_details );

		return intval( $cart_details['ID'] );
	}

	/**
	 * Get cart by any column
	 *
	 * @param $key
	 * @param $value
	 * @param $value_type
	 *
	 * @return false|mixed|stdClass
	 */
	public function get_cart_by_key( $key, $value, $value_type ) {
		global $wpdb;
		$column     = '`' . str_replace( '`', '``', $key ) . '`';
		$value_type = in_array( $value_type, array( '%s', '%d', '%f' ), true ) ? $value_type : '%s';
		$query        = $wpdb->prepare( "SELECT * FROM {table_name} WHERE {$column} = {$value_type} AND status != 2 ORDER BY `ID` DESC LIMIT 0,1", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- value type whitelisted; column quoted; value bound via prepare()
		$cart_details = BWFAN_Model_Abandonedcarts::get_results( $query );

		return ( is_array( $cart_details ) && count( $cart_details ) > 0 ) ? $cart_details[0] : false;
	}

	/**
	 * Check if user has some items in cart
	 *
	 * @return bool
	 */
	public function is_empty() {
		return 0 === sizeof( WC()->cart->get_cart() );
	}

	/**
	 * Create a new abandoned cart row
	 *
	 * @param $details
	 *
	 * @return int
	 */
	public function create_abandoned_cart( $details ) {
		$data          = $this->get_current_cart_details();
		$data['email'] = $details['email'];
		if ( isset( $details['checkout_page_id'] ) ) {
			$data['checkout_page_id'] = $details['checkout_page_id'];
		}
		$data['status']  = 0;
		$data['user_id'] = ( isset( $details['user_id'] ) && 0 !== $details['user_id'] ) ? $details['user_id'] : 0;
		if ( empty( $data['user_id'] ) && is_user_logged_in() ) {
			$user            = wp_get_current_user();
			$data['user_id'] = ( isset( $user->ID ) ? (int) $user->ID : 0 );
			$data['email']   = empty( $data['email'] ) ? $user->user_email : $data['email'];
		}

		/** If email is empty, return 0 */
		if ( empty( $data['email'] ) ) {
			return 0;
		}
		$data['created_time']  = current_time( 'mysql', 1 );
		$data['last_modified'] = current_time( 'mysql', 1 );
		$data['token']         = BWFAN_Common::create_token( 32 );
		$data['cookie_key']    = ( isset( $details['cookie_key'] ) ) ? $details['cookie_key'] : '';

		if ( isset( $details['checkout_data'] ) && is_array( $details['checkout_data'] ) && count( $details['checkout_data'] ) > 0 ) {
			$data['checkout_data'] = wp_json_encode( $details['checkout_data'] );
		}

		$checkout_id = $details['checkout_data']['aerocheckout_page_id'] ?? false;
		if ( intval( $checkout_id ) > 0 ) {
			$data['checkout_page_id'] = intval( $checkout_id );
		}

		BWFAN_Model_Abandonedcarts::insert( $data );

		return BWFAN_Model_Abandonedcarts::insert_id();
	}

	/**
	 * Get Current Cart Details
	 *
	 * @since 1.0.0
	 */
	public function get_current_cart_details() {
		$data        = [];
		$coupon_data = [];
		$this->items = apply_filters( 'bwfan_abandoned_cart_items', WC()->cart->get_cart() );

		foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
			$coupon_data[ $coupon_code ] = [
				'discount_incl_tax' => WC()->cart->get_coupon_discount_amount( $coupon_code, false ),
				'discount_excl_tax' => WC()->cart->get_coupon_discount_amount( $coupon_code ),
				'discount_tax'      => WC()->cart->get_coupon_discount_tax_amount( $coupon_code ),
			];
		}

		$this->coupon_data = $coupon_data;

		$fee = '';
		// Check for fees data in the cart
		if ( WC()->cart->get_fee_total() ) {
			WC()->cart->calculate_fees();
			WC()->cart->calculate_totals();
			$fee = WC()->cart->get_fees();
		}
		$data['items']              = maybe_serialize( $this->items );
		$data['coupons']            = empty( $coupon_data ) ? '' : maybe_serialize( $coupon_data );
		$data['fees']               = empty( $fee ) ? '' : maybe_serialize( $fee );
		$data['shipping_tax_total'] = WC()->cart->shipping_tax_total;
		$data['shipping_total']     = WC()->cart->shipping_total;
		$data['currency']           = get_woocommerce_currency();
		$total                      = WC()->cart->get_total( 'raw' );
		$data['total']              = $total;
		$data['total_base']         = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $total, $data['currency'] );

		return $data;
	}

	/**
	 * Update the abandoned cart details in db table
	 *
	 * @param $old_cart_details
	 */
	public function update_abandoned_cart( $old_cart_details ) {
		$data                  = $this->get_current_cart_details();
		$data['email']         = $old_cart_details['email'];
		$data['user_id']       = $old_cart_details['user_id'];
		$data['status']        = isset( $old_cart_details['status'] ) ? $old_cart_details['status'] : 0;
		$data['cookie_key']    = ( isset( $old_cart_details['cookie_key'] ) ) ? $old_cart_details['cookie_key'] : '';
		$data['last_modified'] = current_time( 'mysql', 1 );

		if ( isset( $old_cart_details['checkout_data'] ) && is_array( $old_cart_details['checkout_data'] ) && count( $old_cart_details['checkout_data'] ) > 0 ) {
			$data['checkout_data'] = wp_json_encode( $old_cart_details['checkout_data'] );
		}

		$checkout_id = $old_cart_details['checkout_data']['aerocheckout_page_id'] ?? false;
		if ( intval( $checkout_id ) > 0 ) {
			$data['checkout_page_id'] = intval( $checkout_id );
		}

		$where = array(
			'ID' => $old_cart_details['ID'],
		);

		BWFAN_Model_Abandonedcarts::update( $data, $where );
	}

	/**
	 * Delete Abandoned Cart
	 *
	 * @since 1.0.0
	 */
	public function delete_abandoned_cart() {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
		if ( ! wp_verify_nonce( $nonce, 'bwfan-action-public' ) ) {
			wp_send_json( array( 'status' => false, 'msg' => __( 'Invalid request, security validation failed.', 'wp-marketing-automations' ) ) );
		}

		if ( isset( $_POST['email'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			$email = sanitize_email( $_POST['email'] ); //phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			if ( ! empty( $email ) ) {
				$where = array(
					'email' => $email,
				);
				BWFAN_Model_Abandonedcarts::delete_abandoned_cart_row( $where );
			}
		}

		wp_die();
	}

	/**
	 * Wfacp Assign Country
	 *
	 * @since 1.0.0
	 */
	public function wfacp_assign_country( $country, $input ) {
		if ( is_null( WC()->session ) ) {
			return $country;
		}
		$restored_data = WC()->session->get( 'bwfan_data_for_js' );
		if ( empty( $restored_data ) ) {
			return $country;
		}
		if ( isset( $restored_data['fields'] ) && is_array( $restored_data['fields'] ) && isset( $restored_data['fields'][ $input ] ) ) {
			$country = $restored_data['fields'][ $input ];
		}

		return $country;
	}

	/**
	 * @param $contact_email
	 *
	 * @return array|object|null
	 */
	public function get_contact_recovered_carts( $contact_email ) {
		if ( ! is_email( $contact_email ) ) {
			return array();
		}
		global $wpdb;
		$where         = 'AND m1.meta_key = "_billing_email"';
		$where         .= ' AND m1.meta_value = %s';
		$post_statuses = apply_filters( 'bwfan_recovered_cart_excluded_statuses', array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'trash', 'draft' ) );
		/** Trailing '' keeps NOT IN () valid when the filter returns an empty list */
		$post_statuses  = array_merge( array_map( 'strval', (array) $post_statuses ), array( '' ) );
		$status_holders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
		$query_args     = array_merge( array( '_bwfan_ab_cart_recovered_a_id', 'shop_order' ), $post_statuses, array( $contact_email ) );
		$prepare_query       = $wpdb->prepare( "SELECT p.ID FROM {$wpdb->prefix}posts p, {$wpdb->prefix}postmeta m1, {$wpdb->prefix}postmeta m2 WHERE p.ID = m1.post_id and p.ID = m2.post_id AND m2.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ($status_holders) $where ORDER BY p.post_modified DESC", ...$query_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders generated; filter values bound via prepare(); counts match
		$recovered_carts_ids = $wpdb->get_results( $prepare_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders generated; filter values bound via prepare(); counts match

		if ( empty( $recovered_carts_ids ) ) {
			return array();
		}

		return $recovered_carts_ids;
	}

	/**
	 * @param $contact_email
	 *
	 * @return int[]
	 */
	public function get_last_abandoned_cart( $contact_email ) {
		$abandoned_data = array(
			'items_count'   => 0,
			'last_modified' => 0,
			'total'         => 0,
		);
		if ( ! is_email( $contact_email ) ) {
			return $abandoned_data;
		}

		global $wpdb;
		$where                   = $wpdb->prepare( "WHERE status IN (1,3,4) and email = %s", $contact_email );
		$contact_abandoned_carts = BWFAN_Model_Automations::get_last_abandoned_cart( $where );
		if ( empty( $contact_abandoned_carts ) ) {
			return $abandoned_data;
		}
		$cart_items = maybe_unserialize( $contact_abandoned_carts[0]['items'] );

		$abandoned_data['items_count']   = is_array( $cart_items ) ? count( $cart_items ) : 0;
		$abandoned_data['last_modified'] = isset( $contact_abandoned_carts[0]['last_modified'] ) ? $contact_abandoned_carts[0]['last_modified'] : '';
		$abandoned_data['total']         = isset( $contact_abandoned_carts[0]['total'] ) ? $contact_abandoned_carts[0]['total'] : 0;

		return $abandoned_data;
	}

	/**
	 * Remove session
	 *
	 * @param $cart_item_key
	 *
	 * @return void
	 */
	public function remove_session( $cart_item_key ) {
		$user    = wp_get_current_user();
		$user_id = $user instanceof WP_User ? $user->ID : 0;
		if ( empty( $user_id ) || is_null( WC()->session ) ) {
			return;
		}
		$session_data = WC()->session->get( 'bwfan_add_to_cart' );
		if ( empty( $session_data ) || ! isset( $session_data[ $cart_item_key ] ) ) {
			return;
		}
		unset( $session_data[ $cart_item_key ] );
		WC()->session->set( 'bwfan_add_to_cart', $session_data );
	}

	/**
	 * Is product in FK cart free gifts
	 *
	 * @param $product_id
	 *
	 * @return bool
	 */
	public function is_product_in_freegift( $product_id ) {
		if ( ! class_exists( 'FKCart\Includes\Data' ) ) {
			return false;
		}
		$settings = FKCart\Includes\Data::get_settings();
		if ( empty( $settings['enable_cart'] ) ) {
			return false;
		}
		$rewards = FKCart\Includes\Data::get_rewards();

		if ( empty( $rewards ) || ! isset( $rewards['rewards'] ) || ! is_array( $rewards['rewards'] ) ) {
			return false;
		}

		$free_gifts = array_filter( $rewards['rewards'], function ( $reward ) {
			return ( isset( $reward['type'] ) && 'freegift' === $reward['type'] );
		} );

		$free_products = [];
		foreach ( $free_gifts as $free_gift ) {
			if ( ! isset( $free_gift['freeProduct'] ) ) {
				continue;
			}
			$free_products = array_merge( $free_products, array_column( $free_gift['freeProduct'], 'key' ) );
		}

		return in_array( $product_id, $free_products, true );
	}

	/**
	 * @return void
	 */
	public function add_to_cart() {
		if ( is_null( $this->added_to_cart ) ) {
			return;
		}

		try {
			$coupon_data = [];
			foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
				$coupon_data[ $coupon_code ] = [
					'discount_incl_tax' => WC()->cart->get_coupon_discount_amount( $coupon_code, false ),
					'discount_excl_tax' => WC()->cart->get_coupon_discount_amount( $coupon_code ),
					'discount_tax'      => WC()->cart->get_coupon_discount_tax_amount( $coupon_code ),
				];
			}

			global $cookie_set;
			$cookie_set = false;
			$this->set_session_cookies();
			$tracking_cookie = BWFAN_Common::get_cookie( 'bwfan_visitor' );
			$tracking_cookie = empty( $tracking_cookie ) ? WC()->session->get( 'bwfan_visitor' ) : $tracking_cookie;

			/** WC has already saved the session on shutdown, persist the keys set above */
			if ( is_callable( [ WC()->session, 'save_data' ] ) ) {
				WC()->session->save_data();
			}

			self::wc_add_to_cart( array(
				'id'            => get_current_user_id(),
				'coupon_data'   => $coupon_data,
				'items'         => WC()->cart->get_cart(),
				'fees'          => WC()->cart->get_fees(),
				'bwfan_visitor' => $tracking_cookie,
				'fk_uid'        => BWFAN_Common::get_cookie( '_fk_contact_uid' ),
			) );
		} catch ( Throwable $e ) {
			BWFAN_Common::log_test_data( 'Add to cart capture failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'fka-add-to-cart-capture-failed', true );
		}
	}

	/**
	 * Capture / update an abandoned cart row from the WC add-to-cart hook.
	 *
	 * Called synchronously from add_to_cart(). The previous REST loopback
	 * (autonami/v1/wc-add-to-cart) has been removed; data now flows directly
	 * from WC()->cart, eliminating the unauthenticated entry point that
	 * allowed PHP object injection via the items/coupons/fees columns.
	 *
	 * Email is sourced from WP_User->user_email or a WooFunnels_Contact, both
	 * server-trusted, so validate_cart_email() / DNS lookup is intentionally
	 * skipped on this path.
	 *
	 * @param array $post_parameters Cart payload sourced from server-side WC state.
	 */
	private static function wc_add_to_cart( array $post_parameters ) {
		if ( empty( $post_parameters ) ) {
			return;
		}
		if ( ! isset( $post_parameters['id'], $post_parameters['coupon_data'], $post_parameters['items'], $post_parameters['fees'], $post_parameters['bwfan_visitor'] ) ) {
			return;
		}

		/** Storage format remains PHP-serialized for backward compatibility with existing rows. */
		foreach ( array( 'coupon_data', 'items', 'fees' ) as $key ) {
			if ( is_array( $post_parameters[ $key ] ) ) {
				$post_parameters[ $key ] = maybe_serialize( $post_parameters[ $key ] );
			}
		}

		/** Before abandoned cart when wc add to cart */
		do_action( 'bwfan_before_abandoned_wc_add_to_cart', $post_parameters );

		$user_id       = $post_parameters['id'];
		$email         = '';
		$abandoned_obj = self::get_instance();
		if ( ! empty( $post_parameters['fk_uid'] ) ) {
			$uid     = $post_parameters['fk_uid'];
			$contact = new WooFunnels_Contact( '', '', '', '', $uid );
			$email   = $contact->get_email();
			$user_id = ! empty( $contact->get_wpid() ) ? $contact->get_wpid() : $user_id;
		}
		if ( empty( $email ) && ! empty( $user_id ) ) {
			$wp_user = get_user_by( 'id', $user_id );
			if ( $wp_user instanceof WP_User ) {
				$email = $wp_user->user_email;
			}
		}

		/** Respect excluded emails / user roles on this capture path as well */
		if ( self::is_capture_excluded( $email, $user_id ) ) {
			return;
		}

		$coupon_data  = $post_parameters['coupon_data'];
		$items        = $post_parameters['items'];
		$fees         = $post_parameters['fees'];
		$cart_details = $abandoned_obj->get_cart_by_key( 'cookie_key', $post_parameters['bwfan_visitor'], '%s' );
		$cart_details = empty( $cart_details ) && ! empty( $email ) ? $abandoned_obj->get_cart_by_key( 'email', $email, '%s' ) : $cart_details;

		if ( ! empty( $email ) && empty( $cart_details ) ) {
			self::insert_cart_row( array(
				'user_id'    => $user_id,
				'email'      => $email,
				'coupons'    => $coupon_data,
				'items'      => $items,
				'fees'       => $fees,
				'cookie_key' => $post_parameters['bwfan_visitor'] ?? '',
			) );

			/** After insert abandoned cart when wc add to cart */
			do_action( 'bwfan_after_insert_abandoned_wc_add_to_cart', $post_parameters );

			return;
		}

		/** If cart details is not found, then return */
		if ( empty( $cart_details ) || ! isset( $cart_details['ID'] ) ) {
			return;
		}

		$cart_details['coupons'] = $coupon_data;
		if ( ! empty( $items ) ) {
			$cart_details['items'] = $items;
		}
		$cart_details['fees']  = $fees;
		$data                  = self::get_abandoned_totals( $cart_details );
		$data['user_id']       = $user_id;
		$data['last_modified'] = current_time( 'mysql', 1 );
		$cart_id               = $cart_details['ID'] ?? 0;

		if ( empty( $cart_id ) ) {
			return;
		}

		/** If status lost and others */
		$cart_status = isset( $cart_details['status'] ) ? intval( $cart_details['status'] ) : 0;
		if ( in_array( $cart_status, array( 2, 3, 4 ), true ) ) {
			$data['status']       = 0;
			$data['created_time'] = current_time( 'mysql', 1 );
		}

		$where = array(
			'ID' => $cart_id,
		);

		BWFAN_Model_Abandonedcarts::update( $data, $where );
		do_action( 'bwfan_after_update_abandoned_wc_add_to_cart', $post_parameters, $data );
	}

	/**
	 * Insert a new abandoned cart row from a fully-populated payload.
	 *
	 * Distinct from the public create_abandoned_cart() which uses live WC()->cart
	 * state — this variant takes pre-built items/coupons/fees and is the helper
	 * used by wc_add_to_cart() above.
	 */
	private static function insert_cart_row( $data ) {
		if ( empty( $data['items'] ) || ! isset( $data['user_id'] ) ) {
			return;
		}

		$customer      = new WC_Customer( $data['user_id'] );
		$checkout_data = array(
			'fields' => array(
				'billing_first_name'  => $customer->get_billing_first_name(),
				'billing_last_name'   => $customer->get_billing_last_name(),
				'billing_company'     => $customer->get_billing_company(),
				'billing_country'     => $customer->get_billing_country(),
				'billing_address_1'   => $customer->get_billing_address_1(),
				'billing_address_2'   => $customer->get_billing_address_2(),
				'billing_city'        => $customer->get_billing_city(),
				'billing_state'       => $customer->get_billing_state(),
				'billing_postcode'    => $customer->get_billing_postcode(),
				'billing_phone'       => $customer->get_billing_phone(),
				'billing_email'       => $customer->get_billing_email(),
				'shipping_first_name' => $customer->get_shipping_first_name(),
				'shipping_last_name'  => $customer->get_shipping_last_name(),
				'shipping_company'    => $customer->get_shipping_company(),
				'shipping_country'    => $customer->get_shipping_country(),
				'shipping_address_1'  => $customer->get_shipping_address_1(),
				'shipping_address_2'  => $customer->get_shipping_address_2(),
				'shipping_city'       => $customer->get_shipping_city(),
				'shipping_state'      => $customer->get_shipping_state(),
				'shipping_postcode'   => $customer->get_shipping_postcode(),
			),
		);

		/** Set language code for the cart */
		$lang = self::get_current_language_code();
		if ( null !== $lang ) {
			$checkout_data['lang'] = $lang;
		}

		$fingerprint = BWFAN_Common::get_visitor_fingerprint( 'wc_add_to_cart' );
		if ( ! empty( $fingerprint ) ) {
			$checkout_data['_visitor_fingerprint'] = $fingerprint;
		}

		$data['status']        = 0;
		$data['created_time']  = current_time( 'mysql', 1 );
		$data['last_modified'] = current_time( 'mysql', 1 );
		$data['token']         = BWFAN_Common::create_token( 32 );
		$data['checkout_data'] = wp_json_encode( $checkout_data );
		$data['currency']      = get_woocommerce_currency();
		$data                  = self::get_abandoned_totals( $data );

		BWFAN_Model_Abandonedcarts::insert( $data );
	}

	/**
	 * Detect the current visitor language from supported translation plugins.
	 *
	 * Returns null when no supported plugin is active, or when Linguise is
	 * active but reports an empty language — preserving the legacy behavior
	 * of the call sites this helper consolidates (callers should skip the
	 * `lang` assignment on null).
	 *
	 * @return string|null
	 */
	private static function get_current_language_code() {
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return ICL_LANGUAGE_CODE;
		}
		if ( function_exists( 'pll_current_language' ) ) {
			return pll_current_language();
		}
		if ( bwfan_is_translatepress_active() ) {
			global $TRP_LANGUAGE;

			return isset( $TRP_LANGUAGE ) ? $TRP_LANGUAGE : null;
		}
		if ( function_exists( 'bwfan_is_weglot_active' ) && bwfan_is_weglot_active() && function_exists( 'weglot_get_current_language' ) ) {
			return weglot_get_current_language();
		}
		if ( function_exists( 'bwfan_is_gtranslate_active' ) && bwfan_is_gtranslate_active() ) {
			return BWFAN_Compatibility_With_GTRANSLATE::get_gtranslate_language();
		}
		if ( function_exists( 'bwfan_is_linguise_active' ) && bwfan_is_linguise_active() && class_exists( '\Linguise\WordPress\Helper' ) ) {
			$lang = \Linguise\WordPress\Helper::getLanguage();
			if ( ! empty( $lang ) ) {
				return $lang;
			}
		}

		return null;
	}

	private static function get_abandoned_totals( $data ) {
		$coupon_data = maybe_unserialize( $data['coupons'] ?? [] );
		$items       = maybe_unserialize( $data['items'] ?? [] );
		$fees        = maybe_unserialize( $data['fees'] ?? [] );

		/**
		 * Prefer WooCommerce's authoritative calculated total when the live cart
		 * is available. Re-summing line_subtotal fields breaks for any modifier
		 * that adjusts price during woocommerce_before_calculate_totals (wholesale
		 * pricing, dynamic/role-based pricing, multi-currency, bulk discounts),
		 * because those line fields can be unpopulated/zero at capture time while
		 * get_total( 'raw' ) reflects the fully calculated total.
		 */
		$calculated_total = null;
		if ( function_exists( 'WC' ) && ! is_null( WC()->cart ) && ! WC()->cart->is_empty() ) {
			$cart_total = WC()->cart->get_total( 'raw' );
			if ( '' !== $cart_total && is_numeric( $cart_total ) ) {
				$calculated_total = (float) $cart_total;
			}
			$data['shipping_tax_total'] = WC()->cart->shipping_tax_total;
			$data['shipping_total']     = WC()->cart->shipping_total;
		}

		if ( is_null( $calculated_total ) ) {
			$calculated_total = 0;
			foreach ( $items as $item ) {
				$line_subtotal_tax = isset( $item['line_subtotal_tax'] ) ? floatval( $item['line_subtotal_tax'] ) : 0;
				$line_subtotal     = isset( $item['line_subtotal'] ) ? floatval( $item['line_subtotal'] ) : 0;
				$calculated_total  += $line_subtotal + $line_subtotal_tax;
			}
			foreach ( $coupon_data as $coupon ) {
				$calculated_total -= $coupon['discount_incl_tax'];
			}
			foreach ( $fees as $fee ) {
				$calculated_total += ( $fee->total + $fee->tax );
			}
		}
		$currency            = $data['currency'] ?? get_woocommerce_currency();
		$calculated_total     = wc_format_decimal( $calculated_total, wc_get_price_decimals() );
		$data['total']        = $calculated_total;
		$data['total_base']   = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $calculated_total, $currency );

		return $data;
	}

	/**
	 * Remove duplicate carts
	 *
	 * @param $carts
	 *
	 * @return array|mixed|void
	 */
	public static function remove_duplicate_cart( $carts ) {
		if ( empty( $carts ) && ! is_array( $carts ) ) {
			return;
		}
		$unique_carts    = [];
		$emails          = [];
		$duplicate_carts = [];
		foreach ( $carts as $cart ) {
			if ( in_array( $cart['email'], $emails, true ) ) {
				$duplicate_carts[] = $cart;
				continue;
			}
			$emails[]       = $cart['email'];
			$unique_carts[] = $cart;
		}
		/** Verify duplicate carts in "In Progress" status, update the oldest record with the latest data, and delete the remaining duplicates. */
		$unique_carts = self::check_for_cart_in_progress( $unique_carts );
		if ( empty( $duplicate_carts ) ) {
			return $unique_carts;
		}

		$p_keys = array_column( $duplicate_carts, 'ID' );
		$emails = array_column( $duplicate_carts, 'email' );

		self::remove_duplicate_contact( $emails );

		$id_placeholders = array_fill( 0, count( $p_keys ), '%d' );
		$id_placeholders = implode( ', ', $id_placeholders );

		BWFAN_Common::log_test_data( [
			'p_keys' => implode( ',', $p_keys ),
			'emails' => implode( ',', $emails ),
		], 'fka-duplicate-cart-captured', true );

		global $wpdb;
		$query = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bwfan_abandonedcarts  WHERE `ID` IN ($id_placeholders)", $p_keys ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; ids bound via prepare() %d
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d

		return $unique_carts;
	}

	/**
	 * Remove duplicate contact
	 *
	 * @param $emails
	 *
	 * @return void
	 */
	public static function remove_duplicate_contact( $emails ) {
		global $wpdb;
		$email_placeholders = array_fill( 0, count( $emails ), '%s' );
		$email_placeholders = implode( ', ', $email_placeholders );
		$query              = "SELECT `email`, GROUP_CONCAT(`id`) AS `pkey` FROM `{$wpdb->prefix}bwf_contact` WHERE `email` IN ($email_placeholders) GROUP BY `email` HAVING COUNT(`email`) > 1";
		$query              = $wpdb->prepare( $query, $emails ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; emails bound via prepare()
		$results            = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; emails bound via prepare()
		if ( empty( $results ) ) {
			return;
		}
		$to_be_deleted = [];
		foreach ( $results as $result ) {
			if ( empty( $result['pkey'] ) ) {
				continue;
			}
			$p_keys = explode( ',', $result['pkey'] );
			array_shift( $p_keys );
			if ( ! is_array( $p_keys ) || empty( $p_keys ) ) {
				continue;
			}
			$to_be_deleted = array_merge( $to_be_deleted, $p_keys );
		}

		if ( empty( $to_be_deleted ) ) {
			return;
		}

		$id_placeholders = array_fill( 0, count( $to_be_deleted ), '%d' );
		$id_placeholders = implode( ', ', $id_placeholders );

		global $wpdb;
		$query = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bwf_contact  WHERE `id` IN ($id_placeholders)", $to_be_deleted ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; ids bound via prepare() %d
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d
	}

	/**
	 * Check for duplicate in-progress carts and update newest data into oldest entry
	 * Keep the oldest cart entry and delete the newest duplicates
	 *
	 * Supports both single cart (backward compatibility) and multiple carts
	 *
	 * @param array|array[] $active_abandoned_carts Single cart array or array of cart arrays
	 *
	 * @return array
	 */
	public static function check_for_cart_in_progress( $active_abandoned_carts ) {
		global $wpdb;

		// Normalize input: convert single cart to array format for consistent processing
		if ( empty( $active_abandoned_carts ) || ! is_array( $active_abandoned_carts ) ) {
			return [];
		}

		$emails = array_column( $active_abandoned_carts, 'email' );
		if ( empty( $emails ) ) {
			return $active_abandoned_carts;
		}

		// Fetch all in-progress carts for all emails in a single query (optimized)
		$email_placeholders = array_fill( 0, count( $emails ), '%s' );

		$email_placeholders = implode( ', ', $email_placeholders );
		$query              = $wpdb->prepare( "SELECT * FROM {table_name} WHERE `email` IN ($email_placeholders) AND `status` IN (1) ORDER BY `ID` ASC", $emails ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; emails bound via prepare()

		$all_existing_carts = BWFAN_Model_Abandonedcarts::get_results( $query );

		// Group existing carts by email for efficient lookup
		$existing_carts_by_email = [];
		if ( is_array( $all_existing_carts ) && count( $all_existing_carts ) > 0 ) {
			foreach ( $all_existing_carts as $cart ) {
				$email = $cart['email'];
				if ( ! isset( $existing_carts_by_email[ $email ] ) ) {
					$existing_carts_by_email[ $email ] = [];
				}
				$existing_carts_by_email[ $email ][] = $cart;
			}
		}

		// Process each email group
		$indices_to_remove = [];
		foreach ( $emails as $index => $email ) {
			// Get existing in-progress carts for this email (from pre-fetched data)
			$existing_carts = isset( $existing_carts_by_email[ $email ] ) ? $existing_carts_by_email[ $email ] : [];
			$active_cart    = isset( $active_abandoned_carts[ $index ] ) ? $active_abandoned_carts[ $index ] : null;

			if ( empty( $existing_carts ) || empty( $active_cart ) ) {
				/** No in-progress carts found in database for this email */
				continue;
			}

			/** Get oldest cart (first in array) */
			$oldest_cart = $existing_carts[0];

			/** Prepare update data from newest active abandoned cart to oldest cart */
			$update_data = [
				'items'              => $active_cart['items'] ?? '',
				'coupons'            => $active_cart['coupons'] ?? '',
				'fees'               => $active_cart['fees'] ?? '',
				'shipping_tax_total' => $active_cart['shipping_tax_total'] ?? '',
				'shipping_total'     => $active_cart['shipping_total'] ?? '',
				'total'              => $active_cart['total'] ?? '',
				'total_base'         => $active_cart['total_base'] ?? '',
				'currency'           => $active_cart['currency'] ?? '',
				'last_modified'      => $active_cart['last_modified'] ?? current_time( 'mysql', 1 ),
				'checkout_data'      => $active_cart['checkout_data'] ?? '',
				'checkout_page_id'   => $active_cart['checkout_page_id'] ?? 0,
				'cookie_key'         => $active_cart['cookie_key'] ?? '',
				'token'              => $active_cart['token'] ?? '',
			];

			/** Update oldest cart with newest active abandoned cart's data */
			$where = [ 'ID' => $oldest_cart['ID'] ];

			BWFAN_Model_Abandonedcarts::update( $update_data, $where );

			/** Collect IDs of all carts except the oldest one (to delete) */
			$ids_to_delete = array_filter( array_column( $existing_carts, 'ID' ), function ( $id ) use ( $oldest_cart ) {
				return intval( $id ) !== intval( $oldest_cart['ID'] );
			} );

			/** Delete the active abandoned cart entry for this email */
			$ids_to_delete[] = intval( $active_cart['ID'] );

			/** Delete duplicate cart entries */
			if ( ! empty( $ids_to_delete ) ) {
				$ids_to_delete   = array_unique( $ids_to_delete );
				$id_placeholders = array_fill( 0, count( $ids_to_delete ), '%d' );
				$id_placeholders = implode( ', ', $id_placeholders );

				BWFAN_Common::log_test_data( [
					'email'          => $email,
					'kept_cart_id'   => $oldest_cart['ID'],
					'active_cart_id' => intval( $active_cart['ID'] ),
					'deleted_ids'    => implode( ',', $ids_to_delete ),
					'deleted_count'  => count( $ids_to_delete ),
				], 'fka-duplicate-cart-merged', true );

				$delete_query = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bwfan_abandonedcarts WHERE `ID` IN ($id_placeholders)", $ids_to_delete ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated; ids bound via prepare() %d
				$wpdb->query( $delete_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders generated; ids bound via prepare() %d

				/** Collect index to remove after iteration */
				$indices_to_remove[] = $index;
			}
		}

		/** Remove processed carts from array after iteration */
		foreach ( $indices_to_remove as $idx ) {
			unset( $active_abandoned_carts[ $idx ] );
		}

		return $active_abandoned_carts;
	}
}

if ( class_exists( 'BWFAN_Core' ) ) {
	BWFAN_Core::register( 'abandoned', 'BWFAN_Abandoned_Cart' );
}
