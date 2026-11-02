<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

#[AllowDynamicProperties]
abstract class BWFAN_Recipes {

	public $data = [];

	/**
	 *  get data of the recipe
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * get slug of recipe
	 */
	public function get_slug() {
		return get_class( $this );
	}

	/**
	 * get global settings
	 * @return mixed|void
	 */
	public function get_settings() {
		$settings = BWFAN_Common::get_global_settings();

		return $settings;
	}

	/**
	 * get plugin name
	 *
	 * @param $slug
	 *
	 * @return string
	 */
	public function get_plugin_name( $slug ) {
		$name = '';
		switch ( $slug ) {
			case 'wc':
				$name = __( 'WooCommerce', 'wp-marketing-automations' );
				break;
			case 'wc-subscription':
				$name = __( 'WooCommerce Subscription', 'wp-marketing-automations' );
				break;
			case 'autonami-pro':
				$name = __( 'FunnelKit Automations Pro', 'wp-marketing-automations' );
				break;
			case 'autonami-conn':
				$name = __( 'FunnelKit Automations Connector', 'wp-marketing-automations' );
				break;
			default:
				break;
		}

		return $name;
	}

	/**
	 * get plugin name
	 *
	 * @param $slug
	 *
	 * @return string
	 */
	public function get_integration_name( $slug ) {
		$name = '';
		switch ( $slug ) {
			case 'wc':
				$name = __( 'WooCommerce', 'wp-marketing-automations' );
				break;
			case 'wc-subscription':
				$name = __( 'WooCommerce Subscription', 'wp-marketing-automations' );
				break;
			case 'autonami-pro':
				$name = __( 'FunnelKit Automations Pro', 'wp-marketing-automations' );
				break;
			case 'autonami-conn':
				$name = __( 'FunnelKit Automations Connector', 'wp-marketing-automations' );
				break;
			default:
				break;
		}

		return $name;
	}

	/**
	 * get channel name
	 *
	 * @param $slug
	 *
	 * @return string
	 */
	public function get_channel_name( $slug ) {
		$name = '';
		switch ( $slug ) {
			case 'email':
				$name = __( 'Email', 'wp-marketing-automations' );
				break;
			default:
				break;
		}

		return $name;
	}

	/**
	 * get goal name
	 *
	 * @param $slug
	 *
	 * @return string
	 */
	public function get_goal_name( $slug ) {
		$name = '';
		switch ( $slug ) {
			case 'convert_sales':
				$name = __( 'Convert Sales', 'wp-marketing-automations' );
				break;
			default:
				break;
		}

		return $name;
	}

	/**
	 * get type name
	 *
	 * @param $slug
	 *
	 * @return string
	 */
	public function get_type_name( $slug ) {
		$name = '';
		switch ( $slug ) {
			case 'abandoned_cart':
				$name = __( 'Abandoned Cart', 'wp-marketing-automations' );
				break;
			case 'browse_abandonment':
				$name = __( 'Browse Abandonment', 'wp-marketing-automations' );
				break;
			case 'customer_winback':
				$name = __( 'Customer Winback', 'wp-marketing-automations' );
				break;
			default:
				break;
		}

		return $name;
	}

	/**
	 *  checking recipe dependency
	 */
	public function check_dependency() {

		/** get plugin dependency */
		$plugin_depend       = $this->data['plugin-dependencies'];
		$plugin_depend_check = BWFAN_Common::plugin_dependency_check( $plugin_depend );

		/** get connector dependency */
		$connnect_depend      = $this->data['connector-dependencies'];
		$connect_depend_check = self::connector_dependency_check( $connnect_depend );

		/** check data dependency */
		$data_depend       = $this->data['data-dependencies'];
		$data_depend_check = self::data_dependency_check( $data_depend );

		/** @var  $depend_error */
		$depend_error = array();
		// check if plugin and connector dependency match
		if ( true !== $connect_depend_check || true !== $plugin_depend_check || true !== $data_depend_check ) {
			if ( is_array( $plugin_depend_check ) && count( $plugin_depend_check ) > 0 ) {
				$depend_error = $plugin_depend_check;
			}
			if ( is_array( $connect_depend_check ) && count( $connect_depend_check ) > 0 ) {
				$depend_error = array_merge( $connect_depend_check, $depend_error );
			}
			if ( is_array( $data_depend_check ) && count( $data_depend_check ) > 0 ) {
				$depend_error = array_merge( $data_depend_check, $depend_error );
			}

			return $depend_error;
		}

		return empty( $depend_error ) ? true : $depend_error;
	}

	/**
	 * checking recipe connector dependency
	 *
	 * @param $connect_depend
	 *
	 * @return array|bool
	 */
	public static function connector_dependency_check( $connect_depend ) {
		if ( empty( $connect_depend ) ) {
			return true;
		}

		$connect_error = [];
		$connect_key   = '';
		// get all connectors
		$all_connectors = WFCO_Load_Connectors::get_all_connectors();
		if ( empty( $all_connectors ) ) {
			foreach ( $connect_depend as $connect ) {
				$connect_name    = self::get_connector_name( $connect );
				$connect_error[] = "{$connect_name} is not activate";
			}

			return $connect_error;
		}

		$saved_connectors = WFCO_Common::$connectors_saved_data;
		foreach ( $connect_depend as $connect ) {
			$connect_name = self::get_connector_name( $connect );
			$conn_name    = str_replace( ' ', '', strtolower( $connect_name ) );
			$connect_key  = 'bwfco_' . $conn_name;
			if ( ! array_key_exists( $connect_key, $saved_connectors ) ) {
				$connect_error[] = "{$connect_name} is not connected";
			}
		}

		return empty( $connect_error ) ? true : $connect_error;
	}

	/**
	 * get connector name
	 *
	 * @param $slug
	 *
	 * @return string
	 */
	public static function get_connector_name( $slug ) {
		$name = '';
		switch ( $slug ) {
			case 'ac':
				$name = __( 'Active Campaign', 'wp-marketing-automations' );
				break;
			case 'drip':
				$name = __( 'Drip', 'wp-marketing-automations' );
				break;
			case 'ck':
				$name = __( 'Kit ( Formerly ConvertKit )', 'wp-marketing-automations' );
				break;
			case 'mailchimp':
				$name = __( 'Mailchimp', 'wp-marketing-automations' );
				break;
			default:
				break;
		}

		return $name;
	}

	/**
	 * checking data dependency
	 *
	 * @param $data_depend
	 *
	 * @return array|bool
	 */
	protected static function data_dependency_check( $data_depend ) {
		if ( empty( $data_depend ) ) {
			return true;
		}
		$data_error = [];

		foreach ( $data_depend as $data ) {
			$operator      = isset( $data['operator'] ) ? $data['operator'] : '';
			$current_value = isset( $data['current_value'] ) ? $data['current_value'] : '';
			$check_value   = isset( $data['check_value'] ) ? $data['check_value'] : '';
			$data_check    = self::check_data_value( $current_value, $operator, $check_value );

			if ( false === $data_check ) {
				$data_error[] = $data['message'];

				return $data_error;
			}
		}

		return empty( $data_error ) ? true : $data_error;
	}

	/**
	 * get operand to check the data dependency
	 *
	 * @param $operator
	 *
	 * @return string
	 */
	protected static function check_data_value( $current_value, $operator, $check_value ) {
		switch ( $operator ) {
			case '=':
				return $current_value === $check_value ? true : false;
			case '!=':
				return $current_value !== $check_value ? true : false;
			default:
				return true;
		}
	}

	/**
	 *  creating recipe automation
	 */
	public function create_automation( $json_files, $title = '' ) {
		if ( ! is_array( $json_files ) || empty( $json_files ) ) {
			$resp = array(
				'msg'    => __( 'Recipe json files are missing', 'wp-marketing-automations' ),
				'status' => false,
			);

			return $resp;
		}

		foreach ( $json_files as $file ) {
			$json_path = plugin_dir_path( __FILE__ ) . '../recipes/json/' . $file . '.json';
			/** checking is the json file exist then return */
			if ( ! file_exists( $json_path ) ) {
				continue;
			}

			$automation_data = json_decode( file_get_contents( $json_path ), true );
			if ( empty( $automation_data ) && ! is_array( $automation_data ) ) {
				continue;
			}

			foreach ( $automation_data as $import_data ) {
				if ( ! isset( $import_data['meta']['title'] ) || '' === $import_data['meta']['title'] ) {
					continue;
				}
				$post             = array();
				$post['status']   = 2;
				$post['source']   = isset( $import_data['data']['source'] ) ? $import_data['data']['source'] : '';
				$post['event']    = isset( $import_data['data']['event'] ) ? $import_data['data']['event'] : '';
				$post['priority'] = 0;

				if ( empty( $post ) ) {
					continue;
				}

				BWFAN_Model_Automations::insert( $post );
				$automation_id = BWFAN_Model_Automations::insert_id();

				BWFAN_Core()->automations->set_automation_id( $automation_id );
				BWFAN_Core()->automations->set_automation_data( 'status', $post['status'] );
				if ( 0 === $automation_id && is_wp_error( $automation_id ) ) {
					continue;
				}
				if ( ! empty( $import_data['meta'] ) ) {
					foreach ( $import_data['meta'] as $key => $auto_meta ) {
						if ( is_array( $auto_meta ) ) {
							$auto_meta = maybe_serialize( $auto_meta );
						} else {
							$auto_meta = $auto_meta;
						}
						$meta                        = array();
						$meta['bwfan_automation_id'] = $automation_id;
						$meta['meta_key']            = $key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
						if ( $key == 'title' && ! empty( $title ) ) {
							$meta['meta_value'] = $title; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
						} else {
							$meta['meta_value'] = $auto_meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
						}
						BWFAN_Model_Automationmeta::insert( $meta );
						BWFAN_Core()->automations->set_automation_data( $key, $meta['meta_value'] );

					}
				}

				$meta                        = array();
				$meta['bwfan_automation_id'] = $automation_id;
				$meta['meta_key']            = 'c_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				$meta['meta_value']          = current_time( 'mysql', 1 ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				BWFAN_Model_Automationmeta::insert( $meta );
				BWFAN_Core()->automations->set_automation_data( 'c_date', $meta['meta_value'] );

				$meta                        = array();
				$meta['bwfan_automation_id'] = $automation_id;
				$meta['meta_key']            = 'm_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				$meta['meta_value']          = current_time( 'mysql', 1 ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				BWFAN_Model_Automationmeta::insert( $meta );
				BWFAN_Core()->automations->set_automation_data( 'm_date', $meta['meta_value'] );

				do_action( 'bwfan_automation_saved', $automation_id );

				$resp['status']       = true;
				$resp['redirect_url'] = add_query_arg( array(
					'page' => 'autonami-automations',
				), admin_url( 'admin.php' ) );
				$resp['id']           = $automation_id;
				$resp['msg']          = __( 'Recipe Successfully Imported', 'wp-marketing-automations' );
			}
		}

		return $resp;
	}

	/**
	 * Create automation by recipe
	 *
	 * @param $recipe_slug
	 * @param $title
	 */
	public static function create_automation_by_recipe( $recipe_slug, $title ) {
		$recipe_instance = $recipe_slug::get_instance();
		$recipe_json     = array_filter( $recipe_instance->data['json'] );
		$automation      = $recipe_instance->create_automation( $recipe_json, $title );

		return $automation;
	}

}
