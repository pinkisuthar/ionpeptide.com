<?php
/**
 * REST API Reports controller.
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\API\Reports
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

/**
 * REST API Reports controller class.
 */
class YITH_WCAF_REST_Reports_Controller extends YITH_WCAF_Abstract_REST_Reports_Controller {

	/**
	 * Get all reports.
	 *
	 * @param WP_REST_Request $request Request data.
	 *
	 * @return WP_Rest_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$data    = array();
		$reports = array(
			array(
				'slug'        => 'affiliates',
				'description' => _x( 'Affiliates detailed reports.', '[REST API] Available reports', 'yith-woocommerce-affiliates' ),
			),
			array(
				'slug'        => 'affiliates/stats',
				'description' => _x( 'Stats about affiliates.', '[REST API] Available reports', 'yith-woocommerce-affiliates' ),
			),
		);

		/**
		 * Filter the list of allowed reports, so that data can be loaded from third party extensions in addition to WooCommerce core.
		 * Array items should be in format of array( 'slug' => 'downloads/stats', 'description' =>  '',
		 * 'url' => '', and 'path' => '/wc-ext/v1/...'.
		 *
		 * @param array $endpoints The list of allowed reports..
		 */
		/**
		 * APPLY_FILTERS: yith_wcaf_admin_reports
		 *
		 * Filters the list of allowed reports.
		 *
		 * @param array $reports List of allowed reports.
		 */
		$reports = apply_filters( 'yith_wcaf_admin_reports', $reports );

		foreach ( $reports as $report ) {
			if ( empty( $report['slug'] ) ) {
				continue;
			}

			if ( empty( $report['path'] ) ) {
				$report['path'] = '/' . $this->namespace . '/reports/' . $report['slug'];
			}

			// Allows a different admin page to be loaded here,
			// or allows an empty url if no report exists for a set of performance indicators.
			if ( ! isset( $report['url'] ) ) {
				if ( '/stats' === substr( $report['slug'], -6 ) ) {
					$url_slug = substr( $report['slug'], 0, -6 );
				} else {
					$url_slug = $report['slug'];
				}

				$report['url'] = '/yith-wcaf/' . $url_slug;
			}

			$item   = $this->prepare_item_for_response( (object) $report, $request );
			$data[] = $this->prepare_response_for_collection( $item );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Prepare links for the request.
	 * Should be overridden to add links specific to the item.
	 *
	 * @param array $object Object data.
	 *
	 * @return array Links for the given post.
	 */
	protected function prepare_item_links( $object ) {
		return array(
			'self'       => array(
				'href' => rest_url( $object->path ),
			),
			'report'     => array(
				'href' => $object->url,
			),
			'collection' => array(
				'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);
	}

	/**
	 * Get the Report's schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'report',
			'type'       => 'object',
			'properties' => array(
				'slug'        => array(
					'description' => _x( 'An alphanumeric identifier for the resource.', '[REST API] Report schema', 'yith-woocommerce-affiliates' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'description' => array(
					'description' => _x( 'A human-readable description of the resource.', '[REST API] Report schema', 'yith-woocommerce-affiliates' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'path'        => array(
					'description' => _x( 'API path.', '[REST API] Report schema', 'yith-woocommerce-affiliates' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		// only context is allowed as valid param for this report.
		return array(
			'context' => $params['context'],
		);
	}
}
