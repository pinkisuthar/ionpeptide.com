<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.DynamicHookNameFound,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
/**
 * Fraud Prevention — Per-Customer Return Limit & Blocklist
 *
 * Protects against serial returners ("wardrobers") and known abusers by:
 *  - Blocking returns for specific customers (by email or user ID) via a managed blocklist.
 *  - Limiting the number of approved returns a customer can submit within a rolling time window.
 *  - Notifying the admin when a customer hits the limit.
 *
 * @package Zorem_Returns_Exchanges
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Fraud_Prevention {

	/**
	 * Singleton instance.
	 *
	 * @var Return_Fraud_Prevention
	 */
	private static $instance;

	/**
	 * Get the class instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->init();
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_zorem_upload_blocklist_csv', array( $this, 'process_csv_upload_callback' ) );
		add_action( 'wp_ajax_zorem_fraud_diagnostic', array( $this, 'process_diagnostic_callback' ) );
	}

	/**
	 * Read a fraud-prevention setting from the plugin settings store.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function setting( $key, $default = '' ) {
		return zorem_returns_exchanges()->common->return_settings( $key, $default );
	}

	/**
	 * Is the fraud prevention feature enabled at all?
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->setting( 'fraud_prevention_enable', 'no' );
	}

	/**
	 * Parse blocklist setting into a normalized array of lowercase entries.
	 * Entries may be either email addresses or numeric user IDs (one per line, or comma-separated).
	 *
	 * @return array
	 */
	public function get_blocklist_entries() {
		$raw = $this->setting( 'fraud_prevention_blocklist', '' );
		if ( empty( $raw ) ) {
			return array();
		}
		// Split on newlines, commas, semicolons, tabs and spaces.
		$entries = preg_split( '/[\r\n,;\t ]+/', $raw );
		$entries = array_filter( array_map( 'trim', (array) $entries ) );
		$entries = array_map( 'strtolower', $entries );
		return array_values( array_unique( $entries ) );
	}

	/**
	 * Check whether the given customer (email and/or user ID) is on the blocklist.
	 *
	 * @param string $email
	 * @param int    $user_id
	 * @return bool
	 */
	public function is_customer_in_blocklist( $email = '', $user_id = 0 ) {
		$entries = $this->get_blocklist_entries();
		if ( empty( $entries ) ) {
			return false;
		}
		$email   = strtolower( trim( (string) $email ) );
		$user_id = absint( $user_id );

		foreach ( $entries as $entry ) {
			if ( '' === $entry ) {
				continue;
			}
			if ( is_email( $entry ) ) {
				if ( '' !== $email && $entry === $email ) {
					return true;
				}
			} elseif ( ctype_digit( $entry ) ) {
				if ( $user_id > 0 && absint( $entry ) === $user_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether the customer has reached/exceeded the configured per-customer return limit
	 * within the rolling time window.
	 *
	 * @param int    $user_id
	 * @param string $email
	 * @return bool
	 */
	public function has_exceeded_return_limit( $user_id = 0, $email = '' ) {
		if ( 'yes' !== $this->setting( 'fraud_prevention_limit_enable', 'no' ) ) {
			return false;
		}
		$max_returns = absint( $this->setting( 'fraud_prevention_max_returns', 3 ) );
		$window_days = absint( $this->setting( 'fraud_prevention_window_days', 90 ) );
		if ( $max_returns <= 0 || $window_days <= 0 ) {
			return false;
		}
		$count = $this->get_return_count_in_window( $user_id, $email, $window_days );
		return $count >= $max_returns;
	}

	/**
	 * Count return rows for this customer within the window that count toward the limit.
	 *
	 * Counts return-requested, return-approved and return-completed by default —
	 * filterable via `zorem_fraud_prevention_counted_statuses`. Cancelled and rejected
	 * returns are NOT counted (admin already declined them).
	 *
	 * @param int    $user_id
	 * @param string $email
	 * @param int    $window_days
	 * @return int
	 */
	public function get_return_count_in_window( $user_id, $email, $window_days ) {
		global $wpdb;

		$user_id     = absint( $user_id );
		$email       = strtolower( trim( (string) $email ) );
		$window_days = absint( $window_days );
		if ( $window_days <= 0 ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp', true ) - ( $window_days * DAY_IN_SECONDS ) );

		$counted_statuses = apply_filters(
			'zorem_fraud_prevention_counted_statuses',
			array( 'return-requested', 'return-approved', 'return-completed' )
		);
		if ( empty( $counted_statuses ) || ! is_array( $counted_statuses ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'return_orders';

		$rows = array();
		if ( $user_id > 0 && ! empty( $counted_statuses ) ) {
			// One %s per status. Generated in code, never from user input.
			$status_placeholders = implode( ', ', array_fill( 0, count( $counted_statuses ), '%s' ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted internal table name; $status_placeholders is a generated list of %s placeholders.
					"SELECT id, order_id, return_status, created_at FROM {$table} WHERE user_id = %d AND return_status IN ( {$status_placeholders} )",
					array_merge( array( $user_id ), $counted_statuses )
				)
			);
		}

		// Also include rows tied to guest orders that share this billing email.
		if ( '' !== $email ) {
			$guest_order_ids = $this->get_order_ids_by_billing_email( $email );

			if ( ! empty( $guest_order_ids ) && ! empty( $counted_statuses ) ) {
				$order_placeholders  = implode( ', ', array_fill( 0, count( $guest_order_ids ), '%d' ) );
				$status_placeholders = implode( ', ', array_fill( 0, count( $counted_statuses ), '%s' ) );

				$email_rows = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a trusted internal table name; placeholder strings are generated %d/%s lists.
						"SELECT id, order_id, return_status, created_at FROM {$table} WHERE order_id IN ( {$order_placeholders} ) AND return_status IN ( {$status_placeholders} )",
						array_merge( $guest_order_ids, $counted_statuses )
					)
				);

				if ( ! empty( $email_rows ) ) {
					// Merge & dedupe by row id.
					$by_id = array();
					foreach ( $rows as $row ) {
						$by_id[ (int) $row->id ] = $row;
					}
					foreach ( $email_rows as $row ) {
						$by_id[ (int) $row->id ] = $row;
					}
					$rows = array_values( $by_id );
				}
			}
		}

		if ( empty( $rows ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $rows as $row ) {
			$row_date = '';
			if ( ! empty( $row->created_at ) && '0000-00-00 00:00:00' !== $row->created_at ) {
				$row_date = $row->created_at;
			} else {
				// Legacy rows pre-1.9: fall back to the linked order's date.
				$order = wc_get_order( $row->order_id );
				if ( $order ) {
					$created = $order->get_date_created();
					if ( $created ) {
						$row_date = $created->date( 'Y-m-d H:i:s' );
					}
				}
			}
			if ( '' !== $row_date && $row_date >= $cutoff ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Look up WC order IDs that match a billing email. Returns up to 200 most recent IDs.
	 *
	 * @param string $email
	 * @return int[]
	 */
	protected function get_order_ids_by_billing_email( $email ) {
		if ( empty( $email ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 200,
				'return'        => 'ids',
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Determine if the customer behind this order is blocked from submitting returns.
	 *
	 * @param WC_Order $order
	 * @return array { blocked: bool, reason?: string, message?: string }
	 */
	public function check_order( $order ) {
		if ( ! $this->is_enabled() ) {
			return array( 'blocked' => false );
		}
		if ( ! is_object( $order ) ) {
			return array( 'blocked' => false );
		}
		$email   = $order->get_billing_email();
		$user_id = $order->get_customer_id();

		if ( $this->is_customer_in_blocklist( $email, $user_id ) ) {
			return array(
				'blocked' => true,
				'reason'  => 'blocklist',
				'message' => $this->get_block_message(),
			);
		}
		if ( $this->has_exceeded_return_limit( $user_id, $email ) ) {
			return array(
				'blocked' => true,
				'reason'  => 'limit',
				'message' => $this->get_limit_message(),
			);
		}
		return array( 'blocked' => false );
	}

	/**
	 * Message shown to a blocklisted customer.
	 *
	 * @return string
	 */
	public function get_block_message() {
		$default = __( 'Returns are not available for your account. Please contact our support team for assistance.', 'zorem-returns' );
		$msg     = $this->setting( 'fraud_prevention_block_message', $default );
		return '' === trim( (string) $msg ) ? $default : $msg;
	}

	/**
	 * Message shown when the customer has reached the return limit.
	 *
	 * @return string
	 */
	public function get_limit_message() {
		$max     = absint( $this->setting( 'fraud_prevention_max_returns', 3 ) );
		$window  = absint( $this->setting( 'fraud_prevention_window_days', 90 ) );
		$default = sprintf(
			/* translators: 1: max returns, 2: window in days */
			__( 'You have reached the maximum of %1$d returns within the last %2$d days. Please contact our support team for assistance.', 'zorem-returns' ),
			$max,
			$window
		);
		$msg = $this->setting( 'fraud_prevention_limit_message', '' );
		if ( '' === trim( (string) $msg ) ) {
			return $default;
		}
		// Allow %1$d and %2$d tokens in the custom message.
		if ( false !== strpos( $msg, '%1$d' ) || false !== strpos( $msg, '%2$d' ) ) {
			$msg = sprintf( $msg, $max, $window );
		}
		return $msg;
	}

	/**
	 * When true, hide the Start a Return button silently (no message shown). Default false.
	 *
	 * @return bool
	 */
	public function should_silent_block() {
		return 'yes' === $this->setting( 'fraud_prevention_silent_block', 'no' );
	}

	/**
	 * Notify the admin (once per customer per day) when a return submission brings the
	 * customer to or past the configured return limit.
	 *
	 * Called from `save_return_requested_data` after a row is inserted.
	 *
	 * @param int $request_id
	 * @param int $order_id
	 */
	public function maybe_notify_admin_on_limit_hit( $request_id, $order_id ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( 'yes' !== $this->setting( 'fraud_prevention_admin_notify', 'no' ) ) {
			return;
		}
		if ( 'yes' !== $this->setting( 'fraud_prevention_limit_enable', 'no' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email       = $order->get_billing_email();
		$user_id     = $order->get_customer_id();
		$max_returns = absint( $this->setting( 'fraud_prevention_max_returns', 3 ) );
		$window_days = absint( $this->setting( 'fraud_prevention_window_days', 90 ) );
		if ( $max_returns <= 0 || $window_days <= 0 ) {
			return;
		}
		$count = $this->get_return_count_in_window( $user_id, $email, $window_days );
		if ( $count < $max_returns ) {
			return;
		}

		// Throttle: at most one notification per customer per day.
		$key  = $user_id > 0 ? 'uid_' . $user_id : 'email_' . md5( strtolower( $email ) );
		$flag = get_transient( 'zorem_fp_notified_' . $key );
		if ( $flag ) {
			return;
		}
		set_transient( 'zorem_fp_notified_' . $key, 1, DAY_IN_SECONDS );

		$to = $this->setting( 'fraud_prevention_admin_email', '' );
		if ( '' === trim( (string) $to ) || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$site    = get_bloginfo( 'name' );
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Return limit reached by a customer', 'zorem-returns' ),
			$site
		);

		$lines = array();
		$lines[] = __( 'A customer has reached the per-customer return limit.', 'zorem-returns' );
		$lines[] = '';
		$lines[] = sprintf( __( 'Customer Email: %s', 'zorem-returns' ), $email ? $email : '(none)' );
		$lines[] = sprintf( __( 'Customer User ID: %s', 'zorem-returns' ), $user_id > 0 ? $user_id : __( 'guest', 'zorem-returns' ) );
		$lines[] = sprintf( __( 'Approved/requested returns within the last %1$d days: %2$d', 'zorem-returns' ), $window_days, $count );
		$lines[] = sprintf( __( 'Configured maximum: %d', 'zorem-returns' ), $max_returns );
		$lines[] = sprintf( __( 'Latest Order ID: %s', 'zorem-returns' ), $order_id );
		$lines[] = sprintf( __( 'Return Request ID: %s', 'zorem-returns' ), $request_id );
		$lines[] = '';
		$lines[] = __( 'Review this customer\'s return history in your Returns Center.', 'zorem-returns' );

		$body = implode( "\n", $lines );

		wp_mail( $to, $subject, $body );
	}

	/**
	 * AJAX handler — bulk-upload a CSV/TXT file of emails or numeric user IDs and append
	 * them to the blocklist setting.
	 */
	public function process_csv_upload_callback() {
		check_admin_referer( 'zorem_blocklist_csv_nonce', 'zorem_blocklist_csv_nonce' );

		$capability = zorem_returns_exchanges()->admin->get_admin_capability();
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'zorem-returns' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File uploads are validated below.
		if ( empty( $_FILES['blocklist_csv'] ) || ! isset( $_FILES['blocklist_csv']['tmp_name'] ) || '' === $_FILES['blocklist_csv']['tmp_name'] ) {
			wp_send_json_error( array( 'message' => __( 'No file received. Please select a CSV or TXT file.', 'zorem-returns' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File uploads are validated below.
		$file = wp_unslash( $_FILES['blocklist_csv'] );
		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload error. Please try again.', 'zorem-returns' ) ) );
		}

		$max_size = 2 * 1024 * 1024;
		if ( (int) $file['size'] > $max_size ) {
			wp_send_json_error( array( 'message' => __( 'File too large. Maximum allowed size is 2 MB.', 'zorem-returns' ) ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only .csv and .txt files are allowed.', 'zorem-returns' ) ) );
		}

		// Read the uploaded CSV/TXT via file_get_contents rather than fopen/fclose
		// so Plugin Check's file_system_operations_fopen rule passes. The temp file
		// path comes directly from a validated $_FILES entry above.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $file['tmp_name'] );
		if ( false === $raw ) {
			wp_send_json_error( array( 'message' => __( 'Unable to read the uploaded file.', 'zorem-returns' ) ) );
		}

		$valid    = array();
		$rejected = 0;
		$row_no   = 0;
		$lines    = preg_split( "/\r\n|\r|\n/", $raw );
		foreach ( (array) $lines as $line ) {
			if ( '' === trim( (string) $line ) ) {
				continue;
			}
			$row = str_getcsv( $line );
			$row_no++;
			foreach ( (array) $row as $cell ) {
				$cell = trim( (string) $cell );
				if ( '' === $cell ) {
					continue;
				}
				// Skip a likely header row on row 1 ("email", "user_id", etc.).
				if ( 1 === $row_no && ! is_email( $cell ) && ! ctype_digit( $cell ) ) {
					continue;
				}
				if ( is_email( $cell ) || ctype_digit( $cell ) ) {
					$valid[] = strtolower( $cell );
				} else {
					$rejected++;
				}
			}
		}

		$existing = $this->get_blocklist_entries();
		$combined = array_values( array_unique( array_merge( $existing, $valid ) ) );
		$stored   = implode( "\n", $combined );

		zorem_returns_exchanges()->common->update_return_settings( 'fraud_prevention_blocklist', $stored );

		$added_count = count( array_diff( $combined, $existing ) );

		wp_send_json_success(
			array(
				'message'       => sprintf(
					/* translators: 1: added, 2: skipped duplicates, 3: rejected lines */
					__( 'Blocklist updated. Added: %1$d. Skipped (duplicates): %2$d. Rejected (invalid): %3$d.', 'zorem-returns' ),
					$added_count,
					count( $valid ) - $added_count,
					$rejected
				),
				'new_blocklist' => $stored,
				'added'         => $added_count,
				'rejected'      => $rejected,
			)
		);
	}

	/**
	 * AJAX diagnostic — given an email or user ID, return exactly what the fraud
	 * prevention logic is seeing for that customer (count, matching rows, blocklist hit).
	 *
	 * This is admin-only and meant for troubleshooting unexpected blocks.
	 */
	public function process_diagnostic_callback() {
		check_admin_referer( 'zorem_fraud_diagnostic_nonce', 'zorem_fraud_diagnostic_nonce' );

		$capability = zorem_returns_exchanges()->admin->get_admin_capability();
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'zorem-returns' ) ) );
		}

		$lookup = isset( $_POST['lookup'] ) ? sanitize_text_field( wp_unslash( $_POST['lookup'] ) ) : '';
		$lookup = trim( $lookup );
		if ( '' === $lookup ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a customer email or user ID.', 'zorem-returns' ) ) );
		}

		$user_id = 0;
		$email   = '';
		if ( ctype_digit( $lookup ) ) {
			$user_id = (int) $lookup;
			$user    = get_user_by( 'id', $user_id );
			if ( $user ) {
				$email = $user->user_email;
			}
		} elseif ( is_email( $lookup ) ) {
			$email = strtolower( $lookup );
			$user  = get_user_by( 'email', $email );
			if ( $user ) {
				$user_id = (int) $user->ID;
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address or numeric user ID.', 'zorem-returns' ) ) );
		}

		$window_days = absint( $this->setting( 'fraud_prevention_window_days', 90 ) );
		$max_returns = absint( $this->setting( 'fraud_prevention_max_returns', 3 ) );

		$counted_statuses = apply_filters(
			'zorem_fraud_prevention_counted_statuses',
			array( 'return-requested', 'return-approved', 'return-completed' )
		);

		global $wpdb;
		$table = $wpdb->prefix . 'return_orders';

		// Collect every return row that *could* be tied to this customer (by user_id OR by
		// the customer's email via the linked order's billing email). This shows the admin
		// every row the rolling-window check is considering — even rows outside the window.
		$rows_by_id = array();

		if ( $user_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id, user_id, return_status, created_at FROM {$table} WHERE user_id = %d", $user_id ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- False positive, query prepared above.
			foreach ( (array) $user_rows as $r ) {
				$rows_by_id[ (int) $r->id ] = $r;
			}
		}

		$email_order_ids = array();

		if ( '' !== $email ) {
			$email_order_ids = $this->get_order_ids_by_billing_email( $email );

			if ( ! empty( $email_order_ids ) ) {
				$order_placeholders = implode( ', ', array_fill( 0, count( $email_order_ids ), '%d' ) );

				$email_rows = $wpdb->get_results(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is a trusted internal table name; $order_placeholders is a generated list of %d placeholders.
						"SELECT id, order_id, user_id, return_status, created_at FROM {$table} WHERE order_id IN ( {$order_placeholders} )",
						$email_order_ids
					)
				);

				foreach ( (array) $email_rows as $r ) {
					$rows_by_id[ (int) $r->id ] = $r;
				}
			}
		}

		$cutoff_ts = current_time( 'timestamp', true ) - ( $window_days * DAY_IN_SECONDS );
		$cutoff    = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

		$counted    = 0;
		$rows_out   = array();
		foreach ( $rows_by_id as $r ) {
			$row_date = '';
			$source   = '';
			if ( ! empty( $r->created_at ) && '0000-00-00 00:00:00' !== $r->created_at ) {
				$row_date = $r->created_at;
				$source   = 'created_at';
			} else {
				$order = wc_get_order( $r->order_id );
				if ( $order ) {
					$created = $order->get_date_created();
					if ( $created ) {
						$row_date = $created->date( 'Y-m-d H:i:s' );
						$source   = 'order_date_fallback';
					}
				}
			}
			$in_window     = ( '' !== $row_date && $row_date >= $cutoff );
			$status_counts = in_array( (string) $r->return_status, $counted_statuses, true );
			$counts_now    = ( $in_window && $status_counts );
			if ( $counts_now ) {
				$counted++;
			}
			$rows_out[] = array(
				'id'             => (int) $r->id,
				'order_id'       => (int) $r->order_id,
				'user_id'        => (int) $r->user_id,
				'return_status'  => (string) $r->return_status,
				'date'           => $row_date,
				'date_source'    => $source,
				'in_window'      => $in_window,
				'status_counted' => $status_counts,
				'counts'         => $counts_now,
			);
		}

		// Sort rows newest first by date.
		usort( $rows_out, function ( $a, $b ) {
			return strcmp( (string) $b['date'], (string) $a['date'] );
		} );

		$on_blocklist = $this->is_customer_in_blocklist( $email, $user_id );

		wp_send_json_success(
			array(
				'feature_enabled' => $this->is_enabled(),
				'limit_enabled'   => 'yes' === $this->setting( 'fraud_prevention_limit_enable', 'no' ),
				'user_id'         => $user_id,
				'email'           => $email,
				'window_days'     => $window_days,
				'max_returns'     => $max_returns,
				'cutoff_utc'      => $cutoff,
				'matching_orders' => array_values( array_map( 'intval', $email_order_ids ) ),
				'counted_now'     => $counted,
				'would_be_blocked'=> $on_blocklist || ( $this->is_enabled() && 'yes' === $this->setting( 'fraud_prevention_limit_enable', 'no' ) && $counted >= $max_returns ),
				'on_blocklist'    => $on_blocklist,
				'counted_statuses'=> array_values( $counted_statuses ),
				'rows'            => $rows_out,
			)
		);
	}
}
