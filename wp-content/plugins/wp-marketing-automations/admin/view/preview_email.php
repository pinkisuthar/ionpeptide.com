<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<style type="text/css">
    .bwfan_preview_email {
        background-color: #ffffff;
        padding: 20px !important;
    }

    #wpbody {
        padding-top: 0 !important;
    }
</style>
<div class="bwfan_body">
	<?php
	if ( isset( $_GET['type'] ) && 'loading' === sanitize_text_field( wp_unslash( $_GET['type'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin email preview; capability checked
		esc_html_e( 'Loading ...', 'wp-marketing-automations' );
	} else {
		BWFAN_Merge_Tag_Loader::set_data( array(
			'is_preview' => true,
		) );
		$automation_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview inside the FKA admin page (menu capability required)
		if ( absint( $automation_id ) < 1 ) {
			echo 'Automation ID missing';
		}
		$email_data = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'email_preview' );

		$email_data['event_data']['event_slug'] = $email_data['event'];
		$action_object                          = BWFAN_Core()->integration->get_action( 'wp_sendemail' );
		if ( null === $action_object && class_exists( 'BWFAN_Wp_Sendemail' ) ) {
			/** Try to get the instance directly */
			$action_object = BWFAN_Wp_Sendemail::get_instance();
		}
		if ( null === $action_object ) {
			esc_html_e( 'Email action not found', 'wp-marketing-automations' );

			return;
		}
		$action_object->is_preview              = true;
		$data_to_set                            = $action_object->make_data( '', $email_data );

		echo $data_to_set['body']; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>

</div>
