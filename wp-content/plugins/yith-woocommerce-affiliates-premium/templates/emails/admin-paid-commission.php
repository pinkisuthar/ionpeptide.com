<?php
/**
 * New pending payment email template
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $email_heading string
 * @var $email         WC_Email
 * @var $payments      YITH_WCAF_Payments_Collection
 * @var $currency      string
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	echo esc_html_x( 'The following payments were correctly issued to a gateway:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
	?>
</p>

<?php if ( ! $payments->is_empty() ) : ?>
	<?php $text_align = is_rtl() ? 'right' : 'left'; ?>
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px;" border="1">
		<thead>
		<tr>
			<th style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php echo esc_html_x( 'Payment:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' ); ?>
			</th>
			<th style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php echo esc_html_x( 'Affiliate:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' ); ?>
			</th>
			<th style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php echo esc_html_x( 'Amount:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' ); ?>
			</th>
			<th style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php echo esc_html_x( 'Gateway:', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' ); ?>
			</th>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $payments as $payment ) : ?>
		<tr>
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<strong>
					#<?php echo esc_html( $payment->get_id() ); ?>
				</strong>
			</td>
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php
				$affiliate = $payment->get_affiliate();

				echo $affiliate ? esc_html( $affiliate->get_formatted_name() ) : esc_html_x( 'N/A', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
				?>
			</td>
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php
				echo wp_kses_post( $payment->get_formatted_amount() );
				?>
			</td>
			<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
				<?php
				echo wp_kses_post( $payment->get_formatted_gateway() );
				?>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<p>
	<?php
	echo esc_html_x( 'If the selected gateway requires confirmation, the payment will switch to completed whenever the gateway reports payment as successful.', '[EMAILS] Payment sent email', 'yith-woocommerce-affiliates' );
	?>
</p>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
