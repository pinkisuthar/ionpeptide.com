<?php
/**
 * Backwards database scan — resumable, date-scoped, and incremental.
 *
 * Two phases, batched over AJAX so they never hit PHP's max_execution_time:
 *   Phase "index" — walk orders (optionally within a date window, skipping any
 *                   already indexed) and populate the index table.
 *   Phase "score" — score rows against the complete cross-order history. Scans
 *                   score only the newly-indexed rows; "Recompute" scores all.
 *
 * Progress is persisted server-side in `fs_scan_state` after every batch, so a
 * scan can be paused, resumed, or continued days later without starting over.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Scanner {

	const STATE_OPTION = 'fs_scan_state';
	const BATCH        = 100;

	/**
	 * Seconds of work to do per AJAX request. Doing many batches per request
	 * means far fewer calls to admin-ajax.php, which avoids tripping host
	 * firewalls / rate limiters (ModSecurity, Wordfence, Cloudflare, etc.).
	 */
	const TIME_BUDGET = 8;

	/**
	 * Hook AJAX + export handlers.
	 */
	public function __construct() {
		add_action( 'wp_ajax_fs_scan_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_fs_scan_step', array( $this, 'ajax_step' ) );
		add_action( 'wp_ajax_fs_scan_state', array( $this, 'ajax_state' ) );
		add_action( 'wp_ajax_fs_scan_pause', array( $this, 'ajax_pause' ) );
		add_action( 'wp_ajax_fs_scan_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_fs_scan_cancel', array( $this, 'ajax_cancel' ) );
		add_action( 'admin_post_fs_export_index', array( $this, 'handle_export_index' ) );
	}

	/**
	 * All order statuses we want to index (everything except trashed/drafts).
	 *
	 * @return array
	 */
	public static function indexable_statuses() {
		$statuses = array_keys( wc_get_order_statuses() ); // wc-prefixed.
		$statuses = array_map(
			function ( $s ) {
				return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
			},
			$statuses
		);
		$statuses[] = 'charged-back';
		return array_values( array_unique( array_diff( $statuses, array( 'trash', 'auto-draft', 'checkout-draft' ) ) ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Persisted progress state
	 * ----------------------------------------------------------------- */

	/**
	 * The idle / "no scan in progress" state.
	 *
	 * @return array
	 */
	public static function default_state() {
		return array(
			'status'       => 'idle',   // idle | running | paused | complete.
			'kind'         => '',       // full | rescore.
			'phase'        => '',       // index | score.
			'range'        => 'all',    // all | 1m | 3m | 6m | 12m | new.
			'date_after'   => 0,        // unix timestamp lower bound (0 = none).
			'skip_indexed' => true,     // skip orders already in the index.
			'score_mode'   => 'new',    // new (only unscored) | all.
			'offset'       => 0,        // cursor within the current phase.
			'index_total'  => 0,        // orders to index (phase 1 denominator).
			'score_total'  => 0,        // rows to score (phase 2 denominator).
			'started_at'   => '',
			'updated_at'   => '',
		);
	}

	/**
	 * Read the current scan state, merged over defaults.
	 *
	 * @return array
	 */
	public static function get_state() {
		$s = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		return array_merge( self::default_state(), $s );
	}

	/**
	 * Persist state (stamping updated_at). Not autoloaded.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function save_state( $state ) {
		$state['updated_at'] = current_time( 'mysql' );
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/* ----------------------------------------------------------------- *
	 *  AJAX endpoints
	 * ----------------------------------------------------------------- */

	/**
	 * Shared nonce + capability guard for every scan endpoint.
	 */
	private function guard() {
		check_ajax_referer( 'fs_scan', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fraud-shield' ) ), 403 );
		}
	}

	/**
	 * Translate a range key into a unix "orders on/after" timestamp.
	 *
	 * @param string $range Range key.
	 * @return int
	 */
	private function range_to_ts( $range ) {
		switch ( $range ) {
			case '1m':
				return time() - 30 * DAY_IN_SECONDS;
			case '3m':
				return time() - 90 * DAY_IN_SECONDS;
			case '6m':
				return time() - 180 * DAY_IN_SECONDS;
			case '12m':
				return time() - 365 * DAY_IN_SECONDS;
			case 'new':
				$ts = $this->latest_indexed_ts();
				// Generous buffer so timezone skew or odd imported dates never drop
				// boundary orders; skip_indexed dedupes anything already present.
				return $ts > 0 ? max( 0, $ts - 7 * DAY_IN_SECONDS ) : 0;
			case 'all':
			default:
				return 0;
		}
	}

	/**
	 * AJAX: (re)start a scan.
	 *
	 * POST: kind = full | rescore, range = all|1m|3m|6m|12m|new, force = 1 to
	 * re-index orders already scanned.
	 */
	public function ajax_start() {
		$this->guard();

		$kind  = ( isset( $_POST['kind'] ) && 'rescore' === $_POST['kind'] ) ? 'rescore' : 'full';
		$range = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'all';
		$force = ! empty( $_POST['force'] );

		$state               = self::default_state();
		$state['status']     = 'running';
		$state['kind']       = $kind;
		$state['range']      = $range;
		$state['offset']     = 0;
		$state['started_at'] = current_time( 'mysql' );

		if ( 'rescore' === $kind ) {
			$state['phase']        = 'score';
			$state['score_mode']   = 'all';
			$state['skip_indexed'] = false;
			$state['date_after']   = 0;
			$state['index_total']  = 0;
			$state['score_total']  = FS_Data::index_count();
		} else {
			$date_after            = $this->range_to_ts( $range );
			$state['phase']        = 'index';
			$state['score_mode']   = 'new';
			$state['skip_indexed'] = ! $force;
			$state['date_after']   = $date_after;
			$state['index_total']  = $this->total_orders( $date_after );
			$state['score_total']  = 0;
		}

		wp_send_json_success( $this->save_state( $state ) );
	}

	/**
	 * AJAX: process the next batch using the persisted cursor, then return state.
	 */
	public function ajax_step() {
		$this->guard();
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			wp_send_json_success( $state );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$deadline = microtime( true ) + self::TIME_BUDGET;

		if ( 'index' === $state['phase'] ) {
			do {
				$found           = $this->run_index_batch( (int) $state['offset'], self::BATCH, (int) $state['date_after'], ! empty( $state['skip_indexed'] ) );
				$state['offset'] = (int) $state['offset'] + $found;
				if ( $found < self::BATCH ) {
					// Indexing finished — move on to scoring.
					$state['phase']       = 'score';
					$state['offset']      = 0;
					$state['score_total'] = ( 'all' === $state['score_mode'] ) ? FS_Data::index_count() : FS_Data::unscored_count();
					break;
				}
			} while ( microtime( true ) < $deadline );
		} elseif ( 'score' === $state['phase'] ) {
			do {
				$found = ( 'all' === $state['score_mode'] )
					? FS_Data::score_index_batch( (int) $state['offset'], self::BATCH )
					: FS_Data::score_unscored_batch( self::BATCH );
				$state['offset'] = (int) $state['offset'] + $found;
				if ( $found < self::BATCH ) {
					$state['status'] = 'complete';
					break;
				}
			} while ( microtime( true ) < $deadline );
		} else {
			$state['status'] = 'complete';
		}

		wp_send_json_success( $this->save_state( $state ) );
	}

	/**
	 * AJAX: report the current state (used to detect a resumable scan on load).
	 */
	public function ajax_state() {
		$this->guard();
		wp_send_json_success( self::get_state() );
	}

	/**
	 * AJAX: pause a running scan (progress is kept).
	 */
	public function ajax_pause() {
		$this->guard();
		$state = self::get_state();
		if ( 'running' === $state['status'] ) {
			$state['status'] = 'paused';
			$this->save_state( $state );
		}
		wp_send_json_success( self::get_state() );
	}

	/**
	 * AJAX: resume a paused scan.
	 */
	public function ajax_resume() {
		$this->guard();
		$state = self::get_state();
		if ( 'paused' === $state['status'] ) {
			$state['status'] = 'running';
			$state           = $this->save_state( $state );
		}
		wp_send_json_success( $state );
	}

	/**
	 * AJAX: abandon any in-progress scan.
	 */
	public function ajax_cancel() {
		$this->guard();
		delete_option( self::STATE_OPTION );
		wp_send_json_success( self::default_state() );
	}

	/* ----------------------------------------------------------------- *
	 *  Counts + batch workers
	 * ----------------------------------------------------------------- */

	/**
	 * Total indexable orders (optionally on/after a timestamp).
	 *
	 * @param int $date_after Unix timestamp (0 = no bound).
	 * @return int
	 */
	private function total_orders( $date_after = 0 ) {
		$args = array(
			'limit'    => 1,
			'return'   => 'ids',
			'status'   => self::indexable_statuses(),
			'type'     => 'shop_order',
			'paginate' => true,
		);
		if ( $date_after > 0 ) {
			$args['date_created'] = '>=' . (int) $date_after;
		}
		$res = wc_get_orders( $args );
		return is_object( $res ) && isset( $res->total ) ? (int) $res->total : 0;
	}

	/**
	 * UTC timestamp of the newest order already in the index (0 if empty).
	 *
	 * order_date is stored in the site's local timezone, while wc_get_orders'
	 * date_created filter compares against UTC — so convert properly, and cap at
	 * "now" so a stray future-dated row (e.g. from a CSV import) can't push the
	 * cutoff past the present and make the scan see zero new orders.
	 *
	 * @return int
	 */
	private function latest_indexed_ts() {
		global $wpdb;
		$max = $wpdb->get_var( 'SELECT MAX(order_date) FROM ' . FS_Data::index_table() ); // phpcs:ignore
		if ( ! $max ) {
			return 0;
		}
		$ts = (int) strtotime( get_gmt_from_date( $max ) );
		return min( $ts > 0 ? $ts : 0, time() );
	}

	/**
	 * Index one batch of orders. Skips orders already indexed (unless forced),
	 * so re-runs continue from the gap instead of re-fetching everything.
	 *
	 * @param int  $offset       Offset.
	 * @param int  $batch        Batch size.
	 * @param int  $date_after   Unix timestamp lower bound (0 = none).
	 * @param bool $skip_indexed Skip orders already present in the index.
	 * @return int Orders fetched in this batch (for cursor + end detection).
	 */
	private function run_index_batch( $offset, $batch, $date_after, $skip_indexed ) {
		$settings = FS_Core::instance()->settings();

		$args = array(
			'limit'   => $batch,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
			'status'  => self::indexable_statuses(),
			'type'    => 'shop_order',
		);
		if ( $date_after > 0 ) {
			$args['date_created'] = '>=' . (int) $date_after;
		}

		$ids   = (array) wc_get_orders( $args );
		$found = count( $ids );

		if ( $skip_indexed && $found ) {
			$existing = FS_Data::existing_order_ids( $ids );
			if ( ! empty( $existing ) ) {
				$ids = array_diff( $ids, $existing );
			}
		}

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$signals       = FS_Data::extract_signals( $order );
			$is_chargeback = FS_Data::is_chargeback( $order, $settings );
			FS_Data::upsert_index( $signals, $is_chargeback );
		}

		return $found;
	}

	/* ----------------------------------------------------------------- *
	 *  CSV export of the index
	 * ----------------------------------------------------------------- */

	/**
	 * Stream the whole index as a CSV download (also re-importable to restore).
	 */
	public function handle_export_index() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fraud-shield' ) );
		}
		check_admin_referer( 'fs_export_index' );

		global $wpdb;
		$table = FS_Data::index_table();
		$cols  = FS_Data::export_columns();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="fraud-shield-index-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for spreadsheet apps. phpcs:ignore
		fputcsv( $out, $cols );

		$select = implode( ', ', $cols ); // fixed, known column names.
		$limit  = 5000;
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore
			$n    = is_array( $rows ) ? count( $rows ) : 0;
			foreach ( (array) $rows as $r ) {
				$line = array();
				foreach ( $cols as $c ) {
					$line[] = isset( $r[ $c ] ) ? $r[ $c ] : '';
				}
				// Neutralize spreadsheet formula injection in customer-controlled
				// cells (names, address text, user agents, coupons). The restore
				// path strips the guard, so round-trips stay faithful.
				fputcsv( $out, array_map( array( 'FS_Data', 'csv_escape_cell' ), $line ) );
			}
			$offset += $limit;
		} while ( $n === $limit );

		fclose( $out ); // phpcs:ignore
		exit;
	}
}
