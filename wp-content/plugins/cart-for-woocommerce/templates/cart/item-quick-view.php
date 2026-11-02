<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="fkcart-quick-view-drawer">
    <div class="fkcart-drawer-header">
        <div class="fkcart-drawer-header-panel fkcart-panel">
            <div class="fkcart-drawer-header-heading"><?php _e( 'Select options', 'woocommerce' ); ?></div>
            <div class="fkcart-quick-view-close">
				<?php fkcart_get_template_part( 'icon/close', '', [ 'width' => 20, 'height' => 20 ] ); ?>
            </div>
        </div>
    </div>
    <div class="fkcart-drawer-content">
    </div>
    <!-- HTML FOR SHIMMER/LOADING EFFECT -->
    <div class="fkcart-drawer-shimmer">
        <div class="fkcart-product-meta fkcart-pt-20 fkcart-panel">
            <div class="fkcart-image-wrapper fkcart-shimmer" style="width: 60%; padding-bottom: 40%; border-radius: 6px; margin: 0 auto; display: block;">
            </div>
            <div class="fkcart-item-title fkcart-shimmer" style="width: 40%;height:18px;display: block;margin: 16px auto 24px;"></div>
        </div>
        <div class="fkcart-product-form-wrap">
            <div class="fkcart-product-form-fields fkcart-panel">
                <div class="fkcart-product-form-field">
                    <div class="fkcart-input-label fkcart-shimmer" style="width:60px;height:18px"></div>
                    <div class="fkcart-form-input-wrap fkcart-shimmer" style="width:182px;height:24px">
                    </div>
                </div>
                <div class="fkcart-product-form-field">
                    <div class="fkcart-input-label fkcart-shimmer" style="width:60px;height:18px"></div>
                    <div class="fkcart-form-input-wrap fkcart-shimmer" style="width:182px;height:24px">
                    </div>
                </div>
            </div>
        </div>
        <div class="fkcart-desc-title fkcart-panel fkcart-shimmer" style="width:120px;height:18px;margin: 24px 16px 10px;"></div>
        <div class="fkcart-product-description fkcart-panel fkcart-p-10 fkcart-shimmer" style="height:80px;margin:0 16px"></div>
        <div class="fkcart-view-link-wrap fkcart-panel fkcart-shimmer" style="width:70px;height:18px;margin: 16px;"></div>
        <div class="fkcart-product-form-button fkcart-panel">
            <div class="fkcart-shimmer" style="width:100%;height:44px;    margin-top: 10px;"></div>
        </div>
    </div>
</div>
