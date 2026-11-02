<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * V2 Automation
 */
#[AllowDynamicProperties]
class BWFAN_Automation_V2 {
	const EDIT_LOCK_PREFIX = 'bwfan_aut_';

	private static $ins = [];

	public $automation_id = 0;
	public $automation_data = [];
	public $automation_meta_data = [];
	public $automation_steps = [];
	public $step_type = [
		'wait'              => 1,
		'action'            => 2,
		'benchmark'         => 3,
		'conditional'       => 4,
		'exit'              => 5,
		'jump'              => 6,
		'split'             => 7,
		'branches'          => 8,
	];
	public $error = '';
	public $split_path_ins = null;

	/**
	 * Class constructor
	 *
	 * @param int $automation_id
	 */
	public function __construct( $automation_id = 0 ) {
		if ( intval( $automation_id ) > 0 ) {
			$this->set_automation_id( $automation_id );
			$this->fetch_automationdata_by_id();
		} else {
			return new WP_Error( 'Automation Id not found' );
		}
	}

	/**
	 * Fetch automation data and set to automation_data
	 *
	 * @return void
	 */
	public function fetch_automationdata_by_id() {
		$data = BWFAN_Model_Automations_V2::get_automation( $this->automation_id );
		if ( is_null( $data ) ) {
			$this->error = 'Automation not found with provided ID';
		}
		if ( isset( $data['benchmark'] ) && ! empty( $data['benchmark'] ) ) {
			$data['benchmark'] = json_decode( $data['benchmark'] );
		}
		$this->automation_data = $data;
	}

	/**
	 * Get instance
	 *
	 * @param $id
	 *
	 * @return BWFAN_Automation_V2|WP_Error|null
	 */
	public static function get_instance( $id = 0 ) {
		$id = intval( $id );
		if ( $id <= 0 ) {
			$error = new WP_Error( 'Automation Id not found' );
			/** Callers check ->error (as on a not-found instance) before using the object; WP_Error has no such property, so they went on to call methods on it and fatal */
			$error->error = 'Automation not found with provided ID';

			return $error;
		}
		/**
		 * One instance per automation: a single cached instance returned the
		 * first automation's data for every later id in the same request.
		 * Keyed by id, so the cache is bounded by how many automations exist -
		 * not by how many contacts a worker processes - and unset_instance()
		 * empties it.
		 */
		if ( ! isset( self::$ins[ $id ] ) ) {
			self::$ins[ $id ] = new self( $id );
		}

		return self::$ins[ $id ];
	}

	/**
	 * Get automation data
	 *
	 * @return array
	 */
	public function get_automation_data() {
		return $this->automation_data;
	}

	/**
	 * Returns get API data
	 *
	 * @return array
	 */
	public function get_automation_API_data() {

		$response = [
			'status'  => false,
			'message' => '',
			'data'    => []
		];

		if ( ! empty( $this->error ) ) {
			$response['message'] = $this->error;
		} else {

			$selected_event = isset( $this->automation_data['event'] ) ? $this->automation_data['event'] : '';

			$data = [
				'eventsList'      => $this->get_event_list( false, $selected_event ),
				'actionsList'     => $this->get_action_list(),
				'goals'           => $this->get_event_list( true ),
				'data'            => $this->automation_data,
				'merge_tags'      => $this->get_merge_tags_lists(),
				'delay_variables' => $this->get_merge_tags_lists( true ),
			];
			// meta data
			$automation_meta = $this->get_automation_meta_data();
			if ( ! isset( $automation_meta['merger_points'] ) ) {
				/** Save merger points of split and conditional step */
				$this->update_automation_meta_data( $automation_meta, $automation_meta['steps'], $automation_meta['links'], $automation_meta['count'] );
				$this->fetch_automation_metadata( false );
				$automation_meta = $this->get_automation_meta_data();
			}
			if ( isset( $automation_meta['steps'] ) && ! empty( $automation_meta['steps'] ) ) {
				$data['steps'] = $this->get_automation_steps( $automation_meta['steps'] );
				unset( $automation_meta['steps'] );
			}
			if ( isset( $automation_meta['links'] ) && ! empty( $automation_meta['links'] ) ) {
				$data['links'] = $automation_meta['links'];
				unset( $automation_meta['links'] );
			}

			if ( isset( $automation_meta['count'] ) && ! empty( $automation_meta['count'] ) ) {
				$data['count'] = $automation_meta['count'];
				unset( $automation_meta['count'] );
			}
			if ( isset( $automation_meta['event_meta'] ) ) {
				$automation_meta['event_meta'] = BWFAN_Common::fetch_updated_data( $automation_meta['event_meta'] );
			}

			// Check for event metadata
			if ( ! empty( $selected_event ) && ! empty( $automation_meta ) && ! empty( $automation_meta['event_meta'] ) ) {
				$event_obj = BWFAN_Core()->sources->get_event( $selected_event );
				if ( ! is_null( $event_obj ) && method_exists( $event_obj, 'validate_event_meta_data' ) ) {
					$automation_meta['event_meta'] = $event_obj->validate_event_meta_data( $automation_meta['event_meta'] );
				}
			}

			$data['meta'] = array_filter( $automation_meta );

			if ( bwfan_is_autonami_pro_active() && ! empty( $selected_event ) ) {
				BWFAN_Core()->rules->load_rules_classes();
				$data['rules'] = BWFAN_Core()->rules->get_rules( $selected_event, $this->automation_id );
			}

			$response['status']  = true;
			$response['message'] = 'Successfully fetched automation';
			$response['data']    = $data;
		}

		return $response;
	}

	/**
	 * Returns event data
	 *
	 * @param bool $get_goals
	 * @param string $selected_event
	 *
	 * @return array[]
	 */
	public function get_event_list( $get_goals = false, $selected_event = '' ) {
		$eventList = [
			'group'    => BWFAN_Core()->sources->get_event_groups(),
			'list'     => [],
			'subgroup' => [],
		];

		$eventList['list'] = BWFAN_Load_Sources::get_api_event_list_data( $get_goals, $selected_event );

		$eventList['subgroup'] = $get_goals ? BWFAN_Load_Sources::get_goal_subgroup() : BWFAN_Core()->sources->get_event_subgroups();

		$eventList['subgroup_priority'] = BWFAN_Core()->sources->get_event_subgroup_priority();

		if ( $get_goals ) {
			$eventList['group'] = array_values( $eventList['group'] );

			return $eventList;
		}

		$dynamic_events = BWFAN_Core()->sources->get_dynamic_event_data();

		if ( isset( $dynamic_events['list'] ) && ! empty( $dynamic_events['list'] ) ) {
			$eventList['list'] = array_merge( $dynamic_events['list'], $eventList['list'] );
		}
		if ( isset( $dynamic_events['group'] ) && ! empty( $dynamic_events['group'] ) ) {
			$eventList['group'] = $this->merge_group_data( $eventList['group'], $dynamic_events['group'] );
		}
		if ( isset( $dynamic_events['subgroup'] ) && ! empty( $dynamic_events['subgroup'] ) ) {
			$eventList['subgroup'] = $this->merge_subgroup_data( $eventList['subgroup'], $dynamic_events['subgroup'] );
		}

		if ( isset( $dynamic_events['subgroup_priority'] ) && ! empty( $dynamic_events['subgroup_priority'] ) ) {
			$eventList['subgroup_priority'] = array_merge( $dynamic_events['subgroup_priority'], $eventList['subgroup_priority'] );
		}

		$eventList['subgroup_desc'] = BWFAN_Core()->sources->get_event_subgroup_description();

		if ( ! empty( $eventList['group'] ) ) {
			usort( $eventList['group'], function ( $a, $b ) {
				return $a['priority'] <=> $b['priority'];
			} );
		}

		return $eventList;
	}

	public function merge_group_data( $main_groups, $dynamic_groups ) {
		foreach ( $dynamic_groups as $key => $value ) {
			if ( isset( $main_groups[ $key ] ) ) {
				if ( isset( $main_groups[ $key ]['subgroup'] ) && isset( $value['subgroup'] ) && ! empty( $value['subgroup'] ) ) {
					$main_groups[ $key ]['subgroup'] = array_unique( array_values( array_merge( $main_groups[ $key ]['subgroup'], $value['subgroup'] ) ) );
				}
			} else {
				$main_groups[ $key ] = $value;
			}
		}

		return array_values( $main_groups );
	}

	public function merge_subgroup_data( $main_sub_groups, $dynamic_sub_groups ) {
		foreach ( $dynamic_sub_groups as $key => $value ) {
			if ( isset( $main_sub_groups[ $key ] ) ) {
				foreach ( $value as $subkey => $data ) {
					if ( isset( $main_sub_groups[ $key ][ $subkey ] ) ) {
						$main_sub_groups[ $key ][ $subkey ] = array_unique( array_merge( $main_sub_groups[ $key ][ $subkey ], $data ) );
					} else {
						$main_sub_groups[ $key ][ $subkey ] = $data;
					}
				}
			} else {
				$main_sub_groups[ $key ] = $value;
			}
		}

		return $main_sub_groups;
	}

	/**
	 * Returns available action for event
	 *
	 * @return array[]
	 */
	public function get_action_list() {
		$automationdata  = $this->automation_data;
		$actionList      = [
			'group'    => BWFAN_Core()->integration->get_integration_group(),
			'list'     => BWFAN_Core()->integration::get_available_actions( isset( $automationdata['event'] ) ? $automationdata['event'] : '' ),
			'subgroup' => BWFAN_Core()->integration->get_integration_subgroups(),
		];
		$dynamic_actions = BWFAN_Core()->integration->get_dynamic_action_data();

		$actionList['list']     = ! empty( $dynamic_actions['list'] ) ? array_merge( $dynamic_actions['list'], $actionList['list'] ) : $actionList['list'];
		$actionList['group']    = ! empty( $dynamic_actions['group'] ) ? $this->merge_group_data( $actionList['group'], $dynamic_actions['group'] ) : array_values( $actionList['group'] );
		$actionList['subgroup'] = ! empty( $dynamic_actions['subgroup'] ) ? $this->merge_subgroup_data( $actionList['subgroup'], $dynamic_actions['subgroup'] ) : $actionList['subgroup'];

		if ( isset( $actionList['subgroup']['wp_adv']['WordPress'] ) && in_array( 'wp_http_post', $actionList['subgroup']['wp_adv']['WordPress'] ) ) {
			$actionList['subgroup']['wp_adv']['WordPress'] = array_diff( $actionList['subgroup']['wp_adv']['WordPress'], array( 'wp_http_post' ) );
		}

		return $actionList;
	}

	/**
	 * Returns merge tags for automation based on event
	 */
	public function get_merge_tags_lists( $get_delay_variables = false ) {
		$data = $this->automation_data;
		if ( empty( $data['event'] ) ) {
			return [];
		}
		$event = BWFAN_Core()->sources->get_event( $data['event'] );
		if ( is_null( $event ) ) {
			return [];
		}
		/**
		 * Get Event's all merge_tags
		 **/
		$all_merge_tags         = BWFAN_Core()->merge_tags->get_all_merge_tags();
		$event_merge_tag_groups = $event->get_merge_tag_groups();
		$mergeGroup             = BWFAN_Core()->merge_tags->get_merge_tag_groups();
		$merge_tags             = [];
		$delay_tags             = [];

		foreach ( $event_merge_tag_groups as $merge_tag_group ) {
			$field_tags          = [];
			$all_merge_tags_data = [];
			$all_delay_tags      = [];
			if ( ! isset( $all_merge_tags[ $merge_tag_group ] ) ) {
				continue;
			}

			/** creating custom contact field merge tag if present */
			if ( isset( $all_merge_tags[ $merge_tag_group ]['bwfan_contact_field'] ) ) {
				$field_merge_tags = $all_merge_tags[ $merge_tag_group ]['bwfan_contact_field'];
				$field_tags       = $this->get_contact_fields_tags( $field_merge_tags->get_priority() );
				unset( $all_merge_tags[ $merge_tag_group ]['bwfan_contact_field'] ); // unsetting so that it will not loop over again
			}

			foreach ( $all_merge_tags[ $merge_tag_group ] as $tags ) {
				if ( ! $tags->is_support_v2() || 'bwfan_contact_confirmation_link' === $tags->get_slug() ) {
					continue;
				}

				// store delay variables
				if ( true === $get_delay_variables && true === $tags->is_delay_variable() ) {
					$delay_data = [
						'tag_name'        => $tags->get_name(),
						'tag_description' => $tags->get_description(),
						'priority'        => $tags->get_priority(),
					];
					if ( method_exists( $tags, 'get_delay_setting_schema' ) ) {
						$delay_data['schema'] = $tags->get_delay_setting_schema();
					}
					if ( method_exists( $tags, 'get_default_values' ) ) {
						$delay_data['default_val'] = $tags->get_default_values();
					}

					$all_delay_tags[] = $delay_data;
				}

				$tag_data = [
					'tag_name'        => $tags->get_name(),
					'tag_description' => $tags->get_description(),
					'priority'        => $tags->get_priority(),
				];
				if ( method_exists( $tags, 'get_setting_schema' ) ) {
					$tag_data['schema'] = $tags->get_setting_schema();
				}
				if ( method_exists( $tags, 'get_default_values' ) ) {
					$tag_data['default_val'] = $tags->get_default_values();
				}

				$all_merge_tags_data[] = $tag_data;
			}

			// merging tag_data and field tags if present
			if ( $merge_tag_group === 'bwf_contact' && ! empty( $field_tags ) ) {
				$all_merge_tags_data = array_merge( $all_merge_tags_data, $field_tags );
			}

			$tag_data = array_filter( $all_merge_tags_data );
			uasort( $tag_data, function ( $a, $b ) {
				return $a['priority'] <= $b['priority'] ? - 1 : 1;
			} );

			$mergeTagGroup = isset( $mergeGroup[ $merge_tag_group ] ) ? $mergeGroup[ $merge_tag_group ] : $merge_tag_group;

			if ( ! empty( $all_delay_tags ) ) {
				$delay_data = array_filter( $all_delay_tags );
				uasort( $delay_data, function ( $a, $b ) {
					return $a['priority'] <= $b['priority'] ? - 1 : 1;
				} );

				// store delay tags
				if ( ! empty( $delay_tags[ $mergeTagGroup ] ) ) {
					$delay_tags[ $mergeTagGroup ] = array_merge( $delay_tags[ $mergeTagGroup ], $delay_data );
				} else {
					$delay_tags[ $mergeTagGroup ] = $delay_data;
				}
			} else {
				if ( ! empty( $merge_tags[ $mergeTagGroup ] ) ) {
					$merge_tags[ $mergeTagGroup ] = array_merge( $merge_tags[ $mergeTagGroup ], $tag_data );
				} else {
					$merge_tags[ $mergeTagGroup ] = $tag_data;
				}
			}
		}

		return false === $get_delay_variables ? $merge_tags : $delay_tags;
	}

	/**
	 * fetching all the contact custom fields for creating merge tag
	 *
	 * @param $priority
	 *
	 * @return array
	 */
	public function get_contact_fields_tags( $priority ) {
		$fields = BWFCRM_Fields::get_custom_fields( 1, 1, null );

		$return = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['slug'] ) ) {
				continue;
			}

			$field_group                               = 'contact_field key="' . $field['slug'] . '"';
			$return[ $field_group ]['tag_name']        = 'contact_field key="' . $field['slug'] . '"';
			$return[ $field_group ]['tag_description'] = $field['name'];
			$return[ $field_group ]['priority']        = $priority;

		}

		return $return;
	}

	/**
	 * Get Automation meta data
	 */
	public function get_automation_meta_data( $fetch_all = false ) {
		if ( empty( $this->automation_meta_data ) ) {
			$this->fetch_automation_metadata();
		}
		$meta_val = $this->automation_meta_data;

		if ( $fetch_all ) {
			return $meta_val;
		}
		if ( ! empty( $meta_val ) ) {
			// Remove values where key has restore_point in it
			foreach ( $meta_val as $key => $value ) {
				if ( strpos( $key, 'restore_point_' ) !== false ) {
					unset( $meta_val[ $key ] );
				}
			}
		}

		return $meta_val;
	}

	/**
	 * Fetch automation data and set to automation_data
	 *
	 * @return void
	 */
	public function fetch_automation_metadata( $cache = true ) {
		$this->automation_meta_data = BWFAN_Model_Automationmeta::get_automation_meta( $this->automation_id, $cache );
	}

	/**
	 * Fetch automation steps
	 *
	 * @param $steps
	 *
	 * @return array
	 */
	public function get_automation_steps( $steps = [], $get_deleted = false ) {
		$automation_steps = array();
		if ( ! empty( $steps ) ) {
			/** Get all steps for the automation */
			$feted_data = BWFAN_Model_Automation_Step::get_all_automation_steps( $this->automation_id, 0, 0, '', 'DESC', 'ID', [], false, $get_deleted );

			/** Set var for orphan step */
			$all_automation_steps = [];

			/** Format automation step by step id */
			if ( ! empty( $feted_data ) ) {
				$this->automation_steps = $this->format_step_data( $feted_data['steps'] );
				/** Setting all steps id found for this automation */
				$all_automation_steps = array_keys( $this->automation_steps );
			}

			/** Set automation step data to react UI */
			foreach ( $steps as $step ) {
				if ( isset( $step['id'] ) && ! in_array( $step['id'], array( 'start', 'end' ), true ) && isset( $step['stepId'] ) && intval( $step['stepId'] ) > 0 && isset( $this->automation_steps[ $step['stepId'] ] ) ) {
					$data = $this->automation_steps[ $step['stepId'] ];

					/** Unset the steps id from orphan array */
					array_splice( $all_automation_steps, array_search( $step['stepId'], $all_automation_steps ), 1 );

					/** Set step note data if found */
					if ( isset( $data['data'] ) && isset( $data['data']['note'] ) ) {
						$step['data']['note'] = $data['data']['note'];
					}

					/** If tags and list in data then fetch updated name of tags & list */
					if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) && isset( $data['action'] ) && isset( $data['action']['intergration'] ) && 'autonami' === $data['action']['intergration'] ) {
						$data['data']['sidebarData'] = BWFAN_Common::modify_step_admin_data( $step['type'], $data['data']['sidebarData'] );
					}

					$step['step_status'] = isset( $this->automation_steps[ $step['stepId'] ]['status'] ) ? intval( $this->automation_steps[ $step['stepId'] ]['status'] ) : 1;

					/** Check for different case */
					switch ( $step['type'] ) {
						case 'action':
							if ( isset( $data['action'] ) && ! empty( $data['action'] ) && isset( $data['action']['action'] ) ) {
								$step['data']['selected'] = $data['action']['action'];
								$action                   = BWFAN_Core()->integration->get_action( $data['action']['action'] );
								if ( ! is_null( $action ) && method_exists( $action, 'get_desc_text' ) && isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
									$step['data']['desc_text'] = $action->get_desc_text( $data['data']['sidebarData'] );
								}
							}
							if ( isset( $data['action'] ) && ! empty( $data['action'] ) && isset( $data['action']['intergration'] ) ) {
								$step['data']['intergration'] = $data['action']['intergration'];
							}
							if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
								$step_data = ( array ) $data['data']['sidebarData'];
								if ( isset( $data['updated_at'] ) && isset( $step_data['bwfan_email_data'] ) ) {
									$step_data['bwfan_email_data']                         = (array) $step_data['bwfan_email_data'];
									$step_data['bwfan_email_data']['data']                 = (array) $step_data['bwfan_email_data']['data'];
									$step_data['bwfan_email_data']['data']['last_updated'] = $data['updated_at'];
								}
								$step['data']['sidebarValues'] = $step_data;
							}
							break;
						case 'wait':
						case 'conditional':
							if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
								$data['data']['sidebarData']   = BWFAN_Common::modify_step_admin_data( 'conditional', $data['data']['sidebarData'] );
								$step['data']['sidebarValues'] = $data['data']['sidebarData'];
							}
							break;
						case 'branches':
							if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
								$sidebar = $data['data']['sidebarData'];
								if ( isset( $sidebar->branches ) && is_array( $sidebar->branches ) ) {
									foreach ( $sidebar->branches as $b_index => $branch ) {
										if ( isset( $branch->rules ) ) {
											$sidebar->branches[ $b_index ]->rules = BWFAN_Common::modify_step_admin_data( 'conditional', $branch->rules );
										}
									}
								}
								$data['data']['sidebarData']   = $sidebar;
								$step['data']['sidebarValues'] = $data['data']['sidebarData'];
							}
							break;
						case 'jump':
							if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
								/** If jump step is deleted */
								if ( isset( $data['data']['sidebarData']->jump_to ) && isset( $data['data']['sidebarData']->jump_to->step ) ) {
									$jump_step_id = $data['data']['sidebarData']->jump_to->step;
									$is_active    = BWFAN_Model_Automation_Step::is_step_active( $jump_step_id );
									if ( empty( $is_active ) && 'end' !== $jump_step_id ) {
										$data['data']['sidebarData']->jump_to = '';

										/** Update jump data in the database */
										BWFAN_Model_Automation_Step::update_automation_step_data( $step['stepId'], [ 'data' => wp_json_encode( $data['data'] ) ] );
									}
								}
								$step['data']['sidebarValues'] = $data['data']['sidebarData'];
							}
							break;
						case 'benchmark':
							if ( isset( $data['action'] ) && ! empty( $data['action'] ) && isset( $data['action']['benchmark'] ) ) {
								$step['data']['benchmark'] = $data['action']['benchmark'];
								$benchmark                 = BWFAN_Core()->sources->get_event( $data['action']['benchmark'] );
								if ( ! is_null( $benchmark ) && method_exists( $benchmark, 'get_desc_text' ) && isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
									$data['data']['sidebarData'] = BWFAN_Common::modify_step_admin_data( 'benchmark', $data['data']['sidebarData'] );
									$step['data']['desc_text']   = $benchmark->get_desc_text( $data['data']['sidebarData'] );
								}
							}
							if ( isset( $data['action'] ) && ! empty( $data['action'] ) && isset( $data['action']['source'] ) ) {
								$step['data']['source'] = $data['action']['source'];
							}
							if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
								$step['data']['sidebarValues'] = $data['data']['sidebarData'];
							}
							break;
						default:
							if ( isset( $data['data'] ) && isset( $data['data']['sidebarData'] ) ) {
								$step['data']['sidebarValues'] = $data['data']['sidebarData'];
							}
							break;
					}
				}

				$automation_steps[] = $step;
			}

			/** Check for orphan step includes */
			if ( ! empty( $all_automation_steps ) ) {
				/** Delete Orphan steps added */
				BWFAN_Model_Automation_Step::delete_automation_steps( $all_automation_steps );
			}
		}

		/** Return formatted step data for front */
		return $automation_steps;
	}

	/**
	 * Format step data while saving it to db
	 *
	 * @param $steps
	 *
	 * @return array
	 */
	public function format_step_data( $steps ) {
		if ( ! is_array( $steps ) ) {
			return [];
		}
		$result = [];

		foreach ( $steps as $step ) {
			if ( isset( $step['data'] ) && ! empty( $step['data'] ) ) {
				$step['data'] = ( array ) json_decode( $step['data'] );
			}
			if ( isset( $step['action'] ) && ! empty( $step['action'] ) ) {
				$step['action'] = ( array ) json_decode( $step['action'] );
			}
			$result[ $step['ID'] ] = $step;
		}

		return $result;
	}

	/**
	 * Get automation completed contacts count
	 *
	 * @return string|null
	 */
	public function get_complete_count() {
		$aid = $this->get_automation_id();

		return BWFAN_Model_Automation_Complete_Contact::get_complete_count( $aid );
	}

	/**
	 * Return automation id
	 *
	 * @return int
	 */
	public function get_automation_id() {
		return $this->automation_id;
	}

	/**
	 * Set automation id
	 *
	 * @param $automation_id
	 *
	 * @return void
	 */
	public function set_automation_id( $automation_id ) {
		$this->automation_id = $automation_id;
	}

	/**
	 * Get active automation contacts count
	 *
	 * @return string|null
	 */
	public function get_active_count() {
		$aid = $this->get_automation_id();

		return BWFAN_Model_Automation_Contact::get_active_count( $aid, 'active' );
	}

	/**
	 * Check if two arrays are same
	 *
	 * @return bool
	 */
	public static function check_array_same( $array1, $array2 ) {
		if ( ! is_array( $array1 ) || ! is_array( $array2 ) ) {
			return false;
		}
		ksort( $array1 );
		ksort( $array2 );

		return $array1 == $array2;
	}

	/**
	 * Self-heal: every branches step must have an Everyone Else branch node + link.
	 * Covers API/import-created steps that bypass the builder UI.
	 *
	 * @param array $steps
	 * @param array $links
	 *
	 * @return array
	 */
	public function ensure_branches_else( $steps, $links ) {
		if ( ! is_array( $steps ) || ! is_array( $links ) ) {
			return [ 'steps' => $steps, 'links' => $links ];
		}

		$node_ids = [];
		foreach ( $steps as $step ) {
			if ( isset( $step['id'] ) ) {
				$node_ids[ $step['id'] ] = true;
			}
		}

		/** Lazy: iteration array is only built when a heal is actually needed */
		$iteration = null;

		foreach ( $steps as $step ) {
			if ( ! isset( $step['type'] ) || 'branches' !== $step['type'] || empty( $step['id'] ) ) {
				continue;
			}
			$else_id = $step['id'] . '-branch-else';
			if ( isset( $node_ids[ $else_id ] ) ) {
				continue;
			}

			/**
			 * Healed else must rejoin the flow where the existing branch paths
			 * converge (their merger point), not jump to 'end' - otherwise
			 * unmatched contacts skip every shared step after the branches.
			 * Freshly computed merger (from the surviving chips' paths) wins;
			 * with fewer than two surviving paths nothing intersects, so fall
			 * back to the stored merger_points meta from the last good save
			 * (validated against the current node set), then to 'end'.
			 */
			if ( null === $iteration ) {
				$step_link_data = $this->get_step_iteration_array( $steps, $links );
				$iteration      = isset( $step_link_data['iteration_array'] ) ? $step_link_data['iteration_array'] : [];
			}
			$merger = ! empty( $step['stepId'] ) ? $this->get_steps_merger_point( $step['stepId'], $links, $steps, $iteration ) : [];
			$target = ! empty( $merger['merger_points'] ) ? $merger['merger_points'] : 0;
			if ( empty( $target ) ) {
				$meta_data     = $this->get_automation_meta_data();
				$stored_merger = isset( $meta_data['merger_points'][ $step['id'] ] ) ? $meta_data['merger_points'][ $step['id'] ] : 0;
				$target        = ! empty( $stored_merger ) && isset( $node_ids[ $stored_merger ] ) ? $stored_merger : 0;
			}
			$target = ! empty( $target ) ? $target : 'end';

			$steps[] = [
				'id'             => $else_id,
				'sourcePosition' => 'bottom',
				'targetPosition' => 'top',
				'type'           => 'branchpath',
				'stepId'         => $else_id,
				'step_status'    => 2,
				'hidden'         => false,
				'position'       => [ 'x' => 0, 'y' => 0 ],
				'data'           => [ 'branchId' => 'else', 'name' => __('Everyone Else', 'wp-marketing-automations'), 'isElse' => true, 'parent' => $step['id'] ],
			];
			$links[] = [
				'id'           => $step['id'] . '-branch-edge-else',
				'source'       => $step['id'],
				'label'        => '',
				'target'       => $else_id,
				// Must match the BranchesNode source Handle id (`branches-edge`)
				// and the frontend dispatcher, or React Flow drops the healed
				// else fork edge and it renders disconnected.
				'sourceHandle' => 'branches-edge',
				'animated'     => false,
			];
			$links[] = [
				/** Fixed '-target-end' id suffix matches the frontend chip-link pattern regardless of actual target */
				'id'           => $else_id . '-target-end',
				'source'       => $else_id,
				'label'        => '',
				'target'       => $target,
				'sourceHandle' => '',
				'animated'     => false,
			];
			$node_ids[ $else_id ] = true;
		}

		return [ 'steps' => $steps, 'links' => $links ];
	}

	/**
	 * Update Automation meta data
	 *
	 * @param $meta
	 * @param $steps
	 * @param $links
	 * @param $count
	 *
	 * @return false
	 */
	public function update_automation_meta_data( $meta, $steps, $links, $count ) {
		$response       = false;
		$meta_data_arr  = [];
		$healed         = $this->ensure_branches_else( $steps, $links );
		$steps          = $healed['steps'];
		$links          = $healed['links'];
		$step_link_data = $this->get_step_iteration_array( $steps, $links );

		$iteration_array = $step_link_data['iteration_array'];
		$steps           = $step_link_data['steps'];
		$links           = $step_link_data['links'];

		$meta_data = $this->get_automation_meta_data();
		/** Update Automation meta data */
		if ( ! empty( $meta ) ) {
			$new_val = [];
			foreach ( $meta as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = maybe_serialize( $value );
				}
				if ( isset( $meta_data[ $key ] ) ) {
					$meta_data_arr[ $key ] = $value;
				} else {
					$new_val[ $key ] = $value;
				}
			}
			/** Insert new automation meta data */
			if ( ! empty( $new_val ) ) {
				$response = BWFAN_Model_Automationmeta::insert_automation_meta_data( $this->automation_id, $new_val );
			}
		}

		$set_restore_point = false;
		$restore_data      = [];

		/** Set step data */
		if ( ! empty( $steps ) ) {
			$steps_data             = $this->format_step_save_data( $steps );
			$meta_data_arr['steps'] = maybe_serialize( $steps_data );
			if ( ! self::check_array_same( $steps_data, $meta_data['steps'] ) ) {
				$set_restore_point = true;
			}
			$restore_data['steps'] = $steps_data;

			/**
			 * Soft-delete step rows the user removed in THIS save. Deleting a
			 * split-path branch (or a split/conditional subtree) drops the child
			 * nodes from the graph but historically left their step rows active,
			 * so a contact parked on such a step was orphaned and looped forever
			 * (traversal can't map the step to a node and re-runs it). Diffing the
			 * previously-saved graph against the new one means this is a no-op
			 * unless a node actually disappeared - add/move/config saves skip it.
			 */
			$this->soft_delete_removed_steps( isset( $meta_data['steps'] ) ? $meta_data['steps'] : [], $steps );
		}

		/** Set link data */
		if ( ! empty( $links ) ) {
			$meta_data_arr['links'] = maybe_serialize( $links );
			if ( ! empty( $iteration_array ) ) {
				if ( $this->automation_data['start'] != $iteration_array['start'][0]['next'] ) {
					$this->update_automation_main_table( [ 'start' => $iteration_array['start'][0]['next'] ] );
				}
				$meta_data_arr['step_iteration_array'] = maybe_serialize( $iteration_array );

			}

			if ( ! self::check_array_same( $links, $meta_data['links'] ) ) {
				$set_restore_point = true;
			}
			$restore_data['links'] = $links;
		}

		/** Set the restore point if step or link data is changed */
		if ( $set_restore_point ) {
			$restore_data['updated_at'] = current_time( 'timestamp', 1 );
			$restore_data['updated_by'] = get_current_user_id();
			$restore_data['count']      = $count;
			$restore_data['event']      = $this->automation_data['event'] ?? '';
			$this->set_automation_restore_point( $restore_data );;
		}

		/** Save merger points of split and conditional step */
		$merger_or_steps = $this->get_all_steps_merger_point( $steps, $links, $iteration_array );

		if ( ! empty( $merger_or_steps['merger_points'] ) ) {
			$meta_data_arr['merger_points'] = maybe_serialize( $merger_or_steps['merger_points'] );
			$meta_data                      = $this->get_automation_meta_data();
			if ( ! isset( $meta_data['merger_points'] ) ) {
				$merger_points = [
					'merger_points' => maybe_serialize( $merger_or_steps['merger_points'] )
				];
				BWFAN_Model_Automationmeta::insert_automation_meta_data( $this->automation_id, $merger_points );
				unset( $meta_data_arr['merger_points'] );
			}
		}

		/** Save splits with all steps */
		$split_steps = isset( $merger_or_steps['split_steps'] ) ? $merger_or_steps['split_steps'] : [];
		if ( ! empty( $split_steps ) ) {
			$meta_data_arr['split_steps'] = maybe_serialize( $split_steps );
			$meta_data                    = $this->get_automation_meta_data();
			if ( ! isset( $meta_data['split_steps'] ) ) {
				$split_steps = [
					'split_steps' => maybe_serialize( $split_steps )
				];
				BWFAN_Model_Automationmeta::insert_automation_meta_data( $this->automation_id, $split_steps );
				unset( $meta_data_arr['split_steps'] );
			}
		}


		/** Set node count */
		if ( ! empty( $count ) ) {
			$meta_data_arr['count'] = $count;
		}

		if ( empty( $meta_data_arr ) ) {
			return $response;
		}

		return BWFAN_Model_Automationmeta::update_automation_meta_values( $this->automation_id, $meta_data_arr );
	}

	/**
	 * Soft-delete step rows that were dropped from the graph in this save.
	 *
	 * Compares the previously-saved node list against the new one and marks any
	 * real step that disappeared as deleted (status = 3). This is what a
	 * split-path branch delete was missing: it rewrote the graph meta but never
	 * deactivated the removed branch's child rows, so a contact parked on one
	 * looped forever. Purely a diff of two in-memory node lists - no DB read and
	 * no writes unless a node was actually removed, so normal add/move/config
	 * saves are unaffected.
	 *
	 * @param array $old_steps previously saved graph nodes
	 * @param array $new_steps graph nodes being saved now
	 *
	 * @return void
	 */
	public function soft_delete_removed_steps( $old_steps, $new_steps ) {
		$old = $this->collect_graph_step_ids( $old_steps );
		if ( empty( $old ) ) {
			return;
		}

		$new = $this->collect_graph_step_ids( $new_steps );

		/** Step ids present before this save but gone now = removed by the user. */
		$removed = array_diff_key( $old, $new );

		/** One aid-scoped UPDATE for all of them, not one per step: a large rewrite can remove 50 steps (same effect as delete_automation_step(), which fires no hooks) */
		$ids = array_filter( array_map( 'absint', array_keys( $removed ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bwfan_automation_step SET `status` = 3 WHERE `aid` = %d AND `ID` IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( absint( $this->automation_id ) ), array_values( $ids ) ) ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
	}

	/**
	 * Collect real step ids from a graph node list.
	 *
	 * Skips start/end, split-path pseudo-nodes ({sid}-path-{n}) and branch
	 * chip pseudo-nodes ({sid}-branch-{id}), which are not backed by step
	 * table rows — absint() on a chip id would collide with real step ids.
	 *
	 * @param array $steps graph nodes
	 *
	 * @return array map of step id => true
	 */
	public function collect_graph_step_ids( $steps ) {
		$ids = [];
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return $ids;
		}

		foreach ( $steps as $node ) {
			if ( empty( $node['stepId'] ) || false !== strpos( (string) $node['stepId'], '-path-' ) || false !== strpos( (string) $node['stepId'], '-branch-' ) ) {
				continue;
			}
			$sid = absint( $node['stepId'] );
			if ( $sid > 0 ) {
				$ids[ $sid ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Set automation restore point
	 *
	 * @param $data
	 *
	 * @return bool
	 */
	public function set_automation_restore_point( $data = [] ) {
		$meta_data               = $this->get_automation_meta_data();
		$restore_point_timestamp = $data['updated_at'];
		$restore_point           = $meta_data['restore_points'] ?? [];
		$meta_data_array         = [];

		/** Save restore point data */
		$meta_key = 'restore_point_' . $restore_point_timestamp;
		BWFAN_Model_Automationmeta::insert_automation_meta_data( $this->automation_id, [ $meta_key => maybe_serialize( $data ) ] );

		/** Save restore reference time */
		$restore_point[] = $restore_point_timestamp;
		if ( 1 === count( $restore_point ) ) {
			/** First restore point */
			BWFAN_Model_Automationmeta::insert_automation_meta_data( $this->automation_id, [ 'restore_points' => maybe_serialize( $restore_point ) ] );

			return false;
		}

		if ( count( $restore_point ) > 20 ) {
			/** Remove the oldest timestamp (index 0) and delete the corresponding row */
			$removed_timestamp = array_shift( $restore_point );  // Remove the oldest timestamp

			/** Delete the corresponding row from the database */
			BWFAN_Model_Automationmeta::delete_automation_meta( $this->automation_id, 'restore_point_' . $removed_timestamp );
		}

		/** Save restore reference in the meta */
		$meta_data_array['restore_points'] = maybe_serialize( $restore_point );

		return BWFAN_Model_Automationmeta::update_automation_meta_values( $this->automation_id, $meta_data_array );
	}

	/**
	 * Modify step data
	 * Save Benchmark data
	 *
	 * @param $steps
	 *
	 * @return array
	 */
	public function format_step_save_data( $steps ) {
		if ( ! is_array( $steps ) ) {
			return [];
		}
		$benchmark = [];
		$result    = [];
		foreach ( $steps as $step ) {
			if ( isset( $step['type'] ) && $step['type'] == 'benchmark' ) {
				$benchmark[ $step['stepId'] ] = '';
			}
			if ( isset( $step['type'] ) && ! in_array( $step['type'], [ 'yesNoNode', 'splitpath', 'branchpath' ], true ) && isset( $step['data'] ) && ! empty( $step['data'] ) ) {
				$step['data'] = [];
			}
			$result[] = $step;
		}

		if ( ! empty( $benchmark ) ) {
			$benchmark_step_ids = array_keys( $benchmark );

			$benchmark = BWFAN_Model_Automation_Step::get_benchmark_events( $benchmark_step_ids );
		}

		$this->update_automation_main_table( [
			'benchmark' => ! empty( $benchmark ) ? wp_json_encode( $benchmark ) : ''
		] );

		return $result;
	}

	/**
	 * Update Automation main table data
	 *
	 * @param $data
	 *
	 * @return false|void
	 */
	public function update_automation_main_table( $data ) {
		$response = false;

		if ( empty( $data ) ) {
			return $response;
		}

		/** Update Automation data in main table */
		return BWFAN_Model_Automations_V2::update_automation( $this->automation_id, $data );
	}

	/**
	 * Get Iteration array
	 *
	 * @param array $steps
	 * @param array $links
	 *
	 * @return array
	 */
	public function get_step_iteration_array( $steps = [], $links = [] ) {
		$automationSteps = [];
		if ( ! is_array( $steps ) ) {
			$steps = [];
		}
		if ( ! is_array( $links ) ) {
			$links = [];
		}
		foreach ( $steps as $step ) {
			if ( empty( $step['id'] ) ) {
				continue;
			}
			if ( 'yesNoNode' === $step['type'] ) {
				$step['stepId'] = $step['id'];
			}

			$automationSteps[ $step['id'] ] = $step;
		}

		$link_data      = [];
		$filtered_links = [];
		foreach ( $links as $link ) {
			if ( empty( $link ) || ! isset( $link['source'], $link['target'] ) ) {
				continue;
			}
			$source = 'start' !== $link['source'] ? ( isset( $automationSteps[ $link['source'] ]['stepId'] ) ? $automationSteps[ $link['source'] ]['stepId'] : '' ) : $link['source'];
			$target = 'end' !== $link['target'] ? ( isset( $automationSteps[ $link['target'] ]['stepId'] ) ? $automationSteps[ $link['target'] ]['stepId'] : '' ) : $link['target'];
			if ( empty( $source ) || empty( $target ) || ( ! isset( $automationSteps[ $link['source'] ] ) && ! isset( $automationSteps[ $link['target'] ] ) ) ) {
				continue;
			}
			$target_type = isset( $automationSteps[ $link['target'] ]['type'] ) ? $automationSteps[ $link['target'] ]['type'] : '';
			if ( 'yesNoNode' === $target_type ) {
				$index                          = strpos( $target, 'yes' ) !== false ? 'yes' : 'no';
				$link_data[ $source ][ $index ] = array(
					'next' => $target,
					'type' => $target_type,
				);
			} else {
				$link_data[ $source ][] = array(
					'next' => $target,
					'type' => $target_type,
				);
			}
			$filtered_links[] = $link;
		}

		return [
			'iteration_array' => $link_data,
			'steps'           => $steps,
			'links'           => $filtered_links,
		];
	}

	/**
	 * Add new automation step
	 *
	 * @param $type
	 * @param $api_data
	 *
	 * @return int
	 */
	public function add_new_automation_step( $type, $api_data = [] ) {
		$time = current_time( 'mysql', 1 );
		if ( ! isset( $this->step_type[ $type ] ) ) {
			$this->error = __( 'Unknown automation step type: ', 'wp-marketing-automations' ) . $type;

			return 0;
		}
		$data = [
			'aid'        => $this->automation_id,
			'type'       => $this->step_type[ $type ],
			'created_at' => $time,
			'updated_at' => $time,
			'status'     => isset( $api_data['status'] ) ? $api_data['status'] : 0,
		];

		/** Add data on moving and copying step */
		if ( ! empty( $api_data ) ) {
			$action = [];
			$meta   = [];

			/** Set note data */
			if ( isset( $api_data['note'] ) && ! empty( $api_data['note'] ) ) {
				$meta['note'] = $api_data['note'];
			}

			/** Set side bar data */
			if ( isset( $api_data['sidebarValues'] ) && ! empty( $api_data['sidebarValues'] ) ) {
				$meta['sidebarData'] = $api_data['sidebarValues'];
			}

			/** Set action data */
			if ( $data['type'] === 2 ) {
				if ( isset( $api_data['selected'] ) && ! empty( $api_data['selected'] ) ) {
					$action['action'] = $api_data['selected'];
				}
				if ( isset( $api_data['intergration'] ) && ! empty( $api_data['intergration'] ) ) {
					$action['intergration'] = $api_data['intergration'];
				}
			}

			/** Set benchmark data */
			if ( $data['type'] === 3 ) {
				if ( isset( $api_data['benchmark'] ) && ! empty( $api_data['benchmark'] ) ) {
					$action['benchmark'] = $api_data['benchmark'];
				}
				if ( isset( $api_data['source'] ) && ! empty( $api_data['source'] ) ) {
					$action['source'] = $api_data['source'];
				}
			}

			/** Set action data if available */
			if ( ! empty( $action ) ) {
				$data['action'] = wp_json_encode( $action );
			}

			/** Set step meta data if available */
			if ( ! empty( $meta ) ) {
				$data['data'] = wp_json_encode( $meta );
			}

		}

		/** return save status */
		return BWFAN_Model_Automation_Step::create_new_automation_step( $data );
	}

	/**
	 * Update step
	 *
	 * @param $step_id
	 * @param $data
	 * @param int $update_status
	 * @param boolean $update_async
	 *
	 * @return bool
	 */
	public function update_automation_step_data( $step_id, $data, $update_status = 1, $update_async = false ) {
		if ( empty( $data ) || 0 === intval( $step_id ) ) {
			return false;
		}

		/** Get step saved data */
		$step_data = BWFAN_Model_Automation_Step::get_step_data_by_id( $step_id );
		if ( empty( $step_data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', 1 );
		$data['aid']        = $this->automation_id;
		$data['status']     = $update_status;

		$temp_data = ! empty( $step_data['data'] ) ? json_decode( $step_data['data'], true ) : [];
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$temp_data = array_merge( $temp_data, $data['data'] );
		}

		/** Unset completed and queued data */
		if ( isset( $temp_data['completed'] ) ) {
			unset( $temp_data['completed'] );
		}

		if ( isset( $temp_data['queued'] ) ) {
			unset( $temp_data['queued'] );
		}

		$data['data'] = wp_json_encode( $temp_data );

		if ( isset( $data['action'] ) && ! empty( $data['action'] ) ) {
			$data['action'] = wp_json_encode( $data['action'] );
		}

		$response = BWFAN_Model_Automation_Step::update_automation_step_data( $step_id, $data );
		if ( $response && true === $update_async ) {
			/** Update execution time of automation if delay time changed */
			if ( 1 === absint( $step_data['type'] ) ) {
				$this->update_automation_execution_time( $step_id );
			}

			if ( 3 === absint( $step_data['type'] ) ) {
				/** Update active automation if goal step setting changed */
				$this->update_active_automations( $step_id, $data['data'] );
			}
		}

		return $response;
	}

	public function update_automation_execution_time( $step_id ) {
		$automations = BWFAN_Model_Automation_Contact::get_automation_contact_by_sid( $step_id );

		if ( empty( $automations ) ) {
			return;
		}

		$automation_contact_ids = array_column( $automations, 'ID' );
		if ( empty( $automation_contact_ids ) ) {
			return;
		}

		$key  = 'bwf_delay_automations_' . $step_id;
		$args = [ 'sid' => $step_id ];

		/** Un-schedule */
		if ( bwf_has_action_scheduled( 'bwfan_delay_step_updated', $args ) ) {
			bwf_unschedule_actions( 'bwfan_delay_step_updated', $args );
		}
		delete_option( $key );

		/** Schedule the action and data */
		bwf_schedule_recurring_action( time(), 60, 'bwfan_delay_step_updated', $args );
		update_option( $key, $automation_contact_ids, false );
	}

	public function update_active_automations( $step_id, $goal_settings ) {
		$settings               = json_decode( $goal_settings, true );
		$goal_run               = isset( $settings['sidebarData']['bwfan_goal_run'] ) ? $settings['sidebarData']['bwfan_goal_run'] : 'wait';
		$automations            = BWFAN_Model_Automation_Contact::get_automation_contact_by_sid( $step_id, 'goal' );
		$automation_contact_ids = empty( $automations ) ? [] : array_column( $automations, 'ID' );

		$key  = 'bwf_goal_automations_' . $step_id;
		$args = [ 'sid' => $step_id, 'goal_run' => $goal_run ];

		/** Un-schedule */
		if ( bwf_has_action_scheduled( 'bwfan_goal_step_updated', $args ) ) {
			bwf_unschedule_actions( 'bwfan_goal_step_updated', $args );
		}
		delete_option( $key );

		/** Schedule the action and data */
		bwf_schedule_recurring_action( time(), 60, 'bwfan_goal_step_updated', $args );
		update_option( $key, $automation_contact_ids, false );
	}

	/**
	 * Delete automation step
	 *
	 * @param $step_id
	 *
	 * @return bool
	 */
	public function delete_automation_step( $step_id ) {

		if ( ! intval( $step_id ) > 0 ) {
			return false;
		}

		/** Scope by automation id so a stray step id can never touch another automation's row */
		return ! ! BWFAN_Model_Automation_Step::update( [ 'status' => 3 ], [
			'id'  => absint( $step_id ),
			'aid' => absint( $this->automation_id ),
		] );
	}

	/**
	 * Change the status of action automation
	 *
	 * @param $to_status
	 * @param $id
	 *
	 * @return bool
	 */
	public function change_automation_status( $to_status, $id ) {
		if ( empty( $to_status ) ) {
			return false;
		}
		$current_time = current_time( 'timestamp', 1 );
		$data         = array(
			'status'    => $to_status,
			'last_time' => $current_time
		);

		/** If status is run now then set current time in execution time */
		if ( 'now' === $to_status ) {
			$data['e_time'] = $current_time;
			unset( $data['status'] );
		}

		$response = BWFAN_Model_Automation_Contact::update( $data, array(
			'ID' => $id
		) );

		return $response;
	}

	public function delete_migrations( $aid ) {
		BWFAN_Model_Automation_Step::delete_steps_by_aid( $aid );
		BWFAN_Model_Automation_Contact::delete_automation_contact_by_aid( $aid );
		BWFAN_Model_Automation_Complete_Contact::delete_automation_contact_by_aid( $aid );
		BWFAN_Model_Automation_Contact_Trail::delete_automation_trail_by_id( $aid );
	}


	/**
	 * Get step's merger point
	 *
	 * @param $step_id
	 * @param $links
	 * @param $steps
	 * @param array $step_iteration
	 *
	 * @return int|mixed
	 */
	public function get_steps_merger_point( $step_id, $links, $steps, $step_iteration = [] ) {
		$split_path_ins            = $this->get_split_path_traversal_instance( $step_id, $links, $steps, $step_iteration );
		$split_path_ins->get_nodes = true;

		return $split_path_ins->get_steps_all_node( $split_path_ins->current_node_id );
	}

	/**
	 * Get all steps of split
	 *
	 * @param $step_id
	 * @param $links
	 * @param $steps
	 * @param $step_iteration
	 *
	 * @return array
	 */
	public function get_all_steps_of_split( $step_id, $links, $steps, $step_iteration = [] ) {
		$split_path_ins            = $this->get_split_path_traversal_instance( $step_id, $links, $steps, $step_iteration );
		$split_path_ins->get_nodes = false;

		return $split_path_ins->get_steps_all_node( $split_path_ins->current_node_id );
	}

	/**
	 * @param $step_id
	 * @param $links
	 * @param $steps
	 * @param array $step_iteration
	 *
	 * @return BWFAN_Split_Path_Traversal_Controller
	 */
	public function get_split_path_traversal_instance( $step_id, $links, $steps, $step_iteration = [] ) {
		$this->split_path_ins = is_null( $this->split_path_ins ) ? new BWFAN_Split_Path_Traversal_Controller() : $this->split_path_ins;
		$split_path_ins       = $this->split_path_ins;
		$node_id              = $this->get_steps_node_id( $step_id, $steps );

		$split_path_ins->current_node_id = $node_id;
		$split_path_ins->automation_id   = $this->automation_id;
		$split_path_ins->db_links        = $links;
		$split_path_ins->step_iteration  = $step_iteration;
		$split_path_ins->set_steps( $steps );

		return $split_path_ins;
	}

	/**
	 * Get node id by step id
	 *
	 * @param $step_id
	 * @param $steps
	 *
	 * @return int|mixed
	 */
	public function get_steps_node_id( $step_id, $steps = [] ) {
		if ( empty( $steps ) ) {
			$meta  = $this->get_automation_meta_data();
			$steps = isset( $meta['steps'] ) ? $meta['steps'] : [];
		}

		if ( empty( $steps ) ) {
			return 0;
		}

		$node_id = 0;

		foreach ( $steps as $step ) {
			if ( ! isset( $step['stepId'] ) || ! isset( $step['id'] ) ) {
				continue;
			}

			if ( intval( $step_id ) === intval( $step['stepId'] ) ) {
				$node_id = $step['id'];
			}
		}

		return $node_id;
	}

	/**
	 * Get split & conditional steps merger points and split's steps
	 *
	 * @param $steps
	 * @param $links
	 * @param $iteration_array
	 *
	 * @return array
	 */
	public function get_all_steps_merger_point( $steps, $links, $iteration_array = [] ) {
		if ( empty( $steps ) ) {
			return [];
		}
		$merger_points = [];
		$split_steps   = [];
		foreach ( $steps as $step ) {
			if ( ! isset( $step['stepId'] ) || ! in_array( $step['type'], [ 'split', 'conditional', 'branches' ], true ) ) {
				continue;
			}

			$merger_or_steps              = $this->get_steps_merger_point( $step['stepId'], $links, $steps, $iteration_array );
			$merger_points[ $step['id'] ] = $merger_or_steps['merger_points'];
			if ( in_array( $step['type'], [ 'conditional', 'branches' ], true ) ) {
				continue;
			}
			$split_steps[ $step['stepId'] ] = $merger_or_steps['split_steps'];
		}

		return [
			'merger_points' => $merger_points,
			'split_steps'   => $split_steps,
		];
	}

	/**
	 * Get all automation step
	 *
	 * @return array
	 */
	public function get_steps() {
		$meta  = $this->get_automation_meta_data();
		$steps = isset( $meta['steps'] ) ? $meta['steps'] : [];

		if ( empty( $steps ) ) {
			return [];
		}

		$formatted_steps = [];
		foreach ( $steps as $step ) {
			$step_id = isset( $step['stepId'] ) ? $step['stepId'] : $step['id'];
			if ( empty( $step_id ) ) {
				continue;
			}
			$formatted_steps[ $step_id ] = $step;
		}

		return $formatted_steps;
	}

	/**
	 * Get single step from step meta
	 *
	 * @param $step_id
	 * @param $steps
	 *
	 * @return int|mixed
	 */
	public function get_single_step( $step_id, $steps = [] ) {
		if ( empty( $steps ) ) {
			$meta  = $this->get_automation_meta_data();
			$steps = isset( $meta['steps'] ) ? $meta['steps'] : [];
		}

		if ( empty( $steps ) ) {
			return [];
		}

		$steps = array_filter( $steps, function ( $step ) use ( $step_id ) {
			return ( ( isset( $step['id'] ) && $step_id === $step['id'] ) || ( isset( $step['stepId'] ) && $step_id === $step['stepId'] ) );
		} );
		sort( $steps );

		return isset( $steps[0] ) ? $steps[0] : [];
	}

	/**
	 * unsetting cached instance(s) for automation
	 *
	 * A new instance reads its row through BWFAN_Model_Automations_V2, whose
	 * per-request cache would hand back the row as it was before the write
	 * that made the caller forget the instance; so that group goes too.
	 *
	 * @param int $id Automation to forget, or 0 for all of them.
	 *
	 * @return void
	 */
	public static function unset_instance( $id = 0 ) {
		if ( class_exists( 'WooFunnels_Cache' ) ) {
			WooFunnels_Cache::get_instance()->reset_cache( 'fka-automation' );
		}

		$id = intval( $id );
		if ( $id > 0 ) {
			unset( self::$ins[ $id ] );

			return;
		}
		self::$ins = [];
	}

	/**
	 * What a builder save can overwrite, hashed, so a save or an MCP edit made
	 * from an older read can be refused (409 / fkmcp_stale).
	 *
	 * Direct SQL on purpose: self::$ins and WooFunnels_Cache go stale inside a
	 * request that has just written. Left out because they change without an edit,
	 * or are written outside the builder: status and category (toggle, list
	 * screen), updated_at (changes on a no-op save), the webhook sample keys the
	 * receiver rewrites on every hit, and a split's runtime last_run.
	 *
	 * @param int        $aid       Automation id.
	 * @param array|null $step_rows Rows (ID, status, action, data) the caller already
	 *                              loaded, any order, deleted ones allowed; saves a query.
	 *
	 * @return string md5, or '' when the automation doesn't exist.
	 */
	public static function get_revision( $aid, $step_rows = null ) {
		global $wpdb;
		$aid  = absint( $aid );
		$main = $wpdb->get_row( $wpdb->prepare( "SELECT `source`, `event`, `priority`, `start`, `v`, `benchmark`, `title` FROM {$wpdb->prefix}bwfan_automations WHERE `ID` = %d", $aid ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $main ) ) {
			return '';
		}

		$meta = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT `meta_key`, `meta_value` FROM {$wpdb->prefix}bwfan_automationmeta WHERE `bwfan_automation_id` = %d AND `meta_key` IN ( 'steps', 'links', 'count', 'event_meta' ) ORDER BY `ID` ASC", $aid ), ARRAY_A ) as $r ) { //phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$value = (string) $r['meta_value'];
			if ( 'event_meta' === $r['meta_key'] ) {
				$em = maybe_unserialize( $value );
				if ( is_array( $em ) ) {
					unset( $em['webhook_data'], $em['received_at'], $em['referer'] );
					$value = maybe_serialize( $em );
				}
			}
			$meta[ $r['meta_key'] ] = $value;
		}
		ksort( $meta );

		if ( null === $step_rows ) {
			$step_rows = $wpdb->get_results( $wpdb->prepare( "SELECT `ID`, `status`, `action`, `data` FROM {$wpdb->prefix}bwfan_automation_step WHERE `aid` = %d AND `status` NOT IN ( 3, 4 )", $aid ), ARRAY_A ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$rows = array();
		foreach ( (array) $step_rows as $r ) {
			if ( ! is_array( $r ) || in_array( (int) $r['status'], array( 3, 4 ), true ) ) {
				continue;
			}
			$data = (string) $r['data'];
			if ( false !== strpos( $data, '"last_run"' ) ) {
				$d = json_decode( $data, true );
				if ( is_array( $d ) && isset( $d['sidebarData'] ) && is_array( $d['sidebarData'] ) ) {
					unset( $d['sidebarData']['last_run'] );
					$data = wp_json_encode( $d );
				}
			}
			$rows[ absint( $r['ID'] ) ] = array( (string) $r['status'], (string) $r['action'], $data );
		}
		ksort( $rows );

		return md5( (string) wp_json_encode( array( $main, $meta, $rows ) ) );
	}

	/**
	 * The edit lock an MCP update ($wait 0) and a builder save that sent a revision
	 * ($wait 3) share, so neither can write between the other's check and write.
	 * Per connection: released by edit_unlock(), or when the connection closes.
	 */
	public static function edit_lock( $aid, $wait = 0 ) {
		global $wpdb;
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters(); // HyperDB / LudicrousDB: lock and release on the same server.
		}

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::EDIT_LOCK_PREFIX . absint( $aid ), absint( $wait ) ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A builder write's revision check (spec §17), shared by Lite's routes and Pro's.
	 * No revision sent (older callers): true, unchanged behaviour. Otherwise takes the
	 * edit lock for the rest of the request (released on shutdown) and compares.
	 *
	 * @param int   $aid  Automation id.
	 * @param mixed $sent The revision the builder sent.
	 *
	 * @return bool false when the caller must answer 409.
	 */
	public static function check_revision( $aid, $sent ) {
		if ( ! is_string( $sent ) || '' === $sent ) {
			return true;
		}

		$aid = absint( $aid );
		if ( ! self::edit_lock( $aid, 3 ) ) {
			return false;
		}
		add_action( 'shutdown', function () use ( $aid ) {
			self::edit_unlock( $aid );
		} );

		return hash_equals( self::get_revision( $aid ), $sent );
	}

	public static function edit_unlock( $aid ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::EDIT_LOCK_PREFIX . absint( $aid ) ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

BWFAN_Core::register( 'automations_v2', 'BWFAN_Automation_V2' );
