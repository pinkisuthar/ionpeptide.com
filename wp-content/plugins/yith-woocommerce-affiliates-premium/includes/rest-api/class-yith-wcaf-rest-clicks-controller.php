<?php
/**
 * REST API Clicks controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Clicks_Controller' ) ) {
	/**
	 * REST API controller class.
	 */
	class YITH_WCAF_REST_Clicks_Controller extends YITH_WCAF_Abstract_REST_CRUD_Controller {
		/**
		 * Object type
		 * Will be used to retrieve data store and base classes
		 *
		 * @var string
		 */
		protected $object = 'click';

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'clicks';

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
					'id'              => array(
						'description' => _x( 'Unique identifier for the resource.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'affiliate_id'    => array(
						'description' => _x( 'Affiliate id.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'link'            => array(
						'description' => _x( 'Visited link.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'origin'          => array(
						'description' => _x( 'Guest origin.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'origin_base'     => array(
						'description' => _x( 'Origin base.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'ip'              => array(
						'description' => _x( 'Guest IP.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'date'            => array(
						'description' => _x( 'Visit date.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'order_id'        => array(
						'description' => _x( 'Order id.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'conversion_date' => array(
						'description' => _x( 'Conversion date.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
					),
					'conversion_time' => array(
						'description' => _x( 'Conversion time.', '[REST API] Click schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
				),
			);
			return $this->add_additional_fields_schema( $schema );
		}
	}
}
