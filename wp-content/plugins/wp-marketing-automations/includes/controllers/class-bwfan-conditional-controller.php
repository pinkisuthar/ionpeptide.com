<?php

#[AllowDynamicProperties]
class BWFAN_Conditional_Controller extends BWFAN_Base_Step_Controller {
	protected $rules = array();

	public function populate_step_data( $db_step = array() ) {
		if ( parent::populate_step_data( $db_step ) ) {
			return $this->populate_filters();
		}

		return false;
	}

	protected function populate_filters() {
		if ( empty( $this->step_data ) ) {
			return false;
		}

		$this->rules = $this->step_data['sidebarData'];
	}

	/**
	 * When true, a missing or throwing rule class aborts evaluation instead of
	 * being swallowed as "no match". BWFAN_Branches_Controller sets this so a
	 * broken branch is recorded in its eval errors rather than silently falling
	 * through to Everyone Else.
	 *
	 * Deliberately a property, not an is_match() parameter: adding a parameter to
	 * a public method is a fatal ("Declaration must be compatible") for any
	 * third-party subclass that overrides is_match() with no arguments.
	 *
	 * @var bool
	 */
	protected $throw_on_error = false;

	public function is_match() {
		if ( empty( $this->rules ) || empty( $this->automation_data ) ) {
			return false;
		}

		/** Local copy so the stored automation contact data stays untouched */
		$automation_data = $this->automation_data;
		if ( isset( $automation_data['global'] ) && is_array( $automation_data['global'] ) ) {
			$automation_data['global']['step_id'] = $this->step_id;
		}

		foreach ( $this->rules as $rule_set ) {
			if ( ! is_array( $rule_set ) || empty( $rule_set ) ) {
				continue;
			}

			$rule_set_passed = true;
			foreach ( $rule_set as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['filter'] ) ) {
					continue;
				}

				$rule_data  = $rule;
				$rule_class = BWFAN_Core()->rules->get_rule( $rule['filter'] );
				if ( empty( $rule_class ) ) {
					BWFAN_Core()->rules->load_rules_classes();
					$rule_class = BWFAN_Core()->rules->get_rule( $rule['filter'] );
				}
				if ( $this->throw_on_error && ! $rule_class instanceof BWFAN_Rule_Base ) {
					throw new Exception( 'Rule class missing for filter: ' . esc_html( $rule['filter'] ) );
				}
				try {
					if ( ! $rule_class instanceof BWFAN_Rule_Base || ! $rule_class->is_match_v2( $automation_data, $rule_data ) ) {
						$rule_set_passed = false;
						break;
					}
				} catch ( \Error | \Exception $e ) {
					if ( $this->throw_on_error ) {
						throw $e;
					}
					$rule_set_passed = false;
					BWFAN_Common::log_test_data( $e->getMessage() . ' | ' . $e->getTraceAsString(), 'conditional_controller_error', true );
					break;
				}
			}

			if ( $rule_set_passed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get retry options
	 *
	 * @return array
	 */
	public function get_retry_data() {
		return array(
			HOUR_IN_SECONDS, // 1 hr
			6 * HOUR_IN_SECONDS, // 6 hrs
			18 * HOUR_IN_SECONDS, // 18 hrs
		);
	}
}
