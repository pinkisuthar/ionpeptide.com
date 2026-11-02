# Zorem MCP

The shared AI connection. Drop this folder into a plugin and it gets the whole
"AI Assistant" screen — one link a merchant pastes into Claude, sign-in, per-person
revoke, the audit log, the WooCommerce MCP screen and the self-test.

**Your plugin writes its tools. Nothing else.**

> **Integrating this into a plugin? Read `CLAUDE.md` in this folder first.**
> It is the complete map — where every file goes, the whole bridge, every rule,
> and every mistake that has already shipped once. This README is the short
> reference for someone who has read it.

Version: see `VERSION`. Every plugin ships a byte-identical copy; the newest one
on a request wins. Never edit one copy — edit the source and re-copy to all.

---

## 1. Register the package

In your main plugin file, **before** the licence check:

```php
require_once plugin_dir_path( __FILE__ ) . 'zorem-mcp/bootstrap.php';
zorem_mcp_register_package(
	trim( (string) file_get_contents( plugin_dir_path( __FILE__ ) . 'zorem-mcp/VERSION' ) ),
	plugin_dir_path( __FILE__ ) . 'zorem-mcp'
);
```

Before the licence check on purpose: the connection belongs to the merchant, not
to your plugin. An expired licence must remove **your tools**, not their link.

## 2. Register your tools

```php
class My_Plugin_MCP_Bridge {

	const SLUG = 'myplugin';   // becomes the tool-name prefix: myplugin-do-thing

	public static function init() {
		add_action( 'zorem_mcp_loaded', array( __CLASS__, 'register' ) );
	}

	public static function register( $registry ) {
		if ( ! self::licensed() ) {
			return;   // no licence, no tools -- the link keeps working
		}

		$registry->add_plugin(
			self::SLUG,
			array(
				'label' => __( 'My Plugin', 'my-plugin' ),
				'tools' => array(
					'list-things' => array(
						'label'        => __( 'List things', 'my-plugin' ),
						'description'  => __( 'Returns the things on an order.', 'my-plugin' ),
						'input_schema' => array(
							'type'       => 'object',
							'properties' => array(
								'order_id' => array( 'type' => 'integer' ),
							),
							'required'   => array( 'order_id' ),
						),
						'callback'     => array( __CLASS__, 'list_things' ),
					),
					'delete-thing' => array(
						'label'       => __( 'Delete thing', 'my-plugin' ),
						'writes'      => true,      // hidden when writes are switched off
						'destructive' => true,      // red "Delete" badge
						'callback'    => array( __CLASS__, 'delete_thing' ),
					),
				),
			)
		);
	}

	public static function list_things( $args ) {
		// Return an array, or a WP_Error. Both are handled.
		return array( 'things' => array() );
	}
}
```

### One tool

| Key | | |
|---|---|---|
| `label` | | Short title, used **exactly as you write it**. Leave it out and the library builds one from your tool key — separators to spaces, first letter up, nothing removed |
| `description` | | One sentence. The AI reads this to decide when to call it |
| `input_schema` | | JSON Schema. Defaults to `array( 'type' => 'object' )` |
| `callback` | required | `function ( array $args )` → array or `WP_Error` |
| `writes` | | Changes data. **Removed from the link entirely** when the merchant switches writes off |
| `destructive` | | Deletes data. Gets the red badge |

Tool names are built for you: `slug` + `-` + the key. Keys are `sanitize_key()`d,
so use lowercase and dashes.

## 3. Draw the screen

```php
zorem_mcp_render_screen( array(
	'slug'     => My_Plugin_MCP_Bridge::SLUG,
	'label'    => __( 'My MCP', 'my-plugin' ),
	'base_url' => admin_url( 'admin.php?page=my-plugin&tab=ai-assistant' ),
	'words'    => array(
		'connect_title' => __( 'Connect Claude to your things', 'my-plugin' ),
		'steps'         => array(
			__( 'Open claude.ai and go to Settings, then Connectors.', 'my-plugin' ),
			__( 'Choose Add custom connector, give it any name, and paste your link.', 'my-plugin' ),
			__( 'Ask Claude: which things need attention?', 'my-plugin' ),
		),
	),
) );
```

## 4. Enqueue the assets

From your own `admin_enqueue_scripts` hook — **not** from the render, which runs
after `wp_print_styles` and would be silently dropped:

```php
if ( function_exists( 'zorem_mcp_enqueue_assets' ) ) {
	zorem_mcp_enqueue_assets();
}
```

That is the whole integration.

---

## The screen is always three sections

**Your MCP · Woo MCP · Audit Log.** In every plugin, in that order.

There is no way to add a fourth, and that is deliberate. A merchant who learns
this screen once should not have to re-read it in the next plugin. If your plugin
needs another screen, give it its own tab — Zorem Returns puts its customer-facing
assistant on a **Customer AI** tab for exactly this reason.

## `words` — every sentence you can change

Anything you leave out falls back to wording that is true of any plugin.

| Key | Default |
|---|---|
| `heading` `description` | Section header |
| `connect_title` `connect_sub` | First card |
| `enable_title` `enable_tooltip` | The on/off switch |
| `writes_title` `writes_tooltip` `writes_hint` | The write permission |
| `steps` | The numbered setup list. **Give it yours** — the default is empty |
| `tools_title` `tools_note` `tools_empty` | The tool list |
| `connected_title` `connected_sub` | Who is connected |
| `woo_*` | The WooCommerce MCP section |
| `audit_*` | The log and its retention |

## `zorem_mcp_render_screen()` — the rest of the config

| Key | | |
|---|---|---|
| `slug` | **required** | Your registry slug. No slug, no screen |
| `base_url` | | Your screen's URL. The log's pagination links need it |
| `label` | | Sidebar label for your section |
| `title` | | Mobile header and aria label |
| `param` | | Query arg the sidebar navigates on. Default `ai_section` |
| `ids` | | Override the three section ids — only for keeping old bookmarks alive |
| `sidebar` | | Callable drawn at the foot of the sidebar |

## It names no plugin but yours

The library carries its own icons, its own `zorem-mcp` text domain, and calls no
constant or function belonging to a host plugin. Every merchant-facing string
says "your own link", never a plugin's name, and the connector each AI client
files the connection under is `zorem` — because the link carries the tools of
every Zorem plugin on the store, not one of them.

This is not tidiness. A shared file that reaches for `AST_PLUGIN_ACCESS` works
perfectly in AST PRO and is a fatal error everywhere else. One that calls
`\Zorem\UI\get_icon()` draws a screen with no icons at all on a plugin that
bundles ZUI without ever loading it. Both shipped. Both were invisible until the
library was installed somewhere new.

**If you find yourself typing a plugin's name into this folder, stop.**

## What the library owns

Settings, the registry, the MCP server, OAuth 2.1 + PKCE, dynamic client
registration, the per-person key store, the audit log, the self-test and its
`.htaccess` repair, the WooCommerce key screen, the CSS, the JS, and the screen.

Its three options (`enabled`, `allow_write`, `audit_retention`) are stored by
`Zorem_MCP_Settings` and saved by the library's own AJAX action as each field
changes. **Do not teach your plugin's settings framework about them** — that is
how the same three options came to be saved three different ways.

## Rules

1. **Every copy is byte-identical.** A copy edited in one plugin wins on some
   sites and loses on others, and you will not be able to reproduce the bug.
2. **Bump `VERSION` on every change.** Two copies claiming the same version with
   different content is the same problem, hidden better.
3. **Register before the licence check.** The merchant's link is not yours to
   take away.
4. **Register tools inside the licence check.** Your tools are.
