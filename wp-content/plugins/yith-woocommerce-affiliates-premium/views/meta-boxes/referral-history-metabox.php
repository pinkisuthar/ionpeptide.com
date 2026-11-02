<?php
/**
 * Order Referral MetaBox
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<?php if ( ! empty( $referral_history_users ) ) : ?>
	<div class="history-section">
		<?php foreach ( $referral_history_users as $user ) : ?>
			<div class="referral-history-item">
				<span><?php echo esc_html( $user['username'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
