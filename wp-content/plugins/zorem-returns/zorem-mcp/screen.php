<?php
// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- one library, many plugins; the strings it owns are translated in the plugin that ships them.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- zorem_mcp_ IS the prefix.
/**
 * The AI Assistant screen, whole.
 *
 * WHY THE LIBRARY DRAWS THE SCREEN AND NOT JUST ITS PARTS
 *
 * The package already owned the hard things -- OAuth, the key store, the audit log, the
 * self-test, the server -- and the five blocks that fill this screen. What it did not own was
 * the frame around them: the sidebar, which section is showing, and the card each block sits
 * in. So every plugin wrote that frame again. AST PRO wrote it inside its own settings
 * framework; Zorem Returns wrote it by hand, in a different file, against a different query
 * parameter, with the three sidebar icons copied in as literal SVG.
 *
 * That is roughly four hundred lines each, and the two copies had already drifted -- one
 * navigates on `ai_section`, the other on `aitab` -- which is what drift always looks like
 * before it looks like a bug. A third plugin would have copied whichever it found first.
 *
 * So the frame moves here. A plugin now says who it is and what its sentences are, and gets
 * the screen. What stays with the plugin is the only thing that was ever really its own: its
 * tools, registered with the shared registry.
 *
 * The screen also renders its own settings fields rather than borrowing a host plugin's
 * renderer. Those fields belong to Zorem_MCP_Settings, which lives here, and asking each
 * plugin's framework to store the library's own options is how they came to be saved three
 * different ways. They post to the library's own AJAX action instead.
 *
 * @package Zorem_MCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'zorem_mcp_icon' ) ) {
	/**
	 * A named icon. The library carries its own.
	 *
	 * WHY IT DOES NOT ASK THE HOST PLUGIN
	 *
	 * It did, and the screen shipped with no icons at all: a sidebar of bare words and an
	 * empty blue square where the section glyph belongs.
	 *
	 * Every Zorem plugin bundles assets/zui/icons.php, so \Zorem\UI\get_icon() looked like
	 * a safe thing to call. But bundling a file is not loading it -- Zorem Returns had never
	 * required it, because nothing in Returns had ever needed an icon. The call failed its
	 * function_exists() guard, the fallback named AST PRO's helper which does not exist
	 * there either, and the function returned an empty string. No error, no warning, no
	 * icons; the exact failure this library is supposed to make impossible.
	 *
	 * So the five glyphs this screen uses live here. They are copied from the ZUI set and
	 * drawn with the same viewBox and stroke, so a plugin that does load ZUI sees no
	 * difference -- and one that does not still gets its icons.
	 *
	 * @param string $name Icon key.
	 * @return string Inline SVG, empty for an unknown name.
	 */
	function zorem_mcp_icon( $name ) {
		$paths = array(
			// Your own link.
			'sparkles'       => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/>',
			// WooCommerce's route.
			'store'          => '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-4 0V7"/><path d="M18 10a2 2 0 0 1-4 0V7"/><path d="M14 10a2 2 0 0 1-4 0V7"/><path d="M10 10a2 2 0 0 1-4 0V7"/><path d="M6 10a2 2 0 0 1-4 0V7"/>',
			// The audit log.
			'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
			// Close the mobile drawer.
			'x'              => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
			// The tooltip marker.
			'help-circle'    => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
			// The Quick Help links.
			'arrow-right'    => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="zui-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"'
			. ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
			. ' aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}

if ( ! function_exists( 'zorem_mcp_render_quick_help' ) ) {
	/**
	 * The Quick Help card at the foot of the sidebar.
	 *
	 * It lived in AST PRO, which meant the screen had a help card in one plugin and a bare
	 * sidebar in every other -- the same screen, visibly less finished depending on which
	 * plugin you opened it from. The markup is ZUI's `zui-quickhelp` block, which every Zorem
	 * plugin already ships the stylesheet for, so it looks the same wherever it is drawn.
	 *
	 * The two links point at Zorem's docs and support by default because that is true of
	 * every plugin using this library; a plugin with its own documentation overrides them
	 * through `words`.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return void
	 */
	function zorem_mcp_render_quick_help( $config ) {
		$words = $config['words'];

		if ( '' === $words['help_title'] ) {
			return;
		}
		?>
		<div class="zui-quickhelp">
			<h4 class="zui-quickhelp__title"><?php echo esc_html( $words['help_title'] ); ?></h4>
			<p class="zui-quickhelp__text"><?php echo esc_html( $words['help_text'] ); ?></p>

			<div class="zui-quickhelp__links">
				<a class="zui-quickhelp__link" href="<?php echo esc_url( $words['help_docs_url'] ); ?>" target="_blank" rel="noreferrer noopener">
					<span><?php echo esc_html( $words['help_docs'] ); ?></span>
					<?php echo zorem_mcp_icon( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the icon set. ?>
				</a>
				<a class="zui-quickhelp__link zui-quickhelp__link--muted" href="<?php echo esc_url( $words['help_support_url'] ); ?>" target="_blank" rel="noreferrer noopener">
					<span><?php echo esc_html( $words['help_support'] ); ?></span>
					<?php echo zorem_mcp_icon( 'arrow-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the icon set. ?>
				</a>
			</div>

			<?php /* Decoration, and announced as such: two sheets of paper and a tick. */ ?>
			<div class="zui-quickhelp__art" aria-hidden="true">
				<span class="zui-quickhelp__glow"></span>
				<span class="zui-quickhelp__sphere"></span>

				<span class="zui-quickhelp__paper zui-quickhelp__paper--1">
					<span class="zui-quickhelp__line zui-quickhelp__line--brand"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--mid"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--short"></span>
				</span>
				<span class="zui-quickhelp__paper zui-quickhelp__paper--2">
					<span class="zui-quickhelp__line zui-quickhelp__line--accent"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--mid"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--mid"></span>
					<span class="zui-quickhelp__line zui-quickhelp__line--short"></span>
				</span>

				<span class="zui-quickhelp__check">&#10003;</span>
			</div>
		</div>
		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_screen_defaults' ) ) {
	/**
	 * Fill in everything the caller did not say.
	 *
	 * The sentences that name a plugin -- "your tracking", "your returns" -- have no sensible
	 * default, so they fall back to wording that is true of any plugin rather than to wording
	 * that is true of none. A plugin that overrides nothing still gets a screen that reads.
	 *
	 * @param array $config Caller's configuration.
	 * @return array
	 */
	function zorem_mcp_screen_defaults( $config ) {
		$config = is_array( $config ) ? $config : array();

		$config = array_merge(
			array(
				'slug'     => '',
				'title'    => __( 'AI Assistant', 'zorem-mcp' ),
				'label'    => __( 'Your link', 'zorem-mcp' ),
				'param'    => 'ai_section',
				'base_url' => '',
				'ids'      => array(),
				'sidebar'  => null,
				'words'    => array(),
			),
			$config
		);

		$config['ids'] = array_merge(
			array(
				'connection' => 'connection',
				'woo'        => 'woo-mcp',
				'audit'      => 'audit',
			),
			is_array( $config['ids'] ) ? $config['ids'] : array()
		);

		$config['words'] = array_merge(
			array(
				'connection_sub'  => __( 'Your own link', 'zorem-mcp' ),
				'connect_title'   => __( 'Connect Claude to your store', 'zorem-mcp' ),
				'connect_sub'     => __( 'Switch on, copy your link, paste it into Claude. Nothing to install.', 'zorem-mcp' ),
				'heading'         => __( 'AI Assistant', 'zorem-mcp' ),
				'description'     => __( 'Let an AI assistant work with this store. Changes save as you make them.', 'zorem-mcp' ),
				'enable_title'    => __( 'Enable the connection', 'zorem-mcp' ),
				'enable_tooltip'  => __( 'Gives you a single secret URL an AI assistant can connect to. This is the only route that works from claude.ai in a browser and from a phone. Treat the URL like a password.', 'zorem-mcp' ),
				'writes_title'    => __( 'Allow the AI to make changes', 'zorem-mcp' ),
				'writes_tooltip'  => __( 'On by default. Turn it off to make the link read-only — the AI can then look things up but not change them.', 'zorem-mcp' ),
				'writes_hint'     => __( 'Turn off to make the connection URL read-only. WooCommerce MCP is not affected — it signs in a real store user instead.', 'zorem-mcp' ),
				'steps'           => array(),
				'tools_title'     => __( 'What Claude can do', 'zorem-mcp' ),
				'tools_note'      => '',
				'tools_empty'     => __( 'No tools are available on this connection yet.', 'zorem-mcp' ),
				'connected_title' => __( 'Who is connected', 'zorem-mcp' ),
				'connected_sub'   => __( 'Everyone who has connected an AI assistant to your link. Turn any one of them off without affecting the rest.', 'zorem-mcp' ),
				'woo_label'       => __( 'Woo MCP', 'zorem-mcp' ),
				'woo_sub'         => __( "WooCommerce's own route", 'zorem-mcp' ),
				'woo_heading'     => __( 'WooCommerce MCP', 'zorem-mcp' ),
				'woo_description' => __( "WooCommerce's own AI connection. Your tools are already on it.", 'zorem-mcp' ),
				'woo_status_note' => '',
				'woo_key_sub'     => '',
				'woo_scope_note'  => '',
				'audit_label'     => __( 'Audit Log', 'zorem-mcp' ),
				'audit_sub'       => __( 'What the AI did', 'zorem-mcp' ),
				'audit_heading'   => __( 'Audit Log', 'zorem-mcp' ),
				'audit_desc'      => __( 'Every action taken by an AI assistant, with the result and any error.', 'zorem-mcp' ),
				'audit_keep'      => __( 'Keep entries for', 'zorem-mcp' ),
				'audit_keep_tip'  => __( 'Entries older than this are deleted automatically once a day. Set 0 to keep everything.', 'zorem-mcp' ),
				'audit_keep_hint' => __( 'Days of history to keep. Default is 30.', 'zorem-mcp' ),
				'audit_empty'     => __( 'Nothing yet. Once an AI assistant works on this store, every action it takes will be listed here.', 'zorem-mcp' ),
				'help_title'      => __( 'Quick Help', 'zorem-mcp' ),
				'help_text'       => __( 'Learn how to connect an AI assistant and what it can do.', 'zorem-mcp' ),
				'help_docs'       => __( 'View Documentation', 'zorem-mcp' ),
				'help_support'    => __( 'Get Support', 'zorem-mcp' ),
				'help_docs_url'   => 'https://docs.zorem.com/',
				'help_support_url' => 'https://www.zorem.com/support/',
			),
			is_array( $config['words'] ) ? $config['words'] : array()
		);

		return $config;
	}
}

if ( ! function_exists( 'zorem_mcp_screen_sections' ) ) {
	/**
	 * The sidebar. Three entries, in every Zorem plugin, always.
	 *
	 * WHY THERE IS NO WAY TO ADD A FOURTH
	 *
	 * There was, briefly -- an `extra` key for a plugin's own sections and an `after` hook to
	 * append to a shared one. Both are gone, and their absence is the feature.
	 *
	 * This screen answers one question, and it is the same question in every plugin: how do I
	 * connect an AI, who is connected, and what did they do. A merchant who learns it once in
	 * AST PRO should not have to re-read it in Returns, and the moment one plugin can add a
	 * fifth sidebar entry, they do have to. A seam that exists gets used; that is not a
	 * failing of whoever uses it, it is what a seam is for. So the seam is not there.
	 *
	 * What a plugin varies is its tools and its sentences. Anything else it needs a screen
	 * for is its own screen, on its own tab, where it is not competing with a shared spine.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return array Section id => meta.
	 */
	function zorem_mcp_screen_sections( $config ) {
		$words = $config['words'];
		$ids   = $config['ids'];

		$sections = array(
			$ids['connection'] => array(
				'label'       => $config['label'],
				'sub'         => $words['connection_sub'],
				'heading'     => $words['heading'],
				'description' => $words['description'],
				'icon'        => 'sparkles',
				'render'      => 'zorem_mcp_screen_connection',
			),
			$ids['woo']        => array(
				'label'       => $words['woo_label'],
				'sub'         => $words['woo_sub'],
				'heading'     => $words['woo_heading'],
				'description' => $words['woo_description'],
				'icon'        => 'store',
				'render'      => 'zorem_mcp_screen_woo',
			),
			$ids['audit']      => array(
				'label'       => $words['audit_label'],
				'sub'         => $words['audit_sub'],
				'heading'     => $words['audit_heading'],
				'description' => $words['audit_desc'],
				'icon'        => 'clipboard-list',
				'render'      => 'zorem_mcp_screen_audit',
			),
		);

		return $sections;
	}
}

if ( ! function_exists( 'zorem_mcp_active_section' ) ) {
	/**
	 * Which section the screen opens on.
	 *
	 * An unknown id falls back to the first rather than to an error: the value arrives in a
	 * URL, and a merchant who follows a stale bookmark should land on the screen rather than
	 * on a blank panel telling them their link was wrong.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return string
	 */
	function zorem_mcp_active_section( $config ) {
		$sections = zorem_mcp_screen_sections( $config );

		/*
		 * Two parameters, because two things write the address of this screen.
		 *
		 * The configured one is what a link to a section carries -- a bookmark, or a support
		 * reply pointing straight at the audit log. The host's settings script writes the
		 * other: it puts `?section=<id>` on the URL as the merchant clicks through the
		 * sidebar, so a refresh lands where they were rather than flashing the wrong panel.
		 *
		 * Reading only the first meant every refresh of this screen came back on the opening
		 * section, however far in the merchant had navigated -- the URL said woo-mcp and the
		 * page showed the connection.
		 *
		 * An id only counts if this screen actually has a section by that name, so `section`
		 * left behind by another tab cannot steer this one.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state; nothing is acted on.
		$get = wp_unslash( $_GET );

		foreach ( array( $config['param'], 'section' ) as $param ) {
			if ( '' === (string) $param || ! isset( $get[ $param ] ) ) {
				continue;
			}

			$wanted = sanitize_key( $get[ $param ] );

			if ( isset( $sections[ $wanted ] ) ) {
				return $wanted;
			}
		}

		return (string) key( $sections );
	}
}

/*
|--------------------------------------------------------------------------
| Fields
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'zorem_mcp_enable_hint' ) ) {
	/**
	 * The line under the enable switch, matched to what this store is actually in.
	 *
	 * Three situations, three different next steps, and telling a merchant the wrong one is
	 * worse than telling them nothing: someone whose link is already live does not need
	 * instructions for switching it on, and someone who has WooCommerce MCP already deserves
	 * to know this is not a second copy of it.
	 *
	 * @return string
	 */
	function zorem_mcp_enable_hint() {
		if ( zorem_mcp_own_connection_active() ) {
			if ( ! get_option( 'permalink_structure' ) ) {
				return __( 'Your connection URL is below. Set Settings > Permalinks to anything other than Plain for a cleaner URL.', 'zorem-mcp' );
			}

			return __( 'Your connection URL is below.', 'zorem-mcp' );
		}

		if ( zorem_mcp_woo_mcp_active() ) {
			return __( 'WooCommerce MCP already gives these tools to AI assistants on a computer. Switch this on as well only if you also want to connect from claude.ai or a phone, which WooCommerce MCP cannot do.', 'zorem-mcp' );
		}

		return __( 'Switch on, then paste the URL that appears into your AI assistant as a custom connector.', 'zorem-mcp' );
	}
}

if ( ! function_exists( 'zorem_mcp_save_setting' ) ) {
	/**
	 * Store one of the library's own settings.
	 *
	 * Saved one field at a time, as it is changed, because that is how the screen behaves --
	 * there is no Save button, and a screen that saves on change should not be posting a whole
	 * form to do it.
	 *
	 * Switching the connection on issues its token here rather than leaving that to the next
	 * page load. The link is drawn immediately below the switch, and a screen that offers an
	 * address which has not been issued yet is offering a 404.
	 *
	 * @return void
	 */
	function zorem_mcp_save_setting() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'zorem-mcp' ) ), 403 );
		}

		check_ajax_referer( 'zorem_mcp_form', 'zorem_mcp_form_nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// An allow-list, not a sanitiser. These three are the only options this screen owns,
		// and the request names the key -- so anything else arriving here is either a bug or
		// somebody probing what else Zorem_MCP_Settings will write.
		$allowed = array(
			'enabled'         => 'bool',
			'allow_write'     => 'bool',
			'audit_retention' => 'int',
		);

		if ( ! isset( $allowed[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown setting.', 'zorem-mcp' ) ), 400 );
		}

		$value = ( 'int' === $allowed[ $key ] ) ? max( 0, (int) $value ) : (int) (bool) $value;

		Zorem_MCP_Settings::set( $key, $value );

		if ( 'enabled' === $key && $value && '' === Zorem_MCP_Settings::token() ) {
			Zorem_MCP_Settings::issue_token( get_current_user_id() );
		}

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
				'url'   => zorem_mcp_connect_url(),
			)
		);
	}
}

add_action( 'wp_ajax_zorem_mcp_save_setting', 'zorem_mcp_save_setting' );


if ( ! function_exists( 'zorem_mcp_render_field' ) ) {
	/**
	 * One of the library's own settings, as a ZUI row.
	 *
	 * Deliberately not routed through a host plugin's field renderer. These options are
	 * stored by Zorem_MCP_Settings, which lives in this package, and every plugin that
	 * borrowed its own renderer for them also had to teach its own save handler about them --
	 * which is how the same three options came to be written three different ways. The input
	 * carries `data-zorem-mcp-field` and the package's script saves it; no host plugin needs
	 * to know these fields exist.
	 *
	 * @param string $key  Setting key in Zorem_MCP_Settings.
	 * @param array  $args Label, tooltip, hint, type ('toggle' or 'number').
	 * @return void
	 */
	function zorem_mcp_render_field( $key, $args ) {
		if ( ! class_exists( 'Zorem_MCP_Settings' ) ) {
			return;
		}

		$args = array_merge(
			array(
				'type'    => 'toggle',
				'title'   => '',
				'tooltip' => '',
				'hint'    => '',
				'min'     => 0,
			),
			is_array( $args ) ? $args : array()
		);

		$value = Zorem_MCP_Settings::get( $key );
		$id    = 'zorem-mcp-' . sanitize_key( $key );
		?>
		<div class="zui-row zui-row--inline">
			<div class="zui-row__head">
				<span class="zui-row__label">
					<?php echo esc_html( $args['title'] ); ?>
					<?php if ( '' !== $args['tooltip'] ) : ?>
						<span class="zui-tooltip" tabindex="0" role="img" aria-label="<?php echo esc_attr( $args['tooltip'] ); ?>">
							<?php echo zorem_mcp_icon( 'help-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the icon set. ?>
							<span class="zui-tooltip__bubble"><?php echo esc_html( $args['tooltip'] ); ?><span class="zui-tooltip__arrow"></span></span>
						</span>
					<?php endif; ?>
				</span>
				<?php if ( '' !== $args['tooltip'] ) : ?>
					<p class="zui-row__desc"><?php echo esc_html( $args['tooltip'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $args['hint'] ) : ?>
					<p class="zui-row__hint"><?php echo esc_html( $args['hint'] ); ?></p>
				<?php endif; ?>
			</div>
			<div class="zui-row__control">
				<?php if ( 'number' === $args['type'] ) : ?>
					<?php /* Sized to its content. A three-digit day count in a full-width box reads as a field that wants a sentence. */ ?>
					<input
						type="number"
						class="zui-input zorem-mcp-number"
						id="<?php echo esc_attr( $id ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						min="<?php echo esc_attr( $args['min'] ); ?>"
						data-zorem-mcp-field="<?php echo esc_attr( $key ); ?>"
					>
				<?php else : ?>
					<label class="zui-toggle">
						<input
							type="checkbox"
							class="zui-toggle__input"
							id="<?php echo esc_attr( $id ); ?>"
							value="1"
							<?php checked( (bool) $value, true ); ?>
							data-zorem-mcp-field="<?php echo esc_attr( $key ); ?>"
						>
						<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
					</label>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

/*
|--------------------------------------------------------------------------
| The three shared sections
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'zorem_mcp_screen_connection' ) ) {
	/**
	 * The connection section: the switch, the link, who holds one, and what they can do.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return void
	 */
	function zorem_mcp_screen_connection( $config ) {
		$words = $config['words'];
		$url   = zorem_mcp_connect_url();
		?>

		<div class="zui-card">
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php echo esc_html( $words['connect_title'] ); ?></h3>
				<p class="zui-card__sub"><?php echo esc_html( $words['connect_sub'] ); ?></p>
			</div>

			<?php
			zorem_mcp_render_field(
				'enabled',
				array(
					'title'   => $words['enable_title'],
					'tooltip' => $words['enable_tooltip'],
					'hint'    => zorem_mcp_enable_hint(),
				)
			);

			zorem_mcp_render_connection(
				array(
					'url'      => $url,
					'wrap'     => 'tracking',
					'field_id' => zorem_mcp_endpoint_field_id(),
					'hint'     => zorem_mcp_link_hint(),
					'steps'    => $words['steps'],
				)
			);
			?>
		</div>

		<?php if ( class_exists( 'Zorem_MCP_Keys' ) && Zorem_MCP_Keys::available() ) : ?>
			<div class="zui-card">
				<div class="zui-card__head">
					<h3 class="zui-card__title"><?php echo esc_html( $words['connected_title'] ); ?></h3>
					<p class="zui-card__sub"><?php echo esc_html( $words['connected_sub'] ); ?></p>
				</div>

				<?php /* Replaced in place when everyone is signed out — every row's status
				         changes at once, and none of it is worth a page load. */ ?>
				<div data-zorem-mcp-connections>
					<?php zorem_mcp_render_connections(); ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="zui-card">
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php echo esc_html( $words['tools_title'] ); ?></h3>
			</div>

			<?php
			// The permission sits with the list it governs. Split apart, a merchant reads what
			// the AI can reach on one card and decides whether it may write on another, having
			// forgotten the first by the time they get there.
			zorem_mcp_render_field(
				'allow_write',
				array(
					'title'   => $words['writes_title'],
					'tooltip' => $words['writes_tooltip'],
					'hint'    => $words['writes_hint'],
				)
			);

			zorem_mcp_render_tools(
				$config['slug'],
				array(
					'empty' => $words['tools_empty'],
					'note'  => $words['tools_note'],
				)
			);
			?>
		</div>

		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_screen_woo' ) ) {
	/**
	 * The WooCommerce MCP section.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return void
	 */
	function zorem_mcp_screen_woo( $config ) {
		$words = $config['words'];
		$args  = array();

		foreach ( array( 'status_note', 'key_sub', 'scope_note' ) as $key ) {
			if ( '' !== $words[ 'woo_' . $key ] ) {
				$args[ $key ] = $words[ 'woo_' . $key ];
			}
		}

		zorem_mcp_render_woo_mcp( $args );
	}
}

if ( ! function_exists( 'zorem_mcp_screen_audit' ) ) {
	/**
	 * The audit log section, and how long it is kept.
	 *
	 * The retention setting lives with the table it governs rather than with the connection
	 * settings: a merchant who thinks the log is too long or too short is already looking at
	 * the log.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return void
	 */
	function zorem_mcp_screen_audit( $config ) {
		$words = $config['words'];
		?>

		<div class="zui-card">
			<?php
			zorem_mcp_render_field(
				'audit_retention',
				array(
					'type'    => 'number',
					'title'   => $words['audit_keep'],
					'tooltip' => $words['audit_keep_tip'],
					'hint'    => $words['audit_keep_hint'],
				)
			);
			?>
		</div>

		<div class="zui-card">
			<?php
			zorem_mcp_render_audit_log(
				array(
					'page_url' => zorem_mcp_screen_pager( $config ),
					'empty'    => $words['audit_empty'],
				)
			);
			?>
		</div>

		<?php
	}
}

if ( ! function_exists( 'zorem_mcp_screen_pager' ) ) {
	/**
	 * A link to another page of the log, landing back on this screen and this section.
	 *
	 * The one thing the log renderer cannot work out for itself: it has no idea which admin
	 * screen it is being drawn on.
	 *
	 * @param array $config Screen configuration, already defaulted.
	 * @return callable
	 */
	function zorem_mcp_screen_pager( $config ) {
		$base    = $config['base_url'];
		$param   = $config['param'];
		$section = zorem_mcp_active_section( $config );

		return static function ( $page ) use ( $base, $param, $section ) {
			return add_query_arg(
				array(
					$param        => $section,
					'ai_log_page' => (int) $page,
				),
				$base
			);
		};
	}
}

/*
|--------------------------------------------------------------------------
| The screen
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'zorem_mcp_render_screen' ) ) {
	/**
	 * Draw the whole AI Assistant screen: sidebar, sections and all.
	 *
	 * The markup shape -- .zui-layout > .zui-sidebar + .zui-content > .zui-section -- is not
	 * decoration. zui.js scopes sidebar navigation to the enclosing .zui-layout, so this
	 * sidebar drives these sections and never touches another tab's, with no JavaScript of
	 * its own. Change the shape and the sidebar stops working silently.
	 *
	 * @param array $config {
	 *     @type string   $slug     The plugin's slug in the shared registry. Required.
	 *     @type string   $title    Screen name, used on the mobile header and for aria.
	 *     @type string   $label    Sidebar label for the connection section.
	 *     @type string   $param    Query argument the sidebar navigates on.
	 *     @type string   $base_url Screen URL, without the section argument.
	 *     @type array    $ids      Override the three shared section ids.
	 *     @type callable $sidebar  Drawn at the foot of the sidebar. Optional.
	 *     @type array    $words    Every sentence on the screen.
	 * }
	 * @return void
	 *
	 * @see zorem_mcp_enqueue_assets() Call it from admin_enqueue_scripts; this is too late.
	 */
	function zorem_mcp_render_screen( $config ) {
		$config = zorem_mcp_screen_defaults( $config );

		if ( '' === $config['slug'] ) {
			return;
		}

		$sections = zorem_mcp_screen_sections( $config );
		$active   = zorem_mcp_active_section( $config );

		/*
		 * No enqueue here, deliberately.
		 *
		 * This runs while the admin page is being printed, which is long after wp_print_styles
		 * has fired -- a stylesheet enqueued at this point is simply dropped, with no error and
		 * no missing-file warning, and the screen renders unstyled. The failure is silent,
		 * which is the worst kind to build in.
		 *
		 * So the host plugin calls zorem_mcp_enqueue_assets() from its own
		 * admin_enqueue_scripts hook, where it is early enough to work. Three lines, and they
		 * are the only three this screen asks a plugin to write outside its configuration.
		 */
		?>
		<div class="zui-layout">

			<div class="zui-sidebar__overlay" data-ast-drawer-close data-zui-drawer-close></div>

			<aside class="zui-sidebar">

				<div class="zui-sidebar__mobile-head">
					<span class="zui-sidebar__mobile-title"><?php echo esc_html( $config['title'] ); ?></span>
					<button type="button" class="zui-sidebar__close" data-ast-drawer-close data-zui-drawer-close aria-label="<?php esc_attr_e( 'Close menu', 'zorem-mcp' ); ?>">
						<?php echo zorem_mcp_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the icon set. ?>
					</button>
				</div>

				<nav class="zui-sidebar__nav" aria-label="<?php echo esc_attr( $config['title'] ); ?>">
					<?php foreach ( $sections as $id => $meta ) : ?>
						<?php $is_on = ( $id === $active ); ?>
						<button
							type="button"
							class="zui-sidebar__item<?php echo $is_on ? ' is-active' : ''; ?>"
							data-section="<?php echo esc_attr( $id ); ?>"
							data-zui-section="<?php echo esc_attr( $id ); ?>"
							<?php echo $is_on ? 'aria-current="true"' : ''; ?>
						>
							<span class="zui-sidebar__icon"><?php echo zorem_mcp_icon( $meta['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the icon set. ?></span>
							<span class="zui-sidebar__label"><?php echo esc_html( $meta['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</nav>

				<?php
				zorem_mcp_render_quick_help( $config );

				if ( is_callable( $config['sidebar'] ) ) {
					call_user_func( $config['sidebar'] );
				}
				?>

			</aside>

			<main class="zui-content">

				<?php zorem_mcp_nonce_field(); ?>

				<?php foreach ( $sections as $id => $meta ) : ?>
					<?php $is_on = ( $id === $active ); ?>
					<section
						class="zui-section<?php echo $is_on ? ' is-active' : ''; ?>"
						data-section="<?php echo esc_attr( $id ); ?>"
						data-zui-section="<?php echo esc_attr( $id ); ?>"
						<?php echo $is_on ? '' : 'hidden'; ?>
					>
						<?php
						/*
						 * ZUI's own section header, class for class.
						 *
						 * Not a simplified version of it. These class names are what
						 * components/section-header.css styles, and a near-miss renders as
						 * unstyled text rather than as an error -- so the shape is copied
						 * exactly, including the actions column the flex row expects on the
						 * right.
						 *
						 * The Save button in that column is inert on purpose. This screen has
						 * no Save: every field posts itself as it changes. The button stays
						 * because removing it leaves the header visibly lopsided against every
						 * other settings screen in the plugin, and because a merchant who has
						 * learned to look for it should find it rather than wonder where their
						 * change went. Disabled and aria-disabled, so it never claims to do
						 * something.
						 */
						?>
						<div class="zui-section-header">
							<div class="zui-section-header__main">
								<span class="zui-section-header__icon"><?php echo zorem_mcp_icon( $meta['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the icon set. ?></span>
								<div class="zui-section-header__text">
									<div class="zui-section-header__titlewrap">
										<h2 class="zui-section-header__title"><?php echo esc_html( $meta['heading'] ); ?></h2>
									</div>
									<?php if ( '' !== $meta['description'] ) : ?>
										<p class="zui-section-header__sub"><?php echo esc_html( $meta['description'] ); ?></p>
									<?php endif; ?>
								</div>
							</div>
							<div class="zui-section-header__actions">
								<button type="button" class="zui-savebtn zui-savebtn--inert" disabled aria-disabled="true">
									<span class="zui-savebtn__label"><?php esc_html_e( 'Save Changes', 'zorem-mcp' ); ?></span>
								</button>
							</div>
						</div>

						<?php call_user_func( $meta['render'], $config ); ?>
					</section>
				<?php endforeach; ?>

			</main>

		</div>
		<?php
	}
}
