<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Auto-routed warranty email — see customer-warranty-submitted.php for the model.
 * Override at: yourtheme/woocommerce/zorem-returns-exchanges/emails/admin-warranty-rejected.php
 *
 * Vars: $claim_id, $claim, $order, $email, $email_settings
 */

defined( 'ABSPATH' ) || exit;

$email_key = 'admin_warranty_rejected';
include zorem_returns_exchanges()->get_plugin_path() . '/templates/emails/_warranty-email-body.php';
