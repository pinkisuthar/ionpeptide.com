<?php
/**
 * Coupon tab containing affiliate's settings
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var WC_Coupon $coupon
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>
<div id="affiliates_coupon_data" class="panel woocommerce_options_panel">
	<?php
	$skip_commissions = wc_string_to_bool( $coupon->get_meta( 'skip_commissions' ) );
	woocommerce_wp_checkbox(
		array(
			'id'            => 'skip_commissions',
			'label'         => _x( 'Skip commissions', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ),
			'wrapper_class' => 'form-row',
			'description'   => _x( 'Skip commissions creation for orders that contain this coupon.', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ),
			'value'         => wc_bool_to_string( $skip_commissions ),
		)
	);
	?>
	<hr>
	<?php
	$subtract_from_commissions = wc_string_to_bool( $coupon->get_meta( 'subtract_from_commissions' ) );
	woocommerce_wp_checkbox(
		array(
			'id'                => 'subtract_from_commissions',
			'label'             => _x( 'Subtract from commissions', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ),
			'wrapper_class'     => 'form-row',
			'description'       => _x( 'When enabled, apply discount to the order, but at the same time subtract discount percentage from affiliate rate used in commissions calculation.', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ),
			'value'             => wc_bool_to_string( $subtract_from_commissions ),
			'custom_attributes' => array(
				'data-dependencies' => '{"skip_commissions":"!:checked","discount_type":"percent"}',
			),
		)
	);
	?>
	<?php if ( YITH_WCAF_Coupons()->are_coupons_enabled() ) : ?>
	<div class="form-row">
		<p class="form-field">
			<label for="coupon_referrer">
				<?php echo esc_html_x( 'Affiliate', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ); ?>
			</label>
			<select
				id="coupon_referrer"
				class="yith-wcaf-enhanced-select"
				name="coupon_referrer"
				style="width: 50%;"
				data-placeholder="<?php echo esc_attr_x( 'Search for an affiliate&hellip;', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ); ?>"
				data-action="yith_wcaf_get_affiliates_ids"
				data-security="<?php echo esc_attr( wp_create_nonce( 'search-affiliates' ) ); ?>"
				data-allow_clear="true"
				data-dependencies='{"skip_commissions":"!:checked"}'
			>
				<?php
				$affiliate_id = $coupon->get_meta( 'coupon_referrer' );
				$affiliate    = YITH_WCAF_Affiliates()->get_affiliate_by_id( $affiliate_id );

				if ( $affiliate ) {
					$affiliate_formatted_name = $affiliate->get_formatted_name() . ' (#' . $affiliate->get_user_id() . ' &ndash; ' . $affiliate->get_user()->user_email . ')';

					echo '<option value="' . esc_attr( $affiliate_id ) . '"' . selected( true, true, false ) . '>' . wp_kses_post( $affiliate_formatted_name ) . '</option>';
				}
				?>
			</select>
			<?php echo wp_kses_post( wc_help_tip( _x( 'User that will be referred when someone purchases with this coupon', '[ADMIN] Coupon affiliate tab', 'yith-woocommerce-affiliates' ) ) ); ?>
		</p>
	</div>
	<?php endif; ?>
</div>
