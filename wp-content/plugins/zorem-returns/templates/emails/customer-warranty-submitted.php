<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables come from wc_get_template()/wc_mail() extract() calls.
/**
 * Customer — Warranty Claim Submitted email.
 *
 * Override at: yourtheme/woocommerce/zorem-returns-exchanges/emails/customer-warranty-submitted.php
 *
 * Vars: $claim_id, $claim, $order, $email, $email_settings
 * Preview note: $claim is null when this template renders inside the customizer
 * preview, so the partial below handles that path gracefully.
 */

defined( 'ABSPATH' ) || exit;

$email_key = 'customer_warranty_submitted';
include zorem_returns_exchanges()->get_plugin_path() . '/templates/emails/_warranty-email-body.php';
