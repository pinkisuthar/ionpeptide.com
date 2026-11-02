<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
final class BWFAN_WC_Integration extends BWFAN_Integration {

	private static $instance = null;

	/** @var array slug => filename, resolved on demand (see BWFAN_Integration::$action_map) */
	protected $action_map = [
		'wc_create_coupon' => 'class-bwfan-wc-create-coupon.php',
	];

	/**
	 * Constructor
	 *
	 * @access public
	 */
	private function __construct() {
		$this->action_dir         = __DIR__;
		$this->native_integration = true;
		$this->nice_name          = __( 'WooCommerce', 'wp-marketing-automations' );
		$this->group_name         = __( 'WooCommerce', 'wp-marketing-automations' );
		$this->group_slug         = 'wc';
		$this->priority           = 35;
	}

	/**
	 * Ensures only one instance of the class is loaded or can be loaded.
	 *
	 * @return BWFAN_Wc_Integration|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


}

/**
 * Register this class as an integration.
 */
if ( bwfan_is_woocommerce_active() ) {
	BWFAN_Load_Integrations::register( 'BWFAN_WC_Integration' );
}
