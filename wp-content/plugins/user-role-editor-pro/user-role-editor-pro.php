<?php
/*
Plugin Name:        User Role Editor Pro
Plugin URI:         https://www.role-editor.com
Description:        Change/add/delete WordPress user roles and capabilities.
Version:            4.66
Requires at least:  4.6
Requires PHP:       7.4
Author:             Vladimir Garagulia
Author URI:         https://www.role-editor.com
License:            GPL v2 or later
License URI:        https://www.role-editor.com/end-user-license-agreement/
Text Domain:        user-role-editor
Domain Path:        /lang/
*/

/*
 Copyright 2010-2026  Vladimir Garagulia  (email: support@role-editor.com)
*/

defined( 'ABSPATH' ) || exit;

/**
 * Centralized debug-only error logging, gated by WP_DEBUG.
 */
if ( ! function_exists( 'ure_log_error' ) ) {
    function ure_log_error( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sole error_log() call site; centralized debug-only logging, gated by WP_DEBUG above.
            error_log( $message );
        }
    }
}


// Registered here, before the class_exists() guard below, and as a plain
// function rather than a URE_Core_Pro callback: when this plugin is activated
// while the free plugin is already active, the free plugin's bootstrap has
// already defined URE_Loader/URE_Core by the time WordPress includes this
// file for the activation request, so the guard below returns early and
// URE_Core_Pro::init() (which used to register this hook) never runs in that
// request - meaning URE_Core_Pro itself is never defined either. Registering
// a class-method callback here (e.g. ['URE_Core_Pro', 'prevent_dual_activation'])
// would fatal with "class not found" when WordPress later invokes it, since
// core-pro.php was never loaded. A standalone function guarded by
// function_exists(), exactly like ure_log_error() above, has no such
// dependency and is safe to register unconditionally.
if ( ! function_exists( 'ure_pro_prevent_dual_activation' ) ) {
    function ure_pro_prevent_dual_activation() {
        $free_plugin = 'user-role-editor/user-role-editor.php';
        if ( file_exists( WP_PLUGIN_DIR . '/' . $free_plugin ) && is_plugin_active( $free_plugin ) ) {
            deactivate_plugins( $free_plugin );
        }
    }
}
register_activation_hook( __FILE__, 'ure_pro_prevent_dual_activation' );

if ( class_exists('URE_Loader') ) {
    ure_log_error( 'URE Loader class is defined already. Stop execution' );
    return;
}
$ure_classes_dir = plugin_dir_path( __FILE__ ) .'includes/classes/';
$ure_loader_file = $ure_classes_dir .'loader.php';
if ( !file_exists( $ure_loader_file ) ) {
    if ( defined( WP_DEBUG ) && WP_DEBUG ) {
        ure_log_error( "File '{$ure_loader_file}' is not found." );
    }
    return;
}

require_once $ure_loader_file;

if ( !class_exists('URE_Loader') ) {
    if ( defined( WP_DEBUG )  && WP_DEBUG ) {
        ure_log_error( "File '{$ure_loader_file}' loaded, but class 'URE_Loader' is not found." );
    }
    return;
}

if ( !URE_Loader::load_file( $ure_classes_dir . 'core.php', 'URE_Core' ) ) {
    return;
}

$ure_pro_classes_dir = plugin_dir_path( __FILE__ ) .'pro/includes/classes/';
if ( !URE_Loader::load_file( $ure_pro_classes_dir . 'core-pro.php', 'URE_Core_Pro' ) ) {
    return;
}

URE_Core_Pro::init( __FILE__ );
