---
module: themes
owner: UNKNOWN
---

# themes

## Responsibility

- Owns the runtime swap of a theme's native header/footer/before-footer markup for the plugin's Elementor-built templates. One adapter class per supported theme registers into that theme's specific hooks and removes the theme's own markup callbacks.
- Stops at rendering placement — the template content comes from `Header_Footer_Elementor::get_*_content()` / `hfe_render_*()` in `inc/`. Adapter *selection* also lives in `inc/class-header-footer-elementor.php:103-130`, not here.

## Why it is this way

- There is no single theme API for replacing a header/footer, so each theme needs a bespoke adapter (different hooks, and different suppression: `remove_action` vs a theme filter vs CSS). The uniform-looking skeleton hides genuinely per-theme suppression logic.
- `default/` is a fallback with two user-selectable strategies (replace `header.php`/`footer.php`, or `wp_body_open`/`wp_footer`) because unsupported themes expose no reliable hook — so the choice is pushed to the site owner via `hfe_compatibility_option`.

## Related ADRs

None yet.
