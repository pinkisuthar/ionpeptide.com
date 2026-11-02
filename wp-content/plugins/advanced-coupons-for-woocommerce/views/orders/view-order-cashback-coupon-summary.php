<?php if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}?>

<tr class="acfwp-cashback-row">
    <td class="label" colspan="3" style="padding-top: 40px;"></td>
</tr>

<tr class="acfwp-cashback-row">
    <td class="label" colspan="3" style="border-top: 1px dotted #999; margin-top:22px; padding-top:12px; height: 1px;">
        <strong><?php esc_html_e( 'Cashback (store credits)', 'advanced-coupons-for-woocommerce' ); ?></strong>
    </td>
</tr>

<?php
foreach ( $cashback_coupons as $row ) :
    $datetime = new \WC_DateTime( $row['schedule'] ?? 'now' );
    $datetime->setTimezone( new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );
?>
    <tr class="acfwp-cashback-row">
        <td class="label">
            <?php echo esc_html( $row['code'] ) . ':'; ?>
        </td>
        <td width="1%"></td>
        <td class="total">
            <?php
                echo wp_kses_post( wc_price( $row['amount'] ) );
                if ( 'scheduled' === $row['status'] ) :
                    echo wp_kses_post(
                        sprintf(
                            ' (<a href="%s" title="%s">%s</a>)',
                            admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=acfwp_cashback_' . $row['item_id'] ),
                            $datetime->format( $this->_helper_functions->get_datetime_format() ),
                            __( 'scheduled', 'advanced-coupons-for-woocommerce' )
                        )
                    );
                elseif ( 'pending' === $row['status'] ) :
                    echo esc_html( sprintf( ' (%s)', __( 'pending', 'advanced-coupons-for-woocommerce' ) ) );
                endif;
            ?>
        </td>
    </tr>
<?php endforeach; ?>
<?php if ( $revoked_cashback ) : ?>
    <tr class="acfwp-cashback-row">
        <td class="label refunded-total">
            <?php echo esc_html__( 'Revoked cashback', 'advanced-coupons-for-woocommerce' ) . ':'; ?>
        </td>
        <td width="1%"></td>
        <td class="total refunded-total">
            <?php
                echo '-' . wp_kses_post( wc_price( $revoked_cashback->get_prop( 'amount' ) ) );
            ?>
        </td>
    </tr>
<?php endif; ?>
