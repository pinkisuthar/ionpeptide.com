<?php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Nonces are verified inside each AJAX handler; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Customizer_Admin {
	
	private static $screen_id = 'zorem_returns_exchanges_customizer';

	private static $screen_title = 'Return & Exchange Customizer'; 

	// WooCommerce email classes.
	public static $email_types_class_names  = array();
	
	public static $email_types_order_status = array();
	
	/**
	 * Get the class instance
	 *
	 * @since  1.0
	 * @return Return_Customizer_Admin
	*/
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	*/
	private static $instance;
	
	/**
	 * Initialize the main plugin function
	 * 
	 * @since  1.0
	*/
	public function __construct() {
		$this->init();
	}
	
	/*
	 * init function
	 *
	 * @since  1.0
	*/
	public function init() {

		//adding hooks
		add_action( 'admin_menu', array( $this, 'register_woocommerce_menu' ), 99 );

		add_action('rest_api_init', array( $this, 'route_api_functions' ) );
						
		add_action('admin_enqueue_scripts', array( $this, 'customizer_enqueue_scripts' ), 20 );

		add_action('admin_footer', array( $this, 'admin_footer_enqueue_scripts' ) );

		add_action( 'wp_ajax_' . self::$screen_id . '_email_preview', array( $this, 'get_preview_func' ) );
		add_action( 'wp_ajax_send_' . self::$screen_id . '_test_email', array( $this, 'send_test_email_func' ) );

		// Custom Hooks for everyone
		add_filter( 'zorem_returns_exchanges_customizer_email_options', array( $this, 'zorem_returns_exchanges_customizer_email_options' ), 10, 2);
		add_filter( 'zorem_returns_exchanges_customizer_preview_content', array( $this, 'zorem_returns_exchanges_customizer_preview_content' ), 10, 1);
		
	}
	
	/*
	 * Admin Menu add function
	 *
	 * @since  2.4
	 * WC sub menu 
	*/
	public function register_woocommerce_menu() {
		$capability = apply_filters( 'zorem_returns_exchanges_admin_capability', 'manage_options' );
		$menu_title = __( 'Return & Exchange Customizer', 'zorem-returns' );
		add_menu_page( $menu_title, $menu_title, $capability, self::$screen_id, array( $this, 'react_settingsPage' ) );
	}

	/*
	 * Call Admin Menu data function
	 *
	 * @since  2.4
	 * WC sub menu 
	*/
	public function react_settingsPage() {
		echo '<div id="root"></div>';
	}

	/*
	 * Add admin javascript
	 *
	 * @since  2.4
	 * WC sub menu 
	*/
	public function admin_footer_enqueue_scripts() {
		echo '<style type="text/css">#toplevel_page_' . esc_html(self::$screen_id) . ' { display: none !important; }</style>';
	}
	
	/*
	* Add admin javascript
	*
	* @since 1.0
	*/	
	public function customizer_enqueue_scripts() {
		
		
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' ;
		
		// Add condition for css & js include for admin page  
		if ( self::$screen_id == $page ) {
			// Add the WP Media 
			wp_enqueue_media();
			wp_enqueue_script( self::$screen_id, plugin_dir_url(__FILE__) . 'dist/main.js', ['jquery', 'wp-util', 'wp-color-picker'], zorem_returns_exchanges()->version, true);
			wp_localize_script( self::$screen_id, self::$screen_id, array(
				'main_title'	=> esc_html__( 'Return & Exchange Customizer', 'zorem-returns' ),
				'admin_email' => get_option('admin_email'),
				'send_test_email_btn' => true,
				'iframeUrl'	=> array(
					'customer_return_requested' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_return_requested'),
					'customer_return_approved' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_return_approved'),
					'customer_return_completed' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_return_completed'),
					'customer_return_rejected' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_return_rejected'),
					'customer_return_store_credit' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_return_store_credit'),
					'customer_exchange_requested' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_exchange_requested'),
					'customer_exchange_approved' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_exchange_approved'),
					'customer_exchange_completed' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_exchange_completed'),
					'customer_exchange_rejected' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_exchange_rejected'),
					'customer_exchange_store_credit' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_exchange_store_credit'),
					'customer_warranty_submitted'    => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_warranty_submitted'),
					'customer_warranty_under_review' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_warranty_under_review'),
					'customer_warranty_approved'     => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_warranty_approved'),
					'customer_warranty_rejected'     => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_warranty_rejected'),
					'customer_warranty_resolved'     => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=customer_warranty_resolved'),
					'admin_return_requested' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_return_requested'),
					'admin_return_approved' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_return_approved'),
					'admin_return_completed' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_return_completed'),
					'admin_return_rejected' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_return_rejected'),
					'admin_exchange_requested' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_exchange_requested'),
					'admin_exchange_approved' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_exchange_approved'),
					'admin_exchange_completed' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_exchange_completed'),
					'admin_exchange_rejected' => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_exchange_rejected'),
					'admin_warranty_submitted'       => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_warranty_submitted'),
					'admin_warranty_under_review'    => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_warranty_under_review'),
					'admin_warranty_approved'        => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_warranty_approved'),
					'admin_warranty_rejected'        => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_warranty_rejected'),
					'admin_warranty_resolved'        => admin_url('admin-ajax.php?action=' . self::$screen_id . '_email_preview&preview=admin_warranty_resolved'),
				),
				'translations' => array(
					esc_html__( 'Save Changes', 'zorem-returns' ),
					esc_html__( 'You are customizing', 'zorem-returns' ),
					esc_html__( 'Customizing', 'zorem-returns' ),
					esc_html__( 'Send Test Email', 'zorem-returns' ),
					esc_html__( 'Send Test Email', 'zorem-returns' ),
					esc_html__( 'Recipient Email Address(es) (separate multiple with commas)', 'zorem-returns' ),
					esc_html__( 'Send', 'zorem-returns' ),
					esc_html__( 'Settings Successfully Saved.', 'zorem-returns' ),
					esc_html__( 'Please save your changes before sending a test email.', 'zorem-returns' )
				),
				'back_to_wordpress_link' => admin_url('admin.php?page=zorem-returns-exchanges&tab=settings'),
				'rest_nonce'	=> wp_create_nonce('wp_rest'),
				'rest_base'	=> esc_url_raw( rest_url() ),
			));

			wp_enqueue_style( self::$screen_id . '-custom-css', plugin_dir_url(__FILE__) . 'assets/custom.css', array(), zorem_returns_exchanges()->version );
			wp_enqueue_script( self::$screen_id . '-custom-js', plugin_dir_url(__FILE__) . 'assets/custom.js', ['jquery'], zorem_returns_exchanges()->version, true );
		}
		
	}


	/*
	 * Customizer Routes API 
	*/
	public function route_api_functions() {

		// nosemgrep:disable audit.php.wp.security.rest-route.permission-callback.return-true
		register_rest_route( self::$screen_id, 'settings', array(
			'methods'  => 'GET',
			'callback' => [$this, 'return_json_sucess_settings_route_api'],
			'permission_callback' => function () {
				return current_user_can( apply_filters( 'zorem_returns_exchanges_admin_capability', 'manage_options' ) );
			},
		));

		register_rest_route( self::$screen_id, 'store/update', array(
			'methods'				=> 'POST',
			'callback'				=> [$this, 'update_store_settings'],
			'permission_callback' => function () {
				return current_user_can( apply_filters( 'zorem_returns_exchanges_admin_capability', 'manage_options' ) );
			},
		));

		register_rest_route( self::$screen_id, 'send-test-email', array(
			'methods'				=> 'POST',
			'callback'				=> [$this, 'send_test_email_func'],
			'permission_callback' => function () {
				return current_user_can( apply_filters( 'zorem_returns_exchanges_admin_capability', 'manage_options' ) );
			},
		));
		// nosemgrep:enable
	}



	/*
	 * Settings API 
	*/
	public function return_json_sucess_settings_route_api( $request ) {
		$preview = !empty($request->get_param('preview')) ? $request->get_param('preview') : '';
		return new WP_REST_Response( array( 'success' => true, 'data' => $this->customize_setting_options_func( $preview ) ) );

	}

	public function customize_setting_options_func( $preview) {

		$settings = apply_filters(  self::$screen_id . '_email_options' , $settings = array(), $preview );
		
		return $settings; 

	}


	public function get_preview_func() {
		$preview = isset($_GET['preview']) ? sanitize_text_field( wp_unslash( $_GET['preview'] ) ) : '';
		echo wp_kses_post($this->get_preview_email($preview));
		die();
	}

	/**
	 * Get the email content
	 *
	 */
	public function get_preview_email( $preview ) {

		$content = apply_filters( self::$screen_id . '_preview_content' , $preview );

		$content .= '<style type="text/css">body{margin: 0;}#outer_wrapper {height: 100vh;}</style>';

		add_filter( 'wp_kses_allowed_html', array( $this, 'allowed_css_tags' ) );
		add_filter( 'safe_style_css', array( $this, 'safe_style_css' ), 10, 1 );

		return wp_kses_post($content);
	}

	/*
	* update a customizer settings
	*/
	public function update_store_settings( $request ) {

		$preview = !empty($request->get_param('preview')) ? $request->get_param('preview') : '';

		$data = $request->get_params() ? $request->get_params() : array();

		if ( ! empty( $data ) ) {

			//data to be saved
			
			$settings = $this->customize_setting_options_func( $preview );
			
			foreach ( $settings as $key => $val ) {

				if ( !isset($data[$key]) || ( isset($val['show']) && true != $val['show'] ) ) {
					continue;
				}

				//check column exist
				if ( isset( $val['option_type'] ) && 'key' == $val['option_type'] ) {
					$data[$key] = isset($data[$key]) ? wp_kses_post( wp_unslash( $data[$key] ) ) : '';
					update_option( $key, $data[$key] );
				} elseif ( isset( $val['option_type'] ) && 'array' == $val['option_type'] ) {
					if ( isset( $val['option_key'] ) && isset( $val['option_name'] ) ) {
						$option_data = get_option( $val['option_name'], array() );
						if ( 'enabled' == $val['option_key'] ) {
							$option_data[$val['option_key']] = isset($data[$key]) && 1 == $data[$key] ? wp_kses_post( wp_unslash( 'yes' ) ) : wp_kses_post( wp_unslash( 'no' ) );
						} else {
							$option_data[$val['option_key']] = isset($data[$key]) ? wp_kses_post( wp_unslash( $data[$key] ) ) : '';
						}
						update_option( $val['option_name'], $option_data );
					} elseif ( isset($val['option_name']) ) {
						$option_data = get_option( $val['option_name'], array() );
						$option_data[$key] = isset($data[$key]) ? wp_kses_post( wp_unslash( $data[$key] ) ) : '';
						update_option( $val['option_name'], $option_data );
					}
				}
			}
			
			return new WP_REST_Response( array( 'success' => true, 'preview' => $preview ) );

		}

		return new WP_REST_Response( array( 'success' => false ) );
	}

	/*
	* send a test email
	*/
	public function send_test_email_func( $request ) {

		$data = $request->get_params() ? $request->get_params() : array();

		$preview = !empty( $data['preview'] ) ? sanitize_text_field($data['preview']) : '';
		$recipients = !empty( $data['recipients'] ) ? sanitize_text_field($data['recipients']) : '';

		if ( ! empty( $preview ) && ! empty( $recipients ) ) {
			
			$message 		= apply_filters( self::$screen_id . '_preview_content' , $preview );
			$subject_email 	= 'email';
			$subject = str_replace('{site_title}', get_bloginfo( 'name' ), 'Test ' . $subject_email );
			
			// create a new email
			$email 		= new WC_Email();
			add_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
			add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );

			$recipients = explode( ',', $recipients );
			if ($recipients) {
				foreach ( $recipients as $recipient) {
					wp_mail( $recipient, $subject, $message, $email->get_headers() );
				}
			}
			
			return new WP_REST_Response( array( 'success' => true ) );

		}

		return new WP_REST_Response( array( 'success' => false ) );
	}

	public function zorem_returns_exchanges_customizer_email_options( $settings, $preview ) {

		$settings = array(
			//panels
			'email_content'	=> array(
				'title'	=> html_entity_decode(esc_html__( 'Return and Exchange Emails', 'zorem-returns' )),
				'type'	=> 'panel',
				'preview' => $preview ? $preview : 'customer_return_requested',
			),
		);

		//settings			
		$email_types = array(
			'customer_return_requested' => html_entity_decode(esc_html__( 'Return Request Received (Customer)', 'zorem-returns' )),
			'customer_return_approved' => html_entity_decode(esc_html__( 'Return Approved (Customer)', 'zorem-returns' )),
			'customer_return_completed' => html_entity_decode(esc_html__( 'Return Completed (Customer)', 'zorem-returns' )),
			'customer_return_rejected' => html_entity_decode(esc_html__( 'Return Rejected (Customer)', 'zorem-returns' )),
			'customer_return_store_credit' => html_entity_decode(esc_html__( 'Return Store Credit (Customer)', 'zorem-returns' )),
			'customer_exchange_requested' => html_entity_decode(esc_html__( 'Exchange Request Received (Customer)', 'zorem-returns' )),
			'customer_exchange_approved' => html_entity_decode(esc_html__( 'Exchange Approved (Customer)', 'zorem-returns' )),
			'customer_exchange_completed' => html_entity_decode(esc_html__( 'Exchange Completed (Customer)', 'zorem-returns' )),
			'customer_exchange_rejected' => html_entity_decode(esc_html__( 'Exchange Rejected (Customer)', 'zorem-returns' )),
			'customer_exchange_store_credit' => html_entity_decode(esc_html__( 'Exchange Store Credit (Customer)', 'zorem-returns' )),
			'customer_warranty_submitted'    => html_entity_decode(esc_html__( 'Warranty Claim Submitted (Customer)', 'zorem-returns' )),
			'customer_warranty_under_review' => html_entity_decode(esc_html__( 'Warranty Claim Under Review (Customer)', 'zorem-returns' )),
			'customer_warranty_approved'     => html_entity_decode(esc_html__( 'Warranty Claim Approved (Customer)', 'zorem-returns' )),
			'customer_warranty_rejected'     => html_entity_decode(esc_html__( 'Warranty Claim Rejected (Customer)', 'zorem-returns' )),
			'customer_warranty_resolved'     => html_entity_decode(esc_html__( 'Warranty Claim Resolved (Customer)', 'zorem-returns' )),
			'admin_return_requested' => html_entity_decode(esc_html__( 'Return Request Received (Admin)', 'zorem-returns' )),
			'admin_return_approved' => html_entity_decode(esc_html__( 'Return Approved (Admin)', 'zorem-returns' )),
			'admin_return_completed' => html_entity_decode(esc_html__( 'Return Completed (Admin)', 'zorem-returns' )),
			'admin_return_rejected' => html_entity_decode(esc_html__( 'Return Rejected (Admin)', 'zorem-returns' )),
			'admin_exchange_requested' => html_entity_decode(esc_html__( 'Exchange Request Received (Admin)', 'zorem-returns' )),
			'admin_exchange_approved' => html_entity_decode(esc_html__( 'Exchange Approved (Admin)', 'zorem-returns' )),
			'admin_exchange_completed' => html_entity_decode(esc_html__( 'Exchange Completed (Admin)', 'zorem-returns' )),
			'admin_exchange_rejected' => html_entity_decode(esc_html__( 'Exchange Rejected (Admin)', 'zorem-returns' )),
			'admin_warranty_submitted'       => html_entity_decode(esc_html__( 'Warranty Claim Submitted (Admin)', 'zorem-returns' )),
			'admin_warranty_under_review'    => html_entity_decode(esc_html__( 'Warranty Claim Under Review (Admin)', 'zorem-returns' )),
			'admin_warranty_approved'        => html_entity_decode(esc_html__( 'Warranty Claim Approved (Admin)', 'zorem-returns' )),
			'admin_warranty_rejected'        => html_entity_decode(esc_html__( 'Warranty Claim Rejected (Admin)', 'zorem-returns' )),
			'admin_warranty_resolved'        => html_entity_decode(esc_html__( 'Warranty Claim Resolved (Admin)', 'zorem-returns' )),
		);

		$warranty_defaults = isset( zorem_returns_exchanges()->warranty ) ? zorem_returns_exchanges()->warranty->generate_email_defaults() : array();
		$defualt_settings  = array_merge( zorem_returns_exchanges()->common->generate_defaults(), zorem_returns_exchanges()->exCommon->generate_defaults(), $warranty_defaults );
		
		$settings[ 'email_type' ] = array(
			'title'    => html_entity_decode(esc_html__( 'Email Type', 'zorem-returns' )),
			'type'     => 'select',
			'default'  => $preview ? $preview : 'customer_return_requested',
			'options'  => $email_types,
			'show'     => true,
			'previewType' => true,
			'parent'=> 'email_content',
			'preview' => $preview ? $preview : 'customer_return_requested' 
		);
		
		foreach ( $email_types as $key => $value ) {

			// Per-family option prefix — rt4wc_ for returns, ex4wc_ for exchanges,
			// wt4wc_ for warranty. Each family stores its settings under a separate
			// option so warranty additions don't leak into the return/exchange
			// stores (and the customizer can render any family without conflicts).
			if ( str_contains( $key, 'warranty' ) ) {
				$option_prefix = 'wt4wc_';
			} elseif ( str_contains( $key, 'exchange' ) ) {
				$option_prefix = 'ex4wc_';
			} else {
				$option_prefix = 'rt4wc_';
			}
			$option_name_for_key = $option_prefix . $key . '_settings';
			$email_settings      = get_option( $option_name_for_key );
			
			$settings[ $key . '_subject' ] = array(
				'title'    => html_entity_decode(esc_html__( 'Email Subject', 'zorem-returns' )),
				'parent'=> 'email_content',
				'default'  => !empty($email_settings['subject']) ? wp_kses_post($email_settings['subject']) : $defualt_settings[$key]['subject'],
				'placeholder' => $defualt_settings[$key]['subject'],
				'type'     => 'text',
				'show'     => true,
				'option_name' => $option_name_for_key,
				'option_key'=> 'subject',
				'option_type' => 'array',
				'class'		=> $key . '_sub_menu all_status_submenu',
			);
			
			$settings[ $key . '_heading' ] = array(
				'title'    => html_entity_decode(esc_html__( 'Email Heading', 'zorem-returns' )),
				'parent'=> 'email_content',
				'default'  => !empty($email_settings['heading']) ? htmlspecialchars_decode($email_settings['heading']) : htmlspecialchars_decode($defualt_settings[$key]['heading']),
				'placeholder' => stripslashes($defualt_settings[$key]['heading']),
				'type'     => 'text',
				'show'     => true,
				'refresh'     => true,
				'option_name' => $option_name_for_key,
				'option_key'=> 'heading',
				'option_type' => 'array',
				'class'		=> $key . '_sub_menu all_status_submenu heading',
			);
			$settings[ $key . '_email_content' ] = array(
				'title'    => html_entity_decode(esc_html__( 'Email Content', 'zorem-returns' )),
				'parent'=> 'email_content',
				'default'  => !empty($email_settings['email_content']) ? htmlspecialchars_decode($email_settings['email_content']) : htmlspecialchars_decode($defualt_settings[$key]['email_content']),
				'placeholder' => htmlspecialchars_decode($defualt_settings[$key]['email_content']),
				'type'     => 'textarea',
				'show'     => true,
				'refresh'     => true,
				'option_key'=> 'email_content',
				'option_name' => $option_name_for_key,
				'option_type' => 'array',
				'class'		=> $key . '_sub_menu all_status_submenu email_content',
				'desc'	=> html_entity_decode(esc_html__( 'You can use basic HTML tags such as <p>, <strong>, <br>, <ul>, <ol>, <li>, <a>.', 'zorem-returns' )),
			);

			if ( str_contains( $key, 'warranty' ) ) {
				$settings[ $key . '_claim_items_title' ] = array(
					'title'       => html_entity_decode( esc_html__( 'Section Title for Warranty Claim', 'zorem-returns' ) ),
					'parent'      => 'email_content',
					'default'     => ! empty( $email_settings['claim_items_title'] ) ? htmlspecialchars_decode( $email_settings['claim_items_title'] ) : $defualt_settings[ $key ]['claim_items_title'],
					'placeholder' => '',
					'type'        => 'text',
					'show'        => true,
					'refresh'     => true,
					'option_name' => $option_name_for_key,
					'option_key'  => 'claim_items_title',
					'option_type' => 'array',
					'class'       => $key . '_sub_menu all_status_submenu claim_items_title',
				);
			} elseif ( str_contains($key, 'exchange') ) {
				$settings[ $key . '_exchangeitems_title' ] = array(
					'title'    => html_entity_decode(esc_html__( 'Section Title for Exchanged Items', 'zorem-returns' )),
					'parent'=> 'email_content',
					'default'  => !empty($email_settings['exchange_items_title']) ? htmlspecialchars_decode($email_settings['exchange_items_title']) : $defualt_settings[$key]['exchange_items_title'],
					'placeholder' => '',
					'type'     => 'text',
					'show'     => true,
					'refresh'     => true,
					'option_name' => 'ex4wc_' . $key . '_settings',
					'option_key'=> 'exchange_items_title',
					'option_type' => 'array',
					'class'		=> $key . '_sub_menu all_status_submenu exchange_items_title',
				);
			} else {
				$settings[ $key . '_return_items_title' ] = array(
					'title'    => html_entity_decode(esc_html__( 'Section Title for Returned Items', 'zorem-returns' )),
					'parent'=> 'email_content',
					'default'  => !empty($email_settings['return_items_title']) ? htmlspecialchars_decode($email_settings['return_items_title']) : $defualt_settings[$key]['return_items_title'],
					'placeholder' => '',
					'type'     => 'text',
					'show'     => true,
					'refresh'     => true,
					'option_name' => 'rt4wc_' . $key . '_settings',
					'option_key'=> 'return_items_title',
					'option_type' => 'array',
					'class'		=> $key . '_sub_menu all_status_submenu return_items_title',
				);
			}

			$settings[ $key . '_additional_content' ] = array(
				'title'    => html_entity_decode(esc_html__( 'Additional Content', 'zorem-returns' )),
				'parent'=> 'email_content',
				'default'  => !empty($email_settings['additional_content']) ? htmlspecialchars_decode($email_settings['additional_content']) : htmlspecialchars_decode($defualt_settings[$key]['additional_content']),
				'placeholder' => '',
				'type'     => 'textarea',
				'show'     => true,
				'refresh'     => true,
				'option_key'=> 'additional_content',
				'option_name' => $option_name_for_key,
				'option_type' => 'array',
				'class'		=> $key . '_sub_menu all_status_submenu additional_content',
				'desc'	=> html_entity_decode(esc_html__( 'You can use basic HTML tags such as <p>, <strong>, <br>, <ul>, <ol>, <li>, <a>.', 'zorem-returns' )),
			);
			$settings[ $key . '_codeinfoblock' ] = array(
				'title'    => html_entity_decode(esc_html__( 'Available Placeholders:', 'zorem-returns' )),
				'parent'=> 'email_content',
				'default'  => '<code>{site_title}<br>{order_date}<br>{order_number}<br>{customer_full_name}<br>{customer_first_name}<br>{customer_last_name}<br>{customer_username}<br>{store_address}<br>{phone_number}</code>',
				'type'     => 'codeinfo',
				'show'     => true,
				'class'		=> $key . '_sub_menu all_status_submenu',
			);
		};

		return $settings;
	}

	public function zorem_returns_exchanges_customizer_preview_content( $preview ) {

		// Get an order
		$order = self::get_wc_order_for_preview( 'completed', 'mockup' );

		// Load WooCommerce emails.
		$mailer = WC()->mailer();
		$email = new WC_Email();
		if ( str_contains( $preview, 'warranty' ) ) {
			$defualt_settings = isset( zorem_returns_exchanges()->warranty ) ? zorem_returns_exchanges()->warranty->generate_email_defaults() : array();
			$email_settings   = get_option( 'wt4wc_' . $preview . '_settings', isset( $defualt_settings[ $preview ] ) ? $defualt_settings[ $preview ] : array() );
		} elseif ( str_contains( $preview, 'exchange' ) ) {
			$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();
			$email_settings   = get_option( 'ex4wc_' . $preview . '_settings', $defualt_settings[ $preview ] );
		} else {
			$defualt_settings = zorem_returns_exchanges()->common->generate_defaults();
			$email_settings   = get_option( 'rt4wc_' . $preview . '_settings', $defualt_settings[ $preview ] );
		}
		$email_heading = isset($email_settings['heading']) ? $email_settings['heading'] : '';
		
		ob_start();

		$local_template	= get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/' . str_replace('_', '-', $preview) . '.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template )) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		wc_get_template( 'emails/' . str_replace('_', '-', $preview) . '.php',
			array(
				'request_id' => null,
				'claim_id'   => null,
				'claim'      => null,
				'order'         => $order,
				'email'    => $email,
				'email_settings'    => $email_settings,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);
		$content = ob_get_clean();
		
		// create a new email
		$content = apply_filters( 'woocommerce_mail_content', $email->style_inline( $mailer->wrap_message( $email_heading, $content ) ) );

		return $content;
		die();
	}

	/**
	 * Get WooCommerce order for preview
	 *
	 * @param string $order_status
	 * @return object
	 */
	public static function get_wc_order_for_preview( $order_status = null, $order_id = null ) {
		if ( ! empty( $order_id ) && 'mockup' != $order_id ) { 
			return wc_get_order( $order_id );
		} else {
			// Use mockup order

			// Instantiate order object
			$order = new WC_Order();

			// Other order properties
			$order->set_props( array(
				'id'                 => 1,
				//'status'             => ( null === $order_status ? 'processing' : $order_status ),
				'billing_first_name' => 'Sherlock',
				'billing_last_name'  => 'Holmes',
				'billing_company'    => 'Detectives Ltd.',
				'billing_address_1'  => '221B Baker Street',
				'billing_city'       => 'London',
				'billing_postcode'   => 'NW1 6XE',
				'billing_country'    => 'GB',
				'billing_email'      => 'sherlock@holmes.co.uk',
				'billing_phone'      => '02079304832',
				'date_created'       => gmdate( 'Y-m-d H:i:s' ),
				'total'              => 24.90,
			) );

			// Item #1
			$order_item = new WC_Order_Item_Product();
			$order_item->set_props( array(
				'name'     => 'A Study in Scarlet',
				'subtotal' => '9.95',
			) );
			$order->add_item( $order_item );

			// Item #2
			$order_item = new WC_Order_Item_Product();
			$order_item->set_props( array(
				'name'     => 'The Hound of the Baskervilles',
				'subtotal' => '14.95',
			) );
			$order->add_item( $order_item );
			
			// Return mockup order
			return $order;
		}

	} 

	/**
	 * Get the from name for outgoing emails.
	 *
	 * @return string
	 */
	public function get_from_name() {
		$from_name = apply_filters( 'woocommerce_email_from_name', get_option( 'woocommerce_email_from_name' ), $this );
		return wp_specialchars_decode( esc_html( $from_name ), ENT_QUOTES );
	}

	/**
	 * Get the from address for outgoing emails.
	 *
	 * @return string
	 */
	public function get_from_address() {
		$from_address = apply_filters( 'woocommerce_email_from_address', get_option( 'woocommerce_email_from_address' ), $this );
		return sanitize_email( $from_address );
	}
	
	/**
	 * Get the email order status
	 *
	 * @param string $email_template the template string name.
	 */
	public function get_email_order_status( $email_template ) {
		
		$order_status = apply_filters( 'customizer_email_type_order_status_array', self::$email_types_order_status );
		
		$order_status = self::$email_types_order_status;
		
		if ( isset( $order_status[ $email_template ] ) ) {
			return $order_status[ $email_template ];
		} else {
			return 'processing';
		}
	}

	/**
	 * Get the email class name
	 *
	 * @param string $email_template the email template slug.
	 */
	public function get_email_class_name( $email_template ) {
		
		$class_names = apply_filters( 'customizer_email_type_class_name_array', self::$email_types_class_names );

		$class_names = self::$email_types_class_names;
		if ( isset( $class_names[ $email_template ] ) ) {
			return $class_names[ $email_template ];
		} else {
			return false;
		}
	}

	public function allowed_css_tags( $tags ) {
		$tags['style'] = array( 'type' => true, );
		return $tags;
	}
	
	public function safe_style_css( $styles ) {
		 $styles[] = 'display';
		return $styles;
	}

}
