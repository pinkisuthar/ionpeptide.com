<?php
/**
 * Usage Registry Class
 *
 * Maintains registry of all usage collectors and collects data from them
 *
 * @package WooFunnels
 * @since 1.10.12.72
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WooFunnels_Usage_Registry' ) ) {

	/**
	 * Class WooFunnels_Usage_Registry
	 */
	#[\AllowDynamicProperties]
	class WooFunnels_Usage_Registry {

		/**
		 * Singleton instance
		 *
		 * @var WooFunnels_Usage_Registry|null
		 */
		private static $instance = null;

		/**
		 * Registered collectors
		 * Structure: [ 'plugin_id' => [ 'class' => 'ClassName', 'module' => 'lite|pro', 'instance' => object|null ] ]
		 *
		 * @var array
		 */
		private static $collectors = array();

		/**
		 * Get singleton instance
		 *
		 * @return WooFunnels_Usage_Registry
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register a usage collector
		 * If Pro version registers, it overrides Lite registration for same plugin_id
		 *
		 * @param string $plugin_id Plugin identifier (e.g., 'funnel-builder', 'automations')
		 * @param string $class_name Full class name of collector
		 * @param string $module Module type ('lite' or 'pro')
		 */
		public static function register( $plugin_id, $class_name, $module = 'lite' ) {

			// Pro always overrides Lite for the same plugin_id
			if ( 'pro' === $module && isset( self::$collectors[ $plugin_id ] ) ) {
				// Pro overrides existing Lite registration
				self::$collectors[ $plugin_id ] = array(
					'class'    => $class_name,
					'module'   => $module,
					'instance' => null,
				);
			} elseif ( ! isset( self::$collectors[ $plugin_id ] ) ) {
				// Register new collector (Lite or first registration)
				self::$collectors[ $plugin_id ] = array(
					'class'    => $class_name,
					'module'   => $module,
					'instance' => null,
				);
			}
			// If Lite tries to register after Pro, ignore it (Pro takes precedence)
		}

		/**
		 * Get all registered collectors
		 *
		 * @return array
		 */
		public static function get_collectors() {
			// Discover collectors on first use (lazy loading)
			if ( empty( self::$collectors ) ) {
				self::discover_collectors();
			}
			return self::$collectors;
		}

		/**
		 * Get collector instance
		 *
		 * @param string $plugin_id Plugin identifier
		 * @return object|null Collector instance or null if not found
		 */
		public static function get_collector( $plugin_id ) {
			// Discover collectors on first use (lazy loading)
			if ( empty( self::$collectors ) ) {
				self::discover_collectors();
			}

			if ( ! isset( self::$collectors[ $plugin_id ] ) ) {
				return null;
			}

			// Return cached instance if available
			if ( null !== self::$collectors[ $plugin_id ]['instance'] ) {
				return self::$collectors[ $plugin_id ]['instance'];
			}

			// Create instance
			$class_name = self::$collectors[ $plugin_id ]['class'];
			if ( class_exists( $class_name ) ) {
				self::$collectors[ $plugin_id ]['instance'] = new $class_name();
				return self::$collectors[ $plugin_id ]['instance'];
			}

			return null;
		}

		/**
		 * Discover and register available collectors
		 * Uses filter hook to allow plugins to register their collectors lazily
		 */
		private static function discover_collectors() {
			// Allow plugins to register collectors via filter
			// This allows lazy registration without loading collector classes
			$registered = apply_filters( 'woofunnels_register_usage_collectors', array() );
			foreach ( $registered as $plugin_id => $collector_info ) {
				if ( isset( $collector_info['class'] ) && isset( $collector_info['module'] ) ) {
					self::register( $plugin_id, $collector_info['class'], $collector_info['module'] );
				}
			}
		}

		/**
		 * Collect data from all registered collectors
		 *
		 * @return array Collected data indexed by plugin_id
		 */
		public static function collect_all_data() {
			// Discover collectors on first use (lazy loading)
			if ( empty( self::$collectors ) ) {
				self::discover_collectors();
			}

			$data = array();

			foreach ( self::$collectors as $plugin_id => $collector_info ) {
				$collector = self::get_collector( $plugin_id );
				if ( $collector && method_exists( $collector, 'collect' ) ) {
					$plugin_data = $collector->collect();
					if ( ! empty( $plugin_data ) ) {
						$data[ $plugin_id ] = $plugin_data;
					}
				}
			}

			return $data;
		}

		/**
		 * Check if a plugin has a Pro collector registered
		 *
		 * @param string $plugin_id Plugin identifier
		 * @return bool
		 */
		public static function has_pro_collector( $plugin_id ) {
			return isset( self::$collectors[ $plugin_id ] )
				   && 'pro' === self::$collectors[ $plugin_id ]['module'];
		}
	}
}

