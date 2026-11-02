<?php

/**
 * Bwfan Notification Email
 *
 * @since 1.0.0
 */
#[AllowDynamicProperties]
class BWFAN_Notification_Email {
	/**
	 * The single instance of the class.
	 *
	 * @var BWFAN_Notification_Email
	 */
	protected static $instance = null;

	/**
	 * Global settings.
	 *
	 * @var array
	 */
	protected $global_settings = array();

	/**
	 * Last executed notification.
	 *
	 * @var array
	 */
	protected $executed_last = array();

	/**
	 * Get the instance of the class.
	 *
	 * @return BWFAN_Notification_Email
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Retrieves the HTML content of a template.
	 *
	 * This method includes the specified template file and allows passing arguments to it.
	 *
	 * @param $template
	 * @param $args
	 *
	 * @return false|string
	 */
	public static function get_template_html( $template, $args = array() ) {
		if ( ! empty( $args ) && is_array( $args ) ) {
			extract( $args ); // @codingStandardsIgnoreLine
		}

		ob_start();
		include BWFAN_PLUGIN_DIR . '/templates/' . $template;

		return ob_get_clean();
	}

	/**
	 * Set the scheduler for the BWFan notification email.
	 *
	 * This function is triggered when the 'bwfan_global_settings' option is updated.
	 * It checks if the 'bwfan_notification_time' value has changed and reschedules the 'bwfan_run_notifications' action accordingly.
	 *
	 * @param array $old_value The old value of the option.
	 * @param array $value The new value of the option.
	 *
	 * @return void
	 */
	public function set_scheduler( $old_value, $value ) {
		if ( isset( $value['bwfan_enable_notification'] ) && $value['bwfan_enable_notification'] ) {
			if ( isset( $old_value['bwfan_notification_time'] ) && $old_value['bwfan_notification_time'] !== $value['bwfan_notification_time'] ) {
				if ( bwf_has_action_scheduled( 'bwfan_run_notifications' ) ) {
					bwf_unschedule_actions( 'bwfan_run_notifications' );
				}
			}

			if ( ! bwf_has_action_scheduled( 'bwfan_run_notifications' ) ) {
				$notification_time = [];
				if ( isset( $value['bwfan_notification_time'] ) && is_array( $value['bwfan_notification_time'] ) ) {
					$notification_time = $value['bwfan_notification_time'];
				}

				$timestamp = $this->create_timestamp_from_array( $notification_time );
				bwf_schedule_single_action( $timestamp, 'bwfan_run_notifications' );
			}

			return;
		}

		if ( bwf_has_action_scheduled( 'bwfan_run_notifications' ) ) {
			bwf_unschedule_actions( 'bwfan_run_notifications' );
		}
	}

	/**
	 * Create a timestamp from an array of time values.
	 *
	 * @param array $time_array An array of time values.
	 *
	 * @return int|bool The timestamp or false if required keys are missing.
	 */
	public function create_timestamp_from_array( $time_array ) {
		// Check if required keys exist in the array
		if ( isset( $time_array['hours'], $time_array['minutes'], $time_array['ampm'] ) ) {
			$hours   = intval( $time_array['hours'] );
			$minutes = intval( $time_array['minutes'] );
			$ampm    = strtolower( $time_array['ampm'] );

			if ( $ampm === 'am' && 12 === $hours ) {
				$hours = 0;
			} elseif ( $ampm === 'pm' && $hours < 12 ) {
				// Convert 12-hour format to 24-hour format
				$hours += 12;
			}

			return BWFAN_Common::get_store_time( $hours, $minutes, 0 );
		}

		return BWFAN_Common::get_store_time( 10 );
	}

	/**
	 * bwfan_run_notifications action callback
	 *
	 * @return void
	 * @throws DateMalformedStringException
	 */
	public function run_notifications() {
		/** global settings */
		$this->global_settings = BWFAN_Common::get_global_settings();

		if ( false === $this->is_notification_active() ) {
			return;
		}

		$frequencies = $this->get_frequencies();
		if ( empty( $frequencies ) ) {
			return;
		}

		/** Fetch the saved notifications data */
		$this->executed_last = get_option( 'bwfan_email_notification_updated', array(
			'daily'   => '',
			'weekly'  => '',
			'monthly' => '',
		) );

		$frequencies = $this->filter_frequencies( $frequencies );
		if ( empty( $frequencies ) ) {
			return;
		}
		$frequencies = self::prepare_frequencies( $frequencies );
		if ( empty( $frequencies ) ) {
			return;
		}

		foreach ( $frequencies as $frequency => $dates ) {
			$this->send_email( $frequency, $dates );
		}
	}

	/**
	 * Check if email notification is active.
	 *
	 * @return bool
	 */
	protected function is_notification_active() {
		return isset( $this->global_settings['bwfan_enable_notification'] ) && $this->global_settings['bwfan_enable_notification'];
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

	/**
	 * Filter the frequencies based on the last saved option key.
	 *
	 * @param array $frequencies The frequencies to filter.
	 *
	 * @return array The filtered frequencies.
	 */
	protected function filter_frequencies( $frequencies = array() ) {
		if ( empty( $frequencies ) ) {
			return array();
		}

		/** Filter out the frequencies if an email was already sent */
		return array_filter( $frequencies, function ( $frequency ) {
			return ! $this->mail_sent( $frequency );
		} );
	}

	/**
	 * Prepare frequencies
	 *
	 * @param $frequencies
	 *
	 * @return array
	 * @throws DateMalformedStringException
	 */
	public static function prepare_frequencies( $frequencies = [] ) {
		$final = array();

		if ( array_search( 'daily', $frequencies ) !== false ) {
			$final['daily'] = BWFAN_Common::get_notification_day_range();
		}

		if ( array_search( 'weekly', $frequencies ) !== false ) {
			$final['weekly'] = BWFAN_Common::get_notification_week_range();
		}

		if ( array_search( 'monthly', $frequencies ) !== false ) {
			$final['monthly'] = BWFAN_Common::get_notification_month_range();
		}

		return $final;
	}

	/**
	 * Check if the email was sent for the given frequency.
	 *
	 * @param string $frequency The frequency to check.
	 *
	 * @return bool True if the email was sent, false otherwise.
	 */
	public function mail_sent( $frequency ) {
		$today = new DateTime( 'now', wp_timezone() );

		/** Check if the last execution time for the given frequency is not set */
		if ( ! isset( $this->executed_last[ $frequency ] ) || empty( $this->executed_last[ $frequency ] ) ) {
			return false;
		}

		try {
			$last_sent = new DateTime( $this->executed_last[ $frequency ] );
			$last_sent->setTimezone( wp_timezone() );
		} catch ( Exception $e ) {
			BWFAN_Common::log_test_data( "Frequency {$frequency} and value {$this->executed_last[ $frequency ]}", 'notification-error', true );
			BWFAN_Common::log_test_data( "Exception {$e->getMessage()}", 'notification-error', true );

			return false;
		} catch ( Error $e ) {
			BWFAN_Common::log_test_data( "Frequency {$frequency} and value {$this->executed_last[ $frequency ]}", 'notification-error', true );
			BWFAN_Common::log_test_data( "Error {$e->getMessage()}", 'notification-error', true );

			return false;
		}

		switch ( $frequency ) {
			case 'daily':
				return ! ( intval( $last_sent->format( 'Ymd' ) ) < intval( $today->format( 'Ymd' ) ) );
			case 'weekly':
				return ! ( intval( $last_sent->format( 'YW' ) ) < intval( $today->format( 'YW' ) ) );
			case 'monthly':
				return ! ( intval( $last_sent->format( 'Ym' ) ) < intval( $today->format( 'Ym' ) ) );
			default:
				return false;
		}
	}

	/**
	 * Send email notification.
	 *
	 * @param $frequency
	 * @param $dates
	 *
	 * @return void
	 * @throws DateMalformedStringException
	 */
	public function send_email( $frequency, $dates ) {
		/** Prepare metrics */
		$metrics_controller = new BWFAN_Notification_Metrics_Controller( $dates, $frequency );
		$metrics_controller->prepare_data();

		/** Check if email has data */
		if ( ! $metrics_controller->is_valid() ) {
			return;
		}

		$data             = $metrics_controller->get_data();
		$email_controller = new BWFAN_Notification_Email_Controller( $frequency, $data, $dates );

		/** Check if there are no recipients */
		$to = $this->get_recipients();
		if ( empty( $to ) ) {
			return;
		}

		$subject = $this->get_email_subject( $frequency, $dates );
		$body    = $email_controller->get_content_html();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = false;
		if ( is_array( $to ) && count( $to ) > 0 ) {
			foreach ( $to as $recipient ) {
				$sent = wp_mail( $recipient, $subject, $body, $headers );
			}
		}

		/** Update the last execution time if the email was sent */
		if ( $sent ) {
			$this->executed_last[ $frequency ] = gmdate( 'c' );
			update_option( 'bwfan_email_notification_updated', $this->executed_last, false );
		}
	}

	/**
	 * Get the recipients for the email.
	 *
	 * @return array The recipients for the email.
	 */
	private function get_recipients() {
		$recipients = [];

		if ( isset( $this->global_settings['bwf_notification_user_selector'] ) && is_array( $this->global_settings['bwf_notification_user_selector'] ) ) {
			foreach ( $this->global_settings['bwf_notification_user_selector'] as $user ) {
				if ( isset( $user['id'] ) && ! empty( $user['id'] ) ) {
					$user_data = get_userdata( $user['id'] );
					if ( $user_data ) {
						$recipients[] = $user_data->user_email;
					}
				}
			}
		}

		if ( isset( $this->global_settings['bwfan_external_user'] ) && is_array( $this->global_settings['bwfan_external_user'] ) ) {
			foreach ( $this->global_settings['bwfan_external_user'] as $user ) {
				if ( isset( $user['mail'] ) && ! empty( $user['mail'] ) ) {
					$recipients[] = $user['mail'];
				}
			}
		}
		if ( empty( $recipients ) ) {
			$recipients[] = get_option( 'admin_email' );
		}

		/** Filter array */
		$recipients = array_filter( $recipients, function ( $email ) {
			return ( strpos( $email, 'support@' ) === false );
		} );

		if ( is_array( $recipients ) ) {
			$recipients = array_unique( $recipients );
			sort( $recipients );
		}

		/**
		 * Filter the email recipients before returning
		 *
		 * @param array $recipients Array of email addresses
		 */
		return apply_filters( 'bwfan_notification_recipients', $recipients );
	}

	/**
	 * Get the email subject.
	 *
	 * @param $frequency
	 * @param $dates
	 *
	 * @return string
	 * @throws DateMalformedStringException
	 */
	public static function get_email_subject( $frequency, $dates ) {
		$date_string = self::get_date_string( $dates, $frequency );
		switch ( $frequency ) {
			case 'daily':
				/* translators: 1: Dynamic Text, 2: Dynamic Date */ return sprintf( __( '%1$s - Daily Report for %2$s', 'wp-marketing-automations' ), get_bloginfo( 'name' ), $date_string );
			case 'weekly':
				/* translators: 1: Dynamic Text, 2: Dynamic Date */ return sprintf( __( '%1$s - Weekly Report for %2$s', 'wp-marketing-automations' ), get_bloginfo( 'name' ), $date_string );
			case 'monthly':
				/* translators: 1: Dynamic Text, 2: Dynamic Date */ return sprintf( __( '%1$s - Monthly Report for %2$s', 'wp-marketing-automations' ), get_bloginfo( 'name' ), $date_string );
			default:
				return '';
		}
	}

	/**
	 * Get the date string for the email subject.
	 *
	 * @param $dates
	 * @param $frequency
	 *
	 * @return string
	 * @throws DateMalformedStringException
	 */
	public static function get_date_string( $dates = array(), $frequency = 'weekly' ) {
		if ( 'daily' === $frequency && isset( $dates['from_date'] ) ) {
			return self::format_date( $dates['from_date'] );
		}

		if ( isset( $dates['from_date'] ) && isset( $dates['to_date'] ) ) {
			/* translators: 1: Dynamic From Date, 2: Dynamic to date */
			return sprintf( __( '%1$s - %2$s', 'wp-marketing-automations' ), self::format_date( $dates['from_date'] ), self::format_date( $dates['to_date'] ) );
		}

		return '';
	}

	/**
	 * Formats a date string to the desired format.
	 *
	 * @param $date_string
	 *
	 * @return string
	 * @throws DateMalformedStringException
	 */
	public static function format_date( $date_string ) {
		/** Convert date string to a DateTime object */
		$date = new DateTime( $date_string );
		$date->setTimezone( wp_timezone() );

		return $date->format( 'F j, Y' );
	}

	/**
	 * Testing email notification.
	 */
	public function test_notification_admin() {
		if ( false === current_user_can( 'administrator' ) ) {
			return;
		}
		if ( ! isset( $_GET['bwfan_email_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin email preview; capability checked
			return;
		}
		$mode = filter_input( INPUT_GET, 'bwfan_mode' );
		$mode = $mode ? sanitize_text_field( $mode ) : '';
		$mode = empty( $mode ) ? 'weekly' : $mode;

		switch ( $mode ) {
			case 'monthly':
				$range = BWFAN_Common::get_notification_month_range();
				break;
			case 'daily':
				$range = BWFAN_Common::get_notification_day_range();
				break;
			default:
				$range = BWFAN_Common::get_notification_week_range();
				$mode  = 'weekly';
				break;
		}

		$dates = array(
			'from_date'          => $range['from_date'],
			'to_date'            => $range['to_date'],
			'from_date_previous' => $range['from_date_previous'],
			'to_date_previous'   => $range['to_date_previous'],
		);

		/** Prepare metrics */
		$metrics_controller = new BWFAN_Notification_Metrics_Controller( $dates, $mode );
		$metrics_controller->prepare_data();

		$data             = $metrics_controller->get_data();
		$email_controller = new BWFAN_Notification_Email_Controller( $mode, $data, $dates );

		echo $email_controller->get_content_html(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Send test email notification.
	 */
	public function send_test_email_notification() {
		if ( ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( __( 'You do not have permission to perform this action.', 'wp-marketing-automations' ) );
		}

		$mode = filter_input( INPUT_GET, 'bwfan_mode' );
		$mode = $mode ? sanitize_text_field( $mode ) : '';
		$mode = empty( $mode ) ? 'weekly' : $mode;

		switch ( $mode ) {
			case 'monthly':
				$range = BWFAN_Common::get_notification_month_range();
				break;
			case 'daily':
				$range = BWFAN_Common::get_notification_day_range();
				break;
			default:
				$range = BWFAN_Common::get_notification_week_range();
				$mode  = 'weekly';
				break;
		}

		$dates = array(
			'from_date'          => $range['from_date'],
			'to_date'            => $range['to_date'],
			'from_date_previous' => $range['from_date_previous'],
			'to_date_previous'   => $range['to_date_previous'],
		);

		/** Prepare metrics */
		$metrics_controller = new BWFAN_Notification_Metrics_Controller( $dates, $mode );
		$metrics_controller->prepare_data();

		$data             = $metrics_controller->get_data();
		$email_controller = new BWFAN_Notification_Email_Controller( $mode, $data, $dates );

		$to      = get_option( 'admin_email' );
		$subject = self::get_email_subject( $mode, $dates );
		$body    = $email_controller->get_content_html();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( $sent ) {
			wp_send_json_success( __( 'Email sent successfully.', 'wp-marketing-automations' ) );
		} else {
			wp_send_json_error( __( 'Failed to send email.', 'wp-marketing-automations' ) );
		}
	}

	/**
	 * Function to set the default settings for notification settings
	 *
	 * @return void
	 */
	public static function set_bwfan_settings() {
		$bwfan_settings = BWFAN_Common::get_global_settings();
		if ( isset( $bwfan_settings['bwfan_enable_notification'] ) && ! empty( $bwfan_settings['bwfan_enable_notification'] ) ) {
			return;
		}

		$new_settings = array(
			'bwfan_enable_notification'  => true,
			'bwf_notification_frequency' => array( 'weekly', 'monthly' ),
			'bwfan_notification_time'    => array(
				'hours'   => '10',
				'minutes' => '00',
				'ampm'    => 'am',
			),
			'bwfan_external_user'        => array(),
		);

		$bwfan_settings = array_merge( $bwfan_settings, $new_settings );

		$users         = get_users( array( 'role' => 'administrator' ) );
		$user_selector = array();

		foreach ( $users as $user ) {
			$user_selector[] = array(
				'id'   => $user->ID,
				'name' => $user->display_name . ' ( ' . $user->user_email . ' )',
			);
		}

		$bwfan_settings['bwf_notification_user_selector'] = $user_selector;

		/** Set scheduler */
		$old_settings = array(
			'bwfan_notification_time' => array(
				'hours'   => '09',
				'minutes' => '00',
				'ampm'    => 'am',
			),
		);
		self::get_instance()->set_scheduler( $old_settings, $new_settings );

		update_option( 'bwfan_global_settings', $bwfan_settings );
	}
}
