<?php if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Exit if accessed directly. ?>

<div class="more-cart-conditions panel" data-tab="moreconditions">
    <h2><?php esc_html_e( 'More Cart Conditions (Premium)', 'advanced-coupons-for-woocommerce-free' ); ?></h2>
    <p>
        <?php
        printf(
            wp_kses_post(
                /* translators: %s: URL to Advanced Coupons Premium pricing page */
                __( 'Unlock the full power of Cart Conditions with <a href="%s" target="_blank" rel="norefer noopener">Advanced Coupons Premium</a>. Restrict your coupons using these premium cart conditions.', 'advanced-coupons-for-woocommerce-free' )
            ),
            esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'morecartconditionslink' ) )
        );
        ?>
    </p>

    <div class="cart-conditions-list">
        <?php foreach ( $cart_conditions as $cart_condition ) : ?>
            <div class="cart-condition">
                <h4><?php echo esc_attr( $cart_condition['title'] ); ?></h4>
                <p><?php echo esc_attr( $cart_condition['description'] ); ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <a class="button button-primary button-large" href="<?php echo esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'morecartconditionsbutton' ) ); ?>" target="_blank" rel="norefer noopener">See all features & pricing →</a>
</div>

<div class="acfw-dyk-notice-holder" style="display: none";>
<?php
\ACFWF()->Notices->display_did_you_know_notice(
    array(
        'classname'   => 'acfw-dyk-notice-cart-conditions-select',
        'description' => __( 'You can unlock a whole range of extra cart conditions.', 'advanced-coupons-for-woocommerce-free' ),
        'button_link' => \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'cartconditiontiplink' ),
    )
);
?>
</div>
