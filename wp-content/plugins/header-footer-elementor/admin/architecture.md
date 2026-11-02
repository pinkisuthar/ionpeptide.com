---
module: admin
owner: UNKNOWN
---

# admin

## Responsibility

- The wp-admin UI layer for HFE: registers the `elementor-hf` CPT, the admin submenu, the template metabox (Type / Display Rules / canvas toggle), list-table columns, editor notices, a widget-usage KPI cron, and all `wp_ajax_*` handlers (widget activation, plugin/theme install, subscription, notices, onboarding analytics).
- `admin/bsf-analytics/` is vendored BSF analytics — outside this module.

## Why it is this way

- The AJAX nonce contract is split across files: the token is created in `inc/class-hfe-settings-page.php`, localized into `hfe_admin_data`, and consumed in JS. Handlers here only *verify* it, which is why editing the string in one place silently breaks auth everywhere.
- Install/theme handlers delegate to WordPress core's own AJAX callbacks, so they must adopt core's `'updates'` nonce and capabilities rather than the plugin's.

## Related ADRs

None yet.
