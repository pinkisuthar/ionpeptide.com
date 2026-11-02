---
module: widgets-manager
owner: UNKNOWN
---

# widgets-manager

## Responsibility

- Owns discovery, activation-gating, and Elementor registration of HFE's own widgets (via a namespace-based SPL autoloader + a module manager) and its page-level extensions (Scroll To Top, Reading Progress Bar). Provides the `Common_Widget` / `Module_Base` base classes.
- Stops at the Elementor registration boundary: it hands widget/extension objects to Elementor's `widgets_manager->register()` and kit-tab hooks. It does not own the settings UI, the widget option store (`HFE_Helper` / `Widgets_Config`), or a widget's internal render logic.

## Why it is this way

- Registration is deferred to `elementor/init` / `elementor/widgets/register` because widgets and categories must be registered inside Elementor's lifecycle; nothing here can register earlier.
- The four-place coordination (folder, `$all_modules`, `get_widgets()`, `Widgets_Config` key) plus a config-driven slug is what lets a widget be independently toggled on/off from the admin without touching Elementor registration code.

## Related ADRs

None yet.
