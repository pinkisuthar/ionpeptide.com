<?php
defined( 'ABSPATH' ) || exit;

/*
 * Project: User Role Editor Pro WordPress plugin 
 * Class for remove own data on unistallation
 * Author: Vladimir Garagulya
 * Author email: support@role-editor.com
 * Author URI: https://www.role-editor.com
 * License: GPL v2+
 * 
*/

class URE_Uninstall_Pro extends URE_Uninstall {


    protected function init_options_list() {

        parent::init_options_list();
        
        $this->options[] = URE_Admin_Menu::DATA_VERSION_KEY;
        $this->options[] = URE_Admin_Menu::ADMIN_MENU_COPY_KEY;
        $this->options[] = URE_Admin_Menu::ADMIN_SUBMENU_COPY_KEY;
        $this->options[] = URE_Admin_Menu::ACCESS_DATA_KEY;
        $this->options[] = URE_Admin_Menu::ADMIN_MENU_HASHES;
        $this->options[] = URE_Admin_Menu_Url_Allowed_Args::ARGS_DATA_KEY;
        $this->options[] = URE_Meta_Boxes::ACCESS_DATA_KEY;
        $this->options[] = URE_Meta_Boxes::META_BOXES_LIST_COPY_KEY;
        $this->options[] = URE_Other_Roles::ACCESS_DATA_KEY;
        $this->options[] = URE_Plugins_Access_Role::ACCESS_DATA_KEY;
        $this->options[] = URE_Posts_Edit_Access_Role::ACCESS_DATA_KEY;
        $this->options[] = URE_Content_View_Restrictions_Controller::ACCESS_DATA_KEY;
        $this->options[] = URE_Widgets_Admin_Controller::ACCESS_DATA_KEY;
        $this->options[] = URE_Widgets_Show_Controller::ACCESS_DATA_KEY;
        $this->options[] = URE_Nav_Menus_Admin_Controller::ACCESS_DATA_KEY;
        $this->options[] = URE_GF_Access_Role::ACCESS_DATA_KEY;
        $this->options[] = 'external_updates-user-role-editor-pro';
        
    }
    // end fo init_options_list()

    
    protected function delete_options() {
    
        parent::delete_options();
        delete_site_option( 'ure_assign_role_job' );
        
    }
    // end of delete_options()
    
    
    private function build_where_str() {
        
        $meta_keys = array(
            'ure_allow_plugins_activation',
            'ure_allow_themes',            
            'wp_ure_authors_list',
            'ure_categories_list',
            'ure_own_data_only',
            'ure_plugins_access_selection_model',
            'ure_posts_list',
            'ure_posts_restriction_type',
            'ure_post_restrict_type_by_author',
            'ure_post_types',
            'ure_allow_gravity_forms'
        );
        $operands = array();
        foreach( $meta_keys as $meta_key ) {
            $operands[] = "meta_key LIKE '%{$meta_key}'";
        }
        $where_str = implode( ' OR ', $operands );
        
        return $where_str;
    }
    // end of build_where_str()
    
    
    private function delete_usermeta() {
        global $wpdb;
        
        $where_str = $this->build_where_str();
        $query = "DELETE FROM {$wpdb->usermeta} 
                    WHERE {$where_str}";
        $wpdb->query($query);
        
    }
    // end of delete_user_meta()
    
    
    public function act() {

        parent::act();

        $this->delete_usermeta();
        
    }
    // end of act()
}
// end of URE_Uninstall_Pro class
