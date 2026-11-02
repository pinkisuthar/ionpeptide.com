<?php
/**
 * Summary footer template (plain text).
 *
 * This template can be overridden by copying it to yourtheme/wpforms/emails/summary-footer-plain.php.
 *
 * @since 1.6.2.3
 *
 * @version 1.6.2.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "\n---\n\n";
printf( /* translators: %1$s - site name, %2$s - link to Settings → Misc to manage weekly emails. */
	esc_html__( 'This email was auto-generated and sent from %1$s. Manage Email Summaries: %2$s', 'wpforms-lite' ),
	esc_html( wp_specialchars_decode( get_bloginfo( 'name' ) ) ),
	esc_url_raw( admin_url( 'admin.php?page=wpforms-settings&view=misc' ) )
);
