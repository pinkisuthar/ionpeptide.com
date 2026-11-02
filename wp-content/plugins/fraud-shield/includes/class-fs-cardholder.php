<?php
/**
 * Cardholder check (v1.20): after a card payment, ask the NMI gateway for
 * the issuer's verdict on that exact transaction — the AVS address check,
 * the CVV security-code check, and any 3-D Secure evidence — and bind it
 * to the customer's ID-verification state.
 *
 * An ID check proves who the PERSON is; only the card issuer can attest
 * whose CARD it is. Together they answer the question staff actually ask:
 * "is the verified person also the cardholder?". An ID-verified customer
 * whose card passes the issuer's address check is recorded as cardholder-
 * confirmed. An ID-verified customer whose card FAILS it is the classic
 * stolen-card-with-real-ID pattern, and the failed check can flag the
 * order or put it On-Hold before it ships.
 *
 * Evidence comes from NMI's Query API: one POST per transaction with the
 * account's private security key. Several keys are supported — one per
 * MID — because rotator setups spread orders across NMI accounts; a
 * per-gateway cache remembers which key found which gateway's orders so
 * lookups usually hit the right MID first. Only result CODES and masked
 * card data are stored (brand, BIN, last four, NMI's card fingerprint);
 * the card number itself never reaches this site.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Cardholder {

	/**
	 * Rolling result log (options table, capped, autoload off).
	 */
	const LOG_OPTION = 'fs_card_checks';

	/**
	 * Cache of which security key found which gateway's transactions:
	 * [ gateway_id => md5( key ) ]. Rotators map each gateway to one MID,
	 * so remembering the pairing makes most lookups a single request.
	 */
	const KEYMAP_OPTION = 'fs_card_key_map';

	/**
	 * Single-event cron hook that performs the lookup out-of-band.
	 */
	const CRON_HOOK = 'fs_card_check_run';

	/**
	 * Scheduled attempts per order before giving up.
	 */
	const MAX_TRIES = 3;

	/**
	 * Log size cap.
	 */
	const LOG_CAP = 200;

	/**
	 * Outcomes that end the process — a re-check only happens manually.
	 *
	 * @var string[]
	 */
	private static $final = array( 'pass', 'partial', 'fail', 'unavailable', 'notfound', 'error' );

	/**
	 * Wire up.
	 */
	public function __construct() {
		// The lookup is queued, never run inline: woocommerce_payment_complete
		// fires inside the customer's checkout request, and a remote API call
		// there would slow every order down.
		add_action( 'woocommerce_payment_complete', array( $this, 'queue_check' ), 30, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 30, 4 );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ), 10, 1 );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20 );
		add_action( 'admin_post_fs_card_check_now', array( $this, 'handle_check_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	/* ===================== Configuration ===================== */

	/**
	 * Feature on and at least one key configured?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = FS()->settings();
		return ! empty( $s['card_check_enabled'] ) && count( self::keys() ) > 0;
	}

	/**
	 * Parse the configured security keys: one per line, either a bare key or
	 * "Label|key" (the label names the MID in results). '#' lines are comments.
	 *
	 * @return array[] Each: [ 'label' => string, 'key' => string ].
	 */
	public static function keys() {
		$raw  = (string) ( FS()->settings()['card_check_keys'] ?? '' );
		$keys = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$label = '';
			$key   = $line;
			if ( false !== strpos( $line, '|' ) ) {
				list( $label, $key ) = array_map( 'trim', explode( '|', $line, 2 ) );
			}
			if ( '' === $key ) {
				continue;
			}
			if ( '' === $label ) {
				/* translators: %d: position of the key in the settings list */
				$label = sprintf( __( 'Key %d', 'fraud-shield' ), count( $keys ) + 1 );
			}
			$keys[] = array(
				'label' => $label,
				'key'   => $key,
			);
			if ( count( $keys ) >= 20 ) {
				break;
			}
		}
		return $keys;
	}

	/**
	 * Query API endpoint (overridable for white-label NMI domains).
	 *
	 * @return string
	 */
	public static function endpoint() {
		$s   = FS()->settings();
		$url = trim( (string) ( $s['card_check_endpoint'] ?? '' ) );
		return '' !== $url ? $url : 'https://secure.nmi.com/api/query.php';
	}

	/* ===================== Queueing ===================== */

	/**
	 * Payment finished — schedule the lookup shortly after, out-of-band.
	 *
	 * @param int $order_id Order id.
	 */
	public function queue_check( $order_id ) {
		$this->maybe_schedule( (int) $order_id, 30 );
	}

	/**
	 * Safety net for gateways that complete payment via webhook or manual
	 * capture instead of the classic payment_complete path.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		if ( in_array( $to, array( 'processing', 'completed' ), true ) ) {
			$this->maybe_schedule( (int) $order_id, 30 );
		}
	}

	/**
	 * Schedule a lookup for the order unless it already has a final result,
	 * is out of scope, or is already queued.
	 *
	 * @param int $order_id Order id.
	 * @param int $delay    Seconds from now.
	 */
	private function maybe_schedule( $order_id, $delay ) {
		if ( $order_id <= 0 || ! self::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( in_array( (string) $order->get_meta( '_fs_card_outcome' ), self::$final, true ) ) {
			return;
		}
		$scope = (array) ( FS()->settings()['card_check_gateways'] ?? array() );
		if ( $scope && ! in_array( (string) $order->get_payment_method(), array_map( 'strval', $scope ), true ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $order_id ) ) ) {
			wp_schedule_single_event( time() + max( 5, (int) $delay ), self::CRON_HOOK, array( $order_id ) );
		}
	}

	/**
	 * Cron entry point.
	 *
	 * @param int $order_id Order id.
	 */
	public function run_scheduled( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			$this->run_check( $order, false );
		}
	}

	/* ===================== The check ===================== */

	/**
	 * Perform the lookup and record the result.
	 *
	 * Scheduled runs retry transient failures (network trouble, transaction
	 * not found yet) up to MAX_TRIES before recording a final failure. A
	 * manual run reports its problem to the staff member instead and never
	 * overwrites an existing result with a failure.
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $manual Staff-triggered from the order screen.
	 * @return string Notice code: ok|disabled|done|no_txn|network|notfound.
	 */
	public function run_check( $order, $manual = false ) {
		$s = FS()->settings();
		if ( empty( $s['card_check_enabled'] ) || ! count( self::keys() ) ) {
			return 'disabled';
		}

		$existing = (string) $order->get_meta( '_fs_card_outcome' );
		if ( ! $manual && in_array( $existing, self::$final, true ) ) {
			return 'done';
		}

		// Scheduled runs respect the gateway scope; a staff member clicking
		// "run check" on a specific order has already made that decision.
		if ( ! $manual ) {
			$scope = (array) ( $s['card_check_gateways'] ?? array() );
			if ( $scope && ! in_array( (string) $order->get_payment_method(), array_map( 'strval', $scope ), true ) ) {
				return 'disabled';
			}
		}

		$txn = self::transaction_id( $order );
		if ( '' === $txn ) {
			if ( ! $manual ) {
				$this->retry_or_finalize( $order, 'notfound', __( 'The order has no gateway transaction id, so there is nothing to look up.', 'fraud-shield' ) );
			}
			return 'no_txn';
		}

		$res = $this->lookup( $txn, (string) $order->get_payment_method() );

		if ( empty( $res['found'] ) ) {
			if ( ! empty( $res['network'] ) ) {
				if ( ! $manual ) {
					$this->retry_or_finalize( $order, 'error', __( 'The NMI Query API could not be reached.', 'fraud-shield' ) );
				}
				return 'network';
			}
			if ( ! $manual ) {
				$this->retry_or_finalize( $order, 'notfound', __( 'The transaction was not found on any configured NMI account. If this gateway settles through a MID whose key is not listed in Settings, add that key.', 'fraud-shield' ) );
			}
			return 'notfound';
		}

		$this->finalize_result( $order, $res );
		return 'ok';
	}

	/**
	 * The gateway transaction id, wherever the payment plugin put it.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function transaction_id( $order ) {
		$txn = trim( (string) $order->get_transaction_id() );
		if ( '' === $txn ) {
			foreach ( array( '_nmi_transaction_id', '_wc_nmi_trans_id' ) as $meta_key ) {
				$txn = trim( (string) $order->get_meta( $meta_key ) );
				if ( '' !== $txn ) {
					break;
				}
			}
		}
		/**
		 * Filter the transaction id used for the NMI cardholder check, for
		 * gateway plugins that store it somewhere non-standard.
		 *
		 * @param string   $txn   Transaction id ('' = none found).
		 * @param WC_Order $order The order.
		 */
		return trim( (string) apply_filters( 'fs_card_check_transaction_id', $txn, $order ) );
	}

	/**
	 * Ask each configured NMI account for the transaction, cached key first.
	 *
	 * @param string $txn        Transaction id.
	 * @param string $gateway_id Order's gateway id (for the key cache).
	 * @return array found=>bool [, network=>bool ] plus the parsed fields when found.
	 */
	private function lookup( $txn, $gateway_id ) {
		$keys = self::keys();

		// Try the key that found this gateway's transactions last time first.
		$map    = get_option( self::KEYMAP_OPTION, array() );
		$cached = ( is_array( $map ) && isset( $map[ $gateway_id ] ) ) ? (string) $map[ $gateway_id ] : '';
		if ( '' !== $cached ) {
			usort(
				$keys,
				function ( $a, $b ) use ( $cached ) {
					return ( md5( $b['key'] ) === $cached ) <=> ( md5( $a['key'] ) === $cached );
				}
			);
		}

		$network = false;
		foreach ( $keys as $k ) {
			$resp = wp_remote_post(
				self::endpoint(),
				array(
					'timeout' => 15,
					'body'    => array(
						'security_key'   => $k['key'],
						'transaction_id' => $txn,
					),
				)
			);
			if ( is_wp_error( $resp ) ) {
				$network = true;
				continue;
			}
			$body = wp_remote_retrieve_body( $resp );
			if ( 200 !== wp_remote_retrieve_response_code( $resp ) || '' === trim( $body ) ) {
				$network = true;
				continue;
			}
			$parsed = self::parse_response( $body );
			if ( null === $parsed ) {
				// A 200 with unparseable XML is a gateway hiccup, not a bad key.
				$network = true;
				continue;
			}
			if ( ! empty( $parsed['error'] ) || empty( $parsed['found'] ) ) {
				// Invalid key, or a valid key for a MID that does not hold
				// this transaction — either way, try the next account.
				continue;
			}

			$parsed['key_label'] = $k['label'];
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			if ( ( $map[ $gateway_id ] ?? '' ) !== md5( $k['key'] ) ) {
				$map[ $gateway_id ] = md5( $k['key'] );
				update_option( self::KEYMAP_OPTION, $map, false );
			}
			return $parsed;
		}

		return array(
			'found'   => false,
			'network' => $network,
		);
	}

	/**
	 * Parse an NMI Query API response.
	 *
	 * @param string $body Raw XML.
	 * @return array|null found/error array, or null when unparseable.
	 */
	public static function parse_response( $body ) {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			return null;
		}
		if ( isset( $xml->error_response ) && '' !== trim( (string) $xml->error_response ) ) {
			return array( 'error' => trim( (string) $xml->error_response ) );
		}
		if ( ! isset( $xml->transaction ) ) {
			return array( 'found' => false );
		}

		$t  = $xml->transaction; // First transaction — we query by id, so there is one.
		$cc = (string) $t->cc_number;

		return array(
			'found'       => true,
			'txn'         => (string) $t->transaction_id,
			'condition'   => (string) $t->condition,
			'avs'         => strtoupper( trim( (string) $t->avs_response ) ),
			'csc'         => strtoupper( trim( (string) $t->csc_response ) ),
			'brand'       => (string) $t->cc_type,
			'bin'         => (string) $t->cc_bin,
			'last4'       => strlen( $cc ) >= 4 ? substr( $cc, -4 ) : '',
			'cc_hash'     => (string) $t->cc_hash,
			'tds_version' => (string) $t->three_ds_version,
			'eci'         => (string) $t->eci,
			'cavv_result' => (string) $t->cavv_result,
		);
	}

	/* ===================== Classification ===================== */

	/**
	 * How strong is this AVS code as address evidence?
	 *
	 * @param string $code AVS response code.
	 * @return string full|partial|none|unknown
	 */
	public static function avs_class( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( in_array( $code, array( 'X', 'Y', 'D', 'M' ), true ) ) {
			return 'full';
		}
		if ( in_array( $code, array( 'A', 'B', 'W', 'Z', 'P' ), true ) ) {
			return 'partial';
		}
		if ( in_array( $code, array( 'N', 'C' ), true ) ) {
			return 'none';
		}
		return 'unknown';
	}

	/**
	 * Human meaning of an AVS code, for notes and screens.
	 *
	 * @param string $code AVS response code.
	 * @return string
	 */
	public static function avs_meaning( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		$map  = array(
			'X' => __( 'street address and 9-digit ZIP match the cardholder\'s bank records', 'fraud-shield' ),
			'Y' => __( 'street address and 5-digit ZIP match the cardholder\'s bank records', 'fraud-shield' ),
			'D' => __( 'street address and postal code match (international card)', 'fraud-shield' ),
			'M' => __( 'street address and postal code match (international card)', 'fraud-shield' ),
			'A' => __( 'street address matches, but the ZIP does not', 'fraud-shield' ),
			'B' => __( 'street address matches; postal code not verified (international card)', 'fraud-shield' ),
			'W' => __( '9-digit ZIP matches, but the street address does not', 'fraud-shield' ),
			'Z' => __( '5-digit ZIP matches, but the street address does not', 'fraud-shield' ),
			'P' => __( 'postal code matches; street address not verified (international card)', 'fraud-shield' ),
			'N' => __( 'neither the street address nor the ZIP matches the cardholder\'s bank records', 'fraud-shield' ),
			'C' => __( 'street address and postal code do not match (international card)', 'fraud-shield' ),
			'U' => __( 'address information is unavailable from the issuer', 'fraud-shield' ),
			'G' => __( 'the card was issued outside the US by a bank that does not participate in AVS', 'fraud-shield' ),
			'I' => __( 'address not verified (international card)', 'fraud-shield' ),
			'R' => __( 'the issuer\'s system was unavailable — the check did not run', 'fraud-shield' ),
			'E' => __( 'AVS is not supported for this transaction type', 'fraud-shield' ),
			'S' => __( 'the issuer does not support AVS', 'fraud-shield' ),
			'O' => __( 'AVS was not available for this transaction', 'fraud-shield' ),
			'0' => __( 'AVS was not available for this transaction', 'fraud-shield' ),
		);
		return $map[ $code ] ?? __( 'no AVS result was returned', 'fraud-shield' );
	}

	/**
	 * Human meaning of a CVV/CSC code.
	 *
	 * @param string $code CSC response code.
	 * @return string
	 */
	public static function csc_meaning( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		$map  = array(
			'M' => __( 'security code matched', 'fraud-shield' ),
			'N' => __( 'security code did NOT match', 'fraud-shield' ),
			'P' => __( 'security code not processed', 'fraud-shield' ),
			'S' => __( 'security code should be on the card but was not provided', 'fraud-shield' ),
			'U' => __( 'the issuer could not verify the security code', 'fraud-shield' ),
		);
		return $map[ $code ] ?? __( 'no security-code result was returned', 'fraud-shield' );
	}

	/**
	 * Combine AVS + CVV into one outcome.
	 *
	 * A CVV no-match fails outright — the code on the back of the card is
	 * possession evidence, and a wrong one on an approved payment is the
	 * loudest signal NMI returns. Otherwise the AVS class decides.
	 *
	 * @param string $avs            AVS code.
	 * @param string $csc            CSC code.
	 * @param bool   $accept_partial Count a partial address match as a pass.
	 * @return string pass|partial|fail|unavailable
	 */
	public static function classify( $avs, $csc, $accept_partial ) {
		$a = self::avs_class( $avs );
		$c = strtoupper( trim( (string) $csc ) );
		if ( 'N' === $c || 'none' === $a ) {
			return 'fail';
		}
		if ( 'full' === $a || ( 'partial' === $a && $accept_partial ) ) {
			return 'pass';
		}
		if ( 'partial' === $a ) {
			return 'partial';
		}
		return 'unavailable';
	}

	/* ===================== Recording ===================== */

	/**
	 * A transaction was found: classify it, store the evidence, write the
	 * order note, apply the failure policy, and log the row.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $res   Parsed transaction fields.
	 */
	private function finalize_result( $order, $res ) {
		$s       = FS()->settings();
		$outcome = self::classify( $res['avs'], $res['csc'], ! empty( $s['card_check_accept_partial'] ) );

		$email_norm = FS_Normalizer::email( (string) $order->get_billing_email() );
		$idv        = class_exists( 'FS_IDV' ) && FS_IDV::is_verified( (int) $order->get_customer_id(), $email_norm );

		$cardholder = '';
		if ( $idv && 'pass' === $outcome ) {
			$cardholder = 'confirmed';
		} elseif ( $idv && 'fail' === $outcome ) {
			$cardholder = 'mismatch';
		}

		$rec = array(
			'txn'         => (string) ( $res['txn'] ?? '' ),
			'checked_at'  => time(),
			'avs'         => (string) $res['avs'],
			'csc'         => (string) $res['csc'],
			'brand'       => (string) ( $res['brand'] ?? '' ),
			'bin'         => (string) ( $res['bin'] ?? '' ),
			'last4'       => (string) ( $res['last4'] ?? '' ),
			'cc_hash'     => (string) ( $res['cc_hash'] ?? '' ),
			'tds_version' => (string) ( $res['tds_version'] ?? '' ),
			'eci'         => (string) ( $res['eci'] ?? '' ),
			'cavv_result' => (string) ( $res['cavv_result'] ?? '' ),
			'condition'   => (string) ( $res['condition'] ?? '' ),
			'key_label'   => (string) ( $res['key_label'] ?? '' ),
			'gateway'     => (string) $order->get_payment_method(),
			'outcome'     => $outcome,
			'cardholder'  => $cardholder,
			'idv'         => $idv ? 1 : 0,
		);

		$order->update_meta_data( '_fs_card_check', $rec );
		$order->update_meta_data( '_fs_card_outcome', $outcome );
		$order->update_meta_data( '_fs_cardholder', $cardholder );
		$order->delete_meta_data( '_fs_card_tries' );
		$order->save();

		$order->add_order_note( self::note_text( $rec ) );

		// Failure policy. 'flag' needs nothing extra here: the note is on the
		// order and the row sits on the Card Checks page. 'hold' pulls a
		// paid-but-unshipped order out of fulfilment; completed orders are
		// left alone — flagging a shipped order is information, holding it
		// is fiction.
		$action  = (string) ( $s['card_check_action'] ?? 'flag' );
		$scope   = (string) ( $s['card_check_scope'] ?? 'all' );
		$applies = ( 'fail' === $outcome ) && ( 'all' === $scope || 'mismatch' === $cardholder );
		if ( $applies && 'hold' === $action && $order->has_status( array( 'processing' ) ) ) {
			$order->update_status(
				'on-hold',
				__( 'Fraud Shield: held — the card failed the issuer\'s cardholder check (see the card check note above).', 'fraud-shield' )
			);
		}

		$this->log_row(
			array(
				't'       => time(),
				'order'   => $order->get_id(),
				'email'   => $email_norm,
				'gw'      => (string) $order->get_payment_method(),
				'outcome' => $outcome,
				'avs'     => (string) $res['avs'],
				'csc'     => (string) $res['csc'],
				'brand'   => (string) ( $res['brand'] ?? '' ),
				'last4'   => (string) ( $res['last4'] ?? '' ),
				'tds'     => (string) ( $res['tds_version'] ?? '' ),
				'ch'      => $cardholder,
				'idv'     => $idv ? 1 : 0,
				'key'     => (string) ( $res['key_label'] ?? '' ),
			)
		);

		// The issuer's verdict is the most actionable thing this plugin learns
		// after the fact, and it arrives out-of-band on cron — long after any
		// API caller could have asked for it. Push it.
		FS_Webhooks::emit(
			'card.checked',
			array(
				'order_id'   => $order->get_id(),
				'user_id'    => (int) $order->get_customer_id(),
				'email_norm' => $email_norm,
				'gateway'    => (string) $order->get_payment_method(),
				'outcome'    => $outcome,
				'cardholder' => $cardholder,
				'id_verified' => (bool) $idv,
				'avs'        => (string) $res['avs'],
				'cvv'        => (string) $res['csc'],
				'brand'      => (string) ( $res['brand'] ?? '' ),
				'last4'      => (string) ( $res['last4'] ?? '' ),
				'three_ds'   => (string) ( $res['tds_version'] ?? '' ),
			)
		);
	}

	/**
	 * The order note staff read, in plain language.
	 *
	 * @param array $rec Stored check record.
	 * @return string
	 */
	public static function note_text( $rec ) {
		$labels = self::outcome_labels();

		$parts   = array();
		$parts[] = sprintf(
			/* translators: 1: outcome label, 2: AVS code, 3: AVS meaning, 4: CVV code, 5: CVV meaning */
			__( 'Fraud Shield card check (NMI): %1$s. AVS %2$s — %3$s. CVV %4$s — %5$s.', 'fraud-shield' ),
			strtoupper( $labels[ $rec['outcome'] ] ?? $rec['outcome'] ),
			'' !== $rec['avs'] ? $rec['avs'] : '—',
			self::avs_meaning( $rec['avs'] ),
			'' !== $rec['csc'] ? $rec['csc'] : '—',
			self::csc_meaning( $rec['csc'] )
		);

		if ( '' !== $rec['brand'] || '' !== $rec['last4'] ) {
			$parts[] = sprintf(
				/* translators: 1: card brand, 2: last four digits */
				__( 'Card: %1$s •••• %2$s.', 'fraud-shield' ),
				'' !== $rec['brand'] ? $rec['brand'] : __( 'card', 'fraud-shield' ),
				$rec['last4']
			);
		}
		if ( '' !== $rec['tds_version'] ) {
			/* translators: %s: 3-D Secure protocol version */
			$parts[] = sprintf( __( '3-D Secure %s was used on this payment.', 'fraud-shield' ), $rec['tds_version'] );
		}

		if ( 'confirmed' === $rec['cardholder'] ) {
			$parts[] = __( 'The customer is ID-verified and the card checks out: cardholder confirmed.', 'fraud-shield' );
		} elseif ( 'mismatch' === $rec['cardholder'] ) {
			$parts[] = __( 'The customer is ID-verified but the issuer\'s records say this card is NOT theirs — treat as a possible stolen card.', 'fraud-shield' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Retry a transient failure, or record the final failure once the
	 * attempts are spent.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $outcome Final outcome if we give up (error|notfound).
	 * @param string   $reason  Human reason.
	 */
	private function retry_or_finalize( $order, $outcome, $reason ) {
		$tries = (int) $order->get_meta( '_fs_card_tries' ) + 1;

		if ( $tries < self::MAX_TRIES ) {
			$order->update_meta_data( '_fs_card_tries', $tries );
			$order->save();
			wp_schedule_single_event( time() + 300 * $tries, self::CRON_HOOK, array( $order->get_id() ) );
			return;
		}

		$rec = array(
			'checked_at' => time(),
			'outcome'    => $outcome,
			'reason'     => $reason,
			'gateway'    => (string) $order->get_payment_method(),
		);
		$order->update_meta_data( '_fs_card_check', $rec );
		$order->update_meta_data( '_fs_card_outcome', $outcome );
		$order->delete_meta_data( '_fs_card_tries' );
		$order->save();

		/* translators: %s: reason the check could not complete */
		$order->add_order_note( sprintf( __( 'Fraud Shield card check (NMI): could not complete — %s', 'fraud-shield' ), $reason ) );

		$this->log_row(
			array(
				't'       => time(),
				'order'   => $order->get_id(),
				'email'   => FS_Normalizer::email( (string) $order->get_billing_email() ),
				'gw'      => (string) $order->get_payment_method(),
				'outcome' => $outcome,
				'avs'     => '',
				'csc'     => '',
				'brand'   => '',
				'last4'   => '',
				'tds'     => '',
				'ch'      => '',
				'idv'     => 0,
				'key'     => '',
			)
		);
	}

	/* ===================== Log ===================== */

	/**
	 * Prepend a row to the rolling log (newest first, capped).
	 *
	 * @param array $row Log row.
	 */
	private function log_row( $row ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		// One row per order: a manual re-check replaces the old row instead
		// of duplicating the order in the list.
		$order_id = (int) ( $row['order'] ?? 0 );
		$log      = array_values(
			array_filter(
				$log,
				function ( $r ) use ( $order_id ) {
					return (int) ( $r['order'] ?? 0 ) !== $order_id;
				}
			)
		);
		array_unshift( $log, $row );
		if ( count( $log ) > self::LOG_CAP ) {
			$log = array_slice( $log, 0, self::LOG_CAP );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * All logged rows, newest first.
	 *
	 * @return array[]
	 */
	public static function log_rows() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * The most recent check for a customer email (for profile screens).
	 *
	 * @param string $email_norm Normalized email.
	 * @return array|null
	 */
	public static function latest_for_email( $email_norm ) {
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' === $email_norm ) {
			return null;
		}
		foreach ( self::log_rows() as $row ) {
			if ( ( $row['email'] ?? '' ) === $email_norm ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Outcome display labels.
	 *
	 * @return array
	 */
	public static function outcome_labels() {
		return array(
			'pass'        => __( 'Pass', 'fraud-shield' ),
			'partial'     => __( 'Partial match', 'fraud-shield' ),
			'fail'        => __( 'Fail', 'fraud-shield' ),
			'unavailable' => __( 'No verdict', 'fraud-shield' ),
			'notfound'    => __( 'Not found', 'fraud-shield' ),
			'error'       => __( 'Error', 'fraud-shield' ),
		);
	}

	/**
	 * Pill class for an outcome.
	 *
	 * @param string $outcome Outcome.
	 * @return string
	 */
	public static function outcome_pill( $outcome ) {
		switch ( $outcome ) {
			case 'pass':
				return 'fs-pill fs-pill-green';
			case 'partial':
				return 'fs-pill fs-pill-amber';
			case 'fail':
				return 'fs-pill fs-pill-red';
			default:
				return 'fs-pill fs-pill-black';
		}
	}

	/* ===================== Order screen ===================== */

	/**
	 * Register the order-screen box (HPOS-aware, same as the risk box).
	 */
	public function add_meta_box() {
		if ( ! self::enabled() ) {
			return;
		}
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box( 'fs_card_check', __( 'Fraud Shield — Card Check', 'fraud-shield' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	/**
	 * Render the box: verdict, evidence, and the manual run button.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$rec    = $order->get_meta( '_fs_card_check' );
		$rec    = is_array( $rec ) ? $rec : array();
		$labels = self::outcome_labels();

		if ( empty( $rec ) ) {
			$txn = self::transaction_id( $order );
			if ( '' === $txn ) {
				echo '<p class="fs-muted">' . esc_html__( 'No card check yet — the order has no gateway transaction id to look up.', 'fraud-shield' ) . '</p>';
			} else {
				echo '<p class="fs-muted">' . esc_html__( 'No card check yet.', 'fraud-shield' ) . '</p>';
			}
		} else {
			$outcome = (string) ( $rec['outcome'] ?? '' );
			echo '<p><span class="' . esc_attr( self::outcome_pill( $outcome ) ) . '">' . esc_html( $labels[ $outcome ] ?? $outcome ) . '</span></p>';

			if ( 'confirmed' === ( $rec['cardholder'] ?? '' ) ) {
				echo '<p style="color:#1a7a2e;font-weight:600;">' . esc_html__( 'Cardholder confirmed: the customer is ID-verified and the card matches the issuer\'s records.', 'fraud-shield' ) . '</p>';
			} elseif ( 'mismatch' === ( $rec['cardholder'] ?? '' ) ) {
				echo '<p style="color:#b32d2e;font-weight:600;">' . esc_html__( 'Cardholder MISMATCH: the customer is ID-verified but the issuer\'s records say this card is not theirs. Treat as a possible stolen card.', 'fraud-shield' ) . '</p>';
			}

			if ( isset( $rec['avs'] ) ) {
				echo '<p><strong>' . esc_html__( 'Address (AVS):', 'fraud-shield' ) . '</strong> ' . esc_html( '' !== $rec['avs'] ? $rec['avs'] : '—' ) . ' <span class="fs-muted">' . esc_html( self::avs_meaning( $rec['avs'] ?? '' ) ) . '</span></p>';
				echo '<p><strong>' . esc_html__( 'Security code (CVV):', 'fraud-shield' ) . '</strong> ' . esc_html( '' !== ( $rec['csc'] ?? '' ) ? $rec['csc'] : '—' ) . ' <span class="fs-muted">' . esc_html( self::csc_meaning( $rec['csc'] ?? '' ) ) . '</span></p>';
			}
			if ( ! empty( $rec['brand'] ) || ! empty( $rec['last4'] ) ) {
				echo '<p><strong>' . esc_html__( 'Card:', 'fraud-shield' ) . '</strong> ' . esc_html( trim( ( $rec['brand'] ?? '' ) . ' •••• ' . ( $rec['last4'] ?? '' ) ) );
				if ( ! empty( $rec['bin'] ) ) {
					echo ' <span class="fs-muted">(' . esc_html__( 'BIN', 'fraud-shield' ) . ' ' . esc_html( $rec['bin'] ) . ')</span>';
				}
				echo '</p>';
			}
			if ( ! empty( $rec['tds_version'] ) ) {
				echo '<p><strong>' . esc_html__( '3-D Secure:', 'fraud-shield' ) . '</strong> ' . esc_html( $rec['tds_version'] );
				if ( ! empty( $rec['cavv_result'] ) ) {
					echo ' <span class="fs-muted">(' . esc_html__( 'CAVV result', 'fraud-shield' ) . ' ' . esc_html( $rec['cavv_result'] ) . ')</span>';
				}
				echo '</p>';
			}
			if ( ! empty( $rec['reason'] ) ) {
				echo '<p class="fs-muted">' . esc_html( $rec['reason'] ) . '</p>';
			}
			if ( ! empty( $rec['checked_at'] ) ) {
				echo '<p class="fs-muted">' . esc_html(
					sprintf(
						/* translators: 1: date, 2: NMI key label */
						__( 'Checked %1$s%2$s.', 'fraud-shield' ),
						date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $rec['checked_at'] + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ),
						! empty( $rec['key_label'] ) ? ' · ' . $rec['key_label'] : ''
					)
				) . '</p>';
			}
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=fs_card_check_now&order_id=' . $order->get_id() ),
			'fs_card_check_' . $order->get_id()
		);
		echo '<p><a href="' . esc_url( $url ) . '" class="button">' . ( empty( $rec ) ? esc_html__( 'Run card check now', 'fraud-shield' ) : esc_html__( 'Re-run card check', 'fraud-shield' ) ) . '</a></p>';
	}

	/**
	 * Staff clicked "run card check" on an order.
	 */
	public function handle_check_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fraud-shield' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		check_admin_referer( 'fs_card_check_' . $order_id );

		$order = wc_get_order( $order_id );
		$code  = 'disabled';
		if ( $order ) {
			$code = $this->run_check( $order, true );
		}

		$back = wp_get_referer();
		if ( ! $back && $order ) {
			$back = $order->get_edit_order_url();
		}
		wp_safe_redirect( add_query_arg( 'fs_card_notice', rawurlencode( $code ), $back ? $back : admin_url() ) );
		exit;
	}

	/**
	 * Show the outcome of a manual run after the redirect.
	 */
	public function maybe_notice() {
		if ( ! isset( $_GET['fs_card_notice'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$code = sanitize_key( wp_unslash( $_GET['fs_card_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$map  = array(
			'ok'       => array( 'success', __( 'Card check complete — the result is on the order.', 'fraud-shield' ) ),
			'done'     => array( 'info', __( 'This order already has a final card-check result.', 'fraud-shield' ) ),
			'no_txn'   => array( 'warning', __( 'Card check not possible: the order has no gateway transaction id.', 'fraud-shield' ) ),
			'network'  => array( 'error', __( 'Card check failed: the NMI Query API could not be reached. The existing result (if any) was kept.', 'fraud-shield' ) ),
			'notfound' => array( 'warning', __( 'The transaction was not found on any configured NMI account. If this gateway settles through another MID, add its security key in Settings. The existing result (if any) was kept.', 'fraud-shield' ) ),
			'disabled' => array( 'warning', __( 'The cardholder check is not enabled, or no NMI security keys are configured in Settings.', 'fraud-shield' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] )
		);
	}
}
