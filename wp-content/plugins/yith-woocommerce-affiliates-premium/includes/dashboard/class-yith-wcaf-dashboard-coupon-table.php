<?php
/**
 * Affiliate Dashboard Coupon Table
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH/Affiliates/Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Dashboard_Coupon_Table' ) ) {
	/**
	 * Offer methods to print tables inside dashboard pages
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Dashboard_Coupon_Table extends YITH_WCAF_Dashboard_Table {

		/* === COLUMN METHODS === */

		/**
		 * Render column for "Code" field
		 *
		 * @param WC_Coupon $coupon Row object.
		 */
		public function render_code_column( $coupon ) {
			$code = $coupon->get_code();
			?>
			<div class="copy-field-wrapper">
				<input type="text" class="copy-target" value="<?php echo esc_attr( YITH_WCAF_Promo::get_apply_promo_url( $code ) ); ?>"/>
				<a
					href="#"
					class="copy-trigger help_tip"
					class="help_tip"
					data-tip="<?php echo esc_attr_x( 'Click to copy sharing URL!', '[FRONTEND] Coupons dashboard section', 'yith-woocommerce-affiliates' ); ?>"
				>
					<?php echo esc_html( $code ); ?>
				</a>
			</div>
			<?php
		}

		/**
		 * Render column for "Type" field
		 *
		 * @param WC_Coupon $coupon Row object.
		 */
		public function render_type_column( $coupon ) {
			$discount_types = wc_get_coupon_types();
			$type           = $coupon->get_discount_type();

			if ( ! isset( $discount_types[ $type ] ) ) {
				$this->render_empty_cell( $coupon, 'type' );
				return;
			}

			echo esc_html( $discount_types[ $type ] );
		}

		/**
		 * Render column for "amount" field
		 *
		 * @param WC_Coupon $coupon Row object.
		 */
		public function render_amount_column( $coupon ) {
			$type = $coupon->get_discount_type();

			if ( 'percent' === $type ) {
				$amount = yith_wcaf_rate_format( $coupon->get_amount() );
			} elseif ( in_array( $type, array( 'fixed_cart', 'fixed_product' ), true ) ) {
				$amount = wc_price( $coupon->get_amount() );
			} elseif ( has_filter( "yith_wcaf_coupon_{$type}_amount" ) ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_coupon_$type_amount
				 *
				 * Filters the amount to discount when the affiliate's associated coupon is used.
				 * <code>$type</code> will be replaced with the coupon discount type.
				 *
				 * @param float     $amount Coupon amount.
				 * @param WC_Coupon $coupon Coupon object.
				 */
				$amount = apply_filters( "yith_wcaf_coupon_{$type}_amount", $coupon->get_amount(), $coupon );
			} else {
				$amount = yith_wcaf_number_format( $coupon->get_amount() );
			}

			echo wp_kses_post( $amount );
		}

		/**
		 * Render column for "Expires" field
		 *
		 * @param WC_Coupon $coupon Row object.
		 */
		public function render_expires_column( $coupon ) {
			if ( ! method_exists( $coupon, 'get_date_expires' ) ) {
				$this->render_empty_cell( $coupon, 'date_expires' );
				return;
			}

			$this->render_formatted_date( $coupon->get_date_expires( 'edit' ), $coupon, 'date_expires' );
		}

		/**
		 * Render column for "Info" field
		 *
		 * @param WC_Coupon $coupon Row object.
		 */
		public function render_info_column( $coupon ) {
			// format info box.
			$coupon_info         = '';
			$free_shipping       = $coupon->get_free_shipping();
			$minimum_spend       = $coupon->get_minimum_amount();
			$maximum_spend       = $coupon->get_maximum_amount();
			$individual_use      = $coupon->get_individual_use();
			$exclude_sale        = $coupon->get_exclude_sale_items();
			$products            = $coupon->get_product_ids();
			$excluded_products   = $coupon->get_excluded_product_ids();
			$categories          = $coupon->get_product_categories();
			$excluded_categories = $coupon->get_excluded_product_categories();
			$limit_per_coupon    = $coupon->get_usage_limit();
			$limit_per_x_items   = $coupon->get_limit_usage_to_x_items();
			$limit_per_user      = $coupon->get_usage_limit_per_user();

			if ( $free_shipping ) {
				$coupon_info .= sprintf( '<b>%s</b><br/>', _x( 'Free shipping!', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ) );
			}

			if ( $minimum_spend ) {
				$coupon_info .= sprintf( '<b>%s</b>: %s<br/>', _x( 'Minimum to spend', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), wc_price( $minimum_spend ) );
			}

			if ( $maximum_spend ) {
				$coupon_info .= sprintf( '<b>%s</b>: %s<br/>', _x( 'Maximum to spend', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), wc_price( $maximum_spend ) );
			}

			if ( $individual_use ) {
				$coupon_info .= sprintf( '<b>%s</b><br/>', _x( 'Individual use!', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ) );
			}

			if ( $exclude_sale ) {
				$coupon_info .= sprintf( '<b>%s</b><br/>', _x( 'Exclude sale products!', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ) );
			}

			if ( ! empty( $products ) ) {
				$product_names = array();
				foreach ( $products as $product_id ) {
					$product_names[] = get_the_title( $product_id );
				}
				$coupon_info .= sprintf( '<b>%s</b>: %s<br/>', _x( 'Allowed products', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), implode( ', ', $product_names ) );
			}

			if ( ! empty( $excluded_products ) ) {
				$product_names = array();

				foreach ( $excluded_products as $product_id ) {
					$product_names[] = get_the_title( $product_id );
				}

				$coupon_info .= sprintf( '<b>%s</b>: %s<br/>', _x( 'Excluded products', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), implode( ', ', $product_names ) );
			}

			if ( ! empty( $categories ) ) {
				$categories_names = array();

				foreach ( $categories as $term_id ) {
					$term               = get_term( $term_id );
					$categories_names[] = $term->name;
				}

				$coupon_info .= sprintf( '<b>%s</b>: %s<br/>', _x( 'Allowed product categories', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), implode( ', ', $categories_names ) );
			}

			if ( ! empty( $excluded_categories ) ) {
				$categories_names = array();

				foreach ( $excluded_categories as $term_id ) {
					$term               = get_term( $term_id );
					$categories_names[] = $term->name;
				}

				$coupon_info .= sprintf( '<b>%s</b>: %s<br/>', _x( 'Excluded product categories', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), implode( ', ', $categories_names ) );
			}

			if ( $limit_per_coupon ) {
				$coupon_info .= sprintf( '<b>%s</b>: %d<br/>', _x( 'Limit per coupon:', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), $limit_per_coupon );
			}

			if ( $limit_per_x_items ) {
				$coupon_info .= sprintf( '<b>%s</b>: %d<br/>', _x( 'Limit per number of items:', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), $limit_per_x_items );
			}

			if ( $limit_per_user ) {
				$coupon_info .= sprintf( '<b>%s</b>: %d<br/>', _x( 'Limit per user:', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' ), $limit_per_user );
			}

			if ( ! $coupon_info ) {
				$coupon_info .= _x( 'No additional info', '[FRONTEND] Coupon dashboard info tooltip', 'yith-woocommerce-affiliates' );
			}

			if ( empty( $coupon_info ) ) {
				return;
			}
			?>

			<a href="#" data-tip="<?php echo esc_attr( $coupon_info ); ?>" class="help_tip">?</a>

			<?php
		}


	}
}
