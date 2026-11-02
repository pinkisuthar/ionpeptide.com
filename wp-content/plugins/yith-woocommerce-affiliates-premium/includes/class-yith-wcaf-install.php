<?php
/**
 * Installation related functions and actions.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates
 * @version 2.0.0
 */

defined( 'YITH_WCAF' ) || exit;

if ( ! class_exists( 'YITH_WCAF_Install' ) ) {
	/**
	 * Install class
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Install {

		/**
		 * List of data stores defined by the plugin
		 *
		 * @var array
		 */
		private static $stores = array(
			'affiliate'        => 'YITH_WCAF_Affiliate_Data_Store',
			'commission'       => 'YITH_WCAF_Commission_Data_Store',
			'click'            => 'YITH_WCAF_Click_Data_Store',
			'payment'          => 'YITH_WCAF_Payment_Data_Store',
			'rate_rule'        => 'YITH_WCAF_Rate_Rule_Data_Store',
			'affiliate_coupon' => 'YITH_WCAF_Affiliate_Coupon_Data_Store',
		);

		/**
		 * Install plugin and perform upgrades
		 *
		 * @return void
		 */
		public static function init() {
			// install data stores.
			self::install_data_stores();

			// install database.
			self::install_tables();

			// install role.
			self::install_role();

			// install pages.
			self::maybe_install_pages();

			// do upgrades.
			self::maybe_upgrade();

			// register scheduled actions.
			add_action( 'yith_wcaf_register_commission_products', array( self::class, 'register_commission_products' ), 10, 1 );
			add_action( 'yith_wcaf_register_commission_totals', array( self::class, 'register_commission_totals' ), 10, 1 );
			add_action( 'yith_wcaf_add_affiliates_role', array( self::class, 'add_affiliates_role' ), 10, 1 );
		}

		/**
		 * Add plugin's data stores to list of available ones
		 *
		 * @param array $data_stores Available Data Stores.
		 *
		 * @return array Filtered array of Data Stores
		 */
		public static function add_data_stores( $data_stores ) {
			$data_stores = array_merge(
				$data_stores,
				self::$stores
			);

			return $data_stores;
		}

		/**
		 * Translate roles using the plugin textdomain.
		 *
		 * @param string $translation  Translated text.
		 * @param string $text         Text to translate.
		 * @param string $context      Context information for the translators.
		 * @param string $domain       Text domain. Unique identifier for retrieving translated strings.
		 * @return string
		 */
		public static function translate_user_roles( $translation, $text, $context, $domain ) {
			if ( 'User role' === $context && 'default' === $domain && 'Affiliate' === $text ) {
				return translate_user_role( $text, 'yith-woocommerce-affiliates' );
			}

			return $translation;
		}

		/* === SCHEDULED ACTIONS === */

		/**
		 * Performs update of commission records, registering product name and id
		 * If another step is required, it will schedule a new run.
		 *
		 * @param int $offset Offset for the query (method is executed with increasing numbers).
		 *
		 * @return bool Status of the operation
		 */
		public static function register_commission_products( $offset ) {
			$per_step = 10;

			try {
				$data_store = WC_Data_Store::load( 'commission' );
			} catch ( Exception $e ) {
				return false;
			}

			/**
			 * Retrieves a commission collection
			 *
			 * @var $commissions YITH_WCAF_Commissions_Collection
			 */
			$commissions = $data_store->query(
				array(
					'limit'  => $per_step,
					'offset' => $offset,
				)
			);

			if ( $commissions->is_empty() ) {
				return false;
			}

			foreach ( $commissions as $commission ) {
				$item = $commission->get_order_item();

				if ( ! $item ) {
					continue;
				}

				/**
				 * Order item object
				 *
				 * @var $item WC_Order_Item_Product
				 */
				$variation_id = $item->get_variation_id();
				$product_id   = $item->get_product_id();

				$commission->set_product_name( $item->get_name() );
				$commission->set_product_id( $variation_id ? $variation_id : $product_id );
				$commission->save();
			}

			$offset += $per_step;

			// register next step.
			return WC()->queue()->add(
				'yith_wcaf_register_commission_products',
				array(
					'offset' => $offset,
				),
				'yith-wcaf-db-updates'
			);
		}

		/**
		 * Performs update of commission records, registering line_total
		 * If another step is required, it will schedule a new run.
		 *
		 * @param int $offset Offset for the query (method is executed with increasing numbers).
		 *
		 * @return bool Status of the operation
		 */
		public static function register_commission_totals( $offset ) {
			$per_step = 10;

			try {
				$data_store = WC_Data_Store::load( 'commission' );
			} catch ( Exception $e ) {
				return false;
			}

			/**
			 * Retrieves a commission collection
			 *
			 * @var $commissions YITH_WCAF_Commissions_Collection
			 */
			$commissions = $data_store->query(
				array(
					'limit'  => $per_step,
					'offset' => $offset,
				)
			);

			if ( $commissions->is_empty() ) {
				return false;
			}

			foreach ( $commissions as $commission ) {
				/**
				 * Order item object
				 *
				 * @var $item WC_Order_Item_Product
				 */
				$order = $commission->get_order();
				$item  = $commission->get_order_item();

				if ( ! $order || ! $item ) {
					continue;
				}

				// performs double check to avoid setting total twice.
				$item_commissions = $data_store->query(
					array(
						'line_item_id' => $item->get_id(),
						'exclude'      => $commission->get_id(),
					)
				);

				if ( ! $item_commissions->is_empty() ) {
					foreach ( $item_commissions as $item_commission ) {
						if ( $item_commission->get_id() !== $commission->get_id() && ! ! $item_commission->get_line_total() ) {
							continue 2;
						}
					}
				}

				// if everything's ok, set total, save, and move to next instance.
				$commission->set_line_total( YITH_WCAF_Orders()->get_line_item_total( $order, $item ) );
				$commission->save();
			}

			$offset += $per_step;

			// register next step.
			return WC()->queue()->add(
				'yith_wcaf_register_commission_totals',
				array(
					'offset' => $offset,
				),
				'yith-wcaf-db-updates'
			);
		}

		/**
		 * Add 'Affiliate' role to any user that is registered as an affiliate of the shop
		 * If another step is required, it will schedule a new run.
		 *
		 * @param int $offset Offset for the query (method is executed with increasing numbers).
		 *
		 * @return bool Status of the operation
		 */
		public static function add_affiliates_role( $offset ) {
			$per_step = 10;

			try {
				$data_store = WC_Data_Store::load( 'affiliate' );
			} catch ( Exception $e ) {
				return false;
			}

			$affiliates = $data_store->query(
				array(
					'limit'  => $per_step,
					'offset' => $offset,
				)
			);

			if ( $affiliates->is_empty() ) {
				return false;
			}

			$role = YITH_WCAF_Affiliates::get_role();

			foreach ( $affiliates as $affiliate ) {
				$user = $affiliate->get_user();

				if ( $user ) {
					$user->add_role( $role );
				}
			}

			$offset += $per_step;

			// register next step.
			return WC()->queue()->add(
				'yith_wcaf_add_affiliates_role',
				array(
					'offset' => $offset,
				),
				'yith-wcaf-db-updates'
			);
		}

		/* === INSTALL METHODS === */

		/**
		 * Create plugin tables
		 *
		 * @return void
		 * @since 1.0.0
		 */
		protected static function install_tables() {
			// skip if current db version is equal to plugin db version.
			$current_db_version = get_option( 'yith_wcaf_db_version' );

			if ( version_compare( $current_db_version, YITH_WCAF::DB_VERSION, '>=' ) ) {
				return;
			}

			// assure dbDelta function is defined.
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			$definitions = '';

			foreach ( self::$stores as $store => $store_class ) {
				try {
					if ( class_exists( $store_class ) && ! in_array( 'YITH_WCAF_Trait_DB_Object', class_uses( $store_class ), true ) ) {
						continue;
					}

					$data_store   = WC_Data_Store::load( $store );
					$definitions .= $data_store->get_table_structure();
				} catch ( Exception $e ) {
					continue;
				}
			}

			! empty( $definitions ) && dbDelta( $definitions );

			update_option( '_yith_wcaf_flush_rewrite_rules', true );
			update_option( 'yith_wcaf_db_version', YITH_WCAF::DB_VERSION );
		}

		/**
		 * Install data stores for the plugin
		 *
		 * @return void.
		 */
		protected static function install_data_stores() {
			add_filter( 'woocommerce_data_stores', array( self::class, 'add_data_stores' ) );
		}

		/**
		 * Install affiliate dashboard page, if it doesn't exists; return created page ID
		 *
		 * @return int Page ID, or false on failure.
		 * @since 1.0.0
		 */
		protected static function maybe_install_pages() {
			if ( ! empty( get_option( 'yith_wcaf_version' ) ) ) {
				return false;
			}

			return self::install_pages();
		}

		/**
		 * Install affiliate dashboard page, if it doesn't exists; return created page ID
		 *
		 * @return int Page ID, or false on failure
		 * @since 1.0.0
		 */
		protected static function install_pages() {
			include_once WC()->plugin_path() . '/includes/admin/wc-admin-functions.php';

			if ( ! function_exists( 'wc_create_page' ) ) {
				return false;
			}

			$page = array(
				'name'    => 'affiliate-dashboard',
				'title'   => _x( 'Affiliate Dashboard', '[GLOBAL] Dashboard page title', 'yith-woocommerce-affiliates' ),
				'content' => '<!-- wp:shortcode -->[yith_wcaf_affiliate_dashboard]<!-- /wp:shortcode -->',
			);

			return wc_create_page( esc_sql( $page['name'] ), 'yith_wcaf_dashboard_page_id', $page['title'], $page['content'] );
		}

		/**
		 * Register role for affiliates users
		 *
		 * @return void
		 * @since 1.2.0
		 */
		protected static function install_role() {
			$role = YITH_WCAF_Affiliates::get_role();

			// register user role translations.
			add_filter( 'gettext_with_context', array( self::class, 'translate_user_roles' ), 10, 4 );

			// Dummy gettext calls to get strings in the catalog.
			_x( 'Affiliate', 'User role', 'yith-woocommerce-affiliates' );

			// install new role.
			add_role(
				$role,
				'Affiliate',
				array(
					'read' => true,
				)
			);

			// if not done yet, assign role to affiliates.
			if ( get_option( 'yith_wcaf_add_role_to_affiliates', false ) ) {
				return;
			}

			WC()->queue()->schedule_single(
				time() + 10,
				'yith_wcaf_add_affiliates_role',
				array(
					'offset' => 0,
				),
				'yith-wcaf-db-updates'
			);

			update_option( 'yith_wcaf_add_role_to_affiliates', true );
		}

		/* === UPGRADE METHODS === */

		/**
		 * Trigger upgrade method, when required
		 *
		 * @return void
		 */
		public static function maybe_upgrade() {
			$current_version = get_option( 'yith_wcaf_version' );

			if ( version_compare( $current_version, YITH_WCAF::VERSION, '>=' ) ) {
				return;
			}

			static::upgrade();
		}

		/**
		 * Performs any required upgrade
		 *
		 * @return void
		 */
		public static function upgrade() {
			$current_version = get_option( 'yith_wcaf_version' );

			// update < 2.0.0 version plugins.
			if ( version_compare( $current_version, '2.0.0', '<' ) ) {
				static::do_200_upgrade();
			}

			// allow third party code execute upgrade methods.
			do_action( 'yith_wcaf_upgrade_' . str_replace( '.', '', YITH_WCAF::VERSION ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

			/**
			 * DO_ACTION: yith_wcaf_upgrade
			 *
			 * Allows to perform any required upgrade.
			 */
			do_action( 'yith_wcaf_upgrade' );

			// finally, store new version.
			update_option( 'yith_wcaf_version', YITH_WCAF::VERSION );
		}

		/**
		 * Performs all required operations to update version earlier than 2.0.0 to most recent db structure
		 *
		 * @return void
		 */
		protected static function do_200_upgrade() {
			// schedule process that will update commissions table to include product details.
			WC()->queue()->schedule_single(
				time() + 10,
				'yith_wcaf_register_commission_products',
				array(
					'offset' => 0,
				),
				'yith-wcaf-db-updates'
			);

			// schedule process that will update commissions table to include line_totals details.
			WC()->queue()->schedule_single(
				time() + 20,
				'yith_wcaf_register_commission_totals',
				array(
					'offset' => 0,
				),
				'yith-wcaf-db-updates'
			);

			// update some options.
			static::do_200_options_upgrade();
		}

		/**
		 * Upgrade options, as required for DB version 2.0.0
		 *
		 * @return void
		 */
		protected static function do_200_options_upgrade() {
			// upgrade fields options.
			static::do_200_profile_fields_upgrade();

			// upgrade registration options.
			$process_orphan_commissions = get_option( 'yith_wcaf_referral_registration_process_dangling_commissions', 'no' );

			update_option( 'yith_wcaf_referral_registration_process_orphan_commissions', $process_orphan_commissions );

			// upgrade share options.
			$facebook_share  = 'yes' === get_option( 'yith_wcaf_share_fb', 'yes' );
			$twitter_share   = 'yes' === get_option( 'yith_wcaf_share_twitter', 'yes' );
			$pinterest_share = 'yes' === get_option( 'yith_wcaf_share_pinterest', 'yes' );
			$email_share     = 'yes' === get_option( 'yith_wcaf_share_email', 'yes' );
			$whatsapp_share  = 'yes' === get_option( 'yith_wcaf_share_whatsapp', 'yes' );

			$share_socials = array_merge(
				$facebook_share ? array( 'facebook' ) : array(),
				$twitter_share ? array( 'twitter' ) : array(),
				$pinterest_share ? array( 'pinterest' ) : array(),
				$email_share ? array( 'email' ) : array(),
				$whatsapp_share ? array( 'whatsapp' ) : array()
			);

			update_option( 'yith_wcaf_share', $facebook_share || $twitter_share || $pinterest_share || $email_share || $whatsapp_share ? 'yes' : 'no' );
			update_option( 'yith_wcaf_share_socials', $share_socials );

			// upgrade registration form option.
			$registration_form = get_option( 'yith_wcaf_referral_registration_form_options' );

			if ( 'any' === $registration_form ) {
				update_option( 'yith_wcaf_referral_registration_use_wc_form', 'yes' );
			}
		}

		/**
		 * Create new option to store profile fields, starting from existing one.
		 *
		 * @return void.
		 */
		protected static function do_200_profile_fields_upgrade() {
			$show_fields_on_settings            = get_option( 'yith_wcaf_referral_show_fields_on_settings', 'no' );
			$show_fields_on_become_an_affiliate = get_option( 'yith_wcaf_referral_show_fields_on_become_an_affiliate', 'no' );
			$show_first_name_field              = get_option( 'yith_wcaf_referral_registration_show_name_field', 'yes' );
			$show_last_name_field               = get_option( 'yith_wcaf_referral_registration_show_surname_field', 'yes' );
			$hide_username_field                = get_option( 'woocommerce_registration_generate_username' );
			$hide_password_field                = get_option( 'woocommerce_registration_generate_password' );
			$fields_structure                   = get_option( 'yith_wcaf_affiliate_profile_fields', array() );
			$privacy_page                       = get_option( 'wp_page_for_privacy_policy' );
			$show_terms_field                   = get_option( 'yith_wcaf_referral_registration_show_terms_field', 'yes' );
			$terms_label                        = get_option( 'yith_wcaf_referral_registration_terms_label' );
			$terms_anchor_url                   = get_option( 'yith_wcaf_referral_registration_terms_anchor_url' );
			$terms_anchor_text                  = get_option( 'yith_wcaf_referral_registration_terms_anchor_text' );

			$show_in = array(
				'settings'            => 'yes' === $show_fields_on_settings,
				'become_an_affiliate' => 'yes' === $show_fields_on_become_an_affiliate,
			);

			// set terms & conditions text, falling back to WC default when needed.
			if ( $terms_label && $terms_anchor_text ) {
				$terms_text = str_replace( '%TERMS%', '<a target="_blank" href="' . esc_url( $terms_anchor_url ) . '">' . esc_html( $terms_anchor_text ) . '</a>', $terms_label );
			} else {
				$terms_text = wc_get_terms_and_conditions_checkbox_text();
			}

			$fields_structure = array_merge(
				$fields_structure,
				array(
					array(
						'name'          => 'username',
						'label'         => _x( 'Username', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'admin_tooltip' => _x( 'This field is protected as it is required for the registration form to work correctly. You can enable/disable it from WP Dashboard > WooCommerce > Settings > Accounts & Privacy.', '[ADMIN] Affiliates profile fields table', 'yith-woocommerce-affiliates' ),
						'type'          => 'text',
						'enabled'       => 'no' === $hide_username_field,
						'required'      => true,
						'reserved'      => true,
					),
					array(
						'name'     => 'first_name',
						'label'    => _x( 'First name', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'type'     => 'text',
						'enabled'  => 'yes' === $show_first_name_field,
						'required' => true,
						'show_in'  => $show_in,
					),
					array(
						'name'     => 'last_name',
						'label'    => _x( 'Last name', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'type'     => 'text',
						'enabled'  => 'yes' === $show_last_name_field,
						'required' => true,
						'show_in'  => $show_in,
					),
					array(
						'name'          => 'email',
						'label'         => _x( 'Email address', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'admin_tooltip' => _x( 'This field is protected as it is required for the registration form to work correctly.', '[ADMIN] Affiliates profile fields table', 'yith-woocommerce-affiliates' ),
						'type'          => 'email',
						'validation'    => 'email',
						'enabled'       => true,
						'required'      => true,
						'reserved'      => true,
					),
					array(
						'name'          => 'password',
						'label'         => _x( 'Password', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'admin_tooltip' => _x( 'This field is protected as it is required for the registration form to work correctly. You can enable/disable it from WP Dashboard > WooCommerce > Settings > Accounts & Privacy.', '[ADMIN] Affiliates profile fields table', 'yith-woocommerce-affiliates' ),
						'type'          => 'password',
						'enabled'       => 'no' === $hide_password_field,
						'required'      => true,
						'reserved'      => true,
					),
					array(
						'name'          => 'privacy_text',
						'label'         => get_option( 'woocommerce_registration_privacy_policy_text', '' ),
						'admin_tooltip' => _x( 'You can change content of this paragraph from WP Dashboard > WooCommerce > Settings > Accounts & Privacy. Paragraph will be visible when you choose a Privacy Policy Page in WP Dashboard > Settings > Privacy.', '[ADMIN] Affiliates profile fields table', 'yith-woocommerce-affiliates' ),
						'class'         => 'woocommerce-privacy-policy-text',
						'type'          => 'paragraph',
						'enabled'       => ! ! $privacy_page,
						'reserved'      => true,
						'editable'      => false,
					),
					array(
						'name'        => 'terms',
						'label'       => $terms_text,
						'admin_label' => _x( 'Terms accepted?', '[FRONTEND] Affiliate field label', 'yith-woocommerce-affiliates' ),
						'type'        => 'checkbox',
						'label_class' => 'terms-label',
						'enabled'     => 'yes' === $show_terms_field,
						'required'    => true,
						'show_in'     => $show_in,
					),
				)
			);

			update_option( 'yith_wcaf_affiliate_profile_fields', $fields_structure );
			update_option( 'yith_wcaf_affiliate_profile_fields_defaults', $fields_structure );
		}
	}
}
