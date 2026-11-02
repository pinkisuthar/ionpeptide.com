<?php
/**
 * New affiliate email template
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
	<?php printf( '%s <strong>%s</strong>', esc_html_x( 'A new affiliate account has been registered for user', '[EMAILS] New affiliate email', 'yith-woocommerce-affiliates' ), esc_html( $affiliate->get_formatted_name() ) ); ?>
</p>

<p>
	<strong>
		<?php echo esc_html_x( 'Username:', '[EMAILS] New affiliate email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="username">
		<?php echo esc_html( $user->user_login ); ?>
	</span>

	<br/>

	<strong>
		<?php echo esc_html_x( 'User email:', '[EMAILS] New affiliate email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="email">
		<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a>
	</span>

	<br/>

	<strong>
		<?php echo esc_html_x( 'Affiliate token:', '[EMAILS] New affiliate email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="token">
		<?php echo esc_html( $affiliate->get_token() ); ?> (<?php echo esc_url( $affiliate->get_referral_url() ); ?>)
	</span>

	<br/>

	<strong>
		<?php echo esc_html_x( 'Payment email:', '[EMAILS] New affiliate email', 'yith-woocommerce-affiliates' ); ?>
	</strong>&nbsp;
	<span class="token">
		<?php
		$payment_email = $affiliate->get_payment_email();
		echo $payment_email ? esc_html( $payment_email ) : esc_html_x( 'N/A', '[EMAILS] New affiliate email', 'yith-woocommerce-affiliates' );
		?>
	</span>
</p>

<hr/>

<p>
	<?php foreach ( YITH_WCAF_Affiliates_Profile::get_become_an_affiliate_fields( 'view' ) as $field_key => $field ) : ?>
		<strong>
			<?php echo esc_html( $field['admin_label'] ); ?>
		</strong>&nbsp;
		<span class="<?php echo esc_attr( $field_key ); ?>">
		<?php
			$field_value = YITH_WCAF_Affiliates_Profile::get_field_value( $field_key, $affiliate, 'view' );
			$field_value = is_array( $field_value ) ? implode( ', ', $field_value ) : $field_value;

			echo wp_kses_post( $field_value );
		?>
		</span>
		<br/>
	<?php endforeach; ?>
</p>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
