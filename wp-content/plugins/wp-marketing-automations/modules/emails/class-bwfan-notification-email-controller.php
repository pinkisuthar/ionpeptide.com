<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

#[\AllowDynamicProperties]
class BWFAN_Notification_Email_Controller {
	private $frequency = '';
	private $id = '';
	private $data = array();
	private $dates = array();

	/**
	 * Constructor.
	 *
	 * @param array $data
	 */
	public function __construct( $frequency, $data = array(), $dates = array() ) {
		$this->frequency = $frequency;
		$this->id        = $frequency . '_' . 'report';
		$this->data      = $data;
		$this->dates     = $dates;
	}

	/**
	 * Retrieves the email sections for the notification email.
	 *
	 * This function constructs an array of email sections that will be used to build the notification email.
	 * The email sections include headers, highlights, performance metrics, automation statuses, dynamic content, and footer.
	 *
	 * @return array The array of email sections.
	 */
	public function get_email_sections() {
		$global_settings = BWFAN_Common::get_global_settings();
		/* translators: 1: Dynamic From Date, 2: Dynamic to date */
		$date_range = sprintf( __( '%1$s - %2$s', 'wp-marketing-automations' ), BWFAN_Notification_Email::format_date( $this->dates['from_date'] ), BWFAN_Notification_Email::format_date( $this->dates['to_date'] ) );
		if ( 'daily' === $this->frequency ) {
			$date_range = BWFAN_Notification_Email::format_date( $this->dates['from_date'] );
		}

		$upgrade_link = BWFAN_Common::get_fk_site_links();
		$upgrade_link = isset( $upgrade_link['upgrade'] ) ? $upgrade_link['upgrade'] : '';

		$highlight_subtitle    = __( 'Analyse key automation metrics and see how well your store performed.', 'wp-marketing-automations' );
		$highlight_button_text = __( 'View Detail Report', 'wp-marketing-automations' );
		$highlight_button_url  = admin_url( 'admin.php?page=autonami' );
		if ( false === bwfan_is_autonami_pro_active() ) {
			$highlight_subtitle    = __( 'Analyse key automation metrics and see how well your store performed. Unlock more insights.', 'wp-marketing-automations' );
			$highlight_button_text = __( 'Upgrade To PRO', 'wp-marketing-automations' );

			$highlight_button_url = add_query_arg( [
				'utm_campaign' => 'FKA+Lite+Notification',
				'utm_medium'   => 'Email+Highlight'
			], $upgrade_link );
		}

		$get_total_orders = bwfan_is_woocommerce_active() ? BWFAN_Dashboards::get_total_orders( '', '', '', '' ) : [];
		$total_revenue    = ! isset( $get_total_orders[0]['total_revenue'] ) ? 0 : $get_total_orders[0]['total_revenue'];
		$total_revenue    = floatval( $total_revenue );

		$theme = array(
			'date'        => $date_range,
			'title'       => __( 'Performance Report', 'wp-marketing-automations' ),
			'subtitle'    => $highlight_subtitle,
			'button_text' => $highlight_button_text,
			'button_url'  => $highlight_button_url,
			'theme'       => 'light',
		);

		$notification = BWFAN_Common::sale_notification_menu();

		if ( false === bwfan_is_autonami_pro_active() && ! empty( $notification ) ) {
			$theme['theme']    = 'dark';
			$title             = ! empty( $notification['title'] ) ? $notification['title'] : __( 'Black Friday', 'wp-marketing-automations' );
			/* translators: %s: Notification title, e.g. Black Friday */
			$theme['subtitle'] = sprintf( __( '💰 %s is HERE - Subscribe Now for Upto 55%% Off 💰', 'wp-marketing-automations' ), $title );
			if ( ! empty( $notification['campaign'] ) ) {
				$theme['button_url'] = add_query_arg( [
					'utm_campaign' => 'FKA+Lite+Notification+' . $notification['campaign'],
					'utm_medium'   => 'Email+Highlight'
				], $upgrade_link );
			}
		}

		$email_sections = array(
			array(
				'type' => 'email_header',
			),
			array(
				'type' => 'highlight',
				'data' => $theme,
			),
			array(
				'type'     => 'dynamic',
				'callback' => array( $this, 'get_dynamic_content_1' ),
			),
			array(
				'type' => 'bwfan_status_section',
				'data' => apply_filters( 'bwfan_weekly_mail_status_section', [] ),
			),
			array(
				'type' => 'section_header',
				'data' => array(
					'title'    => __( 'Key Performance Metrics', 'wp-marketing-automations' ),
					/* translators: 1: Dynamic Data */
					'subtitle' => sprintf( __( 'Change compared to previous %1$s', 'wp-marketing-automations' ), $this->get_frequency_string( $this->frequency ) ),
				),
			),
		);

		// Chunk the original array into groups of 2
		$chunks = array_chunk( $this->data['metrics'], 2, true );

		$tile_data = [];

		foreach ( $chunks as $chunk ) {
			// If the chunk has less than 2 metrics, ignore it
			if ( count( $chunk ) < 2 ) {
				continue;
			}
			$tile_data[] = array(
				reset( $chunk ), // First metric in the chunk
				end( $chunk ),     // Second metric in the chunk
			);
		}

		if ( ! empty( $tile_data ) ) {
			$email_sections[] = array(
				'type' => 'metrics',
				'data' => array(
					'tile_data' => $tile_data,
				),
			);
		}

		if ( $total_revenue > 10 ) {
			/* translators: 1: Dynamic Data, 2: Dynamic Revenue */
			$cta_content = sprintf( __( 'Since installing %1$s you have captured additional revenue of %2$s.', 'wp-marketing-automations' ), '<strong>' . __( 'FunnelKit Automation', 'wp-marketing-automations' ) . '</strong>', '<strong>' . wc_price( $total_revenue ) . '</strong>' );
			if ( false === bwfan_is_autonami_pro_active() ) {
				/* translators: 1: Dynamic Data, 2: Dynamic Revenue */
				$cta_content      = sprintf( __( 'Since installing %1$s you have captured additional revenue of %2$s. Upgrade to Pro for even more revenue.', 'wp-marketing-automations' ), '<strong>' . __( 'FunnelKit Automation', 'wp-marketing-automations' ) . '</strong>', '<strong>' . wc_price( $total_revenue ) . '</strong>' );
				$cta_link         = add_query_arg( [
					'utm_campaign' => 'FKA+Lite+Notification',
					'utm_medium'   => 'Total+Revenue'
				], $upgrade_link );
				$email_sections[] = array(
					'type' => 'bwfan_status_section',
					'data' => [
						'content'           => $cta_content,
						'link'              => $cta_link,
						'link_text'         => __( 'Upgrade To PRO', 'wp-marketing-automations' ),
						'background_color'  => '#FEF7E8',
						'button_color'      => '#FFC65C',
						'button_text_color' => '#000000',
					]
				);
			} else {
				$email_sections[] = array(
					'type' => 'bwfan_status_w_cta_section',
					'data' => [
						'content'           => $cta_content,
						'background_color'  => '#FEF7E8',
						'button_color'      => '#FFC65C',
						'button_text_color' => '#000000',
					]
				);
			}

		}

		if ( class_exists( 'WooCommerce' ) ) {
			$todos = $this->get_todo_lists();

			if ( ! empty( $todos ) ) {
				$email_sections[] = array(
					'type' => 'section_header',
					'data' => array(
						'title'    => __( 'Get More From FunnelKit', 'wp-marketing-automations' ),
						'subtitle' => __( 'Go through the checklist and watch your sales soar', 'wp-marketing-automations' ),
					),
				);

				$link = add_query_arg( [
					'utm_campaign' => 'FKA+Lite+Notification',
					'utm_medium'   => 'Todo'
				], $upgrade_link );

				$email_sections[] = array(
					'type' => 'todo_status',
					'data' => array(
						'todolist'     => $todos,
						'upgrade_link' => $link
					),
				);
			}

		}

		$email_sections = array_merge( $email_sections, array(
			array(
				'type'     => 'dynamic',
				'callback' => array( $this, 'get_dynamic_content_2' ),
			),
			array(
				'type' => 'email_footer',
				'data' => array(
					'date'             => $date_range,
					'business_name'    => ! empty( $global_settings['bwfan_setting_business_name'] ) ? $global_settings['bwfan_setting_business_name'] : get_bloginfo( 'name' ),
					'business_address' => ! empty( $global_settings['bwfan_setting_business_address'] ) ? $global_settings['bwfan_setting_business_address'] : '',
				),
			),
		) );

		return apply_filters( 'bwfan_weekly_notification_email_section', $email_sections );
	}

	/**
	 * Returns the HTML content for the email.
	 *
	 * @return string The HTML content of the email.
	 */
	public function get_content_html() {
		$email_sections = $this->get_email_sections();

		ob_start();

		foreach ( $email_sections as $section ) {
			if ( empty( $section['type'] ) ) {
				continue;
			}
			switch ( $section['type'] ) {
				case 'email_header':
					echo BWFAN_Notification_Email::get_template_html( 'emails/email-header.php' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'highlight':
					echo BWFAN_Notification_Email::get_template_html( 'emails/admin-email-report-highlight.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'metrics':
					echo BWFAN_Notification_Email::get_template_html( 'emails/admin-email-report-metrics.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'section_header':
					echo BWFAN_Notification_Email::get_template_html( 'emails/email-section-header.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'todo_status':
					echo BWFAN_Notification_Email::get_template_html( 'emails/admin-email-report-todo-status.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'divider':
					echo BWFAN_Notification_Email::get_template_html( 'emails/email-divider.php' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'email_footer':
					echo BWFAN_Notification_Email::get_template_html( 'emails/email-footer.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					break;
				case 'dynamic':
					if ( isset( $section['callback'] ) && is_callable( $section['callback'] ) ) {
						call_user_func( $section['callback'], $section['data'] ?? [] );
					}
					break;
				case 'bwfan_status_section':
					if ( ! empty( $section['data'] ) ) {
						echo BWFAN_Notification_Email::get_template_html( 'emails/email-bwfan-status-section.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					break;
				case 'bwfan_status_w_cta_section':
					if ( ! empty( $section['data'] ) ) {
						echo BWFAN_Notification_Email::get_template_html( 'emails/email-bwfan-status-w-btn-section.php', $section['data'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					break;
				default:
					do_action( 'bwfan_email_section_' . $section['type'], isset( $section['data'] ) ? $section['data'] : [] );
					break;
			}
		}

		return ob_get_clean();
	}

	/**
	 * Returns the dynamic content for the email.
	 *
	 * @return string The dynamic content of the email.
	 */
	public function get_dynamic_content_1() {
		do_action( 'bwfan_email_dynamic_content_1', $this->id, $this->data, $this->dates );
	}

	/**
	 * Returns the dynamic content for the email.
	 *
	 * @return string The dynamic content of the email.
	 */
	public function get_dynamic_content_2() {
		do_action( 'bwfan_email_dynamic_content_2', $this->id, $this->data, $this->dates );
	}

	/**
	 * Retrieves the active automations from the database.
	 *
	 * This function queries the database to fetch the distinct 'event' values from the 'bwfan_automations' table
	 * where the 'v' column is equal to 2 and the 'status' column is equal to 1.
	 *
	 * @return array An array of distinct 'event' values from the 'bwfan_automations' table.
	 * @global wpdb $wpdb The WordPress database object.
	 */
	public function get_active_automations() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'bwfan_automations';

		$results = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT `event` FROM `$table_name` WHERE `v` = %d AND `status` = %d", 2, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from $wpdb->prefix; values bound via prepare()

		return $results;
	}

	/**
	 * Get all todos with their status
	 *
	 * @return array|array[]
	 */
	public function get_todo_lists() {
		$to_dos = array(
			'contact_created'      => array(
				'title' => __( 'Create or Import Contacts', 'wp-marketing-automations' ),
				'link'  => esc_url( admin_url( 'admin.php?page=autonami&path=/contacts' ) ),
			),
			'automation_created'   => array(
				'title' => __( 'Create Automation', 'wp-marketing-automations' ),
				'link'  => esc_url( admin_url( 'admin.php?page=autonami&path=/automations' ) ),
			),
			'email_settings_saved' => array(
				'title' => __( 'Complete Email Settings', 'wp-marketing-automations' ),
				'link'  => esc_url( admin_url( 'admin.php?page=autonami&path=/settings' ) ),
			),
			'audience_created'     => array(
				'title' => __( 'Create Audience', 'wp-marketing-automations' ),
				'link'  => esc_url( admin_url( 'admin.php?page=autonami&path=/manage/audiences' ) ),
			),
			'broadcast_created'    => array(
				'title' => __( 'Create Broadcast', 'wp-marketing-automations' ),
				'link'  => esc_url( admin_url( 'admin.php?page=autonami&path=/broadcasts/email' ) ),
				'last'  => true,
			),
		);

		$incomplete_todo = 0;
		foreach ( $to_dos as $key => $to_do ) {
			$method_name = 'metric_' . $key;
			$status      = method_exists( $this, $method_name ) ? $this->$method_name() : false;

			if ( 'active' !== $status ) {
				$incomplete_todo = 1;
			}

			$to_dos[ $key ]['status'] = $status;
		}

		if ( 0 === intval( $incomplete_todo ) ) {
			return [];
		}

		return $to_dos;
	}

	/**
	 * Returns the frequency string based on the given frequency.
	 *
	 * @param string $frequency The frequency value.
	 *
	 * @return string The frequency string.
	 */
	public function get_frequency_string( $frequency ) {
		switch ( $frequency ) {
			case 'daily':
				return __( 'day', 'wp-marketing-automations' );
			case 'weekly':
				return __( 'week', 'wp-marketing-automations' );
			case 'monthly':
				return __( 'month', 'wp-marketing-automations' );
			default:
				return '';
		}
	}

	protected function metric_contact_created() {
		$id = BWFCRM_Model_Contact::get_first_contact_id();

		return intval( $id ) > 0 ? 'active' : 'inactive';
	}

	protected function metric_email_settings_saved() {
		$data = BWFAN_Common::get_global_settings();
		if ( ! isset( $data['bwfan_setting_business_name'] ) || ! isset( $data['bwfan_setting_business_address'] ) || empty( $data['bwfan_setting_business_name'] ) || empty( $data['bwfan_setting_business_address'] ) ) {
			return 'inactive';
		}

		return 'active';
	}

	protected function metric_automation_created() {
		$id = BWFAN_Model_Automations::get_first_automation_id();

		return intval( $id ) > 0 ? 'active' : 'inactive';
	}

	protected function metric_audience_created() {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return 'pro';
		}

		$id = method_exists( 'BWFCRM_Audience', 'get_first_audience_id' ) ? BWFCRM_Audience::get_first_audience_id() : null;

		return intval( $id ) > 0 ? 'active' : 'inactive';
	}

	protected function metric_broadcast_created() {
		if ( ! bwfan_is_autonami_pro_active() ) {
			return 'pro';
		}

		$id = BWFAN_Model_Broadcast::get_first_broadcast_id();

		return intval( $id ) > 0 ? 'active' : 'inactive';
	}
}
