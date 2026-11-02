<?php
/**
 * Live detection + enforcement.
 *
 * - Index + score every new order.
 * - Pre-payment checkout validation (classic checkout): blocklist + score block.
 * - Post-creation backstop (covers block-based / Store API checkout): hold or
 *   cancel high-risk orders that slipped past validation.
 * - Order-screen meta box with score, reasons, and one-click actions.
 *
 * Nothing here blocks or holds anything unless Mode = Enforce. In Observe mode
 * it only records scores and writes order notes, so installing the plugin can
 * never silently break your checkout.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Live {

	/**
	 * Wire hooks.
	 */
	public function __construct() {
		// Pre-payment validation on the classic checkout.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 20, 2 );

		// Index + score EVERY order the moment it is created, regardless of how it
		// was created (classic checkout, blocks checkout, admin, REST API,
		// subscription renewals, imports). This is the always-on safety net that
		// keeps Fraud Shield current without manual scans.
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 2 );

		// Classic checkout: pre-payment block is handled in validate_checkout(); this is the post-creation backstop.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_created' ), 20, 1 );
		// Blocks / Store API checkout: reject blocked attempts while the order is
		// still the session's checkout-draft. A rejection here leaves the draft
		// reusable, so the customer's next attempt updates the SAME draft instead
		// of minting a new cancelled order per retry (the "duplicate orders" pileup).
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'on_store_api_draft_order' ), 20, 2 );
		// Blocks / Store API checkout backstop: this fires after the order is set
		// to pending but BEFORE payment, so anything the draft-stage check could
		// not see (exotic hook ordering, older Woo) is still stopped pre-payment.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order_processed' ), 20, 1 );

		// Keep the index fresh when status changes (e.g. you mark a chargeback).
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );

		// Re-assert the "hold the triggering order" state after payment completes:
		// payment_complete() runs AFTER on_order_created and flips the order to
		// processing, so without this a paid triggering order would not stay held.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20, 1 );

		// Restrict which payment gateways a customer may use. This one filter
		// governs classic checkout, block/Store-API checkout, AND WooCommerce's own
		// server-side "is this method available" validation.
		//
		// Deliberately the LAST word on the list: run this early (it used to be
		// priority 20) and any plugin that rebuilds the gateway list afterwards —
		// a payment rotator being the obvious case — silently undoes the
		// restriction. A fraud control has to win that race, so we sit at
		// PHP_INT_MAX and re-assert ourselves once everything else has registered.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrict_gateways' ), PHP_INT_MAX, 1 );
		add_action( 'wp_loaded', array( $this, 'reassert_gateway_filter' ), PHP_INT_MAX );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'maybe_gateway_notice' ), 5 );

		// Checkout presentation: shared stylesheet, plus the reassurance banner at
		// the top of checkout. The classic checkout has a hook for it; the blocks
		// checkout does not, so we prepend to the block's own output instead of
		// injecting with JavaScript (no flash of unstyled content).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_trust_banner' ), 5 );
		add_filter( 'render_block', array( $this, 'prepend_checkout_block_notices' ), 10, 2 );

		// Gate the checkout PAGE itself: identifiable blocked (or velocity-locked)
		// visitors never see the checkout form — only the configured message.
		add_action( 'template_redirect', array( $this, 'maybe_gate_checkout_page' ) );

		// Order meta box + its actions.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'wp_ajax_fs_order_action', array( $this, 'ajax_order_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_assets' ) );
	}

	/**
	 * Score a posted-but-not-yet-created checkout and block if policy says so.
	 *
	 * @param array    $data   Posted checkout fields.
	 * @param WP_Error $errors Error bag.
	 */
	public function validate_checkout( $data, $errors ) {
		$settings = FS_Core::instance()->settings();
		$signals  = $this->signals_from_checkout( $data );

		// The blocklist is an explicit merchant decision: it hard-blocks the
		// checkout in EVERY mode (Observe mode only affects heuristic scores).
		// Only a customer the merchant has marked Verified is exempt.
		if ( ! self::is_verified_customer( (int) $signals['user_id'] ) ) {
			$matches = FS_Blocklist::match( $signals );
			if ( ! empty( $matches ) ) {
				$evidence = FS_Blocklist::match_evidence( $matches, $signals );
				self::record_block( 'blocklist', $signals, implode( ', ', array_unique( wp_list_pluck( $matches, 'type' ) ) ), $evidence );
				self::prevented( 'blocklist', 'refused', $signals, null, FS_Blocklist::evidence_identities( $evidence ) );
				$errors->add( 'fs_blocked', wp_kses_post( self::pick_block_message( $matches, $settings ) ) );
				return;
			}
		}

		// A repeat attempt from a customer whose order the linked-identity rule
		// already cancelled (v1.26): refused here, with no new order, while the
		// link stands. Their first attempt became the cancelled order on record.
		$linked_message = $this->linked_gate_refusal( $signals, $settings );
		if ( null !== $linked_message ) {
			$errors->add( 'fs_linked', wp_kses_post( $linked_message ) );
			return;
		}

		// A staff-demanded ID verification blocks the order until the customer
		// clears it. Checked before the automatic locks (explicit human
		// decision) and before the verified-credential shortcut below, because
		// only the ID check itself — not an allowlisted email or a verified IP —
		// is allowed to answer this particular demand. The email is passed so
		// signing out and ordering as a guest doesn't walk past it.
		if ( FS_IDV::requires_verification( (int) $signals['user_id'], (string) $signals['email_norm'] ) ) {
			self::record_block( 'idv', $signals, 'id verification requested' );
			self::prevented( 'idv', 'refused', $signals );
			$errors->add( 'fs_idv_required', wp_kses_post( self::idv_required_message( (int) $signals['user_id'], $settings ) ) );
			return;
		}

		// A verified credential (account flag, verified/allowlisted email, or a
		// verified shipping address / IP) bypasses the velocity lock, the hold,
		// and score-based blocking below — but NOT the blocklist above.
		$sig_verified = self::is_signal_verified( $signals );

		// Velocity lock is a deliberate, separately-configured control and applies
		// regardless of Observe/Enforce mode. In "restrict" mode the customer is
		// NOT blocked here — restrict_gateways() limits their payment methods instead.
		// Velocity rule, "block" action only: "hold" lets the order through to
		// be placed On-Hold after creation; "restrict" limits gateways instead.
		if ( ! $sig_verified
			&& ! empty( $settings['velocity_lock'] )
			&& 'block' === self::velocity_action( $settings )
			&& self::is_velocity_locked( (int) $signals['user_id'], $signals['email_norm'], 0, (string) ( $signals['phone_norm'] ?? '' ), (string) ( $signals['ship_door_hash'] ?? '' ) ) ) {
			self::record_block( 'velocity', $signals );
			self::prevented( 'velocity', 'refused', $signals );
			$errors->add( 'fs_velocity_lock', wp_kses_post( $settings['velocity_lock_message'] ) );
			return;
		}

		// New-account verification hold: an account already under hold cannot place
		// another order until an admin verifies it. All modes; verified/allowlisted exempt.
		if ( ! $sig_verified && self::is_on_hold( (int) $signals['user_id'], $signals['email_norm'] ) ) {
			self::prevented( 'hold', 'refused', $signals );
			$errors->add( 'fs_hold', wp_kses_post( $settings['hold_message'] ) );
			return;
		}

		// Gateway allow-list, re-checked at submit. WooCommerce does validate the
		// chosen method against the filtered list, but that list was built before
		// this customer typed their email — so for a guest, or anyone registering
		// during checkout, the display-time filter had no identity to judge. Here
		// we know exactly who they are, so this is the authoritative check.
		if ( ! $this->validate_chosen_gateway( $signals, $errors, $settings ) ) {
			return;
		}

		// High-value hard rule: at or above the hard amount the order is refused
		// until a recent-enough ID check exists. Deliberately NOT behind the
		// $sig_verified shortcut below: this rule exists precisely to ask
		// otherwise-trusted customers to prove themselves again for an order of
		// this size. Only the staff Verified flag is exempt (inside the rule).
		$hv = self::high_value_rule( (int) $signals['user_id'], (string) $signals['email_norm'], self::order_amount() );
		if ( null !== $hv && 'hard' === $hv['mode'] ) {
			self::prevented( 'high_value', 'refused', $signals );
			$errors->add( 'fs_high_value', wp_kses_post( self::hv_block_message( (int) $signals['user_id'], $hv, $settings ) ) );
			return;
		}

		// Heuristic-score blocking only applies under Enforce.
		if ( 'enforce' !== $settings['mode'] || $sig_verified ) {
			return;
		}

		$profile = FS_Data::build_profile( $signals, 0 );
		$scored  = FS_Rules::score_profile( $profile, $settings );

		if ( 'block' === $scored['level'] || ( 'high' === $scored['level'] && 'block' === $settings['action_high'] ) ) {
			self::prevented( 'score', 'refused', $signals );
			$errors->add( 'fs_blocked', wp_kses_post( $settings['block_message'] ) );
		}
	}

	/**
	 * Reject a classic-checkout submission that chose a gateway this customer is
	 * not allowed to use.
	 *
	 * @param array    $signals  Normalized checkout signals.
	 * @param WP_Error $errors   Error bag.
	 * @param array    $settings Plugin settings.
	 * @return bool True when the chosen method is acceptable.
	 */
	private function validate_chosen_gateway( $signals, $errors, $settings ) {
		$chosen = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $chosen ) {
			return true;
		}
		// A free order needs no payment method; don't invent a reason to fail.
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->needs_payment() ) {
			return true;
		}

		if ( self::is_gateway_allowed_for( (int) $signals['user_id'], (string) $signals['email_norm'], $chosen, self::order_amount() ) ) {
			return true;
		}

		$errors->add( 'fs_gateway_not_allowed', wp_kses_post( $settings['gateway_restrict_reject_message'] ) );
		return false;
	}

	/**
	 * Has the merchant manually verified this customer? Verified customers are
	 * exempt from the blocklist and the velocity lock.
	 *
	 * @param int $user_id User id (0 = guest, never verified).
	 * @return bool
	 */
	public static function is_verified_customer( $user_id ) {
		return $user_id > 0 && 'yes' === get_user_meta( (int) $user_id, '_fs_verified', true );
	}

	/**
	 * Record a real checkout block into the durable ledger, from a signals array.
	 * The Checkout Locks page reads this — so it reflects who was actually
	 * stopped, not a reconstruction from the order index.
	 *
	 * @param string     $kind     'velocity' | 'blocklist' | 'idv' | 'linked'.
	 * @param array      $signals  Normalized signals (user_id, email_norm, customer_name).
	 * @param string     $detail   Human reason detail.
	 * @param array|null $evidence What matched, for staff (FS_Blocklist::match_evidence() or linked_evidence()).
	 */
	private static function record_block( $kind, $signals, $detail = '', $evidence = null ) {
		FS_Data::log_checkout_block(
			$kind,
			(int) ( $signals['user_id'] ?? 0 ),
			(string) ( $signals['email_norm'] ?? '' ),
			(string) ( $signals['customer_name'] ?? '' ),
			$detail,
			$evidence
		);
	}

	/**
	 * Record a checkout block into the ledger from a WC_Order (Store API path).
	 *
	 * @param string     $kind     'velocity' | 'blocklist' | 'idv' | 'linked'.
	 * @param WC_Order   $order    Order.
	 * @param string     $detail   Human reason detail.
	 * @param array|null $evidence What matched, for staff.
	 */
	private function record_order_block( $kind, $order, $detail = '', $evidence = null ) {
		FS_Data::log_checkout_block(
			$kind,
			(int) $order->get_customer_id(),
			FS_Normalizer::email( $order->get_billing_email() ),
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$detail,
			$evidence
		);
	}

	/**
	 * The staff-facing record of a linked-identity refusal: the links as
	 * found, each already naming the identities behind the shared credential.
	 *
	 * @param array $links Output of FS_Blocklist::linked().
	 * @return array { kind:'linked', at:string, items:array }
	 */
	private static function linked_evidence( $links ) {
		return array(
			'kind'  => 'linked',
			'at'    => current_time( 'mysql' ),
			'items' => array_values( (array) $links ),
		);
	}

	/**
	 * Emails on the velocity/hold allowlist. This is the guest-friendly
	 * complement to the account-level Verified flag (it releases a locked guest
	 * email). It does NOT override the blocklist — an explicit block decision.
	 *
	 * @return array Normalized emails.
	 */
	public static function allowlist() {
		$list = get_option( 'fs_email_allowlist', array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Whether a (normalized-on-the-fly) email is on the allowlist.
	 *
	 * @param string $email Email (raw or normalized).
	 * @return bool
	 */
	public static function is_email_allowlisted( $email ) {
		$email = FS_Normalizer::email( $email );
		return '' !== $email && in_array( $email, self::allowlist(), true );
	}

	/**
	 * Add an email to the allowlist.
	 *
	 * @param string $email Email.
	 * @return bool True when newly added.
	 */
	public static function allowlist_add( $email ) {
		$email = FS_Normalizer::email( $email );
		if ( '' === $email ) {
			return false;
		}
		$list = self::allowlist();
		if ( in_array( $email, $list, true ) ) {
			return false;
		}
		$list[] = $email;
		update_option( 'fs_email_allowlist', array_values( array_unique( $list ) ) );
		self::release_velocity_holds( 0, $email );

		// An allowlisted email is a release decision like a verified
		// credential, so it travels on the same topic with its own scope
		// (v1.30) — a subscriber mirroring "who is trusted" sees it.
		FS_Webhooks::emit(
			'customer.verified',
			array(
				'scope'      => 'allowlist',
				'type'       => 'email',
				'email_norm' => $email,
				'key'        => 'allow:' . $email,
				'origin'     => '',
			)
		);
		return true;
	}

	/**
	 * Remove an email from the allowlist.
	 *
	 * @param string $email Email.
	 * @return bool True when the email was on the list.
	 */
	public static function allowlist_remove( $email ) {
		$email = FS_Normalizer::email( $email );
		$list  = self::allowlist();
		$was   = in_array( $email, $list, true );
		update_option( 'fs_email_allowlist', array_values( array_diff( $list, array( $email ) ) ) );
		if ( $was ) {
			FS_Webhooks::emit(
				'customer.unverified',
				array(
					'scope'      => 'allowlist',
					'type'       => 'email',
					'email_norm' => $email,
					'key'        => 'allow:' . $email,
				)
			);
		}
		return $was;
	}

	/* ----------------------------------------------------------------- *
	 *  Account-level decisions shared by wp-admin and the API (v1.30)
	 * ----------------------------------------------------------------- */

	/**
	 * Mark an account Verified — the merchant's master override — and release
	 * everything that flag releases: open Checkout Locks rows, a verification
	 * hold (and the held order, if it was paid), and the credential-list
	 * mirror that puts them on the Verified Customers page.
	 *
	 * @param int $user_id Account id.
	 * @return bool False when the account does not exist.
	 */
	public static function verify_account( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return false;
		}
		$email = FS_Normalizer::email( $user->user_email );

		// 'yes', not 1: every reader compares against that exact string.
		update_user_meta( $user_id, '_fs_verified', 'yes' );
		if ( '' !== $email ) {
			self::verified_add( 'email', $email, $user->display_name );
		}
		FS_Data::resolve_checkout_blocks( $user_id, $email, array() );
		self::release_hold( $user_id );
		self::release_velocity_holds( $user_id, $email );
		return true;
	}

	/**
	 * Withdraw the Verified flag, and the credential-list mirror with it.
	 *
	 * @param int $user_id Account id.
	 * @return bool False when the account does not exist.
	 */
	public static function unverify_account( $user_id ) {
		$user_id = (int) $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return false;
		}
		delete_user_meta( $user_id, '_fs_verified' );
		$email = FS_Normalizer::email( $user->user_email );
		if ( '' !== $email ) {
			self::verified_remove( 'email', $email );
		}
		return true;
	}

	/**
	 * Lift a new-account verification hold. The held triggering order is
	 * advanced to processing ONLY if it was actually paid — an unpaid held
	 * order stays on-hold for the merchant to handle, never fabricated into a
	 * ready-to-ship state.
	 *
	 * @param int $user_id Account id.
	 * @return bool True when a hold was lifted.
	 */
	public static function release_hold( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$hold = get_user_meta( $user_id, '_fs_hold', true );
		if ( empty( $hold ) ) {
			return false;
		}
		delete_user_meta( $user_id, '_fs_hold' );
		if ( is_array( $hold ) && ! empty( $hold['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$held = wc_get_order( (int) $hold['order_id'] );
			if ( $held && 'on-hold' === $held->get_status() && $held->get_date_paid() ) {
				$held->update_status( 'processing', __( 'Fraud Shield: released after manual verification.', 'fraud-shield' ) . ' ' );
			}
		}
		return true;
	}

	/**
	 * Put an account on restricted checkout: it may pay only with the given
	 * gateways (plus, under the low-value threshold, the low-value set), and
	 * is released from the velocity lock, the hold and any open ID demand —
	 * the restriction itself is the release. Deliberately does NOT mark the
	 * account Verified: a restricted customer is not a trusted one, and that
	 * flag would void the very restriction being imposed.
	 *
	 * Stamps applied_at so the restriction lapses on its own after the
	 * configured window; re-saving restarts that clock.
	 *
	 * Callers decide whether an empty gateway list is acceptable: the API
	 * refuses it (a restriction with no allowed gateway fails open), the
	 * single-account admin form has always saved whatever was ticked.
	 *
	 * @param int   $user_id      Account id.
	 * @param array $gateways     Allowed gateway ids.
	 * @param float $low_amount   Under-this-amount threshold (0 = off).
	 * @param array $low_gateways Gateways allowed only under the threshold.
	 * @return array The stored record, or empty when the account does not exist.
	 */
	public static function apply_gw_restrict( $user_id, $gateways, $low_amount = 0, $low_gateways = array() ) {
		$user_id  = (int) $user_id;
		$gateways = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $gateways ) ) ) ) );
		$user     = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return array();
		}
		$rec = array(
			'gateways'          => $gateways,
			'lowvalue_amount'   => max( 0.0, (float) $low_amount ),
			'lowvalue_gateways' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $low_gateways ) ) ) ) ),
			'applied_at'        => time(),
		);
		update_user_meta( $user_id, '_fs_gw_restrict', $rec );

		// Released to restricted checkout: clear the hold and open block rows,
		// and an outstanding ID demand — otherwise the customer stays blocked
		// while disappearing from the queue that would have explained why.
		FS_Data::resolve_checkout_blocks( $user_id, FS_Normalizer::email( $user->user_email ), array() );
		if ( ! empty( get_user_meta( $user_id, '_fs_hold', true ) ) ) {
			delete_user_meta( $user_id, '_fs_hold' );
		}
		if ( class_exists( 'FS_IDV' ) ) {
			FS_IDV::clear_requirement( $user_id );
		}
		self::release_velocity_holds( $user_id, FS_Normalizer::email( $user->user_email ) );
		return $rec;
	}

	/**
	 * Exempt from the velocity lock and the new-account hold: a merchant-verified
	 * account OR an allowlisted email. (The blocklist keeps its stricter
	 * exemption — verified account only.)
	 *
	 * @param int    $user_id    User id.
	 * @param string $email_norm Normalized email.
	 * @return bool
	 */
	public static function is_exempt( $user_id, $email_norm ) {
		// A per-account gateway restriction is a RELEASE decision: the customer
		// may check out (on their limited gateways), so the velocity lock and the
		// hold no longer apply. It does not bypass the blocklist.
		return self::is_verified_customer( (int) $user_id )
			|| self::is_email_allowlisted( $email_norm )
			|| self::is_email_verified( $email_norm )
			|| FS_IDV::is_verified( (int) $user_id, (string) $email_norm )
			|| self::is_auto_verified( (int) $user_id, (string) $email_norm )
			|| self::has_gw_restrict( $user_id );
	}

	/**
	 * Whether an account has a per-user gateway restriction ("released to
	 * restricted checkout").
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function has_gw_restrict( $user_id ) {
		$r = self::per_user_gateway_restriction( (int) $user_id );
		return ! empty( $r['gateways'] );
	}

	/* ----------------------------------------------------------------- *
	 *  Verified credentials (v1.12): a green "known-good" list, the inverse
	 *  of the blocklist. Verified email / address / IP bypasses the velocity
	 *  lock, the new-account hold, and gateway restrictions — but NEVER an
	 *  explicit blocklist / destination-lock entry (an explicit block wins).
	 * ----------------------------------------------------------------- */

	/**
	 * Verified-credential map: [ "type:value" => created_at ]. Types:
	 * email (normalized), address (ship_hash), ip (trimmed).
	 *
	 * @return array
	 */
	public static function verified_creds() {
		$v = get_option( 'fs_verified_creds', array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Canonical storage key for a verified credential.
	 *
	 * @param string $type  email|address|ip.
	 * @param string $value Raw value.
	 * @return string '' when invalid.
	 */
	public static function verified_key( $type, $value ) {
		switch ( $type ) {
			case 'email':
				$v = FS_Normalizer::email( $value );
				return '' === $v ? '' : 'email:' . $v;
			case 'address':
				$v = trim( (string) $value );
				if ( 64 !== strlen( $v ) || ! ctype_xdigit( $v ) ) {
					$v = FS_Normalizer::hash( FS_Normalizer::canonicalize( $value ) );
				}
				return '' === $v ? '' : 'address:' . $v;
			case 'ip':
				$v = trim( (string) $value );
				return '' === $v ? '' : 'ip:' . $v;
			default:
				return '';
		}
	}

	/**
	 * Add a verified credential. Returns true when newly added.
	 *
	 * @param string $type  email|address|ip.
	 * @param string $value Raw value.
	 * @param string $label Optional human label stored alongside.
	 * @param string $src   Optional origin marker: '' = staff, 'idv' = earned by
	 *                      a completed ID verification. Lets a revoked ID check
	 *                      remove only what it added, never a staff decision.
	 * @param string $at    Optional original added-at date (CSV import round
	 *                      trip); '' stamps now.
	 * @return bool
	 */
	public static function verified_add( $type, $value, $label = '', $src = '', $at = '' ) {
		$key = self::verified_key( $type, $value );
		if ( '' === $key ) {
			return false;
		}
		$creds = self::verified_creds();
		if ( isset( $creds[ $key ] ) ) {
			return false;
		}
		$at = trim( (string) $at );
		if ( '' !== $at ) {
			// Accept anything strtotime understands; anything else stamps now
			// rather than storing junk in a date field. Parse and render in
			// the SAME timezone: these are naive local stamps (like
			// current_time"mysql" writes), so a valid date must round-trip
			// verbatim, not get shifted through UTC.
			$ts = strtotime( $at );
			$at = $ts ? date( 'Y-m-d H:i:s', $ts ) : ''; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}
		$creds[ $key ] = array(
			'at'    => '' !== $at ? $at : current_time( 'mysql' ),
			'by'    => get_current_user_id(),
			'label' => mb_substr( (string) $label, 0, 191 ),
			'src'   => sanitize_key( (string) $src ),
		);
		update_option( 'fs_verified_creds', $creds );

		FS_Webhooks::emit(
			'customer.verified',
			array(
				'scope'      => 'credential',
				'type'       => sanitize_key( (string) $type ),
				// Only the email type is worth forwarding as a readable address.
				// An address credential is already a hash; an IP credential is
				// NOT, so the key below carries it verbatim and is redacted by
				// name on subscriptions that asked for digests.
				'email_norm' => 'email' === $type ? FS_Normalizer::email( $value ) : '',
				'key'        => $key,
				'origin'     => sanitize_key( (string) $src ),
			)
		);

		return true;
	}

	/**
	 * Origin marker stored on a verified credential ('' for staff-added, or
	 * e.g. 'idv'). Credentials written before this was recorded read as ''.
	 *
	 * @param string $type  email|address|ip.
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function verified_src( $type, $value ) {
		$key   = self::verified_key( $type, $value );
		$creds = self::verified_creds();
		if ( '' === $key || ! isset( $creds[ $key ] ) || ! is_array( $creds[ $key ] ) ) {
			return '';
		}
		return (string) ( $creds[ $key ]['src'] ?? '' );
	}

	/**
	 * Remove a verified credential.
	 *
	 * @param string $type  email|address|ip.
	 * @param string $value Raw value.
	 */
	public static function verified_remove( $type, $value ) {
		$key   = self::verified_key( $type, $value );
		$creds = self::verified_creds();
		if ( '' !== $key && isset( $creds[ $key ] ) ) {
			unset( $creds[ $key ] );
			update_option( 'fs_verified_creds', $creds );

			FS_Webhooks::emit(
				'customer.unverified',
				array(
					'scope'      => 'credential',
					'type'       => sanitize_key( (string) $type ),
					'email_norm' => 'email' === $type ? FS_Normalizer::email( $value ) : '',
					'key'        => $key,
				)
			);
		}
	}

	/**
	 * Whether a single credential is verified.
	 *
	 * @param string $type  email|address|ip.
	 * @param string $value Raw value.
	 * @return bool
	 */
	public static function is_cred_verified( $type, $value ) {
		$key = self::verified_key( $type, $value );
		if ( '' === $key ) {
			return false;
		}
		$creds = self::verified_creds();
		return isset( $creds[ $key ] );
	}

	/**
	 * Whether a normalized email is verified.
	 *
	 * @param string $email_norm Normalized email.
	 * @return bool
	 */
	public static function is_email_verified( $email_norm ) {
		return '' !== (string) $email_norm && self::is_cred_verified( 'email', $email_norm );
	}

	/**
	 * Whether ANY credential on a signals array is verified (account flag, email,
	 * IP, or shipping address — exact or loose). Used at checkout enforcement to
	 * bypass velocity / hold / gateway restrictions (never the blocklist).
	 *
	 * @param array $s Normalized signals.
	 * @return bool
	 */
	public static function is_signal_verified( $s ) {
		if ( self::is_verified_customer( (int) ( $s['user_id'] ?? 0 ) ) ) {
			return true;
		}
		if ( self::is_email_allowlisted( $s['email_norm'] ?? '' ) ) {
			return true;
		}
		// A completed Didit ID verification (v1.14) counts as a verified
		// credential (account or email) — and like the rest, it never
		// bypasses an explicit blocklist entry.
		if ( FS_IDV::is_verified( (int) ( $s['user_id'] ?? 0 ), (string) ( $s['email_norm'] ?? '' ) ) ) {
			return true;
		}
		// An established customer with a clean history (v1.16) has earned the same
		// standing as a verified credential — again, never over the blocklist.
		if ( self::is_auto_verified( (int) ( $s['user_id'] ?? 0 ), (string) ( $s['email_norm'] ?? '' ) ) ) {
			return true;
		}
		$creds = self::verified_creds();
		if ( empty( $creds ) ) {
			return false;
		}
		$checks = array(
			'email:' . FS_Normalizer::email( $s['email_norm'] ?? '' ),
			'ip:' . trim( (string) ( $s['ip'] ?? '' ) ),
			'address:' . (string) ( $s['ship_hash'] ?? '' ),
			'address:' . (string) ( $s['ship_loose_hash'] ?? '' ),
		);
		foreach ( $checks as $key ) {
			// Skip the bare-prefix keys produced by an empty value.
			if ( in_array( $key, array( 'email:', 'ip:', 'address:' ), true ) ) {
				continue;
			}
			if ( isset( $creds[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a logged-in account is verification-exempt for the gateway filter:
	 * the account flag, or its account email being verified/allowlisted.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_account_gateway_exempt( $user_id ) {
		$user_id = (int) $user_id;
		if ( self::is_verified_customer( $user_id ) ) {
			return true;
		}
		// An ID-verified account (Didit, v1.14) unlocks the full gateway set,
		// exactly like a merchant-verified credential. Never bypasses the blocklist.
		if ( FS_IDV::is_user_verified( $user_id ) ) {
			return true;
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}
		$email = FS_Normalizer::email( $user->user_email );
		return self::is_email_allowlisted( $email )
			|| self::is_email_verified( $email )
			|| FS_IDV::is_email_verified( $email )
			|| self::is_auto_verified( $user_id, $email );
	}

	/**
	 * Whether a registered account is currently under a verification hold (the
	 * new-account high-value rule). Gated on the setting; exempt accounts are
	 * never held.
	 *
	 * @param int    $user_id    User id.
	 * @param string $email_norm Normalized email.
	 * @return bool
	 */
	public static function is_on_hold( $user_id, $email_norm = '' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['hold_enabled'] ) ) {
			return false;
		}
		if ( self::is_exempt( $user_id, $email_norm ) ) {
			return false;
		}
		$hold = get_user_meta( $user_id, '_fs_hold', true );
		return ! empty( $hold );
	}

	/**
	 * Index + score a freshly created order, persist a copy on the order, and
	 * write an order note. Shared by both the classic and Store API paths.
	 *
	 * @param WC_Order $order Order object.
	 * @return array { settings:array, scored:array, matches:array }
	 */
	private function evaluate_and_record( $order ) {
		$settings      = FS_Core::instance()->settings();
		$signals       = FS_Data::extract_signals( $order );
		$is_chargeback = FS_Data::is_chargeback( $order, $settings );

		FS_Data::upsert_index( $signals, $is_chargeback );

		$profile = FS_Data::build_profile( $signals, $order->get_id() );
		$scored  = FS_Rules::score_profile( $profile, $settings );
		$matches = FS_Blocklist::match( $signals );

		FS_Data::update_score( $order->get_id(), $scored['score'], $scored['level'], $scored['reasons'] );

		// Persist a copy on the order itself for quick reference.
		$order->update_meta_data( '_fs_risk_score', (int) $scored['score'] );
		$order->update_meta_data( '_fs_risk_level', $scored['level'] );
		$order->update_meta_data( '_fs_risk_reasons', wp_json_encode( $scored['reasons'] ) );

		$note_reasons = empty( $scored['reasons'] ) ? __( 'none', 'fraud-shield' ) : implode( '; ', $scored['reasons'] );
		$blk_note     = empty( $matches )
			? ''
			: ' ' . sprintf( /* translators: %s: comma-separated blocklist match types */ __( 'Blocklist match: %s.', 'fraud-shield' ), implode( ', ', wp_list_pluck( $matches, 'type' ) ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: risk level, 2: numeric score, 3: reasons, 4: optional blocklist note */
				__( 'Fraud Shield: %1$s risk (score %2$d). %3$s%4$s', 'fraud-shield' ),
				strtoupper( $scored['level'] ),
				(int) $scored['score'],
				$note_reasons,
				$blk_note
			)
		);

		$order->save();

		// Linked-identity check (v1.26): only when the rule is on, the order did
		// not match the blocklist outright (that path cancels on its own), and
		// the customer holds no verified standing. Computed here, once, so the
		// alert email below and the cancel decision in the caller agree.
		$links = array();
		if ( ! empty( $settings['linked_cancel_enabled'] ) && empty( $matches ) && ! self::is_signal_verified( $signals ) ) {
			$links = FS_Blocklist::linked( $signals, (array) ( $settings['linked_cancel_types'] ?? array() ) );
			// An IP alone is never grounds to cancel: without a second shared
			// credential the links are dropped here, so nothing downstream acts.
			if ( ! FS_Blocklist::linked_sufficient( $links ) ) {
				$links = array();
			}
		}

		$this->maybe_notify( $order, $scored, $matches, $settings, $links );
		$this->maybe_extend_blocks( $order, $signals, $matches, $settings );

		return array(
			'settings' => $settings,
			'scored'   => $scored,
			'matches'  => $matches,
			'signals'  => $signals,
			'links'    => $links,
		);
	}

	/**
	 * Cancel an order whose customer shares credentials with a blocked identity
	 * (v1.26), when the merchant has switched that rule on.
	 *
	 * Runs after the direct blocklist check in both post-creation handlers, so a
	 * plain blocklist hit keeps its own note and message. Stamps the evidence on
	 * the order (`_fs_linked_cancel`, read by the WooCommerce Orders column and
	 * the meta box), writes the ledger row behind the Auto-Cancelled page, logs
	 * the checkout block (Checkout Locks + the `checkout.blocked` webhook), and
	 * cancels the order. The caller aborts the checkout with the returned
	 * message so no payment is ever attempted against the cancelled order.
	 *
	 * @param WC_Order $order    The freshly created order.
	 * @param array    $decision Output of evaluate_and_record().
	 * @param array    $settings Plugin settings.
	 * @return string|null The customer-facing message when the order was cancelled, else null.
	 */
	private function maybe_linked_cancel( $order, $decision, $settings ) {
		if ( empty( $settings['linked_cancel_enabled'] ) || empty( $decision['links'] ) || ! empty( $decision['matches'] ) ) {
			return null;
		}

		$message = (string) ( $settings['linked_cancel_message'] ?? '' );
		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			$message = (string) $settings['block_message'];
		}

		// Already handled: a third-party checkout plugin re-firing the hook
		// must not write a second note, ledger row or block event. The one
		// thing a re-fire may do is repair: if the stamp was written but the
		// savings row never landed (the request died in between), the money
		// is counted now, from the links the stamp kept — still once.
		$stamped = self::linked_cancel_record( $order );
		if ( $stamped ) {
			if ( ! FS_Data::prevented_row_for_order( (int) $order->get_id() ) ) {
				self::prevented( 'linked', 'cancelled', $order, null, FS_Blocklist::link_identities( $stamped['links'] ) );
			}
			return $message;
		}

		$signals = $decision['signals'];
		$links   = $decision['links'];
		$summary = FS_Blocklist::linked_summary( $links );

		$order->update_meta_data(
			'_fs_linked_cancel',
			wp_json_encode(
				array(
					'at'      => current_time( 'mysql' ),
					'summary' => $summary,
					'links'   => array_values( $links ),
				)
			)
		);
		$order->save();

		if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			$order->update_status(
				'cancelled',
				/* translators: %s: what the customer shares with the blocklist, e.g. "shares IP 203.0.113.7 with blocked Jane Doe (jane@example.com)" */
				sprintf( __( 'Fraud Shield: cancelled automatically — this customer shares credentials with a blocked identity. %s.', 'fraud-shield' ), $summary ) . ' '
			);
		}

		FS_Data::log_linked_cancel( $order, $signals, $links, $summary );
		$this->record_order_block( 'linked', $order, self::linked_detail( $links ), self::linked_evidence( $links ) );
		// The savings row names whom the stop was tied to — the blocked
		// identity behind the shared credential — so the Financial Protection
		// estimate weighs this order by THEIR chargeback history (v1.30).
		self::prevented( 'linked', 'cancelled', $order, null, FS_Blocklist::link_identities( $links ) );

		// The order-level counterpart of the identity-level checkout.blocked
		// (v1.30): which order, how much, and the SHAPE of the links — the
		// credential types, degrees and the block cards they lead to. Never
		// the blocked party's own identifiers: block_card is redacted on
		// hashed subscriptions, and values and identities stay on the store.
		$shape = array();
		foreach ( (array) $links as $l ) {
			$shape[] = array(
				'via'        => (string) ( $l['via'] ?? '' ),
				'degree'     => (int) ( $l['degree'] ?? 0 ),
				'on_order'   => ! empty( $l['on_order'] ),
				'block_card' => (string) ( $l['action_title'] ?? '' ),
			);
		}
		FS_Webhooks::emit(
			'order.cancelled',
			array(
				'order_id'   => (int) $order->get_id(),
				'user_id'    => (int) ( $signals['user_id'] ?? 0 ),
				'email_norm' => (string) ( $signals['email_norm'] ?? '' ),
				'name'       => (string) ( $signals['customer_name'] ?? '' ),
				'total'      => (float) $order->get_total(),
				'currency'   => (string) $order->get_currency(),
				'gateway'    => (string) $order->get_payment_method(),
				'status'     => (string) $order->get_status(),
				'reason'     => 'linked_blocklist',
				'shares'     => array_values( array_unique( array_filter( array_map( 'strval', wp_list_pluck( (array) $links, 'via' ) ) ) ) ),
				'links'      => $shape,
			)
		);

		return $message;
	}

	/**
	 * The ledger / webhook detail for a linked block: credential TYPES only
	 * ("shares ip, address with a blocked identity"), like the blocklist kind's
	 * match types. The full summary, with values, stays on the order and the
	 * Auto-Cancelled page: a `checkout.blocked` payload's `detail` is not a
	 * redacted field, so it must never carry a third party's email or IP.
	 *
	 * @param array $links Output of FS_Blocklist::linked().
	 * @return string
	 */
	private static function linked_detail( $links ) {
		$types = array_values( array_unique( array_filter( array_map( 'strval', wp_list_pluck( (array) $links, 'via' ) ) ) ) );
		/* translators: %s: comma-separated credential types, e.g. "ip, address" */
		return sprintf( __( 'shares %s with a blocked identity', 'fraud-shield' ), implode( ', ', $types ) );
	}

	/**
	 * Refuse a checkout at the gate when it is a REPEAT from a customer whose
	 * order this rule already cancelled — and who is still linked (v1.26).
	 *
	 * The first attempt is allowed to become an order so there is something to
	 * cancel, list and review; that cancelled order is the evidence. Every
	 * later attempt from the same identity is refused before an order exists
	 * (the same way the blocklist refuses), counted against the ledger row, so
	 * a customer pressing Place Order ten times produces one cancelled order
	 * with ten attempts, not ten cancelled orders. Re-checks the link live, so
	 * removing the block, or verifying the customer, reopens checkout at once.
	 *
	 * @param array      $signals  Normalized checkout signals.
	 * @param array      $settings Plugin settings.
	 * @param float|null $amount   The attempt's grand total when the caller knows it (Store API draft); null reads the cart.
	 * @return string|null The refusal message, or null to let the checkout proceed.
	 */
	private function linked_gate_refusal( $signals, $settings, $amount = null ) {
		if ( empty( $settings['linked_cancel_enabled'] ) ) {
			return null;
		}
		$prior = FS_Data::linked_cancel_for_identity( (int) ( $signals['user_id'] ?? 0 ), (string) ( $signals['email_norm'] ?? '' ) );
		if ( ! $prior ) {
			return null;
		}
		if ( self::is_signal_verified( $signals ) ) {
			return null;
		}
		$links = FS_Blocklist::linked( $signals, (array) ( $settings['linked_cancel_types'] ?? array() ) );
		if ( ! FS_Blocklist::linked_sufficient( $links ) ) {
			return null;
		}
		FS_Data::bump_linked_cancel_attempt( (int) $prior['id'] );
		self::record_block( 'linked', $signals, self::linked_detail( $links ), self::linked_evidence( $links ) );
		self::prevented_linked_retry( $prior, $signals, $amount, $links );

		$message = (string) ( $settings['linked_cancel_message'] ?? '' );
		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			$message = (string) $settings['block_message'];
		}
		return $message;
	}

	/**
	 * Count a refused retry on the savings ledger without counting the
	 * customer's money twice.
	 *
	 * Their first attempt became the cancelled order and was recorded under
	 * that order. A retry on the same day is the same purchase — the same
	 * cart, minutes later, after reading the refusal — so it bumps that
	 * order's attempts and never adds a second amount. A return on a later
	 * day is a fresh attempt and is recorded as a refusal, once per day, the
	 * way every other rule's refusals are.
	 *
	 * @param array      $prior   The customer's auto-cancel ledger row (order_id, cancelled_at).
	 * @param array      $signals Normalized checkout signals.
	 * @param float|null $amount  The attempt's grand total when known; null reads the cart.
	 * @param array      $links   The links found on this attempt (FS_Blocklist::linked()), for whom the stop is tied to.
	 */
	private static function prevented_linked_retry( $prior, $signals, $amount = null, $links = array() ) {
		$order_id  = (int) ( $prior['order_id'] ?? 0 );
		$linked_to = FS_Blocklist::link_identities( $links );
		$same_day  = $order_id > 0
			&& substr( (string) ( $prior['cancelled_at'] ?? '' ), 0, 10 ) === substr( current_time( 'mysql' ), 0, 10 );
		if ( ! $same_day ) {
			self::prevented( 'linked', 'refused', $signals, $amount, $linked_to );
			return;
		}
		if ( null === $amount ) {
			$amount = self::cart_total();
		}
		if ( null === $amount ) {
			return;
		}
		// Same dedupe key as the cancelled order ('o:<id>'): the row's
		// attempts go up and the largest cart seen is kept; nothing is added.
		FS_Data::log_prevented(
			'linked',
			'cancelled',
			(float) $amount,
			function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			(int) ( $signals['user_id'] ?? 0 ),
			(string) ( $signals['email_norm'] ?? '' ),
			(string) ( $signals['customer_name'] ?? '' ),
			$order_id,
			$linked_to
		);
	}

	/**
	 * Record the money a stopped checkout carried, for the Dashboard's
	 * "Fraud prevented" panel. Called wherever Fraud Shield refuses an
	 * attempt or cancels an order it just let WooCommerce create.
	 *
	 * An order is counted once: a second call for an order already on the
	 * ledger (a re-fired checkout hook, a backstop that meets an order a
	 * sibling hook already cancelled) changes nothing — only a customer's
	 * refused retry, through prevented_linked_retry(), may bump an order
	 * row's attempts.
	 *
	 * @param string         $kind       The control that stopped it (see FS_Data::prevented_kinds()).
	 * @param string         $stage      'cancelled' (an order exists) or 'refused' (it never did).
	 * @param array|WC_Order $who        The order (cancelled), or the normalized signals (refused).
	 * @param float|null     $amount     Grand total; null reads the order's total, or the session cart's.
	 * @param array|null     $linked_to  The blocked identities the stop was tied to (FS_Blocklist::link_identities() / evidence_identities()).
	 * @param string         $stopped_by 'rule' (a control stopped it) | 'staff' (a person cancelled or refunded it).
	 */
	private static function prevented( $kind, $stage, $who, $amount = null, $linked_to = null, $stopped_by = 'rule' ) {
		if ( $who instanceof WC_Order ) {
			if ( 'cancelled' === $stage && FS_Data::prevented_row_for_order( (int) $who->get_id() ) ) {
				return;
			}
			FS_Data::log_prevented(
				$kind,
				$stage,
				null === $amount ? (float) $who->get_total() : (float) $amount,
				(string) $who->get_currency(),
				(int) $who->get_customer_id(),
				FS_Normalizer::email( $who->get_billing_email() ),
				trim( $who->get_billing_first_name() . ' ' . $who->get_billing_last_name() ),
				(int) $who->get_id(),
				$linked_to,
				$stopped_by
			);
			return;
		}
		if ( null === $amount ) {
			$amount = self::cart_total();
		}
		if ( null === $amount ) {
			return;
		}
		FS_Data::log_prevented(
			$kind,
			$stage,
			(float) $amount,
			function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			(int) ( $who['user_id'] ?? 0 ),
			(string) ( $who['email_norm'] ?? '' ),
			(string) ( $who['customer_name'] ?? '' ),
			0,
			$linked_to,
			$stopped_by
		);
	}

	/**
	 * The session cart's grand total, or null when there is no cart to read.
	 *
	 * @return float|null
	 */
	private static function cart_total() {
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			return (float) WC()->cart->get_total( 'edit' );
		}
		return null;
	}

	/**
	 * Auto-extend blocks: when an order matches an existing block action, link
	 * this order's own credentials (email, phone, IP, user, exact + loose
	 * address) to the owning action(s). This is how a blocked ring that keeps
	 * placing orders with fresh emails stays fully blocked without the merchant
	 * re-clicking "Block". O(1) per order — no cluster re-query.
	 *
	 * Gated behind the auto_extend_blocks setting (default on). Verified
	 * customers never expand a blocklist. Idempotent: re-runs add nothing.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $signals  Normalized signals for the order.
	 * @param array    $matches  Blocklist matches for this order.
	 * @param array    $settings Settings.
	 */
	private function maybe_extend_blocks( $order, $signals, $matches, $settings ) {
		if ( empty( $settings['auto_extend_blocks'] ) || empty( $matches ) ) {
			return;
		}
		if ( self::is_verified_customer( (int) ( $signals['user_id'] ?? 0 ) ) ) {
			return;
		}

		$entry_ids = array();
		foreach ( (array) $matches as $m ) {
			// NEVER auto-extend a destination lock. A 'place' match means this
			// order merely shipped to a locked city/state/ZIP — linking its
			// email/phone/IP into the lock's action would silently convert a geo
			// rule into a mass identity block of everyone who ships there (and,
			// transitively, everyone THEY share credentials with). Geo locks block
			// destinations, not people. An address-review stop (v1.31) is the
			// same kind of thing and owns no card at all.
			if ( isset( $m['type'] ) && in_array( $m['type'], array( 'place', 'review' ), true ) ) {
				continue;
			}
			if ( ! empty( $m['id'] ) ) {
				$entry_ids[] = (int) $m['id'];
			}
		}
		$action_ids = FS_Blocklist::actions_owning_entries( $entry_ids );
		if ( empty( $action_ids ) ) {
			return; // Matched only legacy/ungrouped rows — nothing to extend.
		}

		/* translators: %d: order number */
		$reason = sprintf( __( 'Auto-linked from order #%d', 'fraud-shield' ), $order->get_id() );
		$creds  = array(
			'email'   => (string) ( $signals['email_norm'] ?? '' ),
			'phone'   => (string) ( $signals['phone_norm'] ?? '' ),
			'ip'      => (string) ( $signals['ip'] ?? '' ),
			'user'    => ! empty( $signals['user_id'] ) ? (string) (int) $signals['user_id'] : '',
			'address' => (string) ( $signals['ship_hash'] ?? '' ),
		);
		$loose = (string) ( $signals['ship_loose_hash'] ?? '' );

		foreach ( $action_ids as $aid ) {
			$added = 0;
			$types = array();
			foreach ( $creds as $type => $val ) {
				if ( '' !== $val ) {
					$n = FS_Blocklist::add_and_link( $type, $val, $reason, '', $aid );
					if ( $n > 0 ) {
						$types[] = $type;
					}
					$added += $n;
				}
			}
			if ( '' !== $loose ) {
				$n = FS_Blocklist::add_and_link( 'address', $loose, $reason, '', $aid );
				if ( $n > 0 ) {
					$types[] = 'address';
				}
				$added += $n;
			}
			if ( $added > 0 ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: number of credentials, 2: block action id */
						_n( 'Fraud Shield: linked %1$d new credential to block action #%2$d.', 'Fraud Shield: linked %1$d new credentials to block action #%2$d.', $added, 'fraud-shield' ),
						$added,
						$aid
					)
				);
				// The card grew without anyone pressing a button (v1.30): a
				// subscriber mirroring the blocklist needs to hear about it.
				FS_Blocklist::emit_extended( FS_Blocklist::get_action( $aid ), $added, 'auto_extend', (int) $order->get_id(), $types );
			}
		}
	}

	/**
	 * Index + score every order at the moment of creation, no matter where it
	 * came from (admin, REST, renewals, imports — not just checkout). Quiet
	 * variant: no order note and no enforcement; the checkout hooks handle those
	 * for checkout orders.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order object (when provided by the hook).
	 */
	public function on_new_order( $order_id, $order = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Skip drafts; they are indexed when they become real orders.
		if ( in_array( $order->get_status(), array( 'checkout-draft', 'auto-draft', 'trash' ), true ) ) {
			return;
		}

		$settings      = FS_Core::instance()->settings();
		$signals       = FS_Data::extract_signals( $order );
		$is_chargeback = FS_Data::is_chargeback( $order, $settings );

		FS_Data::upsert_index( $signals, $is_chargeback );

		$profile = FS_Data::build_profile( $signals, $order->get_id() );
		$scored  = FS_Rules::score_profile( $profile, $settings );
		$matches = FS_Blocklist::match( $signals );

		FS_Data::update_score( $order->get_id(), $scored['score'], $scored['level'], $scored['reasons'] );

		$order->update_meta_data( '_fs_risk_score', (int) $scored['score'] );
		$order->update_meta_data( '_fs_risk_level', $scored['level'] );
		$order->update_meta_data( '_fs_risk_reasons', wp_json_encode( $scored['reasons'] ) );
		$order->save();

		$this->notify_or_defer( $order, $scored, $matches, $settings );
		$this->maybe_extend_blocks( $order, $signals, $matches, $settings );
	}

	/**
	 * Alerts deferred to the end of the request: order id => { scored, matches }.
	 *
	 * @var array
	 */
	private static $deferred_alerts = array();

	/**
	 * Send the risky-order alert now, or leave it to the checkout handler.
	 *
	 * woocommerce_new_order fires while a checkout is still creating the
	 * order, BEFORE woocommerce_checkout_order_processed decides whether the
	 * linked-identity rule cancels it. An alert sent from here would go out
	 * without that line and, being once-per-order, would silence the fuller
	 * one that follows (v1.26.2). So for a customer checkout the alert waits
	 * for the checkout handler, which knows the outcome; if that handler
	 * never runs (a third-party flow that skips the action), the alert is
	 * still sent at shutdown with what is known, so none is ever lost.
	 * Orders created anywhere else (admin, REST, renewals) are alerted here
	 * as before.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $scored   Scoring result.
	 * @param array    $matches  Blocklist matches.
	 * @param array    $settings Settings.
	 */
	private function notify_or_defer( $order, $scored, $matches, $settings ) {
		$via = method_exists( $order, 'get_created_via' ) ? (string) $order->get_created_via() : '';
		if ( ! in_array( $via, array( 'checkout', 'store-api' ), true ) ) {
			$this->maybe_notify( $order, $scored, $matches, $settings );
			return;
		}
		if ( empty( self::$deferred_alerts ) ) {
			add_action( 'shutdown', array( $this, 'flush_deferred_alerts' ), 5 );
		}
		self::$deferred_alerts[ (int) $order->get_id() ] = array(
			'scored'  => $scored,
			'matches' => $matches,
		);
	}

	/**
	 * End of request: alert any checkout-created order the checkout handler
	 * did not get to. Orders it did alert carry `_fs_notified` and are
	 * skipped, so nothing is ever sent twice.
	 */
	public function flush_deferred_alerts() {
		$pending                = self::$deferred_alerts;
		self::$deferred_alerts = array();
		if ( empty( $pending ) ) {
			return;
		}
		$settings = FS_Core::instance()->settings();
		foreach ( $pending as $id => $p ) {
			$order = wc_get_order( (int) $id );
			if ( ! $order || 'yes' === $order->get_meta( '_fs_notified' ) ) {
				continue;
			}
			$this->maybe_notify( $order, $p['scored'], $p['matches'], $settings );
		}
	}

	/**
	 * The message a blocked customer should see, by precedence:
	 *   1. per-action message  (the block action that owns the matched entry)
	 *   2. per-entry message    (a message set directly on the blocklist row)
	 *   3. global default       (settings['block_message'])
	 * Within each tier the first match wins, in match order
	 * (email → phone → ip → user → address).
	 *
	 * @param array $matches  Blocklist matches (from FS_Blocklist::match()).
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function pick_block_message( $matches, $settings ) {
		foreach ( (array) $matches as $m ) {
			if ( ! empty( $m['action_message'] ) ) {
				return (string) $m['action_message'];
			}
		}
		foreach ( (array) $matches as $m ) {
			if ( ! empty( $m['message'] ) ) {
				return (string) $m['message'];
			}
		}
		return (string) $settings['block_message'];
	}

	/**
	 * Email an alert about a risky order (once per order).
	 *
	 * Fires for any order at/above the configured level, or any order matching
	 * the blocklist. Works in Observe mode too — alerting is the whole point of
	 * observing.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $scored   Scoring result.
	 * @param array    $matches  Blocklist matches.
	 * @param array    $settings Settings.
	 * @param array    $links    Links to blocked identities (v1.26); when the
	 *                           auto-cancel rule is on these mean the order is
	 *                           about to be cancelled, which is worth an email
	 *                           at any risk level, like a blocklist match.
	 */
	private function maybe_notify( $order, $scored, $matches, $settings, $links = array() ) {
		if ( empty( $settings['notify_enabled'] ) ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_fs_notified' ) ) {
			return;
		}

		$rank = array(
			'low'    => 0,
			'medium' => 1,
			'high'   => 2,
			'block'  => 3,
		);
		$min  = isset( $settings['notify_min_level'], $rank[ $settings['notify_min_level'] ] ) ? $rank[ $settings['notify_min_level'] ] : 2;
		$lvl  = isset( $rank[ $scored['level'] ] ) ? $rank[ $scored['level'] ] : 0;

		if ( $lvl < $min && empty( $matches ) && empty( $links ) ) {
			return;
		}

		$recipients = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $settings['notify_emails'] ) ), 'is_email' );
		if ( empty( $recipients ) ) {
			return;
		}

		$edit_url = ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() )
			? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() )
			: admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

		$ip  = $order->get_customer_ip_address();
		$loc = '';
		if ( $ip && class_exists( 'WC_Geolocation' ) ) {
			$geo = WC_Geolocation::geolocate_ip( $ip, false, false );
			$loc = ( is_array( $geo ) && ! empty( $geo['country'] ) ) ? $geo['country'] : '';
		}

		/* translators: 1: risk level, 2: order number, 3: score */
		$subject = sprintf( __( '[Fraud Shield] %1$s risk order #%2$d (score %3$d)', 'fraud-shield' ), strtoupper( $scored['level'] ), $order->get_id(), (int) $scored['score'] );

		$lines   = array();
		$lines[] = sprintf( __( 'Order: #%d', 'fraud-shield' ), $order->get_id() );
		$lines[] = sprintf( __( 'Risk: %1$s (score %2$d)', 'fraud-shield' ), strtoupper( $scored['level'] ), (int) $scored['score'] );
		$lines[] = sprintf( __( 'Total: %s', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ) );
		$lines[] = sprintf( __( 'Payment method: %s', 'fraud-shield' ), $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method() );
		$lines[] = sprintf( __( 'Customer: %1$s <%2$s> %3$s', 'fraud-shield' ), trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), $order->get_billing_email(), $order->get_billing_phone() );
		$lines[] = sprintf( __( 'IP: %1$s %2$s', 'fraud-shield' ), $ip ? $ip : '—', $loc ? '(' . $loc . ')' : '' );
		$lines[] = '';
		$lines[] = __( 'Signals:', 'fraud-shield' );
		if ( empty( $scored['reasons'] ) ) {
			$lines[] = '- ' . __( 'none', 'fraud-shield' );
		} else {
			foreach ( $scored['reasons'] as $r ) {
				$lines[] = '- ' . $r;
			}
		}
		if ( ! empty( $matches ) ) {
			$lines[] = '';
			/* translators: %s: comma-separated blocklist match types */
			$lines[] = sprintf( __( 'BLOCKLIST MATCH: %s', 'fraud-shield' ), implode( ', ', wp_list_pluck( $matches, 'type' ) ) );
		}
		if ( ! empty( $links ) ) {
			$lines[] = '';
			/* translators: %s: what the customer shares with the blocklist */
			$lines[] = sprintf( __( 'LINKED TO THE BLOCKLIST — order cancelled automatically: %s', 'fraud-shield' ), FS_Blocklist::linked_summary( $links, 5 ) );
			$lines[] = sprintf( __( 'Auto-cancelled orders: %s', 'fraud-shield' ), admin_url( 'admin.php?page=fraud-shield-cancels' ) );
		}
		$lines[] = '';
		$lines[] = sprintf( __( 'Review the order: %s', 'fraud-shield' ), $edit_url );
		$lines[] = sprintf( __( 'Fraud Shield dashboard: %s', 'fraud-shield' ), admin_url( 'admin.php?page=fraud-shield' ) );

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );

		$order->update_meta_data( '_fs_notified', 'yes' );
		$order->save();
	}

	/**
	 * Does this evaluation require a hard block under the current policy?
	 *
	 * @param array $scored   Scoring result.
	 * @param array $matches  Blocklist matches.
	 * @param array $settings Settings.
	 * @return bool
	 */
	private function should_block( $scored, $matches, $settings ) {
		return ! empty( $matches )
			|| 'block' === $scored['level']
			|| ( 'high' === $scored['level'] && 'block' === $settings['action_high'] );
	}

	/**
	 * Classic checkout: index + score, then apply the post-creation backstop.
	 *
	 * Pre-payment blocking on the classic checkout already happened in
	 * validate_checkout(); this cancels/holds anything that slipped through.
	 *
	 * @param int|WC_Order $order_id Order id or object.
	 *
	 * @throws Exception When the linked-identity rule cancels the order: WC_Checkout::process_checkout()
	 *                   catches it, shows the message, and never reaches the payment step.
	 */
	public function on_order_created( $order_id ) {
		$order = is_a( $order_id, 'WC_Order' ) ? $order_id : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$decision = $this->evaluate_and_record( $order );
		$settings = $decision['settings'];

		// Blocklist matches cancel the order in EVERY mode (unless the customer
		// is merchant-verified). The savings row is written whether or not the
		// order still needed cancelling: an order that arrives here already
		// cancelled was stopped all the same, and the ledger counts it once.
		if ( ! empty( $decision['matches'] ) && ! self::is_verified_customer( (int) $order->get_customer_id() ) ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: cancelled (blocklist match).', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'blocklist', 'cancelled', $order, null, FS_Blocklist::evidence_identities( FS_Blocklist::match_evidence( $decision['matches'], $decision['signals'] ) ) );
			return;
		}

		// Linked to a blocked identity (v1.26): cancel, then stop the checkout
		// here. This hook fires inside process_checkout()'s try block, before
		// process_order_payment(); the exception is caught there and rendered
		// as the checkout error, so the cancelled order is never charged.
		$linked_message = $this->maybe_linked_cancel( $order, $decision, $settings );
		if ( null !== $linked_message ) {
			throw new Exception( wp_kses_post( $linked_message ) );
		}

		// A verified credential bypasses the hold trigger and score-based actions.
		if ( self::is_signal_verified( $decision['signals'] ) ) {
			return;
		}

		// Velocity rule, "hold" action (v1.31): the Nth order inside the
		// window is placed On-Hold for review. All modes.
		$this->maybe_velocity_hold( $order, $decision['signals'], $settings );

		// Trigger a NEW verification hold if this order qualifies (new account +
		// high value). All modes; the triggering order is placed (and optionally
		// held for review), not cancelled — it is future orders that get blocked.
		$this->maybe_place_hold( $order, $settings );

		if ( 'enforce' !== $settings['mode'] ) {
			return;
		}

		if ( $this->should_block( $decision['scored'], array(), $settings ) ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: cancelled (block policy).', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'score', 'cancelled', $order );
		} elseif ( 'high' === $decision['scored']['level'] && 'hold' === $settings['action_high']
			&& in_array( $order->get_status(), array( 'pending', 'processing' ), true ) ) {
			$order->update_status( 'on-hold', __( 'Fraud Shield: held for manual review (high risk).', 'fraud-shield' ) . ' ' );
		}
	}

	/**
	 * Trigger the new-account high-value verification hold. Sets a sticky hold on
	 * the ACCOUNT (so its future checkouts are blocked until an admin verifies),
	 * optionally puts the triggering order On-Hold for review, and notifies the
	 * configured emails once. The triggering order itself is never cancelled here.
	 *
	 * Account-scoped by design: it requires a registration date, so it never
	 * touches guests. Idempotent — an already-held account is skipped.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $settings Settings.
	 */
	private function maybe_place_hold( $order, $settings ) {
		if ( empty( $settings['hold_enabled'] ) ) {
			return;
		}
		$uid = (int) $order->get_customer_id();
		if ( $uid <= 0 ) {
			return;
		}
		$email = FS_Normalizer::email( $order->get_billing_email() );
		if ( self::is_exempt( $uid, $email ) ) {
			return;
		}
		if ( ! empty( get_user_meta( $uid, '_fs_hold', true ) ) ) {
			return; // Already held — don't re-trigger or re-notify.
		}

		$user = get_userdata( $uid );
		if ( ! $user || empty( $user->user_registered ) ) {
			return;
		}
		$age_days = (int) floor( ( time() - strtotime( $user->user_registered . ' UTC' ) ) / DAY_IN_SECONDS );
		if ( $age_days > (int) $settings['hold_days'] ) {
			return;
		}
		$total = (float) $order->get_total();
		if ( $total <= (float) $settings['hold_amount'] ) {
			return;
		}

		update_user_meta(
			$uid,
			'_fs_hold',
			array(
				'order_id' => $order->get_id(),
				'amount'   => $total,
				'age_days' => $age_days,
				'ts'       => current_time( 'mysql' ),
			)
		);

		$order->add_order_note( __( 'Fraud Shield: account placed under verification hold (new account + high-value order). Further checkouts are blocked until an admin verifies the customer.', 'fraud-shield' ) );

		// Optionally hold the triggering order for manual review (default on).
		if ( ! empty( $settings['hold_order'] ) && in_array( $order->get_status(), array( 'pending', 'processing' ), true ) ) {
			$order->update_status( 'on-hold', __( 'Fraud Shield: held for verification (new account + high value).', 'fraud-shield' ) . ' ' );
		}

		$this->notify_hold( $order, $settings, $age_days, $total );
	}

	/**
	 * Email the configured recipients that an account was put under a
	 * verification hold, with the details a CS agent needs to make the call.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $settings Settings.
	 * @param int      $age_days Account age in days.
	 * @param float    $total    Order total.
	 */
	private function notify_hold( $order, $settings, $age_days, $total ) {
		$recipients = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $settings['hold_emails'] ) ), 'is_email' );
		if ( empty( $recipients ) ) {
			return;
		}

		/* translators: 1: order number */
		$subject = sprintf( __( '[Fraud Shield] Verification hold — order #%d needs a call', 'fraud-shield' ), $order->get_id() );

		$lines   = array();
		$lines[] = __( 'A recently-registered account placed a high-value order and has been locked from placing further orders until verified.', 'fraud-shield' );
		$lines[] = '';
		$lines[] = sprintf( __( 'Order: #%d', 'fraud-shield' ), $order->get_id() );
		$lines[] = sprintf( __( 'Amount: %s', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $total, array( 'currency' => $order->get_currency() ) ) ) ) );
		$lines[] = sprintf( __( 'Account age: %d days', 'fraud-shield' ), (int) $age_days );
		$lines[] = sprintf( __( 'Customer: %1$s <%2$s> %3$s', 'fraud-shield' ), trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), $order->get_billing_email(), $order->get_billing_phone() );
		$lines[] = '';
		$lines[] = __( 'Call the customer to verify, then release them from the queue:', 'fraud-shield' );
		$lines[] = admin_url( 'admin.php?page=fraud-shield-locks' );

		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Keep a held triggering order On-Hold after its payment completes. Without
	 * this, WC_Order::payment_complete() (which runs after on_order_created) would
	 * move the order to processing, undoing the "hold for review" the merchant
	 * asked for. Only touches the exact order that triggered the account's hold.
	 *
	 * @param int $order_id Order id.
	 */
	public function on_payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$uid = (int) $order->get_customer_id();

		// NOTE: a completed payment does NOT mean the customer is no longer
		// velocity-locked — the lock is "N+ orders in 30 days", which this order
		// does not reduce (it may even be one of those orders). Resolving the
		// velocity ledger row here wrongly dropped still-locked customers off the
		// Checkout Locks page. Velocity rows are instead re-confirmed live against
		// is_velocity_locked() when the page renders, and cleared explicitly by CS
		// (Verify / Allow / Dismiss). Do not resolve velocity rows on payment.

		// A velocity-held order stays held when its payment lands (v1.31).
		self::reassert_velocity_hold( $order );

		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['hold_enabled'] ) || empty( $settings['hold_order'] ) ) {
			return;
		}
		if ( $uid <= 0 ) {
			return;
		}
		$hold = get_user_meta( $uid, '_fs_hold', true );
		if ( empty( $hold ) || ! is_array( $hold ) || (int) ( $hold['order_id'] ?? 0 ) !== (int) $order_id ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'on-hold', 'cancelled', 'refunded' ), true ) ) {
			$order->update_status( 'on-hold', __( 'Fraud Shield: held for verification (new account + high value).', 'fraud-shield' ) . ' ' );
		}
	}

	/**
	 * How long this customer has been a customer, in days, or null when we have
	 * no evidence either way.
	 *
	 * Registration date is the primary signal, as intended: the account's
	 * `user_registered` (stored by WordPress in UTC, so it is parsed as UTC). Two
	 * fallbacks matter, because the newest customers are exactly the ones with no
	 * session account yet:
	 *
	 *  - not signed in, but the checkout email belongs to an account → that
	 *    account's registration date;
	 *  - no account at all → the first order we ever indexed for the email, so a
	 *    long-standing guest customer is still recognised as established.
	 *
	 * @param int    $user_id    Account id (0 = none).
	 * @param string $email_norm Normalized checkout email, when known.
	 * @return float|null Age in days, or null when unknown.
	 */
	public static function customer_age_days( $user_id, $email_norm = '' ) {
		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );
		$registered = null;

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user && ! empty( $user->user_registered ) ) {
				$registered = strtotime( $user->user_registered . ' UTC' );
			}
		}

		// Signed out (or an account created mid-checkout): the email still tells
		// us who they are. Use the raw address, since an account is registered
		// with a real address rather than our normalized form.
		if ( ! $registered && '' !== $email_norm ) {
			$by_email = get_user_by( 'email', $email_norm );
			if ( $by_email && ! empty( $by_email->user_registered ) ) {
				$registered = strtotime( $by_email->user_registered . ' UTC' );
			}
		}

		if ( $registered ) {
			return ( time() - $registered ) / DAY_IN_SECONDS;
		}

		// No account anywhere — fall back to the first order we ever saw for this
		// email, which is the only "customer since" date a guest has.
		if ( '' !== $email_norm ) {
			$first = FS_Data::email_first_seen( $email_norm );
			if ( $first ) {
				$ts = strtotime( $first . ' UTC' );
				if ( $ts ) {
					return ( time() - $ts ) / DAY_IN_SECONDS;
				}
			}
		}

		return null;
	}

	/**
	 * Whether this customer is subject to the new-customer payment-gateway
	 * restriction.
	 *
	 * Pass the identity when you have it (submit-time validation does). With no
	 * arguments it reads the current session and the checkout email WooCommerce
	 * has on the customer object, which is what the display-time filter can see.
	 *
	 * An unknown age counts as NEW. That is the whole point of the control — the
	 * customers it exists to restrict are precisely the ones with no history —
	 * and treating "unknown" as established is what previously let a guest, or
	 * anyone creating their account during checkout, walk past the restriction
	 * with every gateway on display.
	 *
	 * @param int|null    $user_id    Account id, or null to read the session.
	 * @param string|null $email_norm Normalized email, or null to read the session.
	 * @return bool
	 */
	public static function is_gateway_restricted_customer( $user_id = null, $email_norm = null ) {
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['gateway_restrict_enabled'] ) ) {
			return false;
		}

		$uid = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;

		if ( null === $email_norm ) {
			$email_norm = '';
			if ( $uid > 0 ) {
				$user = get_userdata( $uid );
				if ( $user && ! empty( $user->user_email ) ) {
					$email_norm = FS_Normalizer::email( $user->user_email );
				}
			}
			// Guest: WooCommerce may already hold the billing email from the
			// checkout form or the session.
			if ( '' === $email_norm && function_exists( 'WC' ) && WC()->customer ) {
				$email_norm = FS_Normalizer::email( WC()->customer->get_billing_email() );
			}
		} else {
			$email_norm = FS_Normalizer::email( $email_norm );
		}

		// Every way out of the restriction: merchant-verified account, allowlisted
		// or verified email, a passed ID check, or an established clean history.
		if ( $uid > 0 && self::is_account_gateway_exempt( $uid ) ) {
			return false;
		}
		if ( '' !== $email_norm
			&& ( self::is_email_allowlisted( $email_norm )
				|| self::is_email_verified( $email_norm )
				|| FS_IDV::is_email_verified( $email_norm ) ) ) {
			return false;
		}
		if ( self::is_auto_verified( $uid, $email_norm ) ) {
			return false;
		}

		$age_days = self::customer_age_days( $uid, $email_norm );
		if ( null === $age_days ) {
			return true; // No history anywhere: as new as it gets.
		}

		return $age_days <= (int) $settings['gateway_restrict_days'];
	}

	/**
	 * Whether an established, clean-history customer has earned an automatic
	 * exemption from the plugin's automatic controls.
	 *
	 * Grants exactly what a verified credential grants — full payment methods,
	 * no velocity lock, no new-account hold — and deliberately NOT what the
	 * `_fs_verified` account flag grants, because that flag is the only exemption
	 * that overrides the blocklist. An explicit block, a destination lock, and a
	 * staff demand for an ID are human decisions and must never be undone by a
	 * customer simply getting older.
	 *
	 * @param int    $user_id    Account id (0 = guest).
	 * @param string $email_norm Normalized email.
	 * @return bool
	 */
	public static function is_auto_verified( $user_id, $email_norm = '' ) {
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['auto_verify_enabled'] ) ) {
			return false;
		}

		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( $user_id <= 0 && '' === $email_norm ) {
			return false;
		}

		// One evaluation per identity per request: this runs on checkout renders,
		// and each miss costs an aggregate query. The rule's own inputs are part
		// of the key so a settings change can never be answered from a verdict
		// computed under the old thresholds.
		static $cache = array();
		$key          = implode(
			'|',
			array(
				$user_id,
				$email_norm,
				(int) $settings['auto_verify_min_days'],
				(int) $settings['auto_verify_min_orders'],
				! empty( $settings['auto_verify_require_clean'] ) ? 1 : 0,
			)
		);
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$cache[ $key ] = self::evaluate_auto_verified( $user_id, $email_norm, $settings );
		return $cache[ $key ];
	}

	/**
	 * The auto-verification test itself. Split out so the admin screens can
	 * explain the outcome without duplicating the rules.
	 *
	 * @param int    $user_id    Account id.
	 * @param string $email_norm Normalized email.
	 * @param array  $settings   Plugin settings.
	 * @return bool
	 */
	private static function evaluate_auto_verified( $user_id, $email_norm, $settings ) {
		$status = self::auto_verify_status( $user_id, $email_norm, $settings );
		return ! empty( $status['qualifies'] );
	}

	/**
	 * Why a customer does or does not qualify for auto-verification, for both the
	 * decision itself and the admin explanation.
	 *
	 * @param int        $user_id    Account id.
	 * @param string     $email_norm Normalized email.
	 * @param array|null $settings   Plugin settings (loaded when null).
	 * @return array { qualifies:bool, enabled:bool, age_days:?float, min_days:int, paid_orders:int, min_orders:int, chargebacks:int, refunds:int, clean:bool, reason:string }
	 */
	public static function auto_verify_status( $user_id, $email_norm = '', $settings = null ) {
		if ( null === $settings ) {
			$settings = FS_Core::instance()->settings();
		}
		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );

		$min_days   = max( 0, (int) ( $settings['auto_verify_min_days'] ?? 180 ) );
		$min_orders = max( 0, (int) ( $settings['auto_verify_min_orders'] ?? 1 ) );
		$need_clean = ! empty( $settings['auto_verify_require_clean'] );

		$out = array(
			'qualifies'   => false,
			'enabled'     => ! empty( $settings['auto_verify_enabled'] ),
			'age_days'    => null,
			'min_days'    => $min_days,
			'paid_orders' => 0,
			'min_orders'  => $min_orders,
			'chargebacks' => 0,
			'refunds'     => 0,
			'clean'       => true,
			'reason'      => '',
		);

		if ( $user_id <= 0 && '' === $email_norm ) {
			$out['reason'] = 'unknown';
			return $out;
		}

		$out['age_days'] = self::customer_age_days( $user_id, $email_norm );
		if ( null === $out['age_days'] || $out['age_days'] < $min_days ) {
			$out['reason'] = 'too_new';
			return $out;
		}

		$hist                = FS_Data::customer_history( $user_id, $email_norm );
		$out['paid_orders']  = (int) $hist['paid_orders'];
		$out['chargebacks']  = (int) $hist['chargebacks'];
		$out['refunds']      = (int) $hist['refunds'];
		$out['clean']        = ( 0 === $out['chargebacks'] && 0 === $out['refunds'] );

		if ( $out['paid_orders'] < $min_orders ) {
			$out['reason'] = 'too_few_orders';
			return $out;
		}
		if ( $need_clean && ! $out['clean'] ) {
			$out['reason'] = 'not_clean';
			return $out;
		}

		if ( ! $out['enabled'] ) {
			// Everything else passes; the store just hasn't switched it on. Report
			// it honestly so the admin screens can say "would qualify".
			$out['reason'] = 'disabled';
			return $out;
		}

		$out['qualifies'] = true;
		$out['reason']    = 'qualifies';
		return $out;
	}

	/**
	 * The stored per-account gateway restriction, exactly as saved, whether or
	 * not it is still in force: [ gateways[], lowvalue_amount,
	 * lowvalue_gateways[], applied_at ] or empty. Admin screens use this to show
	 * what staff configured and when; enforcement uses the effective accessor
	 * below, which ignores a restriction that has since lapsed.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function gw_restrict_record( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return array();
		}
		$r = get_user_meta( $user_id, '_fs_gw_restrict', true );
		return is_array( $r ) ? $r : array();
	}

	/**
	 * When a stored restriction lapses, as a Unix timestamp. 0 means "never",
	 * either because the store set the window to 0 or because the record
	 * predates applied-at stamping and has not been stamped yet (unknown age
	 * fails SAFE — the restriction stands until the sweep stamps it).
	 *
	 * Months are counted as 30 days each, matching the velocity lock's
	 * new-account window, so "6 months" is 180 days.
	 *
	 * @param array $rec Stored restriction record.
	 * @return int
	 */
	public static function gw_restrict_expires_at( $rec ) {
		if ( empty( $rec['gateways'] ) ) {
			return 0;
		}
		$applied = isset( $rec['applied_at'] ) ? (int) $rec['applied_at'] : 0;
		if ( $applied <= 0 ) {
			return 0;
		}
		$months = (int) ( FS_Core::instance()->settings()['gw_restrict_expire_months'] ?? 6 );
		if ( $months <= 0 ) {
			return 0;
		}
		return $applied + ( $months * 30 * DAY_IN_SECONDS );
	}

	/**
	 * Why an account's stored restriction no longer applies, or '' when it is
	 * still in force. Restricted checkout is probation: it ends when the clock
	 * runs out ('expired') or when the customer proves who they are with an
	 * approved ID check ('idv').
	 *
	 * Staff verifying the customer by hand ends it too ('verified') — that is a
	 * deliberate "this person is fine" decision and the counterpart to the ID
	 * check. The catch staff need to know about is the reverse order: applying a
	 * restriction to a customer who is ALREADY verified does nothing, because
	 * this rule voids it immediately. The gateway editor says so on screen rather
	 * than letting the limit fail silently, which is how the v1.12 "restriction
	 * never applied" bug went unnoticed.
	 *
	 * @param int   $user_id User id.
	 * @param array $rec     Optional pre-loaded record (saves a meta read).
	 * @return string '' | 'expired' | 'idv' | 'verified'
	 */
	public static function gw_restrict_void_reason( $user_id, $rec = null ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( null === $rec ) {
			$rec = self::gw_restrict_record( $user_id );
		}
		if ( empty( $rec['gateways'] ) ) {
			return '';
		}

		$settings = FS_Core::instance()->settings();
		$user     = get_userdata( $user_id );
		$email    = ( $user && ! empty( $user->user_email ) ) ? FS_Normalizer::email( $user->user_email ) : '';

		if ( ! empty( $settings['gw_restrict_release_on_idv'] ) && class_exists( 'FS_IDV' ) ) {
			if ( FS_IDV::is_user_verified( $user_id ) || ( '' !== $email && FS_IDV::is_email_verified( $email ) ) ) {
				return 'idv';
			}
		}

		// Staff verified them by hand.
		if ( self::is_verified_customer( $user_id ) ) {
			return 'verified';
		}
		if ( '' !== $email && ( self::is_email_allowlisted( $email ) || self::is_email_verified( $email ) ) ) {
			return 'verified';
		}

		$expires = self::gw_restrict_expires_at( $rec );
		if ( $expires > 0 && time() >= $expires ) {
			return 'expired';
		}

		return '';
	}

	/**
	 * The per-account gateway restriction currently IN FORCE, or empty when the
	 * account has none, it has lapsed, or an ID check has released it.
	 *
	 * A pure read: enforcement runs on the frontend, so the stale row is left
	 * for the sweep to delete rather than writing to the database mid-checkout.
	 * Ignoring it here is what matters — the customer gets their full gateway
	 * set on the very next page load, without waiting for any sweep.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function per_user_gateway_restriction( $user_id ) {
		$rec = self::gw_restrict_record( $user_id );
		if ( empty( $rec['gateways'] ) ) {
			return $rec;
		}
		return '' === self::gw_restrict_void_reason( $user_id, $rec ) ? $rec : array();
	}

	/**
	 * Take an account off restricted checkout.
	 *
	 * @param int $user_id User id.
	 * @return bool True when a restriction was removed.
	 */
	public static function release_gw_restrict( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || empty( self::gw_restrict_record( $user_id ) ) ) {
			return false;
		}
		delete_user_meta( $user_id, '_fs_gw_restrict' );
		return true;
	}

	/**
	 * Reconcile stored restrictions with the rules: delete the ones that have
	 * lapsed or been released by an ID check, and stamp any record saved before
	 * v1.16 (no applied_at) so its clock starts running.
	 *
	 * Called on the Checkout Locks page so the Restricted checkout list is
	 * accurate whenever staff look at it, and once on upgrade. There is no cron
	 * anywhere in the plugin and none is needed: enforcement already ignores a
	 * lapsed restriction at read time, so this only keeps the list tidy.
	 *
	 * @param int $limit Max accounts to examine.
	 * @return array { released:int, stamped:int }
	 */
	public static function sweep_gw_restrictions( $limit = 500 ) {
		$released = 0;
		$stamped  = 0;

		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_fs_gw_restrict',
						'compare' => 'EXISTS',
					),
				),
				'number'     => (int) $limit,
				'fields'     => array( 'ID' ),
			)
		);

		foreach ( $users as $u ) {
			$uid = (int) $u->ID;
			$rec = self::gw_restrict_record( $uid );
			if ( empty( $rec['gateways'] ) ) {
				continue;
			}

			// Stamp first: an unstamped record has no expiry, so without this it
			// could never lapse.
			if ( empty( $rec['applied_at'] ) ) {
				$rec['applied_at'] = time();
				update_user_meta( $uid, '_fs_gw_restrict', $rec );
				++$stamped;
			}

			if ( '' !== self::gw_restrict_void_reason( $uid, $rec ) ) {
				delete_user_meta( $uid, '_fs_gw_restrict' );
				++$released;
			}
		}

		return array(
			'released' => $released,
			'stamped'  => $stamped,
		);
	}

	/**
	 * The money amount every threshold in the plugin compares against, for the
	 * order being placed right now (or a given order), on the configured basis.
	 *
	 * Basis 'subtotal' is the item total after coupon discounts, before shipping
	 * and tax: when a merchant says "orders under $500" they almost always mean
	 * what the items cost, and letting a $480 basket fail a $500 rule because
	 * shipping and tax pushed the grand total to $510 is how the low-value
	 * allowance silently stopped working. Basis 'total' is the grand total the
	 * customer actually pays.
	 *
	 * Sources, in order: the order passed in; the live cart; the order behind
	 * the order-pay endpoint (the cart is empty there, which used to make every
	 * amount rule blind on that page). Returns null when no amount is knowable.
	 *
	 * @param WC_Order|null $order Order to read instead of the live cart.
	 * @return float|null
	 */
	public static function order_amount( $order = null ) {
		$basis = (string) ( FS_Core::instance()->settings()['amount_basis'] ?? 'subtotal' );

		if ( $order instanceof WC_Order ) {
			return self::order_amount_from_order( $order, $basis );
		}

		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			if ( 'total' === $basis ) {
				return (float) WC()->cart->get_total( 'edit' );
			}
			return (float) WC()->cart->get_cart_contents_total();
		}

		// Order-pay endpoint: the customer is paying for an existing order and
		// the cart is empty, so the order is the only source of truth.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$pay_order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
			if ( $pay_order ) {
				return self::order_amount_from_order( $pay_order, $basis );
			}
		}

		return null;
	}

	/**
	 * The basis amount for a specific order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $basis 'subtotal' | 'total'.
	 * @return float
	 */
	private static function order_amount_from_order( $order, $basis ) {
		if ( 'total' === $basis ) {
			return (float) $order->get_total();
		}
		return max( 0.0, (float) $order->get_subtotal() - (float) $order->get_total_discount() );
	}

	/**
	 * How much the store currently trusts this customer, as a single word.
	 *
	 * 'staff' - a human vouched for them: the Verified account flag, a
	 *           staff-added verified email credential, or the email allowlist.
	 * 'idv'   - they hold an unexpired Didit ID verification.
	 * 'auto'  - they earned automatic verification from account age and a clean
	 *           order history.
	 * 'none'  - nothing above applies.
	 *
	 * The high-value rules read this to decide which threshold a customer gets;
	 * admin screens read it to explain a customer's standing.
	 *
	 * @param int    $user_id    Account id (0 = guest).
	 * @param string $email_norm Normalized email.
	 * @return string staff|idv|auto|none
	 */
	public static function verification_tier( $user_id, $email_norm = '' ) {
		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' === $email_norm && $user_id > 0 ) {
			$user       = get_userdata( $user_id );
			$email_norm = ( $user && ! empty( $user->user_email ) ) ? FS_Normalizer::email( $user->user_email ) : '';
		}

		if ( self::is_verified_customer( $user_id ) || self::is_email_allowlisted( $email_norm ) ) {
			return 'staff';
		}
		// A verified email credential counts as staff trust only when a human
		// added it; the credential an ID check earns is tagged src=idv and that
		// customer is judged by their ID verification instead.
		if ( '' !== $email_norm && self::is_email_verified( $email_norm ) && 'idv' !== self::verified_src( 'email', $email_norm ) ) {
			return 'staff';
		}
		if ( FS_IDV::is_verified( $user_id, $email_norm ) ) {
			return 'idv';
		}
		if ( self::is_auto_verified( $user_id, $email_norm ) ) {
			return 'auto';
		}
		return 'none';
	}

	/**
	 * The high-value rule that applies to this customer for an order of this
	 * amount, or null when nothing does.
	 *
	 * Reading the result:
	 *  - null: no rule fired. The feature is off, the amount is unknown or under
	 *    every threshold that applies to this customer, the customer is exempt,
	 *    or they already completed a recent-enough check.
	 *  - mode 'soft': the order is over the customer's tier threshold. They may
	 *    still pay using the configured fallback gateways (an empty set means no
	 *    restriction, invite only), and verifying unlocks everything.
	 *  - mode 'hard': the order is at or above the hard amount. It will be
	 *    refused at submission until a recent-enough check exists.
	 *
	 * Fail-open guarantees, in the same spirit as the staff ID demand: no rule
	 * ever fires while Didit is unconfigured (a requirement nobody can satisfy
	 * is a locked door with no key), and the staff Verified account flag is
	 * exempt from everything including the hard rule, because that flag is the
	 * merchant's own master override.
	 *
	 * Freshness: a check completed within hv_fresh_hours satisfies every rule.
	 * That is what makes re-verification work for ID-verified customers: their
	 * month-old check is real but not recent, so a $10,500 order still asks
	 * again, while the check they complete during this checkout counts at once.
	 *
	 * @param int        $user_id    Account id (0 = guest).
	 * @param string     $email_norm Normalized email.
	 * @param float|null $amount     Basis amount of the order (null = unknown).
	 * @return array|null { mode:'soft'|'hard', tier:string, threshold:float, amount:float }
	 */
	public static function high_value_rule( $user_id, $email_norm, $amount ) {
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['hv_enabled'] ) || null === $amount ) {
			return null;
		}
		// Never demand a verification the store cannot run.
		if ( ! FS_IDV::configured() ) {
			return null;
		}

		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );
		$amount     = (float) $amount;

		// The merchant's own master override outranks every automatic rule.
		if ( self::is_verified_customer( $user_id ) ) {
			return null;
		}

		$tier = self::verification_tier( $user_id, $email_norm );

		$soft_map = array(
			'none' => (float) ( $settings['hv_amount_none'] ?? 0 ),
			'auto' => (float) ( $settings['hv_amount_auto'] ?? 0 ),
			'idv'  => (float) ( $settings['hv_amount_idv'] ?? 0 ),
		);
		$soft_threshold = isset( $soft_map[ $tier ] ) ? $soft_map[ $tier ] : 0.0; // 'staff' has no soft rule.
		$hard_threshold = (float) ( $settings['hv_hard_amount'] ?? 0 );

		$hard = $hard_threshold > 0 && $amount >= $hard_threshold;
		$soft = $soft_threshold > 0 && $amount >= $soft_threshold;
		if ( ! $hard && ! $soft ) {
			return null;
		}

		// A recent-enough check satisfies whichever rule fired.
		if ( FS_IDV::has_fresh_verification( $user_id, $email_norm, (int) $settings['hv_fresh_hours'] ) ) {
			return null;
		}

		return array(
			'mode'      => $hard ? 'hard' : 'soft',
			'tier'      => $tier,
			'threshold' => $hard ? $hard_threshold : $soft_threshold,
			'amount'    => $amount,
		);
	}

	/**
	 * The high-value rule for the order being placed right now (live cart or
	 * order-pay), for one customer. Resolves a guest's identity from the email
	 * WooCommerce already holds on the session, exactly as the display filter
	 * does, so a returning verified customer who is not signed in is still
	 * recognized.
	 *
	 * @param int    $user_id    Account id.
	 * @param string $email_norm Normalized email ('' = read the session).
	 * @return array|null See high_value_rule().
	 */
	public static function high_value_rule_for_cart( $user_id, $email_norm = '' ) {
		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' === $email_norm && $user_id <= 0 && function_exists( 'WC' ) && WC()->customer ) {
			$email_norm = FS_Normalizer::email( WC()->customer->get_billing_email() );
		}
		return self::high_value_rule( $user_id, $email_norm, self::order_amount() );
	}

	/**
	 * Second stage of the payment-method filter: narrow an already-filtered
	 * gateway list to the high-value fallback set while a soft rule is
	 * outstanding. Most restrictive wins; fails open to the incoming list when
	 * the fallback set matches nothing, so a misconfigured set can never strand
	 * a checkout. A hard rule leaves the list alone on purpose: the refusal
	 * happens at submission with a message, and the verification card at
	 * checkout explains the way through.
	 *
	 * @param array  $available  Gateways keyed by id (already base-filtered).
	 * @param int    $user_id    Account id.
	 * @param string $email_norm Normalized email.
	 * @return array
	 */
	private function apply_high_value_gateways( $available, $user_id, $email_norm ) {
		if ( ! is_array( $available ) || empty( $available ) ) {
			return $available;
		}
		$rule = self::high_value_rule_for_cart( $user_id, $email_norm );
		if ( null === $rule || 'soft' !== $rule['mode'] ) {
			return $available;
		}

		$fallback = array_filter( array_map( 'strval', (array) ( FS_Core::instance()->settings()['hv_gateways'] ?? array() ) ) );
		if ( empty( $fallback ) ) {
			return $available; // Invite-only configuration: nothing is withheld.
		}

		$filtered = array();
		foreach ( $available as $id => $gateway ) {
			if ( in_array( (string) $id, $fallback, true ) ) {
				$filtered[ $id ] = $gateway;
			}
		}

		return empty( $filtered ) ? $available : $filtered;
	}

	/**
	 * The gateway policy that applies to one customer, or null when none does.
	 *
	 * The single source of truth behind BOTH the payment methods on display and
	 * the submit-time check, so the two can never disagree — the display filter
	 * runs before a guest has typed their email, while submit-time validation
	 * knows exactly who they are, and having one rule evaluated with two
	 * different identities is what let disallowed gateways through.
	 *
	 * Precedence: a per-account restriction (explicit staff decision) beats the
	 * general exemptions; otherwise verified customers are unrestricted; then the
	 * velocity restrict mode; then the new-customer rule.
	 *
	 * @param int    $user_id    Account id (0 = guest).
	 * @param string $email_norm Normalized checkout email ('' = unknown).
	 * @return array|null { allowed:string[], low_amount:float, low_gateways:string[], source:string }
	 */
	public static function gateway_policy_for( $user_id, $email_norm = '' ) {
		$user_id  = (int) $user_id;
		$settings = FS_Core::instance()->settings();

		// The low-value allowance is a statement about ORDER SIZE — "under this
		// amount, these methods are an acceptable risk" — so it applies to every
		// restricted checkout, whichever rule imposed the restriction. It used
		// to be wired into the new-customer branch alone, which meant a customer
		// on the per-account Restricted checkout list or under the velocity
		// restriction got NO allowance at all, however small the order: the
		// setting looked global and silently was not. A per-account allowance,
		// when staff configured one on that specific account, still overrides
		// this global one.
		$global_low_amount   = isset( $settings['gateway_restrict_lowvalue_amount'] ) ? (float) $settings['gateway_restrict_lowvalue_amount'] : 0;
		$global_low_gateways = isset( $settings['gateway_restrict_lowvalue_gateways'] )
			? array_values( array_filter( array_map( 'strval', (array) $settings['gateway_restrict_lowvalue_gateways'] ) ) )
			: array();

		// 1) A per-account restriction is an EXPLICIT staff decision for THIS
		// customer and is checked before the general exemptions — otherwise the
		// account-level release that accompanies it would bypass the very
		// restriction the staff just imposed. The accessor already drops a
		// restriction that has lapsed, or that an ID check or a manual
		// verification released, so those customers fall through.
		$per = self::per_user_gateway_restriction( $user_id );
		if ( ! empty( $per['gateways'] ) && is_array( $per['gateways'] ) ) {
			$per_low_amount   = isset( $per['lowvalue_amount'] ) ? (float) $per['lowvalue_amount'] : 0;
			$per_low_gateways = isset( $per['lowvalue_gateways'] )
				? array_values( array_filter( array_map( 'strval', (array) $per['lowvalue_gateways'] ) ) )
				: array();

			$has_own_allowance = ( $per_low_amount > 0 && ! empty( $per_low_gateways ) );

			return array(
				'allowed'      => (array) $per['gateways'],
				'low_amount'   => $has_own_allowance ? $per_low_amount : $global_low_amount,
				'low_gateways' => $has_own_allowance ? $per_low_gateways : $global_low_gateways,
				'source'       => 'per_user',
			);
		}

		// Verified / allowlisted / ID-verified / established-clean customers are
		// not subject to the remaining (general) rules.
		if ( $user_id > 0 && self::is_account_gateway_exempt( $user_id ) ) {
			return null;
		}
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' !== $email_norm
			&& ( self::is_email_allowlisted( $email_norm )
				|| self::is_email_verified( $email_norm )
				|| FS_IDV::is_email_verified( $email_norm ) ) ) {
			return null;
		}
		if ( self::is_auto_verified( $user_id, $email_norm ) ) {
			return null;
		}

		// 2) Velocity-restrict mode: instead of blocking a high-velocity customer
		// outright, push them onto the configured restricted gateway set.
		if ( ! empty( $settings['velocity_lock'] )
			&& 'restrict' === self::velocity_action( $settings )
			&& ! empty( $settings['velocity_restrict_gateways'] )
			&& ( $user_id > 0 || '' !== $email_norm ) ) {
			$vemail = $email_norm;
			if ( '' === $vemail && $user_id > 0 ) {
				$vuser  = get_userdata( $user_id );
				$vemail = $vuser ? FS_Normalizer::email( $vuser->user_email ) : '';
			}
			if ( self::is_velocity_locked( $user_id, $vemail ) ) {
				return array(
					'allowed'      => (array) $settings['velocity_restrict_gateways'],
					'low_amount'   => $global_low_amount,
					'low_gateways' => $global_low_gateways,
					'source'       => 'velocity',
				);
			}
		}

		// 3) The global new-customer restriction.
		if ( ! self::is_gateway_restricted_customer( $user_id, $email_norm ) ) {
			return null;
		}

		return array(
			'allowed'      => (array) $settings['gateway_restrict_allowed'],
			'low_amount'   => $global_low_amount,
			'low_gateways' => $global_low_gateways,
			'source'       => 'new_customer',
		);
	}

	/**
	 * Whether a specific gateway is allowed for this customer on an order of this
	 * value. Used by the submit-time checks, where the customer's identity and
	 * the order total are both known for certain.
	 *
	 * Fails OPEN in exactly the same places the display filter does — an empty
	 * allow-list allows everything — so the two can't reach opposite verdicts and
	 * strand a customer who was shown a method they then can't use.
	 *
	 * @param int        $user_id    Account id.
	 * @param string     $email_norm Normalized email.
	 * @param string     $gateway_id Chosen gateway id.
	 * @param float|null $amount     Basis amount of the order (see order_amount()).
	 * @return bool
	 */
	public static function is_gateway_allowed_for( $user_id, $email_norm, $gateway_id, $amount ) {
		$gateway_id = (string) $gateway_id;
		if ( '' === $gateway_id ) {
			return true;
		}

		$amount = null === $amount ? null : (float) $amount;

		$policy = self::gateway_policy_for( $user_id, $email_norm );
		if ( null !== $policy ) {
			$allowed = array_filter( array_map( 'strval', (array) $policy['allowed'] ) );
			if ( ! empty( $allowed ) ) { // Empty = fail open, as the display filter does.
				if ( $policy['low_amount'] > 0 && ! empty( $policy['low_gateways'] )
					&& null !== $amount && $amount < (float) $policy['low_amount'] ) {
					$allowed = array_merge( $allowed, array_filter( array_map( 'strval', (array) $policy['low_gateways'] ) ) );
				}
				if ( ! in_array( $gateway_id, array_values( array_unique( $allowed ) ), true ) ) {
					return false;
				}
			}
		}

		// High-value stage: while a soft rule is outstanding, only the fallback
		// set may be used (an empty set restricts nothing). Mirrors the display
		// filter INCLUDING its fail-open: when the fallback set matches nothing
		// the customer could actually be shown, the display stage stood down,
		// so this check must stand down too — otherwise every method on the
		// page would be refused at submission, exactly the stranding the
		// fail-open exists to prevent.
		$rule = self::high_value_rule( $user_id, $email_norm, $amount );
		if ( null !== $rule && 'soft' === $rule['mode'] ) {
			$fallback = array_filter( array_map( 'strval', (array) ( FS_Core::instance()->settings()['hv_gateways'] ?? array() ) ) );
			if ( ! empty( $fallback )
				&& ! in_array( $gateway_id, $fallback, true )
				&& self::hv_fallback_usable( $fallback ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether at least one high-value fallback gateway survives in the list a
	 * customer is actually shown right now. Reads the final filtered gateway
	 * list (our own display filter included), so this reaches the same verdict
	 * the customer's screen did. Unknown means unusable, which fails open.
	 *
	 * @param string[] $fallback Configured fallback gateway ids.
	 * @return bool
	 */
	private static function hv_fallback_usable( $fallback ) {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$available = WC()->payment_gateways()->get_available_payment_gateways();
		foreach ( (array) $available as $id => $gateway ) {
			if ( in_array( (string) $id, (array) $fallback, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Move our gateway filter to the very end of the PHP_INT_MAX queue, after
	 * every other plugin has registered its own callbacks.
	 *
	 * WordPress runs callbacks sharing a priority in the order they were added, so
	 * registering at PHP_INT_MAX during plugin load is not by itself enough — a
	 * plugin loaded after us at the same priority would still get the final say
	 * and could hand back a gateway we withheld. Re-adding on `wp_loaded` puts us
	 * last in that bucket.
	 */
	public function reassert_gateway_filter() {
		remove_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrict_gateways' ), PHP_INT_MAX );
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'restrict_gateways' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Filter the payment methods on display to those the customer may use. Fails
	 * OPEN (full set) whenever the allow-list would leave zero usable gateways,
	 * so a misconfiguration can never lock a customer out of checkout.
	 *
	 * @param array $available Available gateways keyed by id.
	 * @return array
	 */
	public function restrict_gateways( $available ) {
		if ( ! is_array( $available ) || empty( $available ) ) {
			return $available;
		}
		// Skip genuine wp-admin screens, but NOT admin-ajax: plenty of frontend
		// features (payment rotators among them) refresh the gateway list over
		// admin-ajax.php, where is_admin() is true even though we are serving a
		// shopper. Bailing on that was enough to drop the restriction entirely.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $available;
		}

		// Remember which gateway ids are really offered on the storefront. A plugin
		// that registers gateways only on the frontend leaves them invisible in
		// wp-admin, so the allow-list cannot be configured against ids nobody can
		// see. Running at PHP_INT_MAX means this is the near-final list.
		self::record_seen_gateways( $available );

		$uid   = get_current_user_id();
		$email = '';
		if ( $uid > 0 ) {
			$user  = get_userdata( $uid );
			$email = ( $user && ! empty( $user->user_email ) ) ? FS_Normalizer::email( $user->user_email ) : '';
		} elseif ( function_exists( 'WC' ) && WC()->customer ) {
			// A guest part-way through checkout: WooCommerce may already have the
			// billing email, which is the only thing that can identify them here.
			$email = FS_Normalizer::email( WC()->customer->get_billing_email() );
		}

		$policy = self::gateway_policy_for( $uid, $email );
		if ( null !== $policy ) {
			$available = $this->apply_gateway_allow(
				$available,
				(array) $policy['allowed'],
				(float) $policy['low_amount'],
				(array) $policy['low_gateways']
			);
		}

		// High-value stage, applied after the base policy so the most
		// restrictive rule wins. Runs for everyone: a customer the base rules
		// exempt (ID-verified, auto-verified) can still owe a check for an
		// order of this size.
		return $this->apply_high_value_gateways( $available, $uid, $email );
	}

	/**
	 * Reduce available gateways to an allow-list, adding low-value-allowance
	 * gateways when the live cart total is under the amount. Fails open.
	 *
	 * @param array $available    Gateways keyed by id.
	 * @param array $allowed_in   Base allowed gateway ids.
	 * @param float $low_amount   Cart-total threshold (0 = no low-value allowance).
	 * @param array $low_gateways Gateways allowed only under the threshold.
	 * @return array
	 */
	private function apply_gateway_allow( $available, $allowed_in, $low_amount, $low_gateways ) {
		$allowed      = array_filter( array_map( 'strval', (array) $allowed_in ) );
		$low_gateways = array_filter( array_map( 'strval', (array) $low_gateways ) );
		$low_amount   = (float) $low_amount;

		// Low-value allowance: certain gateways (e.g. a card processor) also become
		// available when the order amount is under the threshold, an easy payment
		// path for small orders while larger ones stay restricted. The amount is
		// basis-aware (see order_amount()): comparing against the grand total was
		// how a $480 basket failed a $500 allowance once shipping and tax landed
		// on top. Only added when the amount is known (unknown = not added).
		if ( $low_amount > 0 && ! empty( $low_gateways ) ) {
			$amount = self::order_amount();
			if ( null !== $amount && $amount < $low_amount ) {
				$allowed = array_merge( $allowed, $low_gateways );
			}
		}
		$allowed = array_values( array_unique( $allowed ) );

		if ( empty( $allowed ) ) {
			return $available; // Nothing allowed configured — fail open (don't lock out).
		}

		$filtered = array();
		foreach ( $available as $id => $gateway ) {
			if ( in_array( (string) $id, $allowed, true ) ) {
				$filtered[ $id ] = $gateway;
			}
		}

		// If the allowed list matches no currently-available gateway, fail open.
		return empty( $filtered ) ? $available : $filtered;
	}

	/**
	 * Accumulate the gateway ids seen on the storefront, so Settings can offer the
	 * ids that actually reach customers rather than only those wp-admin knows.
	 *
	 * Stored as a growing union rather than a snapshot: a rotator deliberately
	 * offers a different subset on each request, and replacing the record every
	 * time would mean a database write on every checkout render. The union settles
	 * once the pool has cycled, after which this writes at most once a day (the
	 * last-seen freshness tick below).
	 *
	 * The table is bounded, but a FULL table evicts the id that has gone longest
	 * without being served instead of refusing the newcomer. The old behaviour —
	 * simply not recording once 60 ids existed — starved exactly the ids this
	 * record exists to surface: a rotator that mints many ids fills the table
	 * with its regulars, and then a rare-but-real id (an Apple Pay wallet only
	 * Apple devices are ever offered) can never be recorded at all, leaving the
	 * settings screen insisting the id does not exist.
	 *
	 * @param array $available Gateways keyed by id.
	 */
	private static function record_seen_gateways( $available ) {
		$stored = get_option( 'fs_gw_seen', array() );
		$known  = ( is_array( $stored ) && isset( $stored['ids'] ) && is_array( $stored['ids'] ) ) ? $stored['ids'] : array();
		$last   = ( is_array( $stored ) && isset( $stored['last'] ) && is_array( $stored['last'] ) ) ? $stored['last'] : array();

		$now     = time();
		$changed = false;

		// Ids recorded before last-seen tracking existed start their clock now;
		// entries for ids that are no longer known carry no information.
		foreach ( $known as $id => $title ) {
			if ( ! isset( $last[ $id ] ) ) {
				$last[ $id ] = $now;
				$changed     = true;
			}
		}
		foreach ( array_keys( $last ) as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				unset( $last[ $id ] );
				$changed = true;
			}
		}

		$current = array();
		foreach ( $available as $id => $gw ) {
			$id = (string) $id;
			if ( '' === $id ) {
				continue;
			}
			$current[ $id ] = true;

			$title = ( is_object( $gw ) && method_exists( $gw, 'get_method_title' ) && $gw->get_method_title() )
				? (string) $gw->get_method_title()
				: $id;
			$title = mb_substr( $title, 0, 100 );

			if ( isset( $known[ $id ] ) ) {
				if ( $known[ $id ] !== $title ) {
					$known[ $id ] = $title;
					$changed      = true;
				}
				// Freshness at day granularity: precise enough to pick an
				// eviction victim, cheap enough that a settled table costs at
				// most one write a day instead of one per checkout render.
				if ( $now - (int) $last[ $id ] > DAY_IN_SECONDS ) {
					$last[ $id ] = $now;
					$changed     = true;
				}
				continue;
			}

			if ( count( $known ) >= 80 ) {
				// Evict the stalest id that is NOT being served right now —
				// evicting a current one would just thrash it back in below.
				$victim = '';
				$oldest = PHP_INT_MAX;
				foreach ( $last as $old_id => $seen_at ) {
					if ( isset( $current[ $old_id ] ) ) {
						continue;
					}
					if ( (int) $seen_at < $oldest ) {
						$oldest = (int) $seen_at;
						$victim = (string) $old_id;
					}
				}
				if ( '' === $victim ) {
					continue; // Everything known is currently served; nothing sane to evict.
				}
				unset( $known[ $victim ], $last[ $victim ] );
			}

			$known[ $id ] = $title;
			$last[ $id ]  = $now;
			$changed      = true;
		}

		if ( ! $changed ) {
			return;
		}
		ksort( $known );
		update_option(
			'fs_gw_seen',
			array(
				'ids'  => $known,
				'last' => $last,
				'at'   => current_time( 'mysql' ),
			),
			false
		);
	}

	/**
	 * Gateway ids observed on the storefront: [ id => title ].
	 *
	 * @return array
	 */
	public static function seen_gateways() {
		$stored = get_option( 'fs_gw_seen', array() );
		return ( is_array( $stored ) && isset( $stored['ids'] ) && is_array( $stored['ids'] ) ) ? $stored['ids'] : array();
	}

	/**
	 * What the new-customer gateway rule actually resolves to right now, for the
	 * Settings screen.
	 *
	 * This exists because the ways this rule fails are all invisible from the
	 * settings form. The allow-list is matched against gateway ids by exact
	 * string, and it FAILS OPEN when it matches nothing available — so a ticked id
	 * that no longer exists (renamed, or generated dynamically by a payment
	 * rotator that appends a suffix) turns the whole restriction into a no-op that
	 * still looks configured. An order UNDER the low-value threshold gets the
	 * low-value gateways back, which surprises anyone who read the threshold as
	 * "restrict big orders". And the low-value allowance can be ticked against a
	 * gateway wp-admin knows but the storefront never serves (a rotator id), in
	 * which case the allowance ADDS an id that never matches and quietly grants
	 * nothing — the v1.20.1 bug, invisible precisely because the ticked id IS
	 * registered.
	 *
	 * Availability is therefore judged against BOTH worlds: gateways enabled in
	 * wp-admin and ids actually recorded at checkout (seen_gateways). An id in
	 * either set can genuinely reach a customer; an id in neither cannot.
	 *
	 * @param float $total Order total to preview (0 = ignore the amount rules).
	 * @return array
	 */
	public static function gateway_diagnostics( $total = 0 ) {
		$settings = FS_Core::instance()->settings();
		$total    = (float) $total;

		$registered = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gw ) {
				$registered[ (string) $gw->id ] = array(
					'title'   => ( method_exists( $gw, 'get_method_title' ) && $gw->get_method_title() ) ? $gw->get_method_title() : (string) $gw->id,
					'enabled' => ( isset( $gw->enabled ) && 'yes' === $gw->enabled ),
				);
			}
		}
		$seen = self::seen_gateways();

		$allowed = array_values( array_filter( array_map( 'strval', (array) $settings['gateway_restrict_allowed'] ) ) );
		$low     = array_values( array_filter( array_map( 'strval', (array) ( $settings['gateway_restrict_lowvalue_gateways'] ?? array() ) ) ) );
		$low_amt = (float) ( $settings['gateway_restrict_lowvalue_amount'] ?? 0 );

		// Ids that can actually reach a customer: enabled in wp-admin, or
		// recorded on a real checkout render (which is where rotator-generated
		// ids live — they are often not registered in wp-admin at all).
		$enabled_ids = array();
		foreach ( $registered as $id => $meta ) {
			if ( $meta['enabled'] ) {
				$enabled_ids[] = (string) $id;
			}
		}
		$active = $enabled_ids;
		foreach ( $seen as $id => $title ) {
			$active[] = (string) $id;
		}
		$active = array_values( array_unique( $active ) );

		$low_applies = ( $low_amt > 0 && ! empty( $low ) && $total > 0 && $total < $low_amt );
		$effective   = array_values( array_unique( $low_applies ? array_merge( $allowed, $low ) : $allowed ) );

		$shown = array_values( array_intersect( $active, $effective ) );

		// The high-value soft rule runs AFTER the base rule at checkout and can
		// narrow the list again. Model it for the preview persona — a brand-new,
		// unverified customer — so the preview never claims a method the second
		// stage would take away. Mirrors high_value_rule(): needs the feature
		// on, Didit configured, a positive tier threshold, and a fallback set
		// that matches something (otherwise the stage stands down).
		$hv_fallback = array_values( array_filter( array_map( 'strval', (array) ( $settings['hv_gateways'] ?? array() ) ) ) );
		$hv_amount   = (float) ( $settings['hv_amount_none'] ?? 0 );
		$hv_possible = ! empty( $settings['hv_enabled'] )
			&& class_exists( 'FS_IDV' ) && FS_IDV::configured()
			&& $hv_amount > 0
			&& ! empty( $hv_fallback );
		$hv_applies  = false;
		if ( $hv_possible && $total > 0 && $total >= $hv_amount ) {
			$narrowed = array_values( array_intersect( $shown, $hv_fallback ) );
			if ( ! empty( $narrowed ) ) {
				$hv_applies = true;
				$shown      = $narrowed;
			}
		}

		// The band where the two amount rules collide: hv threshold at or under
		// the low-value threshold means an order can be under the allowance yet
		// over the hv threshold, and the hv stage strips whichever allowance
		// gateways are not also in its fallback set. Legal config, but almost
		// never what the merchant meant.
		$hv_strips = array();
		if ( $hv_possible && $low_amt > 0 && ! empty( $low ) && $hv_amount < $low_amt ) {
			$hv_strips = array_values( array_diff( $low, $hv_fallback ) );
		}

		$known = array_unique( array_merge( array_keys( $registered ), array_keys( $seen ) ) );

		return array(
			'enabled'         => ! empty( $settings['gateway_restrict_enabled'] ),
			'registered'      => $registered,
			'seen'            => $seen,
			'active'          => $active,
			'allowed'         => $allowed,
			'low_gateways'    => $low,
			'low_amount'      => $low_amt,
			'low_applies'     => $low_applies,
			'effective'       => $effective,
			'shown'           => $shown,
			'withheld'        => array_values( array_diff( $active, $shown ) ),
			// Ticked but known nowhere — not registered in wp-admin AND never
			// seen at checkout. These ids can never match anything.
			'unknown_allowed' => array_values( array_diff( array_merge( $allowed, $low ), $known ) ),
			// Allowance ids known NOWHERE: not registered at all and never seen
			// at a checkout. Nothing can ever offer them, so the allowance
			// genuinely grants nothing from these.
			'low_unreachable' => array_values( array_diff( $low, $active, array_keys( $registered ) ) ),
			// Allowance ids that are REGISTERED but not enabled in wp-admin and
			// never yet seen at a checkout. These are not provably dead: wallet
			// gateways (Apple Pay above all) routinely read as inactive in
			// wp-admin while deciding for themselves, per device, whether to
			// appear at checkout — and enforcement filters the LIVE list, so
			// the allowance applies the moment the storefront actually offers
			// the id. What is true is that no checkout render has offered it
			// yet, so it cannot be confirmed from here.
			'low_unconfirmed' => array_values( array_intersect( array_diff( $low, $active ), array_keys( $registered ) ) ),
			// Ticked ids that wp-admin reports ENABLED yet no live checkout
			// render has ever offered — while other ids HAVE been recorded
			// there. Enabled-in-wp-admin is normally proof an id can reach a
			// customer, which is why $active trusts it; a payment rotator
			// breaks that proof by stripping the wp-admin gateways from every
			// render and serving its own entries in their place. An id in this
			// list is one the preview must not promise: if the rotator never
			// offers it, neither the allow-list nor the allowance can surface
			// it, because both can only keep what the render carries. Empty
			// until the seen-recorder has data, so a fresh install with no
			// checkout traffic yet is not accused of running a rotator.
			'unseen_active'   => empty( $seen )
				? array()
				: array_values( array_diff( array_intersect( array_unique( array_merge( $allowed, $low ) ), $enabled_ids ), array_keys( $seen ) ) ),
			// High-value second stage, previewed for an unverified customer.
			'hv_possible'     => $hv_possible,
			'hv_amount'       => $hv_amount,
			'hv_fallback'     => $hv_fallback,
			'hv_applies'      => $hv_applies,
			// Allowance gateways the hv stage would strip inside the
			// [hv_amount, low_amount) band (empty = no conflict).
			'hv_strips'       => $hv_strips,
			// True when the rule is configured yet resolves to nothing usable, in
			// which case apply_gateway_allow() hands back the FULL set.
			'fails_open'      => ( ! empty( $allowed ) && empty( array_intersect( $active, $allowed ) ) && ! empty( $active ) ),
			'total'           => $total,
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Checkout presentation
	 * ----------------------------------------------------------------- */

	/**
	 * The Fraud Shield mark: a shield with a tick. Inline SVG rather than an
	 * image file so it inherits the surrounding text colour and needs no HTTP
	 * request, and so it can never 404 behind a CDN.
	 *
	 * @param int $size Pixel size.
	 * @return string
	 */
	public static function shield_svg( $size = 20 ) {
		$size = max( 10, (int) $size );
		return '<svg class="fs-shield" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">'
			. '<path d="M12 2.2 4.6 5v6.3c0 4.6 3.1 8.9 7.4 10.5 4.3-1.6 7.4-5.9 7.4-10.5V5L12 2.2Z" fill="currentColor" opacity=".14"/>'
			. '<path d="M12 2.2 4.6 5v6.3c0 4.6 3.1 8.9 7.4 10.5 4.3-1.6 7.4-5.9 7.4-10.5V5L12 2.2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>'
			. '<path d="M8.6 12.1l2.3 2.3 4.5-4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Load the checkout stylesheet. Kept out of the admin bundle and off every
	 * other page: it only styles the checkout banner and the verification card.
	 */
	public function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		wp_enqueue_style( 'fs-checkout', FS_PLUGIN_URL . 'assets/checkout.css', array(), FS_VERSION );
	}

	/**
	 * The "protected by Fraud Shield" banner markup, or '' when switched off.
	 *
	 * @return string
	 */
	public static function trust_banner_html() {
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['trust_banner'] ) ) {
			return '';
		}
		$text = trim( (string) ( $settings['trust_banner_text'] ?? '' ) );
		if ( '' === $text ) {
			return '';
		}
		return '<div class="fs-trust-banner">' . self::shield_svg( 18 ) . '<span>' . esc_html( $text ) . '</span></div>';
	}

	/**
	 * Classic checkout: banner above the form.
	 */
	public function render_trust_banner() {
		echo self::trust_banner_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
	}

	/**
	 * Blocks checkout: prepend the banner (and the verification card, which has no
	 * hook of its own there) to the checkout block's own markup.
	 *
	 * @param string $content Rendered block HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	public function prepend_checkout_block_notices( $content, $block ) {
		if ( empty( $block['blockName'] ) || 'woocommerce/checkout' !== $block['blockName'] ) {
			return $content;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $content;
		}

		$before = self::trust_banner_html();
		if ( class_exists( 'FS_IDV' ) ) {
			// idv.js only injects this when it is missing, so rendering it here
			// server-side simply wins the race and avoids a visible pop-in.
			$before .= FS_IDV::render_box( 'checkout' );
		}

		return '' === $before ? $content : $before . $content;
	}

	/**
	 * Optional informational notice shown above the payment methods on the classic
	 * checkout for restricted new customers. Default empty (nothing shown). Block
	 * checkout does not render this hook; the restriction itself still applies there.
	 */
	public function maybe_gateway_notice() {
		$settings = FS_Core::instance()->settings();
		$message  = trim( (string) $settings['gateway_restrict_notice'] );
		if ( '' === $message || ! self::is_gateway_restricted_customer() ) {
			return;
		}
		echo '<div class="woocommerce-info fs-gateway-notice">' . wp_kses_post( $message ) . '</div>';
	}

	/**
	 * Blocks / Store API checkout: enforce while the order is still a draft.
	 *
	 * The blocks checkout creates the order BEFORE any validation runs, so the
	 * old flow could only reject in on_store_api_order_processed() — after the
	 * draft had been converted to a real pending order — by cancelling it. A
	 * cancelled order cannot be reused, so every retry (say, after completing
	 * the requested ID verification) minted ANOTHER cancelled order: the
	 * "duplicate orders" pileup in the admin list.
	 *
	 * This hook fires while the order is still the session's checkout-draft,
	 * with the request's billing details already copied onto it. Throwing here
	 * aborts the attempt but leaves the draft as a draft — invisible in the
	 * admin, and REUSED by the customer's next attempt — so a blocked checkout
	 * produces zero orders no matter how many times it is retried, exactly
	 * like the classic checkout's validate_checkout(). The checks and their
	 * order mirror validate_checkout(); on_store_api_order_processed() stays
	 * as the post-creation backstop.
	 *
	 * @param WC_Order             $order   Draft order, billing fields populated.
	 * @param WP_REST_Request|null $request Checkout request. Optional on purpose:
	 *                                      third-party checkout plugins are known
	 *                                      to re-fire WooCommerce's actions with
	 *                                      fewer arguments than core passes, and a
	 *                                      required parameter turns that into a
	 *                                      fatal (HTTP 500) at argument binding —
	 *                                      before any guard can run.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the attempt must be blocked.
	 */
	public function on_store_api_draft_order( $order, $request = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		// Only the place-order submission (POST) is enforced here. WooCommerce
		// fires this same action on the checkout PUT/PATCH "data update"
		// requests that run WHILE the customer is still filling the form —
		// selecting a payment method, editing an address, adding order notes.
		// Throwing on those wedges the form itself: the customer is refused
		// before they ever press Place Order, and before they could clear the
		// condition (e.g. complete the requested ID verification). Anything
		// that is not provably a POST place-order is skipped — the
		// on_store_api_order_processed backstop still enforces at submission.
		if ( ! ( $request instanceof WP_REST_Request ) || 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return;
		}

		// Only drafts. The pay-for-order route (POST /checkout/{id}) fires this
		// action with a real pending order; that flow keeps its pre-1.23.0
		// treatment (the backstop), and a rejection here must never touch an
		// order that already exists outside this checkout session.
		if ( 'checkout-draft' !== $order->get_status() ) {
			return;
		}

		$settings = FS_Core::instance()->settings();
		$signals  = FS_Data::extract_signals( $order );
		// The draft already carries the cart's totals, so it is the amount a
		// refusal here keeps out of a fraudster's hands.
		$draft_total = (float) $order->get_total() > 0 ? (float) $order->get_total() : self::cart_total();

		// Blocklist: explicit merchant decision, hard-blocks in every mode.
		// Only the staff Verified flag is exempt.
		if ( ! self::is_verified_customer( (int) $signals['user_id'] ) ) {
			$matches = FS_Blocklist::match( $signals );
			if ( ! empty( $matches ) ) {
				$evidence = FS_Blocklist::match_evidence( $matches, $signals );
				self::record_block( 'blocklist', $signals, implode( ', ', array_unique( wp_list_pluck( $matches, 'type' ) ) ), $evidence );
				self::prevented( 'blocklist', 'refused', $signals, $draft_total, FS_Blocklist::evidence_identities( $evidence ) );
				$this->throw_store_api_block( self::pick_block_message( $matches, $settings ) );
				return;
			}
		}

		// Repeat attempt after a linked-identity cancellation (v1.26): refused
		// while the draft is still a draft, so no further order is minted.
		$linked_message = $this->linked_gate_refusal( $signals, $settings, $draft_total );
		if ( null !== $linked_message ) {
			$this->throw_store_api_block( $linked_message );
			return;
		}

		// Staff-demanded ID verification: under the blocklist, over the
		// automatic locks, answerable only by the ID check itself.
		if ( FS_IDV::requires_verification( (int) $signals['user_id'], (string) $signals['email_norm'] ) ) {
			self::record_block( 'idv', $signals, 'id verification requested' );
			self::prevented( 'idv', 'refused', $signals, $draft_total );
			$this->throw_store_api_block( self::idv_required_message( (int) $signals['user_id'], $settings ) );
			return;
		}

		$sig_verified = self::is_signal_verified( $signals );

		// Velocity rule, "block" action only (all modes). "hold" acts after the
		// order exists; "restrict" limits gateways instead. The draft is never
		// indexed, but exclude it defensively anyway.
		if ( ! $sig_verified
			&& ! empty( $settings['velocity_lock'] )
			&& 'block' === self::velocity_action( $settings )
			&& self::is_velocity_locked( (int) $signals['user_id'], $signals['email_norm'], (int) $order->get_id(), (string) ( $signals['phone_norm'] ?? '' ), (string) ( $signals['ship_door_hash'] ?? '' ) ) ) {
			self::record_block( 'velocity', $signals );
			self::prevented( 'velocity', 'refused', $signals, $draft_total );
			$this->throw_store_api_block( $settings['velocity_lock_message'] );
			return;
		}

		// Existing new-account verification hold.
		if ( ! $sig_verified && self::is_on_hold( (int) $signals['user_id'], $signals['email_norm'] ) ) {
			self::prevented( 'hold', 'refused', $signals, $draft_total );
			$this->throw_store_api_block( $settings['hold_message'] );
			return;
		}

		// Gateway allow-list, judged with the identity the customer just typed.
		// The chosen method rides the request; the draft may not carry it yet.
		$chosen = isset( $request['payment_method'] ) ? sanitize_text_field( (string) $request['payment_method'] ) : '';
		if ( '' === $chosen ) {
			$chosen = (string) $order->get_payment_method();
		}
		if ( '' !== $chosen
			&& function_exists( 'WC' ) && WC()->cart && WC()->cart->needs_payment()
			&& ! self::is_gateway_allowed_for( (int) $signals['user_id'], (string) $signals['email_norm'], $chosen, self::order_amount( $order ) ) ) {
			$this->throw_store_api_block( $settings['gateway_restrict_reject_message'] );
			return;
		}

		// High-value hard rule — deliberately not behind $sig_verified: it exists
		// to ask otherwise-trusted customers to re-verify at this order size.
		$hv = self::high_value_rule( (int) $signals['user_id'], (string) $signals['email_norm'], self::order_amount( $order ) );
		if ( null !== $hv && 'hard' === $hv['mode'] ) {
			self::prevented( 'high_value', 'refused', $signals, $draft_total );
			$this->throw_store_api_block( self::hv_block_message( (int) $signals['user_id'], $hv, $settings ) );
			return;
		}

		// Heuristic-score blocking only applies under Enforce.
		if ( 'enforce' !== $settings['mode'] || $sig_verified ) {
			return;
		}

		$profile = FS_Data::build_profile( $signals, (int) $order->get_id() );
		$scored  = FS_Rules::score_profile( $profile, $settings );
		if ( 'block' === $scored['level'] || ( 'high' === $scored['level'] && 'block' === $settings['action_high'] ) ) {
			self::prevented( 'score', 'refused', $signals, $draft_total );
			$this->throw_store_api_block( $settings['block_message'] );
		}
	}

	/**
	 * Blocks / Store API checkout: index + score, then enforce BEFORE payment.
	 *
	 * This action fires after the order is set to "pending" but before payment is
	 * processed, so throwing a RouteException here rejects the order without ever
	 * charging the customer — the true pre-payment block the classic checkout gets
	 * from validate_checkout(). Since v1.23.0 the draft-stage check above rejects
	 * these attempts before the draft converts, so in practice this path only
	 * fires when that hook was unavailable — it is the backstop, and cancelling
	 * here is what used to pile up cancelled "duplicate" orders on every retry.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the order must be blocked under enforcement.
	 */
	public function on_store_api_order_processed( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		$decision     = $this->evaluate_and_record( $order );
		$settings     = $decision['settings'];
		$verified     = self::is_verified_customer( (int) $order->get_customer_id() );
		$sig_verified = self::is_signal_verified( $decision['signals'] );

		// Blocklist matches abort the checkout BEFORE payment in EVERY mode
		// (unless the customer is merchant-verified — the account flag only;
		// verified address/IP credentials never bypass an explicit block).
		// The savings row is written whether or not the order still needed
		// cancelling (counted once per order either way).
		if ( ! empty( $decision['matches'] ) && ! $verified ) {
			$evidence = FS_Blocklist::match_evidence( $decision['matches'], $decision['signals'] );
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: cancelled (blocklist match).', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'blocklist', 'cancelled', $order, null, FS_Blocklist::evidence_identities( $evidence ) );
			$this->record_order_block( 'blocklist', $order, implode( ', ', array_unique( wp_list_pluck( $decision['matches'], 'type' ) ) ), $evidence );
			$this->throw_store_api_block( self::pick_block_message( $decision['matches'], $settings ) );
			return;
		}

		// Linked to a blocked identity (v1.26): cancel and abort before payment,
		// exactly like a blocklist match, with the rule's own message.
		$linked_message = $this->maybe_linked_cancel( $order, $decision, $settings );
		if ( null !== $linked_message ) {
			$this->throw_store_api_block( $linked_message );
			return;
		}

		// A staff-demanded ID verification, same as the classic path: ranked
		// under the blocklist, over the automatic locks, and answerable only by
		// the ID check itself — so it is tested before the verified-credential
		// shortcut below.
		if ( FS_IDV::requires_verification( (int) $order->get_customer_id(), FS_Normalizer::email( $order->get_billing_email() ) ) ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: checkout blocked pending ID verification.', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'idv', 'cancelled', $order );
			$this->record_order_block( 'idv', $order, 'id verification requested' );
			$this->throw_store_api_block( self::idv_required_message( (int) $order->get_customer_id(), $settings ) );
			return;
		}

		// Gateway allow-list, re-checked now that the order carries a real billing
		// email and payment method. Evaluated from the same policy the display
		// filter uses rather than from $sig_verified, so the two surfaces cannot
		// reach opposite verdicts — and deliberately BEFORE the verified-credential
		// shortcut below, because a verified IP or address is not a reason to hand
		// someone a payment method the gateway policy withholds.
		$gw_email = FS_Normalizer::email( $order->get_billing_email() );
		$gw_id    = (string) $order->get_payment_method();
		if ( '' !== $gw_id
			&& $order->needs_payment()
			&& ! self::is_gateway_allowed_for( (int) $order->get_customer_id(), $gw_email, $gw_id, self::order_amount( $order ) ) ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: payment method not available to this customer.', 'fraud-shield' ) . ' ' );
			}
			$this->throw_store_api_block( $settings['gateway_restrict_reject_message'] );
			return;
		}

		// High-value hard rule, mirroring the classic path. Checked before the
		// verified-credential shortcut below because this rule deliberately
		// applies to otherwise-trusted customers on orders of this size.
		$hv = self::high_value_rule( (int) $order->get_customer_id(), $gw_email, self::order_amount( $order ) );
		if ( null !== $hv && 'hard' === $hv['mode'] ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: high-value order refused pending ID verification.', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'high_value', 'cancelled', $order );
			$this->throw_store_api_block( self::hv_block_message( (int) $order->get_customer_id(), $hv, $settings ) );
			return;
		}

		// A verified credential (account, email, address, or IP) bypasses the
		// velocity lock, the hold, and score-based blocking below.
		if ( $sig_verified ) {
			return;
		}

		// Velocity rule (independent of mode). "hold": the order is placed
		// On-Hold for review and the checkout completes. "block": cancelled
		// and refused before payment. "restrict": gateways limited elsewhere.
		// The just-created order is excluded from the count so the threshold
		// matches the classic checkout path.
		if ( ! empty( $settings['velocity_lock'] ) && 'hold' === self::velocity_action( $settings ) ) {
			$this->maybe_velocity_hold( $order, $decision['signals'], $settings );
		} elseif ( ! empty( $settings['velocity_lock'] ) && 'block' === self::velocity_action( $settings )
			&& self::is_velocity_locked( (int) $order->get_customer_id(), FS_Normalizer::email( $order->get_billing_email() ), $order->get_id(), (string) ( $decision['signals']['phone_norm'] ?? '' ), (string) ( $decision['signals']['ship_door_hash'] ?? '' ) ) ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: checkout locked pending manual verification (order velocity).', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'velocity', 'cancelled', $order );
			$this->record_order_block( 'velocity', $order );
			$this->throw_store_api_block( $settings['velocity_lock_message'] );
			return;
		}

		// Enforce an EXISTING verification hold (account locked from a prior order).
		if ( self::is_on_hold( (int) $order->get_customer_id(), FS_Normalizer::email( $order->get_billing_email() ) ) ) {
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: checkout locked pending manual verification (new-account hold).', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'hold', 'cancelled', $order );
			$this->throw_store_api_block( $settings['hold_message'] );
			return;
		}

		// Trigger a NEW hold if this order qualifies (new account + high value).
		// Runs in every mode; does not reject this order (it is held for review).
		$this->maybe_place_hold( $order, $settings );

		if ( 'enforce' !== $settings['mode'] ) {
			return;
		}

		if ( $this->should_block( $decision['scored'], array(), $settings ) ) {
			// Cancel so no stock is held, then abort the checkout before payment.
			if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$order->update_status( 'cancelled', __( 'Fraud Shield: cancelled (block policy).', 'fraud-shield' ) . ' ' );
			}
			self::prevented( 'score', 'cancelled', $order );
			$this->throw_store_api_block( $settings['block_message'] );
		} elseif ( 'high' === $decision['scored']['level'] && 'hold' === $settings['action_high']
			&& in_array( $order->get_status(), array( 'pending', 'processing' ), true ) ) {
			$order->update_status( 'on-hold', __( 'Fraud Shield: held for manual review (high risk).', 'fraud-shield' ) . ' ' );
		}
	}

	/**
	 * The customer-facing message for a demanded ID verification.
	 *
	 * When the block was matched by email rather than by the session — i.e. the
	 * customer signed out and is checking out as a guest — the verify button has
	 * nowhere to appear, so the message has to point them back to their account
	 * instead of leaving them with a refusal and no route.
	 *
	 * @param int   $user_id  Account id from the checkout session (0 = guest).
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private static function idv_required_message( $user_id, $settings ) {
		$message = (string) $settings['idv_required_message'];
		if ( (int) $user_id <= 0 ) {
			$message .= ' ' . __( 'Please sign in to your account to verify.', 'fraud-shield' );
		}
		return $message;
	}

	/**
	 * The customer-facing refusal for a high-value order awaiting verification.
	 * Supports an {amount} placeholder for the rule's threshold, and points a
	 * guest session at signing in, since verification lives on the account.
	 *
	 * @param int   $user_id  Account id from the checkout session (0 = guest).
	 * @param array $rule     The fired rule from high_value_rule().
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	public static function hv_block_message( $user_id, $rule, $settings ) {
		$message = (string) ( $settings['hv_block_message'] ?? '' );
		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			$message = __( 'Orders of this value require identity verification before they can be placed. Please use the Verify my ID button at checkout, then submit your order again.', 'fraud-shield' );
		}
		$message = str_replace( '{amount}', wp_strip_all_tags( wc_price( (float) $rule['threshold'] ) ), $message );
		if ( (int) $user_id <= 0 ) {
			$message .= ' ' . __( 'Please sign in to your account (or create one) first, so your verification can be saved.', 'fraud-shield' );
		}
		return $message;
	}

	/**
	 * Throw a Store API RouteException to reject a blocks checkout before payment.
	 *
	 * @param string $message Customer-facing message.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Always (when the class exists).
	 */
	private function throw_store_api_block( $message ) {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			// The blocks checkout renders this as plain text, so strip any markup.
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'fs_blocked',
				wp_strip_all_tags( (string) $message ),
				403
			);
		}
	}

	/**
	 * Whether checkout is currently locked for this customer by the velocity rule.
	 *
	 * Deliberately independent of Observe/Enforce mode — it is its own opt-in
	 * control. A customer the merchant has marked "verified" is always exempt.
	 *
	 * "This customer" is the account and the email, and (v1.31, each behind
	 * its own setting) the billing phone and the shipping door key — because
	 * the customer this rule exists for rotates email addresses between
	 * orders and keeps everything else. The phone and door are only ever
	 * ADDED to the count; a caller that does not know them passes ''.
	 *
	 * @param int    $user_id          Account id (0 for guest).
	 * @param string $email_norm       Normalized email.
	 * @param int    $exclude_order_id Order id to exclude from the count.
	 * @param string $phone_norm       Normalized billing phone, when known.
	 * @param string $door_hash        Shipping door key, when known.
	 * @return bool
	 */
	public static function is_velocity_locked( $user_id, $email_norm, $exclude_order_id = 0, $phone_norm = '', $door_hash = '' ) {
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['velocity_lock'] ) ) {
			return false;
		}

		$user_id    = (int) $user_id;
		$email_norm = (string) $email_norm;
		$phone_norm = ! empty( $settings['velocity_lock_by_phone'] ) ? (string) $phone_norm : '';
		$door_hash  = ! empty( $settings['velocity_lock_by_door'] ) ? (string) $door_hash : '';
		if ( $user_id <= 0 && '' === $email_norm && '' === $phone_norm && '' === $door_hash ) {
			return false;
		}

		// A manually verified account or an allowlisted email is exempt.
		if ( self::is_exempt( $user_id, $email_norm ) ) {
			return false;
		}

		// Optionally limit the lock to newly-registered accounts.
		if ( ! empty( $settings['velocity_lock_new_only'] ) ) {
			$window        = max( 1, (int) $settings['velocity_lock_new_months'] ) * 30 * DAY_IN_SECONDS;
			$now           = time();
			$first_seen_ts = null;

			if ( $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( $user && ! empty( $user->user_registered ) ) {
					$first_seen_ts = strtotime( $user->user_registered . ' UTC' );
				}
			}
			if ( null === $first_seen_ts && '' !== $email_norm ) {
				$fs = FS_Data::email_first_seen( $email_norm );
				if ( $fs ) {
					$first_seen_ts = strtotime( $fs );
				}
			}

			// Established (older than the window) → not subject to the new-customer lock.
			if ( null !== $first_seen_ts && ( $now - $first_seen_ts ) > $window ) {
				return false;
			}
		}

		$threshold = max( 1, (int) $settings['velocity_lock_threshold'] );
		$count     = FS_Data::recent_order_count( $user_id, $email_norm, self::velocity_window_days( $settings ), $exclude_order_id, $phone_norm, $door_hash );

		return $count >= $threshold;
	}

	/**
	 * The velocity rule's window, in (possibly fractional) days — the
	 * merchant sets a number and a unit (days or hours). Thirty days until
	 * v1.31, when it became configurable.
	 *
	 * @param array|null $settings Settings (read when null).
	 * @return float
	 */
	public static function velocity_window_days( $settings = null ) {
		if ( null === $settings ) {
			$settings = FS_Core::instance()->settings();
		}
		$n = max( 1, (int) ( $settings['velocity_lock_window'] ?? 30 ) );
		return (float) ( 'hours' === (string) ( $settings['velocity_lock_window_unit'] ?? 'days' ) ? $n / 24 : $n );
	}

	/**
	 * The velocity rule's window as the merchant would say it ("30 days",
	 * "48 hours"), for notes and messages.
	 *
	 * @param array|null $settings Settings (read when null).
	 * @return string
	 */
	public static function velocity_window_label( $settings = null ) {
		if ( null === $settings ) {
			$settings = FS_Core::instance()->settings();
		}
		$n = max( 1, (int) ( $settings['velocity_lock_window'] ?? 30 ) );
		if ( 'hours' === (string) ( $settings['velocity_lock_window_unit'] ?? 'days' ) ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour', '%d hours', $n, 'fraud-shield' ), $n );
		}
		/* translators: %d: number of days */
		return sprintf( _n( '%d day', '%d days', $n, 'fraud-shield' ), $n );
	}

	/**
	 * Which of the velocity rule's three actions is configured: 'hold' (the
	 * order is placed On-Hold for review — the default), 'block' (the
	 * checkout is refused) or 'restrict' (limited payment gateways).
	 *
	 * @param array|null $settings Settings (read when null).
	 * @return string
	 */
	public static function velocity_action( $settings = null ) {
		if ( null === $settings ) {
			$settings = FS_Core::instance()->settings();
		}
		$a = (string) ( $settings['velocity_lock_action'] ?? 'hold' );
		return in_array( $a, array( 'hold', 'block', 'restrict' ), true ) ? $a : 'hold';
	}

	/**
	 * The velocity rule's HOLD action (v1.31): the order is accepted and
	 * placed On-Hold for manual review, stamped so payment completing cannot
	 * lift the hold (on_payment_complete re-asserts it), noted on the order,
	 * listed on Checkout Locks under the velocity rule, and mailed to the
	 * team when hold recipients are configured. The customer is told nothing
	 * at checkout — the order simply waits for a human.
	 *
	 * Idempotent per order. Never touches a cancelled, refunded or failed
	 * order; never touches an order already on hold.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $signals  The order's normalized signals.
	 * @param array    $settings Settings.
	 * @return bool True when this call placed the hold.
	 */
	private function maybe_velocity_hold( $order, $signals, $settings ) {
		if ( empty( $settings['velocity_lock'] ) || 'hold' !== self::velocity_action( $settings ) ) {
			return false;
		}
		if ( 'yes' === (string) $order->get_meta( '_fs_velocity_held' ) ) {
			return false;
		}
		$uid   = (int) $order->get_customer_id();
		$email = FS_Normalizer::email( $order->get_billing_email() );
		if ( ! self::is_velocity_locked( $uid, $email, (int) $order->get_id(), (string) ( $signals['phone_norm'] ?? '' ), (string) ( $signals['ship_door_hash'] ?? '' ) ) ) {
			return false;
		}

		$count = FS_Data::recent_order_count( $uid, $email, self::velocity_window_days( $settings ), (int) $order->get_id(), ! empty( $settings['velocity_lock_by_phone'] ) ? (string) ( $signals['phone_norm'] ?? '' ) : '', ! empty( $settings['velocity_lock_by_door'] ) ? (string) ( $signals['ship_door_hash'] ?? '' ) : '' );
		$note  = sprintf(
			/* translators: 1: number of earlier orders, 2: the window ("30 days") */
			_n( 'Fraud Shield: held for manual review — velocity rule: %1$d earlier order in the last %2$s.', 'Fraud Shield: held for manual review — velocity rule: %1$d earlier orders in the last %2$s.', $count, 'fraud-shield' ),
			$count,
			self::velocity_window_label( $settings )
		);

		$order->update_meta_data( '_fs_velocity_held', 'yes' );
		if ( in_array( $order->get_status(), array( 'pending', 'processing' ), true ) ) {
			$order->update_status( 'on-hold', $note . ' ' );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}

		self::record_block( 'velocity', $signals, sprintf( /* translators: %d: order number */ __( 'order #%d placed on hold', 'fraud-shield' ), $order->get_id() ) );
		$this->notify_velocity_hold( $order, $settings, $count );
		return true;
	}

	/**
	 * Keep a velocity-held order On-Hold after its payment completes (the
	 * gateway's payment_complete() would otherwise move it to processing).
	 *
	 * @param WC_Order $order Order.
	 */
	private static function reassert_velocity_hold( $order ) {
		if ( 'yes' !== (string) $order->get_meta( '_fs_velocity_held' ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'on-hold', 'cancelled', 'refunded', 'failed' ), true ) ) {
			$order->update_status( 'on-hold', __( 'Fraud Shield: still held for manual review (velocity rule).', 'fraud-shield' ) . ' ' );
		}
	}

	/**
	 * Release a verified customer's velocity-held orders: each one still
	 * On-Hold and actually paid moves to processing. Unpaid held orders stay
	 * where they are — verification is not payment. Called by the Verify and
	 * Allow actions.
	 *
	 * @param int    $user_id    Account id (0 = none).
	 * @param string $email_norm Normalized email.
	 * @return int Orders released.
	 */
	public static function release_velocity_holds( $user_id, $email_norm = '' ) {
		global $wpdb;
		$user_id    = (int) $user_id;
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( ( $user_id <= 0 && '' === $email_norm ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$where = array();
		$args  = array();
		if ( $user_id > 0 ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}
		if ( '' !== $email_norm ) {
			$where[] = 'email_norm = %s';
			$args[]  = $email_norm;
		}
		$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT order_id FROM ' . FS_Data::index_table() . ' WHERE (' . implode( ' OR ', $where ) . ") AND status = 'on-hold' ORDER BY order_date DESC LIMIT 100", $args ) ); // phpcs:ignore
		$released = 0;
		foreach ( (array) $ids as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( $order && 'on-hold' === $order->get_status() && 'yes' === (string) $order->get_meta( '_fs_velocity_held' ) && $order->get_date_paid() ) {
				$order->delete_meta_data( '_fs_velocity_held' );
				$order->update_status( 'processing', __( 'Fraud Shield: released after manual verification (velocity rule).', 'fraud-shield' ) . ' ' );
				$released++;
			}
		}
		return $released;
	}

	/**
	 * Email the team that an order was held under the velocity rule. Hold
	 * recipients first (the people who already handle the verification queue),
	 * else the risk-alert recipients; silent when neither is configured.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $settings Settings.
	 * @param int      $count    Earlier orders in the window.
	 */
	private function notify_velocity_hold( $order, $settings, $count ) {
		$raw        = trim( (string) ( $settings['hold_emails'] ?? '' ) );
		$raw        = '' !== $raw ? $raw : (string) ( $settings['notify_emails'] ?? '' );
		$recipients = array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' );
		if ( empty( $recipients ) ) {
			return;
		}
		/* translators: %d: order number */
		$subject = sprintf( __( '[Fraud Shield] Order #%d held — velocity rule', 'fraud-shield' ), $order->get_id() );
		$lines   = array(
			sprintf(
				/* translators: 1: number of earlier orders, 2: the window ("30 days") */
				__( 'This customer already had %1$d order(s) in the last %2$s, so this order was placed On-Hold for review instead of proceeding.', 'fraud-shield' ),
				(int) $count,
				self::velocity_window_label( $settings )
			),
			'',
			sprintf( __( 'Order: #%d', 'fraud-shield' ), $order->get_id() ),
			sprintf( __( 'Amount: %s', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ) ),
			sprintf( __( 'Customer: %1$s <%2$s> %3$s', 'fraud-shield' ), trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), $order->get_billing_email(), $order->get_billing_phone() ),
			sprintf( __( 'Ships to: %s', 'fraud-shield' ), wp_strip_all_tags( str_replace( '<br/>', ', ', (string) $order->get_formatted_shipping_address() ) ) ),
			'',
			__( 'Review the order, then Verify the customer on Checkout Locks to release their held orders:', 'fraud-shield' ),
			admin_url( 'admin.php?page=fraud-shield-locks' ),
		);
		wp_mail( $recipients, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Message the checkout gate should show the current visitor, or '' when
	 * checkout is allowed.
	 *
	 * Matches everything we can know BEFORE they type anything: their account
	 * (user id, email, saved billing phone, saved billing/shipping addresses)
	 * and their current IP. Address/credential combinations only entered at
	 * checkout are still caught server-side at submission.
	 *
	 * @var string
	 */
	private $gate_message = '';

	/**
	 * Extra already-escaped HTML to append inside the gate card (currently the
	 * self-serve ID-verification button, which turns a dead end into an exit).
	 *
	 * @var string
	 */
	private $gate_extra = '';

	/**
	 * Gate the checkout page for identifiable blocked or velocity-locked
	 * visitors: they see only the configured message — no checkout form at all.
	 */
	public function maybe_gate_checkout_page() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// Never gate the post-purchase "thank you" screen.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$settings = FS_Core::instance()->settings();
		$uid      = get_current_user_id();

		if ( self::is_verified_customer( $uid ) ) {
			return;
		}

		// --- Blocklist: match every credential we already know about ---
		$ip      = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
		$signals = array(
			'user_id'    => $uid,
			'email_norm' => '',
			'phone_norm' => '',
			'ip'         => $ip,
		);

		$ship_hashes = array();
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$signals['email_norm'] = FS_Normalizer::email( $user->user_email );
			}
			$customer = new WC_Customer( $uid );
			$signals['phone_norm'] = FS_Normalizer::phone( $customer->get_billing_phone() );

			foreach ( array( 'billing', 'shipping' ) as $kind ) {
				$addr = array(
					'address_1' => 'billing' === $kind ? $customer->get_billing_address_1() : $customer->get_shipping_address_1(),
					'address_2' => 'billing' === $kind ? $customer->get_billing_address_2() : $customer->get_shipping_address_2(),
					'city'      => 'billing' === $kind ? $customer->get_billing_city() : $customer->get_shipping_city(),
					'state'     => 'billing' === $kind ? $customer->get_billing_state() : $customer->get_shipping_state(),
					'postcode'  => 'billing' === $kind ? $customer->get_billing_postcode() : $customer->get_shipping_postcode(),
					'country'   => 'billing' === $kind ? $customer->get_billing_country() : $customer->get_shipping_country(),
				);
				if ( '' !== trim( (string) $addr['address_1'] ) ) {
					// Carry each saved address's geo so the per-address match() call
					// also tests destination locks. Guests (no saved address) can't
					// be geo-gated pre-submit — destination is unknown until typed —
					// but they are caught by validate_checkout / the Store API path
					// at submission, exactly like address hashes.
					$ship_hashes[] = array(
						'ship_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $addr ) ),
						'ship_loose_hash'  => FS_Normalizer::hash( FS_Normalizer::address_loose( $addr ) ),
						'shipping_country' => strtoupper( trim( (string) $addr['country'] ) ),
						'ship_state'       => FS_Normalizer::region( $addr['state'] ),
						'ship_city'        => FS_Normalizer::city( $addr['city'] ),
						'ship_postcode'    => FS_Normalizer::postcode( $addr['postcode'] ),
						'ship_street'      => FS_Normalizer::street( $addr ),
						'ship_door_hash'   => FS_Normalizer::door( $addr ),
						'ship_door_alts'   => FS_Normalizer::door_alternates( $addr ),
						'ship_number'      => FS_Normalizer::house( $addr ),
						'ship_parts'       => $addr,
					);
				}
			}
		}
		if ( empty( $ship_hashes ) ) {
			$ship_hashes[] = array(
				'ship_hash'        => '',
				'ship_loose_hash'  => '',
				'shipping_country' => '',
				'ship_state'       => '',
				'ship_city'        => '',
				'ship_postcode'    => '',
				'ship_street'      => '',
				'ship_door_hash'   => '',
				'ship_door_alts'   => array(),
				'ship_number'      => '',
				'ship_parts'       => array(),
			);
		}

		$matches     = array();
		$hit_signals = $signals;
		foreach ( $ship_hashes as $hashes ) {
			$m = FS_Blocklist::match( array_merge( $signals, $hashes ) );
			if ( ! empty( $m ) ) {
				$matches     = $m;
				$hit_signals = array_merge( $signals, $hashes ); // The saved address that matched, for the evidence.
				break;
			}
		}

		// Best-effort name for the ledger row.
		$gate_user = $uid > 0 ? get_userdata( $uid ) : null;
		$signals['customer_name'] = $gate_user ? $gate_user->display_name : '';

		// The gate turns a customer away as the checkout PAGE opens: nothing
		// was submitted, so nothing is written to the fraud-prevented savings
		// ledger here (v1.26.2) — opening a page is not a purchase attempt,
		// and a blocked customer reloading it daily must not add their cart
		// to "money saved". Checkout Locks still records who was turned away.
		if ( ! empty( $matches ) ) {
			$this->gate_message = self::pick_block_message( $matches, $settings );
			self::record_block( 'blocklist', $signals, implode( ', ', array_unique( wp_list_pluck( $matches, 'type' ) ) ), FS_Blocklist::match_evidence( $matches, $hit_signals ) );
		} elseif ( FS_IDV::requires_verification( $uid, $signals['email_norm'] ) ) {
			// Staff demanded an ID check from this account. Ranked above the
			// automatic locks because it is an explicit human decision, and
			// below the blocklist because a blocked customer is not being asked
			// a question — they are being refused. The gate carries the verify
			// button so the customer can clear this without contacting anyone.
			$this->gate_message = (string) $settings['idv_required_message'];
			$this->gate_extra   = FS_IDV::gate_verify_html();
			self::record_block( 'idv', $signals, 'id verification requested' );
		} elseif ( ! empty( $settings['velocity_lock'] )
			&& 'block' === self::velocity_action( $settings )
			&& $uid > 0 && $this->gate_velocity_locked( $uid, $signals, $ship_hashes ) ) {
			$this->gate_message = (string) $settings['velocity_lock_message'];
			self::record_block( 'velocity', $signals );
		} elseif ( $uid > 0 && self::is_on_hold( $uid, $signals['email_norm'] ) ) {
			// A held account is already tracked durably in the Verification queue,
			// so it needs no separate ledger row.
			$this->gate_message = (string) $settings['hold_message'];
		}

		if ( '' !== $this->gate_message ) {
			$this->render_gate_and_exit( $this->gate_message );
		}
	}

	/**
	 * The checkout-page gate's velocity test: the account with its saved
	 * billing phone, and each saved address's door key in turn (v1.31), so
	 * the gate agrees with what validate_checkout() will decide once the
	 * saved address is submitted.
	 *
	 * @param int   $uid         Account id.
	 * @param array $signals     Account-level signals (email_norm, phone_norm).
	 * @param array $ship_hashes Per-saved-address hash sets (ship_door_hash).
	 * @return bool
	 */
	private function gate_velocity_locked( $uid, $signals, $ship_hashes ) {
		$phone = (string) ( $signals['phone_norm'] ?? '' );
		$doors = array_values( array_unique( array_filter( array_map( 'strval', wp_list_pluck( (array) $ship_hashes, 'ship_door_hash' ) ) ) ) );
		if ( empty( $doors ) ) {
			$doors = array( '' );
		}
		foreach ( $doors as $door ) {
			if ( self::is_velocity_locked( (int) $uid, (string) $signals['email_norm'], 0, $phone, $door ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hard checkout gate. Runs on template_redirect (before any theme renders,
	 * on classic, FSE/block, shortcode and block-checkout alike), so a blocked
	 * visitor never sees the checkout form — only the message.
	 *
	 * Cache hardening is non-negotiable: without it a page cache or CDN could
	 * store this response for the checkout URL and serve "you are blocked" to
	 * every customer. We send no-store headers, define DONOTCACHEPAGE, and a 403.
	 *
	 * @param string $message Block/lock message (may contain limited HTML).
	 */
	private function render_gate_and_exit( $message ) {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			status_header( 403 );
		}

		$html = $this->checkout_gate_html( $message );

		/**
		 * Filter the full HTML document rendered by the checkout gate, so a
		 * merchant can theme the blocked page.
		 *
		 * @param string $html    Complete HTML document.
		 * @param string $message The block/lock message.
		 */
		$html = apply_filters( 'fs_checkout_gate_html', $html, $message );

		echo $html; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- assembled from escaped parts in checkout_gate_html().
		exit;
	}

	/**
	 * Build the self-contained HTML document for the checkout gate. No
	 * get_header()/get_footer() (they break or double-render on FSE themes) and
	 * no wp_head(); every dynamic value is escaped here.
	 *
	 * @param string $message Block/lock message.
	 * @return string
	 */
	private function checkout_gate_html( $message ) {
		$lang      = get_bloginfo( 'language' );
		$site_name = get_bloginfo( 'name' );
		$home      = home_url( '/' );
		$heading   = __( 'Checkout unavailable', 'fraud-shield' );

		$css = 'body{margin:0;background:#f6f7f7;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;line-height:1.5}'
			. '.fs-wrap{max-width:640px;margin:12vh auto;padding:0 24px}'
			. '.fs-brand{text-align:center;font-size:15px;font-weight:600;margin-bottom:28px}'
			. '.fs-brand a{color:#2271b1;text-decoration:none}'
			. '.fs-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:32px 28px;box-shadow:0 1px 2px rgba(0,0,0,.04)}'
			. '.fs-card h1{font-size:20px;margin:0 0 16px}'
			. '.woocommerce-error{margin:0;padding:14px 16px;border-left:4px solid #d63638;background:#fcf0f1;border-radius:4px;list-style:none}'
			. '.fs-home{display:inline-block;margin-top:24px;color:#2271b1;text-decoration:none}'
			. '.fs-idv-gate{margin-top:20px;padding:16px;border:1px solid #dcdcde;border-radius:6px;background:#fbfbfc}'
			. '.fs-idv-gate button{font:inherit;font-weight:600;color:#fff;background:#2271b1;border:0;border-radius:4px;padding:11px 20px;cursor:pointer}'
			. '.fs-idv-gate button:hover{background:#135e96}'
			. '.fs-idv-gate button[disabled]{opacity:.6;cursor:default}'
			. '.fs-idv-gate p{margin:0 0 12px}'
			. '.fs-idv-gate small{display:block;margin-top:12px;color:#646970}';

		$html  = '<!doctype html>';
		$html .= '<html lang="' . esc_attr( $lang ) . '">';
		$html .= '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		$html .= '<title>' . esc_html( $heading . ' — ' . $site_name ) . '</title>';
		$html .= '<style>' . $css . '</style></head>';
		$html .= '<body><div class="fs-wrap">';
		$html .= '<div class="fs-brand"><a href="' . esc_url( $home ) . '">' . esc_html( $site_name ) . '</a></div>';
		$html .= '<div class="fs-card woocommerce">';
		$html .= '<h1>' . esc_html( $heading ) . '</h1>';
		$html .= '<div class="woocommerce-error" role="alert">' . wp_kses_post( $message ) . '</div>';
		// Already assembled from escaped parts by its producer (FS_IDV).
		$html .= $this->gate_extra;
		$html .= '<a class="fs-home" href="' . esc_url( $home ) . '">&larr; ' . esc_html__( 'Return to store', 'fraud-shield' ) . '</a>';
		$html .= '</div></div></body></html>';

		return $html;
	}

	/**
	 * Re-index when an order's status changes (keeps chargeback flags current).
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$settings      = FS_Core::instance()->settings();
		$signals       = FS_Data::extract_signals( $order );
		$is_chargeback = FS_Data::is_chargeback( $order, $settings );

		// A status change can flip the charged-back state without anyone
		// pressing a button — the Charged back status, or a refund on a store
		// that counts refunds. Compare against what the index held so the
		// order.chargeback event fires exactly once per flip (v1.30).
		$prev = FS_Data::index_row( (int) $order->get_id() );
		$was  = is_array( $prev ) && ! empty( $prev['is_chargeback'] );

		FS_Data::upsert_index( $signals, $is_chargeback );

		if ( is_array( $prev ) && $was !== $is_chargeback ) {
			if ( 'yes' === $order->get_meta( '_fs_chargeback' ) ) {
				$source = 'manual';
			} elseif ( 'refunded' === (string) $to || 'refunded' === (string) $from ) {
				$source = 'refund';
			} else {
				$source = 'status';
			}
			FS_Data::emit_chargeback( $order, $is_chargeback, $source );
		}

		// An order a PERSON cancels or refunds while it is on the blocklist or
		// linked to it is fraud prevented too (v1.30): the control surfaced
		// the link, staff acted on it, the money did not stay with a
		// fraudster. Decided at the end of the request, after any control
		// that cancelled this very order in the same request has written its
		// own row — the ledger counts an order once, and names who stopped it.
		if ( in_array( (string) $to, array( 'cancelled', 'refunded' ), true ) && self::actor_is_staff() ) {
			if ( empty( self::$staff_stops ) ) {
				add_action( 'shutdown', array( $this, 'flush_staff_stops' ), 6 );
			}
			self::$staff_stops[ (int) $order->get_id() ] = true;
		}
	}

	/* ----------------------------------------------------------------- *
	 *  Orders staff stop (v1.30): cancelled or refunded by a person while
	 *  on the blocklist or linked to it — counted as fraud prevented.
	 * ----------------------------------------------------------------- */

	/**
	 * Orders whose status a staff member moved to cancelled/refunded in this
	 * request, to be weighed at shutdown.
	 *
	 * @var array order id => true
	 */
	private static $staff_stops = array();

	/**
	 * Whether the current request is being driven by a person who runs the
	 * store — wp-admin, or the REST API under a shop manager's credentials.
	 * Cron, gateway webhooks, WP-CLI and a customer cancelling their own
	 * pending order from My Account are not staff.
	 *
	 * @return bool
	 */
	private static function actor_is_staff() {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * End of request: count the orders staff cancelled or refunded, when the
	 * order is (still) tied to the blocklist and nothing has counted it yet.
	 */
	public function flush_staff_stops() {
		$pending           = self::$staff_stops;
		self::$staff_stops = array();
		if ( empty( $pending ) ) {
			return;
		}
		$settings = FS_Core::instance()->settings();
		foreach ( array_keys( $pending ) as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order ) {
				$this->count_staff_stop( $order, $settings, true );
			}
		}
	}

	/**
	 * What ties an order to the blocklist right now, if anything: a direct
	 * match on its own credentials (the blocklist, verified account flag
	 * exempt), else — when the linked rule is on, or when a person is asking
	 * — a sufficient link to a blocked identity (the linked rule's own
	 * exemptions apply). Returns the ledger kind, the identities the stop is
	 * tied to, and a one-line reason.
	 *
	 * @param WC_Order $order        Order.
	 * @param array    $settings     Plugin settings.
	 * @param bool     $require_rule Whether a link counts only while the linked rule is enabled.
	 * @return array|null { kind:'blocklist'|'linked', linked_to:array, detail:string } or null.
	 */
	private function stop_verdict( $order, $settings, $require_rule = true ) {
		$signals = FS_Data::extract_signals( $order );
		if ( ! self::is_verified_customer( (int) $signals['user_id'] ) ) {
			$matches = FS_Blocklist::match( $signals );
			if ( ! empty( $matches ) ) {
				$evidence = FS_Blocklist::match_evidence( $matches, $signals );
				$labels   = array();
				foreach ( (array) $evidence['items'] as $item ) {
					$labels[] = (string) $item['label'];
				}
				return array(
					'kind'      => 'blocklist',
					'linked_to' => FS_Blocklist::evidence_identities( $evidence ),
					/* translators: %s: the blocked credentials, e.g. "Email x@example.com; Shipping address 1 Main St (exact address)" */
					'detail'    => sprintf( __( 'The customer is on the blocklist: %s', 'fraud-shield' ), implode( '; ', $labels ) ),
				);
			}
		}
		if ( ( ! $require_rule || ! empty( $settings['linked_cancel_enabled'] ) ) && ! self::is_signal_verified( $signals ) ) {
			$links = FS_Blocklist::linked( $signals, (array) ( $settings['linked_cancel_types'] ?? array() ) );
			if ( FS_Blocklist::linked_sufficient( $links ) ) {
				return array(
					'kind'      => 'linked',
					'linked_to' => FS_Blocklist::link_identities( $links ),
					'detail'    => FS_Blocklist::linked_summary( $links ),
				);
			}
		}
		return null;
	}

	/**
	 * Count a cancelled or refunded order as fraud prevented by staff, if it
	 * qualifies: not a recorded chargeback (that is a loss), not already on
	 * the ledger, and tied to the blocklist right now. Writes the ledger row
	 * at the order's grand total, naming whom it was tied to, and an order
	 * note saying so.
	 *
	 * @param WC_Order   $order        Order.
	 * @param array|null $settings     Plugin settings (null = load).
	 * @param bool       $require_rule Whether a link counts only while the linked rule is enabled (false when a person asks explicitly).
	 * @return array { ok:bool, message:string, kind:string }
	 */
	public function count_staff_stop( $order, $settings = null, $require_rule = true ) {
		$settings = is_array( $settings ) ? $settings : FS_Core::instance()->settings();
		$status   = (string) $order->get_status();
		if ( ! in_array( $status, array( 'cancelled', 'refunded' ), true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Only a cancelled or refunded order can be counted as fraud prevented.', 'fraud-shield' ),
				'kind'    => '',
			);
		}
		if ( FS_Data::is_chargeback( $order, $settings ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This order is recorded as charged back — a loss, not a stop.', 'fraud-shield' ),
				'kind'    => '',
			);
		}
		$existing = FS_Data::prevented_row_for_order( (int) $order->get_id() );
		if ( $existing ) {
			return array(
				'ok'      => false,
				'message' => __( 'This order is already on the Fraud-prevented ledger.', 'fraud-shield' ),
				'kind'    => (string) $existing['kind'],
			);
		}
		$verdict = $this->stop_verdict( $order, $settings, $require_rule );
		if ( ! $verdict ) {
			return array(
				'ok'      => false,
				'message' => __( 'Nothing ties this order to the blocklist right now, so it is not counted.', 'fraud-shield' ),
				'kind'    => '',
			);
		}
		self::prevented( $verdict['kind'], 'cancelled', $order, null, $verdict['linked_to'], 'staff' );
		if ( ! FS_Data::prevented_row_for_order( (int) $order->get_id() ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Nothing was recorded: the order has no total.', 'fraud-shield' ),
				'kind'    => $verdict['kind'],
			);
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: "cancelled" or "refunded", 2: formatted order total, 3: what tied the order to the blocklist (a sentence) */
				__( 'Fraud Shield: counted as fraud prevented — %1$s by staff, %2$s kept out of a fraudster\'s hands. %3$s.', 'fraud-shield' ),
				'refunded' === $status ? __( 'refunded', 'fraud-shield' ) : __( 'cancelled', 'fraud-shield' ),
				html_entity_decode( wp_strip_all_tags( wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ),
				$verdict['detail']
			)
		);
		return array(
			'ok'      => true,
			'message' => __( 'Counted as fraud prevented. The Dashboard and the Financial Protection page include it now.', 'fraud-shield' ),
			'kind'    => $verdict['kind'],
		);
	}

	/**
	 * Build normalized signals from posted checkout data.
	 *
	 * @param array $data Posted fields.
	 * @return array
	 */
	private function signals_from_checkout( $data ) {
		$g = function ( $key ) use ( $data ) {
			if ( isset( $data[ $key ] ) ) {
				return $data[ $key ];
			}
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		};

		$ship_to_different = ! empty( $g( 'ship_to_different_address' ) );

		$prefix = $ship_to_different ? 'shipping_' : 'billing_';
		$addr   = array(
			'address_1' => $g( $prefix . 'address_1' ),
			'address_2' => $g( $prefix . 'address_2' ),
			'city'      => $g( $prefix . 'city' ),
			'state'     => $g( $prefix . 'state' ),
			'postcode'  => $g( $prefix . 'postcode' ),
			'country'   => $g( $prefix . 'country' ),
		);
		if ( '' === trim( (string) $addr['address_1'] ) ) {
			$addr = array(
				'address_1' => $g( 'billing_address_1' ),
				'address_2' => $g( 'billing_address_2' ),
				'city'      => $g( 'billing_city' ),
				'state'     => $g( 'billing_state' ),
				'postcode'  => $g( 'billing_postcode' ),
				'country'   => $g( 'billing_country' ),
			);
		}

		$name = trim( $g( 'billing_first_name' ) . ' ' . $g( 'billing_last_name' ) );

		return array(
			'order_id'         => 0,
			'order_date'       => current_time( 'mysql' ),
			'user_id'          => get_current_user_id(),
			'customer_name'    => $name,
			'email_norm'       => FS_Normalizer::email( $g( 'billing_email' ) ),
			'email_domain'     => FS_Normalizer::email_domain( $g( 'billing_email' ) ),
			'phone_norm'       => FS_Normalizer::phone( $g( 'billing_phone' ) ),
			'ship_hash'        => FS_Normalizer::hash( FS_Normalizer::address( $addr ) ),
			'ship_loose_hash'  => FS_Normalizer::hash( FS_Normalizer::address_loose( $addr ) ),
			'ship_text'        => '',
			'ship_city'        => FS_Normalizer::city( $addr['city'] ),
			'ship_state'       => FS_Normalizer::region( $addr['state'] ),
			'ship_postcode'    => FS_Normalizer::postcode( $addr['postcode'] ),
			'ship_street'      => FS_Normalizer::street( $addr ),
			'ship_door_hash'   => FS_Normalizer::door( $addr ),
			'ship_door_alts'   => FS_Normalizer::door_alternates( $addr ),
			'ship_number'      => FS_Normalizer::house( $addr ),
			'ship_parts'       => $addr,
			'ship_method'      => $this->chosen_shipping_label(),
			'billing_country'  => $g( 'billing_country' ),
			'shipping_country' => $ship_to_different ? $g( 'shipping_country' ) : $g( 'billing_country' ),
			'ip'               => function_exists( 'WC' ) && WC()->customer ? WC_Geolocation::get_ip_address() : '',
		);
	}

	/**
	 * Best-effort label of the shipping method the customer has chosen at
	 * checkout, matching the label WC_Order::get_shipping_method() will store, so
	 * the expedited-shipping rule can also apply pre-payment. Returns '' when it
	 * can't be resolved (the rule then applies once the order is created).
	 *
	 * @return string
	 */
	private function chosen_shipping_label() {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->shipping() ) {
			return '';
		}
		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		if ( ! is_array( $chosen ) || empty( $chosen ) ) {
			return '';
		}
		$packages = WC()->shipping()->get_packages();
		foreach ( (array) $packages as $i => $package ) {
			$rate_id = isset( $chosen[ $i ] ) ? $chosen[ $i ] : '';
			if ( '' !== $rate_id && isset( $package['rates'][ $rate_id ] ) && is_object( $package['rates'][ $rate_id ] ) ) {
				return mb_substr( (string) $package['rates'][ $rate_id ]->get_label(), 0, 191 );
			}
		}
		return '';
	}

	/* ----------------------------------------------------------------- *
	 *  High-value order review (v1.32)
	 * ----------------------------------------------------------------- */

	/**
	 * The order total at or above which an order is listed for review, or 0
	 * when the review page is switched off.
	 *
	 * @return float
	 */
	public static function hv_review_amount() {
		return max( 0.0, (float) ( FS_Core::instance()->settings()['hv_review_amount'] ?? 0 ) );
	}

	/**
	 * Whether an order belongs in the review queue: the page is on and the
	 * order total reaches the threshold.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_high_value( $order ) {
		$amount = self::hv_review_amount();
		return $amount > 0 && $order instanceof WC_Order && (float) $order->get_total() >= $amount;
	}

	/**
	 * Record a reviewer's verdict on a high-value order — the one path the
	 * review page, the order screen's Fraud Shield box and
	 * `POST /orders/{id}/review` share, so every surface changes the order
	 * the same way, writes the same note and raises the same event.
	 *
	 *  cleared   — "looks good": the order is left exactly as it is.
	 *  held      — "something off": the order is set On-Hold so nothing
	 *              ships while it is looked into. An order already held,
	 *              already completed, or not live (cancelled, failed,
	 *              refunded…) keeps its status and is only noted.
	 *  cancelled — "cancel now": the order is cancelled in WooCommerce. The
	 *              payment is NOT refunded by this — that stays a decision on
	 *              the order screen. An order already cancelled or refunded
	 *              keeps its status and is only noted.
	 *
	 * Any order can be reviewed whatever its total: a reviewer who looked at
	 * it has something to record. The ledger is append-only, so a second
	 * verdict is kept beside the first and the order's current verdict is
	 * the latest one.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $verdict  cleared | held | cancelled.
	 * @param string   $note     Optional note (plain text, up to 1000 characters).
	 * @param string   $source   'admin' (review page) | 'order' (order screen) | 'api'.
	 * @param array    $reviewer { id:int, name:string }; defaults to the current user.
	 * @return array { ok:bool, message:string, verdict:string, status_before:string, status_after:string, changed:bool, review_id:int, reviewer:{id,name} }
	 */
	public static function review_high_value( $order, $verdict, $note = '', $source = 'admin', $reviewer = array() ) {
		$verdicts = FS_Data::hv_verdicts();
		if ( ! $order instanceof WC_Order ) {
			return array(
				'ok'      => false,
				'message' => __( 'Order not found.', 'fraud-shield' ),
			);
		}
		$verdict = sanitize_key( (string) $verdict );
		if ( ! isset( $verdicts[ $verdict ] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Unknown verdict. Use looks good, hold package, or cancel now.', 'fraud-shield' ),
			);
		}
		$source = in_array( $source, array( 'admin', 'order', 'api' ), true ) ? $source : 'admin';
		$note   = trim( wp_strip_all_tags( (string) $note ) );
		if ( mb_strlen( $note ) > 1000 ) {
			$note = mb_substr( $note, 0, 1000 );
		}

		$rid   = isset( $reviewer['id'] ) ? (int) $reviewer['id'] : (int) get_current_user_id();
		$rname = isset( $reviewer['name'] ) ? (string) $reviewer['name'] : '';
		if ( '' === $rname && $rid > 0 && function_exists( 'get_userdata' ) ) {
			$user  = get_userdata( $rid );
			$rname = ( $user && ! empty( $user->display_name ) ) ? (string) $user->display_name : '';
		}
		$who = FS_Data::hv_reviewer_name( $rid, $rname );

		/* translators: 1: reviewer's name, 2: the verdict ("Looks good", "Something off — hold package", "Cancel now") */
		$line = sprintf( __( 'Fraud Shield: high-value review by %1$s — %2$s.', 'fraud-shield' ), $who, $verdicts[ $verdict ]['label'] );
		if ( '' !== $note ) {
			/* translators: %s: the reviewer's note */
			$line .= ' ' . sprintf( __( 'Note: %s', 'fraud-shield' ), $note );
		}

		$before  = (string) $order->get_status();
		$changed = false;
		$target  = '';
		if ( 'held' === $verdict && ! in_array( $before, array_merge( FS_Data::hv_dead_statuses(), array( 'on-hold', 'completed' ) ), true ) ) {
			$target = 'on-hold';
		} elseif ( 'cancelled' === $verdict && ! in_array( $before, array( 'cancelled', 'refunded', 'charged-back', 'trash' ), true ) ) {
			$target = 'cancelled';
		}
		if ( '' !== $target ) {
			// The status note carries the verdict line, so the order has one
			// note for the decision, not two. The status change re-indexes
			// the order (on_status_changed), so the queue sees it at once;
			// a staff cancellation of an order tied to the blocklist is
			// counted as fraud prevented there too (v1.30).
			$order->update_status( $target, $line . ' ' );
			$changed = true;
		} else {
			$order->add_order_note( $line );
		}
		$after = (string) $order->get_status();

		$now = current_time( 'mysql' );
		$order->update_meta_data(
			'_fs_hv_review',
			wp_json_encode(
				array(
					'verdict' => $verdict,
					'by'      => $rid,
					'by_name' => $who,
					'at'      => $now,
					'note'    => $note,
					'source'  => $source,
				)
			)
		);
		$order->save();

		$review_id = FS_Data::hv_record_review( $order, $verdict, $note, $rid, $who, $source, $before, $after );

		if ( class_exists( 'FS_Webhooks' ) ) {
			FS_Webhooks::emit(
				'order.reviewed',
				array(
					'order_id'      => (int) $order->get_id(),
					'user_id'       => (int) $order->get_customer_id(),
					'email_norm'    => FS_Normalizer::email( (string) $order->get_billing_email() ),
					'name'          => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'total'         => (float) $order->get_total(),
					'currency'      => (string) $order->get_currency(),
					'verdict'       => $verdict,
					'note'          => $note,
					'status_before' => $before,
					'status_after'  => $after,
					'reviewer'      => array(
						'id'   => $rid,
						'name' => $who,
					),
					'source'        => $source,
					'review_id'     => $review_id,
					'reviewed_at'   => $now,
				)
			);
		}

		switch ( $verdict ) {
			case 'held':
				$message = $changed
					? __( 'Marked as something off — the order is now On-Hold, so the package does not ship until someone releases it.', 'fraud-shield' )
					: __( 'Marked as something off. The order\'s status was left as it is (it is already held, already completed, or not live).', 'fraud-shield' );
				break;
			case 'cancelled':
				$message = $changed
					? __( 'Order cancelled. The payment is not refunded by this — issue the refund from the order screen if one is due.', 'fraud-shield' )
					: __( 'Marked as cancel now. The order was already cancelled or refunded, so its status was left alone.', 'fraud-shield' );
				break;
			default:
				$message = __( 'Marked as looks good.', 'fraud-shield' );
		}

		return array(
			'ok'            => true,
			'message'       => $message,
			'verdict'       => $verdict,
			'status_before' => $before,
			'status_after'  => $after,
			'changed'       => $changed,
			'review_id'     => $review_id,
			'reviewer'      => array(
				'id'   => $rid,
				'name' => $who,
			),
		);
	}

	/**
	 * The latest high-value verdict stamped on an order, if any (v1.32).
	 *
	 * @param WC_Order $order Order.
	 * @return array|null { verdict, by:int, by_name, at, note, source } or null.
	 */
	public static function hv_review_record( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$raw = (string) $order->get_meta( '_fs_hv_review' );
		if ( '' === $raw ) {
			return null;
		}
		$rec = json_decode( $raw, true );
		if ( ! is_array( $rec ) || empty( $rec['verdict'] ) ) {
			return null;
		}
		return array(
			'verdict' => (string) $rec['verdict'],
			'by'      => (int) ( $rec['by'] ?? 0 ),
			'by_name' => (string) ( $rec['by_name'] ?? '' ),
			'at'      => (string) ( $rec['at'] ?? '' ),
			'note'    => (string) ( $rec['note'] ?? '' ),
			'source'  => (string) ( $rec['source'] ?? '' ),
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Order-screen meta box
	 * ----------------------------------------------------------------- */

	/**
	 * Register the meta box on both legacy and HPOS order screens.
	 */
	public function add_meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box( 'fs_order_risk', __( 'Fraud Shield', 'fraud-shield' ), array( $this, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'No order.', 'fraud-shield' ) . '</p>';
			return;
		}

		$settings = FS_Core::instance()->settings();
		$signals  = FS_Data::extract_signals( $order );
		$profile  = FS_Data::build_profile( $signals, $order->get_id() );
		$scored   = FS_Rules::score_profile( $profile, $settings );
		$matches  = FS_Blocklist::match( $signals );
		$is_cb    = FS_Data::is_chargeback( $order, $settings );

		$colors = array(
			'low'    => '#46b450',
			'medium' => '#dba617',
			'high'   => '#d63638',
			'block'  => '#8b0000',
		);
		$color  = $colors[ $scored['level'] ] ?? '#666';

		echo '<div class="fs-metabox" data-order="' . esc_attr( $order->get_id() ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'fs_order_action' ) ) . '">';

		echo '<p style="margin:.2em 0;"><span style="display:inline-block;padding:3px 10px;border-radius:3px;color:#fff;font-weight:600;background:' . esc_attr( $color ) . ';">' . esc_html( strtoupper( $scored['level'] ) ) . '</span> &nbsp;<strong>' . esc_html__( 'Score:', 'fraud-shield' ) . ' ' . (int) $scored['score'] . '</strong></p>';

		if ( $is_cb ) {
			echo '<p style="color:#d63638;font-weight:600;margin:.2em 0;">&#9888; ' . esc_html__( 'Marked as charged back', 'fraud-shield' ) . '</p>';
			// Money won back on this chargeback (a dispute won, a partial
			// reversal). Feeds the Financial Protection page's net-loss figure;
			// nothing is assumed when it is left blank.
			$recovered = (float) $order->get_meta( '_fs_cb_recovered' );
			echo '<p style="margin:.2em 0;font-size:12px;">';
			if ( $recovered > 0 ) {
				/* translators: %s: formatted amount */
				echo esc_html( sprintf( __( 'Recovered: %s', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( $recovered, array( 'currency' => $order->get_currency() ) ) ) ) ) );
				echo ' <button type="button" class="button-link fs-action" data-action="cb_recover_clear">' . esc_html__( 'clear', 'fraud-shield' ) . '</button>';
			} else {
				echo '<span class="fs-muted">' . esc_html__( 'No recovery recorded.', 'fraud-shield' ) . '</span>';
			}
			echo '</p>';
			echo '<p style="margin:.2em 0;display:flex;gap:4px;align-items:center;">';
			echo '<input type="number" class="fs-recover-amount small-text" min="0" step="0.01" placeholder="' . esc_attr( wc_format_decimal( $order->get_total(), 2 ) ) . '" style="width:90px;" />';
			echo '<button type="button" class="button button-small fs-action" data-action="cb_recover">' . esc_html__( 'Record recovery', 'fraud-shield' ) . '</button>';
			echo '</p>';
		}
		if ( ! empty( $matches ) ) {
			// Which of this customer's credentials are blocked, since when, by
			// whom, and under which block card (v1.27) — the provenance staff
			// need before they release or extend a block. Every line links to
			// the Blocklist page's search for that credential.
			$prov = FS_Blocklist::provenance( $matches );
			/* translators: %d: number of blocked credentials */
			echo '<p style="color:#8b0000;font-weight:600;margin:.2em 0;">&#9888; ' . esc_html( sprintf( _n( 'On the blocklist — %d credential', 'On the blocklist — %d credentials', count( $matches ), 'fraud-shield' ), count( $matches ) ) ) . '</p>';
			echo '<ul style="margin:.3em 0 .4em 1.1em;list-style:disc;font-size:12px;">';
			foreach ( $matches as $m ) {
				$p     = $prov[ (int) $m['id'] ] ?? array(
					'added_by_name' => '',
					'created_at'    => '',
					'cards'         => array(),
				);
				$desc  = FS_Blocklist::describe_match( $m, $signals, $p['cards'] );
				$when  = '' !== (string) $p['created_at'] ? mysql2date( 'Y-m-d H:i', (string) $p['created_at'] ) : __( 'unknown date', 'fraud-shield' );
				$who   = '' !== (string) $p['added_by_name'] ? (string) $p['added_by_name'] : __( 'unknown', 'fraud-shield' );
				$find  = add_query_arg(
					array(
						'page' => 'fraud-shield-blocklist',
						's'    => rawurlencode( $desc['search'] ),
					),
					admin_url( 'admin.php' )
				);
				echo '<li><strong>' . esc_html( $desc['label'] ) . '</strong><br/>';
				/* translators: 1: date and time, 2: the staff member (or "Fraud Shield (automatic)") */
				echo esc_html( sprintf( __( 'Blocked %1$s by %2$s', 'fraud-shield' ), $when, $who ) );
				if ( ! empty( $p['cards'] ) ) {
					$titles = array();
					foreach ( array_slice( $p['cards'], 0, 3 ) as $c ) {
						$t = '' !== trim( (string) $c['title'] ) ? (string) $c['title'] : (string) $c['target'];
						if ( (int) $c['created_by'] !== (int) ( $p['added_by'] ?? 0 ) && '' !== (string) $c['created_by_name'] ) {
							/* translators: 1: block card title, 2: who created the block */
							$t = sprintf( __( '%1$s (block by %2$s)', 'fraud-shield' ), $t, $c['created_by_name'] );
						}
						$titles[] = $t;
					}
					echo '<br/><span class="fs-muted">' . esc_html__( 'Under:', 'fraud-shield' ) . ' ' . esc_html( implode( '; ', $titles ) );
					if ( count( $p['cards'] ) > 3 ) {
						echo ' ' . esc_html( sprintf( /* translators: %d: number of further block cards */ _n( 'and %d more block', 'and %d more blocks', count( $p['cards'] ) - 3, 'fraud-shield' ), count( $p['cards'] ) - 3 ) );
					}
					echo '</span>';
				}
				if ( '' !== trim( (string) ( $m['reason'] ?? '' ) ) ) {
					/* translators: %s: the reason recorded when the credential was blocked */
					echo '<br/><span class="fs-muted">' . esc_html( sprintf( __( 'Reason: %s', 'fraud-shield' ), (string) $m['reason'] ) ) . '</span>';
				}
				echo ' <a href="' . esc_url( $find ) . '">' . esc_html__( 'Find on Blocklist', 'fraud-shield' ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		}

		$linked = self::linked_cancel_record( $order );
		if ( $linked ) {
			echo '<p style="color:#8b0000;font-weight:600;margin:.2em 0;">&#9888; ' . esc_html__( 'Cancelled automatically — linked to a blocked identity', 'fraud-shield' ) . '</p>';
			echo '<p style="margin:.2em 0;font-size:12px;">' . esc_html( (string) $linked['summary'] ) . '</p>';
			// The accounts and profiles the shared credentials lead to, with a
			// way into each, so the order screen answers "linked to whom?".
			$seen = array();
			echo '<ul style="margin:.3em 0 .4em 1.1em;list-style:disc;font-size:12px;">';
			foreach ( (array) $linked['links'] as $l ) {
				foreach ( (array) ( $l['identities'] ?? array() ) as $id ) {
					$email = (string) ( $id['email'] ?? '' );
					$uid   = (int) ( $id['user_id'] ?? 0 );
					$key   = '' !== $email ? $email : 'user:' . $uid;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					echo '<li>' . esc_html( FS_Blocklist::identity_label( $id ) );
					if ( ! empty( $id['blocked'] ) ) {
						echo ' <span style="color:#8b0000;font-weight:600;">' . esc_html__( 'blocked', 'fraud-shield' ) . '</span>';
					}
					if ( '' !== $email ) {
						echo ' · <a href="' . esc_url( add_query_arg( array( 'page' => 'fraud-shield-profiles', 'fs_profile' => rawurlencode( $email ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'profile', 'fraud-shield' ) . '</a>';
					}
					if ( $uid > 0 ) {
						echo ' · <a href="' . esc_url( add_query_arg( array( 'page' => 'fraud-shield-new-customers', 'fs_customer' => $uid ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'account', 'fraud-shield' ) . '</a>';
					}
					echo '</li>';
				}
			}
			echo '</ul>';
			echo '<p style="margin:.2em 0;font-size:12px;"><a href="' . esc_url( admin_url( 'admin.php?page=fraud-shield-cancels&s=' . (int) $order->get_id() ) ) . '">' . esc_html__( 'View on Auto-Cancelled', 'fraud-shield' ) . '</a></p>';
		}

		// The savings ledger's view of a stopped order (v1.30): what it is
		// counted as and whom it was tied to — or, for an order a person
		// cancelled or refunded that is tied to the blocklist but was never
		// counted, the one click that counts it.
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) && ! $is_cb ) {
			$pv = FS_Data::prevented_row_for_order( $order->get_id() );
			if ( $pv ) {
				$kinds = FS_Data::prevented_kinds();
				echo '<p style="color:#1a7f37;font-weight:600;margin:.4em 0 .2em;">&#10004; ' . esc_html( sprintf( /* translators: %s: formatted amount */ __( 'Counted as fraud prevented: %s', 'fraud-shield' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $pv['amount'], array( 'currency' => (string) $pv['currency'] ) ) ) ) ) ) . '</p>';
				echo '<p style="margin:.2em 0;font-size:12px;">' . esc_html( $kinds[ (string) $pv['kind'] ]['label'] ?? (string) $pv['kind'] );
				if ( 'staff' === (string) $pv['stopped_by'] ) {
					echo ' · ' . esc_html__( 'stopped by staff', 'fraud-shield' );
				}
				if ( ! empty( $pv['linked_to'] ) ) {
					$names = array();
					foreach ( array_slice( (array) $pv['linked_to'], 0, 3 ) as $id ) {
						$names[] = FS_Blocklist::identity_label( $id );
					}
					/* translators: %s: the blocked identities the stop was tied to */
					echo '<br/>' . esc_html( sprintf( __( 'Tied to: %s', 'fraud-shield' ), implode( '; ', array_filter( $names ) ) ) );
				}
				echo '</p>';
			} else {
				$verdict = $this->stop_verdict( $order, $settings, false );
				if ( $verdict ) {
					echo '<p style="color:#8b0000;font-weight:600;margin:.4em 0 .2em;">&#9888; ' . esc_html__( 'Not counted as fraud prevented yet', 'fraud-shield' ) . '</p>';
					/* translators: %s: what ties the order to the blocklist (a sentence) */
					echo '<p style="margin:.2em 0;font-size:12px;">' . esc_html( sprintf( __( '%s. Count it so the Dashboard and the Financial Protection page include its total.', 'fraud-shield' ), $verdict['detail'] ) ) . '</p>';
					echo '<p style="margin:.2em 0;">';
					$this->action_btn( 'count_prevented', __( 'Count as fraud prevented', 'fraud-shield' ), 'button-primary' );
					echo '</p>';
				}
			}
		}

		if ( ! empty( $scored['reasons'] ) ) {
			echo '<ul style="margin:.4em 0 .6em 1.1em;list-style:disc;">';
			foreach ( $scored['reasons'] as $r ) {
				echo '<li>' . esc_html( $r ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p style="color:#46b450;margin:.2em 0;">' . esc_html__( 'No risk signals detected.', 'fraud-shield' ) . '</p>';
		}

		// High-value review (v1.32): the order's standing in the review
		// queue and the same three verdicts the review page offers, so a
		// reviewer who opened the order can record the decision here.
		$hv_rec = self::hv_review_record( $order );
		if ( self::is_high_value( $order ) || $hv_rec ) {
			$verdicts = FS_Data::hv_verdicts();
			echo '<hr style="margin:.6em 0;">';
			echo '<p style="margin:.2em 0;"><strong>' . esc_html__( 'High-value review', 'fraud-shield' ) . '</strong></p>';
			if ( $hv_rec ) {
				$v = $verdicts[ $hv_rec['verdict'] ] ?? null;
				echo '<p style="margin:.2em 0;font-size:12px;">';
				echo '<span class="fs-pill ' . esc_attr( $v ? $v['pill'] : '' ) . '" style="display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:11px;font-weight:700;background:' . esc_attr( 'cleared' === $hv_rec['verdict'] ? '#46b450' : ( 'held' === $hv_rec['verdict'] ? '#dba617' : '#1d2327' ) ) . ';">' . esc_html( $v ? $v['short'] : $hv_rec['verdict'] ) . '</span> ';
				echo esc_html(
					sprintf(
						/* translators: 1: reviewer's name, 2: date and time */
						__( 'by %1$s, %2$s', 'fraud-shield' ),
						FS_Data::hv_reviewer_name( $hv_rec['by'], $hv_rec['by_name'] ),
						'' !== $hv_rec['at'] ? mysql2date( 'Y-m-d H:i', $hv_rec['at'] ) : '—'
					)
				);
				if ( '' !== $hv_rec['note'] ) {
					echo '<br/><span class="fs-muted" style="color:#646970;">' . esc_html( $hv_rec['note'] ) . '</span>';
				}
				echo '</p>';
			} else {
				echo '<p style="margin:.2em 0;font-size:12px;color:#8a6d09;font-weight:600;">' . esc_html__( 'Awaiting review — nobody has checked this order yet.', 'fraud-shield' ) . '</p>';
			}
			echo '<p style="margin:.3em 0;"><input type="text" class="fs-hv-note widefat" maxlength="1000" placeholder="' . esc_attr__( 'Optional note (what you checked, what you found)', 'fraud-shield' ) . '" /></p>';
			echo '<div class="fs-actions" style="display:flex;flex-wrap:wrap;gap:5px;">';
			$this->action_btn( 'hv_cleared', $verdicts['cleared']['label'], 'button-primary' );
			$this->action_btn( 'hv_held', $verdicts['held']['label'] );
			$this->action_btn( 'hv_cancelled', $verdicts['cancelled']['label'], 'button-link-delete' );
			echo '</div>';
			echo '<p style="margin:.3em 0 0;font-size:12px;"><a href="' . esc_url( admin_url( 'admin.php?page=fraud-shield-high-value' ) ) . '">' . esc_html__( 'Open the High-Value Review queue', 'fraud-shield' ) . '</a></p>';
		}

		echo '<hr style="margin:.6em 0;">';
		echo '<p style="margin:.2em 0;"><strong>' . esc_html__( 'Quick actions', 'fraud-shield' ) . '</strong></p>';
		echo '<div class="fs-actions" style="display:flex;flex-wrap:wrap;gap:5px;">';
		if ( $is_cb ) {
			$this->action_btn( 'clear_chargeback', __( 'Clear chargeback flag', 'fraud-shield' ) );
		} else {
			$this->action_btn( 'mark_chargeback', __( 'Mark as chargeback', 'fraud-shield' ), 'button-link-delete' );
		}
		$this->action_btn( 'blocklist_email', __( 'Block email', 'fraud-shield' ) );
		$this->action_btn( 'blocklist_address', __( 'Block address', 'fraud-shield' ) );
		$this->action_btn( 'blocklist_phone', __( 'Block phone', 'fraud-shield' ) );
		if ( $signals['ip'] ) {
			$this->action_btn( 'blocklist_ip', __( 'Block IP', 'fraud-shield' ) );
		}
		echo '</div>';
		echo '<p class="fs-action-result" style="margin:.5em 0 0;color:#2271b1;"></p>';
		echo '</div>';
	}

	/**
	 * The linked-identity cancellation stamped on an order, if any (v1.26).
	 *
	 * @param WC_Order $order Order.
	 * @return array|null { at:string, summary:string, links:array } or null.
	 */
	public static function linked_cancel_record( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$raw = (string) $order->get_meta( '_fs_linked_cancel' );
		if ( '' === $raw ) {
			return null;
		}
		$rec = json_decode( $raw, true );
		if ( ! is_array( $rec ) ) {
			return null;
		}
		return array(
			'at'      => (string) ( $rec['at'] ?? '' ),
			'summary' => (string) ( $rec['summary'] ?? '' ),
			'links'   => isset( $rec['links'] ) && is_array( $rec['links'] ) ? $rec['links'] : array(),
		);
	}

	/**
	 * Render one action button.
	 *
	 * @param string $action Action key.
	 * @param string $label  Label.
	 * @param string $class  Extra classes.
	 */
	private function action_btn( $action, $label, $class = '' ) {
		echo '<button type="button" class="button button-small fs-action ' . esc_attr( $class ) . '" data-action="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * AJAX handler for meta-box actions.
	 */
	public function ajax_order_action() {
		check_ajax_referer( 'fs_order_action', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fraud-shield' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$action   = isset( $_POST['fs_action'] ) ? sanitize_key( wp_unslash( $_POST['fs_action'] ) ) : '';
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'fraud-shield' ) ), 404 );
		}

		$settings = FS_Core::instance()->settings();
		$signals  = FS_Data::extract_signals( $order );
		$message  = '';

		switch ( $action ) {
			// The chargeback mark and the recovery go through the same functions
			// the API uses (v1.30), so the note, the re-index and the
			// order.chargeback / order.recovery events are identical whichever
			// screen or system did it.
			case 'mark_chargeback':
				FS_Data::set_chargeback( $order, true, 'manual' );
				$message = __( 'Marked as chargeback. Run "Recompute scores" to propagate to linked orders.', 'fraud-shield' );
				break;

			case 'clear_chargeback':
				FS_Data::set_chargeback( $order, false, 'manual' );
				$message = __( 'Chargeback flag cleared.', 'fraud-shield' );
				break;

			case 'cb_recover':
				// Money won back on a charged-back order. Capped at the order
				// total: a recovery cannot exceed what was lost.
				$amount = isset( $_POST['amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['amount'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$amount = min( max( 0.0, $amount ), (float) $order->get_total() );
				if ( $amount <= 0 ) {
					wp_send_json_error( array( 'message' => __( 'Enter the amount recovered (more than zero).', 'fraud-shield' ) ), 400 );
				}
				FS_Data::set_recovery( $order, $amount, 'manual' );
				$message = __( 'Recovery recorded. The Financial Protection page nets it against this chargeback.', 'fraud-shield' );
				break;

			case 'cb_recover_clear':
				FS_Data::set_recovery( $order, null, 'manual' );
				$message = __( 'Recovery cleared.', 'fraud-shield' );
				break;

			case 'count_prevented':
				// A person is asking: a sufficient link counts whether or not
				// the linked rule is switched on.
				$result = $this->count_staff_stop( $order, $settings, false );
				if ( empty( $result['ok'] ) ) {
					wp_send_json_error( array( 'message' => $result['message'] ), 400 );
				}
				$message = $result['message'];
				break;

			// High-value review verdicts (v1.32) — the same path as the
			// review page and the API, so the note, the status change and the
			// order.reviewed event are identical whichever screen did it.
			case 'hv_cleared':
			case 'hv_held':
			case 'hv_cancelled':
				$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
				$result = self::review_high_value( $order, substr( $action, 3 ), $note, 'order' );
				if ( empty( $result['ok'] ) ) {
					wp_send_json_error( array( 'message' => $result['message'] ), 400 );
				}
				$message = $result['message'];
				break;

			case 'blocklist_email':
				/* translators: %d: order number */
				FS_Blocklist::block_manual( 'email', $order->get_billing_email(), sprintf( __( 'Order #%d', 'fraud-shield' ), $order->get_id() ) );
				$message = __( 'Email added to blocklist. Remove it from the Blocklist page to unblock.', 'fraud-shield' );
				break;

			case 'blocklist_phone':
				/* translators: %d: order number */
				FS_Blocklist::block_manual( 'phone', $order->get_billing_phone(), sprintf( __( 'Order #%d', 'fraud-shield' ), $order->get_id() ) );
				$message = __( 'Phone added to blocklist. Remove it from the Blocklist page to unblock.', 'fraud-shield' );
				break;

			case 'blocklist_ip':
				/* translators: %d: order number */
				$result = FS_Blocklist::block_cluster( 'ip', $signals['ip'], sprintf( __( 'Order #%d', 'fraud-shield' ), $order->get_id() ) );
				/* translators: %d: number of blocklist entries created */
				$message = sprintf( __( 'IP blocked (%d credential(s) added to the blocklist).', 'fraud-shield' ), (int) $result['added'] );
				break;

			case 'blocklist_address':
				/* translators: 1: order number, 2: shipping address text */
				$result = FS_Blocklist::block_cluster( 'address', $signals['ship_hash'], sprintf( __( 'Order #%1$d — %2$s', 'fraud-shield' ), $order->get_id(), $signals['ship_text'] ) );
				/* translators: %d: number of blocklist entries created */
				$message = sprintf( __( 'Shipping address blocked (%d credential(s) added to the blocklist).', 'fraud-shield' ), (int) $result['added'] );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown action.', 'fraud-shield' ) ), 400 );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Enqueue the small JS used by the meta box on order screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_order_assets( $hook ) {
		$is_order_screen = false;
		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ( 'shop_order' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id ) ) {
			$is_order_screen = true;
		}
		if ( ! $is_order_screen ) {
			return;
		}
		wp_enqueue_script( 'fs-order', FS_PLUGIN_URL . 'assets/order.js', array( 'jquery' ), FS_VERSION, true );
		wp_localize_script( 'fs-order', 'FS_ORDER', array( 'ajax' => admin_url( 'admin-ajax.php' ) ) );
	}
}
