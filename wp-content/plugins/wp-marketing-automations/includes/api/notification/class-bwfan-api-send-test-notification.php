<?php
defined( 'ABSPATH' ) || exit;
/**
 * Test Notification API file
 *
 * @package BWFCRM_API_Base
 */

/**
 * Test Notification API class
 */
#[\AllowDynamicProperties]
class BWFAN_API_Send_Test_Notification extends BWFAN_API_Base {
	/**
	 * BWFAN_API_Base obj
	 *
	 * @var BWFCRM_Core
	 */
	public static $ins;

	/**
	 * Global settings.
	 *
	 * @var array
	 */
	protected $global_settings = array();

	/**
	 * Return class instance
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct();
		$this->method = WP_REST_Server::CREATABLE;
		$this->route  = '/send-test-notification';
	}

	/**
	 * Default arg.
	 */
	public function default_args_values() {
		return array(
			'email' => '',
		);
	}

	/**
	 * API callback
	 */
	public function process_api_call() {
		$email                 = $this->get_sanitized_arg( 'email', 'text_field' );
		$this->global_settings = BWFAN_Common::get_global_settings();
		$frequencies           = $this->get_frequencies();
		if ( empty( $frequencies ) ) {
			/** If frequency is set will try that else default */
			$frequencies = [ 'weekly' ];
		}
		// If monthly is not set then add it.
		if ( ! in_array( 'monthly', $frequencies, true ) ) {
			$frequencies[] = 'monthly';
		}

		$frequencies = BWFAN_Notification_Email::prepare_frequencies( $frequencies );
		$sent        = array();
		$errors      = new WP_Error();

		foreach ( $frequencies as $frequency => $dates ) {
			/** Prepare metrics */
			$metrics_controller = new BWFAN_Notification_Metrics_Controller( $dates, $frequency );
			$metrics_controller->prepare_data();

			$data             = $metrics_controller->get_data();
			$email_controller = new BWFAN_Notification_Email_Controller( $frequency, $data, $dates );

			$to      = $email;
			$subject = BWFAN_Notification_Email::get_email_subject( $frequency, $dates );
			$body    = $email_controller->get_content_html();
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			if ( wp_mail( $to, $subject, $body, $headers ) ) {
				$sent[ $frequency ] = true;
				break;
			} else {
				/* translators: 1: Frequency */
				$errors->add( $frequency, sprintf( __( 'Unable to send test notification for frequency: %1$s', 'wp-marketing-automations' ), $frequency ) );
			}
		}

		if ( empty( $sent ) && $errors->has_errors() ) {
			return $this->error_response( implode( ", ", $errors->get_error_messages() ), null, 500 );
		}

		return $this->success_response( '', __( 'Test Notification Sent', 'wp-marketing-automations' ) );
	}

	/**
	 * Get the frequencies for email notifications.
	 *
	 * @return array
	 */
	protected function get_frequencies() {
		if ( isset( $this->global_settings['bwf_notification_frequency'] ) && is_array( $this->global_settings['bwf_notification_frequency'] ) ) {
			return $this->global_settings['bwf_notification_frequency'];
		}

		return array();
	}
}

BWFAN_API_Loader::register( 'BWFAN_API_Send_Test_Notification' );
