<?php
/**
 * Settings admin panel handling
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Settings_Admin_Panel_Premium' ) ) {
	/**
	 * Affiliates admin panel handling
	 *
	 * @since 1.0.0
	 */
	class YITH_WCAF_Settings_Admin_Panel_Premium extends YITH_WCAF_Settings_Admin_Panel {

		/**
		 * Current tab name
		 *
		 * @var string
		 */
		protected $tab = 'settings';

		/**
		 * Init Affiliates admin panel
		 */
		public function __construct() {
			parent::__construct();

			$this->premium_actions();

			// filter settings.
			add_filter( 'yith_wcaf_general_settings', array( $this, 'premium_general_options' ) );
			add_filter( 'yith_wcaf_affiliates_registration_settings', array( $this, 'premium_affiliates_registration_options' ) );
			add_filter( 'yith_wcaf_commissions_payments_settings', array( $this, 'premium_commissions_payments_options' ) );

			// register custom fields.
			add_action( 'yith_wcaf_print_admin_template_field', array( $this, 'print_template_field' ), 10, 1 );
			add_action( 'woocommerce_admin_settings_sanitize_option', array( $this, 'save_template' ), 10, 2 );

			// add affiliate fields modal.
			add_action( 'yit_framework_after_print_wc_panel_content', array( $this, 'render_add_field_modal' ), 10, 1 );

			add_action( 'yith_wcaf_print_profile_fields_list_tab', array( $this, 'render_profile_fields_list_table' ) );
		}



		/**
		 * Get instance for Affiliate Profile Fields list table.
		 *
		 * @return YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium
		 */
		public static function get_profile_fields_list_table() {
			return new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium();
		}

		/**
		 * Render the Affiliates Profile fields List tab.
		 */
		public static function render_profile_fields_list_table() {
			$list_table = self::get_profile_fields_list_table();
			$list_table->prepare_items();
			$list_table->views();
			?>
			<div id="yith_wcaf_profile_fields">
				<?php $list_table->display(); ?>
			</div>
			<?php
		}


		/**
		 * Adds premium panel actions to registered one.
		 *
		 * @return void.
		 */
		public function premium_actions() {
			$this->admin_actions = array_merge(
				$this->admin_actions,
				array(
					'move_template'   => array( $this, 'move_template' ),
					'delete_template' => array( $this, 'delete_template' ),
				)
			);
		}

		/* === PREMIUM OPTIONS === */

		/**
		 * Filers plugin options to add premium-specific data
		 *
		 * @param array $options Array of options.
		 *
		 * @return array Filtered array of options.
		 */
		public function premium_general_options( $options ) {

			$general_options = $options['settings-general'];

			// add referral origin options.
			$general_options = $this->add_referral_origin_option( $general_options );

			// add exclusion options.
			$general_options = $this->add_exclusion_options( $general_options );

			// add cookie options.
			$general_options = $this->add_cookie_options( $general_options );

			// add coupon options.
			$general_options = $this->add_coupon_options( $general_options );

			// add clicks options.
			$general_options = $this->add_clicks_options( $general_options );

			$options['settings-general'] = $general_options;

			return $options;
		}

		/**
		 * Filers plugin options to add premium-specific data
		 *
		 * @param array $options Array of options.
		 *
		 * @return array Filtered array of options.
		 */
		public function premium_affiliates_registration_options( $options ) {
			$registration_options = $options['settings-affiliates-registration'];

			// add general premium options.
			$registration_options = $this->add_general_registration_options( $registration_options );

			// add global messages options.
			$registration_options = $this->add_global_messages_options( $registration_options );

			// add hidden sections options.
			$registration_options = $this->add_hidden_sections_options( $registration_options );

			// set premium table.
			if ( isset( $registration_options['referral-registration-fields-table'] ) ) {
				$registration_options['referral-registration-fields-table']['list_table_class']     = 'YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium';
				$registration_options['referral-registration-fields-table']['list_table_class_dir'] = YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-affiliates-profile-fields-table-premium.php';
			}

			$options['settings-affiliates-registration'] = $registration_options;

			return $options;
		}

		/**
		 * Filers plugin options to add premium-specific data
		 *
		 * @param array $options Array of options.
		 *
		 * @return array Filtered array of options.
		 */
		public function premium_commissions_payments_options( $options ) {
			$commissions_options = $options['settings-commissions-payments'];

			// add general premium options.
			$commissions_options = $this->add_commissions_options( $commissions_options );

			// add commission notification options.
			$commissions_options = $this->add_notification_options( $commissions_options );

			// add payments options.
			$commissions_options = $this->add_payments_options( $commissions_options );

			// add invoice options.
			$commissions_options = $this->add_invoice_options( $commissions_options );

			// add gateways options.
			$commissions_options = $this->add_gateways_options( $commissions_options );

			// adjust general rate description.
			$commissions_options['commission-general-rate']['desc'] = _x( 'Enter the default commission rate for all affiliates.<br/>You can override this value in Affiliates &gt; Rates or in each Affiliates\' detail page.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' );

			$options['settings-commissions-payments'] = $commissions_options;

			return $options;
		}

		/**
		 * Filters general plugin options, and adds referral origin option
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_referral_origin_option( $options ) {
			$referral_origin_option = array(
				'general-referral-cod' => array(
					'title'     => _x( 'Allow users to enter a token at checkout', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable if you want to show your users a textbox in the Checkout page where they can enter the token of the affiliate that referred them.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_show_checkout_box',
					'default'   => 'no',
				),
			);

			return yith_wcaf_append_items( $options, 'general-referral-var', $referral_origin_option );
		}

		/**
		 * Filters general plugin options, and adds exclusions options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_exclusion_options( $options ) {
			$user_roles = wp_list_pluck( wp_roles()->roles, 'name' );

			$exclusion_settings = array(
				'product-exclusions-enable' => array(
					'title'     => _x( 'Exclude products from the affiliate program', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'      => _x( 'Enable if you want to exclude products from the affiliate program.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'default'   => 'no',
					'id'        => 'yith_wcaf_product_exclusions_enabled',
				),
				'excluded-products'         => array(
					'title'     => _x( 'Choose which products to exclude', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'      => _x( 'Choose which products to exclude from affiliates\' commissions.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'ajax-products',
					'multiple'  => true,
					'data'      => array(
						'action'      => 'woocommerce_json_search_products_and_variations',
						'security'    => wp_create_nonce( 'search-products' ),
						'placeholder' => _x( 'Search products', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					),
					'id'        => 'yith_wcaf_excluded_products',
					'deps'      => array(
						'id'    => 'yith_wcaf_product_exclusions_enabled',
						'value' => 'yes',
					),
				),
				'excluded-categories'       => array(
					'title'     => _x( 'Choose which product categories to exclude', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'      => _x( 'Choose which product categories to exclude from affiliates\' commissions.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'ajax-terms',
					'multiple'  => true,
					'data'      => array(
						'taxonomy'    => 'product_cat',
						'placeholder' => _x( 'Search categories', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					),
					'id'        => 'yith_wcaf_excluded_product_categories',
					'deps'      => array(
						'id'    => 'yith_wcaf_product_exclusions_enabled',
						'value' => 'yes',
					),
				),
				'excluded-tags'             => array(
					'title'     => _x( 'Choose which product tags to exclude', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'      => _x( 'Choose which product tags to exclude from affiliates\' commissions.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'ajax-terms',
					'multiple'  => true,
					'data'      => array(
						'taxonomy'    => 'product_tag',
						'placeholder' => _x( 'Search tags', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					),
					'id'        => 'yith_wcaf_excluded_product_tags',
					'deps'      => array(
						'id'    => 'yith_wcaf_product_exclusions_enabled',
						'value' => 'yes',
					),
				),
				'user-exclusions-enable'    => array(
					'title'     => _x( 'Exclude users from the affiliate program', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'      => _x( 'Choose to exclude specific users or user roles from the affiliate program.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'default'   => 'no',
					'id'        => 'yith_wcaf_user_exclusions_enabled',
				),
				'excluded-users'            => array(
					'title'     => _x( 'Choose which users to exclude', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'      => _x( 'Choose which users to exclude from affiliates\' commissions.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'ajax-customers',
					'multiple'  => true,
					'data'      => array(
						'placeholder' => _x( 'Search users', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					),
					'id'        => 'yith_wcaf_excluded_users',
					'deps'      => array(
						'id'    => 'yith_wcaf_user_exclusions_enabled',
						'value' => 'yes',
					),
				),
				'excluded-roles'            => array(
					'title'       => _x( 'Choose which user roles to exclude', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'desc'        => _x( 'Choose which user roles to exclude from affiliates\' commissions.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'        => 'yith-field',
					'yith-type'   => 'select',
					'class'       => 'wc-enhanced-select',
					'multiple'    => true,
					'options'     => $user_roles,
					'placeholder' => _x( 'Search roles', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'          => 'yith_wcaf_excluded_user_roles',
					'deps'        => array(
						'id'    => 'yith_wcaf_user_exclusions_enabled',
						'value' => 'yes',
					),
				),
			);

			return yith_wcaf_append_items( $options, 'general-options', $exclusion_settings );
		}

		/**
		 * Filters general plugin options, and adds cookie options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_cookie_options( $options ) {
			$make_cookie_change_setting = array(
				'cookie-make-cookie-change' => array(
					'title'     => _x( 'Change referral cookie if another referral link is visited', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to change referral cookies when the user accesses the website through another referral link.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_make_cookie_change',
					'default'   => 'yes',
				),
			);

			$history_settings = array(
				'cookie-history-enable'        => array(
					'title'     => _x( 'Save cookies history', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to save the history of all referral cookie changes.<br/>You will see this history in the Order Details page, when a user purchases through a referral link.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_history_cookie_enable',
					'default'   => 'yes',
				),

				'cookie-history-name'          => array(
					'title'     => _x( 'Referral history cookie name', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'text',
					'[ADMIN] General settings page',
					'desc'      => _x( 'Enter a name to identify the cookie that will store referral tokens history.<br/>This name should be as unique as possible, to avoid collision with other plugins.<br/>If you change this setting, all the cookies created previously will no longer be valid.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_history_cookie_name',
					'default'   => 'yith_wcaf_referral_history',
					'deps'      => array(
						'id'    => 'yith_wcaf_history_cookie_enable',
						'value' => 'yes',
					),
				),

				'cookie-history-expire-needed' => array(
					'title'     => _x( 'Set an expiration for referral cookies history', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable if you want referral cookies history to expire after a specific time frame.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_history_make_cookie_expire',
					'default'   => 'yes',
					'deps'      => array(
						'id'    => 'yith_wcaf_history_cookie_enable',
						'value' => 'yes',
					),
				),

				'cookie-history-expiration'    => array(
					'title'             => _x( 'Referral cookies history expires after', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'custom',
					'action'            => 'yith_wcaf_print_admin_duration_field',
					'id'                => 'yith_wcaf_history_cookie_expire',
					'default'           => WEEK_IN_SECONDS,
					'custom_attributes' => array(
						'min'  => 1,
						'step' => 1,
					),
					'deps'              => array(
						'id'    => 'yith_wcaf_history_make_cookie_expire',
						'value' => 'yes',
					),
				),

				'cookie-delete-after-checkout' => array(
					'title'     => _x( 'Delete the plugin\'s cookies after checkout', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to delete the plugin\'s cookies after the customer processed a valid checkout.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_delete_cookie_after_checkout',
					'default'   => 'yes',
				),
			);

			return yith_wcaf_append_items( $options, 'cookie-referral-expiration', array_merge( $make_cookie_change_setting, $history_settings ) );
		}

		/**
		 * Filters general plugin options, and adds coupon options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_coupon_options( $options ) {
			$coupon_settings = array(
				'coupon-options'              => array(
					'title' => _x( 'Coupon Options', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'  => 'title',
					'desc'  => '',
					'id'    => 'yith_wcaf_coupon_options',
				),

				'coupon-enable'               => array(
					'title'     => _x( 'Assign coupons to affiliates', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'[ADMIN] General settings page',
					'desc'      => _x( 'If enabled, when a new coupon is created it can be assigned to a specific affiliate.<br/>The affiliate can use the coupon to promote your site, and each order that contains the coupon will generate a commission for him/her.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_coupon_enable',
					'default'   => 'no',
				),

				'coupon-show-section'         => array(
					'title'     => _x( 'Show Coupons section in the Affiliate Dashboard', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'radio',
					'desc'      => _x( 'Choose whether to show the Coupons section to all affiliates or only to those affiliates that have a coupon assigned to them.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_coupon_limit_section',
					'options'   => array(
						'no'  => _x( 'To all affiliates', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
						'yes' => _x( 'Only to affiliates with a coupon code assigned to them', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					),
					'default'   => 'yes',
					'deps'      => array(
						'id'    => 'yith_wcaf_coupon_enable',
						'value' => 'yes',
					),
				),

				'coupon-new-notify-affiliate' => array(
					'title'     => _x( 'Send an email to affiliates when they get a new coupon assigned', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to notify affiliates when a new coupon is created and assigned to their account.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_coupon_notify_affiliate',
					'default'   => 'no',
					'deps'      => array(
						'id'    => 'yith_wcaf_coupon_enable',
						'value' => 'yes',
					),
				),

				'coupon-options-end'          => array(
					'type' => 'sectionend',
					'id'   => 'yith_wcaf_coupon_options',
				),
			);

			return yith_wcaf_append_items( $options, 'cookie-options-end', $coupon_settings );
		}

		/**
		 * Filters general plugin options, and adds clicks options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_clicks_options( $options ) {
			$clicks_settings = array(
				'click-options'                => array(
					'title' => _x( 'Visits Log', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'  => 'title',
					'desc'  => '',
					'id'    => 'yith_wcaf_click_options',
				),

				'click-enabled'                => array(
					'title'     => _x( 'Register visits and visitors\' IP', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable visits registering. Visitors\' IP addresses will be registered in your database.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_click_enabled',
					'default'   => 'yes',
				),

				'click-resolution'             => array(
					'title'             => _x( 'If the same user visits the site with the same referral ID, count it as a new visit after', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'custom',
					'action'            => 'yith_wcaf_print_admin_duration_field',
					'id'                => 'yith_wcaf_click_resolution',
					'default'           => 60,
					'custom_attributes' => array(
						'min'  => 1,
						'step' => 1,
					),
					'deps'              => array(
						'id'    => 'yith_wcaf_click_enabled',
						'value' => 'yes',
					),
				),

				'click-auto-delete'            => array(
					'title'     => _x( 'Automatically delete visits log', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to automatically delete visits log after a specific time.', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_click_auto_delete',
					'default'   => 'no',
					'deps'      => array(
						'id'    => 'yith_wcaf_click_enabled',
						'value' => 'yes',
					),
				),

				'click-auto-delete-older-than' => array(
					'title'             => _x( 'Automatically delete visits log older than', '[ADMIN] General settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'custom',
					'action'            => 'yith_wcaf_print_admin_duration_field',
					'id'                => 'yith_wcaf_click_auto_delete_expiration',
					'default'           => 30 * DAY_IN_SECONDS,
					'custom_attributes' => array(
						'min'  => 1,
						'step' => 1,
					),
					'units'             => array(
						'day',
						'week',
						'month',
					),
					'deps'              => array(
						'id'    => 'yith_wcaf_click_auto_delete',
						'value' => 'yes',
					),
				),

				'click-options-end'            => array(
					'type' => 'sectionend',
					'id'   => 'yith_wcaf_click_options',
				),
			);

			return yith_wcaf_append_items( $options, 'coupon-options-end', $clicks_settings );
		}

		/**
		 * Filters registration plugin options, and adds additional general options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_general_registration_options( $options ) {
			$premium_options = array(
				'referral-registration-auto-enable'      => array(
					'title'     => _x( 'Automatically approve affiliates', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to automatically approve affiliates after registration.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_referral_registration_auto_enable',
					'default'   => 'yes',
				),

				'referral-registration-notify-admin'     => array(
					'title'     => _x( 'Send an email to the admin when', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'checkbox-array',
					'desc'      => _x( 'Choose what email notifications will be sent to the site\'s admin.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_referral_notify_admin',
					'options'   => array(
						'new'    => _x( 'A new affiliate registers on the site', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
						'change' => _x( 'An affiliate\'s account changes status', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
						'ban'    => _x( 'An affiliate\'s account is banned', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					),
					'default'   => array( 'new' ),
				),

				'referral-registration-notify-affiliate' => array(
					'title'     => _x( 'Send an email to an affiliate when', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'checkbox-array',
					'desc'      => _x( 'Choose what email notifications will be sent to affiliates.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_referral_notify_affiliate',
					'options'   => array(
						'new'    => _x( 'His/her account was registered successfully', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
						'change' => _x( 'His/her account changed status', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
						'ban'    => _x( 'His/her account was banned', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					),
					'default'   => array( 'new', 'change', 'ban' ),
				),
			);

			return yith_wcaf_append_items( $options, 'referral-registration-extra-options', $premium_options );
		}

		/**
		 * Filters registration plugin options, and adds additional global messages options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_global_messages_options( $options ) {
			$global_messages_options = array(
				'referral-enable-global-reject-message' => array(
					'title'     => _x( 'Set a default message for rejected affiliates', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to set a default message for all rejected affiliates. Disable if you want to send custom messages to users when they are rejected.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_enable_global_reject_message',
					'css'       => 'min-width: 300px;min-height: 100px;',
					'default'   => '',
				),

				'referral-global-reject-message'        => array(
					'title'     => _x( 'Default rejection message', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'textarea',
					'desc'      => _x( 'Enter a default message to explain to affiliates why they are being rejected.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_global_reject_message',
					'css'       => 'min-width: 300px;min-height: 100px;',
					'default'   => '',
					'deps'      => array(
						'id'    => 'yith_wcaf_enable_global_reject_message',
						'value' => 'yes',
					),
				),

				'referral-enable-global-ban-message'    => array(
					'title'     => _x( 'Set a default message for banned affiliates', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to set a default message for all banned affiliates. Disable if you want to send custom messages to users when they are banned.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_enable_global_ban_message',
					'css'       => 'min-width: 300px;min-height: 100px;',
					'default'   => '',
				),

				'referral-global-ban-message'           => array(
					'title'     => _x( 'Default ban message', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'textarea',
					'desc'      => _x( 'Enter a default message to explain to affiliates why they are being banned.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_global_ban_message',
					'css'       => 'min-width: 300px;min-height: 100px;',
					'default'   => '',
					'deps'      => array(
						'id'    => 'yith_wcaf_enable_global_ban_message',
						'value' => 'yes',
					),
				),
			);

			return yith_wcaf_append_items( $options, 'referral-registration-process-orphan-commissions', $global_messages_options );
		}

		/**
		 * Filters registration plugin options, and adds hidden dashboard sections options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_hidden_sections_options( $options ) {
			$hidden_options = array(
				'referral-enable-banned-hidden-sections' => array(
					'title'     => _x( 'Hide specific sections of the Affiliate Dashboard to banned users', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to hide specific sections of the Affiliate Dashboard to the banned affiliates.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_enable_ban_hidden_sections',
					'css'       => 'min-width: 300px;min-height: 100px;',
					'default'   => '',
				),

				'referral-banned-hidden-sections'        => array(
					'title'     => _x( 'Hide these sections to banned users', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'select',
					'multiple'  => true,
					'desc'      => _x( 'Select the Affiliate Dashboard\'s sections to hide.', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_ban_hidden_sections',
					'options'   => array_merge(
						array(
							'summary' => _x( 'Dashboard', '[ADMIN] Affiliate registration settings page', 'yith-woocommerce-affiliates' ),
						),
						YITH_WCAF_Dashboard::get_dashboard_endpoints()
					),
					'class'     => 'wc-enhanced-select',
					'default'   => '',
					'deps'      => array(
						'id'    => 'yith_wcaf_enable_ban_hidden_sections',
						'value' => 'yes',
					),
				),
			);

			return yith_wcaf_append_items( $options, 'referral-global-ban-message', $hidden_options );
		}

		/**
		 * Filters commissions plugin options, and adds Persistent Commissions options
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_commissions_options( $options ) {
			$persistent_commissions_options = array(
				'commission-persistent-calculation' => array(
					'title'     => _x( 'Calculate commissions permanently', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to register referral token in the customers\' account and credit commissions to the affiliate for any future customer purchase.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_commission_persistent_calculation',
					'default'   => 'no',
				),

				'commission-persistent-avoid-referral-change' => array(
					'title'     => _x( 'Prevent referral switch', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to prevent the persistent referral token registered for a customer from changing when a customer purchases on the store with a different referral token.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_avoid_referral_change',
					'default'   => 'no',
					'deps'      => array(
						'id'    => 'yith_wcaf_commission_persistent_calculation',
						'value' => 'yes',
					),
				),

				'commission-persistent-percentage'  => array(
					'title'             => _x( 'Persistent commissions rate', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'number',
					'desc'              => _x( 'Enter the rate that should be applied to commissions created by the persistent commissions system.<br/>Default commissions rate will be decreased (or increased, when values exceed 100) by the factor you specify here, and used to calculate actual commission.<br/>E.g. if your affiliate rate is 10% and you enter 90 here, the rate of permanent commissions will be 9% (90% of 10%).', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_persistent_rate',
					'css'               => 'max-width: 50px;',
					'default'           => 100,
					'custom_attributes' => array(
						'min'  => 0,
						'max'  => 500,
						'step' => 'any',
					),
					'deps'              => array(
						'id'    => 'yith_wcaf_commission_persistent_calculation',
						'value' => 'yes',
					),
				),
			);

			return yith_wcaf_append_items( $options, 'commission-exclude-discount', $persistent_commissions_options );
		}

		/**
		 * Filters commissions plugin options, and adds Notification option
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_notification_options( $options ) {
			$notification_options = array(
				'commission-pending-notify-admin' => array(
					'title'     => _x( 'Send an email to the admin when a commission changes status', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to notify the site\'s admin when a commission changes status.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_commission_pending_notify_admin',
					'default'   => 'yes',
				),
			);

			return yith_wcaf_append_items( $options, 'commission-persistent-percentage', $notification_options );
		}

		/**
		 * Filters commissions plugin options, and adds Payments option
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_payments_options( $options ) {
			$payment_options = array(
				'payment-options'                  => array(
					'title' => _x( 'Payment options', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'  => 'title',
					'desc'  => '',
					'id'    => 'yith_wcaf_payment_options',
				),

				'payment-type'                     => array(
					'title'     => _x( 'Payment type', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'select',
					'class'     => 'wc-enhanced-select',
					'desc'      => _x( 'Choose how commissions payment will be managed.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_payment_type',
					'options'   => array(
						'manually'                   => _x( 'Manually', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'automatically_on_threshold' => _x( 'Automatically when reaching a minimum threshold', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'automatically_on_date'      => _x( 'Automatically on a specific day of the month', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'automatically_on_both'      => _x( 'Automatically on a specific day of the month, if a minimum threshold is reached', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'automatically_every_day'    => _x( 'Automatically every day', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'let_user_request'           => _x( 'Let the user request the payment', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					),
					'default'   => 'manually',
				),

				'payment-default-gateway'          => array(
					'title'             => _x( 'Default gateway', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'select',
					'desc'              => _x( 'Choose a gateway to execute automatic payments.<br/>If you select "None", the payments will be registered but not issued to any gateway. To complete the payment, the admin has to manually select the gateway from the "Commission payments" tab.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_default_gateway',
					'options'           => array_merge(
						array(
							'note' => _x( 'None', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						),
						YITH_WCAF_Gateways::get_available_gateways_list()
					),
					'default'           => 'none',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_type' => array(
									'automatically_on_threshold',
									'automatically_on_date',
									'automatically_on_both',
									'automatically_every_day',
								),
							)
						),
					),
				),

				'payment-date'                     => array(
					'title'             => _x( 'Payment day', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'number',
					'desc'              => _x( 'Choose a day of the month to pay commissions to all affiliates.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_date',
					'css'               => 'max-width: 50px;',
					'default'           => 15,
					'custom_attributes' => array(
						'min'               => 1,
						'max'               => 28,
						'step'              => 1,
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_type' => array(
									'automatically_on_date',
									'automatically_on_both',
								),
							)
						),
					),
				),

				'payment-threshold'                => array(
					'title'             => _x( 'Payment threshold', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'number',
					'desc'              => _x( 'Choose the minimum amount that an affiliate must earn to allow a payment to be issued.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_threshold',
					'css'               => 'min-width: 50px;',
					'custom_attributes' => array(
						'min'               => 0,
						'step'              => 'any',
						'data-postfix'      => get_woocommerce_currency_symbol(),
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_type' => array(
									'automatically_on_threshold',
									'automatically_on_both',
									'let_user_request',
								),
							)
						),
					),
					'default'           => '50',
				),

				'payment-pay-only-old-commissions' => array(
					'title'             => _x( 'Only pay commissions older than', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'onoff',
					'desc'              => _x( 'Enable if you want to automatically pay only those commissions that are older than a specific number of days.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_pay_only_old_commissions',
					'default'           => 'no',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_type' => array(
									'automatically_on_threshold',
									'automatically_on_date',
									'automatically_on_both',
									'automatically_every_day',
									'let_user_request',
								),
							)
						),
					),
				),

				'payment-commission-age'           => array(
					'title'             => _x( 'Commissions\' days old', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'number',
					'desc'              => _x( 'Choose the minimum number of days that should pass since the commission\'s creation to allow it to be automatically paid.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_commission_age',
					'css'               => 'max-width: 50px;',
					'default'           => 15,
					'custom_attributes' => array(
						'min'               => 1,
						'step'              => 1,
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_pay_only_old_commissions' => 'yes',
							)
						),
					),
				),

				'payment-pending-notify-admin'     => array(
					'title'     => _x( 'Send an email to the admin when a payment is issued', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'      => 'yith-field',
					'yith-type' => 'onoff',
					'desc'      => _x( 'Enable to notify the site\'s admin when a payment is issued.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'        => 'yith_wcaf_payment_pending_notify_admin',
					'default'   => 'yes',
				),

				'payment-options-end'              => array(
					'type' => 'sectionend',
					'id'   => 'yith_wcaf_payment_options',
				),
			);

			return yith_wcaf_append_items( $options, 'commission-options-end', $payment_options );
		}


		/**
		 * Filters commissions plugin options, and adds Invoice option
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_invoice_options( $options ) {
			$invoice_options = array(
				'invoice-options'                   => array(
					'title' => _x( 'Invoice options', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'  => 'title',
					'desc'  => '',
					'id'    => 'yith_wcaf_invoice_options',
				),

				'payment-require-invoice'           => array(
					'title'             => _x( 'Require invoice', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'onoff',
					'desc'              => _x( 'Enable if you want to require an invoice from your affiliates for any withdrawal request.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_require_invoice',
					'default'           => 'yes',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_type' => 'let_user_request',
							)
						),
					),
				),

				'payment-invoice-mode'              => array(
					'title'             => _x( 'Choose how affiliates will generate invoices', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'radio',
					'desc'              => _x( 'Choose how affiliates should submit their invoices when requesting a withdrawal.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_mode',
					'options'           => array(
						'upload'   => _x( 'Let users upload their custom invoices', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'generate' => _x( 'Generate invoices from customers data', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
						'both'     => _x( 'Let users choose their preferred method', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					),
					'default'           => 'both',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_require_invoice' => 'yes',
							)
						),
					),
				),

				'payment-invoice-example'           => array(
					'title'             => _x( 'Invoice example', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'upload',
					'desc'              => _x( 'Choose a file that will be shown to the affiliates as an example of the invoice to generate.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_example',
					'default'           => '',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_mode' => array(
									'upload',
									'both',
								),
							)
						),
					),
				),

				'payment-invoice-company-section'   => array(
					'title'             => _x( 'Company details', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'textarea',
					'desc'              => _x( 'Enter the details about your company that the affiliates should add to their invoices\' headings.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_company_section',
					'default'           => '',
					'css'               => 'min-width: 300px; min-height: 100px;',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_mode' => array(
									'upload',
									'both',
								),
							)
						),
					),
				),

				'payment-invoice-fields'            => array(
					'title'             => _x( 'Invoice\'s fields', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'select',
					'multiple'          => true,
					'desc'              => _x( 'Choose the fields that should be filled by the affiliates in order to collect information for invoice generation.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_fields',
					'options'           => YITH_WCAF_Affiliates_Invoice_Profile::get_available_billing_fields(),
					'default'           => array( 'first_name', 'last_name', 'address', 'city', 'vat' ),
					'class'             => 'wc-enhanced-select',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_mode' => array(
									'generate',
									'both',
								),
							)
						),
					),
				),

				'payment-invoice-show-terms-field'  => array(
					'title'             => _x( 'Show Terms & Conditions field', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'onoff',
					'desc'              => _x( 'Enable to show the "Terms & Conditions" checkbox in the Withdrawal Form.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_show_terms_field',
					'default'           => 'no',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_require_invoice' => 'yes',
							)
						),
					),
				),

				'payment-invoice-terms-label'       => array(
					'title'             => _x( 'Terms & Conditions label', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'text',
					'desc'              => _x( 'Label for the Terms & Conditions checkbox in the Withdrawal Form.<br/>Use the <code>%TERMS%</code> placeholder to include a link to the Terms & Conditions page.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_terms_label',
					'default'           => _x( 'Please, read and accept our %TERMS%', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_show_terms_field' => 'yes',
							)
						),
					),
				),

				'payment-invoice-terms-anchor-url'  => array(
					'title'             => _x( 'Terms & Conditions URL', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'text',
					'desc'              => _x( 'Enter the URL of the Terms & Conditions page for the link that will be used in the Withdrawal Form.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_terms_anchor_url',
					'default'           => '',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_show_terms_field' => 'yes',
							)
						),
					),
				),

				'payment-invoice-terms-anchor-text' => array(
					'title'             => _x( 'Terms & Conditions text', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'text',
					'desc'              => _x( 'Enter the text to use as a label for the Terms & Conditions link in the Withdrawal Form.', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'id'                => 'yith_wcaf_payment_invoice_terms_anchor_text',
					'default'           => '',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_show_terms_field' => 'yes',
							)
						),
					),
				),

				'payment-invoice-template'          => array(
					'title'             => _x( 'Invoice template', '[ADMIN] Commissions/Payments settings page', 'yith-woocommerce-affiliates' ),
					'type'              => 'yith-field',
					'yith-type'         => 'custom',
					'action'            => 'yith_wcaf_print_admin_template_field',
					'id'                => 'yith_wcaf_payment_invoice_template',
					'template'          => 'invoices/affiliate-invoice.php',
					'custom_attributes' => array(
						'data-dependencies' => wp_json_encode(
							array(
								'yith_wcaf_payment_invoice_mode' => array(
									'generate',
									'both',
								),
							)
						),
					),
				),

				'invoice-options-end'               => array(
					'type' => 'sectionend',
					'id'   => 'yith_wcaf_invoice_options',
				),
			);

			return yith_wcaf_append_items( $options, 'payment-options-end', $invoice_options );
		}

		/**
		 * Filters commissions plugin options, and adds Gateways option
		 *
		 * @param array $options Array of existing options.
		 *
		 * @return array Filtered array of options.
		 */
		protected function add_gateways_options( $options ) {
			$payment_options = array(
				'gateways-options'     => array(
					'title' => _x( 'Payment Gateways', '[ADMIN] Title for gateways table, in Commissions/Payments tab', 'yith-woocommerce-affiliates' ),
					'type'  => 'title',
					'desc'  => '',
					'id'    => 'yith_wcaf_gateways_options',
				),

				'gateways-table'       => array(
					'name'                 => _x( 'Payment Gateways', '[ADMIN] Title for gateways table, in Commissions/Payments tab', 'yith-woocommerce-affiliates' ),
					'type'                 => 'yith-field',
					'yith-type'            => 'list-table',
					'class'                => '',
					'list_table_class'     => 'YITH_WCAF_Gateways_Admin_Table',
					'list_table_class_dir' => YITH_WCAF_INC . 'admin/admin-tables/class-yith-wcaf-gateways-table.php',
					'id'                   => 'yith_wcaf_gateways',
				),

				'gateways-options-end' => array(
					'type' => 'sectionend',
					'id'   => 'yith_wcaf_gateways_options',
				),
			);

			return yith_wcaf_append_items( $options, 'invoice-options-end', $payment_options );
		}

		/* === ADMIN ACTIONS === */

		/**
		 * Saves template customized using Template custom fields
		 * Note: this is not managed by \YITH_WCAF_Admin_Actions class, but it is hooked to WC default filter.
		 *
		 * @param mixed $value  Value of the option being saved (in case of template fields, it will just be null).
		 * @param array $option Array that described option being saved.
		 *
		 * @return mixed Returns null for template fields, original values otherwise.
		 */
		public function save_template( $value, $option ) {
			if ( ! isset( $option['action'] ) || 'yith_wcaf_print_admin_template_field' !== $option['action'] ) {
				return $value;
			}

			if ( ! isset( $_POST['yit_panel_wc_options_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yit_panel_wc_options_nonce'] ) ), 'yit_panel_wc_options_' . YITH_WCAF_Admin()->get_panel_slug() ) ) {
				return null;
			}

			if ( ! current_user_can( 'edit_themes' ) ) {
				return null;
			}

			$template = isset( $option['template'] ) ? $option['template'] : '';
			$name     = sanitize_title( $template ) . '_code';

			// any content is allowed here, given that we're dealing whit a template file.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$code = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : false;

			if ( ! empty( $code ) ) {
				YITH_WCAF_Admin_Templates::save( $code, $template );
			}

			return null;
		}

		/**
		 * Moves template after custom field action
		 *
		 * @return array Array of parameters to be added to return url.
		 */
		public function move_template() {
			// nonce verification is performed by \YITH_WCAF_Admin_Actions::process_action.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$template = isset( $_REQUEST['template'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['template'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( ! $template || ! current_user_can( 'edit_themes' ) ) {
				return array();
			}

			YITH_WCAF_Admin_Templates::copy_to_theme( $template );

			return array();
		}

		/**
		 * Deletes template after custom field action
		 *
		 * @return array Array of parameters to be added to return url.
		 */
		public function delete_template() {
			// nonce verification is performed by \YITH_WCAF_Admin_Actions::process_action.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$template = isset( $_REQUEST['template'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['template'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			if ( ! $template || ! current_user_can( 'edit_themes' ) ) {
				return array();
			}

			YITH_WCAF_Admin_Templates::remove_from_theme( $template );

			return array();
		}

		/* === CUSTOM FIELDS === */

		/**
		 * Print template type field
		 *
		 * @param array $field Array of options for the field to print.
		 *
		 * @return void
		 * @since 1.3.0
		 */
		public function print_template_field( $field ) {
			if ( ! isset( $field['template'] ) ) {
				return;
			}

			list( $template, $custom_attributes ) = yith_plugin_fw_extract( $field, 'template', 'custom_attributes' );

			include YITH_WCAF_DIR . 'views/settings/types/template-field.php';
		}

		/* === PANEL HANDLING === */

		/**
		 * Render template for "Add affiliate" modal
		 *
		 * @param string $option_key Current option key (used to distinguish among different settings sub-tabs).
		 *
		 * @return void.
		 */
		public function render_add_field_modal( $option_key ) {
			if ( 'settings-affiliates-registration' !== $option_key ) {
				return;
			}

			include YITH_WCAF_DIR . 'views/settings/add-field-modal.php';
		}

		/**
		 * Returns variable to localize for current panel
		 *
		 * @return array Array of variables to localize.
		 */
		public function get_localize() {
			return array_merge_recursive(
				parent::get_localize(),
				array(
					'labels' => array(
						'add_field_modal_title'   => _x( 'Add field', '[ADMIN] Add field modal', 'yith-woocommerce-affiliates' ),
						'edit_field_modal_title'  => _x( 'Edit field', '[ADMIN] Add field modal', 'yith-woocommerce-affiliates' ),
						'generic_save_button'     => _x( 'Save', '[ADMIN] Edit gateway modal', 'yith-woocommerce-affiliates' ),
						'generic_confirm_title'   => _x( 'Confirm', '[ADMIN] Confirm modal', 'yith-woocommerce-affiliates' ),
						'generic_confirm_message' => _x( 'This operation cannot be undone. Are you sure you want to proceed?', '[ADMIN] Confirm modal', 'yith-woocommerce-affiliates' ),
					),
					'nonces' => array(
						'save_profile_field'     => wp_create_nonce( 'save_profile_field' ),
						'delete_profile_field'   => wp_create_nonce( 'delete_profile_field' ),
						'clone_profile_field'    => wp_create_nonce( 'clone_profile_field' ),
						'sort_profile_fields'    => wp_create_nonce( 'sort_profile_fields' ),
						'restore_profile_fields' => wp_create_nonce( 'restore_profile_fields' ),
					),
				)
			);
		}

		/**
		 * Tab doesn't need custom saving
		 */
		public function save() {
			// do nothing.
		}
	}
}
