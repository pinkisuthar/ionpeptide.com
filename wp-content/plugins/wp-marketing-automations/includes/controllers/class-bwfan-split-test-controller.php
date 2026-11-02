<?php

#[\AllowDynamicProperties]
class BWFAN_Split_Test_Controller extends BWFAN_Base_Step_Controller {
	public $last_run_path = 0;
	public $total_paths = 3;
	public $automation = [];
	public $automation_data = [];
	public $current_node_id = 0;
	public $winner_path = 0;

	/**
	 * @var BWFAN_Split_Path_Traversal_Controller
	 */
	public $split_traverse_ins = null;

	/**
	 * Populate step data
	 *
	 * @param $db_step
	 *
	 * @return bool
	 */
	public function populate_step_data( $db_step = array() ) {
		if ( ! parent::populate_step_data( $db_step ) ) {
			return false;
		}
		$this->winner_path = isset( $this->step_data['sidebarData']['winner'] ) ? intval( $this->step_data['sidebarData']['winner'] ) : 0;
		/** If winner path is declared then no need to populate other data */
		if ( $this->winner_path > 0 ) {
			return true;
		}

		$this->total_paths   = isset( $this->step_data['sidebarData']['split_path'] ) ? $this->step_data['sidebarData']['split_path'] : $this->total_paths;
		$this->last_run_path = isset( $this->step_data['sidebarData']['last_run'] ) ? intval( $this->step_data['sidebarData']['last_run'] ) : 0;

		return true;
	}

	/**
	 * Get next run path
	 *
	 * @return int|mixed
	 */
	public function get_next_path() {
		/** If winner path is declared */
		if ( $this->winner_path > 0 ) {
			return $this->winner_path;
		}

		$next_path = $this->last_run_path + 1;

		return ( intval( $this->total_paths ) >= intval( $next_path ) ) ? $next_path : 1;
	}
}
