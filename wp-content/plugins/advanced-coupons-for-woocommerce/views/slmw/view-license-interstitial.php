<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;}  ?>

<div id="acfw-license-interstitial" class="<?php echo esc_attr( $classname ); ?>">
    <div class="interstitial-content">
        <div class="heading">
            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL . 'acfw-logo.png' ); ?>" alt="ACFW logo" />
        </div>

        <h2 class="title"><em><?php esc_html_e( 'Urgent!', 'advanced-coupons-for-woocommerce' ); ?></em> <?php echo wp_kses_post( $title ); ?></h2>

        <p><?php echo wp_kses_post( $description ); ?></p>

        <p><img class="lock-image" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL . 'ac-locked.png' ); ?>" alt="locked" /></p>

        <ul>
            <?php foreach ( $license_statuses as $key => $license_status ) : ?>
                <li><?php echo esc_html( $license_status['plugin_name'] ?? '' ); ?>: <span><?php echo esc_html( $license_status['status'] ); ?></span></li>
            <?php endforeach; ?>
        </ul>

        <div class="actions">
            <a class="interstitial-cta button-primary" href="<?php echo esc_url( $action_url ); ?>" rel="noopener" target="_blank"><?php echo esc_html( $action_text ); ?></a>
        </div>

        <div class="sub-actions">
            <a href="<?php echo esc_url( $sub_action_url ); ?>"><?php echo esc_html( $sub_action_text ); ?></a>
            <?php if ( $show_refresh_license ) : ?>
                <span class="separator">|</span>
                <a class="refresh-license-status" href="#"><?php esc_attr_e( 'Refresh license status', 'advanced-coupons-for-woocommerce' ); ?></a>
            <?php endif; ?>
        </div>

        <a class="dismiss-button" href="<?php echo esc_url( $this->_constants->get_license_page_url() ); ?>">
            <svg width="23" height="23" viewBox="0 0 23 23" fill="none" xmlns="http://www.w3.org/2000/svg">
                <line x1="22.3536" y1="0.353553" x2="0.353553" y2="22.3536" stroke="black"/>
                <line x1="0.353553" y1="0.646447" x2="22.3536" y2="22.6464" stroke="black"/>
            </svg>
        </a>
    </div>
</div>

<?php
do_action( 'acfw_after_license_post_expired_interstitial' );
