<?php
/**
 * This template manage the onboarding button
 *
 *
 * @var array $field The field array.
 */

defined( 'ABSPATH' ) || exit;

$token = get_option( 'ywpi_refresh_token', '' );
?>
<div id="<?php echo esc_attr( $field['id'] ); ?>">
	<?php
	if ( empty( $token ) ) :
		?>
		<span class="yith-plugin-fw__button yith-plugin-fw__button--secondary yith-plugin-fw__button--with-icon ywpi-button ywpi-connect-account" data-url="<?php echo esc_attr( $field['data']['url'] ); ?>">
		<i class="yith-icon ywpi-connect-icon"></i>
		<?php esc_html_e( 'Connect to Dropbox', 'yith-woocommerce-pdf-invoice' ); ?>
	</span>
	<?php
	else :
		?>
		<div class="ywpi-connected-row">
			<div class="ywpi-connected-button">
				<span class="yith-plugin-fw__button yith-plugin-fw__button--secondary yith-plugin-fw__button--with-icon ywpi-button ywpi-connected-account">
				<i class="yith-icon ywpi-connected-icon"></i>
				<?php esc_html_e( 'Account connected', 'yith-woocommerce-pdf-invoice' ); ?>
				</span>
				<span class="yith-plugin-fw__button yith-plugin-fw__button--secondary ywpi-button ywpi-disconnect-account">
					<?php esc_html_e( 'Disconnect', 'yith-woocommerce-pdf-invoice' ); ?>
				</span>
				<input type="hidden" name="ywpi_disconnect_to_dropbox">
			</div>
		</div>
	<?php
	endif;
	?>
</div>
