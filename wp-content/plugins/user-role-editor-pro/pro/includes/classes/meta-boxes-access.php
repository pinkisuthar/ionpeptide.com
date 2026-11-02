<?php
defined( 'ABSPATH' ) || exit;

/*
 * User Role Editor WordPress plugin
 * Prohibit/Allow view of posts of selected categories for selected role - at User Role Editor dialog
 * Author: Vladimir Garagulya
 * Author email: support@role-editor.com
 * Author URI: https://www.role-editor.com
 * License: GPL v2+ 
 */

class URE_Meta_Boxes_Access {

    const meta_boxes_access_cap = 'ure_meta_boxes_access';
    
    // reference to the code library object
    private $lib = null;        
    private $objects = null;

    public function __construct() {
        
        $this->lib = URE_Lib_Pro::get_instance();
        $this->objects = new URE_Meta_Boxes();
        
        add_action('ure_role_edit_toolbar_service', array($this, 'add_toolbar_buttons'));
        add_action('ure_load_js', array($this, 'add_js'));
        add_action('ure_dialogs_html', array($this, 'dialog_html'));

    }
    // end of __construct()


    public function add_toolbar_buttons() {
        if (!current_user_can('ure_meta_boxes_access')) {
            return;
        }
        // get full meta_boxes list copy from superadmin user
        $this->objects->update_meta_boxes_list_copy();        
?>                
        <button id="ure_meta_boxes_access_button" class="ure_toolbar_button" 
                title="<?php esc_html_e('Prohibit access to selected meta_boxes', 'user-role-editor');?>">
                    <?php esc_html_e('Meta Boxes', 'user-role-editor');?></button>
<?php

    }
    // end of add_toolbar_buttons()
    
    
    public function add_js() {
        wp_register_script( 'ure-meta_boxes-access', 
                            plugins_url( '/pro/js/meta-boxes-access.js', URE_Core_Pro::get_plugin_full_path() ), 
                            array(), 
                            URE_Core_Pro::PLUGIN_VERSION );
        wp_enqueue_script ( 'ure-meta_boxes-access' );
        wp_localize_script( 'ure-meta_boxes-access', 'ure_data_meta_boxes_access',
                array(
                    'meta_boxes' => esc_html__('Meta Boxes', 'user-role-editor'),
                    'dialog_title' => esc_html__('Meta Boxes', 'user-role-editor'),
                    'update_button' => esc_html__('Update', 'user-role-editor'),
                    'edit_posts_required' => esc_html__('Turn ON at least "edit_posts" capability to manage access to meta_boxes for this role', 'user-role-editor')
                ));
    }
    // end of add_js()    
    
    
    public function dialog_html() {
        
?>
        <div id="ure_meta_boxes_access_dialog" class="ure-modal-dialog">
            <div id="ure_meta_boxes_access_container">
            </div>    
        </div>
<?php        
        
    }
    // end of dialog_html()

    
    public static function update_data() {
    
        $answer = array('result'=>'error', 'message'=>'');
        
        if (!current_user_can('ure_meta_boxes_access')) {
            $answer['message'] = esc_html__('URE: you do not have enough permissions to access this module.', 'user-role-editor');
            return $answer;
        }
        
        $ure_object_type = ( isset( $_POST['values']['ure_object_type'] ) ) ? URE_Base_Lib::filter_string_var( $_POST['values']['ure_object_type'] ) : false;
        if ( $ure_object_type!=='role' && $ure_object_type!=='user') {
            $answer['message'] = esc_html__('URE: Meta boxes access: Wrong object type. Data was not updated.', 'user-role-editor');
            return $answer;
        }
        $ure_object_name = isset( $_POST['values']['ure_object_name'] ) ? URE_Base_Lib::filter_string_var( $_POST['values']['ure_object_name'] ) : false;
        if ( empty( $ure_object_name ) ) {
            $answer['message'] = esc_html__('URE: Meta boxes access: Empty object name. Data was not updated', 'user-role-editor');
            return$answer;
        }
                        
        if ($ure_object_type=='role') {
            URE_Meta_Boxes::save_access_data_for_role( $ure_object_name );
        } else {
            URE_Meta_Boxes::save_access_data_for_user( $ure_object_name );
        }
        $answer['result'] = 'success';
        $answer['message'] = esc_html__('Meta boxes access data was updated successfully', 'user-role-editor');

        return $answer;
    }
    // end of update_data()
        
    
    public static function remove_from_list() {
        
        $lib = URE_Lib_Pro::get_instance();
        $key = $lib->get_request_var( 'mb_key', 'post');
        if ( empty( $key ) ) {
            $answer = array('result'=>'error', 'message'=>'Wrong request: meta box key is missed!');
            return $answer;
        }
        
        $meta_boxes_list = get_option(URE_Meta_Boxes::META_BOXES_LIST_COPY_KEY, array());
        if (isset($meta_boxes_list[$key])) {
            unset($meta_boxes_list[$key]);
            update_option(URE_Meta_Boxes::META_BOXES_LIST_COPY_KEY, $meta_boxes_list, false);
        }
        $answer = array('result'=>'success', 'message'=>'Meta box was deleted from the list');
        
        return $answer;
    }
    // end of remove_from_list()
    
}	
// end of URE_Metaboxes_Access class
