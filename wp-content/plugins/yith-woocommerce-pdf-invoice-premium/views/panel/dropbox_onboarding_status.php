<?php
/**
 * This template show the status of onboarding procedure
 *
 * @package Bluehost\PaymentsShipping\Shippo\Emails
 * @author  Bluehost
 *
 * @var array $result The result.
 */

defined( 'ABSPATH' ) || exit;

$class_error = ! empty( $_GET['error'] ) ? 'with_error' : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
$connected   = empty( $result['error'] );
$error       = $result['error_description'] ?? '';
?>
<link rel="stylesheet" href="<?php echo esc_attr(  YIT_CORE_PLUGIN_URL . '/assets/css/yith-icon.css' ); ?>" media="all"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
<link rel="stylesheet" href="<?php echo esc_attr( YIT_CORE_PLUGIN_URL . '/assets/css/yith-plugin-ui.css' ); ?>" media="all"><?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet ?>
<style>

    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    }

    #ywpi_onboarding_status {
        background-color:#fff;
        width: 100%;
        height: 100%;
        margin-top: -5px;
    }

    #ywpi_onboarding_status.with_error {
        background-color: #AF5213;
    }

    .ywpi_onboarding_status_ok, .ywpi_onboarding_status_error {
        position: absolute;
        top: 20%;
        left: 27%;
        max-width: 45%;
        font-weight: bold;
        text-align: center;
        line-height: 24px;
        height: 200px;
        font-family: inherit;
        font-size   : 16px;
    }

    .ywpi_onboarding_status_ok {
        display: flex;
        align-items: center;
        gap: 10px;
        background-image      : url(<?php echo esc_attr( YITH_YWPI_ASSETS_URL . '/images/dropbox-icon.svg' ); ?>);
        background-repeat     : no-repeat;
        background-position-x : center;
    }

    .ywpi_onboarding_status_ok:before, .ywpi_onboarding_status_error:before {
        content: '';
        display: block;
        margin-bottom: 15px;
        background-repeat: no-repeat;
        background-size: contain;
        width: 32px;
        height: 22px;
    }

    .ywpi_onboarding_status_ok:before {
        background-image: url(<?php echo esc_attr( YITH_YWPI_ASSETS_URL . '/images/checkgreen.svg' ); ?>);
    }

    .ywpi_onboarding_status_error {
        color: #931818;
    }

    .ywpi_onboarding_status_error:before {
        background-image: url(<?php echo esc_attr( YITH_YWPI_ASSETS_URL . '/images/error.svg' ); ?>);
    }
#wpwrap{
	display:none;
}
</style>
<script type="text/javascript">
	function RefreshParent() {

		if (window.opener != null && !window.opener.closed) {
			const event = new CustomEvent('ywpi-update-connection-button', {detail: {connected:<?php echo $connected ?>, error: '<?php echo $error ?>' }});
			window.opener.document.dispatchEvent(event);
		}
	}

	window.onbeforeunload = RefreshParent;
	setTimeout(function() {
				   window.close();
			   },
			   3000,
	);
</script>
<div id="ywpi_onboarding_status" class="yith-plugin-ui <?php echo esc_attr( $class_error ); ?>">
	<?php
	if ( empty( $result['error'] ) ) : //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="ywpi_onboarding_status_ok">
			<?php esc_html_e( 'Successfully connected to your Dropbox account!', 'yith-woocommerce-pdf-invoice' ); ?>
		</div>
	<?php
	else :
		$error_message = $result['error_description'];
		?>
		<div class="ywpi_onboarding_status_error">
			<?php echo esc_html( $error_message ); ?>
		</div>
	<?php
	endif;
	?>
</div>
