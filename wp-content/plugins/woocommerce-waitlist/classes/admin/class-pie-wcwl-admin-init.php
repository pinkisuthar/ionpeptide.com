<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Pie_WCWL_Admin_Init' ) ) {
	/**
	 * The Admin User Interface
	 *
	 * @package WooCommerce Waitlist
	 */
	class Pie_WCWL_Admin_Init {
		/**
		 * Initialise Admin class
		 */
		public function init() {
			$this->load_files();
			$this->load_hooks();
		}

		/**
		 * Load required files
		 */
		protected function load_files() {
			require_once 'class-pie-wcwl-waitlist-settings.php';
			$settings = new Pie_WCWL_Waitlist_Settings();
			$settings->init();
			require_once 'class-pie-wcwl-admin-ajax.php';
			$ajax = new Pie_WCWL_Admin_Ajax();
			$ajax->init();
			require_once 'class-pie-wcwl-exporter.php';
			$exporter = new Pie_WCWL_Exporter();
			$exporter->init();
		}

		/**
		 * Add hooks
		 */
		protected function load_hooks() {
			// Init
			add_action( 'init', array( $this, 'load_waitlist' ), 20 );
			add_action( 'wc_bulk_stock_before_process_qty', array( $this, 'load_waitlist_from_product_id' ), 5 );
			add_action( 'admin_init', array( $this, 'ignore_admin_nags' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
			add_action( 'admin_init', array( $this, 'version_check' ) );
			// Columns
			add_filter( 'manage_edit-product_columns', array( $this, 'add_waitlist_column_header' ), 11 );
			add_action( 'manage_product_posts_custom_column', array( $this, 'add_waitlist_column_content' ), 10, 2 );
			add_filter( 'manage_edit-product_sortable_columns', array( $this, 'register_waitlist_column_as_sortable' ) );
			add_action( 'pre_get_posts', array( $this, 'sort_by_waitlist_column' ), 10, 1 );
			// Events
			add_action( 'tribe_events_tickets_metabox_edit_accordion_content', array( $this, 'add_waitlist_for_tickets' ), 99, 2 );
			add_action( 'switch_theme', array( $this, 'check_templates_after_theme_switch' ) );
			// WooCommerce product exporter/importer
			add_filter( 'woocommerce_product_export_column_names', array( $this, 'add_waitlist_export_columns' ) );
			add_filter( 'woocommerce_product_export_product_default_columns', array( $this, 'add_waitlist_export_columns' ) );
			add_filter( 'woocommerce_product_export_product_column_wcwl_waitlist_data', array( $this, 'add_waitlist_export_data' ), 10, 2 );
			add_filter( 'woocommerce_product_export_product_column_wcwl_archive_data', array( $this, 'add_archive_export_data' ), 10, 2 );
			add_filter( 'woocommerce_csv_product_import_mapping_options', array( $this, 'add_waitlist_import_columns' ) );
			add_filter( 'woocommerce_csv_product_import_mapping_default_columns', array( $this, 'add_auto_mapping_for_waitlist_columns' ) );
			add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this, 'import_waitlist_data' ), 10, 2 );
			add_filter( 'woocommerce_product_import_pre_insert_product_object', array( $this, 'import_archive_data' ), 10, 2 );
			// Auto login
			add_action( 'woocommerce_created_customer', array( $this, 'auto_login' ), 10, 3 );
		}

		/**
		 * Sets up the waitlist and calls product tab function if required
		 *
		 * @hooked action init
		 * @return void
		 * @since  1.0.1
		 */
		public function load_waitlist() {
			$product_id = $this->get_post_id();
			if ( 'product' !== get_post_type( $product_id ) ) {
				return;
			}
			$this->load_waitlist_from_product_id( $product_id );
		}

		/**
		 * Get post ID
		 *
		 * @return string
		 */
		protected function get_post_id() {
			if ( isset( $_REQUEST['post'] ) ) {
				$product_id = absint( $_REQUEST['post'] );
			} elseif ( isset( $_REQUEST['post_ID'] ) ) {
				$product_id = absint( $_REQUEST['post_ID'] );
			} else {
				$product_id = '';
			}

			return $product_id;
		}

		/**
		 * Sets up the waitlist from the post id and calls product tab function if required
		 *
		 * We don't want the waitlist tab to appear for grouped products as each linked product will have it's own waitlist
		 *
		 * @param  int $product_id id of the post
		 *
		 * @return void
		 */
		public function load_waitlist_from_product_id( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product && 'grouped' != $product->get_type() && array_key_exists( $product->get_type(), WooCommerce_Waitlist_Plugin::$allowed_product_types ) ) {
				require_once 'product-tab/class-pie-wcwl-custom-admin-tab.php';
				$tab = new Pie_WCWL_Custom_Tab( $product );
				$tab->init();
			}
		}

		/**
		 * Hook up admin nags as and when required
		 */
		public function set_up_admin_nags() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			if ( 'no' !== get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$this->set_up_nag( 'updated', 'hide_out_of_stock_products_nag', $this->get_settings_url( 'inventory' ) );
			}
			if ( ! get_option( '_' . WCWL_SLUG . '_counts_updated' ) ) {
				$this->set_up_nag( 'updated', 'update_waitlist_counts_nag', self::get_settings_url( 'waitlist' ) );
			}
			if ( get_option( '_' . WCWL_SLUG . '_corrupt_data' ) ) {
				$this->set_up_nag( 'updated', 'corrupt_waitlist_data_nag', 'https://woocommerce.com/my-account/create-a-ticket/' );
			}
			if ( ! get_option( '_' . WCWL_SLUG . '_metadata_updated' ) ) {
				$this->set_up_nag( 'updated', 'metadata_update_nag', self::get_settings_url( 'waitlist' ) );
			}
			if ( ! get_option( '_' . WCWL_SLUG . '_version_2_warning' ) ) {
				$this->set_up_nag( 'updated', 'version_2_nag', 'https://docs.woocommerce.com/document/woocommerce-waitlist/#section-2' );
			}
		}

		/**
		 * Add all nag notices in using a particular format
		 *
		 * @param $status string type of notice to be used
		 * @param $type   string type of nag that we are outputting
		 * @param $link   string link to be used in our string to aid the user in fixing the issue
		 */
		protected function set_up_nag( $status, $type, $link ) {
			$usermeta = get_user_meta( get_current_user_id(), '_' . WCWL_SLUG, true );
			if ( ! isset( $usermeta[ 'ignore_' . $type ] ) || ! $usermeta[ 'ignore_' . $type ] ) {
				$message = $this->get_nag_text( $type ) ?? '';
				echo '<div class="' . esc_attr( $status ) . '"><p>';
				printf( esc_html( $message ), '<a href="' . esc_url( $link ) . '">', '</a>' );
				echo ' | <a href="' . esc_url( add_query_arg( 'ignore_' . $type, true ) ) . '">';
				esc_html_e( 'Stop nagging me', 'woocommerce-waitlist' );
				echo '</a></p></div>';
			}
		}

		/**
		 * Get the text for a nag
		 *
		 * @param $type string type of nag that we are outputting
		 *
		 * @return string
		 */
		public function get_nag_text( $type ) {
			switch ( $type ) {
				case 'hide_out_of_stock_products_nag':
					/* translators: %1$s: Opening <a> tag, link to WooCommerce product settings page. %2$s: Closing <a> tag */
					return __( 'The WooCommerce Waitlist extension is active but you have the "Hide out of stock items from the catalog" option switched on. Please %1$schange your settings%2$s for WooCommerce Waitlist to function correctly.', 'woocommerce-waitlist' );
				case 'update_waitlist_counts_nag':
					/* translators: %1$s: Opening <a> tag, link to WooCommerce Waitlist settings page. %2$s: Closing <a> tag */
					return __( 'Your WooCommerce Waitlist counts may be inaccurate. Please %1$shead to the settings page%2$s and click "Update Counts" to do this now.', 'woocommerce-waitlist' );
				case 'corrupt_waitlist_data_nag':
					/* translators: %1$s: Opening <a> tag, link to WooCommerce open support ticket page. %2$s: Closing <a> tag */
					return __( 'WooCommerce Waitlist has discovered waitlist entries on translation products. Please %1$scontact support%2$s to get help with resolving this issue.', 'woocommerce-waitlist' );
				case 'metadata_update_nag':
					/* translators: %1$s: Opening <a> tag, link to WooCommerce Waitlist settings page. %2$s: Closing <a> tag */
					return __( 'WooCommerce Waitlist needs to update your database entries to fully function. Please %1$shead to the settings page%2$s and click "Update Metadata" to do this now', 'woocommerce-waitlist' );
				case 'version_2_nag':
					/* translators: %1$s: Opening <a> tag, link to WooCommerce Waitlist documentation. %2$s: Closing <a> tag */
					return __( 'Thank you for updating WooCommerce Waitlist to version 2. If you have any issues please %1$srefer to the documentation%2$s to review the changes made with this update', 'woocommerce-waitlist' );
			}

			return '';
		}


		/**
		 * When a user selects the option to ignore a nag add this to their usermeta so we don't display it again
		 */
		public function ignore_admin_nags() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			$nag = isset( $_GET['ignore_hide_out_of_stock_products_nag'] ) ? absint( $_GET['ignore_hide_out_of_stock_products_nag'] ) : 0;
			if ( $nag ) {
				$this->ignore_nag( 'ignore_hide_out_of_stock_products_nag' );
			}
			$nag = isset( $_GET['ignore_update_waitlist_counts_nag'] ) ? absint( $_GET['ignore_update_waitlist_counts_nag'] ) : 0;
			if ( $nag ) {
				$this->ignore_nag( 'ignore_update_waitlist_counts_nag' );
			}
			$nag = isset( $_GET['ignore_corrupt_waitlist_data_nag'] ) ? absint( $_GET['ignore_corrupt_waitlist_data_nag'] ) : 0;
			if ( $nag ) {
				$this->ignore_nag( 'ignore_corrupt_waitlist_data_nag' );
			}
			$nag = isset( $_GET['ignore_metadata_update_nag'] ) ? absint( $_GET['ignore_metadata_update_nag'] ) : 0;
			if ( $nag ) {
				$this->ignore_nag( 'ignore_metadata_update_nag' );
			}
			$nag = isset( $_GET['ignore_version_2_nag'] ) ? absint( $_GET['ignore_version_2_nag'] ) : 0;
			if ( $nag ) {
				$this->ignore_nag( 'ignore_version_2_nag' );
			}
		}

		/**
		 * Ignore selected nags by user
		 *
		 * @param $type string type of nag the user has selected to ignore
		 */
		protected function ignore_nag( $type ) {
			$usermeta = get_user_meta( get_current_user_id(), '_' . WCWL_SLUG, true );
			if ( ! is_array( $usermeta ) ) {
				$usermeta = array();
			}
			$usermeta[ $type ] = true;
			update_user_meta( get_current_user_id(), '_' . WCWL_SLUG, $usermeta );
		}


		/**
		 * Check plugin version in DB and call required upgrade functions
		 *
		 * @hooked action admin_init
		 * @return void
		 * @since  1.0.1
		 */
		public function version_check() {
			$options = get_option( WCWL_SLUG );
			if ( ! isset( $options['version'] ) ) {
				$this->set_default_options();
			} else {
				if ( version_compare( $options['version'], '1.7.0' ) < 0 ) {
					update_option( 'woocommerce_queue_flush_rewrite_rules', 'true' );
				}
				if ( version_compare( $options['version'], '2.0.0' ) >= 0 && ! get_option( '_' . WCWL_SLUG . '_version_2_warning' ) ) {
					update_option( '_' . WCWL_SLUG . '_version_2_warning', true, false );
				}
				// Setup any notices for updating the metadata
				add_action( 'admin_notices', array( $this, 'set_up_admin_nags' ), 15 );
			}
			// Do a check of the templates, forcing the check if we don't have a version number or it's not the current version
			if ( ! isset( $options['version'] ) || WCWL_VERSION !== $options['version'] ) {
				$this->template_version_check( true );
			} else {
				$this->template_version_check();
			}
			$options['version'] = WCWL_VERSION;
			update_option( WCWL_SLUG, $options, false );
		}

		/**
		 * Set default waitlist options
		 */
		protected function set_default_options() {
			update_option( 'woocommerce_queue_flush_rewrite_rules', 'true' );
			update_option( '_' . WCWL_SLUG . '_metadata_updated', true, false );
			update_option( '_' . WCWL_SLUG . '_counts_updated', true, false );
			update_option( '_' . WCWL_SLUG . '_version_2_warning', true, false );
			update_option( WCWL_SLUG . '_archive_on', 'yes', false );
			update_option( WCWL_SLUG . '_registration_needed', 'no', false );
			update_option( WCWL_SLUG . '_create_account', 'yes', false );
			update_option( WCWL_SLUG . '_auto_login', 'no', false );
			update_option( WCWL_SLUG . '_double_optin', 'no' );
			update_option( WCWL_SLUG . '_minimum_stock', 1, false );
		}

		/**
		 * Function to get the URL of of the inventory settings page. Settings URLs were refactored in 2.1 with no API
		 * provided to retrieve them
		 *
		 * @param $section
		 *
		 * @return string
		 * @since  1.8.0
		 */
		public static function get_settings_url( $section ) {
			return admin_url( 'admin.php?page=wc-settings&tab=products&section=' . $section );
		}

		/**
		 * Enqueue styles
		 *
		 * @return void
		 */
		public function enqueue_styles() {
			// PHPStan error ignored due to constant being defined in definitions.php
			wp_enqueue_style( 'wcwl_admin', WCWL_ENQUEUE_PATH . '/includes/css/src/wcwl_admin.min.css', array(), WCWL_VERSION ); // @phpstan-ignore constant.notFound
			wp_enqueue_script( 'wcwl_admin_custom_tab', WCWL_ENQUEUE_PATH . '/includes/js/src/wcwl_admin_custom_tab.min.js', array(), WCWL_VERSION, true ); // @phpstan-ignore constant.notFound
			$data = $this->get_data_required_for_js();
			wp_localize_script( 'wcwl_admin_custom_tab', 'wcwl_tab', $data );
		}

		/**
		 * Setup data for JS
		 *
		 * @return array
		 */
		protected function get_data_required_for_js() {
			return array(
				'admin_email'            => get_option( 'woocommerce_email_from_address' ),
				'invalid_email'          => __( 'One or more emails entered appear to be invalid', 'woocommerce-waitlist' ),
				'add_text'               => __( 'Add', 'woocommerce-waitlist' ),
				'no_users_text'          => __( 'No users selected', 'woocommerce-waitlist' ),
				'no_action_text'         => __( 'No action selected', 'woocommerce-waitlist' ),
				'view_profile_text'      => __( 'View User Profile', 'woocommerce-waitlist' ),
				'go_text'                => __( 'Go', 'woocommerce-waitlist' ),
				'update_button_text'     => __( 'Update Options', 'woocommerce-waitlist' ),
				'update_waitlist_notice' => __( 'Waitlists may be appear inaccurate due to an update to variations. Please update the product or refresh the page to update waitlists', 'woocommerce-waitlist' ),
				'current_user'           => get_current_user_id(),
			);
		}

		/**
		 * Appends the element needed to create a custom admin column to an array
		 *
		 * @hooked filter manage_edit-product_columns
		 *
		 * @param array $defaults the array to append
		 *
		 * @return array The $defaults array with custom column values appended
		 * @since  1.0
		 */
		public function add_waitlist_column_header( $defaults ) {
			$defaults[ WCWL_SLUG . '_count' ] = __( 'Waitlist', 'woocommerce-waitlist' );

			return $defaults;
		}

		/**
		 * Outputs total waitlist members for a given post ID if $column_name is our custom column
		 *
		 * @hooked action manage_product_posts_custom_column
		 *
		 * @param string $column_name name of the column for which we are outputting data
		 * @param mixed  $post_ID     ID of the post for which we are outputting data
		 *
		 * @return void
		 * @since  1.0
		 */
		public function add_waitlist_column_content( $column_name, $post_ID ) {
			if ( WCWL_SLUG . '_count' != $column_name ) {
				return;
			}
			$content = get_post_meta( $post_ID, '_' . WCWL_SLUG . '_count', true ) ?? '';
			echo ! $content ? '<span class="na">-</span>' : esc_html( $content );
		}

		/**
		 * Appends our column ID to an array
		 *
		 * @hooked filter manage_edit-product_sortable_columns
		 *
		 * @param array $columns The WP admin sortable columns array.
		 *
		 * @return array
		 * @since  1.0
		 */
		public function register_waitlist_column_as_sortable( $columns ) {
			$columns[ WCWL_SLUG . '_count' ] = WCWL_SLUG . '_count';

			return $columns;
		}

		/**
		 * Sort columns by waitlist count when required
		 *
		 * @param $query
		 */
		public function sort_by_waitlist_column( $query ) {
			if ( ! is_admin() ) {
				return;
			}
			$orderby = $query->get( 'orderby' );
			if ( WCWL_SLUG . '_count' === $orderby ) {
				$query->set( 'orderby', 'meta_value_num' );
        		$query->set( 'meta_key', '_woocommerce_waitlist_count' );
			}
		}

		/**
		 * Include HTML for waitlists on the event page
		 *
		 * @param int $post_id event ID.
		 * @param int $ticket_id ticket ID.
		 * @return void
		 */
		public function add_waitlist_for_tickets( $post_id, $ticket_id ) {
			if ( ! $ticket_id ) {
				return;
			}
			$product = wc_get_product( $ticket_id );
			if ( $product ) {
				require_once 'product-tab/class-pie-wcwl-custom-admin-tab.php';
				new Pie_WCWL_Custom_Tab( $product );
				/**
				 * Filter the path to the event panel
				 * 
				 * @since 2.4.0
				 */
				include apply_filters( 'wcwl_include_path_admin_panel_event', plugin_dir_path( __FILE__ ) . 'product-tab/components/panel-event.php' );
			}
		}

		/**
		 * Add waitlist and archive data to product exports
		 *
		 * @param array $columns
		 * @return array $columns
		 */
		public function add_waitlist_export_columns( $columns ) {
			$columns['wcwl_waitlist_data'] = 'Waitlist';
			$columns['wcwl_archive_data']  = 'Waitlist Archive';

			return $columns;
		}

		/**
		 * Provide serialized waitlist data for export
		 *
		 * @param mixed $value (default: '')
		 * @param WC_Product $product
		 * @return mixed $value - Should be in a format that can be output into a text file (string, numeric, etc).
		 */
		public function add_waitlist_export_data( $value, $product ) {
			if ( ! $product ) {
				return $value;
			}

			// Retrieve the waitlist metadata
			$meta = get_post_meta( $product->get_id(), 'woocommerce_waitlist', true );
			if ( ! $meta ) {
				// If no waitlist data, return default value
				return $value;
			}
			
			// Serialize and return the waitlist data
			return serialize( $meta );
		}

		/**
		 * Provide serialized archive data for export
		 *
		 * @param mixed $value (default: '')
		 * @param WC_Product $product
		 * @return mixed $value - Should be in a format that can be output into a text file (string, numeric, etc).
		 */
		public function add_archive_export_data( $value, $product ) {
			if ( ! $product ) {
				return $value;
			}
			// Retrieve the archive metadata
			$meta = get_post_meta( $product->get_id(), 'wcwl_waitlist_archive', true );
			if ( ! $meta ) {
				// If no archive data, return default value
				return $value;
			}
			
			// Serialize and return the archive data
			return serialize( $meta );
		}

		/**
		 * Add waitlist and archive data to product imports
		 *
		 * @param array $options
		 * @return array $options
		 */
		public function add_waitlist_import_columns( $options ) {
			$options['wcwl_waitlist_data'] = 'Waitlist';
			$options['wcwl_archive_data']  = 'Waitlist Archive';

			return $options;
		}

		/**
		 * Add automatic mapping support for waitlist columns
		 *
		 * @param array $columns
		 * @return array $columns
		 */
		public function add_auto_mapping_for_waitlist_columns( $columns ) {
			$columns['Waitlist']         = 'wcwl_waitlist_data';
			$columns['Waitlist Archive'] = 'wcwl_archive_data';

			return $columns;
		}

		/**
		 * Process and import waitlist data
		 *
		 * @param WC_Product $object - Product being imported or updated.
		 * @param array $data - CSV data read for the product.
		 * @return WC_Product $object
		 */
		public function import_waitlist_data( $object, $data ) {
			if ( ! $object ) {
				return $object;
			}
			if ( ! empty( $data['wcwl_waitlist_data'] ) ) {
				$meta = unserialize( $data['wcwl_waitlist_data'] );
				if ( is_array( $meta ) ) {
					update_post_meta( $object->get_id(), 'woocommerce_waitlist', $meta );
					update_post_meta( $object->get_id(), '_woocommerce_waitlist_count', count( $meta ) );
				}
			}

			return $object;
		}

		/**
		 * Process and import archive data
		 *
		 * @param WC_Product $object - Product being imported or updated.
		 * @param array $data - CSV data read for the product.
		 * @return WC_Product $object
		 */
		public function import_archive_data( $object, $data ) {
			if ( ! $object ) {
				return $object;
			}
			if ( ! empty( $data['wcwl_archive_data'] ) ) {
				$meta = unserialize( $data['wcwl_archive_data'] );
				if ( is_array( $meta ) ) {
					update_post_meta( $object->get_id(), 'wcwl_waitlist_archive', $meta );
				}
			}

			return $object;
		}

				/**
		 * Check waitlist templates in theme for outdated versions
		 *
		 * @return void
		 */
		public function check_templates_after_theme_switch() {
			$this->template_version_check( true );
		}

		/**
		 * Template version check
		 *
		 * @since 2.4.0
		 * @param bool $skip_notice_check "true" will run the check regardless of a missing persisting notice
		 * @return void
		 */
		public function template_version_check( $skip_notice_check = false ) {
			if ( ( ! WC_Admin_Notices::has_notice( 'wcwl_outdated_templates' ) && $skip_notice_check ) ||
					WC_Admin_Notices::has_notice( 'wcwl_outdated_templates' ) ) {
				$plugin_dir = dirname(__FILE__);

				if (!is_dir($plugin_dir)) {
					return;
				}

				$core_templates = WC_Admin_Status::scan_template_files($plugin_dir . '/templates');
				$outdated       = false;

				foreach ($core_templates as $file) {
					$theme_file = false;

					if (file_exists(get_stylesheet_directory() . '/' . $file)) {
						$theme_file = get_stylesheet_directory() . '/' . $file;
					} elseif (file_exists(get_stylesheet_directory() . '/' . WC()->template_path() . $file)) {
						$theme_file = get_stylesheet_directory() . '/' . WC()->template_path() . $file;
					} elseif (file_exists(get_template_directory() . '/' . $file)) {
						$theme_file = get_template_directory() . '/' . $file;
					} elseif (file_exists(get_template_directory() . '/' . WC()->template_path() . $file)) {
						$theme_file = get_template_directory() . '/' . WC()->template_path() . $file;
					}

					if (false !== $theme_file) {
						$core_version  = WC_Admin_Status::get_file_version($plugin_dir . '/templates/' . $file);
						$theme_version = WC_Admin_Status::get_file_version($theme_file);

						if ($core_version && $theme_version && version_compare($theme_version, $core_version, '<')) {
							$outdated = true;
							break;
						}
					}
				}

				if ( $outdated ) {
					if ( ! WC_Admin_Notices::has_notice( 'wcwl_outdated_templates' ) ) {
						WC_Admin_Notices::add_custom_notice( 'wcwl_outdated_templates', $this->template_version_html() );
					}
				} else {
					WC_Admin_Notices::remove_notice( 'wcwl_outdated_templates' );
				}
			}
		}

		/**
		 * Returns HTML for the outdated template version notice
		 *
		 * @since 2.4.0
		 * @return string
		 */
		public function template_version_html() {
			ob_start();
			require_once __DIR__ . '/woo-includes/html-notice-template-check.php';
			return ob_get_clean();
		}

		/**
		 * Automatically login a user after registration if the option is enabled
		 * 
		 * Restricted to only run during a waitlist ajax request
		 *
		 * @param int $customer_id user ID
		 * @param array $new_customer_data
		 * @param bool $password_generated
		 * @return void
		 */
		public function auto_login( $customer_id, $new_customer_data, $password_generated ) {
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
			if ( wp_verify_nonce( $nonce, 'wcwl-ajax-process-user-request-nonce' ) ) {
				$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';
				if ( 'yes' === get_option( 'woocommerce_waitlist_auto_login' ) && 'wcwl_process_user_waitlist_request' === $action ) {

					// Log in the user programmatically
					$credentials  = array(
						'user_login'    => $new_customer_data['user_login'],
						'user_password' => $new_customer_data['user_pass'],
						'remember'      => true
					);
					$login = wp_signon( $credentials, false );
		
					if ( ! is_wp_error( $login ) ) {
						// User logged in successfully
						set_transient( 'waitlist_user_logged_in_' . $customer_id, true, 10 );
					} else {
						wcwl_add_log( $login->get_error_message(), '', $new_customer_data['user_email'] );
					}
				}
			}
		}
	}
}
