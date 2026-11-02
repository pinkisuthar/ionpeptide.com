<?php
/**
 * Store Credit Adjustment - notification email.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/store-credit-adjustment.php.
 *
 * @version 4.7.1
 */
defined( 'ABSPATH' ) || exit;

$base      = get_option( 'woocommerce_email_base_color' );
$base_text = wc_light_or_dark( $base, '#202020', '#ffffff' );
$shop_url  = get_permalink( wc_get_page_id( 'shop' ) );

// Get entry data.
$amount     = $store_credit_entry->get_prop( 'amount', 'edit' );
$entry_type = $store_credit_entry->get_prop( 'type', 'edit' );
$note       = $store_credit_entry->get_prop( 'note', 'edit' );

// Determine if it's an increase or decrease.
$is_increase    = 'increase' === $entry_type;
$type_label     = $is_increase ? __( 'credited to', 'advanced-coupons-for-woocommerce-free' ) : __( 'deducted from', 'advanced-coupons-for-woocommerce-free' );
$amount_display = \ACFWF()->Helper_Functions->api_wc_price( $amount );
$amount_color   = $is_increase ? '#28a745' : '#dc3545';

do_action( 'acfw_email_header', $email_heading, $email ); ?>

<p style="text-align: center;"><?php echo wp_kses_post( $email->get_message() ); ?></p>

<div style="margin: 30px auto; max-width: 400px; background: #f7f7f7; border-radius: 8px; padding: 25px; text-align: center;">
    <p style="margin: 0 0 15px; font-size: 14px; color: #666;">
        <?php
        $amount_html = '<strong style="color: ' . esc_attr( $amount_color ) . ';">' . wp_kses_post( $amount_display ) . '</strong>';
        printf(
            /* Translators: %1$s: formatted amount HTML, %2$s: credited to/deducted from */
            esc_html__( '%1$s has been %2$s your store credit balance.', 'advanced-coupons-for-woocommerce-free' ),
            wp_kses_post( $amount_html ),
            esc_html( $type_label )
        );
        ?>
    </p>

    <?php if ( ! empty( $note ) ) : ?>
        <p style="margin: 15px 0; padding: 10px; background: #fff; border-left: 3px solid <?php echo esc_attr( $base ); ?>; text-align: left; font-size: 13px; color: #555;">
            <strong><?php esc_html_e( 'Note:', 'advanced-coupons-for-woocommerce-free' ); ?></strong><br>
            <?php echo esc_html( $note ); ?>
        </p>
    <?php endif; ?>

    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
        <p style="margin: 0; font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 1px;">
            <?php esc_html_e( 'Your New Balance', 'advanced-coupons-for-woocommerce-free' ); ?>
        </p>
        <p style="margin: 5px 0 0; font-size: 28px; font-weight: bold; color: <?php echo esc_attr( $base ); ?>;">
            <?php echo wp_kses_post( $new_balance ); ?>
        </p>
    </div>
</div>

<p style="text-align:center;">
    <a href="<?php echo esc_url( $shop_url ); ?>" style="cursor: pointer; display: inline-block; padding: 0.6em 3.5em; text-decoration: none; font-weight: 600; background-color: <?php echo esc_attr( $base ); ?>; border-color: <?php echo esc_attr( $base_text ); ?>; color: #ffffff; font-size: 1.2em; border-radius: 4px;">
        <?php echo esc_html( $email->get_button_text() ); ?>
    </a>
</p>

<?php if ( $additional_content ) : ?>
    <div style="text-align: center; margin-top: 20px;">
        <?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
    </div>
<?php endif; ?>

<?php
do_action( 'acfw_email_footer', $email );
