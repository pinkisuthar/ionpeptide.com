<?php
/**
 * Admin related functions
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WooFunnels_DB_Updater' ) ) {
	/**
	 * Class WooFunnels_DB_Updater
	 *
	 */
	#[AllowDynamicProperties]
	class WooFunnels_DB_Updater {
		/**
		 * @var $ins
		 */
		public static $ins;
		/**
		 * @var null Used when order indexing is running
		 */
		public static $indexing = null;
		/**
		 * @var WooFunnels_Background_Updater $updater
		 */
		public $updater;
		/**
		 * @var WooFunnels_Contacts_Background_Updater $contacts_updater
		 */
		public $contacts_updater;
		/**
		 * @var WooFunnels_Background_Updater $updater
		 */
		public $order_id_in_process;
		public $contact_wp_user_address_fields = array(
			'address-1' => 'billing_address_1',
			'address-2' => 'billing_address_2',
			'city'      => 'billing_city',
			'state'     => 'billing_state',
			'postcode'  => 'billing_postcode',
			'country'   => 'billing_country',
		);
		private $_user_address_meta_updated = array();

		public $order_status_change = [];

		/**
		 * Contacts a running re-index has already queued a re-index for after handing them an order.
		 * In-request dedup: as_has_scheduled_action() is always false under the worker's custom
		 * store, so without this every handed order would schedule another recurring action.
		 *
		 * @var array<int,bool>
		 */
		private $handoff_reindex_queued = array();

		/**
		 * WooFunnels_DB_Updater constructor.
		 */
		public function __construct() {

			/** Showing notice to admin to allow upgrading tokens */
			add_action( 'admin_notices', array( $this, 'woofunnels_show_contact_processing_notice' ) );

			add_action( 'admin_init', array( $this, 'woofunnels_handle_db_upgrade_actions' ), 100 );

			/** Initiate Background Database tables customer and customer on clicking 'Allow' button from tools */
			add_action( 'init', array( $this, 'woofunnels_init_background_updater' ), 110 );
			add_action( 'init', array( $this, 'woofunnels_init_background_contacts_updater' ), 110 );
			add_action( 'admin_init', array( $this, 'woofunnels_maybe_update_customer_database' ), 120 );

			/** Creating contact for new orders */
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'woofunnels_wc_order_create_contact' ), 10, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'wc_order_create_contact_blocks' ) );

			/** Creating updating customer on order statuses paid */
			add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_change_async_call' ), 10, 3 );

			/** Updating customer and customer meta on accepting offer */
			add_action( 'wfocu_offer_accepted_and_processed', array( $this, 'woofunnels_offer_accept_create_update_customer' ), 1, 4 );

			/** Attempt to update customer on WP profile update*/
			add_action( 'profile_update', array( $this, 'bwf_update_contact_on_user_update' ), 10, 2 );
			add_action( 'woocommerce_save_account_details', array( $this, 'bwf_update_contact_on_user_update' ), 10, 1 );

			add_action( 'updated_user_meta', array( $this, 'mark_updated_address_fields' ), 10, 4 );

			add_action( 'bwf_order_index_completed', [ $this, 'maybe_change_state_on_success' ] );

			add_action( 'woocommerce_refund_created', [ $this, 'bwf_update_refunded_amount' ], 10, 2 );

			add_action( 'woocommerce_before_delete_order', [ $this, 'schedule_order_reindex_action' ] );

			add_action( 'rest_api_init', [ $this, 'rest_init_register_async_request' ] );

			add_action( 'woofunnels_tools_add_tables_row_start', [ $this, 'bwf_add_indexing_consent_button' ], 10, 1 );

			add_action( 'shutdown', [ $this, 'maybe_clean_indexing' ] );

			add_action( 'admin_footer', [ $this, 'maybe_re_dispatch_background_process' ] );

			add_action( 'bwf_reindex_contact_orders', [ $this, 'bwf_reindex_contact_orders_runner' ] );
			add_action( 'bwf_reindex_contact_orders_end', [ $this, 'bwf_reindex_contact_orders_end' ] );

			add_action( 'init', array( $this, 'maybe_create_db_tables' ) );

			add_action( 'woocommerce_order_status_changed', array( $this, 'bwf_update_cancel_order' ), 10, 3 );
		}

		/**
		 * @return WooFunnels_DB_Updater
		 */
		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self;
			}

			return self::$ins;
		}

		/**
		 * Creating/updating contacts and  customers on offer accepted
		 * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
		 */
		public static function capture_offer_accepted_event( $request ) {
			$posted_data = $request->get_body_params();
			$order_id    = isset( $posted_data['order_id'] ) ? $posted_data['order_id'] : 0;
			$products    = ( isset( $posted_data['products'] ) && count( $posted_data['products'] ) > 0 ) ? $posted_data['products'] : array();
			$total       = isset( $posted_data['total'] ) ? $posted_data['total'] : 0;

			try {
				bwf_create_update_contact( $order_id, $products, $total, false );
			} catch ( Error $r ) {
				BWF_Logger::get_instance()->log( print_R( $r->getMessage(), true ), 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}
		}

		/**
		 * WC order status change async call callback
		 *
		 * @param $request
		 *
		 * @return void
		 */
		public static function capture_order_status_change_event( $request ) {
			self::nocache_headers();
			$posted_data = $request->get_body_params();
			self::process_events( $posted_data );
		}

		public static function process_events( $posted_data ) {

			if ( empty( $posted_data ) ) {
				return;
			}
			$ins = WooFunnels_DB_Updater::get_instance();
			$ins->event_advanced_logs( "Order status change endpoint data received" );
			$ins->event_advanced_logs( $posted_data );

			$order_id    = isset( $posted_data['order_id'] ) ? $posted_data['order_id'] : 0;
			$status_from = isset( $posted_data['from'] ) ? $posted_data['from'] : '';
			$status_to   = isset( $posted_data['to'] ) ? $posted_data['to'] : '';

			/** If data not passed */
			if ( empty( $order_id ) || empty( $status_from ) || empty( $status_to ) ) {
				return;
			}

			/** If order is not valid */
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			try {
				$paid_status = $order->has_status( wc_get_is_paid_statuses() );
				if ( false === $paid_status ) {
					do_action( 'fk_order_status_change_async_capture', $posted_data );

					return;
				}
				$temp_cid = BWF_WC_Compatibility::get_order_meta( $order, '_woofunnel_custid' );
				if ( empty( $temp_cid ) ) {
					/** Update customer when order is paid and not indexed */
					bwf_create_update_contact( $order_id, array(), 0, true );
				}
				do_action( 'fk_order_status_change_async_capture', $posted_data );
			} catch ( Error $r ) {
				$ins->event_advanced_logs( "Error occurred: " . $r->getMessage() );
			}
		}

		public function needs_upgrade() {
			return apply_filters( 'bwf_init_db_upgrade', false );
		}

		/**
		 * Set headers to prevent caching
		 *
		 * @return void
		 */
		public static function nocache_headers() {
			if ( headers_sent() ) {
				return;
			}

			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'Last-Modified: false' );
		}

		public function woofunnels_handle_db_upgrade_actions() {
			if ( isset( $_GET['_bwf_remove_updated_db_notice'] ) && isset( $_GET['_bwf_updated_nonce'] ) ) {
				if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_bwf_updated_nonce'] ) ), '_bwf_hide_updated_nonce' ) ) {
					wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'You don&#8217;t have permission to do this.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				$hide_notice = sanitize_text_field( wp_unslash( $_GET['_bwf_remove_updated_db_notice'] ) );

				if ( 'yes' === $hide_notice ) {
					$this->set_upgrade_state( '5' );
				}
			}

			if ( isset( $_GET['bwf_update_db'] ) && isset( $_GET['_bwf_update_nonce'] ) ) {
				if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_bwf_update_nonce'] ) ), '_bwf_start_update_nonce' ) ) {
					wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'You don&#8217;t have permission to do this.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				$update_db = sanitize_text_field( wp_unslash( $_GET['bwf_update_db'] ) );

				if ( 'yes' === $update_db && '0' === $this->get_upgrade_state() ) {
					$this->set_upgrade_state( '2' );
				}
			}

			if ( isset( $_GET['_bwf_remove_declined_notice'] ) && isset( $_GET['_bwf_declined_nonce'] ) ) {
				if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_bwf_declined_nonce'] ) ), '_bwf_hide_declined_nonce' ) ) {
					wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'You don&#8217;t have permission to do this.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				$hide_notice = sanitize_text_field( wp_unslash( $_GET['_bwf_remove_declined_notice'] ) );

				if ( 'yes' === $hide_notice ) {
					$this->set_upgrade_state( '6' );
				}
			}
		}

		public function set_upgrade_state( $stage ) {
			update_option( '_bwf_db_upgrade', $stage, true );
		}

		public function get_upgrade_state() {

			/**
			 * 0: upgrade is allowed, optin message should show
			 * 1: Upgrade is declined.
			 * 2: Upgrade is accepted but not dispatched
			 * 3: Upgrade is accepted & dispatched (show notice)
			 * 4: Upgrade is completed (show notice)
			 * 5: Upgrade is completed and notice dismissed
			 * 6: Upgrade is declined and dismissed
			 */
			return get_option( '_bwf_db_upgrade', '0' );
		}

		/**
		 * Contact processing notice to notify admin about the state
		 */
		public function woofunnels_show_contact_processing_notice() {

			$db_state = $this->get_upgrade_state();

			if ( '3' === $db_state ) { ?>
                <div class="bwf-notice notice notice-success">
                    <div class="bwf-logo-wrapper">
                        <img src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) ) . 'assets/img/bwf-icon-white-bg.svg'; ?>" width="60" height="40">
                    </div>

                    <div class="bwf-message-content">
                        <strong><?php esc_html_e( 'Indexing of orders has started', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></strong>
                        <p><?php esc_html_e( 'It may take sometime to finish the process. We will update this notice once the process completes.', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
                    </div>
                </div>
				<?php
			} elseif ( '4' === $db_state ) {
				?>
                <div class="bwf-notice notice notice-success">
                    <div class="bwf-logo-wrapper">
                        <img src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) ) . 'assets/img/bwf-icon-white-bg.svg'; ?>" width="60" height="40">
                    </div>

                    <div class="bwf-message-content">
                        <strong><?php esc_html_e( 'Success', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></strong>
                        <p><?php esc_html_e( 'Order indexing completed successfully.', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></p>
                    </div>

                    <div class="bwf-message-action">
                        <a class="button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( '_bwf_remove_updated_db_notice', 'yes' ), '_bwf_hide_updated_nonce', '_bwf_updated_nonce' ) ); ?>"><?php esc_html_e( 'Dismiss', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
                    </div>
                </div>
				<?php
			} elseif ( '1' === $db_state ) {
				?>

                <div class="bwf-notice notice notice-error">
                    <div class="bwf-logo-wrapper">
                        <img src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) ) . 'assets/img/bwf-icon-white-bg.svg'; ?>" width="60" height="40">
                    </div>

                    <div class="bwf-message-content">
                        <strong><?php esc_html_e( 'FunnelKit Notice', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></strong>
                        <p><?php echo sprintf( wp_kses_post( __( 'Unable to complete indexing of orders. Please <a target="_blank" href="%s">contact support</a> to get the issue resolved.', 'wp-marketing-automations' ) ), esc_url( 'https://funnelkit.com/support/' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment ?></p>
                    </div>

                    <div class="bwf-message-action">
                        <a class="button-secondary" target="_blank" href="<?php echo esc_url( 'https://funnelkit.com/support/' ); ?>"><?php esc_html_e( 'Contact Support', 'wp-marketing-automations' );  // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
                        <a class="button-secondary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( '_bwf_remove_declined_notice', 'yes' ), '_bwf_hide_declined_nonce', '_bwf_declined_nonce' ) ); ?>"><?php esc_html_e( 'Dismiss', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
                    </div>
                </div>
				<?php
			}
			?>
            <style>

                .wp-admin .bwf-notice.notice, .wp-admin.toplevel_page_woofunnels .bwf-notice.notice, .wp-admin.woofunnels_page_upstroke .bwf-notice.notice {
                    display: -webkit-box;
                    display: -webkit-flex;
                    display: -ms-flexbox;
                    display: flex !important;
                    -webkit-box-align: center;
                    -webkit-align-items: center;
                    -ms-flex-align: center;
                    align-items: center;
                    padding: 12px;
                    height: auto;
                }

                .wp-admin .bwf-message-content, .wp-admin.toplevel_page_woofunnels .bwf-message-content, .wp-admin.woofunnels_page_upstroke .bwf-message-content {
                    padding: 0 13px;
                }

                .wp-admin .bwf-message-action, .wp-admin.toplevel_page_woofunnels .bwf-message-action, .wp-admin.woofunnels_page_upstroke .bwf-message-action {
                    text-align: center;
                    display: -webkit-box;
                    display: -webkit-inline-flex;
                    display: -ms-flexbox;
                    display: inline;
                    -webkit-box-orient: vertical;
                    -webkit-box-direction: normal;
                    -webkit-flex-direction: column;
                    -ms-flex-direction: column;
                    flex-direction: column;
                    margin-left: auto;
                }

                .wp-admin .bwf-message-content p, .wp-admin.toplevel_page_woofunnels .bwf-message-content p, .wp-admin.woofunnels_page_upstroke .bwf-message-content p {
                    margin: 0;
                    padding: 0;
                }

                .wp-admin .bwf-logo-wrapper, .wp-admin.toplevel_page_woofunnels .bwf-logo-wrapper, .wp-admin.woofunnels_page_upstroke .bwf-logo-wrapper {
                    /* height: 51px; */
                }

                .wp-admin .bwf-notice.notice.notice-success {
                    border-left-color: #1daafc;
                }
            </style>
			<?php
		}

		// Register offer accepted and processed

		/**
		 * Initiate WooFunnels_Background_Updater class
		 * @see woofunnels_maybe_update_customer_database()
		 */
		public function woofunnels_init_background_updater() {
			/** Background-process hooks fire only via admin-ajax, cron healthcheck or CLI — skip loading on frontend views. All consumers null-check $this->updater. */
			if ( ! BWF_Kernel::is_background_updater_context() ) {
				return;
			}
			if ( class_exists( 'WooFunnels_Background_Updater' ) ) {
				$this->updater = new WooFunnels_Background_Updater();
			}
		}

		/**
		 * Initiate WooFunnels_Background_Updater class
		 * @see woofunnels_maybe_update_customer_database()
		 */
		public function woofunnels_init_background_contacts_updater() {
			/** Same context gate as woofunnels_init_background_updater() */
			if ( ! BWF_Kernel::is_background_updater_context() ) {
				return;
			}
			if ( class_exists( 'WooFunnels_Contacts_Background_Updater' ) ) {
				$this->contacts_updater = new WooFunnels_Contacts_Background_Updater();
			}
		}

		/**
		 * @hooked over `woocommerce_checkout_order_processed`
		 * Creating BWF contact if not exist on WC new order
		 * sync call
		 *
		 * @param $order_id
		 * @param $posted_data
		 * @param $order WC_Order
		 */
		public function woofunnels_wc_order_create_contact( $order_id, $posted_data, $order ) {
			if ( apply_filters( 'bwf_woofunnel_skip_sub_order', true ) && wp_get_post_parent_id( $order_id ) ) {
				$order = wc_get_order( $order->get_parent_id() );
			}

			$wp_id = $order->get_customer_id();
			$email = $order->get_billing_email();

			/** If no email then return */
			if ( empty( $email ) ) {
				return;
			}

			/** Assigning wp id 0 if not available */
			if ( empty( $wp_id ) ) {
				$wp_id = 0;
			}

			$bwf_contact = bwf_get_contact( $wp_id, $email );

			/** If contact exists then directly add meta */
			if ( $bwf_contact->get_id() > 0 ) {
				$bwf_contact = bwf_create_update_contact_object( $bwf_contact, $order );
				$bwf_contact->save();
				BWF_Logger::get_instance()->log( "Order #" . $order->get_id() . ": Processed against contact ID" . $bwf_contact->get_id(), 'woofunnels_indexing' );

				/** Save uid as cookie */
				if ( ! empty( $bwf_contact->get_uid() ) && ! headers_sent() ) {
					setcookie( '_fk_contact_uid', $bwf_contact->get_uid(), time() + ( 10 * YEAR_IN_SECONDS ), ( COOKIEPATH ? COOKIEPATH : '/' ), COOKIE_DOMAIN, is_ssl(), false );
				}

				$order->update_meta_data( '_woofunnel_cid', $bwf_contact->get_id() );
				$order->save_meta_data();

				return;
			}

			/** Need to create a contact */
			if ( $wp_id > 0 ) {
				$wp_user = get_user_by( 'id', $wp_id );
				$email   = ( $wp_user instanceof WP_User && ! empty( $wp_user->user_email ) ) ? $wp_user->user_email : $email;
			}
			/** If email is not valid */
			if ( ! is_email( $email ) ) {
				return;
			}
			$bwf_contact->set_email( $email );

			$bwf_contact = bwf_create_update_contact_object( $bwf_contact, $order );
			bwf_contact_maybe_update_creation_date( $bwf_contact, $order );

			$bwf_contact->save();
			BWF_Logger::get_instance()->log( "Order #" . $order->get_id() . ": Processed against contact ID" . $bwf_contact->get_id(), 'woofunnels_indexing' );

			/** Save uid as cookie */
			if ( ! empty( $bwf_contact->get_uid() ) && ! headers_sent() ) {
				setcookie( '_fk_contact_uid', $bwf_contact->get_uid(), time() + ( 10 * YEAR_IN_SECONDS ), ( COOKIEPATH ? COOKIEPATH : '/' ), COOKIE_DOMAIN, is_ssl(), false );
			}

			$order->update_meta_data( '_woofunnel_cid', $bwf_contact->get_id() );
			$order->save_meta_data();
		}

		/**
		 * Creating BWF contact on order created from Checkout Block/Store API
		 *
		 * @param $order WC_Order
		 *
		 * @return void
		 */
		public function wc_order_create_contact_blocks( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$this->woofunnels_wc_order_create_contact( $order->get_id(), [], $order );
		}

		/**
		 * Sign a loopback request with a per-call HMAC. The signature covers the action,
		 * a fresh timestamp, and a random nonce, so each call produces a unique token
		 * with a 5-minute validity window.
		 *
		 * @param string $action
		 *
		 * @return array Keys: _ts, _rnonce, _sig
		 */
		public static function sign_request( $action = '' ) {
			$ts     = time();
			$rnonce = bin2hex( random_bytes( 8 ) );
			$sig    = hash_hmac( 'sha256', $action . '|' . $ts . '|' . $rnonce, wp_salt( 'nonce' ) );

			return array(
				'_ts'     => $ts,
				'_rnonce' => $rnonce,
				'_sig'    => $sig,
			);
		}

		/**
		 * Verify an HMAC-signed loopback request. Rejects tokens outside the 5-minute window,
		 * with malformed metadata, with mismatched signatures, or whose rnonce has already
		 * been consumed (replay defence).
		 *
		 * @param string $action
		 * @param array  $posted_data
		 *
		 * @return bool
		 */
		public static function verify_signature( $action, $posted_data ) {
			if ( empty( $action ) || ! is_array( $posted_data ) ) {
				return false;
			}
			if ( empty( $posted_data['_sig'] ) || empty( $posted_data['_ts'] ) || empty( $posted_data['_rnonce'] ) ) {
				return false;
			}

			$ts = absint( $posted_data['_ts'] );
			if ( $ts <= 0 || abs( time() - $ts ) > 300 ) {
				return false;
			}

			$rnonce = $posted_data['_rnonce'];
			if ( ! is_string( $rnonce ) || 16 !== strlen( $rnonce ) || ! ctype_xdigit( $rnonce ) ) {
				return false;
			}

			$expected = hash_hmac( 'sha256', $action . '|' . $ts . '|' . $rnonce, wp_salt( 'nonce' ) );
			if ( ! hash_equals( $expected, (string) $posted_data['_sig'] ) ) {
				return false;
			}

			/** Replay defence: each rnonce may only be consumed once within its validity window. */
			$replay_key = 'bwf_sig_replay_' . $rnonce;
			if ( false !== get_transient( $replay_key ) ) {
				return false;
			}
			set_transient( $replay_key, 1, 600 );

			return true;
		}

		/**
		 * Run on WC order status change
		 * Fire async call
		 *
		 * @param $order_id
		 * @param $from
		 * @param $to
		 *
		 * @return void
		 */
		public function order_status_change_async_call( $order_id, $from, $to ) {
			/** Avoid duplicate run on a single call */
			$arr = [ $order_id, $from, $to ];
			if ( isset( $this->order_status_change[ md5( wp_json_encode( $arr ) ) ] ) ) {
				return;
			}

			$this->order_status_change[ md5( wp_json_encode( $arr ) ) ] = 1;

			$extra_data = apply_filters( 'fk_before_sending_order_status_change_async_request', [], $order_id, $from, $to );

			/** Send async call */
			$data = array_merge(
				array(
					'order_id'     => $order_id,
					'from'         => $from,
					'to'           => $to,
					'nonce_action' => 'bwf_rest_order_status_changed',
				),
				self::sign_request( 'bwf_rest_order_status_changed' )
			);
			if ( ! empty( $extra_data ) && is_array( $extra_data ) ) {
				$data = array_merge( $extra_data, $data );
			}

			$flag_saved_val = get_transient( 'bwfan_stop_async_call' );
			if ( 1 === intval( $flag_saved_val ) ) {
				$this->process_events( $data );
			} else {

				$url  = home_url() . '/?rest_route=/woofunnel_customer/v1/order_status_changed';
				$args = bwf_get_remote_rest_args( $data );

				$this->event_advanced_logs( "Sending data for order status change JSON endpoint. URL: " . $url );
				$this->event_advanced_logs( $data );

				$start_time = microtime( true );
				$response   = wp_remote_post( $url, $args );
				$end_time   = microtime( true );

				if ( ( $end_time - $start_time ) > 0.2 ) {
					/** Curl took minimum 0.2 secs */
					/** Allow site owners to opt out of the sync-fallback on slow loopbacks (e.g. hosts where the loopback is always >200ms but reachable). */
					if ( false === apply_filters( 'bwf_skip_stop_async_call_transient', false, ( $end_time - $start_time ), $data ) ) {
						$flag_saved_val = get_transient( 'bwfan_stop_async_call' );
						if ( empty( $flag_saved_val ) ) {
							set_transient( 'bwfan_stop_async_call', 1, WEEK_IN_SECONDS );
						}
					}
				}


				$this->event_advanced_logs( "Order status change async call response" );
				if ( is_wp_error( $response ) ) {
					$this->event_advanced_logs( $response->get_error_message() );
				} elseif ( isset( $response['body'] ) ) {
					$this->event_advanced_logs( $response['body'] );
					$this->event_advanced_logs( $response['response'] );
				}
			}

			/** Reducing total_value with remaining order total (if partial earlier refund made) */
			if ( 'cancelled' === $to ) {
				BWF_Logger::get_instance()->log( "Order status changes from $from to $to for order id: $order_id", 'woofunnels_indexing' );
				bwf_reduce_customer_total_on_cancel( $order_id );
			}
		}

		public function event_advanced_logs( $log ) {
			if ( empty( $log ) || false === $this->event_cb_advanced_log_enabled() ) {
				return;
			}
			$log = array(
				't' => microtime( true ),
				'm' => $log,
			);

			add_filter( 'bwf_logs_allowed', array( $this, 'overriding_bwf_logging' ), 99999 );
			BWF_Logger::get_instance()->log( print_r( $log, true ), 'fka-event-endpoint-check', 'autonami' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- diagnostic entry written to the FunnelKit log file
			remove_filter( 'bwf_logs_allowed', array( $this, 'overriding_bwf_logging' ), 99999 );
		}

		public function event_cb_advanced_log_enabled() {
			if ( class_exists( 'BWFAN_Common' ) && BWFAN_Common::is_log_enabled( 'bwfan_end_point_logging' ) ) {
				return true;
			}
			if ( defined( 'BWFAN_ALLOW_EVENT_ENDPOINT_LOGS' ) && true === BWFAN_ALLOW_EVENT_ENDPOINT_LOGS ) {
				return true;
			}
			if ( true === apply_filters( 'bwfan_allow_event_endpoint_logs', false ) ) {
				return true;
			}

			return false;
		}

		public function overriding_bwf_logging() {
			return true;
		}

		/**
		 * Register endpoints
		 *
		 * @return void
		 */
		public function rest_init_register_async_request() {
			//Posting data to async request for processing package product and total for indexing
			register_rest_route( 'woofunnel_customer/v1', '/offer_accepted', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'capture_offer_accepted_event' ),
				'permission_callback' => array( __CLASS__, 'validate' ),
			) );

			/** Posting data to async request for processing new order product on order status change */
			register_rest_route( 'woofunnel_customer/v1', '/order_status_changed', array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'capture_order_status_change_event' ),
				'permission_callback' => array( __CLASS__, 'validate' ),
			) );
		}

		/**
		 * Method to validate nonce for the public creatable post requests
		 *
		 * @param WP_REST_Request $request
		 *
		 * @return false|int
		 */
		public static function validate( $request ) {
			$posted_data = $request->get_body_params();
			$action      = isset( $posted_data['nonce_action'] ) ? sanitize_key( $posted_data['nonce_action'] ) : '';

			$allowed_actions = array( 'bwf_rest_order_status_changed', 'bwf_rest_offer_accepted' );
			if ( ! in_array( $action, $allowed_actions, true ) ) {
				return false;
			}

			return self::verify_signature( $action, $posted_data );
		}

		/**
		 * Updating contact and customer on accepting offer
		 *
		 * @param $get_offer_id
		 * @param $get_package
		 * @param $get_parent_order
		 * @param $new_order
		 */
		public function woofunnels_offer_accept_create_update_customer( $get_offer_id, $get_package, $get_parent_order, $new_order ) {
			if ( ! $get_parent_order instanceof WC_Order ) {
				return;
			}

			$order_id     = $get_parent_order->get_id();
			$new_order_id = ( $new_order instanceof WC_Order ) ? $new_order->get_id() : 0;
			$custid       = BWF_WC_Compatibility::get_order_meta( $get_parent_order, '_woofunnel_custid' );

			/**
			 * Updating contact and customer in async REST API request if parent order is already indexed otherwise customer will be updated during parent order status change
			 * If batching is off then customer will be updated during child order status change
			 */
			if ( $order_id && ! empty( $custid ) && empty( $new_order_id ) ) {
				BWF_Logger::get_instance()->log( "Creating/Updating contact and customer in async request for batching order_id: $order_id and offer id: $get_offer_id ", 'woofunnels_indexing' );
				$product_ids = array();
				if ( is_array( $get_package ) && isset( $get_package['products'] ) && is_array( $get_package['products'] ) ) {

					foreach ( $get_package['products'] as $product_data ) {
						if ( isset( $product_data['id'] ) ) {
							array_push( $product_ids, $product_data['id'] );
						}
						if ( isset( $product_data['_offer_data'] ) && isset( $product_data['_offer_data']->id ) && isset( $product_data['id'] ) && $product_data['id'] !== $product_data['_offer_data']->id ) {
							array_push( $product_ids, $product_data['_offer_data']->id );
						}
					}
				}

				$total       = isset( $get_package['total'] ) ? $get_package['total'] : 0;
				$product_ids = array_unique( $product_ids );

				$data = array_merge(
					array(
						'products'     => $product_ids,
						'total'        => $total,
						'order_id'     => $order_id,
						'nonce_action' => 'bwf_rest_offer_accepted',
					),
					self::sign_request( 'bwf_rest_offer_accepted' )
				);
				$url  = home_url() . '/?rest_route=/woofunnel_customer/v1/offer_accepted';
				$args = bwf_get_remote_rest_args( $data );

				wp_remote_post( $url, $args );
			}
		}

		/**
		 * Updating refunded amount in order meta
		 *
		 * @param $refund_id
		 * @param $args
		 *
		 * @return void
		 */
		public function bwf_update_refunded_amount( $refund_id, $args ) {
			$order_id = isset( $args['order_id'] ) ? $args['order_id'] : 0;
			$amount   = isset( $args['amount'] ) ? $args['amount'] : 0;

			$order = wc_get_order( $order_id );

			/** only update refund in case of partial refund otherwise run the scheduler to reindex customer orders */
			if ( floatval( $order->get_remaining_refund_amount() ) > 0 ) {
				bwf_update_customer_refunded( $order_id, $amount );

				return;
			}

			$this->schedule_order_reindex_action( $order_id );
		}

		/**
		 * Schedule order reindex AS action
		 *
		 * @param $id
		 *
		 * @return void
		 */
		public function schedule_order_reindex_action( $id = '' ) {
			if ( empty( $id ) || ! class_exists( 'WooCommerce' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
				return;
			}

			/** Return if no order object */
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$cid = BWF_WC_Compatibility::get_order_meta( $order, '_woofunnel_cid' );
			if ( empty( $cid ) ) {
				return;
			}

			$args = [ 'cid' => $cid ];
			$hook = 'bwf_reindex_contact_orders';
			if ( ! as_has_scheduled_action( $hook, $args, 'funnelkit' ) ) {
				as_schedule_recurring_action( time(), 60, $hook, $args, 'funnelkit' );
			}
		}


		public function maybe_change_state_on_success() {
			delete_option( '_bwf_last_offsets' );
			$this->set_upgrade_state( '4' );
		}

		/**
		 * Adding allow button for db upgrade inside tools
		 * @SuppressWarnings(PHPMD.ElseExpression)
		 */
		public function bwf_add_indexing_consent_button() {
			$get_threshold_order = get_option( '_bwf_order_threshold', BWF_THRESHOLD_ORDERS );
			$bwf_db_upgrade      = $this->get_upgrade_state();
			global $wpdb;
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			if ( '3' !== $bwf_db_upgrade || $get_threshold_order < 1 ) {

				$paid_statuses = implode( ',', array_map( function ( $status ) {
					return "'" . esc_sql( 'wc-' . $status ) . "'";
				}, wc_get_is_paid_statuses() ) );


				if ( ! BWF_WC_Compatibility::is_hpos_enabled() ) {

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
					$query = $wpdb->prepare( "SELECT COUNT(p.ID) FROM {$wpdb->posts} AS p LEFT JOIN {$wpdb->postmeta} AS pm ON ( p.ID = pm.post_id AND pm.meta_key = '_woofunnel_cid') LEFT JOIN {$wpdb->postmeta} AS pm2 ON (p.ID = pm2.post_id) WHERE 1=1 AND pm.post_id IS NULL AND ( pm2.meta_key = '_billing_email' AND pm2.meta_value != '' ) AND p.post_type = %s AND p.post_status IN ({$paid_statuses})
								ORDER BY p.post_date DESC", 'shop_order' );

				} else {
					$order_table      = $wpdb->prefix . 'wc_orders';
					$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
					$query            = $wpdb->prepare( "SELECT COUNT(p.id) FROM {$order_table} AS p LEFT JOIN {$order_meta_table} AS pm ON ( p.id = pm.order_id AND pm.meta_key = '_woofunnel_cid') WHERE 1=1 AND pm.order_id IS NULL AND p.billing_email != '' AND
												p.type = %s  AND p.status IN ({$paid_statuses})
                                ORDER BY p.date_created_gmt DESC", 'shop_order' );
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared


				}


				$query_results       = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- paid statuses esc_sql quoted at build; other values bound by prepare
				$get_threshold_order = $query_results;
			}
			$remaining_text = '';

			if ( 0 === $get_threshold_order && 0 === absint( $bwf_db_upgrade ) ) {
				$this->set_upgrade_state( '5' );
				$bwf_db_upgrade = '5';
			}
			if ( '5' !== $bwf_db_upgrade && '4' !== $bwf_db_upgrade && $get_threshold_order > 0 ) {
				$remaining_text = sprintf( __( 'This store has <strong>%s orders</strong> to index.', 'wp-marketing-automations' ), $get_threshold_order ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment
			}

			if ( true === apply_filters( 'bwf_needs_order_indexing', false ) ) {
				?>
                <tr>
                    <th>
                        <strong class="name"><?php esc_html_e( 'Index Past Orders', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></strong>
                        <p class="description"><?php echo wp_kses_post( sprintf( 'This tool will scan all the previous orders and create an optimized index to run efficient queries. %s', $remaining_text ), 'woofunnels' ); ?></p>
						<?php if ( '1' === $bwf_db_upgrade || '6' === $bwf_db_upgrade ) { ?>
                            <span style="width:100%; color: red;"><?php esc_html_e( 'Unable to complete indexing of orders.', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></span><br/>
							<?php esc_html_e( 'Please', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?>
                            <a target="_blank" href="<?php echo esc_url( 'https://funnelkit.com/support/' ); ?>"><?php esc_html_e( 'contact support', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a><?php esc_html_e( ' to get the issue resolved.', 'wp-marketing-automations' ); ?>
                            <br/><br/>
						<?php } ?>
                        <a href="https://funnelkit.com/docs/upstroke/miscellaneous/index-past-order/"><?php esc_html_e( 'Learn more about this process', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
                    </th>
                    <td class="run-tool">
						<?php if ( '3' === $bwf_db_upgrade ) { ?>
                            <a href="javascript:void(0);" class="button button-large disabled"><?php esc_html_e( 'Running', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
						<?php } elseif ( '4' === $bwf_db_upgrade || '5' === $bwf_db_upgrade ) { ?>
                            <a href="javascript:void(0);" class="button button-large disabled"><?php esc_html_e( 'Completed', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>

						<?php } elseif ( '1' === $bwf_db_upgrade || '6' === $bwf_db_upgrade ) { ?>
                            <a href="javascript:void(0);" class="button button-large disabled"><?php esc_html_e( 'Start', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
							<?php
						} else {
							$start_url = esc_url( wp_nonce_url( add_query_arg( 'bwf_update_db', 'yes' ), '_bwf_start_update_nonce', '_bwf_update_nonce' ) );
							?>
                            <a class="button button-large <?php echo esc_attr(( $get_threshold_order > 0 ) ? '' : 'disabled'); ?>" href="<?php echo ( $get_threshold_order > 0 ) ? esc_url($start_url) : 'javascript:void(0);'; ?>"><?php esc_html_e( 'Start', 'wp-marketing-automations' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch ?></a>
						<?php } ?>
                    </td>
                </tr>
				<?php
			}
		}

		/**
		 * @param $user_id
		 * @param $old_user_data
		 *
		 * @hooked on profile_update
		 */
		public function bwf_update_contact_on_user_update( $user_id, $old_user_data = [] ) {
			/** If disabled */
			if ( defined( 'BWF_DISABLE_CONTACT_PROFILE_UPDATE' ) && 1 === intval( BWF_DISABLE_CONTACT_PROFILE_UPDATE ) ) {
				return;
			}

			if ( did_action( 'woocommerce_checkout_process' ) > 0 ) {
				return;
			}

			if ( 'profile_update' === current_action() ) {
				$this->do_profile_update_async_call( $user_id, $old_user_data );

				return;
			}
			if ( 'woocommerce_save_account_details' === current_action() ) {
				$this->do_profile_update_async_call( $user_id );
			}
		}

		/** Process profile update synchronously and handle contact data changes */
		public function do_profile_update_async_call( $user_id, $old_user_data = null ) {
			try {
				$old_user_email = '';
				if ( $old_user_data instanceof WP_User && is_email( $old_user_data->user_email ) ) {
					$old_user_email = $old_user_data->user_email;
				}

				/** Get Changed Address Fields */
				$fields = array();
				foreach ( $this->_user_address_meta_updated as $meta_key => $meta_value ) {
					$crm_key = array_search( $meta_key, $this->contact_wp_user_address_fields, true );
					if ( empty( $crm_key ) ) {
						continue;
					}

					$fields[ $crm_key ] = $meta_value;
				}

				$this->process_profile_update_sync( $user_id, $old_user_email, $fields );
			} catch ( \Throwable $e ) {
				BWF_logger::get_instance()->log( 'Profile update sync failed: ' . $e->getMessage(), 'woofunnels_profile_update' );
			}
		}

		/**
		 * Processes the profile update for a user synchronously.
		 *
		 * Updates the contact record with changed address fields and other profile information.
		 * Applies relevant filters and saves the contact.
		 *
		 * @param int    $user_id         The ID of the user being updated.
		 * @param string $old_user_email  The previous email address of the user.
		 * @param array  $fields          Array of changed address fields keyed by CRM field name.
		 */
		public function process_profile_update_sync( $user_id, $old_user_email = '', $fields = array() ) {
			try {
				/** Return if version is less than 2.0.2 */
				if ( defined( 'BWFAN_PRO_VERSION' ) && ! version_compare( BWFAN_PRO_VERSION, '2.0.2', '>' ) ) {
					return;
				}

				$contact = $this->maybe_get_contact_on_profile_update( $user_id, $old_user_email );

				if ( false === $contact ) {
					$this->_user_address_meta_updated = array();

					return;
				}

				if ( ! class_exists( 'WooCommerce' ) || empty( $fields ) ) {
					$contact->save();

					return;
				}

				$contact = apply_filters( 'bwf_before_profile_update_contact_sync', $contact, $user_id );

				foreach ( $fields as $crm_key => $meta_value ) {
					if ( 'state' === $crm_key ) {
						$contact->set_state( $meta_value );
						continue;
					}

					if ( 'country' === $crm_key ) {
						$contact->set_country( $meta_value );
						continue;
					}

					$contact = apply_filters( 'bwf_profile_update_contact_sync_field', $contact, $crm_key, $meta_value, $user_id );
				}

				$contact = apply_filters( 'bwf_after_profile_update_contact_sync', $contact, $user_id );

				$contact->set_last_modified( current_time( 'mysql', 1 ) );
				$contact->save();
			} catch ( \Throwable $e ) {
				BWF_logger::get_instance()->log( 'Profile update processing failed: ' . $e->getMessage(), 'woofunnels_profile_update' );
			}
		}





		/** Get the unsaved contact with WPID and Email changes */
		public function maybe_get_contact_on_profile_update( $user_id, $old_user_email = '' ) {
			/** Check if Old User Data valid */
			$old_email_valid = is_email( $old_user_email );
			$new_user        = get_user_by( 'id', $user_id );
			$new_user_email  = empty( $new_user->user_email ) ? get_user_meta( $user_id, 'billing_email', true ) : $new_user->user_email;
			/** If both emails are not valid */
			if ( ! $old_email_valid && ! is_email( $new_user_email ) ) {
				return false;
			}
			$new_user_exists = $new_user instanceof WP_User && is_email( $new_user_email );

			/** Check if email changed */
			$email_changed = $old_email_valid && $new_user_exists && $new_user_email !== $old_user_email;
			/** Get Contact by Old Email & ( get new_contact, if email changed ) */
			if ( ! $old_email_valid ) {
				$contact     = new WooFunnels_Contact( $user_id, $new_user_email );
				$new_contact = null;
			} else {
				$contact     = new WooFunnels_Contact( $user_id, $old_user_email );
				$new_contact = $email_changed ? new WooFunnels_Contact( '', $new_user_email ) : null;
			}

			$old_contact_exists = $contact instanceof WooFunnels_Contact && absint( $contact->get_id() ) > 0;
			$new_contact_exists = $new_contact instanceof WooFunnels_Contact && absint( $new_contact->get_id() ) > 0;

			if ( $new_contact_exists ) {
				$this->maybe_set_wpid_of_correct_contact( $new_contact, $contact, $user_id );

				/** If both old and new exists, then return */
				if ( $old_contact_exists ) {
					return false;
				}

				/** If both old doesn't exists, then use new as old and go ahead */
				$contact            = $new_contact;
				$new_contact_exists = false;
				$old_contact_exists = true;
			}

			/** If both old and new doesn't exists, then create the contact with new email */
			if ( ! $old_contact_exists && ! $new_contact_exists ) {
				/** If Email changes, then contact with new email, else old one */
				$contact = $new_contact instanceof WooFunnels_Contact ? $new_contact : $contact;

				/** If contact is not WooFunnels_Contact */
				$contact            = $contact instanceof WooFunnels_Contact ? $contact : new WooFunnels_Contact( $user_id, $new_user->user_email );
				$old_contact_exists = true;
			}

			if ( $new_user_exists ) {
				$contact->set_f_name( $new_user->first_name );
				$contact->set_l_name( $new_user->last_name );
			}

			/** Update WPID if old WPID is different */
			if ( $user_id !== absint( $contact->get_wpid() ) ) {
				$contact->set_wpid( $user_id );
			}

			/** Update email if changed */
			if ( $new_user_exists && $email_changed ) {
				$contact->set_email( $new_user->user_email );
			}

			return $contact;
		}

		private function maybe_set_wpid_of_correct_contact( $new_contact, $old_contact, $user_id ) {
			global $wpdb;

			$old_contact_exists = $old_contact instanceof WooFunnels_Contact && absint( $old_contact->get_id() ) > 0;
			$new_contact_exists = $new_contact instanceof WooFunnels_Contact && absint( $new_contact->get_id() ) > 0;

			/** Set wpid, if not same */
			if ( $new_contact_exists && $user_id !== absint( $new_contact->get_wpid() ) ) {
				$new_contact->set_wpid( $user_id );
				$new_contact->set_last_modified( current_time( 'mysql', 1 ) );
				$new_contact->save();
			}

			/** Remove WPID on old contact if same as user_id */
			if ( $old_contact_exists && $user_id === absint( $old_contact->get_wpid() ) ) {
				/** Using SQL because setting wpid as blank is not supported in core */
				//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->prefix . 'bwf_contact', array(
					'wpid'          => 0,
					'last_modified' => current_time( 'mysql', 1 ),
				), array( 'id' => $old_contact->get_id() ) );
			}
		}

		public function mark_updated_address_fields( $meta_id, $object_id, $meta_key, $_meta_value ) {
			/** Return if version is less than 2.0.2 */
			if ( defined( 'BWFAN_PRO_VERSION' ) && ! version_compare( BWFAN_PRO_VERSION, '2.0.2', '>' ) ) {
				return;
			}

			$address_meta_keys = array_values( $this->contact_wp_user_address_fields );
			if ( in_array( $meta_key, $address_meta_keys, true ) ) {
				$this->_user_address_meta_updated[ $meta_key ] = $_meta_value;
			}
		}

		/**
		 *
		 */
		public function maybe_clean_indexing() {
			if ( 1 === did_action( 'admin_head' ) && current_user_can( 'manage_options' ) && 'yes' === filter_input( INPUT_GET, 'bwf_index_clean', FILTER_UNSAFE_RAW ) ) { //phpcs:ignore
				$this->reset_indexing_data();
			}
			$this->bwf_maybe_restart_indexing();
		}

		/**
		 * reset and clean index data for restart index
		 * @return void
		 */
		public function reset_indexing_data() {
			global $wpdb;

			$tables = array(
				'bwf_wc_customers',
			);

			foreach ( $tables as &$table ) {
				$bwf_table = $wpdb->prefix . $table;
				$bwf_table = esc_sql( $bwf_table ); //sanitize the table name manually
				$wpdb->query( "TRUNCATE TABLE {$bwf_table}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- TRUNCATE of plugin table; name from wpdb prefix + hardcoded list
			}

			/**
			 * Remove bwf_wc_customers table from table list for attempt to recreate table
			 */
			$current_table_list = get_option( '_bwf_db_table_list' );

			if ( is_array( $current_table_list ) && isset( $current_table_list['tables'] ) && count( $current_table_list['tables'] ) > 0 ) {
				$table_list = array_unique( $current_table_list['tables'] );
				foreach ( $table_list as $key ) {
					if ( 'bwf_wc_customers' === $key ) {
						unset( $table_list[ $key ] );
						$current_table_list['tables'] = array_values( $table_list );
						update_option( '_bwf_db_table_list', $current_table_list, true );
						break;
					}
				}
			}
			delete_option( '_bwf_db_upgrade' );
			delete_option( '_bwf_order_threshold' );
			delete_option( '_bwf_offset' );
			delete_option( '_bwf_last_offsets' );
			delete_option( '_bwf_contacts_threshold' );
			delete_option( '_bwf_contacts_offset' );
			delete_option( '_bwf_contacts_last_offsets' );

			$table = $wpdb->prefix . 'postmeta';
			$wpdb->delete( $table, array( 'meta_key' => '_woofunnel_cid' ) );  //phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$wpdb->delete( $table, array( 'meta_key' => '_woofunnel_custid' ) );  //phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
				$table = $wpdb->prefix . 'wc_orders_meta';

				$wpdb->delete( $table, array( 'meta_key' => '_woofunnel_cid' ) );  //phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$wpdb->delete( $table, array( 'meta_key' => '_woofunnel_custid' ) );  //phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.SlowDBQuery.slow_db_query_meta_key

			}
			$this->updater->kill_process_safe();
			BWF_Logger::get_instance()->log( 'Indexing was cleaned manually.', 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		/**
		 * Restart indexing when it is stop due to any reason like cron disabled, server stopped etc
		 */
		public function bwf_maybe_restart_indexing() {
			if ( 1 === did_action( 'admin_head' ) && current_user_can( 'manage_options' ) && 'yes' === filter_input( INPUT_GET, 'bwf_restart_indexing', FILTER_UNSAFE_RAW ) ) {
				$this->set_upgrade_state( '2' );
				$this->woofunnels_maybe_update_customer_database();
			}
		}

		/**
		 * @hooked over `admin_head`
		 * This method takes care of database updating process.
		 * Checks whether there is a need to update the database
		 * Iterates over define callbacks and passes it to background updater class
		 * Update bwf_customer and bwf_customer_meta tables with new token from different tables
		 */
		public function woofunnels_maybe_update_customer_database() {

			if ( is_null( $this->updater ) ) {
				return;
			}

			if ( isset( $_GET['bwf_update_db'] ) && isset( $_GET['_bwf_update_nonce'] ) ) {
				if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_bwf_update_nonce'] ) ), '_bwf_start_update_nonce' ) ) {
					wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( esc_html__( 'You don&#8217;t have permission to do this.', 'wp-marketing-automations' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				}

				$bwf_update_db = sanitize_text_field( wp_unslash( $_GET['bwf_update_db'] ) );

				$get_state = $this->get_upgrade_state();
				if ( 'yes' === $bwf_update_db && '2' === $get_state ) {
					$this->bwf_start_indexing();
				}
			}
		}

		public function bwf_start_indexing() {
			$task = 'bwf_create_update_contact_customer';  //Scanning order table and updating customer tables
			$this->updater->push_to_queue( $task );
			BWF_Logger::get_instance()->log( '**************START INDEXING************', 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			$this->set_upgrade_state( '3' );
			$this->updater->save()->dispatch();
			BWF_Logger::get_instance()->log( 'First Dispatch completed', 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}

		public function capture_fatal_error() {
			$error = error_get_last();
			if ( ! empty( $error ) ) {
				if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {

					if ( $this->is_ignorable_error( $error['message'] ) ) {
						return;
					}
					BWF_Logger::get_instance()->log( 'Error logged during the process' . print_r( $error, true ), 'woofunnels_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

					$current_offset = get_option( '_bwf_offset', 0 );
					$current_offset ++;
					update_option( '_bwf_offset', $current_offset );

					$order_id = WooFunnels_Dashboard::$classes['WooFunnels_DB_Updater']->get_order_id_process();
					$order    = wc_get_order( $order_id );
					if ( $order instanceof WC_Order ) {

						$order->update_meta_data( '_woofunnel_cid', 0 );
						$order->save_meta_data();
					}
				}
			}
		}

		private function is_ignorable_error( $str ) {
			$get_all_ingorable_regex = $this->ignorable_errors();

			foreach ( $get_all_ingorable_regex as $re ) {
				$matches = [];
				preg_match_all( $re, $str, $matches, PREG_SET_ORDER, 0 );
				if ( ! empty( $matches ) ) {
					return true;
				}
			}

			return false;
		}

		private function ignorable_errors() {
			return [ '/Maximum execution time of/m', '/Allowed memory size of/m' ];
		}

		public function capture_fatal_error_contacts() {
			$error = error_get_last();
			if ( ! empty( $error ) ) {
				if ( is_array( $error ) && in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {

					if ( $this->is_ignorable_error( $error['message'] ) ) {
						return;
					}
					BWF_Logger::get_instance()->log( 'Error logged during the process' . print_r( $error, true ), 'woofunnels_contacts_indexing' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

					$current_offset = get_option( '_bwf_contacts_offset', 0 );
					$current_offset ++;
					update_option( '_bwf_contacts_offset', $current_offset );

				}
			}
		}

		public function set_order_id_in_process( $order_id ) {
			$this->order_id_in_process = $order_id;
		}

		public function get_order_id_process() {
			return $this->order_id_in_process;
		}

		public function maybe_re_dispatch_background_process() {
			if ( is_null( $this->updater ) ) {
				return;
			}
			$this->updater->maybe_re_dispatch_background_process();
		}

		public function maybe_dispatch_contact_table_indexing() {
			if ( is_null( $this->contacts_updater ) ) {
				return;
			}
			$task_list = array(
				'bwf_contacts_v1_0_init_db_setup',
			);

			$update_queued = false;

			foreach ( $task_list as $task ) {

				$this->contacts_updater->push_to_queue( $task );
				$update_queued = true;
			}

			if ( $update_queued ) {

				$this->contacts_updater->save()->dispatch();
			}
		}

		public function maybe_flag_old_contacts_indexing() {
			$indexing_option = get_option( '_bwf_migrate_contacts_indexing' );
			if ( ! empty( $indexing_option ) ) {
				return;
			}

			global $wpdb;
			$bwf_tables = get_option( '_bwf_created_tables' );
			if ( ! is_array( $bwf_tables ) || ! in_array( $wpdb->prefix . 'bwf_contact', $bwf_tables, true ) || ! in_array( $wpdb->prefix . 'bwf_contact_meta', $bwf_tables, true ) ) {
				return;
			}

			$contact_count = $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}bwf_contact" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( 0 === absint( $contact_count ) ) {
				return;
			}

			/**
			 * 1 - Pending
			 * 2 - In Progress
			 * 3 - Complete
			 */
			update_option( '_bwf_migrate_contacts_indexing', 1 );
		}

		public function maybe_create_db_tables() {
			/**
			 * Schema reconciliation (dbDelta) belongs in admin/cron/CLI/REST — not on every frontend
			 * page view. Tables are created during activation (an admin request) and reconciled on the
			 * next worker request after any upgrade (cron/AS worker run at least per minute), so a plain
			 * frontend view can safely assume they already exist. Mirrors BWF_Kernel::get_eager_classes(),
			 * which drops WooFunnels_DB_Tables (the schema provider) on the same non-worker requests.
			 */
			if ( class_exists( 'BWF_Kernel' ) && ! BWF_Kernel::is_backend_context() ) {
				return;
			}
			WooFunnels_Create_DB_Tables::get_instance()->create();
		}

		/**
		 * Read an option straight from the database, bypassing a stale persistent object cache.
		 *
		 * update_option() skips its cache-repair step on a zero-row (no-op) write, so a control value
		 * like the reindex cursor can pin to a stale cached copy under Redis / Object Cache Pro and spin
		 * the recurring action without ever making progress. A get_option() based read cannot help either:
		 * legacy cursor rows were created autoloaded, so it resolves via the alloptions blob (or a stale
		 * notoptions entry) before the per-key cache is ever consulted. Query the row directly instead.
		 *
		 * @param string $key
		 * @param mixed  $default
		 *
		 * @return mixed
		 */
		private function bwf_fresh_option( $key, $default = 0 ) {
			global $wpdb;

			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			return is_null( $value ) ? $default : $value;
		}

		/**
		 * Reindex contact orders
		 *
		 * @param $cid
		 *
		 * @return mixed false || 0 : not completed || 1 : completed
		 */
		public function bwf_reindex_contact_orders( $cid ) {
			global $wpdb;

			/**
			 * Acquire a short-lived per-contact lock so concurrent Action Scheduler runners cannot race
			 * the cursor / processed_order_ids writes. If another runner already holds the lock, bail
			 * without marking the action complete so it is retried on the next tick.
			 */
			$lock_name = "bwf_reindex_{$cid}";
			$got_lock  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			if ( '1' !== (string) $got_lock ) {
				return 0;
			}

			try {
				$bwf_contact = new WooFunnels_Contact( '', '', '', $cid );
				if ( 0 === $bwf_contact->get_id() ) {
					$this->un_schedule_wc_recurring_actions( $cid );

					return false;
				}

				$paid_statuses = implode( ',', array_map( function ( $status ) {
					return "'" . esc_sql( 'wc-' . $status ) . "'";
				}, wc_get_is_paid_statuses() ) );

				$key      = "bwf_contact_orders_{$cid}";
				$runs_key = "bwf_contact_orders_runs_{$cid}";

				$indexed_order_id = $this->bwf_fresh_option( $key, 0 );
				if ( 0 > $indexed_order_id ) {
					/**
					 * Completion sentinel present. If its cleanup action went missing
					 * (AS trimmed/failed), re-queue it instead of no-oping forever:
					 * scheduling is safe from any context. Never clean up inline here —
					 * a recurring occurrence respawns on completion, so deletes from
					 * inside the action cannot terminate the chain.
					 */
					if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
						if ( ! as_has_scheduled_action( 'bwf_reindex_contact_orders_end', [ 'cid' => $cid ], 'funnelkit' ) ) {
							BWF_Logger::get_instance()->log( "Stale reindex sentinel for contact #{$cid}: cleanup action missing, re-queued", 'woofunnels_indexing' );
							as_schedule_single_action( time(), 'bwf_reindex_contact_orders_end', [ 'cid' => $cid ], 'funnelkit' );
						}
					}

					return 1;
				}

				/**
				 * Bounded-execution failsafe: if the cursor has failed to advance across too many consecutive
				 * runs the action is stuck. Force-unschedule it instead of re-running the expensive scan forever.
				 */
				$no_progress_runs     = absint( $this->bwf_fresh_option( $runs_key, 0 ) );
				$max_no_progress_runs = absint( apply_filters( 'bwf_reindex_max_no_progress_runs', 50, $cid ) );
				if ( $no_progress_runs >= $max_no_progress_runs ) {
					delete_option( $runs_key );
					$this->un_schedule_wc_recurring_actions( $cid );

					return 1;
				}

				/** Delete custom table row when starting from 0 i.e. initial starting for a contact */
				if ( 0 === intval( $indexed_order_id ) ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bwf_wc_customers WHERE cid = %d", $cid ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
				}

				$limit = 10;

				/**
				 * Discovery identities for this contact. Matching on the contact's wpid / email rather than
				 * on an existing `_woofunnel_cid` stamp is what lets the re-index find orders that were never
				 * attributed in the first place; the previous query joined on the stamp, so an order that had
				 * never been indexed could never be discovered by the thing meant to index it.
				 *
				 * A contact with no wpid must not contribute a customer_id branch: `customer_id = 0` matches
				 * every guest order in the store and would pull the whole guest order book onto this contact.
				 * Same reasoning for an empty email.
				 */
				$match_wpid  = absint( $bwf_contact->get_wpid() );
				$match_email = sanitize_email( (string) $bwf_contact->get_email() );
				$contacts    = $wpdb->prefix . 'bwf_contact';

				try {
					$branches = array();
					$args     = array();

					/**
					 * `IS NULL OR = cid` keeps the pass self-draining: an order is either unattributed or
					 * already ours, so it leaves the result set once stamped. Orders carrying a different
					 * contact id are left alone rather than re-attributed on every run.
					 *
					 * Separate UNION branches instead of an OR across the two identity columns: each branch
					 * then resolves through its own index (customer_id / billing_email on HPOS), where an OR
					 * alongside the status, type and cursor conditions loses both.
					 *
					 * The email branch deliberately covers guest orders only. bwf_create_update_contact()
					 * resolves an order that carries a customer_id through that user's account email and
					 * ignores the billing email, so a registered user's order bearing someone else's billing
					 * address belongs to the account holder. Matching on billing email alone would hand this
					 * contact orders that provably resolve to a different one. An order whose customer row no
					 * longer exists (user deleted without WooCommerce resetting customer_id, or orders imported
					 * from another site) is resolved by wpid first and by billing email only when no contact
					 * holds that wpid, so it counts as guest only when no contact holds it. The check is a CASE
					 * so the contact lookup runs only for orders whose user row is missing; written as a plain
					 * OR, MySQL evaluates it ahead of the users join for every registered order.
					 *
					 * The stamp branch keeps every order the previous query found. The checkout hook
					 * woofunnels_wc_order_create_contact() resolves through the billing email and stamps
					 * `_woofunnel_cid` without setting the contact's wpid, so a registered order can be stamped
					 * to a contact that neither identity branch matches. The customer row was deleted at cursor 0
					 * and is rebuilt only from discovered orders, so dropping these would shrink the contact's
					 * order count and lifetime value. The per-order loop still lets the resolver decide the owner.
					 */
					if ( ! BWF_WC_Compatibility::is_hpos_enabled() ) {
						$posts     = $wpdb->prefix . 'posts';
						$postmeta  = $wpdb->prefix . 'postmeta';
						$users     = $wpdb->users;
						$order_col = 'ID';

						$branches[] = "( SELECT p.ID FROM {$posts} AS p
							INNER JOIN {$postmeta} AS st ON st.post_id = p.ID AND st.meta_key = '_woofunnel_cid' AND st.meta_value = %s
							WHERE p.post_type = %s AND p.post_status IN ({$paid_statuses}) AND p.ID > %d
							ORDER BY p.ID ASC LIMIT {$limit} )";
						array_push( $args, (string) $cid, 'shop_order', $indexed_order_id );

						if ( $match_wpid > 0 ) {
							$branches[] = "( SELECT p.ID FROM {$posts} AS p
								INNER JOIN {$postmeta} AS cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user' AND cu.meta_value = %s
								LEFT JOIN {$postmeta} AS fk ON fk.post_id = p.ID AND fk.meta_key = '_woofunnel_cid'
								WHERE p.post_type = %s AND p.post_status IN ({$paid_statuses}) AND p.ID > %d
								AND ( fk.post_id IS NULL OR fk.meta_value = %s )
								ORDER BY p.ID ASC LIMIT {$limit} )";
							array_push( $args, (string) $match_wpid, 'shop_order', $indexed_order_id, (string) $cid );
						}

						if ( ! empty( $match_email ) ) {
							$branches[] = "( SELECT p.ID FROM {$posts} AS p
								INNER JOIN {$postmeta} AS be ON be.post_id = p.ID AND be.meta_key = '_billing_email' AND be.meta_value = %s
								LEFT JOIN {$postmeta} AS cu2 ON cu2.post_id = p.ID AND cu2.meta_key = '_customer_user'
								LEFT JOIN {$users} AS u ON u.ID = cu2.meta_value
								LEFT JOIN {$postmeta} AS fk ON fk.post_id = p.ID AND fk.meta_key = '_woofunnel_cid'
								WHERE p.post_type = %s AND p.post_status IN ({$paid_statuses}) AND p.ID > %d
								AND ( CASE WHEN cu2.meta_id IS NULL OR cu2.meta_value IN ( '0', '' ) THEN 1 WHEN u.ID IS NOT NULL THEN 0
									ELSE NOT EXISTS ( SELECT 1 FROM {$contacts} AS bc WHERE bc.wpid = CAST( cu2.meta_value AS UNSIGNED ) ) END ) = 1
								AND ( fk.post_id IS NULL OR fk.meta_value = %s )
								ORDER BY p.ID ASC LIMIT {$limit} )";
							array_push( $args, $match_email, 'shop_order', $indexed_order_id, (string) $cid );
						}
					} else {
						$order_table      = $wpdb->prefix . 'wc_orders';
						$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
						$users            = $wpdb->users;
						$order_col        = 'id';

						$branches[] = "( SELECT o.id FROM {$order_table} AS o
							INNER JOIN {$order_meta_table} AS st ON st.order_id = o.id AND st.meta_key = '_woofunnel_cid' AND st.meta_value = %s
							WHERE o.status IN ({$paid_statuses}) AND o.type = %s AND o.id > %d
							ORDER BY o.id ASC LIMIT {$limit} )";
						array_push( $args, (string) $cid, 'shop_order', $indexed_order_id );

						if ( $match_wpid > 0 ) {
							$branches[] = "( SELECT o.id FROM {$order_table} AS o
								LEFT JOIN {$order_meta_table} AS om ON om.order_id = o.id AND om.meta_key = '_woofunnel_cid'
								WHERE o.customer_id = %d
								AND o.status IN ({$paid_statuses}) AND o.type = %s AND o.id > %d
								AND ( om.order_id IS NULL OR om.meta_value = %s )
								ORDER BY o.id ASC LIMIT {$limit} )";
							array_push( $args, $match_wpid, 'shop_order', $indexed_order_id, (string) $cid );
						}

						if ( ! empty( $match_email ) ) {
							$branches[] = "( SELECT o.id FROM {$order_table} AS o
								LEFT JOIN {$users} AS u ON u.ID = o.customer_id
								LEFT JOIN {$order_meta_table} AS om ON om.order_id = o.id AND om.meta_key = '_woofunnel_cid'
								WHERE o.billing_email = %s
								AND ( CASE WHEN o.customer_id = 0 THEN 1 WHEN u.ID IS NOT NULL THEN 0
									ELSE NOT EXISTS ( SELECT 1 FROM {$contacts} AS bc WHERE bc.wpid = o.customer_id ) END ) = 1
								AND o.status IN ({$paid_statuses}) AND o.type = %s AND o.id > %d
								AND ( om.order_id IS NULL OR om.meta_value = %s )
								ORDER BY o.id ASC LIMIT {$limit} )";
							array_push( $args, $match_email, 'shop_order', $indexed_order_id, (string) $cid );
						}
					}

					$sql = implode( ' UNION ', $branches );
					if ( count( $branches ) > 1 ) {
						$sql .= " ORDER BY {$order_col} ASC LIMIT {$limit}";
					}

					$orders_ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ); //phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- UNION of fixed branches; values bound through $args, statuses esc_sql()-quoted, $limit a constant
				} catch ( Error|Exception $e ) {
					delete_option( $runs_key );
					$this->un_schedule_wc_recurring_actions( $cid );

					return 1;
				}

				if ( empty( $orders_ids ) ) {
					delete_option( $runs_key );
					$this->un_schedule_wc_recurring_actions( $cid );

					return 1;
				}

				$old_processed_oids = $bwf_contact->get_meta( 'processed_order_ids' );
				$old_processed_oids = is_array( $old_processed_oids ) ? array_map( 'intval', $old_processed_oids ) : [];
				$processed_oids     = [];
				foreach ( $orders_ids as $id ) {
					if ( in_array( intval( $id ), $old_processed_oids, true ) ) {
						/** Advance the cursor on the skip path so already-processed orders leave the result set */
						update_option( $key, $id, false );
						continue;
					}

					$order = wc_get_order( $id );
					if ( ! $order instanceof WC_Order ) {
						/** Advance the cursor on the unloadable-order skip path too */
						update_option( $key, $id, false );
						$processed_oids[] = $id;
						continue;
					}

					try {
						$order->delete_meta_data( '_woofunnel_cid' );
						$order->delete_meta_data( '_woofunnel_custid' );
						$order->save_meta_data();

						$resolved_cid = absint( bwf_create_update_contact( $id, array(), 0, true ) );

						/**
						 * The resolver handed this order to another contact. That contact's customer row was not
						 * reset, so the counter write above is applied on top of whatever it already holds. Queue a
						 * full re-index for it, which rebuilds the row from scratch, rather than skipping the write
						 * and leaving the order counted nowhere.
						 */
						if ( $resolved_cid > 0 && $resolved_cid !== absint( $cid ) && ! isset( $this->handoff_reindex_queued[ $resolved_cid ] ) ) {
							$this->handoff_reindex_queued[ $resolved_cid ] = true;

							$resolved_args = [ 'cid' => (string) $resolved_cid ];
							if ( ! as_has_scheduled_action( 'bwf_reindex_contact_orders', $resolved_args, 'funnelkit' ) ) {
								as_schedule_recurring_action( time(), 60, 'bwf_reindex_contact_orders', $resolved_args, 'funnelkit' );
							}
						}
					} catch ( Error|Exception $e ) {
						/**
						 * A single order that consistently throws (corrupt order data, a third-party hook on
						 * bwf_normalize_contact_meta_*, or a save failure) must not freeze the cursor and re-run
						 * the expensive scan every tick. Advance the cursor past it so the result set keeps
						 * draining and the recurring action still terminates; the order is left un-reindexed.
						 */
						BWF_Logger::get_instance()->log( "Reindex skipped order #{$id} for contact #{$cid}: " . $e->getMessage(), 'bwf-contact-orders-reindex' );
						update_option( $key, $id, false );
						continue;
					}

					/** Update order id on a key index */
					update_option( $key, $id, false );
					$processed_oids[] = $id;
				}

				$processed_oids = array_unique( array_merge( $old_processed_oids, $processed_oids ) );
				sort( $processed_oids );
				$bwf_contact->update_meta( 'processed_order_ids', maybe_serialize( $processed_oids ) );

				/** Track forward progress for the bounded-execution failsafe */
				$new_cursor = intval( $this->bwf_fresh_option( $key, 0 ) );
				if ( $new_cursor > intval( $indexed_order_id ) ) {
					delete_option( $runs_key );
				} else {
					update_option( $runs_key, $no_progress_runs + 1, false );
				}

				if ( count( $orders_ids ) < $limit ) {
					delete_option( $key );
					delete_option( $runs_key );
					$bwf_contact->delete_meta( 'processed_order_ids' );

					$hook = 'bwf_reindex_contact_orders_end';
					if ( ! as_has_scheduled_action( $hook, [ 'cid' => $cid ], 'funnelkit' ) ) {
						as_schedule_single_action( time(), $hook, [ 'cid' => $cid ], 'funnelkit' );
					}

					return 1;
				}

				return 0;
			} finally {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			}
		}

		/**
		 * Action Scheduler handler for the recurring reindex: drain batches inside a
		 * time budget instead of one 10-order batch per 60s tick. Mirrors the manual
		 * resync endpoint's loop; 15s stays safely inside AS runner time limits.
		 *
		 * @param int|string $cid Contact ID.
		 *
		 * @return void
		 */
		public function bwf_reindex_contact_orders_runner( $cid ) {
			$start_time  = microtime( true );
			$key         = "bwf_contact_orders_{$cid}";
			$prev_cursor = intval( $this->bwf_fresh_option( $key, 0 ) );
			$batches     = 0;

			/**
			 * Hard cap independent of the time budget, same reasoning as the manual resync
			 * endpoint's loop: each batch is fast, so a very large order history can run through
			 * far more batches than 15s implies, growing the request-lifetime WC_Order cache with
			 * no ceiling - risking a memory/execution-time kill on this AS worker request instead
			 * of a clean, resumable tick. Hitting the cap just ends this tick early; the recurring
			 * action already re-enters on its own schedule and the cursor picks up where it left off.
			 */
			$max_batches = (int) apply_filters( 'bwf_reindex_contact_orders_runner_max_batches', 20, $cid );

			do {
				$response = $this->bwf_reindex_contact_orders( $cid );
				$batches ++;
				if ( 1 === intval( $response ) || false === $response ) {
					break;
				}

				$new_cursor = intval( $this->bwf_fresh_option( $key, 0 ) );
				if ( $new_cursor === $prev_cursor ) {
					break;
				}
				$prev_cursor = $new_cursor;
			} while ( ( microtime( true ) - $start_time ) < 15 && $batches < $max_batches );
		}

		/**
		 * Callback to Un-schedule contact orders sync recurring action
		 *
		 * @param $cid
		 *
		 * @return void
		 */
		public function un_schedule_wc_recurring_actions( $cid ) {
			if ( empty( $cid ) ) {
				return;
			}
			update_option( "bwf_contact_orders_{$cid}", '-1', false );
			$hook = 'bwf_reindex_contact_orders_end';
			if ( ! as_has_scheduled_action( $hook, [ 'cid' => $cid ], 'funnelkit' ) ) {
				as_schedule_single_action( time(), $hook, [ 'cid' => $cid ], 'funnelkit' );
			}
		}

		/**
		 * Un-schedule main recurring action from this single action
		 *
		 * @param $cid
		 *
		 * @return void
		 */
		public function bwf_reindex_contact_orders_end( $cid ) {
			if ( empty( $cid ) ) {
				return;
			}

			global $wpdb;

			delete_option( "bwf_contact_orders_{$cid}" );
			delete_option( "bwf_contact_orders_runs_{$cid}" );
			$bwf_contact = new WooFunnels_Contact( '', '', '', $cid );
			$bwf_contact->delete_meta( 'processed_order_ids' );

			$hook = 'bwf_reindex_contact_orders';
			$args = wp_json_encode( [ 'cid' => $cid ] );

			/** query */
			$query = "DELETE FROM `{$wpdb->prefix}actionscheduler_actions` WHERE `args` = %s AND `hook` = %s Limit 25";
			$query = $wpdb->prepare( $query, $args, $hook ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared; table from wpdb prefix, values bound as %s

			$run = true;
			do {
				$deleted = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- runs string fully built by prepare at 1645
				if ( ! $deleted ) {
					$run = false;
				}
			} while ( $run );
		}

		/**
		 * schedule to sync all the orders of contact on cancel
		 *
		 * @param $order_id
		 * @param $from
		 * @param $to
		 *
		 * @return void
		 */
		public function bwf_update_cancel_order( $order_id, $from, $to ) {
			/** return if from status is not paid status or to status is not cancelled */
			$failed_statuses = [ 'pending', 'failed', 'cancelled' ];
			if ( in_array( $from, $failed_statuses, true ) || 'cancelled' !== $to ) {
				return;
			}

			$this->schedule_order_reindex_action( $order_id );
		}

	}
}

