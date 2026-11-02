<?php

#[\AllowDynamicProperties]
class BWFAN_Generic_Rule_Controller {

	protected $data = [];
	protected $rules = [];

	public function __construct( $rules, $data = [] ) {
		$this->set_data( $data );
		$this->rules = $rules;
	}

	public function set_data( $data ) {
		if ( empty( $data ) ) {
			$data = array_merge( BWFAN_Merge_Tag_Loader::get_data() );
			$data = [ 'global' => $data ];
		}

		/** If cid is not available in data */
		if ( ! isset( $data['global']['cid'] ) && isset( $data['global']['contact_id'] ) ) {
			$data['global']['cid'] = intval( $data['global']['contact_id'] );
		}
		/** Set email if not available */
		if ( ! isset( $data['global']['email'] ) && isset( $data['global']['contact_id'] ) ) {
			$contact                 = new BWFCRM_Contact( $data['global']['contact_id'] );
			$data['global']['email'] = $contact->get_id() > 0 ? $contact->contact->get_email() : '';
		}

		$this->data = $data;
	}

	/**
	 * Validate rules
	 *
	 * @return bool
	 */
	public function is_match() {
		if ( empty( $this->rules ) || empty( $this->data ) ) {
			return false;
		}

		/** No need to validate rule if preview or if is broadcast */
		if ( ( isset( $this->data['global']['is_preview'] ) && 1 === intval( $this->data['global']['is_preview'] ) ) || ( isset( $this->data['global']['broadcast_id'] ) && intval( $this->data['global']['broadcast_id'] ) > 0 ) ) {
			return true;
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

				$rule_data = $rule;
				$rule      = BWFAN_Core()->rules->get_rule( $rule['filter'] );
				if ( ! $rule instanceof BWFAN_Rule_Base || ! $rule->is_match_v2( $this->data, $rule_data ) ) {
					$rule_set_passed = false;
					break;
				}
			}

			if ( $rule_set_passed ) {
				return true;
			}
		}

		return false;
	}
}
