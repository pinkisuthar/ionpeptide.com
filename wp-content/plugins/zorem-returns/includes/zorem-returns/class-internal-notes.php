<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
/**
 * Internal Admin Notes per Return Request
 *
 * Private notes left by support staff on a return request, for team
 * coordination and decision tracking. Customers never see these.
 *
 * Supports:
 *  - Multiple threaded notes per request
 *  - Author + timestamp on each note
 *  - @mention of other admin team members (email notification on mention)
 *  - Author or admin can delete a note
 *
 * @package Zorem_Returns_Exchanges
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Internal_Notes {

	/**
	 * Singleton instance.
	 *
	 * @var Return_Internal_Notes
	 */
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->init();
	}

	public function init() {
		add_action( 'wp_ajax_zorem_add_internal_note', array( $this, 'ajax_add_note' ) );
		add_action( 'wp_ajax_zorem_delete_internal_note', array( $this, 'ajax_delete_note' ) );
		add_action( 'wp_ajax_zorem_search_admin_users', array( $this, 'ajax_search_admin_users' ) );
	}

	/**
	 * Capability check — only users who can manage the Returns Center may
	 * read/write internal notes.
	 *
	 * @return bool
	 */
	protected function current_user_can_manage() {
		$capability = zorem_returns_exchanges()->admin->get_admin_capability();
		return current_user_can( $capability );
	}

	/**
	 * Get all notes for a return request, newest first.
	 *
	 * @param int $request_id
	 * @return array
	 */
	public function get_notes( $request_id ) {
		global $wpdb;
		$request_id = absint( $request_id );
		if ( ! $request_id ) {
			return array();
		}
		$table = $wpdb->prefix . 'return_internal_notes';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %d ORDER BY created_at DESC, id DESC", $request_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Insert a new note. Returns the inserted row ID, or 0 on failure.
	 *
	 * @param int    $request_id
	 * @param int    $author_id
	 * @param string $content
	 * @param int[]  $mentioned_user_ids
	 * @return int
	 */
	public function add_note( $request_id, $author_id, $content, $mentioned_user_ids = array() ) {
		global $wpdb;

		$request_id = absint( $request_id );
		$author_id  = absint( $author_id );
		$content    = trim( (string) $content );

		if ( ! $request_id || ! $author_id || '' === $content ) {
			return 0;
		}

		$mentioned_user_ids = array_values( array_filter( array_map( 'absint', (array) $mentioned_user_ids ) ) );

		$table = $wpdb->prefix . 'return_internal_notes';
		$data  = array(
			'request_id'         => $request_id,
			'author_id'          => $author_id,
			'note_content'       => wp_kses( $content, $this->allowed_html() ),
			'mentioned_user_ids' => empty( $mentioned_user_ids ) ? null : wp_json_encode( $mentioned_user_ids ),
			'created_at'         => current_time( 'mysql', true ),
		);
		$inserted = $wpdb->insert( $table, $data );
		if ( ! $inserted ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a note. Only the author OR an admin (`manage_options`) can delete.
	 *
	 * @param int $note_id
	 * @return bool
	 */
	public function delete_note( $note_id ) {
		global $wpdb;
		$note_id = absint( $note_id );
		if ( ! $note_id ) {
			return false;
		}
		$table = $wpdb->prefix . 'return_internal_notes';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$note  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $note_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $note ) {
			return false;
		}
		$current_user_id = get_current_user_id();
		$is_author       = ( (int) $note->author_id === (int) $current_user_id );
		$is_admin        = current_user_can( 'manage_options' );
		if ( ! $is_author && ! $is_admin ) {
			return false;
		}
		$deleted = $wpdb->delete( $table, array( 'id' => $note_id ), array( '%d' ) );
		return false !== $deleted;
	}

	/**
	 * Conservative HTML allowed in note content — supports basic formatting and
	 * the @mention <span> tag emitted by the front-end.
	 */
	protected function allowed_html() {
		return array(
			'a'      => array( 'href' => array(), 'title' => array(), 'target' => array(), 'rel' => array() ),
			'br'     => array(),
			'em'     => array(),
			'strong' => array(),
			'b'      => array(),
			'i'      => array(),
			'span'   => array( 'class' => array(), 'data-user-id' => array() ),
		);
	}

	/**
	 * Pretty author display + timestamp for a note row.
	 *
	 * @param object $note Row from {prefix}return_internal_notes.
	 * @return array { author_name: string, author_avatar: string (HTML), date_html: string }
	 */
	public function get_author_meta( $note ) {
		$author     = $note ? get_user_by( 'id', (int) $note->author_id ) : null;
		$name       = $author ? $author->display_name : __( '(unknown user)', 'zorem-returns' );
		$avatar     = $author ? get_avatar( $author->ID, 32 ) : '';

		// Convert stored UTC timestamp to site-local for display.
		$ts_utc     = isset( $note->created_at ) ? strtotime( $note->created_at . ' UTC' ) : 0;
		$date_human = $ts_utc ? sprintf(
			/* translators: %s: human time difference, e.g. "5 minutes" */
			__( '%s ago', 'zorem-returns' ),
			human_time_diff( $ts_utc, current_time( 'timestamp', true ) )
		) : '';
		$date_full  = $ts_utc ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts_utc ) : '';

		return array(
			'author_name'   => $name,
			'author_avatar' => $avatar,
			'date_human'    => $date_human,
			'date_full'     => $date_full,
		);
	}

	/* --------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * AJAX — add a note to a request.
	 */
	public function ajax_add_note() {
		check_ajax_referer( 'zorem_internal_notes_nonce', 'nonce' );

		if ( ! $this->current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to add notes.', 'zorem-returns' ) ) );
		}

		$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
		$content  = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

		$mentions = isset( $_POST['mentioned_user_ids'] ) && is_array( $_POST['mentioned_user_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['mentioned_user_ids'] ) ) : array();
		$mentions = array_values( array_filter( $mentions ) );

		if ( ! $request_id || '' === trim( wp_strip_all_tags( (string) $content ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Note cannot be empty.', 'zorem-returns' ) ) );
		}

		// Verify the request actually exists.
		$return_row = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		if ( ! $return_row ) {
			wp_send_json_error( array( 'message' => __( 'Return request not found.', 'zorem-returns' ) ) );
		}

		$note_id = $this->add_note( $request_id, get_current_user_id(), $content, $mentions );
		if ( ! $note_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the note.', 'zorem-returns' ) ) );
		}

		// Fire mention notifications (best-effort — failures don't block the response).
		if ( ! empty( $mentions ) ) {
			$this->notify_mentions( $note_id, $request_id, $mentions );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'return_internal_notes';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $note_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_send_json_success(
			array(
				'note_id' => $note_id,
				'html'    => $this->render_note_html( $row ),
			)
		);
	}

	/**
	 * AJAX — delete a note.
	 */
	public function ajax_delete_note() {
		check_ajax_referer( 'zorem_internal_notes_nonce', 'nonce' );

		if ( ! $this->current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'zorem-returns' ) ) );
		}

		$note_id = isset( $_POST['note_id'] ) ? absint( $_POST['note_id'] ) : 0;
		$ok      = $this->delete_note( $note_id );

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete note (you may not be the author).', 'zorem-returns' ) ) );
		}
		wp_send_json_success();
	}

	/**
	 * AJAX — search admin/shop_manager users for the @mention autocomplete.
	 */
	public function ajax_search_admin_users() {
		check_ajax_referer( 'zorem_internal_notes_nonce', 'nonce' );

		if ( ! $this->current_user_can_manage() ) {
			wp_send_json_error();
		}

		$term  = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$users = $this->get_mentionable_admins( $term, 8 );

		$out = array();
		foreach ( $users as $u ) {
			$out[] = array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'email' => $u->user_email,
				'avatar'=> get_avatar_url( $u->ID, array( 'size' => 24 ) ),
			);
		}
		wp_send_json_success( array( 'users' => $out ) );
	}

	/**
	 * Get admins / shop managers matching a search term (used by the @mention dropdown).
	 *
	 * @param string $term
	 * @param int    $limit
	 * @return WP_User[]
	 */
	public function get_mentionable_admins( $term = '', $limit = 8 ) {
		$args = array(
			'role__in' => array( 'administrator', 'shop_manager' ),
			'number'   => max( 1, absint( $limit ) ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		);
		if ( '' !== $term ) {
			$args['search']         = '*' . esc_attr( $term ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}
		$users = get_users( $args );
		// Exclude the current user from the suggestion list.
		$current = get_current_user_id();
		$users   = array_values(
			array_filter(
				$users,
				function ( $u ) use ( $current ) {
					return (int) $u->ID !== (int) $current;
				}
			)
		);
		return $users;
	}

	/**
	 * Send a one-off email to each mentioned admin so they can jump back into the request.
	 *
	 * @param int   $note_id
	 * @param int   $request_id
	 * @param int[] $mentioned_ids
	 */
	protected function notify_mentions( $note_id, $request_id, $mentioned_ids ) {
		$author     = wp_get_current_user();
		$author_name = $author && $author->ID ? $author->display_name : __( 'A team member', 'zorem-returns' );

		$return_row = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		$order_id   = $return_row ? (int) $return_row->order_id : 0;

		$dashboard_url = admin_url( 'admin.php?page=zorem-returns-exchanges&tab=returns-orders#request-' . $request_id );

		foreach ( $mentioned_ids as $uid ) {
			$user = get_user_by( 'id', $uid );
			if ( ! $user || empty( $user->user_email ) ) {
				continue;
			}
			$subject = sprintf(
				/* translators: 1: author name, 2: request id */
				__( '[%1$s] You were mentioned in a note on return request #%2$d', 'zorem-returns' ),
				get_bloginfo( 'name' ),
				$request_id
			);
			$lines   = array();
			/* translators: %s: Customer display name. */
			$lines[] = sprintf( __( 'Hi %s,', 'zorem-returns' ), $user->display_name );
			$lines[] = '';
			$lines[] = sprintf(
				/* translators: 1: author name, 2: order id, 3: request id */
				__( '%1$s mentioned you in an internal note on the return request for order #%2$d (request #%3$d).', 'zorem-returns' ),
				$author_name,
				$order_id,
				$request_id
			);
			$lines[] = '';
			$lines[] = __( 'Open the return request:', 'zorem-returns' );
			$lines[] = $dashboard_url;

			wp_mail( $user->user_email, $subject, implode( "\n", $lines ) );
		}
	}

	/* --------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Render the whole Internal Notes section for a request (used by the admin
	 * preview modal). Returns HTML.
	 *
	 * @param int $request_id
	 * @return string
	 */
	public function render_section_html( $request_id ) {
		if ( ! $this->current_user_can_manage() ) {
			return '';
		}
		$request_id = absint( $request_id );
		if ( ! $request_id ) {
			return '';
		}
		$notes = $this->get_notes( $request_id );
		ob_start();
		?>
		<div class="zorem-internal-notes-section" data-request-id="<?php echo esc_attr( $request_id ); ?>">
			<h3 class="zorem-internal-notes-heading">
				<span class="dashicons dashicons-edit-page"></span>
				<?php esc_html_e( 'Internal Admin Notes', 'zorem-returns' ); ?>
				<span class="zorem-internal-notes-count">(<?php echo count( $notes ); ?>)</span>
			</h3>
			<p class="zorem-internal-notes-tip"><?php esc_html_e( 'Private to your team. Customers never see these. Type @ to mention a teammate.', 'zorem-returns' ); ?></p>

			<div class="zorem-internal-notes-list">
				<?php
				if ( empty( $notes ) ) {
					echo '<div class="zorem-internal-notes-empty">' . esc_html__( 'No notes yet.', 'zorem-returns' ) . '</div>';
				} else {
					foreach ( $notes as $n ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is escaped within render_note_html().
						echo $this->render_note_html( $n );
					}
				}
				?>
			</div>

			<div class="zorem-internal-notes-form">
				<div class="zorem-internal-notes-input-wrap">
					<textarea class="zorem-internal-notes-input" placeholder="<?php esc_attr_e( 'Add an internal note…', 'zorem-returns' ); ?>" rows="3"></textarea>
					<div class="zorem-mention-dropdown" hidden></div>
				</div>
				<div class="zorem-internal-notes-actions">
					<button type="button" class="button button-primary zorem-internal-notes-submit"><?php esc_html_e( 'Add Note', 'zorem-returns' ); ?></button>
					<span class="zorem-internal-notes-status"></span>
				</div>
				<?php $nonce = wp_create_nonce( 'zorem_internal_notes_nonce' ); ?>
				<input type="hidden" class="zorem-internal-notes-nonce" value="<?php echo esc_attr( $nonce ); ?>" />
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single note row (used both during initial render and after AJAX add).
	 *
	 * @param object $note Row from {prefix}return_internal_notes.
	 * @return string
	 */
	public function render_note_html( $note ) {
		if ( ! $note ) {
			return '';
		}
		$meta = $this->get_author_meta( $note );

		$current_user_id = get_current_user_id();
		$can_delete      = ( (int) $note->author_id === (int) $current_user_id ) || current_user_can( 'manage_options' );

		ob_start();
		?>
		<div class="zorem-internal-note" data-note-id="<?php echo esc_attr( $note->id ); ?>">
			<div class="zorem-internal-note-avatar"><?php echo wp_kses_post( $meta['author_avatar'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="zorem-internal-note-body">
				<div class="zorem-internal-note-meta">
					<strong class="zorem-internal-note-author"><?php echo esc_html( $meta['author_name'] ); ?></strong>
					<span class="zorem-internal-note-date" title="<?php echo esc_attr( $meta['date_full'] ); ?>"><?php echo esc_html( $meta['date_human'] ); ?></span>
					<?php if ( $can_delete ) { ?>
						<a href="#" class="zorem-internal-note-delete" title="<?php esc_attr_e( 'Delete this note', 'zorem-returns' ); ?>"><span class="dashicons dashicons-trash"></span></a>
					<?php } ?>
				</div>
				<div class="zorem-internal-note-content"><?php echo wp_kses( nl2br( $note->note_content ), $this->allowed_html() ); ?></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
