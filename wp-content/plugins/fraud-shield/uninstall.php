<?php
/**
 * Fraud Shield uninstall routine.
 *
 * Runs only when the plugin is deleted from the WordPress admin. Removes the
 * custom tables, plugin options, and the per-order chargeback meta flag.
 *
 * Since v1.19 this is OPT-IN: nothing is deleted unless "Remove all data on
 * uninstall" was ticked in Settings first. Fraud evidence, the blocklist, and
 * customers' completed ID verifications are exactly the data a merchant
 * cannot recreate by hand, and deleting them as a side effect of clicking
 * Delete on the plugins screen proved to be a trap. Reinstalling picks the
 * data straight back up.
 *
 * @package FraudShield
 */

// Exit if this file is called directly (i.e. not by WordPress' uninstaller).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Keep everything unless the merchant explicitly opted in to removal.
$fs_uninstall_settings = get_option( 'fs_settings', array() );
if ( ! is_array( $fs_uninstall_settings ) || empty( $fs_uninstall_settings['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Drop the custom tables. Table names are built from the trusted $wpdb->prefix.
$fs_index           = $wpdb->prefix . 'fs_order_index';
$fs_blocklist       = $wpdb->prefix . 'fs_blocklist';
$fs_block_actions   = $wpdb->prefix . 'fs_block_actions';
$fs_action_entries  = $wpdb->prefix . 'fs_block_action_entries';
$fs_checkout_blocks = $wpdb->prefix . 'fs_checkout_blocks';
$fs_idv_sessions    = $wpdb->prefix . 'fs_idv_sessions';
$fs_api_keys        = $wpdb->prefix . 'fs_api_keys';
$fs_api_events      = $wpdb->prefix . 'fs_api_events';
$fs_webhooks        = $wpdb->prefix . 'fs_webhooks';
$fs_deliveries      = $wpdb->prefix . 'fs_webhook_deliveries';
$fs_linked_cancels  = $wpdb->prefix . 'fs_linked_cancels';
$fs_prevented       = $wpdb->prefix . 'fs_prevented';
$fs_hv_reviews      = $wpdb->prefix . 'fs_hv_reviews';

$wpdb->query( "DROP TABLE IF EXISTS {$fs_hv_reviews}" );      // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_prevented}" );       // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_linked_cancels}" );  // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_action_entries}" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_block_actions}" );  // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_checkout_blocks}" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_index}" );          // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_blocklist}" );      // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_idv_sessions}" );   // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_deliveries}" );     // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_webhooks}" );       // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_api_events}" );     // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$fs_api_keys}" );       // phpcs:ignore WordPress.DB

// The webhook queue's and the geo backfill's cron entries outlive the plugin files otherwise.
wp_clear_scheduled_hook( 'fs_webhook_dispatch' );
wp_clear_scheduled_hook( 'fs_geo_backfill' );

// Remove options.
delete_option( 'fs_settings' );
delete_option( 'fs_db_version' );
delete_option( 'fs_door_v2_cursor' );
delete_option( 'fs_door_v2_done' );
delete_option( 'fs_geofence_regions' );

// Cached address-validation results (v1.31) — transients under a known prefix.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_fs\_addr\_%' OR option_name LIKE '\_transient\_timeout\_fs\_addr\_%'" ); // phpcs:ignore WordPress.DB
delete_option( 'fs_scan_state' );
delete_option( 'fs_import_state' );
delete_option( 'fs_email_allowlist' );
delete_option( 'fs_payment_labels' );
delete_option( 'fs_shipping_labels' );
delete_option( 'fs_verified_creds' );
delete_option( 'fs_idv_verified' );
delete_option( 'fs_idv_required' );
delete_option( 'fs_idv_sync_cursor' );
delete_option( 'fs_gw_seen' );
delete_option( 'fs_card_checks' );
delete_option( 'fs_card_key_map' );
delete_option( 'fs_api_settings' );
delete_option( 'fs_api_counts' );
delete_option( 'fs_api_pairing' );
delete_option( 'fs_api_audit' );

// Remove the CSV-import storage directory (uploaded order exports live here).
$fs_uploads = wp_upload_dir();
if ( ! empty( $fs_uploads['basedir'] ) ) {
	$fs_import_dir = trailingslashit( $fs_uploads['basedir'] ) . 'fraud-shield';
	if ( is_dir( $fs_import_dir ) ) {
		$fs_items = glob( trailingslashit( $fs_import_dir ) . '*' );
		if ( is_array( $fs_items ) ) {
			foreach ( $fs_items as $fs_item ) {
				if ( is_file( $fs_item ) ) {
					wp_delete_file( $fs_item );
				}
			}
		}
		// Remove hidden protection files, then the directory itself.
		foreach ( array( '.htaccess', 'index.html' ) as $fs_hidden ) {
			if ( is_file( $fs_import_dir . '/' . $fs_hidden ) ) {
				wp_delete_file( $fs_import_dir . '/' . $fs_hidden );
			}
		}
		@rmdir( $fs_import_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

// Remove every per-order meta key we may have written, from both the classic
// post meta store and the HPOS orders-meta table.
$fs_meta_keys    = array( '_fs_chargeback', '_fs_risk_score', '_fs_risk_level', '_fs_risk_reasons', '_fs_notified', '_fs_idv_verified', '_fs_idv_session', '_fs_card_check', '_fs_card_outcome', '_fs_cardholder', '_fs_card_tries', '_fs_linked_cancel', '_fs_cb_recovered' );
$fs_placeholders = implode( ', ', array_fill( 0, count( $fs_meta_keys ), '%s' ) );

$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$fs_placeholders})", $fs_meta_keys )
); // phpcs:ignore WordPress.DB

// Remove the per-customer "verified" flag, verification-hold meta, and the
// per-account gateway restriction.
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s, %s, %s)", '_fs_verified', '_fs_hold', '_fs_gw_restrict', '_fs_idv', '_fs_idv_required' )
); // phpcs:ignore WordPress.DB

// And the HPOS equivalent, if the orders meta table exists.
$fs_orders_meta = $wpdb->prefix . 'wc_orders_meta';
$fs_exists      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fs_orders_meta ) ); // phpcs:ignore WordPress.DB
if ( $fs_exists === $fs_orders_meta ) {
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$fs_orders_meta} WHERE meta_key IN ({$fs_placeholders})", $fs_meta_keys )
	); // phpcs:ignore WordPress.DB
}
