<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Pie_WCWL_Waitlist_Update_Stock' ) ) {

	/**
	 * Handle stock updates and mailouts
	 */
	class Pie_WCWL_Waitlist_Update_Stock {

		/**
		 * Delay hooks until init
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'init', array( $this, 'add_stock_update_hooks' ), 20 );
		}

		/**
		 * Add hooks for stock updates
		 *
		 * @return void
		 */
		public function add_stock_update_hooks() {

			// maintain custom stock count to ensure we only send mailouts when stock is updated
			add_action( 'woocommerce_product_set_stock', array( $this, 'update_stock_status' ), 99 );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'update_stock_status' ), 99 );
			
			/**
			 * Filter to allow mailouts to be processed during product import
			 * 
			 * @since 2.4.0
			 */
			if ( isset( $_REQUEST['action'] ) && 'woocommerce_do_ajax_product_import' === $_REQUEST['action'] && ! apply_filters( 'wcwl_allow_mailouts_during_product_import', false ) ) {
				return;
			}

			// stock status and quantity updates
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'perform_api_mailout_stock_status' ), 10, 2 );
			add_action( 'woocommerce_product_set_stock', array( $this, 'perform_api_mailout_stock' ) );
			add_action( 'woocommerce_variation_set_stock_status', array( $this, 'perform_api_mailout_stock_status' ), 10, 2 );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'perform_api_mailout_stock' ) );
			add_action( 'woocommerce_product_object_updated_props', array( $this, 'perform_api_mailout_bundles' ), 10, 2 );
			add_action( 'transition_post_status', array( $this, 'perform_api_mailout_on_publish' ), 10, 3 );

			// Events (ticket stock status is updated directly in postmeta so does not trigger WC hooks above).
			if ( function_exists( 'tribe_is_event' ) && 'yes' === get_option( 'woocommerce_waitlist_events' ) ) {
				add_action( 'updated_postmeta', array( $this, 'perform_mailout_if_ticket_stock_updated' ), 10, 4 );
				add_action( 'tribe_tickets_ticket_add', array( $this, 'trigger_waitlist_mailouts_for_events' ), 10, 3 );
			}
		}

		/**
		 * Update custom product meta to keep track of whether a product was in/out of stock before the latest update
		 *
		 * @param WC_Product $product updated product.
		 */
		public function update_stock_status( WC_Product $product ) {
			if ( ! $product ) {
				return;
			}
			$stock = $product->get_stock_quantity();
			if ( ! $stock || ! in_array( get_post_status( $product->get_id() ), array( 'publish', 'private' ) ) ) {
				$stock = 0;
			}
			update_post_meta( $product->get_id(), 'wcwl_stock_level', $stock );
		}

		/**
		 * Perform mailouts when stock status is updated and product is in stock
		 * We only want to do this for variations and simple products NOT variable (parent) products
		 *
		 * @param int    $product_id updated product ID.
		 * @param string $stock_status product stock status.
		 */
		public function perform_api_mailout_stock_status( $product_id, $stock_status ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return;
			}
			if ( 'instock' === $stock_status || $product->is_in_stock() ) {
				if ( WooCommerce_Waitlist_Plugin::is_variable( $product ) ) {
					return;
				}
				$this->do_mailout( $product );
			}
		}

		/**
		 * Perform mailouts when stock quantity is updated and product registers as in stock
		 * We only want to do this for variations and simple products NOT variable (parent) products
		 *
		 * @param WC_Product|int $product updated product/product ID.
		 */
		public function perform_api_mailout_stock( $product ) {
			$product = wc_get_product( $product );
			if ( ! $product ) {
				return;
			}
			if ( WooCommerce_Waitlist_Plugin::is_variable( $product ) && $product->managing_stock() ) {
				$this->handle_variable_mailout( $product );
			} elseif ( $product->is_in_stock() ) {
				$this->do_mailout( $product );
			}
		}

		/**
		 * Perform mailouts for product bundles if the given bundled product returns in stock
		 *
		 * @param WC_Product $product updated product.
		 * @param array      $updated_props updated property array.
		 * @return void
		 */
		public function perform_api_mailout_bundles( WC_Product $product, $updated_props ) {
			if ( ! $product ) {
				return;
			}
			if ( ! $product->is_type( 'bundle' ) ||
				! $updated_props ||
				'bundled_items_stock_status' !== $updated_props[0] ||
				is_null( $product->get_stock_status() ) ||
				! $product->is_in_stock() ) {
					return;
			}
			$this->do_mailout( $product );
		}

		/**
		 * Trigger mailouts when post status is updated
		 *
		 * @param string $new_status post status updated to.
		 * @param string $old_status post status updated from.
		 * @param object $post       post object.
		 * @return void
		 */
		public function perform_api_mailout_on_publish( $new_status, $old_status, $post ) {
			if ( ! in_array( $old_status, array( 'publish', 'private' ) ) && in_array( $new_status, array( 'publish', 'private' ) ) ) {
				$product = wc_get_product( $post );
				if ( $product && WooCommerce_Waitlist_Plugin::is_variable( $product ) ) {
					// PHPStan check ignored as we are sure the product is a WC_Varable at this point
					// so we know the function exists
					foreach ( $product->get_available_variations() as $variation ) { // @phpstan-ignore method.notFound
						$variation = wc_get_product( $variation['variation_id'] );
						$this->perform_api_mailout_stock( $variation );
					}
				} else {
					$this->perform_api_mailout_stock( $product );
				}
			}
		}

		/**
		 * Triggers mailout when "_stock_status" postmeta for an event ticket product is updated to "instock"
		 *
		 * @param int    $meta_id meta ID.
		 * @param int    $post_id post ID.
		 * @param string $meta_key meta key.
		 * @param mixed  $meta_value meta value.
		 */
		public function perform_mailout_if_ticket_stock_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
			if ( '_stock_status' !== $meta_key ) {
				return;
			}
			if ( ! function_exists( 'tribe_events_product_is_ticket' ) ) {
				return;
			}
			if ( ! tribe_events_product_is_ticket( $post_id ) ) {
				return;
			}
			$product = wc_get_product( $post_id );
			if ( $product && $product->is_in_stock() ) {
				$this->do_mailout( $product );
			}
		}

		/**
		 * Trigger in stock notification if required when ticket is updated
		 *
		 * @param int    $event_id event ID.
		 * @param object $ticket ticket.
		 * @param array  $data data.
		 */
		public function trigger_waitlist_mailouts_for_events( $event_id, $ticket, $data ) {
			if ( ! get_post_meta( $ticket->ID, WCWL_SLUG, true ) ) {
				return;
			}
			$ticket = wc_get_product( $ticket->ID );
			if ( $ticket && $ticket->is_in_stock() ) {
				$this->do_mailout( $ticket );
			}
		}

		/**
		 * Handle mailouts when variable product stock status is updated for each variation
		 *
		 * @param WC_Product $product updated variable product.
		 * @return void
		 */
		public function handle_variable_mailout( WC_Product $product ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				if ( 'parent' === $variation->managing_stock() && $product->is_in_stock() ) {
					$this->do_mailout( $variation );
				}
			}
		}

		/**
		 * Fire a call to perform the mailout for the given product
		 *
		 * @param WC_Product $product updated product.
		 */
		public function do_mailout( WC_Product $product ) {
			if ( ! $product ) {
				return;
			}
			/**
			 * Filter to allow mailouts to be skipped
			 * 
			 * @since 2.4.0
			 */
			if ( apply_filters( 'wcwl_waitlist_should_do_mailout', true, $product ) ) {
				$stock_level = $this->get_minimum_stock_level( $product->get_id() );
				if ( $this->minimum_stock_requirement_met( $product, $stock_level ) && $this->stock_level_has_broken_threshold( $product, $stock_level ) ) {
					$waitlist = new Pie_WCWL_Waitlist( $product );
					$waitlist->waitlist_mailout();
					// Chained products
					global $wc_cp;
					if ( $wc_cp && method_exists( $wc_cp, 'get_chained_parent_ids' ) ) {
						$chained_products = $wc_cp->get_chained_parent_ids( $product->get_id() );
						if ( ! empty( $chained_products ) ) {
							wcwl_perform_mailout_for_chained_products( $chained_products );
						}
					}
					// Bundle products
					if ( function_exists( 'wc_pb_get_bundled_product_map' ) ) {
						$map = wc_pb_get_bundled_product_map( $product );
						if ( is_array( $map ) && ! empty( $map ) ) {
							wcwl_perform_mailout_for_bundle_products( $map );
						}
					}
				}
			}
		}

		/**
		 * Return minimum required stock level before we email waitlist users
		 *
		 * @param int $product_id product ID.
		 *
		 * @return int
		 * @since  1.8.0
		 */
		public function get_minimum_stock_level( $product_id ) {
			$options = get_post_meta( $product_id, 'wcwl_options', true );
			if ( isset( $options['enable_stock_trigger'] ) && 'true' === $options['enable_stock_trigger'] && isset( $options['minimum_stock'] ) ) {
				return absint( $options['minimum_stock'] );
			} else {
				$minimum_stock = get_option( 'woocommerce_waitlist_minimum_stock' ) ? absint( get_option( 'woocommerce_waitlist_minimum_stock' ) ) : 1;
				return $minimum_stock;
			}
		}

		/**
		 * Check the minimum stock requirements are met for the current waitlist before processing mailouts
		 *
		 * @param WC_Product $product              product object.
		 * @param int        $stock_level_required minimum stock required to trigger waitlist mailout.
		 *
		 * @return bool
		 * @since  1.8.0
		 */
		public function minimum_stock_requirement_met( WC_Product $product, $stock_level_required ) {
			if ( ( WooCommerce_Waitlist_Plugin::is_simple( $product ) || $product->is_type( 'bundle' ) ) && ! $product->get_manage_stock() ) {
				return true;
			}
			$product_stock = $product->get_stock_quantity();
			if ( WooCommerce_Waitlist_Plugin::is_variation( $product ) && ! $product->get_manage_stock() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( ! $parent || ! $parent->get_manage_stock() ) {
					return true;
				} else {
					$product_stock = $parent->get_stock_quantity();
				}
			}
			if ( $product_stock && $product_stock >= $stock_level_required ) {
				return true;
			}
			return false;
		}

		/**
		 * Check the stock level update has caused the stock level to go from under->over the set threshold
		 * This check avoids sending a duplicate mailout each time the product stock increases
		 *
		 * @param WC_Product $product WC_Product.
		 * @param int        $stock_level_required set stock threshold.
		 * @return boolean
		 */
		public function stock_level_has_broken_threshold( WC_Product $product, $stock_level_required ) {
			if ( ! $product ) {
				return false;
			}
			if ( ( WooCommerce_Waitlist_Plugin::is_simple( $product ) || $product->is_type( 'bundle' ) ) && ! $product->get_manage_stock() ) {
				return true;
			}
			if ( WooCommerce_Waitlist_Plugin::is_variation( $product ) && 'parent' === $product->get_manage_stock() ) {
				$previous_stock_level = (int) get_post_meta( $product->get_parent_id(), 'wcwl_stock_level', true );
			} else {
				$previous_stock_level = (int) get_post_meta( $product->get_id(), 'wcwl_stock_level', true );
			}
			if ( $previous_stock_level < $stock_level_required ) {
				return true;
			} else {
				return false;
			}
		}
	}
}
