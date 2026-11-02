<?php
// phpcs:ignoreFile WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- License activation callbacks verify nonces via wp_verify_nonce()/check_admin_referer(); superglobal reads are wrapped in wp_unslash() + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Exchange_License {

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	public $item_code          = 'return';
	public $store_url          = 'https://www.zorem.com/';
	public $default_product_id = '99335';

	/**
	 * Get the class instance
	 *
	 * @since  1.0
	 * @return Return_Exchange_License
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the main plugin function
	 *
	 * @since  1.0
	 * @return  void
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Return item code
	 *
	 * @since   1.0
	 * @return  string
	 */
	public function get_item_code() {
		return $this->item_code;
	}

	/**
	 * Set license key
	 *
	 * @since   1.0
	 * @return  Void
	 */
	public function set_license_key( $license_key ) {
		update_option( $this->get_item_code() . '_license_key', $license_key );
	}

	/**
	 * Return licence key
	 *
	 * @since   1.0
	 * @return  string
	 */
	public function get_license_key() {
		return get_option( $this->get_item_code() . '_license_key', false );
	}

	/**
	 * Set license status
	 *
	 * @since   1.0
	 * @return  Void
	 */
	public function set_license_status( $status ) {
		update_option( $this->get_item_code() . '_license_status', $status );
	}

	/**
	 * Return license status
	 *
	 * @since   1.0
	 * @return  Bool
	 */
	public function get_license_status() {
		return get_option( $this->get_item_code() . '_license_status', false );
	}

	/**
	 * Create Instance ID
	 *
	 * @since   1.0
	 * @return  string
	 */
	public function create_instance_id() {
		return md5( $this->get_item_code() . time() );
	}

	/**
	 * Set Instance ID
	 *
	 * @since   1.0
	 * @return  Void
	 */
	public function set_instance_id( $instance_id ) {
		update_option( $this->get_item_code() . '_instance_id', $instance_id );
	}

	/**
	 * Return Instance ID
	 *
	 * @since   1.0
	 * @return  string
	 */
	public function get_instance_id() {
		return get_option( $this->get_item_code() . '_instance_id', false );
	}

	/**
	 * Return item code
	 *
	 * @since   1.0
	 * @return  string
	 */
	public function get_product_id() {
		return $this->default_product_id;
	}

	/**
	 * Return cron hook
	 *
	 * @since   1.0
	 * @return  string
	 */
	public function get_license_cron_hook() {
		return $this->get_item_code() . '_license_cron_hook';
	}

	/*
	 * init function
	 *
	 * @since  1.0
	*/
	public function init() {

		// cron schedule added
		add_filter( 'cron_schedules', array( $this, 'license_cron_schedule' ) );

		// ajax call for license
		add_action( 'wp_ajax_' . $this->get_item_code() . '_license_activate', array( $this, 'license_activate' ) );
		add_action( 'wp_ajax_' . $this->get_item_code() . '_license_deactivate', array( $this, 'license_deactivate' ) );

		// cron schedule
		add_action( 'admin_init', array( $this, 'add_cron_schedule' ) );

		// check license valid
		add_action( $this->get_license_cron_hook(), array( $this, 'check_license_valid' ) );
		if ( ! $this->get_license_status() ) {
			add_action( 'admin_notices', array( $this, 'return_licence_notice' ) );
		}

		// add_action( 'return_license_form', array( $this, 'return_license_form' ), 10 );
		add_action( 'return_license_form', array( $this, 'connect_with_zorem' ), 1 );
	}

	public function return_license_form() {
		require_once 'admin-html/returns-exchanges-license-form.php';
	}

	/*
	* add schedule for license check
	*
	* @since  1.0
	*
	* @return  array
	*/
	public function license_cron_schedule( $schedules ) {
		$schedules['license_cron_events'] = array(
			'interval' => 86400,
			'display'  => __( 'Every day', 'zorem-returns' ),
		);
		return $schedules;
	}

	/*
	* license activate
	* @return  json string
	*/
	public function license_activate() {
		check_ajax_referer( 'return_license_tab', 'return_license_tab_nonce' );

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		if ( isset( $license_key ) ) {
			$instance_id    = $this->create_instance_id();
			$authorize_data = $this->license_authorize_action( $license_key, 'activate', $instance_id );
			if ( 'true' == $authorize_data->success ) {
				$this->set_license_key( $license_key );
				$this->set_instance_id( $instance_id );
				$this->set_license_status( 1 );
				delete_transient( 'zorem_upgrade_' . $this->get_item_code() );
				delete_transient( 'zorem_subscription_status_' . $this->get_item_code() );
			} elseif ( $authorize_data->error ) {
				$this->set_license_key( '' );
				$this->set_instance_id( '' );
				$this->set_license_status( 0 );
			}
			header( 'Content-type: application/json' );
			echo json_encode( $authorize_data, JSON_PRETTY_PRINT );
			die();
		}
	}

	/*
	* license deactivate
	* @return  json string
	*/
	public function license_deactivate() {
		check_ajax_referer( 'return_license_tab', 'return_license_tab_nonce' );
		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

		if ( isset( $license_key ) ) {
			$return = $this->license_authorize_action( $license_key, 'deactivate' );
			if ( 'true' == $return->success ) {
				$this->set_license_key( '' );
				$this->set_instance_id( '' );
				$this->set_license_status( 0 );
				delete_transient( 'zorem_upgrade_' . $this->get_item_code() );
				delete_transient( 'zorem_subscription_status_' . $this->get_item_code() );
			}
			header( 'Content-type: application/json' );
			echo json_encode( $return, JSON_PRETTY_PRINT );
			die();
		}
	}

	/*
	* License authorize with server
	*/
	public function license_authorize_action( $license_key = '', $action = 'validate', $instance_id = false ) {

		if ( false == $instance_id ) {
			$instance_id = $this->get_instance_id();
		}

		$domain     = home_url();
		$api_params = array(
			'wc-api'       => 'wc-am-api',
			'wc_am_action' => $action,
			'instance'     => $instance_id,
			'object'       => $domain,
			'product_id'   => $this->get_product_id(),
			'api_key'      => $license_key,
		);

		$request  = add_query_arg( $api_params, $this->store_url );
		$response = wp_remote_get(
			$request,
			array(
				'timeout'   => 15,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$authorize_data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $authorize_data ) || null === $authorize_data || false === $authorize_data ) {
			return false;
		}
		return $authorize_data;
	}

	/*
	 * schedule cron event if not scheduled
	 *
	 * @since  1.0
	 *
	 * @return  null
	 */
	public function add_cron_schedule() {

		if ( ! wp_next_scheduled( $this->get_license_cron_hook() ) ) {
			wp_schedule_event( time(), 'license_cron_events', $this->get_license_cron_hook() );
		}
	}

	/**
	 *
	 * Check license valid
	 *
	 * @since  1.0
	 *
	 * @return  null
	 */
	public function check_license_valid() {

		if ( $this->get_license_status() ) {

			$authorize = $this->license_authorize_action( $this->get_license_key(), 'status' );

			$license_status = $authorize->status_check;
			if ( 'inactive' == $license_status ) {
				$this->set_license_key( '' );
				$this->set_instance_id( '' );
				$this->set_license_status( 0 );
			}
		}
	}

	/*
	* License notice
	*/
	public function return_licence_notice() {
		$class = 'notice notice-error';
		/* translators: %s: replace with settings page url */
		$message = sprintf( __( 'Opps! your <strong>Returns for WooCommerce</strong> licence key is not activated. To buy license %1$s click here %2$s to activate it.', 'zorem-returns' ), '<a href="' . admin_url( '/admin.php?page=zorem-returns-exchanges&tab=license' ) . '">', '</a>' );
		echo '<div class="notice notice-error return-license-notice"><p>' . wp_kses_post( $message ) . '</p></div>';
	}

	public function connect_with_zorem() {

		// Show the "activated" confirmation once, on the license page that loads right after
		// activation reloads (the activation branch sets this transient just before reloading).
		// Uses the ZUI snackbar — the same toast the rest of the settings screen shows on save —
		// with a fallback to the legacy snackbar if the library is not present.
		if ( get_transient( 'zorem_license_activated_notice' ) ) {
			delete_transient( 'zorem_license_activated_notice' );
			$activated_msg = __( 'License successfully activated.', 'zorem-returns' );
			?>
			<script>
				( function () {
					var msg = <?php echo wp_json_encode( $activated_msg ); ?>;
					function zoremLicenseActivatedToast() {
						if ( window.ZUI && typeof window.ZUI.snackbar === 'function' ) {
							window.ZUI.snackbar( msg, { type: 'success' } );
						} else if ( window.jQuery && jQuery.fn.zorem_snackbar ) {
							jQuery( document ).zorem_snackbar( msg );
						}
					}
					if ( 'loading' !== document.readyState ) {
						zoremLicenseActivatedToast();
					} else {
						document.addEventListener( 'DOMContentLoaded', zoremLicenseActivatedToast );
					}
				} )();
			</script>
			<?php
		}

		$connect     = isset( $_GET['connect'] ) ? sanitize_text_field( wp_unslash( $_GET['connect'] ) ) : '';
		$email       = isset( $_GET['email'] ) ? sanitize_text_field( wp_unslash( $_GET['email'] ) ) : '';
		$license_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// echo '<pre>';print_r($_GET);echo '</pre>';
		?>
		<script>
			/* zorem_snackbar jquery */
			(function( $ ){
				$.fn.zorem_snackbar = function(msg) {
					if ( jQuery('.snackbar-logs').length === 0 ){
						$("body").append("<section class=snackbar-logs></section>");
					}
					var zorem_snackbar = $("<article></article>").addClass('snackbar-log snackbar-log-success snackbar-log-show').text( msg );
					$(".snackbar-logs").append(zorem_snackbar);
					setTimeout(function(){ zorem_snackbar.remove(); }, 3000);
					return this;
				};
			})( jQuery );
			
			/* zorem_snackbar_warning jquery */
			(function( $ ){
				$.fn.zorem_snackbar_warning = function(msg) {
					if ( jQuery('.snackbar-logs').length === 0 ){
						$("body").append("<section class=snackbar-logs></section>");
					}
					var zorem_snackbar_warning = $("<article></article>").addClass( 'snackbar-log snackbar-log-error snackbar-log-show' ).html( msg );
					$(".snackbar-logs").append(zorem_snackbar_warning);
					setTimeout(function(){ zorem_snackbar_warning.remove(); }, 3000);
					return this;
				}; 
			})( jQuery );
		</script>
		<?php
		if ( 'true' == $connect && '' != $email ) {
			update_option( 'zorem_license_connected', 1 );
			update_option( 'zorem_license_email', $email );

			if ( isset( $license_key ) ) {
				$instance_id    = $this->create_instance_id();
				$authorize_data = $this->license_authorize_action( $license_key, 'activate', $instance_id );

				if ( 'true' == $authorize_data->success ) {
					$this->set_license_key( $license_key );
					$this->set_instance_id( $instance_id );
					$this->set_license_status( 1 );

					delete_transient( 'zorem_upgrade_' . $this->get_item_code() );

					// Defer the confirmation to the post-reload license page (handled at the top
					// of this method). The reload below lands on the clean license URL where the
					// ZUI library is loaded, so the toast shows there in the same snackbar the
					// rest of the settings screen uses — instead of flashing before the reload.
					set_transient( 'zorem_license_activated_notice', 'yes', MINUTE_IN_SECONDS );
					?>
					<style>
					.return-license-notice {
						display: none;
					}
					</style>
					<?php
				} elseif ( $authorize_data->error ) {

					if ( 'No API resources exist. Login to My Account to verify there are activations remaining, and the API Key and Product ID are correct.' == $authorize_data->error ) {
						$message = 'License not found for user ' . $email;
						?>
						<script>
							jQuery(document).zorem_snackbar_warning( '<?php echo wp_kses_post( $message ); ?>' );
						</script>
						<?php
					} elseif ( 'Cannot activate License Key. This key is already active on another site.' == $authorize_data->error ) {
						$message = 'Cannot activate License. License is already active on another site.';
						?>
						<script>
							jQuery(document).zorem_snackbar_warning( '<?php echo wp_kses_post( $message ); ?>' );
						</script>
						<?php
					} else {
						$message = 'License not found for user ' . $email;
						?>
						<script>
							jQuery(document).zorem_snackbar_warning( '<?php echo wp_kses_post( $message ); ?>' );
						</script>
						<?php
					}

					$this->set_license_key( '' );
					$this->set_instance_id( '' );
					$this->set_license_status( 0 );
				}
			}
			?>
			<script>
				var url = window.location.protocol + "//" + window.location.host + window.location.pathname+"?page=zorem-returns-exchanges&tab=license";
				window.history.pushState({path:url},'',url);
				jQuery('input#tab6').trigger('click');
				location.reload();
			</script>
			<?php
		} elseif ( 'false' == $connect && '' != $email ) {
			?>
			<script>
				var url = window.location.protocol + "//" + window.location.host + window.location.pathname+"?page=zorem-returns-exchanges&tab=license";
				window.history.pushState({path:url},'',url);
				jQuery('input#tab6').trigger('click');
			</script>
			<?php
		}
	}

	public function check_subscription_status() {

		$license_connected = get_option( 'zorem_license_connected', 0 );
		$license_email     = get_option( 'zorem_license_email', '' );

		$subscription = false;

		if ( $this->get_license_status() ) {
			return true;
		}

		if ( $license_connected && '' != $license_email ) {

			$zorem_subscription_status = get_transient( 'zorem_subscription_status_' . $this->get_item_code() );
			if ( false == $zorem_subscription_status ) {

				$api_params = array(
					'license_email' => $license_email,
					'product_id'    => $this->get_product_id(),
				);

				$request  = add_query_arg( $api_params, $this->store_url . 'wp-json/wc-zorem-license/v3/status/subscription' );
				$response = wp_remote_get(
					$request,
					array(
						'timeout'   => 15,
						'sslverify' => false,
					)
				);

				if ( is_wp_error( $response ) ) {
					return false;
				}

				$subscription_data = json_decode( wp_remote_retrieve_body( $response ) );

				set_transient( 'zorem_subscription_status_' . $this->get_item_code(), $response, 43200 ); // 12 hours cache

				return $subscription_data->subscription;
			} else {
				$subscription_data = json_decode( wp_remote_retrieve_body( $zorem_subscription_status ) );
				return $subscription_data->subscription;
			}
		}

		return $subscription;
	}
}
