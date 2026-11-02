<?php
defined( 'ABSPATH' ) || exit;

/*
 * Class: Edit access to posts/pages for role data controller
 * Project: User Role Editor Pro WordPress plugin
 * Author: Vladimir Garagulya
 * email: support@role-editor.com
 * 
 */

class URE_Posts_Edit_Access_Role_Controller {
 

    public static function load_data($role_id) {
            
        $access_data = get_option(URE_Posts_Edit_Access_Role::ACCESS_DATA_KEY);
        if (is_array($access_data) && array_key_exists($role_id, $access_data)) {
            $result =  $access_data[$role_id];
            if ( !isset( $result['data']['post_types'] ) ) {
                $result['data']['post_types'] = array();    // data structure changed, v. 4.63
            }
        } else {
            $result = array(
                'restriction_type'=>1,                
                'own_data_only'=>0,
                'data'=>array(
                    'post_types'=>array(),
                    'posts'=>array(),
                    'terms'=>array(),
                    'authors'=>array()                    
            ));
        }
        
        return $result;
        
    }
    // end of load_data()
    
    
    /**
     * Prepare data for show via URE_Posts_Edit_Access_View::get_html()
     * @global WP_Roles $wp_roles
     * @param string $role_id
     * @return boolean
     */
    public static function prepare_form_data( $role_id ) {
        global $wp_roles;
                
        $data = self::load_data( $role_id );
        $result = array();
        $result['restriction_type'] = $data['restriction_type'];        
        $result['own_data_only'] = $data['own_data_only'];
        $result['post_types'] = $data['data']['post_types'];
        $result['posts_list'] = implode(', ', $data['data']['posts']);
        $result['post_authors_list'] = implode(', ', $data['data']['authors']);
        $result['categories_list'] = implode(', ', $data['data']['terms']);
        
        $result['show_authors'] = false;
        if (!empty($role_id) && isset($wp_roles->roles[$role_id])) {
            $caps_to_check = array('edit_others_posts', 'edit_others_pages');
            foreach($caps_to_check as $cap) {
                if (!empty($wp_roles->roles[$role_id]['capabilities'][$cap])) {
                    $result['show_authors'] = true;
                    break;
                }
            }
        }
        $result['object_type'] = 'role';
        $result['object_name'] = $role_id;
                
        return $result;
    }
    // end of prepare_form_data()
    
    
    public static function extract_wp_post_types_from_post() {
        
        $lib = URE_Lib_Pro::get_instance();
        $wp_post_types = $lib->_get_post_types();
        
        $ure_post_types = ( isset( $_POST['values']['ure_post_types'] ) && is_array( $_POST['values']['ure_post_types'] ) ) ? $_POST['values']['ure_post_types'] : array();
        $post_types = array();
        foreach( $ure_post_types as $post_type ) {
            if ( in_array( $post_type, $wp_post_types ) ) {
                $post_types[] = $post_type;
            }
        }
        
        return $post_types;
    }
    // end of extract_wp_post_types_from_post()
            
        
    private static function get_data_from_post() {
        
        $restriction_type = URE_Utils::get_int_value_from_post('ure_posts_restriction_type');
        if ($restriction_type!=1 && $restriction_type!=2) { // got invalid value
            $restriction_type = 1;  // use 'Allow' as default value
        }        
        
        $own_data_only = URE_Utils::get_int_value_from_post( 'ure_own_data_only' );
        if ($own_data_only!=0 && $own_data_only!=1) { // got invalid value
            $own_data_only = 0;  // use 'Not Checked' as a default value
        }

        $post_types = self::extract_wp_post_types_from_post();

        $posts = URE_Utils::get_int_value_from_post('ure_posts_list', true );
        $authors = URE_Utils::get_int_value_from_post('ure_post_authors_list', true );
        $terms = URE_Utils::get_int_value_from_post('ure_categories_list', true );
                
        $data = array(
            'restriction_type'=>$restriction_type, 
            'own_data_only'=>$own_data_only,
            'data'=>array(
                'post_types'=>$post_types,
                'posts'=>$posts,
                'authors'=>$authors,
                'terms'=>$terms
                    )
                );         
        
        return $data;
    }
    // end of get_data_from_post()
    
    
    private static function save_data( $role_id ) {
        global $wp_roles;
        
        $access_for_role = self::get_data_from_post();
        $access_data = get_option(URE_Posts_Edit_Access_Role::ACCESS_DATA_KEY);        
        if ( !is_array( $access_data ) ) {
            $access_data = array();
        }

        $role_exists = isset( $wp_roles->roles[$role_id] );

        if (count($access_for_role)>0) {
            if ($role_exists) {
                $access_data[$role_id] = $access_for_role;
            } elseif (isset($access_data[$role_id])) {
                unset($access_data[$role_id]);
            }
        } elseif (isset($access_data[$role_id])) {            
            unset($access_data[$role_id]);
        }
        
        update_option( URE_Posts_Edit_Access_Role::ACCESS_DATA_KEY, $access_data );
        
        do_action('ure_save_user_edit_content_restrictions', $role_id );
    }
    // end of save_data()    
    

    public static function update_data() {
                                
        $answer = array('result'=>'error', 'message'=>''); 
       
        if ( !current_user_can(URE_Posts_Edit_Access_Role::EDIT_POSTS_ACCESS_CAP)) {
            $answer['message'] =  esc_html__('URE: you have not enough permissions to use this add-on.', 'user-role-editor');
            return $answer;
        }
               
        $object_type = ( isset( $_POST['values']['ure_object_type'] ) ) ? URE_Base_Lib::filter_string_var( $_POST['values']['ure_object_type'] ) : false;
        if ( $object_type!=='role' ) {
            $answer['message'] = esc_html__('URE: posts edit access: Wrong object type. Data was not updated.', 'user-role-editor');
            return $answer;
        }
        
        $object_name = isset( $_POST['values']['ure_object_name'] ) ? URE_Base_Lib::filter_string_var( $_POST['values']['ure_object_name'] ) : false;
        if ( empty( $object_name ) ) {
            $answer['message'] = esc_html__('URE: posts edit access: Empty object name. Data was not updated', 'user-role-editor');
            return $answer;
        }
                
        self::save_data( $object_name );        
        
        $answer['result'] = 'success';
        $answer['message'] = esc_html__('Posts edit access data was updated successfully', 'user-role-editor');
        
        return $answer;
    }
    // end of update_data()        
    
}
// end of URE_Posts_Edit_Access_Role_Controller