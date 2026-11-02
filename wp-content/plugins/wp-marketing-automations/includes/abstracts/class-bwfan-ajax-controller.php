<?php
defined( 'ABSPATH' ) || exit;

/**
 * Class BWFAN_AJAX_Controller
 * Handles All the request came from front end or the backend
 */
#[\AllowDynamicProperties]
abstract class BWFAN_AJAX_Controller {

	public static function init() {
		if ( is_admin() && BWFAN_Common::is_automation_v1_active() ) {
			/**
			 * Register authenticated admin AJAX handlers only when automation v1 is active.
			 */
			add_action( 'wp_ajax_bwfan_update_automation', array( __CLASS__, 'update_automation' ) );
			add_action( 'wp_ajax_bwfan_toggle_automation_state', array( __CLASS__, 'toggle_automation_state' ) );
			add_action( 'wp_ajax_bwfan_select2ajax', array( __CLASS__, 'bwfan_select2ajax' ) );
			add_action( 'wp_ajax_bwfan_show_email_preview', array( __CLASS__, 'bwfan_save_temporary_preview_data' ) );
			add_action( 'wp_ajax_bwfan_automation_submit', array( __CLASS__, 'handle_automation_post_submit' ) );
		}

		add_action( 'wp_ajax_bwfan_test_sms', array( __CLASS__, 'test_sms' ) );
		add_action( 'wp_ajax_bwfan_test_email', array( __CLASS__, 'test_email' ) );
	}

	public static function bwfan_select2ajax() {
		BWFAN_Common::check_nonce();

		/**
		 * Rule classes (BWFAN_Dynamic_Option_Base) hook 'bwfan_select2_ajax_callable' in their constructor,
		 * so the posted rule must be instantiated before the filter runs. Nothing else loads rules on admin-ajax.
		 */
		if ( BWFAN_Core()->rules instanceof BWFAN_Rules ) {
			BWFAN_Core()->rules->load_rules_classes();
			if ( ! empty( $_POST['ruletype'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification, FunnelBuilder.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck -- check_nonce() above verifies nonce + capability
				BWFAN_Core()->rules->woocommerce_bwfan_rule_get_rule_object( sanitize_text_field( wp_unslash( $_POST['ruletype'] ) ) ); //phpcs:ignore WordPress.Security.NonceVerification, FunnelBuilder.CodeAnalysis.FunnelBuilderSpecific.MissingCapabilityCheck
			}
		}

		$callback = apply_filters( 'bwfan_select2_ajax_callable', '', $_POST ); //phpcs:ignore WordPress.Security.NonceVerification
		if ( ! is_callable( $callback ) ) {
			wp_send_json( [ 'results' => [] ] );
		}

		$items = call_user_func( $callback, sanitize_text_field( $_POST['search_term']['term'] ) );//phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		wp_send_json( $items );
	}

	/**
	 * Runs when an automation is saved from single automation screen.
	 * @throws Exception
	 */
	public static function handle_automation_post_submit() {
		BWFAN_Common::check_nonce();

		//phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_POST['automation_id'] ) || empty( $_POST['automation_id'] ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		$automation_id = absint( $_POST['automation_id'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$a_track_id    = ( isset( $_POST['a_track_id'] ) && ! empty( $_POST['a_track_id'] ) ) ? sanitize_text_field( $_POST['a_track_id'] ) : 0; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$t_to_delete   = ( isset( $_POST['t_to_delete'] ) && ! empty( $_POST['t_to_delete'] ) ) ? sanitize_text_field( stripslashes( $_POST['t_to_delete'] ) ) : null; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		//make sure following is in array just like following
		$data    = stripslashes( $_POST['data'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$data    = json_decode( $data, true );
		$actions = ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) ? $data['actions'] : [];

		/** Make actions array if not */
		if ( ! array( $actions ) ) {
			$actions = [];
		}

		foreach ( $actions as $group_id => $action_data ) {
			if ( null === $action_data ) {
				continue;
			}
			$actions[ $group_id ] = BWFAN_Common::remove_back_slash_from_automation( $action_data );
		}

		$actions = BWFAN_Common::sort_actions( $actions );

		/** Validate action data before save - unset temp_action_slug as of no use */
		$actions = BWFAN_Common::validate_action_date_before_save( $actions );

		$ui                        = stripslashes( $_POST['ui'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ui                        = json_decode( $ui, true );
		$uiData                    = stripslashes( $_POST['uiData'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uiData                    = json_decode( $uiData, true );
		$automation_data           = [];
		$automation_data['event']  = isset( $data['trigger']['event'] ) ? sanitize_text_field( $data['trigger']['event'] ) : '';
		$automation_data['source'] = isset( $data['trigger']['source'] ) ? sanitize_text_field( $data['trigger']['source'] ) : '';
		$where                     = [];
		$where['ID']               = $automation_id;

		BWFAN_Model_Automations::update( $automation_data, $where );
		BWFAN_Core()->automations->set_automation_data( 'event', $data['trigger']['event'] );
		BWFAN_Core()->automations->set_automation_data( 'source', $data['trigger']['source'] );

		$automation_meta_data              = [];
		$automation_meta_data['condition'] = [];

		if ( isset( $data['condition'] ) ) {
			$automation_meta_data['condition'] = $data['condition'];
		}
		$automation_meta_data['actions']    = $actions;
		$automation_meta_data['event_meta'] = ( isset( $data['trigger']['event_meta'] ) ) ? $data['trigger']['event_meta'] : [];
		$automation_meta_data['ui']         = $ui;
		$automation_meta_data['uiData']     = $uiData;
		$automation_meta_data['a_track_id'] = $a_track_id;
		$automation_meta                    = BWFAN_Model_Automationmeta::get_automation_meta( $automation_id );
		$db_a_track_id                      = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'a_track_id' );

		/** For saving subject of send email action of automation meta for tracking purpose */
		do_action( 'bwfan_automation_email_tracking_post_data', $automation_id, $automation_meta, $automation_meta_data['actions'], $db_a_track_id );

		/** Update automation meta */
		foreach ( $automation_meta_data as $meta_key => $meta_value ) {
			$meta_value = maybe_serialize( $meta_value );
			BWFAN_Core()->automations->set_automation_data( $meta_key, $meta_value );

			$where       = [];
			$update_data = [
				'bwfan_automation_id' => $automation_id,
				'meta_key'            => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				'meta_value'          => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			];
			if ( array_key_exists( $meta_key, $automation_meta ) ) {
				// Update Meta
				$where['bwfan_automation_id'] = $automation_id;
				$where['meta_key']            = $meta_key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			}
			if ( count( $where ) > 0 ) {
				BWFAN_Model_Automationmeta::update( $update_data, $where );
			} else {
				BWFAN_Model_Automationmeta::insert( $update_data );
			}
		}

		/** Update the modified date of automation */
		$meta_data = array(
			'meta_value' => current_time( 'mysql', 1 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		);
		$where     = array(
			'bwfan_automation_id' => $automation_id,
			'meta_key'            => 'm_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		);
		BWFAN_Model_Automationmeta::update( $meta_data, $where );
		BWFAN_Core()->automations->set_automation_data( 'm_date', $meta_data['meta_value'] );
		BWFAN_Core()->automations->set_automation_data( 'run_count', isset( $automation_meta['run_count'] ) ? $automation_meta['run_count'] : 0 );

		// Update requires_update key to 0 on update which implies that user has verified and saved the automation
		$meta_data = array(
			'meta_value' => 0, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		);
		$where     = array(
			'bwfan_automation_id' => $automation_id,
			'meta_key'            => 'requires_update', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		);

		BWFAN_Model_Automationmeta::update( $meta_data, $where );
		BWFAN_Core()->automations->set_automation_data( 'requires_update', 0 );
		BWFAN_Core()->automations->set_automation_id( $automation_id );
		do_action( 'bwfan_automation_saved', $automation_id );

		// Send async call to delete all the tasks except for completed tasks (actually logs)
		if ( ! is_null( $t_to_delete ) ) {
			$url       = rest_url( '/autonami/v1/delete-tasks' );
			$body_data = array(
				'automation_id' => $automation_id,
				'a_track_id'    => $db_a_track_id,
				't_to_delete'   => $t_to_delete,
				'unique_key'    => get_option( 'bwfan_u_key', false ),
			);
			$args      = bwf_get_remote_rest_args( $body_data );
			wp_remote_post( $url, $args );
		}

		$resp = array(
			'id'     => $automation_id,
			'status' => true,
			'msg'    => __( 'Automation Updated', 'wp-marketing-automations' ),
		);
		wp_send_json( $resp );

		//phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Runs when the title of the automation is updated from single automation screen.
	 */
	public static function update_automation() {
		BWFAN_Common::check_nonce();

		$resp = array(
			'msg'    => 'automation not found',
			'status' => false,
		);
		if ( ! isset( $_POST['automation_id'] ) || empty( $_POST['automation_id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			wp_send_json( $resp );
		}

		$automation_id = sanitize_text_field( wp_unslash( $_POST['automation_id'] ) ); //phpcs:ignore WordPress.Security.NonceVerification

		$where = [
			'bwfan_automation_id' => $automation_id,
		];
		$meta  = array();

		$where['meta_key']  = 'm_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		$meta['meta_key']   = 'm_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		$meta['meta_value'] = current_time( 'mysql', 1 ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		BWFAN_Model_Automationmeta::update( $meta, $where );

		$where['meta_key']  = 'title'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		$meta['meta_key']   = 'title'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		$meta['meta_value'] = sanitize_text_field( stripslashes( $_POST['title'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		BWFAN_Model_Automationmeta::update( $meta, $where );

		$resp['msg']             = __( 'Automation Successfully Updated', 'wp-marketing-automations' );
		$resp['status']          = true;
		$resp['automation_name'] = sanitize_text_field( stripslashes( $_POST['title'] ) ); //phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput

		BWFAN_Core()->automations->set_automation_id( $automation_id );
		do_action( 'bwfan_automation_saved', $automation_id );

		wp_send_json( $resp );
	}

	/**
	 * Runs when automation is activated/deactivated
	 */
	public static function toggle_automation_state() {
		BWFAN_Common::check_nonce();

		$resp = array(
			'msg'    => '',
			'status' => true,
		);
		// phpcs:disable WordPress.Security.NonceVerification
		if ( empty( $_POST['id'] ) ) {
			$resp = array(
				'msg'    => 'Automation ID is missing',
				'status' => false,
			);
			wp_send_json( $resp );
		}

		$automation_id        = sanitize_text_field( wp_unslash( $_POST['id'] ) );
		$automation           = array();
		$automation['status'] = 2;
		if ( isset( $_POST['state'] ) && 'true' === $_POST['state'] ) {
			$automation['status'] = 1;
		}

		BWFAN_Core()->automations->toggle_state( $automation_id, $automation );

		//phpcs:enable WordPress.Security.NonceVerification
		wp_send_json( $resp );
	}

	public static function is_wfocu_front_ajax() {

		if ( defined( 'DOING_AJAX' ) && true === DOING_AJAX && null !== filter_input( INPUT_POST, 'action' ) && false !== strpos( filter_input( INPUT_POST, 'action' ), 'wfocu_front' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Runs when `preview` option is clicked in email action. It temporarily saved data in options table.
	 */
	public static function bwfan_save_temporary_preview_data() {
		BWFAN_Common::check_nonce();

		//phpcs:disable WordPress.Security.NonceVerification
		$automation_id = isset( $_POST['automation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['automation_id'] ) ) : '';

		if ( absint( $automation_id ) < 1 ) {
			wp_send_json( array(
				'status' => false,
			) );
		}

		$post                     = $_POST;
		$post['data']['to']       = stripslashes( sanitize_text_field( $post['data']['to'] ) );
		$post['data']['subject']  = stripslashes( sanitize_text_field( $post['data']['subject'] ) );
		$post['data']['body']     = stripslashes( $post['data']['body'] );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$post['data']['body_raw'] = stripslashes( $post['data']['body_raw'] );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$meta               = array();
		$meta['meta_key']   = 'email_preview'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		$meta['meta_value'] = maybe_serialize( $post ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup

		$current_data = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'email_preview' );
		if ( false === $current_data ) {
			$meta['bwfan_automation_id'] = $automation_id;
			BWFAN_Model_Automationmeta::insert( $meta );
		} else {
			$where = [
				'bwfan_automation_id' => $automation_id,
				'meta_key'            => 'email_preview', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			];
			BWFAN_Model_Automationmeta::update( $meta, $where );
		}

		//phpcs:enable WordPress.Security.NonceVerification
		wp_send_json( array(
			'status' => true,
		) );
	}

	public static function test_email() {
		BWFAN_Common::check_nonce();
		// phpcs:disable WordPress.Security.NonceVerification

		$result = array(
			'status' => false,
			'msg'    => __( 'Error', 'wp-marketing-automations' ),
		);

		if ( ! isset( $_POST['email'] ) || ! filter_var( wp_unslash( $_POST['email'] ), FILTER_VALIDATE_EMAIL ) ) {
			$result['msg'] = __( 'Email not valid', 'wp-marketing-automations' );
			wp_send_json( $result );
		}

		$post = $_POST;

		$automation_id = sanitize_text_field( $post['automation_id'] );
		if ( absint( $automation_id ) < 1 ) {
			$result['msg']    = __( 'Automation ID missing', 'wp-marketing-automations' );
			$result['status'] = false;
			wp_send_json( $result );
		}

		$post['data']['to']         = sanitize_email( $post['email'] );
		$post['data']['subject']    = isset( $post['data']['subject'] ) && ! empty( $post['data']['subject'] ) ? stripslashes( $post['data']['subject'] ) : __( 'This is a fake subject line, enter subject to fix it', 'wp-marketing-automations' );
		$post['data']['preheading'] = isset( $post['data']['preheading'] ) ? stripslashes( $post['data']['preheading'] ) : '';
		$post['data']['body']       = stripslashes( $post['data']['body'] );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$post['data']['body_raw']   = stripslashes( $post['data']['body_raw'] );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( isset( $post['data']['editor'] ) ) {
			$post['data']['editor']['body']   = stripslashes( $post['data']['editor']['body'] );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$post['data']['editor']['design'] = stripslashes( $post['data']['editor']['design'] );//phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		$meta               = array();
		$meta['meta_key']   = 'email_preview'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
		$meta['meta_value'] = maybe_serialize( $post ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup

		$current_data = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'email_preview' );
		if ( false === $current_data ) {
			$meta['bwfan_automation_id'] = $automation_id;
			BWFAN_Model_Automationmeta::insert( $meta );
		} else {
			$where = [
				'bwfan_automation_id' => $automation_id,
				'meta_key'            => 'email_preview', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
			];
			BWFAN_Model_Automationmeta::update( $meta, $where );
		}

		BWFAN_Merge_Tag_Loader::set_data( array(
			'is_preview' => true,
			'test_email' => $post['email'],
		) );

		$post['event_data']['event_slug'] = $post['event'];
		$action_object                    = BWFAN_Core()->integration->get_action( 'wp_sendemail' );
		if ( null === $action_object && class_exists( 'BWFAN_Wp_Sendemail' ) ) {
			/** Try to get the instance directly */
			$action_object = BWFAN_Wp_Sendemail::get_instance();
		}
		if ( null === $action_object ) {
			$result['msg']    = __( 'Email action not found.', 'wp-marketing-automations' );
			$result['status'] = false;

			return $result;
		}
		$action_object->is_preview = true;
		$data_to_set               = $action_object->make_data( '', $post );
		$data_to_set['test']       = true;

		$action_object->set_data( $data_to_set );
		$response = $action_object->send_email();

		if ( true === $response ) {
			$result['msg']    = __( 'Test email sent.', 'wp-marketing-automations' );
			$result['status'] = true;
		} elseif ( is_array( $response ) && isset( $response['message'] ) ) {
			$result['msg']    = $response['message'];
			$result['status'] = false;
		} else {
			$result['msg']    = __( 'Server does not support email facility', 'wp-marketing-automations' );
			$result['status'] = false;

		}
		//phpcs:enable WordPress.Security.NonceVerification
		wp_send_json( $result );
	}


	public static function test_sms() {
		BWFAN_Common::check_nonce();

		/** If FKA pro is not active */
		if ( ! bwfan_is_autonami_pro_active() ) {
			return array(
				'status' => false,
				'msg'    => __( 'FunnelKit Automations Pro is not active', 'wp-marketing-automations' ),
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$result = array(
			'status' => false,
			'msg'    => __( 'Error', 'wp-marketing-automations' ),
		);

		if ( ! isset( $_POST['data']['sms_to'] ) && ! isset( $_POST['test_sms_to'] ) ) {
			$result['msg'] = __( 'Phone number can\'t be blank', 'wp-marketing-automations' );
			wp_send_json( $result );
		}

		$post = $_POST;

		$mediaUrl = '';
		if ( isset( $post['v'] ) && 2 === absint( $post['v'] ) ) {
			$sms_to       = $post['test_sms_to'];
			$sms_body     = isset( $post['sms_body'] ) ? stripslashes( $post['sms_body'] ) : '';
			$sms_provider = isset( $post['sms_provider'] ) ? $post['sms_provider'] : '';
		} else {
			$sms_to       = $post['data']['sms_to'];
			$sms_body     = isset( $post['data']['sms_body'] ) ? stripslashes( $post['data']['sms_body'] ) : '';
			$sms_provider = isset( $post['data']['sms_provider'] ) ? $post['data']['sms_provider'] : '';
		}

		/** Append UTM parameters */
		if ( class_exists( 'BWFAN_UTM_Tracking' ) ) {
			$utm = BWFAN_UTM_Tracking::get_instance();
			if ( version_compare( BWFAN_PRO_VERSION, '2.0.4', '>' ) ) {
				$sms_body = $utm->maybe_add_utm_parameters( $sms_body, $post, 'sms' );
			} else {
				$sms_body = $utm->maybe_add_utm_parameters( $sms_body, $post );
			}
		}

		/** Media handling */
		if ( ! empty( $post['data']['attach_custom_img'] ) ) {
			$img = stripslashes( $post['data']['attach_custom_img'] );
			$img = json_decode( $img, true );
			if ( is_array( $img ) && count( $img ) > 0 ) {
				$mediaUrl = $img[0];
			}
		}

		// is_preview set to true for merge tag before sending data for sms;
		BWFAN_Merge_Tag_Loader::set_data( array(
			'is_preview' => true,
		) );

		$sms_body        = BWFAN_Common::decode_merge_tags( $sms_body );
		$send_sms_result = BWFCRM_Common::send_sms( array(
			'to'           => $sms_to,
			'body'         => $sms_body,
			'is_test'      => true,
			'image_url'    => $mediaUrl,
			'sms_provider' => $sms_provider
		) );

		if ( $send_sms_result instanceof WP_Error ) {
			wp_send_json( array(
				'status' => false,
				'msg'    => $send_sms_result->get_error_message(),
			) );
		}

		$message = __( 'SMS sent successfully', 'wp-marketing-automations' );

		wp_send_json( array(
			'status' => true,
			'msg'    => $message,
		) );
	}
}

BWFAN_AJAX_Controller::init();
