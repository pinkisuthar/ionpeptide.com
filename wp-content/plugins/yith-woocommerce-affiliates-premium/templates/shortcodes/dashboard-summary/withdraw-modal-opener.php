<?php
/**
 * Affiliate Dashboard Payments - Withdraw Modal Opener
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Templates
 * @version 2.0.0
 */

/**
 * Template variables:
 *
 * @var $affiliate                  YITH_WCAF_Affiliate
 * @var $should_show_withdraw_popup bool
 * @var $error_notes                array
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php
/**
 * DO_ACTION: yith_wcaf_before_withdraw_modal_opener
 *
 * Allows to render some content before the button to open the withdraw modal.
 */
do_action( 'yith_wcaf_before_withdraw_modal_opener' );
?>

<span class="<?php echo $should_show_withdraw_popup ? '' : 'disabled'; ?>" id="withdraw_modal_opener_wrapper" data-tip="<?php echo esc_attr( implode( '<br/>', $error_notes ) ); ?>">
	<a href="#" role="button" class="button <?php echo $should_show_withdraw_popup ? '' : 'disabled'; ?>" id="withdraw_modal_opener">
		<?php echo esc_html_x( 'Request withdrawal', '[FRONTEND] Payments tab', 'yith-woocommerce-affiliates' ); ?>
	</a>
</span>

<?php
/**
 * DO_ACTION: yith_wcaf_after_withdraw_modal_opener
 *
 * Allows to render some content after the button to open the withdraw modal.
 */
do_action( 'yith_wcaf_after_withdraw_modal_opener' );
?>
