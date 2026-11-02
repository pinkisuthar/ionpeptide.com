<?php
/**
 * ACFWF - Getting Started Page
 *
 * @package WWP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generate a string of star SVG icons.
 *
 * @param int $count Number of stars to generate. Default is 5.
 * @param int $size  Size of each star in pixels. Default is 20.
 * @return string    HTML string containing the star SVGs.
 */
function acfw_stars( $count = 5, $size = 20 ) {
    $stars = '';
    for ( $i = 0; $i < $count; ++$i ) {
        $stars .= <<<SVG
    <svg
        width="$size" height="$size" viewBox="0 0 20 20" fill="#F9E850"
        xmlns="http://www.w3.org/2000/svg"
    >
        <path
            d="M9.071 0.91166C9.41474 0.095201 10.5855 0.0952021 10.9292 0.911662L12.958 5.73055C13.103 6.07475 13.4307 6.30993 13.8068 6.33972L19.0728 6.75679C19.965 6.82746 20.3268 7.92745 19.647 8.50272L15.6349 11.898C15.3483 12.1405 15.2231 12.5211 15.3107 12.8837L16.5365 17.9603C16.7441 18.8204 15.797 19.5003 15.0331 19.0394L10.5247 16.3189C10.2026 16.1245 9.79762 16.1245 9.4756 16.3189L4.96711 19.0394C4.20323 19.5003 3.25608 18.8204 3.46376 17.9603L4.68954 12.8837C4.7771 12.5211 4.65194 12.1405 4.36538 11.898L0.353184 8.50272C-0.326596 7.92745 0.0351899 6.82746 0.927413 6.75679L6.19348 6.33972C6.56962 6.30993 6.89728 6.07475 7.04219 5.73055L9.071 0.91166Z"
        />
    </svg>
    SVG;
    }
    return $stars;
}


?>
<div class="wrap">
    <img class="acfw-confetti" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/confetti.png" alt="Confetti" />

    <div class="acfw-getting-started-container welcome-container">
        <div class="acfw-row">
            <img class="acfw-logo" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>acfw-logo.png" alt="ACFW Logo" />
        </div>
        <div class="acfw-row">
            <h3 class="acfw-section-title"><?php esc_html_e( 'Welcome to ', 'advanced-coupons-for-woocommerce-free' ); ?> <span class="acfw-dark-blue"><?php esc_html_e( 'Advanced Coupons!', 'advanced-coupons-for-woocommerce-free' ); ?></span>🎉</h3>
        </div>
        <div class="acfw-row">
            <div class="acfw-w-100">
                <p><?php esc_html_e( 'Thank you for choosing Advanced Coupons. By selecting our plugins, you\'ve taken the first step towards leveling up your WooCommerce store’s promotions!', 'advanced-coupons-for-woocommerce-free' ); ?></p>
                <p>
                    <?php esc_html_e( 'At Advanced Coupons, we believe every small store has the potential to grow. 🌱', 'advanced-coupons-for-woocommerce-free' ); ?>
                    <br>
                    <?php esc_html_e( 'That\'s why we\'ve built tools that help you go beyond basic discounts. Our FREE plugin extends the default coupon features of WooCommerce-letting you create powerful promotions like BOGO deals and URL coupons, grant store credits, and set advanced cart conditions.', 'advanced-coupons-for-woocommerce-free' ); ?>
                </p>
            </div>
        </div>
        <div class="acfw-row">
            <div class="acfw-row acfw-getting-started-feedback-container">
                <div>
                    <img class="acfw-trusted-store-owner" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/trusted-store-owner-1.png" alt="acfw-trusted-store-owner-1" />
                    <img class="acfw-trusted-store-owner" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/trusted-store-owner-2.png" alt="acfw-trusted-store-owner-2" />
                    <img class="acfw-trusted-store-owner" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/trusted-store-owner-3.png" alt="acfw-trusted-store-owner-3" />
                    <img class="acfw-trusted-store-owner" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/trusted-store-owner-4.png" alt="acfw-trusted-store-owner-4" />
                    <img class="acfw-trusted-store-owner" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/trusted-store-owner-5.png" alt="acfw-trusted-store-owner-5" />
                </div>

                <div class="acfw-getting-started-feedback-footer">
                    <div class="acfw-getting-started-stars-container"><?php echo acfw_stars( 5, 34 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <p class="acfw-getting-started-trustedby-text"><?php esc_html_e( 'Trusted by 20k Store Owners', 'advanced-coupons-for-woocommerce-free' ); ?></p>
                </div>
            </div>
        </div>
        <div class="acfw-row">
            <p class="acfw-getting-started-quote-text">
                <?php esc_html_e( 'We\'re here to support you every step of the way.', 'advanced-coupons-for-woocommerce-free' ); ?>
                <br>
                <?php esc_html_e( 'So, buckle up! Your next big promotion begins here.', 'advanced-coupons-for-woocommerce-free' ); ?>
            </p>
        </div>
        <div class="acfw-row">
            <p><a
                class="acfw-getting-started-link"
                href="<?php echo esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'kb/getting-started/', 'acfwf', 'upsell', 'acfwfgettingstartedguidebutton' ) ); ?>"
                target="_blank"
            ><?php esc_html_e( 'Read Getting Started Guide', 'advanced-coupons-for-woocommerce-free' ); ?></a></p>
        </div>
    </div>

    <div class="acfw-green-section">
        <div class="acfw-getting-started-container">
            <div class="acfw-row">
                <h3 class="acfw-section-title">
                    <?php esc_html_e( 'Start Creating ', 'advanced-coupons-for-woocommerce-free' ); ?>
                    <span class="acfw-dark-blue"><?php esc_html_e( 'Smarter Coupons ', 'advanced-coupons-for-woocommerce-free' ); ?></span>
                    <?php esc_html_e( 'Today!', 'advanced-coupons-for-woocommerce-free' ); ?> 🎯
                </h3>
            </div>
            <div class="acfw-row">
                <div class="acfw-w-50">
                    <p>
                        <?php
                        esc_html_e(
                            'Advanced Coupons Free can help you create irresistible offers that keep customers coming back. It\'s easy to use, packed with essential features, and absolutely free to get started.',
                            'advanced-coupons-for-woocommerce-free'
                        );
                        ?>
                    </p>
                </div>
            </div>
            <div class="acfw-row">
                <div class="acfw-feature-boxes">
                    <div class="acfw-feature-box">
                        <div class="acfw-feature-icon">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-bogo-deals.png" alt="start-bogo-deals" />
                        </div>
                        <div class="acfw-feature-title">
                            <h4><?php esc_html_e( 'BOGO Deals', 'advanced-coupons-for-woocommerce-free' ); ?></h4>
                        </div>
                        <div class="acfw-feature-content">
                            <?php esc_html_e( 'Launch irresistible "Buy One, Get One" offers to boost sales and move inventory.', 'advanced-coupons-for-woocommerce-free' ); ?>
                        </div>
                    </div>
                    <div class="acfw-feature-box">
                        <div class="acfw-feature-icon">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-store-credits.png" alt="start-store-credits" />
                        </div>
                        <div class="acfw-feature-title">
                            <h4><?php esc_html_e( 'Store Credits', 'advanced-coupons-for-woocommerce-free' ); ?></h4>
                        </div>
                        <div class="acfw-feature-content">
                            <?php esc_html_e( 'Grant store credits to encourage repeat purchases and build loyalty.', 'advanced-coupons-for-woocommerce-free' ); ?>
                        </div>
                    </div>
                    <div class="acfw-feature-box">
                        <div class="acfw-feature-icon">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-url-coupons.png" alt="start-url-coupons" />
                        </div>
                        <div class="acfw-feature-title">
                            <h4><?php esc_html_e( 'URL Coupons', 'advanced-coupons-for-woocommerce-free' ); ?></h4>
                        </div>
                        <div class="acfw-feature-content">
                            <?php esc_html_e( 'Share coupon links that automatically apply discounts — perfect for email or social campaigns!', 'advanced-coupons-for-woocommerce-free' ); ?>
                        </div>
                    </div>
                    <div class="acfw-feature-box">
                        <div class="acfw-feature-icon">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-cart-conditions.png" alt="start-cart-conditions" />
                        </div>
                        <div class="acfw-feature-title">
                            <h4><?php esc_html_e( 'Cart Conditions', 'advanced-coupons-for-woocommerce-free' ); ?></h4>
                        </div>
                        <div class="acfw-feature-content">
                            <?php esc_html_e( 'Control when and how coupons can be applied with advanced cart conditions.', 'advanced-coupons-for-woocommerce-free' ); ?>
                        </div>
                    </div>
                    <div class="acfw-feature-box">
                        <div class="acfw-feature-icon">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-role-restrictions.png" alt="start-role-restrictions" />
                        </div>
                        <div class="acfw-feature-title">
                            <h4><?php esc_html_e( 'Role Restrictions', 'advanced-coupons-for-woocommerce-free' ); ?></h4>
                        </div>
                        <div class="acfw-feature-content">
                            <?php esc_html_e( 'Set coupons to work only for specific customer roles, like VIPs or wholesale buyers.', 'advanced-coupons-for-woocommerce-free' ); ?>
                        </div>
                    </div>
                    <div class="acfw-feature-box">
                        <div class="acfw-feature-icon">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-coupon-scheduler.png" alt="start-coupon-scheduler" />
                        </div>
                        <div class="acfw-feature-title">
                            <h4><?php esc_html_e( 'Coupon Scheduler', 'advanced-coupons-for-woocommerce-free' ); ?></h4>
                        </div>
                        <div class="acfw-feature-content">
                            <?php esc_html_e( 'Automate your promotions by scheduling coupons to start and end exactly when you want.', 'advanced-coupons-for-woocommerce-free' ); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="acfw-row">
                <div class="acfw-start-setting-up-container">
                    <a class="acfw-start-setting-up" href="/wp-admin/edit.php?post_type=shop_coupon&acfw">
                        <?php esc_html_e( 'Launch Your First Promotion Today', 'advanced-coupons-for-woocommerce-free' ); ?>
                        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/start-icon-button.png" alt="start-icon-button" />
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="acfw-getting-started-container">
        <div class="acfw-row">
            <h3 class="acfw-section-title">
                <?php esc_html_e( 'Imagine What You Can Achieve ', 'advanced-coupons-for-woocommerce-free' ); ?>
                <br>
                <?php esc_html_e( 'With Our ', 'advanced-coupons-for-woocommerce-free' ); ?><span class="acfw-dark-blue"><?php esc_html_e( 'Complete Suite Of Tools!', 'advanced-coupons-for-woocommerce-free' ); ?></span> 🤯
            </h3>
        </div>
        <div class="acfw-row">
            <div class="acfw-w-50">
                <p>
                <?php
                esc_html_e(
                    'Advanced Coupons FREE is just the beginning! With our premium plugins, you can unlock powerful features to help you grow sales, build customer loyalty, and scale your store to new heights.',
                    'advanced-coupons-for-woocommerce-free'
                );
                ?>
                </p>
            </div>
        </div>
        <div class="acfw-row">
            <div class="acfw-plugins acfw-w-100">
                <div class="acfw-plugin">
                    <div class="acfw-plugin-info">
                        <div class="acfw-plugin-title">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/feature-icon-acfwp.png" alt="feature-icon-acfwp" />
                            <h4>
                                <?php esc_html_e( 'Advanced ', 'advanced-coupons-for-woocommerce-free' ); ?>
                                <span class="acfw-dark-blue"><?php esc_html_e( 'Coupons Premium', 'advanced-coupons-for-woocommerce-free' ); ?></span>
                            </h4>
                        </div>
                        <div class="plugin-excerpt">
                            <p><?php esc_html_e( 'Take your promotions to a whole new level!', 'advanced-coupons-for-woocommerce-free' ); ?> 🚀</p>
                        </div>
                        <div class="plugin-content">
                            <p>
                                <?php
                                    printf(
                                        /* translators: %1$s, %2$s, %3$s, %4$s, and %5$s are feature names that will be bolded. */
                                        esc_html__( 'Upgrading to the premium version gives you access to game-changing features like %1$s, %2$s, %3$s, %4$s, and %5$s.', 'advanced-coupons-for-woocommerce-free' ),
                                        '<strong>' . esc_html__( 'Shipping Discounts', 'advanced-coupons-for-woocommerce-free' ) . '</strong>',
                                        '<strong>' . esc_html__( 'Auto-apply Coupons', 'advanced-coupons-for-woocommerce-free' ) . '</strong>',
                                        '<strong>' . esc_html__( 'Advanced Cart Conditions', 'advanced-coupons-for-woocommerce-free' ) . '</strong>',
                                        '<strong>' . esc_html__( 'Cashback Coupons', 'advanced-coupons-for-woocommerce-free' ) . '</strong>',
                                        '<strong>' . esc_html__( 'Advanced BOGO Offers', 'advanced-coupons-for-woocommerce-free' ) . '</strong>'
                                    );
                                ?>

                                <br><br>
                                <?php esc_html_e( 'This tool has everything you need to create advanced, high-performing campaigns that drive results!', 'advanced-coupons-for-woocommerce-free' ); ?>
                            </p>
                        </div>
                        <p>
                            <a class="acfw-plugin-learn-more" href="<?php echo esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'pricing/', 'acfwf', 'upsell', 'acfwfgettingstartedacfwplink' ) ); ?>" target="_blank">
                                <?php esc_html_e( 'Learn More', 'advanced-coupons-for-woocommerce-free' ); ?>
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </a>
                        </p>
                    </div>
                    <div class="acfw-plugin-sample-img">
                        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/feature-sample-acfwp.png" alt="feature-sample-acfwp" />
                    </div>
                </div>
            </div>
        </div>
        <div class="acfw-row">
            <div class="acfw-plugins acfw-w-100">
                <div class="acfw-plugin">
                    <div class="acfw-plugin-sample-img">
                        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/feature-sample-lpfw.png" alt="feature-sample-lpfw" />
                    </div>
                    <div class="acfw-plugin-info">
                        <div class="acfw-plugin-title">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/feature-icon-lpfw.png" alt="feature-icon-lpfw" />
                            <h4>
                                <?php esc_html_e( 'WooCommerce ', 'advanced-coupons-for-woocommerce-free' ); ?>
                                <span class="acfw-dark-blue"><?php esc_html_e( 'Loyalty Program', 'advanced-coupons-for-woocommerce-free' ); ?></span>
                            </h4>
                        </div>
                        <div class="plugin-excerpt">
                            <p><?php esc_html_e( 'Turn one-time shoppers into loyal brand advocates.', 'advanced-coupons-for-woocommerce-free' ); ?> 💗</p>
                        </div>
                        <div class="plugin-content">
                            <p>
                                <?php
                                    printf(
                                        /* translators: %1$s is "Points-Based Loyalty System", %2$s and %3$s are "Purchases" and "Referrals", which will be bolded. */
                                        esc_html__( 'Our plugin allows you to launch a %1$s in minutes! Reward points for %2$s, %3$s, or other actions. Perfect for building long-term relationships.', 'advanced-coupons-for-woocommerce-free' ),
                                        '<strong>' . esc_html__( 'Points-Based Loyalty System', 'advanced-coupons-for-woocommerce-free' ) . '</strong>',
                                        '<strong>' . esc_html__( 'Purchases', 'advanced-coupons-for-woocommerce-free' ) . '</strong>',
                                        '<strong>' . esc_html__( 'Referrals', 'advanced-coupons-for-woocommerce-free' ) . '</strong>'
                                    );
                                ?>
                            </p>
                        </div>
                        <p>
                            <a class="acfw-plugin-learn-more" href="<?php echo esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'pricing/loyalty/', 'acfwf', 'upsell', 'acfwfgettingstartedlpfwlink' ) ); ?>" target="_blank">
                                <?php esc_html_e( 'Learn More', 'advanced-coupons-for-woocommerce-free' ); ?>
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </a>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="acfw-row">
            <div class="acfw-plugins acfw-w-100">
                <div class="acfw-plugin">
                    <div class="acfw-plugin-info">
                        <div class="acfw-plugin-title">
                            <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/feature-icon-agc.png" alt="feature-icon-agc" />
                            <h4>
                                <?php esc_html_e( 'WooCommerce ', 'advanced-coupons-for-woocommerce-free' ); ?>
                                <span class="acfw-dark-blue"><?php esc_html_e( 'Gift Cards', 'advanced-coupons-for-woocommerce-free' ); ?></span>
                            </h4>
                        </div>
                        <div class="plugin-excerpt">
                            <p><?php esc_html_e( 'Make gifting easier for customers, while spreading love for your brand.', 'advanced-coupons-for-woocommerce-free' ); ?> 🎁</p>
                        </div>
                        <div class="plugin-content">
                            <p>
                                <?php
                                printf(
                                    /* translators: %1$s is "Digital Gift Cards" which will be bolded. */
                                    esc_html__( 'This easy-to-use plugin allows you to sell %1$s, adding another revenue stream for your store. Perfect for Holidays, Special Occasions, and year-round promotions!', 'advanced-coupons-for-woocommerce-free' ),
                                    '<strong>' . esc_html__( 'Digital Gift Cards', 'advanced-coupons-for-woocommerce-free' ) . '</strong>'
                                );
                                ?>
                            </p>
                        </div>
                        <p>
                            <a class="acfw-plugin-learn-more" href="<?php echo esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'pricing/gift-cards/', 'acfwf', 'upsell', 'acfwfgettingstartedagclink' ) ); ?>" target="_blank">
                                <?php esc_html_e( 'Learn More', 'advanced-coupons-for-woocommerce-free' ); ?>
                                <span class="dashicons dashicons-arrow-right-alt"></span>
                            </a>
                        </p>
                    </div>
                    <div class="acfw-plugin-sample-img">
                        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/feature-sample-agc.png" alt="feature-sample-agc" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="acfw-green-section">
        <div class="acfw-getting-started-container all-access-bundle-container">
            <div class="acfw-row">
                <h3 class="acfw-section-title">
                    <?php esc_html_e( 'Everything You Need To Grow Your Store ', 'advanced-coupons-for-woocommerce-free' ); ?>
                    <br>
                    <?php esc_html_e( 'In ', 'advanced-coupons-for-woocommerce-free' ); ?><span class="acfw-dark-blue"><?php esc_html_e( 'One Bundle', 'advanced-coupons-for-woocommerce-free' ); ?></span> ⭐
                </h3>
            </div>
            <div class="acfw-row">
                <div class="acfw-w-50">
                    <p>
                        <?php esc_html_e( 'Save time, save money, and scale your store like never before.', 'advanced-coupons-for-woocommerce-free' ); ?>
                        <br>
                        <?php esc_html_e( 'Our All-Access Bundle includes all the plugins you need to create smarter promotions, build customer loyalty, and boost your revenue-at an unbeatable value!', 'advanced-coupons-for-woocommerce-free' ); ?>
                    </p>
                </div>
            </div>
            <div class="acfw-row">
                <div class="acfw-w-50">
                    <img class="all-access-bundle-image" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/bundle.png" alt="bundle" />
                </div>
            </div>
            <div class="acfw-row">
                <div class="acfw-bundle-link-container">
                    <a class="acfw-get-bundle-link" href="<?php echo esc_url( \ACFWF()->Helper_Functions->get_utm_url( 'pricing/bundle/', 'acfwf', 'upsell', 'acfwfgettingstartedbundlelink' ) ); ?>" target="_blank">
                        <?php esc_html_e( 'Get All Access Bundle', 'advanced-coupons-for-woocommerce-free' ); ?>
                        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/bundle-icon-button.png" alt="bundle-icon-button" />
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="acfw-getting-started-container">
        <div class="acfw-row">
            <h3 class="acfw-section-title">
                <?php esc_html_e( 'Loved by Over ', 'advanced-coupons-for-woocommerce-free' ); ?> <span class="acfw-dark-blue"><?php esc_html_e( '20,000 WooCommerce', 'advanced-coupons-for-woocommerce-free' ); ?></span>
                <br>
                <span class="acfw-dark-blue"><?php esc_html_e( 'Store Owners', 'advanced-coupons-for-woocommerce-free' ); ?></span> ♥️
            </h3>
        </div>
        <div class="acfw-row">
            <div class="acfw-w-50">
                <p>
                    <?php esc_html_e( 'Don\'t just take our word for it—see what real store owners have to say about how Advanced Coupons transformed their marketing game:', 'advanced-coupons-for-woocommerce-free' ); ?>
                </p>
            </div>
        </div>
        <div class="acfw-row">
            <div class="acfw-w-50">
                <p>
                    <img class="rating-platform-image" src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/rating-platform.png" alt="rating-platform" />
                </p>
            </div>
        </div>
        <div class="acfw-row">
            <div class="reviews-container">
                <div class="review">
                    <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/rating-customer-1.png" alt="rating-customer-1">
                    <div class="review-stars">
                        <?php echo acfw_stars(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <div class="review-author">
                        <p class="author">Bob Dunn (Do the Woo Podcast)</p>
                        <p class="site">dothewoo.io</p>
                    </div>
                    <p class="review-content">
                        <?php esc_html_e( '"For most shop owners, using coupons on their WooCommerce online stores is essential. But basic coupon features don\'t always give enough flexibility and creativity for running the best customer deals. That\'s where WooCommerce Advanced Coupons plugin comes in."', 'advanced-coupons-for-woocommerce-free' ); ?>
                    </p>
                </div>
                <div class="review">
                    <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/rating-customer-2.png" alt="rating-customer-2">
                    <div class="review-stars">
                        <?php echo acfw_stars(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <div class="review-author">
                        <p class="author">Colin Newcomer (WordPress Reviewer)</p>
                        <p class="site">wplift.com</p>
                    </div>
                    <p class="review-content">
                        <?php esc_html_e( '"If you offer a lot of deals at your store, you\'ll love the flexibility that the plugin gives you. And because Advanced Coupons is based on the native WooCommerce coupon functionality, you can keep using any other coupon workflows at your store."', 'advanced-coupons-for-woocommerce-free' ); ?>
                    </p>
                </div>
                <div class="review">
                    <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL ); ?>getting-started/rating-customer-3.png" alt="rating-customer-3">
                    <div class="review-stars">
                        <?php echo acfw_stars(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <div class="review-author">
                        <p class="author">WP Mayor (Industry Blog)</p>
                        <p class="site">wpmayor.com</p>
                    </div>
                    <p class="review-content">
                        <?php esc_html_e( '"Coupons have such great ability to transform your WooCommerce store into a money-making powerhouse, you just have to structure them right and have the right tools behind you."', 'advanced-coupons-for-woocommerce-free' ); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
