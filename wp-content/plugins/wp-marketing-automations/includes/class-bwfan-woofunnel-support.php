<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
class BWFAN_WooFunnels_Support {

	public static $_instance = null;

	public function __construct() {
		add_action( 'bwfan_page_right_content', array( $this, 'bwfan_options_page_right_content' ), 10 );
		//add_action( 'admin_menu', array( $this, 'add_menus' ), 81.1 );

		add_filter( 'woofunnels_default_reason_' . BWFAN_PLUGIN_BASENAME, function () {
			return 1;
		} );
		add_filter( 'woofunnels_default_reason_default', function () {
			return 1;
		} );

		add_filter( 'woofunnels_register_usage_collectors', array( $this, 'maybe_add_registery' ), 15, 1 );
	}


	/**
	 * @return null|BWFAN_WooFunnels_Support
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}

		return self::$_instance;
	}


	public function bwfan_options_page_right_content() {
		$autonami_notifications = BWFAN_Common::get_autonami_notifications();
		if ( 0 === count( $autonami_notifications ) ) {
			return;
		}
		?>
        <div class="postbox wfacp_side_content wfacp_allow_panel_close wf_notification_list_wrap">
            <button type="button" class="handlediv">
                <span class="toggle-indicator"></span>
            </button>
            <h3 class="hndle"><span><?php esc_html_e( 'Alert(s)', 'wp-marketing-automations' ); ?></span></h3>
			<?php
			WooFunnels_Notifications::get_instance()->get_notification_html( $autonami_notifications );
			?>
        </div>
		<?php
	}

	/**
	 * Adding WooCommerce sub-menu for global options
	 */
	public function add_menus() {
		if ( true === WooFunnels_dashboard::$is_core_menu ) {
			return;
		}

		add_menu_page( __( 'WooFunnels', 'wp-marketing-automations' ), __( 'WooFunnels', 'wp-marketing-automations' ), 'manage_options', 'woofunnels', array( $this, 'woofunnels_page' ), '', 59 );
		add_submenu_page( 'woofunnels', __( 'Licenses', 'wp-marketing-automations' ), __( 'License', 'wp-marketing-automations' ), 'manage_options', 'woofunnels' );
		WooFunnels_dashboard::$is_core_menu = true;
	}

	public function woofunnels_page() {
		if ( ! isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			WooFunnels_dashboard::$selected = 'support';
		}
		WooFunnels_dashboard::load_page();
	}

	public function maybe_add_registery( $collectors ) {
		/**
		 * Load usage collector (lazy registration via filter hook)
		 */
		// Load the usage collector class if it doesn't exist yet
		if ( ! class_exists( 'BWFAN_Usage_Collector' ) && file_exists( BWFAN_PLUGIN_DIR . '/includes/usage/class-bwfan-usage-collector.php' ) ) {
			require_once BWFAN_PLUGIN_DIR . '/includes/usage/class-bwfan-usage-collector.php';
		}

		// Register with registry if class exists and Pro is not active
		if ( class_exists( 'BWFAN_Usage_Collector' ) && ! class_exists( 'BWFANCRM_Usage_Collector' ) ) {
			return BWFAN_Usage_Collector::add_to_registry( $collectors );
		}

		return $collectors;
	}
}

if ( class_exists( 'BWFAN_WooFunnels_Support' ) ) {
	BWFAN_Core::register( 'support', 'BWFAN_WooFunnels_Support' );
}
