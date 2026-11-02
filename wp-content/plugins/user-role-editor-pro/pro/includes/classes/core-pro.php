<?php

/*
 * Project: User Role Editor
 * General Helper class
 * Author: Vladimir Garagulya
 * Author email: support@role-editor.com
 * Author URI: https://www.role-editor.com
 * License: GPL v2+
 * 
 */

class URE_Core_Pro extends URE_Core {
    const PLUGIN_VERSION = '4.66';
    const UPDATE_URL = 'https://update.role-editor.com';


    // It's excuted on plugin uninstall via WordPress->Plugins->Delete
    // Overrides URE_Core::uninstall(), which hard-codes `new URE_Uninstall`
    // and would otherwise skip all Pro-only add-on data cleanup.
    public static function uninstall() {

        $uninstall = new URE_Uninstall_Pro();
        $uninstall->act();

    }
    // end of uninstall()

    
    final public static function define_files() {
        
        parent::define_files();
        
        $includes_dir = URE_Core::get_plugin_dir() . 'pro/includes/';
        $classes_dir = $includes_dir . 'classes/';
                        
        URE_Loader::add_def( $classes_dir . 'lib-pro.php', 'URE_Lib_Pro');
        URE_Loader::add_def( $classes_dir . 'ajax-processor-pro.php', 'URE_Ajax_Processor_Pro');
        URE_Loader::add_def( $classes_dir . 'utils.php', 'URE_Utils');
        URE_Loader::add_def( $classes_dir . 'mutex.php', 'URE_Mutex');
        URE_Loader::add_def( $classes_dir . 'license-key.php', 'URE_License_Key');
        URE_Loader::add_def( $classes_dir . 'plugin-presence.php', 'URE_Plugin_Presence');
        URE_Loader::add_def( $classes_dir . 'bbpress-pro.php', 'URE_bbPress_Pro');
        URE_Loader::add_def( $classes_dir . 'duplicate-post.php', 'URE_Duplicate_Post');
        URE_Loader::add_def( $classes_dir . 'foogallery.php', 'URE_FooGallery');
        URE_Loader::add_def( $classes_dir . 'wc-bookings.php', 'URE_WC_Bookings');
        URE_Loader::add_def( $classes_dir . 'assign-role-pro.php', 'URE_Assign_Role_Pro');
        URE_Loader::add_def( $classes_dir . 'screen-help-pro.php', 'URE_Screen_Help_Pro');
        URE_Loader::add_def( $classes_dir . 'create-posts-cap.php', 'URE_Create_Posts_Cap');
        URE_Loader::add_def( $classes_dir . 'post-types-own-caps.php', 'URE_Post_Types_Own_Caps');

        // Export/Import 
        URE_Loader::add_def( $classes_dir . 'import-roles-view.php', 'URE_Import_Roles_View');
        URE_Loader::add_def( $classes_dir . 'import-validator.php', 'URE_Import_Validator');
        URE_Loader::add_def( $classes_dir . 'export-single-role.php', 'URE_Export_Single_Role');
        URE_Loader::add_def( $classes_dir . 'import-single-role.php', 'URE_Import_Single_Role');

        // Additional modules:
        URE_Loader::add_def($classes_dir . 'addons-manager.php', 'URE_Addons_Manager');
        URE_Loader::add_def($classes_dir . 'network-addons-data-replicator.php', 'URE_Network_Addons_Data_Replicator');

        // Admin menu access
        URE_Loader::add_def($classes_dir . 'admin-menu-copy.php', 'URE_Admin_Menu_Copy');
        URE_Loader::add_def($classes_dir . 'admin-menu-hashes.php', 'URE_Admin_Menu_Hashes');
        URE_Loader::add_def($classes_dir . 'admin-menu.php', 'URE_Admin_Menu');
        URE_Loader::add_def($classes_dir . 'admin-menu-view.php', 'URE_Admin_Menu_View');
        URE_Loader::add_def($classes_dir . 'admin-menu-url-allowed-args.php', 'URE_Admin_Menu_URL_Allowed_Args');
        URE_Loader::add_def($classes_dir . 'admin-menu-access.php', 'URE_Admin_Menu_Access');

        // Front end menu access
        URE_Loader::add_def($classes_dir . 'front-end-menu-controller.php', 'URE_Front_End_Menu_Controller');
        URE_Loader::add_def($classes_dir . 'front-end-menu-view.php', 'URE_Front_End_Menu_View');
        URE_Loader::add_def($classes_dir . 'front-end-menu-access.php', 'URE_Front_End_Menu_Access');

        // Navigation Menus access
        URE_Loader::add_def($classes_dir . 'nav-menus-admin-controller.php', 'URE_Nav_Menus_Admin_Controller');
        URE_Loader::add_def($classes_dir . 'nav-menus-admin-view.php', 'URE_Nav_Menus_Admin_View');
        URE_Loader::add_def($classes_dir . 'nav-menus-admin-access.php', 'URE_Nav_Menus_Admin_Access');

        // Widgets Admin access
        URE_Loader::add_def($classes_dir . 'widgets-admin-controller.php', 'URE_Widgets_Admin_Controller');
        URE_Loader::add_def($classes_dir . 'widgets-admin-view.php', 'URE_Widgets_Admin_View');
        URE_Loader::add_def($classes_dir . 'widgets-admin-access.php', 'URE_Widgets_Admin_Access');

        // Widgets Show access
        URE_Loader::add_def($classes_dir . 'widgets-show-view.php', 'URE_Widgets_Show_View');
        URE_Loader::add_def($classes_dir . 'widgets-show-controller.php', 'URE_Widgets_Show_Controller');
        URE_Loader::add_def($classes_dir . 'widgets-show-access.php', 'URE_Widgets_Show_Access');

        // Metaboxes access
        URE_Loader::add_def($classes_dir . 'meta-boxes.php', 'URE_Meta_Boxes');
        URE_Loader::add_def($classes_dir . 'meta-boxes-access.php', 'URE_Meta_Boxes_Access');

        // Other Roles access
        URE_Loader::add_def($classes_dir . 'other-roles.php', 'URE_Other_Roles');
        URE_Loader::add_def($classes_dir . 'other-roles-access.php', 'URE_Other_Roles_Access');

        // Posts edit access
        URE_Loader::add_def($classes_dir . 'posts-edit-access-view.php', 'URE_Posts_Edit_Access_View');
        URE_Loader::add_def($classes_dir . 'posts-edit-access-role-controller.php', 'URE_Posts_Edit_Access_Role_Controller');
        URE_Loader::add_def($classes_dir . 'posts-edit-access-role.php', 'URE_Posts_Edit_Access_Role');
        URE_Loader::add_def($classes_dir . 'posts-edit-access-user-meta.php', 'URE_Posts_Edit_Access_User_Meta');
        URE_Loader::add_def($classes_dir . 'posts-edit-access-user.php', 'URE_Posts_Edit_Access_User');
        URE_Loader::add_def($classes_dir . 'posts-edit-access-bulk-action.php', 'URE_Posts_Edit_Access_Bulk_Action');
        URE_Loader::add_def($classes_dir . 'posts-edit-access.php', 'URE_Posts_Edit_Access');

        // Plugins access
        URE_Loader::add_def($classes_dir . 'plugins-access-controller.php', 'URE_Plugins_Access_Controller');
        URE_Loader::add_def($classes_dir . 'plugins-access-role-controller.php', 'URE_Plugins_Access_Role_Controller');
        URE_Loader::add_def($classes_dir . 'plugins-access-role.php', 'URE_Plugins_Access_Role');
        URE_Loader::add_def($classes_dir . 'plugins-access-user-controller.php', 'URE_Plugins_Access_User_Controller');
        URE_Loader::add_def($classes_dir . 'plugins-access-user.php', 'URE_Plugins_Access_User');
        URE_Loader::add_def($classes_dir . 'plugins-access-view.php', 'URE_Plugins_Access_View');
        URE_Loader::add_def($classes_dir . 'plugins-access-role-view.php', 'URE_Plugins_Access_Role_View');
        URE_Loader::add_def($classes_dir . 'plugins-access-user-view.php', 'URE_Plugins_Access_User_View');
        URE_Loader::add_def($classes_dir . 'plugins-access.php', 'URE_Plugins_Access');

        // Admin page permissions viewer
        URE_Loader::add_def($classes_dir . 'page-permissions-view.php', 'URE_Page_Permissions_View');

        // Themes activation access
        URE_Loader::add_def($classes_dir . 'themes-access.php', 'URE_Themes_Access');

        // Gravity Forms Access
        URE_Loader::add_def($classes_dir . 'gf-access-view.php', 'URE_GF_Access_View');
        URE_Loader::add_def($classes_dir . 'gf-access-role.php', 'URE_GF_Access_Role');
        URE_Loader::add_def($classes_dir . 'gf-access-user.php', 'URE_GF_Access_User');
        URE_Loader::add_def($classes_dir . 'gf-access.php', 'URE_GF_Access');

        // Content view restricitons
        URE_Loader::add_def($classes_dir . 'content-view-restrictions-controller.php', 'URE_Content_View_Restrictions_Controller');
        URE_Loader::add_def($classes_dir . 'posts-view.php', 'URE_Posts_View');
        URE_Loader::add_def($classes_dir . 'posts-view-access.php', 'URE_Posts_View_Access');
        URE_Loader::add_def($classes_dir . 'content-view-restrictions-editor.php', 'URE_Content_View_Restrictions_Editor');
        URE_Loader::add_def($classes_dir . 'content-view-restrictions-posts-list.php', 'URE_Content_View_Restrictions_Posts_List');
        URE_Loader::add_def($classes_dir . 'content-view-restrictions.php', 'URE_Content_View_Restrictions');

        // Content view restrictions shortcode
        URE_Loader::add_def($classes_dir . 'content-view-shortcode-users.php', 'URE_Content_View_Shortcode_Users');
        URE_Loader::add_def($classes_dir . 'content-view-shortcode-roles.php', 'URE_Content_View_Shortcode_Roles');
        URE_Loader::add_def($classes_dir . 'content-view-shortcode.php', 'URE_Content_View_Shortcode');

        // Additional Capabilities
        URE_Loader::add_def($classes_dir . 'additional-caps.php', 'URE_Additional_Caps');

        // role/user capabilities editor Extended - for Pro version
        URE_Loader::add_def($classes_dir . 'editor-ext.php', 'URE_Editor_Ext');

        // User Role Editor Pro Settings - Tools
        URE_Loader::add_def($classes_dir . 'export-roles-csv.php', 'URE_Export_Roles_CSV');
        URE_Loader::add_def($classes_dir . 'import-roles-csv.php', 'URE_Import_Roles_CSV');
        URE_Loader::add_def($classes_dir . 'tools-ext.php', 'URE_Tools_Ext');

        // User Role Editor Pro Settings
        URE_Loader::add_def($classes_dir . 'settings-pro.php', 'URE_Settings_Pro');

        // User Role Editor Pro
        URE_Loader::add_def($classes_dir . 'user-role-editor-pro-view.php', 'URE_Pro_View');
        URE_Loader::add_def($classes_dir . 'uninstall-pro.php', 'URE_Uninstall_Pro');
        URE_Loader::add_def($classes_dir . 'user-role-editor-pro.php', 'User_Role_Editor_Pro');

    }
    // end of define_files()


    final public static function init( $plugin_full_path ) {
        
        if ( self::$initialized ) {
            // Allow URE to initialize one time only
            return;
        }

        self::$initialized = true;
        
        self::set_plugin_full_path( $plugin_full_path );

        if ( !self::valid_php_version() ) {
            return;
        }
        if ( !self::valid_wordpress_version() ) {
            return;
        }
                
        self::define_files();
        URE_Loader::load_files();
        
        // Uninstall hook
        register_uninstall_hook( URE_Core::get_plugin_full_path(), [ 'URE_Core_Pro', 'uninstall' ] );

        if ( ! isset( $GLOBALS['user_role_editor'] ) ) {
            $GLOBALS['user_role_editor'] = User_Role_Editor_Pro::get_instance();
        }
    }
    // end of init()
}
// end URE_Core_Pro class
