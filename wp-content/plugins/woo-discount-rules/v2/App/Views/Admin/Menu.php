<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly
?>
<div class="wdr">
    <div class="wdr-alert-top-right" id="notify-msg-holder"></div>
    <?php if ( ! \Wdr\App\Helpers\Helper::hasPro() ) : ?>
    <div style="width:100%;box-sizing:border-box;background:linear-gradient(to right,#3a2db5,#6254f3);border-radius:8px;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px;box-shadow:0 2px 10px rgba(79,71,235,0.4);flex-wrap:nowrap;">
        <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;">
            <div style="flex-shrink:0;background:rgba(255,255,255,0.15);border-radius:50%;padding:8px;display:flex;align-items:center;justify-content:center;">
                <svg style="width:20px;height:20px;" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                    <polyline points="20 12 20 22 4 22 4 12"/>
                    <rect x="2" y="7" width="20" height="5"/>
                    <line x1="12" y1="22" x2="12" y2="7"/>
                    <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/>
                    <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
                </svg>
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;min-width:0;">
                <p style="margin:0;color:#fff;font-weight:600;font-size:13px;line-height:1.3;">
                    <?php esc_html_e( 'Sell more with advanced discounts. Upgrade to Discount Rules PRO. Get 20% OFF on the PRO.', 'woo-discount-rules' ); ?>
                </p>
                <p style="margin:0;color:rgba(255,255,255,0.78);font-size:12px;line-height:1.4;">
                    <?php esc_html_e( 'Go beyond simple coupons with BOGO, bulk pricing, scheduled sales, role-based discounts, and dynamic cart rules.', 'woo-discount-rules' ); ?>
                </p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;background:rgba(255,255,255,0.1);border:1px dashed rgba(255,255,255,0.4);border-radius:8px;padding:8px 12px;">
            <span style="color:#fff;font-weight:600;font-size:12px;white-space:nowrap;"><?php esc_html_e( 'Coupon', 'woo-discount-rules' ); ?></span>
            <span style="color:rgba(255,255,255,0.3);user-select:none;">|</span>
            <span id="awdr-upgrade-coupon-code" style="color:#fff;font-family:monospace;font-weight:700;font-size:13px;letter-spacing:0.1em;background:rgba(255,255,255,0.12);padding:2px 8px;border-radius:4px;">UPGRADE20</span>
            <button id="awdr-copy-coupon-btn" onclick="awdrCopyCoupon()" title="<?php esc_attr_e( 'Copy coupon code', 'woo-discount-rules' ); ?>" style="background:transparent;border:none;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-left:2px;">
                <svg id="awdr-copy-icon" style="width:16px;height:16px;" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="1.8" viewBox="0 0 24 24">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>
                </svg>
                <svg id="awdr-check-icon" style="width:16px;height:16px;display:none;" fill="none" stroke="#86efac" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </button>
        </div>
        <a href="<?php echo esc_url( 'https://www.flycart.org/products/wordpress/woocommerce-discount-rules?utm_source=woo-discount-rules-v2&utm_medium=plugin&utm_campaign=upgrade_banner&utm_content=upgrade_pro' ); ?>" target="_blank" style="flex-shrink:0;background:#fff;color:#3a2db5;font-weight:600;font-size:13px;padding:8px 16px;border-radius:6px;text-decoration:none;white-space:nowrap;display:inline-block;">
            <?php esc_html_e( 'Upgrade to PRO →', 'woo-discount-rules' ); ?>
        </a>
    </div>
    <script>
    function awdrCopyCoupon() {
        var code = document.getElementById('awdr-upgrade-coupon-code').innerText;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code);
        } else {
            var el = document.createElement('textarea');
            el.value = code; document.body.appendChild(el); el.select();
            document.execCommand('copy'); document.body.removeChild(el);
        }
        document.getElementById('awdr-copy-icon').style.display = 'none';
        document.getElementById('awdr-check-icon').style.display = 'inline';
        setTimeout(function() {
            document.getElementById('awdr-copy-icon').style.display = 'inline';
            document.getElementById('awdr-check-icon').style.display = 'none';
        }, 2000);
    }
    </script>
    <?php endif; ?>
    <h2 class="wdr_tabs_container nav-tab-wrapper">
        <?php foreach ($tabs as $awdr_tab_key => $awdr_tab_handler) {
            $awdr_params = array(
                'page' => WDR_SLUG,
                'tab' => $awdr_tab_key
            );
            $awdr_target = '';
            $awdr_link = admin_url('admin.php?' . http_build_query($awdr_params));
            // if ($awdr_tab_key === 'help') {
            //$awdr_link = 'https://docs.flycart.org/en/collections/806883-discount-rules-for-woocommerce';
            //  $awdr_target = 'target="_blank"';
            //  }
            ?>
            <a class="nav-tab <?php echo esc_attr(($awdr_tab_key === $current_tab ? 'nav-tab-active' : '')); ?>"
               style="<?php echo ($awdr_tab_key === 'help') ? 'background: cornflowerblue;color: white;' : ''; ?>"
               href="<?php echo esc_url($awdr_link); ?>" <?php echo esc_attr($awdr_target); ?>><?php echo esc_html($awdr_tab_handler->title); ?></a>
        <?php } ?>
        <span class="awdr_version_text"> <?php echo esc_html('v' . (defined('WDR_VERSION') ? WDR_VERSION : '2.0.0 + ') . ' '); ?> </span>
        <?php
        if (isset($on_sale_page_rebuild['available']) && $on_sale_page_rebuild['available']) {
            $awdr_additional_class_for_rebuild = '';
            if ($on_sale_page_rebuild['required_rebuild'] === true) {
                $awdr_additional_class_for_rebuild = ' need_attention';
            }
            ?>
            <span class="awdr_rebuild_on_sale_rule_page_con<?php echo esc_attr($awdr_additional_class_for_rebuild); ?>">
                <button type="button" class="btn btn-danger"
                        id="awdr_rebuild_on_sale_list_on_rule_page" data-awdr_nonce="<?php echo esc_attr(\Wdr\App\Helpers\Helper::create_nonce('wdr_ajax_rule_build_index')); ?>"><?php esc_html_e('Rebuild index', 'woo-discount-rules'); ?></button>
            </span>
            <?php
        }

        do_action('advanced_woo_discount_rules_content_next_to_tabs');
        ?>
    </h2>

    <div class="wdr_settings">
        <?php
        \Wdr\App\Helpers\Helper::displayCompatibleCheckMessages();
        ?>
        <div class="wdr_settings_container">
            <?php
            $handler->render($page);
            ?>
        </div>
        <div class="woo_discount_loader">
            <div class="lds-ripple">
                <div></div><div></div>
            </div>
        </div>
    </div>
    <div class="clear"></div>
</div>