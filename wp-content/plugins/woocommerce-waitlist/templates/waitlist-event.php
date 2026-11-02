<?php
/**
 * The template for displaying the waitlist elements on a single event page
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/waitlist-event.php.
 *
 * HOWEVER, on occasion WooCommerce Waitlist will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @version 1.9.0
 * 
 * @todo escape registration text echo, we might need to pull the string into this file to appease phpcs
 */

// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$user = isset( $user ) ? $user : null;
$registration_required_text = isset( $registration_required_text ) ? $registration_required_text : '';
$intro = isset( $intro ) ? $intro : '';
$context = isset( $context ) ? $context : '';
$product_id = isset( $product_id ) ? $product_id : '';
$notice = isset( $notice ) ? $notice : '';
$dismiss_notification_text = isset( $dismiss_notification_text ) ? $dismiss_notification_text : '';
$opt_in = isset( $opt_in ) ? $opt_in : false;
$on_waitlist = isset( $on_waitlist ) ? $on_waitlist : false;
$opt_in_text = isset( $opt_in_text ) ? $opt_in_text : '';
$email_class = isset( $email_class ) ? $email_class : '';
$email_address_label_text = isset( $email_address_label_text ) ? $email_address_label_text : '';
$email_address_placeholder_text = isset( $email_address_placeholder_text ) ? $email_address_placeholder_text : '';
$lang = isset( $lang ) ? $lang : '';
$url = isset( $url ) ? $url : '';

?>

<div class="wcwl_elements wcwl_nojs">
	<?php
	$users_email = $user ? $user->user_email : '';
	$disabled    = $users_email ? 'disabled' : '';
	if ( 'yes' == get_option( 'woocommerce_waitlist_registration_needed' ) && ! $users_email ) {
		?>
		<div class="wcwl_notice woocommerce-info">
			<?php
			echo wp_kses( $registration_required_text, 'post' );
			?>
		</div>
	<?php } else { ?>
		<div class="wcwl_intro">
			<p><?php esc_html_e( $intro ); ?></p>
		</div>
		<?php
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
		<?php
		}
		if ( $opt_in && ! $on_waitlist ) {
			?>
			<div class="wcwl_optin">
				<input type="checkbox" name="wcwl_optin" id="wcwl_optin">
				<label for="wcwl_optin"><?php esc_html_e( $opt_in_text ); ?></label>
			</div>
		<?php
		}
		?>
		<div class="wcwl_email_elements <?php esc_attr_e( $email_class ); ?>">
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
			<button type="button" class="woocommerce_waitlist button"><?php esc_html_e( wcwl_get_button_text( $context, $product_id ) ); ?></button>
			<div aria-live="polite" class="wcwl_visually_hidden"></div>
			<div class="spinner"></div>
		</a>
	<?php } ?>
</div><!-- wcwl_elements -->
