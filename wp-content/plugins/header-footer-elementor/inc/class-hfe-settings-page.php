<?php
/**
 * HFE Settings Page.
 *
 * Add plugin setting page.
 *
 * @since 1.6.0
 * @package hfe
 */

namespace HFE\Themes;

use HFE\WidgetsManager\Base\HFE_Helper;

/**
 * Class Settings Page.
 *
 * @since 1.6.0
 */
class HFE_Settings_Page {
	
	/**
	 * Instance
	 * z
	 *
	 * @access private
	 * @var string Class object.
	 * @since 1.0.0
	 */
	private $menu_slug = 'hfe';

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 */
	public function __construct() {

		add_action( 'admin_post_uaelite_rollback', [ $this, 'post_uaelite_rollback' ] );
		
		add_action( 'admin_head', [ $this, 'hfe_global_css' ] );

		add_action( 'admin_head', [ $this, 'fetch_user_email' ] );
		add_action( 'admin_head', [ $this, 'fetch_site_url' ] );
		add_action( 'admin_head', [ $this, 'fetch_user_fname' ] );

		if ( ! HFE_Helper::is_pro_active() ) {
			if ( is_admin() && current_user_can( 'manage_options' ) ) {
				add_action( 'admin_menu', [ $this, 'hfe_register_settings_page' ] );
				if( ! defined( 'UAEL_PRO' ) ){
					add_action( 'admin_menu', [ $this, 'hfe_add_upgrade_to_pro' ] );
					add_action( 'admin_footer', [ $this, 'hfe_add_upgrade_to_pro_target_attr' ] );
				}
			}
			add_action( 'admin_footer_text', [ $this, 'uae_custom_admin_footer_text' ] );
			add_action( 'admin_init', [ $this, 'hfe_admin_init' ] );
			add_filter( 'views_edit-elementor-hf', [ $this, 'hfe_settings' ], 10, 1 );
		}
		
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_filter( 'plugin_action_links_' . HFE_PATH, [ $this, 'settings_link' ] );
		add_filter( 'plugin_action_links_' . HFE_PATH, [ $this, 'upgrade_pro_link' ] );
		add_filter( 'plugin_row_meta', [ $this, 'add_rating_meta_links' ], 10, 2 );		

		/* Flow content view */
		add_action( 'hfe_render_admin_page_content', [ $this, 'render_content' ], 10, 2 );


		if ( ! HFE_Helper::is_pro_active() ) {
			add_action( 'admin_footer', __CLASS__ . '::show_nps_notice' );
		}

	}

	/**
	 * Show action on plugin page.
	 *
	 * @param  array $links links.
	 * @return array
	 * @since 2.4.5
	 */
	public function upgrade_pro_link( $links ) {
		$plugin_file = 'ultimate-elementor/ultimate-elementor.php';
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) && ! HFE_Helper::is_pro_active() ) {
			$links[]     = '<a href="' . esc_url( 'https://ultimateelementor.com/pricing/?utm_source=wp-admin&utm_medium=plugin-list&utm_campaign=uae-upgrade' ) . '" target="_blank" rel="noreferrer" class="uae-plugins-go-pro">' . esc_html__( 'Go Pro', 'header-footer-elementor' ) . '</a>';
		}

		return $links;
	}

	/**
	 * Add meta links to the plugin row (under description).
	 *
	 * @param array<int,string> $links Array of plugin meta links.
	 * @param string            $file Plugin file path.
	 * @return array<int,string> Modified plugin meta links.
	 * @since 2.5.2
	 */
	public function add_rating_meta_links( array $links, string $file ): array {
		if ( HFE_PATH === $file ) {
			$stars = str_repeat( '<span class="dashicons dashicons-star-filled hfe-rating-star" aria-hidden="true"></span>', 5 );
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s" role="button">%s</a>',
				esc_url( 'https://wordpress.org/support/plugin/header-footer-elementor/reviews/#new-post' ),
				esc_attr__( 'Rate our plugin', 'header-footer-elementor' ),
				$stars
			);
		}
		return $links;
	}

	/**
	 * Adding Rating footer to dashboard pages.
	 *
	 * @since 2.4.5
	 * @return void
	 */
	public function uae_custom_admin_footer_text( $footer_text ) {
		$screen = get_current_screen();
	
		if (
			( isset( $_GET['page'] ) && 'hfe' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			( isset( $screen->post_type ) && $screen->post_type === 'elementor-hf' )
		) {
			$footer_text = sprintf(
				/* translators: %1$s is bold plugin name, %2$s is the review link */
				__( 'Enjoyed %1$s? Please leave us a %2$s rating. We really appreciate your support!', 'header-footer-elementor' ),
				'<b>UAE</b>',
				'<a class="uae-rating" href="https://wordpress.org/support/plugin/header-footer-elementor/reviews/#new-post" target="_blank">★★★★★</a>'
			);
		}
		return $footer_text;
	}

	/**
	 * Render UAE NPS Survey Notice.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function show_nps_notice() {
		if ( class_exists( 'Nps_Survey' ) ) {
			$uae_logo = HFE_URL . 'assets/images/settings/logo.svg';
			\Nps_Survey::show_nps_notice(
				'nps-survey-header-footer-elementor',
				[
					'show_if'          => true, // Add your display conditions.
					'dismiss_timespan' => 2 * WEEK_IN_SECONDS,
					'display_after'    => 2 * WEEK_IN_SECONDS,
					'plugin_slug'      => 'header-footer-elementor',
					'show_on_screens'  => [ 'toplevel_page_hfe' ],
					'message'          => [
						// Step 1 i.e rating input.
						'logo'                  => esc_url( $uae_logo ),
						'plugin_name'           => __( 'Ultimate Addons for Elementor', 'header-footer-elementor' ),
						'nps_rating_message'    => __( 'How likely are you to recommend Ultimate Addons for Elementor to your friends or colleagues?', 'header-footer-elementor' ),
						// Step 2A i.e. positive.
						'feedback_title' => __( 'Thanks a lot for your feedback! 😍', 'header-footer-elementor' ),
						'feedback_content' => __( 'Thanks for using Ultimate Addons! Got feedback or suggestions to make it even better? We’d love to hear from you.', 'header-footer-elementor' ),
						'plugin_rating_link'    => esc_url( 'https://www.trustpilot.com/review/ultimateelementor.com' ),
						// Step 2B i.e. negative.
						'plugin_rating_title'   => __( 'Thank you for your feedback', 'header-footer-elementor' ),
						'plugin_rating_content' => __( 'We value your input. How can we improve your experience?', 'header-footer-elementor' ),				
					],
				]
			);
		}
	}

	/**
	 * Get Elementor edit page link
	 */
	public static function get_elementor_new_page_url() {

		if ( class_exists( '\Elementor\Plugin' ) && current_user_can( 'edit_pages' ) ) {
			// Ensure Elementor is loaded.
			$query_args = [
				'action'    => 'elementor_new_post',
				'post_type' => 'page',
			];
		
			$new_post_url = add_query_arg( $query_args, admin_url( 'edit.php' ) );
		
			$new_post_url = add_query_arg( '_wpnonce', wp_create_nonce( 'elementor_action_new_post' ), $new_post_url );
		
			return $new_post_url;
		}
		return '';
	}

	

	/**
	 * UAELite version rollback.
	 *
	 * Rollback to previous version.
	 *
	 * Fired by `admin_post_uaelite_rollback` action.
	 *
	 * @since 2.2.1
	 * @access public
	 */
	public function post_uaelite_rollback() {

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'header-footer-elementor' ),
				esc_html__( 'Rollback to Previous Version', 'header-footer-elementor' ),
				[
					'response' => 200,
				]
			);
		}

		check_admin_referer( 'uaelite_rollback' );

		$rollback_versions = HFE_Helper::get_rollback_versions_options();
		$update_version    = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';

		// Extract version values from the rollback_versions array.
		$version_values = array_column( $rollback_versions, 'value' );

		if ( empty( $update_version ) || ! in_array( $update_version, $version_values, true ) ) {
			wp_die( esc_html__( 'The selected version is invalid. Please try a different version.', 'header-footer-elementor' ) );
		}

		$plugin_slug = basename( HFE_FILE, '.php' );
		
		if ( class_exists( 'HFE_Rollback' ) ) {
			$rollback = new \HFE_Rollback(
				[
					'version'     => $update_version,
					'plugin_name' => HFE_PATH,
					'plugin_slug' => $plugin_slug,
					'package_url' => sprintf( 'https://downloads.wordpress.org/plugin/%s.%s.zip', $plugin_slug, $update_version ),
				]
			);

			$rollback->run();

			wp_die(
				'',
				esc_html__( 'Rollback to Previous Version', 'header-footer-elementor' ),
				[
					'response' => 200,
				]
			);
		}
		wp_die();
	}

	/**
	 * Adds CSS to Hide the extra submenu added for the settings tab.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function hfe_global_css() {
		wp_enqueue_style( 'hfe-admin-style', HFE_URL . 'admin/assets/css/ehf-admin.css', [], HFE_VER );
	}

	/**
	 * Fetch and return the user's email.
	 *
	 * @since 1.6.0
	 * @return string|null The user's email if logged in, null otherwise.
	 */
	public function fetch_user_email() {
		$current_user = wp_get_current_user();
		if ( $current_user->ID !== 0 ) {
			return $current_user->user_email;
		} else {
			return null;
		}
	}

	/**
	 * Fetch and return the user's first name.
	 *
	 * @since 2.4.5
	 * @return string|null The user's name if logged in, null otherwise.
	 */
	public function fetch_user_fname() {
		$current_user = wp_get_current_user();
		if ( 0 !== $current_user->ID ) {
			return $current_user->user_firstname;
		} else {
			return null;
		}
	}

	/**
	 * Fetch and return the current user's last name.
	 *
	 * @since 2.8.7
	 * @return string|null The user's last name if logged in, null otherwise.
	 */
	public function fetch_user_lname() {
		$current_user = wp_get_current_user();
		if ( 0 !== $current_user->ID ) {
			return $current_user->user_lastname;
		} else {
			return null;
		}
	}

	/**
	 * Fetch and return the site URL.
	 *
	 * @since 2.4.5
	 * @return string|null
	 */
	public function fetch_site_url() {
		$siteurl = get_option('siteURL');
		if ( !empty( $siteurl ) ) {
			return $siteurl;
		} else {
			return null;
		}
	}

	/**
	 * Load admin styles on header footer elementor edit screen.
	 *
	 * @return void
	 */
	public function enqueue_admin_scripts() {

		global $pagenow, $post_type;
	
		$uae_logo      = HFE_URL . 'assets/images/settings/dashboard-logo.svg';
		$white_logo    = HFE_URL . 'assets/images/settings/white-logo.svg';
		$show_view_all = ( $post_type === 'elementor-hf' && $pagenow === 'post.php' ) ? 'yes' : 'no';
		$hfe_edit_url  = admin_url( 'edit.php?post_type=elementor-hf' );
		$is_hfe_post   = ( 'elementor-hf' === $post_type && ( 'post.php' === $pagenow || 'post-new.php' === $pagenow ) ) ? 'yes' : 'no';
	
		$additional_condition = ( isset( $_GET['post_type'] ) && 'elementor-hf' === sanitize_text_field( $_GET['post_type'] ) && 
			( 'edit.php' === $GLOBALS['pagenow'] || 'post.php' === $GLOBALS['pagenow'] || 'post-new.php' === $GLOBALS['pagenow'] ) ) ||
			( isset( $_GET['post'] ) && 'post.php' === $GLOBALS['pagenow'] && isset( $_GET['action'] ) && 'edit' === sanitize_text_field( $_GET['action'] ) && 'elementor-hf' === get_post_type( absint( $_GET['post'] ) ) ) ||
			( 'post-new.php' === $GLOBALS['pagenow'] && isset( $_GET['post_type'] ) && 'elementor-hf' === sanitize_text_field( $_GET['post_type'] ) );
	
		if ( ( 
				self::is_current_page( 'hfe' ) || 
				$additional_condition 
			) && 
			! HFE_Helper::is_pro_active() 
		) {
	
			$rollback_versions = HFE_Helper::get_rollback_versions_options();
			$st_status         = HFE_Helper::free_starter_templates_status();
			$stpro_status      = HFE_Helper::premium_starter_templates_status();
			$st_link           = HFE_Helper::starter_templates_link();
			$hfe_post_url      = admin_url( 'post-new.php?post_type=elementor-hf' );
			// Fetch the user's email.
			$user_email = $this->fetch_user_email();
			$user_name     = $this->fetch_user_fname();
			$user_lastname = $this->fetch_user_lname();
			$siteurl       = $this->fetch_site_url();
			$show_theme_support = 'no';
			$hfe_theme_status   = get_option( 'hfe_is_theme_supported', false );
			$analytics_status   = get_option( 'uae_usage_optin', false );
	
			if ( ( ! current_theme_supports( 'header-footer-elementor' ) ) && ! $hfe_theme_status ) {
				$show_theme_support = 'yes';
			}
			$theme_option = get_option( 'hfe_compatibility_option', '1' );
	
			// Load the build-time generated dependency list so React and ReactDOM are always
			// resolved to the versions WordPress supplies (never bundled). This keeps the
			// admin app compatible with future WordPress React upgrades (e.g. React 19).
			$asset_file = HFE_DIR . 'build/main.asset.php';
			$main_asset = file_exists( $asset_file )
				? require $asset_file
				: [
					'dependencies' => [ 'react', 'react-dom', 'wp-element', 'wp-dom-ready', 'wp-api-fetch', 'wp-i18n' ],
					'version'      => HFE_VER,
				];

			wp_enqueue_script(
				'header-footer-elementor-react-app',
				HFE_URL . 'build/main.js',
				$main_asset['dependencies'],
				$main_asset['version'],
				true
			);

			wp_set_script_translations( 'header-footer-elementor-react-app', 'header-footer-elementor', HFE_DIR . 'languages' );

	
			wp_localize_script(
				'header-footer-elementor-react-app',
				'hfeSettingsData',
				[
					'hfe_nonce_action'         => wp_create_nonce( 'wp_rest' ),
					'installer_nonce'          => wp_create_nonce( 'updates' ),
					'ajax_url'                 => admin_url( 'admin-ajax.php' ),
					'ajax_nonce'               => wp_create_nonce( 'hfe-widget-nonce' ),
					'templates_url'            => HFE_URL . 'assets/images/settings/starter-templates.png',
					'column_url'               => HFE_URL . 'assets/images/settings/column.png',
					'template_url'             => HFE_URL . 'assets/images/settings/template.png',
					'icon_url'                 => HFE_URL . 'assets/images/settings/logo.svg',
					'elementor_page_url'       => self::get_elementor_new_page_url(),
					'astra_url'                => HFE_URL . 'assets/images/settings/astra.svg',
					'starter_url'              => HFE_URL . 'assets/images/settings/starter-templates.svg',
					'surecart_url'             => HFE_URL . 'assets/images/settings/surecart.svg',
					'suretriggers_url'         => HFE_URL . 'assets/images/settings/OttoKit-Symbol-Primary.svg',
					'theme_url_selected'       => HFE_URL . 'assets/images/settings/theme.svg',
					'theme_url'                => HFE_URL . 'assets/images/settings/layout-template.svg',
					'version_url'              => HFE_URL . 'assets/images/settings/version.svg',
					'version__selected_url'    => HFE_URL . 'assets/images/settings/git-compare.svg',
					'tracking_url'             => HFE_URL . 'assets/images/settings/tracking.svg',
					'tracking__selected_url'   => HFE_URL . 'assets/images/settings/tracking_selected.svg',
					'branding_url'             => HFE_URL . 'assets/images/settings/branding.svg',
					'branding__selected_url'   => HFE_URL . 'assets/images/settings/branding-selected.svg',
					'user_url'                 => HFE_URL . 'assets/images/settings/user.svg',
					'user__selected_url'       => HFE_URL . 'assets/images/settings/user-selected.svg',
					'integrations_url'         => HFE_URL . 'assets/images/settings/integrations.svg', // Update the path to your assets folder.
					'ai_tools_url'             => HFE_URL . 'assets/images/settings/ai-tools.svg',
					'ai_tools__selected_url'   => HFE_URL . 'assets/images/settings/ai-tools-selected.svg',
					'welcome_banner'           => HFE_URL . 'assets/images/settings/welcome-banner.png',
					'build_banner'             => HFE_URL . 'assets/images/settings/build_banner.png',
					'special_reward'           => HFE_URL . 'assets/images/settings/build_bg.png',
					'success_banner'           => HFE_URL . 'assets/images/settings/success_bg.png',
					'success_badge'            => HFE_URL . 'assets/images/settings/success_badge.svg',
					'icon_svg'                 => HFE_URL . 'assets/images/settings/uae-logo-svg.svg',
					'augemented_url'                 => HFE_URL . 'assets/images/settings/augemented_reality_widgets.png',
					'rocket_svg'                 => HFE_URL . 'assets/images/settings/rocket.svg',
					'augmented_reality'                 => HFE_URL . 'assets/images/settings/augmented_reality.png',
					'welcome_new'                 => HFE_URL . 'assets/images/settings/welcome_new.png',
					'icon_new'                 => HFE_URL . 'assets/images/settings/icon_2.svg',
					'create_new'                 => HFE_URL . 'assets/images/settings/create_new_banner.png',
					'uaelite_previous_version' => isset( $rollback_versions[0]['value'] ) ? $rollback_versions[0]['value'] : '',
					'uaelite_versions'         => $rollback_versions,
					'uaelite_rollback_url'     => esc_url( add_query_arg( 'version', 'VERSION', wp_nonce_url( admin_url( 'admin-post.php?action=uaelite_rollback' ), 'uaelite_rollback' ) ) ),
					'uaelite_current_version'  => defined( 'HFE_VER' ) ? HFE_VER : '',
					'show_theme_support'       => $show_theme_support,
					'theme_option'             => $theme_option,
					'st_status'                => $st_status,
					'hfe_settings_url'         => admin_url( 'admin.php?page=hfe' ),
					'header_footer_builder'    => admin_url( 'edit.php?post_type=elementor-hf' ),
					'st_pro_status'            => $stpro_status,
					'st_link'                  => $st_link,
					'hfe_post_url'             => $hfe_post_url,
					'is_hfe_post'              => $is_hfe_post,
					'user_email'               => $user_email,
					'user_fname'               => $user_name,
					'user_lname'               => $user_lastname,
					'siteurl'                  => $siteurl,
					'analytics_status'         => $analytics_status,
					'onboarding_success_url'   => admin_url( 'admin.php?page=hfe#onboardingsuccess' ),
					'uaelite_subscription'	   => get_option( 'uaelite_subscription', false ),
					'is_uael_active'           => defined( 'UAEL_VER' ),
					'mcp_settings'             => wp_parse_args( get_option( 'uae_mcp_settings', [] ), \HFE_Settings_Api::get_mcp_settings_defaults() ),
					'is_abilities_api_active'  => function_exists( 'wp_register_ability' ),
					'is_mcp_adapter_active'    => class_exists( '\WP\MCP\Core\McpAdapter' ),
					'is_angie_active'          => defined( 'ANGIE_VERSION' ),
					'site_url'                 => site_url(),
					'rest_url'                 => esc_url_raw( rest_url( '/' ) ),
					'application_passwords_url' => esc_url_raw( admin_url( 'profile.php#application-passwords-section' ) ),
					'wp_path'                  => ABSPATH,
					'current_user'             => is_user_logged_in() ? wp_get_current_user()->user_login : '',
				]
			);
	
			wp_enqueue_style(
				'header-footer-elementor-react-styles',
				HFE_URL . 'build/main.css',
				[],
				HFE_VER
			);
		}
	
		if ( '' !== $uae_logo && '' !== $white_logo ) {
	
			$custom_css = '
				#toplevel_page_hfe .wp-menu-image {
					background-image: url(' . esc_url( $uae_logo ) . ') !important;
					background-size: 23px 34px !important;
					background-repeat: no-repeat !important;
					background-position: center !important;
				}
				#toplevel_page_hfe.wp-menu-open .wp-menu-image,
				#toplevel_page_hfe .wp-has-current-submenu .wp-menu-image {
					background-image: url(' . esc_url( $white_logo ) . ') !important;
				}
			';
			wp_add_inline_style( 'wp-admin', $custom_css );
		}
	
		wp_enqueue_script( 'hfe-admin-script', HFE_URL . 'admin/assets/js/ehf-admin.js', [ 'jquery', 'updates' ], HFE_VER, true );
	
		$is_dismissed = get_user_meta( get_current_user_id(), 'hfe-popup' );
		$upgrade_notice_dismissed = get_user_meta( get_current_user_id(), 'hfe_upgrade_notice_dismissed', 'false' ) === 'true';

		$strings = [
			'addon_activate'        => esc_html__( 'Activate', 'header-footer-elementor' ),
			'addon_activated'       => esc_html__( 'Activated', 'header-footer-elementor' ),
			'addon_active'          => esc_html__( 'Active', 'header-footer-elementor' ),
			'addon_deactivate'      => esc_html__( 'Deactivate', 'header-footer-elementor' ),
			'addon_inactive'        => esc_html__( 'Inactive', 'header-footer-elementor' ),
			'addon_install'         => esc_html__( 'Install', 'header-footer-elementor' ),
			'theme_installed'       => esc_html__( 'Theme Installed', 'header-footer-elementor' ),
			'plugin_installed'      => esc_html__( 'Plugin Installed', 'header-footer-elementor' ),
			'addon_download'        => esc_html__( 'Download', 'header-footer-elementor' ),
			'addon_exists'          => esc_html__( 'Already Exists.', 'header-footer-elementor' ),
			'visit_site'            => esc_html__( 'Visit Website', 'header-footer-elementor' ),
			'plugin_error'          => esc_html__( 'Could not install. Please download from WordPress.org and install manually.', 'header-footer-elementor' ),
			'subscribe_success'     => esc_html__( 'Your details are submitted successfully.', 'header-footer-elementor' ),
			'subscribe_error'       => esc_html__( 'Encountered an error while performing your request.', 'header-footer-elementor' ),
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'hfe-admin-nonce' ),
			'installer_nonce'       => wp_create_nonce( 'updates' ),
			'upgrade_notice_dismissed' => $upgrade_notice_dismissed,
			'popup_dismiss'         => false,
			'data_source'           => 'HFE',
			'show_all_hfe'          => $show_view_all,
			'hfe_edit_url'          => $hfe_edit_url,
			'view_all_text'         => esc_html__( 'View All', 'header-footer-elementor' ),
			'header_footer_builder' => $hfe_edit_url,
		];
	
		$strings = apply_filters( 'hfe_admin_strings', $strings );
	
		wp_localize_script(
			'hfe-admin-script',
			'hfe_admin_data',
			$strings
		);
	}


	/**
	 * Adds a tab in plugin submenu page.
	 *
	 * @since 1.6.0
	 * @param string $views to add tab to current post type view.
	 *
	 * @return mixed
	 */
	public function hfe_settings( $views ) {
		return $views;
	}

	/**
	 * CHeck if it is current page by parameters
	 *
	 * @param string $page_slug Menu name.
	 * @param string $action Menu name.
	 *
	 * @return  string page url
	 */
	public static function is_current_page( $page_slug = '', $action = '' ) {

		$page_matched = false;

		if ( empty( $page_slug ) ) {
			return false;
		}

		$current_page_slug = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_action    = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! is_array( $action ) ) {
			$action = explode( ' ', $action );
		}

		if ( $page_slug === $current_page_slug && in_array( $current_action, $action, true ) ) {
			$page_matched = true;
		}

		return $page_matched;
	}

	/**
	 * Function for registering the settings api.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function hfe_admin_init() {
		register_setting( 'hfe-plugin-options', 'hfe_compatibility_option' );
		add_settings_section( 'hfe-options', __( 'Add Theme Support', 'header-footer-elementor' ), [ $this, 'hfe_compatibility_callback' ], 'Settings' );
		add_settings_field( 'hfe-way', 'Methods to Add Theme Support', [ $this, 'hfe_compatibility_option_callback' ], 'Settings', 'hfe-options' );

	}

	/**
	 * Call back function for the ssettings api function add_settings_section
	 *
	 * This function can be used to add description of the settings sections
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function hfe_compatibility_callback() {
		$message      = __( 'The Ultimate Addons for Elementor plugin needs compatibility with your current theme to work smoothly.<br/><br/>Following are two methods that enable theme support for the plugin.<br/><br/>Method 1 is selected by default and works fine with almost all themes. If you face any issue with the header or footer template, try choosing Method 2.', 'header-footer-elementor' );
		$allowed_html = [ 'br' => [] ];
		echo wp_kses( $message, $allowed_html );
	}

	/**
	 * Call back function for the ssettings api function add_settings_field
	 *
	 * This function will contain the markup for the input feilds that we can add.
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function hfe_compatibility_option_callback() {
		$hfe_radio_button = get_option( 'hfe_compatibility_option', '1' );
		wp_enqueue_style( 'hfe-admin-style', HFE_URL . 'admin/assets/css/ehf-admin.css', [], HFE_VER );
		?>

		<label>
			<input type="radio" name="hfe_compatibility_option" value= 1 <?php checked( $hfe_radio_button, 1 ); ?> > <div class="hfe_radio_options"><?php esc_html_e( 'Method 1 (Recommended)', 'header-footer-elementor' ); ?></div>
			<p class="description"><?php esc_html_e( 'This method replaces your theme\'s header (header.php) & footer (footer.php) template with plugin\'s custom templates.', 'header-footer-elementor' ); ?></p><br>
		</label>
		<label>
			<input type="radio" name="hfe_compatibility_option" value= 2 <?php checked( $hfe_radio_button, 2 ); ?> > <div class="hfe_radio_options"><?php esc_html_e( 'Method 2', 'header-footer-elementor' ); ?></div>
			<p class="description">
				<?php
				printf(
					esc_html__( "This method hides your theme's header & footer template with CSS and displays custom templates from the plugin.", 'header-footer-elementor' ),
					'<br>'
				);
				?>
			</p><br>
		</label>
		<p class="description">
			<?php
			/* translators: %s: URL to the plugin support page */
			printf(
				wp_kses(
					__( 'If the above methods do not work with your theme, contact your theme author and request them to add support for the <a href="%s">plugin</a>.', 'header-footer-elementor' ),
					[
						'a' => [
							'href' => [],
						],
					]
				),
				'https://github.com/brainstormforce/header-footer-elementor/wiki/Adding-Header-Footer-Elementor-support-for-your-theme'
			);
			?>
		</p>

		<?php
	}

	/**
	 * Show a settings page incase of unsupported theme.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function hfe_register_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$menu_slug  = $this->menu_slug;
		$capability = 'manage_options';

		add_menu_page(
			__( 'UAE Lite', 'header-footer-elementor' ),
			__( 'UAE', 'header-footer-elementor' ),
			$capability,
			$menu_slug,
			[ $this, 'render' ],
			'none',
			'59'
		);

		// Add the Dashboard Submenu.
		add_submenu_page(
			$menu_slug,
			__( 'UAE Lite', 'header-footer-elementor' ),
			__( 'Dashboard', 'header-footer-elementor' ),
			$capability,
			$menu_slug,
			[ $this, 'render' ],
			1
		);

		// Add the Widgets Submenu.
		add_submenu_page(
			$menu_slug,
			__( 'Widgets', 'header-footer-elementor' ),
			__( 'Widgets', 'header-footer-elementor' ),
			$capability,
			$menu_slug . '#widgets',
			[ $this, 'render' ],
			9
		);

		// Add the Settings Submenu.
		add_submenu_page(
			$menu_slug,
			__( 'Settings', 'header-footer-elementor' ),
			__( 'Settings', 'header-footer-elementor' ),
			$capability,
			$menu_slug . '#settings',
			[ $this, 'render' ],
			9
		);

		// Add the Settings Submenu.
		add_submenu_page(
			$menu_slug,
			__( 'Onboarding', 'header-footer-elementor' ),
			__( 'Onboarding', 'header-footer-elementor' ),
			$capability,
			$menu_slug . '#onboarding',
			[ $this, 'render' ],
			9
		);
		

		// Add the Settings Submenu.
		add_submenu_page(
			$menu_slug,
			__( 'Onboarding', 'header-footer-elementor' ),
			__( 'Onboarding Success', 'header-footer-elementor' ),
			$capability,
			$menu_slug . '#onboardingsuccess',
			[ $this, 'render' ],
			9
		);
	}
	
	/**
	 * Open to Upgrade to Pro submenu link in new tab.
	 *
	 * @return void
	 * @since 2.4.2
	 */
	public function hfe_add_upgrade_to_pro_target_attr() {
		?>
		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				// Upgrade link handler.
				// IMPORTANT: If this URL changes, also update it in the `add_upgrade_to_pro` function.
				const upgradeLink = document.querySelector('a[href*="https://ultimateelementor.com/pricing/?utm_source=wp-admin&utm_medium=menu&utm_campaign=uae-upgrade"]');
				if (upgradeLink) {
					upgradeLink.addEventListener('click', e => {
						e.preventDefault();
						window.open(upgradeLink.href, '_blank');
					});
				}

				// Get Help link handler.
				const getHelpLink = document.querySelector('a[href*="https://ultimateelementor.com/docs"]');
				if (getHelpLink) {
					getHelpLink.addEventListener('click', e => {
						e.preventDefault();
						window.open(getHelpLink.href, '_blank');
					});
				}
			});
		</script>
		<?php
	}

	/**
	 * Add Upgrade to pro menu item.
	 *
	 * @return void
	 * @since 2.4.2
	 */
	public function hfe_add_upgrade_to_pro() {
		
		// Add the Get Help Submenu.
		add_submenu_page(
			$this->menu_slug,
			__( 'Get Help', 'header-footer-elementor' ),
			__( 'Get Help', 'header-footer-elementor' ),
			'manage_options',
			'https://ultimateelementor.com/docs/',
			null,
			11
		);

		// Add the Upgrade to Pro Submenu.
		add_submenu_page(
			$this->menu_slug,
			__( 'Upgrade to Pro', 'header-footer-elementor' ),
			 __( 'Upgrade to Pro', 'header-footer-elementor' ),
			'manage_options',
			'https://ultimateelementor.com/pricing/?utm_source=wp-admin&utm_medium=menu&utm_campaign=uae-upgrade',
		);
	}
	/**
	 * Settings page.
	 *
	 * Call back function for add submenu page function.
	 *
	 * @since 2.2.1
	 * @return void
	 */
	public function render() {

		$menu_page_slug = ( ! empty( $_GET['page'] ) ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : $this->menu_slug; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_action    = '';
   
		if ( isset( $_GET['action'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page_action = sanitize_text_field( wp_unslash( $_GET['action'] ) ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$page_action = str_replace( '_', '-', $page_action );
		}
   
		include_once HFE_DIR . 'inc/settings/admin-base.php';
	}

	/**
	 * Renders the admin settings content.
	 *
	 * @since 1.0.0
	 * @param string $menu_page_slug current page name.
	 * @param string $page_action current page action.
	 *
	 * @return void
	 */
	public function render_content() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_current_page( 'hfe' ) ) {
			include_once HFE_DIR . 'inc/settings/settings-app.php';
		}
	}

	
	/**
	 * Add settings link to the Plugins page.
	 *
	 * @since 1.6.0
	 *
	 * @param array $links Plugin row links.
	 *
	 * @return array $links
	 */
	public function settings_link( $links ) {
		$menu_setting = HFE_Helper::is_pro_active() ? 'uaepro' : 'hfe'; // Replace with your actual menu slug.

		$custom['settings'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url(
				add_query_arg(
					[
						'page' => $menu_setting,
					],
					admin_url( 'admin.php' )
				) . '#dashboard'
			),
			esc_attr__( 'Go to UAE Settings page', 'header-footer-elementor' ),
			esc_html__( 'Settings', 'header-footer-elementor' )
		);

		return array_merge( $custom, (array) $links );
	}

}

new HFE_Settings_Page();
