<?php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Nonces are verified inside each AJAX handler; superglobal reads are wrapped with wp_unslash + sanitize_*.
/**
 * Restocking Fee
 *
 * Handles all logic related to deducting a restocking fee from return refunds:
 * - Resolution: product override → category override → global setting
 * - Calculation: per-item, sum across the return request
 * - Storage: snapshot frozen at submit time on the return_orders row
 * - Display helpers used by customer form, admin dashboard and emails
 *
 * The fee never applies to exchanges — exchange flow ignores this class entirely.
 *
 * @class Restocking_Fee
 * @since 1.6.2.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Restocking_Fee {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// AJAX endpoint for live recalculation on the customer return form.
		add_action( 'wp_ajax_zorem_calculate_restocking_fee', array( $this, 'ajax_calculate_fee' ) );
		add_action( 'wp_ajax_nopriv_zorem_calculate_restocking_fee', array( $this, 'ajax_calculate_fee' ) );

		// Per-product metabox on the Product edit screen.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );

		// Per-category fields on the Product Category edit/add screens.
		add_action( 'product_cat_add_form_fields', array( $this, 'render_category_add_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'render_category_edit_fields' ) );
		add_action( 'created_product_cat', array( $this, 'save_category_fields' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category_fields' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings readers
	 * --------------------------------------------------------------------- */

	/**
	 * Is the feature globally enabled?
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$enabled = zorem_returns_exchanges()->common->return_settings( 'restocking_fee_enable', 'no' );
		return 'yes' === $enabled;
	}

	/**
	 * Get global default fee config from settings.
	 *
	 * @return array { type: 'percentage'|'fixed', amount: float }
	 */
	public function get_global_config() {
		return array(
			'type'   => zorem_returns_exchanges()->common->return_settings( 'restocking_fee_type', 'percentage' ),
			'amount' => (float) zorem_returns_exchanges()->common->return_settings( 'restocking_fee_amount', 0 ),
		);
	}

	/**
	 * Get the customer-facing label for the fee.
	 *
	 * @return string
	 */
	public function get_label() {
		$label = zorem_returns_exchanges()->common->return_settings( 'restocking_fee_label', __( 'Restocking Fee', 'zorem-returns' ) );
		return ! empty( $label ) ? $label : __( 'Restocking Fee', 'zorem-returns' );
	}

	/* ---------------------------------------------------------------------
	 * Per-product / per-category override resolution
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the effective fee config for a given product, walking the chain:
	 *   product override (or exempt) → category override → global default.
	 *
	 * @param int $product_id The product (or variation parent) ID.
	 * @return array { type: string, amount: float, source: string, exempt: bool }
	 */
	public function resolve_config_for_product( $product_id ) {
		$global = $this->get_global_config();
		$result = array(
			'type'   => $global['type'],
			'amount' => $global['amount'],
			'source' => 'global',
			'exempt' => false,
		);

		if ( empty( $product_id ) ) {
			return $result;
		}

		// Variations inherit from their parent.
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}

		// 1. Product-level override.
		$product_mode = get_post_meta( $product_id, '_zorem_restocking_fee_mode', true );
		if ( 'exempt' === $product_mode ) {
			$result['amount'] = 0;
			$result['source'] = 'product';
			$result['exempt'] = true;
			return $result;
		}
		if ( 'override' === $product_mode ) {
			$type   = get_post_meta( $product_id, '_zorem_restocking_fee_type', true );
			$amount = get_post_meta( $product_id, '_zorem_restocking_fee_amount', true );
			if ( '' !== $type && '' !== $amount ) {
				$result['type']   = $type;
				$result['amount'] = (float) $amount;
				$result['source'] = 'product';
				return $result;
			}
		}

		// 2. Category-level override (first matched category wins).
		$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
		if ( ! empty( $term_ids ) ) {
			foreach ( $term_ids as $term_id ) {
				$cat_mode = get_term_meta( $term_id, '_zorem_restocking_fee_mode', true );
				if ( 'exempt' === $cat_mode ) {
					$result['amount'] = 0;
					$result['source'] = 'category';
					$result['exempt'] = true;
					return $result;
				}
				if ( 'override' === $cat_mode ) {
					$type   = get_term_meta( $term_id, '_zorem_restocking_fee_type', true );
					$amount = get_term_meta( $term_id, '_zorem_restocking_fee_amount', true );
					if ( '' !== $type && '' !== $amount ) {
						$result['type']   = $type;
						$result['amount'] = (float) $amount;
						$result['source'] = 'category';
						return $result;
					}
				}
			}
		}

		// 3. Fall through to global.
		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Calculation
	 * --------------------------------------------------------------------- */

	/**
	 * Calculate the restocking fee for a single line item being returned.
	 *
	 * @param WC_Order_Item_Product $item       The order item.
	 * @param int                   $return_qty Quantity being returned.
	 * @return array { fee: float, item_refund: float, config: array }
	 */
	public function calculate_for_item( $item, $return_qty ) {
		$result = array(
			'fee'         => 0.0,
			'item_refund' => 0.0,
			'config'      => array(
				'type'   => 'percentage',
				'amount' => 0,
				'source' => 'none',
				'exempt' => false,
			),
		);

		if ( ! $item || $return_qty <= 0 ) {
			return $result;
		}

		$ordered_qty = $item->get_quantity();
		if ( $ordered_qty <= 0 ) {
			return $result;
		}

		// Per-unit refund value (price + tax) is what the % is taken from / what fixed is subtracted from.
		$item_total      = (float) $item->get_total() + (float) $item->get_total_tax();
		$per_unit_refund = $item_total / $ordered_qty;
		$line_refund     = $per_unit_refund * $return_qty;

		$result['item_refund'] = round( $line_refund, 2 );

		if ( ! $this->is_enabled() ) {
			return $result;
		}

		$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		$config     = $this->resolve_config_for_product( $product_id );

		$result['config'] = $config;

		if ( $config['exempt'] || $config['amount'] <= 0 ) {
			return $result;
		}

		if ( 'percentage' === $config['type'] ) {
			$fee = ( $line_refund * $config['amount'] ) / 100;
		} else {
			// Fixed: per item × qty.
			$fee = $config['amount'] * $return_qty;
		}

		// Never let the fee exceed the line refund itself.
		if ( $fee > $line_refund ) {
			$fee = $line_refund;
		}

		$result['fee'] = round( max( 0, $fee ), 2 );
		return $result;
	}

	/**
	 * Calculate fee + breakdown for a full return request (multiple items).
	 *
	 * @param WC_Order $order        The original WC order.
	 * @param array    $return_items POST'd return_items array (item_id => [ return_product_qty => N ]).
	 * @return array { total_fee: float, total_refund: float, net_refund: float, breakdown: array, label: string, enabled: bool }
	 */
	public function calculate_for_request( $order, $return_items ) {
		$out = array(
			'enabled'      => $this->is_enabled(),
			'label'        => $this->get_label(),
			'total_fee'    => 0.0,
			'total_refund' => 0.0,
			'net_refund'   => 0.0,
			'breakdown'    => array(),
		);

		if ( ! $order || empty( $return_items ) || ! is_array( $return_items ) ) {
			return $out;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( empty( $return_items[ $item_id ]['return_product_qty'] ) ) {
				continue;
			}
			$qty  = (int) $return_items[ $item_id ]['return_product_qty'];
			$calc = $this->calculate_for_item( $item, $qty );

			$out['total_refund'] += $calc['item_refund'];
			$out['total_fee']    += $calc['fee'];

			$out['breakdown'][ $item_id ] = array(
				'qty'         => $qty,
				'item_refund' => $calc['item_refund'],
				'fee'         => $calc['fee'],
				'config'      => $calc['config'],
				'name'        => $item->get_name(),
			);
		}

		$out['total_refund'] = round( $out['total_refund'], 2 );
		$out['total_fee']    = round( $out['total_fee'], 2 );
		$out['net_refund']   = round( $out['total_refund'] - $out['total_fee'], 2 );

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * AJAX live recalc on the customer return form
	 * --------------------------------------------------------------------- */

	public function ajax_calculate_fee() {
		if ( ! check_ajax_referer( 'return_center_multiStep_form', 'nonce', false ) ) {
			wp_send_json_error( array( 'error' => 'invalid_nonce' ) );
		}

		$order_id     = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$return_items = isset( $_POST['return_items'] ) ? map_deep( wp_unslash( $_POST['return_items'] ), 'sanitize_text_field' ) : array();

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			wp_send_json_error( array( 'error' => 'invalid_order' ) );
		}

		$summary = $this->calculate_for_request( $order, $return_items );

		wp_send_json_success(
			array(
				'enabled'        => $summary['enabled'],
				'label'          => $summary['label'],
				'total_refund'   => wc_price( $summary['total_refund'] ),
				'total_fee'      => wc_price( $summary['total_fee'] ),
				'net_refund'     => wc_price( $summary['net_refund'] ),
				'total_fee_raw'  => $summary['total_fee'],
				'has_fee'        => $summary['total_fee'] > 0,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Per-product metabox (General tab on Product edit)
	 * --------------------------------------------------------------------- */

	public function render_product_fields() {
		global $post;
		if ( ! $post ) {
			return;
		}

		$mode   = get_post_meta( $post->ID, '_zorem_restocking_fee_mode', true );
		$type   = get_post_meta( $post->ID, '_zorem_restocking_fee_type', true );
		$amount = get_post_meta( $post->ID, '_zorem_restocking_fee_amount', true );

		echo '<div class="options_group zorem-restocking-fee-product-options">';

		// Dedicated nonce for the restocking-fee product fields.
		wp_nonce_field( 'zorem_save_product_restocking_fee', 'zorem_restocking_fee_product_nonce' );

		woocommerce_wp_select(
			array(
				'id'          => '_zorem_restocking_fee_mode',
				'label'       => __( 'Restocking Fee', 'zorem-returns' ),
				'description' => __( 'Override the global restocking fee for this product, or exempt it.', 'zorem-returns' ),
				'desc_tip'    => true,
				'value'       => $mode,
				'options'     => array(
					''         => __( 'Use global setting', 'zorem-returns' ),
					'override' => __( 'Override (custom amount)', 'zorem-returns' ),
					'exempt'   => __( 'Exempt (no fee)', 'zorem-returns' ),
				),
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => '_zorem_restocking_fee_type',
				'label'         => __( 'Fee Type', 'zorem-returns' ),
				'value'         => $type ? $type : 'percentage',
				'wrapper_class' => 'zorem-restocking-fee-override-row',
				'options'       => array(
					'percentage' => __( 'Percentage (%)', 'zorem-returns' ),
					'fixed'      => __( 'Fixed amount per item', 'zorem-returns' ),
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_zorem_restocking_fee_amount',
				'label'         => __( 'Fee Amount', 'zorem-returns' ),
				'value'         => $amount,
				'wrapper_class' => 'zorem-restocking-fee-override-row',
				'type'          => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			)
		);

		echo '</div>';

		// Lightweight inline JS to show/hide override fields based on mode.
		?>
		<script>
		(function($){
			$(function(){
				var $sel = $('#_zorem_restocking_fee_mode');
				function toggle(){
					var v = $sel.val();
					$('.zorem-restocking-fee-override-row').toggle( v === 'override' );
				}
				toggle();
				$sel.on('change', toggle);
			});
		})(jQuery);
		</script>
		<?php
	}

	public function save_product_fields( $post_id ) {
		// Verify our own nonce, emitted by render_product_fields().
		if ( ! isset( $_POST['zorem_restocking_fee_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zorem_restocking_fee_product_nonce'] ) ), 'zorem_save_product_restocking_fee' ) ) {
			return;
		}

		$mode = isset( $_POST['_zorem_restocking_fee_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['_zorem_restocking_fee_mode'] ) ) : '';
		update_post_meta( $post_id, '_zorem_restocking_fee_mode', $mode );

		if ( 'override' === $mode ) {
			$type   = isset( $_POST['_zorem_restocking_fee_type'] ) ? sanitize_text_field( wp_unslash( $_POST['_zorem_restocking_fee_type'] ) ) : 'percentage';
			$amount = isset( $_POST['_zorem_restocking_fee_amount'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_zorem_restocking_fee_amount'] ) ) ) : '';
			update_post_meta( $post_id, '_zorem_restocking_fee_type', $type );
			update_post_meta( $post_id, '_zorem_restocking_fee_amount', $amount );
		} else {
			delete_post_meta( $post_id, '_zorem_restocking_fee_type' );
			delete_post_meta( $post_id, '_zorem_restocking_fee_amount' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Per-category fields
	 * --------------------------------------------------------------------- */

	public function render_category_add_fields() {
		wp_nonce_field( 'zorem_save_category_restocking_fee', 'zorem_restocking_fee_category_nonce' );
		?>
		<div class="form-field">
			<label for="zorem_restocking_fee_mode"><?php esc_html_e( 'Restocking Fee', 'zorem-returns' ); ?></label>
			<select name="_zorem_restocking_fee_mode" id="zorem_restocking_fee_mode">
				<option value=""><?php esc_html_e( 'Use global setting', 'zorem-returns' ); ?></option>
				<option value="override"><?php esc_html_e( 'Override (custom amount)', 'zorem-returns' ); ?></option>
				<option value="exempt"><?php esc_html_e( 'Exempt (no fee)', 'zorem-returns' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Override the global restocking fee for products in this category.', 'zorem-returns' ); ?></p>
		</div>
		<div class="form-field zorem-restocking-fee-cat-override-row">
			<label for="zorem_restocking_fee_type"><?php esc_html_e( 'Fee Type', 'zorem-returns' ); ?></label>
			<select name="_zorem_restocking_fee_type" id="zorem_restocking_fee_type">
				<option value="percentage"><?php esc_html_e( 'Percentage (%)', 'zorem-returns' ); ?></option>
				<option value="fixed"><?php esc_html_e( 'Fixed amount per item', 'zorem-returns' ); ?></option>
			</select>
		</div>
		<div class="form-field zorem-restocking-fee-cat-override-row">
			<label for="zorem_restocking_fee_amount"><?php esc_html_e( 'Fee Amount', 'zorem-returns' ); ?></label>
			<input type="number" step="0.01" min="0" name="_zorem_restocking_fee_amount" id="zorem_restocking_fee_amount" value="" />
		</div>
		<?php
		$this->print_category_toggle_script();
	}

	public function render_category_edit_fields( $term ) {
		$mode   = get_term_meta( $term->term_id, '_zorem_restocking_fee_mode', true );
		$type   = get_term_meta( $term->term_id, '_zorem_restocking_fee_type', true );
		$amount = get_term_meta( $term->term_id, '_zorem_restocking_fee_amount', true );
		wp_nonce_field( 'zorem_save_category_restocking_fee', 'zorem_restocking_fee_category_nonce' );
		?>
		<tr class="form-field">
			<th><label for="zorem_restocking_fee_mode"><?php esc_html_e( 'Restocking Fee', 'zorem-returns' ); ?></label></th>
			<td>
				<select name="_zorem_restocking_fee_mode" id="zorem_restocking_fee_mode">
					<option value="" <?php selected( $mode, '' ); ?>><?php esc_html_e( 'Use global setting', 'zorem-returns' ); ?></option>
					<option value="override" <?php selected( $mode, 'override' ); ?>><?php esc_html_e( 'Override (custom amount)', 'zorem-returns' ); ?></option>
					<option value="exempt" <?php selected( $mode, 'exempt' ); ?>><?php esc_html_e( 'Exempt (no fee)', 'zorem-returns' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Override the global restocking fee for products in this category.', 'zorem-returns' ); ?></p>
			</td>
		</tr>
		<tr class="form-field zorem-restocking-fee-cat-override-row">
			<th><label for="zorem_restocking_fee_type"><?php esc_html_e( 'Fee Type', 'zorem-returns' ); ?></label></th>
			<td>
				<select name="_zorem_restocking_fee_type" id="zorem_restocking_fee_type">
					<option value="percentage" <?php selected( $type, 'percentage' ); ?>><?php esc_html_e( 'Percentage (%)', 'zorem-returns' ); ?></option>
					<option value="fixed" <?php selected( $type, 'fixed' ); ?>><?php esc_html_e( 'Fixed amount per item', 'zorem-returns' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="form-field zorem-restocking-fee-cat-override-row">
			<th><label for="zorem_restocking_fee_amount"><?php esc_html_e( 'Fee Amount', 'zorem-returns' ); ?></label></th>
			<td><input type="number" step="0.01" min="0" name="_zorem_restocking_fee_amount" id="zorem_restocking_fee_amount" value="<?php echo esc_attr( $amount ); ?>" /></td>
		</tr>
		<?php
		$this->print_category_toggle_script();
	}

	/**
	 * Inline JS that shows the Fee Type / Fee Amount rows only when mode is "override".
	 * Used on both the category add and category edit screens.
	 */
	private function print_category_toggle_script() {
		?>
		<script>
		(function($){
			$(function(){
				var $sel = $('#zorem_restocking_fee_mode');
				if ( ! $sel.length ) {
					return;
				}
				function toggle(){
					var v = $sel.val();
					$('.zorem-restocking-fee-cat-override-row').toggle( v === 'override' );
				}
				toggle();
				$sel.on('change', toggle);
			});
		})(jQuery);
		</script>
		<?php
	}

	public function save_category_fields( $term_id ) {
		// Verify our own nonce, emitted by render_category_add_fields() / render_category_edit_fields().
		if ( ! isset( $_POST['zorem_restocking_fee_category_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['zorem_restocking_fee_category_nonce'] ) ), 'zorem_save_category_restocking_fee' ) ) {
			return;
		}

		$mode = isset( $_POST['_zorem_restocking_fee_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['_zorem_restocking_fee_mode'] ) ) : '';
		update_term_meta( $term_id, '_zorem_restocking_fee_mode', $mode );

		if ( 'override' === $mode ) {
			$type   = isset( $_POST['_zorem_restocking_fee_type'] ) ? sanitize_text_field( wp_unslash( $_POST['_zorem_restocking_fee_type'] ) ) : 'percentage';
			$amount = isset( $_POST['_zorem_restocking_fee_amount'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_zorem_restocking_fee_amount'] ) ) ) : '';
			update_term_meta( $term_id, '_zorem_restocking_fee_type', $type );
			update_term_meta( $term_id, '_zorem_restocking_fee_amount', $amount );
		} else {
			delete_term_meta( $term_id, '_zorem_restocking_fee_type' );
			delete_term_meta( $term_id, '_zorem_restocking_fee_amount' );
		}
	}
}

Restocking_Fee::get_instance();
