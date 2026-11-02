# Zorem MCP — read this before you touch anything

You are integrating the shared Zorem MCP library into a WordPress plugin, or
changing the library itself. This file is the whole map. If you follow it you
cannot get it wrong; every warning below is here because somebody already made
that exact mistake and shipped it.

**The one rule:** this folder is byte-identical in every Zorem plugin, and it
must never name, call, or depend on any single plugin.

---

## 1. Which job are you doing?

| You want to | Go to |
|---|---|
| Put MCP into a plugin that has none | §2 first, then §3, §4, §5 — about 60 lines of your code |
| Add a tool to a plugin that already has MCP | §4 only |
| Change the library itself | §8 — read it before you edit a single line |
| Understand why the screen looks like it does | §6 |
| Something is broken | §9 |

---

## 2. How a question becomes your data

Read this before you write any code, or you will build an endpoint — and you must not.

**There is one endpoint on the whole store, and the library owns it.** Your
plugin does not register a REST route, does not parse JSON-RPC, does not check a
token. It supplies functions; the library calls them.

```
  Claude / ChatGPT / Cursor
            │
            │  POST { "method": "tools/call",
            │         "params": { "name": "your-plugin-list-things",
            │                     "arguments": { "order_id": 123 } } }
            ▼
  /wp-json/zorem-mcp/v1/mcp          ← THE endpoint. One per store. Library's.
            │
            ├─ 1. Who is this?       OAuth token → a real WP user, set as current
            ├─ 2. Is writing on?     'writes' tools refused when the switch is off
            ├─ 3. Log it             one audit row, whichever plugin answers
            │
            ▼
  Zorem_MCP_Registry::call( 'your-plugin-list-things', $args )
            │
            │  splits the name:  your-plugin  +  list-things
            │                    └─ slug ─┘     └─ your key ─┘
            ▼
  YOUR CALLBACK                     ← this is where your plugin starts
            │
            │  $wpdb, wc_get_orders(), Your_Plugin_Service::get_things()
            │  — whatever your plugin already uses
            ▼
  return array( 'things' => [...] )  ← the library JSON-encodes it and replies
```

**The tool name is the routing.** That is the whole mechanism. You registered
`list-things` under slug `your-plugin`, so the library knows
`your-plugin-list-things` is yours and hands it straight to your callback with
the AI's arguments already decoded.

### Where the data comes from

**From your callback.** It runs inside WordPress, as a logged-in user, with your
plugin fully loaded. Everything your plugin can normally do, it can do there:

```php
public static function list_things( $args ) {
    global $wpdb;

    // Your tables.
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ... WHERE order_id = %d", $order_id ), ARRAY_A );

    // Or WooCommerce.
    $orders = wc_get_orders( array( 'limit' => 20 ) );

    // Or your own service, which is usually the right answer.
    $things = Your_Plugin_Service::get_things( $order_id );

    return array( 'things' => $things );
}
```

There is no extra plumbing between the AI and that function. Nothing to serialise,
nothing to route, nothing to authenticate — it has all been done by the time your
first line runs.

### Why not one endpoint per plugin

Because an endpoint is a **link the merchant has to paste**, and every plugin
with its own endpoint is another link, another sign-in, another connector to
manage, and another place to revoke someone.

That is what this library exists to prevent. One link carries every Zorem
plugin's tools; install a second Zorem plugin and its tools simply appear on the
link the merchant already gave Claude, with no reconnection. Add your own
endpoint and you have taken that away.

> **A separate endpoint is the right answer for exactly one thing:** a route that
> answers a *different person*. Zorem Returns has one for **customers** — its own
> OAuth, its own keys, its own rate limits — because the shared link authenticates
> as the shop owner, and a customer tool running as the shop owner would answer
> about the wrong person. That endpoint is Returns' own code, entirely outside
> this library. If your plugin is only for the merchant, you do not need one.

### What you still cannot skip

The library authenticates. It does **not** authorise. It proves *which signed-in
person* is calling and writes it to the audit log; whether that person may touch
your data is a question only your plugin can answer. **Every callback checks a
capability on its first line.** See §4.4.

---

## 3. Where every file goes

The library is a folder you drop in. **You do not edit anything inside it.**

```
your-plugin/
├── your-plugin.php               ← 6 lines here (§3.1)
├── zorem-mcp/                    ← THIS FOLDER. Never edit. Never rename.
│   ├── VERSION                       (bump only if you edit the library)
│   ├── bootstrap.php  screen.php  ui-helpers.php
│   ├── class-zorem-mcp-*.php
│   ├── assets/  languages/
│   ├── README.md                     (quick reference)
│   └── CLAUDE.md                     (this file)
└── includes/
    └── mcp/                      ← YOUR code. Create this folder.
        └── class-your-plugin-mcp-bridge.php   ← ONE file. That is all you need.
```

**You need exactly one new file.** `includes/mcp/class-{plugin}-mcp-bridge.php`.

Do not create a file per tool. Do not create an endpoint file — the library owns
the endpoint. Do not create a settings page, a CSS file, a JS file, an OAuth
handler, a key store, or an audit log; the library has all of them and yours
would be a second one competing with it.

If your plugin genuinely has dozens of tools and one file gets unwieldy, put the
*handlers* in your existing service classes where the logic already lives, and
keep the bridge as the thin list that points at them. The bridge stays one file.

### 3.1 Register the package

In your main plugin file, **before** the licence check:

```php
require_once plugin_dir_path( __FILE__ ) . 'zorem-mcp/bootstrap.php';
zorem_mcp_register_package(
    trim( (string) file_get_contents( plugin_dir_path( __FILE__ ) . 'zorem-mcp/VERSION' ) ),
    plugin_dir_path( __FILE__ ) . 'zorem-mcp'
);
```

**Before the licence check, always.** The connection belongs to the merchant, not
to your plugin. An expired licence must remove *your tools* and leave their link,
their sign-ins and their audit history working. Put this inside the licence check
and an expiry silently kills a merchant's AI connection for every Zorem plugin on
the store.

Every plugin ships its own copy and registers it. The highest VERSION offered on
a request boots; the rest stand down. So a store with three Zorem plugins runs
one library, at the newest version installed.

---

## 4. The bridge — your only file

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Your_Plugin_MCP_Bridge {

    /**
     * Prefixes every tool name: your-plugin-list-things.
     * NEVER change this after release — AI clients store the tool names.
     */
    const SLUG = 'your-plugin';

    public static function init() {
        add_action( 'zorem_mcp_loaded', array( __CLASS__, 'register' ) );
    }

    public static function register( $registry ) {
        // Licence checked HERE, not around the package registration.
        if ( ! self::licensed() ) {
            return;   // no tools; the merchant's link keeps working
        }

        $registry->add_plugin(
            self::SLUG,
            array(
                'label' => __( 'Your Plugin', 'your-plugin' ),
                'tools' => self::tools(),
            )
        );
    }

    protected static function tools() {
        return array(

            'list-things' => array(
                'label'        => __( 'List things', 'your-plugin' ),
                'description'  => __( 'List the things on an order, newest first. Use this to answer "what is on order 123". For things still awaiting approval use list-pending instead.', 'your-plugin' ),
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'order_id' => array(
                            'type'        => 'integer',
                            'description' => __( 'The order to look at.', 'your-plugin' ),
                        ),
                        'limit'    => array(
                            'type'        => 'integer',
                            'description' => __( 'How many to return, 1 to 100. Defaults to 20.', 'your-plugin' ),
                            'minimum'     => 1,
                            'maximum'     => 100,
                        ),
                    ),
                    'required'   => array( 'order_id' ),
                ),
                'callback'     => array( __CLASS__, 'list_things' ),
            ),

            'approve-thing' => array(
                'label'        => __( 'Approve thing', 'your-plugin' ),
                'description'  => __( 'Approve one pending thing and email the customer.', 'your-plugin' ),
                'writes'       => true,
                'input_schema' => array(
                    'type'       => 'object',
                    'properties' => array( 'thing_id' => array( 'type' => 'integer' ) ),
                    'required'   => array( 'thing_id' ),
                ),
                'callback'     => array( __CLASS__, 'approve_thing' ),
            ),

            'delete-thing' => array(
                'label'       => __( 'Delete thing', 'your-plugin' ),
                'description' => __( 'Permanently delete one thing. This cannot be undone.', 'your-plugin' ),
                'writes'      => true,
                'destructive' => true,
                'callback'    => array( __CLASS__, 'delete_thing' ),
            ),
        );
    }

    /**
     * Every callback does these four things, in this order.
     */
    public static function list_things( $args ) {

        // 1. PERMISSION. The library proved WHO is calling and wrote it to the
        //    audit log. Whether that person may touch YOUR data is your question.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new WP_Error(
                'your_plugin_forbidden',
                __( 'This connection is not allowed to read things.', 'your-plugin' )
            );
        }

        // 2. INPUT. An AI will send limit: 100000. Clamp everything.
        $order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
        $limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 20;
        $limit    = max( 1, min( 100, $limit ) );

        if ( ! $order_id ) {
            return new WP_Error( 'your_plugin_no_order', __( 'An order number is required.', 'your-plugin' ) );
        }

        // 3. YOUR EXISTING CODE. Do not write new business logic here.
        $things = Your_Plugin_Service::get_things( $order_id, $limit );

        // 4. A PLAIN ARRAY. The library turns it into JSON.
        return array(
            'order_id' => $order_id,
            'count'    => count( $things ),
            'things'   => $things,
        );
    }

    protected static function licensed() {
        return function_exists( 'your_plugin' )
            && isset( your_plugin()->license )
            && (bool) your_plugin()->license->check_subscription_status();
    }
}

Your_Plugin_MCP_Bridge::init();
```

### 4.1 What each key does

| Key | | |
|---|---|---|
| `callback` | **required** | `function ( array $args )` → array, or `WP_Error` |
| `label` | | Short title, used **exactly as you write it**. Omit it and the library derives one from the key: separators to spaces, first letter up, **nothing removed**. Write `Admin: approve claim` if that is what you mean — the library will not edit it |
| `description` | | The most important field. See §4.2 |
| `input_schema` | | JSON Schema. Defaults to `array( 'type' => 'object' )` |
| `writes` | | The tool changes data |
| `destructive` | | The tool **deletes** data |

Tool names are built for you: `SLUG` + `-` + your key, run through `sanitize_key()`.
Use lowercase and dashes. Two plugins can both have `list-things`; the slug keeps
them apart.

### 4.2 `description` decides which tool the AI calls

It is not documentation. With ten tools on one link from three plugins, the
description is the only thing stopping the model reaching for the wrong one.

* Weak: `Gets shipments.`
* Strong: `List and count orders that HAVE shipment tracking, newest first,
  optionally narrowed to one carrier or a date range. Answers questions about
  what has already shipped. **For orders still awaiting tracking use
  get-orders-missing-tracking instead.**`

Point at your sibling tools. Say what the tool is *not* for.

### 4.3 `writes` and `destructive` — mark them honestly

The merchant has one switch: **Allow the AI to make changes**.

* Switch **off** → every tool marked `writes` **disappears from `tools/list`** and
  is refused if a client calls it by name from memory. Not hidden. Gone.
* `destructive` adds a red **Delete** badge and tells the model the call destroys
  data.

Mark an *update* as `destructive` and a merchant sees a red Delete badge on a
tool that only edits a status. That has shipped. Do not repeat it.

### 4.4 Errors are written for the AI to read

Return a `WP_Error` and the model receives your message and can act on it — ask
the merchant, retry with different arguments, explain. So write a sentence:

```php
return new WP_Error(
    'your_plugin_not_found',
    sprintf(
        /* translators: %s: id supplied by the AI. */
        __( 'No thing was found with the number %s.', 'your-plugin' ),
        $raw
    )
);
```

If your existing code signals refusal by **throwing**, catch it in the bridge and
convert. An uncaught exception is a 500 that tells the merchant nothing, and your
exception message was the useful part of the answer.

### 4.5 Not every tool should cross

The shared link authenticates as the **shop owner**. A tool written for a
customer — one that reads "who am I" from a session — would run with the shop
owner as the customer, answer confidently, and answer about the wrong person.

Zorem Returns hands over 5 of its 12 tools for exactly this reason. Filter yours.

---

## 5. The screen — one call

```php
zorem_mcp_render_screen( array(
    'slug'     => Your_Plugin_MCP_Bridge::SLUG,
    'label'    => __( 'Your MCP', 'your-plugin' ),
    'base_url' => admin_url( 'admin.php?page=your-plugin&tab=ai-assistant' ),
    'words'    => array(
        'connect_title' => __( 'Connect Claude to your things', 'your-plugin' ),
        'steps'         => array(
            __( 'Open claude.ai and go to Settings, then Connectors.', 'your-plugin' ),
            __( 'Choose Add custom connector, give it any name, and paste your link.', 'your-plugin' ),
            __( 'Ask Claude: which things need attention?', 'your-plugin' ),
        ),
    ),
) );
```

And from your own `admin_enqueue_scripts` hook — **not** from the render:

```php
if ( function_exists( 'zorem_mcp_enqueue_assets' ) ) {
    zorem_mcp_enqueue_assets();
}
```

> The render runs while the page is printing, long after `wp_print_styles`. A
> stylesheet enqueued there is **silently dropped** — no error, no warning, and
> the screen comes out unstyled. This is the single easiest mistake to make here.

`base_url` is your screen's URL without the section argument; the audit log's
pagination links are built from it. Get it wrong and paging leaves the screen.

### 5.1 Config keys

| Key | | |
|---|---|---|
| `slug` | **required** | Your registry slug. No slug, no screen |
| `base_url` | | Your screen's URL |
| `label` | | Sidebar label for your section |
| `title` | | Mobile header and aria label |
| `param` | | Query arg the sidebar navigates on. Default `ai_section` |
| `ids` | | Override the three section ids. Only to keep old bookmarks alive |
| `sidebar` | | Callable drawn at the foot of the sidebar |
| `words` | | Every sentence. Anything omitted falls back to plugin-neutral wording |

`words` keys: `heading` `description` `connect_title` `connect_sub`
`enable_title` `enable_tooltip` `writes_title` `writes_tooltip` `writes_hint`
`steps` `tools_title` `tools_note` `tools_empty` `connected_title`
`connected_sub` `woo_description` `woo_status_note` `woo_key_sub`
`woo_scope_note` `audit_empty` `audit_keep` `audit_keep_tip` `audit_keep_hint`.

**Always supply `steps`** — the default is empty, and the setup list is the one
thing on the screen a merchant actually follows.

---

## 6. The screen is always three sections

**Your MCP · Woo MCP · Audit Log.** In every plugin, in that order. There is no
way to add a fourth, and the absence of that seam is the feature: a merchant who
learns this screen in one plugin must not have to re-learn it in the next.

There was an `extra` key and an `after` hook once. Both were removed. A seam that
exists gets used — that is what a seam is for — so the seam is not there.

**If your plugin needs another screen, it is your screen.** Render it yourself,
after `zorem_mcp_render_screen()` in your own method, on your own tab, or
anywhere else in your plugin. Zorem Returns draws its customer-facing assistant
directly below the shared screen from its own `render_customer_area()`, and
decides in its own code who may see it. The library knows nothing about that and
should not.

---

## 7. What you get, and what you must not rebuild

The library owns: the MCP server and endpoint, OAuth 2.1 + PKCE, dynamic client
registration, the per-person key store and revoke, the audit log, the self-test
and its `.htaccess` repair, the WooCommerce key screen, the settings, the CSS,
the JS, the icons, and the screen.

Its three options — `enabled`, `allow_write`, `audit_retention` — belong to
`Zorem_MCP_Settings` and are saved by the library's own AJAX action as each field
changes. **Do not teach your settings framework about them.** Doing so is how the
same three options came to be written three different ways in two plugins.

There is **one link**, and it carries every Zorem plugin's tools. Two people can
connect it and each gets their own token; revoking one leaves the other working.
Every call from every plugin lands in one audit log. None of that is yours to
build again.

---

## 8. If you are editing the library itself

1. **Every copy is byte-identical.** Edit one source, then re-copy to *all*
   plugins. A copy edited in isolation wins on some sites and loses on others,
   and you will not be able to reproduce the bug.
2. **Bump `VERSION`.** Two copies claiming the same version with different
   content is the same bug wearing a disguise.
3. **Name no plugin.** Not a constant, not a function, not a string, not a text
   domain. Two that shipped:
   * `current_user_can( AST_PLUGIN_ACCESS )` — perfect in AST PRO, a fatal error
     printed across the screen in every other plugin.
   * `\Zorem\UI\get_icon()` — every plugin *bundles* `assets/zui/icons.php`, but
     bundling is not loading. On a plugin that never required it the call failed
     its guard, returned an empty string, and the screen shipped with no icons at
     all. No error, no warning.

   Both were invisible in the plugin they were written in.
4. **Do not edit a plugin's words.** The library once stripped a leading `admin-`
   from tool labels because it read more tidily. A plugin that writes
   `Admin: update return status` means it. Pass labels through untouched.
5. **The library's own strings use the `zorem-mcp` text domain**, loaded by
   `bootstrap.php` from the winning copy's `languages/`. Never a host plugin's
   domain — on any other plugin those strings can never be translated at all.
6. **Name no plugin in a CSS class or a data attribute either.** Rule 3 applies
   to markup, and it was broken for a long time: every class and hook in this
   folder was `ast-ai-*`, named for the plugin it was carved out of. Renaming
   them to `zorem-mcp-*` in 2.0.0 touched 221 places across three files, and any
   host plugin still styling `.ast-ai-*` broke silently. Do not start that again.
7. **A selector is a contract between two files. Give it one home.** The Copy
   buttons on the connection screen did nothing for months: the field rendered as
   `zorem-mcp-endpoint` while the buttons still asked for `#ast-ai-endpoint`, so
   `querySelector` returned null and the handler returned. No error — a copy
   button that finds no field simply does nothing. Ids that two files must agree
   on come from a function (`zorem_mcp_endpoint_field_id()`), never a literal
   typed twice. The same goes for a sentence written by both PHP and an AJAX
   reply: one helper, called by both.
8. **Rename CSS, markup and JS in the same commit.** They are one thing wearing
   three file extensions. After any rename, check both directions: every
   `data-zorem-mcp-*` the script looks for must exist in the markup, and every
   class the markup emits must exist in the stylesheet.
9. **No `location.reload()` on this screen.** A reload throws away everything
   else the merchant has open. Whatever changed, return its markup from the AJAX
   handler and swap it in — that is what `paintWooKey()` and the connections
   table already do.
10. **Every control that changes something asks first.** `data-zorem-mcp-confirm`
    on the button, read by the handler before anything is sent. Read-only
    controls — Copy, Test my link — must not, or the dialog becomes noise people
    click through.
11. **Never report success you have not had.** `navigator.clipboard.writeText()`
    returns a promise, and it refuses more often than it looks: insecure origin,
    permissions policy, a document without focus. Ignoring the promise made every
    refusal look like a copy. Wait for the answer, fall back, and say so when
    neither route worked.

---

## 9. When something is wrong

| Symptom | Cause |
|---|---|
| `Undefined constant "X"` on the screen | Library calling a host plugin's constant. §8.3 |
| Sidebar has no icons, section icon is a blank square | Library asking a host for icons. §8.3 |
| Screen renders unstyled | Assets enqueued during render instead of `admin_enqueue_scripts`. §5 |
| Link does not appear until you reload | The link wrapper starts hidden; whatever fills it must also unhide it |
| Your tools do not appear at all | Licence check failing, or the bridge never hooked `zorem_mcp_loaded` |
| Write tools missing | The merchant switched writes off. Working as intended — §4.3 |
| Red **Delete** badge on a tool that updates | `destructive => true` on a non-destructive tool. §4.3 |
| Audit paging leaves the screen | Wrong or missing `base_url`. §5 |
| Strings will not translate | Wrong text domain. §8.5 |
| Two plugins show different screens | The copies have drifted. §8.1 |
| A button does nothing at all, no error | Its selector matches nothing. §8.7 |
| Screen loses its styling after a rename | CSS renamed, markup not — or the reverse. §8.8 |
| Copy says "Copied" but the clipboard is empty | The clipboard promise was ignored. §8.11 |
| Screen opens on the first section after every refresh | Only the configured parameter is read; the host's settings script writes `?section=`. `zorem_mcp_active_section()` reads both |
| Audit log names an app `undici` | The caller passed the user agent. WooCommerce MCP arrives through `mcp-remote`, whose agent is its HTTP library. Resolve from the session: `Mcp-Session-Id` → `SessionManager::get_session()` → `clientInfo` → `Zorem_MCP_Woo_Key::app_name()` |
| One assistant fills the connection list | Grouped per session instead of per app. A client opens a fresh session on every reconnect |
| `Missing Mcp-Session-Id header` | The route is a session protocol. `initialize` first, then send the returned id on every later call |
| WooCommerce's own tools missing from the tool list | They exist only during an MCP request — `AbilitiesRestBridge` returns early otherwise. They cannot be read from an admin screen; ask the route |

**Merchant-side, not your bug:** "Test my link" reporting a block usually means a
security plugin. All In One WP Security's 6G *Block user-agents* rule turned away
Claude's sign-in service for two days before anyone found it; the self-test now
names the plugin and the exact setting.

---

## 10. Before you call it done

- [ ] Package registered **before** the licence check; tools registered **inside** it
- [ ] `SLUG` is final — it can never change after release
- [ ] Every callback checks a capability on its first line
- [ ] Every numeric argument clamped
- [ ] `writes` and `destructive` honest
- [ ] Every `description` says when to use the tool *and when not to*
- [ ] `steps` supplied
- [ ] `zorem_mcp_enqueue_assets()` on `admin_enqueue_scripts`
- [ ] Nothing inside `zorem-mcp/` edited — or if it was, VERSION bumped, CHANGELOG
      written, and every copy re-synced
- [ ] No plugin's name anywhere new — including CSS classes and data attributes
- [ ] Every `data-zorem-mcp-*` the script looks for exists in the markup, and every
      class the markup emits exists in the stylesheet
- [ ] Every control that changes something has a confirm; no read-only one does
- [ ] No `location.reload()` — changed markup comes back from the handler
- [ ] Every button clicked by hand, on a page that was **not** just reloaded
- [ ] Tested on a store where **your plugin is the only Zorem plugin installed**

That last one is not optional. Every bug in §8.3 was invisible until the library
ran somewhere its author had not tried.
