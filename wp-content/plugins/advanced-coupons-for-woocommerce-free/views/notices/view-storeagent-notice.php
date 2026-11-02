<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Exit if accessed directly.
?>

<div id="acfwf-storeagent-notice" class="acfwf-storeagent-notice">
    <style>
        .acfwf-storeagent-notice {
            display: flex;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-left: 4px solid #6E3FF3;
            margin: 15px 0;
            padding: 0;
            border-radius: 4px;
            overflow: hidden;
            position: relative;
        }
        .acfwf-storeagent-notice-sidebar {
            background: #F3F0FF;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 25px;
            min-width: 80px;
        }
        .acfwf-storeagent-notice-sidebar img {
            width: 110px;
            height: auto;
        }
        .acfwf-storeagent-notice-content {
            padding: 15px 40px 15px 20px;
            flex: 1;
        }
        .acfwf-storeagent-notice-content h3 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
        }
        .acfwf-storeagent-notice-content p {
            margin: 0 0 12px;
            font-size: 13px;
            color: #50575e;
            line-height: 1.5;
        }
        .acfwf-storeagent-notice-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .acfwf-storeagent-notice-actions .button-primary {
            background: #6E3FF3;
            border-color: #6E3FF3;
            color: #fff;
            padding: 4px 16px;
            font-size: 13px;
            line-height: 1.8;
            height: auto;
            text-decoration: none;
        }
        .acfwf-storeagent-notice-actions .button-primary:hover,
        .acfwf-storeagent-notice-actions .button-primary:focus {
            background: #5B2FD9;
            border-color: #5B2FD9;
            color: #fff;
        }
        .acfwf-storeagent-notice-actions .button-primary:disabled {
            background: #6E3FF3 !important;
            border-color: #6E3FF3 !important;
            color: #fff !important;
            opacity: 0.7;
        }
        .acfwf-storeagent-notice-actions a.acfwf-storeagent-learn-more {
            color: #6E3FF3;
            text-decoration: none;
            font-size: 13px;
        }
        .acfwf-storeagent-notice-actions a.acfwf-storeagent-learn-more:hover {
            text-decoration: underline;
        }
        .acfwf-storeagent-notice-dismiss {
            position: absolute;
            top: 8px;
            right: 10px;
            background: none;
            border: none;
            cursor: pointer;
            color: #787c82;
            font-size: 16px;
            line-height: 1;
            padding: 4px;
        }
        .acfwf-storeagent-notice-dismiss:hover {
            color: #d63638;
        }
        .woocommerce-layout__notice-list-hide ~ .acfwf-storeagent-notice {
            margin-left: 20px;
            margin-right: 20px;
        }
    </style>

    <div class="acfwf-storeagent-notice-sidebar">
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'StoreAgent', 'advanced-coupons-for-woocommerce-free' ); ?>" />
    </div>

    <div class="acfwf-storeagent-notice-content">
        <h3><?php esc_html_e( 'Boost Your Store with AI-Powered Customer Support', 'advanced-coupons-for-woocommerce-free' ); ?></h3>
        <p>
            <?php
            esc_html_e(
                'Want to cut down on repetitive support questions and give your customers instant answers? Our sister plugin, StoreAgent, adds an AI-powered chat assistant to your store. It learns from your products and policies to deliver accurate, 24/7 support—saving you time and boosting conversions.',
                'advanced-coupons-for-woocommerce-free'
            );
            ?>
        </p>
        <div class="acfwf-storeagent-notice-actions">
            <button type="button" class="button button-primary acfwf-storeagent-install-btn" data-nonce="<?php echo esc_attr( $install_nonce ); ?>" data-plugin-slug="<?php echo esc_attr( $plugin_slug ); ?>">
                <?php echo esc_html( $button_text ); ?>
            </button>
            <a href="https://storeagent.ai/?utm_source=acfwf&amp;utm_medium=admin_notice&amp;utm_campaign=storeagent_cross_promo" class="acfwf-storeagent-learn-more" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e( 'Learn More', 'advanced-coupons-for-woocommerce-free' ); ?>
            </a>
        </div>
    </div>

    <button type="button" class="acfwf-storeagent-notice-dismiss" data-nonce="<?php echo esc_attr( $dismiss_nonce ); ?>" title="<?php esc_attr_e( 'Dismiss', 'advanced-coupons-for-woocommerce-free' ); ?>">
        <span class="dashicons dashicons-no-alt"></span>
    </button>

    <script>
    jQuery(document).ready(function($) {
        // Dismiss notice.
        $('#acfwf-storeagent-notice .acfwf-storeagent-notice-dismiss').on('click', function() {
            var $notice = $('#acfwf-storeagent-notice');
            $.post(ajaxurl, {
                action: 'acfwf_dismiss_storeagent_notice',
                nonce: $(this).data('nonce')
            });
            $notice.fadeOut(300, function() {
                $notice.remove();
            });
        });

        // Install & Activate.
        $('#acfwf-storeagent-notice .acfwf-storeagent-install-btn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Installing...', 'advanced-coupons-for-woocommerce-free' ) ); ?>');

            $.post(ajaxurl, {
                action: 'acfw_install_activate_plugin',
                nonce: $btn.data('nonce'),
                plugin_slug: $btn.attr('data-plugin-slug')
            })
            .done(function(response) {
                if (response.success) {
                    $btn.text('<?php echo esc_js( __( 'Activated! Reloading...', 'advanced-coupons-for-woocommerce-free' ) ); ?>');
                    // Dismiss the notice so it won't show again, then reload once the request settles.
                    $.post(ajaxurl, {
                        action: 'acfwf_dismiss_storeagent_notice',
                        nonce: $('#acfwf-storeagent-notice .acfwf-storeagent-notice-dismiss').data('nonce')
                    }).always(function() {
                        location.reload();
                    });
                } else {
                    $btn.prop('disabled', false).text('<?php echo esc_js( $button_text ); ?>');
                }
            })
            .fail(function() {
                $btn.prop('disabled', false).text('<?php echo esc_js( $button_text ); ?>');
            });
        });
    });
    </script>
</div>
