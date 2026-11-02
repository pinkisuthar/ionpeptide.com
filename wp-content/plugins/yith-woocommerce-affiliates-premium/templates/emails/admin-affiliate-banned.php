<?php
/**
 * Affiliate banned email template
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading          string
 * @var $email                  WC_Email
 * @var $affiliate              YITH_WCAF_Affiliate
 * @var $affiliate_referral_url string
 * @var $new_status             string
 * @var $additional_message     string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php
$user = $affiliate->get_user();

if ( ! $user ) {
	return;
}
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	// translators: 1. Affiliate name.
	echo wp_kses_post( sprintf( _x( 'Affiliate <strong>%s</strong> was just banned.', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' ), $affiliate->get_formatted_name() ) );
	?>
</p>

<p>
	<strong>
		<?php echo esc_html_x( 'Username:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="username">
		<?php echo esc_html( $user->user_login ); ?>
	</span>

	<br/>

	<strong>
		<?php echo esc_html_x( 'User email:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="email">
		<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
	</span>

	<br/>

	<strong>
		<?php echo esc_html_x( 'Affiliate token:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="token">
		<?php echo esc_html( $affiliate->get_token() ); ?> (<?php echo esc_url( $affiliate->get_referral_url() ); ?>)
	</span>
</p>



<?php if ( $additional_message ) : ?>
	<strong>
		<?php echo esc_html_x( 'Ban message:', '[EMAILS] Affiliate banned email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<p class="additional-message">
		<?php echo esc_html( $additional_message ); ?>
	</p>
<?php endif; ?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
