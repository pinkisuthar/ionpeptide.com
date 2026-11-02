<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

#[AllowDynamicProperties]
class BWFAN_Recipe_Loader {
	private static $instance = null;
	public static $web_url = '';

	/**
	 * Constructor
	 */
	public function __construct() {
		self::$web_url = 'https://app.getautonami.com/recipes';
	}

	/**
	 * Ensures only one instance of the class is loaded or can be loaded.
	 *
	 * @return BWFAN_Recipe_Loader|null
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get recipes listing array
	 *
	 * @param bool $load_new
	 *
	 * @return array
	 */
	public static function get_recipes_array( $load_new = false ) {
		$recipes_data['data']        = self::get_recipe_data( $load_new );
		$recipes_data['total_count'] = ! empty( $recipes_data['data'] ) ? count( $recipes_data['data'] ) : 0;
		$recipes_data['filters']     = self::get_recipes_filter();

		return $recipes_data;
	}

	/**
	 * Get the recipes listing data from the database or server
	 *
	 * @param bool $load_new
	 *
	 * @return array
	 */
	public static function get_recipe_data( $load_new ) {
		// A cache written by an older version may be a proxy page or a cut-off body; refetch rather than serve it.
		if ( ! $load_new ) {
			$cached = json_decode( (string) get_option( 'bwfan_get_recipes' ), true );
			if ( self::is_recipe_list( $cached ) ) {
				return $cached;
			}
		}

		$request = wp_remote_get( self::$web_url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $request ) || 200 !== (int) wp_remote_retrieve_response_code( $request ) ) {
			return [];
		}

		$result  = wp_remote_retrieve_body( $request );
		$recipes = json_decode( $result, true );

		// A 200 that is not the catalogue (a proxy page, a cut-off body) must not replace the cache.
		if ( ! self::is_recipe_list( $recipes ) ) {
			return [];
		}

		update_option( 'bwfan_get_recipes', $result, false );

		return $recipes;
	}

	/**
	 * A non-empty list whose rows each carry a slug - what the catalogue server sends.
	 *
	 * @param mixed $recipes
	 *
	 * @return bool
	 */
	private static function is_recipe_list( $recipes ) {
		if ( ! is_array( $recipes ) || empty( $recipes ) ) {
			return false;
		}

		foreach ( $recipes as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) || ! is_string( $row['slug'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get recipes group or sub group filters
	 *
	 * @return array
	 */
	public static function get_recipes_filter() {
		$data = [
			'groups'    => [
				__( 'All', 'wp-marketing-automations' ),
				__( 'WooCommerce', 'wp-marketing-automations' ),
				__( 'WordPress', 'wp-marketing-automations' ),
				'FunnelKit',
				__( 'Learndash', 'wp-marketing-automations' ),
				__( 'AffiliateWP', 'wp-marketing-automations' )
			],
			'subGroups' => [
				'WooCommerce' => [
					__( 'Cart', 'wp-marketing-automations' ),
					__( 'Customer', 'wp-marketing-automations' ),
					__( 'Orders', 'wp-marketing-automations' ),
					__( 'Subscription', 'wp-marketing-automations' ),
					__( 'Reviews', 'wp-marketing-automations' ),
				],
				'WordPress'   => [
					__( 'User', 'wp-marketing-automations' ),
				],
				'FunnelKit'   => [
					__( 'Optin Form', 'wp-marketing-automations' ),
				],
			]
		];

		return apply_filters( 'bwfan_recipe_group_filters', $data );
	}
}

if ( class_exists( 'BWFAN_Core' ) ) {
	BWFAN_Core::register( 'bwfan_recipe', 'BWFAN_Recipe_Loader' );
}
