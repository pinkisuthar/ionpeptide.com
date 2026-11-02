<?php
/**
 * Store credits checkout discount row.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/acfw-store-credits/checkout-discount.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package ACFWF\Templates
 * @version 4.5.7
 */
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}?>

<?php
if ( ! isset( $display_order_total ) ) {
    $display_order_total = true;
}
?>

<?php if ( $display_order_total ) : ?>
<tr class="acfw-order-total">
    <th><?php echo esc_html__( 'Order Total', 'advanced-coupons-for-woocommerce-free' ); ?></th>
    <td><strong><?php echo wc_price( $order_total ); ?></strong></td>
</tr>
<?php endif; ?>

<tr class="acfw-store-credits-balance-row">
    <th><?php echo esc_html__( 'Pay with Store Credits', 'advanced-coupons-for-woocommerce-free' ); ?></th>
    <td>
        <span class="balance-value">
            <strong><?php echo wc_price( $amount ); ?></strong>
            <a class="acfw-remove-store-credits" href="javascript:void(0)"><?php echo esc_html__( '[Remove]', 'advanced-coupons-for-woocommerce-free' ); ?></a>
        </span>
    </td>
</tr>
