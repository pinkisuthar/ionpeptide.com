<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * Class BWFAN_Notification_Metrics_Controller
 */
#[\AllowDynamicProperties]
class BWFAN_Notification_Metrics_Controller {
	protected $data = array();
	protected $date_params = array();

	private $frequency = '';

	/**
	 * Constructor.
	 *
	 * @param array $date_params
	 */
	public function __construct( $date_params = array(), $frequency = 'weekly' ) {
		$this->date_params = wp_parse_args( $date_params, array(
			'from_date'          => gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) ),
			'to_date'            => gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) ),
			'from_date_previous' => gmdate( 'Y-m-d 00:00:00', strtotime( '-2 day' ) ),
			'to_date_previous'   => gmdate( 'Y-m-d 23:59:59', strtotime( '-2 day' ) ),
		) );

		$this->frequency = $frequency;
	}

	/**
	 * Get metrics.
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Prepare data.
	 */
	public function prepare_data() {
		$this->data['metrics'] = array();

		$this->data['metrics']['total_contacts'] = $this->get_total_contacts();

		$this->data['metrics'] = array_merge( $this->data['metrics'], $this->get_total_engagement_sent() );

		if ( class_exists( 'WooCommerce' ) ) {
			$this->data['metrics'] = array_merge( $this->data['metrics'], $this->get_total_carts() );
			$this->data['metrics'] = array_merge( $this->data['metrics'], $this->get_conversions() );
		}
	}

	/**
	 * Get percentage change.
	 *
	 * @param int $previous_value The previous value.
	 * @param int $current_value The current value.
	 *
	 * @return float
	 */
	public function get_percentage_change( $previous_value = 0, $current_value = 0 ) {
		switch ( $previous_value ) {
			case 0:
				return $current_value * 100;
			default:
				return ( ( $current_value - $previous_value ) / $previous_value ) * 100;
		}
	}

	/**
	 * Get total contacts.
	 *
	 * @return array
	 */
	public function get_total_contacts() {
		$contacts          = BWFAN_Dashboards::get_total_contacts( $this->date_params['from_date'], $this->date_params['to_date'] );
		$previous_contacts = BWFAN_Dashboards::get_total_contacts( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'] );

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_contacts, $contacts );

		return array(
			'text'                                              => __( 'New Contacts', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count'                                             => $contacts,
			'previous_count'                                    => $previous_contacts,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Get total engagement sent.
	 *
	 * @return array
	 */
	public function get_total_engagement_sent() {
		$engagement_sent          = BWFAN_Dashboards::get_total_engagement_sents( $this->date_params['from_date'], $this->date_params['to_date'], '', '' );
		$previous_engagement_sent = BWFAN_Dashboards::get_total_engagement_sents( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'], '', '' );

		$engagement_open          = BWFAN_Dashboards::get_total_email_open( $this->date_params['from_date'], $this->date_params['to_date'], '', '' );
		$previous_engagement_open = BWFAN_Dashboards::get_total_email_open( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'], '', '' );

		$engagement_click          = BWFAN_Dashboards::get_total_email_click( $this->date_params['from_date'], $this->date_params['to_date'], '', '' );
		$previous_engagement_click = BWFAN_Dashboards::get_total_email_click( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'], '', '' );

		return array(
			'email_sent'  => $this->get_total_email_sent( $engagement_sent, $previous_engagement_sent ),
			'email_open'  => $this->get_total_email_open( $engagement_open, $previous_engagement_open ),
			'email_click' => $this->get_total_email_click( $engagement_click, $previous_engagement_click ),
		);
	}

	/**
	 * Get total email click.
	 *
	 * @param array $engagement_click The engagement sent data for the current date range.
	 * @param array $previous_engagement_click The engagement sent data for the previous date range.
	 *
	 * @return array
	 */
	public function get_total_email_click( $engagement_click, $previous_engagement_click ) {
		$email_click          = isset( $engagement_click[0]['email_click'] ) ? $engagement_click[0]['email_click'] : 0;
		$previous_email_click = isset( $previous_engagement_click[0]['email_click'] ) ? $previous_engagement_click[0]['email_click'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_email_click, $email_click );

		return array(
			'text'                                              => __( 'Emails Clicked', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count'                                             => $email_click,
			'previous_count'                                    => $previous_email_click,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Get total email open.
	 *
	 * @param array $engagement_sent The engagement sent data for the current date range.
	 * @param array $previous_engagement_sent The engagement sent data for the previous date range.
	 *
	 * @return array
	 */
	public function get_total_email_open( $engagement_open, $previous_engagement_open ) {
		$email_open          = isset( $engagement_open[0]['email_open'] ) ? $engagement_open[0]['email_open'] : 0;
		$previous_email_open = isset( $previous_engagement_open[0]['email_open'] ) ? $previous_engagement_open[0]['email_open'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_email_open, $email_open );

		return array(
			'text'                                              => __( 'Emails Opened', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count'                                             => $email_open,
			'previous_count'                                    => $previous_engagement_open,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Get total email sent.
	 *
	 * @param array $engagement_sent The engagement sent data for the current date range.
	 * @param array $previous_engagement_sent The engagement sent data for the previous date range.
	 *
	 * @return array
	 */
	public function get_total_email_sent( $engagement_sent, $previous_engagement_sent ) {
		$email_sent          = isset( $engagement_sent[0]['email_sents'] ) ? $engagement_sent[0]['email_sents'] : 0;
		$previous_email_sent = isset( $previous_engagement_sent[0]['email_sents'] ) ? $previous_engagement_sent[0]['email_sents'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_email_sent, $email_sent );

		return array(
			'text'                                              => __( 'Emails Sent', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count'                                             => $email_sent,
			'previous_count'                                    => $previous_email_sent,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Get total sms sent.
	 *
	 * @param array $engagement_sent The engagement sent data for the current date range.
	 * @param array $previous_engagement_sent The engagement sent data for the previous date range.
	 *
	 * @return array
	 */
	public function get_total_sms_sent( $engagement_sent, $previous_engagement_sent ) {
		$sms_sent          = isset( $engagement_sent[0]['sms_sent'] ) ? $engagement_sent[0]['sms_sent'] : 0;
		$previous_sms_sent = isset( $previous_engagement_sent[0]['sms_sent'] ) ? $previous_engagement_sent[0]['sms_sent'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_sms_sent, $sms_sent );

		return array(
			'text'                                              => __( 'SMS Sent', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count'                                             => $sms_sent,
			'previous_count'                                    => $previous_sms_sent,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Get total carts.
	 *
	 * @return array
	 */
	private function get_total_carts() {
		require_once BWFAN_PLUGIN_DIR . '/includes/class-bwfan-cart-analytics.php';

		$captured_cart          = BWFAN_Cart_Analytics::get_captured_cart( $this->date_params['from_date'], $this->date_params['to_date'] );
		$previous_captured_cart = BWFAN_Cart_Analytics::get_captured_cart( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'] );

		$recovered_cart          = BWFAN_Cart_Analytics::get_recovered_cart( $this->date_params['from_date'], $this->date_params['to_date'] );
		$previous_recovered_cart = BWFAN_Cart_Analytics::get_recovered_cart( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'] );

		$recovered_count          = isset( $recovered_cart[0]['count'] ) ? $recovered_cart[0]['count'] : 0;
		$previous_recovered_count = isset( $previous_recovered_cart[0]['count'] ) ? $previous_recovered_cart[0]['count'] : 0;

		// Calculate percentage change
		$recovered_percentage_change = $this->get_percentage_change( $previous_recovered_count, $recovered_count );

		$count          = isset( $captured_cart[0]['count'] ) ? $captured_cart[0]['count'] : 0;
		$previous_count = isset( $previous_captured_cart[0]['count'] ) ? $previous_captured_cart[0]['count'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_count, $count );

		return array(
			array(
				'text'                                              => __( 'Carts Captured', 'wp-marketing-automations' ),
				/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
				'count'                                             => $count,
				'previous_count'                                    => $previous_count,
				'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
				'percentage_change_positive'                        => $percentage_change >= 0,
			),
			array(
				'text'                                              => __( 'Carts Recovered', 'wp-marketing-automations' ),
				/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
				'count'                                             => $recovered_count,
				'previous_count'                                    => $previous_recovered_count,
				'percentage_change'                                 => sprintf( '%s%%', round( $recovered_percentage_change, 2 ) ),
				'percentage_change_positive'                        => $recovered_percentage_change >= 0,
			),
		);
	}

	/**
	 * Get conversions.
	 *
	 * @return array
	 */
	public function get_conversions() {
		$total_orders          = BWFAN_Dashboards::get_total_orders( $this->date_params['from_date'], $this->date_params['to_date'], '', '' );
		$previous_total_orders = BWFAN_Dashboards::get_total_orders( $this->date_params['from_date_previous'], $this->date_params['to_date_previous'], '', '' );

		return array(
			'total_orders'  => $this->get_total_orders( $total_orders, $previous_total_orders ),
			'total_revenue' => $this->get_total_revenue( $total_orders, $previous_total_orders ),
		);
	}

	/**
	 * Get total orders.
	 *
	 * @param array $total_orders The total orders data for the current date range.
	 * @param array $previous_total_orders The total orders data for the previous date range.
	 *
	 * @return array
	 */
	public function get_total_orders( $total_orders, $previous_total_orders ) {
		$total_orders          = isset( $total_orders[0]['total_orders'] ) ? $total_orders[0]['total_orders'] : 0;
		$previous_total_orders = isset( $previous_total_orders[0]['total_orders'] ) ? $previous_total_orders[0]['total_orders'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_total_orders, $total_orders );

		return array(
			'text'                                              => __( 'Total Orders', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count'                                             => $total_orders,
			'previous_count'                                    => $previous_total_orders,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Get total revenue.
	 *
	 * @param array $total_orders The total orders data for the current date range.
	 * @param array $previous_total_orders The total orders data for the previous date range.
	 *
	 * @return array
	 */
	public function get_total_revenue( $total_orders, $previous_total_orders ) {
		$total_revenue          = isset( $total_orders[0]['total_revenue'] ) ? $total_orders[0]['total_revenue'] : 0;
		$previous_total_revenue = isset( $previous_total_orders[0]['total_revenue'] ) ? $previous_total_orders[0]['total_revenue'] : 0;

		// Calculate percentage change
		$percentage_change = $this->get_percentage_change( $previous_total_revenue, $total_revenue );

		return array(
			'text'                                              => __( 'Total Revenue', 'wp-marketing-automations' ),
			/* translators: 1: Dynamic Text. */ 'previous_text' => sprintf( __( '- Previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_text() ),
			'count_suffix'                                      => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : __( 'USD', 'wp-marketing-automations' ),
			'count'                                             => round( $total_revenue, 2 ),
			'previous_count'                                    => $previous_total_revenue,
			'percentage_change'                                 => sprintf( '%s%%', round( $percentage_change, 2 ) ),
			'percentage_change_positive'                        => $percentage_change >= 0,
		);
	}

	/**
	 * Check if email has data and ready to go
	 *
	 * @return bool
	 */
	public function is_valid() {
		$is_valid = false;
		foreach ( $this->data['metrics'] as $metric ) {
			if ( floatval( $metric['count'] ) > 0 ) {
				$is_valid = true;
				break;
			}
		}

		return $is_valid;
	}

	protected function get_frequency_text( $capitalized = false ) {
		if ( 'daily' === $this->frequency ) {
			return $capitalized ? __( 'Day', 'wp-marketing-automations' ) : __( 'day', 'wp-marketing-automations' );
		}
		if ( 'monthly' === $this->frequency ) {
			return $capitalized ? __( 'Month', 'wp-marketing-automations' ) : __( 'month', 'wp-marketing-automations' );
		}

		return $capitalized ? __( 'Week', 'wp-marketing-automations' ) : __( 'week', 'wp-marketing-automations' );
	}
}
