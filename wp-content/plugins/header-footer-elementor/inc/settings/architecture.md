---
module: settings
owner: UNKNOWN
---

# settings

## Responsibility

- `hfe-settings-api.php` owns the `/hfe/v1/` REST namespace and the `uae_mcp_settings` option (read/write). `admin-base.php` and `settings-app.php` are thin PHP view templates — the former fires `hfe_render_admin_page_content`, the latter emits the `#hfe-settings-app` React mount div.
- Stops at the MCP registry (`inc/abilities`) and the React app (`src/`), which it exposes data to but does not own.

## Why it is this way

- The nonce check lives in each callback rather than in the shared `permission_callback` because the permission callback is capability-only by design; the two-layer split is deliberate but easy to break by copying only one half.

## Related ADRs

None yet.
