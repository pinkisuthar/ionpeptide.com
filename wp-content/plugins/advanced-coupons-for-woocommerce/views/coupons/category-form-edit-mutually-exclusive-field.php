<?php
/**
 * Template for the Category Mutually Exclusive Field on the Edit Coupon Category Taxonomy Form.
 *
 * @since 3.5.5
 */

// Avoid undefined variable notice.
$mutually_exclusive = $mutually_exclusive ?? false;
?>

<tr class="form-field term-acfwp-mutually-exclusive-wrap">
    <th scope="row">
        <label for="acfwp-mutually-exclusive"><?php echo esc_html( __( 'Mutually Exclusive', 'advanced-coupons-for-woocommerce' ) ); ?></label>
    </th>
    <td>
        <div>
            <input
                name="<?php echo esc_attr( $this->_constants->MUTUALLY_EXCLUSIVE ); ?>"
                id="tag-acfwp-mutually-exclusive"
                type="checkbox"
                size="40"
                aria-describedby="acfwp-mutually-exclusive-description"
                <?php echo 'yes' === $mutually_exclusive ? esc_attr( 'checked' ) : ''; ?>
            />
            <span><?php echo esc_html( __( 'Enable Mutually Exclusive Option', 'advanced-coupons-for-woocommerce' ) ); ?></span>
        </div>
        <p class="description" id="acfwp-mutually-exclusive-description"><?php echo esc_html( __( 'Enabling this option will ensure that only a single coupon belonging to the selected category can be applied to the cart, preventing multiple coupons of the same category from being added to the order.', 'advanced-coupons-for-woocommerce' ) ); ?></p>
    </td>
</tr>
