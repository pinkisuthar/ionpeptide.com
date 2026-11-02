<?php

#[AllowDynamicProperties]
class BWFAN_Branches_Controller extends BWFAN_Conditional_Controller {
	protected $branches = array();

	protected function populate_filters() {
		if ( empty( $this->step_data ) || empty( $this->step_data['sidebarData'] ) || empty( $this->step_data['sidebarData']['branches'] ) ) {
			return false;
		}

		$this->branches = $this->step_data['sidebarData']['branches'];

		return true;
	}

	/**
	 * Evaluate branches in order, return first matching branch id.
	 * Errored branches are skipped and recorded in $eval_errors (locked decision #1).
	 *
	 * @param array $eval_errors
	 *
	 * @return string branch id or 'else'
	 */
	public function get_matched_branch( &$eval_errors = array() ) {
		if ( empty( $this->branches ) || empty( $this->automation_data ) ) {
			return 'else';
		}

		/**
		 * Surface rule failures instead of letting them read as "no match" — a broken
		 * branch must be recorded, not silently skipped past. Restored in `finally`
		 * because the loop returns early on the first match.
		 */
		$this->throw_on_error = true;
		try {
			foreach ( $this->branches as $branch ) {
				if ( ! is_array( $branch ) || empty( $branch['id'] ) || empty( $branch['rules'] ) || ! is_array( $branch['rules'] ) ) {
					continue;
				}

				$this->rules = $branch['rules'];
				try {
					if ( $this->is_match() ) {
						return $branch['id'];
					}
				} catch ( \Error | \Exception $e ) {
					$eval_errors[] = array(
						'branch' => $branch['id'],
						'msg'    => $e->getMessage(),
					);
					BWFAN_Common::log_test_data( 'Branch ' . $branch['id'] . ' skipped due to error: ' . $e->getMessage(), 'branches_controller_error', true );
				}
			}
		} finally {
			$this->throw_on_error = false;
		}

		return 'else';
	}
}
