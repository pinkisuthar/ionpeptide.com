<?php
// phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template partial: variables come from the including class scope.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="license_connection_section">

<?php
$zorem_license_connected = get_option( 'zorem_license_connected', 0 );
$zorem_license_email     = get_option( 'zorem_license_email', '' );
$current_url             = esc_url( admin_url( '/admin.php?page=zorem-returns-exchanges&tab=license' ) );
$license                 = zorem_returns_exchanges()->license;

if ( $this->get_license_status() ) {
	?>
	<h3 class="licnese-inner-heading"><?php esc_html_e( 'Status', 'zorem-returns' ); ?>:<span class="success">Active</span></h3>
	<p><?php esc_html_e( 'Want to deactivate the license for any reason?', 'zorem-returns' ); ?></p>
	<form method="post" id="wc_ast_pro_addons_form" class="addons_inner_container" action="" enctype="multipart/form-data">
		<input name="license_key" type="hidden" value="<?php echo esc_html( $license->get_license_key() ); ?>" />
		<input type="hidden" name="action" class="licence_action" value="<?php echo $license->get_license_status() ? 'return_license_deactivate' : 'return_license_activate'; ?>" />
		<button name="save" class="button-primary licence_submit button-return" type="submit" value="Deactivate"><?php esc_html_e( 'Deactivate', 'zorem-returns' ); ?></button>
		<?php $nonce = wp_create_nonce( 'return_license_tab' ); ?>
		<input type="hidden" id="return_license_tab_nonce" name="return_license_tab_nonce" value="<?php echo esc_html( $nonce ); ?>">
	</form>	
<?php } elseif ( 1 == $zorem_license_connected && '' != $zorem_license_email ) { ?>
	<h3 class="licnese-inner-heading"><?php esc_html_e( 'Activate License', 'zorem-returns' ); ?></h3>
	<p><?php esc_html_e( 'Activate your license to receive automatic updates and premium support.', 'zorem-returns' ); ?></p>
	<a href="https://www.zorem.com/my-account/license-activation/?product_id=<?php echo esc_html( $this->get_product_id() ); ?>&redirect_url=<?php echo urlencode( $current_url ); ?>" class="button-primary button-return"><?php esc_html_e( 'Activate License', 'zorem-returns' ); ?></a>
<?php } else { ?>
	<h3 class="licnese-inner-heading"><?php esc_html_e( 'Activate License', 'zorem-returns' ); ?></h3>
	<p><?php esc_html_e( 'Activate your license to receive automatic updates and premium support.', 'zorem-returns' ); ?></p>
	<a href="https://www.zorem.com/my-account/license-activation/?product_id=<?php echo esc_html( $this->get_product_id() ); ?>&redirect_url=<?php echo urlencode( $current_url ); ?>" class="button-primary button-return"><?php esc_html_e( 'Connect & Activate', 'zorem-returns' ); ?></a>
<?php } ?>
</div>
