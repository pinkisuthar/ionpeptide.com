<?php
/**
 * Display Email settings heading.
 *
 * @since 1.8.5
 *
 * @var array $args {
 *     Template arguments.
 *
 *     @type string $smtp_notice Pre-rendered SMTP education notice HTML, empty when admin education is disabled.
 * }
 */

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

?>
<h4>
	<?php esc_html_e( 'Email', 'wpforms-lite' ); ?>
</h4>
<p>
	<?php esc_html_e( 'Customize your email template and sending preferences.', 'wpforms-lite' ); ?>
</p>

<?php

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo $args['smtp_notice'] ?? '';

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
