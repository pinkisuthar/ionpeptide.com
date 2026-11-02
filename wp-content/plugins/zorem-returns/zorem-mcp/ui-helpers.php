<?php
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- this library owns the 'zorem-mcp' domain and loads it itself; Plugin Check compares against the host plugin's folder name, which is a different plugin every time this folder ships.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- hook and function names are a public API shared with the free plugin; renaming them breaks customer snippets.
/**
 * Zorem MCP - view helpers for the AI Assistant screen.
 *
 * Shared because the screen is: every Zorem plugin shows the same tab over the same
 * connection, and only two things differ -- the plugin's own name, and its own tools. Both
 * arrive as arguments, so nothing here knows or cares which plugin is drawing it.
 *
 * Markup uses zui classes only. The settings FIELDS are still rendered by the host plugin's
 * own renderer, because that is the one part each plugin owns; everything else -- the status
 * rows, the client instructions, the steps, the disclosures -- is emitted here.
 *
 * The screen's whole job is telling the merchant the truth about what an AI can currently
 * do with their store, so every status is MEASURED, never assumed.
 *
 * @package Zorem_MCP
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zorem_mcp_woo_mcp_active' ) ) {
	/**
	 * Whether WooCommerce's own MCP feature is switched on for this store.
	 *
	 * @return bool
	 */
	function zorem_mcp_woo_mcp_active() {
		// Asked directly rather than through any plugin: whether WooCommerce's own MCP
		// feature is on is a fact about the store, and every Zorem plugin's screen reports
		// the same answer.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return false;
		}

		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' )
			&& method_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil', 'feature_is_enabled' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'mcp_integration' );
		}

		return 'yes' === get_option( 'woocommerce_feature_mcp_integration_enabled' );
	}
}

if ( ! function_exists( 'zorem_mcp_own_connection_active' ) ) {
	/**
	 * Whether AST PRO's own MCP endpoint is switched on AND has issued a URL.
	 *
	 * @return bool
	 */
	function zorem_mcp_own_connection_active() {
		return class_exists( 'Zorem_MCP_Server' )
			&& Zorem_MCP_Settings::enabled()
			&& '' !== Zorem_MCP_Server::endpoint_url();
	}
}

if ( ! function_exists( 'zorem_mcp_writes_allowed' ) ) {
	/**
	 * Whether the AI may change tracking through AST PRO's own connection.
	 *
	 * @return bool
	 */
	function zorem_mcp_writes_allowed() {
		return class_exists( 'Zorem_MCP_Settings' ) && Zorem_MCP_Settings::writes_enabled();
	}
}

if ( ! function_exists( 'zorem_mcp_endpoint_field_id' ) ) {
	/**
	 * The id of the input holding the connection URL.
	 *
	 * One source of truth because two places need to agree on it and they are far apart: the
	 * row that renders the input, and every per-client Copy button, which reaches the input by
	 * selector. They drifted once, when the package was renamed and the row moved to the new
	 * id while the buttons kept asking for the old one. The symptom was silent: a copy button
	 * that finds no field simply does nothing at all.
	 *
	 * @return string
	 */
	function zorem_mcp_endpoint_field_id() {
		return 'zorem-mcp-endpoint';
	}
}

if ( ! function_exists( 'zorem_mcp_registered_tools' ) ) {
	/**
	 * One plugin's tools, as its own screen should list them.
	 *
	 * Read from the shared registry, which is the same array the connection answers with --
	 * so a tool listed here is a tool that exists, and one withheld (because the merchant
	 * set the link read-only) is absent from both.
	 *
	 * The plugin prefix is stripped back off the title. On the connection it is what keeps
	 * one plugin's tools together in a flat list; on this screen the whole card is already
	 * that plugin's, and repeating the name on every row would be noise.
	 *
	 * @param string $slug Plugin slug.
	 * @return array List of array( 'name', 'label', 'description', 'writes', 'destructive' ).
	 */
	function zorem_mcp_registered_tools( $slug ) {
		if ( ! class_exists( 'Zorem_MCP_Registry' ) ) {
			return array();
		}

		$registry = Zorem_MCP_Registry::instance();
		$plugin   = $registry->plugin( $slug );

		if ( ! $plugin ) {
			return array();
		}

		$prefix = $plugin['prefix'];
		$out    = array();

		foreach ( $registry->tools() as $name => $tool ) {
			if ( $registry->owner( $name ) !== $slug ) {
				continue;
			}

			$label = $tool['title'];

			if ( '' !== $prefix && 0 === strpos( $label, $prefix ) ) {
				$label = substr( $label, strlen( $prefix ) );
			}

			$out[] = array(
				'name'        => $name,
				'label'       => $label,
				'description' => $tool['description'],
				'writes'      => empty( $tool['annotations']['readOnlyHint'] ),
				'destructive' => ! empty( $tool['annotations']['destructiveHint'] ),
			);
		}

		return $out;
	}
}

if ( ! function_exists( 'zorem_mcp_other_plugins' ) ) {
	/**
	 * The other Zorem plugins sharing this connection, by name.
	 *
	 * A plugin's screen lists its own tools, which is clear but understates the link: the
	 * link carries everything. Saying "these three tools" while the link answers with eight
	 * is a lie by omission, and the kind a merchant acts on -- they hand the link out
	 * believing it reaches less than it does. So when this returns anything, the screen
	 * says so.
	 *
	 * @param string $slug Plugin asking.
	 * @return array Labels of the other plugins.
	 */
	function zorem_mcp_other_plugins( $slug ) {
		if ( ! class_exists( 'Zorem_MCP_Registry' ) ) {
			return array();
		}

		$others = array();

		foreach ( Zorem_MCP_Registry::instance()->plugins() as $registered => $plugin ) {
			if ( $registered !== $slug ) {
				$others[] = $plugin['label'];
			}
		}

		return $others;
	}
}

if ( ! function_exists( 'zorem_mcp_shared_link_note' ) ) {
	/**
	 * Echo the line admitting the link is wider than this screen.
	 *
	 * Silent when this plugin is the only one registered, because then there is nothing to
	 * admit and the list above is the whole truth.
	 *
	 * @param string $slug Plugin asking.
	 * @return void
	 */
	function zorem_mcp_shared_link_note( $slug ) {
		$others = zorem_mcp_other_plugins( $slug );

		if ( empty( $others ) ) {
			return;
		}

		?>
		<p class="zorem-mcp-clients__note">
			<?php
			printf(
				/* translators: %s: names of the other plugins, e.g. "Returns and Exchanges". */
				esc_html__( 'This link also carries tools from %s. See its own screen for what those do.', 'zorem-mcp' ),
				esc_html( implode( ', ', $others ) )
			);
			?>
		</p>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_client_targets' ) ) {
	/**
	 * The AI clients a connection can be handed to, and how each one takes it.
	 *
	 * Copying a URL is the easy half; a merchant who has never set up an MCP server is
	 * stuck on the other half -- where does this go? So each client gets a row, and each
	 * row does as much of the work as that client actually allows:
	 *
	 *   'link'    A deeplink the client itself handles: one click and the server is added.
	 *             Only Cursor and VS Code publish one. Claude has no such scheme, so no
	 *             button is offered there -- a button that silently does nothing is worse
	 *             than an instruction that works.
	 *   'command' Ready text (a terminal command, or a config block), with a Copy button.
	 *   'paste'   Nothing to automate. The client wants the URL in its own connector UI,
	 *             so the row says exactly which screen.
	 *   'none'    That client genuinely cannot reach this route. Say so plainly and point
	 *             at the one that works.
	 *
	 * The two routes are NOT interchangeable, which is why one function serves both: the
	 * differences are all in this table, where they can be seen side by side.
	 *
	 *   AST PRO ($key empty)  The token lives in the URL, so anything that accepts a URL
	 *                         works -- including claude.ai and Claude Desktop's connector
	 *                         UI, neither of which can send a custom header.
	 *   WooCommerce ($key)    The key must travel as an X-MCP-API-Key header. Claude's
	 *                         connector UI has nowhere to put one, so Claude Desktop needs
	 *                         a config file and claude.ai cannot do it at all.
	 *
	 * Every value here embeds a secret -- the token or the key -- so all of it is built
	 * only for a user already allowed to see it.
	 *
	 * @param string $url The connection URL.
	 * @param string $key Optional WooCommerce key as "ck_...:cs_...". Empty for the shared link.
	 * @return array List of array( 'name', 'note', 'action', ... ).
	 */
	function zorem_mcp_client_targets( $url, $key = '' ) {
		if ( '' === $url ) {
			return array();
		}

		$woo = ( '' !== $key );

		/*
		 * The server name is what a client files the connection under, so the two routes on
		 * a store must not share one: installing the second would quietly overwrite the
		 * first.
		 *
		 * "zorem", not any one plugin's name. This said 'ast-pro', and a merchant with only
		 * Zorem Returns installed was told by Claude Desktop, Cursor and the CLI alike to
		 * file their connection under a plugin they do not own -- while the link itself
		 * carries the tools of every Zorem plugin on the store, which is the whole point of
		 * there being one link.
		 *
		 * The WooCommerce route keeps WooCommerce's name because that is genuinely whose
		 * server it is; our plugins only contribute tools to it.
		 */
		$name = $woo ? 'woocommerce' : 'zorem';

		$cursor_config = array( 'url' => $url );

		if ( $woo ) {
			$cursor_config['headers'] = array( 'X-MCP-API-Key' => $key );
		}

		// Cursor takes the name separately and the rest as base64 JSON. The base64 is
		// URL-encoded on top: raw base64 can contain '+', which a query parser reads as
		// a space and would hand Cursor a broken URL.
		$cursor = 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( $name )
			. '&config=' . rawurlencode( base64_encode( (string) wp_json_encode( $cursor_config ) ) );

		if ( $woo ) {
			$targets = array(
				array(
					'name'   => __( 'claude.ai', 'zorem-mcp' ),
					'note'   => __( 'Cannot send an API key header. Use your own link for claude.ai and for phones.', 'zorem-mcp' ),
					'action' => 'none',
					'match'  => array( 'claude-ai', 'claude.ai' ),
				),
				array(
					'name'   => __( 'ChatGPT', 'zorem-mcp' ),
					'note'   => __( 'Cannot send an API key header either. Use your own link for ChatGPT.', 'zorem-mcp' ),
					'action' => 'none',
					'match'  => array( 'chatgpt', 'openai' ),
				),
				/*
				 * One Claude Desktop row, not two.
				 *
				 * There was a second, offering a prompt to paste into Claude Desktop so it
				 * would edit its own settings file. It reads well and it does not work:
				 * claude_desktop_config.json sits in a protected folder that Claude Desktop
				 * will not grant itself access to, so the attempt ends in a folder picker,
				 * a refusal, and a merchant who now believes the connection is broken.
				 *
				 * The file edit is the only route that works, so it is the only one offered,
				 * with the whole path to it rather than "by hand" as an apology.
				 */
				array(
					'name'    => __( 'Claude Desktop', 'zorem-mcp' ),
					'note'    => __( 'In Claude Desktop: Settings > Developer > Edit Config. Paste this inside "mcpServers", save, and restart Claude Desktop.', 'zorem-mcp' ),
					'action'  => 'command',
					'label'   => __( 'Copy config', 'zorem-mcp' ),
					'command' => zorem_mcp_desktop_config_block( $name, $url, $key ),
					'match'   => array( 'claude desktop', 'claude-desktop' ),
				),
			);
		} else {
			$targets = array(
				array(
					'name'   => __( 'claude.ai', 'zorem-mcp' ),
					'note'   => __( 'Settings > Connectors > Add custom connector, then paste the URL.', 'zorem-mcp' ),
					'action' => 'paste',
					'match'  => array( 'claude-ai', 'claude.ai' ),
				),
				array(
					'name'   => __( 'ChatGPT', 'zorem-mcp' ),
					'note'   => __( 'Turn on Developer mode in the ChatGPT settings first, then add the URL as a connector. OpenAI still ships this as a developer feature, so it is not on every plan yet.', 'zorem-mcp' ),
					'action' => 'paste',
					'match'  => array( 'chatgpt', 'openai' ),
				),
				array(
					'name'   => __( 'Claude Desktop', 'zorem-mcp' ),
					'note'   => __( 'Settings > Connectors > Add custom connector, then paste the URL. No config file to edit.', 'zorem-mcp' ),
					'action' => 'paste',
					'match'  => array( 'claude desktop', 'claude-desktop' ),
				),
			);
		}

		/*
		 * NO CLAUDE CODE ROW.
		 *
		 * It worked -- one command pasted into a terminal and the connection was made -- and
		 * it was on the wrong screen. This screen belongs to a shop owner: somebody who looks
		 * at orders, adds tracking, approves returns. "Run this once in your terminal" is not
		 * a sentence they can act on, and an instruction a reader cannot act on does not sit
		 * there harmlessly; it teaches them that parts of this screen are not for them, and
		 * that is a bad thing to teach on a screen you want them to trust.
		 *
		 * A developer who wants it does not need a button. They have the link, and
		 * `claude mcp add --transport http <name> "<url>"` is the whole of it.
		 */
		$targets[] = array(
			'name'   => __( 'Cursor', 'zorem-mcp' ),
			'note'   => __( 'Opens Cursor and adds the server.', 'zorem-mcp' ),
			'action' => 'link',
			'label'  => __( 'Add to Cursor', 'zorem-mcp' ),
			'link'   => $cursor,
			'match'  => array( 'cursor' ),
		);

		/*
		 * NO VS CODE ROW, FOR NOW.
		 *
		 * Everything on this server's side of a VS Code connection is correct and has been
		 * checked against a live store and against VS Code's own source: the 401 carries the
		 * right WWW-Authenticate, both discovery documents are found and valid, dynamic client
		 * registration answers 201 keeping all four of the redirect URIs VS Code sends, and
		 * authorize reaches the consent screen. VS Code still never creates the authentication
		 * provider it needs, and fails where it does not tell the merchant why -- its own
		 * message goes to the Window log, not to the MCP output the merchant is watching.
		 *
		 * So the button offered something that does not work and gave no way to find out why.
		 * A row that cannot succeed is worse than no row: the merchant presses it, nothing
		 * happens, and they conclude the whole feature is broken.
		 *
		 * Claude, ChatGPT, Claude Desktop, Claude Code and Cursor are all unaffected and all
		 * work. To put the row back once VS Code connects, this is the whole of it:
		 *
		 *     $config = array( 'name' => $name, 'type' => 'http', 'url' => $url );
		 *
		 *     if ( $woo ) {
		 *         $config['headers'] = array( 'X-MCP-API-Key' => $key );
		 *     }
		 *
		 *     $targets[] = array(
		 *         'name'   => __( 'VS Code', 'zorem-mcp' ),
		 *         'note'   => __( 'Opens VS Code and adds the server.', 'zorem-mcp' ),
		 *         'action' => 'link',
		 *         'label'  => __( 'Add to VS Code', 'zorem-mcp' ),
		 *         'link'   => 'vscode:mcp/install?' . rawurlencode( (string) wp_json_encode( $config ) ),
		 *         'match'  => array( 'visual studio code', 'vscode', 'vs code' ),
		 *     );
		 */

		/**
		 * Filter the AI clients offered on the connection screen.
		 *
		 * @since 5.0.4
		 *
		 * @param array  $targets Client rows.
		 * @param string $url     The connection URL.
		 * @param string $key     WooCommerce key, or '' for the AST PRO route.
		 */
		return apply_filters( 'zorem_mcp_client_targets', $targets, $url, $key );
	}
}

if ( ! function_exists( 'zorem_mcp_desktop_config_block' ) ) {
	/**
	 * The claude_desktop_config.json entry for a header-authenticated server.
	 *
	 * Claude Desktop launches local processes over stdio and has no field for a header,
	 * so mcp-remote bridges the remote HTTP server into that transport. The key travels
	 * through mcp-remote's environment rather than inline in --header because mcp-remote
	 * splits that argument on the first colon, and a WooCommerce key is itself
	 * "ck_...:cs_..." -- inlined, the split would tear it in half.
	 *
	 * Returned as the inner property only, since it is pasted into an mcpServers object
	 * the merchant already has open.
	 *
	 * @param string $name Server name.
	 * @param string $url  Endpoint URL.
	 * @param string $key  WooCommerce key as "ck_...:cs_...".
	 * @return string Pretty-printed JSON fragment.
	 */
	function zorem_mcp_desktop_config_block( $name, $url, $key ) {
		$block = (string) wp_json_encode(
			array(
				$name => array(
					'command' => 'npx',
					'args'    => array( '-y', 'mcp-remote', $url, '--header', 'X-MCP-API-Key:${MCP_KEY}' ),
					'env'     => array( 'MCP_KEY' => $key ),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Drop the wrapping braces: what is wanted is the property, not a whole object.
		$block = trim( (string) preg_replace( '/^\{\s*|\s*\}$/', '', trim( $block ) ) );

		// json_encode indented every line for a wrapper that is now gone, so pull the body
		// back one level. Without this the block lands in the merchant's file a step to the
		// right of the servers either side of it.
		return (string) preg_replace( '/^    /m', '', $block );
	}
}

if ( ! function_exists( 'zorem_mcp_client_seen' ) ) {
	/**
	 * When this client last ran something, if it ever has.
	 *
	 * This is a web server: what sits in a config file on somebody's laptop is not
	 * knowable from here. What IS knowable is which apps have actually called, because
	 * every call is recorded with the name the app announced in its MCP handshake. So the
	 * row reports use, not configuration -- and "it has worked" is the more useful thing
	 * to tell a merchant anyway.
	 *
	 * Matching is by substring, because those names belong to the apps and are not
	 * consistent: VS Code says "Visual Studio Code", Claude Code says "claude-code". An app
	 * nobody recognises still appears in the audit log under whatever it called itself; it
	 * simply does not light up a row here. That is the right way round -- a row claiming a
	 * connection that is not there would send someone hunting for a fault in the wrong
	 * place.
	 *
	 * @param array $target One entry from zorem_mcp_client_targets().
	 * @return int|null UTC timestamp, or null if this app has never called.
	 */
	function zorem_mcp_client_seen( $target ) {
		if ( empty( $target['match'] ) || ! class_exists( 'Zorem_MCP_Audit' ) ) {
			return null;
		}

		$latest = null;

		foreach ( Zorem_MCP_Audit::clients_seen( 'zorem-link' ) as $client => $when ) {
			foreach ( (array) $target['match'] as $needle ) {
				if ( false !== strpos( $client, $needle ) && ( null === $latest || $when > $latest ) ) {
					$latest = $when;
				}
			}
		}

		return $latest;
	}
}

if ( ! function_exists( 'zorem_mcp_client_row' ) ) {
	/**
	 * Echo one client row: name, what to do, and the control that does it.
	 *
	 * @param array $target One entry from zorem_mcp_client_targets().
	 * @return void
	 */
	function zorem_mcp_client_row( $target ) {
		?>
		<?php $seen = zorem_mcp_client_seen( $target ); ?>
		<div class="zorem-mcp-client">
			<div class="zorem-mcp-client__head">
				<span class="zorem-mcp-client__name">
					<?php echo esc_html( $target['name'] ); ?>
					<?php if ( $seen ) : ?>
						<span class="zorem-mcp-badge zorem-mcp-badge--ok"><?php esc_html_e( 'In use', 'zorem-mcp' ); ?></span>
					<?php endif; ?>
				</span>
				<p class="zorem-mcp-client__note">
					<?php if ( $seen ) : ?>
						<?php
						printf(
							/* translators: %s: how long ago this app last ran a tool, e.g. "5 mins". */
							esc_html__( 'Connected and working — last used %s ago.', 'zorem-mcp' ),
							esc_html( human_time_diff( $seen, time() ) )
						);
						?>
					<?php else : ?>
						<?php echo esc_html( $target['note'] ); ?>
					<?php endif; ?>
				</p>
			</div>
			<div class="zorem-mcp-client__control">
				<?php if ( 'link' === $target['action'] ) : ?>
					<?php
					/*
					 * 'cursor' and 'vscode' are not in wp_allowed_protocols(), so esc_url()
					 * would return an empty string for these without being told about them.
					 */
					?>
					<?php
					/*
					 * Reset sits beside Add, because that is where a merchant is standing when
					 * they need it: they have pressed Add, the app has done nothing visible,
					 * and pressing Add again does nothing visible either.
					 *
					 * It cannot reach inside the app -- there is no uninstall deeplink for
					 * either editor, and this page has no business in their settings anyway.
					 * What it can do is withdraw the token the app is holding, which is the
					 * actual reason the app is not asking to sign in. With the token gone the
					 * next call is refused, and the app has to start over.
					 *
					 * Shown whether or not anything is currently connected. It was hidden
					 * when nothing was -- which sounds reasonable and was exactly backwards:
					 * a merchant who has just signed everyone out is the likeliest person to
					 * need it, and they found the button gone. Turning off nothing costs
					 * nothing, and reopening the app is half the point of pressing it.
					 */
					if ( ! empty( $target['match'] ) ) :
						?>
						<button type="button" class="zorem-mcp-reset" data-zorem-mcp-reset="<?php echo esc_attr( implode( '|', (array) $target['match'] ) ); ?>"
							data-label-working="<?php esc_attr_e( 'Resetting…', 'zorem-mcp' ); ?>"
							data-zorem-mcp-confirm="<?php
								/* translators: %s: the AI app's name, e.g. Cursor. */
								echo esc_attr( sprintf( __( 'Make %s sign in again? Its connection is turned off here, so the next time it calls it has to ask you to sign in. Nothing else is affected.', 'zorem-mcp' ), $target['name'] ) );
							?>">
							<?php esc_html_e( 'Reset', 'zorem-mcp' ); ?>
						</button>
						<?php
					endif;
					?>
					<a class="zui-btn-secondary" href="<?php echo esc_url( $target['link'], array( 'cursor', 'vscode' ) ); ?>"
						data-zorem-mcp-open
						data-label-opening="<?php
							/* translators: %s: the AI app's name, e.g. Cursor. */
							echo esc_attr( sprintf( __( 'Opening %s…', 'zorem-mcp' ), $target['name'] ) );
						?>"
						data-label-missing="<?php
							/* translators: %s: the AI app's name, e.g. Cursor. */
							echo esc_attr( sprintf( __( 'Is %s installed?', 'zorem-mcp' ), $target['name'] ) );
						?>">
						<?php echo esc_html( $target['label'] ); ?>
					</a>
				<?php elseif ( 'command' === $target['action'] ) : ?>
					<button type="button" class="zui-btn-secondary"
						data-zorem-mcp-copy-text="<?php echo esc_attr( $target['command'] ); ?>"
						data-label-copied="<?php esc_attr_e( 'Copied', 'zorem-mcp' ); ?>"
						data-label-copyfailed="<?php esc_attr_e( 'Copy failed — press Ctrl+C', 'zorem-mcp' ); ?>">
						<?php echo esc_html( $target['label'] ); ?>
					</button>
				<?php elseif ( 'none' === $target['action'] ) : ?>
					<span class="zorem-mcp-client__unavailable"><?php esc_html_e( 'Not supported', 'zorem-mcp' ); ?></span>
				<?php else : ?>
					<button type="button" class="zui-btn-secondary"
						data-zorem-mcp-copy="#<?php echo esc_attr( zorem_mcp_endpoint_field_id() ); ?>"
						data-label-copied="<?php esc_attr_e( 'Copied', 'zorem-mcp' ); ?>"
						data-label-copyfailed="<?php esc_attr_e( 'Copy failed — press Ctrl+C', 'zorem-mcp' ); ?>">
						<?php esc_html_e( 'Copy URL', 'zorem-mcp' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_enqueue_assets' ) ) {
	/**
	 * Load the connection screen's stylesheet and script.
	 *
	 * Called by whichever plugin is drawing the screen, from its own admin_enqueue_scripts
	 * handler, so the assets land on that plugin's settings page and nowhere else -- the
	 * library has no way of knowing which screens those are, and guessing would put them on
	 * every admin page in the site.
	 *
	 * Registered under one handle, so several plugins asking for it on the same request get
	 * one copy. Versioned by the file's own timestamp: the package updates on its own
	 * schedule, independently of any plugin's version number, and a merchant should never
	 * have to hard-refresh to get a fixed screen.
	 *
	 * @return void
	 */
	function zorem_mcp_enqueue_assets() {
		if ( ! defined( 'ZOREM_MCP_DIR' ) ) {
			return;
		}

		$base = ZOREM_MCP_DIR . '/assets/';
		$url  = plugins_url( 'assets/', ZOREM_MCP_DIR . '/bootstrap.php' );

		$css = $base . 'zorem-mcp.css';
		$js  = $base . 'zorem-mcp.js';

		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'zorem-mcp', $url . 'zorem-mcp.css', array(), (string) filemtime( $css ) );
		}

		if ( file_exists( $js ) ) {
			wp_enqueue_script( 'zorem-mcp', $url . 'zorem-mcp.js', array( 'jquery' ), (string) filemtime( $js ), true );
		}
	}
}

if ( ! function_exists( 'zorem_mcp_tool_icon' ) ) {
	/**
	 * The glyph for one power, as inline SVG.
	 *
	 * Decorative on purpose -- aria-hidden, because the word next to it already says the same
	 * thing, and a screen reader announcing "eye Read" is worse than one announcing "Read".
	 * What the icon is for is the case colour cannot cover: a printed page, a high-contrast
	 * mode, or a merchant who does not separate amber from red. Drawn in currentColor so it
	 * inherits whatever tint the chip carries and can never drift from it.
	 *
	 * @param string $kind 'read', 'write' or 'delete'.
	 * @return string Inline SVG markup. Built from a fixed map -- no caller input reaches it.
	 */
	function zorem_mcp_tool_icon( $kind ) {
		$paths = array(
			// Eye.
			'read'   => '<path d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8Z"/><circle cx="8" cy="8" r="1.9"/>',
			// Pencil.
			'write'  => '<path d="M11 2.4 13.6 5 5.6 13H3v-2.6Z" stroke-linejoin="round"/>',
			// Bin.
			'delete' => '<path d="M2.5 4h11M6 4V2.5h4V4M4 4l.7 9.5h6.6L12 4" stroke-linecap="round"/>',
		);

		if ( ! isset( $paths[ $kind ] ) ) {
			return '';
		}

		return '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true" focusable="false">' . $paths[ $kind ] . '</svg>';
	}
}

if ( ! function_exists( 'zorem_mcp_render_tools' ) ) {
	/**
	 * Echo the "what the AI can do" list for one plugin.
	 *
	 * The list itself is the one thing on this screen that genuinely differs between plugins,
	 * which is exactly why it is the thing they should NOT be writing the markup for. Each
	 * plugin says which tools are its own -- the registry already knows -- and gets the same
	 * two-column list, the same read/write wording and the same note underneath as everywhere
	 * else. Otherwise the tools differ AND their presentation drifts, and a merchant reading
	 * two Zorem screens has to learn two layouts to answer one question.
	 *
	 * WHY THE BADGE IS ONE WORD, AND THE SAME WORD EVERYWHERE
	 *
	 * The right-hand label used to be a sentence each plugin wrote for itself -- "Changes
	 * orders" here, "Changes returns" there -- which read well in isolation and badly in a
	 * list: two plugins on one link described the same permission in two vocabularies, so
	 * nothing could be scanned. It also collapsed two different powers into one phrase. A
	 * tool that adds tracking and a tool that deletes it are not the same risk, and a
	 * merchant deciding whether to leave writes switched on is deciding exactly between them.
	 *
	 * So: Read, Write, Delete. Taken from the annotations the tools already advertise to the
	 * AI -- the same facts the model is told, shown to the person who owns the store, which
	 * is the only way the two can be trusted to agree.
	 *
	 * @param string $slug The plugin's slug on the shared connection.
	 * @param array  $args {
	 *     @type string $empty Shown when the plugin has contributed no tools.
	 *     @type string $note  Shown under the list.
	 * }
	 * @return void
	 */
	function zorem_mcp_render_tools( $slug, $args = array() ) {
		$args = array_merge(
			array(
				'empty' => __( 'No tools are available on this connection yet.', 'zorem-mcp' ),
				'note'  => '',
			),
			is_array( $args ) ? $args : array()
		);

		$tools = zorem_mcp_registered_tools( $slug );
		?>
		<?php /* One tight line per tool. Not .zui-row -- that carries 32px of padding meant for
		         a form control, which turns a four-item list into a screenful. */ ?>
		<div class="zorem-mcp-tools">
			<?php if ( empty( $tools ) ) : ?>
				<p class="zorem-mcp-tools__note"><?php echo esc_html( $args['empty'] ); ?></p>
			<?php else : ?>
				<?php foreach ( $tools as $tool ) : ?>
					<?php
					if ( ! empty( $tool['destructive'] ) ) {
						$kind = 'delete';
						$word = __( 'Delete', 'zorem-mcp' );
					} elseif ( ! empty( $tool['writes'] ) ) {
						$kind = 'write';
						$word = __( 'Write', 'zorem-mcp' );
					} else {
						$kind = 'read';
						$word = __( 'Read', 'zorem-mcp' );
					}
					?>
					<div class="zorem-mcp-tools__item">
						<span class="zorem-mcp-tools__name"><?php echo esc_html( $tool['label'] ); ?></span>
						<span class="zorem-mcp-badge zorem-mcp-badge--tool zorem-mcp-badge--<?php echo esc_attr( $kind ); ?>"><?php echo zorem_mcp_tool_icon( $kind ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup from a local map, no input reaches it. ?><?php echo esc_html( $word ); ?></span>
					</div>
				<?php endforeach; ?>
				<?php if ( '' !== $args['note'] ) : ?>
					<p class="zorem-mcp-tools__note"><?php echo esc_html( $args['note'] ); ?></p>
				<?php endif; ?>
				<?php zorem_mcp_shared_link_note( $slug ); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_woo_key_hint' ) ) {
	/**
	 * The sentence above the key fields, saying whether one is stored.
	 *
	 * Built here rather than inline because two things write it: this screen on load, and the
	 * reply to entering or forgetting a key, which repaints it without a page load. Two copies
	 * of a sentence drift, and the drift shows as a screen that contradicts itself.
	 *
	 * @param array $record What record() returned. Empty for "no key stored".
	 * @return string Escaped, ready to place as text.
	 */
	function zorem_mcp_woo_key_hint( $record ) {
		if ( empty( $record['truncated'] ) ) {
			return __( 'Make one in WooCommerce, then paste both halves here. Copy them before you leave that screen — the secret is shown once and never again.', 'zorem-mcp' );
		}

		return sprintf(
			/* translators: 1: last characters of the key, 2: how long ago it was entered. */
			__( 'A key ending %1$s was entered here %2$s ago, and your setup steps are below. Enter another to replace it.', 'zorem-mcp' ),
			$record['truncated'],
			human_time_diff( (int) $record['created'], time() )
		);
	}
}

if ( ! function_exists( 'zorem_mcp_woo_key_last_used' ) ) {
	/**
	 * When this key was last used, from whichever source actually knows.
	 *
	 * WooCommerce keeps last_access on the key row, but it is written by its REST
	 * authentication and the MCP transport does not go through it — so on a key used only by
	 * assistants that column stays empty forever, and the screen reported "never" directly
	 * above a table of sessions active minutes ago. The sessions are the better witness; the
	 * key row is the fallback for a key that reaches WooCommerce some other way.
	 *
	 * @param array $details What key_details() returned.
	 * @return string
	 */
	function zorem_mcp_woo_key_last_used( $details ) {
		$newest = 0;

		if ( class_exists( 'Zorem_MCP_Woo_Key' ) && method_exists( 'Zorem_MCP_Woo_Key', 'sessions' ) ) {
			foreach ( Zorem_MCP_Woo_Key::sessions() as $session ) {
				$newest = max( $newest, (int) $session['last'] );
			}
		}

		if ( ! $newest && ! empty( $details['last_access'] ) ) {
			$newest = (int) strtotime( $details['last_access'] );
		}

		if ( ! $newest ) {
			return __( 'never — nothing has connected with it yet', 'zorem-mcp' );
		}

		/* translators: %s: how long ago, e.g. "2 hours". */
		return sprintf( __( '%s ago', 'zorem-mcp' ), human_time_diff( $newest, time() ) );
	}
}

if ( ! function_exists( 'zorem_mcp_render_woo_connections' ) ) {
	/**
	 * Echo who currently holds a session on WooCommerce's MCP route.
	 *
	 * Apps rather than people, and no Revoke beside them, because neither is available here.
	 * Every session is the one account the key acts as, and closing a session would achieve
	 * nothing: the app still holds the key and opens another on its next request. The only
	 * way to end access is to delete the key, which is WooCommerce's screen to offer — so
	 * this says that instead of a button that looks like it works.
	 *
	 * @return void
	 */
	function zorem_mcp_render_woo_connections() {
		$sessions = class_exists( 'Zorem_MCP_Woo_Key' ) && method_exists( 'Zorem_MCP_Woo_Key', 'sessions' )
			? Zorem_MCP_Woo_Key::sessions()
			: array();

		if ( empty( $sessions ) ) {
			?>
			<div class="zorem-mcp-tools">
				<p class="zorem-mcp-tools__note"><?php esc_html_e( 'Nothing is connected with this key yet. Set up an app with the steps above and it will appear here.', 'zorem-mcp' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<?php
		$details = class_exists( 'Zorem_MCP_Woo_Key' ) ? Zorem_MCP_Woo_Key::key_details() : array();
		$acts_as = ! empty( $details['email'] ) ? $details['email'] : ( ! empty( $details['user'] ) ? $details['user'] : __( 'the key owner', 'zorem-mcp' ) );
		?>
		<?php /* The same shape as the shared link's connection list, which sits above it: a
		         merchant comparing the two routes is comparing exactly these rows. Every row
		         acts as the same account here, because that is all a WooCommerce key can be. */ ?>
		<div class="zorem-mcp-log">
		<table class="zorem-mcp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Acting as', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'App', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Connected', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'zorem-mcp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sessions as $session ) : ?>
					<?php
					/*
					 * No button on any row, for the same reason the shared link's rows have
					 * none: closing a session here would not take anything away. The app still
					 * holds the key and opens another session on its next request. Only
					 * deleting the key ends access, and that is WooCommerce's screen to offer.
					 */
					?>
					<tr>
						<td><?php echo esc_html( $acts_as ); ?></td>
						<?php
						/*
						 * The app, and nothing beside it.
						 *
						 * There was a session count here. An app opens a fresh session every
						 * time it reconnects -- a restart, a dropped network, mcp-remote's own
						 * retries -- so the number counted reconnections, which is machinery,
						 * not information: eight or two, there is nothing a merchant would do
						 * differently. What they came to read is on this row already.
						 */
						?>
						<td><?php echo esc_html( $session['app'] ); ?></td>
						<td><?php echo esc_html( $session['created'] ? date_i18n( 'Y-m-d', $session['created'] ) : '—' ); ?></td>
						<td>
							<?php
							echo esc_html(
								$session['last']
									/* translators: %s: how long ago, e.g. "2 hours". */
									? sprintf( __( '%s ago', 'zorem-mcp' ), human_time_diff( $session['last'], time() ) )
									: '—'
							);
							?>
						</td>
						<td>
							<span class="zorem-mcp-badge zorem-mcp-badge--ok"><?php esc_html_e( 'Active', 'zorem-mcp' ); ?></span>
						</td>
						<td></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php
		/*
		 * One button, under the table rather than on any row.
		 *
		 * There is no per-app revoke to offer: every row holds the same key, and closing one
		 * session only sends that app back for another. What genuinely ends access is deleting
		 * the key, and that ends it for all of them at once — so the control sits where its
		 * scope is, and says what it does before it is pressed.
		 */
		?>
		<div class="zorem-mcp-connections__foot">
			<button type="button" class="zorem-mcp-revoke" data-zorem-mcp-deletekey
				data-zorem-mcp-confirm="<?php esc_attr_e( 'Delete this key in WooCommerce? Every app using it stops working immediately, and a new key has to be made before anything can connect again. If you gave this key to something other than an AI assistant, that stops too. This cannot be undone.', 'zorem-mcp' ); ?>"
				data-label-working="<?php esc_attr_e( 'Deleting…', 'zorem-mcp' ); ?>">
				<?php esc_html_e( 'Delete this key', 'zorem-mcp' ); ?>
			</button>
			<?php esc_html_e( 'They cannot be turned off one at a time — each is the same account, and an app that still has the key opens a new session on its next request. Deleting the key ends all of them, and a new one must be made to connect again.', 'zorem-mcp' ); ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_render_woo_key_details' ) ) {
	/**
	 * Echo what WooCommerce knows about the stored key.
	 *
	 * Not a "who is connected" table, because this route has no such thing: one key is one
	 * identity, and everyone holding it is that identity. What a merchant can act on is
	 * whether the key still exists, what it reaches, and whether anything has used it.
	 *
	 * @return void
	 */
	function zorem_mcp_render_woo_key_details() {
		$details = class_exists( 'Zorem_MCP_Woo_Key' ) && method_exists( 'Zorem_MCP_Woo_Key', 'key_details' )
			? Zorem_MCP_Woo_Key::key_details()
			: array();

		if ( empty( $details ) ) {
			return;
		}

		if ( empty( $details['found'] ) ) {
			?>
			<div class="zorem-mcp-tools">
				<p class="zorem-mcp-tools__note"><?php esc_html_e( 'This key no longer exists in WooCommerce — it has been deleted there, so nothing can connect with it. Make a new one and paste it above.', 'zorem-mcp' ); ?></p>
			</div>
			<?php
			return;
		}

		$access = array(
			'read'       => __( 'Read only', 'zorem-mcp' ),
			'write'      => __( 'Write only', 'zorem-mcp' ),
			'read_write' => __( 'Read and write', 'zorem-mcp' ),
		);

		$rows = array(
			__( 'Acting as', 'zorem-mcp' ) => '' !== $details['user'] ? $details['user'] : __( 'an account that no longer exists', 'zorem-mcp' ),
			__( 'Named', 'zorem-mcp' )     => '' !== $details['description'] ? $details['description'] : __( 'no description', 'zorem-mcp' ),
			__( 'Access', 'zorem-mcp' )    => isset( $access[ $details['permissions'] ] ) ? $access[ $details['permissions'] ] : $details['permissions'],
			__( 'Last used', 'zorem-mcp' ) => zorem_mcp_woo_key_last_used( $details ),
		);
		?>
		<div class="zorem-mcp-tools">
			<?php foreach ( $rows as $label => $value ) : ?>
				<div class="zorem-mcp-tools__item">
					<span class="zorem-mcp-tools__name"><?php echo esc_html( $label ); ?></span>
					<span><?php echo esc_html( $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_woo_mcp_tools' ) ) {
	/**
	 * The tools WooCommerce's own MCP server hands an assistant.
	 *
	 * Read live rather than listed here, because the answer is per store: it changes with the
	 * WooCommerce version and with whatever else on the site registers an ability. A list
	 * written into this file would be a claim about somebody else's plugin that nothing would
	 * ever check, and merchants would find out it was stale by being surprised.
	 *
	 * WooCommerce does not expose every registered ability on that server. It keeps the ones
	 * whose meta carries `expose_in_deprecated_woocommerce_mcp`, which is the same key a Zorem
	 * plugin sets to get its own tools listed there — so this returns both, which is the
	 * honest answer to "what can an assistant do through this route".
	 *
	 * @return array List of tools, each with label, whether it writes, and whether it deletes.
	 */
	function zorem_mcp_woo_mcp_tools() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_meta' ) ) {
				continue;
			}

			$meta = (array) $ability->get_meta();

			if ( empty( $meta['expose_in_deprecated_woocommerce_mcp'] ) ) {
				continue;
			}

			$notes = isset( $meta['annotations'] ) ? (array) $meta['annotations'] : array();
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$name  = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';

			// Both spellings: the MCP specification says readOnlyHint and destructiveHint, and
			// an ability's meta says readonly and destructive. Whichever an ability carries.
			$out[] = array(
				'name'        => $name,
				'label'       => '' !== $label ? $label : $name,
				'writes'      => empty( $notes['readonly'] ) && empty( $notes['readOnlyHint'] ),
				'destructive' => ! empty( $notes['destructive'] ) || ! empty( $notes['destructiveHint'] ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $out;
	}
}

if ( ! function_exists( 'zorem_mcp_own_woo_tools' ) ) {
	/**
	 * This site's own tools as they ride the WooCommerce route, built from the registry.
	 *
	 * The screen reads WooCommerce's tools live from the route and caches the answer, and that
	 * cache can predate a plugin update that added tools. This plugin's own tools do not need
	 * asking for -- they are in the registry -- so they are folded in from there, named the way
	 * WooCommerce exposes them (the ability id with its slash turned to a dash) and titled the
	 * way the rest of the plugin titles them.
	 *
	 * @return array List of array( name, label, writes, destructive ).
	 */
	function zorem_mcp_own_woo_tools() {
		if ( ! class_exists( 'Zorem_MCP_Registry' ) ) {
			return array();
		}

		$out = array();

		foreach ( Zorem_MCP_Registry::instance()->plugins() as $slug => $plugin ) {
			if ( empty( $plugin['tools'] ) || ! is_array( $plugin['tools'] ) ) {
				continue;
			}

			$prefix = isset( $plugin['prefix'] ) ? $plugin['prefix'] : '';

			foreach ( $plugin['tools'] as $short => $tool ) {
				$short = sanitize_key( $short );

				if ( '' === $short ) {
					continue;
				}

				$label = ( isset( $tool['label'] ) && '' !== $tool['label'] )
					? $tool['label']
					: ucfirst( str_replace( array( '-', '_' ), ' ', $short ) );

				$out[] = array(
					'name'        => $slug . '-' . $short,
					'label'       => $prefix . $label,
					'writes'      => ! empty( $tool['writes'] ),
					'destructive' => ! empty( $tool['destructive'] ),
				);
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'zorem_mcp_render_woo_tools' ) ) {
	/**
	 * Echo the list of tools reachable over WooCommerce's MCP route.
	 *
	 * Deliberately the same tight row as the connection screen's own tool list, badge and all,
	 * because a merchant comparing the two routes is comparing exactly this: what each one
	 * lets an assistant do.
	 *
	 * @param string $empty Sentence shown when the route exposes nothing.
	 * @return void
	 */
	function zorem_mcp_render_woo_tools( $empty = '' ) {
		// Asked of the server when a key is stored, because WooCommerce's own tools exist only
		// during an MCP request and cannot be read from an admin screen. Without a key there
		// is nothing to ask with, so fall back to the tools this plugin registers — those are
		// on the route either way — and say what is missing.
		$answer = class_exists( 'Zorem_MCP_Woo_Key' ) && method_exists( 'Zorem_MCP_Woo_Key', 'server_tools' )
			? Zorem_MCP_Woo_Key::server_tools()
			: array( 'ok' => false, 'tools' => array(), 'error' => '' );

		$tools = ! empty( $answer['ok'] ) ? $answer['tools'] : zorem_mcp_woo_mcp_tools();
		$note  = ! empty( $answer['ok'] ) ? '' : (string) $answer['error'];

		// Always fold in this site's own tools. The probe above answers from a cache that can
		// predate a plugin update, and it reports WooCommerce's tools; ours we know from the
		// registry, which is always current. Keyed by a normalised name (slash or dash, same
		// tool) so a fresh probe that already lists them does not double them up, and ours win
		// so the label carries the plugin prefix the merchant sees everywhere else.
		$by_name = array();

		foreach ( (array) $tools as $tool ) {
			if ( ! empty( $tool['name'] ) ) {
				$by_name[ str_replace( '/', '-', (string) $tool['name'] ) ] = $tool;
			}
		}

		foreach ( zorem_mcp_own_woo_tools() as $tool ) {
			$by_name[ str_replace( '/', '-', (string) $tool['name'] ) ] = $tool;
		}

		$tools = array_values( $by_name );

		usort(
			$tools,
			function ( $a, $b ) {
				return strcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		if ( '' === $empty ) {
			$empty = __( 'WooCommerce is not exposing any tools on this route yet.', 'zorem-mcp' );
		}
		?>
		<div class="zorem-mcp-tools">
			<?php if ( '' !== $note ) : ?>
				<p class="zorem-mcp-tools__note"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>

			<?php if ( empty( $tools ) ) : ?>
				<p class="zorem-mcp-tools__note"><?php echo esc_html( $empty ); ?></p>
			<?php else : ?>
				<?php foreach ( $tools as $tool ) : ?>
					<?php
					if ( ! empty( $tool['destructive'] ) ) {
						$kind = 'delete';
						$word = __( 'Delete', 'zorem-mcp' );
					} elseif ( ! empty( $tool['writes'] ) ) {
						$kind = 'write';
						$word = __( 'Write', 'zorem-mcp' );
					} else {
						$kind = 'read';
						$word = __( 'Read', 'zorem-mcp' );
					}
					?>
					<div class="zorem-mcp-tools__item">
						<span class="zorem-mcp-tools__name"><?php echo esc_html( $tool['label'] ); ?></span>
						<span class="zorem-mcp-badge zorem-mcp-badge--tool zorem-mcp-badge--<?php echo esc_attr( $kind ); ?>"><?php echo zorem_mcp_tool_icon( $kind ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup from a local map, no input reaches it. ?><?php echo esc_html( $word ); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_render_woo_mcp' ) ) {
	/**
	 * Echo the WooCommerce MCP screen: its status, the way in, and the key it needs.
	 *
	 * WooCommerce ships an MCP server of its own, and a Zorem plugin's tools appear on it the
	 * moment the plugin is active -- there is nothing to switch on for that. What a merchant
	 * needs here is the REST API key that route authenticates with, and an honest account of
	 * what that key opens.
	 *
	 * None of that is any one plugin's to explain, which is why it lives here. The key is
	 * made by Zorem_MCP_Woo_Key, the button is answered by the package's own script, and the
	 * warning about scope is true whichever Zorem plugin the merchant happens to be looking
	 * at. Only the sentences that name a plugin are the caller's.
	 *
	 * Kept apart from the shared link on purpose. The two routes look similar and are not:
	 * one is a link, the other a key in a header; one reaches a plugin's tools, the other the
	 * whole store. Shown together they blur, and a merchant who blurs them is a merchant who
	 * pastes a store-wide credential somewhere it should not go.
	 *
	 * @param array $args {
	 *     @type string $status_note Shown only when WooCommerce MCP is switched off.
	 *     @type string $key_sub     Under the Connection key heading.
	 *     @type string $scope_note  What a WooCommerce key opens, compared with the link.
	 * }
	 * @return void
	 */
	function zorem_mcp_render_woo_mcp( $args = array() ) {
		$args = array_merge(
			array(
				'status_note' => __( 'WooCommerce MCP is switched off in WooCommerce, so this route is not answering at all. Your own link is unaffected.', 'zorem-mcp' ),
				'key_sub'     => __( 'Only needed if you connect through WooCommerce MCP instead of your own link.', 'zorem-mcp' ),
				'scope_note'  => __( 'A WooCommerce key opens the whole store — orders, products, customers — because the key belongs to WooCommerce and cannot be narrowed. Your own link reaches only what this plugin adds.', 'zorem-mcp' ),
				'tools_sub'   => __( 'Everything an assistant can reach through WooCommerce MCP — WooCommerce\'s own tools, plus the ones this plugin adds.', 'zorem-mcp' ),
				'tools_empty' => __( 'WooCommerce is not exposing any tools on this route yet.', 'zorem-mcp' ),
				'about_sub'   => __( 'Everyone who uses this key is this one account — the route has no way to tell them apart. Your own link above does, and can turn one person off.', 'zorem-mcp' ),
				'conns_sub'   => __( 'Every app connected with this key. They all act as the same account, because that is all a WooCommerce key can be.', 'zorem-mcp' ),
			),
			is_array( $args ) ? $args : array()
		);

		$woo_on = zorem_mcp_woo_mcp_active();
		?>

		<?php
		/*
		 * No status card.
		 *
		 * There was one, reporting "READY" on WooCommerce's route, and it was a badge for a
		 * thing the merchant is not using and cannot act on: ready means WooCommerce has the
		 * feature switched on, which says nothing about whether anything works -- that route
		 * needs a key, and without one it answers nobody. A green word about somebody else's
		 * endpoint, on a screen about a key, is a claim the merchant has to learn to ignore.
		 *
		 * What is left is what they came for: the key, and what it opens.
		 */
		?>

		<?php if ( ! $woo_on ) : ?>
			<div class="zui-card">
				<div class="zui-row">
					<div class="zui-row__head">
						<p class="zui-row__hint"><?php echo esc_html( $args['status_note'] ); ?></p>
						<p class="zui-row__hint">
							<a class="ptw_a" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ); ?>">
								<?php esc_html_e( 'Open WooCommerce feature settings', 'zorem-mcp' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<div class="zui-card">
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php esc_html_e( 'Connection key', 'zorem-mcp' ); ?></h3>
				<p class="zui-card__sub"><?php echo esc_html( $args['key_sub'] ); ?></p>
			</div>

			<?php if ( $woo_on && class_exists( 'Zorem_MCP_Woo_Key' ) && Zorem_MCP_Woo_Key::available() ) : ?>
				<?php
				$woo_key  = Zorem_MCP_Woo_Key::record();
				// Defined here, not further down, because the "Use this key" confirm below reads it
				// before the connect rows are drawn.
				$woo_pair = method_exists( 'Zorem_MCP_Woo_Key', 'pair' ) ? Zorem_MCP_Woo_Key::pair() : '';
				?>

				<?php
				/*
				 * The merchant makes the key; this takes it.
				 *
				 * There was a button here that created one. It worked, and it was the wrong
				 * thing to offer: a WooCommerce key opens orders, products and customers, and
				 * minting one on somebody's behalf from a shipment-tracking screen gives them
				 * a store-wide credential they never quite decided to create. WooCommerce's
				 * own screen asks who it is for and what it may do, and those questions
				 * deserve to be answered there.
				 *
				 * What is genuinely hard is the part after: turning two long strings into the
				 * shape each AI client wants. That is what this does.
				 */
				?>
				<div class="zui-row">
					<div class="zui-row__head">
						<span class="zui-row__label"><?php esc_html_e( 'Your WooCommerce key', 'zorem-mcp' ); ?></span>
						<p class="zui-row__hint" data-zorem-mcp-woo-hint><?php echo esc_html( zorem_mcp_woo_key_hint( $woo_key ) ); ?></p>
						<p class="zui-row__hint">
							<a class="ptw_a" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys&create-key=1' ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Create a key in WooCommerce', 'zorem-mcp' ); ?>
							</a>
						</p>
						<p class="zui-row__hint" data-zorem-mcp-keyerror hidden></p>
					</div>
					<div class="zui-row__control zorem-mcp-keyform">
						<input type="text" class="zui-input" id="zorem-mcp-ck" placeholder="ck_…" autocomplete="off" spellcheck="false" aria-label="<?php esc_attr_e( 'Consumer key', 'zorem-mcp' ); ?>" />
						<input type="text" class="zui-input" id="zorem-mcp-cs" placeholder="cs_…" autocomplete="off" spellcheck="false" aria-label="<?php esc_attr_e( 'Consumer secret', 'zorem-mcp' ); ?>" />
						<?php
						/*
						 * Asked before it is taken, like every other control on this screen
						 * that changes something. What is being handed over is a credential
						 * for the whole store, and when one is already here it is replaced —
						 * neither is a thing to do by mis-clicking a button.
						 */
						?>
						<button type="button" class="zui-btn-secondary" data-zorem-mcp-usekey
							data-zorem-mcp-confirm="<?php
								echo esc_attr(
									'' !== $woo_pair
										? __( 'Replace the key this site is using? The one stored now is dropped, and any app set up with it stops working until you set it up again with the new one. The old key is not deleted in WooCommerce.', 'zorem-mcp' )
										: __( 'Store this key on the site? It is kept encrypted so your setup steps survive a reload, and it opens the whole store — orders, products, customers — to whatever you connect with it.', 'zorem-mcp' )
								);
							?>"
							data-label-working="<?php esc_attr_e( 'Working…', 'zorem-mcp' ); ?>">
							<?php esc_html_e( 'Use this key', 'zorem-mcp' ); ?>
						</button>
					</div>
				</div>

				<?php
				/*
				 * Drawn here when a key is already stored, and by the reply to the button when
				 * one is entered.
				 *
				 * It used to be empty on every page load, because the key was thrown away as
				 * soon as the rows were drawn. That made a refresh — or simply coming back —
				 * look like nothing had ever been set up, and the only way forward was to
				 * fetch the key from WooCommerce and paste it again. The key is kept sealed
				 * now, so the steps are simply here.
				 *
				 * $woo_pair is set once, up where $woo_key is read, so the confirm above and these
				 * rows use the same value.
				 */
				?>
				<div class="zorem-mcp-woo" data-zorem-mcp-woo<?php echo '' === $woo_pair ? ' hidden' : ''; ?>>
					<div class="zorem-mcp-clients" data-zorem-mcp-woo-rows>
						<?php
						if ( '' !== $woo_pair ) {
							foreach ( zorem_mcp_client_targets( Zorem_MCP_Woo_Key::endpoint_url(), $woo_pair ) as $woo_target ) {
								zorem_mcp_client_row( $woo_target );
							}
						}
						?>
					</div>
				</div>

				<?php /* Present either way, hidden until there is a key to forget, so the reply
				         to entering one can simply reveal it. */ ?>
				<div class="zui-row" data-zorem-mcp-woo-forget<?php echo '' === $woo_pair ? ' hidden' : ''; ?>>
					<div class="zui-row__head">
						<span class="zui-row__label"><?php esc_html_e( 'Forget this key', 'zorem-mcp' ); ?></span>
						<p class="zui-row__hint"><?php esc_html_e( 'Removes the key from this site so the steps above are no longer shown. The key itself keeps working until you delete it in WooCommerce.', 'zorem-mcp' ); ?></p>
					</div>
					<div class="zui-row__control">
						<button type="button" class="zui-btn-secondary" data-zorem-mcp-forgetkey
							data-zorem-mcp-confirm="<?php esc_attr_e( 'Forget the stored key? The setup steps will be hidden until you paste it again. Nothing is deleted in WooCommerce.', 'zorem-mcp' ); ?>"
							data-label-working="<?php esc_attr_e( 'Working…', 'zorem-mcp' ); ?>">
							<?php esc_html_e( 'Forget key', 'zorem-mcp' ); ?>
						</button>
					</div>
				</div>

				<div class="zorem-mcp-clients">
					<p class="zorem-mcp-clients__note">
						<?php echo esc_html( $args['scope_note'] ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>

		<?php /* Repainted with the rest when a key is entered or forgotten. */ ?>
		<div class="zui-card" data-zorem-mcp-woo-conns<?php echo '' === $woo_pair ? ' hidden' : ''; ?>>
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php esc_html_e( 'Who is connected', 'zorem-mcp' ); ?></h3>
				<p class="zui-card__sub"><?php echo esc_html( $args['conns_sub'] ); ?></p>
			</div>

			<div data-zorem-mcp-woo-conns-body>
				<?php zorem_mcp_render_woo_connections(); ?>
			</div>
		</div>

		<div class="zui-card" data-zorem-mcp-woo-about<?php echo '' === $woo_pair ? ' hidden' : ''; ?>>
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php esc_html_e( 'About this key', 'zorem-mcp' ); ?></h3>
				<p class="zui-card__sub"><?php echo esc_html( $args['about_sub'] ); ?></p>
			</div>

			<div data-zorem-mcp-woo-about-body>
				<?php zorem_mcp_render_woo_key_details(); ?>
			</div>
		</div>

		<?php /* Only while the route exists. Listing what it would carry, on a store where it
		         carries nothing, reads as a promise rather than a description. */ ?>
		<?php if ( $woo_on ) : ?>
			<div class="zui-card">
				<div class="zui-card__head">
					<h3 class="zui-card__title"><?php esc_html_e( 'What this key can do', 'zorem-mcp' ); ?></h3>
					<p class="zui-card__sub"><?php echo esc_html( $args['tools_sub'] ); ?></p>
				</div>

				<?php /* Repainted in place when a key is entered or forgotten, because what the
				         route offers depends on the key that asks. */ ?>
				<div data-zorem-mcp-woo-tools>
					<?php zorem_mcp_render_woo_tools( $args['tools_empty'] ); ?>
				</div>
			</div>
		<?php endif; ?>

		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_connect_url' ) ) {
	/**
	 * The link to put on screen.
	 *
	 * Two of them exist and they are not the same kind of thing. The older one carries a
	 * credential in its path: whoever holds it is in, which is why it works from a browser
	 * and a phone, and why one person's access cannot be taken away without taking everyone's.
	 * The newer one carries nothing. It is an address, and whoever adds it signs in as
	 * themselves and is issued a connection of their own.
	 *
	 * The newer one is offered wherever it can be, because everything a merchant wants from
	 * this screen -- who is connected, and turning one of them off -- only becomes possible
	 * once the link stops being the password. The old one keeps working for everybody already
	 * connected; nothing about this disconnects them.
	 *
	 * @return string
	 */
	function zorem_mcp_connect_url() {
		if ( class_exists( 'Zorem_MCP_OAuth' ) && Zorem_MCP_OAuth::available() ) {
			return Zorem_MCP_OAuth::endpoint_url();
		}

		return class_exists( 'Zorem_MCP_Server' ) ? Zorem_MCP_Server::endpoint_url() : '';
	}
}

if ( ! function_exists( 'zorem_mcp_link_hint' ) ) {
	/**
	 * What to say under the link, which depends on what kind of link it is.
	 *
	 * A warning to guard it like a password is exactly right for a link that IS the password,
	 * and exactly wrong for one that is not -- it would have merchants treating a harmless
	 * address as a secret, and worse, treating the next real secret as harmless because the
	 * warning had cried wolf.
	 *
	 * @return string
	 */
	function zorem_mcp_link_hint() {
		if ( class_exists( 'Zorem_MCP_OAuth' ) && Zorem_MCP_OAuth::available() ) {
			return __( 'The same link for everyone. Whoever adds it signs in with their own store account, and you can turn any one of them off on its own.', 'zorem-mcp' );
		}

		return __( 'Anyone holding this link can act on your store, so keep it like a password.', 'zorem-mcp' );
	}
}

if ( ! function_exists( 'zorem_mcp_is_caller' ) ) {
	/**
	 * Whether something that reached the link counts as somebody connecting.
	 *
	 * The link is an address now rather than a password, which means it can safely be pasted
	 * where addresses get pasted -- and the moment it lands in a chat window, that chat's
	 * preview bot fetches it. Those fetches arrive with no credential and are turned away,
	 * exactly as they should be, and they used to arrive on this screen as rows saying
	 * REFUSED: a list of people supposedly trying to get in, none of whom exist.
	 *
	 * Two more are dropped for the same reason: requests the store made to itself, and this
	 * package's own link test. None of the three is a person, and a list of who is connected
	 * that reports machinery as people is worse than no list.
	 *
	 * Anything not recognised is kept. An unknown caller on a store's own connection is the
	 * one thing here most worth showing, so the doubt is spent in favour of showing it.
	 *
	 * @param string $agent The caller's reported name.
	 * @return bool
	 */
	function zorem_mcp_is_caller( $agent ) {
		$agent = strtolower( (string) $agent );

		$machinery = array(
			// The store, and this package, talking to themselves.
			'wordpress/',
			'zorem link test',
			// Link previews. They follow any address a person pastes into a chat.
			'slackbot',
			'twitterbot',
			'facebookexternalhit',
			'whatsapp',
			'telegrambot',
			'discordbot',
			'skypeuripreview',
			'linkedinbot',
			'embedly',
			'redditbot',
			'bingbot',
			'googlebot',
		);

		/**
		 * Filter the callers treated as machinery rather than as people.
		 *
		 * @since 1.5.1
		 *
		 * @param string[] $machinery Lowercased fragments matched against the caller's name.
		 * @param string   $agent     The caller being judged.
		 */
		$machinery = apply_filters( 'zorem_mcp_machinery_agents', $machinery, $agent );

		foreach ( $machinery as $fragment ) {
			if ( false !== strpos( $agent, $fragment ) ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'zorem_mcp_shared_callers' ) ) {
	/**
	 * The shared link, as one row per caller that has actually used it.
	 *
	 * WHY MORE THAN ONE ROW
	 *
	 * The link is one secret, and a merchant hands it to whoever needs it. Collapsing that
	 * into a single row was accurate about the credential and useless about the situation: a
	 * store where two colleagues had connected showed exactly what a store where one had
	 * connected showed, so the second person was invisible.
	 *
	 * The server cannot name them -- that is what signing in is for, and it is the next thing
	 * to build. But it can see that requests arrived from somewhere else, and reporting that
	 * is the difference between "somebody is connected" and "two people are connected, and
	 * the second one was here a minute ago".
	 *
	 * Each row says who the link ACTS AS, which is the same account for all of them, because
	 * that is the truth a merchant most needs to see: whoever holds this link is working as
	 * that person, with that person's permissions.
	 *
	 * @return array List of rows, newest caller first. Empty when there is no link.
	 */
	function zorem_mcp_shared_callers() {
		if ( ! class_exists( 'Zorem_MCP_Settings' ) || '' === Zorem_MCP_Settings::token() ) {
			return array();
		}

		$user   = get_userdata( Zorem_MCP_Settings::user_id() );
		$acts   = $user ? $user->user_email : __( 'The account that made it', 'zorem-mcp' );
		$issued = Zorem_MCP_Settings::issued_at();

		/*
		 * Built from the inbound record rather than the audit log, and the difference matters.
		 *
		 * The audit log holds tool CALLS. Somebody who adds the link, sees the tools appear and
		 * has not asked anything yet leaves nothing in it -- so the screen reported "nobody yet"
		 * to a merchant looking at an assistant that was plainly connected. Connecting is not a
		 * tool call, and a list of who is connected cannot be built from tool calls.
		 *
		 * The inbound record holds every request that reached the link, the handshake included,
		 * with the caller's name and address. That is the same question this list is asking.
		 */
		// Nothing to say about the shared link once people have their own connections.
		//
		// Those rows answered a real question while the link WAS the credential: who is using
		// it, and from where. They answer nothing now. The people are listed by name below,
		// each with a row of their own, and repeating their traffic up here as anonymous
		// callers turned two colleagues into seven mystery entries.
		if ( class_exists( 'Zorem_MCP_Keys' ) && Zorem_MCP_Keys::live_count() > 0 ) {
			return array();
		}

		$seen     = array();
		$signed   = false;
		$refusals = array();

		foreach ( Zorem_MCP_Settings::attempts() as $attempt ) {
			$agent = (string) $attempt['agent'];

			if ( ! zorem_mcp_is_caller( $agent ) ) {
				continue;
			}

			$via = isset( $attempt['via'] ) ? (string) $attempt['via'] : '';

			// A call that came in on somebody's own connection is already a row of its own,
			// listed underneath by name. Counting it here as well reported one person signing
			// in as four separate callers on the shared link, which is the opposite of what
			// this screen is for.
			if ( 'key' === $via ) {
				continue;
			}

			// Refusals during a sign-in are part of the handshake -- the assistant knocks
			// before it has anything to knock with. They are worth showing while nothing has
			// worked and are pure noise once something has, so they are held back and only
			// used if the store turns out to have no working connection at all.
			if ( 'ok' !== $attempt['outcome'] && $signed ) {
				continue;
			}

			if ( 'ok' !== $attempt['outcome'] ) {
				$refusals[] = $attempt;
			}

			$key = strtolower( $agent ) . '|' . $attempt['ip'];

			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = array(
					'agent' => '' !== $agent ? $agent : __( 'An AI client', 'zorem-mcp' ),
					'ip'    => (string) $attempt['ip'],
					'last'  => (int) $attempt['time'],
					'first' => (int) $attempt['time'],
					'ok'    => false,
				);
			}

			// Attempts arrive newest first, so the last one seen for a caller is its earliest.
			$seen[ $key ]['first'] = (int) $attempt['time'];

			if ( 'ok' === $attempt['outcome'] ) {
				$seen[ $key ]['ok'] = true;
			}
		}

		$out = array();

		foreach ( $seen as $caller ) {
			$out[] = array(
				'acts_as' => $acts,
				'app'     => $caller['agent'],
				'where'   => $caller['ip'],
				'first'   => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $caller['first'] ), 'Y-m-d' ),
				'last'    => sprintf(
					/* translators: %s: a length of time, e.g. "2 hours". */
					__( '%s ago', 'zorem-mcp' ),
					human_time_diff( $caller['last'], time() )
				),
				'refused' => ! $caller['ok'],
			);
		}

		// Nothing to say about the shared link on a store where everybody has signed in: the
		// link is still a way in, but nobody is using it that way, and an empty row saying so
		// would sit above the real connections looking like one of them.
		if ( empty( $out ) && $signed ) {
			return array();
		}

		// A link that exists but has never been used still deserves a row: it is a way in, and
		// a screen that lists ways in cannot leave one out because nobody has walked through it.
		if ( empty( $out ) ) {
			$out[] = array(
				'acts_as' => $acts,
				'app'     => __( 'Nobody yet', 'zorem-mcp' ),
				'where'   => '',
				'first'   => $issued ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $issued ), 'Y-m-d' ) : '—',
				'last'    => __( 'never', 'zorem-mcp' ),
				'refused' => false,
			);
		}

		return $out;
	}
}

if ( ! function_exists( 'zorem_mcp_render_connections' ) ) {
	/**
	 * Echo the list of people connected to this store, each revocable on its own.
	 *
	 * The screen that makes per-person tokens worth having. Without it a merchant can see
	 * that something connected -- the audit log says that much -- but not who holds access
	 * today, and has no way to take one person's away.
	 *
	 * Deliberately not a "keys" screen. Nothing is generated here: a row appears because
	 * somebody connected and signed in, and the only control is the one that ends it. A
	 * merchant reading this list is asking "who can reach my store", and every column
	 * answers part of that: who, from which app, when last, and whether it still works.
	 *
	 * @param array $args {
	 *     @type string $empty Shown while nobody has connected yet.
	 * }
	 * @return void
	 */
	function zorem_mcp_render_connections( $args = array() ) {
		if ( ! class_exists( 'Zorem_MCP_Keys' ) ) {
			return;
		}

		$args = array_merge(
			array(
				'empty' => __( 'Nobody has connected yet. Anyone you give the link to appears here once they connect, and you can turn any of them off without affecting the others.', 'zorem-mcp' ),
			),
			is_array( $args ) ? $args : array()
		);

		$rows   = Zorem_MCP_Keys::all();
		$shared = zorem_mcp_shared_callers();

		if ( empty( $rows ) && empty( $shared ) ) {
			?>
			<div class="zui-row">
				<div class="zui-row__head">
					<p class="zui-row__hint"><?php echo esc_html( $args['empty'] ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		zorem_mcp_nonce_field();
		?>
		<div class="zorem-mcp-log">
		<table class="zorem-mcp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Acting as', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'App', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Connected', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'zorem-mcp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $shared as $i => $caller ) : ?>
					<?php
					/*
					 * One row per caller on the shared link, and no button on any of them.
					 *
					 * These callers cannot be turned off one at a time -- they hold one secret
					 * between them -- and the control that once cut all of them at once is gone: the
					 * link is an address now, so there is nothing about it left to revoke. The rows
					 * stay because knowing somebody is connected is worth showing; they simply have
					 * nothing to act on, which is the honest shape of it.
					 */
					?>
					<tr data-zorem-mcp-conn="0">
						<td>
							<?php echo esc_html( $caller['acts_as'] ); ?>
							<?php if ( '' !== $caller['where'] ) : ?>
								<span class="zorem-mcp-from"><?php echo esc_html( $caller['where'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $caller['app'] ); ?></td>
						<td><?php echo esc_html( $caller['first'] ); ?></td>
						<td><?php echo esc_html( $caller['last'] ); ?></td>
						<td>
							<span class="zorem-mcp-badge <?php echo empty( $caller['refused'] ) ? 'zorem-mcp-badge--ok' : 'zorem-mcp-badge--error'; ?>">
								<?php echo empty( $caller['refused'] ) ? esc_html__( 'Shared link', 'zorem-mcp' ) : esc_html__( 'Refused', 'zorem-mcp' ); ?>
							</span>
						</td>
						<td></td>
					</tr>
				<?php endforeach; ?>

				<?php foreach ( $rows as $row ) : ?>
					<?php
					$user    = get_userdata( (int) $row['user_id'] );
					$revoked = ! empty( $row['revoked_at'] );

					// A person is named by their email, because that is how a merchant knows
					// their colleagues. A deleted account still gets a row -- the connection
					// existed, and hiding it would hide that it once had access.
					$who = $user ? $user->user_email : sprintf(
						/* translators: %d: the WordPress user id of a deleted account. */
						__( 'Deleted account #%d', 'zorem-mcp' ),
						(int) $row['user_id']
					);

					$last = ! empty( $row['last_used_at'] )
						? sprintf(
							/* translators: %s: a length of time, e.g. "2 hours". */
							__( '%s ago', 'zorem-mcp' ),
							human_time_diff( (int) strtotime( $row['last_used_at'] . ' UTC' ), time() )
						)
						: __( 'never', 'zorem-mcp' );
					?>
					<tr data-zorem-mcp-conn="<?php echo esc_attr( $row['id'] ); ?>">
						<td><?php echo esc_html( $who ); ?></td>
						<td><?php echo esc_html( '' !== $row['client'] ? $row['client'] : $row['name'] ); ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d' ) ); ?></td>
						<td><?php echo esc_html( $last ); ?></td>
						<td>
							<span class="zorem-mcp-badge <?php echo $revoked ? 'zorem-mcp-badge--error' : 'zorem-mcp-badge--ok'; ?>">
								<?php echo $revoked ? esc_html__( 'Revoked', 'zorem-mcp' ) : esc_html__( 'Active', 'zorem-mcp' ); ?>
							</span>
						</td>
						<td>
							<?php if ( ! $revoked ) : ?>
								<button type="button" class="zorem-mcp-revoke" data-zorem-mcp-revoke="<?php echo esc_attr( $row['id'] ); ?>"
									data-label-working="<?php esc_attr_e( 'Turning off…', 'zorem-mcp' ); ?>"
									data-zorem-mcp-confirm="<?php
										/* translators: %s: the person's email address. */
										echo esc_attr( sprintf( __( 'Turn off this connection for %s? Their AI assistant stops working straight away. Everyone else is unaffected.', 'zorem-mcp' ), $who ) );
									?>">
									<?php esc_html_e( 'Revoke', 'zorem-mcp' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php
		/*
		 * The lever a merchant reaches for when an app will not ask again.
		 *
		 * An AI app keeps its token and nothing done inside the app clears it -- remove the
		 * connection in VS Code, add it straight back, and it reuses what it already has.
		 * No sign-in, no Allow screen, and no way for the merchant to make one happen.
		 *
		 * Withdrawing the tokens is the only thing that works from this side, so it is
		 * offered plainly rather than left as something only support knows about. The link
		 * does not change; everyone simply proves who they are once more.
		 */
		if ( Zorem_MCP_Keys::live_count() > 0 ) :
			?>
			<div class="zorem-mcp-connections__foot">
				<button type="button" class="zorem-mcp-revoke" data-zorem-mcp-revoke-all
					data-label-working="<?php esc_attr_e( 'Turning off…', 'zorem-mcp' ); ?>"
					data-zorem-mcp-confirm="<?php esc_attr_e( 'Sign everyone out? Every AI assistant stops working until each person connects again. Your link does not change.', 'zorem-mcp' ); ?>">
					<?php esc_html_e( 'Sign everyone out', 'zorem-mcp' ); ?>
				</button>
				<p class="zorem-mcp-connections__hint">
					<?php esc_html_e( 'Use this when an app will not ask to sign in again — removing it inside the app is not enough, because the app keeps its own copy of the connection.', 'zorem-mcp' ); ?>
				</p>
			</div>
			<?php
		endif;
		?>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_channel_label' ) ) {
	/**
	 * Turn a stored channel slug into something a merchant recognises.
	 *
	 * @param string $channel Stored channel.
	 * @return string
	 */
	function zorem_mcp_channel_label( $channel ) {
		$known = array(
			'woocommerce-mcp' => __( 'WooCommerce MCP', 'zorem-mcp' ),
			'ast-connection'  => __( 'Your link', 'zorem-mcp' ),
			'zorem-link'      => __( 'Your link', 'zorem-mcp' ),
		);

		/**
		 * Filter the display name of an audit channel.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $known   Label keyed by stored channel slug.
		 * @param string   $channel The channel being labelled.
		 */
		$known = apply_filters( 'zorem_mcp_channel_labels', $known, $channel );

		return isset( $known[ $channel ] ) ? $known[ $channel ] : '—';
	}
}

if ( ! function_exists( 'zorem_mcp_render_audit_log' ) ) {
	/**
	 * Echo the audit log: every action an AI assistant took, with the result and any error.
	 *
	 * The log is the library's -- one table, written by the shared server, holding the actions
	 * of every Zorem plugin's tools. So there is exactly one thing to show, and a plugin that
	 * built its own view of it would be showing the same rows in a different table. Worse, a
	 * merchant with two Zorem plugins would find two audit screens listing identical entries
	 * and reasonably conclude one of them was lying.
	 *
	 * The caller supplies only how to build a link back to its own screen, because that is the
	 * one thing the library cannot know.
	 *
	 * @param array $args {
	 *     @type callable $page_url Given a page number, returns a URL to this screen at that
	 *                              page. Omit to render without paging links.
	 *     @type int      $page     Page to show. Defaults to the ai_log_page query argument.
	 *     @type string   $empty    Shown when nothing has been logged yet.
	 * }
	 * @return void
	 */
	function zorem_mcp_render_audit_log( $args = array() ) {
		if ( ! class_exists( 'Zorem_MCP_Audit' ) ) {
			return;
		}

		$args = array_merge(
			array(
				'page_url' => null,
				'page'     => 0,
				'empty'    => __( 'Nothing yet. Once an AI assistant reads or updates anything on this store, every action it takes will be listed here.', 'zorem-mcp' ),
			),
			is_array( $args ) ? $args : array()
		);

		$page = (int) $args['page'];

		if ( $page < 1 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging state, no action taken.
			$page = isset( $_GET['ai_log_page'] ) ? max( 1, absint( wp_unslash( $_GET['ai_log_page'] ) ) ) : 1;
		}

		$entries = Zorem_MCP_Audit::entries( $page );
		$total   = Zorem_MCP_Audit::count();
		$pages   = $total ? (int) ceil( $total / Zorem_MCP_Audit::PER_PAGE ) : 1;

		if ( empty( $entries ) ) {
			?>
			<div class="zui-row">
				<div class="zui-row__head">
					<p class="zui-row__hint"><?php echo esc_html( $args['empty'] ); ?></p>
				</div>
			</div>
			<?php
			return;
		}
		?>
		<div class="zorem-mcp-log">
		<table class="zorem-mcp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Tool', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Channel', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'App', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'User', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Result', 'zorem-mcp' ); ?></th>
					<th><?php esc_html_e( 'Error', 'zorem-mcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$failed = ( 'error' === $entry['result'] );
					// Stored in UTC; show it in the store's own timezone.
					$when   = get_date_from_gmt( $entry['created_at'], 'Y-m-d H:i:s' );
					?>
					<tr>
						<td><?php echo esc_html( $when ); ?></td>
						<td><code><?php echo esc_html( $entry['tool'] ); ?></code></td>
						<td><?php echo esc_html( zorem_mcp_channel_label( $entry['channel'] ) ); ?></td>
						<td><?php echo esc_html( ! empty( $entry['client'] ) ? $entry['client'] : '—' ); ?></td>
						<td><?php echo esc_html( ! empty( $entry['user_login'] ) ? $entry['user_login'] : '—' ); ?></td>
						<td>
							<span class="zorem-mcp-badge <?php echo $failed ? 'zorem-mcp-badge--error' : 'zorem-mcp-badge--ok'; ?>">
								<?php echo $failed ? esc_html__( 'Refused', 'zorem-mcp' ) : esc_html__( 'OK', 'zorem-mcp' ); ?>
							</span>
						</td>
						<td class="zorem-mcp-log__error" title="<?php echo esc_attr( ! empty( $entry['error'] ) ? $entry['error'] : '' ); ?>"><?php echo esc_html( ! empty( $entry['error'] ) ? $entry['error'] : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php if ( $pages > 1 && is_callable( $args['page_url'] ) ) : ?>
			<div class="zui-row">
				<div class="zui-row__head">
					<p class="zui-row__hint">
						<?php
						printf(
							/* translators: 1: current page number, 2: total pages, 3: total number of entries. */
							esc_html__( 'Page %1$d of %2$d — %3$d entries', 'zorem-mcp' ),
							(int) $page,
							(int) $pages,
							(int) $total
						);
						?>
					</p>
				</div>
				<div class="zui-row__control">
					<?php if ( $page > 1 ) : ?>
						<a class="zorem-mcp-pagebtn" href="<?php echo esc_url( call_user_func( $args['page_url'], $page - 1 ) ); ?>"><?php esc_html_e( 'Newer', 'zorem-mcp' ); ?></a>
					<?php endif; ?>
					<?php if ( $page < $pages ) : ?>
						<a class="zorem-mcp-pagebtn" href="<?php echo esc_url( call_user_func( $args['page_url'], $page + 1 ) ); ?>"><?php esc_html_e( 'Older', 'zorem-mcp' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_nonce_field' ) ) {
	/**
	 * Print the nonce the library's own AJAX actions check.
	 *
	 * It used to be a plugin's job, and that was wrong twice over. The action being verified
	 * belongs to this library, so a plugin printing the field is a plugin keeping a secret
	 * for somebody else's endpoint -- and the second plugin to draw the screen did not know
	 * it had to, so its buttons came up dead with nothing on screen to explain it.
	 *
	 * Printed at most once per request, because two fields would share an id and the script
	 * reads it by id.
	 *
	 * @return void
	 */
	function zorem_mcp_nonce_field() {
		static $done = false;

		if ( $done || ! class_exists( 'Zorem_MCP_Woo_Key' ) ) {
			return;
		}

		$done = true;

		wp_nonce_field( Zorem_MCP_Woo_Key::NONCE, Zorem_MCP_Woo_Key::NONCE . '_nonce' );
	}
}

if ( ! function_exists( 'zorem_mcp_render_connection' ) ) {
	/**
	 * Echo the whole connection block: the link, the self-test, the steps and the folds.
	 *
	 * WHY THIS IS HERE AND NOT IN A PLUGIN
	 *
	 * It was in a plugin, and that was the mistake. AST PRO built this screen, and the second
	 * plugin to join the shared connection would have had to build it again -- the same rows,
	 * the same wording, the same folds -- which is the opposite of what a shared connection is
	 * for. Sharing the routes and the token while leaving every plugin to draw its own screen
	 * shares the easy half and duplicates the hard one, and the two copies start drifting the
	 * first time one of them is improved.
	 *
	 * So the screen moved down here with everything else the merchant actually touches. A
	 * plugin renders its own settings fields -- those belong to its own settings framework and
	 * are the one part that genuinely differs -- and calls this for the rest.
	 *
	 * WHAT A CALLER STILL CHOOSES
	 *
	 * Only what really varies between them: which URL, what to call it, what to warn about,
	 * and the example question at the end of the steps, which should name something that
	 * plugin can actually answer. Everything else is fixed, on purpose: a merchant who has
	 * connected once should meet the same screen in every Zorem plugin, not a family
	 * resemblance.
	 *
	 * @param array $args {
	 *     @type string   $url      The connection URL. An empty string renders the block hidden,
	 *                              ready for the script to fill in after the toggle saves.
	 *     @type string   $wrap     Value for data-zorem-mcp-linkwrap, unique on the screen.
	 *     @type string   $field_id id of the URL input, unique on the screen.
	 *     @type string   $label    Row label. Defaults to "Your link".
	 *     @type string   $hint     Warning under the label.
	 *     @type string[] $steps    The numbered steps. Defaults to the claude.ai path.
	 *     @type bool     $selftest Whether to offer "Test my link". Default true.
	 * }
	 * @return void
	 */
	function zorem_mcp_render_connection( $args ) {
		$args = array_merge(
			array(
				'url'        => '',
				'wrap'       => 'tracking',
				'field_id'   => zorem_mcp_endpoint_field_id(),
				'label'      => __( 'Your link', 'zorem-mcp' ),
				'hint'       => __( 'Anyone holding this link can act on your store, so keep it like a password.', 'zorem-mcp' ),
				'steps'      => array(),
				'selftest'   => true,
				'empty_note' => '',
			),
			is_array( $args ) ? $args : array()
		);

		zorem_mcp_nonce_field();

		// A caller with its own on/off switch above this block wants nothing here: the switch
		// is the answer, and the block fills itself in the moment it is saved. A caller
		// without one -- a plugin that joined a connection somebody else switches on -- has to
		// say where it is switched on, or its screen is an empty space with no explanation.
		if ( '' === $args['url'] && '' !== $args['empty_note'] ) {
			?>
			<div class="zui-row">
				<div class="zui-row__head">
					<p class="zui-row__hint"><?php echo esc_html( $args['empty_note'] ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		if ( empty( $args['steps'] ) ) {
			$args['steps'] = array(
				__( 'Open claude.ai and go to Settings, then Connectors.', 'zorem-mcp' ),
				__( 'Choose Add custom connector, give it any name, and paste your link.', 'zorem-mcp' ),
			);
		}

		// The sign-in is part of connecting now, so it belongs in the instructions rather than
		// arriving as a surprise halfway through.
		if ( class_exists( 'Zorem_MCP_OAuth' ) && Zorem_MCP_OAuth::available() && ! empty( $args['steps'] ) ) {
			array_splice(
				$args['steps'],
				2,
				0,
				array( __( 'Press Connect and sign in with your store account. Once only.', 'zorem-mcp' ) )
			);
		}
		?>
		<div data-zorem-mcp-linkwrap="<?php echo esc_attr( $args['wrap'] ); ?>"<?php echo '' === $args['url'] ? ' hidden' : ''; ?>>
			<div class="zui-row">
				<div class="zui-row__head">
					<span class="zui-row__label"><?php echo esc_html( $args['label'] ); ?></span>
					<p class="zui-row__hint"><?php echo esc_html( $args['hint'] ); ?></p>
				</div>
				<div class="zui-row__control zorem-mcp-url">
					<input type="text" class="zui-input zorem-mcp-url__field" id="<?php echo esc_attr( $args['field_id'] ); ?>" value="<?php echo esc_attr( $args['url'] ); ?>" readonly onfocus="this.select();" />
					<button type="button" class="zui-btn-secondary" data-zorem-mcp-copy="#<?php echo esc_attr( $args['field_id'] ); ?>" data-label-copied="<?php esc_attr_e( 'Copied', 'zorem-mcp' ); ?>"
						data-label-copyfailed="<?php esc_attr_e( 'Copy failed — press Ctrl+C', 'zorem-mcp' ); ?>">
						<?php esc_html_e( 'Copy', 'zorem-mcp' ); ?>
					</button>
				</div>
			</div>

			<?php if ( $args['selftest'] ) : ?>
				<?php
				/*
				 * One self-test per screen, not one per link. The test asks whether anything
				 * from outside is reaching this site at all, and that answer is the same for
				 * every link on it -- a second button would run the same check and print the
				 * same sentence, while looking like it had checked something else.
				 */
				?>
				<div class="zui-row">
					<div class="zui-row__head">
						<span class="zui-row__label"><?php esc_html_e( 'Not working in your AI app?', 'zorem-mcp' ); ?></span>
						<p class="zui-row__hint" data-zorem-mcp-testresult>
							<?php esc_html_e( 'Checks whether an AI assistant can actually reach your link, and says what stopped it if not.', 'zorem-mcp' ); ?>
						</p>
						<p class="zui-row__hint zorem-mcp-testdetail" data-zorem-mcp-testdetail hidden></p>
						<p class="zui-row__hint" data-zorem-mcp-testlinks hidden></p>
						<?php
						/*
						 * Offered only when the test has PROVED the store refuses callers by name, and
						 * only where .htaccess can actually answer it. A button that edits a server
						 * config file has to be earned by evidence; sitting there permanently it would
						 * be an invitation to change something that was never wrong.
						 */
						?>
						<p class="zui-row__hint" data-zorem-mcp-fixwrap hidden>
							<button type="button" class="zui-btn-secondary" data-zorem-mcp-fix
								data-label-working="<?php esc_attr_e( 'Fixing…', 'zorem-mcp' ); ?>"
								data-zorem-mcp-confirm="<?php esc_attr_e( 'This adds four lines to your .htaccess file so requests to this plugin, and nothing else, skip your bad-bot rules. Nothing else on your site changes, and no rule is deleted. A copy of the file is kept, and the change is undone automatically if it turns out not to help. Continue?', 'zorem-mcp' ); ?>">
								<?php esc_html_e( 'Fix this for me', 'zorem-mcp' ); ?>
							</button>
						</p>
					</div>
					<div class="zui-row__control">
						<button type="button" class="zui-btn-secondary" data-zorem-mcp-selftest
							data-label-working="<?php esc_attr_e( 'Testing…', 'zorem-mcp' ); ?>">
							<?php esc_html_e( 'Test my link', 'zorem-mcp' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<?php
			zorem_mcp_steps( $args['steps'] );

			zorem_mcp_disclosure_open( __( 'Using ChatGPT, or an app instead of claude.ai?', 'zorem-mcp' ) );
			?>
				<div class="zorem-mcp-clients">
					<?php foreach ( zorem_mcp_client_targets( $args['url'] ) as $target ) : ?>
						<?php zorem_mcp_client_row( $target ); ?>
					<?php endforeach; ?>
					<p class="zorem-mcp-clients__note">
						<?php esc_html_e( 'Every button here carries your link, so do not share a screenshot of this list.', 'zorem-mcp' ); ?>
					</p>
				</div>
			<?php
			zorem_mcp_disclosure_close();

			zorem_mcp_fallback_link();
			?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_fallback_link' ) ) {
	/**
	 * The same connection, with the credential in the address instead of a sign-in.
	 *
	 * WHY THIS EXISTS, AND WHY IT IS FOLDED AWAY
	 *
	 * Signing in is the better connection in every way that matters -- each person gets
	 * their own token, the audit log records who did what, and one of them can be turned off
	 * without touching the rest. That is the link on show, and it should stay the link on
	 * show.
	 *
	 * But it depends on the app being able to complete an OAuth flow, and some cannot. An
	 * app that has cached a failed registration keeps failing until its own cache is cleared,
	 * which is not something a merchant can be expected to know how to do -- and from where
	 * they are standing the connection is simply broken with no way forward.
	 *
	 * This address needs no sign-in at all: the token is in it, so pasting it is the whole
	 * setup. The cost is real and is stated rather than glossed: everyone who holds it is the
	 * same one person as far as the store is concerned, so the audit log cannot tell them
	 * apart and there is no per-person revoke. It is the older, blunter connection, kept
	 * because a blunt connection that works beats a sharp one that will not start.
	 *
	 * @return void
	 */
	function zorem_mcp_fallback_link() {
		if ( ! class_exists( 'Zorem_MCP_Server' ) || ! class_exists( 'Zorem_MCP_OAuth' ) ) {
			return;
		}

		// Only worth offering when signing in is the normal route. Where OAuth is not
		// available this IS the link, and it is already shown above.
		if ( ! Zorem_MCP_OAuth::available() ) {
			return;
		}

		$url = Zorem_MCP_Server::endpoint_url();

		if ( '' === $url ) {
			return;
		}

		zorem_mcp_disclosure_open( __( 'An app that will not ask you to sign in?', 'zorem-mcp' ) );
		?>
			<div class="zui-row">
				<div class="zui-row__head">
					<p class="zui-row__hint">
						<?php esc_html_e( 'Some apps cache a failed connection and never ask again. Paste this address instead — it needs no sign-in, so there is nothing for the app to get wrong.', 'zorem-mcp' ); ?>
					</p>
				</div>
			</div>

			<div class="zui-row">
				<div class="zui-row__control zorem-mcp-copyrow">
					<input type="text" class="zui-input" id="zorem-mcp-fallback" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" />
					<button type="button" class="zui-btn-secondary"
						data-zorem-mcp-copy="#zorem-mcp-fallback"
						data-label-copied="<?php esc_attr_e( 'Copied', 'zorem-mcp' ); ?>"
						data-label-copyfailed="<?php esc_attr_e( 'Copy failed — press Ctrl+C', 'zorem-mcp' ); ?>">
						<?php esc_html_e( 'Copy', 'zorem-mcp' ); ?>
					</button>
				</div>
			</div>

			<div class="zui-row">
				<div class="zui-row__head">
					<p class="zui-row__hint">
						<?php esc_html_e( 'This one IS the password — treat it like one. Everybody using it counts as the same person, so the log cannot tell them apart and they cannot be turned off individually. Prefer the link above wherever the app will allow it.', 'zorem-mcp' ); ?>
					</p>
				</div>
			</div>
		<?php
		zorem_mcp_disclosure_close();
	}
}

if ( ! function_exists( 'zorem_mcp_steps' ) ) {
	/**
	 * Echo a numbered "do this, then this" list.
	 *
	 * The single most useful thing on this screen for someone who has never connected an
	 * AI assistant, and the reason the rest of the connection detail is folded away: a
	 * merchant needs one path spelled out, not a menu of five.
	 *
	 * @param string[] $steps Steps in order.
	 * @return void
	 */
	function zorem_mcp_steps( $steps ) {
		if ( empty( $steps ) ) {
			return;
		}
		?>
		<ol class="zorem-mcp-steps">
			<?php foreach ( $steps as $step ) : ?>
				<li><?php echo esc_html( $step ); ?></li>
			<?php endforeach; ?>
		</ol>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_disclosure_open' ) ) {
	/**
	 * Open a folded section.
	 *
	 * <details> rather than a scripted accordion: it works before any JavaScript runs, it
	 * is what a screen reader already understands, and browser find-in-page opens it.
	 *
	 * @param string $summary Visible label.
	 * @param string $class   Extra class for the details element.
	 * @return void
	 */
	function zorem_mcp_disclosure_open( $summary, $class = '' ) {
		?>
		<details class="zorem-mcp-more <?php echo esc_attr( $class ); ?>">
			<summary class="zorem-mcp-more__summary"><?php echo esc_html( $summary ); ?></summary>
			<div class="zorem-mcp-more__body">
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_disclosure_close' ) ) {
	/**
	 * Close a folded section opened by zorem_mcp_disclosure_open().
	 *
	 * @return void
	 */
	function zorem_mcp_disclosure_close() {
		?>
			</div>
		</details>
		<?php
	}
}
