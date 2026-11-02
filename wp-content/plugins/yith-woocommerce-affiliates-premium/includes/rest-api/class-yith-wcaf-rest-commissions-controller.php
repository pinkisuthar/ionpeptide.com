<?php
/**
 * REST API Commissions controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Commissions_Controller' ) ) {
	/**
	 * REST API controller class.
	 */
	class YITH_WCAF_REST_Commissions_Controller extends YITH_WCAF_Abstract_REST_CRUD_Controller {
		/**
		 * Object type
		 * Will be used to retrieve data store and base classes
		 *
		 * @var string
		 */
		protected $object = 'commission';

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'commissions';

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
						'description' => _x( 'Unique identifier for the resource.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'order_id'     => array(
						'description' => _x( 'Order id.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'line_item_id' => array(
						'description' => _x( 'Line item id.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'line_total'   => array(
						'description' => _x( 'Line item total.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'product_id'   => array(
						'description' => _x( 'Product id.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'product_name' => array(
						'description' => _x( 'Product name.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'affiliate_id' => array(
						'description' => _x( 'Affiliate id.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'rate'         => array(
						'description' => _x( 'Commission rate.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'amount'       => array(
						'description' => _x( 'Commission amount.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'refunds'      => array(
						'description' => _x( 'Commission refunds.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'status'       => array(
						'description' => _x( 'Commission status.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'created_at'   => array(
						'description' => _x( 'Creation date.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'last_edit'    => array(
						'description' => _x( 'Last edit date.', '[REST API] Commission schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				),
			);
			return $this->add_additional_fields_schema( $schema );
		}
	}
}
