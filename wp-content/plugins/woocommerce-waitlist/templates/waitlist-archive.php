<?php
/**
 * The template for displaying the waitlist elements on an archive page (e.g. shop)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/waitlist-archive.php.
 *
 * HOWEVER, on occasion WooCommerce Waitlist will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @version 1.9.0
 */
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$user = isset( $user ) ? $user : null;
$context = isset( $context ) ? $context : '';
$product_id = isset( $product_id ) ? $product_id : '';
$on_waitlist = isset( $on_waitlist ) ? $on_waitlist : false;
$notice = isset( $notice ) ? $notice : '';
$lang = isset( $lang ) ? $lang : '';
$dismiss_notification_text = isset( $dismiss_notification_text ) ? $dismiss_notification_text : '';
$email_address_label_text = isset( $email_address_label_text ) ? $email_address_label_text : '';
$email_address_placeholder_text = isset( $email_address_placeholder_text ) ? $email_address_placeholder_text : '';
$url = isset( $url ) ? $url : '';

$users_email = $user ? $user->user_email : '';
$disabled    = $users_email ? 'disabled' : ''; 
// Don't display anything on the archive page if users are required to register (unnecessary clutter)
if ( 'yes' == get_option( 'woocommerce_waitlist_registration_needed' ) && ! $users_email ) {
	return;
}
$button_text = wcwl_get_button_text( $context, $product_id );
?>
<div class="wcwl_frontend_wrap wcwl_nojs">
	<?php if ( ! $on_waitlist ) { ?>
		<div class="wcwl_toggle">
			<button type="button" class="button"><?php esc_html_e( $button_text ); ?></button>
			<div class="spinner"></div>
		</div>
		<?php
		// phpcs ignore due to template having sufficient escaping and being directly output
		echo wcwl_get_waitlist_fields( $product_id, $context, $notice, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php
	} else {
		/**
		 * Filter to hide waitlist notices on the frontend
		 * 
		 * @since 2.4.11
		 */
		if ( $notice && apply_filters( 'wcwl_show_notice_on_frontend', true, $product_id, $notice, $context ) ) {
			?>
			<div class="wcwl_notice woocommerce-message">
				<div aria-live="polite">
					<p><?php esc_html_e( $notice ); ?></p>
				</div>
				<button type="button" class="wcwl_notice_dismiss">
					<span class="screen-reader-text"><?php esc_html_e( $dismiss_notification_text ); ?></span>
				</button>
			</div>
	<?php } ?>
		<div class="wcwl_email_elements wcwl_hide">
			<label for="wcwl_email_<?php esc_attr_e( $product_id ); ?>" class="wcwl_email_label wcwl_visually_hidden"><?php esc_html_e( $email_address_label_text ); ?></label>
			<input type="email" value="<?php esc_attr_e( $users_email ); ?>" id="wcwl_email_<?php esc_attr_e( $product_id ); ?>" name="wcwl_email" class="wcwl_email" placeholder="<?php esc_attr_e( $email_address_placeholder_text ); ?>" <?php esc_attr_e( $disabled ); ?>/>
		</div>
		<?php
		/**
		 * Action to run before the form submit button, allowing hookup of recaptcha for example
		 * 
		 * @since 2.4.11
		 */
		do_action( 'wcwl_before_form_submit_button', $context, $product_id, $lang );
		?>
		<a class="wcwl_control" rel="nofollow" href="<?php echo esc_url( $url ); ?>" data-nonce="<?php esc_attr_e( wp_create_nonce( 'wcwl-ajax-process-user-request-nonce' ) ); ?>" data-product-id="<?php esc_attr_e( $product_id ); ?>" data-context="<?php esc_attr_e( $context ); ?>" data-wpml-lang="<?php esc_attr_e( $lang ); ?>">
			<button type="button" class="woocommerce_waitlist button"><?php esc_html_e( $button_text ); ?></button>
			<div aria-live="polite" class="wcwl_visually_hidden"></div>
			<div class="spinner"></div>
		</a>
	<?php } ?>
</div><!-- wcwl_frontend_wrap -->
