<?php
namespace WPForms\Emails;

use Exception;
use WPForms\Emails\Summaries\Data;
use WPForms\Emails\Summaries\Milestones;
use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Slot\Blocks\ActivityDropoff;
use WPForms\Emails\Summaries\Slot\Blocks\BaselineRecap;
use WPForms\Emails\Summaries\Slot\Blocks\FeatureSpotlight;
use WPForms\Emails\Summaries\Slot\Blocks\GetMoreResponses;
use WPForms\Emails\Summaries\Slot\Blocks\Headline;
use WPForms\Emails\Summaries\Slot\Blocks\LicenseNotice;
use WPForms\Emails\Summaries\Slot\Blocks\LiteConnectPrompt;
use WPForms\Emails\Summaries\Slot\Blocks\Milestone;
use WPForms\Emails\Summaries\Slot\Blocks\PaymentsEducation;
use WPForms\Emails\Summaries\Slot\Blocks\Tip;
use WPForms\Emails\Summaries\Slot\Context;
use WPForms\Emails\Summaries\Slot\Pipeline;
use WPForms\Emails\Summaries\Week;
use WPForms\Emails\Tasks\FetchInfoBlocksTask;
use WPForms\Tasks\Task;

/**
 * Email Summaries main class.
 *
 * @since 1.5.4
 */
class Summaries {

	/**
	 * Slot pipeline built for the current run, kept so the send can be recorded.
	 *
	 * @since 2.0.3
	 *
	 * @var Pipeline|null
	 */
	private $pipeline;

	/**
	 * Slot context built for the current run.
	 *
	 * @since 2.0.3
	 *
	 * @var Context|null
	 */
	private $context;

	/**
	 * Resolved slot blocks for the current run.
	 *
	 * @since 2.0.3
	 *
	 * @var array
	 */
	private $blocks = [];

	/**
	 * Notification blocks store built for the current run.
	 *
	 * @since 2.0.3
	 *
	 * @var NotificationBlocks|null
	 */
	private $notification_blocks;

	/**
	 * Constructor.
	 *
	 * @since 1.5.4
	 */
	public function __construct() {

		$this->hooks();

		$summaries_disabled = $this->is_disabled();

		if ( $summaries_disabled && wp_next_scheduled( 'wpforms_email_summaries_cron' ) ) {
			wp_clear_scheduled_hook( 'wpforms_email_summaries_cron' );
		}

		if ( ! $summaries_disabled && ! wp_next_scheduled( 'wpforms_email_summaries_cron' ) ) {
			// Since v1.9.1 we use a single event and manually reoccur it
			// because a recurring event cannot guarantee
			// its firing at the same time during WP_CLI execution.
			wp_schedule_single_event( $this->get_next_launch_time(), 'wpforms_email_summaries_cron' );
		}
	}

	/**
	 * Get the instance of a class and store it in itself.
	 *
	 * @since 1.5.4
	 */
	public static function get_instance() {

		static $instance;

		if ( ! $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Email Summaries hooks.
	 *
	 * @since 1.5.4
	 */
	public function hooks() {

		add_filter( 'wpforms_settings_defaults', [ $this, 'disable_summaries_setting' ] );
		add_action( 'wpforms_settings_updated', [ $this, 'deregister_fetch_info_blocks_task' ] );

		// Leave early if Email Summaries are disabled in settings.
		if ( $this->is_disabled() ) {
			return;
		}

		add_action( 'init', [ $this, 'preview' ] );
		add_action( 'wpforms_email_summaries_cron', [ $this, 'cron' ] );
		add_filter( 'wpforms_tasks_get_tasks', [ $this, 'register_fetch_info_blocks_task' ] );
	}

	/**
	 * Check if Email Summaries are disabled in settings.
	 *
	 * @since 1.5.4
	 *
	 * @return bool
	 */
	protected function is_disabled(): bool {

		/**
		 * Allows to modify whether Email Summaries are disabled in settings.
		 *
		 * @since 1.5.4
		 *
		 * @param bool $is_disabled True if Email Summaries are disabled in settings. False by default.
		 */
		return (bool) apply_filters( 'wpforms_emails_summaries_is_disabled', (bool) wpforms_setting( 'email-summaries-disable', false ) );
	}

	/**
	 * Add "Disable Email Summaries" to WPForms settings.
	 *
	 * @since 1.5.4
	 *
	 * @param array $settings WPForms settings.
	 *
	 * @return mixed
	 */
	public function disable_summaries_setting( $settings ) {

		/** This filter is documented in wpforms/src/Emails/Summaries.php */
		if ( (bool) apply_filters( 'wpforms_emails_summaries_is_disabled', false ) ) {
			return $settings;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'wpforms_email_template' => 'summary',
					'wpforms_email_preview'  => '1',
				],
				admin_url()
			),
			Preview::PREVIEW_NONCE_NAME
		);

		$desc = esc_html__( 'Disable Email Summaries weekly delivery.', 'wpforms-lite' );

		if ( ! $this->is_disabled() ) {
			$desc .= ' <a href="' . $url . '" target="_blank">' . esc_html__( 'View Email Summary Example', 'wpforms-lite' ) . '</a>.';
		}

		// Get the uninstall data setting.
		$uninstall_data = $settings['misc']['uninstall-data'];

		// Remove the uninstall data setting.
		unset( $settings['misc']['uninstall-data'] );

		// Add the email summaries setting.
		$settings['misc']['email-summaries-disable'] = [
			'id'     => 'email-summaries-disable',
			'name'   => esc_html__( 'Disable Email Summaries', 'wpforms-lite' ),
			'desc'   => $desc,
			'type'   => 'toggle',
			'status' => true,
		];

		// Add the uninstall data setting to the end.
		$settings['misc']['uninstall-data'] = $uninstall_data;

		return $settings;
	}

	/**
	 * Preview Email Summary.
	 *
	 * @since 1.5.4
	 */
	public function preview() {

		// Leave early if the current request is not a preview for the summaries email template.
		if ( ! $this->is_preview() ) {
			return;
		}

		$template = ( new Templates\Summary() )->set_args( $this->get_template_args( true ) );

		/**
		 * Filters the summaries email template.
		 *
		 * @since 1.5.4
		 *
		 * @param Templates\Summary $template Default summaries email template.
		 */
		$template = apply_filters( 'wpforms_emails_summaries_template', $template );

		$content = $template->get();

		if ( Helpers::is_plain_text_template() ) {
			$content = wpautop( $content );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $content;

		exit;
	}

	/**
	 * Get next cron occurrence date.
	 *
	 * @since 1.5.4
	 * @deprecated 1.9.1
	 *
	 * @return int
	 */
	protected function get_first_cron_date_gmt(): int {

		_deprecated_function( __METHOD__, '1.9.1 of the WPForms plugin', __CLASS__ . '::get_next_launch_time()' );

		return $this->get_next_launch_time();
	}

	/**
	 * Get next Monday 2p.m with WordPress offset.
	 *
	 * @since 1.9.1
	 *
	 * @return int
	 */
	protected function get_next_launch_time(): int {

		$datetime      = date_create( 'now', wp_timezone() );
		$now_plus_week = time() + constant( 'WEEK_IN_SECONDS' );

		if ( ! $datetime ) {
			return $now_plus_week;
		}

		$hours = 14;

		// If today is Monday and the current time is less than 2 p.m.,
		// we can launch the cron for today.
		if (
			(int) $datetime->format( 'N' ) !== 1 ||
			(int) $datetime->format( 'H' ) >= $hours
		) {
			try {
				$datetime->modify( 'next monday' );
			} catch ( Exception $e ) {
				return $now_plus_week;
			}
		}

		$datetime->setTime( $hours, 0 );

		$timestamp = $datetime->getTimestamp();

		return $timestamp > 0 ? $timestamp : $now_plus_week;
	}

	/**
	 * Add custom Email Summaries cron schedule.
	 *
	 * @since      1.5.4
	 * @deprecated 1.9.1
	 *
	 * @param array $schedules WP cron schedules.
	 *
	 * @return array
	 */
	public function add_weekly_cron_schedule( $schedules ) {

		_deprecated_function( __METHOD__, '1.9.1 of the WPForms plugin' );

		$schedules['wpforms_email_summaries_weekly'] = [
			'interval' => $this->get_next_launch_time() - time(),
			'display'  => esc_html__( 'Weekly WPForms Email Summaries', 'wpforms-lite' ),
		];

		return $schedules;
	}

	/**
	 * Email Summaries cron callback.
	 *
	 * @since 1.5.4
	 */
	public function cron() {

		$week    = Week::current();
		$data    = $this->get_data()->for_week( $week );
		$entries = $data['forms'];

		/**
		 * Allows skipping the cron early return when there are no entries.
		 *
		 * Pro features (e.g. re-engagement alerts) need the weekly email to send
		 * even when the site has zero entries.
		 *
		 * @since 1.10.1.1
		 *
		 * @param bool  $skip_empty Whether to skip sending when entries are empty. Default true.
		 * @param array $entries    Form entries.
		 */
		$skip_empty = (bool) apply_filters( 'wpforms_emails_summaries_cron_skip_empty', true, $entries );

		// Email won't be sent if there are no form entries.
		if ( empty( $entries ) && $skip_empty ) {
			return;
		}

		$args     = $this->get_template_args( false, $week, $data );
		$template = ( new Templates\Summary() )->set_args( $args );

		/** This filter is documented in preview() method above. */
		$template = apply_filters( 'wpforms_emails_summaries_template', $template );

		$content = $template->get();

		if ( ! $content ) {
			return;
		}

		$parsed_home_url = wp_parse_url( home_url() );
		$site_domain     = $parsed_home_url['host'];

		if ( is_multisite() && isset( $parsed_home_url['path'] ) ) {
			$site_domain .= $parsed_home_url['path'];
		}

		$subject = sprintf(
			/* translators: %s - site domain. */
			esc_html__( 'Your Weekly WPForms Summary for %s', 'wpforms-lite' ),
			$site_domain
		);

		/**
		 * Filters the summaries email subject.
		 *
		 * @since 1.5.4
		 *
		 * @param string $subject Default summaries email subject.
		 */
		$subject = apply_filters( 'wpforms_emails_summaries_cron_subject', $subject );

		/**
		 * Filters the summaries recipient email address.
		 *
		 * @since 1.5.4
		 *
		 * @param string $option Default summaries recipient email address.
		 */
		$to_email = apply_filters( 'wpforms_emails_summaries_cron_to_email', get_option( 'admin_email' ) );

		$sent = ( new Mailer() )
			->template( $template )
			->subject( $subject )
			->to_email( $to_email )
			->send();

		if ( $sent === true ) {
			$this->pipeline->on_sent( $this->blocks, $this->context );

			// Cache the notification block shown to avoid showing it again in the future.
			$this->notification_blocks->maybe_remember_shown_block( $args['body']['notification_block'] );
		}
	}

	/**
	 * Build the template arguments. Shared by the cron and the preview so the two never drift.
	 *
	 * @since 2.0.3
	 *
	 * @param bool       $is_preview Whether the email is being previewed.
	 * @param Week|null  $week       Reported week, when the caller already resolved it.
	 * @param array|null $data       Metrics from Data::for_week(), when the caller already loaded them.
	 *
	 * @return array
	 */
	private function get_template_args( bool $is_preview = false, ?Week $week = null, ?array $data = null ): array {

		$week       = $week ?? Week::current();
		$data       = $data ?? $this->get_data()->for_week( $week );
		$milestones = wpforms()->obj( 'emails_summaries_milestones' );

		// Before the blocks decide, whichever wins the top slot; never on a preview GET.
		if ( ! $is_preview && $milestones instanceof Milestones ) {
			$milestones->maybe_baseline( $data, $week );
		}

		$this->context             = new Context( $week, $data, $is_preview, $this->get_preview_param( 'wpforms_email_block' ), $this->get_preview_param( 'wpforms_email_variant' ) );
		$this->pipeline            = new Pipeline( $this->get_blocks() );
		$this->blocks              = $this->pipeline->resolve_all( $this->context );
		$this->notification_blocks = new NotificationBlocks();

		// Theme overrides of the pre-redesign body template still loop over these.
		$legacy_entries = [];

		foreach ( $data['forms'] as $form_id => $form ) {
			$form += [
				'title'    => '',
				'entries'  => 0,
				'edit_url' => '',
			];

			$legacy_entries[ $form_id ] = [
				'form_id'  => $form_id,
				'title'    => $form['title'],
				'count'    => $form['entries'],
				'edit_url' => $form['edit_url'],
				'trends'   => isset( $form['trends'] ) ? sprintf( '%+d%%', $form['trends'] ) : null,
			];
		}

		return [
			'body' => [
				'tiles'              => $this->get_tiles( $data ),
				'forms'              => $data['forms'],
				'columns'            => [
					'payments'   => $this->has_payments_row( $data ),
					'conversion' => ! empty( $data['conversion_allowed'] ),
					'locked'     => ! empty( $data['conversion_locked'] ),
				],
				'blocks'             => $this->blocks,
				'cta'                => $this->get_cta( $data ),
				'notification_block' => $this->notification_blocks->get_block(),
				'icons'              => $this->get_icons_url(),
				'entries'            => $legacy_entries,
				'has_trends'         => ! empty( array_filter( array_column( $legacy_entries, 'trends' ) ) ),
			],
		];
	}

	/**
	 * Primary call to action: All Forms, where the per-form Analytics links live, when the tier can open
	 * them; the WPForms Dashboard otherwise. Analytics has no site-wide page to link to.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week().
	 *
	 * @return array Keys `text`, `url`.
	 */
	private function get_cta( array $data ): array {

		$has_analytics = wpforms()->is_pro() && ! empty( $data['conversion_allowed'] );

		return [
			'text' => $has_analytics ? __( 'View Full Analytics', 'wpforms-lite' ) : __( 'View Your Dashboard', 'wpforms-lite' ),
			'url'  => AbstractBlock::admin_utm_url(
				'admin.php?page=' . ( $has_analytics ? 'wpforms-overview' : 'wpforms-dashboard' ),
				$has_analytics ? 'view-full-analytics' : 'view-dashboard'
			),
		];
	}

	/**
	 * Stat tile rows: entries or backups, views, spam or leads; then payments, sales, refunds on Pro when a gateway is connected.
	 *
	 * Each tile: `label`, `value` (formatted), `delta` (percent or null), `note`.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week().
	 *
	 * @return array
	 */
	private function get_tiles( array $data ): array {

		$first = $data['backups'] === null
			? $this->tile( __( 'Entries', 'wpforms-lite' ), $data['entries'], $data['entries_delta'] )
			: $this->tile( __( 'Entry Backups', 'wpforms-lite' ), $data['backups'] );

		$row = [
			$first,
			$this->tile( __( 'Views', 'wpforms-lite' ), $data['views'], $data['views_delta'] ),
		];

		// Free Lite records neither blocked spam nor leads, so its row has two tiles.
		if ( $data['leads_saved'] !== null ) {
			$row[] = $this->tile( __( 'Leads Saved', 'wpforms-lite' ), $data['leads_saved'] );
		} elseif ( $data['spam'] !== null ) {
			$spam_tile = $this->tile( __( 'Spam', 'wpforms-lite' ), $data['spam'] );

			if ( isset( $data['lifetime_spam'] ) ) {
				/* translators: %s - number of spam entries ever blocked. */
				$spam_tile['note'] = sprintf( __( '%s Total', 'wpforms-lite' ), number_format_i18n( (int) $data['lifetime_spam'] ) );
			}

			$row[] = $spam_tile;
		}

		$rows = [ $row ];

		if ( $this->has_payments_row( $data ) ) {
			$rows[] = [
				$this->tile( __( 'Payments', 'wpforms-lite' ), $data['payments'], $data['payments_delta'] ),
				$this->tile( __( 'Sales', 'wpforms-lite' ), wpforms_format_amount( (float) $data['sales'], true ), $data['sales_delta'] ),
				// No trend: this is a count, while the aggregator's delta tracks the amount.
				$this->tile( __( 'Refunds', 'wpforms-lite' ), $data['refunds'] ),
			];
		}

		return $rows;
	}

	/**
	 * Build one tile.
	 *
	 * @since 2.0.3
	 *
	 * @param string     $label Tile label.
	 * @param int|string $value Count, or an already formatted amount.
	 * @param int|null   $delta Week-over-week percent, null to omit.
	 *
	 * @return array
	 */
	private function tile( string $label, $value, ?int $delta = null ): array {

		return [
			'label'  => $label,
			'value'  => is_int( $value ) ? number_format_i18n( $value ) : (string) $value,
			'delta'  => $delta,
			// Shown whenever the tile has no trend, so every number carries a line under it.
			'note'   => '--',
			// Formatted amounts run longer than counts, so the template sizes them down.
			'amount' => ! is_int( $value ),
		];
	}

	/**
	 * Whether the payments tiles and column show: a gateway is connected and a payment has been collected.
	 *
	 * Both editions, the payments table exists on Lite too. A connected gateway with no payments yet
	 * gets the educational block instead, never a zeros row.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Metrics from Data::for_week().
	 *
	 * @return bool
	 */
	private function has_payments_row( array $data ): bool {

		return ! empty( $data['has_gateway'] ) && ! empty( $data['has_payments'] );
	}

	/**
	 * Metrics source. The registry resolves the Lite or Pro implementation.
	 *
	 * @since 2.0.3
	 *
	 * @return Data
	 */
	private function get_data(): Data {

		return Data::resolve();
	}

	/**
	 * Blocks competing for the email slots.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	protected function get_blocks(): array {

		/**
		 * Filters the blocks competing for the weekly summary slots.
		 *
		 * @since 2.0.3
		 *
		 * @param array $blocks Block instances implementing the Block interface.
		 */
		return (array) apply_filters(
			'wpforms_emails_summaries_get_blocks',
			[
				new LicenseNotice(),
				new ActivityDropoff(),
				new Milestone(),
				new Headline(),
				new BaselineRecap(),
				new PaymentsEducation(),
				new LiteConnectPrompt(),
				new GetMoreResponses(),
				new Tip(),
				new FeatureSpotlight(),
			]
		);
	}

	/**
	 * A preview URL parameter, e.g. the block or variant to force. Nonce checked in is_preview().
	 *
	 * @since 2.0.3
	 *
	 * @param string $name Query parameter name.
	 *
	 * @return string
	 */
	private function get_preview_param( string $name ): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_key( wp_unslash( $_GET[ $name ] ?? '' ) );
	}

	/**
	 * Register Action Scheduler task to fetch and cache Info Blocks.
	 *
	 * @since 1.6.4
	 *
	 * @param Task[] $tasks List of task classes.
	 *
	 * @return array
	 */
	public static function register_fetch_info_blocks_task( $tasks ): array {

		$tasks[] = FetchInfoBlocksTask::class;

		return $tasks;
	}

	/**
	 * Deregister Action Scheduler task to fetch and cache Info Blocks.
	 *
	 * @since 1.6.4
	 */
	public function deregister_fetch_info_blocks_task() {

		if ( ! $this->is_disabled() ) {
			return;
		}

		// Deregister the task.
		( new FetchInfoBlocksTask() )->cancel();

		// Delete last run time record.
		delete_option( FetchInfoBlocksTask::LAST_RUN );

		// Remove the cache file if it exists.
		$file_name = ( new InfoBlocks() )->get_cache_file_path();

		if ( file_exists( $file_name ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $file_name );
		}
	}

	/**
	 * Check if the current request is a preview for the summaries email template.
	 *
	 * @since 1.8.8
	 *
	 * @return bool
	 */
	private function is_preview(): bool {

		// Leave if the current user can't access.
		if ( ! wpforms_current_user_can() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Leave early if nonce verification failed.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), Preview::PREVIEW_NONCE_NAME ) ) {
			return false;
		}

		// Leave early if preview is not requested.
		if ( ! isset( $_GET['wpforms_email_preview'], $_GET['wpforms_email_template'] ) ) {
			return false;
		}

		// Leave early if preview is not requested for the summaries template.
		if ( $_GET['wpforms_email_template'] !== 'summary' ) {
			return false;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return true;
	}

	/**
	 * Get icons URL.
	 * Primarily used in the HTML version of the email template.
	 *
	 * @since 1.8.8
	 *
	 * @return array
	 */
	private function get_icons_url(): array {

		$base_url = WPFORMS_PLUGIN_URL . 'assets/images/email/';

		return [
			'upward'             => $base_url . 'icon-upward.png',
			'downward'           => $base_url . 'icon-downward.png',
			'notification_block' => $base_url . 'notification-block-icon.png',
			'locked'             => $base_url . 'icon-locked-conversion.png',
		];
	}
}
