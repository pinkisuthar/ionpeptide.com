# Zorem MCP — changelog

Every plugin ships a byte-identical copy of this folder and the newest one on a request wins,
so a plugin that has not been updated still runs the newest library another plugin brought.
That is fine for PHP. It is not fine for markup and CSS that stopped matching — see 2.0.0.

---

## 2.12.1

### Breaking — read this before updating

**2.0.0 renamed every CSS class and data attribute** from `ast-ai-*` to `zorem-mcp-*`
(221 occurrences across `ui-helpers.php`, `assets/zorem-mcp.js` and `assets/zorem-mcp.css`).
The old names came from the plugin this library was extracted out of and had no business in a
shared package.

**If your plugin has its own CSS or JS that targets `.ast-ai-*` or `[data-ast-ai-*]`, it stops
matching.** Grep your plugin for `ast-ai` before you ship. Nothing inside the library refers to
the old names any more.

### The connection screen

- **Copy buttons did nothing at all.** The URL field renders as `zorem-mcp-endpoint` while every
  per-client Copy button still asked for `#ast-ai-endpoint`, so `querySelector` found nothing and
  the handler returned in silence. The id now comes from one place,
  `zorem_mcp_endpoint_field_id()`, which the field, the buttons and the caller all use.
- **Copy reported success it had not had.** `navigator.clipboard.writeText()` returns a promise
  and the old code ignored it, so a refusal — insecure origin, permissions policy, a document
  without focus — looked exactly like a copy. It now waits for the answer, falls back to
  `execCommand`, and says `Copy failed — press Ctrl+C` when neither route works.
- **No page reloads anywhere on this screen.** Forgetting a key, deleting a key and signing
  everyone out repainted by reloading; each now returns the markup it changed and swaps it in.
- **Every control that changes something asks first.** `Use this key` was the last one without a
  confirm, and its wording differs depending on whether it is storing a first key or replacing one.
- **Section is read from `section` as well as the configured parameter.** A host settings script
  that writes `?section=<id>` as the merchant navigates left this screen opening on its first
  section after every refresh, however far in they had gone.

### The WooCommerce MCP key

- **The key is kept, sealed with the site's salts.** It used to be discarded once the setup rows
  were drawn, so a refresh — or coming back tomorrow — meant fetching it from WooCommerce and
  pasting it again. Sealed with `wp_salt()`, which lives in `wp-config.php` and not the database,
  so a dump of `wp_options` yields ciphertext. Sites without OpenSSL keep the old behaviour
  rather than storing it in the clear.
- **`Forget key`** removes this site's copy; the key keeps working in WooCommerce.
  **`Delete this key`** deletes the WooCommerce key itself, which is the only thing that actually
  ends access — closing a session does not, because the app still holds the key.
- **What this key can do.** WooCommerce builds its own MCP tools only while an MCP request is
  being served, so they cannot be read from an admin screen. The list is fetched the way an
  assistant fetches it: `initialize`, then `tools/list` with the returned `Mcp-Session-Id`.
  Cached 12 hours; a failure is cached 10 minutes.
- **Who is connected.** Read from the adapter's sessions, grouped by app rather than by session
  — a client opens a fresh one on every reconnect — with the raw `clientInfo` name mapped to
  something a merchant recognises (`claude-ai (via mcp-remote 0.8.3)` → `Claude`). This screen's
  own probe is excluded.
- **About this key.** Which account it acts as, what it may do, and when it was last used —
  taken from the sessions, because WooCommerce's `last_access` is written by its REST
  authentication and the MCP transport does not go through it.

### Notes for integrating plugins

- `Zorem_MCP_Woo_Key::app_name()` is public. Use it wherever you name an AI client, so one app is
  called one thing across the whole screen.
- The audit log's App column comes from whatever the caller passes to `Zorem_MCP_Audit::record()`.
  If yours passes the user agent, WooCommerce MCP callers arrive as `undici` — the HTTP library
  inside `mcp-remote`. Resolve the app from the session instead: read `Mcp-Session-Id`, ask
  `SessionManager::get_session()`, and pass the `clientInfo` name through `app_name()`.

### Known

The screen opens an MCP session each time it reads the tool list and does not close it. The
adapter keeps 32 sessions per user and evicts the oldest, so on a busy store this could in
principle displace a live one. At one session per 12 hours it is slow enough not to have caused
a problem yet.
