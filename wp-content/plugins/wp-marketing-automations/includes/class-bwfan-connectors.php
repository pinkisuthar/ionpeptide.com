<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class BWFAN_Connectors
 * Autonami Connectors Controller
 *
 * @package Autonami
 * @author XlPlugins
 */
#[\AllowDynamicProperties]
class BWFAN_Connectors {
	private static $ins = null;

	/** @var WFCO_Connector_Screen[] $_connectors */
	private $_connectors = array();

	/**
	 * Check which connectors will provide extra data
	 */

	public static function get_instance() {
		if ( null === self::$ins ) {
			self::$ins = new self();
		}

		return self::$ins;
	}

	public function get_connectors() {
		if ( empty( $this->_connectors ) ) {
			$connector_screens = self::get_available_connectors();
			$this->_connectors = $connector_screens['autonami'];
		}

		return $this->_connectors;
	}

	public function get_connectors_for_listing() {
		/** Fill connectors if not already fetched */
		$this->get_connectors();

		$connectors = array();
		foreach ( $this->_connectors as $connector_screen ) {
			$slug      = $connector_screen->get_slug();
			$connector = WFCO_Load_Connectors::get_connector( $slug );

			$fields_schema = isset( $connector->v2 ) && true === $connector->v2 ? $connector->get_fields_schema() : array();
			$fields_values = isset( $connector->v2 ) && true === $connector->v2 ? $connector->get_settings_fields_values() : array();
			$is_pro        = isset( $connector->is_pro ) ? $connector->is_pro : true;
			$is_connected  = ! is_null( $connector ) && ( $connector_screen->is_activated() || ! $is_pro ) && isset( WFCO_Common::$connectors_saved_data[ $slug ] ) && true === $connector->has_settings();
			/** For Bitly */
			if ( ! is_null( $connector ) ) {
				$is_connected = false === $is_connected && method_exists( $connector, 'is_connected' ) ? $connector->is_connected() : $is_connected;
			}

			/** Connector DB ID */
			$connector_id = isset( $connector->v2 ) && true === $connector->v2 && $is_connected ? $this->get_connector_id( $slug ) : 0;

			/** Connector Meta */
			$meta = [];
			if ( ! is_null( $connector ) ) {
				$meta = method_exists( $connector, 'get_meta_data' ) ? $connector->get_meta_data() : $meta;
			}

			$priority = is_null( $connector ) && property_exists( $connector_screen, 'priority' ) ? $connector_screen->priority : 0;
			if ( empty( $priority ) ) {
				$priority = ! is_null( $connector ) && method_exists( $connector, 'get_priority' ) ? $connector->get_priority() : 100;
			}

			$logo = property_exists( $connector_screen, 'logo' ) ? $connector_screen->logo : '';
			if ( empty( $logo ) ) {
				$logo = $connector_screen->get_logo();
			} else {
				/** Check if valid url */
				$logo = filter_var( $logo, FILTER_VALIDATE_URL ) ? $logo : BWFAN_PLUGIN_URL . '/includes/connectors-logo/' . $logo . '.png';
			}

			$required = is_null( $connector ) && property_exists( $connector_screen, 'required_plugins' ) ? $connector_screen->required_plugins : [];
			if ( empty( $required ) ) {
				$required = ! is_null( $connector ) && method_exists( $connector, 'get_required_plugins' ) ? $connector->get_required_plugins() : [];
			}

			$new = is_null( $connector ) && property_exists( $connector_screen, 'new' ) ? $connector_screen->new : 0;
			if ( empty( $new ) ) {
				$new = ( ! is_null( $connector ) && isset( $connector->is_new ) ) ? $connector->is_new : 0;
			}

			$final_connector = array(
				'name'             => $connector_screen->get_name(),
				'logo'             => $logo,
				'description'      => $connector_screen->get_desc(),
				'is_syncable'      => $connector_screen->is_activated() && $connector instanceof BWF_CO && $connector->is_syncable(),
				'is_connected'     => $is_connected,
				'fields_schema'    => $fields_schema,
				'fields_values'    => $fields_values,
				'connector_id'     => $connector_id,
				'meta'             => $meta,
				'ispro'            => $is_pro,
				'direct_connect'   => ! is_null( $connector ) && isset( $connector->direct_connect ) ? $connector->direct_connect : false,
				'new'              => $new,
				'priority'         => $priority,
				'required_plugins' => $required,
			);

			/** For Wizard Connectors */
			$initial_schema = false;
			if ( ! is_null( $connector ) ) {
				$initial_schema = method_exists( $connector, 'get_initial_schema' ) ? $connector->get_initial_schema() : $initial_schema;
			}
			if ( false !== $initial_schema ) {
				$final_connector['initial_schema'] = $initial_schema;
			}

			$connectors[ $connector_screen->get_slug() ] = $final_connector;
		}

		return $connectors;
	}

	public function get_connector_id( $slug ) {
		$saved_data = WFCO_Common::$connectors_saved_data;
		$old_data   = ( isset( $saved_data[ $slug ] ) && is_array( $saved_data[ $slug ] ) && count( $saved_data[ $slug ] ) > 0 ) ? $saved_data[ $slug ] : array();
		if ( isset( $old_data['id'] ) ) {
			return absint( $old_data['id'] );
		}

		return 0;
	}

	public function is_connected( $slug ) {
		if ( ! class_exists( 'WFCO_Load_Connectors' ) ) {
			return false;
		}

		$connector = WFCO_Load_Connectors::get_connector( $slug );
		if ( is_null( $connector ) ) {
			/** Try loading connectors files */
			if ( method_exists( 'WFCO_Load_Connectors', 'load_connectors_direct' ) ) {
				WFCO_Load_Connectors::load_connectors_direct();
				$connector = WFCO_Load_Connectors::get_connector( $slug );
			}

			if ( is_null( $connector ) ) {
				return false;
			}
		}
		if ( empty( WFCO_Common::$connectors_saved_data ) ) {
			WFCO_Common::get_connectors_data();
		}

		$is_connected = isset( WFCO_Common::$connectors_saved_data[ $slug ] ) && ! is_null( $connector ) && true === $connector->has_settings();

		/** For Bitly */
		return ( false === $is_connected && ! is_null( $connector ) && method_exists( $connector, 'is_connected' ) ) ? $connector->is_connected() : $is_connected;
	}

	public function is_wizard_connector( $connector ) {
		$meta = method_exists( $connector, 'get_meta_data' ) ? $connector->get_meta_data() : array();

		return isset( $meta['connect_type'] ) && 'wizard' === $meta['connect_type'];
	}

	public static function get_available_connectors( $type = '' ) {
		ob_start();
		include BWFAN_PLUGIN_DIR . '/includes/connectors.json'; //phpcs:ignore WordPressVIPMinimum.Files.IncludingNonPHPFile.IncludingNonPHPFile
		$connectors_json = ob_get_clean();
		$response_data   = json_decode( $connectors_json, true );
		$response_data   = apply_filters( 'wfco_connectors_loaded', $response_data );
		if ( '' !== $type ) {
			return $response_data[ $type ] ?? [];
		}

		return self::load_connector_screens( $response_data, $type );
	}

	private static function load_connector_screens( $response_data, $type = '' ) {
		foreach ( $response_data as $slug => $data ) {
			$connectors = $data['connectors'];
			foreach ( $connectors as $c_slug => $connector ) {
				$connector['type'] = $slug;
				if ( isset( $data['source'] ) && ! empty( $data['source'] ) ) {
					$connector['source'] = $data['source'];
				}
				if ( isset( $data['file'] ) && ! empty( $data['file'] ) ) {
					$connector['file'] = $data['file'];
				}
				if ( isset( $data['support'] ) && ! empty( $data['support'] ) ) {
					$connector['support'] = $data['support'];
				}
				if ( isset( $data['connector_class'] ) && ! empty( $data['connector_class'] ) ) {
					$connector['connector_class'] = $data['connector_class'];
				}
				WFCO_Connector_Screen_Factory::create( $c_slug, $connector );
			}
		}

		return WFCO_Connector_Screen_Factory::getAll( $type );
	}
}

BWFAN_Core::register( 'connectors', 'BWFAN_Connectors' );
