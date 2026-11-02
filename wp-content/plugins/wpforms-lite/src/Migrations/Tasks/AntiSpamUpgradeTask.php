<?php

namespace WPForms\Migrations\Tasks;

use WP_Post;
use WPForms\Forms\AntiSpam;

/**
 * Legacy anti-spam upgrade task.
 *
 * Upgrades every form still protected by the legacy honeypot or the legacy
 * token to modern anti-spam, capturing the pre-upgrade state as a form
 * revision first.
 *
 * @since 2.0.3
 */
class AntiSpamUpgradeTask extends UpgradeBaseTask {

	/**
	 * Option holding the run counters.
	 *
	 * @since 2.0.3
	 */
	public const STATS_OPTION = 'wpforms_antispam_upgrade';

	/**
	 * Option set when an admin consented to upgrading the skipped forms.
	 *
	 * @since 2.0.3
	 */
	public const FORCE_OPTION = 'wpforms_antispam_upgrade_force';

	/**
	 * Post meta stamped on every upgraded form.
	 *
	 * @since 2.0.3
	 */
	public const UPGRADED_META = '_wpforms_antispam_upgraded';

	/**
	 * Post meta holding the map of auto-saved revision IDs to their source.
	 *
	 * @since 2.0.3
	 */
	public const REVISION_MAP_META = '_wpforms_ai_revisions';

	/**
	 * Source key identifying this task's checkpoint revisions.
	 *
	 * @since 2.0.3
	 */
	public const REVISION_SOURCE = 'antispam_upgrade';

	/**
	 * Number of forms handled per Action Scheduler action.
	 *
	 * @since 2.0.3
	 */
	private const CHUNK_SIZE = 50;

	/**
	 * Revision rows a single form upgrade needs.
	 *
	 * Two are written, the checkpoint and the current-version mirror. With
	 * fewer slots the trim inside wp_save_post_revision() deletes the oldest
	 * row, which is the checkpoint, and the rollback is silently lost.
	 *
	 * @since 2.0.3
	 */
	private const REQUIRED_REVISION_SLOTS = 2;

	/**
	 * Priority the link rel neutralizer is registered at.
	 *
	 * @since 2.0.3
	 */
	private const LINK_REL_PRIORITY = 50;

	/**
	 * Counter defaults, also the zeroed shape a batch accumulates deltas into.
	 *
	 * @since 2.0.3
	 */
	private const STATS_DEFAULTS = [
		'upgraded_total'    => 0,
		'upgraded_honeypot' => 0,
		'upgraded_token'    => 0,
		'skipped'           => 0,
		'completed'         => 0,
	];

	/**
	 * Whether the retention gate is waived because an admin consented.
	 *
	 * @since 2.0.3
	 *
	 * @var bool
	 */
	private $is_forced = false;

	/**
	 * Run counters, loaded per batch and flushed once.
	 *
	 * @since 2.0.3
	 *
	 * @var array
	 */
	private $stats = [];

	/**
	 * Form IDs handled in the current batch, keyed by outcome.
	 *
	 * @since 2.0.3
	 *
	 * @var array
	 */
	private $batch_forms = [];

	/**
	 * Content filters removed for the duration of a write, as [ callback, priority ] pairs.
	 *
	 * @since 2.0.3
	 *
	 * @var array
	 */
	private $removed_filters = [];

	/**
	 * Get the run counters.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	public static function get_stats(): array {

		$stats = wp_parse_args(
			(array) get_option( self::STATS_OPTION, [] ),
			self::STATS_DEFAULTS
		);

		return array_map( 'intval', $stats );
	}

	/**
	 * Take a form off the skipped count once its legacy protection was turned off by hand.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id Form ID.
	 */
	public static function release_skipped( int $form_id ): void {

		$stats = self::get_stats();

		// An upgraded form restored to legacy protection was never in the count.
		if ( ! $stats['completed'] || $stats['skipped'] < 1 || get_post_meta( $form_id, self::UPGRADED_META, true ) ) {
			return;
		}

		--$stats['skipped'];

		update_option( self::STATS_OPTION, $stats, false );
	}

	/**
	 * Re-arm the task after an admin consented to upgrading the skipped forms.
	 *
	 * @since 2.0.3
	 */
	public function force(): void {

		update_option( self::FORCE_OPTION, true, false );

		$stats = self::get_stats();

		$stats['skipped']   = 0;
		$stats['completed'] = 0;

		update_option( self::STATS_OPTION, $stats, false );

		$this->update_status( self::START );
	}

	/**
	 * Set task properties.
	 *
	 * @since 2.0.3
	 */
	protected function set_task_properties(): void {

		$this->is_forced = (bool) get_option( self::FORCE_OPTION, false );
	}

	/**
	 * Enumerate the candidate forms and queue them in batches.
	 *
	 * @since 2.0.3
	 */
	protected function init_migration(): void {

		$form_ids = $this->get_candidate_form_ids();

		if ( ! $form_ids ) {
			$this->finish_migration();

			return;
		}

		foreach ( array_chunk( $form_ids, self::CHUNK_SIZE ) as $chunk ) {
			$this->create_task( $chunk );
		}
	}

	/**
	 * Process one batch of forms.
	 *
	 * The batch counts into a zeroed set and applies the result as deltas, so one
	 * option write covers the whole batch instead of one per form. The flush is
	 * still a read-modify-write, so two Action Scheduler batches running
	 * concurrently can lose one set of counters. The counters only drive the
	 * admin notice wording, so an undercount costs a notice, never a form.
	 *
	 * @since 2.0.3
	 *
	 * @param array $data Form IDs.
	 */
	protected function process_migration( array $data ): void {

		$this->stats       = self::STATS_DEFAULTS;
		$this->batch_forms = [
			'upgraded' => [],
			'skipped'  => [],
		];

		foreach ( $data as $form_id ) {
			$this->process_form( (int) $form_id );
		}

		$upgraded = $this->batch_forms['upgraded'];
		$skipped  = $this->batch_forms['skipped'];

		// Derived from the ID lists rather than counted alongside them, so the
		// numbers the notice reports cannot disagree with the logged forms.
		$this->stats['upgraded_total'] = count( $upgraded );
		$this->stats['skipped']        = count( $skipped );

		$stats = self::get_stats();

		foreach ( $this->stats as $key => $delta ) {
			$stats[ $key ] += $delta;
		}

		update_option( self::STATS_OPTION, $stats, false );

		$message = 'Legacy anti-spam upgrade: ';

		$message .= $upgraded
			? sprintf( '%1$d form(s) upgraded, IDs %2$s.', count( $upgraded ), implode( ', ', $upgraded ) )
			: 'no forms upgraded.';

		if ( $skipped ) {
			$message .= sprintf( ' Skipped %1$d, IDs %2$s.', count( $skipped ), implode( ', ', $skipped ) );
		}

		// The 'Migration' title groups the record with the versioned chain's own entries.
		wpforms_log(
			'Migration',
			$message,
			[
				'type'  => 'log',
				'force' => true,
			]
		);
	}

	/**
	 * Stamp the completion time and clear the consent flag.
	 *
	 * The base class deletes its status option inside is_completed(), so
	 * completion cannot be read back from it.
	 *
	 * @since 2.0.3
	 */
	protected function finish_migration(): void {

		$stats = self::get_stats();

		$stats['completed'] = time();

		update_option( self::STATS_OPTION, $stats, false );

		delete_option( self::FORCE_OPTION );

		parent::finish_migration();
	}

	/**
	 * Get the IDs of forms that may carry legacy anti-spam.
	 *
	 * The LIKE pair is a superset filter only. wpforms_encode() slashes its
	 * JSON, unslashed back by wp_update_post() on storage, so stored bytes
	 * have no spaces and "antispam_v3":"1" cannot match "antispam":"1".
	 * AntiSpam::has_legacy_protection() stays the authority per form.
	 *
	 * Trashed forms stay out: the notice lists every upgraded form with a link to
	 * its builder, and a trashed form cannot be opened there. Auto-drafts stay out
	 * too, since wp_save_post_revision() refuses them a checkpoint and they would
	 * land in the skipped count that raises the action-needed notice.
	 *
	 * @since 2.0.3
	 *
	 * @return array
	 */
	private function get_candidate_form_ids(): array {

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts
					WHERE post_type IN ( 'wpforms', 'wpforms-template' )
						AND post_status NOT IN ( 'trash', 'auto-draft' )
						AND (
							post_content LIKE %s
							OR post_content LIKE %s
						)
					ORDER BY ID",
				'%' . $wpdb->esc_like( '"honeypot":"1"' ) . '%',
				'%' . $wpdb->esc_like( '"antispam":"1"' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Upgrade one form, if every gate allows it.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id Form ID.
	 */
	private function process_form( int $form_id ): void {

		$post = get_post( $form_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// json_decode() rather than wpforms_decode(): that one ends in wp_unslash(),
		// which strips a literal backslash out of every value, and the rewrite below
		// would store the stripped copy. Damaged content decodes to null, which casts
		// to an empty array and fails the legacy check.
		$form_data = (array) json_decode( $post->post_content, true );

		if ( ! AntiSpam::has_legacy_protection( $form_data ) ) {
			return;
		}

		if ( ! AntiSpam::is_auto_upgrade_allowed( $form_id, $form_data ) ) {
			return;
		}

		if ( ! $this->is_forced && ! $this->has_revision_slots( $post ) ) {
			$this->batch_forms['skipped'][] = $form_id;

			return;
		}

		$this->commit_upgrade( $post, $form_data );
	}

	/**
	 * Capture the rollback point, write the upgrade, and record it.
	 *
	 * Nothing is counted or marked unless the write actually landed, otherwise a
	 * failed form would be reported as upgraded and its builder note would read as
	 * a deliberate user restore.
	 *
	 * A form whose checkpoint could not be written is left alone on an automatic
	 * run, since the completion notice promises a restorable revision.
	 *
	 * @since 2.0.3
	 *
	 * @param WP_Post $post      Form post as it is before the upgrade.
	 * @param array   $form_data Form data as it is before the upgrade.
	 */
	private function commit_upgrade( WP_Post $post, array $form_data ): void {

		$form_id        = (int) $post->ID;
		$has_checkpoint = $this->save_checkpoint( $post );

		// A forced run is the consented path for installs that cannot hold the
		// rollback, so only the automatic run insists on the checkpoint.
		if ( ! $has_checkpoint && ! $this->is_forced ) {
			$this->batch_forms['skipped'][] = $form_id;

			return;
		}

		if ( ! $this->write_form_data( $form_id, $form_data ) ) {
			// Recorded so a failed write is observable and the notice offers the retry,
			// instead of the form silently dropping out of the run.
			$this->batch_forms['skipped'][] = $form_id;

			return;
		}

		// Counted from the pre-write data, which still carries the legacy keys the
		// cohort counters read.
		$this->count_cohorts( $form_data );

		$this->batch_forms['upgraded'][] = $form_id;

		update_post_meta( $form_id, self::UPGRADED_META, time() );
	}

	/**
	 * Determine whether the site retains enough revisions for a rollback.
	 *
	 * @since 2.0.3
	 *
	 * @param WP_Post $post Form post.
	 *
	 * @return bool
	 */
	private function has_revision_slots( WP_Post $post ): bool {

		$revisions_to_keep = wp_revisions_to_keep( $post );

		// A negative value means unlimited.
		return $revisions_to_keep < 0 || $revisions_to_keep >= self::REQUIRED_REVISION_SLOTS;
	}

	/**
	 * Increment the per-cohort counters. A form can belong to both.
	 *
	 * @since 2.0.3
	 *
	 * @param array $form_data Form data.
	 */
	private function count_cohorts( array $form_data ): void {

		$settings = $form_data['settings'] ?? [];

		if ( ( $settings['honeypot'] ?? '' ) === '1' ) {
			++$this->stats['upgraded_honeypot'];
		}

		if ( ( $settings['antispam'] ?? '' ) === '1' ) {
			++$this->stats['upgraded_token'];
		}
	}

	// phpcs:disable WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks
	/**
	 * Capture the pre-upgrade state as a form revision.
	 *
	 * @since 2.0.3
	 *
	 * @param WP_Post $post Form post.
	 *
	 * @return bool Whether the rollback point exists.
	 */
	private function save_checkpoint( WP_Post $post ): bool {

		$callback = [ $this, 'filter_force_revision' ];

		// Without this the dedup check skips the checkpoint whenever the newest
		// revision already matches the form, which is the common case.
		add_filter( 'wp_save_post_revision_check_for_changes', $callback );

		// _wp_put_post_revision() stores the copy through wp_insert_post(), which
		// runs content_save_pre, so kses rewrites the JSON of the very revision the
		// rollback restores: escaped quotes come back unescaped and the row stops
		// being valid JSON. The mirror revision escapes this because post_updated
		// fires while write_form_data() already has the filters off.
		$this->remove_content_filters();

		try {
			$revision_id = wp_save_post_revision( $post->ID );
		} finally {
			$this->restore_content_filters();

			remove_filter( 'wp_save_post_revision_check_for_changes', $callback );
		}

		if ( ! $revision_id || is_wp_error( $revision_id ) ) {
			return false;
		}

		$revision_id = (int) $revision_id;

		$this->align_checkpoint_row( $revision_id, $post );
		$this->tag_revision( (int) $post->ID, $revision_id );

		return true;
	}
	// phpcs:enable WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks

	/**
	 * Skip the revision dedup check while the checkpoint is being written.
	 *
	 * A method rather than __return_false: WordPress keys a string callback by
	 * the function name, so removing __return_false would also drop an identical
	 * callback a third party registered at the same priority.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	public function filter_force_revision(): bool {

		return false;
	}

	/**
	 * Empty the link rel attribute while the form is being written.
	 *
	 * A method rather than __return_empty_string, for the same reason as
	 * filter_force_revision() above.
	 *
	 * @since 2.0.3
	 *
	 * @return string
	 */
	public function filter_link_rel(): string {

		return '';
	}

	/**
	 * Align the checkpoint row with the form state it captured.
	 *
	 * Two columns need correcting and wp_update_post() can set neither.
	 * post_author is not a revisioned field, so wp_insert_post() stamps the
	 * acting user, which under Action Scheduler is 0. post_modified is forced to
	 * the current time on every update, which leaves the checkpoint tied with the
	 * mirror revision written a fraction of a second later; the Revisions panel
	 * sorts on post_modified_gmt with a comparator that never returns 0, so a tie
	 * inverts the incoming order and hides the checkpoint instead of the mirror.
	 * Back-dating is also the honest value: the row holds the form as of its last
	 * real edit. A form last saved in the same second as its own migration write
	 * would still tie with the mirror, so that case is stepped back one more second.
	 *
	 * @since 2.0.3
	 *
	 * @param int     $revision_id Checkpoint revision ID.
	 * @param WP_Post $post        Form post as it was before the upgrade.
	 */
	private function align_checkpoint_row( int $revision_id, WP_Post $post ): void {

		global $wpdb;

		$modified     = $post->post_modified;
		$modified_gmt = $post->post_modified_gmt;

		// A form never published keeps a zeroed GMT date, and the panel sorts and
		// dates the checkpoint by that column.
		if ( $modified_gmt === '0000-00-00 00:00:00' ) {
			$modified_gmt = get_gmt_from_date( $modified );
		}

		// A form last saved in the same second as its own migration write would leave
		// the checkpoint tied with the mirror, and the panel's comparator resolves a
		// tie by hiding the checkpoint. A second earlier is still before the upgrade.
		if ( $modified_gmt === current_time( 'mysql', true ) ) {
			$modified     = gmdate( 'Y-m-d H:i:s', strtotime( $modified . ' +0000' ) - 1 );
			$modified_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $modified_gmt . ' +0000' ) - 1 );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			[
				'post_author'       => (int) $post->post_author,
				'post_modified'     => $modified,
				'post_modified_gmt' => $modified_gmt,
			],
			[ 'ID' => $revision_id ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		clean_post_cache( $revision_id );
	}

	/**
	 * Record the checkpoint revision so the Revisions panel can label it.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id     Form ID.
	 * @param int $revision_id Revision ID.
	 */
	private function tag_revision( int $form_id, int $revision_id ): void {

		$map = get_post_meta( $form_id, self::REVISION_MAP_META, true );

		// An absent meta key reads as '', and casting that to an array would seed
		// a stray 0 => '' entry into the map.
		$map = is_array( $map ) ? $map : [];

		// Compat: the legacy shape is a flat, unkeyed list of revision IDs.
		if ( $map && is_int( reset( $map ) ) ) {
			$map = array_fill_keys( array_map( 'absint', $map ), 'smart_edit' );
		}

		$map[ $revision_id ] = self::REVISION_SOURCE;

		// Drop entries for revisions WordPress has already rotated out, or the map
		// grows unbounded. Guaranteed to matter here: the task upgrades forms whose
		// revision limit can be as low as REQUIRED_REVISION_SLOTS.
		$existing_revision_ids = array_map( 'absint', wp_get_post_revisions( $form_id, [ 'fields' => 'ids' ] ) );

		$map = array_intersect_key( $map, array_flip( $existing_revision_ids ) );

		update_post_meta( $form_id, self::REVISION_MAP_META, $map );
	}

	/**
	 * Swap the legacy keys for modern anti-spam and store the form.
	 *
	 * Form_Handler::update() is deliberately not used: it runs
	 * map_deep( $data, 'wp_strip_all_tags' ) for any actor without
	 * unfiltered_html, which under Action Scheduler is every actor, and it
	 * injects setting defaults this migration must not change.
	 *
	 * @since 2.0.3
	 *
	 * @param int   $form_id   Form ID.
	 * @param array $form_data Form data.
	 *
	 * @return bool Whether the form was written successfully.
	 */
	private function write_form_data( int $form_id, array $form_data ): bool {

		// Switched off, not dropped: the builder renders a legacy toggle for every
		// key present, and that toggle is the rollback when no revision was kept.
		foreach ( AntiSpam::LEGACY_KEYS as $key ) {
			if ( isset( $form_data['settings'][ $key ] ) ) {
				$form_data['settings'][ $key ] = '0';
			}
		}

		$form_data['settings']['antispam_v3'] = '1';

		$this->remove_content_filters();

		try {
			// wpforms_encode() slashes on purpose and relies on wp_update_post()
			// unslashing again, so a direct $wpdb write would store damaged JSON.
			// post_updated then writes the current-version mirror revision, which is
			// stamped now and therefore sorts ahead of the back-dated checkpoint, so
			// the panel drops the mirror and lists the checkpoint.
			$updated = wp_update_post(
				[
					'ID'           => $form_id,
					'post_content' => wpforms_encode( $form_data ),
				]
			);
		} finally {
			$this->restore_content_filters();
		}

		return $updated && ! is_wp_error( $updated );
	}

	/**
	 * Remove the content filters that damage stored form JSON.
	 *
	 * @since 2.0.3
	 */
	private function remove_content_filters(): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks

		$this->removed_filters = [];

		// balanceTags rewrites tags inside field content.
		if ( remove_filter( 'content_save_pre', 'balanceTags', 50 ) ) {
			$this->removed_filters[] = [ 'balanceTags', 50 ];
		}

		// wp_filter_post_kses is registered for any actor without unfiltered_html.
		if ( remove_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			$this->removed_filters[] = [ 'wp_filter_post_kses', 10 ];
		}

		// wp_targeted_link_rel runs on content_save_pre and rewrites any field
		// content holding a target link, injecting unescaped double quotes that
		// damage the stored JSON. Emptying the rel value leaves the link intact.
		add_filter( 'wp_targeted_link_rel', [ $this, 'filter_link_rel' ], self::LINK_REL_PRIORITY );
	}

	/**
	 * Put back exactly the filters that were removed.
	 *
	 * @since 2.0.3
	 */
	private function restore_content_filters(): void { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks

		remove_filter( 'wp_targeted_link_rel', [ $this, 'filter_link_rel' ], self::LINK_REL_PRIORITY );

		foreach ( $this->removed_filters as $removed ) {
			[ $callback, $priority ] = $removed;

			add_filter( 'content_save_pre', $callback, $priority );
		}

		$this->removed_filters = [];
	}
}
