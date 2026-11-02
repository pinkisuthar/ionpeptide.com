<?php
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - the WordPress Abilities bridge.
 *
 * The registry feeds the shared LINK. This class feeds the OTHER route the registry docblock
 * names: WooCommerce's own MCP server, which does not read this library's registry -- it reads
 * the WordPress Abilities API. So every tool a plugin hands the registry is also registered here
 * as an ability, and a merchant who connects an assistant to WooCommerce MCP (with a WooCommerce
 * key) finds this plugin's tools sitting alongside WooCommerce's own, with nothing to switch on.
 *
 * ONE SOURCE, TWO READERS
 *
 * Both routes are fed from the same array of tool definitions inside each plugin -- the one it
 * passed to Zorem_MCP_Registry::add_plugin(). The registry answers the link from it; this class
 * turns each entry into an ability for WooCommerce's route from it. The screen's own
 * zorem_mcp_woo_mcp_tools() then lists exactly the abilities carrying the
 * `expose_in_deprecated_woocommerce_mcp` meta this class sets, which is how the plugin's tools
 * show up under "what this key can do".
 *
 * TIMING
 *
 * Plugins register their tools on `zorem_mcp_loaded`, fired from zorem_mcp_boot() on
 * `plugins_loaded` priority 1 -- long before `wp_abilities_api_init` (which fires on `init`).
 * So by the time register_abilities() runs, the registry is fully populated.
 *
 * @package Zorem_MCP
 * @since 2.12.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Zorem_MCP_Abilities' ) ) {

	class Zorem_MCP_Abilities {

		/**
		 * Hook the two Abilities API init points and WooCommerce's inclusion filter.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_categories' ) );
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
			add_filter( 'woocommerce_mcp_include_ability', array( __CLASS__, 'include_in_wc_mcp' ), 10, 2 );
		}

		/**
		 * One category per registered plugin, so WooCommerce groups a plugin's abilities under
		 * its own name rather than scattering them.
		 *
		 * @return void
		 */
		public static function register_categories() {
			if ( ! function_exists( 'wp_register_ability_category' ) || ! class_exists( 'Zorem_MCP_Registry' ) ) {
				return;
			}

			foreach ( Zorem_MCP_Registry::instance()->plugins() as $slug => $plugin ) {
				if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $slug ) ) {
					continue;
				}

				$label = isset( $plugin['label'] ) ? $plugin['label'] : $slug;

				wp_register_ability_category(
					$slug,
					array(
						'label' => $label,
						/* translators: %s: plugin name. */
						'description' => sprintf( __( 'Tools provided by %s.', 'zorem-mcp' ), $label ),
					)
				);
			}
		}

		/**
		 * Register every registered tool as an ability, id `<slug>/<tool>`.
		 *
		 * The ability's execute_callback runs the plugin's own callback -- the same one the link
		 * runs -- turns a WP_Error into the { error, code } shape an MCP client can read, and
		 * writes the call to the audit log under WooCommerce's channel.
		 *
		 * @return void
		 */
		public static function register_abilities() {
			if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'Zorem_MCP_Registry' ) ) {
				return;
			}

			foreach ( Zorem_MCP_Registry::instance()->plugins() as $slug => $plugin ) {
				if ( empty( $plugin['tools'] ) || ! is_array( $plugin['tools'] ) ) {
					continue;
				}

				foreach ( $plugin['tools'] as $short => $tool ) {
					$short = sanitize_key( $short );

					if ( '' === $short || empty( $tool['callback'] ) || ! is_callable( $tool['callback'] ) ) {
						continue;
					}

					$callback    = $tool['callback'];
					$writes      = ! empty( $tool['writes'] );
					$destructive = ! empty( $tool['destructive'] );

					$label = ( isset( $tool['label'] ) && '' !== $tool['label'] )
						? $tool['label']
						: ucfirst( str_replace( array( '-', '_' ), ' ', $short ) );

					wp_register_ability(
						$slug . '/' . $short,
						array(
							'label'               => $label,
							'description'         => isset( $tool['description'] ) ? $tool['description'] : '',
							'category'            => $slug,
							'input_schema'        => self::normalize_schema( isset( $tool['input_schema'] ) ? $tool['input_schema'] : array( 'type' => 'object' ) ),
							'permission_callback' => array( __CLASS__, 'may_run' ),
							'execute_callback'    => static function ( $args ) use ( $callback, $slug, $short ) {
								$result = call_user_func( $callback, is_array( $args ) ? $args : array() );

								// Written to the audit log under WooCommerce's channel, so this
								// route's traffic shows up on the same screens as the link's.
								if ( class_exists( 'Zorem_MCP_Audit' ) && self::is_live_mcp_request() ) {
									Zorem_MCP_Audit::record( $slug . '-' . $short, 'woocommerce-mcp', $result, self::client() );
								}

								if ( is_wp_error( $result ) ) {
									return array(
										'error' => $result->get_error_message(),
										'code'  => $result->get_error_code(),
									);
								}

								return $result;
							},
							'meta'                => array(
								'show_in_rest' => true,
								'mcp'          => array(
									'public' => true,
									'type'   => 'tool',
								),
								// The key zorem_mcp_woo_mcp_tools() and WooCommerce's own server
								// both read to decide what rides the WooCommerce MCP route.
								'expose_in_deprecated_woocommerce_mcp' => true,
								// Both spellings, so whichever reader wins finds the answer.
								'annotations'  => array(
									'readOnlyHint'    => ! $writes,
									'readonly'        => ! $writes,
									'destructiveHint' => $destructive,
									'destructive'     => $destructive,
								),
							),
						)
					);
				}
			}
		}

		/**
		 * The gate on every ability run through WooCommerce's route.
		 *
		 * A WooCommerce key opens the whole store, so the person holding it is a store manager;
		 * that is the capability required. Each tool's own callback still applies its finer
		 * checks, so this is the outer gate, not the only one.
		 *
		 * @return bool
		 */
		public static function may_run() {
			return current_user_can( 'manage_woocommerce' );
		}

		/**
		 * Opt every `<slug>/…` ability into WooCommerce's MCP, leaving all others as they were.
		 *
		 * @param bool   $include    WooCommerce's decision so far.
		 * @param string $ability_id The ability being considered.
		 * @return bool
		 */
		public static function include_in_wc_mcp( $include, $ability_id ) {
			if ( ! class_exists( 'Zorem_MCP_Registry' ) ) {
				return $include;
			}

			foreach ( Zorem_MCP_Registry::instance()->plugins() as $slug => $plugin ) {
				if ( 0 === strpos( (string) $ability_id, $slug . '/' ) ) {
					return true;
				}
			}

			return $include;
		}

		/**
		 * The calling app's name for the audit log -- its User-Agent, truncated, the same shape
		 * the link's server records so both routes read alike on the Audit Log.
		 *
		 * @return string
		 */
		public static function client() {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- sanitised on the same line.
			$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

			return substr( trim( $agent ), 0, 64 );
		}

		/**
		 * Whether this is a genuine external MCP call, not an admin page load or the REST
		 * preload that runs inside wp-admin -- both of which carry the merchant's own browser
		 * and would otherwise be recorded as a caller.
		 *
		 * @return bool
		 */
		protected static function is_live_mcp_request() {
			if ( is_admin() || ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
				return false;
			}

			$route = ( isset( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) )
				? (string) $GLOBALS['wp']->query_vars['rest_route']
				: ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );

			return false !== stripos( $route, 'mcp' );
		}

		/**
		 * Make a JSON Schema safe for strict MCP clients.
		 *
		 * A schema's `properties` must serialise as an object ({}). An empty PHP array becomes
		 * [] in JSON, which clients reject with "expected record, received array", so any empty
		 * `properties` -- and a missing one on an object schema -- is forced to an stdClass.
		 * Recurses through nested object properties and array items.
		 *
		 * @param mixed $schema Schema fragment.
		 * @return mixed
		 */
		protected static function normalize_schema( $schema ) {
			if ( ! is_array( $schema ) ) {
				return $schema;
			}

			$is_object = ! isset( $schema['type'] ) || 'object' === $schema['type'];

			if ( $is_object && ( ! isset( $schema['properties'] ) || ( is_array( $schema['properties'] ) && empty( $schema['properties'] ) ) ) ) {
				$schema['properties'] = new stdClass();
			}

			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as $key => $child ) {
					$schema['properties'][ $key ] = self::normalize_schema( $child );
				}
			}

			if ( isset( $schema['items'] ) ) {
				$schema['items'] = self::normalize_schema( $schema['items'] );
			}

			return $schema;
		}
	}
}
