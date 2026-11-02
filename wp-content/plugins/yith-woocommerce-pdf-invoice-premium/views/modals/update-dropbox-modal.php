<?php
/**
 * This is the template to show the modal about new authentication system on dropbox
 *
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="ywpi_update_dropbox_modal_content" style="display: none;">
	<span><?php
		esc_html_e( 'To keep saving your invoices in Dropbox, please, reconnect your account.', 'yith-woocommerce-pdf-invoice' );
		?>
		</span>
	<span>
		<?php
		esc_html_e( 'This operation has to be done only once.', 'yith-woocommerce-pdf-invoice' );
		?>
		</span>
	<span class="yith-plugin-fw__button yith-plugin-fw__button--secondary ywpi_reconnect_button">
		<?php esc_html_e( 'Reconnect to Dropbox', 'yith-woocommerce-pdf-invoice' ); ?>
	</span>

</div>