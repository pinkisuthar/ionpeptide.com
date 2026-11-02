<?php
/**
 * Invoice template
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<div class="invoice-container">
	<div class="invoice-heading">
		<h2 class="invoice-number">
			<?php echo esc_html_x( 'Invoice n. {{number}}', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' ); ?>
		</h2>

		<div class="invoice-date">
			<?php echo wp_kses_post( _x( '<b>Date:</b> {{current_date}}', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' ) ); ?>
		</div>

		<h1>{{title}}</h1>
		<h3>{{blog_name}}</h3>
	</div>

	<div class="invoice-addresses">
		<table class="addresses">
			<tr>
				<td class="affilliate-address">
					<h3><?php echo esc_html_x( 'Affiliate', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' ); ?></h3>

					<br/>
					{{affiliate_section}}
				</td>

				<td class="company-details">
					<h3><?php echo esc_html_x( 'Client', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' ); ?></h3>

					<br/>
					{{company_section}}
				</td>
			</tr>
		</table>
	</div>

	<div class="invoice-content">
		<p>
			<?php echo wp_kses_post( _x( '<b>Description:</b> {{description}}', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' ) ); ?>
		</p>
		<p>
			<?php
			echo wp_kses_post(
				_x(
					'In reference to {{affiliate_program}} I joined, I ask for a payment of {{withdraw_amount}} for commissions earned through my referral link.',
					'[FRONTEND] Invoice template',
					'yith-woocommerce-affiliates'
				)
			);
			?>
		</p>
		<p>
			<?php
			echo esc_html_x( 'With the proving documentation of the due payment attachment, the following document represents a valid invoice for fiscal purposes.', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' )
			?>
		</p>
	</div>

	<div class="invoice-totals">
		<table class="totals">
			<tr class="grand-total">
				<td class="total-description">
					<?php echo esc_html_x( 'Commissions total:', '[FRONTEND] Invoice template', 'yith-woocommerce-affiliates' ); ?>
				</td>
				<td class="total">{{withdraw_amount}}</td>
			</tr>
		</table>
	</div>
</div>
