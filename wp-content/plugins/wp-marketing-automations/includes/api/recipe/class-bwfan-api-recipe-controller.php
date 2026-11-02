<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWFAN_API_Recipe_Controller extends BWFAN_API_Controller {

	public static $ins;
	public $total_count = 0;

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function default_args_values() {
		return array();
	}

	protected function register_routes() {
		// GET /recipes — list all recipes
		$this->add_route( '/recipes', array(
			array( 'GET', 'get_recipes' ),
		) );

		// GET /automation/recipe/ — get a single recipe by slug
		$this->add_route( '/automation/recipe/', array(
			array( 'GET', 'get_automation_recipe' ),
		) );

		// GET /automation/recipe/import — import a recipe
		$this->add_route( '/automation/recipe/import', array(
			array( 'GET', 'import_automation_recipe' ),
		) );
	}

	/**
	 * GET /recipes — list all recipes
	 */
	public function get_recipes() {
		$recipe_sync         = $this->get_sanitized_arg( 'sync' );
		$all_recipes         = BWFAN_Recipe_Loader::get_recipes_array( $recipe_sync == 'true' ? true : false );
		$this->response_code = 200;
		$this->total_count   = is_array( $all_recipes ) ? count( $all_recipes ) : 0;

		return $this->success_response( $all_recipes, __( 'Recipes found', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /automation/recipe/ — get a single recipe by slug
	 */
	public function get_automation_recipe() {
		$recipe_slug = $this->get_sanitized_arg( 'recipe_slug' );
		if ( empty( $recipe_slug ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Fetch Recipe data */
		$recipe_data = $this->get_selected_recipe( $recipe_slug );
		if ( is_wp_error( $recipe_data ) ) {
			return $this->error_response( $recipe_data->get_error_message(), null, 400 );
		}
		if ( empty( $recipe_data ) ) {
			return $this->error_response( __( 'Recipe not found.', 'wp-marketing-automations' ), null, 400 );
		}

		$this->response_code = 200;

		return $this->success_response( $recipe_data, ! empty( $recipe_data['message'] ) ? $recipe_data['message'] : __( 'Recipes found', 'wp-marketing-automations' ) );
	}

	/**
	 * GET /automation/recipe/import — import a recipe
	 */
	public function import_automation_recipe() {
		$recipe_slug      = $this->get_sanitized_arg( 'recipe_slug' );
		$automation_title = $this->get_sanitized_arg( 'title', 'text_field' );

		if ( empty( $recipe_slug ) ) {
			return $this->error_response( __( 'Invalid / Empty automation ID provided', 'wp-marketing-automations' ), null, 400 );
		}

		/** Fetch Recipe data */
		$recipe_data = $this->get_selected_recipe( $recipe_slug );
		if ( is_wp_error( $recipe_data ) ) {
			return $this->error_response( $recipe_data->get_error_message(), null, 400 );
		}
		if ( empty( $recipe_data ) ) {
			return $this->error_response( __( 'Recipe not found', 'wp-marketing-automations' ), null, 400 );
		}

		/** Check dependencies */
		if ( isset( $recipe_data['dependencies'] ) && ! empty( $recipe_data['dependencies'] ) ) {
			/** Validate the recipe dependencies */
			$dependency = new BWFAN_Recipe_Dependency();
			$dependency->set_data( $recipe_data['dependencies'] );
			$result = $dependency->validate();

			if ( true !== $result ) {
				return $this->error_response( $result, null, 400 );
			}
		}

		/** Set blank if not available */
		$recipe_data['tips'] = ( isset( $recipe_data['tips'] ) && count( $recipe_data['tips'] ) > 0 ) ? $recipe_data['tips'] : [];

		/**
		 * Import automation
		 */
		$automation_id = 0;
		if ( isset( $recipe_data['import'] ) && ! empty( $recipe_data['import'] ) ) {
			$automation_id = BWFAN_Core()->automations->import( $recipe_data['import'], $automation_title, $recipe_data['tips'], true );

			if ( empty( $automation_id ) ) {
				return $this->error_response( '', null, 400 );
			}
		}

		$this->response_code = 200;

		return $this->success_response( [ 'automation_id' => $automation_id ], __( 'Recipe imported', 'wp-marketing-automations' ) );
	}

	/**
	 * @param $slug recipe slug
	 *
	 * @return array|false
	 */
	/**
	 * @return array|false|WP_Error The recipe; false when the service says there is no such recipe;
	 *                              WP_Error when the service could not be reached.
	 */
	public function get_selected_recipe( $slug ) {
		// Sanitize slug to prevent path traversal
		$slug    = sanitize_key( $slug );
		$request = wp_remote_get( "https://app.getautonami.com/recipe/$slug" );
		$code    = is_wp_error( $request ) ? 0 : (int) wp_remote_retrieve_response_code( $request );
		if ( 404 === $code ) {
			return false;
		}
		// Blocked, timed out or a server error: not an answer about the recipe, so don't report it as missing.
		if ( 200 !== $code ) {
			return new WP_Error( 'bwfan_recipe_unreachable', __( 'Could not reach the FunnelKit recipe service, so the recipe could not be loaded. Check that this site can make outbound requests, then try again.', 'wp-marketing-automations' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $request ), true );
		if ( ! is_array( $data ) || isset( $data['error'] ) ) {
			return false;
		}

		return $data;
	}

	public function get_result_total_count() {
		return $this->total_count;
	}
}

BWFAN_API_Recipe_Controller::get_instance();
