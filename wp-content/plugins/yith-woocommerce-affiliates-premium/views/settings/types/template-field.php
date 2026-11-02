<?php
/**
 * Edit template field
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var $this              YITH_WCAF_Settings_Admin_Panel Panel object.
 * @var $field             array                          Array of field options.
 * @var $template          string                         Template for current field.
 * @var $custom_attributes array                          Array of custom attributes.
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php
$local_file    = YITH_WCAF_Admin_Templates::get_theme_file( $template );
$template_file = yith_wcaf_locate_template( $template );
$template_dir  = WC()->template_path() . 'yith-wcaf';
?>
<div class="template yith-wcaf-enhanced-template" id="<?php echo esc_attr( $field['id'] ); ?>" <?php yith_plugin_fw_html_attributes_to_string( $custom_attributes, true ); ?> >

	<?php if ( file_exists( $local_file ) ) : ?>
		<p>
			<?php
			/* translators: %s: Path to template file */
			printf( esc_html_x( 'This template has been overridden by your theme and can be found in: %s.', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ), '<code>' . esc_html( trailingslashit( basename( get_stylesheet_directory() ) ) . $template_dir . '/' . $template ) . '</code>' );
			?>
		</p>

		<div class="template-actions">
			<a
				href="#"
				class="button button-secondary toggle_editor"
				data-view-label="<?php echo esc_attr_x( 'View template', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>"
				data-hide-label="<?php echo esc_attr_x( 'Hide template', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>"
			></a>

			<?php if ( is_writable( $local_file ) ) : ?>
				<a
					href="<?php echo esc_url( YITH_WCAF_Admin_Actions::get_action_url( 'delete_template', array( 'template' => $template ) ) ); ?>"
					class="delete_template button button-secondary"
					data-confirm="<?php echo esc_attr_x( 'Are you sure you want to delete this template file?', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>"
				>
					<?php echo esc_html_x( 'Delete template file', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="editor" style="display:none">
			<textarea
				class="code"
				cols="25"
				rows="20"
				<?php if ( ! is_writable( $local_file ) ) : ?>
				readonly="readonly" disabled="disabled"
				<?php else : ?>
				data-name="<?php echo esc_attr( sanitize_title( $template ) ); ?>_code"
				<?php endif; ?>
			><?php echo esc_html( file_get_contents( $local_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents ?></textarea>
		</div>
	<?php elseif ( file_exists( $template_file ) ) : ?>
		<p>
			<?php
			/* translators: 1: Path to template file 2: Path to theme folder */
			printf( esc_html_x( 'To override and edit this template copy %1$s to your theme folder: %2$s.', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ), '<code>' . esc_html( plugin_basename( $template_file ) ) . '</code>', '<code>' . esc_html( trailingslashit( basename( get_stylesheet_directory() ) ) . $template_dir . '/' . $template ) . '</code>' );
			?>
		</p>

		<div class="template-actions">
			<a
				href="#"
				class="button button-secondary toggle_editor"
				data-view-label="<?php echo esc_attr_x( 'View template', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>"
				data-hide-label="<?php echo esc_attr_x( 'Hide template', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>"
			></a>

			<?php
			$target_dir = get_stylesheet_directory() . '/' . $template_dir;

			if ( is_writable( $target_dir ) ) :
				?>
				<a
					href="<?php echo esc_url( YITH_WCAF_Admin_Actions::get_action_url( 'move_template', array( 'template' => $template ) ) ); ?>"
					class="button button-secondary"
				>
					<?php echo esc_html_x( 'Copy file to theme', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="editor" style="display:none">
			<textarea
				class="code"
				readonly="readonly"
				disabled="disabled"
				cols="25"
				rows="20"
			><?php echo esc_html( file_get_contents( $template_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents ?></textarea>
		</div>
	<?php else : ?>
		<p><?php echo esc_html_x( 'File was not found.', '[ADMIN] Template field in settings', 'yith-woocommerce-affiliates' ); ?></p>
	<?php endif; ?>
</div>

