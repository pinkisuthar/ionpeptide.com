<?php
/**
 * Actions select
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $this              YITH_WCAF_Abstract_Admin_Panel
 * @var $available_actions array
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php if ( empty( $available_actions ) ) : ?>
	<p><?php echo esc_html_x( 'No available actions', '[ADMIN] Actions select', 'yith-woocommerce-affiliates' ); ?></p>
<?php else : ?>
	<select class="wc-enhanced-select" name="action" id="action">
		<option value=""><?php echo esc_html_x( 'Select an action', '[ADMIN] Actions select', 'yith-woocommerce-affiliates' ); ?></option>
		<?php foreach ( $available_actions as $action_id => $action_label ) : ?>
			<option value="<?php echo esc_attr( $action_id ); ?>">
				<?php echo esc_html( $action_label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
	<p class="submit">
		<button class="button-primary yith-plugin-fw__button--xl">
			<?php echo esc_html_x( 'Save', '[ADMIN] Actions select', 'yith-woocommerce-affiliates' ); ?>
		</button>
	</p>
	<?php wp_nonce_field( 'bulk-' . $this->tab ); ?>
<?php endif; ?>
