<?php
defined( 'ABSPATH' ) || exit;

#[\AllowDynamicProperties]
final class BWFAN_WC_Comment_Post extends BWFAN_Event {
	private static $instance = null;
	public $comment_id = null;
	public $comment_details = null;

	private function __construct() {
		$this->optgroup_label         = esc_html__( 'Reviews', 'wp-marketing-automations' );
		$this->event_name             = esc_html__( 'Review Received', 'wp-marketing-automations' );
		$this->event_desc             = esc_html__( 'This event runs when a product review is submitted or approved.', 'wp-marketing-automations' );
		$this->event_merge_tag_groups = array( 'bwf_contact', 'wc_product', 'wc_review' );
		$this->event_rule_groups      = array(
			'wc_comment',
			'bwf_contact_segments',
			'bwf_contact',
			'bwf_contact_fields',
			'bwf_contact_user',
			'bwf_contact_wc',
			'bwf_contact_geo',
			'bwf_engagement',
			'bwf_broadcast'
		);
		$this->priority               = 35;
		$this->v2                     = true;
		$this->is_goal                = true;
		$this->optgroup_priority      = 30;
	}

	public function load_hooks() {
		add_action( 'comment_post', array( $this, 'product_review' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'my_approve_comment_callback' ), 20, 3 );
	}

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * This function is the callback function for comment hook
	 *
	 * @param $comment_id
	 * @param $status
	 */
	public function product_review( $comment_id, $status ) {
		if ( 'spam' === $status || 'trash' === $status ) {
			return;
		}
		$this->process( $comment_id, 'submission', $status );
	}

	/**
	 * Make the required data for the current event and send it asynchronously.
	 *
	 * @param $comment_id
	 */
	public function process( $comment_id, $trigger_source = 'submission', $comment_status = 1 ) {
		$data                   = $this->get_default_data();
		$data['comment_id']     = $comment_id;
		$data['trigger_source'] = $trigger_source;
		$data['comment_status'] = $comment_status;

		$this->send_async_call( $data );
	}

	/**
	 * Registers the tasks for current event.
	 *
	 * @param $automation_id
	 * @param $integration_data
	 * @param $event_data
	 */
	public function register_tasks( $automation_id, $integration_data, $event_data ) {
		if ( ! is_array( $integration_data ) ) {
			return;
		}

		$data_to_send = $this->get_event_data();

		$this->create_tasks( $automation_id, $integration_data, $event_data, $data_to_send );
	}

	public function get_event_data() {
		$data_to_send                              = [];
		$data_to_send['global']['comment_id']      = $this->comment_details['comment_id'];
		$data_to_send['global']['email']           = $this->comment_details['email'];
		$data_to_send['global']['product_id']      = isset( $this->comment_details['product_id'] ) ? $this->comment_details['product_id'] : 0;
		$data_to_send['global']['comment_message'] = isset( $this->comment_details['comment_message'] ) ? $this->comment_details['comment_message'] : '';
		$data_to_send['global']['rating_number']   = isset( $this->comment_details['rating_number'] ) ? $this->comment_details['rating_number'] : '';

		return $data_to_send;
	}

	/**
	 * This function gets fired when state of a comment is changed to approved
	 *
	 * @param $comment
	 */
	public function my_approve_comment_callback( $new_status, $old_status, $comment ) {
		if ( 'approved' !== $new_status || $old_status === $new_status ) {
			return;
		}
		$comment_id = $comment->comment_ID;
		$this->process( $comment_id, 'approval', 1 );
	}

	/**
	 * Make the view data for the current event which will be shown in task listing screen.
	 *
	 * @param $global_data
	 *
	 * @return false|string
	 */
	public function get_task_view( $global_data ) {
		ob_start();
		if ( ! is_array( $global_data ) ) {
			return '';
		}
		if ( isset( $global_data['comment_id'] ) ) {
			?>
            <li>
                <strong><?php esc_html_e( 'Comment ID:', 'wp-marketing-automations' ); ?> </strong>
				<?php echo "<a href='" . get_edit_comment_link( $global_data['comment_id'] ) . "' target='blank'>" . esc_html( $global_data['comment_id'] ) . '</a>'; //phpcs:ignore WordPress.Security.EscapeOutput ?>
            </li>
			<?php
		}
		if ( isset( $global_data['email'] ) ) {
			?>
            <li>
                <strong><?php esc_html_e( 'Email:', 'wp-marketing-automations' ); ?> </strong>
				<?php echo $global_data['email']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </li>
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * Set global data for all the merge tags which are supported by this event.
	 *
	 * @param $task_meta
	 */
	public function set_merge_tags_data( $task_meta ) {
		$wc_comment_id = BWFAN_Merge_Tag_Loader::get_data( 'wc_comment_id' );
		if ( empty( $wc_comment_id ) || $wc_comment_id !== $task_meta['global']['comment_id'] ) {
			$set_data = array(
				'wc_comment_id'      => $task_meta['global']['comment_id'],
				'email'              => $task_meta['global']['email'],
				'wc_comment_details' => $this->get_comment_feed( $task_meta['global']['comment_id'] ),
			);

			$set_data['user_id']    = $set_data['wc_comment_details']['user_id'];
			$set_data['product_id'] = $set_data['wc_comment_details']['product_id'];

			BWFAN_Merge_Tag_Loader::set_data( $set_data );
		}
	}

	/**
	 *
	 * This function is a wrapper function and it returns a single feed for comment
	 *
	 * @param $comment_id
	 *
	 * @return array
	 */
	public function get_comment_feed( $comment_id ) {
		$final_data      = array();
		$args            = array(
			'comment__in' => array( $comment_id ),
			'post_type'   => 'product',
		);
		$comment_details = get_comments( $args );
		if ( ! is_array( $comment_details ) || 0 === count( $comment_details ) ) {
			return $final_data;
		}
		$comment_details  = $comment_details[0];
		$single_feed_data = $this->get_single_comment_data( $comment_details );
		if ( ! is_array( $single_feed_data ) || 0 === count( $single_feed_data ) ) {
			return $final_data;
		}
		$final_data = $single_feed_data;

		return $final_data;
	}

	/**
	 *
	 * This function makes a single feed data for a comment
	 *
	 * @param $comment_details
	 *
	 * @return array
	 */

	public function get_single_comment_data( $comment_details ) {
		$comment_details                        = (array) $comment_details;
		$single_feed_details                    = array();
		$post_id                                = $comment_details['comment_post_ID'];
		$product_details                        = get_post( $post_id );
		$rating                                 = get_comment_meta( $comment_details['comment_ID'], 'rating', true );
		$single_feed_details['product_id']      = $product_details->ID;
		$single_feed_details['product_name']    = $product_details->post_title;
		$single_feed_details['full_name']       = $this->capitalize_word( $comment_details['comment_author'] );
		$single_feed_details['comment_message'] = $comment_details['comment_content'];
		$single_feed_details['email']           = $comment_details['comment_author_email'];
		$single_feed_details['ip']              = $comment_details['comment_author_IP'];
		$single_feed_details['rating_star']     = $rating;
		$single_feed_details['rating_number']   = $rating;
		$single_feed_details['is_verified']     = get_comment_meta( $comment_details['comment_ID'], 'verified', true );
		$single_feed_details['user_id']         = $comment_details['user_id'];
		$single_feed_details['comment_id']      = $comment_details['comment_ID'];
		$single_feed_details['comment_date']    = $comment_details['comment_date_gmt'];

		return $single_feed_details;
	}

	public function capitalize_word( $text ) {
		return ucwords( strtolower( $text ) );
	}

	/**
	 * Recalculate action's execution time with respect to order date.
	 * eg.
	 * today is 22 jan.
	 * order was placed on 17 jan.
	 * user set an email to send after 10 days of order placing.
	 * user setup the sync process.
	 * email should be sent on 27 Jan as the order date was 17 jan.
	 *
	 * @param $actions
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function recalculate_actions_time( $actions ) {
		$comment_date = $this->comment_details['comment_date'];
		$comment_date = DateTime::createFromFormat( 'Y-m-d H:i:s', $comment_date );
		$actions      = $this->calculate_actions_time( $actions, $comment_date );

		return $actions;
	}


	/**
	 * Capture the async data for the current event.
	 * @return array|bool
	 */
	public function capture_async_data() {
		$comment_id = BWFAN_Common::$events_async_data['comment_id'];

		/** v1 backward compatibility: only fire for approved reviews */
		$trigger_source = isset( BWFAN_Common::$events_async_data['trigger_source'] ) ? BWFAN_Common::$events_async_data['trigger_source'] : 'submission';
		$comment_status = isset( BWFAN_Common::$events_async_data['comment_status'] ) ? BWFAN_Common::$events_async_data['comment_status'] : 1;
		if ( 'approval' !== $trigger_source && 1 !== absint( $comment_status ) ) {
			return false;
		}

		$this->comment_id      = $comment_id;
		$this->comment_details = $this->get_comment_feed( $this->comment_id );
		if ( ! isset( $this->comment_details['product_id'] ) ) {
			return false;
		}

		return $this->run_automations();
	}

	/**
	 * Capture the async data for the current event.
	 * @return array|bool
	 */
	public function capture_v2_data( $automation_data ) {
		$this->comment_id      = BWFAN_Common::$events_async_data['comment_id'];
		$this->comment_details = $this->get_comment_feed( $this->comment_id );

		$automation_data['comment_id']      = $this->comment_id;
		$automation_data['rating_number']   = isset( $this->comment_details['rating_number'] ) ? $this->comment_details['rating_number'] : '';
		$automation_data['trigger_source']  = isset( BWFAN_Common::$events_async_data['trigger_source'] ) ? BWFAN_Common::$events_async_data['trigger_source'] : 'submission';
		$automation_data['comment_status']  = isset( BWFAN_Common::$events_async_data['comment_status'] ) ? BWFAN_Common::$events_async_data['comment_status'] : 1;

		return $automation_data;
	}

	/**
	 * Set up rules data
	 *
	 * @param $value
	 */
	public function pre_executable_actions( $value ) {
		BWFAN_Core()->rules->setRulesData( $this->event_automation_id, 'automation_id' );
		BWFAN_Core()->rules->setRulesData( $this->comment_details, 'wc_comment' );
		BWFAN_Core()->rules->setRulesData( BWFAN_Common::get_bwf_customer( $this->get_email_event(), $this->get_user_id_event() ), 'bwf_customer' );
	}

	public function get_email_event() {
		if ( ! isset( $this->comment_details['email'] ) ) {
			return false;
		}

		return $this->comment_details['email'];
	}

	public function get_user_id_event() {
		if ( ! isset( $this->comment_details['user_id'] ) ) {
			return false;
		}

		return $this->comment_details['user_id'];
	}

	/**
	 * Event settings schema for the automation builder UI.
	 *
	 * @return array
	 */
	public function get_fields_schema() {
		return array(
			array(
				'id'          => 'review_trigger',
				'label'       => __( 'Trigger When', 'wp-marketing-automations' ),
				'type'        => 'radio',
				'options'     => array(
					array(
						'label' => __( 'Review Approved', 'wp-marketing-automations' ),
						'value' => 'review_approved',
					),
					array(
						'label' => __( 'Review Received', 'wp-marketing-automations' ),
						'value' => 'review_received',
					),
				),
				'class'       => 'bwfan-input-wrapper',
				'tip'         => '',
				'required'    => true,
				'description' => '',
			),
		);
	}

	/**
	 * Default values for event settings — preserves backward compatibility.
	 *
	 * @return array
	 */
	public function get_default_values() {
		return array(
			'review_trigger' => 'review_approved',
		);
	}

	/**
	 * Validate v2 event settings against the trigger context.
	 *
	 * @param array $automation_data
	 *
	 * @return bool
	 */
	public function validate_v2_event_settings( $automation_data ) {
		$review_trigger = isset( $automation_data['event_meta']['review_trigger'] ) ? $automation_data['event_meta']['review_trigger'] : 'review_approved';
		$trigger_source = isset( $automation_data['trigger_source'] ) ? $automation_data['trigger_source'] : 'submission';
		$comment_status = isset( $automation_data['comment_status'] ) ? $automation_data['comment_status'] : 1;

		if ( 'review_received' === $review_trigger ) {
			/** Fire only on initial submission, not on later approval transition */
			return 'submission' === $trigger_source;
		}

		/** Default: review_approved — fire when approved (auto or manual) */
		if ( 'approval' === $trigger_source ) {
			return true;
		}

		/** From submission hook, only if auto-approved (status=1) */
		return 1 === absint( $comment_status );
	}

	/**
	 * Goal settings schema for the automation builder UI.
	 *
	 * @return array[]
	 */
	public function get_goal_fields_schema() {
		return array(
			array(
				'id'          => 'review_trigger',
				'label'       => __( 'Goal Met When', 'wp-marketing-automations' ),
				'type'        => 'radio',
				'options'     => array(
					array(
						'label' => __( 'Review Received', 'wp-marketing-automations' ),
						'value' => 'review_received',
					),
					array(
						'label' => __( 'Review Approved', 'wp-marketing-automations' ),
						'value' => 'review_approved',
					),
				),
				'class'       => 'bwfan-input-wrapper',
				'tip'         => '',
				'required'    => true,
				'description' => '',
				'hint'        => __( 'The goal will be met when a product review matches the selected condition.', 'wp-marketing-automations' ),
			),
		);
	}

	/**
	 * Default values for goal settings.
	 *
	 * @return array
	 */
	public function get_default_goal_values() {
		return array(
			'review_trigger' => 'review_received',
		);
	}

	/**
	 * Validate goal settings against the incoming event data.
	 *
	 * @param array $automation_settings Goal settings from the automation builder.
	 * @param array $automation_data     Event data from the trigger.
	 *
	 * @return bool
	 */
	public function validate_goal_settings( $automation_settings, $automation_data ) {
		if ( ! is_array( $automation_settings ) || ! is_array( $automation_data ) || ! isset( $automation_data['comment_id'] ) ) {
			return false;
		}

		$review_trigger = isset( $automation_settings['review_trigger'] ) ? $automation_settings['review_trigger'] : 'review_received';
		$trigger_source = isset( $automation_data['trigger_source'] ) ? $automation_data['trigger_source'] : 'submission';
		$comment_status = isset( $automation_data['comment_status'] ) ? $automation_data['comment_status'] : 1;

		if ( 'review_received' === $review_trigger ) {
			/** Goal met on initial submission */
			return 'submission' === $trigger_source;
		}

		/** review_approved: goal met when approved (auto or manual) */
		if ( 'approval' === $trigger_source ) {
			return true;
		}

		/** From submission hook, only if auto-approved (status=1) */
		return 1 === absint( $comment_status );
	}

	/**
	 * Resolve contact ID from the review's email address for goal processing.
	 *
	 * @param array $capture_args Async event data.
	 *
	 * @return int
	 */
	public function get_contact_id_for_goal( $capture_args ) {
		if ( ! is_array( $capture_args ) || ! isset( $capture_args['comment_id'] ) || empty( $capture_args['comment_id'] ) ) {
			return 0;
		}

		$comment = get_comment( absint( $capture_args['comment_id'] ) );
		if ( ! $comment instanceof WP_Comment ) {
			return 0;
		}

		$email   = $comment->comment_author_email;
		$user_id = absint( $comment->user_id );
		if ( empty( $email ) ) {
			return 0;
		}

		$contact = new WooFunnels_Contact( $user_id, $email );
		if ( ! $contact instanceof WooFunnels_Contact || 0 === absint( $contact->get_id() ) ) {
			return 0;
		}

		return absint( $contact->get_id() );
	}

}

/**
 * Register this event to a source.
 * This will show the current event in dropdown in single automation screen.
 */
if ( bwfan_is_woocommerce_active() ) {
	return 'BWFAN_WC_Comment_Post';
}
