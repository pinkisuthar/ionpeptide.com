<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class BWFAN_Load_Integrations
 * @package Autonami
 * @author XlPlugins
 */
#[AllowDynamicProperties]
class BWFAN_Load_Integrations {
	/**
	 * Saves all the main integration's object
	 * @var array
	 */
	private static $integrations = array();
	/**
	 * Saves all the action's object
	 * @var array
	 */
	private static $available_actions = array();

	/**
	 * Slugs whose file loaded this request and declined to register, because
	 * the integration plugin they guard on is inactive. Nine getters call
	 * load_all_actions(), which re-walks every index key, so without this memo
	 * each of them re-stats and re-requires every guard-declined file.
	 * Deliberately holds only permanent failures — see materialize().
	 */
	private static $unresolvable_actions = array();
	private static $integration_actions = array();

	private static $integration_localize_data = array();
	private static $integration_actions_localize_data = array();
	private static $action_localize_data = array();
	private static $ins = null;
	private static $dynamic_register_actions = [
		'list' => []
	];
	/**
	 * Lazily resolvable actions, from integrations that declare $action_map.
	 * slug => [ dir, integration_slug, filename, calls_after ]
	 *
	 * Keyed by slug; each entry carries its own directory. That is what keeps
	 * the wc_create_coupon / pro_wc_create_coupon filename collision straight:
	 * distinct slugs, same integration, same filename, different plugins.
	 *
	 * Never persisted — the declarations live in integration classes that
	 * load on every request anyway.
	 *
	 * @var array
	 */
	private static $action_index = [];

	/**
	 * BWFAN_Load_Integrations constructor.
	 */
	protected function __construct() {
	}

	/**
	 * Return the object of current class
	 *
	 * @return null|BWFAN_Load_Integrations
	 */
	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	/**
	 * Register the integration when the integration file is included
	 *
	 * @param $class
	 */
	public static function register( $class ) {
		if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
			$temp_integration = $class::get_instance();
			if ( $temp_integration instanceof BWFAN_Integration ) {
				$connector_slug = $temp_integration->get_connector_slug();

				/**
				 * If a connector
				 */
				if ( ! empty( $connector_slug ) ) {
					$all_connectors = WFCO_Load_Connectors::get_all_connectors();

					if ( empty( $all_connectors ) ) {
						return;
					}
					$saved_connectors = WFCO_Common::$connectors_saved_data;

					if ( empty( $saved_connectors ) ) {
						/** One time fetching from database is required */
						WFCO_Common::get_connectors_data();
						$saved_connectors = WFCO_Common::$connectors_saved_data;
					}

					if ( empty( $saved_connectors ) ) {
						/** If still no saved connectors, then return */
						return;
					}

					if ( isset( $all_connectors[ $connector_slug ] ) && ! isset( $saved_connectors[ $connector_slug ] ) ) {
						return;
					}
				}

				$slug                                     = $temp_integration->get_slug();
				self::$integrations[ $slug ]              = $temp_integration;
				self::$integration_localize_data[ $slug ] = $temp_integration->get_localize_data();
				$temp_integration->load_actions();
				$group_slug = $temp_integration->get_group_slug();
				if ( ! isset( self::$dynamic_register_actions['group'][ $group_slug ] ) ) {
					self::$dynamic_register_actions['group'][ $group_slug ] = [
						'group_slug' => $group_slug,
						'label'      => $temp_integration->get_group_name(),
						'subgroup'   => [ $slug ],
					];
				} elseif ( isset( self::$dynamic_register_actions['group'][ $group_slug ] ) && ! in_array( $slug, self::$dynamic_register_actions['group'][ $group_slug ]['subgroup'] ) ) {
					self::$dynamic_register_actions['group'][ $group_slug ]['subgroup'][] = $slug;
				}
			}
		}
	}

	/**
	 * Register a declared map of lazily loadable actions.
	 *
	 * @param string $integration_slug integration the actions belong to
	 * @param string $dir              absolute path to the actions directory
	 * @param array  $map              slug => filename
	 * @param bool   $calls_after      whether do_after_action_registration() runs
	 */
	public static function register_lazy_actions( $integration_slug, $dir, array $map, $calls_after = true ) {
		foreach ( $map as $slug => $filename ) {
			self::$action_index[ $slug ] = [ $dir, $integration_slug, $filename, (bool) $calls_after ];
		}
	}

	/**
	 * Load the one file behind an indexed slug.
	 *
	 * The work happens on the owning integration instance —
	 * do_after_action_registration() is protected, so only BWFAN_Integration
	 * itself may call it, and $this-> there dispatches polymorphically to the
	 * connector overrides.
	 *
	 * @param string $slug
	 *
	 * @return BWFAN_Action|null
	 */
	public static function materialize( $slug ) {
		if ( isset( self::$available_actions[ $slug ] ) ) {
			return self::$available_actions[ $slug ];
		}

		/** Settled earlier this request: the file loads and declines to register. */
		if ( isset( self::$unresolvable_actions[ $slug ] ) ) {
			return null;
		}

		if ( ! isset( self::$action_index[ $slug ] ) ) {
			return null;
		}

		list( $dir, $integration_slug, $filename, $calls_after ) = self::$action_index[ $slug ];

		$integration = isset( self::$integrations[ $integration_slug ] ) ? self::$integrations[ $integration_slug ] : null;
		if ( ! $integration instanceof BWFAN_Integration ) {
			/** Not memoized: an integration may still register later this request. */
			return null;
		}

		$action_obj = $integration->materialize_action( $dir, $filename, $calls_after );
		if ( $action_obj instanceof BWFAN_Action ) {
			return $action_obj;
		}

		/**
		 * require_once returns bool true for a file some other path already
		 * loaded; if that path also registered the action, hand it back.
		 */
		if ( isset( self::$available_actions[ $slug ] ) ) {
			return self::$available_actions[ $slug ];
		}

		/**
		 * null: the file loaded and its guard declined (integration plugin
		 * inactive) — permanent for this request, so stop re-attempting it.
		 * false: the file could not be read, which is transient; an update
		 * swapping files must not disable the action for the whole request.
		 */
		if ( null === $action_obj ) {
			self::$unresolvable_actions[ $slug ] = true;
		}

		return null;
	}

	/**
	 * Materialize every indexed action, in insertion order — integration
	 * registration order × filename order, which is today's glob order.
	 * Only for callers that explicitly want everything; never from get_action().
	 */
	public static function load_all_actions() {
		foreach ( array_keys( self::$action_index ) as $slug ) {
			if ( ! isset( self::$available_actions[ $slug ] ) ) {
				self::materialize( $slug );
			}
		}
	}

	/**
	 * Register every action when action file is included
	 *
	 * @param $action_obj BWFAN_Action
	 */
	public static function register_actions( BWFAN_Action $action_obj ) {
		if ( method_exists( $action_obj, 'get_instance' ) ) {
			$slug                                                                  = $action_obj->get_slug();
			$integration_type                                                      = $action_obj->get_integration_type();
			$localized_data                                                        = $action_obj->get_localize_data();
			self::$available_actions[ $slug ]                                      = $action_obj;
			self::$integration_actions[ $integration_type ][ $slug ]               = $action_obj;
			self::$action_localize_data[ $slug ]                                   = $localized_data;
			self::$integration_actions_localize_data[ $integration_type ][ $slug ] = $localized_data;

			$integration = BWFAN_Core()->integration->get_integration( $integration_type );
			if ( $action_obj->support_v2 && $integration instanceof BWFAN_Integration ) {
				self::$dynamic_register_actions['list'][ $slug ]                                               = [
					'action_name' => $action_obj->get_name(),
					'lock'        => true,
					'isLite'      => isset( self::lite_actions()[ $slug ] ),
				];
				self::$dynamic_register_actions['subgroup'][ $integration_type ][ $integration->get_name() ][] = $slug;
				/**
				 * No append into ['group'][ $integration_type ]['subgroup'].
				 *
				 * That isset() compares an ACTION's integration slug against a
				 * key built from some integration's GROUP slug - two unrelated
				 * namespaces that only ever match by accident. When they do, an
				 * action slug lands in a list that holds integration slugs, and
				 * BWFAN_Automation_V2::merge_group_data() renders it as a
				 * phantom subgroup in the v2 action picker. Observed live:
				 * group 'wp' (created by Pro's BWFAN_WP_Adv_Integration) picking
				 * up Lite's 'wp_sendemail', and group 'wc' (Lite's) picking up
				 * Pro's six WC actions.
				 *
				 * It never fired before actions resolved lazily: register()
				 * calls load_actions() BEFORE it creates the group entry, so the
				 * isset() was always false at eager-registration time.
				 */
			}
		}
	}

	/**
	 * Returns all action available
	 *
	 * @return array
	 */
	public static function get_all_action_list() {
		return apply_filters( 'bwfan_all_action_list', [
			'automation_end'                       => __( 'End Automation', 'wp-marketing-automations' ),
			'crm_add_contact_note'                 => __( 'Add Contact Note', 'wp-marketing-automations' ),
			'crm_add_tag'                          => __( 'Add Tags', 'wp-marketing-automations' ),
			'crm_add_to_list'                      => __( 'Add Contact to List', 'wp-marketing-automations' ),
			'crm_change_contact_status'            => __( 'Change Status', 'wp-marketing-automations' ),
			'crm_create_contact'                   => __( 'Create Contact', 'wp-marketing-automations' ),
			'crm_rmv_from_list'                    => __( 'Remove Contact From List', 'wp-marketing-automations' ),
			'crm_rmv_tag'                          => __( 'Remove Tags', 'wp-marketing-automations' ),
			'crm_update_customfields'              => __( 'Update Fields', 'wp-marketing-automations' ),
			'wp_createuser'                        => __( 'Create User', 'wp-marketing-automations' ),
			'wp_custom_callback'                   => __( 'Custom Callback', 'wp-marketing-automations' ),
			'wp_debug'                             => __( 'Debug', 'wp-marketing-automations' ),
			'wp_http_post'                         => __( 'Send Data To Any Source (HTTP Request)', 'wp-marketing-automations' ),
			'wp_update_user_meta'                  => __( 'Update User Meta', 'wp-marketing-automations' ),
			'wp_update_user_role'                  => __( 'Update User Role', 'wp-marketing-automations' ),
			'za_send_data'                         => __( 'Send Data To Zapier', 'wp-marketing-automations' ),
			'pro_wc_create_coupon'                 => __( 'Create Coupon', 'wp-marketing-automations' ),
			'wc_remove_coupon'                     => __( 'Delete Coupon', 'wp-marketing-automations' ),
			'wp_sendemail'                         => __( 'Send Email', 'wp-marketing-automations' ),
			'ac_add_tag'                           => __( 'Add Tags', 'wp-marketing-automations' ),
			'ac_add_to_automation'                 => __( 'Add Contact To Automation', 'wp-marketing-automations' ),
			'ac_add_to_list'                       => __( 'Add Contact To List', 'wp-marketing-automations' ),
			'ac_create_abandoned_cart'             => __( 'Create Abandoned Cart', 'wp-marketing-automations' ),
			'ac_create_contact'                    => __( 'Create Contact', 'wp-marketing-automations' ),
			'ac_rmv_from_automation'               => __( 'Remove Contact From Automation', 'wp-marketing-automations' ),
			'ac_rmv_from_list'                     => __( 'Remove Contact From List', 'wp-marketing-automations' ),
			'ac_rmv_tag'                           => __( 'Remove Tags', 'wp-marketing-automations' ),
			'ac_update_customfields'               => __( 'Update Fields', 'wp-marketing-automations' ),
			'mailchimp_add_cart'                   => __( 'Add Abandoned Cart', 'wp-marketing-automations' ),
			'mailchimp_add_tags'                   => __( 'Add Tags', 'wp-marketing-automations' ),
			'mailchimp_add_to_automation'          => __( 'Add Contact to Automation', 'wp-marketing-automations' ),
			'mailchimp_add_to_list'                => __( 'Add Contact to List', 'wp-marketing-automations' ),
			'mailchimp_remove_from_automation'     => __( 'Remove Contact from Automation', 'wp-marketing-automations' ),
			'mailchimp_remove_from_list'           => __( 'Remove Contact from List', 'wp-marketing-automations' ),
			'mailchimp_remove_tags'                => __( 'Remove Tags', 'wp-marketing-automations' ),
			'mailchimp_update_custom_fields'       => __( 'Update Custom (Merge) Fields', 'wp-marketing-automations' ),
			'hubspot_add_contact_to_list'          => __( 'Add Contact to List', 'wp-marketing-automations' ),
			'hubspot_add_contact_to_workflow'      => __( 'Add Contact to Workflow', 'wp-marketing-automations' ),
			'hubspot_create_contact'               => __( 'Create Contact', 'wp-marketing-automations' ),
			'hubspot_remove_contact_from_list'     => __( 'Remove Contact from List', 'wp-marketing-automations' ),
			'hubspot_remove_contact_from_workflow' => __( 'Remove Contact from Workflow', 'wp-marketing-automations' ),
			'hubspot_update_contact'               => __( 'Update Contact', 'wp-marketing-automations' ),
			'twilio_send_sms'                      => __( 'Send SMS', 'wp-marketing-automations' ),
			'wcm_delete_membership'                => __( 'Remove From Membership Plan', 'wp-marketing-automations' ),
			'wcm_update_plan'                      => __( 'Assign / Update Membership Plan', 'wp-marketing-automations' ),
			'gr_add_tags'                          => __( 'Add Tags', 'wp-marketing-automations' ),
			'gr_add_to_list'                       => __( 'Add to List', 'wp-marketing-automations' ),
			'gr_create_contact'                    => __( 'Create Contact', 'wp-marketing-automations' ),
			'gr_remove_tags'                       => __( 'Remove Tag', 'wp-marketing-automations' ),
			'gr_update_custom_fields'              => __( 'Update Custom Field', 'wp-marketing-automations' ),
			'gr_remove_from_list'                  => __( 'Remove from List', 'wp-marketing-automations' ),
			'ontraport_add_tags'                   => __( 'Add Tag', 'wp-marketing-automations' ),
			'ontraport_add_to_campaign'            => __( 'Add to Campaign', 'wp-marketing-automations' ),
			'ontraport_create_contact'             => __( 'Create Contact', 'wp-marketing-automations' ),
			'ontraport_rmv_from_campaign'          => __( 'Remove from Campaign', 'wp-marketing-automations' ),
			'ontraport_rmv_tag'                    => __( 'Remove Tag', 'wp-marketing-automations' ),
			'ontraport_update_contact_fields'      => __( 'Update Contact Field', 'wp-marketing-automations' ),
			'klaviyo_add_to_list'                  => __( 'Add to List', 'wp-marketing-automations' ),
			'klaviyo_remove_from_list'             => __( 'Remove from List', 'wp-marketing-automations' ),
			'klaviyo_update_profile_fields'        => __( 'Update Profile Field', 'wp-marketing-automations' ),
			'ck_add_customfields'                  => __( 'Add Custom Field', 'wp-marketing-automations' ),
			'ck_add_tags'                          => __( 'Add Tag', 'wp-marketing-automations' ),
			'ck_add_to_sequence'                   => __( 'Add Sequence', 'wp-marketing-automations' ),
			'ck_rmv_tags'                          => __( 'Remove Tag', 'wp-marketing-automations' ),
			'bulkgate_send_transactional_sms'      => __( 'Send SMS', 'wp-marketing-automations' ),
			'ac_create_order'                      => __( 'Create Order', 'wp-marketing-automations' ),
			'ac_create_deal'                       => __( 'Create Deal', 'wp-marketing-automations' ),
			'ac_create_deal_note'                  => __( 'Create Deal Note', 'wp-marketing-automations' ),
			'ac_update_deal'                       => __( 'Update Deal', 'wp-marketing-automations' ),
			'wlm_add_user_to_pay_per_post'         => __( 'Add User to Pay Per Post', 'wp-marketing-automations' ),
			'wlm_remove_user_from_pay_per_post'    => __( 'Remove User from Pay Per Post', 'wp-marketing-automations' ),
			'wlm_user_add_level'                   => __( 'Add User to Level', 'wp-marketing-automations' ),
			'wlm_user_cancel_level'                => __( 'Cancel User Level', 'wp-marketing-automations' ),
			'wlm_user_move_level'                  => __( 'Move User to Level', 'wp-marketing-automations' ),
			'wlm_user_remove_level'                => __( 'Remove User from Level', 'wp-marketing-automations' ),
			'ld_add_user_to_group'                 => __( 'Add User to Group', 'wp-marketing-automations' ),
			'ld_enroll_user_into_course'           => __( 'Enroll User into Course(s)', 'wp-marketing-automations' ),
			'ld_remove_user_from_course'           => __( 'Remove User from Course(s)', 'wp-marketing-automations' ),
			'ld_add_user_from_group'               => __( 'Remove User from Group', 'wp-marketing-automations' ),
			'ld_reset_course_progress'             => __( 'Reset Course Progress', 'wp-marketing-automations' ),
			'ld_reset_quiz_attempts'               => __( 'Reset Quiz Attempts', 'wp-marketing-automations' ),
			'gs_delete_data'                       => __( 'Delete Row', 'wp-marketing-automations' ),
			'gs_insert_data'                       => __( 'Insert Row', 'wp-marketing-automations' ),
			'gs_update_data'                       => __( 'Update Row', 'wp-marketing-automations' ),
			'sl_message'                           => __( 'Sends a message to a channel', 'wp-marketing-automations' ),
			'sl_message_user'                      => __( 'Sends a message to a user', 'wp-marketing-automations' ),
		] );
	}

	/**
	 * @param string $type
	 *
	 * Return integration object
	 *
	 * @return BWFAN_Integration|null
	 */
	public function get_integration( $type = 'wp' ) {
		return isset( self::$integrations[ $type ] ) ? self::$integrations[ $type ] : null;
	}

	/**
	 * Get available action based on event
	 */
	public static function get_available_actions( $evt = '' ) {
		if ( empty( $evt ) ) {
			return [];
		}
		self::load_all_actions();

		$filtered_action = [];
		$all_actions     = self::$available_actions;

		foreach ( $all_actions as $action ) {
			$action_data                         = $action->get_action_data_for_api();
			$action_data['integration_nicename'] = isset( $action_data['integration_slug'] ) && isset( self::$integration_localize_data[ $action_data['integration_slug'] ] ) ? self::$integration_localize_data[ $action_data['integration_slug'] ]['nice_name'] : '';
			if ( ! empty( $action_data ) && ! empty( $action_data['integration_nicename'] ) && $action_data['support_v2'] ) {
				$include = true;
				if ( ! empty( $action_data['included_events'] ) && ! in_array( $evt, $action_data['included_events'] ) ) {
					$include = false;
				} else if ( ! empty( $action_data['excluded_events'] ) && in_array( $evt, $action_data['excluded_events'] ) ) {
					$include = false;
				}

				if ( $include ) {
					$filtered_action[ $action_data['slug'] ] = $action_data;
				}
			}
		}

		$all_actions       = self::get_all_action_list();
		$all_actions_array = [];
		foreach ( $all_actions as $action_slug => $action_label ) {
			if ( isset( $filtered_action[ $action_slug ] ) ) {
				$all_actions_array[ $action_slug ] = $filtered_action[ $action_slug ];
			} else {
				$all_actions_array[ $action_slug ] = [
					'action_name' => $action_label,
					'lock'        => true,
					'isLite'      => isset( self::lite_actions()[ $action_slug ] ),
				];
			}
			unset( $filtered_action[ $action_slug ] );
		}

		if ( ! empty( $filtered_action ) ) {
			$all_actions_array = array_merge( $all_actions_array, $filtered_action );
		}

		return $all_actions_array;
	}

	/**
	 * Return all available action registered which register by their integration
	 * @return array
	 */
	public function get_actions( $slug = '' ) {
		self::load_all_actions();

		return self::$available_actions;
	}

	/**
	 * Return all the integrations with instance
	 *
	 * @return array
	 */
	public function get_integrations() {
		return self::$integrations;
	}

	/**
	 * @param string $slug
	 *
	 * Return action object
	 *
	 * @return BWFAN_Action|null
	 */
	public function get_action( $slug = 'wp' ) {
		/**
		 * $available_actions first, index second. Load-bearing order: old Pro's
		 * WC loader and any third-party copy of the old eager loop register
		 * directly into $available_actions and never appear in the index.
		 */
		if ( isset( self::$available_actions[ $slug ] ) ) {
			return self::$available_actions[ $slug ];
		}

		if ( isset( self::$action_index[ $slug ] ) ) {
			return self::materialize( $slug );
		}

		return null;
	}

	public function get_integration_localize_data( $type = '' ) {
		if ( '' !== $type ) {
			return isset( self::$integration_localize_data[ $type ] ) ? self::$integration_localize_data[ $type ] : [];
		}

		return self::$integration_localize_data;
	}

	/**
	 * Array
	 * (
	 * [wc_add_order_note] => wc
	 * [wc_create_coupon] => wc
	 * [wc_remove_coupon] => wc
	 * [wp_custom_callback] => wp
	 * [wp_debug] => wp
	 * [wp_http_post] => wp
	 * [wp_sendemail] => wp
	 * )
	 * @return array
	 */
	public function get_mapped_arr_action_with_integration() {
		self::load_all_actions();
		$actions  = self::$available_actions;
		$map_data = [];
		if ( count( $actions ) > 0 ) {
			foreach ( $actions as $action ) {
				$type              = $action->get_integration_type();
				$slug              = $action->get_slug();
				$map_data[ $slug ] = $type;

			}
		}

		return $map_data;
	}

	/**
	 *
	 *
	 * @return array
	 */
	public function get_mapped_arr_integration_name_with_action_name() {
		self::load_all_actions();
		$integrations = self::get_all_integrations();
		$data         = [];
		if ( count( $integrations ) > 0 ) {
			foreach ( $integrations as $slug => $actions ) {

				/**
				 * @var BWFAN_Integration $integration
				 */
				$integration = $this->get_integration( $slug );
				if ( count( $actions ) === 0 ) {
					continue;
				}
				$nice_name          = $integration->get_name();
				$data[ $nice_name ] = [];
				/**
				 * @var BWFAN_Action $action
				 */
				foreach ( $actions as $action ) {
					$data[ $nice_name ][ $action->get_slug() ] = [
						'label'     => $action->get_name(),
						'available' => 'yes',
						'priority'  => $action->get_action_priority(),
					];
				}

				uasort( $data[ $nice_name ], function ( $item1, $item2 ) {
					return ( $item1['priority'] <=> $item2['priority'] );
				} );
			}
		}

		return $data;
	}

	/**
	 * Return all the actions with group and their integrations
	 *
	 * @return array
	 */
	public static function get_all_integrations() {
		self::load_all_actions();

		return self::$integration_actions;
	}

	public function get_integration_actions_localize_data() {
		self::load_all_actions();

		return self::$integration_actions_localize_data;
	}

	public function get_actions_schemas( $event = '', $integration_actions = array() ) {
		self::load_all_actions();
		if ( empty( $integration_actions ) ) {
			/** @var BWFAN_Action[] $actions */
			$integration_actions = $this->get_actions_objs( $event );
		}

		$schemas = array();
		foreach ( $integration_actions as $integration => $actions ) {

			/** @var BWFAN_Action[] $actions */
			foreach ( $actions as $slug => $action ) {
				$final_actions[ $integration ][ $slug ] = $action->get_fields_schema();
			}
		}

		return $schemas;
	}

	public function get_actions_objs( $event = '' ) {
		self::load_all_actions();
		$integrations = self::$integration_actions;

		$final_actions = array();
		foreach ( $integrations as $integration => $actions ) {

			/** @var BWFAN_Action[] $actions */
			foreach ( $actions as $slug => $action ) {
				$included_events = $action->get_included_events();
				$excluded_events = $action->get_excluded_events();

				if ( ! empty( $included_events ) && in_array( $event, $included_events ) ) {
					$final_actions[ $integration ][ $slug ] = $action;
					continue;
				}

				if ( empty( $excluded_events ) || ! in_array( $event, $excluded_events ) ) {
					$final_actions[ $integration ][ $slug ] = $action;
				}
			}
		}

		return $final_actions;
	}

	/**
	 * Returns integration group array
	 *
	 * @return array
	 */
	public function get_integration_group() {
		return apply_filters( 'bwfan_integration_groups', [
			'messaging'  => [
				'group_slug' => 'messaging',
				'label'      => __( 'Messaging', 'wp-marketing-automations' ),
				'subgroup'   => [
					'wp',
					'whatsapp',
					'twilio',
					'bulkgate',
					'wabot',
					'slack',
				],
				'priority'   => 25,
			],
			'autonami'   => [
				'group_slug' => 'autonami',
				'label'      => __( 'Automations', 'wp-marketing-automations' ),
				'subgroup'   => [
					'autonami',
				],
				'priority'   => 15,
			],
			'wp'         => [
				'group_slug' => 'wp',
				'label'      => __( 'WordPress', 'wp-marketing-automations' ),
				'subgroup'   => [
					'wp_adv',
				],
				'priority'   => 25,
			],
			'wc'         => [
				'group_slug' => 'wc',
				'label'      => __( 'WooCommerce', 'wp-marketing-automations' ),
				'subgroup'   => [
					'wc',
					'wcs',
					'wcm',
				],
				'priority'   => 35,
			],
			'stripe'     => [
				'group_slug' => 'stripe',
				'label'      => __( 'Stripe', 'wp-marketing-automations' ),
				'subgroup'   => [
					'stripe',
				],
				'priority'   => 45,
			],
			'woofunnels' => [
				'group_slug' => 'woofunnels',
				'label'      => __( 'Funnel Builder', 'wp-marketing-automations' ),
				'subgroup'   => [
					'upstroke',
				],
				'priority'   => 8,
			],
			'crm'        => [
				'group_slug' => 'crm',
				'label'      => __( 'CRM', 'wp-marketing-automations' ),
				'subgroup'   => [
					'activecampaign',
					'convertkit',
					'mailchimp',
					'getresponse',
					'ontraport',
					'drip',
					'klaviyo',
				],
				'priority'   => 55,
			],
			'send_data'  => [
				'group_slug' => 'send_data',
				'label'      => __( 'Send Data', 'wp-marketing-automations' ),
				'subgroup'   => [
					'zapier',
				],
				'priority'   => 85,
			],
			'wlm'        => [
				'group_slug' => 'wlm',
				'label'      => __( 'Membership', 'wp-marketing-automations' ),
				'subgroup'   => [
					'wlm',
				],
				'priority'   => 60,
			],
			'lms'        => [
				'group_slug' => 'lms',
				'label'      => __( 'LMS', 'wp-marketing-automations' ),
				'subgroup'   => [
					'ld',
				],
				'priority'   => 65,
			],
			'affiliate'  => [
				'group_slug' => 'affiliate',
				'label'      => __( 'Affiliate', 'wp-marketing-automations' ),
				'subgroup'   => [
					'affwp',
				],
				'priority'   => 75,
			],
		] );
	}

	/**
	 * Returns Integration subgroups
	 *
	 * @return array
	 */
	public function get_integration_subgroups() {
		return apply_filters( 'bwfan_integration_subgroups', [
			'hubspot'        => [
				__( 'Hubspot', 'wp-marketing-automations' ) => [
					'hubspot_create_contact',
					'hubspot_update_contact',
					'hubspot_add_contact_to_list',
					'hubspot_remove_contact_from_list',
					'hubspot_add_contact_to_workflow',
					'hubspot_remove_contact_from_workflow',
				],
			],
			'autonami'       => [
				__( 'Contact', 'wp-marketing-automations' ) => [
					'crm_create_contact',
					'crm_change_contact_status',
					'crm_update_customfields',
					'crm_add_tag',
					'crm_rmv_tag',
					'crm_add_to_list',
					'crm_rmv_from_list',
					'crm_add_contact_note',
					'automation_end',
				],
			],
			'wp'             => [
				__( 'Email', 'wp-marketing-automations' ) => [
					'wp_sendemail',
				],
			],
			'wp_adv'         => [
				__( 'WordPress', 'wp-marketing-automations' ) => [
					'wp_createuser',
					'wp_update_user_meta',
					'wp_update_user_role',
					'wp_custom_callback',
					'wp_http_post',
					'wp_debug',
				],
			],
			'twilio'         => [
				__( 'Twilio', 'wp-marketing-automations' ) => [
					'twilio_send_sms',
				],
			],
			'wcm'            => [
				__( 'Membership', 'wp-marketing-automations' ) => [
					'wcm_update_plan',
					'wcm_delete_membership',
				],
			],
			'wcs'            => [
				__( 'Subscriptions', 'wp-marketing-automations' ) => [
					'wcs_change_subscription_status',
					'wcs_cancel_order_subscriptions',
					'wcs_add_note',
					'wcs_add_coupon',
					'wcs_remove_coupon',
					'wcs_send_subscription_invoice',
				],
			],
			'wc'             => [
				__( 'WooCommerce', 'wp-marketing-automations' ) => [
					'pro_wc_create_coupon',
					'wc_remove_coupon',
				],
			],
			'getresponse'    => [
				__( 'Get Response', 'wp-marketing-automations' ) => [
					'gr_create_contact',
					'gr_update_custom_fields',
					'gr_add_tags',
					'gr_remove_tags',
					'gr_add_to_list',
					'gr_remove_from_list',
				],
			],
			'ontraport'      => [
				__( 'Ontraport', 'wp-marketing-automations' ) => [
					'ontraport_create_contact',
					'ontraport_update_contact_fields',
					'ontraport_add_tags',
					'ontraport_rmv_tag',
					'ontraport_add_to_campaign',
					'ontraport_rmv_from_campaign',
				],
			],
			'mailchimp'      => [
				__( 'Mailchimp', 'wp-marketing-automations' ) => [
					'mailchimp_add_to_list',
					'mailchimp_remove_from_list',
					'mailchimp_add_tags',
					'mailchimp_remove_tags',
					'mailchimp_add_to_automation',
					'mailchimp_remove_from_automation',
					'mailchimp_update_custom_fields',
					'mailchimp_add_cart',
				],
			],
			'klaviyo'        => [
				__( 'Klaviyo', 'wp-marketing-automations' ) => [
					'klaviyo_add_to_list',
					'klaviyo_remove_from_list',
					'klaviyo_update_profile_fields',
				],
			],
			'convertkit'     => [
				__( 'Kit ( Formerly ConvertKit )', 'wp-marketing-automations' ) => [
					'ck_add_tags',
					'ck_rmv_tags',
					'ck_add_to_sequence',
					'ck_add_customfields',
				],
			],
			'bulkgate'       => [
				__( 'BulkGate', 'wp-marketing-automations' ) => [
					'bulkgate_send_transactional_sms',
				],
			],
			'activecampaign' => [
				__( 'ActiveCampaign', 'wp-marketing-automations' ) => [
					'ac_create_contact',
					'ac_update_customfields',
					'ac_add_tag',
					'ac_rmv_tag',
					'ac_add_to_list',
					'ac_rmv_from_list',
					'ac_add_to_automation',
					'ac_rmv_from_automation',
					'ac_create_abandoned_cart',
				],
			],
			'wlm'            => [
				__( 'WishList Member', 'wp-marketing-automations' ) => [
					'wlm_add_user_to_pay_per_post',
					'wlm_remove_user_from_pay_per_post',
					'wlm_user_add_level',
					'wlm_user_remove_level',
					'wlm_user_move_level',
					'wlm_user_cancel_level',
				],
			],
			'ld'             => [
				__( 'LearnDash', 'wp-marketing-automations' ) => [
					'ld_enroll_user_into_course',
					'ld_remove_user_from_course',
					'ld_add_user_to_group',
					'ld_add_user_from_group',
					'ld_reset_course_progress',
					'ld_reset_quiz_attempts',
				],
			],
			'google_sheets'  => [
				__( 'Google Sheets', 'wp-marketing-automations' ) => [
					'gs_insert_data',
					'gs_update_data',
					'gs_delete_data',
				],
			],
			'zapier'         => [
				__( 'General', 'wp-marketing-automations' ) => [
					'wp_http_post',
				],
				'Zapier'                                    => [
					'za_send_data',
				],
			],
			'slack'          => [
				__( 'Slack', 'wp-marketing-automations' ) => [
					'sl_message',
					'sl_message_user',
				]
			]
		] );
	}

	public function get_dynamic_action_data() {
		self::load_all_actions();

		return self::$dynamic_register_actions;
	}

	public static function lite_actions() {
		return [
			'wp_sendemail' => __( 'Send Email', 'wp-marketing-automations' ),
		];
	}
}

if ( class_exists( 'BWFAN_Load_Integrations' ) ) {
	BWFAN_Core::register( 'integration', 'BWFAN_Load_Integrations' );
}
