<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class BWFAN_Compatibilities
 * Loads all the compatibilities files we have in Autonami against plugins
 */
class BWFAN_Compatibilities {

	/**
	 * Load all compatibilities if valid
	 *
	 * @return void
	 */
	public static function load_all_compatibilities() {
		$compatibilities = [
			// checkout
			'checkout/class-bwfan-compatibility-with-aelia-cs.php'                     => class_exists( 'Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ),
			'checkout/class-bwfan-compatibility-with-aerocheckout.php'                 => ( class_exists( 'WFACP_Common' ) && method_exists( 'WFACP_Common', 'is_theme_builder' ) ),
			'checkout/class-bwfan-compatibility-with-bonanza.php'                      => class_exists( 'XLWCFG_Core' ),
			'checkout/class-bwfan-compatibility-with-handle-utm-grabber.php'           => ( function_exists( 'bwfan_is_utm_grabber_active' ) && bwfan_is_utm_grabber_active() ),
			'checkout/class-bwfan-compatibility-with-utm-leads-tracker.php'            => class_exists( 'Xlutm_Core' ),
			'checkout/class-bwfan-compatibility-with-wc-multi-currency-villatheme.php' => ( defined( 'WOOMULTI_CURRENCY_VERSION' ) || defined( 'WOOMULTI_CURRENCY_F_VERSION' ) ),

			// email
			'email/class-bwfan-compatibility-with-elastic-email-sender.php'            => class_exists( 'eemail' ),
			'email/class-bwfan-compatibility-with-wp-ses.php'                          => isset( $GLOBALS['wposes_meta'] ),
			'email/class-bwfan-compatibility-with-wp-smtp.php'                         => defined( 'WPMS_PLUGIN_VER' ),

			//plugins
			'plugins/class-bwfan-compatibility-with-mailpoet.php'                      => class_exists( 'MailPoet\Config\Initializer' ),
			'plugins/class-bwfan-compatibility-with-uni-cpo.php'                       => defined( 'UNI_CPO_PLUGIN_FILE' ),
			'plugins/class-bwfan-compatibility-with-wc-preorder.php'                   => class_exists( 'WC_Pre_Orders' ),
			'plugins/class-bwfan-compatibility-with-wc-product-bundle.php'             => class_exists( 'WC_Bundles' ),
			'plugins/class-bwfan-compatibility-with-weglot.php'                        => function_exists( 'bwfan_is_weglot_active' ) && bwfan_is_weglot_active(),
			'plugins/class-bwfan-compatibility-with-wpml.php'                          => defined( 'ICL_SITEPRESS_VERSION' ),
			'plugins/class-bwfan-compatibility-woocommerce.php'                        => function_exists( 'bwfan_is_woocommerce_active' ) && bwfan_is_woocommerce_active(),
			'plugins/class-bwfan-wc-product-addon.php'                                 => defined( 'PEWC_PLUGIN_VERSION' ),
			'plugins/class-bwfan-compatibility-with-gtranslate.php'                    => function_exists( 'bwfan_is_gtranslate_active' ) && bwfan_is_gtranslate_active(),

			//rest
			'rest/class-bwfan-compatibility-with-perfmatters.php'                      => defined( 'PERFMATTERS_VERSION' ),
			'rest/class-bwfan-compatibilities-with-force-login.php'                    => function_exists( 'v_forcelogin_rest_access' ),
			'rest/class-bwfan-compatibilities-with-logged-in-only.php'                 => function_exists( 'logged_in_only_rest_api' ),
			'rest/class-bwfan-compatibilities-with-password-protected.php'             => class_exists( 'Password_Protected' ),
			'rest/class-bwfan-compatibility-with-breeze-cache.php'                     => defined( 'BREEZE_VERSION' ),
			'rest/class-bwfan-compatibility-with-clearfy.php'                          => class_exists( 'Clearfy_Plugin' ),
			'rest/class-bwfan-compatibility-with-sg-cache.php'                         => function_exists( 'sg_cachepress_purge_cache' ),
			'rest/class-bwfan-compatibility-with-wp-rest-authenticate.php'             => function_exists( 'mo_api_auth_activate_miniorange_api_authentication' ),
			'rest/class-bwfan-compatibility-with-wp-rocket.php'                        => function_exists( 'rocket_clean_home' ),
			'rest/class-bwfan-compatibility-with-image-optimisation.php'               => defined( 'IMAGE_OPTIMIZATION_VERSION' ),
			'rest/class-bwfan-compatibility-with-atom-stock-manager.php'               => defined( 'ATUM_VERSION' ),
			'rest/class-bwfan-compatibility-with-security-by-cleantalk.php'            => defined( 'SPBC_VERSION' ),
			'rest/class-bwfan-compatibility-with-shield-security.php'                 => class_exists( 'ICWP_WPSF_Shield_Security' ),
			'rest/class-bwfan-compatibility-with-aios.php'                            => defined( 'AIO_WP_SECURITY_VERSION' ),
			'rest/class-bwfan-compatibility-with-tec.php'                              => class_exists( 'Tribe__Events__Main' ),
			'rest/class-bwfan-compatibility-with-hostinger-reach.php'                  => class_exists( 'Hostinger\Reach\Jobs\ActionScheduler' ),

			// other files
			'class-bwfan-compatibility-with-wp-oauth.php'                              => ( defined( 'WPOAUTH_VERSION' ) && class_exists( 'WO_SERVER' ) ),
		];
		self::add_files( array_filter( $compatibilities ) );
	}

	/**
	 * Include valid compatibility files
	 *
	 * @param array $paths
	 */
	public static function add_files( $paths ) {
		/** Compatibilities folder */
		$dir = plugin_dir_path( BWFAN_PLUGIN_FILE ) . 'compatibilities';
		try {
			foreach ( $paths as $file => $condition ) {
				include_once $dir . '/' . $file;
			}
		} catch ( Exception|Error $e ) {
			BWF_Logger::get_instance()->log( 'Error while loading compatibility files: ' . $e->getMessage(), 'compatibilities-load-error', 'fka-files-load-error' );
		}
	}
}

add_action( 'plugins_loaded', array( 'BWFAN_Compatibilities', 'load_all_compatibilities' ), 999 );
