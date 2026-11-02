<?php

class Xoo_Wsc_Admin_Settings{

	protected static $_instance = null;

	public $installedPlugins = array();

	public static function get_instance(){
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct(){

		$this->hooks();

		if( xoo_wsc_helper()->admin->is_settings_page() ){
			xoo_wsc_helper()->license->adminff = xoo_wsc_helper()->admin;
			xoo_wsc_helper()->license->add_license_tab();
		}

	}

	public function hooks(){

		if( current_user_can( 'manage_options' ) ){
			add_action( 'init', array( $this, 'generate_settings' ), 0 );
			add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		}
		
		add_action( 'xoo_tab_page_start', array( $this, 'info_tab_html' ), 10, 2 );
		add_filter( 'plugin_action_links_' . XOO_WSC_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		if( xoo_wsc_helper()->admin->is_settings_page() && xoo_wsc_helper()->license->init_plugin() ){

			add_action( 'xoo_as_enqueue_scripts', array( $this, 'enqueue_custom_scripts' ) );

			add_action( 'admin_footer', array( $this, 'sidecart_preview' ) );

			add_action( 'xoo_tab_page_start', array( $this, 'preview_info' ), 5 );


			add_action( 'xoo_admin_setting_field_callback_html', array( $this, 'pattern_selector_field' ), 10, 4 );

			add_action( 'xoo_admin_setting_field_callback_html', array( $this, 'header_layout_setting_html' ), 10, 4 );


			if( get_option('xoo-wsc-pattern-init') === false ){
				add_action( 'xoo_tab_page_end', array( $this, 'popup_pattern_selector' ), 10, 2 );
				add_filter('admin_body_class', array( $this, 'admin_body_class') );
			}

			add_action( 'xoo_tab_page_start', array( $this, 'rewards_html' ) );
			add_action( 'admin_footer', array( $this, 'rewards_preview' ) );

		}
	
		if( get_option('xoo-wsc-pattern-init') === false ){
			add_action( 'xoo_admin_settings_side-cart-woocommerce_saved', array( $this, 'popup_initialised' ) );
		}		

		add_action( 'update_option_xoo-wsc-gl-options', array( $this, 'register_checkpoint_strings_translation' ) );

		if( isset( $_GET['page'] ) && $_GET['page'] === 'mlang_strings' ){
			add_action( 'init', array( $this, 'register_checkpoint_strings_translation' ) );
		}

		add_action( 'wp_ajax_xoo_wsc_el_install', array( $this, 'install_loginpopup' ) );
		add_action( 'wp_ajax_xoo_wsc_el_request_just_to_init_save_settings',  array( $this, 'el_request_just_to_init_save_settings' ) );

		add_action( 'wp_ajax_xoo_wsc_product_search_fill_defaults', array( $this, 'product_search_fill_defaults' ) );
		
		
	}

	public function product_search_fill_defaults(){

		if ( !wp_verify_nonce( $_POST['xoo_wsc_nonce'], 'xoo-wsc-nonce' ) ) {
			die('cheating');
		}

		$product_ids = $_POST['product_ids'];

		if( !$product_ids || empty( $product_ids ) ) return;

		$optionsHTML = '';

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( is_object( $product ) ) {
				$optionsHTML .= '<option value="' . esc_attr( $product_id ) . '"' . selected( true, true, false ) . '>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}

		echo $optionsHTML;
		
		die();

	}

	public function rewards_preview(){
		if( !xoo_wsc_helper()->admin->is_settings_page() ) return;
		$base_id = 'xoo-wsc-rewards-options[bars][%$]';
		include XOO_WSC_PATH.'/admin/templates/rewards/progressbar.php';
		include XOO_WSC_PATH.'/admin/templates/rewards/checkpoint.php';
	}

	public function rewards_html($tab_id){
		if( $tab_id !== 'rewards' ) return;
		include XOO_WSC_PATH.'/admin/templates/xoo-wsc-rewards.php';
	}


	public function register_checkpoint_strings_translation(){

		$bars = xoo_wsc_helper()->get_rewards_option('bars');
		$bars = !is_array( $bars ) ? array() : $bars;
		
		foreach ( $bars as $index => $bar ) {

			xoo_wsc_helper()->register_string_for_translation( $bar['settings']['comptxt'], $bar['settings']['barTitle'].' | Completed Text' );

			if( isset($bar['checkpoints'] ) && !empty( $bar['checkpoints'] ) ){

				$points = $bar['checkpoints'];

				foreach ( $points as $point_index => $point) {
					xoo_wsc_helper()->register_string_for_translation( $point['title'], $bar['settings']['barTitle'].' | checkpoint-'.$point_index.'-title' );
					xoo_wsc_helper()->register_string_for_translation( $point['remaining'], $bar['settings']['barTitle'].' | checkpoint-'.$point_index.'-remaining' );	
				}

			}
		}


	}


	public function is_plugin_installed( $plugin_slug ){

		if( isset( $this->installedPlugins[$plugin_slug] ) ){
			return $this->installedPlugins[$plugin_slug];
		}

		$installed = false;

		// Load the necessary WordPress plugin functions
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get the list of all installed plugins
		$all_plugins = get_plugins();

		// Check if the plugin is in the list of installed plugins
		foreach ($all_plugins as $plugin_path => $plugin_data) {
			if (strpos($plugin_path, $plugin_slug . '/') === 0) {
				$installed = true; // Plugin is installed
				break;
			}
		}


		$this->installedPlugins[$plugin_slug] = $installed;

		return $installed;

	}


	public function preview_info($tab_id){
		if( !xoo_wsc_helper()->admin->is_settings_page() || $tab_id === 'pro' || $tab_id === 'info' || $tab_id === 'license' || $tab_id === 'rewards' ) return;
		?>
		<div class="xoo-as-preview-info">*Only settings with the icon <span class="dashicons dashicons-laptop"></span> are updated in customizer view. Actual website view might look different from the setting view due to theme styling.</div>
		<?php
	}

	public function sidecart_preview(){
		if( !xoo_wsc_helper()->admin->is_settings_page() ) return;
		xoo_wsc_helper()->get_template( 'xoo-wsc-preview.php', array(), XOO_WSC_PATH.'/admin/templates/preview/' );
	}



	public function enqueue_custom_scripts( $slug ){

		if( $slug !== 'side-cart-woocommerce' ) return;

		wp_enqueue_style( 'xoo-aff-fa', XOO_WSC_URL.'/library/fontawesome5/css/all.min.css' ); //Font Awesome
		wp_enqueue_style( 'xoo-aff-fa-picker', XOO_WSC_URL.'/library/fontawesome-iconpicker/dist/css/fontawesome-iconpicker.min.css', array(), '1.0', 'all' ); //Font Awesome Icon Picker
		wp_enqueue_script( 'xoo-aff-fa-pickers', XOO_WSC_URL.'/library/fontawesome-iconpicker/dist/js/fontawesome-iconpicker.js', array( 'jquery'), '1.0', false );

		wp_enqueue_script('jquery-ui-sortable');

		wp_enqueue_style( 'xoo-wsc-magic', XOO_WSC_URL.'/library/magic/dist/magic.min.css', array(), '1.0' );
		wp_enqueue_script( 'masonry-js', 'https://unpkg.com/masonry-layout@4.2.2/dist/masonry.pkgd.min.js', array(), XOO_WSC_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_enqueue_script( 'xoo-wsc-serializejson', XOO_WSC_URL . '/admin/assets/xoo-wsc-serializejson.js', array( 'jquery' ), '1.0', true );


		wp_enqueue_script('wc-enhanced-select'); // For product search

		wp_enqueue_style( 'xoo-wsc-admin-fonts', XOO_WSC_URL . '/assets/css/xoo-wsc-fonts.css', array(), XOO_WSC_VERSION );
		wp_enqueue_style( 'xoo-wsc-admin-style', XOO_WSC_URL . '/admin/assets/xoo-wsc-admin-style.css', array(), XOO_WSC_VERSION );
		wp_enqueue_script( 'xoo-wsc-admin-js', XOO_WSC_URL . '/admin/assets/xoo-wsc-admin-js.js', array( 'jquery' ), XOO_WSC_VERSION, true );

		wp_localize_script( 'xoo-wsc-admin-js', 'xoo_wsc_admin_params', array(
			'adminurl'  => admin_url().'admin-ajax.php',
			'nonce' 	=> wp_create_nonce('xoo-wsc-nonce'),
			'isMobile' 	=> wp_is_mobile() ? 'yes' : 'no',
			'bars' 		 => xoo_wsc_helper()->get_rewards_option('bars'),
			'barDefaults' => xoo_wsc_bars()->get_bar_defaults()
		) );
	}


	/**
	 * Show action links on the plugin screen.
	 *
	 * @param	mixed $links Plugin Action links
	 * @return	array
	 */
	public function plugin_action_links( $links ) {
		$action_links = array(
			'settings' 	=> '<a href="' . admin_url( 'admin.php?page=side-cart-woocommerce-settings' ) . '">Settings</a>',
			'support' 	=> '<a href="https://xootix.com/support" target="__blank">Support</a>',
		);

		return array_merge( $action_links, $links );
	}


	public function el_request_just_to_init_save_settings(){
		wp_die();
	}


	public function install_loginpopup(){

		// Check for nonce security      
		if ( !wp_verify_nonce( $_POST['xoo_wsc_nonce'], 'xoo-wsc-nonce' ) ) {
			die('cheating');
		}

		try {

			$plugin_slug = 'easy-login-woocommerce';

			include_once ABSPATH . 'wp-admin/includes/file.php';
			include_once ABSPATH . 'wp-admin/includes/misc.php';
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			include_once ABSPATH . 'wp-admin/includes/plugin.php';

			if( !$this->is_plugin_installed( 'easy-login-woocommerce' ) ){

				// Initialize the WP Filesystem
				if (false === WP_Filesystem()) {
					throw new Exception( "Could not initialize WP_Filesystem.", 'filesystem_error' ) ;
				}

				// Set the plugin URL from the WordPress repository
				$plugin_zip_url = "https://downloads.wordpress.org/plugin/{$plugin_slug}.latest-stable.zip";

				// Download the plugin ZIP file
				$download_result = download_url($plugin_zip_url);

				if (is_wp_error($download_result)) {
					throw new Xoo_Exception( $download_result );
				}

				// Prepare for installation
				$skin 				= new Automatic_Upgrader_Skin();
				$plugin_upgrader 	= new Plugin_Upgrader($skin);
				$install_result 	= $plugin_upgrader->install($plugin_zip_url);

				// Clean up the downloaded file
				unlink($download_result);

				// Return the result of the installation
				if (is_wp_error($install_result)) {
					throw new Xoo_Exception( $install_result );
				}

				//Default setting when installed using side cart
				if( get_option( 'xoo-el-version' ) === false ){

					$firsttime_download = 'yes';

					update_option( 'xoo-el-sy-options', array(
						'sy-popup-style' 	=> 'slider',
						'sy-popup-width' 	=> 500,
					) );
					
					update_option( 'xoo-el-gl-options', array(
						'm-form-pattern' 	=> 'single',
						'm-nav-pattern'  	=> 'links',
						'ao-enable' 		=> 'no'
					) );

					update_option('xoo-el-settings-init', 'yes');

				}
				

			}

			// Activate the plugin after installation
			$activate_result = activate_plugin($plugin_slug . '/xoo-el-main.php');

			if (is_wp_error($activate_result)) {
				throw new Xoo_Exception( $activate_result );
			}

			wp_send_json( array(
				'notice' 				=> 'Plugin installed successfully.<br>For now everything is already setup, but if you want to customize the settings, you can access them <a href="'.admin_url( 'admin.php?page=easy-login-woocommerce-settings' ).'" target="_blank">[here]</a>',
				'firsttime_download' 	=> isset( $firsttime_download ) ? 'yes' : 'no'
			) );

		} catch (Xoo_Exception $e) {
			wp_send_json( array(
				'error' 	=> 'yes',
				'notice' 	=> $e->getMessage()
			) );
		}

		
	}



	public function generate_settings(){
		xoo_wsc_helper()->admin->auto_generate_settings();
	}


	public function add_menu_pages(){

		$args = array(
			'menu_title' 	=> 'Side Cart',
			'icon' 			=> 'dashicons-cart',
		);

		if( !xoo_wsc_helper()->license->is_license_active() ){
			$args['callback'] = array( xoo_wsc_helper()->license, 'license_form' );
		}

		xoo_wsc_helper()->admin->register_menu_page( $args );

	}


	public function info_tab_html( $tab_id, $tab_data ){
		if( $tab_id !== 'info' || !xoo_wsc_helper()->admin->is_settings_page() ) return;
		xoo_wsc_helper()->get_template( 'xoo-wsc-tab-info.php', array(), XOO_WSC_PATH.'/admin/templates/' );
	}


	public function popup_pattern_selector( $tab_id, $tab_data ){
		if( $tab_id !== 'general' ) return;
		?>
		<div class="xoo-wsc-admin-popup">
			<div class="xoo-wsc-adpop">
				<?php $this->pattern_selector_field_html(); ?>
				<span class="xoo-wsc-adpopup-head">Choose your Product Layout</span>
				<span>You can change this later from "Style -> Side Cart Body"</span>
				<button type="button" class="xoo-wsc-adpopup-go button-primary button">Let's Go!</button>
			</div>
			<div class="xoo-wsc-adpop-opac"></div>
		</div>
		<?php
	}


	public function pattern_selector_field_html(){
		?>
		<div class="xoo-wsc-pattern-cont">
			<img class="xoo-wsc-patimg" src="<?php echo XOO_WSC_URL; ?>/admin/assets/images/pattern-card.jpg ?>" data-pattern="cards">
			<img class="xoo-wsc-patimg" src="<?php echo XOO_WSC_URL; ?>/admin/assets/images/pattern-row.jpg ?>" data-pattern="rows" >
		</div>
		<?php
	}

	public function admin_body_class( $classes ){
		$classes .= ' xoo-wsc-adpopup-active';
		return $classes;
	}

	public function popup_initialised(){
		update_option( 'xoo-wsc-pattern-init', 'yes' );
	}

	public function pattern_selector_field( $field, $field_id, $value, $args ){
		if( $field_id !== 'xoo-wsc-sy-options[scb-playout]' ) return $field;
		ob_start();
		$this->pattern_selector_field_html();
		$customField = ob_get_clean();
		return $field. $customField;
	}


	public function header_layout_setting_html( $field, $field_id, $value, $args ){

		if( $field_id !== 'xoo-wsc-sy-options[sch-layout]' ) return $field;

		$defaults = array(
			'left' 		=> array( 'basket', 'heading' ),
			'center' 	=> array(),
			'right'		=> array( 'save', 'close' ),
		);

		if( !$value || empty($value) ){
			$value = $defaults;
		}
		else{
			$defaults = array_map(function() {
			    return array();
			}, $defaults);
			
			$value = xoo_recursive_parse_args( $value, $defaults );
		}

		

		$html = array(
			'basket' 	=> '<span class="xoo-wsc-icon-shopping-bag1 xoo-wschl-icon"></span>',
			'heading' 	=> 'Heading',
			'save' 		=> '<span class="xoo-wsc-icon-heart1 xoo-wschl-icon"></span>',
			'close' 	=> '<span class="xoo-wsc-icon-del1 xoo-wschl-icon"></span>',
		);

		ob_start();

		?>
		<div class="xoo-wsch-layout-cont xoo-as-setting xoo-as-has-preview">

			<?php foreach ($value as $location => $elements ): ?>

				<div>
					<span><?php echo $location; ?></span>
					<ul id="xooWscH-<?php echo $location; ?>" class="xooWscHconnectedSortable" data-name="<?php echo $location; ?>">

						<?php foreach( $elements as $element ): ?>
							<li>
								<?php echo $html[ $element ] ?>
								<input type="hidden" name="xoo-wsc-sy-options[sch-layout][<?php echo $location ?>][]" value="<?php echo $element ?>">
							</li>

						<?php endforeach; ?>
					</ul>
				</div>

			<?php endforeach; ?>

		</div>

		<?php

		return ob_get_clean();

	}

	public function bar_selectedoptions( $name, $options ){
		foreach ( $options as $option_value => $title) {
			?>
			<option value="<?php echo $option_value ?>" {{ data.<?php echo $name; ?> == '<?php echo $option_value ?>' ? 'selected' : '' }} ><?php echo $title ?></option>
			<?php
		}
	}


}

function xoo_wsc_admin_settings(){
	return Xoo_Wsc_Admin_Settings::get_instance();
}
xoo_wsc_admin_settings();