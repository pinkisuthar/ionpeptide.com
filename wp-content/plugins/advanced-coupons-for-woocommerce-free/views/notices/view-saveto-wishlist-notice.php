<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Exit if accessed directly.
?>

<div id="acfwf-saveto-notice" class="acfwf-saveto-notice">
    <style>
        .acfwf-saveto-notice {
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
        .acfwf-saveto-notice-sidebar {
            background: #F3F0FF;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 25px;
            min-width: 80px;
        }
        .acfwf-saveto-notice-sidebar img {
            width: 110px;
            height: auto;
        }
        .acfwf-saveto-notice-content {
            padding: 15px 40px 15px 20px;
            flex: 1;
        }
        .acfwf-saveto-notice-content h3 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 600;
            color: #1d2327;
        }
        .acfwf-saveto-notice-content p {
            margin: 0 0 12px;
            font-size: 13px;
            color: #50575e;
            line-height: 1.5;
        }
        .acfwf-saveto-notice-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .acfwf-saveto-notice-actions .button-primary {
            background: #6E3FF3;
            border-color: #6E3FF3;
            color: #fff;
            padding: 4px 16px;
            font-size: 13px;
            line-height: 1.8;
            height: auto;
            text-decoration: none;
        }
        .acfwf-saveto-notice-actions .button-primary:hover,
        .acfwf-saveto-notice-actions .button-primary:focus {
            background: #5B2FD9;
            border-color: #5B2FD9;
            color: #fff;
        }
        .acfwf-saveto-notice-actions .button-primary:disabled {
            background: #6E3FF3 !important;
            border-color: #6E3FF3 !important;
            color: #fff !important;
            opacity: 0.7;
        }
        .acfwf-saveto-notice-actions a.acfwf-saveto-learn-more {
            color: #6E3FF3;
            text-decoration: none;
            font-size: 13px;
        }
        .acfwf-saveto-notice-actions a.acfwf-saveto-learn-more:hover {
            text-decoration: underline;
        }
        .acfwf-saveto-notice-dismiss {
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
        .acfwf-saveto-notice-dismiss:hover {
            color: #d63638;
        }
        .woocommerce-layout__notice-list-hide ~ .acfwf-saveto-notice {
            margin-left: 20px;
            margin-right: 20px;
        }
    </style>

    <div class="acfwf-saveto-notice-sidebar">
        <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'SaveTo Wishlist', 'advanced-coupons-for-woocommerce-free' ); ?>" />
    </div>

    <div class="acfwf-saveto-notice-content">
        <h3><?php esc_html_e( 'Boost Your Sales with SaveTo Wishlist', 'advanced-coupons-for-woocommerce-free' ); ?></h3>
        <p>
            <?php
            esc_html_e(
                'Let customers save their favorite products and come back to buy later. Our sister plugin, SaveTo Wishlist, adds beautiful wishlist functionality to your WooCommerce store — helping you increase conversions and customer engagement.',
                'advanced-coupons-for-woocommerce-free'
            );
            ?>
        </p>
        <div class="acfwf-saveto-notice-actions">
            <button type="button" class="button button-primary acfwf-saveto-install-btn" data-nonce="<?php echo esc_attr( $install_nonce ); ?>">
                <?php echo esc_html( $button_text ); ?>
            </button>
            <a href="https://savetowishlist.com/?utm_source=acfwf&amp;utm_medium=admin_notice&amp;utm_campaign=saveto_cross_promo" class="acfwf-saveto-learn-more" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e( 'Learn More', 'advanced-coupons-for-woocommerce-free' ); ?>
            </a>
        </div>
    </div>

    <button type="button" class="acfwf-saveto-notice-dismiss" data-nonce="<?php echo esc_attr( $dismiss_nonce ); ?>" title="<?php esc_attr_e( 'Dismiss', 'advanced-coupons-for-woocommerce-free' ); ?>">
        <span class="dashicons dashicons-no-alt"></span>
    </button>

    <script>
    jQuery(document).ready(function($) {
        // Dismiss notice.
        $('#acfwf-saveto-notice .acfwf-saveto-notice-dismiss').on('click', function() {
            var $notice = $('#acfwf-saveto-notice');
            $.post(ajaxurl, {
                action: 'acfwf_dismiss_saveto_notice',
                nonce: $(this).data('nonce')
            });
            $notice.fadeOut(300, function() {
                $notice.remove();
            });
        });

        // Install & Activate.
        $('#acfwf-saveto-notice .acfwf-saveto-install-btn').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Installing...', 'advanced-coupons-for-woocommerce-free' ) ); ?>');

            $.post(ajaxurl, {
                action: 'acfw_install_activate_plugin',
                nonce: $btn.data('nonce'),
                plugin_slug: 'saveto-wishlist-lite-for-woocommerce'
            })
            .done(function(response) {
                if (response.success) {
                    // Dismiss the notice so it won't show again.
                    $.post(ajaxurl, {
                        action: 'acfwf_dismiss_saveto_notice',
                        nonce: $('#acfwf-saveto-notice .acfwf-saveto-notice-dismiss').data('nonce')
                    });
                    $btn.text('<?php echo esc_js( __( 'Activated! Reloading...', 'advanced-coupons-for-woocommerce-free' ) ); ?>');
                    location.reload();
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
