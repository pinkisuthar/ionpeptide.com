<?php

#[AllowDynamicProperties]
class BWFAN_Goal_Controller extends BWFAN_Base_Step_Controller {

	/** Props - Global usage */
	public static $TRAVERSE_SETTING_CONTINUE = 'continue';
	public static $TRAVERSE_SETTING_WAIT = 'wait';
	public static $TRAVERSE_SETTING_END = 'end';

	/** Captured goal event data */
	public static $captured_data = [];
	public $traverse_setting = 'continue';
	public $data = null;
	public $goal_steps = [];
	public $update_status = false;

	/**
	 * Goal related events, end controller function
	 * Used by BWFAN_Common extend_async_capture method
	 *
	 * @param BWFAN_Event $event
	 * @param array $post_parameters
	 */
	public static function capture_async_goal( $event, $post_parameters ) {
		/**
		 * Goals are Pro-only. The engine already fast-forwards goal steps on Lite,
		 * so traversing contacts toward one would skip their pending steps while the
		 * goal itself does nothing. Parked (status 4) contacts resume when Pro returns.
		 */
		if ( ! bwfan_is_autonami_pro_active() ) {
			return false;
		}

		/** @todo maybe create AS action and run async */
		self::$captured_data = $post_parameters;

		/** Get Contact ID */
		$contact_id = $event->get_contact_id_for_goal( $post_parameters );
		if ( empty( $contact_id ) ) {
			return false;
		}

		/** Get Active Automations and goal ids */
		$automations = $event->get_current_goal_automations( true );
		$a_ids       = $automations['a_ids'] ?? [];
		$goal_ids    = $automations['goal_ids'] ?? [];
		if ( empty( $a_ids ) || empty( $goal_ids ) ) {
			return false;
		}

		/** Validate goal step setting */
		$goal_steps_data = BWFAN_Model_Automation_Step::get_step_data_by_ids( $goal_ids );
		if ( empty( $goal_steps_data ) ) {
			return false;
		}
		$controller = new self();
		foreach ( $goal_steps_data as $goal_step ) {
			if ( empty( $goal_step ) ) {
				continue;
			}
			$aid             = $goal_step['aid'] ?? 0;
			$automation_sids = $a_ids[ $aid ] ?? [];
			$step_index      = array_search( intval( $goal_step['ID'] ), $automation_sids, true );
			if ( empty( $automation_sids ) || false === $step_index ) {
				continue;
			}

			/** Reset old data */
			$controller->data      = null;
			$controller->step_data = null;

			if ( ! $controller->populate_step_data( $goal_step ) ) {
				BWFAN_Common::log_l2_data( 'Failed to populate step data for goal step: ' . $goal_step['ID'], 'goal-validation' );
				continue;
			}
			if ( $event->validate_goal_settings( $controller->data, $post_parameters ) ) {
				continue;
			}
			/** Remove the invalid goal step from the steps */
			unset( $automation_sids[ $step_index ] );
			if ( empty( $automation_sids ) && isset( $a_ids[ $aid ] ) ) {
				unset( $a_ids[ $aid ] );
			} else {
				$a_ids[ $aid ] = $automation_sids;
			}
		}

		$automations = array_keys( $a_ids );
		if ( empty( $automations ) ) {
			return false;
		}

		/** Check if contact is in those automations */
		$automation_contact_rows = self::filter_goal_automations_contact( $automations, $contact_id );
		if ( empty( $automation_contact_rows ) ) {
			return false;
		}

		/** Process each automation */
		foreach ( $automation_contact_rows as $automation_contact ) {
			$validated_goals = $a_ids[ $automation_contact['aid'] ] ?? [];
			if ( empty( $validated_goals ) ) {
				continue;
			}
			$automation = BWFAN_Model_Automations::get_automation_with_data( $automation_contact['aid'] );
			if ( empty( $automation ) ) {
				continue;
			}

			/** Reset controller properties */
			$controller->automation_id = absint( $automation_contact['aid'] );
			$controller->contact_id    = absint( $contact_id );
			$controller->process_goal( $automation, $event, $automation_contact, $validated_goals );
		}

		self::$captured_data = array();
	}

	/**
	 * Filter goal automations if contact is active in them
	 *
	 * @param $automations
	 * @param $contact_id
	 *
	 * @return array automation contact row
	 */
	public static function filter_goal_automations_contact( $automations, $contact_id ) {
		global $wpdb;

		if ( empty( $automations ) || empty( $contact_id ) ) {
			return [];
		}

		$string_placeholder = array_fill( 0, count( $automations ), '%d' );
		$placeholder        = implode( ', ', $string_placeholder );

		$data  = array_merge( [ $contact_id ], $automations );
		$query = $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}bwfan_automation_contact` WHERE `cid` = %d AND `aid` IN ($placeholder) AND `status` IN (1,4,6)", $data ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN() placeholders generated as %d; values bound; table from $wpdb->prefix

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- IN() placeholders generated as %d; values bound; table from $wpdb->prefix
	}

	/**
	 * @param array $automation Automation data
	 * @param BWFAN_Event $event
	 * @param array $automation_contact Automation contact row
	 * @param array $goal_step_ids
	 *
	 * @return void
	 */
	public function process_goal( $automation, $event, $automation_contact, $goal_step_ids ) {
		/** Set default values */
		$this->traverse_setting = 'continue';
		$this->data             = null;
		$this->goal_steps       = [];
		$this->update_status    = false;

		/** Fetching step trail */
		$trail_id = $automation_contact['trail'];
		if ( empty( $trail_id ) ) {
			$trail_id                    = md5( $automation_contact['ID'] . $automation_contact['cid'] . $automation_contact['c_date'] );
			$automation_contact['trail'] = $trail_id;
			BWFAN_Model_Automation_Contact::update( array(
				'trail' => $trail_id,
			), array(
				'ID' => $automation_contact['ID'],
			) );
		}

		/** Steps and Links not found */
		if ( ! is_array( $automation['meta'] ) || ! is_array( $automation['meta']['steps'] ) || ! is_array( $automation['meta']['links'] ) ) {
			return;
		}

		BWFAN_Common::log_l2_data( 'Trail id: ' . $trail_id, 'goal-check' );

		$this->step_id = ( 0 === absint( $automation_contact['last'] ) ) ? $automation['start'] : $automation_contact['last'];
		BWFAN_Common::log_l2_data( 'Last run: ' . $this->step_id, 'goal-check' );
		BWFAN_Common::log_l2_data( 'Goal step ids: ' . implode( ', ', $goal_step_ids ), 'goal-check' );

		$traverse_ins = new BWFAN_Traversal_Controller();
		$traverse_ins->set_steps( $automation['meta']['steps'] );
		$traverse_ins->set_links( $automation['meta']['links'] );
		$traverse_ins->set_node_id_from_step_id( $this->step_id );
		$traverse_ins->automation_id = $this->automation_id;
		$traverse_ins->contact_id    = $this->contact_id;

		/** Try to traverse the goal, if unable to traverse, then return false */
		$traversed_goal_node_ids = $traverse_ins->try_traverse_to_goal( $goal_step_ids, $this, $automation_contact );
		if ( empty( $traversed_goal_node_ids ) ) {
			return;
		}

		$steps_trail = BWFAN_Model_Automation_Contact_Trail::get_trail( $trail_id );
		/** Validate found goal settings */
		foreach ( $traversed_goal_node_ids as $node_id ) {
			$step_id = array_search( $node_id, $this->goal_steps );
			if ( empty( $step_id ) || 1 > intval( $step_id ) ) {
				continue;
			}

			BWFAN_Common::log_l2_data( 'Goal achieved: step id ' . $step_id, 'goal-check' );

			/** Update existing steps trail status if needed or insert */
			$this->maybe_update_trail_status( $steps_trail );
			$this->maybe_add_goal_step_trail( $step_id, $steps_trail, $automation_contact );

			/** Goal step passed, update automation contact status to active */
			BWFAN_Model_Automation_Contact::update( array(
				'last'      => $step_id,
				'attempts'  => 0,
				'status'    => BWFAN_Automation_Controller::$STATUS_ACTIVE,
				'last_time' => current_time( 'timestamp', 1 ),
				'e_time'    => current_time( 'timestamp', 1 ),
			), array(
				'ID' => $automation_contact['ID'],
			) );
		}
	}

	/**
	 * Get goal step ids in an automation or check it exists
	 *
	 * @param array $step_ids Optional array of step IDs to validate
	 * @param string $automation_id Optional automation ID
	 * @param string $event_slug Optional event slug
	 *
	 * @return array
	 */
	public function get_goal_step_ids( $step_ids = array(), $automation_id = '', $event_slug = '' ) {
		global $wpdb;
		if ( ! empty( $step_ids ) ) {
			$placeholder = array_fill( 0, count( $step_ids ), '%d' );
			$placeholder = implode( ', ', $placeholder );

			$query    = $wpdb->prepare( "SELECT `ID` FROM `{$wpdb->prefix}bwfan_automation_step` WHERE `type` = 3 AND `status` IN (0, 1) AND `ID` IN ($placeholder)", $step_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholders generated as %d; values bound via prepare
			$step_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- IN() placeholders generated as %d; values bound via prepare

			return ! empty( $step_ids ) ? $step_ids : [];
		}
		if ( empty( $automation_id ) || empty( $event_slug ) ) {
			return [];
		}
		$query    = $wpdb->prepare( "SELECT `ID` FROM `{$wpdb->prefix}bwfan_automation_step` WHERE `aid` = %d AND `type` = 3 AND `status` IN (0, 1) AND `action` LIKE %s", $automation_id, "%{$event_slug}%" );
		$step_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- IN() placeholders generated as %d; values bound via prepare

		return ! empty( $step_ids ) ? $step_ids : [];
	}

	/**
	 * Set step data
	 *
	 * @param array $db_step step data
	 *
	 * @return bool
	 */
	public function populate_step_data( $db_step = array() ) {
		if ( parent::populate_step_data( $db_step ) && isset( $this->step_data['sidebarData'] ) && is_array( $this->step_data['sidebarData'] ) ) {
			$this->data             = $this->step_data['sidebarData'];
			$this->traverse_setting = $this->data['bwfan_goal_run'];

			return true;
		}

		return false;
	}

	/**
	 * Maybe steps trail contains a step with status 2. Update that to 1.
	 * Run once only
	 *
	 * array $steps_trail current contact steps trail
	 *
	 * @return void
	 */
	public function maybe_update_trail_status( $steps_trail ) {
		if ( true === $this->update_status ) {
			return;
		}

		/** Filter steps with status 2 */
		$filtered = array_filter( $steps_trail, function ( $row ) {
			if ( 2 === absint( $row['status'] ) ) {
				return true;
			}

			return false;
		} );

		/** If empty */
		if ( ! is_array( $filtered ) || 0 === count( $filtered ) ) {
			$this->update_status = true;

			return;
		}
		$filtered = array_column( $filtered, 'ID' );
		BWFAN_Common::log_l2_data( __FUNCTION__ . ' filtered', 'goal-update-trail-status' );
		BWFAN_Common::log_l2_data( implode( ', ', $filtered ), 'goal-update-trail-status' );

		global $wpdb;
		$string_placeholder = array_fill( 0, count( $filtered ), '%d' );
		$placeholder        = implode( ', ', $string_placeholder );
		/**  Update status and time   */
		$data  = array_merge( [ 1, time() ], $filtered );
		$query = $wpdb->prepare( "UPDATE `{$wpdb->prefix}bwfan_automation_contact_trail` SET `status` = %d, `c_time` = %d  WHERE `ID` IN ($placeholder)", $data ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder count matches array args at runtime; all %d
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholder count matches array args at runtime; all %d

		$this->update_status = true;
	}

	/**
	 * Maybe add the goal step trail
	 *
	 * @param int $goal_step_id
	 * @param array $steps_trail current contact steps trail
	 * @param array $automation_contact automation contact row
	 *
	 * @return int|void
	 */
	public function maybe_add_goal_step_trail( $goal_step_id, $steps_trail, $automation_contact ) {
		/** Filter steps if a goal step is already in wait */
		$filtered = array_filter( $steps_trail, function ( $row ) use ( $goal_step_id ) {
			$data = ! empty( $row['data'] ) ? json_decode( $row['data'], true ) : [];

			return ( ! empty( $data ) && isset( $data['msg'] ) && 'wait' === $data['msg'] && $goal_step_id === intval( $row['sid'] ) );
		} );

		if ( is_array( $filtered ) && count( $filtered ) > 0 ) {
			return;
		}

		/** Add a goal step trail */
		$arr = array(
			'tid'    => $automation_contact['trail'],
			'cid'    => $automation_contact['cid'],
			'aid'    => $automation_contact['aid'],
			'sid'    => $goal_step_id,
			'c_time' => time(),
			'status' => BWFAN_Automation_Controller::$STATUS_ACTIVE,
		);
		BWFAN_Model_Automation_Contact_Trail::insert( $arr );

		return BWFAN_Model_Automation_Contact_Trail::insert_id();
	}
}
