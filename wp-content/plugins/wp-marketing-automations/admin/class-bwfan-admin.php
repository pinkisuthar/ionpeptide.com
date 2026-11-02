<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Bwfan Admin
 *
 * @since 1.0.0
 */
#[AllowDynamicProperties]
class BWFAN_Admin {

	private static $ins = null;
	public $admin_path;
	public $admin_url;
	public $section_page = '';
	public $should_show_shortcodes = null;
	public $events_js_data = array();
	public $actions_js_data = array();
	public $select2ajax_js_data = array();
	public $dashboard_page;
	public $wizard_url = '';
	private $is_order_contact_column_hidden = null;

	/**
	 *   Construct
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->admin_path = BWFAN_PLUGIN_DIR . '/admin';
		$this->admin_url  = BWFAN_PLUGIN_URL . '/admin';
		$this->wizard_url = admin_url( 'admin.php?page=autonami&path=/user-setup' );
		$this->include_admin_pages();
		$this->init_admin_pages();
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 90 );

		/**
		 * Admin enqueue scripts
		 */
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_assets' ), 99 );

		/**
		 * Admin footer text
		 */
		add_filter( 'admin_footer_text', array( $this, 'admin_footer_text' ), 99999, 1 );
		add_filter( 'update_footer', array( $this, 'admin_footer_version' ), 99999, 1 );

		add_action( 'admin_head', array( $this, 'js_variables' ) );
		add_action( 'admin_init', array( $this, 'maybe_set_automation_id' ) );
		add_action( 'admin_head', array( $this, 'change_autonami_menu_icon' ), - 1 );

		/** Hooks to check if activation and deactivation request for post. */
		add_filter( 'plugin_action_links_' . BWFAN_PLUGIN_BASENAME, array( $this, 'plugin_actions' ) );

		/** Scheduling actions */
		add_action( 'admin_init', array( $this, 'maybe_set_as_ct_worker' ), 12 );
		add_action( 'admin_init', array( $this, 'schedule_abandoned_cart_cron' ) );
		add_action( 'wp', array( $this, 'maybe_set_as_ct_worker' ) );
		add_action( 'wp', array( $this, 'schedule_abandoned_cart_cron' ) );

		add_action( 'in_admin_header', array( $this, 'maybe_remove_all_notices_on_page' ) );

		add_action( 'admin_init', array( $this, 'maybe_handle_optin_choice' ), 14 );

		add_action( 'admin_notices', array( $this, 'maybe_show_sandbox_mode_notice' ) );

		/** Create automation earlier */
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_automation' ), 15 );

		/** Enable reset tracking setting in global woofunnels tools */
		add_filter( 'bwf_needs_order_indexing', '__return_true' );

		/** redirect the connector page to autonami-automations tabs connector page */
		add_action( 'admin_init', array( $this, 'redirect_autonami_connector_page' ), 99999 );

		/** Automation builder, modify events action array */
		add_filter( 'bwfan_modify_actions_groups', array( $this, 'automation_modify_actions_groups' ) );
		add_filter( 'bwfan_modify_integrations', array( $this, 'automation_modify_integrations' ) );

		add_action( 'personal_options', array( $this, 'bwfan_add_contact_profile_link' ), 10, 1 );

		/** Add Total spent column on WC orders listing page */
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'bwfan_add_order_contact_column_header' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'bwfan_add_order_contact_column_content' ), 20, 2 );

		/** Add Total spent column on WC orders listing page when HPOS enabled */
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'bwfan_add_order_contact_column_header' ), 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'bwfan_add_order_contact_column_content' ), 20, 2 );

		add_action( 'add_meta_boxes', array( $this, 'bwf_add_single_order_meta_box' ), 50, 2 );
		add_filter( 'user_row_actions', array( $this, 'bwf_user_list_add_contact_link' ), 10, 2 );

		/** Validating & removing scripts on page load */
		add_action( 'admin_print_styles', array( $this, 'bwfan_removing_scripts_single_ui' ), - 1 );
		add_action( 'admin_print_scripts', array( $this, 'bwfan_removing_scripts_single_ui' ), - 1 );
		add_action( 'admin_print_footer_scripts', array( $this, 'bwfan_removing_scripts_single_ui' ), - 1 );

		/** redirect on autonami list page */
		add_action( 'load-autonami_page_autonami-automations', array( $this, 'redirect_autonami_automations_page' ) );

		/** Load font and size selector */
		add_filter( 'mce_buttons', array( $this, 'add_tinymce_options' ), 999 );
		add_action( 'wp_ajax_bwf_migrate_automation', array( $this, 'bwfan_migrate_automation' ) );

		/** Add a plugin action link and notice for pro plugin*/
		add_action( 'after_plugin_row_meta', [ $this, 'maybe_add_notice' ] );
		add_action( 'plugin_action_links', [ $this, 'plugin_action_link' ], 10, 2 );

		/** Force redirect to wizard page*/
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ), 15 );
	}

	/**
	 * Set meta key for v1 migrated automation
	 * @return void
	 */
	function bwfan_migrate_automation() {
		BWFAN_Common::check_nonce();

		// phpcs:disable WordPress.Security.NonceVerification
		if ( empty( $_POST['automation_id'] ) ) {
			$resp = array(
				'msg'    => 'Automation ID is missing',
				'status' => false,
			);
			wp_send_json( $resp );
		}

		$id     = sanitize_text_field( wp_unslash( $_POST['automation_id'] ) );
		$result = BWFAN_Model_Automationmeta::insert_automation_meta_data( $id, [
			'v1_migrate' => true,
		] );

		$resp = array(
			'msg'    => 'Automation migrated',
			'status' => true,
		);

		if ( ! $result ) {
			$resp = array(
				'msg'    => 'Unable to update automation',
				'status' => false,
			);
		}
		//phpcs:enable WordPress.Security.NonceVerification

		wp_send_json( $resp );
	}

	/** Load font selector and size selector */
	function add_tinymce_options( $toolbar2 ) {
		$temp = [
			'fontselect',
			'fontsizeselect',
		];

		return array_merge( $temp, $toolbar2 );
	}

	/**
	 * Include Admin Pages
	 *
	 * @since 1.0.0
	 */
	public function include_admin_pages() {
		include_once $this->admin_path . '/class-bwfcrm-base-react-page.php';
		include_once $this->admin_path . '/view/class-bwfcrm-dashboard.php';
	}

	/**
	 * Init Admin Pages
	 *
	 * @since 1.0.0
	 */
	public function init_admin_pages() {
		$this->dashboard_page = BWFCRM_Dashboard::get_instance();
	}

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/** removing the script from other plugin on single ui */
	function bwfan_removing_scripts_single_ui() {
		global $wp_scripts, $wp_styles;

		if ( ! BWFAN_Common::is_load_admin_assets( 'all' ) ) {
			return;
		}

		$mod_wp_scripts = $wp_scripts;
		$assets         = $wp_scripts;

		if ( 'admin_print_styles' == current_action() ) {
			$mod_wp_scripts = $wp_styles;
			$assets         = $wp_styles;
		}

		if ( is_object( $assets ) && isset( $assets->registered ) && count( $assets->registered ) > 0 ) {
			foreach ( $assets->registered as $handle => $script_obj ) {
				if ( ! isset( $script_obj->src ) || empty( $script_obj->src ) ) {
					continue;
				}

				$src = $script_obj->src;

				/** Remove scripts of massive VC addons plugin */
				if ( strpos( $src, 'wp-cloudflare-page-cache/' ) !== false ) {
					unset( $mod_wp_scripts->registered[ $handle ] );
				}

				/** Remove scripts of massive bigscoots cache plugin */
				if ( strpos( $src, 'bigscoots-cache/' ) !== false ) {
					unset( $mod_wp_scripts->registered[ $handle ] );
				}

				/** remove scripts of gm-woocommerce-quote-popup */
				if ( strpos( $src, 'gm-woocommerce-quote-popup/' ) !== false ) {
					unset( $mod_wp_scripts->registered[ $handle ] );
				}

				/** remove scripts of themes Jupiter Core */
				if ( strpos( $src, 'themes/jupiter/' ) !== false ) {
					unset( $mod_wp_scripts->registered[ $handle ] );
				}

				/** remove scripts of location domination */
				if ( strpos( $src, 'location-domination-wordpress-stable/' ) !== false ) {
					unset( $mod_wp_scripts->registered[ $handle ] );
				}

				/** remove scripts of Embed Plus Plugin For Youtube  */
				if ( strpos( $src, 'youtube-embed-plus/' ) !== false ) {
					unset( $mod_wp_scripts->registered[ $handle ] );
				}
			}
		}

		if ( 'admin_print_styles' == current_action() ) {
			$wp_styles = $mod_wp_scripts;
		} else {
			$wp_scripts = $mod_wp_scripts;
		}
	}

	/**
	 * Maybe Show Sandbox Mode Notice
	 *
	 * @since 1.0.0
	 */
	public function maybe_show_sandbox_mode_notice() {
		if ( false === BWFAN_Common::is_sandbox_mode_active() ) {
			return;
		}
		?>
        <div class="notice notice-warning" style="display: block!important;">
            <p>
				<?php
				echo __( '<strong>Warning! FunnelKit Automations is currently in Sandbox Mode</strong>. Automations and Broadcasts will not run or trigger for any contacts while this mode is active.', 'wp-marketing-automations' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
            </p>
        </div>
		<?php
	}

	/**
	 * Get Admin Url
	 *
	 * @since 1.0.0
	 */
	public function get_admin_url() {
		return plugin_dir_url( BWFAN_PLUGIN_FILE ) . 'admin';
	}

	/**
	 * Register Admin Menu
	 *
	 * @since 1.0.0
	 */
	public function register_admin_menu() {
		$capability = self::menu_cap();

		$menu_data = BWFAN_Common::get_user_menu_access();

		$notification = false === bwfan_is_autonami_pro_active() ? BWFAN_Common::sale_notification_menu() : [];

		/** Check if Autonami is in sandbox mode */
		$title = 'FunnelKit Automations';

		if ( ! empty( $notification ) ) {
			$title .= ' <span style="background-color:#d63638;color:#fff;border-radius:10px;margin-left:2px;font-size:10px;padding:3px 6px;">1</span>';
		}
		if ( true === BWFAN_Common::is_sandbox_mode_active() ) {
			$title .= ' <span style="background-color:#ca4a1f;border-radius:10px;margin-left:0;font-size:10px;padding:3px 6px;">' . __( 'Sandbox', 'wp-marketing-automations' ) . '</span>';
		}
		add_menu_page( false, $title, $capability, 'autonami', array( $this, 'autonami_page' ), '', 59 );

		add_submenu_page( 'autonami', __( 'Dashboard', 'wp-marketing-automations' ), __( 'Dashboard', 'wp-marketing-automations' ), $capability, 'autonami', false, 10 );

		if ( empty( $menu_data ) || in_array( 'contacts', $menu_data ) ) {
			add_submenu_page( 'autonami', __( 'Contacts', 'wp-marketing-automations' ), __( 'Contacts', 'wp-marketing-automations' ), $capability, 'autonami&path=/contacts', array(
				$this,
				'autonami_page'
			), 20 );
		}

		if ( ( empty( $menu_data ) || in_array( 'carts', $menu_data ) ) && false !== BWFAN_Plugin_Dependency::woocommerce_active_check() ) {
			add_submenu_page( 'autonami', __( 'Carts', 'wp-marketing-automations' ), __( 'Carts', 'wp-marketing-automations' ), $capability, 'autonami&path=/carts/recoverable', function () {
			}, 27 );
			$position = apply_filters( 'bwfan_cart_submenu_position', 5 );
			$position = ( empty( absint( $position ) ) ) ? 5 : absint( $position );

			add_submenu_page( 'woocommerce', __( 'Carts', 'wp-marketing-automations' ), __( 'Carts', 'wp-marketing-automations' ), $capability, 'admin.php?page=autonami&path=/carts/recoverable', false, $position );
		}

		if ( empty( $menu_data ) || in_array( 'automations-v2', $menu_data ) ) {
			add_submenu_page( 'autonami', __( 'Automations', 'wp-marketing-automations' ), __( 'Automations', 'wp-marketing-automations' ), $capability, 'autonami&path=/automations', array(
				$this,
				'autonami_automations_page'
			), 24 );

			if ( BWFAN_Common::is_automation_v1_active() ) {
				add_submenu_page( 'autonami', __( 'Automations', 'wp-marketing-automations' ), __( 'Automations', 'wp-marketing-automations' ) . ' <span style="background-color:#ece6e4; color: #000;white-space: nowrap; border-radius:10px;margin-left:2px;font-size:10px;padding:3px 6px;">Legacy</span>', $capability, 'autonami-automations', array(
					$this,
					'autonami_automations_page'
				), 25 );
			}
		}

		if ( true === bwfan_is_autonami_pro_active() ) {
			if ( empty( $menu_data ) || in_array( 'broadcasts', $menu_data ) ) {
				add_submenu_page( 'autonami', __( 'Broadcasts', 'wp-marketing-automations' ), __( 'Broadcasts', 'wp-marketing-automations' ), $capability, 'autonami&path=/broadcasts/email', function () {
				}, 30 );
			}

			if ( empty( $menu_data ) || in_array( 'templates', $menu_data ) ) {
				add_submenu_page( 'autonami', __( 'Emails', 'wp-marketing-automations' ), __( 'Emails', 'wp-marketing-automations' ), $capability, 'autonami&path=/' . ( bwfan_is_woocommerce_active() ? 'transactional-emails' : 'templates' ), function () {
				}, 30 );
			}

		}

		if ( empty( $menu_data ) || in_array( 'analytics', $menu_data ) ) {
			add_submenu_page( 'autonami', __( 'Analytics', 'wp-marketing-automations' ), __( 'Analytics', 'wp-marketing-automations' ), $capability, 'autonami&path=/analytics', function () {
			}, 15 );
		}


		if ( true === bwfan_is_autonami_pro_active() ) {

			if ( empty( $menu_data ) || in_array( 'forms', $menu_data ) ) {
				add_submenu_page( 'autonami', __( 'Forms', 'wp-marketing-automations' ), __( 'Forms', 'wp-marketing-automations' ), $capability, 'autonami&path=/forms', function () {
				}, 50 );
			}

			if ( empty( $menu_data ) || in_array( 'link-triggers', $menu_data ) ) {
				add_submenu_page( 'autonami', __( 'Link Triggers', 'wp-marketing-automations' ), __( 'Link Triggers', 'wp-marketing-automations' ), $capability, 'autonami&path=/link-triggers', function () {
				}, 50 );
			}

		}

		if ( ( empty( $menu_data ) || in_array( 'mail-setup', $menu_data ) ) && ! get_option( 'bwfan_smtp_recommend', false ) ) {
			add_submenu_page( 'autonami', __( 'Email Setup', 'wp-marketing-automations' ), __( 'Email Setup', 'wp-marketing-automations' ), $capability, 'autonami&path=/mail-setup', function () {
			}, 50 );
		}

		if ( ( empty( $menu_data ) || in_array( 'connectors', $menu_data ) ) && true === bwfan_is_autonami_pro_active() ) {
			add_submenu_page( 'autonami', __( 'Connectors', 'wp-marketing-automations' ), __( 'Connectors', 'wp-marketing-automations' ), $capability, 'autonami&path=/connectors', function () {
			}, 15 );
		}

		if ( empty( $menu_data ) || in_array( 'settings', $menu_data ) ) {
			add_submenu_page( 'autonami', __( 'Settings', 'wp-marketing-automations' ), __( 'Settings', 'wp-marketing-automations' ), $capability, 'autonami&path=/settings', function () {
			}, 45 );
		}


		/** Adding Buy Pro sub menu when pro not activated */
		if ( false === bwfan_is_autonami_pro_active() ) {
			$url  = BWFAN_Common::get_fk_site_links();
			$url  = isset( $url['upgrade'] ) ? $url['upgrade'] : '';
			$link = add_query_arg( [
				'utm_medium' => 'Admin+Menu'
			], $url );
			add_submenu_page( 'autonami', '', '<a href="' . $link . '" style="background-color:#1DA867; color:white;" target="_blank"><strong>' . __( 'Upgrade to Pro', 'wp-marketing-automations' ) . '</strong></a>', $capability, '', function () {
			}, 60 );

			if ( ! empty( $notification ) && ! empty( $notification['campaign'] ) && ! empty( $notification['title'] ) ) {
				$campaign = $notification['campaign'];
				$title    = $notification['title'] . " 🔥";
				$link     = add_query_arg( [
					'utm_source'   => 'WordPress',
					'utm_medium'   => 'Admin+Menu+FKA',
					'utm_campaign' => $campaign
				], $url );
				add_submenu_page( 'autonami', '', '<a href="' . $link . '"  target="_blank">' . $title . '</a>', $capability, 'upgrade_pro', function () {
				}, 61 );
			}
		} else {
			$license_data = BWFAN_Common::get_lk_data();

			if ( is_array( $license_data ) && isset( $license_data['e'] ) && ! empty( $license_data['e'] ) ) {
				$e = new DateTime( $license_data['e'] );
				$c = new DateTime( current_time( 'mysql', true ) );
				/**
				 * the expiry should always be less than on current utc
				 */
				if ( $e->getTimestamp() < $c->getTimestamp() ) {
					$link = add_query_arg( [
						'utm_source'   => 'WordPress',
						'utm_medium'   => 'Admin+Menu+Upgrade+Pro',
						'utm_campaign' => 'FKA+Lite+Plugin',
					], 'https://funnelkit.com/my-account/' );
					add_submenu_page( 'autonami', null, '<a href="' . $link . '" style="background-color:#e15334; color:white;" target="_blank"><strong>' . __( 'License Expired', 'wp-marketing-automations' ) . '</strong></a>', 'manage_options', 'upgrade_pro', function () {
					}, 99 );
				}
			}
		}
	}

	/**
	 * Autonami admin menu capability
	 *
	 * @return mixed|void
	 */
	public static function menu_cap() {
		$capability    = 'manage_options';
		$modified_caps = BWFAN_Common::access_capabilities();
		if ( in_array( 'manage_woocommerce', $modified_caps, true ) ) {
			$capability = 'manage_woocommerce';
		}

		return apply_filters( 'bwfan_menu_access_caps', $capability, $modified_caps );
	}

	/**
	 * Admin Enqueue Assets
	 *
	 * @since 1.0.0
	 */
	public function admin_enqueue_assets() {
		global $post;

		$min = '.min';
		if ( defined( 'BWFAN_IS_DEV' ) && true === BWFAN_IS_DEV ) {
			$min = '';
		}
		$pro_active = false;

		if ( bwfan_is_autonami_pro_active() ) {
			$pro_active = true;
		}

		$v1_active = BWFAN_Common::is_automation_v1_active();

		/**
		 * Adding Woofunnels' font CSS
		 */
		wp_enqueue_style( 'bwfan-woofunnel-fonts', $this->admin_url . '/assets/css/bwfan-admin-font.css', array(), BWFAN_VERSION_DEV );

		/**
		 * Load Builder page assets
		 */
		if ( BWFAN_Common::is_load_admin_assets( 'builder' ) ) {
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		}

		/**
		 * Including izimodal assets
		 */
		if ( true === $v1_active && BWFAN_Common::is_load_admin_assets( 'all' ) ) {
			wp_enqueue_style( 'bwfan-izimodal', $this->admin_url . '/includes/iziModal/iziModal.css', array(), BWFAN_VERSION_DEV );
			wp_enqueue_script( 'bwfan-izimodal', $this->admin_url . '/includes/iziModal/iziModal.js', array(), BWFAN_VERSION_DEV, false );
		}

		$data = array(
			'ajax_nonce'            => wp_create_nonce( 'bwfan-action-admin' ),
			'plugin_url'            => plugin_dir_url( BWFAN_PLUGIN_FILE ),
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'admin_url'             => admin_url(),
			'ajax_chosen'           => wp_create_nonce( 'json-search' ),
			'search_products_nonce' => wp_create_nonce( 'search-products' ),
			'loading_gif_path'      => admin_url() . 'images/wpspin_light.gif',
			'rules_texts'           => array(
				'text_or'         => __( 'OR', 'wp-marketing-automations' ),
				'text_apply_when' => '',
				'remove_text'     => __( 'Remove', 'wp-marketing-automations' ),
			),
			'current_page_id'       => ( isset( $post->ID ) ) ? $post->ID : 0,
		);

		/** WooCommerce ajax endpoint */
		if ( class_exists( 'WC_AJAX' ) ) {
			$data['wc_ajax_url'] = WC_AJAX::get_endpoint( '%%endpoint%%' );
		}

		/** Cart tracking enable checking */
		$bwfan_ab_enable                 = BWFAN_Common::is_cart_abandonment_active();
		$data['wc_cart_tracking_status'] = ( empty( $bwfan_ab_enable ) ) ? 'no' : 'yes';

		/** CRM active */
		$data['crm'] = ( class_exists( 'BWFCRM_Core' ) ) ? 'yes' : 'no';

		/**
		 * Including Autonami assets on all Autonami pages.
		 */
		if ( true === $v1_active && BWFAN_Common::is_load_admin_assets( 'automation' ) ) {

			wp_enqueue_script( 'wp-i18n' );
			wp_enqueue_script( 'wp-util' );

			wp_dequeue_script( 'wpml-select-2' );
			wp_dequeue_script( 'select2' );
			wp_deregister_script( 'select2' );
			wp_enqueue_style( 'bwfan-select2-css', $this->admin_url . '/assets/css/select2.min.css', array(), BWFAN_VERSION_DEV );
			wp_enqueue_style( 'bwfan-sweetalert2-style', $this->admin_url . '/assets/css/sweetalert2.min.css', array(), BWFAN_VERSION_DEV );
			wp_enqueue_style( 'bwfan-toast-style', $this->admin_url . '/assets/css/toast.min.css', array(), BWFAN_VERSION_DEV );
			wp_register_script( 'select2', $this->admin_url . '/assets/js/select2.min.js', array( 'jquery' ), BWFAN_VERSION_DEV, true );
			wp_enqueue_script( 'select2' );
			wp_enqueue_script( 'bwfan-sweetalert2-script', $this->admin_url . '/assets/js/sweetalert2.js', array( 'jquery' ), BWFAN_VERSION_DEV, true );
			wp_enqueue_script( 'bwfan-toast-script', $this->admin_url . '/assets/js/toast.min.js', array( 'jquery' ), BWFAN_VERSION_DEV, true );
			wp_enqueue_editor();
			wp_enqueue_script( 'jquery-ui-datepicker' );

			// jQuery UI theme css file
			wp_register_style( 'jquery-ui', $this->admin_url . '/assets/css/jquery-ui.css', array(), BWFAN_VERSION_DEV );
			wp_enqueue_style( 'jquery-ui' );

			$all_events_merge_tags = BWFAN_Common::get_all_events_merge_tags();
			$all_events_rules      = BWFAN_Common::get_all_events_rules();
			$all_merge_tags        = BWFAN_Core()->merge_tags->get_localize_tags_with_source();

			/** Filter v1 merge tags */
			foreach ( $all_merge_tags as $mergeGroup => $mergeTagList ) {
				if ( empty( $mergeTagList ) ) {
					continue;
				}
				$final_Arr = [];
				foreach ( $mergeTagList as $mergeTagKey => $mergeTagData ) {
					if ( isset( $mergeTagData['support_v1'] ) && $mergeTagData['support_v1'] ) {
						$final_Arr[ $mergeTagKey ] = $mergeTagData;
					}
				}
				if ( ! empty( $final_Arr ) ) {
					$all_merge_tags[ $mergeGroup ] = $final_Arr;
				}
			}

			/**
			 * @todo: Since we are including default merge tags at the bottom of every merge tags then we need to do sorting in JS.
			 */
			$all_events_merge_tags = BWFAN_Common::attach_default_merge_to_events( $all_events_merge_tags, $all_merge_tags );

			$data['events_merge_tags'] = $all_events_merge_tags;
			$data['events_rules']      = $all_events_rules;

			wp_enqueue_style( 'bwfan-admin-app', $this->admin_url . '/assets/css/bwfan-admin-app' . $min . '.css', array(), BWFAN_VERSION_DEV );
			wp_enqueue_style( 'bwfan-admin', $this->admin_url . '/assets/css/bwfan-admin' . $min . '.css', array(), BWFAN_VERSION_DEV );
			wp_enqueue_style( 'bwfan-admin-sub', $this->admin_url . '/assets/css/bwfan-admin-sub' . $min . '.css', array(), BWFAN_VERSION_DEV );
			//}
			/** Common open function */
			wp_enqueue_script( 'bwfan-admin-common', $this->admin_url . '/assets/js/bwfan-admin-common.js', array(), BWFAN_VERSION_DEV, true );

			wp_enqueue_script( 'wc-backbone-modal' );
			wp_enqueue_script( 'bwfan-admin-app', $this->admin_url . '/assets/js/bwfan-admin-ui-rules' . $min . '.js', array(
				'jquery',
				'jquery-ui-datepicker',
				'underscore',
				'backbone',
			), BWFAN_VERSION_DEV, true );

			/** @todo below admin sub css needs to clean */
			wp_enqueue_script( 'bwfan-admin', $this->admin_url . '/assets/js/bwfan-admin' . $min . '.js', array(), BWFAN_VERSION_DEV, true );
			wp_enqueue_script( 'bwfan-admin-ui-actions', $this->admin_url . '/assets/js/bwfan-admin-ui-actions' . $min . '.js', array(), BWFAN_VERSION_DEV, true );

			if ( BWFAN_Common::is_load_admin_assets( 'automation' ) ) {
				wp_enqueue_script( 'wp-block-library' );
				wp_tinymce_inline_scripts();
				wp_enqueue_script( 'jquery-ui-draggable' );
				wp_enqueue_script( 'bwfan-admin-ui', $this->admin_url . '/assets/js/bwfan-admin-ui' . $min . '.js', array( 'bwfan-admin-ui-actions' ), BWFAN_VERSION_DEV, true );
			}
		}

		$data['bitly_success_authentication_message'] = __( 'Successfully Authenticated', 'wp-marketing-automations' );
		$data['setting_page_url']                     = admin_url( 'admin.php?page=autonami-settings' );
		$data['connector_page_url']                   = admin_url( 'admin.php?page=autonami&path=connectors' );
		$data                                         = apply_filters( 'bwfan_admin_localize_data', $data, $this );
		$data['coupon_enabled']                       = ( 'yes' === get_option( 'woocommerce_enable_coupons' ) ) ? 'y' : 'n';
		$data['pro_active']                           = $pro_active;

		if ( BWFAN_Common::is_load_admin_assets( 'all' ) ) {
			$data['bwfan_global_settings'] = BWFAN_Common::get_global_settings();
		}

		/** Settings schema (~60K) is never localized; the settings screen fetches it via GET /settings (refreshSettingsSchema in JS). */

		if ( bwfan_is_autonami_pro_active() ) {
			$data['pro_version_notice']           = [ 'version' => BWFAN_MIN_PRO_VERSION ];
			$data['pro_version_notice']['status'] = ! ( version_compare( BWFAN_PRO_VERSION, BWFAN_MIN_PRO_VERSION, '>=' ) );
		}
		$data['lk'] = BWFAN_Common::get_lk_data();

		wp_localize_script( 'jquery', 'bwfanParams', $data );

		$automation_id = BWFAN_Core()->automations->get_automation_id();

		if ( is_null( $automation_id ) || empty( $automation_id ) ) {
			return;
		}

		/** Single v1 automation edit page. So continue respective params localization */

		global $automation_global_events_js_data;

		$automation_global_js_object      = array();
		$automation_global_events_js_data = array();

		$automation_global_events_js_data['automation_sync_state'] = 'off';

		$automation_meta = BWFAN_Core()->automations->get_automation_data_meta( $automation_id );

		$all_sources_events  = BWFAN_Load_Sources::get_sources_events_arr();
		$all_triggers        = BWFAN_Core()->sources->get_source_localize_data();
		$all_triggers_events = BWFAN_Core()->sources->get_sources_events_localize_data();
		$all_integrations    = BWFAN_Core()->integration->get_integration_actions_localize_data();
		$all_automations     = BWFAN_Core()->integration->get_integration_localize_data();

		$automation_global_js_object['trigger']                  = array();
		$automation_global_js_object['actions']                  = array();
		$automation_global_js_object['condition']                = array();
		$automation_global_js_object['ui']                       = array();
		$automation_global_js_object['uiData']                   = array();
		$automation_global_events_js_data['all_integrations']    = array();
		$automation_global_events_js_data['all_automations']     = array();
		$automation_global_events_js_data['all_triggers_events'] = array();
		$automation_global_events_js_data['all_triggers']        = array();
		$automation_global_events_js_data['automation_id']       = $automation_id;

		/** Localize all the data which needs to be present on single automation screen. */
		if ( isset( $automation_meta['event'] ) && ! empty( $automation_meta['event'] ) ) {
			$automation_global_js_object['trigger']['source'] = $automation_meta['source'];
			$automation_global_js_object['trigger']['event']  = $automation_meta['event'];
			$automation_global_js_object['trigger']['name']   = __( 'Not Found', 'wp-marketing-automations' );

			$single_event = BWFAN_Core()->sources->get_event( $automation_meta['event'] );
			if ( ! is_null( $single_event ) && true === $single_event->is_time_independent() ) {
				$automation_global_events_js_data['is_time_independent'] = true;
				$automation_global_events_js_data['name']                = $single_event->get_name();
			} else {
				$automation_global_events_js_data['is_time_independent'] = false;
			}
		}
		if ( isset( $automation_meta['event_meta'] ) ) {
			$automation_global_js_object['trigger']['event_meta'] = $automation_meta['event_meta'];
		}
		$automation_global_js_object['actions'] = isset( $automation_meta['actions'] ) ? $automation_meta['actions'] : array();
		if ( isset( $automation_meta['condition'] ) ) {
			$automation_global_js_object['condition'] = $automation_meta['condition'];
		}
		if ( isset( $automation_meta['ui'] ) ) {
			$automation_global_js_object['ui'] = $automation_meta['ui'];
		}
		if ( isset( $automation_meta['uiData'] ) ) {
			$automation_global_js_object['uiData'] = $automation_meta['uiData'];
		}
		if ( isset( $all_integrations ) ) {
			$automation_global_events_js_data['all_integrations'] = $all_integrations;
		}
		if ( isset( $all_automations ) ) {
			$automation_global_events_js_data['all_automations'] = $all_automations;
		}
		if ( isset( $all_triggers_events ) ) {
			$automation_global_events_js_data['all_triggers_events'] = $all_triggers_events;
		}
		if ( isset( $all_triggers ) ) {
			$automation_global_events_js_data['all_triggers'] = $all_triggers;
		}
		if ( isset( $all_sources_events ) ) {
			$automation_global_events_js_data['all_sources_events'] = $all_sources_events;
		}
		if ( isset( $all_merge_tags ) ) {
			$automation_global_events_js_data['all_merge_tags'] = $all_merge_tags;
		}

		$automation_global_events_js_data['int_actions'] = BWFAN_Core()->integration->get_mapped_arr_action_with_integration();
		$automation_global_events_js_data['actions_int'] = BWFAN_Core()->integration->get_mapped_arr_integration_name_with_action_name();
		$automation_global_events_js_data['pro_actions'] = BWFAN_Common::merge_default_actions();
		$automation_global_events_js_data                = apply_filters( 'bwfan_admin_builder_localized_data', $automation_global_events_js_data );

		/** Exclude actions from events */
		$events                  = BWFAN_Core()->sources->get_events();
		$events_included_actions = array();
		$events_excluded_actions = array();

		if ( is_array( $events ) && count( $events ) > 0 ) {
			foreach ( $events as $event ) {
				/**
				 * @var $event_instance BWFAN_Event;
				 */
				$events_included_actions[ $event->get_slug() ] = $event->get_included_actions();
				$events_excluded_actions[ $event->get_slug() ] = $event->get_excluded_actions();
			}
		}

		// all event js data and then set localized unique key
		$all_event_js_data = BWFAN_Core()->admin->get_events_js_data();
		foreach ( $all_event_js_data as $key => $data ) {
			$all_event_js_data[ $key ]['localized_automation_key'] = wp_generate_password( 32, false );
		}
		$all_event_js_data = apply_filters( 'bwfan_all_event_js_data', $all_event_js_data, $automation_meta, $automation_id );

		$automation_global_events_js_data = apply_filters( 'bwfan_automation_global_js_data', $automation_global_events_js_data );

		wp_enqueue_media();
		wp_localize_script( 'bwfan-admin', 'bwfan_automation_ui_data_detail', $automation_global_js_object );
		wp_localize_script( 'bwfan-admin', 'bwfan_automation_data', $automation_global_events_js_data );
		wp_localize_script( 'bwfan-admin', 'bwfan_events_js_data', $all_event_js_data );
		wp_localize_script( 'bwfan-admin', 'bwfan_events_included_actions', $events_included_actions );
		wp_localize_script( 'bwfan-admin', 'bwfan_events_excluded_actions', $events_excluded_actions );
		wp_localize_script( 'bwfan-admin', 'bwfan_set_select2ajax_js_data', BWFAN_Core()->admin->get_select2ajax_js_data() );
		wp_localize_script( 'bwfan-admin', 'bwfan_set_actions_js_data', BWFAN_Core()->admin->get_actions_js_data() );


		do_action( 'bwfan_automation_v1_loaded' );
	}

	/**
	 * Get Events Js Data
	 *
	 * @since 1.0.0
	 */
	public function get_events_js_data() {
		return $this->events_js_data;
	}

	/**
	 * Set the event field values for each html field present in that event.
	 *
	 * @param $event_slug
	 * @param $key
	 * @param $data
	 */
	public function set_events_js_data( $event_slug, $key, $data ) {
		if ( ! isset( $this->events_js_data[ $event_slug ] ) ) {
			$this->events_js_data[ $event_slug ]         = [];
			$this->events_js_data[ $event_slug ][ $key ] = $data;

			return;
		}

		if ( ! isset( $this->events_js_data[ $event_slug ][ $key ] ) ) {
			$this->events_js_data[ $event_slug ][ $key ] = $data;

			return;
		}

		$saved_value = is_string( $this->events_js_data[ $event_slug ][ $key ] ) ? json_decode( $this->events_js_data[ $event_slug ][ $key ] ) : array();

		if ( ! empty( $data ) ) {
			$data = is_string( $data ) ? json_decode( $data ) : array();
			foreach ( $data as $key1 => $value1 ) {
				$saved_value[ $key1 ] = $value1;
			}
		}
		$this->events_js_data[ $event_slug ][ $key ] = wp_json_encode( $saved_value );
	}

	/**
	 * Get Select2Ajax Js Data
	 *
	 * @since 1.0.0
	 */
	public function get_select2ajax_js_data() {
		return $this->select2ajax_js_data;
	}

	/**
	 * @param string $key a search type key to set data against to
	 * @param array $data
	 */
	public function set_select2ajax_js_data( $key, $data ) {
		if ( isset( $this->select2ajax_js_data[ $key ] ) ) {

			$this->select2ajax_js_data[ $key ] = array_replace( $this->select2ajax_js_data[ $key ], $data );
		} else {
			$this->select2ajax_js_data[ $key ] = $data;
		}
	}

	/**
	 * Get Actions Js Data
	 *
	 * @since 1.0.0
	 */
	public function get_actions_js_data() {
		return $this->actions_js_data;
	}

	/**
	 * Set action's html fields data.
	 *
	 * @param $integration_slug
	 * @param $key
	 * @param $data
	 */
	public function set_actions_js_data( $integration_slug, $key, $data ) {
		if ( isset( $this->actions_js_data[ $integration_slug ] ) ) {
			if ( isset( $this->actions_js_data[ $integration_slug ][ $key ] ) ) {
				$saved_value = json_decode( $this->actions_js_data[ $integration_slug ][ $key ] );

				if ( ! empty( $data ) ) {
					$data = json_decode( $data );
					foreach ( $data as $key1 => $value1 ) {
						$saved_value[ $key1 ] = $value1;
					}
				}
				$this->actions_js_data[ $integration_slug ][ $key ] = wp_json_encode( $saved_value );
			} else {
				$this->actions_js_data[ $integration_slug ][ $key ] = $data;
			}
		} else {
			$this->actions_js_data[ $integration_slug ][ $key ] = $data;
		}
	}

	/**
	 * Autonami Page
	 *
	 * @since 1.0.0
	 */
	public function autonami_page() {
		//phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_GET['page'] ) && 'autonami' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		if ( class_exists( 'BWFCRM_Dashboard' ) ) {
			$dashboard_page = BWFCRM_Dashboard::get_instance();

			return $dashboard_page->render();
		} else {
			include_once $this->admin_path . '/view/automation-dashboards.php';
		}
		//phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Js Variables
	 *
	 * @since 1.0.0
	 */
	public function js_variables() {
		$time_texts = array(
			'singular' => array(
				'minutes' => __( 'minute', 'wp-marketing-automations' ),
				'hours'   => __( 'hour', 'wp-marketing-automations' ),
				'day'     => __( 'day', 'wp-marketing-automations' ),
			),
			'plural'   => array(
				'minutes' => __( 'minutes', 'wp-marketing-automations' ),
				'hours'   => __( 'hours', 'wp-marketing-automations' ),
				'day'     => __( 'days', 'wp-marketing-automations' ),
			),
		);
		$data       = array(
			'site_url'   => home_url(),
			'texts'      => array(
				'sync_title'                         => __( 'Sync Integration', 'wp-marketing-automations' ),
				'sync_text'                          => __( 'All the data of this Integration will be Synced.', 'wp-marketing-automations' ),
				'sync_wait'                          => __( 'Please Wait...', 'wp-marketing-automations' ),
				'sync_progress'                      => __( 'Sync in progress...', 'wp-marketing-automations' ),
				'sync_success_title'                 => __( 'Integration Synced', 'wp-marketing-automations' ),
				'sync_success_text'                  => __( 'We have detected change in the integration during syncing. Please Re-save your Automations.', 'wp-marketing-automations' ),
				'sync_oops_title'                    => __( 'Oops', 'wp-marketing-automations' ),
				'sync_oops_text'                     => __( 'There was some error. Please try again later.', 'wp-marketing-automations' ),
				'delete_int_title'                   => __( 'There was some error. Please try again later.', 'wp-marketing-automations' ),
				'delete_int_text'                    => __( 'There was some error. Please try again later.', 'wp-marketing-automations' ),
				'delete_int_prompt_title'            => __( 'Delete Connector', 'wp-marketing-automations' ),
				'delete_int_prompt_text'             => __( 'All the Tasks of this Integration will be Deleted.', 'wp-marketing-automations' ),
				'delete_int_wait_title'              => __( 'Please Wait...', 'wp-marketing-automations' ),
				'delete_int_wait_text'               => __( 'Disconnecting the connector ...', 'wp-marketing-automations' ),
				'delete_int_success'                 => __( 'Connector Disconnected', 'wp-marketing-automations' ),
				'task_executed_success'              => __( 'Task Executed', 'wp-marketing-automations' ),
				'task_executed_just'                 => __( 'Just Executed', 'wp-marketing-automations' ),
				'log_deleted_title'                  => __( 'Log Deleted', 'wp-marketing-automations' ),
				'task_deleted_success'               => __( 'Task Deleted', 'wp-marketing-automations' ),
				'change_event_title'                 => __( 'Change in Event', 'wp-marketing-automations' ),
				'change_event_text'                  => __( 'You are about to change the event. You would need to Re-create your automation.', 'wp-marketing-automations' ),
				'delete_automation_title'            => __( 'Delete Automation', 'wp-marketing-automations' ),
				'delete_automation_text'             => __( 'All the Tasks of this automation will be deleted.', 'wp-marketing-automations' ),
				'delete_automation_wait_title'       => __( 'Please Wait...', 'wp-marketing-automations' ),
				'delete_automation_wait_text'        => __( 'Deleting the automation...', 'wp-marketing-automations' ),
				'delete_automation_success'          => __( 'Automation Deleted', 'wp-marketing-automations' ),
				'merge_tag_error_title'              => __( 'Merge Tag Error', 'wp-marketing-automations' ),
				'merge_tag_error_text'               => __( 'Please Check All Your Merge Tags.', 'wp-marketing-automations' ),
				'wrong_action_title'                 => __( 'Incompatible Action', 'wp-marketing-automations' ),
				'wrong_action_text'                  => __( 'Selected Action is not compatible with the selected Event.', 'wp-marketing-automations' ),
				'wrong_event_title'                  => __( 'Incompatible Event', 'wp-marketing-automations' ),
				'wrong_event_text'                   => __( 'Selected Event is not compatible with the Integrations. If you proceed, then you would need to re-create your integrations.', 'wp-marketing-automations' ),
				'no_event'                           => __( 'Please select an event', 'wp-marketing-automations' ),
				'no_trigger'                         => __( 'Please select a trigger', 'wp-marketing-automations' ),
				'no_action'                          => __( 'Please select an action', 'wp-marketing-automations' ),
				'source_change'                      => __( 'Change in Source ! You would need to re-create you automation.', 'wp-marketing-automations' ),
				'activated'                          => __( 'Activated', 'wp-marketing-automations' ),
				'deactivated'                        => __( 'Deactivated', 'wp-marketing-automations' ),
				'sync_process_oops_title'            => __( 'Automation is in sync process', 'wp-marketing-automations' ),
				'task_delete_title'                  => __( 'Delete Task', 'wp-marketing-automations' ),
				'task_delete_text'                   => __( 'Are you sure to delete the task ?', 'wp-marketing-automations' ),
				'delete_batch_process_title'         => __( 'Are you sure to delete the batch process', 'wp-marketing-automations' ),
				'delete_batch_process_text'          => __( 'This batch process will be deleted.', 'wp-marketing-automations' ),
				'delete_batch_process_wait_title'    => __( 'Please Wait...', 'wp-marketing-automations' ),
				'delete_batch_process_wait_text'     => __( 'Deleting the batch process...', 'wp-marketing-automations' ),
				'delete_batch_process_success'       => __( 'Batch Process Deleted', 'wp-marketing-automations' ),
				'terminate_batch_process_title'      => __( 'Are you sure to terminate the batch process', 'wp-marketing-automations' ),
				'terminate_batch_process_text'       => __( 'This batch process will be terminated.', 'wp-marketing-automations' ),
				'terminate_batch_process_wait_title' => __( 'Please Wait...', 'wp-marketing-automations' ),
				'terminate_batch_process_wait_text'  => __( 'Terminating the batch process...', 'wp-marketing-automations' ),
				'terminate_batch_process_success'    => __( 'Batch Process Terminated', 'wp-marketing-automations' ),
			),
			'time_delay' => $time_texts,
		);
		if ( class_exists( 'BWFCRM_Common' ) ) {
			$data['crm'] = '1';
		}

		$wfo = 'window.bwfan=' . wp_json_encode( $data ) . ';';
		echo "<script>$wfo</script>"; //phpcs:ignore WordPress.Security.EscapeOutput

		?>

        <style type="text/css">
            #adminmenu li.bwfan_admin_menu_b_top {
                border-top: 1px dashed #65686b;
                padding-top: 5px;
                margin-top: 5px
            }

            #adminmenu li.bwfan_admin_menu_b_bottom {
                border-bottom: 1px dashed #65686b;
                padding-bottom: 5px;
                margin-bottom: 5px
            }
        </style>

		<?php
	}

	/**
	 * Admin Footer Text
	 *
	 * @since 1.0.0
	 */
	public function admin_footer_text( $footer_text ) {
		if ( false === BWFAN_Common::is_load_admin_assets( 'all' ) ) {
			return $footer_text;
		}
		if ( BWFAN_Common::is_load_admin_assets( 'builder' ) ) {
			return '';
		}
		$link = add_query_arg( array(
			'utm_source'   => 'WordPress',
			'utm_medium'   => 'Footer+Support',
			'utm_campaign' => 'FKA+Lite+Plugin',
		), 'https://funnelkit.com/support' );

		/* translators: 1: Dynamic URL */

		return sprintf( __( 'Over %1$s 5 star reviews show that FunnelKit users trust our top-rated support for their online business. Do you need help? <a href="%2$s" target="_blank"><b>Contact FunnelKit Support</b></a>.', 'wp-marketing-automations' ), '330+', esc_url( $link ) );
	}

	/**
	 * Admin Footer Version
	 *
	 * @since 1.0.0
	 */
	public function admin_footer_version( $footer_version ) {
		if ( false === BWFAN_Common::is_load_admin_assets( 'all' ) ) {
			return $footer_version;
		}
		if ( BWFAN_Common::is_load_admin_assets( 'builder' ) ) {
			return '';
		}

		/* translators: 1: Dynamic Data */
		return sprintf( __( 'Version %1$s', 'wp-marketing-automations' ), BWFAN_VERSION );
	}

	/**
	 * Get Automation Section
	 *
	 * @since 1.0.0
	 */
	public function get_automation_section() {
		if ( isset( $_GET['section'] ) && ! empty( sanitize_text_field( wp_unslash( $_GET['section'] ) ) ) && isset( $_GET['page'] ) && 'autonami-automations' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			return sanitize_key( wp_unslash( $_GET['section'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change. sanitize_key() strips path characters so the section cannot traverse the include at automation-builder-view.php
		}

		return 'automation';
	}

	/**
	 * Maybe Set Automation Id
	 *
	 * @since 1.0.0
	 */
	public function maybe_set_automation_id() {
		if ( $this->is_autonami_page() && isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			BWFAN_Core()->automations->set_automation_id( absint( wp_unslash( $_GET['edit'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			BWFAN_Core()->automations->set_automation_details();

			do_action( 'bwfan_automation_data_set_automation' ); // WordPress.CSRF.NonceVerification.NoNonceVerification
		}
	}

	/**
	 * Is Autonami Page
	 *
	 * @since 1.0.0
	 */
	public function is_autonami_page() {
		$page = filter_input( INPUT_GET, 'page' ); // WordPress.CSRF.NonceVerification.NoNonceVerification
		if ( is_null( $page ) ) {
			return false;
		}

		return ( 'autonami-automations' === $page || false !== strpos( $page, 'autonami' ) );
	}

	/**
	 * Change Autonami Menu Icon
	 *
	 * @since 1.0.0
	 */
	public function change_autonami_menu_icon() {
		?>
        <style>
            .wp-admin #adminmenu .toplevel_page_autonami .wp-menu-image:before {
                content: none;
            }

            .wp-admin #adminmenu .toplevel_page_autonami .wp-not-current-submenu .wp-menu-image {
                background-image: url("<?php echo esc_url( plugin_dir_url( WooFunnel_Loader::$ultimate_path ) . 'woofunnels/assets/img/bwf-icon-grey.svg' ); ?>") !important;
            }

            .wp-admin #adminmenu .toplevel_page_autonami .wp-has-current-submenu .wp-menu-image {
                background-image: url("<?php echo esc_url( plugin_dir_url( WooFunnel_Loader::$ultimate_path ) . 'woofunnels/assets/img/bwf-icon-white.svg' ); ?>") !important;
            }

            .wp-admin #adminmenu .toplevel_page_autonami .wp-menu-image {
                background-repeat: no-repeat;
                position: relative;
                top: 5px;
                background-position: 50% 25%;
                background-size: 60%;
            }
        </style>
		<?php
	}

	/**
	 * Hooked over 'plugin_action_links_{PLUGIN_BASENAME}' WordPress hook to add deactivate popup support
	 *
	 * @param array $links array of existing links
	 *
	 * @return array modified array
	 */
	public function plugin_actions( $links ) {
		if ( false === bwfan_is_autonami_pro_active() ) {
			$link = BWFAN_Common::get_fk_site_links();
			$link = isset( $link['upgrade'] ) ? $link['upgrade'] : '';
			$link = add_query_arg( [
				'utm_medium'  => 'Plugin+Action+Links',
				'utm_content' => 'Upgrade'
			], $link );

			$links = array_merge( [
				'pro_upgrade' => '<a href="' . $link . '" target="_blank" style="color: #1da867 !important;font-weight:600">' . __( 'Upgrade to Pro', 'wp-marketing-automations' ) . '</a>'
			], $links );
		}

		if ( isset( $links['edit'] ) ) {
			unset( $links['edit'] );
		}
		if ( isset( $links['deactivate'] ) ) {
			$links['deactivate'] .= '<i class="woofunnels-slug" data-slug="' . BWFAN_PLUGIN_BASENAME . '"></i>';
		}

		return $links;
	}

	/**
	 * Tooltip
	 *
	 * @since 1.0.0
	 */
	public function tooltip( $text ) {
		?>
        <span class="bwfan-help"><i class="icon"></i><div class="helpText"><?php echo esc_html( $text ); ?></div></span>
		<?php
	}

	/**
	 * Remove all the notices in our dashboard pages as they might break the design.
	 */
	public function maybe_remove_all_notices_on_page() {
		if ( isset( $_GET['page'] ) && 'autonami' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) && isset( $_GET['section'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			remove_all_actions( 'admin_notices' );
		}
	}

	/**
	 * Maybe Set As Ct Worker
	 *
	 * @since 1.0.0
	 */
	public function maybe_set_as_ct_worker() {
		if ( BWFAN_Common::is_automation_v1_active() && ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_run_queue' ) ) {
			bwf_schedule_recurring_action( time(), MINUTE_IN_SECONDS, 'bwfan_run_queue' );
		}

		if ( ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_run_queue_v2' ) ) {
			bwf_schedule_recurring_action( time(), MINUTE_IN_SECONDS, 'bwfan_run_queue_v2' );
		}

		if ( ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_delete_logs' ) ) {
			$store_time = BWFAN_Common::get_store_time( 4 );
			bwf_schedule_recurring_action( $store_time, DAY_IN_SECONDS, 'bwfan_delete_logs' );
		}

		if ( ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_run_event_queue' ) ) {
			bwf_schedule_recurring_action( time(), MINUTE_IN_SECONDS, 'bwfan_run_event_queue' );
		}

		$midnight_time = BWFAN_Common::get_midnight_store_time();
		if ( ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_run_midnight_cron' ) ) {
			bwf_schedule_recurring_action( $midnight_time, DAY_IN_SECONDS, 'bwfan_run_midnight_cron' );
		}
	}

	/**
	 * Schedule Abandoned Cart Cron
	 *
	 * @since 1.0.0
	 */
	public function schedule_abandoned_cart_cron() {
		/** If no WC, return */
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		/** Schedule abandoned cart tracking action */
		if ( ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_check_abandoned_carts' ) ) {
			bwf_schedule_recurring_action( time(), MINUTE_IN_SECONDS, 'bwfan_check_abandoned_carts' ); // check for abandoned carts for every minute
		}

		/** Schedule delete expired coupons and abandoned lost cart actions */
		$delete_expired_coupons = BWFAN_Common::bwf_has_action_scheduled( 'bwfan_delete_expired_autonami_coupons' );
		$mark_lost_carts        = BWFAN_Common::bwf_has_action_scheduled( 'bwfan_mark_abandoned_lost_cart' );

		$midnight_time = BWFAN_Common::get_midnight_store_time();
		if ( ! $delete_expired_coupons ) {
			bwf_schedule_recurring_action( $midnight_time, DAY_IN_SECONDS, 'bwfan_delete_expired_autonami_coupons' ); // Run once in a day
		}
		if ( ! $mark_lost_carts ) {
			bwf_schedule_recurring_action( $midnight_time, DAY_IN_SECONDS, 'bwfan_mark_abandoned_lost_cart' ); // Run once in a day
		}

		if ( true === apply_filters( 'bwfan_ab_delete_inactive_carts', false ) && ! BWFAN_Common::bwf_has_action_scheduled( 'bwfan_delete_old_abandoned_carts' ) ) {
			bwf_schedule_recurring_action( $midnight_time, DAY_IN_SECONDS, 'bwfan_delete_old_abandoned_carts' ); // Run once in a day
		}
	}

	/**
	 * Maybe Handle Optin Choice
	 *
	 * @since 1.0.0
	 */
	public function maybe_handle_optin_choice() {
		if ( isset( $_GET['bwfan-optin-choice'] ) && isset( $_GET['_bwfan_optin_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_bwfan_optin_nonce'] ) ), 'bwfan_optin_nonce' ) ) {
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-marketing-automations' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Cheating huh?', 'wp-marketing-automations' ) );
			}

			$optin_choice = sanitize_text_field( wp_unslash( $_GET['bwfan-optin-choice'] ) );
			if ( $optin_choice === 'yes' ) {
				$this->allow_optin();

			} else {
				$this->block_optin();
			}

			do_action( 'bwfan_after_optin_choice', $optin_choice );
			wp_safe_redirect( admin_url( 'admin.php?page=autonami' ) );
			exit;
		}
	}

	/**
	 * Allow Optin
	 *
	 * @since 1.0.0
	 */
	public function allow_optin() {
		update_option( 'bwfan_is_opted', 'yes', true );

		// try to push data for once
		$data = WooFunnels_optIn_Manager::collect_data();

		// posting data to api
		WooFunnels_API::post_tracking_data( $data );
	}

	/**
	 * Block Optin
	 *
	 * @since 1.0.0
	 */
	public function block_optin() {
		update_option( 'bwfan_is_opted', 'no', true );
	}

	/**
	 * Maybe Redirect To Automation
	 *
	 * @since 1.0.0
	 */
	public function maybe_redirect_to_automation() {
		$page = filter_input( INPUT_GET, 'page' );
		$id   = filter_input( INPUT_GET, 'edit' );
		if ( empty( $page ) || 'autonami-automations' !== strval( $page ) || ! empty( $id ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=autonami&path=/automations-v1' ) );
		exit;
	}

	/**
	 * Get Automation Id
	 *
	 * @since 1.0.0
	 */
	public function get_automation_id() {
		if ( isset( $_GET['edit'] ) && ! empty( sanitize_text_field( wp_unslash( $_GET['edit'] ) ) ) && isset( $_GET['page'] ) && 'autonami-automations' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			return absint( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
		}

		return false;
	}

	/**
	 *  autonami page
	 */
	public function autonami_automations_page() {

		$external_template = apply_filters( 'bwfan_load_external_autonami_page_template', '' );
		if ( ! empty( $external_template ) ) {
			if ( is_array( $external_template ) ) {
				foreach ( $external_template as $template ) {
					require_once $template;
				}
			} else {
				require_once $external_template;
			}

			return;
		}

		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			if ( isset( $_GET['section'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
				if ( 'preview_email' === sanitize_text_field( wp_unslash( $_GET['section'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
					include_once $this->admin_path . '/view/preview_email.php';
					exit;
				}
			}

			include_once $this->admin_path . '/view/automation-builder-view.php';

			return;
		}

		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			switch ( $tab ) {
				case 'tasks':
					wp_safe_redirect( admin_url( 'admin.php?page=autonami&path=/automations/task-history' ) );
					exit;
				case 'logs':
					wp_safe_redirect( admin_url( 'admin.php?page=autonami&path=/automations/task-history' ) );
					exit;
				default:
					break;
			}

			return;
		}
	}

	/**
	 * redirect to automation list page
	 */
	public function redirect_autonami_automations_page() {
		$tab  = filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW );
		$edit = filter_input( INPUT_GET, 'edit', FILTER_UNSAFE_RAW );
		if ( ! empty( $tab ) || ! empty( $edit ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=autonami&path=/automations-v1' ) );
		exit;
	}

	/**
	 *  making tools tab data
	 */
	public function make_tools_tabs_data() {

		$tab_arr = array(
			'unsubscribers' => array(
				'name' => __( 'Unsubscribers', 'wp-marketing-automations' ),
				'href' => admin_url( 'admin.php?page=autonami-settings&tab=unsubscribers' ),
			),
			'api_endpoints' => array(
				'name' => __( 'Endpoints', 'wp-marketing-automations' ),
				'href' => admin_url( 'admin.php?page=autonami-settings&tab=api_endpoints' ),
			),
			'actions'       => array(
				'name' => __( 'Actions', 'wp-marketing-automations' ),
				'href' => admin_url( 'admin.php?page=autonami-settings&tab=actions' ),
			),
		);

		if ( 'autonami-settings' === filter_input( INPUT_GET, 'page' ) ) {
			$tab = filter_input( INPUT_GET, 'tab' );
			switch ( $tab ) {
				case 'api_endpoints':
				case 'actions':
					$tab_arr[ $tab ]['active'] = true;
					break;
				default:
					$tab_arr['unsubscribers']['active'] = true;
			}
		}

		$tab_arr = apply_filters( 'bwfan_tools_tab_array', $tab_arr );
		$this->make_tools_tab_ui( $tab_arr );
	}

	/** making tools tab html
	 *
	 * @param $arr
	 * @param string $prefix
	 */
	public function make_tools_tab_ui( $arr, $prefix = 'bwfan' ) {
		if ( ! is_array( $arr ) || count( $arr ) === 0 ) {
			return;
		}

		ob_start();
		echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';
		foreach ( $arr as $key => $val ) {
			if ( ! isset( $val['name'] ) || empty( $val['name'] ) ) {
				continue;
			}
			$href  = ( isset( $val['href'] ) && ! empty( $val['href'] ) ) ? $val['href'] : 'javascript:void(0)';
			$class = array( 'nav-tab', $prefix . '_tab_' . $key );
			if ( isset( $val['active'] ) && true === $val['active'] ) {
				$class[] = 'nav-tab-active';
			}
			if ( isset( $val['class'] ) && is_array( $val['class'] ) && count( $val['class'] ) > 0 ) {
				$class = array_merge( $class, $val['class'] );
			}
			$attr = array();
			if ( isset( $val['attr'] ) && is_array( $val['attr'] ) && count( $val['attr'] ) > 0 ) {
				$attr = $val['attr'];
				array_walk( $attr, function ( &$val, $key ) {
					if ( ! empty( $key ) && ! empty( $val ) ) {
						$val = ' ' . $key . '=' . $val;
					}
				} );
			}

			?>
            <a href="<?php echo esc_url( $href ); ?>"
               class="<?php echo esc_attr( implode( ' ', $class ) ); ?>"<?php echo esc_attr( implode( ' ', $attr ) ); ?>><?php echo esc_html( $val['name'] ); ?></a>
			<?php
		}
		echo '</nav>';
		echo ob_get_clean(); //phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** handling autonami connector page  */
	public function redirect_autonami_connector_page() {
		/** handling connectors oAuth 2.0 in autonami */
		if ( isset( $_GET['page'] ) && 'autonami' === $_GET['page'] && isset( $_GET['tab'] ) && 'connector' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy admin URL redirect; query re-encoded by http_build_query() and sent via wp_safe_redirect()
			$_GET['path'] = '/connectors';
			unset( $_GET['tab'] );
			$build_query = http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy admin URL redirect; query re-encoded by http_build_query() and sent via wp_safe_redirect()

			wp_safe_redirect( admin_url( 'admin.php?' . $build_query ) );
			exit;
		}

		/** handling batch process page in autonami */
		if ( isset( $_GET['page'] ) && 'autonami' === $_GET['page'] && isset( $_GET['tab'] ) && 'batch_process' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy admin URL redirect; query re-encoded by http_build_query() and sent via wp_safe_redirect()
			$_GET['page'] = 'autonami-automations';
			$build_query  = http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy admin URL redirect; query re-encoded by http_build_query() and sent via wp_safe_redirect()

			wp_safe_redirect( admin_url( 'admin.php?' . $build_query ) );
			exit;
		}

		/** handling autonami builder page */
		if ( isset( $_GET['page'] ) && 'autonami' === $_GET['page'] && isset( $_GET['section'] ) && 'automation' === $_GET['section'] && filter_input( INPUT_GET, 'edit' ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy admin URL redirect; query re-encoded by http_build_query() and sent via wp_safe_redirect()
			$_GET['page'] = 'autonami-automations';
			unset( $_GET['section'] );
			$build_query = http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- legacy admin URL redirect; query re-encoded by http_build_query() and sent via wp_safe_redirect()

			wp_safe_redirect( admin_url( 'admin.php?' . $build_query ) );
			exit;
		}

		return;
	}

	/**
	 * Automation Modify Actions Groups
	 *
	 * @since 1.0.0
	 */
	public function automation_modify_actions_groups( $arr ) {
		/** Adding custom WP send email */
		if ( ! isset( $arr['messaging'] ) ) {
			$arr['messaging'] = array(
				'label'    => __( 'Messaging', 'wp-marketing-automations' ),
				'priority' => 5,
				'subgroup' => array( 'wp' => 'Email' ),
			);
		} else {
			$arr['messaging']['subgroup'] = array_merge( array( 'wp' => __( 'Email', 'wp-marketing-automations' ) ), $arr['messaging']['subgroup'] );
		}
		if ( isset( $arr['wp']['subgroup']['wp'] ) ) {
			unset( $arr['wp']['subgroup']['wp'] );
		}

		/** Modify WP_ADV group */
		if ( isset( $arr['wp']['subgroup']['wp_adv'] ) ) {
			unset( $arr['wp']['subgroup']['wp_adv'] );

			$arr['wp']['subgroup']['wp_adv_1'] = __( 'Users', 'wp-marketing-automations' );
			$arr['wp']['subgroup']['wp_adv_2'] = __( 'Advanced', 'wp-marketing-automations' );
		}

		/** Adding Autonami new groups */
		if ( isset( $arr['autonami'] ) ) {
			$arr['autonami']['subgroup'] = array(
				'autonami_1' => __( 'Contacts', 'wp-marketing-automations' ),
				'autonami_2' => __( 'Automation', 'wp-marketing-automations' ),
			);
		}

		/** Adding HTTP Post in Send Data */
		if ( ! isset( $arr['send_data'] ) ) {
			$arr['send_data'] = array(
				'label'    => __( 'Send Data', 'wp-marketing-automations' ),
				'priority' => 85,
				'subgroup' => array(),
			);
		}
		$arr['send_data']['subgroup'] = array_merge( array( 'http_post' => __( 'Post', 'wp-marketing-automations' ) ), $arr['send_data']['subgroup'] );

		return $arr;
	}

	/**
	 * Automation Modify Integrations
	 *
	 * @since 1.0.0
	 */
	public function automation_modify_integrations( $arr ) {
		/** Adding WP ADV integration actions */
		if ( isset( $arr['wp_adv'] ) ) {
			$arr = $this->copy_integration_action_arr( $arr, 'wp_createuser', 'wp_adv', 'wp_adv_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'wp_update_user_meta', 'wp_adv', 'wp_adv_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'wp_update_user_role', 'wp_adv', 'wp_adv_1' );

			$arr = $this->copy_integration_action_arr( $arr, 'wp_custom_callback', 'wp_adv', 'wp_adv_2' );
			$arr = $this->copy_integration_action_arr( $arr, 'wp_debug', 'wp_adv', 'wp_adv_2' );

			$arr = $this->copy_integration_action_arr( $arr, 'wp_http_post', 'wp_adv', 'http_post' );
		}

		/** Adding Autonami new integration actions */
		if ( isset( $arr['autonami'] ) ) {
			$arr = $this->copy_integration_action_arr( $arr, 'crm_create_contact', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_update_customfields', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_add_tag', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_add_contact_note', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_rmv_tag', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_add_to_list', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_rmv_from_list', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'crm_change_contact_status', 'autonami', 'autonami_1' );
			$arr = $this->copy_integration_action_arr( $arr, 'automation_end', 'autonami', 'autonami_2' );
		}

		return $arr;
	}

	/**
	 * Copy Integration Action Arr
	 *
	 * @since 1.0.0
	 */
	public function copy_integration_action_arr( $arr, $key, $old_base, $new_base ) {
		if ( ! isset( $arr[ $new_base ] ) ) {
			$arr[ $new_base ] = array();
		}
		if ( isset( $arr[ $old_base ] ) && isset( $arr[ $old_base ][ $key ] ) ) {
			$arr[ $new_base ][ $key ]                     = $arr[ $old_base ][ $key ];
			$arr[ $new_base ][ $key ]['real_integration'] = $old_base;
		}

		return $arr;
	}

	/**
	 * Bwfan Add Contact Profile Link
	 *
	 * @since 1.0.0
	 */
	function bwfan_add_contact_profile_link( $user ) {
		if ( ! current_user_can( self::menu_cap() ) ) {
			return;
		}
		if ( $user && $user instanceof WP_User ) {
			$editingUserVars = null;
			$urlBase         = admin_url( 'admin.php?page=autonami' );
			$crmProfile      = bwf_get_contact( $user->ID, $user->user_email );
			if ( $crmProfile && $crmProfile instanceof WooFunnels_Contact && $crmProfile->get_id() > 0 ) {
				$crmProfileUrl   = $urlBase . '&path=/contact/' . $crmProfile->id;
				$editingUserVars = array(
					'user_id'            => $user->ID,
					'bwfcrm_profile_id'  => $crmProfile->get_id(),
					'bwfcrm_profile_url' => $crmProfileUrl,
				);
			}
			$bwfan_bar_vars['rest']            = '';
			$bwfan_bar_vars['links']           = '';
			$bwfan_bar_vars['subscriber_base'] = $urlBase . '&path=/contacts';
			$bwfan_bar_vars['edit_user_vars']  = $editingUserVars;

			?>
            <script type="text/javascript">
                jQuery(document).ready(function () {
                    window.bwfan_bar_vars = '<?php echo wp_json_encode( $bwfan_bar_vars ); ?>';
                    if (window.bwfan_bar_vars.hasOwnProperty('edit_user_vars') !== null) {
                        var edit_user_vars = JSON.parse(window.bwfan_bar_vars).edit_user_vars;
                        if (edit_user_vars !== null && edit_user_vars.hasOwnProperty('bwfcrm_profile_url')) {
                            window.jQuery('<a style="background: #1DAAFC;color: white;border: none;" class="page-title-action" href="' + edit_user_vars.bwfcrm_profile_url + '">View Contact Profile</a>').insertBefore("#profile-page > .wp-header-end")
                        }
                    }
                });
            </script>
			<?php
		}
	}

	/**
	 * Adds 'Profit' column header to 'Orders' page immediately after 'Total' column.
	 *
	 * @param $columns
	 *
	 * @return array|mixed
	 */
	function bwfan_add_order_contact_column_header( $columns ) {
		if ( defined( 'BWFAN_Orders_Disable_CRM_Column' ) && 1 === BWFAN_Orders_Disable_CRM_Column ) {
			return $columns;
		}

		$new_columns = array();
		foreach ( $columns as $column_name => $column_info ) {
			$new_columns[ $column_name ] = $column_info;
			if ( 'order_status' === $column_name ) {
				$new_columns['bwfan_order_contact'] = __( 'Total Spent', 'wp-marketing-automations' );
			}
		}

		return $new_columns;
	}

	/**
	 * Adds 'Contact' column content to 'Orders' page immediately after 'Status' column.
	 *
	 * @param string[] $column name of column being displayed
	 * @param int $order_id current order to traverse
	 */
	public function bwfan_add_order_contact_column_content( $column, $order_id ) {
		if ( 'bwfan_order_contact' !== $column ) {
			return;
		}

		if ( defined( 'BWFAN_Orders_Disable_CRM_Column' ) && 1 === BWFAN_Orders_Disable_CRM_Column ) {
			return;
		}

		if ( $this->bwfan_is_order_contact_column_hidden() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$cid = $order->get_meta( '_woofunnel_cid' );
		if ( empty( $cid ) ) {
			echo '-';

			return;
		}

		$default_currency_symbol = get_option( 'woocommerce_currency' );

		$WooFunnels_Cache_obj = WooFunnels_Cache::get_instance();
		$is_cache             = $WooFunnels_Cache_obj->get_cache( 'bwf_cid_' . $cid . '_wc_columns', 'bwfan_order_contact_data' );
		$result               = [];

		if ( false !== $is_cache ) {
			$result = $is_cache;
		} else {
			global $wpdb;
			$result = $wpdb->get_row( $wpdb->prepare( "SELECT `total_order_value`, `total_order_count` FROM `{$wpdb->prefix}bwf_wc_customers` WHERE `cid` = %d LIMIT 0, 1", $cid ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$WooFunnels_Cache_obj->set_cache( 'bwf_cid_' . $cid . '_wc_columns', $result, 'bwfan_order_contact_data' );
		}

		if ( empty( $result ) || empty( $result['total_order_count'] ) ) {
			echo '-';

			return;
		}
		?>
        <div style="display: flex; align-items: center">
			<span>
				<?php
				echo wc_price( $result['total_order_value'], array( 'currency' => $default_currency_symbol ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo ' | ';
				// translators: %s: number of orders
				printf( _n( '%s order', '%s orders', intval( $result['total_order_count'] ), 'wp-marketing-automations' ), intval( $result['total_order_count'] ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</span>
        </div>
		<?php
	}

	/**
	 * Check if the order contact column is hidden
	 *
	 * @return bool
	 */
	private function bwfan_is_order_contact_column_hidden(): bool {
		if ( null !== $this->is_order_contact_column_hidden ) {
			return $this->is_order_contact_column_hidden;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			$this->is_order_contact_column_hidden = false;

			return false;
		}
		if ( ! function_exists( 'get_hidden_columns' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		$hidden                                = get_hidden_columns( $screen );
		$this->is_order_contact_column_hidden = in_array( 'bwfan_order_contact', $hidden, true );

		return $this->is_order_contact_column_hidden;
	}

	/**
	 * Attach meta box of FKA Contact on Single order screen
	 *
	 * @param $post_type
	 * @param $post
	 *
	 * @return void
	 */
	public function bwf_add_single_order_meta_box( $post_type, $post ) {
		if ( 'shop_order' === $post_type || ( $post instanceof WC_Order && 'shop_order' === $post->get_type() ) ) {
			$order = $post instanceof WC_Order ? $post : wc_get_order( $post->ID );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$order_contact_id = $order->get_meta( '_woofunnel_cid' );
			if ( $order_contact_id ) {
				$data = array(
					'cid' => $order_contact_id,
				);
				add_meta_box( 'bwfan_contact_info_box', __( 'Contact Profile', 'wp-marketing-automations' ), array(
					$this,
					'bwf_order_meta_box_data'
				), get_current_screen(), 'side', 'high', $data );
			}
		}
	}

	/**
	 * Bwf Order Meta Box Data
	 *
	 * @since 1.0.0
	 */
	public function bwf_order_meta_box_data( $post, $data ) {
		$args = $data['args'];
		if ( ! isset( $args['cid'] ) || empty( $args['cid'] ) ) {
			esc_html_e( 'No Contact Mapped', 'wp-marketing-automations' );

			return;
		}

		$contact_id = absint( $args['cid'] );
		$contact    = new WooFunnels_Contact( '', '', '', $contact_id );
		if ( empty( $contact->get_id() ) ) {
			esc_html_e( 'No Contact Mapped', 'wp-marketing-automations' );

			return;
		}

		$user_mail    = $contact->email;
		$user_fname   = $contact->get_f_name();
		$user_lname   = $contact->get_l_name();
		$contact_name = ucfirst( $user_fname ) . ' ' . ucfirst( $user_lname );
		$admin_url    = admin_url( 'admin.php?page=autonami&path=/contact/' . $contact_id );
		$avatar_url   = 'https://www.gravatar.com/avatar/0?s=80&d=blank';
		if ( $user_mail ) {
			$avatar_url = 'https://www.gravatar.com/avatar/' . md5( $user_mail ) . '?s=80&&d=blank';
		}

		$status      = $this->get_display_status( $contact );
		$joined_date = $contact->get_creation_date();
		$format      = BWFAN_Common::bwfan_get_date_format();
		$joined_date = gmdate( $format, strtotime( $joined_date ) );
		$resync_text = [
			'text'    => __( 'Resync Orders', 'wp-marketing-automations' ),
			'loading' => __( 'Syncing ...', 'wp-marketing-automations' ),
			'error'   => __( 'Request failed. Please try again.', 'wp-marketing-automations' ),
		];
		?>
        <style type="text/css">
            .bwf-order-edit-wrap {
                display: block;
                text-align: center;
            }

            .bwf-order-edit-wrap a {
                text-decoration: none;
            }

            .bwf-contact-profile {
                padding: 0;
                margin: 10px auto;
                position: relative;
                width: 80px;
            }

            img.bwf-gravatar {
                position: absolute;
                left: 0;
                z-index: 9;
                border-radius: 7px;
            }

            .bwf-c-name-initials {
                border-radius: 8px;
                height: 80px;
                width: 80px;
                display: flex;
                justify-content: center;
                align-items: center;
                text-transform: uppercase;
                color: #fff;
                background: #1daafc;
                font-size: 14px;
                font-weight: 700;
                letter-spacing: 0.06em;
                flex-shrink: 0;
                position: relative;
                text-decoration: none;
                border: none;
            }

            .bwf-contact-wc-detail {
                display: inline-flex;
                flex-direction: column;
            }

            .bwf-contact-wc-detail a {
                text-decoration: none;
            }

            #bwfan-admin-resync-order {
                color: #0073AA;
                font-size: 13px;
                font-style: normal;
                font-weight: 500;
                line-height: 20px;
                margin: 8px auto;
                cursor: pointer;
            }

            #bwfan-admin-resync-order.loading {
                color: #82838e;
                cursor: default;
            }

            #bwfan-editorder-message-section {
                display: none;
                font-size: 13px;
                line-height: 20px;
            }

            #bwfan-editorder-message-section.bwf-error {
                color: #E15334;
            }

            .bwf-contact-wc-status {
                display: inline-block;
                width: auto;
                color: #18b2ff;
                background: #f6fcff;
                border: 1px solid #c3c4c7;
                font-size: 0.6875rem;
                line-height: 1.25rem;
                border-radius: 15px;
                padding: 3px 12px;
                margin: 8px 8px 0 0;
                user-select: none;
                -webkit-user-select: none;
                -moz-user-select: none;
            }

            .bwf-pro-data {
                margin: 10px 0;
            }

            .bwf-pro-data > div > span:nth-child(1) {
                font-weight: 500;
                min-width: 80px;
                display: inline-block;
            }

            .bwf-pro-data > div {
                margin-bottom: 8px;
            }

            .bwf-pro-data .bwf-order-data-gap {
                display: block;
                clear: both;
                height: 1px;
                border-bottom: 1px solid #eee;
                margin-bottom: 10px;
            }

            .bwf-pro-tags-data {
                list-style: none;
                display: flex;
            }

            .bwf-pro-tags-data li {
                list-style: none;
                width: fit-content;
                padding: 0 10px;
                border-radius: 10px;
                display: inline-block;
                color: #8091a7;
                background: #f1f3f5;
                border: 1px solid #f1f3f5;
                font-size: 0.6875rem;
                line-height: 1.25rem;
                margin: 0 2px 5px 0;
            }

            .bwf-pro-tags-data span {
                font-weight: 500;
                display: inline-block;
                margin-bottom: 5px;
            }
        </style>
        <div class="bwf-order-edit-wrap">
            <div class="bwf-contact-profile">
                <a href='<?php echo esc_url( $admin_url ); ?>' target="_blank">
                    <img alt="" class="bwf-gravatar" src="<?php echo esc_url( $avatar_url ); ?>" width="80" height="80"/>
                    <div class="bwf-c-name-initials">
                        <span>
                            <?php echo substr( $user_fname, 0, 1 ) . substr( $user_lname, 0, 1 ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
                        </span>
                    </div>
                </a>
            </div>
            <div class="bwf-contact-wc-detail">
                <b>
					<?php echo esc_html( empty( $contact_name ) ? $user_mail : $contact_name ); ?>
                </b>
                <span><?php esc_html_e( 'Joined On', 'wp-marketing-automations' ); ?>&nbsp;<?php echo esc_html( $joined_date ); ?></span>
                <span class="bwf-contact-wc-status">
                    <a href='<?php echo esc_url( $admin_url ); ?>' target="_blank">
                        <?php esc_html_e( 'View Contact', 'wp-marketing-automations' ); ?>
                    </a>
                </span>
                <span id="bwfan-admin-resync-order" title="<?php esc_html_e( 'Automatically sync orders', 'wp-marketing-automations' ); ?>">
					<?php echo esc_html( $resync_text['text'] ) ?>
				</span>
            </div>
            <div id="bwfan-editorder-message-section"></div>
        </div>
		<?php
		$this->bwf_show_contact_list_tags( $contact, $status );//phpcs:ignore WordPress.Security.EscapeOutput

		wp_enqueue_script( 'bwfan-admin-common', $this->admin_url . '/assets/js/bwfan-admin-common.js', array( 'wp-api' ), BWFAN_VERSION_DEV, true );
		wp_localize_script( 'bwfan-admin-common', 'bwfanProObj', array(
			'siteUrl'       => site_url(),
			'apiNamespace'  => BWFAN_API_NAMESPACE,
			'contactId'     => $contact_id,
			'localize_text' => $resync_text,
		) );
	}

	/**
	 * Add contact lists and tags on order metabox
	 *
	 * @param $contact WooFunnels_Contact
	 * @param $status
	 */
	public function bwf_show_contact_list_tags( $contact, $status ) {
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return;
		}

		/** using $default_currency_symbol to pass the default currency in case client using currency switcher as we show price in default currency */
		$default_currency_symbol = get_option( 'woocommerce_currency' );
		$customer                = new WooFunnels_Customer( $contact );
		$total_orders            = $customer->get_total_order_count();
		$total_spend             = $customer->get_total_order_value();
		$aov                     = $customer->get_aov();
		?>
        <div class="bwf-pro-data">
            <div class="bwf-order-data-gap"></div>
            <div>
                <span><?php echo esc_html__( 'Status', 'woocommerce' ) . ': '; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span>
				<?php echo $status; //phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
            <div>
                <span><?php echo esc_html__( 'Order(s)', 'wp-marketing-automations' ) . ': '; ?></span>
				<?php echo $total_orders; //phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
            <div>
                <span><?php echo esc_html__( 'Total Spend', 'wp-marketing-automations' ) . ': '; ?></span>
				<?php echo wc_price( $total_spend, array( 'currency' => $default_currency_symbol ) ); //phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
            <div>
                <span><?php echo esc_html__( 'AOV', 'wp-marketing-automations' ) . ': '; ?></span>
				<?php echo wc_price( $aov, array( 'currency' => $default_currency_symbol ) ); //phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
			<?php
			$tags  = ! empty( $contact->get_tags() ) ? BWFCRM_Tag::get_tags( $contact->get_tags() ) : [];
			$lists = ! empty( $contact->get_lists() ) ? BWFCRM_Lists::get_lists( $contact->get_lists() ) : [];
			if ( ! empty( $tags ) || ! empty( $lists ) ) {
				echo '<div class="bwf-order-data-gap"></div>'; //phpcs:ignore WordPress.Security.EscapeOutput
			}

			/** Display contact tags */
			echo '<div class="bwf-pro-tags-data"><span>' . esc_html__( 'Tags', 'wp-marketing-automations' ) . ': </span><div>';//phpcs:ignore WordPress.Security.EscapeOutput
			if ( ! empty( $tags ) ) {
				foreach ( $tags as $tag ) {
					?>
                    <li><?php echo isset( $tag['name'] ) ? $tag['name'] : '-'; //phpcs:ignore WordPress.Security.EscapeOutput ?></li>
					<?php
				}
			} else {
				echo '-';
			}
			echo '</div></div>';

			/** Display contact lists */
			echo '<div class="bwf-pro-tags-data"><span>' . esc_html__( 'Lists', 'wp-marketing-automations' ) . ': </span><div>';//phpcs:ignore WordPress.Security.EscapeOutput
			if ( ! empty( $lists ) ) {
				foreach ( $lists as $list ) {
					?>
                    <li><?php echo isset( $list['name'] ) ? $list['name'] : '-'; //phpcs:ignore WordPress.Security.EscapeOutput ?></li>
					<?php
				}
			} else {
				echo '-';
			}
			echo '</div></div>';
			?>
        </div>
		<?php
	}

	/**
	 * Add link to autonami contact
	 *
	 * @param $actions
	 * @param $user
	 *
	 * @return mixed
	 */
	public function bwf_user_list_add_contact_link( $actions, $user ) {
		$contact_data = BWFAN_Model_Customers::bwf_get_customer_data_by_id( $user->ID, false );
		if ( ! empty( $contact_data ) ) {
			$admin_url           = admin_url( 'admin.php?page=autonami&path=/contact/' . $contact_data->cid );
			$actions['autonami'] = '<a href="' . $admin_url . '">' . __( 'View Contact', 'wp-marketing-automations' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Add a custom notice under the pro plugin table row
	 *
	 * @param $plugin_file
	 *
	 * @return void
	 * @throws Exception
	 */
	public function maybe_add_notice( $plugin_file ) {
		/* Match the live (possibly renamed) Pro row by its real basename — the canonical literal would never match a renamed folder. */
		if ( defined( 'BWFAN_PRO_PLUGIN_BASENAME' ) && $plugin_file === BWFAN_PRO_PLUGIN_BASENAME && true === BWFAN_Common::is_pro_folder_renamed() ) {
			$notice = wp_get_admin_notice(
				wp_kses_post( __( 'We have detected that plugin directory has been renamed. Please restore the directory to its original name or download the latest files from', 'wp-marketing-automations' ) ) . ' <a href="' . esc_url( BWFAN_Common::get_fk_site_links( 'account' ) ) . '">' . esc_html__( 'your account', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'or', 'wp-marketing-automations' ) ) . ' <a href="' . esc_url( BWFAN_Common::get_fk_site_links( 'support' ) ) . '">' . esc_html__( 'contact support', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'for assistance.', 'wp-marketing-automations' ) ),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline', 'notice-alt' ),
				)
			);
			printf(
				'<div class="requires">%s</div>',
				wp_kses_post( $notice )
			);
			return;
		}

		/* Mirror the rename notice on the Connectors row when its folder slug has been changed. */
		if ( defined( 'WFCO_AUTONAMI_CONNECTORS_PLUGIN_BASENAME' ) && $plugin_file === WFCO_AUTONAMI_CONNECTORS_PLUGIN_BASENAME && true === BWFAN_Common::is_connector_folder_renamed() ) {
			$notice = wp_get_admin_notice(
				wp_kses_post( __( 'We have detected that plugin directory has been renamed. Please restore the directory to its original name or download the latest files from', 'wp-marketing-automations' ) ) . ' <a href="' . esc_url( BWFAN_Common::get_fk_site_links( 'account' ) ) . '">' . esc_html__( 'your account', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'or', 'wp-marketing-automations' ) ) . ' <a href="' . esc_url( BWFAN_Common::get_fk_site_links( 'support' ) ) . '">' . esc_html__( 'contact support', 'wp-marketing-automations' ) . '</a> ' . wp_kses_post( __( 'for assistance.', 'wp-marketing-automations' ) ),
				array(
					'type'               => 'error',
					'additional_classes' => array( 'inline', 'notice-alt' ),
				)
			);
			printf(
				'<div class="requires">%s</div>',
				wp_kses_post( $notice )
			);
			return;
		}

		if ( $plugin_file !== 'wp-marketing-automations-pro/wp-marketing-automations-pro.php' || false === bwfan_is_autonami_pro_active() ) {
			return;
		}

		$license_data = BWFAN_Common::get_lk_data();
		if ( empty( $license_data['e'] ) ) {
			return;
		}

		$c = new DateTime( current_time( 'mysql', true ) );
		$e = new DateTime( $license_data['e'] );
		if ( $e->getTimestamp() > $c->getTimestamp() ) {
			return;
		}
		$notice = wp_get_admin_notice(
			/* translators: 1: Dynamic Data, 2: Dynamic URL */
			sprintf( wp_kses_post( __( '<strong>Your FunnelKit Automation Pro license has expired!</strong> Please renew your license to continue using premium features without interruption. <a href="%1$s">Renew Now</a> or <a href="%2$s">I have My License Key</a>', 'wp-marketing-automations' ) ), 'https://funnelkit.com/my-account/?utm_source=WordPress&utm_campaign=FKA+Lite+Plugin&utm_medium=Plugin+Inline+Notice+Renew+Now', esc_url( admin_url( 'admin.php?page=autonami&path=/settings' ) ) ),
			array(
				'type'               => 'error',
				'additional_classes' => array( 'inline', 'notice-alt' ),
			)
		);
		printf(
			'<div class="requires">%s</div>',
			wp_kses_post( $notice )
		);
	}

	/**
	 * Add a custom link to renew license to the plugin action links
	 *
	 * @param $actions
	 * @param $plugin_file
	 *
	 * @return array
	 * @throws Exception
	 */
	public function plugin_action_link( $actions, $plugin_file ) {
		/* Surface the rename problem on the live (possibly renamed) Pro row, matched by its real basename. */
		if ( defined( 'BWFAN_PRO_PLUGIN_BASENAME' ) && $plugin_file === BWFAN_PRO_PLUGIN_BASENAME && true === BWFAN_Common::is_pro_folder_renamed() ) {
			$new_actions                    = [];
			$new_actions['folder_renamed']  = '<span class="bwfan_folder_renamed" style="color: #d63638;">' . esc_html__( 'License inactive', 'wp-marketing-automations' ) . '</span>';

			return array_merge( $new_actions, $actions );
		}

		/* Same treatment for the Connectors row when its folder slug has been changed. */
		if ( defined( 'WFCO_AUTONAMI_CONNECTORS_PLUGIN_BASENAME' ) && $plugin_file === WFCO_AUTONAMI_CONNECTORS_PLUGIN_BASENAME && true === BWFAN_Common::is_connector_folder_renamed() ) {
			$new_actions                   = [];
			$new_actions['folder_renamed'] = '<span class="bwfan_folder_renamed" style="color: #d63638;">' . esc_html__( 'License inactive', 'wp-marketing-automations' ) . '</span>';

			return array_merge( $new_actions, $actions );
		}

		if ( $plugin_file !== 'wp-marketing-automations-pro/wp-marketing-automations-pro.php' || false === bwfan_is_autonami_pro_active() ) {
			return $actions;
		}

		$license_data = BWFAN_Common::get_lk_data();
		if ( empty( $license_data['e'] ) ) {
			return $actions;
		}

		$c = new DateTime( current_time( 'mysql', true ) );
		$e = new DateTime( $license_data['e'] );
		if ( $e->getTimestamp() > $c->getTimestamp() ) {
			return $actions;
		}

		$link        = esc_url( 'https://funnelkit.com/my-account/?utm_source=WordPress&utm_campaign=Automation+Lite&utm_medium=Plugin+Inline+Notice' );
		$new_actions = [];

		$new_actions['renewal_license'] = '<style>tr[data-slug="funnelkit-automations-pro"] .renewal_license{position: relative}tr[data-slug="funnelkit-automations-pro"] .renewal_license svg{position:absolute;top:1px;left:0}</style><svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
<g clip-path="url(#clip0_835_18634)">
<path d="M10.2957 1.75368C10.1928 1.76698 10.0983 1.81626 10.0298 1.89236C9.9613 1.96846 9.92347 2.06621 9.92333 2.16745C9.92336 2.18598 9.92462 2.2045 9.92711 2.22287L10.0257 2.94891C9.06453 2.28807 7.90358 1.96102 6.729 2.02021C5.55442 2.0794 4.43425 2.52139 3.54808 3.27532C2.66191 4.02926 2.06109 5.05145 1.84194 6.17802C1.6228 7.30459 1.79802 8.47027 2.33952 9.48816C2.88102 10.5061 3.75743 11.3172 4.82823 11.7915C5.89903 12.2659 7.10219 12.3759 8.2448 12.104C9.38741 11.8322 10.4033 11.1941 11.1295 10.2922C11.8558 9.39026 12.2504 8.2767 12.25 7.13005C12.25 7.0192 12.2048 6.9129 12.1244 6.83452C12.044 6.75614 11.935 6.7121 11.8213 6.7121C11.7076 6.7121 11.5986 6.75614 11.5182 6.83452C11.4378 6.9129 11.3926 7.0192 11.3926 7.13005C11.3936 8.09095 11.0634 9.02432 10.4552 9.78043C9.847 10.5366 8.9959 11.0716 8.03846 11.2997C7.08103 11.5279 6.07273 11.4359 5.17532 11.0386C4.27792 10.6412 3.5434 9.96156 3.0896 9.10856C2.6358 8.25557 2.48902 7.2787 2.6728 6.33466C2.85658 5.39061 3.36028 4.5341 4.10308 3.90251C4.84589 3.27093 5.78476 2.90087 6.76909 2.85171C7.75342 2.80255 8.72617 3.07712 9.53129 3.63139L8.58699 3.6711C8.47664 3.67573 8.37239 3.7217 8.29596 3.79943C8.21953 3.87715 8.17681 3.98064 8.17672 4.08832C8.17672 4.09444 8.17692 4.10046 8.17713 4.10658C8.18202 4.21732 8.23183 4.32162 8.3156 4.39656C8.39938 4.47149 8.51025 4.51092 8.62384 4.50616L10.6202 4.4223C10.6265 4.42202 10.6322 4.42021 10.6384 4.41973C10.6427 4.41935 10.647 4.41973 10.6515 4.41922C10.6536 4.41897 10.6557 4.41933 10.6581 4.41904H10.6583C10.6669 4.41791 10.6754 4.41498 10.684 4.41333C10.6969 4.41089 10.7094 4.40825 10.7218 4.40472C10.7283 4.40286 10.7351 4.402 10.7416 4.39983C10.7497 4.39711 10.7568 4.39281 10.7646 4.38965C10.7761 4.38499 10.7874 4.38027 10.7984 4.37469C10.8048 4.37139 10.8118 4.36918 10.8181 4.36552C10.8261 4.36098 10.8328 4.35507 10.8404 4.34995C10.8495 4.34387 10.8585 4.33775 10.8671 4.33102C10.8698 4.32893 10.8728 4.32725 10.8754 4.3251C10.8791 4.32209 10.8833 4.32011 10.8869 4.31695C10.8942 4.31068 10.8995 4.30314 10.9062 4.29649C10.913 4.28985 10.9188 4.2837 10.9248 4.27696C10.9307 4.27021 10.9373 4.26461 10.9427 4.25769C10.9494 4.24916 10.9543 4.23988 10.9603 4.23096C10.9643 4.22486 10.968 4.21865 10.9718 4.21234C10.9765 4.20436 10.9822 4.197 10.9864 4.18871C10.9911 4.17924 10.9942 4.16929 10.9982 4.15957C11.0012 4.15253 11.0037 4.14543 11.0062 4.1382C11.0092 4.12952 11.0132 4.12129 11.0156 4.11239C11.0182 4.10309 11.0191 4.09358 11.021 4.08416C11.0229 4.07473 11.0244 4.06535 11.0256 4.0559C11.0267 4.04784 11.0288 4.0401 11.0293 4.03191C11.0299 4.0233 11.0289 4.01474 11.0289 4.00608C11.0289 3.99961 11.0304 3.99342 11.0302 3.98686C11.0299 3.9803 11.028 3.97482 11.0275 3.96864C11.0272 3.9655 11.0275 3.96237 11.0271 3.95925C11.0267 3.95614 11.0272 3.95298 11.0268 3.94991V3.94916V3.94849L10.7773 2.11314C10.7699 2.05869 10.7516 2.00619 10.7234 1.95864C10.6952 1.9111 10.6577 1.86944 10.613 1.83605C10.5682 1.80266 10.5172 1.7782 10.4628 1.76407C10.4083 1.74994 10.3516 1.74641 10.2957 1.75368Z" fill="#C5443F"/>
</g>
<defs>
<clipPath id="clip0_835_18634">
<rect width="14" height="14" fill="white"/>
</clipPath>
</defs>
</svg>
<a href="' . $link . '" class="bwfan_renew_license" style="color: #d63638;padding-left: 20px;">' . __( 'Renew Expired License', 'wp-marketing-automations' ) . '</a>';

		return array_merge( $new_actions, $actions );
	}

	/**
	 * Maybe redirect to wizard if new user
	 *
	 * @return void
	 */
	public function maybe_redirect_to_wizard() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) || 'autonami' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
			return;
		}

		$new_user = get_option( 'bwfan_new_user', '' );
		if ( $new_user !== 'yes' || get_option( '_wffn_onboarding_completed' ) ) {
			return;
		}

		delete_option( 'bwfan_new_user' );
		wp_safe_redirect( $this->wizard_url );
		exit;
	}

	/**
	 * Check if contact is in unsubscribe table
	 *
	 * @param $contact
	 *
	 * @return array|object|string|null
	 */
	public static function is_contact_unsubscribe( $contact ) {
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return [];
		}
		$data = array(
			'recipient' => array( $contact->get_email(), $contact->get_contact_no() ),
		);

		return BWFAN_Model_Message_Unsubscribe::get_message_unsubscribe_row( $data );
	}

	/**
	 * Get display status
	 *
	 * @param $contact
	 *
	 * @return string|WP_Error|null
	 */
	public function get_display_status( $contact ) {
		if ( ! $contact instanceof WooFunnels_Contact ) {
			return '';
		}
		if ( 0 === $contact->get_id() ) {
			return BWFAN_Common::crm_error( __( 'Contact not valid', 'wp-marketing-automations' ) );
		}

		$status          = intval( $contact->get_status() );
		$is_unsubscribed = self::is_contact_unsubscribe( $contact );
		$is_unsubscribed = is_array( $is_unsubscribed ) && count( $is_unsubscribed ) > 0;

		if ( $is_unsubscribed ) {
			return __( 'Unsubscribed', 'wp-marketing-automations' );
		}

		if ( BWFCRM_Contact::$STATUS_BOUNCED === $status ) {
			return __( 'Bounced', 'wp-marketing-automations' );
		}

		if ( BWFCRM_Contact::$STATUS_SOFT_BOUNCED === $status ) {
			return __( 'Soft Bounced', 'wp-marketing-automations' );
		}

		if ( BWFCRM_Contact::$STATUS_COMPLAINT === $status ) {
			return __( 'Complaint', 'wp-marketing-automations' );
		}

		if ( BWFCRM_Contact::$STATUS_NOT_OPTED_IN === $status ) {
			return __( 'Unverified', 'wp-marketing-automations' );
		}

		return __( 'Subscribed', 'wp-marketing-automations' );
	}
}

if ( class_exists( 'BWFAN_Core' ) ) {
	BWFAN_Core::register( 'admin', 'BWFAN_Admin' );
}
