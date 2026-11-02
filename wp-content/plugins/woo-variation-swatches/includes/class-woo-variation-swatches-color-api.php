<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woo_Variation_Swatches_Color_API' ) ) {
	/**
	 * Color Suggestion API
	 */
	class Woo_Variation_Swatches_Color_API {

		/**
		 * Class Instance.
		 *
		 * @var Woo_Variation_Swatches_Color_API|null $instance
		 */
		protected static $instance = null;

		/**
		 * API namespace.
		 *
		 * @var string $namespace
		 */
		protected string $namespace = 'woo-variation-swatches/v1';

		/**
		 * Init.
		 */
		protected function __construct() {
			$this->hooks();

			do_action( 'woo_variation_swatches_color_suggestion_api_loaded', $this );
		}

		/**
		 * Create Singleton Instances.
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Add Hook.
		 *
		 * @return void
		 */
		protected function hooks(): void {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		/**
		 * Register Route.
		 *
		 * @return void
		 */
		public function register_routes(): void {

			// @example: /wp-json/storepress/v1/get-color/?name=Green

			/**
			 * We made this endpoint to survive CORS for https://colors.storepress.com/v1/?name=** API.
			 */

			register_rest_route(
				$this->namespace,
				'/get-color',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'callback' ),
					'permission_callback' => array( $this, 'has_permission' ),
					'args'                => array(
						'name' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						)
					),
				)
			);
		}

		/**
		 * Permission.
		 *
		 * @return bool
		 */
		public function has_permission(): bool {
			if ( ! wc_string_to_bool( woo_variation_swatches()->get_option('enable_color_api', 'no')) ) {
				return false;
			}

			return current_user_can( 'manage_product_terms' ) || current_user_can( 'edit_products' );
		}

		/**
		 * API Callback.
		 *
		 * @param WP_REST_Request $request Rest Request.
		 *
		 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
		 */
		public function callback( \WP_REST_Request $request ) {

			$name = trim($request->get_param( 'name' ));

			$color = $this->send_api_request( $name );

			return rest_ensure_response( array(
				'success' =>  isset( $color['name'] ),
				'color'   => $color,
				'message'   => isset( $color['name'] ) ? '' : esc_html__( 'Sorry! Cannot find any colors.', 'woo-variation-swatches' ),
			) );
		}

		/**
		 * External API Endpoint.
		 *
		 * @return string
		 */
		public function endpoint(): string {
			/**
			 * Filters the color lookup endpoint.
			 *
			 * Lets a site point at a self-hosted instance, or at a mock during tests.
			 */
			return (string) apply_filters( 'storepress_color_suggession_api_endpoint', 'https://colors.storepress.com/v1/');
		}

		/**
		 * Send API Request by PHP.
		 *
		 * @param string $name Color Name.
		 *
		 * @return array
		 */
		private function send_api_request( string $name ): array {

			$args = array( 'name'=> rawurlencode($name) );

			$api_url = add_query_arg( $args, $this->endpoint() );

			$api_args = array(
				'user-agent' => 'Woo_Variation_Swatches',
				'headers' => array(
					'Accept' => 'application/json'
				),
			);

			$response = wp_safe_remote_get( $api_url, $api_args );

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				return array();
			}

			$body  = wp_remote_retrieve_body( $response );
			$color = json_decode( $body, true );

			if ( !isset($color['name']) ) {
				return array();
			}

			return array(
				'name'    => sanitize_text_field(  $color['name'] ),
				'hex'     => sanitize_hex_color(  $color['hex']),
				'palette' => map_deep( $color['palette'], 'sanitize_hex_color'),
			);
		}
	}
}
