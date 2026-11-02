<?php
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - the tool registry.
 *
 * Where plugins hand their tools to the shared connection. A plugin says who it is and what
 * it offers; the library owns everything else -- the route, the token, the audit log, the
 * settings screen.
 *
 * WHY A REGISTRY AND NOT ABILITIES
 *
 * WordPress has an Abilities API and WooCommerce reads it, so it is a fair question why
 * these tools are not simply registered there and left at that. They are, in fact: each
 * plugin still registers abilities, which is how the tools reach WooCommerce's own MCP
 * server without a merchant doing anything. This registry is for the OTHER route -- the
 * link -- and it exists because reading the abilities registry means forcing it to
 * initialise, which fires a global init action once per request. Doing that early enough
 * to answer a request would close the registry before other plugins have hooked it.
 *
 * So: abilities for WooCommerce's route, this registry for ours, both fed from one array of
 * definitions inside each plugin. Two readers, one source, no duplicated wording.
 *
 * NAMING
 *
 * A tool's `name` is its identifier: an AI calls it by that string and a client stores it.
 * It is namespaced by plugin (`ast-pro-get-tracking`) and must never change once shipped --
 * changing it breaks every connection already made. A tool's `title` is for people, and
 * carries the plugin's name in front of it (`AST PRO > Get tracking`) because MCP has no
 * way to group tools: tools/list is a flat array and the client decides how to draw it.
 * Naming each tool with its owner is the only grouping available.
 *
 * @package Zorem_MCP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zorem_MCP_Registry {

	/**
	 * The single instance.
	 *
	 * @var Zorem_MCP_Registry|null
	 */
	protected static $instance = null;

	/**
	 * Registered plugins, keyed by slug.
	 *
	 * @var array
	 */
	protected $plugins = array();

	/**
	 * Get the registry.
	 *
	 * @return Zorem_MCP_Registry
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register one plugin's tool set.
	 *
	 * Called on `zorem_mcp_loaded`, AFTER the plugin has checked its own licence. A plugin
	 * with an expired licence simply does not call this, and the connection carries on
	 * without its tools rather than failing.
	 *
	 * @param string $slug   Plugin slug, e.g. 'ast-pro'. Also the tool name prefix.
	 * @param array  $args   {
	 *     @type string $label Plugin name as a merchant knows it, e.g. 'AST PRO'. The library
	 *                         builds the tool-title prefix from it -- do not pass one.
	 *     @type array  $tools Tool definitions, keyed by short name.
	 * }
	 * @return void
	 */
	public function add_plugin( $slug, $args ) {
		$slug = sanitize_key( $slug );

		if ( '' === $slug || empty( $args['tools'] ) || ! is_array( $args['tools'] ) ) {
			return;
		}

		$label = isset( $args['label'] ) ? (string) $args['label'] : $slug;

		/*
		 * The prefix is derived, never passed in.
		 *
		 * Both shipped plugins used to hand it over -- "AST PRO › " and "Returns › " -- which
		 * was two plugins hand-building the exact string this line already produces, and a
		 * third free to write "My Plugin - " instead. How a tool title reads in a list of ten
		 * from three plugins is the library's question; the plugin's answer is its name.
		 */
		$this->plugins[ $slug ] = array(
			'slug'   => $slug,
			'label'  => $label,
			'prefix' => $label . ' › ',
			'tools'  => $args['tools'],
		);
	}

	/**
	 * Every registered plugin.
	 *
	 * @return array
	 */
	public function plugins() {
		return $this->plugins;
	}

	/**
	 * One registered plugin, or null.
	 *
	 * @param string $slug Plugin slug.
	 * @return array|null
	 */
	public function plugin( $slug ) {
		$slug = sanitize_key( $slug );
		return isset( $this->plugins[ $slug ] ) ? $this->plugins[ $slug ] : null;
	}

	/**
	 * Whether any plugin other than the given one has registered tools.
	 *
	 * The settings screen asks this. Each plugin's screen lists its own tools, which is
	 * clear but understates the link: the link carries everything. A screen that says
	 * "these four tools" while the link answers with nine is lying by omission, so when
	 * this returns true the screen adds a line saying so.
	 *
	 * @param string $slug Plugin asking.
	 * @return bool
	 */
	public function has_others( $slug ) {
		$slug = sanitize_key( $slug );

		foreach ( $this->plugins as $registered => $plugin ) {
			if ( $registered !== $slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every registered tool, in MCP's shape, keyed by tool name.
	 *
	 * Write tools are dropped when the merchant has asked for a read-only link. They are
	 * removed from the LIST, not merely refused when called: a tool an AI can see is a tool
	 * it will try, and an assistant that keeps offering to do something the store will not
	 * allow is worse than one that never offers.
	 *
	 * @return array
	 */
	public function tools() {
		$writes = Zorem_MCP_Settings::writes_enabled();
		$out    = array();

		foreach ( $this->plugins as $plugin ) {
			foreach ( $plugin['tools'] as $short => $tool ) {
				if ( ! empty( $tool['writes'] ) && ! $writes ) {
					continue;
				}

				$name = $plugin['slug'] . '-' . sanitize_key( $short );

				/*
				 * The plugin's label, exactly as the plugin wrote it.
				 *
				 * This used to strip a leading "admin-" on the way past, so that
				 * "admin-update-return-status" read as "Update return status". It looked
				 * tidier and it was not the library's call to make: a plugin that writes
				 * "Admin: update return status" means it -- the prefix tells a merchant which
				 * of that plugin's two audiences the tool belongs to, and a library quietly
				 * deleting it is a library editing somebody else's words.
				 *
				 * The fallback below exists only for a plugin that supplies no label at all,
				 * and it does the least it can: separators to spaces, first letter up. It
				 * removes nothing.
				 */
				$label = isset( $tool['label'] ) && '' !== $tool['label']
					? $tool['label']
					: ucfirst( str_replace( array( '-', '_' ), ' ', $short ) );

				$out[ $name ] = array(
					'name'        => $name,
					'title'       => $plugin['prefix'] . $label,
					'description' => isset( $tool['description'] ) ? $tool['description'] : '',
					'inputSchema' => isset( $tool['input_schema'] ) ? $tool['input_schema'] : array( 'type' => 'object' ),
					'annotations' => array(
						'readOnlyHint'    => empty( $tool['writes'] ),
						'destructiveHint' => ! empty( $tool['destructive'] ),
					),
				);
			}
		}

		return $out;
	}

	/**
	 * Run one tool.
	 *
	 * The callback is the plugin's own: this library never touches a store's data itself,
	 * it only decides who may ask and writes down what was asked.
	 *
	 * @param string $name Tool name as advertised.
	 * @param array  $args Tool arguments.
	 * @return array|WP_Error
	 */
	public function call( $name, $args ) {
		$tool = $this->find( $name );

		if ( ! $tool ) {
			return new WP_Error(
				'zorem_mcp_unknown_tool',
				__( 'That tool is not available on this connection.', 'zorem-mcp' )
			);
		}

		if ( empty( $tool['callback'] ) || ! is_callable( $tool['callback'] ) ) {
			return new WP_Error(
				'zorem_mcp_not_executable',
				__( 'That tool cannot be run right now.', 'zorem-mcp' )
			);
		}

		return call_user_func( $tool['callback'], is_array( $args ) ? $args : array() );
	}

	/**
	 * Find a tool definition by its advertised name, honouring the same write gate the
	 * list does -- otherwise a client that remembered a name from a previous session could
	 * still call a tool the merchant has since switched off.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	protected function find( $name ) {
		$writes = Zorem_MCP_Settings::writes_enabled();

		foreach ( $this->plugins as $plugin ) {
			foreach ( $plugin['tools'] as $short => $tool ) {
				if ( $plugin['slug'] . '-' . sanitize_key( $short ) !== $name ) {
					continue;
				}

				if ( ! empty( $tool['writes'] ) && ! $writes ) {
					return null;
				}

				return $tool;
			}
		}

		return null;
	}

	/**
	 * Which plugin a tool name belongs to. Used by the audit log so a merchant reading it
	 * can see which plugin was asked, not just which tool.
	 *
	 * @param string $name Tool name.
	 * @return string Plugin slug, or an empty string.
	 */
	public function owner( $name ) {
		foreach ( $this->plugins as $plugin ) {
			if ( 0 === strpos( (string) $name, $plugin['slug'] . '-' ) ) {
				return $plugin['slug'];
			}
		}

		return '';
	}
}
