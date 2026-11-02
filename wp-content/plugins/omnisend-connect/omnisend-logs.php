<?php
/**
 * Omnisend Logs Page
 *
 * @package OmnisendPlugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render logs page
 */
function omnisend_show_logs() {
	if ( ! class_exists( 'WP_List_Table' ) ) {
		include_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
	}

	if ( isset( $_GET['action'] ) && check_admin_referer( 'omnisend_logs' ) ) {
		if ( $_GET['action'] == 'log_options' ) {
			if ( isset( $_GET['enable'] ) && '1' === $_GET['enable'] ) {
				Omnisend_Logger::enable_logging();
			} else {
				Omnisend_Logger::disable_logging();
			}
		} elseif ( $_GET['action'] == 'clean_log' ) {
			Omnisend_Logger::remove_all_logs();
			wp_safe_redirect( admin_url( 'admin.php?page=' . OMNISEND_SETTINGS_PAGE . '&tab=' . OMNISEND_LOGS_PAGE ) );
			exit;
		}
	}
	// phpcs:enable

	$logging_enabled = Omnisend_Logger::is_logging_enabled();
	?>
	<div class="settings-page">
		<?php
		omnisend_display_omnisend_connected();
		omnisend_display_tabs( 'Logs' );
		?>
		<div class="settings-main-wrapper single-column logs-page">
			<div class="settings-main-content">
				<div class="omnisend-settings-card">
					<h3>Log Settings</h3>
					<div class="omnisend-content-body">
						Manage logging settings and view system logs for debugging purposes.
					</div>

				<!-- Logging Control Row -->
				<div class="logging-control-row">
					<div class="logging-control-content">
						<label class="logging-label" for="logging-switch">Logging</label>
						<span class="status-chip <?php echo $logging_enabled ? 'status-on' : 'status-off'; ?>">
							<?php echo $logging_enabled ? 'Active' : 'Inactive'; ?>
						</span>
					</div>
					<div class="logging-actions">
						<label class="switch" for="logging-switch">
							<input
								type="checkbox"
								id="logging-switch"
								<?php echo $logging_enabled ? 'checked' : ''; ?>
								role="switch"
								aria-checked="<?php echo $logging_enabled ? 'true' : 'false'; ?>"
								aria-describedby="logging-desc"
							>
							<span class="thumb"></span>
						</label>
					</div>
				</div>

				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'_wpnonce' => wp_create_nonce( 'omnisend_logs' ),
						),
						admin_url( 'admin.php?page=' . OMNISEND_SETTINGS_PAGE . '&tab=' . OMNISEND_LOGS_PAGE . '&action=clean_log' )
					)
				);
				?>
				"
				class="omnisend-primary-button clean-log-button-external">
					Clear logs
				</a>

			</div>

				<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					const loggingSwitch = document.getElementById('logging-switch');
					const statusChip = document.querySelector('.status-chip');
					const systemLogsCard = document.querySelector('.omnisend-settings-card:last-child');

					if (loggingSwitch) {
						loggingSwitch.addEventListener('change', function() {
							const isEnabled = this.checked;
							const enableValue = isEnabled ? '1' : '0';

						// Update status chip
						if (statusChip) {
							statusChip.textContent = isEnabled ? 'Active' : 'Inactive';
							statusChip.className = 'status-chip ' + (isEnabled ? 'status-on' : 'status-off');
						}

							// Update ARIA attributes
							this.setAttribute('aria-checked', isEnabled);

							// Dim system logs table when disabled
							if (systemLogsCard) {
								if (isEnabled) {
									systemLogsCard.style.opacity = '1';
									systemLogsCard.setAttribute('aria-disabled', 'false');
								} else {
									systemLogsCard.style.opacity = '0.6';
									systemLogsCard.setAttribute('aria-disabled', 'true');
								}
							}

							// Make AJAX request to update logging state
							fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
								method: 'POST',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded',
								},
								body: new URLSearchParams({
									action: 'omnisend_toggle_logging',
									enable: enableValue,
									_wpnonce: '<?php echo esc_js( wp_create_nonce( 'omnisend_logs' ) ); ?>'
								})
							}).then(response => response.json())
							.then(data => {
								if (!data.success) {
							// Revert switch state on error
							loggingSwitch.checked = !isEnabled;
							statusChip.textContent = !isEnabled ? 'Active' : 'Inactive';
							statusChip.className = 'status-chip ' + (!isEnabled ? 'status-on' : 'status-off');
							alert('Failed to update logging settings. Please try again.');
								}
							})
							.catch(error => {
								console.error('Error:', error);
								// Revert switch state on error
								loggingSwitch.checked = !isEnabled;
								statusChip.textContent = !isEnabled ? 'Active' : 'Inactive';
								statusChip.className = 'status-chip ' + (!isEnabled ? 'status-on' : 'status-off');
								alert('Failed to update logging settings. Please try again.');
							});
						});

						// Initialize disabled state for system logs card
						if (!loggingSwitch.checked && systemLogsCard) {
							systemLogsCard.style.opacity = '0.6';
							systemLogsCard.setAttribute('aria-disabled', 'true');
						}
					}
				});
				</script>

		<div class="omnisend-logs-container">
			<div class="omnisend-settings-card">
				<h3>System Logs</h3>
				<div class="omnisend-content-body">
					View recent system logs and API requests for troubleshooting.
				</div>
			<?php
			$logs = Omnisend_Logger::get_all_logs();
			if ( count( $logs ) == 0 ) {
				echo '<div class="omnisend-content-body omnisend-logs-clean">Logfile is clean!</div>';
			} else {
				echo "<div class='omnisend-logs-table-container'><table class='wp-list-table widefat fixed striped posts omnisend-logs-table'>
					<thead>
						<tr>
							<td class='fixed_date'>Date, GMT</td>
							<td class='fixed_type'>Type</td>
							<td class='fixed_endpoint'>Endpoint</td>
							<td class='fixed_url'>Url</td>
							<td>Message</td>
						</tr>
					</thead>";
				foreach ( $logs as $log ) {
					echo '<tr><td>' . esc_html( $log->date ) . '</td>
						<td class="omnisend-' . esc_attr( $log->type ) . '">' . esc_html( $log->type ) . '</td>
						<td>' . esc_html( $log->endpoint ) . '</td>
						<td>' . esc_html( $log->url ) . '</td>
						<td>' . esc_html( $log->message ) . '</td></tr>';
				}
				echo '</table></div>';
			}
			?>
			</div>
		</div>
			</div>
		</div>
	</div>
	<?php
}

?>
