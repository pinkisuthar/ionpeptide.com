<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- this package is shared across the Zorem plugin family; its own zorem_mcp_ prefix is the point.
/**
 * Zorem MCP - shared AI connection for the Zorem plugin family.
 *
 * WHAT THIS IS
 *
 * One MCP server, one connection link, one audit log -- shared by every Zorem plugin that
 * ships this package. A merchant connects their AI assistant once and gets the tools from
 * whichever Zorem plugins they have, instead of a separate setup per plugin.
 *
 * Each plugin bundles a copy of this folder, exactly as they all bundle `zui`. The copies
 * are identical; only one ever runs.
 *
 * WHICH COPY RUNS
 *
 * The first copy to load claims the namespace, and every later copy returns immediately.
 * That makes load order decide it, which is fine while the copies match -- but they will
 * not always match, because plugins update on their own schedules. So the guard is on the
 * VERSION, not merely on "has something already loaded": a newer copy that arrives first
 * wins, and an older one that arrives first is allowed to be replaced.
 *
 * This matters more here than it does for zui. zui is stylesheets; a stale copy is a
 * cosmetic problem. This package owns the connection token, the settings and a database
 * table, so an older copy reading a newer copy's data is a real one.
 *
 * LICENSING
 *
 * Nothing here is behind a licence. The library is the plumbing -- routes, token, audit,
 * settings screen -- and it stays available as long as any Zorem plugin is installed. What
 * a licence gates is TOOLS: each plugin checks its own licence before registering its own
 * tools, so an expired licence removes that plugin's tools and leaves everything else,
 * including the merchant's existing connection, working.
 *
 * @package Zorem_MCP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zorem_mcp_register_package' ) ) {
	/**
	 * Offer this copy of the package. The highest version offered on the request wins.
	 *
	 * Called at plugin load time, before anything is instantiated, so every copy present
	 * has had its say before the winner is booted on `plugins_loaded`.
	 *
	 * @param string $version Version of the calling copy.
	 * @param string $dir     Absolute path to the calling copy's folder.
	 * @return void
	 */
	function zorem_mcp_register_package( $version, $dir ) {
		global $zorem_mcp_package;

		if ( isset( $zorem_mcp_package['version'] ) && version_compare( $zorem_mcp_package['version'], $version, '>=' ) ) {
			return;
		}

		$zorem_mcp_package = array(
			'version' => (string) $version,
			'dir'     => rtrim( (string) $dir, '/\\' ),
		);
	}
}

if ( ! function_exists( 'zorem_mcp_boot' ) ) {
	/**
	 * Load the winning copy.
	 *
	 * Hooked once, on `plugins_loaded` at an early priority: by then every plugin file has
	 * run and offered its copy, and nothing that consumes the library has started yet.
	 *
	 * @return void
	 */
	function zorem_mcp_boot() {
		global $zorem_mcp_package;

		if ( defined( 'ZOREM_MCP_VERSION' ) || empty( $zorem_mcp_package['dir'] ) ) {
			return;
		}

		define( 'ZOREM_MCP_VERSION', $zorem_mcp_package['version'] );
		define( 'ZOREM_MCP_DIR', $zorem_mcp_package['dir'] );

		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-settings.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-registry.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-audit.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-keys.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-oauth.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-htaccess.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-woo-key.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-abilities.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-server.php';
		require_once ZOREM_MCP_DIR . '/class-zorem-mcp-selftest.php';
		require_once ZOREM_MCP_DIR . '/ui-helpers.php';
		require_once ZOREM_MCP_DIR . '/screen.php';

		/*
		 * The library's own translations, from the library's own folder.
		 *
		 * Its strings used to carry AST PRO's text domain -- all 248 of them -- which meant
		 * that on any other plugin they could never be translated at all: the domain named a
		 * plugin that might not even be installed. They are 'zorem-mcp' now, and this loads
		 * that domain from whichever copy of the package won the version check, so the
		 * translations travel with the code they belong to.
		 */
		$zorem_mcp_lang = ZOREM_MCP_DIR . '/languages/zorem-mcp-' . determine_locale() . '.mo';

		if ( file_exists( $zorem_mcp_lang ) ) {
			load_textdomain( 'zorem-mcp', $zorem_mcp_lang );
		}

		Zorem_MCP_Audit::init();
		Zorem_MCP_Keys::init();
		Zorem_MCP_OAuth::init();
		Zorem_MCP_Htaccess::init();
		Zorem_MCP_Woo_Key::init();
		Zorem_MCP_Abilities::init();
		Zorem_MCP_Selftest::init();
		Zorem_MCP_Server::instance()->init();

		/*
		 * Tool sets carry translated labels and descriptions, so registration must wait for
		 * `init`: WordPress 6.7+ warns (_load_textdomain_just_in_time) if any text domain is
		 * translated before then. We are on `plugins_loaded` here, so defer the announcement to
		 * `init` at an early priority -- still well before wp_abilities_api_init (which fires lazily
		 * on the first WP_Abilities_Registry::get_instance() after init) and before rest_api_init,
		 * so the registry is fully populated by the time either the abilities or the link read it.
		 */
		add_action(
			'init',
			static function () {
				/**
				 * Fires once the shared MCP library is loaded and ready to be given tools.
				 *
				 * Every plugin hooks this, checks its OWN licence, and registers its own tool set.
				 *
				 * @since 1.0.0
				 *
				 * @param Zorem_MCP_Registry $registry The shared registry.
				 */
				do_action( 'zorem_mcp_loaded', Zorem_MCP_Registry::instance() );
			},
			0
		);
	}
}

add_action( 'plugins_loaded', 'zorem_mcp_boot', 1 );
