<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} ?>

<div id="<?php echo esc_attr( $panel_id ); ?>" class="panel woocommerce_options_panel acfw-generic-panel <?php echo isset( $additional_classes ) ? esc_attr( $additional_classes ) : ''; ?>">
    <div class="acfw-help-link" data-module="<?php echo esc_attr( $help_slug ); ?>"></div>
    <div class="acfw-tab-info">
        <h3><?php echo esc_html( $title ); ?></h3>
        <?php if ( is_array( $descriptions ) && ! empty( $descriptions ) ) : ?>
            <?php foreach ( $descriptions as $description ) : ?>
                <p><?php echo wp_kses_post( $description ); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="acfw-date-range-schedules-section acfw-scheduler-section">
        <label class="acfw-section-toggle">
            <input type="checkbox" name="_acfw_enable_date_range_schedule" value="yes" <?php checked( true, $is_enabled ); ?> />
            <span><?php esc_attr_e( 'Date Range Schedules', 'advanced-coupons-for-woocommerce-free' ); ?></span>
        </label>
        <div class="options_group">
        <?php
            foreach ( $fields as $field ) :
                if ( is_array( $field['cb'] ) ) {
                    call_user_func_array( $field['cb'], array( $field['args'] ) );
                } else {
                    $field['cb']( $field['args'] );
                }
            endforeach;
        ?>
        </div>
    </div>

<?php do_action( 'acfw_after_scheduler_panel', $coupon ); ?>

</div><!-- url_coupon_data -->
