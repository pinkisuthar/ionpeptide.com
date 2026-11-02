<?php
/**
 * Rate rule class
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Rate_Rule' ) ) {

	/**
	 * Rate rule object
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Rate_Rule extends YITH_WCAF_Abstract_Object {

		/**
		 * Stores meta in cache for future reads.
		 *
		 * A group must be set to to enable caching.
		 *
		 * @var string
		 */
		protected $cache_group = 'rate_rules';

		/**
		 * Constructor
		 *
		 * @param int|\YITH_WCAF_Rate_Rule $rule Rule identifier.
		 *
		 * @throws Exception When not able to load Data Store class.
		 */
		public function __construct( $rule = 0 ) {
			// set default values.
			$this->data = array(
				'name'               => '',
				'enabled'            => 1,
				'type'               => '',
				'rate'               => 0,
				'affiliate_ids'      => array(),
				'product_ids'        => array(),
				'product_categories' => array(),
				'user_roles'         => array(),
				'priority'           => 0,
			);

			parent::__construct();

			if ( is_numeric( $rule ) && $rule > 0 ) {
				$this->set_id( $rule );
			} elseif ( $rule instanceof self ) {
				$this->set_id( $rule->get_id() );
			} elseif ( is_array( $rule ) && isset( $rule['id'] ) ) {
				$this->set_id( $rule['id'] );
				unset( $rule['id'] );
			} else {
				$this->set_object_read( true );
			}

			$this->data_store = WC_Data_Store::load( 'rate_rule' );

			if ( $this->get_id() > 0 ) {
				$this->data_store->read( $this );
			}

			is_array( $rule ) && $this->set_props( $rule );
		}

		/* === GETTERS === */

		/**
		 * Return name for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return float Rule name.
		 */
		public function get_name( $context = 'view' ) {
			return $this->get_prop( 'name', $context );
		}

		/**
		 * Return enabled property for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return int Enabled property.
		 */
		public function get_enabled( $context = 'view' ) {
			return (int) $this->get_prop( 'enabled', $context );
		}

		/**
		 * Return true is rule is enabled
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return bool Whether rule is enabled.
		 */
		public function is_enabled( $context = 'view' ) {
			return ! ! $this->get_enabled( $context );
		}

		/**
		 * Return rate for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return float Rule rate.
		 */
		public function get_rate( $context = 'view' ) {
			return (float) $this->get_prop( 'rate', $context );
		}

		/**
		 * Get rule's formatted rate
		 *
		 * @return string Formatted rate.
		 */
		public function get_formatted_rate() {
			$rate = $this->get_rate( 'view' );
			return yith_wcaf_rate_format( $rate );
		}

		/**
		 * Return type for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return float Rule type.
		 */
		public function get_type( $context = 'view' ) {
			return $this->get_prop( 'type', $context );
		}

		/**
		 * Return affiliate ids for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array of affiliate ids.
		 */
		public function get_affiliate_ids( $context = 'view' ) {
			return $this->get_prop( 'affiliate_ids', $context );
		}

		/**
		 * Get a list of formatted affiliates
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array for formatted affiliates.
		 */
		public function get_formatted_affiliates( $context = 'view' ) {
			$affiliate_ids = $this->get_affiliate_ids( $context );

			if ( ! $affiliate_ids ) {
				return array();
			}

			$formatted = array();

			foreach ( $affiliate_ids as $affiliate_id ) {
				$affiliate = YITH_WCAF_Affiliate_Factory::get_affiliate( $affiliate_id );

				if ( ! $affiliate_id ) {
					continue;
				}

				$formatted[ $affiliate_id ] = 'view' === $context ? <<<EOHTML
					<a target="_blank" href="{$affiliate->get_admin_edit_url()}">
						{$affiliate->get_formatted_name()}
					</a>
				EOHTML : $affiliate->get_formatted_name();
			}

			return $formatted;
		}

		/**
		 * Return user roles for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array of role slugs.
		 */
		public function get_user_roles( $context = 'view' ) {
			return $this->get_prop( 'user_roles', $context );
		}

		/**
		 * Returns a list of formatted role names for current rule
		 *
		 * @return array An array of formatted role names.
		 */
		public function get_formatted_user_roles() {
			$roles  = $this->get_user_roles( 'view' );
			$_roles = wp_roles()->roles;

			if ( ! $roles ) {
				return array();
			}

			$formatted = array();

			foreach ( $roles as $role ) {

				if ( ! isset( $_roles[ $role ] ) ) {
					continue;
				}

				$role_name = translate_user_role( $_roles[ $role ]['name'] );
				$role_url  = add_query_arg( 'role', $role, admin_url( 'users.php' ) );

				$formatted[ $role ] = <<<EOHTML
					<a target="_blank" href="{$role_url}">
						{$role_name}
					</a>
				EOHTML;
			}

			return $formatted;
		}

		/**
		 * Return user ids for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array of user ids.
		 */
		public function get_product_ids( $context = 'view' ) {
			return $this->get_prop( 'product_ids', $context );
		}

		/**
		 * Get a list of formatted products
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array for formatted products.
		 */
		public function get_formatted_products( $context = 'view' ) {
			$product_ids = $this->get_product_ids( $context );

			if ( ! $product_ids ) {
				return array();
			}

			$formatted = array();

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				$product_name = wp_strip_all_tags( $product->get_formatted_name() );
				$product_url  = get_edit_post_link( $product->get_id() );

				$formatted[ $product_id ] = 'view' === $context ? <<<EOHTML
					<a target="_blank" href="{$product_url}">
						{$product_name}
					</a>
				EOHTML : $product_name;
			}

			return $formatted;
		}

		/**
		 * Return product categories for current rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array Array of category ids.
		 */
		public function get_product_categories( $context = 'view' ) {
			return $this->get_prop( 'product_categories', $context );
		}

		/**
		 * Returns a list of formatted category names for current rule
		 *
		 * @return array An array of formatted category names.
		 */
		public function get_formatted_product_categories() {
			$category_ids = $this->get_product_categories( 'view' );

			$formatted = array();

			foreach ( $category_ids as $category_id ) {
				$term = get_term( $category_id, 'product_cat' );

				if ( ! $term ) {
					continue;
				}

				$term_name = wp_strip_all_tags( $term->name );
				$term_url  = add_query_arg(
					array(
						'post_type'   => 'product',
						'product_cat' => $term->slug
					),
					admin_url( 'edit.php' )
				);

				$formatted[ $category_id ] = <<<EOHTML
					<a target="_blank" href="{$term_url}">
						{$term_name}
					</a>
				EOHTML;
			}

			return $formatted;
		}

		/**
		 * Returns priority for the rule
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return int Rule priority.
		 */
		public function get_priority( $context = 'view' ) {
			return (int) $this->get_prop( 'priority', $context );
		}

		/**
		 * Returns an array representation of this object
		 *
		 * @return array Formatted array representing current item.
		 */
		public function to_array() {
			$data = array_merge(
				parent::to_array(),
				array(
					'products'   => $this->get_formatted_products( 'edit' ),
					'affiliates' => $this->get_formatted_affiliates( 'edit' ),
				)
			);

			return $data;
		}

		/* === SETTERS === */

		/**
		 * Set rule's name
		 *
		 * @param string $name Name for the rule.
		 */
		public function set_name( $name ) {
			$this->set_prop( 'name', $name );
		}

		/**
		 * Set rule's enabled property
		 *
		 * @param int $enabled Enabled property for the rule.
		 */
		public function set_enabled( $enabled ) {
			$this->set_prop( 'enabled', (int) $enabled );
		}

		/**
		 * Enable rule.
		 */
		public function enable() {
			$this->set_enabled( 1 );
		}

		/**
		 * Disable rule.
		 */
		public function disable() {
			$this->set_enabled( 0 );
		}

		/**
		 * Set rate for the rule
		 *
		 * @param float $rate Rule rate.
		 */
		public function set_rate( $rate ) {
			$rate = (float) $rate;

			/**
			 * APPLY_FILTERS: yith_wcaf_max_rate_value
			 *
			 * Filters the maximum rate value.
			 *
			 * @param int $max_rate_value Maximum rate value.
			 */
			if ( $rate < 0 || $rate > apply_filters( 'yith_wcaf_max_rate_value', 100 ) ) {
				return;
			}

			$this->set_prop( 'rate', $rate );
		}

		/**
		 * Set type for the rule
		 *
		 * @param string $type Rule type.
		 */
		public function set_type( $type ) {
			$available_types = array_keys( YITH_WCAF_Rate_Handler_Premium::get_supported_rule_types() );

			if ( ! in_array( $type, $available_types, true ) ) {
				return;
			}

			$this->set_prop( 'type', $type );
		}

		/**
		 * Set affiliate ids for current rule
		 *
		 * @param int|int[] $affiliate_ids Affiliate ids.
		 */
		public function set_affiliate_ids( $affiliate_ids ) {
			$affiliate_ids = (array) $affiliate_ids;
			$affiliate_ids = array_map( 'intval', $affiliate_ids );
			$valid_ids     = array();

			foreach ( $affiliate_ids as $affiliate_id ) {
				if ( ! YITH_WCAF_Affiliate_Factory::get_affiliate( $affiliate_id ) ) {
					continue;
				}

				$valid_ids[] = $affiliate_id;
			}

			$this->set_prop( 'affiliate_ids', $valid_ids );
		}

		/**
		 * Add an affiliate for current rule.
		 *
		 * @param int $affiliate_id Affiliate id.
		 */
		public function add_affiliate_id( $affiliate_id ) {
			if ( ! YITH_WCAF_Affiliate_Factory::get_affiliate( $affiliate_id ) ) {
				return;
			}

			$affiliate_id  = (int) $affiliate_id;
			$affiliate_ids = $this->get_affiliate_ids();

			if ( ! in_array( $affiliate_id, $affiliate_ids, true ) ) {
				$affiliate_ids[] = $affiliate_id;
				$this->set_prop( 'affiliate_ids', $affiliate_ids );
			}
		}

		/**
		 * Removes an affiliate from current rule.
		 *
		 * @param int $affiliate_id Affiliate id.
		 */
		public function remove_affiliate_id( $affiliate_id ) {
			$affiliate_id  = (int) $affiliate_id;
			$affiliate_ids = $this->get_affiliate_ids();
			$id_position   = array_search( $affiliate_id, $affiliate_ids, true );

			if ( false !== $id_position ) {
				unset( $affiliate_ids[ $id_position ] );
			}

			$this->set_prop( 'affiliate_ids', $affiliate_ids );
		}

		/**
		 * Set product ids for current rule
		 *
		 * @param int|int[] $product_ids Product ids.
		 */
		public function set_product_ids( $product_ids ) {
			$product_ids = (array) $product_ids;
			$product_ids = array_map( 'intval', $product_ids );
			$valid_ids   = array();

			foreach ( $product_ids as $product_id ) {
				if ( ! wc_get_product( $product_id ) ) {
					continue;
				}

				$valid_ids[] = $product_id;
			}

			$this->set_prop( 'product_ids', $valid_ids );
		}

		/**
		 * Add a product for current rule.
		 *
		 * @param int $product_id Product id.
		 */
		public function add_product_id( $product_id ) {
			if ( ! wc_get_product( $product_id ) ) {
				return;
			}

			$product_id  = (int) $product_id;
			$product_ids = $this->get_product_ids();

			if ( ! in_array( $product_id, $product_ids, true ) ) {
				$product_ids[] = $product_id;
				$this->set_prop( 'product_ids', $product_ids );
			}
		}

		/**
		 * Removes a product from current rule.
		 *
		 * @param int $product_id Product id.
		 */
		public function remove_product_id( $product_id ) {
			$product_id  = (int) $product_id;
			$product_ids = $this->get_product_ids();
			$id_position = array_search( $product_id, $product_ids, true );

			if ( false !== $id_position ) {
				unset( $product_ids[ $id_position ] );
			}

			$this->set_prop( 'product_ids', $product_ids );
		}

		/**
		 * Set product categories for current rule
		 *
		 * @param int|int[] $product_cats Product categories.
		 */
		public function set_product_categories( $product_cats ) {
			$product_cats = (array) $product_cats;
			$product_cats = array_map( 'intval', $product_cats );
			$valid_ids    = array();

			foreach ( $product_cats as $category_id ) {
				if ( ! term_exists( $category_id, 'product_cat' ) ) {
					continue;
				}

				$valid_ids[] = $category_id;
			}

			$this->set_prop( 'product_categories', $valid_ids );
		}

		/**
		 * Add a product category for current rule.
		 *
		 * @param int $category_id Category id.
		 */
		public function add_product_category( $category_id ) {
			if ( ! term_exists( $category_id, 'product_cat' ) ) {
				return;
			}

			$category_id  = (int) $category_id;
			$product_cats = $this->get_product_categories();

			if ( ! in_array( $category_id, $product_cats, true ) ) {
				$product_cats[] = $category_id;
				$this->set_prop( 'product_categories', $product_cats );
			}
		}

		/**
		 * Removes a product category from current rule.
		 *
		 * @param int $category_id Category id.
		 */
		public function remove_product_category( $category_id ) {
			$category_id  = (int) $category_id;
			$product_cats = $this->get_product_categories();
			$id_position  = array_search( $category_id, $product_cats, true );

			if ( false !== $id_position ) {
				unset( $product_cats[ $id_position ] );
			}

			$this->set_prop( 'product_cats', $product_cats );
		}

		/**
		 * Set user roles for current rule
		 *
		 * @param string|string[] $user_roles User roles.
		 */
		public function set_user_roles( $user_roles ) {
			$user_roles  = (array) $user_roles;
			$valid_roles = array();

			foreach ( $user_roles as $role ) {
				if ( ! array_key_exists( $role, wp_roles()->roles ) ) {
					continue;
				}

				$valid_roles[] = $role;
			}

			$this->set_prop( 'user_roles', $valid_roles );
		}

		/**
		 * Add a user role for current rule.
		 *
		 * @param string $user_role User role.
		 */
		public function add_user_role( $user_role ) {
			if ( ! array_key_exists( $user_role, wp_roles()->roles ) ) {
				return;
			}

			$user_roles = $this->get_user_roles();

			if ( ! in_array( $user_roles, $user_roles, true ) ) {
				$user_roles[] = $user_role;
				$this->set_prop( 'user_roles', $user_roles );
			}
		}

		/**
		 * Removes a user role from current rule.
		 *
		 * @param string $user_role User role.
		 */
		public function remove_user_role( $user_role ) {
			$user_roles  = $this->get_user_roles();
			$id_position = array_search( $user_role, $user_roles, true );

			if ( false !== $id_position ) {
				unset( $user_roles[ $id_position ] );
			}

			$this->set_prop( 'user_roles', $user_roles );
		}

		/**
		 * Set priority for the rule
		 *
		 * @param int $priority Rule priority.
		 */
		public function set_priority( $priority ) {
			$priority = (int) $priority;

			if ( $priority < 0 ) {
				$priority = 0;
			}

			$this->set_prop( 'priority', $priority );
		}
	}
}
