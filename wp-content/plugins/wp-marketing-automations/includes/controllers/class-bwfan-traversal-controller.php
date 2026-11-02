<?php

/**
 * Bwfan Traversal Controller
 *
 * @since 1.0.0
 */
#[AllowDynamicProperties]
class BWFAN_Traversal_Controller {
	public $current_node_id = 0;

	/** For processing of conditional controller */
	public $automation_id = 0;
	public $contact_id = 0;
	public $trail_id = 0;

	/** Conditional traversal Result */
	public $conditional_result_node_id = null;

	/** Split test traversal result  */
	public $split_result_node_id = null;

	protected $steps = array();
	protected $links = array();

	/** Lazy cache of the automation's stored merger_points meta (null = not loaded yet). */
	protected $stored_merger_points = null;

	/** Logging */
	protected $step_log = null;

	public $automation_contact_ins = null;

	/**
	 * Set steps in the traverser
	 *
	 * @param array $db_steps
	 *
	 * @return void
	 */
	public function set_steps( $db_steps ) {
		if ( ! is_array( $db_steps ) || empty( $db_steps ) ) {
			return;
		}

		foreach ( $db_steps as $step ) {
			$this->steps[ $step['id'] ] = $step;
		}
	}

	/**
	 * Set links in the traverser
	 *
	 * @param array $db_links
	 *
	 * @return void
	 */
	public function set_links( $db_links ) {
		if ( ! is_array( $db_links ) || empty( $db_links ) ) {
			return;
		}

		foreach ( $db_links as $link ) {
			if ( empty( $link ) || ! isset( $link['source'] ) || ! isset( $link['target'] ) ) {
				continue;
			}
			$this->links[ $link['source'] ] = $link['target'];
		}
	}

	/**
	 * Get current step id
	 *
	 * @return false|mixed
	 */
	public function get_current_step_id() {
		if ( empty( $this->steps ) || empty( $this->current_node_id ) || ! isset( $this->steps[ $this->current_node_id ] ) || ! isset( $this->steps[ $this->current_node_id ]['stepId'] ) ) {
			return false;
		}

		return $this->steps[ $this->current_node_id ]['stepId'];
	}

	/**
	 * Check if the current step is an end step
	 *
	 * @return bool
	 */
	public function is_end() {
		return $this->current_node_id === 'end';
	}

	/**
	 * @param array $step_ids Goal step IDs
	 * @param $goal_ins BWFAN_Goal_Controller
	 * @param array $automation_contact
	 *
	 * @return array|false
	 */
	public function try_traverse_to_goal( $step_ids, $goal_ins, $automation_contact ) {
		if ( empty( $this->current_node_id ) || empty( $step_ids ) ) {
			return false;
		}

		$goal_ins->goal_steps = $this->get_step_nodes( $step_ids );

		/**
		 * Live disabled-step set. Async goal-jump must honour disabled Split/Conditional
		 * steps the same way the main engine does — otherwise a benchmark inside a disabled
		 * branch could pull the contact into that branch, bypassing the skip. Read fresh from
		 * DB because the meta `steps` step_status goes stale after a disable toggle.
		 */
		$disabled_step_ids = BWFAN_Model_Automation_Step::get_disabled_step_ids( $this->automation_id );

		$run              = 0;
		$goal_found_nodes = [];
		while ( $run < count( $this->steps ) ) {
			$run ++;
			$current_step = $this->steps[ $this->current_node_id ];
			$this->log( 'current step', 'goal-check' );
			$this->log( [ 'id' => $current_step['id'], 'type' => $current_step['type'] ], 'goal-check' );

			/**
			 * Disabled Split/Conditional, or any branching step on a Lite site — do not
			 * enter its branch. Jump to the merger, matching process_disabled_step() in
			 * the main engine (which fast-forwards Pro-only steps when Pro is inactive;
			 * evaluating rules here would also fatal on Lite where BWFAN_Core()->rules
			 * is not registered). Any goal inside the skipped subtree is intentionally
			 * unreachable via this path.
			 */
			if (
				in_array( $current_step['type'], [ 'conditional', 'split', 'branches' ], true ) &&
				( ! bwfan_is_autonami_pro_active() || ( ! empty( $disabled_step_ids ) && in_array( intval( $current_step['stepId'] ), $disabled_step_ids, true ) ) )
			) {
				$merger = $this->get_merger_or_end( intval( $current_step['stepId'] ) );
				if ( empty( $merger ) || 'end' === $merger || false === $this->set_node_id_from_step_id( intval( $merger ) ) ) {
					/** No merger (branch ends) — goal not reachable past here, stop. */
					$run = count( $this->steps );
				}
				continue;
			}

			switch ( $current_step['type'] ) {
				case 'action':
				case 'wait':
					$this->traverse_to_next_step();
					$this->log( 'traversed to next action/ wait step', 'goal-check' );
					break;
				case 'conditional':
					$this->process_goal_conditional( $goal_ins, $automation_contact );
					$this->traverse_to_next_step();
					$this->log( 'traversed to next conditional step', 'goal-check' );
					break;
				case 'branches':
					$this->process_goal_branches( $goal_ins, $automation_contact );
					$this->traverse_to_next_step();
					$this->log( 'traversed to next multi conditional step', 'goal-check' );
					break;
				case 'jump':
					$this->process_jump( $goal_ins, $automation_contact );
					/** No need to traverse to next step as traversing already from process_jump function */
					$this->log( 'traversed to next jump step', 'goal-check' );
					break;
				case 'end':
					$this->log( 'end step', 'goal-check' );
					$run = count( $this->steps );
					break;
				case 'benchmark':
					if ( in_array( $current_step['id'], $goal_ins->goal_steps ) ) {
						$goal_found_nodes[] = $current_step['id'];
						$this->log( 'goal step found during traverse. goal step id: ' . $current_step['id'], 'goal-check' );
					}
					$this->traverse_to_next_step();
					$this->log( 'traversed to next step from goal step', 'goal-check' );
					break;
				case 'split':
					if ( in_array( $current_step['id'], $goal_ins->goal_steps ) ) {
						$goal_found_nodes[] = $current_step['id'];
						$this->log( 'split step found during traverse. split step id: ' . $current_step['id'], 'goal-check' );
					}
					$this->process_split_path( $automation_contact );
					$this->traverse_to_next_step();
					/** No need to traverse to next step as traversing already from process_jump function */
					$this->log( 'traversed to next split step', 'goal-check' );
					break;
				default:
					break;
			}
		}

		$goal_found_nodes = array_unique( $goal_found_nodes );
		sort( $goal_found_nodes );

		return $goal_found_nodes;
	}

	/**
	 * Return current step data by step id
	 *
	 * @param int $step_id
	 *
	 * @return false|mixed
	 */
	public function get_step_by_step_id( $step_id ) {
		if ( empty( $step_id ) ) {
			return false;
		}

		foreach ( $this->steps as $step ) {

			/** If step id is empty or is a split path / branch chip pseudo-node then skip */
			if ( empty( $step['stepId'] ) || false !== strpos( (string) $step['stepId'], '-path-' ) || false !== strpos( (string) $step['stepId'], '-branch-' ) ) {
				continue;
			}
			if ( ( absint( $step['stepId'] ) === absint( $step_id ) ) ) {
				return $step;
			}
		}

		return false;
	}

	/**
	 * Get node ids from step ids
	 *
	 * @param $step_ids
	 *
	 * @return array|false
	 */
	public function get_step_nodes( $step_ids ) {
		if ( empty( $step_ids ) ) {
			return false;
		}
		$nodes = [];
		foreach ( $this->steps as $step ) {
			if ( 'benchmark' !== $step['type'] ) {
				continue;
			}
			if ( isset( $step['stepId'] ) && in_array( $step['stepId'], $step_ids ) ) {
				$nodes[ $step['stepId'] ] = $step['id'];
			}
		}

		return empty( $nodes ) ? false : $nodes;

	}

	/**
	 * Traverse to next step node
	 *
	 * @return bool
	 */
	public function traverse_to_next_step() {
		$current_step = $this->get_current_step();

		if ( empty( $current_step ) || empty( $this->links ) ) {
			return false;
		}

		$this->log( $this->trail_id . ' - current step id: ' . ( isset( $current_step['stepId'] ) ? $current_step['stepId'] : '' ) . ' and node id: ' .  ( isset( $current_step['id'] ) ? $current_step['id'] : '' ) . ' and type: ' . ( isset( $current_step['type'] ) ? $current_step['type'] : '' ) );

		if ( 'conditional' === $current_step['type'] ) {
			/** Traverse conditional step */
			$next_step = $this->traverse_conditional_step();
			if ( empty( $next_step ) ) {
				return false;
			}

			$this->current_node_id = $next_step;
			$this->log( $this->trail_id . ' - traverse to next step from conditional step. New node id: ' . $this->current_node_id );

			return true;
		}

		if ( 'branches' === $current_step['type'] ) {
			/** Traverse multi conditional (branches) step */
			$next_step = $this->traverse_branches_step();
			if ( empty( $next_step ) ) {
				return false;
			}

			$this->current_node_id = $next_step;
			$this->log( $this->trail_id . ' - traverse to next step from multi conditional step. New node id: ' . $this->current_node_id );

			return true;
		}

		/** If current step is split */
		if ( 'split' === $current_step['type'] ) {
			/** Traverse split step */
			$next_step = $this->traverse_split_step();
			if ( empty( $next_step ) ) {
				return false;
			}

			$this->current_node_id = $next_step;
			$this->log( $this->trail_id . ' - traverse to next path from split step. New node id: ' . $this->current_node_id );

			return true;
		}

		if ( ! isset( $this->links[ $this->current_node_id ] ) ) {
			return false;
		}

		$this->current_node_id = $this->links[ $this->current_node_id ];
		$this->log( $this->trail_id . ' - traverse to next step. New node id: ' . $this->current_node_id );

		return true;
	}

	/**
	 * Get current step data
	 *
	 * @return false|mixed
	 */
	public function get_current_step() {
		return ! empty( $this->steps ) && ! empty( $this->current_node_id ) && isset( $this->steps[ $this->current_node_id ] ) ? $this->steps[ $this->current_node_id ] : false;
	}

	/**
	 * Return step id after processing conditional step result
	 *
	 * @return false|mixed
	 */
	public function traverse_conditional_step() {
		$conditional_node_id = $this->conditional_result_node_id;
		if ( isset( $this->links[ $conditional_node_id ] ) ) {
			return $this->links[ $conditional_node_id ];
		}

		/** Maybe check if set in the database */
		if ( $this->automation_contact_ins instanceof BWFAN_Automation_Controller ) {
			$data = $this->automation_contact_ins->automation_contact_data;
			if ( isset( $data['node_id'] ) && isset( $this->links[ $data['node_id'] ] ) ) {
				/** Removing saved node id from the DB */
				$this->automation_contact_ins->update_conditional_step_result();

				return $this->links[ $data['node_id'] ];
			}
		}

		/** If somehow conditional step is deleted from table but its step node id exists in links then return target step of NO node */
		if ( isset( $this->links[ $this->links[ $this->current_node_id ] ] ) ) {
			return $this->links[ $this->links[ $this->current_node_id ] ];
		}

		BWFAN_Common::log_test_data( 'not able to traverse to condition next step ' . $this->current_node_id, 'traverse_conditional_failed' );
		BWFAN_Common::log_test_data( 'conditional_node_id: ' . $conditional_node_id, 'traverse_conditional_failed' );
		BWFAN_Common::log_test_data( $this->links, 'traverse_conditional_failed' );

		return false;
	}

	/**
	 * Return step id after processing multi conditional step result
	 *
	 * @return false|mixed
	 */
	public function traverse_branches_step() {
		$branch_node_id = $this->conditional_result_node_id;
		if ( isset( $this->links[ $branch_node_id ] ) ) {
			return $this->links[ $branch_node_id ];
		}

		/** Maybe check if set in the database */
		if ( $this->automation_contact_ins instanceof BWFAN_Automation_Controller ) {
			$data = $this->automation_contact_ins->automation_contact_data;
			if ( isset( $data['node_id'] ) && isset( $this->links[ $data['node_id'] ] ) ) {
				/** Removing saved node id from the DB */
				$this->automation_contact_ins->update_conditional_step_result();

				return $this->links[ $data['node_id'] ];
			}
		}

		/** Branch deleted while contact in-flight: fall to Everyone Else path */
		$else_node_id = $this->current_node_id . '-branch-else';
		if ( isset( $this->links[ $else_node_id ] ) ) {
			return $this->links[ $else_node_id ];
		}

		BWFAN_Common::log_test_data( 'not able to traverse to multi conditional next step ' . $this->current_node_id, 'traverse_branches_failed' );
		BWFAN_Common::log_test_data( 'branch_node_id: ' . $branch_node_id, 'traverse_branches_failed' );

		return false;
	}

	/**
	 * Return step id after processing split next path result
	 *
	 * @return false|mixed
	 */
	public function traverse_split_step() {
		$path_node_id = $this->split_result_node_id;
		if ( isset( $this->links[ $path_node_id ] ) ) {
			return $this->links[ $path_node_id ];
		}

		BWFAN_Common::log_test_data( 'not able to traverse to split next path ' . $this->current_node_id, 'traverse_split_failed' );
		BWFAN_Common::log_test_data( 'split_path_node_id: ' . $path_node_id, 'traverse_split_failed' );
		BWFAN_Common::log_test_data( $this->links, 'traverse_split_failed' );

		return false;
	}

	/**
	 * Process conditional step data for goal only
	 *
	 * @param $goal_ins BWFAN_Goal_Controller
	 * @param array $automation_contact automation contact row
	 *
	 * @return void
	 */
	public function process_goal_conditional( $goal_ins, $automation_contact ) {
		$current_step = $this->get_current_step();

		$ins                = new BWFAN_Conditional_Controller();
		$ins->contact_id    = $goal_ins->contact_id;
		$ins->automation_id = $goal_ins->automation_id;
		$ins->step_id       = absint( $current_step['stepId'] );
		$ins->populate_step_data( $current_step );
		$ins->populate_automation_contact_data( $automation_contact );

		$result = $ins->is_match();

		$this->conditional_result_node_id = $current_step['id'] . ( ( true === $result ) ? 'yes' : 'no' );
	}

	/**
	 * Process multi conditional step data for goal only (live re-evaluation)
	 *
	 * @param $goal_ins BWFAN_Goal_Controller
	 * @param array $automation_contact automation contact row
	 *
	 * @return void
	 */
	public function process_goal_branches( $goal_ins, $automation_contact ) {
		$current_step = $this->get_current_step();

		$ins                = new BWFAN_Branches_Controller();
		$ins->contact_id    = $goal_ins->contact_id;
		$ins->automation_id = $goal_ins->automation_id;
		$ins->step_id       = absint( $current_step['stepId'] );
		$ins->populate_step_data( $current_step );
		$ins->populate_automation_contact_data( $automation_contact );

		$branch = $ins->get_matched_branch();

		$this->conditional_result_node_id = $current_step['id'] . '-branch-' . $branch;
	}

	/**
	 * Process jump step
	 *
	 * @param $goal_ins
	 * @param $automation_contact
	 *
	 * @return void
	 */
	public function process_jump( $goal_ins, $automation_contact ) {
		$current_step = $this->get_current_step();

		$ins          = new BWFAN_Jump_Controller();
		$ins->step_id = absint( $current_step['stepId'] );
		$ins->populate_step_data( $current_step );

		$jump_step_id = $ins->get_jump_step_id();

		if ( empty( $jump_step_id ) ) {
			return;
		}

		/** Set current node to jump step */
		$this->set_node_id_from_step_id( $jump_step_id );
	}

	/**
	 * Maybe traverse back to valid step
	 *
	 * @return void
	 */
	public function maybe_traverse_back() {
		global $wpdb;

		/** @var BWFAN_Automation_Controller $ins */
		$ins = $this->automation_contact_ins;

		$step_id = $ins->step_id;
		$query   = $wpdb->prepare( "SELECT `ID` FROM `{$wpdb->prefix}bwfan_automation_contact_trail` WHERE `tid` = %s AND `sid` = %d LIMIT 0,1;", $ins->automation_contact['trail'], $step_id );
		$result  = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

		$valid_step_id = 0;
		if ( ! empty( $result ) ) {
			$query  = $wpdb->prepare( "SELECT t.`sid` FROM `{$wpdb->prefix}bwfan_automation_contact_trail` as t INNER JOIN `{$wpdb->prefix}bwfan_automation_step` as s ON s.`ID` = t.`sid` WHERE t.`tid` = %s AND t.`ID` < %d AND s.`status` IN (%d, %d) ORDER BY t.ID DESC LIMIT 0,1;", $ins->automation_contact['trail'], $result, 0, 1 );
			$result = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
			if ( ! empty( $result ) ) {
				$valid_step_id = strval( $result );
			}
		}
		if ( empty( $valid_step_id ) ) {
			/** Step not found, start from the first node */
			$ins->step_id = $ins->automation['start'];
			$this->set_node_id_from_step_id( $ins->step_id );

			$ins->automation_contact['last'] = 0;

			return;
		}

		$this->set_node_id_from_step_id( $valid_step_id );
	}

	/**
	 * Set step node ID from the step ID
	 *
	 * @param int $step_id
	 *
	 * @return bool
	 */
	public function set_node_id_from_step_id( $step_id ) {
		$step = $this->get_step_by_step_id( $step_id );
		if ( empty( $step ) || ! isset( $step['id'] ) ) {
			return false;
		}

		$this->current_node_id = $step['id'];

		return true;
	}

	/**
	 * Process split step for goal only
	 *
	 * @param $automation_contact
	 *
	 * @return void
	 */
	public function process_split_path( $automation_contact ) {
		$current_step         = $this->get_current_step();
		$ins                  = new BWFAN_Split_Test_Controller();
		$ins->current_node_id = $this->current_node_id;
		$ins->automation_id   = $this->automation_id;
		$ins->step_id         = absint( $current_step['stepId'] );

		$ins->populate_step_data( $current_step );
		$ins->populate_automation_contact_data( $automation_contact );
		$path = $ins->get_next_path();

		$this->split_result_node_id = $current_step['id'] . '-path-' . $path;
	}

	/**
	 * Special log function for step execution
	 *
	 * @param $log
	 * @param $name
	 *
	 * @return void
	 */
	public function log( $log, $name = 'fka-automation-step-id' ) {
		if ( empty( $log ) ) {
			return;
		}
		if ( false === $this->step_log ) {
			return;
		}

		if ( is_null( $this->step_log ) && false === apply_filters( 'bwfan_allow_automation_step_logging', BWFAN_Common::is_log_enabled( 'bwfan_step_logging' ) ) ) {
			$this->step_log = false;

			return;
		}
		$this->step_log = true;

		BWFAN_Common::log_test_data( $log, $name, true );
	}

	/**
	 * Return the step ID of the single outgoing edge from $step_id.
	 * Used when bypassing a disabled Action / Wait step.
	 *
	 * Returns 'end' if the next node is the end node, false if no edge exists.
	 *
	 * @param int $step_id source step ID (the step being skipped)
	 *
	 * @return string|int|false 'end' | next step ID | false
	 */
	public function get_single_next_step_id( $step_id ) {
		$step = $this->get_step_by_step_id( $step_id );
		if ( empty( $step ) || ! isset( $step['id'] ) ) {
			return false;
		}
		$node_id = $step['id'];
		if ( ! isset( $this->links[ $node_id ] ) ) {
			return false;
		}
		$next_node_id = $this->links[ $node_id ];
		if ( 'end' === $next_node_id ) {
			return 'end';
		}
		if ( ! isset( $this->steps[ $next_node_id ] ) || ! isset( $this->steps[ $next_node_id ]['stepId'] ) ) {
			return false;
		}

		return $this->steps[ $next_node_id ]['stepId'];
	}

	/**
	 * For a disabled Conditional / Split node, find the step ID where its
	 * branches converge (the "merger"), or 'end' if branches don't converge.
	 *
	 * Primary source: the pre-computed merger stored in automation meta
	 * (get_stored_merger_points) — the same map the builder greys the canvas from,
	 * so runtime skip and canvas preview agree. 0/stale → 'end'.
	 *
	 * Fallback (only when the automation has no stored merger_points, e.g. saved
	 * before the feature) — compute live from $this->links:
	 *   1. Enumerate branch-start node IDs from the disabled node's virtual
	 *      sub-node IDs (yes/no for conditional; -path-N for split).
	 *   2. For each branch start, walk forward via $this->links collecting
	 *      every reachable node ID. Stop at 'end', exit, jump, or already-visited
	 *      (cycle guard). Cap at 500 hops per branch.
	 *   3. Intersect the reachable sets across all branches.
	 *   4. Pick the intersection node closest to the disabled step
	 *      (minimum hops through any branch).
	 *   5. If intersection is empty, return 'end'.
	 *
	 * @param int $step_id step ID of the disabled Conditional or Split
	 *
	 * @return string|int 'end' | merger step ID
	 */
	public function get_merger_or_end( $step_id ) {
		$step = $this->get_step_by_step_id( $step_id );
		if ( empty( $step ) || ! isset( $step['id'] ) ) {
			return 'end';
		}
		$node_id = $step['id'];

		/**
		 * Prefer the pre-computed merger stored in automation meta — this is the exact
		 * same source the builder greys the canvas from (get_all_steps_merger_point),
		 * so the runtime skip target and the canvas preview stay in sync. Only fall
		 * through to the live BFS below when the automation has no stored merger_points
		 * (e.g. it was saved before the feature and never re-saved).
		 *
		 * Stored value is the merger node id, or 0 when the branches never reconverge —
		 * 0 (or a stale node id) means "no merger" → end the automation.
		 */
		$stored = $this->get_stored_merger_points();
		if ( ! empty( $stored ) && array_key_exists( $node_id, $stored ) ) {
			$merger_node = $stored[ $node_id ];
			if ( empty( $merger_node ) || ! isset( $this->steps[ $merger_node ]['stepId'] ) ) {
				return 'end';
			}

			return $this->steps[ $merger_node ]['stepId'];
		}

		/** Enumerate branch starts. Conditional has yes/no virtual nodes; Split has -path-N. */
		$branch_starts = [];
		foreach ( [ 'yes', 'no' ] as $suffix ) {
			$virtual = $node_id . $suffix;
			if ( isset( $this->links[ $virtual ] ) ) {
				$branch_starts[] = $this->links[ $virtual ];
			}
		}
		/**
		 * Split paths — enumerate the actual -path-* handles present in the graph
		 * instead of a fixed 1..N loop, so a wider split can never silently fall
		 * past a hardcoded ceiling. Mirrors the frontend (split-node.js), which
		 * also reads real -path- child nodes rather than the stored path count.
		 */
		foreach ( $this->links as $src => $tgt ) {
			if ( 0 === strpos( (string) $src, $node_id . '-path-' ) || 0 === strpos( (string) $src, $node_id . '-branch-' ) ) {
				$branch_starts[] = $tgt;
			}
		}

		if ( empty( $branch_starts ) ) {
			return 'end';
		}

		/** Walk each branch — collect reachable nodes with min-hop distance. */
		$max_hops    = 500;
		$per_branch  = []; // index => [ reachable_node_id => hops_from_branch_start ]
		foreach ( $branch_starts as $idx => $start ) {
			$reach    = [];
			$queue    = [ [ $start, 0 ] ];
			$visited  = [];
			while ( ! empty( $queue ) ) {
				list( $cur, $hops ) = array_shift( $queue );
				if ( isset( $visited[ $cur ] ) || 'end' === $cur || $hops > $max_hops ) {
					continue;
				}
				$visited[ $cur ] = true;
				$reach[ $cur ]   = $hops;

				/** Bare linear edge — absent for a branching node (its edges are keyed by virtual handle), so guard rather than continue, else the sub-edge enqueue below is skipped and the walk truncates at a nested conditional/split. */
				if ( isset( $this->links[ $cur ] ) ) {
					$next = $this->links[ $cur ];
					if ( ! isset( $visited[ $next ] ) ) {
						$queue[] = [ $next, $hops + 1 ];
					}
				}
				/** Also enqueue branching sub-edges if this node is itself a conditional / split. */
				foreach ( [ 'yes', 'no' ] as $sub ) {
					$v = $cur . $sub;
					if ( isset( $this->links[ $v ] ) && ! isset( $visited[ $this->links[ $v ] ] ) ) {
						$queue[] = [ $this->links[ $v ], $hops + 1 ];
					}
				}
				/** Nested split / branches sub-edges — enumerate actual -path-* / -branch-* handles (no fixed ceiling). */
				foreach ( $this->links as $src => $tgt ) {
					if ( ( 0 === strpos( (string) $src, $cur . '-path-' ) || 0 === strpos( (string) $src, $cur . '-branch-' ) ) && ! isset( $visited[ $tgt ] ) ) {
						$queue[] = [ $tgt, $hops + 1 ];
					}
				}
			}
			$per_branch[ $idx ] = $reach;
		}

		/** Intersect reachable sets across all branches. */
		$intersection = null;
		foreach ( $per_branch as $reach ) {
			$keys = array_keys( $reach );
			$intersection = ( null === $intersection ) ? $keys : array_intersect( $intersection, $keys );
			if ( empty( $intersection ) ) {
				break;
			}
		}

		if ( empty( $intersection ) ) {
			return 'end';
		}

		/** For each candidate, take the min hops across branches; pick the smallest min. */
		$best_node_id = null;
		$best_hops    = PHP_INT_MAX;
		foreach ( $intersection as $candidate_node_id ) {
			$min_hops = PHP_INT_MAX;
			foreach ( $per_branch as $reach ) {
				if ( isset( $reach[ $candidate_node_id ] ) && $reach[ $candidate_node_id ] < $min_hops ) {
					$min_hops = $reach[ $candidate_node_id ];
				}
			}
			if ( $min_hops < $best_hops ) {
				$best_hops    = $min_hops;
				$best_node_id = $candidate_node_id;
			}
		}

		if ( null === $best_node_id || ! isset( $this->steps[ $best_node_id ] ) || ! isset( $this->steps[ $best_node_id ]['stepId'] ) ) {
			return 'end';
		}

		return $this->steps[ $best_node_id ]['stepId'];
	}

	/**
	 * Stored split/conditional merger_points for this automation, keyed by node id.
	 * Loaded once per traversal instance from cached automation meta (the same map
	 * get_all_steps_merger_point() writes and the builder greys the canvas from).
	 * Absent (empty array) when the automation was never saved with the feature.
	 *
	 * @return array node_id => merger node id (0 = no merger)
	 */
	protected function get_stored_merger_points() {
		if ( null !== $this->stored_merger_points ) {
			return $this->stored_merger_points;
		}

		$meta                       = BWFAN_Model_Automationmeta::get_automation_meta( $this->automation_id );
		$this->stored_merger_points = ( isset( $meta['merger_points'] ) && is_array( $meta['merger_points'] ) ) ? $meta['merger_points'] : array();

		return $this->stored_merger_points;
	}
}
