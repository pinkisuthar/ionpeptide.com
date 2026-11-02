<?php
/**
 * Installation related functions and actions.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates
 * @version 2.0.0
 */

defined( 'YITH_WCAF' ) || exit;

if ( ! class_exists( 'YITH_WCAF_Install_Premium' ) ) {
	/**
	 * Install class
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Install_Premium extends YITH_WCAF_Install {

		/**
		 * Install plugin and perform upgrades
		 *
		 * @return void
		 */
		public static function init() {
			// run plugin install.
			parent::init();

			// install folders.
			self::maybe_install_folders();
		}

		/* === INSTALL METHODS === */

		/**
		 * Install folders and protect them
		 *
		 * @return void
		 * @since 1.3.0
		 */
		protected static function maybe_install_folders() {
			if ( file_exists( YITH_WCAF_INVOICES_DIR ) ) {
				return;
			}

			self::install_folders();
		}

		/**
		 * Install folders and protect them
		 *
		 * @return void
		 * @since 1.3.0
		 */
		protected static function install_folders() {
			$files = array(
				array(
					'base'    => YITH_WCAF_INVOICES_DIR,
					'file'    => 'index.html',
					'content' => '',
				),
				array(
					'base'    => YITH_WCAF_INVOICES_DIR,
					'file'    => '.htaccess',
					'content' => 'deny from all',
				),
			);

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
				return;
			}

			global $wp_filesystem;

			foreach ( $files as $file ) {
				$file_path = trailingslashit( $file['base'] ) . $file['file'];

				if ( wp_mkdir_p( $file['base'] ) && ! file_exists( $file_path ) ) {
					$wp_filesystem->put_contents( $file_path, $file['content'] );
				}
			}
		}

		/* === UPGRADE METHODS === */

		/**
		 * Upgrade options, as required for DB version 2.0.0
		 *
		 * @return void
		 */
		protected static function do_200_options_upgrade() {
			// cal parent.
			parent::do_200_options_upgrade();

			// upgrade paypal options.
			self::do_200_paypal_options_upgrade();

			// upgrade rates rules.
			self::do_200_rate_rules_upgrade();

			// upgrade exclusions, if needed.
			$excluded_products = get_option( 'yith_wcaf_exclusions_excluded_products', array() );

			if ( $excluded_products ) {
				update_option( 'yith_wcaf_product_exclusions_enabled', 'yes' );
				update_option( 'yith_wcaf_excluded_products', $excluded_products );
			}

			$excluded_users = get_option( 'yith_wcaf_exclusions_excluded_users', array() );

			if ( $excluded_users ) {
				update_option( 'yith_wcaf_user_exclusions_enabled', 'yes' );
				update_option( 'yith_wcaf_excluded_users', $excluded_users );
			}

			// upgrade coupons, if needed.
			$show_coupon_section = get_option( 'yith_wcaf_coupon_show_section' );

			if ( 'no' === $show_coupon_section ) {
				update_option( 'yith_wcaf_coupon_limit_section', 'yes' );
			}

			// upgrade notify options.
			$notify_admin_registration = get_option( 'yith_wcaf_referral_registration_notify_admin', 'yes' );
			$notify_affiliate_ban      = get_option( 'yith_wcaf_referral_registration_notify_affiliates_ban', 'yes' );
			$notify_affiliate_update   = get_option( 'yith_wcaf_referral_registration_notify_affiliates', 'yes' );

			update_option( 'yith_wcaf_referral_notify_admin', 'yes' === $notify_admin_registration ? array( 'new' ) : array() );
			update_option(
				'yith_wcaf_referral_notify_affiliate',
				array_merge(
					'yes' === $notify_affiliate_update ? array( 'change' ) : array(),
					'yes' === $notify_affiliate_ban ? array( 'ban' ) : array()
				)
			);

			// upgrade global messages.
			$global_reject_message = get_option( 'yith_wcaf_ban_reject_global_message', '' );
			$global_ban_message    = get_option( 'yith_wcaf_ban_global_message', '' );

			update_option( 'yith_wcaf_global_reject_message', $global_reject_message );
			update_option( 'yith_wcaf_enable_global_reject_message', $global_reject_message ? 'yes' : 'no' );
			update_option( 'yith_wcaf_global_ban_message', $global_ban_message );
			update_option( 'yith_wcaf_enable_global_ban_message', $global_ban_message ? 'yes' : 'no' );

			// upgrade hidden sections options.
			$hidden_sections = get_option( 'yith_wcaf_ban_hidden_sections', array() );

			update_option( 'yith_wcaf_enable_ban_hidden_sections', $hidden_sections ? 'yes' : 'no' );

			// upgrade token origin option.
			$origin = get_option( 'yith_wcaf_general_referral_cod', 'query_string' );

			if ( 'checkout' === $origin ) {
				update_option( 'yith_wcaf_show_checkout_box', 'yes' );
			}

			// upgrade clicks cron threshold.
			$expiration = (int) get_option( 'yith_wcaf_click_auto_delete_expiration', 30 );

			update_option(
				'yith_wcaf_click_auto_delete_expiration',
				array(
					'amount' => $expiration,
					'unit'   => 'day',
				)
			);

			// upgrade email options.
			$new_affiliate      = get_option( 'woocommerce_new_affiliate_settings', array() );
			$pending_commission = get_option( 'woocommerce_pending_commission_settings', array() );
			$affiliate_ban      = get_option( 'woocommerce_affiliate_ban_settings', array() );

			update_option( 'woocommerce_admin_new_affiliate_settings', $new_affiliate );
			update_option( 'woocommerce_admin_commission_status_changed_settings', $pending_commission );
			update_option( 'woocommerce_admin_affiliate_banned_settings', $affiliate_ban );
		}

		/**
		 * Create new option to store profile fields, starting from existing one.
		 *
		 * @return void.
		 */
		protected static function do_200_profile_fields_upgrade() {
			// first ao all, call parent.
			parent::do_200_profile_fields_upgrade();

			$show_fields_on_settings            = get_option( 'yith_wcaf_referral_show_fields_on_settings', 'no' );
			$show_fields_on_become_an_affiliate = get_option( 'yith_wcaf_referral_show_fields_on_become_an_affiliate', 'no' );
			$show_website_field                 = get_option( 'yith_wcaf_referral_registration_show_website_field', 'no' );
			$show_promotional_methods_field     = get_option( 'yith_wcaf_referral_registration_show_promotional_methods_field', 'no' );
			$fields_structure                   = get_option( 'yith_wcaf_affiliate_profile_fields', array() );

			$show_in = array(
				'settings'            => 'yes' === $show_fields_on_settings,
				'become_an_affiliate' => 'yes' === $show_fields_on_become_an_affiliate,
			);

			// set promote option in the expected format.
			$promote_options = array();

			foreach ( yith_wcaf_get_promote_methods() as $value => $label ) {
				$promote_options[] = compact( 'value', 'label' );
			}

			$fields_structure = yith_wcaf_append_items(
				array_combine( wp_list_pluck( $fields_structure, 'name' ), $fields_structure ),
				'privacy_text',
				array(
					'website'        => array(
						'name'     => 'website',
						'label'    => _x( 'Website', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'type'     => 'text',
						'enabled'  => 'yes' === $show_website_field,
						'required' => true,
						'show_in'  => $show_in,
					),
					'how_promote'    => array(
						'name'     => 'how_promote',
						'label'    => _x( 'How will you promote our site?', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'type'     => 'select',
						'enabled'  => 'yes' === $show_promotional_methods_field,
						'required' => true,
						'show_in'  => $show_in,
						'options'  => $promote_options,
					),
					'custom_promote' => array(
						'name'     => 'custom_promote',
						'label'    => _x( 'Specify how you will promote our site', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'type'     => 'textarea',
						'enabled'  => 'yes' === $show_promotional_methods_field,
						'required' => false,
						'show_in'  => $show_in,
					),
				),
				'before'
			);

			$fields_structure = array_values( $fields_structure );

			update_option( 'yith_wcaf_affiliate_profile_fields', $fields_structure );
			update_option( 'yith_wcaf_affiliate_profile_fields_defaults', $fields_structure );
		}

		/**
		 * Read old PayPal settings and registers them with new single-option system
		 *
		 * @return void
		 */
		protected static function do_200_paypal_options_upgrade() {
			$enabled        = 'yes'; // enable gateway by default.
			$enable_sandbox = get_option( 'yith_wcaf_paypal_enable_sandbox', 'no' );
			$enable_log     = get_option( 'yith_wcaf_paypal_enable_log', 'yes' );
			$api_username   = get_option( 'yith_wcaf_paypal_api_username', '' );
			$api_password   = get_option( 'yith_wcaf_paypal_api_password', '' );
			$api_signature  = get_option( 'yith_wcaf_paypal_api_signature', '' );
			$email_subject  = get_option( 'yith_wcaf_paypal_email_subject', '' );

			update_option( 'yith_wcaf_paypal_gateway_settings', compact( 'enabled', 'enable_sandbox', 'enable_log', 'api_username', 'api_password', 'api_signature', 'email_subject' ) );
		}

		/**
		 * Upgrades old rules system to new one
		 *
		 * @return void
		 */
		protected static function do_200_rate_rules_upgrade() {
			$product_rules   = get_option( 'yith_wcaf_product_rates', array() );
			$affiliate_rules = YITH_WCAF_Affiliate_Factory::get_affiliates( array( 'rate' => 'NOT NULL' ) );

			if ( ! empty( $product_rules ) ) {
				foreach ( $product_rules as $product_id => $product_rate ) {
					$product = wc_get_product( $product_id );

					if ( ! $product ) {
						continue;
					}

					$existing_rules = YITH_WCAF_Rate_Rule_Factory::get_rules( array( 'product_id' => $product_id ) );

					if ( $existing_rules && ! $existing_rules->is_empty() ) {
						continue;
					}

					$rule = YITH_WCAF_Rate_Rule_Factory::create_rule();

					// translators: 1. Product or Affiliate formatted name.
					$rule->set_name( sprintf( _x( 'Rule for: %s', '[ADMIN] Default rate rule name', 'yith-woocommerce-affiliates' ), $product->get_formatted_name() ) );
					$rule->add_product_id( $product_id );
					$rule->set_rate( $product_rate );
					$rule->set_type( 'product_ids' );

					$rule->save();
				}
			}

			if ( ! empty( $affiliate_rules ) ) {
				foreach ( $affiliate_rules as $affiliate ) {
					$existing_rules = YITH_WCAF_Rate_Rule_Factory::get_rules( array( 'affiliate_id' => $affiliate->get_id() ) );

					if ( $existing_rules && ! $existing_rules->is_empty() ) {
						continue;
					}

					$rule = YITH_WCAF_Rate_Rule_Factory::create_rule();

					// translators: 1. Product or Affiliate formatted name.
					$rule->set_name( sprintf( _x( 'Rule for: %s', '[ADMIN] Default rate rule name', 'yith-woocommerce-affiliates' ), $affiliate->get_formatted_name() ) );
					$rule->add_affiliate_id( $affiliate->get_id() );
					$rule->set_rate( $affiliate->get_rate() );
					$rule->set_type( 'affiliate_ids' );

					$rule->save();
				}
			}
		}

	}
}
