<?php
/**
 * Plugin Name: Ion Mission Donations
 * Description: Monthly medical research donation tracker, annual pledge progress, and community voting poll for Ion Peptide's mission page.
 * Version: 1.0.5
 * Author: BuildRightWeb
 * Author URI: https://buildrightweb.com/
 * Text Domain: ion-mission-donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ion_Mission_Donations {
	const VERSION              = '1.0.5';
	const OPTION_SETTINGS      = 'ionmd_settings';
	const OPTION_CURRENT_POLL  = 'ionmd_current_poll';
	const OPTION_POLL_ARCHIVE  = 'ionmd_poll_archive';
	const OPTION_DONATIONS     = 'ionmd_donations';
	const NONCE_ACTION_SAVE    = 'ionmd_save_settings';
	const NONCE_ACTION_DONATE  = 'ionmd_add_donation';
	const NONCE_ACTION_DELETE  = 'ionmd_delete_donation';
	const NONCE_ACTION_RECEIPT = 'ionmd_update_donation_receipt';
	const NONCE_ACTION_VOTE    = 'ionmd_vote';

	private static $instance = null;
	private $frontend_assets_loaded = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'maybe_rollover_poll' ), 5 );
		add_action( 'ionmd_daily_rollover', array( $this, 'maybe_rollover_poll' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'admin_post_ionmd_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ionmd_add_donation', array( $this, 'handle_add_donation' ) );
		add_action( 'admin_post_ionmd_delete_donation', array( $this, 'handle_delete_donation' ) );
		add_action( 'admin_post_ionmd_update_receipt', array( $this, 'handle_update_receipt' ) );

		add_action( 'wp_ajax_ionmd_vote', array( $this, 'handle_vote' ) );
		add_action( 'wp_ajax_nopriv_ionmd_vote', array( $this, 'handle_vote' ) );

		add_shortcode( 'ion_mission_page', array( $this, 'shortcode_mission_page' ) );
		add_shortcode( 'ion_donation_progress', array( $this, 'shortcode_progress' ) );
		add_shortcode( 'ion_donation_poll', array( $this, 'shortcode_poll' ) );
		add_shortcode( 'ion_donation_tracker', array( $this, 'shortcode_tracker' ) );
	}

	public function activate() {
		$this->ensure_defaults();
		$this->maybe_rollover_poll();

		if ( ! wp_next_scheduled( 'ionmd_daily_rollover' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:10:00' ), 'daily', 'ionmd_daily_rollover' );
		}
	}

	public function deactivate() {
		$timestamp = wp_next_scheduled( 'ionmd_daily_rollover' );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ionmd_daily_rollover' );
			$timestamp = wp_next_scheduled( 'ionmd_daily_rollover' );
		}
	}

	private function ensure_defaults() {
		$current_ym = $this->current_ym();
		$settings   = get_option( self::OPTION_SETTINGS );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$defaults = array(
			'monthly_amount'      => 25000,
			'annual_goal'         => 300000,
			'campaign_start_month' => $current_ym,
			'poll_active'         => 1,
			'poll_question'       => 'Where should this month\'s $25,000 medical research donation go?',
			'poll_options_text'   => implode( "\n", $this->default_poll_option_names() ),
			'show_results_before_vote' => 0,
			'brand_name'          => 'Ion Peptide',
		);

		$settings = wp_parse_args( $settings, $defaults );
		$settings['monthly_amount'] = max( 0, absint( $settings['monthly_amount'] ) );
		$settings['annual_goal']    = max( 1, absint( $settings['annual_goal'] ) );

		update_option( self::OPTION_SETTINGS, $settings, false );

		if ( ! is_array( get_option( self::OPTION_DONATIONS ) ) ) {
			update_option( self::OPTION_DONATIONS, array(), false );
		}

		if ( ! is_array( get_option( self::OPTION_POLL_ARCHIVE ) ) ) {
			update_option( self::OPTION_POLL_ARCHIVE, array(), false );
		}

		if ( ! is_array( get_option( self::OPTION_CURRENT_POLL ) ) ) {
			$this->create_poll_for_month( $current_ym, array(), false );
		}
	}

	private function default_poll_option_names() {
		return array(
			'St. Jude Children’s Research Hospital',
			'Alex’s Lemonade Stand Foundation',
			'Damon Runyon Cancer Research Foundation',
			'The Leukemia & Lymphoma Society / Blood Cancer United',
			'Cure Alzheimer’s Fund',
		);
	}

	private function get_settings() {
		$this->ensure_defaults();
		$settings = get_option( self::OPTION_SETTINGS, array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function current_ym() {
		return wp_date( 'Y-m' );
	}

	private function current_year() {
		return (int) wp_date( 'Y' );
	}

	private function display_month( $ym ) {
		$timestamp = strtotime( $ym . '-01 00:00:00' );
		if ( ! $timestamp ) {
			return esc_html( $ym );
		}
		return date_i18n( 'F Y', $timestamp );
	}

	private function parse_month( $ym ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ) {
			return false;
		}
		$timestamp = strtotime( $ym . '-01 00:00:00' );
		return $timestamp ? $ym : false;
	}

	private function normalize_option_id( $name, $used = array() ) {
		$base = sanitize_title( remove_accents( $name ) );
		if ( '' === $base ) {
			$base = 'option';
		}

		$id = $base;
		$i  = 2;
		while ( in_array( $id, $used, true ) ) {
			$id = $base . '-' . $i;
			$i++;
		}

		return $id;
	}

	private function get_candidate_names_from_settings( $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( self::OPTION_SETTINGS, array() );
		}
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$text = isset( $settings['poll_options_text'] ) ? (string) $settings['poll_options_text'] : '';
		$lines    = preg_split( '/\r\n|\r|\n/', $text );
		$names    = array();

		foreach ( $lines as $line ) {
			$name = trim( wp_strip_all_tags( $line ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return ! empty( $names ) ? $names : $this->default_poll_option_names();
	}

	private function build_poll_options( $names, $existing_options = array() ) {
		$existing_by_id   = array();
		$existing_by_name = array();

		if ( is_array( $existing_options ) ) {
			foreach ( $existing_options as $existing ) {
				if ( empty( $existing['id'] ) || empty( $existing['name'] ) ) {
					continue;
				}
				$existing_by_id[ (string) $existing['id'] ]       = $existing;
				$existing_by_name[ strtolower( (string) $existing['name'] ) ] = $existing;
			}
		}

		$options = array();
		$used    = array();

		foreach ( $names as $name ) {
			$name = trim( wp_strip_all_tags( $name ) );
			if ( '' === $name ) {
				continue;
			}

			$id       = $this->normalize_option_id( $name, $used );
			$used[]   = $id;
			$votes    = 0;
			$name_key = strtolower( $name );

			if ( isset( $existing_by_id[ $id ]['votes'] ) ) {
				$votes = absint( $existing_by_id[ $id ]['votes'] );
			} elseif ( isset( $existing_by_name[ $name_key ]['votes'] ) ) {
				$votes = absint( $existing_by_name[ $name_key ]['votes'] );
			}

			$options[] = array(
				'id'    => $id,
				'name'  => $name,
				'votes' => $votes,
			);
		}

		return $options;
	}

	private function create_poll_for_month( $ym, $existing_options = array(), $preserve_votes = false ) {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings = wp_parse_args(
			$settings,
			array(
				'poll_question' => 'Where should this month\'s $25,000 medical research donation go?',
				'poll_active'   => 1,
			)
		);
		$names = $this->get_candidate_names_from_settings( $settings );
		$options  = $this->build_poll_options( $names, $preserve_votes ? $existing_options : array() );

		$poll = array(
			'month'       => $ym,
			'question'    => isset( $settings['poll_question'] ) ? sanitize_text_field( $settings['poll_question'] ) : '',
			'active'      => ! empty( $settings['poll_active'] ) ? 1 : 0,
			'options'     => $options,
			'voters'      => array(),
			'created_at'  => current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		);

		update_option( self::OPTION_CURRENT_POLL, $poll, false );
		return $poll;
	}

	public function maybe_rollover_poll( $force = false ) {
		$this->ensure_defaults();

		$current_ym = $this->current_ym();
		$poll       = get_option( self::OPTION_CURRENT_POLL, array() );

		if ( ! is_array( $poll ) || empty( $poll['month'] ) ) {
			$this->create_poll_for_month( $current_ym, array(), false );
			return;
		}

		if ( $force || $poll['month'] !== $current_ym ) {
			if ( ! empty( $poll['month'] ) ) {
				$archive = get_option( self::OPTION_POLL_ARCHIVE, array() );
				if ( ! is_array( $archive ) ) {
					$archive = array();
				}
				$archive[ $poll['month'] ] = $poll;
				update_option( self::OPTION_POLL_ARCHIVE, $archive, false );
			}

			$this->create_poll_for_month( $current_ym, array(), false );
		}
	}

	public function register_admin_menu() {
		add_menu_page(
			'Mission Donations',
			'Mission Donations',
			'manage_options',
			'ion-mission-donations',
			array( $this, 'render_admin_page' ),
			'dashicons-heart',
			56
		);
	}

	public function admin_assets( $hook ) {
		if ( 'toplevel_page_ion-mission-donations' !== $hook ) {
			return;
		}

		wp_enqueue_media();

		wp_register_script( 'ion-mission-donations-admin', '', array( 'jquery' ), self::VERSION, true );
		wp_enqueue_script( 'ion-mission-donations-admin' );
		wp_add_inline_script(
			'ion-mission-donations-admin',
			"jQuery(function($){\n" .
			"  $(document).on('click', '.ionmd-media-pick', function(e){\n" .
			"    e.preventDefault();\n" .
			"    var input = $(this).closest('.ionmd-receipt-control').find('input[type=\\'url\\']');\n" .
			"    if (!input.length || typeof wp === 'undefined' || !wp.media) { return; }\n" .
			"    var frame = wp.media({ title: 'Choose Donation Receipt PDF', button: { text: 'Use this PDF' }, multiple: false, library: { type: 'application/pdf' } });\n" .
			"    frame.on('select', function(){\n" .
			"      var attachment = frame.state().get('selection').first().toJSON();\n" .
			"      if (attachment && attachment.url) { input.val(attachment.url).trigger('change'); }\n" .
			"    });\n" .
			"    frame.open();\n" .
			"  });\n" .
			"  $(document).on('click', '.ionmd-media-clear', function(e){\n" .
			"    e.preventDefault();\n" .
			"    $(this).closest('.ionmd-receipt-control').find('input[type=\\'url\\']').val('').trigger('change');\n" .
			"  });\n" .
			"});"
		);

		wp_register_style( 'ion-mission-donations-admin', false, array(), self::VERSION );
		wp_enqueue_style( 'ion-mission-donations-admin' );
		wp_add_inline_style(
			'ion-mission-donations-admin',
			'.ionmd-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:18px 0}.ionmd-admin-card{padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.04)}.ionmd-admin-grid-bottom{align-items:start}.ionmd-receipt-control{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.ionmd-receipt-control input[type=url]{min-width:min(420px,100%)}.ionmd-receipt-form{display:grid;gap:8px;max-width:460px}.ionmd-record-actions{display:flex;gap:7px;align-items:center;justify-content:flex-end}.ionmd-record-actions form{margin:0}@media(max-width:960px){.ionmd-admin-grid{grid-template-columns:1fr}.ionmd-record-actions{justify-content:flex-start}}'
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ion-mission-donations' ) );
		}

		$settings  = $this->get_settings();
		$poll      = get_option( self::OPTION_CURRENT_POLL, array() );
		$donations = $this->get_donations();
		$message   = isset( $_GET['ionmd_message'] ) ? sanitize_key( wp_unslash( $_GET['ionmd_message'] ) ) : '';
		?>
		<div class="wrap ionmd-admin-wrap">
			<h1>Ion Mission Donations</h1>

			<?php if ( 'saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Mission donation settings saved.</p></div>
			<?php elseif ( 'donation_added' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Donation record added.</p></div>
			<?php elseif ( 'donation_deleted' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Donation record deleted.</p></div>
			<?php elseif ( 'receipt_saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p>Donation receipt link saved.</p></div>
			<?php endif; ?>

			<div class="ionmd-admin-grid">
				<div class="ionmd-admin-card">
					<h2>Shortcodes</h2>
					<p>Use these on the Our Mission page.</p>
					<ul>
						<li><code>[ion_mission_page]</code> — full mission page layout, progress, poll, and tracker.</li>
						<li><code>[ion_donation_progress]</code> — annual pledge counter only.</li>
						<li><code>[ion_donation_poll]</code> — monthly community poll only.</li>
						<li><code>[ion_donation_tracker]</code> — donation history tracker only.</li>
					</ul>
				</div>

				<div class="ionmd-admin-card">
					<h2>Current Poll</h2>
					<p><strong>Month:</strong> <?php echo esc_html( isset( $poll['month'] ) ? $this->display_month( $poll['month'] ) : $this->display_month( $this->current_ym() ) ); ?></p>
					<p><strong>Status:</strong> <?php echo ! empty( $poll['active'] ) ? 'Active' : 'Paused'; ?></p>
					<p><strong>Total votes:</strong> <?php echo esc_html( number_format_i18n( $this->poll_total_votes( $poll ) ) ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ionmd-admin-card ionmd-main-settings">
				<input type="hidden" name="action" value="ionmd_save_settings">
				<?php wp_nonce_field( self::NONCE_ACTION_SAVE ); ?>

				<h2>Mission Settings</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ionmd_brand_name">Brand Name</label></th>
						<td><input type="text" id="ionmd_brand_name" name="brand_name" class="regular-text" value="<?php echo esc_attr( $settings['brand_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ionmd_monthly_amount">Monthly Donation Amount</label></th>
						<td><input type="number" min="0" step="1" id="ionmd_monthly_amount" name="monthly_amount" value="<?php echo esc_attr( $settings['monthly_amount'] ); ?>"> <span class="description">Default: 25000</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="ionmd_annual_goal">Annual Commitment Goal</label></th>
						<td><input type="number" min="1" step="1" id="ionmd_annual_goal" name="annual_goal" value="<?php echo esc_attr( $settings['annual_goal'] ); ?>"> <span class="description">Default: 300000</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="ionmd_campaign_start_month">Campaign Start Month</label></th>
						<td><input type="month" id="ionmd_campaign_start_month" name="campaign_start_month" value="<?php echo esc_attr( $settings['campaign_start_month'] ); ?>"> <span class="description">Controls the automatic annual counter. Current month starts at $25,000.</span></td>
					</tr>
					<tr>
						<th scope="row"><label for="ionmd_poll_question">Poll Question</label></th>
						<td><input type="text" id="ionmd_poll_question" name="poll_question" class="large-text" value="<?php echo esc_attr( $settings['poll_question'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ionmd_poll_options_text">Poll Organizations</label></th>
						<td>
							<textarea id="ionmd_poll_options_text" name="poll_options_text" class="large-text code" rows="7"><?php echo esc_textarea( $settings['poll_options_text'] ); ?></textarea>
							<p class="description">One organization per line. These are used for the current poll and for the next month when the poll rolls over automatically.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Poll Status</th>
						<td>
							<label><input type="checkbox" name="poll_active" value="1" <?php checked( ! empty( $settings['poll_active'] ) ); ?>> Voting is open</label><br>
							<label><input type="checkbox" name="show_results_before_vote" value="1" <?php checked( ! empty( $settings['show_results_before_vote'] ) ); ?>> Show vote totals before a visitor votes</label>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Mission Settings' ); ?>
			</form>

			<div class="ionmd-admin-grid ionmd-admin-grid-bottom">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ionmd-admin-card">
					<input type="hidden" name="action" value="ionmd_add_donation">
					<?php wp_nonce_field( self::NONCE_ACTION_DONATE ); ?>

					<h2>Add Donation Record</h2>
					<p>This powers the public donation tracker at the bottom of the mission page.</p>

					<p>
						<label for="ionmd_donation_month"><strong>Donation Month</strong></label><br>
						<input type="month" id="ionmd_donation_month" name="donation_month" value="<?php echo esc_attr( $this->current_ym() ); ?>" required>
					</p>
					<p>
						<label for="ionmd_donation_org"><strong>Organization</strong></label><br>
						<input type="text" id="ionmd_donation_org" name="organization" class="regular-text" required>
					</p>
					<p>
						<label for="ionmd_donation_amount"><strong>Amount</strong></label><br>
						<input type="number" min="0" step="1" id="ionmd_donation_amount" name="amount" value="<?php echo esc_attr( $settings['monthly_amount'] ); ?>" required>
					</p>
					<p>
						<label for="ionmd_donation_date"><strong>Donation Date</strong></label><br>
						<input type="date" id="ionmd_donation_date" name="donation_date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>">
					</p>
					<p>
						<label for="ionmd_donation_receipt_url"><strong>Donation Receipt PDF</strong></label><br>
						<span class="ionmd-receipt-control">
							<input type="url" id="ionmd_donation_receipt_url" name="receipt_url" class="regular-text" placeholder="https://example.com/receipt.pdf">
							<button type="button" class="button ionmd-media-pick">Choose PDF</button>
							<button type="button" class="button ionmd-media-clear">Clear</button>
						</span>
						<span class="description">Upload the PDF to Media Library or paste the PDF URL. The tracker will show a Donation Receipt Letter link.</span>
					</p>
					<p>
						<label for="ionmd_donation_note"><strong>Public Note</strong></label><br>
						<textarea id="ionmd_donation_note" name="note" class="large-text" rows="3" placeholder="Optional"></textarea>
					</p>

					<?php submit_button( 'Add Donation Record' ); ?>
				</form>

				<div class="ionmd-admin-card">
					<h2>Donation Records</h2>
					<?php if ( empty( $donations ) ) : ?>
						<p>No donation records have been added yet. The public counter still shows the monthly pledged amount based on the campaign start month.</p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th>Month</th>
									<th>Organization</th>
									<th>Amount</th>
									<th>Receipt PDF</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $donations as $donation ) : ?>
									<tr>
										<td><?php echo esc_html( $this->display_month( $donation['month'] ) ); ?></td>
										<td><?php echo esc_html( $donation['organization'] ); ?></td>
										<td><?php echo esc_html( $this->money( $donation['amount'] ) ); ?></td>
										<td>
											<?php $receipt_url = $this->receipt_url_for_donation( $donation ); ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ionmd-receipt-form">
												<input type="hidden" name="action" value="ionmd_update_receipt">
												<input type="hidden" name="id" value="<?php echo esc_attr( $donation['id'] ); ?>">
												<?php wp_nonce_field( self::NONCE_ACTION_RECEIPT ); ?>
												<span class="ionmd-receipt-control">
													<input type="url" name="receipt_url" class="regular-text" value="<?php echo esc_attr( $receipt_url ); ?>" placeholder="https://example.com/receipt.pdf">
													<button type="button" class="button button-small ionmd-media-pick">Choose PDF</button>
													<button type="button" class="button button-small ionmd-media-clear">Clear</button>
													<button type="submit" class="button button-small button-primary">Save</button>
												</span>
												<?php if ( $receipt_url ) : ?>
													<a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener">View current receipt</a>
												<?php endif; ?>
											</form>
										</td>
										<td>
											<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ionmd_delete_donation&id=' . rawurlencode( $donation['id'] ) ), self::NONCE_ACTION_DELETE ) ); ?>" onclick="return confirm('Delete this donation record?');">Delete</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ion-mission-donations' ) );
		}

		check_admin_referer( self::NONCE_ACTION_SAVE );

		$settings = $this->get_settings();
		$monthly  = isset( $_POST['monthly_amount'] ) ? absint( wp_unslash( $_POST['monthly_amount'] ) ) : 25000;
		$annual   = isset( $_POST['annual_goal'] ) ? absint( wp_unslash( $_POST['annual_goal'] ) ) : 300000;
		$start    = isset( $_POST['campaign_start_month'] ) ? $this->parse_month( sanitize_text_field( wp_unslash( $_POST['campaign_start_month'] ) ) ) : $this->current_ym();

		$settings['brand_name']          = isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_name'] ) ) : 'Ion Peptide';
		$settings['monthly_amount']      = max( 0, $monthly );
		$settings['annual_goal']         = max( 1, $annual );
		$settings['campaign_start_month'] = $start ? $start : $this->current_ym();
		$settings['poll_question']       = isset( $_POST['poll_question'] ) ? sanitize_text_field( wp_unslash( $_POST['poll_question'] ) ) : '';
		$settings['poll_options_text']   = isset( $_POST['poll_options_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['poll_options_text'] ) ) : '';
		$settings['poll_active']         = isset( $_POST['poll_active'] ) ? 1 : 0;
		$settings['show_results_before_vote'] = isset( $_POST['show_results_before_vote'] ) ? 1 : 0;

		update_option( self::OPTION_SETTINGS, $settings, false );

		$poll = get_option( self::OPTION_CURRENT_POLL, array() );
		if ( ! is_array( $poll ) ) {
			$poll = array();
		}

		$poll['month']      = isset( $poll['month'] ) ? $poll['month'] : $this->current_ym();
		$poll['question']   = $settings['poll_question'];
		$poll['active']     = $settings['poll_active'];
		$poll['options']    = $this->build_poll_options( $this->get_candidate_names_from_settings(), isset( $poll['options'] ) ? $poll['options'] : array() );
		$poll['voters']     = isset( $poll['voters'] ) && is_array( $poll['voters'] ) ? $poll['voters'] : array();
		$poll['updated_at'] = current_time( 'mysql' );

		update_option( self::OPTION_CURRENT_POLL, $poll, false );

		wp_safe_redirect( admin_url( 'admin.php?page=ion-mission-donations&ionmd_message=saved' ) );
		exit;
	}

	public function handle_add_donation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ion-mission-donations' ) );
		}

		check_admin_referer( self::NONCE_ACTION_DONATE );

		$month = isset( $_POST['donation_month'] ) ? $this->parse_month( sanitize_text_field( wp_unslash( $_POST['donation_month'] ) ) ) : false;
		$org   = isset( $_POST['organization'] ) ? sanitize_text_field( wp_unslash( $_POST['organization'] ) ) : '';
		$amt   = isset( $_POST['amount'] ) ? absint( wp_unslash( $_POST['amount'] ) ) : 0;
		$date  = isset( $_POST['donation_date'] ) ? sanitize_text_field( wp_unslash( $_POST['donation_date'] ) ) : '';
		$note        = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$receipt_url = isset( $_POST['receipt_url'] ) ? $this->sanitize_receipt_url( wp_unslash( $_POST['receipt_url'] ) ) : '';

		if ( ! $month || '' === $org ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ion-mission-donations' ) );
			exit;
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}

		$donations   = $this->get_donations();
		$donations[] = array(
			'id'           => uniqid( 'ionmd_', true ),
			'month'        => $month,
			'organization' => $org,
			'amount'       => $amt,
			'date'         => $date,
			'note'         => $note,
			'receipt_url'  => $receipt_url,
			'created_at'   => current_time( 'mysql' ),
		);

		update_option( self::OPTION_DONATIONS, $donations, false );

		wp_safe_redirect( admin_url( 'admin.php?page=ion-mission-donations&ionmd_message=donation_added' ) );
		exit;
	}

	public function handle_delete_donation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ion-mission-donations' ) );
		}

		check_admin_referer( self::NONCE_ACTION_DELETE );

		$id        = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$donations = $this->get_donations();
		$filtered  = array();

		foreach ( $donations as $donation ) {
			if ( isset( $donation['id'] ) && $donation['id'] === $id ) {
				continue;
			}
			$filtered[] = $donation;
		}

		update_option( self::OPTION_DONATIONS, $filtered, false );

		wp_safe_redirect( admin_url( 'admin.php?page=ion-mission-donations&ionmd_message=donation_deleted' ) );
		exit;
	}

	public function handle_update_receipt() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ion-mission-donations' ) );
		}

		check_admin_referer( self::NONCE_ACTION_RECEIPT );

		$id          = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$receipt_url = isset( $_POST['receipt_url'] ) ? $this->sanitize_receipt_url( wp_unslash( $_POST['receipt_url'] ) ) : '';
		$donations   = $this->get_donations();

		foreach ( $donations as &$donation ) {
			if ( isset( $donation['id'] ) && $donation['id'] === $id ) {
				$donation['receipt_url'] = $receipt_url;
				break;
			}
		}
		unset( $donation );

		update_option( self::OPTION_DONATIONS, $donations, false );

		wp_safe_redirect( admin_url( 'admin.php?page=ion-mission-donations&ionmd_message=receipt_saved' ) );
		exit;
	}

	private function frontend_assets() {
		wp_enqueue_style(
			'ion-mission-donations',
			plugin_dir_url( __FILE__ ) . 'assets/css/frontend.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'ion-mission-donations',
			plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js',
			array(),
			self::VERSION,
			true
		);

		if ( ! $this->frontend_assets_loaded ) {
			wp_localize_script(
				'ion-mission-donations',
				'IonMissionDonations',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'voteToken' => $this->vote_token(),
				)
			);
			$this->frontend_assets_loaded = true;
		}
	}

	public function shortcode_mission_page( $atts = array() ) {
		$this->frontend_assets();
		$settings       = $this->get_settings();
		$brand          = ! empty( $settings['brand_name'] ) ? $settings['brand_name'] : 'Ion Peptide';
		$monthly_amount = absint( $settings['monthly_amount'] );
		$annual_goal    = max( 1, absint( $settings['annual_goal'] ) );

		ob_start();
		?>
		<main id="ion-mission-page" class="ionmd-page" aria-label="Our Mission">
			<section class="ionmd-hero" aria-label="Our Mission Hero">
				<div class="ionmd-container">
					<div class="ionmd-hero-card">
						<div class="ionmd-hero-copy">
							<div class="ionmd-breadcrumb">
								<a href="/">Home</a>
								<span>/</span>
								<span>Our Mission</span>
							</div>

							<span class="ionmd-hero-badge">Medical Research Initiative</span>

							<h1>Supporting the <span>Future of Medical Research</span></h1>

							<p>At <?php echo esc_html( $brand ); ?>, research advancement is at the core of what we do. This initiative puts action behind that belief by supporting qualified medical research organizations every month.</p>

							<div class="ionmd-hero-actions">
								<a class="ionmd-btn" href="#ionmd-current-poll">Vote This Month</a>
								<a class="ionmd-btn ionmd-btn--ghost" href="#ionmd-donation-tracker">View Donation Tracker</a>
							</div>
						</div>

						<div class="ionmd-hero-visual" aria-label="Donation initiative snapshot">
							<div class="ionmd-pledge-panel">
								<div class="ionmd-pledge-header">
									<span>Annual Commitment</span>
									<strong><?php echo esc_html( $this->money( $annual_goal ) ); ?></strong>
									<p>Guaranteed toward medical research-based 501(c)(3) organizations.</p>
								</div>

								<div class="ionmd-pledge-list">
									<div class="ionmd-pledge-row">
										<span class="ionmd-pledge-icon" aria-hidden="true">$</span>
										<div>
											<strong><?php echo esc_html( $this->money( $monthly_amount ) ); ?> Monthly Donation</strong>
											<span>Updated every month</span>
										</div>
									</div>

									<div class="ionmd-pledge-row">
										<span class="ionmd-pledge-icon" aria-hidden="true">✓</span>
										<div>
											<strong>Community Voice</strong>
											<span>Monthly organization poll</span>
										</div>
									</div>

									<div class="ionmd-pledge-row">
										<span class="ionmd-pledge-icon" aria-hidden="true">501</span>
										<div>
											<strong>Qualified Organizations</strong>
											<span>Medical research-based nonprofits</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="ionmd-summary" aria-label="Mission highlights">
				<div class="ionmd-container">
					<div class="ionmd-summary-grid">
						<article class="ionmd-summary-card">
							<div class="ionmd-summary-icon">01</div>
							<h3><?php echo esc_html( $this->money( $monthly_amount ) ); ?> Monthly</h3>
							<p>A dedicated monthly donation supporting medical research organizations.</p>
						</article>

						<article class="ionmd-summary-card">
							<div class="ionmd-summary-icon">02</div>
							<h3><?php echo esc_html( $this->money( $annual_goal ) ); ?> Annually</h3>
							<p>A long-term commitment toward research that can improve future outcomes.</p>
						</article>

						<article class="ionmd-summary-card">
							<div class="ionmd-summary-icon">03</div>
							<h3>Community Poll</h3>
							<p>Ambassadors, research clients, and the community can help guide each month’s selection.</p>
						</article>
					</div>
				</div>
			</section>

			<section class="ionmd-story" aria-label="Mission story">
				<div class="ionmd-container">
					<div class="ionmd-story-grid">
						<article class="ionmd-story-card ionmd-story-card--primary">
							<span class="ionmd-kicker">Why We’re Doing This</span>
							<h2>Every breakthrough starts with support.</h2>
							<p>We believe innovation, discovery, and progress are driven by the researchers, scientists, doctors, and organizations working every day to improve lives.</p>
							<p>Funding helps turn ideas into studies, studies into evidence, and evidence into better possibilities for the future.</p>
						</article>

						<article class="ionmd-story-card">
							<span class="ionmd-kicker">Involving Our Community</span>
							<h2>A monthly vote with real purpose.</h2>
							<p>Each month, we review medical research-based 501(c)(3) organizations and give the community an opportunity to help decide where the donation should go.</p>
							<p>We will continue reviewing qualified organizations and welcome suggestions from the people who care about research advancement.</p>
						</article>
					</div>
				</div>
			</section>

			<section class="ionmd-progress-band" aria-label="Annual donation progress">
				<div class="ionmd-container">
					<?php echo $this->render_progress_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</section>

			<section class="ionmd-commitment" aria-label="Long-term commitment">
				<div class="ionmd-container">
					<div class="ionmd-commitment-card">
						<div class="ionmd-commitment-visual" aria-hidden="true">
							<div class="ionmd-image-slot">
								<span>Future mission image</span>
							</div>
						</div>

						<div class="ionmd-commitment-copy">
							<span class="ionmd-kicker">Our Commitment</span>
							<h2>This is not a one-time gesture.</h2>
							<p>By pledging <?php echo esc_html( $this->money( $annual_goal ) ); ?> annually toward medical research, <?php echo esc_html( $brand ); ?> hopes to help support the people and organizations working toward better treatments, better outcomes, and a healthier future.</p>
							<p>Research moves the world forward. We are proud to do our part in supporting it.</p>
						</div>
					</div>
				</div>
			</section>

			<section class="ionmd-community" aria-label="Community poll and donation tracker">
				<div class="ionmd-container">
					<div class="ionmd-community-grid">
						<?php echo $this->render_poll_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $this->render_tracker_block(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
			</section>
		</main>
		<?php
		return ob_get_clean();
	}

	public function shortcode_progress( $atts = array() ) {
		$this->frontend_assets();
		return $this->render_progress_block();
	}

	public function shortcode_poll( $atts = array() ) {
		$this->frontend_assets();
		return $this->render_poll_block();
	}

	public function shortcode_tracker( $atts = array() ) {
		$this->frontend_assets();
		return $this->render_tracker_block();
	}

	private function render_progress_block() {
		$settings       = $this->get_settings();
		$monthly_amount = absint( $settings['monthly_amount'] );
		$annual_goal    = max( 1, absint( $settings['annual_goal'] ) );
		$pledged        = $this->pledged_to_date();
		$percent        = min( 100, round( ( $pledged / $annual_goal ) * 100 ) );

		ob_start();
		?>
		<section class="ionmd-progress ionmd-progress--simple" aria-label="Annual donation tracker">
			<div class="ionmd-progress-copy">
				<span class="ionmd-kicker">Annual Donation Tracker</span>
				<h2><?php echo esc_html( $this->money( $pledged ) ); ?> committed toward medical research this year</h2>
				<p><?php echo esc_html( $this->money( $monthly_amount ) ); ?> pledged each month, with a guaranteed annual commitment of <?php echo esc_html( $this->money( $annual_goal ) ); ?>.</p>

				<div class="ionmd-progress-bar" role="img" aria-label="<?php echo esc_attr( $percent ); ?> percent of annual commitment reached">
					<span style="width: <?php echo esc_attr( $percent ); ?>%;"></span>
				</div>

				<div class="ionmd-progress-numbers">
					<span><?php echo esc_html( $this->money( $pledged ) ); ?> recorded</span>
					<span><?php echo esc_html( $this->money( $annual_goal ) ); ?> annual goal</span>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_poll_block() {
		$settings = $this->get_settings();
		$poll     = get_option( self::OPTION_CURRENT_POLL, array() );
		if ( ! is_array( $poll ) || empty( $poll['options'] ) ) {
			return '';
		}

		$total_votes    = $this->poll_total_votes( $poll );
		$visitor_voted  = $this->visitor_has_voted( $poll );
		$show_results   = $visitor_voted || ! empty( $settings['show_results_before_vote'] );
		$poll_month     = isset( $poll['month'] ) ? $poll['month'] : $this->current_ym();
		$is_active      = ! empty( $poll['active'] );
		$monthly_amount = absint( $settings['monthly_amount'] );

		ob_start();
		?>
		<section class="ionmd-poll" data-ionmd-poll="1" aria-label="Monthly medical research donation poll">
			<div class="ionmd-section-head">
				<p class="ionmd-eyebrow"><?php echo esc_html( $this->display_month( $poll_month ) ); ?> Community Poll</p>
				<h2><?php echo esc_html( ! empty( $poll['question'] ) ? $poll['question'] : 'Where should this month’s medical research donation go?' ); ?></h2>
				<p>This month’s donation amount: <strong><?php echo esc_html( $this->money( $monthly_amount ) ); ?></strong></p>
			</div>

			<?php if ( ! $is_active ) : ?>
				<div class="ionmd-alert">Voting is currently paused while this month’s organizations are reviewed.</div>
			<?php elseif ( $visitor_voted ) : ?>
				<div class="ionmd-alert ionmd-alert--success">Thank you. Your vote has been recorded.</div>
			<?php endif; ?>

			<form class="ionmd-poll__form" data-ionmd-poll-form>
				<input type="hidden" name="action" value="ionmd_vote">
				<input type="hidden" name="poll_month" value="<?php echo esc_attr( $poll_month ); ?>">

				<div class="ionmd-poll__options">
					<?php foreach ( $poll['options'] as $option ) :
						$votes   = isset( $option['votes'] ) ? absint( $option['votes'] ) : 0;
						$percent = $total_votes > 0 ? round( ( $votes / $total_votes ) * 100 ) : 0;
						?>
						<label class="ionmd-poll__option">
							<input type="radio" name="option_id" value="<?php echo esc_attr( $option['id'] ); ?>" <?php disabled( ! $is_active || $visitor_voted ); ?> required>
							<span class="ionmd-poll__option-main">
								<span class="ionmd-poll__option-name"><?php echo esc_html( $option['name'] ); ?></span>
								<?php if ( $show_results ) : ?>
									<span class="ionmd-poll__result-line"><span style="width: <?php echo esc_attr( $percent ); ?>%;"></span></span>
									<span class="ionmd-poll__result-meta"><?php echo esc_html( $percent ); ?>% · <?php echo esc_html( number_format_i18n( $votes ) ); ?> vote<?php echo 1 === $votes ? '' : 's'; ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>

				<?php if ( $is_active && ! $visitor_voted ) : ?>
					<button type="submit" class="ionmd-button">Submit Vote</button>
				<?php endif; ?>

				<p class="ionmd-poll__message" data-ionmd-poll-message></p>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	private function render_tracker_block() {
		$settings       = $this->get_settings();
		$monthly_amount = absint( $settings['monthly_amount'] );
		$months         = $this->tracker_months_for_current_year();
		$donations      = $this->donations_by_month();

		ob_start();
		?>
		<section class="ionmd-tracker" aria-label="Donation history tracker">
			<div class="ionmd-section-head">
				<p class="ionmd-eyebrow">Donation History</p>
				<h2>Monthly medical research donation tracker</h2>
				<p>Each month will be updated with the organization selected for that month’s donation.</p>
			</div>

			<div class="ionmd-tracker__list">
				<?php foreach ( $months as $ym ) :
					$record      = isset( $donations[ $ym ] ) ? $donations[ $ym ] : null;
					$receipt_url = $record ? $this->receipt_url_for_donation( $record ) : '';
					$receipt_title = $this->display_month( $ym ) . ' Donation Receipt Letter';
					?>
					<div class="ionmd-tracker__item">
						<div>
							<strong><?php echo esc_html( $this->display_month( $ym ) ); ?></strong>
							<?php if ( $record ) : ?>
								<?php if ( $receipt_url ) : ?>
									<a class="ionmd-tracker__org ionmd-tracker__org--link" href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener" data-ionmd-receipt-open data-receipt-title="<?php echo esc_attr( $receipt_title ); ?>"><?php echo esc_html( $record['organization'] ); ?></a>
									<a class="ionmd-tracker__receipt" href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" rel="noopener" data-ionmd-receipt-open data-receipt-title="<?php echo esc_attr( $receipt_title ); ?>">Donation Receipt Letter</a>
								<?php else : ?>
									<span class="ionmd-tracker__org"><?php echo esc_html( $record['organization'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $record['note'] ) ) : ?>
									<small><?php echo esc_html( $record['note'] ); ?></small>
								<?php endif; ?>
							<?php else : ?>
								<span><?php echo $ym === $this->current_ym() ? 'Community vote in progress' : 'Donation record pending'; ?></span>
							<?php endif; ?>
						</div>
						<b><?php echo esc_html( $record ? $this->money( $record['amount'] ) : $this->money( $monthly_amount ) ); ?></b>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="ionmd-receipt-modal" data-ionmd-receipt-modal aria-hidden="true">
				<div class="ionmd-receipt-modal__backdrop" data-ionmd-receipt-close></div>
				<div class="ionmd-receipt-modal__dialog" role="dialog" aria-modal="true" aria-label="Donation receipt letter">
					<div class="ionmd-receipt-modal__head">
						<strong data-ionmd-receipt-title>Donation Receipt Letter</strong>
						<div>
							<a href="#" target="_blank" rel="noopener" data-ionmd-receipt-direct>Open PDF</a>
							<button type="button" data-ionmd-receipt-close aria-label="Close receipt popup">×</button>
						</div>
					</div>
					<iframe src="about:blank" title="Donation receipt PDF" loading="lazy" data-ionmd-receipt-frame></iframe>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	public function handle_vote() {
		$this->maybe_rollover_poll();

		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! hash_equals( $this->vote_token(), $token ) ) {
			wp_send_json_error( array( 'message' => 'The voting form expired. Please refresh the page and try again.' ), 403 );
		}

		$option_id = isset( $_POST['option_id'] ) ? sanitize_key( wp_unslash( $_POST['option_id'] ) ) : '';
		$month     = isset( $_POST['poll_month'] ) ? sanitize_text_field( wp_unslash( $_POST['poll_month'] ) ) : '';
		$poll      = get_option( self::OPTION_CURRENT_POLL, array() );

		if ( ! is_array( $poll ) || empty( $poll['active'] ) || empty( $poll['options'] ) ) {
			wp_send_json_error( array( 'message' => 'Voting is currently closed.' ), 400 );
		}

		if ( empty( $poll['month'] ) || $month !== $poll['month'] ) {
			wp_send_json_error( array( 'message' => 'This poll has reset for a new month. Please refresh the page.' ), 409 );
		}

		$voter_hash = $this->visitor_hash( $poll );
		$voters     = isset( $poll['voters'] ) && is_array( $poll['voters'] ) ? $poll['voters'] : array();

		if ( isset( $voters[ $voter_hash ] ) || $this->visitor_has_vote_cookie( $poll ) ) {
			wp_send_json_success(
				array(
					'message' => 'You already voted in this month’s poll.',
					'html'    => $this->render_poll_block(),
				)
			);
		}

		$found = false;
		foreach ( $poll['options'] as $index => $option ) {
			if ( isset( $option['id'] ) && $option['id'] === $option_id ) {
				$poll['options'][ $index ]['votes'] = isset( $option['votes'] ) ? absint( $option['votes'] ) + 1 : 1;
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			wp_send_json_error( array( 'message' => 'Please choose a valid organization.' ), 400 );
		}

		$voters[ $voter_hash ] = current_time( 'timestamp' );
		$poll['voters']       = $voters;
		$poll['updated_at']   = current_time( 'mysql' );

		update_option( self::OPTION_CURRENT_POLL, $poll, false );
		$this->set_vote_cookie( $poll );

		wp_send_json_success(
			array(
				'message' => 'Thank you. Your vote has been recorded.',
				'html'    => $this->render_poll_block(),
			)
		);
	}

	private function vote_token( $month = null ) {
		$month = $month ? (string) $month : $this->current_ym();
		return hash_hmac( 'sha256', 'ionmd_vote|' . $month, wp_salt( 'nonce' ) );
	}

	private function visitor_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			if ( false !== strpos( $value, ',' ) ) {
				$value = trim( explode( ',', $value )[0] );
			}
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				return $value;
			}
		}
		return 'unknown';
	}

	private function visitor_hash( $poll ) {
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
		$month = isset( $poll['month'] ) ? (string) $poll['month'] : $this->current_ym();
		return hash_hmac( 'sha256', $this->visitor_ip() . '|' . $ua . '|' . $month, wp_salt( 'auth' ) );
	}

	private function vote_cookie_name( $poll ) {
		$month = isset( $poll['month'] ) ? preg_replace( '/[^0-9]/', '', (string) $poll['month'] ) : preg_replace( '/[^0-9]/', '', $this->current_ym() );
		return 'ionmd_voted_' . $month;
	}

	private function visitor_has_vote_cookie( $poll ) {
		$name = $this->vote_cookie_name( $poll );
		return ! empty( $_COOKIE[ $name ] );
	}

	private function set_vote_cookie( $poll ) {
		$name     = $this->vote_cookie_name( $poll );
		$expires  = strtotime( '+45 days' );
		$value    = wp_hash( $name . '|' . $this->visitor_hash( $poll ) );
		$secure   = is_ssl();
		$httponly = true;

		if ( ! headers_sent() ) {
			setcookie( $name, $value, $expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, $httponly );
			$_COOKIE[ $name ] = $value;
		}
	}

	private function visitor_has_voted( $poll ) {
		if ( $this->visitor_has_vote_cookie( $poll ) ) {
			return true;
		}

		$voters = isset( $poll['voters'] ) && is_array( $poll['voters'] ) ? $poll['voters'] : array();
		$hash   = $this->visitor_hash( $poll );
		return isset( $voters[ $hash ] );
	}

	private function poll_total_votes( $poll ) {
		$total = 0;
		if ( empty( $poll['options'] ) || ! is_array( $poll['options'] ) ) {
			return 0;
		}

		foreach ( $poll['options'] as $option ) {
			$total += isset( $option['votes'] ) ? absint( $option['votes'] ) : 0;
		}
		return $total;
	}

	private function get_donations() {
		$donations = get_option( self::OPTION_DONATIONS, array() );
		if ( ! is_array( $donations ) ) {
			return array();
		}

		usort(
			$donations,
			function ( $a, $b ) {
				return strcmp( isset( $b['month'] ) ? $b['month'] : '', isset( $a['month'] ) ? $a['month'] : '' );
			}
		);

		return $donations;
	}

	private function donations_by_month() {
		$by_month = array();
		foreach ( $this->get_donations() as $donation ) {
			if ( empty( $donation['month'] ) ) {
				continue;
			}
			$by_month[ $donation['month'] ] = $donation;
		}
		return $by_month;
	}

	private function pledged_to_date() {
		$current_year = $this->current_year();
		$actual       = 0;

		foreach ( $this->get_donations() as $donation ) {
			if ( empty( $donation['month'] ) || (int) substr( $donation['month'], 0, 4 ) !== $current_year ) {
				continue;
			}

			$actual += isset( $donation['amount'] ) ? absint( $donation['amount'] ) : 0;
		}

		return $actual;
	}

	private function tracker_months_for_current_year() {
		$settings = $this->get_settings();
		$start    = isset( $settings['campaign_start_month'] ) ? $this->parse_month( $settings['campaign_start_month'] ) : $this->current_ym();
		$current  = $this->current_ym();
		$year     = $this->current_year();
		$months   = array();

		/*
		 * The visible tracker must never hide a real donation record just because
		 * the campaign start month was left on the current month. This matters when
		 * a prior-month donation, such as June, is entered after the site has rolled
		 * into July.
		 */
		$start_mon = 1;
		if ( $start && (int) substr( $start, 0, 4 ) === $year ) {
			$start_mon = (int) substr( $start, 5, 2 );
		}

		foreach ( $this->get_donations() as $donation ) {
			if ( empty( $donation['month'] ) || (int) substr( $donation['month'], 0, 4 ) !== $year ) {
				continue;
			}

			$donation_mon = (int) substr( $donation['month'], 5, 2 );
			if ( $donation_mon > 0 ) {
				$start_mon = min( $start_mon, $donation_mon );
			}
		}

		$current_mon = (int) substr( $current, 5, 2 );
		for ( $m = $current_mon; $m >= $start_mon; $m-- ) {
			$months[] = sprintf( '%04d-%02d', $year, $m );
		}

		/*
		 * Safety net: include any recorded current-year donation month even if it
		 * falls outside the normal month loop for any reason.
		 */
		foreach ( $this->get_donations() as $donation ) {
			if ( empty( $donation['month'] ) || (int) substr( $donation['month'], 0, 4 ) !== $year ) {
				continue;
			}

			if ( ! in_array( $donation['month'], $months, true ) ) {
				$months[] = $donation['month'];
			}
		}

		rsort( $months );

		return array_values( array_unique( $months ) );
	}

	private function sanitize_receipt_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}

		return $url;
	}

	private function receipt_url_for_donation( $donation ) {
		if ( ! is_array( $donation ) ) {
			return '';
		}

		if ( ! empty( $donation['receipt_url'] ) ) {
			$receipt_url = $this->sanitize_receipt_url( $donation['receipt_url'] );
			if ( $receipt_url ) {
				return $receipt_url;
			}
		}

		return $this->default_receipt_url_for_donation( $donation );
	}

	private function default_receipt_url_for_donation( $donation ) {
		$month        = isset( $donation['month'] ) ? (string) $donation['month'] : '';
		$organization = isset( $donation['organization'] ) ? strtolower( remove_accents( (string) $donation['organization'] ) ) : '';

		if ( '2026-06' === $month && false !== strpos( $organization, 'st. jude' ) ) {
			return plugin_dir_url( __FILE__ ) . 'assets/receipts/ionresearchgiftreceipt-june-2026.pdf';
		}

		if ( '2026-06' === $month && false !== strpos( $organization, 'st jude' ) ) {
			return plugin_dir_url( __FILE__ ) . 'assets/receipts/ionresearchgiftreceipt-june-2026.pdf';
		}

		return '';
	}

	private function money( $amount ) {
		return '$' . number_format_i18n( absint( $amount ) );
	}
}

Ion_Mission_Donations::instance();
