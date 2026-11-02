<?php
defined( 'ABSPATH' ) || exit;

/**
 * This class handled all the functions related to Rules that belongs to this plugin only
 *
 * @author BuildWooFunnels
 */
#[AllowDynamicProperties]
class BWFAN_Rules_Loader extends BWFAN_Rules {
	private static $ins = null;
	public $is_executing_rule = false;
	public $environments = array();
	public $excluded_rules = array();
	public $excluded_rules_categories = array();
	public $processed = array();
	public $record = array();
	public $skipped = array();
	public $rules_data = null;
	public $select2names = array();

	/** v2 Props */
	public $slim_data = false;
	protected $rule_types = null;

	public function __construct() {

		parent::__construct();
		add_filter( 'bwfan_rule_get_rule_types', array( $this, 'default_rule_types' ), 10 );

		add_filter( 'bwfan_admin_builder_localized_data', array( $this, 'add_rule_groups_to_js' ), 10 );
		add_filter( 'bwfan_admin_builder_localized_data', array( $this, 'add_rules_to_js' ), 11 );
		add_filter( 'bwfan_admin_builder_localized_data', array( $this, 'add_rules_ui_prev_data' ), 12 );
		add_action( 'bwfan_automation_data_set_automation', array( $this, 'maybe_set_rules_ajax_select_names' ) );
		add_filter( 'bwfan_modify_rule_class', [ $this, 'append_pruduct_taxonomy_rules' ], 10, 2 );
	}

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function get_default_rule_groups() {
		return apply_filters( 'bwfan_rules_default_groups', array() );
	}

	public function add_rule_groups_to_js( $localized_data ) {
		$localized_data['rule_groups'] = $this->get_all_groups();

		return $localized_data;
	}

	public function get_all_groups() {
		$this->load_rules_classes();

		return apply_filters( 'bwfan_rules_groups', array(
			'wc_items'            => array(
				'title' => __( 'Product', 'wp-marketing-automations' ),
			),
			'wc_order'            => array(
				'title' => __( 'Current Woo Order', 'wp-marketing-automations' ),
			),
			'wc_order_items_data' => array(
				'title' => __( 'Order Item Data', 'wp-marketing-automations' ),
			),
			'wc_order_state'      => array(
				'title' => __( 'Order Status', 'wp-marketing-automations' ),
			),
			'wc_customer'         => array(
				'title' => __( 'Contact', 'wp-marketing-automations' ),
			),
			'wp_user'             => array(
				'title' => __( 'User', 'wp-marketing-automations' ),
			),
			'automation'          => array(
				'title' => __( 'Automation', 'wp-marketing-automations' ),
			),
			'wc_comment'          => array(
				'title' => __( 'Reviews', 'wp-marketing-automations' ),
			),
			'ab_cart'             => array(
				'title' => __( 'Cart', 'wp-marketing-automations' ),
			),
			'cf7'                 => array(
				'title' => __( 'Contact Form 7', 'wp-marketing-automations' ),
			),
		) );
	}

	public function add_rules_to_js( $localized_data ) {
		$localized_data['rules'] = apply_filters( 'bwfan_rule_get_rule_types', array() );
		$v2_rules_key            = [];
		foreach ( $localized_data['rules'] as $key => $ruleset ) {
			foreach ( $ruleset as $rule_key => $rules ) { //phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis
				$get_rule_object = $this->woocommerce_bwfan_rule_get_rule_object( $rule_key );
				if ( ! $get_rule_object instanceof BWFAN_Rule_Base ) {
					continue;
				}

				if ( true === $get_rule_object->is_v1() ) {
					continue;
				}

				unset( $localized_data['rules'][ $key ][ $rule_key ] );
				$v2_rules_key[] = $key;
			}

			/** in case all the rules are for v2 in group then remove from rules */
			if ( empty( $localized_data['rules'][ $key ] ) ) {
				unset( $localized_data['rules'][ $key ] );
			}

		}
		/** @var getting all the v2 rules key to remove in rule groups $v2_rules_key */
		$v2_rules_key = array_unique( $v2_rules_key );

		foreach ( $v2_rules_key as $rule_slug ) {
			if ( ! empty( $localized_data['rules'][ $rule_slug ] ) ) {
				continue;
			}
			unset( $localized_data['rule_groups'][ $rule_slug ] );
		}

		return $localized_data;
	}

	public function default_rule_types( $types ) {
		$types = array(
			'wc_items'            => array(
				'product_item'              => __( 'Product', 'wp-marketing-automations' ),
				'product_category'          => __( 'Product Categories', 'wp-marketing-automations' ),
				'product_tags'              => __( 'Product Tags', 'wp-marketing-automations' ),
				'product_item_count'        => __( 'Product Count', 'wp-marketing-automations' ),
				'product_item_type'         => __( 'Product Type', 'wp-marketing-automations' ),
				'product_item_price'        => __( 'Product Price', 'wp-marketing-automations' ),
				'product_stock'             => __( 'Product Stock', 'wp-marketing-automations' ),
				'product_item_custom_field' => __( 'Product Custom Field', 'wp-marketing-automations' ),
				'product_item_sku'          => __( 'Product SKU', 'wp-marketing-automations' ),
			),
			'wc_order'            => array(
				'product_item'   => __( 'Ordered Products', 'wp-marketing-automations' ),
				'order_category' => __( 'Ordered Products Categories', 'wp-marketing-automations' ),
				'order_tags'     => __( 'Ordered Products Tags', 'wp-marketing-automations' ),
			),
			'wc_order_items_data' => array(
				'order_items_data' => __( 'Order Item Data', 'wp-marketing-automations' ),
			),
			'wc_order_state'      => array(
				'order_status_change' => __( 'Older Order Status', 'wp-marketing-automations' ),
			),
			'wp_user'             => array(
				'users_role' => __( 'User Role', 'wp-marketing-automations' ),
				'users_user' => __( 'User', 'wp-marketing-automations' ),
			),
			'wc_comment'          => array(
				'comment_count'         => __( 'Review Rating Count', 'wp-marketing-automations' ),
				'comment_products_cats' => __( 'Reviewed Product Categories', 'wp-marketing-automations' ),
			),
			'ab_cart'             => array(
				'cart_total'               => __( 'Cart Total', 'wp-marketing-automations' ),
				'cart_product'             => __( 'Cart Items', 'wp-marketing-automations' ),
				'cart_category'            => __( 'Cart Items Category', 'wp-marketing-automations' ),
				'cart_items_tag'           => __( 'Cart Items Tags', 'wp-marketing-automations' ),
				'cart_coupons'             => __( 'Cart Coupons', 'wp-marketing-automations' ),
				'cart_coupon_text_match'   => __( 'Cart Coupon Text', 'wp-marketing-automations' ),
				'all_cart_items_purchased' => __( 'All Cart Items Purchased (in past)', 'wp-marketing-automations' ),
				'cart_contains_coupon'     => __( 'Cart Contains Any Coupon', 'wp-marketing-automations' ),
				'cart_item_count'          => __( 'Cart Item Count', 'wp-marketing-automations' ),
				'is_global_checkout'       => __( 'Is Global Checkout', 'wp-marketing-automations' ),
			),
			'cf7'                 => array(
				'cf7_form_field' => __( 'Form Field', 'wp-marketing-automations' ),
			),
		);
		$global_settings = BWFAN_Common::get_global_settings();
		if ( ! empty( $global_settings['bwfan_user_consent'] ) ) {
			$types['ab_cart']['cart_marketing_consent'] = __( 'Marketing Consent', 'wp-marketing-automations' );
		}
		$product_taxonomies = $this->get_product_taxonomies();
		if ( ! empty( $product_taxonomies ) ) {
			$types['wc_order'] = array_merge( $types['wc_order'], $product_taxonomies );
		}

		$order_other_rules = [
			'order_total'                => __( 'Order Total', 'wp-marketing-automations' ),
			'order_sub_total'            => __( 'Order Subtotal', 'wp-marketing-automations' ),
			'order_coupons'              => __( 'Order Coupons', 'wp-marketing-automations' ),
			'order_has_coupon'           => __( 'Order Has Coupon', 'wp-marketing-automations' ),
			'order_coupon_text_match'    => __( 'Order Coupon Text', 'wp-marketing-automations' ),
			'order_payment_gateway'      => __( 'Order Payment Gateway', 'wp-marketing-automations' ),
			'order_shipping_method'      => __( 'Order Shipping Method', 'wp-marketing-automations' ),
			'order_shipping_method_zone' => __( 'Order Shipping Method Zone', 'wp-marketing-automations' ),
			'order_billing_country'      => __( 'Order Billing Country', 'wp-marketing-automations' ),
			'order_shipping_country'     => __( 'Order Shipping Country', 'wp-marketing-automations' ),
			'order_custom_field'         => __( 'Order Custom Field', 'wp-marketing-automations' ),
			'is_guest'                   => __( 'Is Guest Order', 'wp-marketing-automations' ),
			'is_first_order'             => __( 'Is First Order', 'wp-marketing-automations' ),
			'order_status'               => __( 'Order Status', 'wp-marketing-automations' ),
			'order_note_text_match'      => __( 'Order Note Text', 'wp-marketing-automations' ),
		];
		$types['wc_order'] = array_merge( $types['wc_order'], $order_other_rules );

		if ( bwfan_is_autonami_pro_active() ) {
			$types['wc_comment']['customer_reviewed_product'] = __( 'Reviewed Product', 'wp-marketing-automations' );
		}

		return $types;
	}

	public function get_product_taxonomies() {
		$taxonomies = $this->get_custom_taxonomy();
		if ( empty( $taxonomies ) ) {
			return [];
		}

		$data = [];
		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( in_array( $key, [ 'product_type', 'product_cat', 'product_tag' ], true ) ) {
				continue;
			}
			$data[ 'bwf_custom_taxonomy_' . $taxonomy->name ] = str_replace( 'Product', 'Ordered Products', $taxonomy->label );
		}

		return $data;
	}

	public function get_custom_taxonomy() {
		$args = array(
			'object_type' => array( 'product' )
		);

		return get_taxonomies( $args, '' );
	}

	/**
	 * @param $val
	 * @param $rule_type
	 *
	 * @return BWFAN_Rule_Custom_Taxonomy|mixed
	 */
	public function append_pruduct_taxonomy_rules( $val, $rule_type ) {
		$types = $this->rule_types;
		if ( is_null( $types ) ) {
			$types = apply_filters( 'bwfan_rule_get_rule_types', array() );

			$this->rule_types = $types;
		}

		if ( ! isset( $types['wc_order'] ) || ! isset( $types['wc_order'][ $rule_type ] ) || strpos( $rule_type, 'bwf_custom_taxonomy_' ) === false ) {
			return $val;
		}
		$taxonomy = str_replace( 'bwf_custom_taxonomy_', '', $rule_type );

		return new BWFAN_Rule_Custom_Taxonomy( $taxonomy, $rule_type );
	}

	public function add_rules_ui_prev_data( $localized_data ) {
		if ( ! BWFAN_Common::is_load_admin_assets( 'automation' ) ) {
			return $localized_data;
		}
		$types                          = apply_filters( 'bwfan_rule_get_rule_types', array() );
		$localized_data['rule_ui_data'] = array();

		foreach ( $types as $ruleset ) {
			foreach ( $ruleset as $key => $rules ) { //phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis
				$get_rule_object = $this->woocommerce_bwfan_rule_get_rule_object( $key );
				if ( $get_rule_object instanceof BWFAN_Rule_Base && false === $get_rule_object->is_v1() ) {
					continue;
				}

				if ( $get_rule_object instanceof BWFAN_Rule_Base && is_callable( array( $get_rule_object, 'get_ui_preview_data' ) ) ) {

					$localized_data['rule_ui_data'][ $get_rule_object->get_name() ] = $get_rule_object->get_ui_preview_data();

					if ( isset( $this->select2names[ $get_rule_object->get_name() ] ) ) {
						$localized_data['rule_ui_data'][ $get_rule_object->get_name() ] = $this->select2names[ $get_rule_object->get_name() ];
					}
				}
			}
		}

		foreach ( $localized_data['rule_ui_data'] as $rule_key => $rule_value ) {
			if ( is_array( $rule_value ) && 0 === count( $rule_value ) ) {
				$localized_data['rule_ui_data'][ $rule_key ] = new stdClass();
			}
		}

		return $localized_data;
	}

	/**
	 * @hooked over 'init'
	 * Initiate the class of each rule object so that respective filters gets registered
	 * In the __construct() of some of the rule classes exists hook that need to be initialize on load
	 */
	public function maybe_initiate_all_rules() {
		$rules_data = apply_filters( 'bwfan_rule_get_rule_types', array() );
		foreach ( $rules_data as $group_rules ) {

			foreach ( $group_rules as $rule => $title ) { //phpcs:ignore WordPressVIPMinimum.Variables.VariableAnalysis
				$this->woocommerce_bwfan_rule_get_rule_object( $rule );
			}
		}
	}

	/**
	 * @hooked over `bwfan_automation_data_set_automation`
	 * Iterate over all the conditions saved against an automation to get nice names of the IDs saved as rule data to show in ADMIN UI.
	 * It also registers this nice name data to register for the localization.
	 */
	public function maybe_set_rules_ajax_select_names() {
		$data = BWFAN_Core()->automations->get_automation_details();
		if ( isset( $data['condition'] ) && is_array( $data['condition'] ) && count( $data['condition'] ) > 0 ) {
			/** Rule classes load lazily; without them woocommerce_bwfan_rule_get_rule_object() returns null and no nice names are collected */
			$this->load_rules_classes();
			foreach ( $data['condition'] as $groups ) {
				if ( is_array( $groups ) && count( $groups ) > 0 ) {
					foreach ( $groups as $rulegroups ) {
						if ( ! is_array( $rulegroups ) ) {
							continue;
						}
						foreach ( $rulegroups as $rules ) {
							if ( ! is_array( $rules ) ) {
								continue;
							}
							foreach ( $rules as $rule ) {
								$rule_type       = is_array( $rule ) && isset( $rule['rule_type'] ) ? $rule['rule_type'] : $rule;
								$get_rule_object = $this->woocommerce_bwfan_rule_get_rule_object( $rule_type );
								/** added checking for rule condition also */
								if ( $get_rule_object instanceof BWFAN_Rule_Base && is_callable( array( $get_rule_object, 'get_condition_values_nice_names' ) ) && isset( $rule['condition'] ) ) {
									BWFAN_Core()->admin->set_select2ajax_js_data( $get_rule_object->get_search_type_name(), $get_rule_object->get_condition_values_nice_names( $rule['condition'] ) );
									if ( ! isset( $this->select2names[ $rule_type ] ) ) {
										$this->select2names[ $rule_type ] = array();
									}
									$this->select2names[ $rule_type ] = array_replace( $this->select2names[ $rule_type ], $get_rule_object->get_condition_values_nice_names( $rule['condition'] ) );
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * @param $key
	 *
	 * @return mixed|string|null
	 */
	public function getRulesData( $key = '' ) {
		if ( empty( $key ) ) {
			return $this->rules_data;
		}

		return ( isset( $this->rules_data[ $key ] ) ) ? $this->rules_data[ $key ] : '';
	}

	/**
	 * @param string $rules_data
	 * @param string $key
	 */
	public function setRulesData( $rules_data = '', $key = '' ) {
		$this->rules_data[ $key ] = $rules_data;
	}

	/** v2 Methods: START */

	public function get_rules( $event = 'ab_cart_abandoned', $aid = 0 ) {
		/** TODO: Replace the controller instance with a static one from universal automation controller */
		$event_automation_meta = array();
		if ( ! empty( $aid ) ) {
			/** Initiate automation object */
			$ins                   = BWFAN_Automation_V2::get_instance( $aid );
			$meta                  = $ins->get_automation_meta_data();
			$event_automation_meta = isset( $meta['event_meta'] ) ? $meta['event_meta'] : array();
		}

		/** Support for multiple events - normalize to array */
		$event_slugs = is_array( $event ) ? $event : array( $event );

		/** Get event objects for all provided event slugs */
		$events      = array();
		$event_names = array();
		foreach ( $event_slugs as $event_slug ) {
			$event_obj = BWFAN_Core()->sources->get_event( $event_slug );
			if ( $event_obj instanceof BWFAN_Event ) {
				$events[]      = $event_obj;
				$event_names[] = $event_obj->get_slug();
			}
		}

		if ( empty( $events ) ) {
			return [];
		}

		/** Merge rule groups from all events (union) */
		$event_rule_groups = array();
		foreach ( $events as $event_obj ) {
			$event_rule_groups = array_merge( $event_rule_groups, $event_obj->get_rule_group() );
		}
		$event_rule_groups = array_unique( $event_rule_groups );

		$rules_groups = BWFAN_Core()->rules->get_all_groups();
		$rules        = apply_filters( 'bwfan_rule_get_rule_types', array() );

		$event_rules = array();
		foreach ( $event_rule_groups as $group ) {
			if ( ! isset( $rules_groups[ $group ] ) || ! isset( $rules[ $group ] ) ) {
				continue;
			}

			$event_rules[ $group ] = $rules_groups[ $group ];

			$group_rules = $rules[ $group ];
			if ( 'wc_subscription' === $group ) {
				if ( ! in_array( 'wcs_note_added', $event_names, true ) ) {
					if ( isset( $group_rules['subscription_note_text_match'] ) ) {
						unset( $rules[ $group ]['subscription_note_text_match'] );
					}
				}
				if ( ! in_array( 'wcs_status_changed', $event_names, true ) ) {
					if ( isset( $group_rules['subscription_old_status'] ) ) {
						unset( $rules[ $group ]['subscription_old_status'] );
					}
				}
				$allowed_events = [ 'wcs_status_changed', 'wcs_note_added', 'wcs_renewal_payment_complete', 'wcs_before_renewal', 'wcs_before_end', 'wcs_renewal_payment_failed', 'wcs_trial_end' ];
				if ( empty( array_intersect( $event_names, $allowed_events ) ) ) {
					if ( isset( $group_rules['subscription_payment_count'] ) ) {
						unset( $rules[ $group ]['subscription_payment_count'] );
					}
				}
			}
			if ( 'wc_order' === $group ) {
				if ( ! in_array( 'wc_order_note_added', $event_names, true ) && isset( $group_rules['order_note_text_match'] ) ) {
					unset( $rules[ $group ]['order_note_text_match'] );
				}
			}
			$rule_keys = array_keys( $rules[ $group ] );

			$event_rules[ $group ]['rules'] = array_map( function ( $rule ) use ( $group_rules, $event_automation_meta ) {
				$rule_array = $this->get_rule_schema( $rule, $event_automation_meta );
				if ( empty( $rule_array ) ) {
					return false;
				}
				$rule_array['name'] = $group_rules[ $rule ];

				return $rule_array;
			}, $rule_keys );

			$event_rules[ $group ]['rules'] = array_filter( $event_rules[ $group ]['rules'] );
			$event_rules[ $group ]['rules'] = array_values( $event_rules[ $group ]['rules'] );
			if ( empty( $event_rules[ $group ]['rules'] ) ) {
				unset( $event_rules[ $group ] );
			}
		}

		return $event_rules;
	}

	public function get_rule_schema( $rule = 'cart_category', $event_automation_meta = array() ) {
		$rule = $this->woocommerce_bwfan_rule_get_rule_object( $rule );
		if ( ! $rule instanceof BWFAN_Rule_Base || ! $rule->is_v2() ) {
			return array();
		}

		$rule->event_automation_meta = $event_automation_meta;
		$type                        = $rule->get_rule_type();
		$options                     = array();
		if ( ! in_array( strtolower( $type ), array( 'search', 'product-qty' ) ) ) {
			$options = $rule->get_options( '' );
		}

		return array(
			'slug'          => $rule->get_name(),
			'type'          => $type,
			'options'       => $options,
			'multiple'      => $rule->get_multiple_select_support(),
			'operators'     => $rule->get_possible_rule_operators(),
			'readable_text' => $rule->get_readable_text_schema(),
			'value_label'   => $rule->get_value_label(),
			'default'       => $rule->get_default_rule_value(),
			'title'         => $rule->title,
			'extra_props'   => $rule->get_extra_props(),
		);
	}

	public function get_rule_search_suggestions( $term = '', $rule = 'cart_category', $extra = [] ) {
		// Load all the rules classes
		$this->load_rules_classes();

		$rule = $this->woocommerce_bwfan_rule_get_rule_object( $rule );

		return $rule->get_options( $term, $extra );
	}

	/**
	 * @return BWFAN_Rule_Base
	 */
	public function get_rule( $rule = 'cart_category' ) {
		return $this->woocommerce_bwfan_rule_get_rule_object( $rule );
	}

	/** v2 Methods: END */

}

if ( class_exists( 'BWFAN_Rules_Loader' ) ) {
	BWFAN_Core::register( 'rules', 'BWFAN_Rules_Loader' );
}
