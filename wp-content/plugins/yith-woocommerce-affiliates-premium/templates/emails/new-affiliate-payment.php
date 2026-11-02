<?php
/**
 * New affiliate payment email template
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading    string
 * @var $email            WC_Email
 * @var $affiliate        YITH_WCAF_Affiliate
 * @var $payment          YITH_WCAF_Payment
 * @var $commissions      YITH_WCAF_Commissions_Collection
 * @var $display_name     string
 * @var $currency         string
 * @var $user             WP_User
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	// translators: 1. Affiliate formatted name.
	echo esc_html( sprintf( _x( 'Hi %s,', '[EMAILS] New affiliate commission', 'yith-woocommerce-affiliates' ), $display_name ) );
	?>
</p>

<p>
	<?php
	// translators: 1. Payment id. 2. Payment gateway.
	echo wp_kses_post( sprintf( _x( 'Payment <strong>#%1$s</strong> was issued correctly to your account via %2$s.', '[EMAILS] New affiliate payment', 'yith-woocommerce-affiliates' ), $payment->get_id(), $payment->get_formatted_gateway() ) );
	?>
</p>

<p>
	<strong>
		<?php esc_html_e( 'Amount:', 'yith-woocommerce-affiliates' ); ?>
	</strong>
	&nbsp;
	<span class="amount">
		<?php
		echo esc_html(
			wp_strip_all_tags(
				$payment->get_formatted_amount(
					'view',
					array(
						'currency' => $currency,
					)
				)
			)
		);
		?>
	</span>
</p>

<?php
YITH_WCAF_Emails::print_commissions_table(
	$commissions,
	false,
	array(
		'affiliate' => $affiliate,
		'token'     => $affiliate->get_token(),
	)
);
?>

<p>
	{content_html}
</p>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
