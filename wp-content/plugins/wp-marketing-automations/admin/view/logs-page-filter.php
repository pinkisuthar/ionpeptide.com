<?php
defined( 'ABSPATH' ) || exit;

$all_actions     = BWFAN_Common::get_actions_filter_data();
$all_automations = BWFAN_Common::get_automations_filter_data();
$automation_id   = ( isset( $_GET['filter_aid'] ) && ! empty( $_GET['filter_aid'] ) ) ? absint( sanitize_text_field( wp_unslash( $_GET['filter_aid'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
$log_action      = ( isset( $_GET['filter_action'] ) && ! empty( $_GET['filter_action'] ) ) ? sanitize_text_field( wp_unslash( $_GET['filter_action'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change

// Don't show automation filter when single automation screen is opened
if ( ! isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page routing or list filter from the URL; no state change
	?>
    <div class="bwfan_filter_section">
        <select name="filter_aid" class="bwfan_filter_select">
            <option value=""><?php esc_html_e( 'Select Automation', 'wp-marketing-automations' ); ?></option>
			<?php
			foreach ( $all_automations as $automationid => $automation_name ) {
				$selected = ( $automation_id === $automationid ) ? 'selected' : '';

				echo '<option value="' . esc_attr( $automationid ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $automation_name ) . '</option>';
			}
			?>
        </select>
    </div>
	<?php
}
?>
<div class="bwfan_filter_section">
    <select name="filter_action" class="bwfan_filter_select">
        <option value=""><?php esc_html_e( 'Select Action', 'wp-marketing-automations' ); ?></option>
		<?php
		foreach ( $all_actions as $action_slug => $action_name ) {
			$selected = ( $log_action === $action_slug ) ? 'selected' : '';

			echo '<option value="' . esc_attr( $action_slug ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $action_name ) . '</option>';
		}
		?>
    </select>
</div>

<div class="bwfan_filter_section">
    <input type="submit" name="bwfan_submit_filter_logs" class="button" value="<?php esc_attr_e( 'Filter', 'wp-marketing-automations' ); ?>"/>
</div>
