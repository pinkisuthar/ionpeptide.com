<?php

#[\AllowDynamicProperties]
class BWFAN_Add_Contact_To_Automation_Controller {
	public $automation_id = '';
	public $contact_id = '';
	protected $messages = [];

	public function __construct( $automation_id, $contact_id ) {
		$this->automation_id = intval( $automation_id );
		$this->contact_id    = intval( $contact_id );

		$this->messages = [
			'required_data_missing'     => __( 'Required data are missing', 'wp-marketing-automations' ),
			'automation_data_not_found' => __( 'Automation data not found', 'wp-marketing-automations' ),
			'automation_is_not_active'  => __( 'Automation is not active', 'wp-marketing-automations' ),
			'event_not_found'           => __( 'Event not found', 'wp-marketing-automations' ),
			'contact_not_found'         => __( 'Contact does not exist', 'wp-marketing-automations' ),
			'not_added_to_automation'   => __( 'Contact not added to automation', 'wp-marketing-automations' ),
			'added_to_automation'       => __( 'Contact added to automation', 'wp-marketing-automations' ),
			'validation_failed'         => __( 'Event validation failed', 'wp-marketing-automations' ),
			'user_not_found'            => __( 'No linked WordPress user for this contact.', 'wp-marketing-automations' )
		];
	}

	public function add_contact_to_automation() {
		if ( empty( $this->automation_id ) || empty( $this->contact_id ) ) {
			return $this->get_response( 404, 'required_data_missing' );
		}

		$automation_data = $this->prepare_automation_data();
		if ( empty( $automation_data ) ) {
			return $this->get_response( 404, 'automation_data_not_found' );
		}

		if ( isset( $automation_data['code'] ) ) {
			return $this->get_response( 404, $automation_data['code'] );
		}

		/** @var BWFAN_Event $event */
		$event = BWFAN_Core()->sources->get_event( $automation_data['event'] );
		if ( ! $event instanceof BWFAN_Event ) {
			return $this->get_response( 404, 'event_not_found' );
		}

		$automation_data = $event->get_manually_added_contact_automation_data( $automation_data, $this->contact_id );
		if ( isset( $automation_data['status'] ) && 0 === $automation_data['status'] ) {
			$message = $automation_data['message'] ?? '';

			return $this->get_response( 404, $automation_data['type'], $message );
		}

		if ( false === apply_filters( 'bwfan_skip_add_contact_to_automation_validation', false, $event, $automation_data ) && false === $event->validate_v2_event_settings( $automation_data ) ) {
			return $this->get_response( 500, 'validation_failed' );
		}

		$event->global_data = $event->get_event_data( $automation_data );
		$event->event_data  = $event->get_automation_event_data( $automation_data );

		$result = $event->handle_automation_run_v2( $this->automation_id, $automation_data );

		return empty( $result ) ? $this->get_response( 500, 'not_added_to_automation' ) : $this->get_response( 200, 'added_to_automation' );
	}

	public function prepare_automation_data() {
		$ins             = new BWFAN_Automation_Controller();
		$automation_data = $ins->get_automation_data( $this->automation_id );
		if ( empty( $automation_data ) ) {
			return [];
		}

		if ( ! isset( $automation_data['status'] ) || 2 === intval( $automation_data['status'] ) ) {
			return [ 'code' => 'automation_is_not_active' ];
		}

		$automation_data = $this->set_or_unset_extra_data( $automation_data );
		if ( empty( $automation_data ) ) {
			return [];
		}

		$automation_data = BWFAN_Common::remove_extra_automation_data( $automation_data );

		return empty( $automation_data ) ? [] : $automation_data;
	}

	public function set_or_unset_extra_data( $automation_data ) {
		$id         = $automation_data['ID'];
		$meta       = $automation_data['meta'];
		$version    = $automation_data['v'];
		$unset_data = array( 'ID', 'meta', 'v', 'status', 'priority' );
		foreach ( $unset_data as $data ) {
			if ( isset( $automation_data[ $data ] ) ) {
				unset( $automation_data[ $data ] );
			}
		}

		$automation_data['id']      = $id;
		$automation_data['version'] = $version;

		return array_merge( $automation_data, $meta );
	}

	protected function get_response( $code, $type = '', $msg = '' ) {
		$msg = ! empty( $msg ) ? $msg : ( $this->messages[ $type ] ?? '' );

		return [ 'code' => $code, 'message' => $msg ];
	}

}
