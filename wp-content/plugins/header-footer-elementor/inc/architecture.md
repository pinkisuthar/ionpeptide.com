---
module: core
owner: UNKNOWN
---

# core

## Responsibility

- The plugin boot/core layer. `Header_Footer_Elementor` is the singleton entry point: it gates on Elementor availability, detects the active theme and loads the matching compat adapter, wires the global hooks, and pulls in every other module via `includes()`. `hfe-functions.php` holds the header/footer/before-footer render + enabled-check helpers the whole plugin calls.
- Stops at the subfolder boundaries (`abilities/`, `angie/`, `compatibility/`, `lib/`, `settings/`, `widgets-manager/`) and `admin/`, `themes/` — it only `require`s them, never defines them.

## Why it is this way

- Every include and hook sits behind an Elementor-availability gate because the plugin is inert without Elementor; boot order is hand-tuned so the render helpers exist before anything calls them.
- Analytics is deliberately loaded after theme detection (outside `includes()`) so its event logic sees the theme-support options the constructor has already written.

## Related ADRs

None yet.
