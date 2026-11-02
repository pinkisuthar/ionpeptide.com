<?php
/**
 * Table row for each user for waitlist and archive tabs
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$user       = isset( $user ) ? $user : 0;
$product_id = isset( $product_id ) ? $product_id : 0;
$date       = isset( $date ) ? $date : '';

$email = $user;
if ( ! is_email( $email ) ) {
	$user = get_user_by( 'id', $email );
	if ( $user ) {
		$email = $user->user_email;
	}
} elseif ( email_exists( $email ) ) {
	$user = get_user_by( 'email', $email );
}
?>
<tr class="wcwl_user_row" data-user-id="<?php esc_attr_e( $email ); ?>">
	<?php if ( $email ) { ?>
		<td>
			<input class="wcwl_user_checkbox" type="checkbox" name="wcwl_user_checkbox" value="<?php esc_attr_e( $email ); ?>" data-user-email="<?php esc_attr_e( $email ); ?>" data-date-added="<?php esc_attr_e( $date ); ?>"/>
		</td>
		<td>
			<strong>
				<?php
				if ( isset( $user->ID ) ) {
					?>
					<a title="<?php esc_attr_e( __( 'View User Profile', 'woocommerce-waitlist' ) ); ?>" href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>">
					<?php
				}
				esc_html_e( $email );
				?>
					</a>
				<?php
				$flag_url = Pie_WCWL_Custom_Tab::get_user_language_flag_url( $email, $product_id );
				if ( $flag_url ) {
					?>
					<img src="<?php echo esc_url( $flag_url ); ?>" />
					<?php
				}
				if ( isset( $wl_errors[ $email ] ) ) {
					echo '<span class="dashicons dashicons-warning"><span>' . esc_html( $wl_errors[ $email ] ) . '</span></span>';
				}
				?>
			</strong>
		</td>
		<td>
			<?php esc_html_e( Pie_WCWL_Custom_Tab::format_date( $date ) ); ?>
		</td>
	<?php } else { ?>
		<td>
			<input class="wcwl_user_checkbox" type="checkbox" name="wcwl_user_checkbox wcwl_removed_user" value="0" data-user-email="0" data-date-added="<?php esc_attr_e( $date ); ?>"/>
		</td>
		<td>
			<strong><?php esc_html_e( 'User removed themselves', 'woocommerce-waitlist' ); ?></strong>
		</td>
		<td>
			<?php esc_html_e( Pie_WCWL_Custom_Tab::format_date( $date ) ); ?>
		</td>
	<?php } ?>
</tr>
