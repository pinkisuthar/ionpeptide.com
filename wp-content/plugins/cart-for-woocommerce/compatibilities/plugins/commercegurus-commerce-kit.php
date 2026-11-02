<?php
/**
 * CommerceGurus CommerceKit by CommerceGurus version 2.4.0
 * Author url: https://www.commercegurus.com
 *
 */

namespace FKCart\Compatibilities;
if ( ! class_exists( '\FKCart\Compatibilities\FKCART_Commercegurus_Commercekit' ) ) {


	/**
	 * Class FKCART_Commercegurus_Commercekit
	 * Handles attribute swatches integration between WooFunnels Checkout and Shoptimizer theme
	 * FKCART_Commercegurus_Commercekit
	 */
	class FKCART_Commercegurus_Commercekit {

		public function __construct() {
			add_action( 'wp_enqueue_scripts', [ $this, 'action' ] );
			add_action( 'wp_footer', [ $this, 'internal_js' ] );
			add_action( 'wp_head', [ $this, 'internal_css' ] );
		}

		/**
		 * Add internal CSS for swatches in the cart modal
		 */
		public function internal_css() {
			if ( ! $this->is_enable() ) {
				return;
			}
			?>
            <style>
                #fkcart-modal .fkcart-product-form-field.variations th label {
                    text-transform: capitalize
                }


                #fkcart-modal .fkcart-product-form-field.variations td,
                #fkcart-modal .fkcart-product-form-field.variations th {
                    display: list-item;
                    padding: 0;
                    list-style: none;
                }

                #fkcart-modal .fkcart-quick-view-drawer .fkcart-product-form-wrap table tr {
                    display: inherit;
                }

                #fkcart-modal .fkcart-quick-view-drawer table.woocommerce-product-attributes .no-selection,
                #fkcart-modal .commercekit-pdp-before-form,
                #fkcart-modal .fkcart-quick-view-drawer table.woocommerce-product-attributes .ckit-chosen-attribute_semicolon {
                    display: none;
                }
            </style>
			<?php
		}

		/**
		 * Enqueue required stylesheets
		 *
		 * @return bool|void Returns false if on single product page
		 */
		public function action() {
			if ( ! $this->is_enable() ) {
				return;
			}

			// Skip if we're on a single product page
			if ( is_product() ) {
				return false;
			}

			try {
				// Check if CGKIT_CSS_JS_VER is defined to prevent errors
				$version = defined( 'CGKIT_CSS_JS_VER' ) ? CGKIT_CSS_JS_VER : '1.0.0';

				$plugin_url = plugins_url( 'commercegurus-commercekit' );
				$path       = $plugin_url . '/assets/css/commercegurus-attribute-swatches.css';

				wp_enqueue_style( 'commercekit-attribute-swatches-css', $path, array(), $version );
			} catch ( \Exception $e ) {
				error_log( 'FKCART_Commercegurus_Commercekit: Error loading CSS: ' . $e->getMessage() );
			}
		}

		/**
		 * Check if the swatches functionality should be enabled
		 *
		 * @return bool Whether the functionality is enabled
		 */
		public function is_enable() {
			// Check the required dependencies
			if ( ! class_exists( '\FKCart\Plugin' ) || ! function_exists( 'commercekit_scripts' ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Add JavaScript to handle swatch updates in the cart modal
		 */
		public function internal_js() {
			if ( ! $this->is_enable() ) {
				return;
			}
			?>
            <script>
                window.addEventListener('load', function () {
                    if (typeof jQuery === 'undefined') {
                        console.error('jQuery is not available');
                        return;
                    }

                    (function ($) {
                        $(document.body).on('fkcart_cart_quick_view_open', function () {
                            setTimeout(function () {
                                if ($('.cgkit-swatch.cgkit-swatch-selected').length > 0 && typeof cgkitUpdateAttributeSwatch2 === 'function') {
                                    var cgkit_sel_swatches = document.querySelectorAll('.cgkit-swatch.cgkit-swatch-selected');
                                    cgkit_sel_swatches.forEach(function (input) {
                                        try {
                                            cgkitUpdateAttributeSwatch2(input);
                                        } catch (e) {
                                            console.error('Error updating attribute swatch:', e);
                                        }
                                    });
                                }
                            }, 100);
                        });
                    })(jQuery);
                });
            </script>
			<?php
		}
	}


	Compatibility::register( new FKCART_Commercegurus_Commercekit(), 'commercegurus-commercekit' );
}