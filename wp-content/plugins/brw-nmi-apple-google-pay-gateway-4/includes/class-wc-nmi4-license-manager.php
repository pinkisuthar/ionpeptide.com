<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain-bound licensing client for the BRW Hub.
 *
 * Checkout never waits on the Hub. Successful validations are cached locally,
 * with a 72-hour grace period for temporary Hub/network outages.
 */
final class WC_NMI4_License_Manager {
	const PRODUCT_SLUG    = 'brw-commercepay-gateway';
	const OPTION_KEY      = 'wc_nmi4_brw_license';
	const CRON_HOOK       = 'wc_nmi4_brw_license_check';
	const GRACE_PERIOD    = 259200;

	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'ensure_schedule' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 30 );
		add_action( 'admin_post_wc_nmi4_save_license', array( $this, 'save_license' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( self::CRON_HOOK, array( $this, 'scheduled_check' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_gateways' ), 9999 );
		register_activation_hook( WC_NMI4_MAIN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( WC_NMI4_MAIN_FILE, array( __CLASS__, 'deactivate' ) );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	private function get_data() {
		$data = get_option( self::OPTION_KEY, array() );
		return wp_parse_args(
			is_array( $data ) ? $data : array(),
			array(
				'license_key'   => '',
				'status'        => 'missing',
				'message'       => 'Enter a license key to activate these gateways.',
				'last_checked'  => 0,
				'last_valid_at' => 0,
			)
		);
	}

	private function endpoint( $route ) {
		$base = defined( 'WC_NMI4_BRW_HUB_URL' )
			? WC_NMI4_BRW_HUB_URL
			: 'https://buildrightweb.com/wp-json/brw-license/v1';
		return trailingslashit( $base ) . ltrim( $route, '/' );
	}

	private function domain() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = strtolower( preg_replace( '/^www\./i', '', $host ) );
		return sanitize_text_field( $host );
	}

	public function is_valid() {
		$data = $this->get_data();
		if ( 'valid' === $data['status'] ) {
			return true;
		}
		return 'unreachable' === $data['status']
			&& ! empty( $data['last_valid_at'] )
			&& ( time() - (int) $data['last_valid_at'] ) <= self::GRACE_PERIOD;
	}

	public function validate( $activate = false ) {
		$data = $this->get_data();
		$key  = trim( (string) $data['license_key'] );

		if ( '' === $key ) {
			$data['status']       = 'missing';
			$data['message']      = 'Enter a license key to activate these gateways.';
			$data['last_checked'] = time();
			update_option( self::OPTION_KEY, $data, false );
			return false;
		}

		$response = wp_remote_post(
			$this->endpoint( $activate ? 'activate' : 'check' ),
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => array(
					'license' => $key,
					'domain'  => $this->domain(),
					'product' => self::PRODUCT_SLUG,
					'site_url'=> home_url( '/' ),
				),
			)
		);

		$data['last_checked'] = time();
		if ( is_wp_error( $response ) ) {
			$data['status']  = 'unreachable';
			$data['message'] = 'The BRW licensing server could not be reached. A previously validated license remains usable for up to 72 hours.';
			update_option( self::OPTION_KEY, $data, false );
			return $this->is_valid();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$data['status']  = 'unreachable';
			$data['message'] = 'The BRW licensing server returned an unreadable response. A previously validated license remains usable for up to 72 hours.';
			update_option( self::OPTION_KEY, $data, false );
			return $this->is_valid();
		}

		if ( ! empty( $body['valid'] ) ) {
			$data['status']        = 'valid';
			$data['message']       = ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : 'License active.';
			$data['last_valid_at'] = time();
			update_option( self::OPTION_KEY, $data, false );
			return true;
		}

		$data['status']  = 'invalid';
		$data['message'] = ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : 'License invalid for this domain.';
		update_option( self::OPTION_KEY, $data, false );
		return false;
	}

	public function scheduled_check() {
		$this->validate( false );
	}

	public function filter_available_gateways( $gateways ) {
		if ( $this->is_valid() ) {
			return $gateways;
		}
		foreach ( array( 'nmi4', 'nmi4_apple_pay', 'nmi4_google_pay' ) as $gateway_id ) {
			unset( $gateways[ $gateway_id ] );
		}
		return $gateways;
	}

	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			'NMI Apple / Google Pay License',
			'NMI Apple / Google Pay License',
			'manage_woocommerce',
			'wc-nmi4-license',
			array( $this, 'render_admin_page' )
		);
	}

	public function save_license() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this license.', 'wc-nmi4' ) );
		}
		check_admin_referer( 'wc_nmi4_save_license' );
		$data                = $this->get_data();
		$data['license_key'] = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		$data['status']      = 'pending';
		$data['message']     = 'Checking license…';
		$data['last_checked']= 0;
		update_option( self::OPTION_KEY, $data, false );
		$this->validate( true );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=wc-nmi4-license' ) ) );
		exit;
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$data  = $this->get_data();
		$valid = $this->is_valid();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BRW NMI Apple / Google Pay Gateway License', 'wc-nmi4' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License settings saved and checked.', 'wc-nmi4' ); ?></p></div>
			<?php endif; ?>
			<p><strong><?php esc_html_e( 'Status:', 'wc-nmi4' ); ?></strong> <span style="color:<?php echo $valid ? '#008a20' : '#b32d2e'; ?>"><?php echo esc_html( $valid ? 'Active' : ucfirst( (string) $data['status'] ) ); ?></span></p>
			<p><?php echo esc_html( (string) $data['message'] ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wc_nmi4_save_license">
				<?php wp_nonce_field( 'wc_nmi4_save_license' ); ?>
				<table class="form-table"><tr>
					<th scope="row"><label for="wc-nmi4-license-key"><?php esc_html_e( 'License key', 'wc-nmi4' ); ?></label></th>
					<td><input id="wc-nmi4-license-key" name="license_key" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( (string) $data['license_key'] ); ?>"></td>
				</tr></table>
				<?php submit_button( 'Save & Activate License' ); ?>
			</form>
		</div>
		<?php
	}

	public function admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || $this->is_valid() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'woocommerce_page_wc-nmi4-license' === $screen->id ) {
			return;
		}
		$url = admin_url( 'admin.php?page=wc-nmi4-license' );
		echo '<div class="notice notice-error"><p>' . wp_kses_post( sprintf( __( 'BRW NMI Apple / Google Pay payment methods are unavailable until a valid BRW license is activated. <a href="%s">Manage license</a>.', 'wc-nmi4' ), esc_url( $url ) ) ) . '</p></div>';
	}
}
