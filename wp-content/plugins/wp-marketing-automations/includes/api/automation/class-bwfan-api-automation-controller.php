<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consolidated Automation API Controller.
 *
 * Merges all 26 automation API endpoint classes into a single controller.
 *
 * @see BWFAN_API_Controller
 */
class BWFAN_API_Automation_Controller extends BWFAN_API_Controller {

	public static $ins;

	public $total_count = 0;

	/** Used by get_automation, get_automation_contacts, get_automation_conversions, get_automation_recipients */
	private $aid = 0;

	/** Used by get_automation_contact_trail */
	public $trail = null;

	/** Used by get_automation_recipient_timeline */
	public $recipients_timeline = [];

	/** Used by get_single_automation_stats */
	private $automation_obj = null;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	protected function register_routes() {

		/** GET /automation/{id} — fetch single automation */
		/** PUT /automation/{id} — update single automation */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)', [
			[ 'GET', 'get_automation', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
			[ 'PUT', 'update_automation', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** POST /automation/{id}/add-step — add step to automation */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/add-step', [
			[ 'POST', 'add_automation_step', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** PUT /automation/{id}/step/{sid} — update automation step */
		/** DELETE /automation/{id}/step/{sid} — delete automation step */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/step/(?P<step_id>[\d]+)', [
			[ 'PUT', 'update_automation_step', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'step_id'       => [
					'description' => __( 'Step ID to update', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
			[ 'DELETE', 'delete_automation_step', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'step_id'       => [
					'description' => __( 'Step ID to update', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** POST /automation/add-contact — add contact to automation */
		$this->add_route( '/automation/add-contact', [
			[ 'POST', 'add_contact_to_automation', [
				'automation_id' => [
					'description' => __( 'Automation ID to add contact to', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'contact_id'    => [
					'description' => __( 'Contact ID to add into automation', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** POST /automation/{id}/contact/status — change automation contact status */
		$this->add_route( '/automation/(?P<contact_automation_id>[\d]+)/contact/status', [
			[ 'POST', 'change_automation_status', [
				'contact_automation_id' => [
					'description' => __( 'Automation contact ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** POST /automation/bulk-action — automation contact bulk action */
		$this->add_route( '/automation/bulk-action', [
			[ 'POST', 'automation_contact_bulk_action' ],
		] );

		/** DELETE /automation/{id}/delete-contact — delete automation contact */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/delete-contact', [
			[ 'DELETE', 'delete_automation_contact', [
				'automation_id' => [
					'description' => __( 'Automation ID', 'wp-marketing-automations' ),
					'type'        => 'string',
				],
			] ],
		] );

		/** GET /automation/{id}/contacts/ — get automation contacts */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/contacts/', [
			[ 'GET', 'get_automation_contacts', [
				'automation_id' => [
					'description' => __( 'Automation id to retrieve contacts', 'wp-marketing-automations' ),
					'type'        => 'string',
				],
			] ],
		] );

		/** GET /automations/contacts/ — get all automation contacts */
		$this->add_route( '/automations/contacts/', [
			[ 'GET', 'get_all_automation_contacts' ],
		] );

		/** GET /automation/{id}/contacts/journey — get automation contacts journey */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/contacts/journey', [
			[ 'GET', 'get_automation_contacts_journey', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** GET /automation/{id}/trail/ — get automation contact trail */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/trail/', [
			[ 'GET', 'get_automation_contact_trail', [
				'tid' => [
					'description' => __( 'Trail ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'string',
				],
			] ],
		] );

		/** GET /automation/{id}/step/{sid}/contacts — get automation step contacts */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/step/(?P<step_id>[\d]+)/contacts', [
			[ 'GET', 'get_automation_step_contacts', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'step_id'       => [
					'description' => __( 'Step ID data to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** GET /automation/{id}/analytics/{sid} — get node analytics */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/analytics/(?P<step_id>[\d]+)', [
			[ 'GET', 'get_node_analytics', [
				'automation_id' => [
					'description' => __( 'Automation ID', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'step_id'       => [
					'description' => __( 'Step ID ', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** POST /automation/{id}/merger-points — get automation merger points */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/merger-points', [
			[ 'POST', 'get_automation_merger_points', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** GET /automation/actions/{action}/suggestions — get action search suggestions */
		$this->add_route( '/automation/actions/(?P<action>[a-zA-Z0-9_-]+)/suggestions', [
			[ 'GET', 'get_actions_search_suggestions' ],
		] );

		/** GET /automation/rules/{rule}/suggestions — get rule search suggestions */
		$this->add_route( '/automation/rules/(?P<rule>[a-zA-Z0-9_-]+)/suggestions', [
			[ 'GET', 'get_rules_suggestions' ],
		] );

		/** GET /automation/event-rules/{event} — get rules */
		$this->add_route( '/automation/event-rules/(?P<event>[a-zA-Z0-9_,]+)', [
			[ 'GET', 'get_rules' ],
		] );

		/** GET /custom-search/{type} — custom search */
		$this->add_route( '/custom-search/(?P<type>[a-zA-Z0-9_]+)', [
			[ 'GET', 'custom_search' ],
		] );

		/** DELETE /bulk-action/{type} — bulk action */
		$this->add_route( '/bulk-action/(?P<type>[a-zA-Z0-9_-]+)', [
			[ 'DELETE', 'bulk_action' ],
		] );

		/** DELETE /automation/{id}/delete_meta — remove automation meta */
		$this->add_route( '/automation/(?P<automation_id>[\d]+)/delete_meta', [
			[ 'DELETE', 'remove_automation_meta' ],
		] );

		/** GET /automation/allowed-automations — get automations to add contact manually */
		$this->add_route( '/automation/allowed-automations', [
			[ 'GET', 'get_automation_to_add_contact_manually' ],
		] );

		/** GET /automations-stats/{id}/ — get single automation stats */
		$this->add_route( '/automations-stats/(?P<automation_id>[\d]+)/', [
			[ 'GET', 'get_single_automation_stats', [
				'automation_id' => [
					'description' => __( 'Automation id to stats', 'wp-marketing-automations' ),
					'type'        => 'string',
				],
			] ],
		] );

		/** v3 routes (pro) — registered only when pro 3.0 is active */
		if ( method_exists( 'BWFAN_Common', 'is_pro_3_0' ) && BWFAN_Common::is_pro_3_0() ) {
			/** POST v3/automation/{id}/bulk-action — automation bulk action (v3 pro) */
			$this->add_route( 'v3/automation/(?P<automation_id>[\d]+)/bulk-action', [
				[ 'POST', 'automation_bulk_action_v3', [
					'automation_id' => [
						'description' => __( 'Automation ID', 'wp-marketing-automations' ),
						'type'        => 'integer',
					],
				] ],
			] );
		}

		/** GET v3/automation/{id}/conversions/ — get automation conversions */
		$this->add_route( 'v3/automation/(?P<automation_id>[\d]+)/conversions/', [
			[ 'GET', 'get_automation_conversions', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'offset'        => [
					'description' => __( 'Contacts list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'limit'         => [
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** GET v3/automation/{id}/recipients/ — get automation recipients */
		$this->add_route( 'v3/automation/(?P<automation_id>[\d]+)/recipients/', [
			[ 'GET', 'get_automation_recipients', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'offset'        => [
					'description' => __( 'Contacts list Offset', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'limit'         => [
					'description' => __( 'Per page limit', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );

		/** GET v3/automation/{id}/recipients/{cid}/timeline — get automation recipient timeline */
		$this->add_route( 'v3/automation/(?P<automation_id>[\d]+)/recipients/(?P<contact_id>[\d]+)/timeline', [
			[ 'GET', 'get_automation_recipient_timeline', [
				'automation_id' => [
					'description' => __( 'Automation ID to retrieve engagements', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
				'contact_id'    => [
					'description' => __( 'Contact ID to retrieve recipient timeline', 'wp-marketing-automations' ),
					'type'        => 'integer',
				],
			] ],
		] );
	}

	// ─── Default args ───────────────────────────────────────────────

	public function default_args_values() {
		$args = [
			'ids' => [],
		];

		return $args;
	}

	/**
	 * 409 when the builder sent a revision that is no longer current (spec §17); null
	 * when none was sent (older builders, recipes and imports keep working) or it
	 * matches. Takes the shared edit lock (3 s) and holds it until this request ends,
	 * so an MCP update can't write between this check and this handler's write.
	 */
	private function revision_conflict( $automation_id, $args ) {
		if ( BWFAN_Automation_V2::check_revision( $automation_id, isset( $args['revision'] ) ? $args['revision'] : '' ) ) {
			return null;
		}

		return $this->error_response( __( 'This Automation changed since you opened it. Reload to see the latest version.', 'wp-marketing-automations' ), null, 409 );
	}

	/** The revision after this handler's writes, for the builder's next save. */
	private function current_revision( $automation_id ) {
		return BWFAN_Automation_V2::get_revision( absint( $automation_id ) );
	}

	// ─── GET /automation/{id} ───────────────────────────────────────
	// Originally: BWFAN_API_Automation_Single::get_items()

	public function get_automation() {
		$automation_id = $this->get_sanitized_arg( 'automation_id' );
		if ( empty( $automation_id ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( $automation_obj->error, null, 400 );
		}
		$this->aid = $automation_id;

		/** Fetch Automation data */
		$automation_data = $automation_obj->get_automation_API_data();

		if ( ! $automation_data['status'] ) {
			return $this->error_response( ! empty( $automation_data['message'] ) ? $automation_data['message'] : __( 'Automation not found with provided ID', 'wp-marketing-automations' ), null, 400 );
		} else {
			$automation = $automation_data['data'];
		}

		$automation['revision'] = $this->current_revision( $automation_id ); // last: get_automation_API_data() can itself write.

		$this->response_code = 200;

		return $this->success_response( $automation, ! empty( $automation_data['message'] ) ? $automation_data['message'] : __( 'Successfully fetched automation', 'wp-marketing-automations' ) );
	}

	// ─── PUT /automation/{id} ───────────────────────────────────────
	// Originally: BWFAN_API_Automation_Single::update_item()

	public function update_automation() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$arg_data      = $this->args['data'];
		if ( empty( $arg_data ) ) {
			return $this->error_response( [], __( 'Automation Data is missing.', 'wp-marketing-automations' ) );
		}
		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		$conflict = $this->revision_conflict( $automation_id, $this->args );
		if ( null !== $conflict ) {
			return $conflict;
		}

		$data    = $steps = $links = $meta = [];
		$count   = 0;
		$updated = false;
		/** Main table data */
		if ( isset( $arg_data['data'] ) && ! empty( $arg_data['data'] ) ) {
			$data = $arg_data['data'];
		}

		/** Step data */
		if ( isset( $arg_data['steps'] ) && ! empty( $arg_data['steps'] ) ) {
			$steps = $arg_data['steps'];
		}

		/** Link data */
		if ( isset( $arg_data['links'] ) && ! empty( $arg_data['links'] ) ) {
			$links = $arg_data['links'];
		}

		/** Node count */
		if ( isset( $arg_data['count'] ) && intval( $arg_data['count'] ) > 0 ) {
			$count = intval( $arg_data['count'] );
		}

		/** Update automation meta */
		if ( isset( $arg_data['meta'] ) && ! empty( $arg_data['meta'] ) ) {
			$meta = $arg_data['meta'];
		}

		/** Check for unique key */
		if ( isset( $arg_data['need_unique_key'] ) && is_bool( $arg_data['need_unique_key'] ) && $arg_data['need_unique_key'] == true ) {
			$meta['event_meta'] = [
				'bwfan_unique_key' => wp_generate_password( 32, false )
			];
		}

		/** Check for unique key */
		if ( isset( $arg_data['isWebhook'] ) && is_bool( $arg_data['isWebhook'] ) && $arg_data['isWebhook'] == true && isset( $meta['event_meta'] ) ) {
			$automation_meta = $automation_obj->get_automation_meta_data();
			$ameta           = [];
			if ( isset( $automation_meta['event_meta'] ) ) {
				$ameta = $automation_meta['event_meta'];
			}

			$exclude_key = [ 'bwfan_unique_key', 'received_at', 'referer', 'webhook_data' ];
			if ( ! empty( $meta['event_meta'] ) ) {
				foreach ( $meta['event_meta'] as $key => $value ) {
					if ( ! in_array( $key, $exclude_key ) ) {
						$ameta[ $key ] = $value;
					}
				}
			}
			$meta['event_meta'] = $ameta;
		}
		/** Check for data */
		if ( empty( $data ) && empty( $steps ) && empty( $links ) && $count == 0 && empty( $meta ) ) {
			return $this->error_response( [], __( 'Automation Data is missing.', 'wp-marketing-automations' ) );
		}

		if ( ! empty( $data ) ) {
			if ( isset( $data['start'] ) ) {
				unset( $data['start'] );
			}
			/** Update main table data */
			$updated = $automation_obj->update_automation_main_table( $data );
		}

		if ( ! empty( $steps ) || ! empty( $links ) || $count !== 0 || ! empty( $meta ) ) {
			/** Update automation data with meta data */
			$updated = $automation_obj->update_automation_meta_data( $meta, $steps, $links, $count );
		}

		if ( $updated ) {
			$this->response_code = 200;
			$automation_obj->fetch_automation_metadata( false );
			$automation_meta = $automation_obj->get_automation_meta_data();
			if ( ! empty( $automation_meta['steps'] ) ) {
				unset( $automation_meta['steps'] );
			}
			if ( ! empty( $automation_meta['links'] ) ) {
				unset( $automation_meta['links'] );
			}

			if ( ! empty( $automation_meta['count'] ) ) {
				unset( $automation_meta['count'] );
			}
			if ( isset( $automation_meta['event_meta'] ) ) {
				$automation_meta['event_meta'] = BWFAN_Common::fetch_updated_data( $automation_meta['event_meta'] );
			}

			return $this->success_response( [
				'meta'     => $automation_meta,
				'revision' => $this->current_revision( $automation_id ),
			], __( 'Automation Data Updated', 'wp-marketing-automations' ) );
		} else {
			$this->response_code = 404;

			return $this->error_response( [], __( 'Unable to updated data', 'wp-marketing-automations' ) );
		}
	}

	// ─── POST /automation/{id}/add-step ─────────────────────────────
	// Originally: BWFAN_API_Add_Automation_Step::process_api_call()

	public function add_automation_step() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$type          = isset( $this->args['type'] ) ? $this->get_sanitized_arg( 'type', 'text_field' ) : 'action';

		$data = isset( $this->args['data'] ) ? $this->args['data'] : [];

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		$conflict = $this->revision_conflict( $automation_id, $this->args );
		if ( null !== $conflict ) {
			return $conflict;
		}

		/** Add new step and get id */
		$step_id = $automation_obj->add_new_automation_step( $type, $data );

		if ( empty( $step_id ) ) {
			return $this->error_response( ! empty( $automation_obj->error ) ? $automation_obj->error : __( 'Unable to create automation step', 'wp-marketing-automations' ), null, 400 );
		}

		$this->response_code = 200;

		return $this->success_response( [ 'step_id' => $step_id, 'revision' => $this->current_revision( $automation_id ) ], __( 'Data updated', 'wp-marketing-automations' ) );
	}

	// ─── PUT /automation/{id}/step/{sid} ────────────────────────────
	// Originally: BWFAN_API_Automation_Step::update_item()

	public function update_automation_step() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$step_id       = $this->get_sanitized_arg( 'step_id', 'text_field' );
		$arg_data      = $this->args;

		if ( isset( $arg_data['content'] ) ) {
			$content  = BWFAN_Common::is_json( $arg_data['content'] ) ? json_decode( $arg_data['content'], true ) : [];
			$arg_data = wp_parse_args( $content, $arg_data );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [ "Step Not Updated" ], $automation_obj->error );
		}

		$conflict = $this->revision_conflict( $automation_id, $arg_data ); // $arg_data already has `content` merged in.
		if ( null !== $conflict ) {
			return $conflict;
		}

		// Update step data
		$data          = $steps = $links = [];
		$count         = 0;
		$optionUpdated = false;

		/** Action table data */
		if ( isset( $arg_data['data'] ) && ! empty( $arg_data['data'] ) ) {
			$data = $arg_data['data'];
		}

		/** Step data */
		if ( isset( $arg_data['steps'] ) && ! empty( $arg_data['steps'] ) ) {
			$steps = $arg_data['steps'];
		}

		/** Link data */
		if ( isset( $arg_data['links'] ) && ! empty( $arg_data['links'] ) ) {
			$links = $arg_data['links'];
		}

		/** Node count */
		if ( isset( $arg_data['count'] ) && intval( $arg_data['count'] ) > 0 ) {
			$count = intval( $arg_data['count'] );
		}

		/** Update automation data */
		if ( ! empty( $steps ) && ! empty( $links ) ) {
			$automation_obj->update_automation_meta_data( [], $steps, $links, $count );
		}

		/** Benchmark Option Updated data */
		if ( isset( $arg_data['optionUpdated'] ) && ! empty( $arg_data['optionUpdated'] ) ) {
			$optionUpdated = $arg_data['optionUpdated'];
		}
		$automation_obj->fetch_automation_metadata( false );
		$automation_meta = $automation_obj->get_automation_meta_data();
		if ( ! empty( $automation_meta['steps'] ) ) {
			unset( $automation_meta['steps'] );
		}
		if ( ! empty( $automation_meta['links'] ) ) {
			unset( $automation_meta['links'] );
		}

		if ( ! empty( $automation_meta['count'] ) ) {
			unset( $automation_meta['count'] );
		}
		if ( isset( $automation_meta['event_meta'] ) ) {
			$automation_meta['event_meta'] = BWFAN_Common::fetch_updated_data( $automation_meta['event_meta'] );
		}
		/** Save mail and sms steps in split steps */
		if ( isset( $automation_meta['split_steps'] ) ) {
			$this->save_mail_steps( $automation_meta['split_steps'] );
		}
		$return_val['meta'] = $automation_meta;

		$update_status = 1;

		/** Update status */
		if ( isset( $arg_data['updateStatus'] ) && ! empty( $arg_data['updateStatus'] ) ) {
			$update_status = intval( $arg_data['updateStatus'] );
		}

		if ( empty( $data ) ) {
			/**
			 * Status-only update path (no action data to save) — e.g. the Disable/Enable
			 * step toggle sends only { updateStatus }. This must run BEFORE the early
			 * return below, otherwise the status change is silently dropped.
			 */
			if ( isset( $arg_data['updateStatus'] ) && ! empty( $arg_data['updateStatus'] ) ) {
				/** Whitelist — the status-only path only toggles Active (1) <-> Disabled (5). */
				$allowed_statuses = [
					BWFAN_Model_Automation_Step::STATUS_ACTIVE,   // 1
					BWFAN_Model_Automation_Step::STATUS_DISABLED, // 5
					BWFAN_Model_Automation_Step::STATUS_INCOMPLETE, // 2
				];
				if ( ! in_array( $update_status, $allowed_statuses, true ) ) {
					return $this->error_response( __( 'Invalid step status value', 'wp-marketing-automations' ), null, 400 );
				}

				$current_step   = BWFAN_Model_Automation_Step::get_step_data_by_id( $step_id );
				$current_status = isset( $current_step['status'] ) ? absint( $current_step['status'] ) : 0;

				/**
				 * Source-status guard — the toggle may only move a step between Active (1)
				 * and Disabled (5). Refuse to touch an Archived (4/deleted) or Incomplete (2)
				 * step, otherwise this path would resurrect a deleted step or force an
				 * incomplete one active. Handles enable AND disable (a deleted step must not
				 * be disable-toggled either).
				 */
				if ( ! in_array( $current_status, $allowed_statuses, true ) ) {
					return $this->error_response( __( 'This step can no longer be updated', 'wp-marketing-automations' ), null, 400 );
				}

				/** Eligibility gate for STATUS_DISABLED — reject ineligible step types. */
				if ( BWFAN_Model_Automation_Step::STATUS_DISABLED === $update_status ) {
					/**
					 * Pro-only gate — disabling a step requires FunnelKit Automations Pro.
					 * Enabling (status 1) is intentionally NOT gated so a downgraded site can
					 * still clear an existing disabled step.
					 */
					if ( ! function_exists( 'bwfan_is_autonami_pro_active' ) || ! bwfan_is_autonami_pro_active() ) {
						return $this->error_response( __( 'Disabling a step requires FunnelKit Automations Pro', 'wp-marketing-automations' ), null, 400 );
					}

					$current_type          = isset( $current_step['type'] ) ? absint( $current_step['type'] ) : 0;
					$disable_allowed_types = [
						BWFAN_Automation_Controller::$TYPE_ACTION,
						BWFAN_Automation_Controller::$TYPE_WAIT,
						BWFAN_Automation_Controller::$TYPE_CONDITIONAL,
						BWFAN_Automation_Controller::$TYPE_SPLIT,
						BWFAN_Automation_Controller::$TYPE_BRANCHES,
					];
					if ( ! in_array( $current_type, $disable_allowed_types, true ) ) {
						return $this->error_response( __( 'This step type cannot be disabled', 'wp-marketing-automations' ), null, 400 );
					}
				}

				/** Check if step has required data and set status draft if sidebar data is empty */
				$data = isset( $current_step['data'] ) ? json_decode( $current_step['data'], true ) : [];
				$sidebar_data = isset( $data['sidebarData'] ) ? $data['sidebarData'] : [];
				if ( $update_status === BWFAN_Model_Automation_Step::STATUS_ACTIVE && empty( $sidebar_data ) ) {
					$update_status = BWFAN_Model_Automation_Step::STATUS_INCOMPLETE;
				}

				BWFAN_Model_Automation_Step::update_automation_step_data( $step_id, [ 'status' => $update_status ] );
			}

			$this->response_code = 200;

			$return_val['revision'] = $this->current_revision( $automation_id );

			return $this->success_response( $return_val, __( 'Step Updated', 'wp-marketing-automations' ) );
		}

		$step_data      = BWFAN_Model_Automation_Step::get_step_data_by_id( $step_id );
		$action         = isset( $step_data['action'] ) ? json_decode( $step_data['action'], true ) : [];
		$action_name    = isset( $action['action'] ) ? $action['action'] : '';
		$benchmark_name = isset( $action['benchmark'] ) ? $action['benchmark'] : '';

		if ( in_array( $action_name, [ 'crm_add_to_list', 'crm_add_tag', 'crm_rmv_tag', 'crm_rmv_from_list' ], true ) ) {
			/** Create List/tags if not exists */
			$terms = [];
			if ( class_exists( 'BWFCRM_Term_Type' ) ) {
				$term_type = BWFCRM_Term_Type::$TAG;
				$terms     = isset( $data['data']['sidebarData']['tags'] ) ? $data['data']['sidebarData']['tags'] : [];
				if ( empty( $terms ) && ( 'crm_add_to_list' === $action_name || 'crm_rmv_from_list' === $action_name ) ) {
					$term_type = BWFCRM_Term_Type::$LIST;
					$terms     = isset( $data['data']['sidebarData']['list_id'] ) ? $data['data']['sidebarData']['list_id'] : [];
				}
			}

			$terms_with_mergetags = [];
			foreach ( $terms as $index => $term ) {
				if ( 0 !== absint( $term['id'] ) || false === strpos( $term['name'], '}}' ) ) {
					continue;
				}
				unset( $terms[ $index ] );
				$terms_with_mergetags[] = $term;
			}

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$terms = BWFAN_Common::get_or_create_terms( $terms, $term_type );
			}

			$terms = array_merge( $terms, $terms_with_mergetags );

			/** Checking action is Added to List and Add Tag */
			if ( ( 'crm_add_to_list' === $action_name || 'crm_rmv_from_list' === $action_name ) && ! empty( $terms ) ) {
				$data['data']['sidebarData']['list_id'] = $terms;
			} elseif ( ( 'crm_add_tag' === $action_name || 'crm_rmv_tag' === $action_name ) && ! empty( $terms ) ) {
				$data['data']['sidebarData']['tags'] = $terms;
			}
		}

		/** If no setting in action */
		if ( empty( $action_name ) && isset( $data['action'] ) && isset( $data['action']['action'] ) ) {
			$action_name   = $data['action']['action'];
			$action        = BWFAN_Core()->integration->get_action( $action_name );
			$fields_schema = $action instanceof BWFAN_Action ? $action->get_fields_schema() : null;
			if ( ! is_null( $fields_schema ) && empty( $fields_schema ) ) {
				$data['data']  = [ 'sidebarData' => [] ];
				$update_status = 1;
			}
		}

		/** update step data */
		$result = $automation_obj->update_automation_step_data( $step_id, $data, $update_status, $optionUpdated );
		if ( $result ) {
			if ( ! empty( $action_name ) ) {
				$action = ( $action instanceof BWFAN_Action ) ? $action : BWFAN_Core()->integration->get_action( $action_name );
				if ( ! empty( $action ) && method_exists( $action, 'get_desc_text' ) && isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
					$return_val['desc_text'] = $action->get_desc_text( $data['data']['sidebarData'] );
				}
			}
			if ( ! empty( $benchmark_name ) ) {
				$benchmark = BWFAN_Core()->sources->get_event( $benchmark_name );
				if ( ! empty( $action ) && method_exists( $benchmark, 'get_desc_text' ) && isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
					$return_val['desc_text'] = $benchmark->get_desc_text( $data['data']['sidebarData'] );
				}
			}
			$this->response_code = 200;

			$return_val['revision'] = $this->current_revision( $automation_id );

			return $this->success_response( $return_val, __( 'Step Updated', 'wp-marketing-automations' ) );
		} else {
			return $this->error_response( [ __( "Step Not Updated", 'wp-marketing-automations' ) ], __( 'Unable to update action data', 'wp-marketing-automations' ) );
		}
	}

	// ─── DELETE /automation/{id}/step/{sid} ──────────────────────────
	// Originally: BWFAN_API_Automation_Step::delete_item()

	public function delete_automation_step() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$step_id       = $this->get_sanitized_arg( 'step_id', 'text_field' );
		$node_type     = $this->get_sanitized_arg( 'nodeType', 'text_field' );
		$arg_data      = $this->args;

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		$conflict = $this->revision_conflict( $automation_id, $arg_data );
		if ( null !== $conflict ) {
			return $conflict;
		}

		$steps = $links = [];
		$count = 0;

		/** Step data */
		if ( isset( $arg_data['steps'] ) && ! empty( $arg_data['steps'] ) ) {
			$steps = $arg_data['steps'];
		}

		/** Link data */
		if ( isset( $arg_data['links'] ) && ! empty( $arg_data['links'] ) ) {
			$links = $arg_data['links'];
		}

		/** Node count */
		if ( isset( $arg_data['count'] ) && intval( $arg_data['count'] ) > 0 ) {
			$count = intval( $arg_data['count'] );
		}

		/** Update automation data */
		if ( ! empty( $steps ) && ! empty( $links ) ) {
			$automation_obj->update_automation_meta_data( [], $steps, $links, $count );
		}

		/** Delete step and get response */
		$response = $automation_obj->delete_automation_step( $step_id );

		if ( ! $response ) {
			// The builder saves the graph without the node first, and that save already
			// soft-deleted this step (status 3): a success, not the old error path.
			$row = BWFAN_Model_Automation_Step::get_step_data_by_id( $step_id );
			if ( empty( $row ) || 3 !== (int) $row['status'] || absint( $row['aid'] ) !== absint( $automation_id ) ) {
				return $this->error_response( [], 'Unable to delete the node' );
			}
		}

		if ( 'benchmark' === $node_type || 'wait' === $node_type ) {
			$status             = 'wait' === $node_type ? 1 : 4;
			$queued_automations = BWFAN_Model_Automation_Contact::get_automation_contact_by_sid( $step_id, '', $status );

			if ( ! empty( $queued_automations ) && is_array( $queued_automations ) ) {
				$key  = 'bwf_queued_automations_' . $step_id;
				$args = [ 'sid' => $step_id ];

				/** Un-schedule action */
				if ( bwf_has_action_scheduled( 'bwfan_automation_step_deleted', $args ) ) {
					bwf_unschedule_actions( 'bwfan_automation_step_deleted', $args );
				}

				$ids                = array_column( $queued_automations, 'ID' );
				$queued_automations = wp_json_encode( $ids );
				update_option( $key, $queued_automations, false );
				bwf_schedule_recurring_action( time(), 120, 'bwfan_automation_step_deleted', $args );
			}
		}
		$automation_obj->fetch_automation_metadata( false );
		$automation_meta = $automation_obj->get_automation_meta_data();
		if ( ! empty( $automation_meta['steps'] ) ) {
			unset( $automation_meta['steps'] );
		}
		if ( ! empty( $automation_meta['links'] ) ) {
			unset( $automation_meta['links'] );
		}

		if ( ! empty( $automation_meta['count'] ) ) {
			unset( $automation_meta['count'] );
		}
		if ( isset( $automation_meta['event_meta'] ) ) {
			$automation_meta['event_meta'] = BWFAN_Common::fetch_updated_data( $automation_meta['event_meta'] );
		}

		$this->response_code = 200;

		return $this->success_response( [ 'meta' => $automation_meta, 'revision' => $this->current_revision( $automation_id ) ], __( 'Data updated', 'wp-marketing-automations' ) );
	}

	// ─── POST /automation/add-contact ───────────────────────────────
	// Originally: BWFAN_API_Add_Contact_To_Automation::process_api_call()

	public function add_contact_to_automation() {
		$automation_id = $this->get_sanitized_arg( 'automation_id' );
		$contact_id    = $this->get_sanitized_arg( 'contact_id' );

		if ( empty( $automation_id ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		if ( empty( $contact_id ) ) {
			return $this->error_response( __( 'Invalid / Empty contact ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		$ins      = new BWFAN_Add_Contact_To_Automation_Controller( $automation_id, $contact_id );
		$response = $ins->add_contact_to_automation();

		$this->response_code = $response['code'] ?? 200;
		$message             = $response['message'] ?? __( 'Unknown error occurred', 'wp-marketing-automations' );

		return $this->success_response( [], $message );
	}

	// ─── POST /automation/{id}/contact/status ───────────────────────
	// Originally: BWFAN_API_Change_Automation_Status::process_api_call()

	public function change_automation_status() {
		$a_cid = absint( $this->get_sanitized_arg( 'contact_automation_id', 'text_field' ) );

		if ( empty( $a_cid ) ) {
			return $this->error_response( [], __( 'Automation contact ID is missing', 'wp-marketing-automations' ) );
		}
		/** To be changed status*/
		$to = $this->get_sanitized_arg( 'to', 'text_field' );
		if ( empty( $to ) ) {
			return $this->error_response( [], __( 'Status is missing', 'wp-marketing-automations' ) );
		}

		$data = ( 're_run' === $to ) ? BWFAN_Model_Automation_Complete_Contact::get( $a_cid ) : BWFAN_Model_Automation_Contact::get_data( $a_cid );

		$aid = isset( $data['aid'] ) ? $data['aid'] : 0;

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $aid );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->errors ) ) {
			return $this->error_response( __( 'Automation not found', 'wp-marketing-automations' ), $automation_obj->errors );
		}

		switch ( $to ) {
			case 'end' === $to:
				$trail = isset( $data['trail'] ) ? $data['trail'] : '';

				/** Update the step trail status as complete if active */
				BWFAN_Model_Automation_Contact_Trail::update_all_step_trail_status_complete( $trail );

				$result = BWFAN_Common::end_v2_automation( $a_cid, $data, 'manually' );
				break;
			case 're_run' === $to:
				global $wpdb;
				$query        = "SELECT `ID`,`cid`,`aid`,`trail`,`event`,`data` FROM {$wpdb->prefix}bwfan_automation_complete_contact WHERE `ID` = %d";
				$query_result = $wpdb->get_results( $wpdb->prepare( $query, $a_cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; id absint() and bound via %d
				BWFAN_Common::insert_automations( $query_result );
				$result = true;
				break;
			case 'startbegin' === $to:
				global $wpdb;
				$query        = "SELECT `ID`,`cid`,`aid`,`trail` FROM {$wpdb->prefix}bwfan_automation_contact WHERE `ID` = %d";
				$query_result = $wpdb->get_results( $wpdb->prepare( $query, $a_cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; id absint() and bound via %d
				$trails       = array_column( $query_result, 'trail' );
				BWFAN_Common::update_automation_status( array( $a_cid ), 1, $trails, current_time( 'timestamp', 1 ), true );
				$result = true;
				break;
			default:
				$result = $automation_obj->change_automation_status( $to, $a_cid );
				break;
		}
		if ( $result ) {
			$this->response_code = 200;

			return $this->success_response( [], __( 'Automation contact updated', 'wp-marketing-automations' ) );
		}
		$this->response_code = 404;

		return $this->error_response( [], __( 'Unable to update automation contact', 'wp-marketing-automations' ) );
	}

	// ─── POST /automation/bulk-action ───────────────────────────────
	// Originally: BWFAN_API_Automation_Contact_Bulk_Action::process_api_call()

	public function automation_contact_bulk_action() {
		$action = $this->get_sanitized_arg( 'action', 'text_field' );
		$status = $this->get_sanitized_arg( 'status', 'text_field' );
		$a_cids = isset( $this->args['a_cids'] ) ? $this->args['a_cids'] : [];

		if ( empty( $a_cids ) ) {
			return $this->error_response( [], __( 'Required parameter is missing', 'wp-marketing-automations' ) );
		}

		$dynamic_string = BWFAN_Common::get_dynamic_string();
		$args           = [ 'key' => $dynamic_string, 'action' => $action, 'status' => $status ];
		sort( $a_cids );
		update_option( "bwfan_bulk_automation_all_contact_{$action}_{$dynamic_string}", $a_cids );
		bwf_schedule_recurring_action( time(), 60, "bwfan_automation_all_contact_bulk_action", $args );
		BWFAN_Common::ping_woofunnels_worker();
		$this->response_code = 200;

		return $this->success_response( [], __( 'Process is scheduled. Will soon be executed.', 'wp-marketing-automations' ) );
	}

	// ─── POST v3/automation/{id}/bulk-action (pro) ──────────────────
	// Originally: BWFAN_API_Automation_Bulk_Action::process_api_call()

	public function automation_bulk_action_v3() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$action        = $this->get_sanitized_arg( 'action', 'text_field' );
		$status        = $this->get_sanitized_arg( 'status', 'text_field' );
		$a_cids        = isset( $this->args['a_cids'] ) ? $this->args['a_cids'] : [];
		$a_cids        = array_map( 'absint', $a_cids );
		if ( empty( $a_cids ) ) {
			return $this->error_response( [], __( 'Required parameter is missing', 'wp-marketing-automations' ) );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		$dynamic_string = BWFAN_Common::get_dynamic_string();
		$args           = [ 'key' => $dynamic_string, 'aid' => $automation_id, 'action' => $action, 'status' => $status ];
		sort( $a_cids );
		update_option( "bwfan_bulk_automation_contact_{$action}_{$dynamic_string}", $a_cids );
		bwf_schedule_recurring_action( time(), 60, "bwfan_automation_contact_bulk_action", $args );
		BWFCRM_Common::ping_woofunnels_worker();
		$this->response_code = 200;

		return $this->success_response( [], __( 'Process is scheduled. Will soon be executed.', 'wp-marketing-automations' ) );
	}

	// ─── DELETE /automation/{id}/delete-contact ─────────────────────
	// Originally: BWFAN_API_Delete_Automation_Contacts::process_api_call()

	public function delete_automation_contact() {
		$trail_id = $this->get_sanitized_arg( 'trail_id', 'text_field' );
		$ac_id    = $this->get_sanitized_arg( 'ac_id', 'text_field' );
		$type     = $this->get_sanitized_arg( 'type', 'text_field' );

		if ( empty( $trail_id ) && empty( $ac_id ) ) {
			return $this->error_response( __( 'Invalid / Empty Contact data provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Automation Contact table */
		if ( empty( $trail_id ) && 'completed' !== $type ) {
			$row = BWFAN_Model_Automation_Contact::get( $ac_id );
		} else {
			$row = BWFAN_Model_Automation_Contact::get_row_by_trail_id( $trail_id );
		}

		if ( ! empty( $row ) ) {
			$resp = $this->delete_automation_contact_data( $row );
			if ( is_array( $resp ) && isset( $resp['status'] ) && 'error' === $resp['status'] ) {
				return $this->error_response( $resp['msg'], null, 400 );
			}
			$this->response_code = 200;

			return $this->success_response( $trail_id, '' );
		}

		/** Search for automation contact complete row */
		if ( empty( $trail_id ) && 'completed' === $type ) {
			$row = BWFAN_Model_Automation_Complete_Contact::get( $ac_id );
		} else {
			$row = BWFAN_Model_Automation_Complete_Contact::get_row_by_trail_id( $trail_id );
		}

		if ( ! empty( $row ) ) {
			$resp = $this->delete_automation_contact_data( $row, 2 );
			if ( is_array( $resp ) && isset( $resp['status'] ) && 'error' === $resp['status'] ) {
				return $this->error_response( $resp['msg'], null, 400 );
			}
		}

		$this->response_code = 200;

		return $this->success_response( $trail_id, __( 'Automation contact deleted', 'wp-marketing-automations' ) );
	}

	/**
	 * Helper for delete_automation_contact.
	 *
	 * @param $row
	 * @param $mode 'default 1 - Automation contact 2 - Automation complete contact'
	 *
	 * @return string[]|void
	 */
	public function delete_automation_contact_data( $row, $mode = 1 ) {
		if ( empty( $row ) ) {
			return;
		}
		$id       = $row['ID'];
		$aid      = $row['aid'];
		$cid      = $row['cid'];
		$trail_id = $row['trail'];

		/** Automation complete contact table */
		$start_date = isset( $row['s_date'] ) ? $row['s_date'] : '';
		$end_date   = isset( $row['c_date'] ) ? $row['c_date'] : '';

		if ( 1 === absint( $mode ) ) {
			/** Automation contact table */
			$start_date = $row['c_date'];
			$end_date   = absint( $row['last_time'] );
			$end_date   = ( $end_date > 0 ) ? $end_date : time();
			$end_date   = gmdate( 'Y-m-d H:i:s', $end_date + 120 );
		}

		try {
			/** Delete db row */
			if ( 1 === absint( $mode ) ) {
				BWFAN_Model_Automation_Contact::delete( $id );
			} else {
				BWFAN_Model_Automation_Complete_Contact::delete( $id );
			}

			BWFAN_Common::maybe_remove_aid_from_contact_fields( $cid, $aid );

			/** Delete automation contact trail */
			BWFAN_Model_Automation_Contact_Trail::delete_row_by_trail_by( $trail_id );
		} catch ( Error $e ) {
			$msg = "Error occurred in deleting the automation contact db row {$e->getMessage()}";
			BWFAN_Common::log_test_data( $msg, 'automation_contact_delete_fail', true );

			return [ 'status' => 'error', 'msg' => $msg ];
		}

		if ( empty( $start_date ) || empty( $end_date ) ) {
			return;
		}

		if ( false === bwfan_is_autonami_pro_active() ) {
			return;
		}

		/** Fetch engagements */
		$engagements = BWFAN_Model_Engagement_Tracking::get_contact_engagements( $aid, $cid, $start_date, $end_date );
		if ( empty( $engagements ) ) {
			return;
		}

		try {
			/** Delete engagement meta */
			BWFAN_Model_Engagement_Trackingmeta::delete_engagements_meta( $engagements );

			/** Delete engagements */
			BWFAN_Model_Engagement_Tracking::delete_contact_engagements( $engagements );

			/** Delete conversions */
			BWFAN_Model_Conversions::delete_conversions_by_track_id( $engagements );
		} catch ( Error $e ) {
		}
	}

	// ─── GET /automation/{id}/contacts/ ─────────────────────────────
	// Originally: BWFAN_API_Get_Automation_Contacts::process_api_call()

	public function get_automation_contacts() {
		$aid    = empty( $this->get_sanitized_arg( 'automation_id' ) ) ? 0 : $this->get_sanitized_arg( 'automation_id' );
		$offset = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? absint( $this->get_sanitized_arg( 'offset', 'absint' ) ) : 0;
		$limit  = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;
		$type   = ! empty( $this->get_sanitized_arg( 'type', 'text_field' ) ) ? $this->get_sanitized_arg( 'type', 'text_field' ) : 'active';
		$search = ! empty( $this->get_sanitized_arg( 'search', 'text_field' ) ) ? $this->get_sanitized_arg( 'search', 'text_field' ) : '';

		if ( empty( $aid ) ) {
			return $this->error_response( __( 'Invalid / Empty Automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $aid );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}
		$this->aid = $aid;

		$contacts = BWFAN_Common::get_automation_contacts( $aid, '', $search, $limit, $offset, $type );
		$message  = __( 'Successfully fetched trails', 'wp-marketing-automations' );
		if ( ! isset( $contacts['contacts'] ) || empty( $contacts['contacts'] ) || ! is_array( $contacts['contacts'] ) ) {
			$message = __( 'No data found', 'wp-marketing-automations' );
		}
		$completed_count = BWFAN_Model_Automation_Complete_Contact::get_complete_count( $aid );
		/** active contacts = active + wait + retry */
		$active_count = BWFAN_Model_Automation_Contact::get_active_count( $aid, 'active' );
		$countdata    = [
			'active'    => $active_count,
			'paused'    => BWFAN_Model_Automation_Contact::get_active_count( $aid, 3 ),
			'failed'    => BWFAN_Model_Automation_Contact::get_active_count( $aid, 2 ),
			'completed' => $completed_count,
			'inactive'  => BWFAN_Model_Automation_Contact::get_active_count( $aid, 'inactive' ),
		];
		$all          = 0;
		foreach ( $countdata as $data ) {
			$all += intval( $data );
		}
		$countdata['all'] = $all;
		$final_contacts   = [];
		if ( 'failed' === $type ) {
			foreach ( $contacts['contacts'] as $contact ) {
				if ( ! empty( $contact['error_msg'] ) && is_array( $contact['error_msg'] ) ) {
					$contact['error_msg'] = $this->get_error_message( $contact['error_msg'] );
				}
				$final_contacts[] = $contact;
			}
		} else {
			$final_contacts = $contacts['contacts'];
		}

		$contacts_data = [
			'total'           => isset( $contacts['total'] ) ? $contacts['total'] : 0,
			'data'            => $final_contacts,
			'automation_data' => $automation_obj->automation_data,
			'count_data'      => $countdata
		];

		return $this->success_response( $contacts_data, $message );
	}

	// ─── GET /automations/contacts/ ─────────────────────────────────
	// Originally: BWFAN_API_Get_All_Automation_Contacts::process_api_call()

	public function get_all_automation_contacts() {
		$offset = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? intval( $this->get_sanitized_arg( 'offset', 'absint' ) ) : 0;
		$limit  = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;
		$type   = ! empty( $this->get_sanitized_arg( 'type', 'text_field' ) ) ? $this->get_sanitized_arg( 'type', 'text_field' ) : 'active';
		$search = ! empty( $this->get_sanitized_arg( 'search', 'text_field' ) ) ? $this->get_sanitized_arg( 'search', 'text_field' ) : '';
		$cid    = ! empty( $this->get_sanitized_arg( 'contact', 'text_field' ) ) ? intval( $this->get_sanitized_arg( 'contact', 'text_field' ) ) : '';
		$aid    = ! empty( $this->get_sanitized_arg( 'automation', 'text_field' ) ) ? intval( $this->get_sanitized_arg( 'automation', 'text_field' ) ) : '';

		$contacts = BWFAN_Common::get_automation_contacts( $aid, $cid, $search, $limit, $offset, $type );
		$message  = __( 'Successfully fetched Automations', 'wp-marketing-automations' );
		if ( ! isset( $contacts['contacts'] ) || empty( $contacts['contacts'] ) || ! is_array( $contacts['contacts'] ) ) {
			$message = __( 'No data found', 'wp-marketing-automations' );
		}

		$completed_count = BWFAN_Model_Automation_Complete_Contact::get_complete_count( $aid, $cid );

		/** active contacts = active + wait + retry */
		$active_count = BWFAN_Model_Automation_Contact::get_active_count( $aid, 'active', '', $cid );
		$countdata    = [
			'active'    => $active_count,
			'paused'    => BWFAN_Model_Automation_Contact::get_active_count( $aid, 3, '', $cid ),
			'failed'    => BWFAN_Model_Automation_Contact::get_active_count( $aid, 2, '', $cid ),
			'completed' => $completed_count,
			'delayed'   => BWFAN_Model_Automation_Contact::get_active_count( $aid, 'delayed', '', $cid, 'AND cc.e_time < ' . current_time( 'timestamp', 1 ) ),
			'inactive'  => BWFAN_Model_Automation_Contact::get_active_count( $aid, 'inactive', '', $cid ),
		];

		$all = 0;
		foreach ( $countdata as $data ) {
			$all += intval( $data );
		}

		// adding automation title and url
		$final_contacts = [];
		$total          = isset( $contacts['total'] ) ? $contacts['total'] : 0;
		foreach ( $contacts['contacts'] as $contact ) {
			$automation_obj = BWFAN_Automation_V2::get_instance( $contact['aid'] );
			/** Check for automation exists */
			if ( ! empty( $automation_obj->error ) ) {
				continue;
			}
			if ( ! empty( $contact['error_msg'] ) && is_array( $contact['error_msg'] ) ) {
				$contact['error_msg'] = $this->get_error_message( $contact['error_msg'] );
			}

			$title            = isset( $automation_obj->automation_data['title'] ) && ! empty( $automation_obj->automation_data['title'] ) ? $automation_obj->automation_data['title'] : '';
			$event_slug       = isset( $automation_obj->automation_data['event'] ) && ! empty( $automation_obj->automation_data['event'] ) ? $automation_obj->automation_data['event'] : '';
			$event_obj        = BWFAN_Core()->sources->get_event( $event_slug );
			$event            = ! empty( $event_obj ) ? $event_obj->get_name() : '';
			$final_contacts[] = array_merge( $contact, array(
				'title' => $title,
				'event' => $event
			) );
		}

		$countdata['all'] = $all;
		$contacts_data    = [
			'total'      => $total,
			'data'       => $final_contacts,
			'count_data' => $countdata
		];

		return $this->success_response( $contacts_data, $message );
	}

	// ─── GET /automation/{id}/contacts/journey ──────────────────────
	// Originally: BWFAN_API_Get_Automation_Contacts_Journey::process_api_call()

	public function get_automation_contacts_journey() {
		$automation_id = $this->get_sanitized_arg( 'automation_id' );
		$search        = isset( $this->args['search'] ) ? $this->args['search'] : '';
		$offset        = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? absint( $this->get_sanitized_arg( 'offset', 'absint' ) ) : 0;
		$limit         = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		/** If step id is 0 , event data to be returned */

		if ( empty( $automation_id ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		$contacts = BWFAN_Common::get_automation_contacts_journey( $automation_id, $search );


		if ( empty( $contacts ) || ! is_array( $contacts ) ) {
			return $this->error_response( [], __( 'No data found', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;

		return $this->success_response( $contacts, __( 'Successfully fetched contacts', 'wp-marketing-automations' ) );
	}

	// ─── GET /automation/{id}/trail/ ────────────────────────────────
	// Originally: BWFAN_API_Get_Automation_Contact_Trail::process_api_call()

	public function get_automation_contact_trail() {
		$tid = $this->get_sanitized_arg( 'tid' );
		$aid = empty( $this->get_sanitized_arg( 'automation_id' ) ) ? 0 : intval( $this->get_sanitized_arg( 'automation_id' ) );

		/** If step id is 0 , event data to be returned */
		if ( empty( $tid ) ) {
			return $this->error_response( __( 'Invalid/ Empty trail ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		if ( empty( $aid ) ) {
			return $this->error_response( __( 'Invalid/ Empty Automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $aid );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		/** Get trail data from DB */
		$this->trail = BWFAN_Model_Automation_Contact_Trail::get_trail( $tid );
		//$this->trail = $this->maybe_alter_trail( $this->trail );

		/** Check for empty data */
		if ( empty( $this->trail ) || ! is_array( $this->trail ) ) {
			return $this->error_response( [], 'No data found' );
		}

		/** Get event slug, name and source*/
		$event_slug  = BWFAN_Model_Automations::get_event_name( $aid );
		$event_obj   = BWFAN_Core()->sources->get_event( $event_slug );
		$event_name  = ! empty( $event_obj ) ? $event_obj->get_name() : $event_slug;
		$source      = ! empty( $event_obj ) ? $event_obj->get_source() : '';
		$integration = ! empty( $source ) ? BWFAN_Core()->sources->get_source( $source ) : '';
		$source_name = ! empty( $integration ) ? $integration->get_name() : '-';
		/** Set start array */
		$trail_data = [
			[
				'id'       => 'start',
				'type'     => 'start',
				'data'     => [
					'event'     => $event_slug,
					'nice_name' => $event_name,
					'source'    => $source_name,
					'date'      => ''
				],
				'hidden'   => false,
			]
		];
		$last_sid   = 'start';
		foreach ( $this->trail as $index => $data ) {
			/** Set time in start node if not set */
			if ( isset( $trail_data[0]['data']['date'] ) && empty( $trail_data[0]['data']['date'] ) ) {
				$trail_data[0]['data']['date'] = gmdate( 'Y-m-d H:i:s', $data['c_time'] );
			}
			/** Get data trail data by step type */
			$tdata = $this->get_trail_data_by_step_type( $data, $event_slug, $index );

			/** Merge with trail main array */
			$trail_data = array_merge( $trail_data, $tdata );

			/** Set new link data */
			$trail_data[] = [
				'id'           => $last_sid . '-' . $data['sid'] . '-' . $data['ID'],
				'source'       => $last_sid,
				'target'       => $data['sid'] . '-' . $data['ID'],
				'sourceHandle' => '',
				'animated'     => false,
			];
			/** Check if conditional or split node and set last step connecting */
			switch ( $data['type'] ) {
				case BWFAN_Automation_Controller::$TYPE_CONDITIONAL:
					$last_sid = 1 === count( $tdata )
						? ( $data['sid'] . '-' . $data['ID'] )
						: ( $data['sid'] . '-condi-' . $data['ID'] );
					break;
				case BWFAN_Automation_Controller::$TYPE_SPLIT:
					$last_sid = 1 === count( $tdata )
						? ( $data['sid'] . '-' . $data['ID'] )
						: ( $data['sid'] . '-split-' . $data['ID'] );
					break;
				default:
					$last_sid = $data['sid'] . '-' . $data['ID'];
					break;
			}
		}

		/** Get automation contact complete data */
		$complete_data = BWFAN_Model_Automation_Complete_Contact::get_data_by_trail( $tid );
		$end_reason    = $complete_data['end_reason'] ?? [];
		$end_reason    = ! empty( $end_reason ) ? BWFAN_Common::get_end_reason_message( $end_reason ) : '';

		/** Set end node */
		$trail_data[] = [
			'id'       => 'end',
			'type'     => 'end',
			'data'     => [
				'reason' => $end_reason,
				'label'  => __( 'End Automation', 'wp-marketing-automations' ),
			],
			'hidden'   => false,
		];
		/** Set last node link with last step id  */
		$trail_data[] = [
			'id'           => $last_sid . '-end',
			'source'       => $last_sid,
			'target'       => 'end',
			'sourceHandle' => '',
			'animated'     => empty( $complete_data ),
		];

		$this->response_code = 200;

		return $this->success_response( $trail_data, __( 'Automation contact data found', 'wp-marketing-automations' ) );
	}

	// ─── GET /automation/{id}/step/{sid}/contacts ───────────────────
	// Originally: BWFAN_API_Get_Automation_Step_Contacts::process_api_call()

	public function get_automation_step_contacts() {
		$automation_id = $this->get_sanitized_arg( 'automation_id' );
		$step_id       = $this->get_sanitized_arg( 'step_id' );
		$type          = $this->get_sanitized_arg( 'type' );
		$path          = $this->get_sanitized_arg( 'path' );
		$offset        = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? absint( $this->get_sanitized_arg( 'offset', 'absint' ) ) : 0;
		$limit         = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;

		/** If step id is 0 , event data to be returned */

		if ( empty( $automation_id ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}
		$step      = BWFAN_Model_Automation_Step::get( $step_id );
		$step_type = isset( $step['type'] ) ? intval( $step['type'] ) : 0;
		/** `path` filters trail data: split paths use the `path` JSON key, branches branches use `branch` */
		$path     = in_array( $step_type, [ BWFAN_Automation_Controller::$TYPE_SPLIT, BWFAN_Automation_Controller::$TYPE_BRANCHES ], true ) ? $path : '';
		$data_key = BWFAN_Automation_Controller::$TYPE_BRANCHES === $step_type ? 'branch' : 'path';

		$data = BWFAN_Common::get_completed_contacts( $automation_id, $step_id, $type, $offset, $limit, $path, $data_key );

		if ( ! isset( $data['contacts'] ) || ! is_array( $data['contacts'] ) ) {
			return $this->error_response( [], __( 'No data found', 'wp-marketing-automations' ) );
		}

		$contacts = array_map( function ( $contact ) {
			$trail = isset( $contact['trail'] ) ? $contact['trail'] : '';

			$time                   = isset( $contact['c_date'] ) ? strtotime( $contact['c_date'] ) : '';
			$prepared_data          = [];
			$prepared_data['id']    = $contact['cid'];
			$prepared_data['name']  = ! empty( $contact['f_name'] ) || ! empty( $contact['l_name'] ) ? $contact['f_name'] . ' ' . $contact['l_name'] : '';
			$prepared_data['phone'] = isset( $contact['contact_no'] ) ? $contact['contact_no'] : '';
			$prepared_data['email'] = isset( $contact['email'] ) ? $contact['email'] : '';
			$prepared_data['trail'] = isset( $contact['tid'] ) ? $contact['tid'] : $trail;
			$prepared_data['time']  = isset( $contact['c_time'] ) ? $contact['c_time'] : $time;
			/** Set path if path is available */
			if ( isset( $contact['data'] ) && ! empty( $contact['data'] ) ) {
				$trail_data = json_decode( $contact['data'], true );
				if ( isset( $trail_data['path'] ) ) {
					$prepared_data['path'] = $trail_data['path'];
				}
			}

			return $prepared_data;
		}, $data['contacts'] );

		$total_contacts = isset( $data['total'] ) ? intval( $data['total'] ) : 0;
		$path_total     = 0;

		$automation = [
			'total'      => $total_contacts,
			'path_total' => $path_total,
			'data'       => $contacts
		];

		$this->response_code = 200;

		return $this->success_response( $automation, __( 'Automation contact found', 'wp-marketing-automations' ) );
	}

	// ─── GET /automation/{id}/analytics/{sid} ───────────────────────
	// Originally: BWFAN_API_Get_Node_Analytics::process_api_call()

	public function get_node_analytics() {
		$automation_id = $this->get_sanitized_arg( 'automation_id' );
		$step_id       = $this->get_sanitized_arg( 'step_id' );
		$mode          = $this->get_sanitized_arg( 'mode' );
		$mode          = ! empty( $mode ) ? $mode : 'email';

		if ( empty( $automation_id ) || empty( $step_id ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}
		$data = [
			'status' => false,
			'data'   => [],
		];
		switch ( $mode ) {
			case 'email':
			case 'sms':
				$data = $this->get_step_mail_sms_analytics( $automation_id, $step_id, $mode );
				break;
			case 'split':
				$data = $this->get_split_step_analytics( $automation_obj, $automation_id, $step_id );
				break;
			default:
				$data = apply_filters( 'bwfan_automation_node_analytics', $data, $automation_id, $step_id, $mode );
		}

		if ( ! $data['status'] ) {
			return $this->error_response( __( 'Automation step analytics not found.', 'wp-marketing-automations' ), null, 400 );
		}

		$this->response_code = 200;

		return $this->success_response( $data['data'], __( 'Step analytics fetched successfully.', 'wp-marketing-automations' ) );
	}

	// ─── POST /automation/{id}/merger-points ────────────────────────
	// Originally: BWFAN_API_Get_Automation_Merger_Points::process_api_call()

	public function get_automation_merger_points() {
		$automation_id = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$arg_data      = $this->args;

		/** Get automation */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		$conflict = $this->revision_conflict( $automation_id, $arg_data );
		if ( null !== $conflict ) {
			return $conflict;
		}

		$steps = $links = [];
		$count = 0;

		/** Step data */
		if ( ! empty( $arg_data['steps'] ) ) {
			$steps = $arg_data['steps'];
		}

		/** Link data */
		if ( ! empty( $arg_data['links'] ) ) {
			$links = $arg_data['links'];
		}

		/** Node count */
		if ( isset( $arg_data['count'] ) && intval( $arg_data['count'] ) > 0 ) {
			$count = intval( $arg_data['count'] );
		}

		/** Update automation data */
		if ( ! empty( $steps ) && ! empty( $links ) ) {
			$automation_obj->update_automation_meta_data( [], $steps, $links, $count );
		}

		$automation_obj->fetch_automation_metadata( false );

		$automation_meta = $automation_obj->get_automation_meta_data();

		$this->response_code = 200;

		return $this->success_response( [
			'merger_points' => ! empty( $automation_meta['merger_points'] ) ? $automation_meta['merger_points'] : [],
			'revision'      => $this->current_revision( $automation_id ),
		], __( 'Merger Points Updated', 'wp-marketing-automations' ) );
	}

	// ─── GET /automation/actions/{action}/suggestions ───────────────
	// Originally: BWFAN_API_Get_Actions_Search_Suggestion::process_api_call()

	public function get_actions_search_suggestions() {
		$action = $this->get_sanitized_arg( 'action' );
		if ( empty( $action ) ) {
			return $this->error_response_200( __( 'Invalid or empty action', 'wp-marketing-automations' ), null, 400 );
		}
		$search     = $this->get_sanitized_arg( 'search' );
		$search     = ! empty( $search ) ? $search : '';
		$identifier = $this->get_sanitized_arg( 'identifier' );
		$identifier = ! empty( $identifier ) ? $identifier : '';

		$action = BWFAN_Core()->integration->get_action( $action );
		$result = [];
		if ( ! empty( $action ) ) {
			$result = $action->get_options( $search, $identifier );
		}

		return $this->success_response( $result );
	}

	// ─── GET /automation/rules/{rule}/suggestions ───────────────────
	// Originally: BWFAN_API_Get_Rule_Search_Suggestion::process_api_call()

	public function get_rules_suggestions() {
		$rule = $this->get_sanitized_arg( 'rule' );
		if ( empty( $rule ) ) {
			return $this->error_response_200( __( 'Invalid or empty rule', 'wp-marketing-automations' ), null, 400 );
		}

		// removed get_sanitized_arg function because that was merging two or more substrings and making one
		// for example : cart abandonment. with get_sanitized_arg, it is becoming cartabandonment, which making issue in search
		$search = $this->args['search'] ? $this->args['search'] : '';
		$extra  = isset( $this->args['extra'] ) ? json_decode( $this->args['extra'], true ) : array();
		if ( ! is_array( $extra ) ) {
			$extra = array();
		}
		if ( isset( $extra['type'] ) ) {
			$extra['type'] = sanitize_key( $extra['type'] );
		}

		$result = BWFAN_Core()->rules->get_rule_search_suggestions( $search, $rule, $extra );

		return $this->success_response( $result );
	}

	// ─── GET /automation/event-rules/{event} ────────────────────────
	// Originally: BWFAN_API_Get_Rules::process_api_call()

	public function get_rules() {
		$event = $this->get_sanitized_arg( 'event', 'text_field' );
		if ( empty( $event ) ) {
			return $this->error_response_200( __( 'Invalid or empty event', 'wp-marketing-automations' ), null, 400 );
		}
		/** Support comma-separated events */
		if ( strpos( $event, ',' ) !== false ) {
			$event = array_map( 'sanitize_text_field', explode( ',', $event ) );
		}

		$aid = $this->get_sanitized_arg( 'automation_id' );
		BWFAN_Core()->rules->load_rules_classes();
		$rules = BWFAN_Core()->rules->get_rules( $event, absint( $aid ) );

		return $this->success_response( $rules );
	}

	// ─── GET /custom-search/{type} ──────────────────────────────────
	// Originally: BWFAN_API_Get_Custom_Search::process_api_call()

	public function custom_search() {
		do_action( 'bwfan_load_custom_search_classes' );
		$type = $this->get_sanitized_arg( 'type' );
		// removed get_sanitized_arg function because that was merging two or more substrings and making one
		// for example : xl autonami. with get_sanitized_arg, it is becoming xlautonami, which making issue in search
		$search     = isset( $this->args['search'] ) && ! empty( $this->args['search'] ) ? $this->args['search'] : '';
		$extra_data = $this->args;
		if ( empty( $type ) ) {
			return $this->error_response( __( 'Invalid or empty type', 'wp-marketing-automations' ), null, 400 );
		}

		if ( ! class_exists( 'BWFAN_' . $type ) ) {
			return $this->error_response( __( 'Invalid or empty type', 'wp-marketing-automations' ), null, 400 );
		}
		$type    = BWFAN_Core()->custom_search->get_custom_search( $type );
		$options = ! empty( $type ) ? $type->get_options( $search, $extra_data ) : [];

		return $this->success_response( $options );
	}

	// ─── DELETE /bulk-action/{type} ─────────────────────────────────
	// Originally: BWFAN_API_Bulk_Action::process_api_call()

	public function bulk_action() {
		$type      = $this->get_sanitized_arg( 'type' );
		$ids       = ! empty( $this->args['ids'] ) ? array_map( 'absint', $this->args['ids'] ) : [];
		$cart_type = isset( $this->args['cart_type'] ) ? $this->args['cart_type'] : '';
		if ( empty( $ids ) ) {
			return $this->error_response( __( 'Invalid or empty ids', 'wp-marketing-automations' ), null, 400 );
		}

		$dynamic_string = BWFAN_Common::get_dynamic_string();
		$args           = [ 'key' => $dynamic_string, 'type' => $type, 'cart_type' => $cart_type ];
		sort( $ids );
		update_option( "bwfan_bulk_action_{$dynamic_string}", $ids );
		bwf_schedule_recurring_action( time(), 60, "bwfan_bulk_action", $args );

		$response = BWFAN_Common::bwfan_bulk_action( $dynamic_string, $type, $cart_type );

		if ( empty( $response ) ) {
			delete_option( "bwfan_bulk_action_{$dynamic_string}" );
			if ( bwf_has_action_scheduled( 'bwfan_bulk_action', $args ) ) {
				bwf_unschedule_actions( "bwfan_bulk_action", $args );
			}

			return $this->success_response( [], __( 'Bulk action run successfully', 'wp-marketing-automations' ) );
		}

		return $this->success_response( [], __( 'Bulk action has been scheduled', 'wp-marketing-automations' ) );
	}

	// ─── DELETE /automation/{id}/delete_meta ─────────────────────────
	// Originally: BWFAN_API_Remove_Automation_Automation_Meta::process_api_call()

	public function remove_automation_meta() {
		$automation_id = $this->get_sanitized_arg( 'automation_id' );
		$meta_key      = $this->get_sanitized_arg( 'meta_key' );
		/** Initiate automation object */
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}

		BWFAN_Model_Automationmeta::delete_automation_meta( $automation_id, $meta_key );

		return $this->success_response( [], __( 'Automation meta deleted', 'wp-marketing-automations' ) );
	}

	// ─── GET /automation/allowed-automations ────────────────────────
	// Originally: BWFAN_API_Get_Automation_To_Add_Contact_Manually::process_api_call()

	public function get_automation_to_add_contact_manually() {
		$limit   = ! empty( $this->get_sanitized_arg( 'limit', 'absint' ) ) ? $this->get_sanitized_arg( 'limit', 'absint' ) : 25;
		$offset  = ! empty( $this->get_sanitized_arg( 'offset', 'absint' ) ) ? $this->get_sanitized_arg( 'offset', 'absint' ) : 0;
		$search  = ! empty( $this->get_sanitized_arg( 'search', 'text_field' ) ) ? $this->get_sanitized_arg( 'search', 'text_field' ) : '';
		$status  = ! empty( $this->get_sanitized_arg( 'status', 'text_field' ) ) ? $this->get_sanitized_arg( 'status', 'text_field' ) : 1;
		$version = ! empty( $this->get_sanitized_arg( 'version', 'text_field' ) ) ? $this->get_sanitized_arg( 'version', 'text_field' ) : 2;

		$events = BWFAN_Core()->sources->get_events_to_add_contact_manually();

		if ( empty( $events ) ) {
			return $this->success_response( [], __( 'No allowed events found', 'wp-marketing-automations' ) );
		}

		$placeholder = array_fill( 0, count( $events ), '%s' );
		$placeholder = implode( ", ", $placeholder );

		$args = $events;
		$args = array_merge( $args, [ $status, $version, '%' . $search . '%', $offset, $limit ] );

		global $wpdb;
		$automations = $wpdb->get_results( $wpdb->prepare( "SELECT `ID` as `key`,`title` as `value` FROM `{$wpdb->prefix}bwfan_automations` WHERE `event` IN($placeholder) AND `status`=%d AND `v`=%d AND `title` LIKE %s LIMIT %d, %d", $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholder is built at runtime so the sniff undercounts; wpdb unwraps the $args array itself.; placeholders generated via array_fill; all values bound in prepare()

		if ( empty( $automations ) ) {
			return $this->success_response( [], __( 'No automations found in which contact can be added manually', 'wp-marketing-automations' ) );
		}

		$this->response_code = 200;

		return $this->success_response( $automations, __( 'Automations found', 'wp-marketing-automations' ) );
	}

	// ─── GET /automations-stats/{id}/ ───────────────────────────────
	// Originally: BWFAN_API_Get_Single_Automation_Stats::process_api_call()

	public function get_single_automation_stats() {
		$aid = empty( $this->get_sanitized_arg( 'automation_id' ) ) ? 0 : $this->get_sanitized_arg( 'automation_id' );

		if ( empty( $aid ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Initiate automation object */
		$this->automation_obj = BWFAN_Automation_V2::get_instance( $aid );

		/** Check for automation exists */
		if ( ! empty( $this->automation_obj->error ) ) {
			return $this->error_response( [], $this->automation_obj->error );
		}
		$data = [
			'start' => [
				'queued'    => 0,
				'active'    => $this->automation_obj->get_active_count(),
				'completed' => $this->automation_obj->get_complete_count(),
			]
		];

		$step_ids = BWFAN_Model_Automation_Step::get_automation_step_ids( $aid );
		if ( empty( $step_ids ) ) {
			return $this->success_response( $data, __( 'Automation stats found', 'wp-marketing-automations' ) );
		}
		$step_ids = array_column( $step_ids, 'ID' );

		/** Single query for all step status counts (completed, queued, failed, skipped) */
		$step_counts = BWFAN_Model_Automation_Contact_Trail::get_all_step_status_counts( $step_ids );

		foreach ( $step_ids as $sid ) {
			$counts       = isset( $step_counts[ $sid ] ) ? $step_counts[ $sid ] : array( 'completed' => 0, 'queued' => 0, 'failed' => 0, 'skipped' => 0 );
			$data[ $sid ] = [
				'queued'    => $counts['queued'],
				'active'    => 0,
				'completed' => $counts['completed'],
				'skipped'   => $counts['skipped'],
				'failed'    => $counts['failed'],
			];
		}
		$meta        = $this->automation_obj->get_automation_meta_data();
		$split_steps = isset( $meta['split_steps'] ) ? $meta['split_steps'] : [];

		$data = $this->get_split_steps_stats( $split_steps, $data );

		/** Per-branch contact counts for branches steps, keyed by chip node id */
		$data = $this->get_branch_steps_stats( isset( $meta['steps'] ) ? $meta['steps'] : [], $data );

		return $this->success_response( $data, __( 'Automation stats found', 'wp-marketing-automations' ) );
	}

	// ─── GET v3/automation/{id}/conversions/ ────────────────────────
	// Originally: BWFAN_API_Get_Automation_Conversions::process_api_call()

	public function get_automation_conversions() {
		$automation_id  = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}
		$this->aid = $automation_id;

		$conversions                    = $this->get_conversions( $automation_id, $this->pagination->offset, $this->pagination->limit );
		$conversions['automation_data'] = $automation_obj->automation_data;

		if ( isset( $conversions['automation_data']['v'] ) && 1 === absint( $conversions['automation_data']['v'] ) ) {
			$meta                                    = BWFAN_Model_Automationmeta::get_automation_meta( $automation_id );
			$conversions['automation_data']['title'] = isset( $meta['title'] ) ? $meta['title'] : '';
		}

		if ( empty( $conversions['total'] ) ) {
			$this->response_code = 404;
			$response            = __( "No orders found", "wp-marketing-automations" );

			return $this->success_response( $conversions, $response );
		}
		$this->total_count = $conversions['total'];

		return $this->success_response( $conversions, __( 'Got All Orders', 'wp-marketing-automations' ) );
	}

	// ─── GET v3/automation/{id}/recipients/ ─────────────────────────
	// Originally: BWFAN_API_Get_Automation_Recipients::process_api_call()

	public function get_automation_recipients() {
		$automation_id  = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$automation_obj = BWFAN_Automation_V2::get_instance( $automation_id );

		/** Check for automation exists */
		if ( ! empty( $automation_obj->error ) ) {
			return $this->error_response( [], $automation_obj->error );
		}
		$this->aid = $automation_id;

		$recipients               = BWFAN_Model_Engagement_Tracking::get_automation_recipents( $automation_id, $this->pagination->offset, $this->pagination->limit );
		$recipients['recipients'] = $recipients['conversations'];
		unset( $recipients['conversations'] );
		$recipients['automation_data'] = $automation_obj->automation_data;

		if ( isset( $recipients['automation_data']['v'] ) && 1 === absint( $recipients['automation_data']['v'] ) ) {
			$meta                                   = BWFAN_Model_Automationmeta::get_automation_meta( $automation_id );
			$recipients['automation_data']['title'] = isset( $meta['title'] ) ? $meta['title'] : '';
		}

		if ( empty( $recipients['total'] ) ) {
			$this->response_code = 404;
			$response            = __( "No recipients found", "wp-marketing-automations" );

			return $this->success_response( $recipients, $response );
		}

		$this->total_count = $recipients['total'];

		return $this->success_response( $recipients, __( 'Got All Recipients', 'wp-marketing-automations' ) );
	}

	// ─── GET v3/automation/{id}/recipients/{cid}/timeline ───────────
	// Originally: BWFAN_API_Get_Automation_Recipient_Timeline::process_api_call()

	public function get_automation_recipient_timeline() {
		$automation_id       = $this->get_sanitized_arg( 'automation_id', 'text_field' );
		$contact_id          = $this->get_sanitized_arg( 'contact_id', 'text_field' );
		$mode                = ! empty( absint( $this->get_sanitized_arg( 'mode', 'text_field' ) ) ) ? $this->get_sanitized_arg( 'mode', 'text_field' ) : 1;
		$recipients_timeline = BWFAN_Model_Engagement_Tracking::get_automation_recipient_timeline( $automation_id, $contact_id, $mode );
		if ( empty( $recipients_timeline ) ) {
			return $this->success_response( [], __( 'No engagement found', 'wp-marketing-automations' ) );
		}
		$this->recipients_timeline = $recipients_timeline;

		return $this->success_response( $recipients_timeline, __( 'Got All timeline', 'wp-marketing-automations' ) );
	}

	// ═══════════════════════════════════════════════════════════════════
	// Helper methods
	// ═══════════════════════════════════════════════════════════════════

	/**
	 * Get error message — recursively unwraps arrays.
	 * Used by get_automation_contacts and get_all_automation_contacts.
	 *
	 * @param string|array $res
	 *
	 * @return mixed|string
	 */
	public function get_error_message( $res = '' ) {
		if ( is_array( $res ) ) {
			$res = $this->get_error_message( array_values( $res )[0] );
		}

		return $res;
	}

	/**
	 * @return array
	 */
	public function get_result_count_data() {
		return [
			'failed' => BWFAN_Model_Automation_Contact::get_active_count( $this->aid, 2 )
		];
	}

	public function get_result_total_count() {
		return $this->total_count;
	}

	// ─── Trail helper methods (from BWFAN_API_Get_Automation_Contact_Trail) ──

	/**
	 * Get trail message if multilevel array
	 *
	 * @param $res
	 *
	 * @return mixed|string
	 */
	public function get_trail_message( $res = '' ) {
		if ( is_array( $res ) ) {
			$res = $this->get_trail_message( array_values( $res )[0] );
		}

		return $res;
	}

	/**
	 * Maybe alter trail if same steps found multiple times
	 *
	 * @param $trail
	 *
	 * @return array
	 */
	public function maybe_alter_trail( $trail ) {
		if ( empty( $trail ) ) {
			return [];
		}
		$new_trail = [];
		$step_key  = [];
		foreach ( $trail as $key => $single ) {
			if ( isset( $step_key[ $single['sid'] ] ) ) {
				unset( $new_trail[ $step_key[ $single['sid'] ] ] );
			}
			$step_key[ $single['sid'] ] = $key;
			$new_trail[ $key ]          = $single;
		}
		sort( $new_trail );

		return $new_trail;
	}

	/**
	 * Format step
	 *
	 * @param $data
	 * @param $event
	 *
	 * @return array|array[]
	 */
	public function get_trail_data_by_step_type( $data, $event, $current_step_index ) {
		$status   = intval( $data['status'] );
		$response = isset( $data['data'] ) ? json_decode( $data['data'], true ) : '';
		$res_msg  = isset( $response['msg'] ) ? $this->get_trail_message( $response['msg'] ) : '';
		$time     = $this->get_step_execution_time( $data, $current_step_index );
		$aid = empty( $this->get_sanitized_arg( 'automation_id' ) ) ? 0 : intval( $this->get_sanitized_arg( 'automation_id' ) );
		$trail_data = [];
		/** Set step node and data by type */
		switch ( absint( $data['type'] ) ) {
			case BWFAN_Automation_Controller::$TYPE_WAIT :
				$type_data  = isset( $data['step_data'] ) ? json_decode( $data['step_data'], true ) : [];
				$delay_data = isset( $type_data['sidebarData']['data'] ) ? $type_data['sidebarData']['data'] : [];
				$res_msg    = ( empty( $res_msg ) && isset( $response['error_msg'] ) ) ? $this->get_trail_message( $response['error_msg'] ) : $res_msg;
				$trail_data = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'wait',
						'data' => [
							'value'       => [
								'type'      => 1,
								'delayData' => $delay_data,
							],
							'date'        => date( 'Y-m-d H:i:s', $time ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Mixed origin: gmdate OK only if line 2269 strtotime(c_date) is pinned to UTC
							'status'      => $status,
							'msg'         => $res_msg,
							'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
				break;
			case BWFAN_Automation_Controller::$TYPE_ACTION :
				$action      = isset( $data['action'] ) ? json_decode( $data['action'], true ) : [];
				$action_slug = isset( $action['action'] ) ? $action['action'] : '';
				$res_msg     = ( empty( $res_msg ) && isset( $response['error_msg'] ) ) ? $this->get_trail_message( $response['error_msg'] ) : $res_msg;

				/** check for action slug */
				if ( empty( $action_slug ) ) {
					$trail_data = [
						[
							'id'   => $data['sid'] . '-' . $data['ID'],
							'type' => 'action',
							'data' => [
								'selected'    => '',
								'nice_name'   => __( 'Not Configured', 'wp-marketing-automations' ),
								'integration' => '',
								'date'        => gmdate( 'Y-m-d H:i:s', $time ),
								'status'      => $status,
								'msg'         => $res_msg,
								'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
							],
						]
					];
					break;
				}
				/** Action object */
				$action_obj = ! empty( $action_slug ) ? BWFAN_Core()->integration->get_action( $action_slug ) : '';
				/** check for action object */
				if ( ! $action_obj instanceof BWFAN_Action ) {
					$trail_data = [
						[
							'id'   => $data['sid'] . '-' . $data['ID'],
							'type' => 'action',
							'data' => [
								'selected'    => '',
								'nice_name'   => __( 'Action Not Found', 'wp-marketing-automations' ),
								'integration' => '',
								'date'        => gmdate( 'Y-m-d H:i:s', $time ),
								'status'      => $status,
								'msg'         => $res_msg,
								'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
							],
						]
					];
					break;
				}
				$integration_slug = $action_obj->get_integration_type();
				$integration      = BWFAN_Core()->integration->get_integration( $integration_slug );
				/** Get integration name */
				$integration_name = ! empty( $integration ) ? $integration->get_name() : '-';
				$action_name      = ! empty( $action_obj ) ? $action_obj->get_name() : '-';
				$trail_data       = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'action',
						'data' => [
							'selected'    => $action_slug,
							'nice_name'   => $action_name,
							'integration' => $integration_name,
							'date'        => gmdate( 'Y-m-d H:i:s', $time ),
							'status'      => $status,
							'msg'         => $res_msg,
							'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
				break;
			case BWFAN_Automation_Controller::$TYPE_GOAL :
				$action    = isset( $data['action'] ) ? json_decode( $data['action'], true ) : [];
				$benchmark = isset( $action['benchmark'] ) ? $action['benchmark'] : '';
				/** Get event object */
				$event_obj   = BWFAN_Core()->sources->get_event( $benchmark );
				$event_name  = ! empty( $event_obj ) ? $event_obj->get_name() : '';
				$source_slug = ! empty( $event_obj ) ? $event_obj->get_source() : '';
				$res_msg     = ( empty( $res_msg ) && isset( $response['error_msg'] ) ) ? $this->get_trail_message( $response['error_msg'] ) : $res_msg;
				$source      = ! empty( $source_slug ) ? BWFAN_Core()->sources->get_source( $source_slug ) : '';
				/** Get source name */
				$source_name = ! empty( $source_slug ) ? $source->get_name() : '-';
				$trail_data  = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'benchmark',
						'data' => [
							'benchmark'   => $benchmark,
							'nice_name'   => $event_name,
							'source'      => $source_name,
							'date'        => date( 'Y-m-d H:i:s', $time ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Mixed origin: gmdate OK only if line 2269 strtotime(c_date) is pinned to UTC
							'status'      => $status,
							'msg'         => $res_msg,
							'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
				break;
			case BWFAN_Automation_Controller::$TYPE_CONDITIONAL :
				$type_data          = isset( $data['step_data'] ) ? json_decode( $data['step_data'], true ) : [];
				$sidebar_data       = isset( $type_data['sidebarData'] ) ? $type_data['sidebarData'] : [];
				$conditional_result = isset( $data['data'] ) ? json_decode( $data['data'], true ) : [];
				$direction          = isset( $conditional_result['msg'] ) && 1 === absint( $conditional_result['msg'] ) ? 'yes' : 'no';
				$trail_data         = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'conditional',
						'data' => [
							'sidebarValues' => $sidebar_data,
							'event'         => $event,
							'aid'           => $aid,
							'date'          => gmdate( 'Y-m-d H:i:s', $time ),
							'status'        => $status,
							'msg'           => $res_msg,
							'step_status'   => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
				/** Skip the yes/no result child when this run chose no branch — step skipped (disabled or Pro-locked on Lite), trail status 4. */
				$is_skipped_step = 4 === $status;
				if ( 3 !== $status && ! $is_skipped_step ) {
					$trail_data[] = [
						'id'   => $data['sid'] . '-condi-' . $data['ID'],
						'type' => 'yesNoNode',
						'data' => [
							'direction' => $direction,
							'parent'    => $data['sid'],
							'date'      => gmdate( 'Y-m-d H:i:s', $time ),
						]
					];
					$trail_data[] = [
						'id'           => $data['sid'] . '-condi-edge-' . $data['ID'],
						'source'       => $data['sid'] . '-' . $data['ID'],
						'target'       => $data['sid'] . '-condi-' . $data['ID'],
						'sourceHandle' => $direction,
						'animated'     => false,
					];
				}

				break;
			case BWFAN_Automation_Controller::$TYPE_BRANCHES :
				$type_data    = isset( $data['step_data'] ) ? json_decode( $data['step_data'], true ) : [];
				$sidebar_data = isset( $type_data['sidebarData'] ) ? $type_data['sidebarData'] : [];
				$branch_id    = isset( $response['branch'] ) ? $response['branch'] : '';
				$eval_errors  = isset( $response['errors'] ) ? $response['errors'] : [];

				/** Resolve branch id to name from the step's current sidebarData (`step_data` is joined live config, not a snapshot) */
				$branch_name    = __( 'Everyone Else', 'wp-marketing-automations' );
				$branch_deleted = false;
				$branch_rules   = [];
				if ( 'else' !== $branch_id ) {
					$branch_deleted = true;
					$branch_name    = __( 'Deleted branch', 'wp-marketing-automations' );
					$snap_branches  = isset( $sidebar_data['branches'] ) && is_array( $sidebar_data['branches'] ) ? $sidebar_data['branches'] : [];
					foreach ( $snap_branches as $snap_branch ) {
						if ( isset( $snap_branch['id'] ) && $snap_branch['id'] === $branch_id ) {
							$branch_name    = isset( $snap_branch['name'] ) ? $snap_branch['name'] : $branch_name;
							$branch_rules   = isset( $snap_branch['rules'] ) ? $snap_branch['rules'] : [];
							$branch_deleted = false;
							break;
						}
					}
				}

				$node_data = [
					'sidebarValues' => $sidebar_data,
					'event'         => $event,
					'aid'           => $aid,
					'date'          => gmdate( 'Y-m-d H:i:s', $time ),
					'status'        => $status,
					'msg'           => $res_msg,
					'errors'        => $eval_errors,
					'step_status'   => isset( $data['step_status'] ) ? $data['step_status'] : 0,
				];
				/** Merge the matched branch into the single journey card
				 * (name + the rules it evaluated) instead of a separate
				 * branch node. Skipped when the step didn't run — failed
				 * (status 3) or skipped/disabled (status 4), matching the
				 * conditional and split cases; a skipped run has no branch
				 * in its response and would wrongly render "Deleted branch". */
				if ( 3 !== $status && 4 !== $status ) {
					$node_data['branch'] = [
						'name'          => $branch_name,
						'isElse'        => ( 'else' === $branch_id ),
						'deleted'       => $branch_deleted,
						'sidebarValues' => $branch_rules,
					];
				}
				$trail_data = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'branches',
						'data' => $node_data,
					],
				];

				break;
			case BWFAN_Automation_Controller::$TYPE_EXIT :
				$trail_data = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'exit',
						'data' => [
							'date'        => gmdate( 'Y-m-d H:i:s', $time ),
							'status'      => $status,
							'msg'         => $res_msg,
							'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
				break;
			case BWFAN_Automation_Controller::$TYPE_JUMP :
				$trail_data = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'jump',
						'data' => [
							'date'        => gmdate( 'Y-m-d H:i:s', $time ),
							'status'      => $status,
							'msg'         => $res_msg,
							'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
				break;
			case BWFAN_Automation_Controller::$TYPE_SPLIT :
				$type_data          = isset( $data['step_data'] ) ? json_decode( $data['step_data'], true ) : [];
				$sidebar_data       = isset( $type_data['sidebarData'] ) ? $type_data['sidebarData'] : [];
				$conditional_result = isset( $data['data'] ) ? json_decode( $data['data'], true ) : [];
				$msg                = isset( $conditional_result['msg'] ) ? $conditional_result['msg'] : '';
				$path               = isset( $conditional_result['path'] ) ? $conditional_result['path'] : 0;
				/** Skip the splitpath child when this run chose no path — step skipped (disabled or Pro-locked on Lite), trail status 4. */
				$is_skipped_step    = 4 === $status;
				$trail_data         = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'split',
						'data' => [
							'sidebarValues' => $sidebar_data,
							'event'         => $event,
							'date'          => gmdate( 'Y-m-d H:i:s', $data['c_time'] ),
							'status'        => $status,
							'msg'           => $msg,
							'step_status'   => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					],
				];
				/** Skip the splitpath child + edge when no path was chosen (step skipped). */
				if ( ! $is_skipped_step ) {
					$trail_data[] = [
						'id'   => $data['sid'] . '-split-' . $data['ID'],
						'type' => 'splitpath',
						'data' => [
							'path'   => $path,
							'parent' => $data['sid'],
							'date'   => gmdate( 'Y-m-d H:i:s', $data['c_time'] ),
						]
					];
					$trail_data[] = [
						'id'           => $data['sid'] . '-split-edge-' . $data['ID'],
						'source'       => $data['sid'] . '-' . $data['ID'],
						'target'       => $data['sid'] . '-split-' . $data['ID'],
						'sourceHandle' => '',
						'animated'     => false,
					];
				}
				break;
			default:
				$trail_data = [
					[
						'id'   => $data['sid'] . '-' . $data['ID'],
						'type' => 'unknown',
						'data' => [
							'date'        => gmdate( 'Y-m-d H:i:s', $time ),
							'status'      => $status,
							'msg'         => $res_msg,
							'step_status' => isset( $data['step_status'] ) ? $data['step_status'] : 0
						],
					]
				];
		}

		return $trail_data;
	}

	/**
	 * Get step execution or next run time
	 *
	 * @param $data
	 * @param $current_step_index
	 *
	 * @return mixed|string
	 */
	private function get_step_execution_time( $data, $current_step_index ) {
		$time = $data['c_time'];

		/** If not goal or delay step */
		if ( ! in_array( intval( $data['type'] ), [ BWFAN_Automation_Controller::$TYPE_WAIT, BWFAN_Automation_Controller::$TYPE_GOAL ], true ) ) {
			return $time;
		}

		if ( BWFAN_Automation_Controller::$TYPE_WAIT === intval( $data['type'] ) && 2 === intval( $data['status'] ) ) {
			return BWFAN_Model_Automation_Contact::get_next_run_time_by_trail( $data['tid'] );
		}

		if ( 1 === intval( $data['status'] ) ) {
			/** If current step has run then get c_time of next step */
			$next_step_index = $current_step_index + 1;

			/** There is next node in trail, pick their time */
			if ( isset( $this->trail[ $next_step_index ]['c_time'] ) ) {
				return $this->trail[ $next_step_index ]['c_time'];
			}

			/** There is no next node and automation would have been completed. Checking completion time from automation contact table */
			$complete_data = BWFAN_Model_Automation_Complete_Contact::get_row_by_trail_id( $data['tid'] );

			return isset( $complete_data['c_date'] ) ? strtotime( $complete_data['c_date'] ) : $time;
		}

		return $time;
	}

	// ─── Mail step helpers (from BWFAN_API_Automation_Step) ─────────

	public function save_mail_steps( $split_steps ) {

		foreach ( $split_steps as $step_id => $paths ) {
			/** Get email and sms steps of all paths */
			$mail_steps = [];
			foreach ( $paths as $path_name => $step_ids ) {

				if ( empty( $step_ids ) ) {
					$mail_steps[ $path_name ] = [];
					continue;
				}

				$email_steps              = BWFAN_Model_Automation_Step::get_messaging_steps( $step_ids );
				$sms_steps                = BWFAN_Model_Automation_Step::get_messaging_steps( $step_ids, '_sms' );
				$mail_steps[ $path_name ] = array_merge( $email_steps, $sms_steps );
			}

			/** Save mail steps in step */
			$this->save_mail_step( $step_id, $mail_steps );
		}
	}

	/**
	 * Save mail and sms step
	 *
	 * @param $step_id
	 * @param $all_steps
	 * @param $automation_obj
	 *
	 * @return bool|void
	 */
	public function save_mail_step( $step_id, $mail_steps ) {
		$data        = BWFAN_Model_Automation_Step::get_step_data( $step_id );
		$data        = isset( $data['data'] ) ? $data['data'] : [];
		$sidebarData = isset( $data['sidebarData'] ) ? $data['sidebarData'] : [];

		$sidebarData['mail_steps'] = $mail_steps;
		$updated_data              = [
			'sidebarData' => $sidebarData
		];

		BWFAN_Model_Automation_Step::update( array(
			'data' => wp_json_encode( $updated_data )
		), array(
			'ID' => $step_id,
		) );
	}

	// ─── Node analytics helpers (from BWFAN_API_Get_Node_Analytics) ──

	/**
	 * Returns email/sms analytics
	 *
	 * @param $automation_id
	 * @param $step_id
	 * @param $mode
	 *
	 * @return array
	 */
	public function get_step_mail_sms_analytics( $automation_id, $step_id, $mode ) {
		if ( class_exists( 'BWFAN_Model_Engagement_Tracking' ) || method_exists( 'BWFAN_Model_Engagement_Tracking', 'get_automation_step_analytics' ) ) {
			$data = BWFAN_Model_Engagement_Tracking::get_automation_step_analytics( $automation_id, $step_id );
		}

		$open_rate        = isset( $data['open_rate'] ) ? number_format( $data['open_rate'], 1 ) : 0;
		$click_rate       = isset( $data['click_rate'] ) ? number_format( $data['click_rate'], 1 ) : 0;
		$revenue          = isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : 0;
		$unsubscribes     = isset( $data['unsubscribers'] ) ? absint( $data['unsubscribers'] ) : 0;
		$conversions      = isset( $data['conversions'] ) ? absint( $data['conversions'] ) : 0;
		$sent             = isset( $data['sent'] ) ? absint( $data['sent'] ) : 0;
		$open_count       = isset( $data['open_count'] ) ? absint( $data['open_count'] ) : 0;
		$click_count      = isset( $data['click_count'] ) ? absint( $data['click_count'] ) : 0;
		$contacts_count   = isset( $data['contacts_count'] ) ? absint( $data['contacts_count'] ) : 1;
		$rev_per_person   = empty( $contacts_count ) || empty( $revenue ) ? 0 : number_format( $revenue / $contacts_count, 1 );
		$unsubscribe_rate = empty( $contacts_count ) || empty( $unsubscribes ) ? 0 : ( $unsubscribes / $contacts_count ) * 100;

		/** Tile for sms */
		if ( 'sms' === $mode ) {
			$tiles = [
				[
					'label' => __( 'Sent', 'wp-marketing-automations' ),
					'value' => $sent,
				],
				[
					'label' => __( 'Click Rate', 'wp-marketing-automations' ),
					'value' => $click_rate . '% (' . $click_count . ')',
				]
			];
		} else {
			/** Get click rate from total opens */
			$click_to_open_rate = ( empty( $click_count ) || empty( $open_count ) ) ? 0 : number_format( ( $click_count / $open_count ) * 100, 1 );

			$tiles = [
				[
					'label' => __( 'Sent', 'wp-marketing-automations' ),
					'value' => $sent,
				],
				[
					'label' => __( 'Open Rate', 'wp-marketing-automations' ),
					'value' => $open_rate . '% (' . $open_count . ')',
				],
				[
					'label' => __( 'Click Rate', 'wp-marketing-automations' ),
					'value' => $click_rate . '% (' . $click_count . ')',
				],
				[
					'label' => __( 'Click to Open Rate', 'wp-marketing-automations' ),
					'value' => $click_to_open_rate . '%',
				]
			];
		}

		if ( bwfan_is_woocommerce_active() ) {

			$currency_symbol = get_woocommerce_currency_symbol();
			$revenue         = html_entity_decode( $currency_symbol . $revenue, ENT_QUOTES | ENT_HTML401 );
			$rev_per_person  = html_entity_decode( $currency_symbol . $rev_per_person, ENT_QUOTES | ENT_HTML401 );

			$revenue_tiles = [
				[
					'label' => __( 'Revenue', 'wp-marketing-automations' ),
					'value' => $revenue . ' (' . $conversions . ')',
				],
				[
					'label' => __( 'Revenue/Contact', 'wp-marketing-automations' ),
					'value' => $rev_per_person,
				]
			];

			$tiles = array_merge( $tiles, $revenue_tiles );
		}

		$tiles[] = [
			'label' => __( 'Unsubscribe Rate', 'wp-marketing-automations' ),
			'value' => number_format( $unsubscribe_rate, 2 ) . '% (' . $unsubscribes . ')',
		];


		return [
			'status' => true,
			'data'   => [
				'analytics' => [],
				'tile'      => $tiles,
			]
		];
	}

	public function get_split_step_analytics( $automation_obj, $automation_id, $step_id ) {
		$automation_meta = $automation_obj->get_automation_meta_data();
		$split_steps     = isset( $automation_meta['split_steps'] ) ? $automation_meta['split_steps'] : [];
		$data            = BWFAN_Model_Automation_Step::get_step_data( $step_id );
		$started_at      = $data['created_at'];
		$paths           = $split_steps[ $step_id ];
		$path_stats      = [ 'started_at' => $started_at ];
		foreach ( $paths as $path => $step_ids ) {
			$path_num      = str_replace( 'p-', '', $path );
			$stats         = $this->get_node_analytics_path_stats( $automation_id, $step_ids );
			$contact_count = BWFAN_Model_Automation_Contact_Trail::get_path_contact_count( $step_id, $path_num );
			$stats[0]      = [
				'l' => 'Contacts',
				'v' => ! empty( $contact_count ) ? intval( $contact_count ) : '-',
			];

			$path_stats['paths'][] = [
				'path'  => $path_num,
				'stats' => $stats
			];

		}

		return [
			'status' => true,
			'data'   => [
				'data' => $path_stats
			]
		];
	}

	/**
	 * @param $split_steps
	 * @param $current_step
	 *
	 * @return mixed|string
	 */
	private function get_split_step_creation_time( $split_steps, $current_step ) {
		if ( empty( $split_steps ) ) {
			return '';
		}
		$split_step_id = 0;
		foreach ( $split_steps as $split_id => $paths ) {
			foreach ( $paths as $step_ids ) {
				if ( ! in_array( intval( $current_step ), array_map( 'intval', $step_ids ), true ) ) {
					continue;
				}
				$split_step_id = $split_id;
				break;
			}
			if ( intval( $split_step_id ) > 0 ) {
				break;
			}
		}

		if ( 0 === intval( $split_step_id ) ) {
			return '';
		}
		$split_data = BWFAN_Model_Automation_Step::get( $split_id );

		return isset( $split_data['created_at'] ) ? $split_data['created_at'] : '';
	}

	/**
	 * Get path's stats (node analytics version — uses number_format 1 decimal)
	 *
	 * @param $automation_id
	 * @param $step_ids
	 *
	 * @return array|WP_Error
	 */
	public function get_node_analytics_path_stats( $automation_id, $step_ids, $after_date = '' ) {
		$data = BWFAN_Model_Engagement_Tracking::get_automation_step_analytics( $automation_id, $step_ids, $after_date );

		$open_rate        = isset( $data['open_rate'] ) ? number_format( $data['open_rate'], 1 ) : 0;
		$click_rate       = isset( $data['click_rate'] ) ? number_format( $data['click_rate'], 1 ) : 0;
		$revenue          = isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : 0;
		$unsubscribes     = isset( $data['unsubscribers'] ) ? absint( $data['unsubscribers'] ) : 0;
		$conversions      = isset( $data['conversions'] ) ? absint( $data['conversions'] ) : 0;
		$sent             = isset( $data['sent'] ) ? absint( $data['sent'] ) : 0;
		$open_count       = isset( $data['open_count'] ) ? absint( $data['open_count'] ) : 0;
		$click_count      = isset( $data['click_count'] ) ? absint( $data['click_count'] ) : 0;
		$contacts_count   = isset( $data['contacts_count'] ) ? absint( $data['contacts_count'] ) : 1;
		$rev_per_person   = empty( $contacts_count ) || empty( $revenue ) ? 0 : number_format( $revenue / $contacts_count, 1 );
		$unsubscribe_rate = empty( $contacts_count ) || empty( $unsubscribes ) ? 0 : ( $unsubscribes / $contacts_count ) * 100;

		/** Get click rate from total opens */
		$click_to_open_rate = ( empty( $click_count ) || empty( $open_count ) ) ? 0 : number_format( ( $click_count / $open_count ) * 100, 1 );

		$tiles = [
			[
				'l' => __( 'Contact', 'wp-marketing-automations' ),
				'v' => '',
			],
			[
				'l' => __( 'Sent', 'wp-marketing-automations' ),
				'v' => empty( $sent ) ? '-' : $sent,
			],
			[
				'l' => __( 'Opened', 'wp-marketing-automations' ),
				'v' => empty( $open_count ) ? '-' : $open_count . ' (' . $open_rate . '%)',
			],
			[
				'l' => __( 'Clicked', 'wp-marketing-automations' ),
				'v' => empty( $click_count ) ? '-' : $click_count . ' (' . $click_rate . '%)',
			],
			[
				'l' => __( 'Click to Open', 'wp-marketing-automations' ),
				'v' => empty( $click_to_open_rate ) ? '-' : $click_to_open_rate . '%',
			]
		];
		if ( bwfan_is_woocommerce_active() ) {

			$currency_symbol = get_woocommerce_currency_symbol();
			$revenue         = empty( $revenue ) ? '' : html_entity_decode( $currency_symbol . $revenue, ENT_QUOTES | ENT_HTML401 );
			$rev_per_person  = empty( $rev_per_person ) ? '' : html_entity_decode( $currency_symbol . $rev_per_person, ENT_QUOTES | ENT_HTML401 );

			$revenue_tiles = [
				[
					'l' => __( 'Rev.', 'wp-marketing-automations' ),
					'v' => empty( $conversions ) ? '-' : $revenue . ' (' . $conversions . ')',
				],
				[
					'l' => __( 'Rev./Contact', 'wp-marketing-automations' ),
					'v' => empty( $rev_per_person ) ? '-' : $rev_per_person,
				]
			];

			$tiles = array_merge( $tiles, $revenue_tiles );
		}

		$tiles[] = [
			'l' => __( 'Unsubscribed', 'wp-marketing-automations' ),
			'v' => empty( $unsubscribes ) ? '-' : $unsubscribes . ' (' . number_format( $unsubscribe_rate, 1 ) . '%)',
		];

		return $tiles;
	}

	// ─── Single automation stats helpers (from BWFAN_API_Get_Single_Automation_Stats) ──

	/**
	 * Per-branch contact counts for branches steps.
	 *
	 * Keyed by the branch chip node id (`{node}-branch-{branchId}`) so the
	 * frontend BranchPathNode can read statData[node.id] — the same way split
	 * paths and other steps read their stat entry. The trail rows are keyed by
	 * the parent branches step's DB id, so we map node id -> stepId first.
	 *
	 * @param array $steps Persisted flow nodes (from automation meta).
	 * @param array $data  Stats map being assembled.
	 *
	 * @return array
	 */
	public function get_branch_steps_stats( $steps, $data ) {
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return $data;
		}

		/** Map branches node id -> DB step id (trail sid). */
		$parent_step_ids = [];
		foreach ( $steps as $step ) {
			if ( isset( $step['type'], $step['id'], $step['stepId'] ) && 'branches' === $step['type'] ) {
				$parent_step_ids[ $step['id'] ] = intval( $step['stepId'] );
			}
		}

		if ( empty( $parent_step_ids ) ) {
			return $data;
		}

		/** One grouped query for every branches step, then chips read from the map */
		$branch_counts = BWFAN_Model_Automation_Contact_Trail::get_branch_contact_counts( array_values( $parent_step_ids ) );

		foreach ( $steps as $step ) {
			if ( ! isset( $step['type'] ) || 'branchpath' !== $step['type'] || empty( $step['id'] ) || empty( $step['data'] ) ) {
				continue;
			}
			$parent    = isset( $step['data']['parent'] ) ? $step['data']['parent'] : '';
			$branch_id = isset( $step['data']['branchId'] ) ? $step['data']['branchId'] : '';
			if ( '' === $branch_id || ! isset( $parent_step_ids[ $parent ] ) ) {
				continue;
			}

			$sid                 = $parent_step_ids[ $parent ];
			$count               = isset( $branch_counts[ $sid ][ $branch_id ] ) ? $branch_counts[ $sid ][ $branch_id ] : 0;
			$data[ $step['id'] ] = [
				'queued'    => 0,
				'active'    => 0,
				'completed' => intval( $count ),
				'skipped'   => 0,
				'failed'    => 0,
			];
		}

		return $data;
	}

	public function get_split_steps_stats( $split_steps, $data ) {
		/** Get split step's step ids */
		$split_step_ids = [];
		foreach ( $split_steps as $split_id => $paths ) {
			foreach ( $paths as $step_ids ) {
				$current_step_ids            = isset( $split_step_ids[ $split_id ] ) ? $split_step_ids[ $split_id ] : [];
				$split_step_ids[ $split_id ] = array_merge( $current_step_ids, $step_ids );
			}
		}


		$split_steps_data = [];
		foreach ( $split_step_ids as $split_id => $step_ids ) {
			$step_data                                  = BWFAN_Model_Automation_Step::get( $split_id );
			$created_at                                 = isset( $step_data['created_at'] ) ? strtotime( $step_data['created_at'] ) : '';
			$split_steps_data[ $split_id ]['completed'] = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( $step_ids, 1, $created_at );
			$split_steps_data[ $split_id ]['queued']    = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( $step_ids, 2, $created_at );
		}

		foreach ( $split_steps_data as $steps ) {
			foreach ( $steps as $key => $step_data ) {
				if ( ! is_array( $step_data ) ) {
					continue;
				}
				foreach ( $step_data as $s_data ) {
					if ( ! isset( $data[ $s_data['sid'] ] ) ) {
						continue;
					}
					$data[ $s_data['sid'] ][ $key ] = $s_data['count'];
				}
			}
		}

		return $data;
	}


	public function get_split_path_stats( $aid, $split_steps ) {
		$path_stats = [];
		foreach ( $split_steps as $split_id => $paths ) {
			$step_data     = BWFAN_Model_Automation_Step::get_step_data_by_id( $split_id );
			$after_date    = isset( $step_data['created_at'] ) ? $step_data['created_at'] : '';
			$split_node_id = $this->automation_obj->get_steps_node_id( $split_id );
			foreach ( $paths as $path => $step_ids ) {
				$path_num                 = str_replace( 'p-', '', $path );
				$path_name                = $split_node_id . '-path-' . $path_num;
				$path_stats[ $path_name ] = $this->get_stats_path_stats( $aid, $step_ids, $after_date );
				$contact_count            = BWFAN_Model_Automation_Contact_Trail::get_path_contact_count( $split_id, $path_num );

				$path_stats[ $path_name ][] = [
					'l' => 'Contacts',
					'v' => intval( $contact_count ),
				];

			}
		}

		return $path_stats;
	}

	/**
	 * Get path's stats (single automation stats version — uses number_format 2 decimal)
	 *
	 * @param $automation_id
	 * @param $step_ids
	 *
	 * @return array|WP_Error
	 */
	public function get_stats_path_stats( $automation_id, $step_ids, $after_date ) {
		$data = BWFAN_Model_Engagement_Tracking::get_automation_step_analytics( $automation_id, $step_ids, $after_date );

		$open_rate        = isset( $data['open_rate'] ) ? number_format( $data['open_rate'], 2 ) : 0;
		$click_rate       = isset( $data['click_rate'] ) ? number_format( $data['click_rate'], 2 ) : 0;
		$revenue          = isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : 0;
		$unsubscribes     = isset( $data['unsbuscribers'] ) ? absint( $data['unsbuscribers'] ) : 0;
		$conversions      = isset( $data['conversions'] ) ? absint( $data['conversions'] ) : 0;
		$sent             = isset( $data['sent'] ) ? absint( $data['sent'] ) : 0;
		$open_count       = isset( $data['open_count'] ) ? absint( $data['open_count'] ) : 0;
		$click_count      = isset( $data['click_count'] ) ? absint( $data['click_count'] ) : 0;
		$contacts_count   = isset( $data['contacts_count'] ) ? absint( $data['contacts_count'] ) : 1;
		$rev_per_person   = empty( $contacts_count ) || empty( $revenue ) ? 0 : number_format( $revenue / $contacts_count, 2 );
		$unsubscribe_rate = empty( $contacts_count ) || empty( $unsubscribes ) ? 0 : ( $unsubscribes / $contacts_count ) * 100;

		/** Get click rate from total opens */
		$click_to_open_rate = ( empty( $click_count ) || empty( $open_count ) ) ? 0 : number_format( ( $click_count / $open_count ) * 100, 2 );

		$tiles = [
			[
				'l' => __( 'Sent', 'wp-marketing-automations' ),
				'v' => empty( $sent ) ? '-' : $sent,
			],
			[
				'l' => __( 'Open Rate', 'wp-marketing-automations' ),
				'v' => empty( $open_count ) ? '-' : $open_rate . '% (' . $open_count . ')',
			],
			[
				'l' => __( 'Click Rate', 'wp-marketing-automations' ),
				'v' => empty( $click_count ) ? '-' : $click_rate . '% (' . $click_count . ')',
			],
			[
				'l' => __( 'Click to Open Rate', 'wp-marketing-automations' ),
				'v' => empty( $click_to_open_rate ) ? '-' : $click_to_open_rate . '%',
			]
		];

		if ( bwfan_is_woocommerce_active() ) {

			$currency_symbol = get_woocommerce_currency_symbol();
			$revenue         = empty( $revenue ) ? '' : html_entity_decode( $currency_symbol . $revenue, ENT_QUOTES | ENT_HTML401 );
			$rev_per_person  = empty( $rev_per_person ) ? '' : html_entity_decode( $currency_symbol . $rev_per_person, ENT_QUOTES | ENT_HTML401 );

			$revenue_tiles = [
				[
					'l' => __( 'Revenue', 'wp-marketing-automations' ),
					'v' => empty( $conversions ) ? '-' : $revenue . ' (' . $conversions . ')',
				],
				[
					'l' => __( 'Revenue/Contact', 'wp-marketing-automations' ),
					'v' => empty( $rev_per_person ) ? '-' : $rev_per_person,
				]
			];

			$tiles = array_merge( $tiles, $revenue_tiles );
		}

		$tiles[] = [
			'l' => __( 'Unsubscribe Rate', 'wp-marketing-automations' ),
			'v' => empty( $unsubscribes ) ? '-' : number_format( $unsubscribe_rate, 2 ) . '% (' . $unsubscribes . ')',
		];

		return $tiles;
	}

	// ─── Conversions helper (from BWFAN_API_Get_Automation_Conversions) ──

	public function get_conversions( $automation_id, $offset = 0, $limit = 25 ) {
		if ( empty( $automation_id ) ) {
			return [ 'conversions' => [], 'total' => 0 ];
		}

		return BWFAN_Model_Conversions::get_conversions_by_source_type( $automation_id, BWFAN_Email_Conversations::$TYPE_AUTOMATION, $limit, $offset );
	}
}

BWFAN_API_Automation_Controller::get_instance();
