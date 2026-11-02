<?php
defined( 'ABSPATH' ) || exit;

/*
 * Access restriction to plugins administration
 * Role level restrictions data controller
 * Project: User Role Editor Pro WordPress plugin
 * Author: Vladimir Garagulya
 * email: support@role-editor.com
 * 
 */

class URE_Plugins_Access_Role_Controller {
            
    public static function load_data($role_id) {
        
        $access_data = get_option(URE_Plugins_Access_Role::ACCESS_DATA_KEY);
        if (is_array($access_data) && array_key_exists($role_id, $access_data)) {
            $result =  $access_data[$role_id];
        } else {
            $result = array(
                'selection_model'=>1,
                'data'=>array(
                    'plugins'=>''
            ));
        }
        
        return $result;
        
    }
    // end of load_data()
        
    
    /**
     * Prepare data for show via URE_Plugins_Access_View::get_html()
     * @param string $role_id
     * @return boolean
     */
    public static function prepare_form_data($role_id) {
                
        $data = self::load_data($role_id);
        $result = array();
        $result['selection_model'] = $data['selection_model'];
        $result['plugins'] = $data['data']['plugins'];
        $result['object_type'] = 'role';
        $result['object_name'] = $role_id;
                
        return $result;
    }
    // end of prepare_form_data()
    
    
    private static function get_plugins_from_post() {
        
        $installed_plugins = get_plugins();
        $plugins = array();        
        foreach( array_keys( $installed_plugins ) as $plugin_id ) {
            foreach( array_values( $_POST['values'] ) as $selected_plugin ) {
                if ($selected_plugin===$plugin_id) {
                    $plugins[] = $plugin_id;
                }
            }
        }
        $plugins_str = implode(',', $plugins);
        
        return $plugins_str;
    }
    // end of get_plugins_from_post()
    
            
    private static function get_data_from_post() {
        
        $selection_model = URE_Utils::get_int_value_from_post('ure_plugins_access_model');
        if ($selection_model!=1 && $selection_model!=2) { // got invalid value
            $selection_model = 1;  // use 'Selected' as a default value
        }                
        $plugins_str = self::get_plugins_from_post();
        $data = array(
            'selection_model'=>$selection_model, 
            'data'=>array(
                'plugins'=>$plugins_str
                    )
                );         
        
        return $data;
    }
    // end of get_data_from_post()
    
    
    private static function save_data( $role_id ) {
        global $wp_roles;
        
        $access_for_role = self::get_data_from_post();
        
        $access_data = get_option( URE_Plugins_Access_Role::ACCESS_DATA_KEY );
        if ( !is_array( $access_data ) ) {
            $access_data = array();
        }

        if ( isset( $wp_roles->roles[ $role_id] ) ) {
            $access_data[$role_id] = $access_for_role;
        } elseif ( isset( $access_data[$role_id] ) ) {
            unset( $access_data[$role_id] );
        }
        
        update_option( URE_Plugins_Access_Role::ACCESS_DATA_KEY, $access_data );
        
        do_action('ure_save_plugins_access_restrictions', $role_id );
    }
    // end of save_data()    
    

    public static function update_data() {
    
        $answer = array('result'=>'error', 'message'=>'');
        if ( !current_user_can( URE_Plugins_Access::CAPABILITY ) ) {
            $answer['message'] = esc_html__('URE: you do not have enough permissions to use this add-on.', 'user-role-editor');
            return $answer;
        }
        
        $object_type = ( isset( $_POST['values']['ure_object_type'] ) ) ? URE_Base_Lib::filter_string_var( $_POST['values']['ure_object_type'] ) : false;
        if ($object_type!=='role') {
            $answer['message'] = esc_html__('URE: plugins access: Wrong object type. Data was not updated.', 'user-role-editor');
            return $answer;
        }
        
        $object_name = isset( $_POST['values']['ure_object_name'] ) ? URE_Base_Lib::filter_string_var( $_POST['values']['ure_object_name'] ) : false;
        if ( empty( $object_name ) ) {
            $answer['message'] = esc_html__('URE: plugins: Empty object name. Data was not updated', 'user-role-editor');
            return $answer;
        }
                        
        self::save_data( $object_name );
        
        $answer['result'] = 'success';
        $answer['message'] = esc_html__('Plugins access data was updated successfully', 'user-role-editor');
        
        return $answer;
    }
    // end of update_data()        
    
}
// end of URE_Plugins_Access_Role_Controller