<?php
/**
 * REST API Affiliates controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Affiliates_Controller' ) ) {
	/**
	 * REST API controller class.
	 */
	class YITH_WCAF_REST_Affiliates_Controller extends YITH_WCAF_Abstract_REST_CRUD_Controller {
		/**
		 * Object type
		 * Will be used to retrieve data store and base classes
		 *
		 * @var string
		 */
		protected $object = 'affiliate';

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'affiliates';

		/**
		 * Retrieves the item's schema, conforming to JSON Schema.
		 *
		 * @return array Item schema data.
		 */
		public function get_item_schema() {
			$schema = array(
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => $this->object,
				'type'       => 'object',
				'properties' => array(
					'id'           => array(
						'description' => _x( 'Unique identifier for the resource.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'name'         => array(
						'description' => _x( 'Affiliate name.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'token'        => array(
						'description' => _x( 'Affiliate token.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'user_id'      => array(
						'description' => _x( 'Affiliate user id.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'user_login'   => array(
						'description' => _x( 'Affiliate user login.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'user_email'   => array(
						'description' => _x( 'Affiliate user email.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'rate'         => array(
						'description' => _x( 'Affiliate rate.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'earnings'     => array(
						'description' => _x( 'Affiliate earnings.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'refunds'      => array(
						'description' => _x( 'Affiliate refunds.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'paid'         => array(
						'description' => _x( 'Affiliate paid.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'clicks_count' => array(
						'description' => _x( 'Affiliate referred visits.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'conversions'  => array(
						'description' => _x( 'Affiliate conversions.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'status'       => array(
						'description' => _x( 'Affiliate status.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'banned'       => array(
						'description' => _x( 'Whether affiliate is banned or not.', '[REST API] Affiliate schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
				),
			);
			return $this->add_additional_fields_schema( $schema );
		}
	}
}
