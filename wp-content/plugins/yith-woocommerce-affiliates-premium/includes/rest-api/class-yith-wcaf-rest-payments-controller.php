<?php
/**
 * REST API Payments controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_REST_Payments_Controller' ) ) {
	/**
	 * REST API Reports controller class.
	 */
	class YITH_WCAF_REST_Payments_Controller extends YITH_WCAF_Abstract_REST_CRUD_Controller {
		/**
		 * Object type
		 * Will be used to retrieve data store and base classes
		 *
		 * @var string
		 */
		protected $object = 'payment';

		/**
		 * Route base.
		 *
		 * @var string
		 */
		protected $rest_base = 'payments';

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
						'description' => _x( 'Unique identifier for the resource.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'affiliate_id'    => array(
						'description' => _x( 'Affiliate id.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					),
					'gateway_id'      => array(
						'description' => _x( 'Gateway id.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'status'          => array(
						'description' => _x( 'Payment status.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
					'amount'          => array(
						'description' => _x( 'Payment amount.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'number',
						'context'     => array( 'view', 'edit' ),
					),
					'created_at'      => array(
						'description' => _x( 'Creation date.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'completed_at'    => array(
						'description' => _x( 'Completed date.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'date-time',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
					'transaction_key' => array(
						'description' => _x( 'Transaction ID.', '[REST API] Payment schema', 'yith-woocommerce-affiliates' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
					),
				),
			);
			return $this->add_additional_fields_schema( $schema );
		}
	}
}
