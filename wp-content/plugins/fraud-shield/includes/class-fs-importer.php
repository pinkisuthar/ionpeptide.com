<?php
/**
 * CSV import — populate the order index from an exported orders CSV instead of
 * walking the live WooCommerce order store.
 *
 * This is the fast alternative to the "index" phase of the full scan: for a
 * store with hundreds of thousands of orders, parsing a CSV is dramatically
 * quicker than loading every WC_Order object. After the rows are imported the
 * importer runs the same scoring pass the scanner uses.
 *
 * The workflow mirrors the scan: upload → confirm column mapping → import in
 * resumable batches → score. Progress is persisted in the `fs_import_state`
 * option (with a byte offset into the file), so it survives navigating away.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Importer {

	const STATE_OPTION = 'fs_import_state';
	const IMPORT_BATCH = 200;
	const SCORE_BATCH  = 100;

	/**
	 * Seconds of work per AJAX request (fewer requests → less chance of tripping
	 * a host firewall / rate limiter).
	 */
	const TIME_BUDGET = 8;

	/**
	 * Canonical fields we map CSV columns onto.
	 */
	const FIELDS = array(
		'order_id',
		'order_date',
		'user_id',
		'customer_name',
		'first_name',
		'last_name',
		'email',
		'phone',
		'ship_address_1',
		'ship_address_2',
		'ship_city',
		'ship_state',
		'ship_postcode',
		'ship_country',
		'billing_country',
		'bill_address_1',
		'bill_address_2',
		'bill_city',
		'bill_state',
		'bill_postcode',
		'ip',
		'order_total',
		'status',
		'payment_method',
		'coupons',
		'user_agent',
	);

	/**
	 * Hook upload + AJAX handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_fs_import_upload', array( $this, 'handle_upload' ) );
		add_action( 'wp_ajax_fs_import_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_fs_import_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_fs_import_cancel', array( $this, 'ajax_cancel' ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Field metadata (labels for the mapping UI)
	 * ----------------------------------------------------------------- */

	/**
	 * Human labels for each mappable field.
	 *
	 * @return array
	 */
	public static function field_labels() {
		return array(
			'order_id'        => __( 'Order ID (required)', 'fraud-shield' ),
			'order_date'      => __( 'Order date', 'fraud-shield' ),
			'user_id'         => __( 'Customer account ID', 'fraud-shield' ),
			'customer_name'   => __( 'Customer full name', 'fraud-shield' ),
			'first_name'      => __( 'First name', 'fraud-shield' ),
			'last_name'       => __( 'Last name', 'fraud-shield' ),
			'email'           => __( 'Email', 'fraud-shield' ),
			'phone'           => __( 'Phone', 'fraud-shield' ),
			'ship_address_1'  => __( 'Shipping address line 1', 'fraud-shield' ),
			'ship_address_2'  => __( 'Shipping address line 2', 'fraud-shield' ),
			'ship_city'       => __( 'Shipping city', 'fraud-shield' ),
			'ship_state'      => __( 'Shipping state/region', 'fraud-shield' ),
			'ship_postcode'   => __( 'Shipping postcode/ZIP', 'fraud-shield' ),
			'ship_country'    => __( 'Shipping country', 'fraud-shield' ),
			'billing_country' => __( 'Billing country', 'fraud-shield' ),
			'bill_address_1'  => __( 'Billing address line 1', 'fraud-shield' ),
			'bill_address_2'  => __( 'Billing address line 2', 'fraud-shield' ),
			'bill_city'       => __( 'Billing city', 'fraud-shield' ),
			'bill_state'      => __( 'Billing state/region', 'fraud-shield' ),
			'bill_postcode'   => __( 'Billing postcode/ZIP', 'fraud-shield' ),
			'ip'              => __( 'Customer IP address', 'fraud-shield' ),
			'order_total'     => __( 'Order total', 'fraud-shield' ),
			'status'          => __( 'Order status', 'fraud-shield' ),
			'payment_method'  => __( 'Payment method', 'fraud-shield' ),
			'coupons'         => __( 'Coupon code(s)', 'fraud-shield' ),
			'user_agent'      => __( 'Customer user agent (device)', 'fraud-shield' ),
		);
	}

	/* ----------------------------------------------------------------- *
	 *  State
	 * ----------------------------------------------------------------- */

	/**
	 * Idle state.
	 *
	 * @return array
	 */
	public static function default_state() {
		return array(
			'status'       => 'idle', // idle | ready | running | complete.
			'phase'        => '',     // import | score.
			'file'         => '',
			'filename'     => '',
			'delimiter'    => ',',
			'headers'      => array(),
			'mapping'      => array(),
			'native'       => false, // a Fraud Shield index export → restore verbatim.
			'data_offset'  => 0,
			'byte_offset'  => 0,
			'filesize'     => 0,
			'row_offset'   => 0,
			'imported'     => 0,
			'score_offset' => 0,
			'score_total'  => 0,
			'started_at'   => '',
			'updated_at'   => '',
		);
	}

	/**
	 * Current import state, merged over defaults.
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
	 * Persist state (stamps updated_at). Not autoloaded.
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
	 *  Storage
	 * ----------------------------------------------------------------- */

	/**
	 * Directory the uploaded CSV is stored in (protected from web access).
	 *
	 * @return string
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'fraud-shield';
	}

	/**
	 * Create + harden the storage directory.
	 */
	private function ensure_storage_dir() {
		$dir = self::storage_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Best-effort protection of this PII-bearing directory.
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		$idx = $dir . '/index.html';
		if ( ! file_exists( $idx ) ) {
			file_put_contents( $idx, '' ); // phpcs:ignore
		}
	}

	/**
	 * Delete the uploaded CSV referenced by state (called on completion/cancel).
	 *
	 * @param array $state State.
	 */
	private function cleanup_file( $state ) {
		if ( ! empty( $state['file'] ) && is_file( $state['file'] ) ) {
			$dir = self::storage_dir();
			// Only ever delete inside our own storage directory.
			if ( 0 === strpos( wp_normalize_path( $state['file'] ), wp_normalize_path( $dir ) ) ) {
				wp_delete_file( $state['file'] );
			}
		}
	}

	/* ----------------------------------------------------------------- *
	 *  Upload + analysis
	 * ----------------------------------------------------------------- */

	/**
	 * Handle the CSV upload form: validate, store, analyze, then redirect back.
	 */
	public function handle_upload() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fraud-shield' ) );
		}
		check_admin_referer( 'fs_import_upload' );

		$redirect = admin_url( 'admin.php?page=fraud-shield-import' );

		if ( empty( $_FILES['fs_csv']['name'] ) || ! isset( $_FILES['fs_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'fs_status', 'nofile', $redirect ) );
			exit;
		}

		$name = sanitize_file_name( wp_unslash( $_FILES['fs_csv']['name'] ) );
		$tmp  = $_FILES['fs_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! is_uploaded_file( $tmp ) || ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'fs_status', 'badfile', $redirect ) );
			exit;
		}

		$this->ensure_storage_dir();
		$dest = self::storage_dir() . '/import-' . wp_generate_password( 16, false ) . '.csv';

		if ( ! @move_uploaded_file( $tmp, $dest ) ) { // phpcs:ignore
			wp_safe_redirect( add_query_arg( 'fs_status', 'movefail', $redirect ) );
			exit;
		}

		$analysis = $this->analyze_file( $dest );

		$state                = self::default_state();
		$state['status']      = 'ready';
		$state['phase']       = 'import';
		$state['file']        = $dest;
		$state['filename']    = $name;
		$state['delimiter']   = $analysis['delimiter'];
		$state['headers']     = $analysis['headers'];
		$state['mapping']     = $analysis['mapping'];
		$state['native']      = ! empty( $analysis['native'] );
		$state['data_offset'] = $analysis['data_offset'];
		$state['byte_offset'] = $analysis['data_offset'];
		$state['filesize']    = (int) filesize( $dest );
		$state['started_at']  = current_time( 'mysql' );
		$this->save_state( $state );

		wp_safe_redirect( add_query_arg( 'fs_status', 'uploaded', $redirect ) );
		exit;
	}

	/**
	 * Sniff the delimiter, read the header row, and auto-detect a mapping.
	 *
	 * @param string $path File path.
	 * @return array headers, delimiter, data_offset, mapping.
	 */
	private function analyze_file( $path ) {
		$out = array(
			'headers'     => array(),
			'delimiter'   => ',',
			'data_offset' => 0,
			'mapping'     => array(),
		);

		$fh = fopen( $path, 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			return $out;
		}

		$first = fgets( $fh );
		if ( false === $first ) {
			fclose( $fh ); // phpcs:ignore
			return $out;
		}
		$first = str_replace( "\xEF\xBB\xBF", '', $first ); // strip UTF-8 BOM.

		$candidates = array(
			','  => substr_count( $first, ',' ),
			';'  => substr_count( $first, ';' ),
			"\t" => substr_count( $first, "\t" ),
			'|'  => substr_count( $first, '|' ),
		);
		arsort( $candidates );
		$delim = key( $candidates );
		if ( 0 === $candidates[ $delim ] ) {
			$delim = ',';
		}

		rewind( $fh );
		$headers = fgetcsv( $fh, 0, $delim );
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}
		if ( isset( $headers[0] ) ) {
			$headers[0] = str_replace( "\xEF\xBB\xBF", '', (string) $headers[0] );
		}
		$headers = array_map(
			function ( $h ) {
				return trim( (string) $h );
			},
			$headers
		);

		$out['headers']     = $headers;
		$out['delimiter']   = $delim;
		$out['data_offset'] = ftell( $fh );
		$out['mapping']     = $this->autodetect_mapping( $headers );
		$out['native']      = $this->is_native_export( $headers );

		fclose( $fh ); // phpcs:ignore
		return $out;
	}

	/**
	 * Detect a Fraud Shield index export (columns that a generic order export
	 * would never contain), which we restore verbatim rather than remap.
	 *
	 * @param array $headers CSV header cells.
	 * @return bool
	 */
	private function is_native_export( $headers ) {
		$norm = array();
		foreach ( $headers as $h ) {
			$norm[] = strtolower( trim( (string) $h ) );
		}
		foreach ( array( 'ship_hash', 'email_norm', 'risk_score' ) as $marker ) {
			if ( ! in_array( $marker, $norm, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Known header aliases per field, for best-effort auto-mapping.
	 *
	 * @return array
	 */
	private static function aliases() {
		return array(
			'order_id'        => array( 'order id', 'order_id', 'order number', 'order_number', 'order' ),
			'order_date'      => array( 'order date', 'order_date', 'date created', 'date', 'created', 'order date created' ),
			'user_id'         => array( 'customer user id', 'customer id', 'user id', 'customer_user', 'user_id' ),
			'customer_name'   => array( 'customer name', 'full name', 'name', 'billing name' ),
			'first_name'      => array( 'billing first name', 'first name', 'billing_first_name', 'first' ),
			'last_name'       => array( 'billing last name', 'last name', 'billing_last_name', 'last' ),
			'email'           => array( 'billing email', 'email', 'billing_email', 'customer email', 'email address', 'e mail' ),
			'phone'           => array( 'billing phone', 'phone', 'billing_phone', 'telephone', 'phone number' ),
			'ship_address_1'  => array( 'shipping address 1', 'shipping address line 1', 'shipping_address_1', 'ship address 1', 'shipping street address', 'shipping address' ),
			'ship_address_2'  => array( 'shipping address 2', 'shipping address line 2', 'shipping_address_2', 'ship address 2' ),
			'ship_city'       => array( 'shipping city', 'shipping_city', 'ship city' ),
			'ship_state'      => array( 'shipping state', 'shipping region', 'shipping_state', 'ship state' ),
			'ship_postcode'   => array( 'shipping postcode', 'shipping zip', 'shipping_postcode', 'ship postcode', 'shipping zip code', 'postcode', 'zip' ),
			'ship_country'    => array( 'shipping country', 'shipping_country', 'ship country' ),
			'billing_country' => array( 'billing country', 'billing_country', 'country' ),
			'ip'              => array( 'customer ip address', 'ip address', 'customer_ip_address', 'ip', 'customer ip' ),
			'bill_address_1'  => array( 'billing address 1', 'billing address line 1', 'billing_address_1', 'billing street address', 'billing address' ),
			'bill_address_2'  => array( 'billing address 2', 'billing address line 2', 'billing_address_2' ),
			'bill_city'       => array( 'billing city', 'billing_city' ),
			'bill_state'      => array( 'billing state', 'billing region', 'billing_state' ),
			'bill_postcode'   => array( 'billing postcode', 'billing zip', 'billing_postcode', 'billing zip code' ),
			'order_total'     => array( 'order total', 'order_total', 'total', 'grand total', 'amount' ),
			'status'          => array( 'order status', 'status', 'order_status', 'post status' ),
			'payment_method'  => array( 'payment method title', 'payment method', 'payment_method', 'payment', 'payment type' ),
			'coupons'         => array( 'coupon codes', 'coupon code', 'coupons', 'coupon', 'discount codes', 'discount code' ),
			'user_agent'      => array( 'customer user agent', 'user agent', 'user_agent', 'browser' ),
		);
	}

	/**
	 * Normalize a header for comparison (lowercase alphanumerics + single spaces).
	 *
	 * @param string $h Header.
	 * @return string
	 */
	private function norm_header( $h ) {
		$h = strtolower( trim( (string) $h ) );
		$h = preg_replace( '/[^a-z0-9]+/', ' ', $h );
		return trim( preg_replace( '/\s+/', ' ', (string) $h ) );
	}

	/**
	 * Best-effort mapping of field => column index (-1 = unmapped).
	 *
	 * @param array $headers CSV header cells.
	 * @return array
	 */
	private function autodetect_mapping( $headers ) {
		$norm = array();
		foreach ( $headers as $i => $h ) {
			$norm[ $i ] = $this->norm_header( $h );
		}

		$map = array();
		foreach ( self::FIELDS as $field ) {
			$map[ $field ] = -1;
		}

		$aliases = self::aliases();

		// Pass 1: exact normalized matches.
		foreach ( $aliases as $field => $alist ) {
			foreach ( $alist as $a ) {
				$an = $this->norm_header( $a );
				foreach ( $norm as $i => $hn ) {
					if ( '' !== $hn && $hn === $an && ! in_array( $i, $map, true ) ) {
						$map[ $field ] = $i;
						break 2;
					}
				}
			}
		}

		// Pass 2: substring matches for anything still unmapped.
		foreach ( $aliases as $field => $alist ) {
			if ( -1 !== $map[ $field ] ) {
				continue;
			}
			foreach ( $alist as $a ) {
				$an = $this->norm_header( $a );
				foreach ( $norm as $i => $hn ) {
					if ( '' !== $an && '' !== $hn && false !== strpos( $hn, $an ) && ! in_array( $i, $map, true ) ) {
						$map[ $field ] = $i;
						break 2;
					}
				}
			}
		}

		return $map;
	}

	/* ----------------------------------------------------------------- *
	 *  AJAX: start / batch / cancel
	 * ----------------------------------------------------------------- */

	/**
	 * Guard shared by the AJAX endpoints (reuses the admin scan nonce).
	 */
	private function guard() {
		check_ajax_referer( 'fs_scan', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fraud-shield' ) ), 403 );
		}
	}

	/**
	 * AJAX: lock in the column mapping and begin importing.
	 */
	public function ajax_start() {
		$this->guard();
		$state = self::get_state();

		if ( empty( $state['file'] ) || ! is_file( $state['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No uploaded file found. Please upload a CSV first.', 'fraud-shield' ) ), 400 );
		}

		$raw     = isset( $_POST['mapping'] ) ? json_decode( wp_unslash( $_POST['mapping'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$headers = is_array( $state['headers'] ) ? $state['headers'] : array();
		$max     = count( $headers ) - 1;
		$mapping = array();
		foreach ( self::FIELDS as $field ) {
			$idx             = isset( $raw[ $field ] ) ? (int) $raw[ $field ] : -1;
			$mapping[ $field ] = ( $idx >= 0 && $idx <= $max ) ? $idx : -1;
		}

		if ( empty( $state['native'] ) && $mapping['order_id'] < 0 ) {
			wp_send_json_error( array( 'message' => __( 'You must map the Order ID column.', 'fraud-shield' ) ), 400 );
		}

		$state['mapping']      = $mapping;
		$state['status']       = 'running';
		$state['phase']        = 'import';
		$state['byte_offset']  = (int) $state['data_offset'];
		$state['row_offset']   = 0;
		$state['imported']     = 0;
		$state['score_offset'] = 0;
		$state['score_total']  = 0;

		wp_send_json_success( $this->save_state( $state ) );
	}

	/**
	 * AJAX: process the next batch (import rows, then score), return state.
	 */
	public function ajax_batch() {
		$this->guard();
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			wp_send_json_success( $state );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$deadline = microtime( true ) + self::TIME_BUDGET;

		if ( 'import' === $state['phase'] ) {
			if ( empty( $state['file'] ) || ! is_file( $state['file'] ) ) {
				wp_send_json_error( array( 'message' => __( 'The uploaded file is no longer available.', 'fraud-shield' ) ), 410 );
			}
			do {
				$before = (int) $state['byte_offset'];
				$this->run_import_batch( $state );
				if ( 'import' !== $state['phase'] || 'running' !== $state['status'] || (int) $state['byte_offset'] === $before ) {
					break; // reached EOF (→ score/complete) or made no progress.
				}
			} while ( microtime( true ) < $deadline );
		} elseif ( 'score' === $state['phase'] ) {
			do {
				$found                 = FS_Data::score_index_batch( (int) $state['score_offset'], self::SCORE_BATCH );
				$state['score_offset'] = (int) $state['score_offset'] + $found;
				if ( $found < self::SCORE_BATCH ) {
					$state['status'] = 'complete';
					$this->cleanup_file( $state );
					$state['file'] = '';
					break;
				}
			} while ( microtime( true ) < $deadline );
		} else {
			$state['status'] = 'complete';
		}

		wp_send_json_success( $this->save_state( $state ) );
	}

	/**
	 * AJAX: abandon the import and delete the uploaded file.
	 */
	public function ajax_cancel() {
		$this->guard();
		$this->cleanup_file( self::get_state() );
		delete_option( self::STATE_OPTION );
		wp_send_json_success( self::default_state() );
	}

	/* ----------------------------------------------------------------- *
	 *  Row processing
	 * ----------------------------------------------------------------- */

	/**
	 * Import one batch of CSV rows, advancing the byte cursor. On EOF, switch to
	 * the scoring phase.
	 *
	 * @param array $state State (by reference).
	 */
	private function run_import_batch( &$state ) {
		$settings = FS_Core::instance()->settings();
		$delim    = $state['delimiter'] ? $state['delimiter'] : ',';
		$mapping  = is_array( $state['mapping'] ) ? $state['mapping'] : array();

		$native = ! empty( $state['native'] );

		// For a native restore, map export column name → CSV position once.
		$hmap = array();
		if ( $native ) {
			foreach ( (array) $state['headers'] as $i => $h ) {
				$hmap[ strtolower( trim( (string) $h ) ) ] = $i;
			}
		}

		$fh = fopen( $state['file'], 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			$state['status'] = 'complete';
			return;
		}
		fseek( $fh, (int) $state['byte_offset'] );

		$processed = 0;
		$imported  = 0;
		while ( $processed < self::IMPORT_BATCH ) {
			$row = fgetcsv( $fh, 0, $delim );
			if ( false === $row ) {
				break;
			}
			++$processed;
			if ( array( null ) === $row ) {
				continue; // blank line.
			}

			if ( $native ) {
				$assoc = array();
				foreach ( FS_Data::export_columns() as $col ) {
					$assoc[ $col ] = isset( $hmap[ $col ], $row[ $hmap[ $col ] ] ) ? $row[ $hmap[ $col ] ] : '';
				}
				if ( (int) preg_replace( '/[^0-9]/', '', (string) $assoc['order_id'] ) <= 0 ) {
					continue;
				}
				FS_Data::restore_index_row( $assoc );
				++$imported;
				continue;
			}

			$signals = $this->signals_from_row( $row, $mapping );
			if ( $signals['order_id'] <= 0 ) {
				continue;
			}

			$status = $signals['status'];
			$is_cb  = ( 'charged-back' === $status )
				|| ( ! empty( $settings['count_refunded_as_chargeback'] ) && 'refunded' === $status );

			FS_Data::upsert_index( $signals, $is_cb );
			++$imported;
		}

		$state['byte_offset'] = ftell( $fh );
		$eof                  = feof( $fh );
		fclose( $fh ); // phpcs:ignore

		$state['row_offset'] = (int) $state['row_offset'] + $processed;
		$state['imported']   = (int) $state['imported'] + $imported;

		if ( $eof ) {
			if ( $native ) {
				// Restored rows already carry their scores — nothing to recompute.
				$state['status'] = 'complete';
				$this->cleanup_file( $state );
				$state['file'] = '';
			} else {
				$state['phase']        = 'score';
				$state['score_offset'] = 0;
				$state['score_total']  = FS_Data::index_count();
			}
		}
	}

	/**
	 * Read a mapped value from a CSV row.
	 *
	 * @param array  $row     Row cells.
	 * @param array  $mapping field => column index.
	 * @param string $field   Field key.
	 * @return string
	 */
	private function value( $row, $mapping, $field ) {
		if ( ! isset( $mapping[ $field ] ) ) {
			return '';
		}
		$i = (int) $mapping[ $field ];
		if ( $i < 0 || ! isset( $row[ $i ] ) ) {
			return '';
		}
		return trim( (string) $row[ $i ] );
	}

	/**
	 * Build normalized index signals from one CSV row.
	 *
	 * @param array $row     Row cells.
	 * @param array $mapping Column mapping.
	 * @return array
	 */
	private function signals_from_row( $row, $mapping ) {
		$name = $this->value( $row, $mapping, 'customer_name' );
		if ( '' === $name ) {
			$name = trim( $this->value( $row, $mapping, 'first_name' ) . ' ' . $this->value( $row, $mapping, 'last_name' ) );
		}

		$ship = array(
			'address_1' => $this->value( $row, $mapping, 'ship_address_1' ),
			'address_2' => $this->value( $row, $mapping, 'ship_address_2' ),
			'city'      => $this->value( $row, $mapping, 'ship_city' ),
			'state'     => $this->value( $row, $mapping, 'ship_state' ),
			'postcode'  => $this->value( $row, $mapping, 'ship_postcode' ),
			'country'   => $this->value( $row, $mapping, 'ship_country' ),
		);

		$ship_text = trim( $ship['address_1'] . ' ' . $ship['address_2'] . ', ' . $ship['city'] . ' ' . $ship['state'] . ' ' . $ship['postcode'] . ' ' . $ship['country'], " ,\t" );

		$email        = $this->value( $row, $mapping, 'email' );
		$bill_country = $this->value( $row, $mapping, 'billing_country' );
		$ship_country = '' !== $ship['country'] ? $ship['country'] : $bill_country;

		$bill = array(
			'address_1' => $this->value( $row, $mapping, 'bill_address_1' ),
			'address_2' => $this->value( $row, $mapping, 'bill_address_2' ),
			'city'      => $this->value( $row, $mapping, 'bill_city' ),
			'state'     => $this->value( $row, $mapping, 'bill_state' ),
			'postcode'  => $this->value( $row, $mapping, 'bill_postcode' ),
			'country'   => $bill_country,
		);
		$bill_hash = ( '' !== trim( $bill['address_1'] ) ) ? FS_Normalizer::hash( FS_Normalizer::address( $bill ) ) : '';

		$order_id_raw = preg_replace( '/[^0-9]/', '', $this->value( $row, $mapping, 'order_id' ) );
		$total_raw    = preg_replace( '/[^0-9.\-]/', '', str_replace( ',', '', $this->value( $row, $mapping, 'order_total' ) ) );

		$signals = array(
			'order_id'         => (int) $order_id_raw,
			'order_date'       => $this->normalize_date( $this->value( $row, $mapping, 'order_date' ) ),
			'user_id'          => (int) preg_replace( '/[^0-9]/', '', $this->value( $row, $mapping, 'user_id' ) ),
			'customer_name'    => mb_substr( $name, 0, 191 ),
			'email_norm'       => FS_Normalizer::email( $email ),
			'email_domain'     => FS_Normalizer::email_domain( $email ),
			'phone_norm'       => FS_Normalizer::phone( $this->value( $row, $mapping, 'phone' ) ),
			'ship_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $ship ) ),
			'ship_loose_hash'  => FS_Normalizer::hash( FS_Normalizer::address_loose( $ship ) ),
			'ship_text'        => mb_substr( $ship_text, 0, 255 ),
			'billing_country'  => mb_substr( $bill_country, 0, 8 ),
			'shipping_country' => mb_substr( $ship_country, 0, 8 ),
			'ip'               => mb_substr( $this->value( $row, $mapping, 'ip' ), 0, 64 ),
			'order_total'      => (float) $total_raw,
			'status'           => $this->normalize_status( $this->value( $row, $mapping, 'status' ) ),
			'payment_method'   => mb_substr( $this->value( $row, $mapping, 'payment_method' ), 0, 100 ),
			'coupons'          => mb_substr( $this->value( $row, $mapping, 'coupons' ), 0, 255 ),
			'user_agent'       => mb_substr( $this->value( $row, $mapping, 'user_agent' ), 0, 255 ),
			'bill_hash'        => $bill_hash,
		);

		// Populate geo only when the CSV actually supplied city/state/postcode.
		// When it didn't, leave the keys out so upsert_index() stores NULL and the
		// row stays eligible for the geo backfill (which reads the real order, if
		// it still exists). CSV-only rows for orders that no longer exist simply
		// won't get geography — there is nothing to read.
		$geo_city  = FS_Normalizer::city( $ship['city'] );
		$geo_state = FS_Normalizer::region( $ship['state'] );
		$geo_zip   = FS_Normalizer::postcode( $ship['postcode'] );
		if ( '' !== $geo_city || '' !== $geo_state || '' !== $geo_zip ) {
			$signals['ship_city']     = $geo_city;
			$signals['ship_state']    = $geo_state;
			$signals['ship_postcode'] = $geo_zip;
			// Set together with the rest of the geo, and stamped with the parser
			// scheme, so the backfill cursor means exactly "no current geo yet".
			$signals['ship_street']    = FS_Normalizer::street( $ship );
			$signals['ship_door_hash'] = FS_Normalizer::door( $ship );
			$signals['geo_scheme']     = FS_Data::GEO_SCHEME;
		}

		return $signals;
	}

	/**
	 * Normalize a status label into a WooCommerce status key.
	 *
	 * @param string $s Raw status.
	 * @return string
	 */
	private function normalize_status( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/^wc-/', '', $s );
		$s = preg_replace( '/\s+/', '-', $s );
		return (string) $s;
	}

	/**
	 * Normalize a CSV date into 'Y-m-d H:i:s' (best effort).
	 *
	 * @param string $raw Raw date string.
	 * @return string|null
	 */
	private function normalize_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $raw ) ) {
			$raw = str_replace( 'T', ' ', $raw );
			if ( 10 === strlen( $raw ) ) {
				$raw .= ' 00:00:00';
			} elseif ( 16 === strlen( $raw ) ) {
				$raw .= ':00';
			}
			return $raw;
		}
		$ts = strtotime( $raw );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
