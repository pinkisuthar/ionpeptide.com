<?php

namespace WPForms\Admin;

use WPForms\Migrations\Tasks\AntiSpamUpgradeTask;

/**
 * Legacy anti-spam upgrade notices.
 *
 * @since 2.0.3
 */
class AntiSpamUpgrade {

	/**
	 * Query arg carrying a one-click upgrade request.
	 *
	 * @since 2.0.3
	 */
	private const ACTION_ARG = 'wpforms_antispam_upgrade';

	/**
	 * Query arg set on the redirect after a one-click upgrade started.
	 *
	 * @since 2.0.3
	 */
	private const STARTED_ARG = 'wpforms_antispam_upgrade_started';

	/**
	 * Spam documentation URL used by both notice variants.
	 *
	 * @since 2.0.3
	 */
	private const DOCS_URL = 'https://wpforms.com/docs/how-to-prevent-spam-in-wpforms/';

	/**
	 * Upper bound on the forms listed in the notice.
	 *
	 * @since 2.0.3
	 */
	private const MAX_LISTED_FORMS = 100;

	/**
	 * Shared CSS class of both notice variants.
	 *
	 * @since 2.0.3
	 */
	private const NOTICE_CLASS = 'wpforms-notice-antispam-upgrade';

	/**
	 * Init class.
	 *
	 * @since 2.0.3
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 2.0.3
	 */
	private function hooks(): void {

		add_action( 'admin_init', [ $this, 'maybe_start_upgrade' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'admin_notices', [ $this, 'maybe_add_notice' ] );
	}

	/**
	 * Re-run the upgrade over the forms the retention gate skipped.
	 *
	 * @since 2.0.3
	 */
	public function maybe_start_upgrade(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::ACTION_ARG ] ) ) {
			return;
		}

		// This rewrites every legacy form on the site regardless of author, so a form-scoped or category capability is not enough.
		if ( ! current_user_can( wpforms_get_capability_manage_options() ) ) {
			wp_die( esc_html__( 'You do not have permission to upgrade anti-spam protection.', 'wpforms-lite' ) );
		}

		check_admin_referer( self::ACTION_ARG );

		$task = wpforms()->obj( 'antispam_upgrade_task' );

		if ( $task ) {
			$task->force();
		}

		wp_safe_redirect(
			add_query_arg(
				self::STARTED_ARG,
				1,
				remove_query_arg( [ self::ACTION_ARG, '_wpnonce' ] )
			)
		);

		exit;
	}

	/**
	 * Add the notice matching the state of the last upgrade run.
	 *
	 * @since 2.0.3
	 */
	public function maybe_add_notice(): void {

		$stats = AntiSpamUpgradeTask::get_stats();

		switch ( $this->get_notice_variant( $stats ) ) {
			case 'started':
				$this->add_started_notice();
				break;

			case 'action_needed':
				$this->add_action_needed_notice( $stats['skipped'] );
				break;

			case 'upgraded':
				$this->add_upgraded_notice( $stats['upgraded_total'] );
				break;
		}
	}

	/**
	 * Load the notice styles, which admin.css carries only on the plugin's own screens.
	 *
	 * @since 2.0.3
	 */
	public function enqueue_styles(): void {

		if ( ! $this->get_notice_variant( AntiSpamUpgradeTask::get_stats() ) ) {
			return;
		}

		wp_enqueue_style(
			'wpforms-antispam-upgrade-notice',
			WPFORMS_PLUGIN_URL . 'assets/css/admin/antispam-upgrade-notice' . wpforms_get_min_suffix() . '.css',
			[],
			WPFORMS_VERSION
		);
	}

	/**
	 * Determine which notice the state of the last upgrade run calls for.
	 *
	 * @since 2.0.3
	 *
	 * @param array $stats Run counters.
	 *
	 * @return string Variant name, or an empty string when no notice is due.
	 */
	private function get_notice_variant( array $stats ): string {

		if ( ! wpforms_current_user_can( 'edit_forms' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::STARTED_ARG ] ) ) {
			return 'started';
		}

		if ( ! $stats['completed'] ) {
			return '';
		}

		if ( $stats['skipped'] > 0 ) {
			return 'action_needed';
		}

		return $stats['upgraded_total'] > 0 ? 'upgraded' : '';
	}

	/**
	 * Confirm a one-click upgrade was queued.
	 *
	 * @since 2.0.3
	 */
	private function add_started_notice(): void {

		Notice::info(
			esc_html__( 'Upgrading your forms to modern anti-spam protection. This runs in the background and may take a few minutes.', 'wpforms-lite' )
		);
	}

	/**
	 * Report a completed automatic upgrade.
	 *
	 * @since 2.0.3
	 *
	 * @param int $count Number of upgraded forms.
	 */
	private function add_upgraded_notice( int $count ): void {

		$forms = $this->get_upgraded_forms();

		// The number the user clicks has to be the length of the list it opens, so
		// the run counter is only the fallback for when no form carries the stamp.
		$count = $forms ? count( $forms ) : $count;

		$count_label = sprintf( /* translators: %d - number of forms upgraded to modern anti-spam protection. */
			_n( '%d form', '%d forms', $count, 'wpforms-lite' ),
			$count
		);

		// The count is the disclosure control for the form list, so it stays plain
		// text when no form title survived to list.
		// The notice renders on every admin screen, while admin.css only loads on the
		// plugin's own, so core's button-link keeps the control looking like a link
		// where the plugin styles are absent.
		$count_html = $forms
			? sprintf(
				'<button type="button" class="button-link wpforms-notice-antispam-upgrade-toggle" aria-expanded="false">%s</button>',
				esc_html( $count_label )
			)
			: esc_html( $count_label );

		$body = sprintf( /* translators: %s - number of upgraded forms, for example "3 forms". */
			_n(
				'We upgraded %s from legacy anti-spam to modern anti-spam protection, which blocks significantly more spam. The form&#8217;s previous version is saved as a revision you can restore anytime.',
				'We upgraded %s from legacy anti-spam to modern anti-spam protection, which blocks significantly more spam. Each form&#8217;s previous version is saved as a revision you can restore anytime.',
				$count,
				'wpforms-lite'
			),
			$count_html
		);

		$message = sprintf(
			'<p><strong>%1$s</strong></p><p>%2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>%5$s',
			esc_html__( 'Your Forms Now Use Modern Anti-Spam Protection', 'wpforms-lite' ),
			$body,
			esc_url( $this->get_docs_url() ),
			esc_html__( 'Learn More', 'wpforms-lite' ),
			$this->get_upgraded_forms_list( $forms )
		);

		Notice::info(
			$message,
			[
				'dismiss' => Notice::DISMISS_GLOBAL,
				'slug'    => 'antispam_upgrade_complete',
				'autop'   => false,
				'class'   => self::NOTICE_CLASS,
			]
		);
	}

	/**
	 * Get the forms the migration stamped as upgraded.
	 *
	 * @since 2.0.3
	 *
	 * @return array Array of WP_Post objects.
	 */
	private function get_upgraded_forms(): array {

		// Explicit type and status, so the list is the stamped set on every admin page:
		// the handler defaults to published forms only, and the Forms Overview filters
		// reshape its query. 'any' leaves out trash and auto-drafts, like the cohort.
		$forms = wpforms()->obj( 'form' )->get(
			'',
			[
				'meta_key'       => AntiSpamUpgradeTask::UPGRADED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'post_type'      => [ 'wpforms', 'wpforms-template' ],
				'post_status'    => 'any',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'nopaging'       => false,
				'posts_per_page' => self::MAX_LISTED_FORMS,
			]
		);

		return is_array( $forms ) ? $forms : [];
	}

	/**
	 * Build the collapsed list of upgraded forms.
	 *
	 * @since 2.0.3
	 *
	 * @param array $forms Array of WP_Post objects.
	 *
	 * @return string
	 */
	private function get_upgraded_forms_list( array $forms ): string {

		if ( ! $forms ) {
			return '';
		}

		$items = '';

		foreach ( $forms as $form ) {
			$items .= sprintf(
				'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></li>',
				esc_url( $this->get_form_settings_url( (int) $form->ID ) ),
				esc_html( wpforms_get_post_title( $form ) )
			);
		}

		return sprintf(
			'<ul class="ul-disc wpforms-notice-antispam-upgrade-forms" hidden>%s</ul>',
			$items
		);
	}

	/**
	 * Get the URL of a form's anti-spam settings.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return string
	 */
	private function get_form_settings_url( int $form_id ): string {

		return add_query_arg(
			[
				'page'    => 'wpforms-builder',
				'view'    => 'settings',
				'section' => 'anti_spam',
				'form_id' => $form_id,
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Prompt for consent on installs that cannot hold a rollback revision.
	 *
	 * @since 2.0.3
	 *
	 * @param int $count Number of skipped forms.
	 */
	private function add_action_needed_notice( int $count ): void {

		$body = sprintf( /* translators: %d - number of forms still using legacy anti-spam protection. */
			_n(
				'%d form still uses legacy anti-spam protection.',
				'%d forms still use legacy anti-spam protection.',
				$count,
				'wpforms-lite'
			),
			$count
		);

		$upgrade_button = '';

		// The action rewrites every legacy form on the site, so a form editor gets
		// the warning without a button that would only fail the capability check.
		if ( current_user_can( wpforms_get_capability_manage_options() ) ) {
			$upgrade_button = sprintf(
				'<a href="%1$s" class="button button-primary">%2$s</a>',
				esc_url( wp_nonce_url( add_query_arg( self::ACTION_ARG, 1 ), self::ACTION_ARG ) ),
				esc_html__( 'Upgrade All Forms', 'wpforms-lite' )
			);
		}

		$message = sprintf(
			'<p><strong>%1$s</strong></p><p>%2$s</p><div class="wpforms-notice-antispam-upgrade-buttons">%3$s<a href="%4$s" class="button" target="_blank" rel="noopener noreferrer">%5$s</a></div>',
			esc_html__( 'Your Forms Are Using Outdated Anti-Spam Protection', 'wpforms-lite' ),
			esc_html( $body ),
			$upgrade_button,
			esc_url( $this->get_docs_url() ),
			esc_html__( 'Learn More', 'wpforms-lite' )
		);

		Notice::warning(
			$message,
			[
				'dismiss' => Notice::DISMISS_GLOBAL,
				'slug'    => 'antispam_upgrade_action_needed',
				'autop'   => false,
				'class'   => self::NOTICE_CLASS,
			]
		);
	}

	/**
	 * Get the tracked documentation URL.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	private function get_docs_url(): string {

		return wpforms_utm_link( self::DOCS_URL, 'Anti-Spam Upgrade Notice', 'Learn More' );
	}
}
