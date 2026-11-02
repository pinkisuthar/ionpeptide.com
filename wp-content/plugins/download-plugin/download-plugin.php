<?php
/*
*  Plugin Name: Download Plugin
*  Plugin URI: http://metagauss.com
*  Description: Download any plugin from your WordPress admin panel's Plugins page by just one click! Now, download themes, users, blog posts, pages, custom posts, comments, attachments and much more.
*  Version: 2.4.6
*  Author: Download Plugin
*  Author URI: https://profiles.wordpress.org/downloadplugin/
*  Text Domain: download-plugin
*  Requires at least: 4.8
*  Tested up to: 7.0
*  Requires PHP: 5.6
*/

if ( !defined( 'ABSPATH' ) ) {
    exit;
}

if( !is_admin() ) return;

// plugin version
define('DPWAP_VERSION', '2.4.6');
// directory separator
if ( !defined( 'DS' ) ) define( 'DS', DIRECTORY_SEPARATOR );
// plugin file name
if ( !defined( 'DPWAP_PLUGIN_FILE' ) ) {
    define( 'DPWAP_PLUGIN_FILE', __FILE__ );
}
if ( !defined( 'DPWAP_DIR' ) ) {
    define( 'DPWAP_DIR', dirname( __FILE__ ) );	// Plugin dir
}
if ( !defined( 'DPWAP_URL' ) ) {
    define( 'DPWAP_URL', plugin_dir_url( __FILE__ ) ); // Plugin url
}
if ( !defined( 'DPWAP_FREE_GUIDE_URL' ) ) {
    define( 'DPWAP_FREE_GUIDE_URL', 'https://theeventprime.com/how-to-use-download-plugin-in-wordpress/' );
}
if ( !defined( 'DPWAP_PRO_GUIDE_URL' ) ) {
    define( 'DPWAP_PRO_GUIDE_URL', 'https://theeventprime.com/how-to-use-download-plugin-in-wordpress/' );
}
if ( !defined( 'DPWAP_PREFIX' ) ) {
    define( 'DPWAP_PREFIX', 'dpwap_' ); // Plugin Prefix
}

if ( ! function_exists( 'dpwap_add_utm_params' ) ) {
    function dpwap_add_utm_params( $url, $medium, $campaign, $content = '' ) {
        $args = array(
            'utm_source'   => 'download_plugin',
            'utm_medium'   => $medium,
            'utm_campaign' => $campaign,
        );

        if ( '' !== $content ) {
            $args['utm_content'] = $content;
        }

        return add_query_arg( $args, $url );
    }
}

$dpwapUploadDir = wp_upload_dir();
if ( !defined( 'DPWAPUPLOADDIR_PATH' ) ) {
    define( 'DPWAPUPLOADDIR_PATH', $dpwapUploadDir['basedir'] );
}    
if ( !defined( 'DPWAP_PLUGINS_TEMP' ) ) {
    define( 'DPWAP_PLUGINS_TEMP', $dpwapUploadDir['basedir'].'/dpwap_plugins' ); // Plugin Prefix
}

require_once dirname( DPWAP_PLUGIN_FILE ) . '/vendor/autoload.php';

register_activation_hook( DPWAP_PLUGIN_FILE, 'dpwap_on_plugin_activation' );
register_activation_hook( DPWAP_PLUGIN_FILE, 'dpwap_func_activate' );
register_deactivation_hook( DPWAP_PLUGIN_FILE, 'dpwap_on_plugin_deactivation' );

/**
 * Handle Download Plugin activation.
 */
function dpwap_on_plugin_activation() {
	if ( defined( 'WP_INSTALLING' ) ) {
		return;
	}
	$timestamp = time();
	update_option( 'dpwap_pro_last_activation_time', $timestamp );
	update_option( 'dpwap_pro_welcome_modal_pending', 1 );
	update_option( 'dpwap_pro_welcome_modal_dismissed', 0 );
	update_option( 'dpwap_pro_notice_dismissed_at', 0 );
	update_option( 'dpwap_pro_notice_cooldown_until', $timestamp + DAY_IN_SECONDS );
	update_option( 'dpwap_pro_notice_version', DPWAP_VERSION );
}

/**
 * Handle Download Plugin deactivation.
 */
function dpwap_on_plugin_deactivation() {
	update_option( 'dpwap_pro_last_deactivation_time', time() );
}

/**
 * Check if the paid Pro plugin is active.
 *
 * @return bool
 */
function dpwap_is_pro_active() {
	if ( defined( 'DPWAP_PRO_ACTIVE' ) && DPWAP_PRO_ACTIVE ) {
		return true;
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active( 'download-plugin-pro/download-plugin-pro.php' );
}

add_action( 'plugins_loaded', 'dpwap_plugin_loaded' );

//register_activation_hook( __FILE__, 'dpwap_func_activate' );

register_uninstall_hook( __FILE__, 'dpwap_func_uninstall' );

function dpwap_plugin_loaded() {
    static $instance;
	if ( is_null( $instance ) ) {
		$instance = new DPWAP\Main();
        /**
         * Download plugin loaded.
         *
         * Fires when Download plugin was fully loaded and instantiated.
         *
         */
        do_action( 'dpwap_download_plugin_loaded' );
	}
	return $instance;
}

if( !function_exists( 'dpwap_func_activate' ) ) {
    function dpwap_func_activate() {
        update_option( 'download_plugin_do_activation_redirect', true );
    }
}

if ( !function_exists( 'dpwap_func_uninstall' ) ){
    function dpwap_func_uninstall() {
        //delete_option( 'dpwap_popup_status' );
        $folder = DPWAP_PLUGINS_TEMP;
        $files = glob( "$folder/*" );
        if ( !empty( $files) ) {
            foreach( $files as $file ) {
                if ( is_file( $file) ){
                    unlink( $file );
                }
            }
        }
    }
}

// enhancement start 
// Add download link to post/page row actions
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'dpwap_plugin_action_links' );

function dpwap_plugin_action_links( $links ) {
    $starter_url = dpwap_add_utm_params( DPWAP_FREE_GUIDE_URL, 'plugin_row_action', 'guide', 'learn_how_it_works' );
    $pro_url     = dpwap_add_utm_params( 'https://theeventprime.com/checkout/?download_id=43730&edd_action=add_to_cart&edd_options[price_id][]=1', 'plugin_row_action', 'pro_upgrade', 'upgrade_to_pro' );
    $is_pro_active = function_exists( 'dpwap_is_pro_active' ) && dpwap_is_pro_active();

    $starter_link = '<a href="' . esc_url( $starter_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn how it works', 'download-plugin' ) . '</a>';
    $pro_link     = '<a href="' . esc_url( $pro_url ) . '" target="_blank" rel="noopener noreferrer" class="dpwap-pro-link" data-action="open-pro-modal" data-checkout-url="' . esc_url( $pro_url ) . '">' . esc_html__( 'Upgrade to Pro', 'download-plugin' ) . '</a>';

    $result = array();
    foreach ( $links as $key => $link ) {
        $result[ $key ] = $link;
        if ( 'deactivate' === $key ) {
            if ( ! $is_pro_active ) {
                $result['dpwap_free_pro_upgrade'] = $pro_link;
            }
            $result['dpwap_starter_guide']    = $starter_link;
        }
    }

    if ( ! isset( $result['dpwap_starter_guide'] ) ) {
        if ( ! $is_pro_active ) {
            $result['dpwap_free_pro_upgrade'] = $pro_link;
        }
        $result['dpwap_starter_guide']    = $starter_link;
    }

    return $result;
}
function dpwap_add_download_link($actions, $post) {
    if (current_user_can('manage_options')) {
        $download_url = wp_nonce_url(
            add_query_arg(
                [
                    'dpwap_download' => 1,
                    'post_id' => $post->ID,
                    'type' => $post->post_type,
                ],
                admin_url('edit.php')
            ),
            'dpwap_download_post_' . $post->ID
        );
        $actions['dpwap_download'] = '<a href="' . esc_url($download_url) . '">Download</a>';
    }
    return $actions;
}
add_filter('post_row_actions', 'dpwap_add_download_link', 10, 2);
add_filter('page_row_actions', 'dpwap_add_download_link', 10, 2);
add_filter('tag_row_actions', 'dpwap_add_term_download_action', 10, 2);
add_action('admin_init', 'dpwap_register_taxonomy_download_hooks');
add_action('admin_init', 'dpwap_handle_download_term');

// Handle the download request
function dpwap_handle_download() {
    if (isset($_GET['dpwap_download']) && current_user_can('manage_options')) {
        $post_id = intval($_GET['post_id']);
         // Verify the nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dpwap_download_post_' . $post_id)) {
            wp_die(__('Invalid nonce specified', 'dpwap'), __('Error', 'dpwap'), ['response' => 403]);
        }
        
        $post_type = sanitize_text_field($_GET['type']);
        $format = 'csv'; // Default to CSV

        // Fetch the post and its metadata
        $post = get_post($post_id);
        $title = $post->post_title;
        $post_type = $post->post_type;
        $meta_data = get_post_meta($post_id);
        $meta_data = array_combine(array_keys($meta_data), array_column($meta_data, '0'));
        
        $data = array();
        // Prepare the data
        $data[] = [
            'post' => $post,
            'meta' => $meta_data,
        ];
        
        $type = !empty($title)?$title:$post_type;
        $filename  = sanitize_key($type).'.csv';
        
        dpwap_export_bulk_csv($data,$filename);
        exit;
    }
}

add_action('admin_init', 'dpwap_handle_download');
add_action('admin_init', 'dpwap_add_bulk_filters');

function dpwap_add_bulk_filters()
{
    $post_types = get_post_types();
    if(!empty($post_types))
    {
        foreach ($post_types as $post_type) {
            add_filter('bulk_actions-edit-'.$post_type, 'dpwap_register_bulk_download');
            add_filter('bulk_actions-edit-'.$post_type, 'dpwap_register_bulk_download');
            add_filter('handle_bulk_actions-edit-'.$post_type, 'dpwap_handle_bulk_download', 10, 3);
            add_filter('handle_bulk_actions-edit-'.$post_type, 'dpwap_handle_bulk_download', 10, 3);
        }
    }
}

// Register bulk action for posts/pages
function dpwap_register_bulk_download($bulk_actions) {
    if (current_user_can('manage_options')) {
        $bulk_actions['dpwap_bulk_download'] = 'Download';
    }
    return $bulk_actions;
}

// Handle the bulk download
function dpwap_handle_bulk_download($redirect_to, $doaction, $post_ids) {
    if ($doaction === 'dpwap_bulk_download' && current_user_can('manage_options')) {
        check_admin_referer('bulk-posts');
        $data = [];
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            $post_type = $post->post_type;
            $meta_data = get_post_meta($post_id);
            $meta_data = array_combine(array_keys($meta_data), array_column($meta_data, '0'));
            $data[] = ['post' => $post, 'meta' => $meta_data];
        }
        $type = !empty($post_type)?$post_type:'post';
        $filename  = sanitize_key($type).'.csv';
        dpwap_export_bulk_csv($data,$filename);
        exit;
     }
     return $redirect_to;
}

function dpwap_export_bulk_csv($data,$file_name) {
    // Collect all unique post keys and meta keys from all posts.
    // Meta keys will be prefixed with 'meta:' in the CSV headers to match the pro addon convention.
    $all_post_keys = [];
    $all_meta_keys = [];
    
    foreach ($data as $item) {
        $post = $item['post'];
        
        // Collect post object properties.
        foreach ($post as $key => $value) {
            if (!in_array($key, $all_post_keys)) {
                $all_post_keys[] = $key;
            }
        }
        
        // Collect meta keys (will be prefixed with 'meta:' in headers).
        $meta = $item['meta'];
        foreach ($meta as $key => $value) {
            if (!in_array($key, $all_meta_keys)) {
                $all_meta_keys[] = $key;
            }
        }
    }
    
    $filename = !empty($file_name) ? $file_name : 'bulk_export.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=' . $filename);
    $output = fopen('php://output', 'w');

    // CSV Headers: combine post fields and meta keys (with 'meta:' prefix).
    $headers = $all_post_keys;
    foreach ($all_meta_keys as $meta_key ) {
        $headers[] = 'meta:' . $meta_key;
    }
    fputcsv($output, $headers);

    // Export each post with its data.
    foreach ($data as $item) {
        $post = $item['post'];
        $meta = $item['meta'];
        $row = array();
        
        // Add post data in the order of the post headers.
        foreach ($all_post_keys as $key) {
            $value = isset($post->$key) ? $post->$key : '';
            if (is_array($value) || is_object($value)) {
                $value = maybe_serialize($value);
            }
            $row[] = (string) $value;
        }

        // Add meta data in the order of the meta headers.
        // For array/object meta (like Elementor, Divi data), use JSON encoding for better compatibility.
        foreach ($all_meta_keys as $key) {
            $value = isset($meta[$key]) ? $meta[$key] : '';
            if (is_array($value) || is_object($value)) {
                // Use JSON encoding for complex meta values (page builder data, etc).
                $value = wp_json_encode($value);
            }
            $row[] = (string) $value;
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function dpwap_handle_download_comment() {
    if (isset($_GET['dpwap_download_comment']) && current_user_can('manage_options')) {
        $comment_id = intval($_GET['comment_id']);

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dpwap_download_comment_' . $comment_id)) {
            wp_die(__('Invalid nonce specified', 'dpwap'), __('Error', 'dpwap'), ['response' => 403]);
        }

        dpwap_export_comments([$comment_id]);
        exit;
    }
    
}

add_action('admin_init', 'dpwap_handle_download_comment');

function dpwap_handle_download_user() {
    if (isset($_GET['dpwap_download_user']) && current_user_can('manage_options')) {
        $user_id = intval($_GET['user_id']);

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'dpwap_download_user_' . $user_id)) {
            wp_die(__('Invalid nonce specified', 'dpwap'), __('Error', 'dpwap'), ['response' => 403]);
        }

        dpwap_export_users([$user_id]);
        exit;
    }
}

add_action('admin_init', 'dpwap_handle_download_user');

function add_download_button_to_comment_row($actions, $comment) {
     if (current_user_can('manage_options')) {
    $download_link_csv = wp_nonce_url(
            add_query_arg(
        [
            'dpwap_download_comment' => 1,
            'comment_id' => $comment->comment_ID,
            'format' => 'csv',
        ],
        admin_url('edit-comments.php')
        ),
            'dpwap_download_comment_' . $comment->comment_ID
    );
    
    $actions['download_comment'] = '<a href="' . esc_url($download_link_csv) . '">Download</a>';
     }
    return $actions;
}
add_filter('comment_row_actions', 'add_download_button_to_comment_row', 10, 2);

function add_download_button_to_user_row($actions, $user) {
     if (current_user_can('manage_options')) {
        $download_link_csv = wp_nonce_url(
            add_query_arg([
        'dpwap_download_user' => 1,
        'user_id' => $user->ID,
        'format' => 'csv',
    ], admin_url('users.php')),
            'dpwap_download_user_' . $user->ID
        );

    $actions['download_user'] = '<a href="' . esc_url($download_link_csv) . '">Download</a>';
     }
    return $actions;
}
add_filter('user_row_actions', 'add_download_button_to_user_row', 10, 2);

// Add bulk action for exporting comments
function dpwap_register_comment_bulk_action($bulk_actions) {
    $bulk_actions['export_comments_to_csv'] = __('Download', 'dpwap');
    return $bulk_actions;
}
add_filter('bulk_actions-edit-comments', 'dpwap_register_comment_bulk_action');

// Handle the bulk action for comments
function dpwap_handle_comment_bulk_action($redirect_to, $doaction, $comment_ids) {
    if ($doaction === 'export_comments_to_csv') {
        dpwap_export_comments($comment_ids, ($doaction === 'export_comments_to_csv') ? 'csv' : 'json');
    }
    return $redirect_to;
}
add_filter('handle_bulk_actions-edit-comments', 'dpwap_handle_comment_bulk_action', 10, 3);

// Add bulk action for exporting users
function dpwap_register_user_bulk_action($bulk_actions) {
    $bulk_actions['export_users_to_csv'] = __('Download', 'dpwap');
    return $bulk_actions;
}
add_filter('bulk_actions-users', 'dpwap_register_user_bulk_action');

// Handle the bulk action for users
function dpwap_handle_user_bulk_action($redirect_to, $doaction, $user_ids) {
    if ($doaction === 'export_users_to_csv') {
        dpwap_export_users($user_ids);
    }
    return $redirect_to;
}
add_filter('handle_bulk_actions-users', 'dpwap_handle_user_bulk_action', 10, 3);

function dpwap_export_users($user_ids) {
    $data = [];

    foreach ($user_ids as $user_id) {
        $user = get_userdata($user_id);
        $meta = get_user_meta($user_id);

        $data[] = [
            'user' => $user,
            'meta' => $meta,
        ];
    }
    dpwap_export_users_csv($data);
}






function dpwap_export_comments($comment_ids) {
    $data = [];

    foreach ($comment_ids as $comment_id) {
        $comment = get_comment($comment_id);
        $meta = get_comment_meta($comment_id);

        $data[] = [
            'comment' => $comment,
            'meta' => $meta,
        ];
    }

    dpwap_export_comments_csv($data);
}

function dpwap_export_users_csv($data)
{
    // Collect all unique user keys and meta keys.
    // Meta keys will be prefixed with 'meta:' in the CSV headers to maintain consistency.
    $all_user_keys = [];
    $all_meta_keys = [];
    
    foreach ($data as $item) {
        $user_data = $item['user']->data;
        foreach ($user_data as $key => $value) {
            if (!in_array($key, $all_user_keys)) {
                $all_user_keys[] = $key;
            }
        }
        
        $meta = $item['meta'];
        foreach ($meta as $key => $value) {
            if (!in_array($key, $all_meta_keys)) {
                $all_meta_keys[] = $key;
            }
        }
    }
    
    $filename = 'users_export.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=' . $filename);
    $output = fopen('php://output', 'w');

    // CSV Headers: combine user fields and meta keys (with 'meta:' prefix).
    $headers = $all_user_keys;
    foreach ($all_meta_keys as $meta_key) {
        $headers[] = 'meta:' . $meta_key;
    }
    fputcsv($output, $headers);

    foreach ($data as $item) {
        $user_data = $item['user']->data;
        $meta = $item['meta'];
        $row = array();
        
        // Add user data in the order of the user headers.
        foreach ($all_user_keys as $key) {
            $value = isset($user_data->$key) ? $user_data->$key : '';
            $row[] = (string) $value;
        }

        // Add meta data in the order of the meta headers.
        // User meta is stored as single values in an array, so we take the first element.
        foreach ($all_meta_keys as $key) {
            $value = '';
            if (isset($meta[$key]) && is_array($meta[$key])) {
                $value = reset($meta[$key]); // Get first value.
                if (is_array($value) || is_object($value)) {
                    $value = wp_json_encode($value);
                }
            }
            $row[] = (string) $value;
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function dpwap_export_comments_csv($data) {
    
    // Collect all unique comment keys and meta keys.
    // Meta keys will be prefixed with 'meta:' in the CSV headers to maintain consistency.
    $all_comment_keys = [];
    $all_meta_keys = [];
    
    foreach ($data as $item) {
        $comment = $item['comment'];
        foreach ($comment as $key => $value) {
            if (!in_array($key, $all_comment_keys)) {
                $all_comment_keys[] = $key;
            }
        }
        
        $meta = $item['meta'];
        foreach ($meta as $key => $value) {
            if (!in_array($key, $all_meta_keys)) {
                $all_meta_keys[] = $key;
            }
        }
    }
    
    $filename = 'comments_export.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=' . $filename);
    $output = fopen('php://output', 'w');

    // CSV Headers: combine comment fields and meta keys (with 'meta:' prefix).
    $headers = $all_comment_keys;
    foreach ($all_meta_keys as $meta_key) {
        $headers[] = 'meta:' . $meta_key;
    }
    fputcsv($output, $headers);

    foreach ($data as $item) {
        $comment = $item['comment'];
        $meta = $item['meta'];
        $row = array();
        
        // Add comment data in the order of the comment headers.
        foreach ($all_comment_keys as $key) {
            $value = isset($comment->$key) ? $comment->$key : '';
            if (is_array($value) || is_object($value)) {
                $value = maybe_serialize($value);
            }
            $row[] = (string) $value;
        }

        // Add meta data in the order of the meta headers.
        foreach ($all_meta_keys as $key) {
            $value = isset($meta[$key]) ? $meta[$key] : '';
            if (is_array($value) || is_object($value)) {
                $value = wp_json_encode($value);
            }
            $row[] = (string) $value;
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function dpwap_add_term_download_action( $actions, $term ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        return $actions;
    }

    if ( empty( $term ) || ! isset( $term->term_id, $term->taxonomy ) ) {
        return $actions;
    }

    $taxonomy = sanitize_key( $term->taxonomy );
    $taxonomy_obj = get_taxonomy( $taxonomy );
    if ( $taxonomy_obj && empty( $taxonomy_obj->show_ui ) ) {
        return $actions;
    }
    $args     = array(
        'dpwap_download_term' => 1,
        'term_id'             => (int) $term->term_id,
        'taxonomy'            => $taxonomy,
    );

    if ( isset( $_REQUEST['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $args['post_type'] = sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    $url = wp_nonce_url(
        add_query_arg( $args, admin_url( 'edit-tags.php' ) ),
        'dpwap_download_term_' . $term->term_id
    );

    $actions['dpwap_download'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Download', 'download-plugin' ) . '</a>';
    return $actions;
}

function dpwap_register_taxonomy_download_hooks() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $taxonomies = get_taxonomies( array( 'show_ui' => true ), 'names' );
    foreach ( $taxonomies as $taxonomy ) {
        add_filter( 'bulk_actions-edit-' . $taxonomy, 'dpwap_register_term_bulk_download' );
        add_filter( 'handle_bulk_actions-edit-' . $taxonomy, 'dpwap_handle_term_bulk_download', 10, 3 );
    }
}

function dpwap_register_term_bulk_download( $bulk_actions ) {
    if ( current_user_can( 'manage_options' ) ) {
        $bulk_actions['dpwap_bulk_download_terms'] = __( 'Download', 'download-plugin' );
    }
    return $bulk_actions;
}

function dpwap_handle_term_bulk_download( $redirect_to, $doaction, $term_ids ) {
    if ( 'dpwap_bulk_download_terms' !== $doaction || ! current_user_can( 'manage_options' ) ) {
        return $redirect_to;
    }

    $taxonomy = isset( $_REQUEST['taxonomy'] ) ? sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
        return $redirect_to;
    }

    check_admin_referer( 'bulk-tags' );
    dpwap_export_terms_csv( array_map( 'intval', (array) $term_ids ), $taxonomy );
    exit;
}

function dpwap_handle_download_term() {
    if ( ! isset( $_GET['dpwap_download_term'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $term_id  = isset( $_GET['term_id'] ) ? (int) $_GET['term_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    if ( ! $term_id || empty( $taxonomy ) || ! taxonomy_exists( $taxonomy ) ) {
        wp_die( esc_html__( 'Invalid term download request.', 'download-plugin' ) );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'dpwap_download_term_' . $term_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        wp_die( esc_html__( 'Invalid nonce specified.', 'download-plugin' ), esc_html__( 'Error', 'download-plugin' ), array( 'response' => 403 ) );
    }

    dpwap_export_terms_csv( array( $term_id ), $taxonomy );
    exit;
}

function dpwap_export_terms_csv( $term_ids, $taxonomy ) {
    if ( empty( $term_ids ) || ! taxonomy_exists( $taxonomy ) ) {
        return;
    }

    $terms = array();
    foreach ( $term_ids as $term_id ) {
        $term = get_term( (int) $term_id, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            $terms[] = $term;
        }
    }

    if ( empty( $terms ) ) {
        return;
    }

    // Collect all unique term keys and meta keys.
    // Meta keys will be prefixed with 'meta:' in the CSV headers to maintain consistency.
    $all_term_keys = array();
    $all_meta_keys = array();

    foreach ( $terms as $term ) {
        $term_data = get_object_vars( $term );
        foreach ( $term_data as $key => $value ) {
            if ( ! in_array( $key, $all_term_keys, true ) ) {
                $all_term_keys[] = $key;
            }
        }
        $meta = get_term_meta( $term->term_id );
        foreach ( $meta as $key => $values ) {
            if ( ! in_array( $key, $all_meta_keys, true ) ) {
                $all_meta_keys[] = $key;
            }
        }
    }

    $filename = sanitize_key( $taxonomy ) . '-terms.csv';
    header( 'Content-Type: text/csv' );
    header( 'Content-Disposition: attachment;filename=' . $filename );
    $output = fopen( 'php://output', 'w' );

    // CSV Headers: combine term fields and meta keys (with 'meta:' prefix).
    $headers = $all_term_keys;
    foreach ( $all_meta_keys as $meta_key ) {
        $headers[] = 'meta:' . $meta_key;
    }
    fputcsv( $output, $headers );

    foreach ( $terms as $term ) {
        $term_data = get_object_vars( $term );
        $meta      = get_term_meta( $term->term_id );

        $row = array();

        // Add term data in the order of the term headers.
        foreach ( $all_term_keys as $key ) {
            $value = isset( $term_data[ $key ] ) ? $term_data[ $key ] : '';
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = maybe_serialize( $value );
            }
            $row[] = (string) $value;
        }

        // Add meta data in the order of the meta headers.
        foreach ( $all_meta_keys as $key ) {
            $value = '';
            if ( isset( $meta[ $key ] ) ) {
                $meta_values = $meta[ $key ];
                if ( is_array( $meta_values ) ) {
                    $value = reset( $meta_values ); // Get first value.
                } else {
                    $value = $meta_values;
                }
                if ( is_array( $value ) || is_object( $value ) ) {
                    $value = wp_json_encode( $value );
                }
            }
            $row[] = (string) $value;
        }

        fputcsv( $output, $row );
    }

    fclose( $output );
    exit;
}
