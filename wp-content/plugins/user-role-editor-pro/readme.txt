=== User Role Editor Pro ===
Contributors: Vladimir Garagulya (https://www.role-editor.com)
Tags: user, role, editor, security, access, permission, capability
Requires at least: 4.6
Tested up to: 7.1
Stable tag: 4.66
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.role-editor.com/end-user-license-agreement/

User Role Editor Pro WordPress plugin makes user roles and capabilities changing easy. Edit/add/delete WordPress user roles and capabilities.

== Description ==

User Role Editor Pro WordPress plugin allows you to change user roles and capabilities easy.
Just turn on check boxes of capabilities you wish to add to the selected role and click "Update" button to save your changes. That's done. 
Add new roles and customize its capabilities according to your needs, from scratch of as a copy of other existing role. 
Unnecessary self-made role can be deleted if there are no users whom such role is assigned.
Role assigned every new created user by default may be changed too.
Capabilities could be assigned on per user basis. Multiple roles could be assigned to user simultaneously.
You can add new capabilities and remove unnecessary capabilities which could be left from uninstalled plugins.
Multi-site support is provided.

== Installation ==

Installation procedure:

1. Deactivate plugin if you have the previous version installed.
2. Extract "user-role-editor-pro.zip" archive content to the "/wp-content/plugins/user-role-editor-pro" directory.
3. Activate "User Role Editor Pro" plugin via 'Plugins' menu in WordPress admin menu. 
4. Go to the "Settings"-"User Role Editor" and adjust plugin options according to your needs. For WordPress multisite URE options page is located under Network Admin Settings menu.
5. Go to the "Users"-"User Role Editor" menu item and change WordPress roles and capabilities according to your needs.

In case you have a free version of User Role Editor installed: 
Pro version includes its own copy of a free version (or the core of a User Role Editor). So you should deactivate free version and can remove it before installing of a Pro version. 
The only thing that you should remember is that both versions (free and Pro) use the same place to store their settings data. 
So if you delete free version via WordPress Plugins Delete link, plugin will delete automatically its settings data. Changes made to the roles will stay unchanged.
You will have to configure lost part of the settings at the User Role Editor Pro Settings page again after that.
Right decision in this case is to delete free version folder (user-role-editor) after deactivation via FTP, not via WordPress.

== Changelog ==

= [4.66] 25.08.2026 =
* Core version: 4.66.1
* Update: Marked as compatible with WordPress 7.1
* Required PHP version increased up to 7.4
* Update: Plugin loading code is enhanced. 
* Update: Plugin does not use self-defined PHP global constants. Needed data moved inside classes.
* Update: "Admin menu access" add-on: White list of URL arguments allowed by default for WooCommerce products and shop orders list pages was updated.
* Fix: URE_Assign_Role_Pro::get_users_queued() referenced an undefined constant instead of self::CRON_ACTION_HOOK.
* Fix: URE_Assign_Role_Pro::assign_role_to_user() used a loose in_array() check against $user->caps that was always true, so the background role-assignment feature never actually assigned the role; now correctly checks $user->roles.
* Fix: "Posts Edit Access" bulk action's user ID list could contain stray whitespace instead of clean integers; user IDs are now properly cast to int.
* Update: URE_Uninstall_Pro::init_options_list() now references addon classes own *_KEY constants instead of duplicating their option name strings.
* Fix: activating Pro while the free plugin was already active still left both active in some load orders, because URE_Core_Pro::init() (which registered the fix above) was itself skipped when the free plugin had already defined URE_Loader/URE_Core earlier in the same request; the activation-hook registration now happens unconditionally via a standalone function in the main plugin file.
* Core version was updated to 4.66.1
* Update: URE_Admin_Notice class output was escaped with esc_attr(), wp_kses_post() functions.
* Security Fix: SQL queries in URE_Editor::direct_network_roles_update() and leave_roles_for_blog() are passed to $wpdb->prepare() with real %s placeholders.
* Security Fix: URE_Editor::get_caps_columns_quant() now requires a valid nonce before writing a display-preference transient from $_POST, closing a minor CSRF gap.
* Fix: URE_Protect_Admin used a bitwise "&" instead of a logical "&&" when checking a capabilities array, which could throw a PHP 8 TypeError; fixed to use "&&", and the related IN() SQL clause is now hardened with array_map('absint', ...).
* Update: nonce actions used on the Settings/Tools pages are now scoped per form (ure_settings_update, ure_addons_settings_update, ure_default_roles_update, ure_settings_ms_update, ure_settings_tools_exec) instead of one shared string.
* Update: additional output escaping was added across URE_View, URE_Role_View and URE_Role_Additional_Options (role/capability slugs, wp_json_encode() instead of json_encode(), esc_url() on form actions), plus a defense-in-depth capability check in URE_Role_Additional_Options::save().
* Update: rel="noopener noreferrer" was added to external links opened with target="_new".
* Update: hardcoded text strings in the role editor toolbar are now translatable.
* Fix: URE_Assign_Role used the %i SQL placeholder, which needs WordPress 6.2+, below the plugin's declared minimum; replaced with direct interpolation of internal table names.
* Fix: URE_Editor::reset_user_roles() had an unescaped wp_die() message; further output escaping (esc_url(), esc_html(), absint()) was added across URE_Base_Lib, URE_Editor, URE_User_Other_Roles and URE_User_View.
* Fix: several request-var/database-result comparisons that could be bypassed by PHP type juggling are now strict, including URE_Grant_Roles::is_try_remove_admin_from_himself()'s "can't remove your own admin role" check.
* Fix: URE_Base_Lib::set() now correctly rejects unknown properties instead of silently creating them; URE_View declares its $advert property explicitly.
* Update: $_SERVER['REQUEST_URI'] is now validated and unslashed before sanitizing in URE_Lib::is_right_admin_path() and URE_User_Other_Roles::is_user_profile_extention_allowed().
* Update: posted role IDs are now sanitized (sanitize_key(), wp_unslash()) in URE_Editor, and its 'object'/role-selection request parameters are constrained to known values.
* Update: URE_Base_Lib::get_blog_ids() now uses get_sites() instead of a raw database query.
* Update: URE_Capability::revoke_caps() now uses get_users() instead of a raw database query.
* Update: URE_Protect_Admin::has_administrator_role() now uses user_can() instead of a raw database query.
* Fix: URE_Assign_Role::$lib property was changed to protected.
* Fix: URE_Uninstall::delete_options() private function changed to protected.
* Fix: URE_Uninstall::init_options_list() referenced 'ure_task_queue', which never matched URE_Task_Queue::OPTION_NAME ('ure_tasks_queue'), so that option was never deleted on uninstall.
* Fix: URE_Core::define_files() - class URE_Uninstall was not added correctly, null was written instead of class name.
* Fix: activating Pro while the free version was already active caused a fatal "Cannot redeclare ure_log_error()" error; the function declaration is now guarded with function_exists().
* Fix: activating this plugin while the paired Pro plugin was already active silently left both active instead of deactivating the paired one, because the register_activation_hook() call never ran in that request; it's now registered as a standalone function directly in the main plugin file, ahead of the class_exists('URE_Loader') guard that was skipping it.

= [4.65] 21.05.2026 =
* Core version: 4.65
* Update: Marked as compatible with WordPress 7.0
* Update: Minor fixes to pages markup are applied to correspond WordPress 7.0 CSS changes. 
* Update: "defined('ABSPATH')" guard was added to all PHP files to exclude PHP files direct execution. 
* Update: sanitize_text_field(), sanitize_key(), sanitize_url() functions are used to secure user input before processing.
* Update: _nonce field checking was added before data update in addition checking made already on the higher level.
* Core version was updated to 4.65
* Fix: Users->User Role Editor->Import: single user role was imported successfully but the empty page was shown instead of URE page with successful import notification.
* Fix: Meta Boxes Access add-on: WP Multisite: Network Admin->Users->User Role Editor->Meta Boxes: current role does not lose now all capabilities after the 'Update' button click.
* Fix: Other Roles Access add-on: WP Multisite: Network Admin->Users->User Role Editor->Other Roles: current role does not lose now all capabilities after the 'Update' button click.
* Fix: Posts Edit Access add-on: WP Multisite: Network Admin->Users->User Role Editor->Posts Edit: current role does not lose now all capabilities after the 'Update' button click.
* Fix: Plugins Access add-on: WP Multisite: Network Admin->Users->User Role Editor->Plugins: current role does not lose now all capabilities after the 'Update' button click.
* Fix: Export roles CSV download file with .pdf extension in the FireFox browser. Content type header was replaced to 'text/plain'.
* Update: Meta Boxes Access add-on: data is updated via AJAX without full page refresh now.
* Update: Other Roles Access add-on: data is updated via AJAX without full page refresh now.
* Update: Posts Edit Access add-on: data for role is updated without full page refresh  via AJAX now.
* Update: Plugins Access add-on: data is updated via AJAX without full page refresh  now.
* Update: Import role CSV: uploaded file .csv extention and mime type checking were added.
* Update: "Users->User Role Editor->Import" button is hidden in case page is opened from the WP Multisite -> Network admin. Use it from the selected single site only.

Full list of changes is available in changelog.txt file.
