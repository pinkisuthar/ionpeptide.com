<?php
/**
 * ID verification via Didit (v1.14): step-up identity verification.
 *
 * The unlock model: a customer whose payment methods are currently restricted
 * (the new-customer gateway rule or the velocity "restrict" mode) is offered a
 * one-time government-ID check at checkout. When Didit approves the document —
 * and the name on it matches the customer's billing name (configurable) — the
 * customer becomes ID-verified: they bypass gateway restrictions, the velocity
 * lock, and the new-account hold, exactly like a merchant-verified credential.
 * An explicit blocklist entry still wins — ID verification NEVER bypasses it.
 *
 * Privacy: the document images never touch this site. The customer uploads
 * directly to Didit's hosted flow; we store only the session id, the decision,
 * the extracted name (for the match + disputes), and timestamps.
 *
 * Requires a Didit Business Console app: API key + workflow id (and, strongly
 * recommended, a webhook pointed at /wp-json/fraud-shield/v1/didit with its
 * secret pasted into settings). Without the webhook the plugin still works —
 * checkout polling falls back to querying the Didit decision API directly.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_IDV {

	/**
	 * Didit verification API base (v3).
	 */
	const API_BASE = 'https://verification.didit.me';

	/**
	 * Didit session statuses that mean "still going".
	 *
	 * @var array
	 */
	private static $pending_statuses = array( 'Not Started', 'In Progress', 'Awaiting User', 'In Review', 'Resubmitted' );

	/**
	 * Wire hooks.
	 */
	public function __construct() {
		// Webhook receiver (signature-authenticated, see rest_webhook()).
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Checkout UI (classic checkout hook; the blocks checkout gets the same
		// box injected by assets/idv.js using the server-rendered markup).
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'checkout_notice' ), 6 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Front-end AJAX: start a session / poll its status.
		add_action( 'wp_ajax_fs_idv_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_nopriv_fs_idv_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_fs_idv_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_fs_idv_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_fs_idv_state', array( $this, 'ajax_state' ) );
		add_action( 'wp_ajax_nopriv_fs_idv_state', array( $this, 'ajax_state' ) );

		// Tiny "you can close this window" page Didit redirects back to.
		add_action( 'template_redirect', array( $this, 'maybe_render_return_page' ), 1 );

		// Admin: order-screen meta box + profile section + actions.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_fs_idv_admin', array( $this, 'handle_admin_action' ) );
		add_action( 'show_user_profile', array( $this, 'render_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile' ) );

		// Stamp orders placed by an ID-verified customer (dispute evidence).
		add_action( 'woocommerce_new_order', array( $this, 'stamp_order' ), 30, 2 );

		// Optional placement anywhere (e.g. My Account): [fraud_shield_verify].
		add_shortcode( 'fraud_shield_verify', array( $this, 'shortcode' ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Settings helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Whether the feature is enabled AND has the credentials it needs.
	 *
	 * @return bool
	 */
	public static function configured() {
		$s = FS_Core::instance()->settings();
		return ! empty( $s['idv_enabled'] ) && '' !== trim( (string) $s['idv_api_key'] ) && '' !== trim( (string) $s['idv_workflow_id'] );
	}

	/**
	 * The REST webhook URL to paste into the Didit console.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'fraud-shield/v1/didit' );
	}

	/* ----------------------------------------------------------------- *
	 *  Verified state (the thing the rest of Fraud Shield asks about)
	 * ----------------------------------------------------------------- */

	/**
	 * The stored verification record for an account, or null.
	 *
	 * @param int $user_id User id.
	 * @return array|null { session_id, full_name, name_match, at, expires }
	 */
	public static function record_for_user( $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return null;
		}
		$rec = get_user_meta( (int) $user_id, '_fs_idv', true );
		return ( is_array( $rec ) && ! empty( $rec['session_id'] ) ) ? $rec : null;
	}

	/**
	 * Whether a record is current (not expired).
	 *
	 * @param array|null $rec Record.
	 * @return bool
	 */
	private static function record_valid( $rec ) {
		if ( empty( $rec ) || ! is_array( $rec ) ) {
			return false;
		}
		$expires = isset( $rec['expires'] ) ? (int) $rec['expires'] : 0;
		return 0 === $expires || $expires > time();
	}

	/**
	 * Is this account ID-verified (and unexpired)?
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_user_verified( $user_id ) {
		return self::record_valid( self::record_for_user( $user_id ) );
	}

	/**
	 * The verification record for a customer, from the account first and the
	 * email map second — the one lookup admin screens need.
	 *
	 * @param int    $user_id    User id (0 = none).
	 * @param string $email_norm Normalized email.
	 * @return array|null
	 */
	public static function record_for( $user_id, $email_norm = '' ) {
		$rec = self::record_for_user( $user_id );
		if ( $rec ) {
			return $rec;
		}
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' === $email_norm ) {
			return null;
		}
		$map = get_option( 'fs_idv_verified', array() );
		return ( is_array( $map ) && ! empty( $map[ $email_norm ]['session_id'] ) ) ? $map[ $email_norm ] : null;
	}

	/**
	 * Is this (normalized) email ID-verified (and unexpired)?
	 *
	 * Guest-friendly complement to the account record, stored in an option map
	 * like the email allowlist.
	 *
	 * @param string $email_norm Normalized email.
	 * @return bool
	 */
	public static function is_email_verified( $email_norm ) {
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' === $email_norm ) {
			return false;
		}
		$map = get_option( 'fs_idv_verified', array() );
		return is_array( $map ) && isset( $map[ $email_norm ] ) && self::record_valid( $map[ $email_norm ] );
	}

	/**
	 * Account OR email verified — the one-call check the exemption points use.
	 *
	 * @param int    $user_id    User id.
	 * @param string $email_norm Normalized email.
	 * @return bool
	 */
	public static function is_verified( $user_id, $email_norm = '' ) {
		return self::is_user_verified( $user_id ) || self::is_email_verified( $email_norm );
	}

	/**
	 * Does this customer hold a verification completed within the last N hours?
	 *
	 * This is what the high-value re-verification rules ask: not "have they
	 * ever proved who they are" but "have they proved it recently enough for an
	 * order of this size". A check completed a month ago is real, current, and
	 * still not fresh; the one completed during this checkout is.
	 *
	 * Records written before v1.18 carry no timestamp in epoch form, so the
	 * site-local 'at' datetime is the fallback; a record with neither reads as
	 * not fresh, which fails toward asking again rather than waving through.
	 *
	 * @param int    $user_id    User id.
	 * @param string $email_norm Normalized email.
	 * @param int    $hours      Freshness window in hours (minimum 1).
	 * @return bool
	 */
	public static function has_fresh_verification( $user_id, $email_norm, $hours ) {
		$rec = self::record_for( (int) $user_id, $email_norm );
		if ( ! self::record_valid( $rec ) ) {
			return false;
		}

		$at = isset( $rec['at_ts'] ) ? (int) $rec['at_ts'] : 0;
		if ( $at <= 0 && ! empty( $rec['at'] ) ) {
			// 'at' was written with current_time('mysql') (site-local), so the
			// comparison clock must be the same one.
			$parsed = strtotime( (string) $rec['at'] );
			if ( $parsed ) {
				return ( current_time( 'timestamp' ) - $parsed ) <= max( 1, (int) $hours ) * HOUR_IN_SECONDS;
			}
			return false;
		}

		return $at > 0 && ( time() - $at ) <= max( 1, (int) $hours ) * HOUR_IN_SECONDS;
	}

	/**
	 * The one question checkout UI and the AJAX endpoints ask: what, if
	 * anything, does this customer still owe before this order goes through
	 * normally?
	 *
	 * Answers, strongest first:
	 *  - { type:'staff' }                      a staff ID demand is open.
	 *  - { type:'hv', mode, tier, threshold, amount }  the high-value rule fired
	 *    for the order being placed right now.
	 *  - null                                  nothing outstanding.
	 *
	 * @param int    $user_id    User id.
	 * @param string $email_norm Normalized email.
	 * @return array|null
	 */
	public static function outstanding_requirement( $user_id, $email_norm = '' ) {
		if ( self::requires_verification( (int) $user_id, $email_norm ) ) {
			return array( 'type' => 'staff' );
		}
		$rule = FS_Live::high_value_rule_for_cart( (int) $user_id, $email_norm );
		if ( null !== $rule ) {
			return array_merge( array( 'type' => 'hv' ), $rule );
		}
		return null;
	}

	/**
	 * Persist an approval: account meta when we have a user, plus the email map
	 * so the verification survives logged-out sessions and covers guests.
	 *
	 * @param int    $user_id    User id (0 = guest).
	 * @param string $email_norm Normalized email.
	 * @param string $session_id Didit session id.
	 * @param string $full_name  Extracted document name.
	 * @param string $name_match 'yes' | 'no' | 'n/a' | 'manual'.
	 * @param string $method     How this verification came to be: 'didit' for a
	 *                           completed hosted check, 'staff' when a human
	 *                           accepted a held mismatch. Recorded so every
	 *                           screen can say not just THAT a customer is
	 *                           verified but HOW.
	 * @param int    $verified_at_ts Epoch of when the verification actually
	 *                           happened (0 = now). Restores and imports pass
	 *                           the original date, so expiry keeps counting
	 *                           from the day the customer really verified and
	 *                           a restored month-old check does not suddenly
	 *                           read as "fresh" to the high-value rules.
	 */
	public static function mark_verified( $user_id, $email_norm, $session_id, $full_name = '', $name_match = 'yes', $method = 'didit', $verified_at_ts = 0 ) {
		$days = (int) ( FS_Core::instance()->settings()['idv_valid_days'] ?? 365 );
		$ts   = (int) $verified_at_ts > 0 ? (int) $verified_at_ts : time();
		$rec  = array(
			'session_id' => (string) $session_id,
			'full_name'  => mb_substr( (string) $full_name, 0, 191 ),
			'name_match' => (string) $name_match,
			'method'     => in_array( (string) $method, array( 'didit', 'staff' ), true ) ? (string) $method : 'didit',
			'at'         => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y-m-d H:i:s' ),
			'at_ts'      => $ts,
			'expires'    => $days > 0 ? $ts + ( $days * DAY_IN_SECONDS ) : 0,
		);

		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, '_fs_idv', $rec );
		}

		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' !== $email_norm ) {
			$map = get_option( 'fs_idv_verified', array() );
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			// Prune expired entries while we're writing anyway.
			foreach ( $map as $k => $v ) {
				if ( ! self::record_valid( $v ) ) {
					unset( $map[ $k ] );
				}
			}
			$map[ $email_norm ] = $rec;
			update_option( 'fs_idv_verified', $map, false );
		}

		self::sync_verified_credential( $user_id, $email_norm, $full_name );

		// Proving who you are ends restricted checkout early: drop the per-account
		// gateway limit outright so the customer regains every payment method AND
		// disappears from the Restricted checkout list, rather than lingering there
		// as a row that no longer does anything.
		if ( $user_id > 0 && ! empty( FS_Core::instance()->settings()['gw_restrict_release_on_idv'] ) ) {
			FS_Live::release_gw_restrict( $user_id );
		}

		// A staff "block checkout & request ID" demand is satisfied the moment
		// the customer clears it — that is the whole point of the mode.
		self::clear_requirement( $user_id );
	}

	/**
	 * Promote a completed verification into a Fraud Shield **verified
	 * credential**, so an ID-verified customer shows up on the Verified
	 * Customers page and reads as green everywhere staff look, instead of
	 * being invisible in a parallel list.
	 *
	 * Deliberately NOT the `_fs_verified` account flag: that flag is the only
	 * exemption in the plugin that overrides the blocklist, and an approved
	 * document must never let an explicitly blocked customer back in. A
	 * credential clears the velocity lock, the new-account hold, and gateway
	 * restrictions — which is exactly the unlock ID verification promises.
	 *
	 * @param int    $user_id    Account id (0 = none).
	 * @param string $email_norm Normalized email.
	 * @param string $full_name  Name from the document (stored as the label).
	 */
	private static function sync_verified_credential( $user_id, $email_norm, $full_name = '' ) {
		if ( '' === $email_norm ) {
			return;
		}
		$label = trim( (string) $full_name );
		if ( '' === $label ) {
			$user  = (int) $user_id > 0 ? get_userdata( (int) $user_id ) : null;
			$label = $user ? (string) $user->display_name : '';
		}

		FS_Live::verified_add( 'email', $email_norm, $label, 'idv' );

		// Release the velocity ledger rows the customer just earned their way out
		// of, so the Checkout Locks queue doesn't keep showing them as blocked.
		// Rows from an explicit blocklist match are left alone on purpose —
		// passing an ID check is not a way around a block staff decided on.
		FS_Data::resolve_checkout_blocks( (int) $user_id, $email_norm, array( 'velocity' ) );
	}

	/**
	 * Revoke a verification (admin action, or a re-check policy).
	 *
	 * @param int    $user_id    User id.
	 * @param string $email_norm Normalized email.
	 */
	public static function revoke( $user_id, $email_norm = '' ) {
		if ( (int) $user_id > 0 ) {
			delete_user_meta( (int) $user_id, '_fs_idv' );
		}
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' !== $email_norm ) {
			$map = get_option( 'fs_idv_verified', array() );
			if ( is_array( $map ) && isset( $map[ $email_norm ] ) ) {
				unset( $map[ $email_norm ] );
				update_option( 'fs_idv_verified', $map, false );
			}
			// Withdraw the verified credential ONLY if the ID check is what
			// earned it. A credential a human added stands on its own and must
			// survive a revoked document.
			if ( 'idv' === FS_Live::verified_src( 'email', $email_norm ) ) {
				FS_Live::verified_remove( 'email', $email_norm );
			}
		}
	}

	/* ----------------------------------------------------------------- *
	 *  Staff-demanded verification ("block checkout & request ID")
	 * ----------------------------------------------------------------- */

	/**
	 * The open verification demand for an account, or null.
	 *
	 * This is intentionally its own flag rather than a blocklist entry: a
	 * blocklist entry is a verdict staff expect to hold until they lift it,
	 * whereas this one is a question the customer is meant to answer. Keeping
	 * them separate is what lets ID verification release this block without
	 * ever weakening the blocklist.
	 *
	 * @param int $user_id Account id.
	 * @return array|null { at, by }
	 */
	public static function requirement_record( $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return null;
		}
		$rec = get_user_meta( (int) $user_id, '_fs_idv_required', true );
		return is_array( $rec ) ? $rec : null;
	}

	/**
	 * Is this customer blocked from checkout pending an ID verification?
	 *
	 * Fails OPEN when the feature is switched off or loses its credentials: a
	 * block whose only exit is a verification the store can no longer perform
	 * would strand the customer permanently. Already-verified customers are
	 * never held either, so a stale flag cannot lock out someone who has since
	 * verified, and an account the merchant has marked **Verified** overrides
	 * this like every other layer.
	 *
	 * Pass the checkout email where you have one: the demand is stored against
	 * an account, so without it, signing out and ordering as a guest on the same
	 * address would walk straight past it.
	 *
	 * @param int    $user_id    Account id (0 = guest session).
	 * @param string $email_norm Normalized checkout email, when known.
	 * @return bool
	 */
	public static function requires_verification( $user_id, $email_norm = '' ) {
		if ( ! self::configured() ) {
			return false;
		}

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			$user_id = self::requirement_owner( $email_norm );
		}
		if ( $user_id <= 0 || null === self::requirement_record( $user_id ) ) {
			return false;
		}
		// The account Verified flag exempts from every layer, this one included.
		if ( FS_Live::is_verified_customer( $user_id ) ) {
			return false;
		}
		return ! self::is_user_verified( $user_id );
	}

	/**
	 * The account an outstanding demand belongs to, looked up by email.
	 *
	 * Backed by an option map rather than `get_user_by()` because the emails we
	 * compare are normalized (gmail dots and +tags removed), so they will not
	 * always match the address the account was registered with.
	 *
	 * @param string $email_norm Normalized email.
	 * @return int Account id, or 0.
	 */
	public static function requirement_owner( $email_norm ) {
		$email_norm = FS_Normalizer::email( $email_norm );
		if ( '' === $email_norm ) {
			return 0;
		}
		$map = get_option( 'fs_idv_required', array() );
		return ( is_array( $map ) && isset( $map[ $email_norm ] ) ) ? (int) $map[ $email_norm ] : 0;
	}

	/**
	 * Demand an ID verification before this account may check out again.
	 *
	 * @param int $user_id Account id.
	 * @return bool True when the demand was recorded.
	 */
	public static function require_verification( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		update_user_meta(
			$user_id,
			'_fs_idv_required',
			array(
				'at' => current_time( 'mysql' ),
				'by' => get_current_user_id(),
			)
		);

		// Index the account email so a logged-out checkout still resolves to it.
		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_email ) ) {
			$map = get_option( 'fs_idv_required', array() );
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			$map[ FS_Normalizer::email( $user->user_email ) ] = $user_id;
			update_option( 'fs_idv_required', $map, false );
		}

		return true;
	}

	/**
	 * Drop the demand — because the customer satisfied it, or staff withdrew it.
	 *
	 * Also closes the ledger rows the demand opened, so Checkout Locks stops
	 * listing someone who is no longer blocked.
	 *
	 * @param int $user_id Account id.
	 */
	public static function clear_requirement( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		delete_user_meta( $user_id, '_fs_idv_required' );

		$user  = get_userdata( $user_id );
		$email = $user ? FS_Normalizer::email( $user->user_email ) : '';

		// Drop the email index entry (matching on the id too, so a reassigned
		// address can't strip another account's demand).
		$map = get_option( 'fs_idv_required', array() );
		if ( is_array( $map ) ) {
			$changed = false;
			foreach ( $map as $k => $v ) {
				if ( (int) $v === $user_id ) {
					unset( $map[ $k ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( 'fs_idv_required', $map, false );
			}
		}

		FS_Data::resolve_checkout_blocks( $user_id, $email, array( 'idv' ) );
	}

	/**
	 * Accounts currently awaiting a staff-demanded verification.
	 *
	 * @param int $limit Max accounts.
	 * @return array WP_User[]
	 */
	public static function pending_requirements( $limit = 200 ) {
		$users = get_users(
			array(
				'meta_key'     => '_fs_idv_required', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'number'       => (int) $limit,
				'orderby'      => 'ID',
				'order'        => 'DESC',
			)
		);

		// Show exactly who is actually blocked: a customer who has since verified,
		// or whom staff have marked Verified, is no longer waiting on anything.
		return array_values(
			array_filter(
				$users,
				function ( $u ) {
					return self::requires_verification( (int) $u->ID );
				}
			)
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Offer logic
	 * ----------------------------------------------------------------- */

	/**
	 * Whether the velocity "restrict" mode currently limits this account's
	 * gateways (mirrors branch 2 of FS_Live::restrict_gateways()).
	 *
	 * @param int $uid User id.
	 * @return bool
	 */
	public static function velocity_restrict_applies( $uid, $email_norm = '' ) {
		$uid      = (int) $uid;
		$settings = FS_Core::instance()->settings();
		if ( empty( $settings['velocity_lock'] )
			|| 'restrict' !== ( $settings['velocity_lock_action'] ?? 'block' )
			|| empty( $settings['velocity_restrict_gateways'] ) ) {
			return false;
		}

		// Accounts are their account email; a guest is the email on the
		// checkout (passed by the caller, or held on the WooCommerce session).
		// Guests used to be refused here outright — but restrict_gateways()
		// velocity-locks a guest by that same email, and a guest CAN clear the
		// lock by verifying (approvals are recorded against the email). Denying
		// them the offer left restricted payment methods with no exit.
		if ( $uid > 0 ) {
			$user  = get_userdata( $uid );
			$email = $user ? FS_Normalizer::email( $user->user_email ) : '';
		} else {
			$email = FS_Normalizer::email( $email_norm );
			if ( '' === $email && function_exists( 'WC' ) && WC()->customer ) {
				$email = FS_Normalizer::email( WC()->customer->get_billing_email() );
			}
			if ( '' === $email ) {
				return false; // No identity at all — nothing to be locked BY.
			}
		}
		return FS_Live::is_velocity_locked( $uid, $email );
	}

	/**
	 * Should the current visitor be offered ID verification at checkout?
	 *
	 * Offered exactly to customers whose payment methods are being limited by a
	 * rule ID verification can lift: the new-customer gateway restriction or
	 * the velocity restrict mode. A per-account restriction set by staff is an
	 * explicit decision and is NOT overridden (matching restrict_gateways()),
	 * so we don't dangle an unlock that wouldn't happen.
	 *
	 * @return bool
	 */
	public static function should_offer() {
		$uid        = get_current_user_id();
		$email_norm = '';
		if ( $uid > 0 ) {
			$user       = get_userdata( $uid );
			$email_norm = $user ? FS_Normalizer::email( $user->user_email ) : '';
		} elseif ( function_exists( 'WC' ) && WC()->customer ) {
			$email_norm = FS_Normalizer::email( WC()->customer->get_billing_email() );
		}
		return self::should_offer_for( $uid, $email_norm );
	}

	/**
	 * The identity-aware core of should_offer(). Split out so the AJAX state
	 * endpoint and the start gate can answer for the identity the customer just
	 * typed into the form, not only the one the session already knew — the
	 * page-load answer and the mid-checkout answer are different questions on
	 * a guest checkout.
	 *
	 * @param int    $uid        User id (0 = guest).
	 * @param string $email_norm Normalized identity email ('' = unknown).
	 * @return bool
	 */
	public static function should_offer_for( $uid, $email_norm = '' ) {
		// NB: is_admin() is TRUE for admin-ajax requests (the checkout button
		// posts there), so it must not veto the AJAX flow — only real wp-admin.
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! self::configured() ) {
			return false;
		}
		$uid        = (int) $uid;
		$email_norm = FS_Normalizer::email( $email_norm );

		// A staff-demanded verification outranks every rule below: the customer
		// is blocked from checkout until they clear it, so the offer must always
		// be available to them — otherwise the block has no exit.
		if ( self::requires_verification( $uid, $email_norm ) ) {
			return apply_filters( 'fs_idv_should_offer', true, $uid );
		}

		// A high-value rule outranks the exemptions below for the same reason:
		// it fires precisely for customers the other rules trust, so their
		// exemptions must not hide the one control that can satisfy it. This is
		// also what lets an already-ID-verified customer start a NEW check when
		// an order of this size asks for a recent one. Account holders only:
		// verification is bound to the account identity, so a guest is invited
		// to sign in instead (render_box) rather than handed a button that
		// would bind a check to a typed email.
		if ( $uid > 0 && null !== FS_Live::high_value_rule_for_cart( $uid ) ) {
			return apply_filters( 'fs_idv_should_offer', true, $uid );
		}

		$per = FS_Live::per_user_gateway_restriction( $uid );
		if ( ! empty( $per['gateways'] ) ) {
			// When an approved ID check ends restricted checkout (the setting's
			// default), the offer is the customer's documented way OUT of the
			// list — hiding it stranded exactly the people staff most wanted
			// verified. Only when the release-on-IDV setting is off is the
			// restriction a decision verification cannot lift, and only then
			// is dangling the button a false promise.
			$offer_restricted = ! empty( FS_Core::instance()->settings()['gw_restrict_release_on_idv'] );
			return apply_filters( 'fs_idv_should_offer', $offer_restricted, $uid );
		}
		if ( $uid > 0 && FS_Live::is_account_gateway_exempt( $uid ) ) {
			return apply_filters( 'fs_idv_should_offer', false, $uid );
		}

		// Only dangle the unlock when something is actually restricted: the
		// new-customer rule fails open with an empty allow-list, so an empty
		// list means nothing to unlock.
		$settings     = FS_Core::instance()->settings();
		$new_customer = FS_Live::is_gateway_restricted_customer( $uid, '' !== $email_norm ? $email_norm : null ) && ! empty( $settings['gateway_restrict_allowed'] );
		$offer        = self::velocity_restrict_applies( $uid, $email_norm ) || $new_customer;

		/**
		 * Filter whether the ID-verification offer shows for this visitor —
		 * e.g. return true to offer it to every unverified customer.
		 *
		 * @param bool $offer Default decision.
		 * @param int  $uid   Current user id (0 = guest).
		 */
		return apply_filters( 'fs_idv_should_offer', $offer, $uid );
	}

	/* ----------------------------------------------------------------- *
	 *  Session rows
	 * ----------------------------------------------------------------- */

	/**
	 * Latest session row for a customer (account first, then email).
	 *
	 * @param int    $uid        User id.
	 * @param string $email_norm Normalized email.
	 * @return object|null
	 */
	private static function latest_row( $uid, $email_norm ) {
		global $wpdb;
		$table = FS_Data::idv_sessions_table();
		$uid   = (int) $uid;

		if ( $uid > 0 && '' !== $email_norm ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d OR email_norm = %s ORDER BY id DESC LIMIT 1", $uid, $email_norm ) ); // phpcs:ignore WordPress.DB
		}
		if ( $uid > 0 ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $uid ) ); // phpcs:ignore WordPress.DB
		}
		if ( '' !== $email_norm ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_norm = %s ORDER BY id DESC LIMIT 1", $email_norm ) ); // phpcs:ignore WordPress.DB
		}
		return null;
	}

	/**
	 * Row by Didit session id.
	 *
	 * @param string $session_id Session id.
	 * @return object|null
	 */
	private static function row_by_session( $session_id ) {
		global $wpdb;
		$table = FS_Data::idv_sessions_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", (string) $session_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Update columns on a session row.
	 *
	 * @param int   $row_id Row id.
	 * @param array $cols   Column => value.
	 */
	private static function update_row( $row_id, $cols ) {
		global $wpdb;
		$cols['updated_at'] = current_time( 'mysql' );
		$wpdb->update( FS_Data::idv_sessions_table(), $cols, array( 'id' => (int) $row_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A still-usable pending session for this customer (< 24 h old), or null.
	 *
	 * @param int    $uid        User id.
	 * @param string $email_norm Normalized email.
	 * @return object|null
	 */
	private static function pending_row( $uid, $email_norm ) {
		$row = self::latest_row( $uid, $email_norm );
		if ( ! $row || ! in_array( (string) $row->status, self::$pending_statuses, true ) ) {
			return null;
		}
		// created_at is stored via current_time('mysql') (site-local), so the
		// "now" side must use the same basis.
		$age = current_time( 'timestamp' ) - (int) strtotime( (string) $row->created_at );
		return ( $age >= 0 && $age < DAY_IN_SECONDS ) ? $row : null;
	}

	/**
	 * Does a session row belong to this caller?
	 *
	 * Logged in: the row was created for this account, or carries the account
	 * email (a session started as a guest and finished after signing in).
	 * Guest: the row email must match the (non-empty) checkout email. Used to
	 * refuse polling someone else's session by id.
	 *
	 * @param object $row        Session row.
	 * @param int    $uid        Caller's user id (0 = guest).
	 * @param string $email_norm Caller's pinned normalized email.
	 * @return bool
	 */
	private static function row_belongs_to( $row, $uid, $email_norm ) {
		if ( (int) $uid > 0 && (int) $row->user_id === (int) $uid ) {
			return true;
		}
		return '' !== (string) $email_norm && (string) $row->email_norm === (string) $email_norm;
	}

	/* ----------------------------------------------------------------- *
	 *  Didit API
	 * ----------------------------------------------------------------- */

	/**
	 * Didit API request.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $path   Path beginning with /v3/.
	 * @param array|null $body   JSON body for POST.
	 * @return array|WP_Error Decoded JSON array, or WP_Error.
	 */
	private static function api_request( $method, $path, $body = null ) {
		$s    = FS_Core::instance()->settings();
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'x-api-key' => trim( (string) $s['idv_api_key'] ),
				'Accept'    => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
			return new WP_Error(
				'fs_idv_api',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Didit API error (HTTP %d).', 'fraud-shield' ), $code ),
				array(
					'status' => $code,
					'body'   => is_array( $json ) ? $json : wp_remote_retrieve_body( $response ),
				)
			);
		}
		return $json;
	}

	/**
	 * Create a Didit verification session.
	 *
	 * @param int    $uid        User id (0 = guest).
	 * @param string $email      Raw email.
	 * @param string $first_name Expected first name.
	 * @param string $last_name  Expected last name.
	 * @return array|WP_Error { session_id, url } or error.
	 */
	private static function create_session( $uid, $email, $first_name, $last_name ) {
		$s    = FS_Core::instance()->settings();
		$body = array(
			'workflow_id' => trim( (string) $s['idv_workflow_id'] ),
			// Stable customer key: Didit reuses an unfinished session for the
			// same vendor_data, which protects the credit balance from double
			// clicks and lost popups. The key carries a per-store prefix — two
			// stores sharing one Didit account used to collide on bare user
			// ids ("fs-u83502" means a different person on each store), which
			// let one store's sync attribute the other store's verifications
			// to whoever held the same numeric id locally.
			'vendor_data' => self::vendor_data_key( $uid, $email ),
			'callback'    => add_query_arg( 'fs-idv-done', '1', home_url( '/' ) ),
			'metadata'    => array(
				'store'   => home_url( '/' ),
				'user_id' => (int) $uid,
			),
		);

		$lang = strtolower( substr( (string) get_locale(), 0, 2 ) );
		if ( 2 === strlen( $lang ) ) {
			$body['language'] = $lang;
		}
		if ( is_email( $email ) ) {
			$body['contact_details'] = array(
				'email'                    => $email,
				'send_notification_emails' => false,
			);
		}
		if ( '' !== trim( $first_name ) && '' !== trim( $last_name ) ) {
			$body['expected_details'] = array(
				'first_name' => mb_substr( trim( $first_name ), 0, 100 ),
				'last_name'  => mb_substr( trim( $last_name ), 0, 100 ),
			);
		}

		$json = self::api_request( 'POST', '/v3/session/', $body );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( empty( $json['session_id'] ) || empty( $json['url'] ) ) {
			return new WP_Error( 'fs_idv_api', __( 'Didit did not return a verification URL.', 'fraud-shield' ) );
		}
		return $json;
	}

	/**
	 * Fetch the full decision for a session.
	 *
	 * @param string $session_id Session id.
	 * @return array|WP_Error
	 */
	private static function fetch_decision( $session_id ) {
		return self::api_request( 'GET', '/v3/session/' . rawurlencode( (string) $session_id ) . '/decision/' );
	}

	/* ----------------------------------------------------------------- *
	 *  Decision processing (shared by webhook + polling fallback)
	 * ----------------------------------------------------------------- */

	/**
	 * Apply a Didit session status to our row; on Approved, run the name-match
	 * policy and persist the verification.
	 *
	 * @param object     $row      Session row.
	 * @param string     $status   Didit session status.
	 * @param array|null $decision Decision payload when the caller has one.
	 */
	private static function process_status( $row, $status, $decision = null ) {
		$status = (string) $status;
		if ( '' === $status || ( (string) $row->status === $status && '' !== (string) $row->result ) ) {
			return; // Nothing new.
		}

		$cols = array( 'status' => $status );

		// Whatever the outcome, keep the decision context Didit sends along:
		// declines carry the most interesting warnings of all.
		if ( is_array( $decision ) ) {
			$cols = array_merge( $cols, self::enrichment_cols( $decision ) );
		}

		if ( 'Approved' === $status ) {
			// Pull the extracted document data (from the webhook payload when it
			// carries the decision, otherwise from the decision endpoint).
			if ( ! is_array( $decision ) || empty( $decision['id_verifications'] ) ) {
				$fetched = self::fetch_decision( $row->session_id );
				if ( is_wp_error( $fetched ) ) {
					// Transient API failure — do NOT record a terminal state from
					// missing data. The row stays pending; the checkout poller (or
					// Didit's webhook retry) completes it on the next pass.
					return;
				}
				$decision = $fetched;
				$cols     = array_merge( $cols, self::enrichment_cols( $decision ) );
			}
			$idv       = ( ! empty( $decision['id_verifications'] ) && is_array( $decision['id_verifications'] ) ) ? (array) $decision['id_verifications'][0] : array();
			$full_name = trim( (string) ( $idv['full_name'] ?? trim( ( $idv['first_name'] ?? '' ) . ' ' . ( $idv['last_name'] ?? '' ) ) ) );
			$doc_type  = (string) ( $idv['document_type'] ?? '' );

			$mode     = (string) ( FS_Core::instance()->settings()['idv_name_match'] ?? 'lenient' );
			$expected = (string) $row->expected_name;
			if ( '' === trim( $expected ) ) {
				// No name was captured when the session started (gate page,
				// account page, or an old row): fall back to the profile
				// name, then to the billing name on their most recent order —
				// which is what guests actually have on file.
				$expected = self::expected_name_for( (int) $row->user_id, (string) $row->email_norm );
				if ( '' !== trim( $expected ) ) {
					$cols['expected_name'] = mb_substr( $expected, 0, 191 );
				}
			}

			$match = self::name_matches( $expected, $full_name, $mode );

			$cols['full_name'] = mb_substr( $full_name, 0, 191 );
			$cols['doc_type']  = mb_substr( $doc_type, 0, 64 );

			if ( $match['ok'] ) {
				$cols['result']     = 'approved';
				$cols['name_match'] = $match['label'];
				self::mark_verified( (int) $row->user_id, (string) $row->email_norm, (string) $row->session_id, $full_name, $match['label'] );
			} else {
				// Document is genuine but the name doesn't match the customer —
				// the stolen-ID + stolen-card combination this check exists to
				// catch, but also what an innocent married name, transliteration
				// or nickname looks like.
				$cols['result']     = 'name_mismatch';
				$cols['name_match'] = 'no';

				// 'verify' (default): the customer completed the check, so complete
				// the verification and unlock their payment methods — but keep the
				// row marked name_mismatch so staff still see it on the ID
				// Verifications page and the order screen. 'hold' keeps the older,
				// stricter behaviour where only staff can promote a mismatch.
				if ( 'hold' !== (string) ( FS_Core::instance()->settings()['idv_mismatch_action'] ?? 'verify' ) ) {
					self::mark_verified( (int) $row->user_id, (string) $row->email_norm, (string) $row->session_id, $full_name, 'no' );
				}
			}
		} elseif ( 'Declined' === $status ) {
			$cols['result'] = 'declined';
		} elseif ( in_array( $status, array( 'Expired', 'Abandoned', 'Kyc Expired' ), true ) ) {
			$cols['result'] = 'expired';
		}

		self::update_row( (int) $row->id, $cols );

		// Publish the outcome. Only terminal results are events: a subscriber
		// wants the verdict, not each intermediate status Didit reports while
		// the customer is still holding up their licence.
		if ( isset( $cols['result'] ) ) {
			$topics = array(
				'approved'      => 'idv.approved',
				'name_mismatch' => 'idv.name_mismatch',
				'declined'      => 'idv.declined',
				'expired'       => 'idv.declined',
			);
			if ( isset( $topics[ $cols['result'] ] ) ) {
				FS_Webhooks::emit(
					$topics[ $cols['result'] ],
					array(
						'session_id'    => (string) $row->session_id,
						'user_id'       => (int) $row->user_id,
						'email_norm'    => (string) $row->email_norm,
						'result'        => (string) $cols['result'],
						'status'        => $status,
						'name_match'    => (string) ( $cols['name_match'] ?? '' ),
						'full_name'     => (string) ( $cols['full_name'] ?? '' ),
						'expected_name' => (string) ( $cols['expected_name'] ?? $row->expected_name ),
						'doc_type'      => (string) ( $cols['doc_type'] ?? '' ),
						'doc_country'   => (string) ( $cols['doc_country'] ?? '' ),
						'vpn'           => ! empty( $cols['verify_vpn'] ),
						'order_id'      => (int) $row->order_id,
					)
				);
			}
		}

		// Leave a trail on the linked order, if any.
		if ( (int) $row->order_id > 0 && isset( $cols['result'] ) ) {
			$order = wc_get_order( (int) $row->order_id );
			if ( $order ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: Didit session status, 2: internal result */
						__( 'Fraud Shield: ID verification update — %1$s (%2$s).', 'fraud-shield' ),
						$status,
						$cols['result']
					)
				);
			}
		}
	}

	/**
	 * Distil a Didit decision payload into the enrichment columns we keep:
	 * where the document is from, where the customer physically was while
	 * verifying, whether they hid behind a VPN or Tor, and any risk warnings
	 * Didit raised. Everything is optional; workflows differ in which features
	 * they run, and Didit has renamed fields before, so every read is
	 * defensive and an absent field simply stays empty.
	 *
	 * Deliberately NOT stored: document numbers, dates of birth, raw addresses,
	 * images. Location and flags carry the fraud signal; the rest is liability.
	 *
	 * @param array $decision Didit decision payload.
	 * @return array Column => value for the session row.
	 */
	private static function enrichment_cols( $decision ) {
		$cols = array();
		$idv  = ( ! empty( $decision['id_verifications'] ) && is_array( $decision['id_verifications'] ) ) ? (array) $decision['id_verifications'][0] : array();
		if ( empty( $idv ) && ! empty( $decision['id_verification'] ) && is_array( $decision['id_verification'] ) ) {
			$idv = (array) $decision['id_verification'];
		}

		$pick = function ( $source, $keys ) {
			foreach ( (array) $keys as $key ) {
				if ( isset( $source[ $key ] ) && '' !== trim( (string) $source[ $key ] ) ) {
					return trim( (string) $source[ $key ] );
				}
			}
			return '';
		};

		if ( ! empty( $idv ) ) {
			$doc_country = $pick( $idv, array( 'issuing_country', 'issuing_country_code', 'document_country', 'country' ) );
			// Prefer the address state printed on the document (a driver's
			// license says where the holder lives); fall back to the issuing
			// state (which says where the document is from).
			$doc_state = '';
			foreach ( array( 'parsed_address', 'address_parts' ) as $addr_key ) {
				if ( ! empty( $idv[ $addr_key ] ) && is_array( $idv[ $addr_key ] ) ) {
					$doc_state = $pick( $idv[ $addr_key ], array( 'state', 'region', 'province' ) );
					break;
				}
			}
			if ( '' === $doc_state ) {
				$doc_state = $pick( $idv, array( 'issuing_state_name', 'issuing_state', 'state' ) );
			}

			if ( '' !== $doc_country ) {
				$cols['doc_country'] = mb_substr( strtoupper( $doc_country ), 0, 8 );
			}
			if ( '' !== $doc_state ) {
				$cols['doc_state'] = mb_substr( $doc_state, 0, 64 );
			}
		}

		$ip = ( ! empty( $decision['ip_analysis'] ) && is_array( $decision['ip_analysis'] ) ) ? (array) $decision['ip_analysis'] : array();
		if ( ! empty( $ip ) ) {
			$ip_country = $pick( $ip, array( 'ip_country_code', 'ip_country', 'country_code', 'country' ) );
			$ip_state   = $pick( $ip, array( 'ip_state', 'ip_region', 'state', 'region' ) );
			if ( '' !== $ip_country ) {
				$cols['verify_ip_country'] = mb_substr( strtoupper( $ip_country ), 0, 8 );
			}
			if ( '' !== $ip_state ) {
				$cols['verify_ip_state'] = mb_substr( $ip_state, 0, 64 );
			}
			foreach ( array( 'is_vpn_or_tor', 'is_vpn', 'vpn', 'is_tor' ) as $vpn_key ) {
				if ( ! empty( $ip[ $vpn_key ] ) ) {
					$cols['verify_vpn'] = 1;
					break;
				}
			}
		}

		$warnings = self::collect_warnings( $decision );
		if ( ! empty( $warnings ) ) {
			$cols['warnings'] = mb_substr( (string) wp_json_encode( $warnings ), 0, 4000 );
		}

		return $cols;
	}

	/**
	 * Gather the human-readable risk warnings scattered through a decision:
	 * Didit attaches a warnings array to the top level and/or to each feature
	 * block (id_verification, ip_analysis, aml, liveness, face_match...).
	 *
	 * @param array $decision Decision payload.
	 * @return string[] Deduplicated short descriptions.
	 */
	private static function collect_warnings( $decision ) {
		$out  = array();
		$grab = function ( $list ) use ( &$out ) {
			foreach ( (array) $list as $w ) {
				if ( is_string( $w ) && '' !== trim( $w ) ) {
					$out[] = trim( $w );
				} elseif ( is_array( $w ) ) {
					$text = trim( (string) ( $w['short_description'] ?? ( $w['description'] ?? ( $w['log_type'] ?? '' ) ) ) );
					if ( '' !== $text ) {
						$risk  = trim( (string) ( $w['risk'] ?? '' ) );
						$out[] = '' !== $risk ? $risk . ': ' . $text : $text;
					}
				}
			}
		};

		if ( ! empty( $decision['warnings'] ) ) {
			$grab( $decision['warnings'] );
		}
		foreach ( $decision as $block ) {
			if ( is_array( $block ) && ! empty( $block['warnings'] ) ) {
				$grab( $block['warnings'] );
			} elseif ( is_array( $block ) && isset( $block[0] ) ) {
				foreach ( $block as $item ) {
					if ( is_array( $item ) && ! empty( $item['warnings'] ) ) {
						$grab( $item['warnings'] );
					}
				}
			}
		}

		return array_values( array_unique( array_slice( $out, 0, 20 ) ) );
	}

	/**
	 * The name an account is known by, for the "expected" side of the document
	 * match when the checkout form didn't supply one: saved billing name, then
	 * the account's real first/last name.
	 *
	 * display_name is only trusted when it is two words or more: for accounts
	 * created at checkout it defaults to the username or the email local part
	 * ("bones123"), and matching a government ID against a username produced a
	 * mismatch on nearly every verification. No name at all is better — the
	 * match then records "n/a" instead of manufacturing a false mismatch.
	 *
	 * @param int $uid User id.
	 * @return string
	 */
	private static function expected_name_for_user( $uid ) {
		$uid  = (int) $uid;
		$name = '';
		if ( class_exists( 'WC_Customer' ) ) {
			try {
				$customer = new WC_Customer( $uid );
				$name     = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
			} catch ( Exception $e ) {
				$name = '';
			}
		}
		if ( '' === $name ) {
			$name = trim( trim( (string) get_user_meta( $uid, 'first_name', true ) ) . ' ' . trim( (string) get_user_meta( $uid, 'last_name', true ) ) );
		}
		if ( '' === $name ) {
			$user    = get_userdata( $uid );
			$display = $user ? trim( (string) $user->display_name ) : '';
			if ( false !== strpos( $display, ' ' ) ) {
				$name = $display;
			}
		}
		return $name;
	}

	/**
	 * Compare the billing/expected name against the name extracted from the
	 * document.
	 *
	 * @param string $expected  What the customer typed / has on file.
	 * @param string $extracted What the ID document says.
	 * @param string $mode      off | lenient | strict.
	 * @return array { ok:bool, label:string }
	 */
	public static function name_matches( $expected, $extracted, $mode ) {
		if ( 'off' === $mode ) {
			return array(
				'ok'    => true,
				'label' => 'n/a',
			);
		}

		$norm = function ( $v ) {
			$v = remove_accents( (string) $v );
			$v = strtolower( $v );
			$v = preg_replace( '/[^a-z ]+/', ' ', $v );
			return trim( preg_replace( '/\s+/', ' ', $v ) );
		};

		$exp = $norm( $expected );
		$ext = $norm( $extracted );

		if ( '' === $exp ) {
			// Nothing to compare against (guest with no name captured) — the
			// document itself was approved; record that the match didn't run.
			return array(
				'ok'    => true,
				'label' => 'n/a',
			);
		}
		if ( '' === $ext ) {
			// Approved workflow but no document name extracted — cannot confirm.
			return array(
				'ok'    => false,
				'label' => 'no',
			);
		}

		$exp_tokens = explode( ' ', $exp );
		$ext_tokens = explode( ' ', $ext );

		$pct = 0.0;
		similar_text( $exp, $ext, $pct );

		if ( 'strict' === $mode ) {
			$subset = count( array_diff( $exp_tokens, $ext_tokens ) ) === 0;
			$ok     = $subset || $pct >= 92;
		} else {
			// Lenient (default): the last name must appear on the document and
			// the first initial must line up — tolerant of middle names, nick
			// names and ordering, still hard to satisfy with someone else's ID.
			// The similarity fallback (≥ 90) only rescues near-identical strings
			// (e.g. transliteration variants), NOT similar-but-different surnames.
			$last_ok  = in_array( end( $exp_tokens ), $ext_tokens, true );
			$first_ok = isset( $exp_tokens[0][0] ) && self::any_token_starts_with( $ext_tokens, $exp_tokens[0][0] );
			$ok       = ( $last_ok && $first_ok ) || $pct >= 90;
		}

		return array(
			'ok'    => $ok,
			'label' => $ok ? 'yes' : 'no',
		);
	}

	/**
	 * Whether any token starts with the given character.
	 *
	 * @param array  $tokens Tokens.
	 * @param string $char   Single character.
	 * @return bool
	 */
	private static function any_token_starts_with( $tokens, $char ) {
		foreach ( (array) $tokens as $t ) {
			if ( '' !== $t && $t[0] === $char ) {
				return true;
			}
		}
		return false;
	}

	/* ----------------------------------------------------------------- *
	 *  Webhook (REST)
	 * ----------------------------------------------------------------- */

	/**
	 * Register the webhook route. Authentication is the HMAC signature — the
	 * permission callback stays open on purpose.
	 */
	public function register_rest() {
		register_rest_route(
			'fraud-shield/v1',
			'/didit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Didit webhook receiver.
	 *
	 * Verifies X-Timestamp freshness (±5 min) and the X-Signature header —
	 * HMAC-SHA256 of the exact raw request body with the shared secret from
	 * the Didit console. Unsigned or mis-signed requests change nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_webhook( $request ) {
		$secret = trim( (string) ( FS_Core::instance()->settings()['idv_webhook_secret'] ?? '' ) );
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'error' => 'webhook not configured' ), 403 );
		}

		$raw       = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x-signature' );
		$timestamp = (string) $request->get_header( 'x-timestamp' );

		if ( '' === $raw || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return new WP_REST_Response( array( 'error' => 'bad request' ), 400 );
		}
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_REST_Response( array( 'error' => 'stale timestamp' ), 401 );
		}
		if ( ! hash_equals( hash_hmac( 'sha256', $raw, $secret ), strtolower( $signature ) ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || empty( $payload['session_id'] ) ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		// Only session status changes matter here; acknowledge everything else.
		$type = (string) ( $payload['webhook_type'] ?? 'status.updated' );
		if ( 'status.updated' !== $type ) {
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		$row = self::row_by_session( (string) $payload['session_id'] );
		if ( ! $row ) {
			// Not a session this store started (or the row predates the table) —
			// try to recover the customer from vendor_data before giving up.
			$row = self::adopt_from_vendor_data( $payload );
		}
		if ( $row ) {
			$decision = ( isset( $payload['decision'] ) && is_array( $payload['decision'] ) ) ? $payload['decision'] : null;
			self::process_status( $row, (string) ( $payload['status'] ?? '' ), $decision );
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Recreate a session row from a webhook whose row we don't have (edge
	 * case: table cleared, or the session was started from another device
	 * against the same account). Only adopts sessions provably this store's:
	 * webhooks reach every store that shares the Didit account, and a bare
	 * uid means a different customer on each one — adopting on uid alone is
	 * how another store's verification got pinned on a local customer.
	 *
	 * @param array $payload Webhook payload (signature already verified).
	 * @return object|null Freshly inserted row, or null.
	 */
	private static function adopt_from_vendor_data( $payload ) {
		global $wpdb;

		// A store stamp that names another store settles it outright.
		$meta_store = isset( $payload['metadata']['store'] ) ? (string) $payload['metadata']['store'] : '';
		if ( '' !== $meta_store && ! self::same_store( $meta_store ) ) {
			return null;
		}

		$vendor = (string) ( $payload['vendor_data'] ?? '' );
		$own    = self::store_key();

		$uid        = 0;
		$email_norm = '';
		$proven     = '' !== $meta_store;

		if ( preg_match( '/^fs-([0-9a-f]{8})-(?:u\d+|e[0-9a-f]{32})$/', $vendor, $m ) && $m[1] !== $own ) {
			return null; // Another store's namespaced key.
		}

		if ( preg_match( '/^fs-(?:' . $own . '-)?u(\d+)$/', $vendor, $m ) ) {
			$uid  = (int) $m[1];
			$user = get_userdata( $uid );
			if ( ! $user ) {
				return null;
			}
			$email_norm = FS_Normalizer::email( $user->user_email );
			if ( 0 === strpos( $vendor, 'fs-' . $own . '-' ) ) {
				$proven = true;
			}
		} elseif ( preg_match( '/^fs-(?:' . $own . '-)?e([0-9a-f]{32})$/', $vendor, $m ) ) {
			// The hash IS the email: resolving it through this store's own
			// customer emails is self-corroborating.
			$hash_map = self::email_hash_map();
			if ( ! isset( $hash_map[ $m[1] ] ) ) {
				return null;
			}
			$email_norm = (string) $hash_map[ $m[1] ];
			$user       = get_user_by( 'email', $email_norm );
			$uid        = $user ? (int) $user->ID : 0;
			$proven     = true;
		} else {
			return null;
		}

		if ( ! $proven ) {
			// Legacy bare uid: only the session's own contact email can vouch
			// for it. The webhook (or the decision riding on it) carries one
			// for every session this plugin created; without agreement the
			// webhook is another store's and adopting it would misattribute.
			$contact = self::payload_contact_email( $payload );
			if ( '' === $contact && isset( $payload['decision'] ) && is_array( $payload['decision'] ) ) {
				$contact = self::payload_contact_email( $payload['decision'] );
			}
			if ( '' === $contact || $contact !== $email_norm ) {
				return null;
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB
			FS_Data::idv_sessions_table(),
			array(
				'session_id'    => (string) $payload['session_id'],
				'user_id'       => $uid,
				'email_norm'    => $email_norm,
				'expected_name' => self::expected_name_for( $uid, $email_norm ),
				'status'        => 'In Progress',
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);
		return self::row_by_session( (string) $payload['session_id'] );
	}

	/* ----------------------------------------------------------------- *
	 *  Restore & sync (v1.19)
	 *
	 *  Didit keeps every session this store ever started, and each one was
	 *  stamped with a customer key at creation (vendor_data = fs-u{user_id}
	 *  for accounts, fs-e{md5 of the normalized email} for guests). That is
	 *  enough to rebuild the whole verification state from nothing — after an
	 *  uninstall, a migration, or a lost database — and to keep a store in
	 *  step with its Didit console on demand.
	 * ----------------------------------------------------------------- */

	/**
	 * Session statuses that are finished and therefore worth restoring.
	 *
	 * @var array
	 */
	private static $terminal_statuses = array( 'Approved', 'Declined', 'Expired', 'Abandoned', 'Kyc Expired' );

	/**
	 * Pull sessions back from Didit and rebuild local state — the whole list
	 * in one call.
	 *
	 * Bounded by wall-clock time, not by a batch size: the loop keeps walking
	 * pages and restoring sessions until the list is exhausted or the time
	 * budget runs out (each approved session costs one decision API call, so
	 * a huge backlog on a slow host can outlast one request). Idempotent:
	 * sessions already known locally are skipped, and progress checkpoints
	 * page by page, so if a run IS cut short — by the budget or by the server
	 * itself — the next run continues where it stopped instead of starting
	 * over.
	 *
	 * @param int $time_budget Seconds this run may spend before handing back.
	 * @return array|WP_Error Report counters, or the first hard API error.
	 */
	public static function sync_from_didit( $time_budget = 240 ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'fs_idv_unconfigured', __( 'ID verification is not configured, so there is nothing to sync from.', 'fraud-shield' ) );
		}

		$report = array(
			'listed'    => 0,  // Sessions seen on Didit this run.
			'known'     => 0,  // Already in the local table; untouched.
			'restored'  => 0,  // Approved sessions fully restored (verification granted or mismatch recorded).
			'history'   => 0,  // Declined/expired sessions recorded as history.
			'skipped'   => 0,  // Still in flight on Didit's side; nothing to restore.
			'unmatched' => 0,  // Could not be tied to any customer this store knows.
			'foreign'   => 0,  // Another store's sessions (shared Didit account); never attributed here.
			'remaining' => 0,  // Approved sessions this run saw but could not restore (deadline, or a decision fetch failed).
			'error'     => '', // A mid-run API failure, so partial progress is never mistaken for "done".
		);

		$hash_map = self::email_hash_map();
		$deadline = microtime( true ) + min( 600, max( 5, (int) $time_budget ) );
		$limit    = 50;
		// A run cut short leaves the cursor at the last fully-processed page,
		// so the next run continues from there instead of re-reading the
		// whole list.
		$start     = max( 0, (int) get_option( 'fs_idv_sync_cursor', 0 ) );
		$offset    = $start;
		$safe      = $start; // Last page boundary processed without a cut.
		$exhausted = false;
		$cut       = false;

		while ( true ) {
			// PHP's own execution clock is reset every page so it never fires
			// while we are inside the budget; the deadline is the real bound.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( microtime( true ) >= $deadline ) {
				$cut = true;
				break;
			}

			$page = self::api_request( 'GET', '/v3/sessions/?limit=' . (int) $limit . '&offset=' . (int) $offset );
			if ( is_wp_error( $page ) ) {
				if ( 0 === $report['listed'] ) {
					return $page; // Nothing worked at all (bad key, network down): a hard error, not a report.
				}
				// Partial progress is safe (every step is idempotent), but it
				// must not read as success: record the failure so the admin is
				// told to run the sync again.
				$report['error'] = $page->get_error_message();
				break;
			}

			$items = ( isset( $page['results'] ) && is_array( $page['results'] ) ) ? $page['results'] : array();
			if ( empty( $items ) ) {
				$exhausted = true;
				break;
			}

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['session_id'] ) ) {
					continue;
				}
				// Business (KYB) sessions are not customer verifications.
				if ( isset( $item['session_kind'] ) && 'user' !== (string) $item['session_kind'] ) {
					continue;
				}
				$report['listed']++;

				$status = (string) ( $item['status'] ?? '' );
				if ( self::is_foreign( (string) $item['session_id'] ) ) {
					$report['foreign']++;
					continue;
				}
				if ( self::row_by_session( (string) $item['session_id'] ) ) {
					$report['known']++;
					continue;
				}
				if ( ! in_array( $status, self::$terminal_statuses, true ) ) {
					$report['skipped']++;
					continue;
				}
				// Approved restores are the expensive step (one decision API
				// call each). Past the deadline they are counted, not fetched,
				// so the report can still say how many are left.
				if ( 'Approved' === $status && microtime( true ) >= $deadline ) {
					$cut = true;
					$report['remaining']++;
					continue;
				}

				self::restore_session( $item, $hash_map, $report );
			}

			if ( ! $cut ) {
				$safe = $offset + $limit;
			}
			if ( empty( $page['next'] ) ) {
				$exhausted = true;
				break;
			}
			$offset += $limit;
		}

		// Cursor bookkeeping. The whole list walked clean: forget the cursor
		// so the next sync starts fresh. Walked to the end but some sessions
		// still need another pass (decision fetches failed, or the deadline
		// hit inside the last pages): hold at this run's start so the next
		// run retries them. Cut or failed mid-list: continue from the last
		// clean page boundary.
		if ( $exhausted && 0 === $report['remaining'] && '' === $report['error'] ) {
			delete_option( 'fs_idv_sync_cursor' );
		} elseif ( $exhausted ) {
			update_option( 'fs_idv_sync_cursor', $start, false );
		} else {
			update_option( 'fs_idv_sync_cursor', $safe, false );
		}

		$report['more'] = ( ! $exhausted && '' === $report['error'] ) || $report['remaining'] > 0;

		return $report;
	}

	/**
	 * Tear attribution back to bedrock so the next sync can rebuild it under
	 * the ownership rules: drops every finished session row, withdraws every
	 * verification a Didit check granted (misattributed sessions granted
	 * some to the wrong customers), and forgets the sync bookkeeping.
	 *
	 * What deliberately survives:
	 *  - live in-flight sessions younger than a day, so a customer mid-check
	 *    keeps their flow;
	 *  - every STAFF decision: accepted mismatches (method 'staff'), the
	 *    Verified account flag, hand-added credentials (src '' = staff);
	 *  - the order index, blocklists, everything that is not ID-verification
	 *    state.
	 *
	 * @return array { purged:int, kept_pending:int, revoked:int }
	 */
	public static function rebuild_reset() {
		global $wpdb;
		$table = FS_Data::idv_sessions_table();

		$statuses = "'" . implode( "','", array_map( 'esc_sql', self::$pending_statuses ) ) . "'";
		$cutoff   = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'Y-m-d H:i:s' );

		$kept   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN ({$statuses}) AND created_at >= %s", $cutoff ) ); // phpcs:ignore WordPress.DB
		$purged = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE NOT (status IN ({$statuses}) AND created_at >= %s)", $cutoff ) ); // phpcs:ignore WordPress.DB

		// Withdraw Didit-earned verification state. Records written before
		// the method marker existed can only have come from a completed
		// check, so a missing method reads as 'didit'.
		$revoked = 0;
		$uids    = $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_fs_idv'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $uids as $uid ) {
			$rec = get_user_meta( (int) $uid, '_fs_idv', true );
			if ( is_array( $rec ) && 'staff' === (string) ( $rec['method'] ?? 'didit' ) ) {
				continue;
			}
			delete_user_meta( (int) $uid, '_fs_idv' );
			$revoked++;
		}

		$map = get_option( 'fs_idv_verified', array() );
		if ( is_array( $map ) && ! empty( $map ) ) {
			$changed = false;
			foreach ( $map as $k => $v ) {
				$v = is_array( $v ) ? $v : array();
				if ( 'staff' === (string) ( $v['method'] ?? 'didit' ) ) {
					continue;
				}
				unset( $map[ $k ] );
				$changed = true;
				$revoked++;
			}
			if ( $changed ) {
				update_option( 'fs_idv_verified', $map, false );
			}
		}

		// The Verified Customers credentials an ID check minted (src 'idv').
		// Staff-added ones carry src '' or 'staff' and stand.
		$creds   = FS_Live::verified_creds();
		$changed = false;
		foreach ( $creds as $key => $meta ) {
			if ( is_array( $meta ) && 'idv' === (string) ( $meta['src'] ?? '' ) ) {
				unset( $creds[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'fs_verified_creds', $creds );
		}

		delete_option( 'fs_idv_sync_cursor' );
		delete_option( 'fs_idv_foreign_sessions' );

		return array(
			'purged'       => $purged,
			'kept_pending' => $kept,
			'revoked'      => $revoked,
		);
	}

	/**
	 * Rebuild one Didit session locally: a history row for everyone, plus the
	 * verification itself for approved sessions, dated when it really
	 * happened so expiry and the high-value freshness rules stay honest.
	 *
	 * @param array $item     Session row from the list endpoint.
	 * @param array $hash_map md5(normalized email) => normalized email.
	 * @param array $report   Counters (by reference).
	 */
	private static function restore_session( $item, $hash_map, &$report ) {
		global $wpdb;

		$session_id = (string) $item['session_id'];
		$status     = (string) ( $item['status'] ?? '' );
		$created_ts = ! empty( $item['created_at'] ) ? (int) strtotime( (string) $item['created_at'] ) : 0;
		$created_at = $created_ts > 0 ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $created_ts ), 'Y-m-d H:i:s' ) : current_time( 'mysql' );

		// Ownership before identity. Stores that share one Didit account see
		// each other's sessions in the same list, and a bare user id means a
		// DIFFERENT person on each store — attributing by uid alone is how
		// one store's customers ended up wearing another store's documents.
		// The store stamp we send at creation is decisive when Didit echoes
		// it back; a session stamped for another store is not even history
		// here.
		$meta_store = isset( $item['metadata']['store'] ) ? (string) $item['metadata']['store'] : '';
		if ( '' !== $meta_store && ! self::same_store( $meta_store ) ) {
			self::remember_foreign( $session_id );
			$report['foreign']++;
			return;
		}

		list( $uid, $email_norm, $verdict ) = self::resolve_vendor_data( (string) ( $item['vendor_data'] ?? '' ), $hash_map );

		if ( 'foreign' === $verdict ) {
			self::remember_foreign( $session_id );
			$report['foreign']++;
			return;
		}

		// 'own' (namespaced for this store) and 'email' (the key IS the
		// email) prove themselves; a matching store stamp proves anything.
		// A LEGACY bare uid key proves nothing across shared Didit accounts:
		// it needs the session's own contact email to agree with the account
		// it resolves to before anyone here is credited.
		$proven = ( '' !== $meta_store ) || in_array( $verdict, array( 'own', 'email' ), true );
		if ( ! $proven && 'legacy' === $verdict ) {
			$contact = self::payload_contact_email( $item );
			if ( '' !== $contact ) {
				if ( $contact === $email_norm ) {
					$proven = true;
				} else {
					// The uid points at someone other than the session's own
					// contact — the uid belongs to another store. The email
					// is the identity that is true everywhere: attach by it
					// when this store knows it, otherwise the session is
					// foreign.
					$known = get_user_by( 'email', $contact );
					if ( $known || isset( $hash_map[ md5( $contact ) ] ) ) {
						$uid        = $known ? (int) $known->ID : 0;
						$email_norm = $contact;
						$proven     = true;
					} else {
						self::remember_foreign( $session_id );
						$report['foreign']++;
						return;
					}
				}
			}
		}

		$cols = array(
			'session_id'    => $session_id,
			'user_id'       => 0,
			'email_norm'    => '',
			'expected_name' => '',
			'status'        => $status,
			'created_at'    => $created_at,
			'updated_at'    => current_time( 'mysql' ),
		);

		if ( 'Approved' !== $status ) {
			// Unfinished/declined history grants nothing, so cheap rules
			// apply: proven identities are attributed; an unproven legacy uid
			// stays an anonymous history row rather than being pinned on
			// whoever holds that number here.
			if ( $proven && ( $uid > 0 || '' !== $email_norm ) ) {
				$cols['user_id']       = (int) $uid;
				$cols['email_norm']    = (string) $email_norm;
				$cols['expected_name'] = self::expected_name_for( (int) $uid, (string) $email_norm );
			} else {
				$report['unmatched']++;
			}
			$cols['result'] = 'Declined' === $status ? 'declined' : 'expired';
			$wpdb->insert( FS_Data::idv_sessions_table(), $cols ); // phpcs:ignore WordPress.DB
			$report['history']++;
			return;
		}

		// Approved: the list row is a light projection, so the document data
		// (name, type, origin, IP analysis, warnings) comes from the decision
		// endpoint. On a transient failure, insert nothing at all — the next
		// run will find the session still unknown and try again.
		$decision = self::fetch_decision( $session_id );
		if ( is_wp_error( $decision ) ) {
			$report['remaining']++;
			return;
		}

		$idv       = ( ! empty( $decision['id_verifications'] ) && is_array( $decision['id_verifications'] ) ) ? (array) $decision['id_verifications'][0] : array();
		$full_name = trim( (string) ( $idv['full_name'] ?? trim( ( $idv['first_name'] ?? '' ) . ' ' . ( $idv['last_name'] ?? '' ) ) ) );
		$doc_type  = (string) ( $idv['document_type'] ?? '' );

		// The decision payload carries the session's contact email — the
		// corroboration legacy uid keys need, and the recovery path for a
		// guest hash this store's map couldn't resolve.
		if ( ! $proven ) {
			$contact = self::payload_contact_email( $decision );
			if ( '' !== $contact ) {
				if ( $uid > 0 && $contact === $email_norm ) {
					$proven = true;
				} else {
					$known = get_user_by( 'email', $contact );
					if ( $known || isset( $hash_map[ md5( $contact ) ] ) ) {
						$uid        = $known ? (int) $known->ID : 0;
						$email_norm = $contact;
						$proven     = true;
					}
				}
			}
			if ( ! $proven && $uid > 0 ) {
				// A legacy uid the contact email does not vouch for: another
				// store's customer. Skipped for good, never credited here.
				self::remember_foreign( $session_id );
				$report['foreign']++;
				return;
			}
		}

		if ( ! $proven || ( 0 === (int) $uid && '' === (string) $email_norm ) ) {
			// Nobody to attach the verification to: keep the history row so the
			// attempt is visible, but grant nothing to a customer we can't name.
			$report['unmatched']++;
			$cols['result']     = 'approved';
			$cols['full_name']  = mb_substr( $full_name, 0, 191 );
			$cols['doc_type']   = mb_substr( $doc_type, 0, 64 );
			$cols['name_match'] = 'n/a';
			$cols                = array_merge( $cols, self::enrichment_cols( $decision ) );
			$wpdb->insert( FS_Data::idv_sessions_table(), $cols ); // phpcs:ignore WordPress.DB
			$report['history']++;
			return;
		}

		$cols['user_id']       = (int) $uid;
		$cols['email_norm']    = (string) $email_norm;
		$cols['expected_name'] = self::expected_name_for( (int) $uid, (string) $email_norm );

		// Same name-match policy as a live decision, judged against the name on
		// file today. Restores follow the same rules as the day the check ran.
		$mode     = (string) ( FS_Core::instance()->settings()['idv_name_match'] ?? 'lenient' );
		$expected = (string) $cols['expected_name'];
		$match    = self::name_matches( $expected, $full_name, $mode );

		$cols['full_name'] = mb_substr( $full_name, 0, 191 );
		$cols['doc_type']  = mb_substr( $doc_type, 0, 64 );
		$cols              = array_merge( $cols, self::enrichment_cols( $decision ) );

		if ( $match['ok'] ) {
			$cols['result']     = 'approved';
			$cols['name_match'] = $match['label'];
			self::maybe_restore_verification( (int) $uid, $email_norm, $session_id, $full_name, $match['label'], $created_ts );
		} else {
			$cols['result']     = 'name_mismatch';
			$cols['name_match'] = 'no';
			if ( 'hold' !== (string) ( FS_Core::instance()->settings()['idv_mismatch_action'] ?? 'verify' ) ) {
				self::maybe_restore_verification( (int) $uid, $email_norm, $session_id, $full_name, 'no', $created_ts );
			}
		}

		$wpdb->insert( FS_Data::idv_sessions_table(), $cols ); // phpcs:ignore WordPress.DB
		$report['restored']++;
	}

	/**
	 * Grant a restored verification unless the customer already holds a newer
	 * one, or the restored check would be born expired. The Didit list arrives
	 * newest first, so without the newest-wins guard a customer with several
	 * approved checks would end the sync wearing their OLDEST one, having
	 * overwritten the newest along the way.
	 *
	 * The expiry gate matters just as much: mark_verified() fires every "just
	 * verified" side effect (the Verified Customers credential, velocity
	 * release, restriction release, clearing an open staff ID demand), and an
	 * approval already past the validity window has not earned any of that. It
	 * stays visible as history; it grants nothing.
	 *
	 * @param int    $uid        Account id.
	 * @param string $email_norm Normalized email.
	 * @param string $session_id Didit session id.
	 * @param string $full_name  Document name.
	 * @param string $name_match Match label.
	 * @param int    $created_ts Epoch the session was created.
	 */
	private static function maybe_restore_verification( $uid, $email_norm, $session_id, $full_name, $name_match, $created_ts ) {
		$created_ts = (int) $created_ts;

		$days = (int) ( FS_Core::instance()->settings()['idv_valid_days'] ?? 365 );
		if ( $days > 0 && $created_ts > 0 && ( $created_ts + $days * DAY_IN_SECONDS ) <= time() ) {
			return; // Born expired: history only.
		}

		$existing = self::record_for( $uid, $email_norm );
		if ( $existing && (int) ( $existing['at_ts'] ?? 0 ) >= $created_ts ) {
			return; // The customer already wears a newer check.
		}

		self::mark_verified( $uid, $email_norm, $session_id, $full_name, $name_match, 'didit', $created_ts );
	}

	/**
	 * Map of md5(normalized email) back to the email, for resolving the
	 * fs-e{hash} vendor keys guests were stamped with. Built from every email
	 * the store knows: the order index and the registered accounts.
	 *
	 * @return array hash => normalized email.
	 */
	private static function email_hash_map() {
		global $wpdb;
		$map = array();

		foreach ( FS_Data::all_customer_emails( 20000 ) as $known ) {
			$known = FS_Normalizer::email( $known );
			if ( '' !== $known ) {
				$map[ md5( $known ) ] = $known;
			}
		}
		$user_emails = $wpdb->get_col( "SELECT user_email FROM {$wpdb->users} WHERE user_email <> '' LIMIT 20000" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $user_emails as $known ) {
			$known = FS_Normalizer::email( (string) $known );
			if ( '' !== $known ) {
				$map[ md5( $known ) ] = $known;
			}
		}

		return $map;
	}

	/**
	 * Create one verification from an imported CSV row.
	 *
	 * Accepts the plugin's own ID Verifications export (round trip: export
	 * before a migration, import after) and hand-made lists down to a bare
	 * column of email addresses. Grants follow the same rules as a live
	 * decision: newest verification wins, the mismatch policy is honoured, and
	 * the original date is kept so expiry and freshness stay honest.
	 *
	 * @param array $data Keys: email, user_id, session_id, result, full_name,
	 *                    name_match, doc_type, verified_at (all optional except
	 *                    one of email / user_id).
	 * @return string 'restored' | 'history' | 'known' | 'invalid'
	 */
	public static function import_verification( $data ) {
		global $wpdb;

		$email_raw  = trim( (string) ( $data['email'] ?? '' ) );
		$email_norm = FS_Normalizer::email( $email_raw );
		$uid        = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;

		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( ! $user ) {
				$uid = 0; // Never attach a verification to a user id that no longer exists.
			} elseif ( '' === $email_norm ) {
				$email_norm = FS_Normalizer::email( $user->user_email );
			}
		}
		if ( 0 === $uid && '' !== $email_raw && is_email( $email_raw ) ) {
			$user = get_user_by( 'email', $email_raw );
			$uid  = $user ? (int) $user->ID : 0;
		}
		if ( 0 === $uid && '' === $email_norm ) {
			return 'invalid';
		}

		$session_id = trim( (string) ( $data['session_id'] ?? '' ) );
		if ( '' === $session_id ) {
			// A stable synthetic id keeps re-imports of the same file idempotent.
			$session_id = 'import-' . md5( $email_norm . '|' . (string) ( $data['verified_at'] ?? '' ) . '|' . $uid );
		}
		if ( self::row_by_session( $session_id ) ) {
			return 'known';
		}

		// Outcome handling errs toward granting nothing. A file with no outcome
		// column at all is a bare list of verified customers, so approval is
		// what the staff member means; a file that HAS the column but carries a
		// value this import does not recognize gets no benefit of the doubt.
		// The plugin's own export writes 'in_progress' for sessions that never
		// finished, and a round-tripped export must not turn those into grants.
		if ( array_key_exists( 'result', $data ) && '' !== trim( (string) $data['result'] ) ) {
			$result = strtolower( trim( (string) $data['result'] ) );
			if ( ! in_array( $result, array( 'approved', 'name_mismatch', 'declined', 'expired' ), true ) ) {
				return 'invalid';
			}
		} else {
			$result = 'approved';
		}

		// Dates in the plugin's own export are site-local naive datetimes.
		$at_ts = 0;
		if ( ! empty( $data['verified_at'] ) ) {
			$gmt = get_gmt_from_date( (string) $data['verified_at'], 'U' );
			if ( $gmt ) {
				$at_ts = (int) $gmt;
			}
		}
		if ( $at_ts <= 0 ) {
			$at_ts = time();
		}

		$name_match = strtolower( trim( (string) ( $data['name_match'] ?? '' ) ) );
		if ( ! in_array( $name_match, array( 'yes', 'no', 'n/a', 'manual' ), true ) ) {
			$name_match = 'name_mismatch' === $result ? 'no' : 'n/a';
		}

		$status_map = array(
			'approved'      => 'Approved',
			'name_mismatch' => 'Approved',
			'declined'      => 'Declined',
			'expired'       => 'Expired',
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB
			FS_Data::idv_sessions_table(),
			array(
				'session_id'    => $session_id,
				'user_id'       => $uid,
				'email_norm'    => $email_norm,
				'expected_name' => $uid > 0 ? self::expected_name_for_user( $uid ) : '',
				'status'        => $status_map[ $result ],
				'result'        => $result,
				'full_name'     => mb_substr( trim( (string) ( $data['full_name'] ?? '' ) ), 0, 191 ),
				'doc_type'      => mb_substr( trim( (string) ( $data['doc_type'] ?? '' ) ), 0, 64 ),
				'name_match'    => $name_match,
				'created_at'    => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $at_ts ), 'Y-m-d H:i:s' ),
				'updated_at'    => current_time( 'mysql' ),
			)
		);

		$grants = ( 'approved' === $result )
			|| ( 'name_mismatch' === $result && 'hold' !== (string) ( FS_Core::instance()->settings()['idv_mismatch_action'] ?? 'verify' ) );
		if ( $grants ) {
			self::maybe_restore_verification( $uid, $email_norm, $session_id, (string) ( $data['full_name'] ?? '' ), $name_match, $at_ts );
			return 'restored';
		}

		return 'history';
	}

	/**
	 * The short key that marks a session as THIS store's. Two storefronts
	 * sharing one Didit account each get their own key, so neither can claim
	 * (or be handed, via Didit's vendor_data session reuse) the other's
	 * sessions.
	 *
	 * @return string 8 hex chars derived from the site URL.
	 */
	public static function store_key() {
		return substr( md5( home_url( '/' ) ), 0, 8 );
	}

	/**
	 * The vendor_data written on new sessions: store key + customer key.
	 *
	 * @param int    $uid   Account id (0 = guest).
	 * @param string $email Contact email (guests).
	 * @return string
	 */
	public static function vendor_data_key( $uid, $email ) {
		$key = self::store_key();
		return (int) $uid > 0
			? 'fs-' . $key . '-u' . (int) $uid
			: 'fs-' . $key . '-e' . md5( FS_Normalizer::email( $email ) );
	}

	/**
	 * Whether a store URL (from session metadata) is THIS store, judged on
	 * the host+path so scheme and www drift don't split a store from itself.
	 *
	 * @param string $url Store URL another record carries.
	 * @return bool
	 */
	private static function same_store( $url ) {
		$norm = function ( $u ) {
			$u = strtolower( trim( (string) $u ) );
			$u = preg_replace( '#^https?://#', '', $u );
			$u = preg_replace( '#^www\.#', '', $u );
			return untrailingslashit( $u );
		};
		return $norm( $url ) === $norm( home_url( '/' ) );
	}

	/**
	 * Remember that a session belongs to another store, so later syncs skip
	 * it without re-fetching its decision. Kept as an option (not a table):
	 * it is a skip-list, losing it costs one wasted API call per entry.
	 *
	 * @param string $session_id Didit session id.
	 */
	private static function remember_foreign( $session_id ) {
		$ids = get_option( 'fs_idv_foreign_sessions', array() );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		if ( isset( $ids[ $session_id ] ) ) {
			return;
		}
		$ids[ $session_id ] = 1;
		if ( count( $ids ) > 5000 ) {
			$ids = array_slice( $ids, -4000, null, true );
		}
		update_option( 'fs_idv_foreign_sessions', $ids, false );
	}

	/**
	 * Whether a session was already identified as another store's.
	 *
	 * @param string $session_id Didit session id.
	 * @return bool
	 */
	private static function is_foreign( $session_id ) {
		$ids = get_option( 'fs_idv_foreign_sessions', array() );
		return is_array( $ids ) && isset( $ids[ $session_id ] );
	}

	/**
	 * The best expected name this store holds for a customer: the profile
	 * name when the account carries a real one, else the billing name from
	 * their most recent order — which is what finally puts names on guest
	 * sessions and on accounts whose profile was never filled in.
	 *
	 * @param int    $uid        Account id (0 = guest).
	 * @param string $email_norm Normalized email.
	 * @return string
	 */
	public static function expected_name_for( $uid, $email_norm ) {
		$name = (int) $uid > 0 ? self::expected_name_for_user( (int) $uid ) : '';
		if ( '' === $name && class_exists( 'FS_Data' ) ) {
			$name = FS_Data::latest_customer_name( (int) $uid, (string) $email_norm );
		}
		return $name;
	}

	/**
	 * Turn a vendor_data key back into a customer — with a verdict on whether
	 * the session is provably this store's.
	 *
	 * Verdicts:
	 *  'own'     - carries this store's key: attribution is trustworthy.
	 *  'foreign' - carries ANOTHER store's key: never attribute it here.
	 *  'legacy'  - bare pre-1.22 uid key: the uid means someone on every
	 *              store that shares the Didit account, so attribution needs
	 *              corroboration (the session's contact email must agree)
	 *              before anyone is credited.
	 *  'email'   - resolved through an email hash this store knows; the hash
	 *              IS the email, so the resolution is self-corroborating.
	 *  'none'    - unresolvable.
	 *
	 * @param string $vendor   The vendor_data Didit stored.
	 * @param array  $hash_map md5(normalized email) => normalized email.
	 * @return array [ user_id, email_norm, verdict ].
	 */
	private static function resolve_vendor_data( $vendor, $hash_map ) {
		$vendor = (string) $vendor;
		$own    = self::store_key();

		if ( preg_match( '/^fs-([0-9a-f]{8})-(u\d+|e[0-9a-f]{32})$/', $vendor, $m ) && $m[1] !== $own ) {
			return array( 0, '', 'foreign' );
		}

		if ( preg_match( '/^fs-(?:' . $own . '-)?u(\d+)$/', $vendor, $m ) ) {
			$uid     = (int) $m[1];
			$user    = get_userdata( $uid );
			$namespaced = 0 === strpos( $vendor, 'fs-' . $own . '-' );
			// The account may be gone (deleted user): fall back to unmatched
			// rather than granting a verification to a recycled user id.
			if ( ! $user ) {
				return array( 0, '', 'none' );
			}
			return array( $uid, FS_Normalizer::email( $user->user_email ), $namespaced ? 'own' : 'legacy' );
		}
		if ( preg_match( '/^fs-(?:' . $own . '-)?e([0-9a-f]{32})$/', $vendor, $m ) && isset( $hash_map[ $m[1] ] ) ) {
			$email = (string) $hash_map[ $m[1] ];
			$user  = get_user_by( 'email', $email );
			$namespaced = 0 === strpos( $vendor, 'fs-' . $own . '-' );
			return array( $user ? (int) $user->ID : 0, $email, $namespaced ? 'own' : 'email' );
		}
		return array( 0, '', 'none' );
	}

	/**
	 * The contact email a Didit payload carries, '' when it carries none.
	 * Defensive against the couple of shapes Didit has used.
	 *
	 * @param array $data Session list item, decision payload, or webhook body.
	 * @return string Normalized email or ''.
	 */
	private static function payload_contact_email( $data ) {
		foreach ( array( 'contact_details', 'contact' ) as $key ) {
			$candidate = isset( $data[ $key ]['email'] ) ? (string) $data[ $key ]['email'] : '';
			if ( is_email( $candidate ) ) {
				return FS_Normalizer::email( $candidate );
			}
		}
		$flat = isset( $data['email'] ) && is_string( $data['email'] ) ? (string) $data['email'] : '';
		return is_email( $flat ) ? FS_Normalizer::email( $flat ) : '';
	}

	/* ----------------------------------------------------------------- *
	 *  Front-end AJAX
	 * ----------------------------------------------------------------- */

	/**
	 * Start (or resume) a verification session for the current customer.
	 */
	public function ajax_start() {
		check_ajax_referer( 'fs_idv', 'nonce' );

		if ( ! self::configured() ) {
			wp_send_json_error( array( 'message' => __( 'ID verification is not available right now.', 'fraud-shield' ) ), 400 );
		}

		$uid   = get_current_user_id();
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';

		// What the customer actually entered on this request — kept apart from
		// the profile fallback below, because only genuine input may refresh
		// the expected name on a resumed session.
		$typed = trim( trim( $first ) . ' ' . trim( $last ) );

		if ( $uid > 0 ) {
			// SECURITY: the verification is bound to the ACCOUNT identity, never
			// to a client-supplied email — otherwise a logged-in attacker could
			// get an arbitrary (mule) email marked ID-verified with their own ID.
			$user  = get_userdata( $uid );
			$email = $user ? (string) $user->user_email : '';

			// The first/last name the customer typed into the checkout form IS
			// the expected side of the document match — comparing the ID against
			// what they entered is the point of the check. The profile name only
			// fills in when the request carried no name at all (the gate page
			// and account page have no name fields). The profile used to win
			// outright, so a stale or username-shaped account name overrode the
			// real name sitting right there in the billing fields — and the
			// match failed on nearly every verification.
			if ( '' === $typed ) {
				$on_file = self::expected_name_for( $uid, FS_Normalizer::email( $email ) );
				if ( '' !== trim( $on_file ) ) {
					$parts = explode( ' ', $on_file, 2 );
					$first = $parts[0] ?? '';
					$last  = $parts[1] ?? '';
				}
			}
		}
		if ( 0 === $uid && ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your email address first, then try again.', 'fraud-shield' ) ), 400 );
		}
		if ( 0 === $uid && '' === $typed ) {
			// Guests get the same courtesy as accounts: when the form carried
			// no name, the billing name from their latest order stands in.
			$on_file = self::expected_name_for( 0, FS_Normalizer::email( $email ) );
			if ( '' !== trim( $on_file ) ) {
				$parts = explode( ' ', $on_file, 2 );
				$first = $parts[0] ?? '';
				$last  = $parts[1] ?? '';
			}
		}

		// The offer must actually apply (or the merchant placed the shortcode).
		// Identity-aware: a guest is judged by the email they typed, not by
		// whatever the session held at page load — the button they clicked was
		// offered against that typed email, and this gate must agree with it.
		$via_page = ! empty( $_POST['context'] ) && 'page' === sanitize_key( wp_unslash( $_POST['context'] ) );
		if ( ! self::should_offer_for( $uid, FS_Normalizer::email( $email ) ) && ! ( $via_page && $uid > 0 && ! self::is_verified( $uid, FS_Normalizer::email( $email ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'ID verification is not needed for your account.', 'fraud-shield' ) ), 400 );
		}

		$email_norm = FS_Normalizer::email( $email );

		// Resume an in-flight session instead of paying for a new one. The
		// session id rides along so the browser polls THIS session and no
		// other — the flow stays bound to the session it opened.
		$pending = self::pending_row( $uid, $email_norm );

		// A resume must not hand out a dead session. Didit expires sessions on
		// its own clock, well inside our 24-hour resume window — and a customer
		// who abandoned the popup an hour ago and clicks again used to be sent
		// straight back into the corpse: a Didit error page, with nothing on
		// our side ever recovering. For a row older than 15 minutes, ask Didit
		// once whether the session is still alive (behind the same 10-second
		// per-session lock the status poll uses). A terminal answer is recorded
		// like any webhook and a NEW session is created below instead; an API
		// failure resumes as before rather than burning a fresh credit blind.
		if ( $pending && self::configured()
			&& ( current_time( 'timestamp' ) - (int) strtotime( (string) $pending->created_at ) ) > 15 * MINUTE_IN_SECONDS
			&& false === get_transient( 'fs_idv_probe_' . $pending->session_id ) ) {
			set_transient( 'fs_idv_probe_' . $pending->session_id, 1, 10 );
			$decision = self::fetch_decision( (string) $pending->session_id );
			if ( ! is_wp_error( $decision ) && ! empty( $decision['status'] ) ) {
				self::process_status( $pending, (string) $decision['status'], $decision );
				$fresh = self::row_by_session( (string) $pending->session_id );
				if ( $fresh && in_array( (string) $fresh->status, self::$pending_statuses, true ) ) {
					$pending = $fresh; // Still alive — resume it.
				} elseif ( $fresh && 'approved' === (string) $fresh->result ) {
					// The check finished while they were away. Don't open
					// anything: the card flips green on the next poll/refresh.
					wp_send_json_error( array( 'message' => __( 'Good news — this verification was already completed. Your payment options update in a moment.', 'fraud-shield' ) ), 409 );
				} else {
					$pending = null; // Expired/abandoned/declined → start fresh.
				}
			}
		}

		if ( $pending && ! empty( $pending->url ) ) {
			// The customer may have typed (or corrected) their name since the
			// click that opened this session — keep the expected name in step
			// with what they entered, so the eventual match checks the ID
			// against their latest input rather than a stale first draft. Only
			// genuine form input refreshes it: a resume from the gate page
			// (which has no name fields) must not clobber a typed name with
			// the profile fallback.
			if ( '' !== $typed && $typed !== trim( (string) $pending->expected_name ) ) {
				self::update_row( (int) $pending->id, array( 'expected_name' => mb_substr( $typed, 0, 191 ) ) );
			}
			wp_send_json_success(
				array(
					'url'     => (string) $pending->url,
					'session' => (string) $pending->session_id,
				)
			);
		}

		// Rate-limit new sessions per customer (protects the Didit balance).
		$max      = max( 1, (int) ( FS_Core::instance()->settings()['idv_max_attempts'] ?? 3 ) );
		$rl_key   = 'fs_idv_rl_' . md5( $uid > 0 ? 'u' . $uid : 'e' . $email_norm );
		$attempts = (int) get_transient( $rl_key );
		if ( $attempts >= $max ) {
			wp_send_json_error( array( 'message' => __( 'Too many verification attempts. Please try again tomorrow or contact support.', 'fraud-shield' ) ), 429 );
		}

		$expected_name = trim( $first . ' ' . $last );
		$session       = self::create_session( $uid, $email, $first, $last );
		if ( is_wp_error( $session ) ) {
			wp_send_json_error( array( 'message' => __( 'We could not start the verification. Please try again in a moment.', 'fraud-shield' ) ), 502 );
		}

		set_transient( $rl_key, $attempts + 1, DAY_IN_SECONDS );

		// Didit reuses an unfinished session for the same vendor_data, so the
		// "new" session may be one this table already tracks (the customer
		// came back after the 24h resume window). Refresh that row instead of
		// inserting a twin — duplicate rows made the history page list the
		// same check twice and left polls racing two copies.
		global $wpdb;
		$existing = self::row_by_session( (string) $session['session_id'] );
		if ( $existing ) {
			$cols = array(
				'url'        => (string) $session['url'],
				'status'     => (string) ( $session['status'] ?? $existing->status ),
				'updated_at' => current_time( 'mysql' ),
			);
			if ( '' !== $expected_name ) {
				$cols['expected_name'] = mb_substr( $expected_name, 0, 191 );
			}
			self::update_row( (int) $existing->id, $cols );
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				FS_Data::idv_sessions_table(),
				array(
					'session_id'    => (string) $session['session_id'],
					'user_id'       => (int) $uid,
					'email_norm'    => $email_norm,
					'expected_name' => mb_substr( $expected_name, 0, 191 ),
					'url'           => (string) $session['url'],
					'status'        => (string) ( $session['status'] ?? 'Not Started' ),
					'created_at'    => current_time( 'mysql' ),
					'updated_at'    => current_time( 'mysql' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'url'     => (string) $session['url'],
				'session' => (string) $session['session_id'],
			)
		);
	}

	/**
	 * Poll the customer's verification state. When no webhook has arrived for
	 * an in-flight session, fall back to asking Didit's decision API directly
	 * (throttled) — so the flow completes even before webhooks are set up.
	 */
	public function ajax_status() {
		check_ajax_referer( 'fs_idv', 'nonce' );

		$uid   = get_current_user_id();
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		// SECURITY: identity is pinned exactly like ajax_start() pins it — a
		// logged-in customer is their ACCOUNT, never whatever email currently
		// sits in the billing field. Before this, the poll trusted the typed
		// email: with another customer's address in the form (an agent-assisted
		// order, autofill, a shared machine), is_verified() and latest_row()
		// matched THAT customer — so the moment they finished THEIR check,
		// this poll answered "approved" to someone else. The typed email only
		// counts for guests, whose checkout identity IS the typed email.
		if ( $uid > 0 ) {
			$user       = get_userdata( $uid );
			$email_norm = $user ? FS_Normalizer::email( $user->user_email ) : '';
		} else {
			$email_norm = FS_Normalizer::email( $email );
		}

		// "Approved" here means approved FOR THIS CHECKOUT: a customer holding
		// an old verification who is being asked for a fresh one (a high-value
		// re-verification) must not be told they are done, or the page would
		// reload straight back into the same request.
		if ( self::is_verified( $uid, $email_norm ) && null === self::outstanding_requirement( $uid, $email_norm ) ) {
			wp_send_json_success( array( 'state' => 'approved' ) );
		}

		// The browser reports WHICH session it opened (returned by ajax_start
		// and carried for the life of the flow), so the poll follows that
		// exact session — never "the newest row that looks like this
		// customer". The ownership check keeps it honest: a session id that
		// does not belong to the caller is ignored, not answered.
		$row        = null;
		$session_id = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		if ( '' !== $session_id ) {
			$candidate = self::row_by_session( $session_id );
			if ( $candidate && self::row_belongs_to( $candidate, $uid, $email_norm ) ) {
				$row = $candidate;
			}
		}
		if ( ! $row ) {
			$row = self::latest_row( $uid, $email_norm );
		}
		if ( ! $row ) {
			wp_send_json_success( array( 'state' => 'none' ) );
		}

		// Active fallback: webhook hasn't updated a pending row → query Didit
		// (at most once every 10 s per session, enforced with a transient lock).
		if ( in_array( (string) $row->status, self::$pending_statuses, true )
			&& self::configured()
			&& false === get_transient( 'fs_idv_probe_' . $row->session_id ) ) {
			set_transient( 'fs_idv_probe_' . $row->session_id, 1, 10 );
			$decision = self::fetch_decision( (string) $row->session_id );
			if ( ! is_wp_error( $decision ) && ! empty( $decision['status'] ) ) {
				self::process_status( $row, (string) $decision['status'], $decision );
				$row = self::row_by_session( (string) $row->session_id );
				if ( ! $row ) {
					wp_send_json_success( array( 'state' => 'none' ) );
				}
			}
		}

		if ( self::is_verified( $uid, $email_norm ) && null === self::outstanding_requirement( $uid, $email_norm ) ) {
			wp_send_json_success( array( 'state' => 'approved' ) );
		}

		$s     = FS_Core::instance()->settings();
		$state = 'pending';
		$msg   = '';
		if ( 'name_mismatch' === (string) $row->result ) {
			$state = 'mismatch';
			$msg   = (string) $s['idv_declined_text'];
		} elseif ( 'declined' === (string) $row->result ) {
			$state = 'declined';
			$msg   = (string) $s['idv_declined_text'];
		} elseif ( 'expired' === (string) $row->result ) {
			// Its own state, not 'none': the browser was polling this session,
			// and 'none' reads as "keep waiting" — which left an abandoned
			// customer staring at a spinner forever. 'expired' tells idv.js to
			// stop the poll, explain, and offer a fresh start.
			$state = 'expired';
			$msg   = __( 'Your verification session expired before it was finished. Click the button to start a new one.', 'fraud-shield' );
		}

		wp_send_json_success(
			array(
				'state'   => $state,
				'message' => $msg,
			)
		);
	}

	/**
	 * What a session row's outcome should be CALLED where staff read it. A row
	 * with a recorded result speaks for itself. A row still in flight is
	 * honestly "in progress" only while the customer can still resume it (the
	 * 24-hour window); older than that it is an abandonment in every sense
	 * that matters, and calling it "in progress" for weeks misled staff into
	 * thinking verifications were stuck. Display-level only — the row itself
	 * is updated when real status arrives (webhook, poll probe, resume probe,
	 * or the Sync tool).
	 *
	 * @param array|object $row Session row.
	 * @return string result value | 'in_progress' | 'abandoned'.
	 */
	public static function display_outcome( $row ) {
		$row    = (array) $row;
		$result = (string) ( $row['result'] ?? '' );
		if ( '' !== $result ) {
			return $result;
		}
		$age = current_time( 'timestamp' ) - (int) strtotime( (string) ( $row['created_at'] ?? '' ) );
		return ( $age >= 0 && $age < DAY_IN_SECONDS ) ? 'in_progress' : 'abandoned';
	}

	/**
	 * AJAX: recompute the checkout verification card for the identity NOW on
	 * the form. The page-load render can only answer for the identity the
	 * session knew at load time — a guest who then types the email of a
	 * restricted or velocity-locked customer changes the answer, and on the
	 * blocks checkout (no PHP hook re-render) nothing else can surface the
	 * card. idv.js calls this after checkout updates and email edits.
	 *
	 * Identity is pinned exactly like ajax_status(): a logged-in customer is
	 * their account, never the typed email; the typed email only counts for
	 * guests, whose checkout identity IS the typed email. Read-only — decides
	 * what to SHOW; the start endpoint re-checks the gate on its own.
	 */
	public function ajax_state() {
		check_ajax_referer( 'fs_idv', 'nonce' );

		if ( ! self::configured() ) {
			wp_send_json_success(
				array(
					'state'   => 'none',
					'html'    => '',
					'session' => '',
				)
			);
		}

		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			$user       = get_userdata( $uid );
			$email_norm = $user ? FS_Normalizer::email( $user->user_email ) : '';
		} else {
			$posted     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$email_norm = FS_Normalizer::email( $posted );
			if ( '' === $email_norm && function_exists( 'WC' ) && WC()->customer ) {
				$email_norm = FS_Normalizer::email( WC()->customer->get_billing_email() );
			}
		}

		$requirement = self::outstanding_requirement( $uid, $email_norm );
		$verified    = null === $requirement && self::is_verified( $uid, $email_norm );
		$pending     = self::pending_row( $uid, $email_norm );
		$offer       = self::should_offer_for( $uid, $email_norm );

		$state = 'none';
		if ( $verified ) {
			$state = 'approved';
		} elseif ( $pending ) {
			$state = 'pending';
		} elseif ( $offer || null !== $requirement ) {
			$state = 'offer';
		}

		wp_send_json_success(
			array(
				'state'   => $state,
				'html'    => 'none' === $state ? '' : self::render_box( 'checkout', $email_norm ),
				'session' => $pending ? (string) $pending->session_id : '',
			)
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Checkout UI
	 * ----------------------------------------------------------------- */

	/**
	 * The notice + button rendered above the payment methods (classic
	 * checkout); the same markup is injected by idv.js on the blocks checkout.
	 */
	public function checkout_notice() {
		echo self::render_box( 'checkout' ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- built from escaped parts in render_box().
	}

	/**
	 * Build the verification box markup ('' when there is nothing to show).
	 *
	 * @param string $context    'checkout' | 'page'.
	 * @param string $email_hint Guest identity email when the caller knows one
	 *                           the session may not hold yet (the AJAX state
	 *                           re-check passes the email typed into the form).
	 *                           Ignored for logged-in customers, whose identity
	 *                           is always their account.
	 * @return string
	 */
	public static function render_box( $context = 'checkout', $email_hint = '' ) {
		if ( ! self::configured() ) {
			return '';
		}
		$s          = FS_Core::instance()->settings();
		$uid        = get_current_user_id();
		$user       = $uid > 0 ? get_userdata( $uid ) : null;
		$email_norm = $user ? FS_Normalizer::email( $user->user_email ) : '';

		// For guests, identity is the email typed into the form (when the
		// caller carried it) or the one WooCommerce holds on the session; the
		// high-value rules need it to recognize a returning verified customer
		// who is not signed in.
		if ( '' === $email_norm && $uid <= 0 ) {
			$email_norm = FS_Normalizer::email( $email_hint );
		}
		if ( '' === $email_norm && function_exists( 'WC' ) && WC()->customer ) {
			$email_norm = FS_Normalizer::email( WC()->customer->get_billing_email() );
		}

		$requirement = 'checkout' === $context ? self::outstanding_requirement( $uid, $email_norm ) : null;

		// Verified: replace the invitation with a confirmation. This used to render
		// nothing at checkout on the grounds that the unlocked methods spoke for
		// themselves, but a customer who has just handed over an ID document
		// deserves to be told it worked. Not shown while anything is outstanding:
		// telling someone "you have been verified" in the same breath as asking
		// them to verify again is how trust in the card dies.
		if ( null === $requirement && self::is_verified( $uid, $email_norm ) ) {
			return self::verified_card_html( $context );
		}

		// A guest owing a high-value check cannot hold the verification (it
		// lives on an account), so their card carries a sign-in path instead of
		// a button that would go nowhere.
		if ( null !== $requirement && 'hv' === $requirement['type'] && $uid <= 0 ) {
			return self::hv_guest_card_html( $requirement, $s );
		}

		$offer = ( 'page' === $context && $uid > 0 ) || self::should_offer_for( $uid, $email_norm );
		if ( ! $offer ) {
			return '';
		}

		$pending = self::pending_row( $uid, $email_norm );
		$state   = $pending ? 'pending' : 'offer';
		$notice  = $pending ? $s['idv_pending_text'] : $s['idv_notice_text'];
		$button  = $pending ? __( 'Resume verification', 'fraud-shield' ) : $s['idv_button_text'];
		$heading = $pending
			? __( 'Verification in progress', 'fraud-shield' )
			: __( 'Unlock every payment method', 'fraud-shield' );

		// High-value orders speak differently: this is not an upsell to unlock
		// methods, it is a condition of the order. Re-verification (an already
		// ID-verified customer being asked again because of the order size)
		// gets its own text, because "verify once to unlock everything" would
		// be a broken promise to someone who already did.
		if ( ! $pending && null !== $requirement && 'hv' === $requirement['type'] ) {
			$amount = wp_strip_all_tags( wc_price( (float) $requirement['threshold'] ) );
			$notice = str_replace( '{amount}', $amount, (string) ( 'idv' === $requirement['tier'] ? $s['hv_reverify_text'] : $s['hv_notice_text'] ) );
			if ( 'hard' === $requirement['mode'] ) {
				$heading = __( 'This order requires identity verification', 'fraud-shield' );
			} else {
				$heading = 'idv' === $requirement['tier']
					? __( 'Please confirm your identity again', 'fraud-shield' )
					: __( 'Verify your ID for this order', 'fraud-shield' );
			}
		}

		$html  = '<div class="fs-idv fs-card" id="fs-idv-box" data-state="' . esc_attr( $state ) . '" data-context="' . esc_attr( $context ) . '" role="group">';
		$html .= '<div class="fs-card-icon" aria-hidden="true">' . FS_Live::shield_svg( 28 ) . '</div>';
		$html .= '<div class="fs-card-body">';
		$html .= '<p class="fs-card-title">' . esc_html( $heading ) . '</p>';
		$html .= '<p class="fs-card-text fs-idv-text">' . wp_kses_post( $notice ) . '</p>';
		$html .= '<p class="fs-card-actions">';
		$html .= '<button type="button" class="button fs-idv-btn">' . esc_html( $button ) . '</button>';
		$html .= '<span class="fs-idv-spinner" aria-hidden="true"></span>';
		$html .= '</p>';
		if ( '' !== trim( (string) $s['idv_consent_text'] ) ) {
			$html .= '<p class="fs-card-fine fs-idv-consent">' . wp_kses_post( $s['idv_consent_text'] ) . '</p>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * The card a guest sees when a high-value rule fires: same shell, but the
	 * action is signing in, because a verification has to live on an account.
	 *
	 * @param array $requirement Fired requirement from outstanding_requirement().
	 * @param array $s           Plugin settings.
	 * @return string
	 */
	private static function hv_guest_card_html( $requirement, $s ) {
		$amount = wp_strip_all_tags( wc_price( (float) $requirement['threshold'] ) );
		$text   = str_replace( '{amount}', $amount, (string) $s['hv_notice_text'] );

		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();

		$html  = '<div class="fs-idv fs-card" id="fs-idv-box" data-state="signin" data-context="checkout" role="group">';
		$html .= '<div class="fs-card-icon" aria-hidden="true">' . FS_Live::shield_svg( 28 ) . '</div>';
		$html .= '<div class="fs-card-body">';
		$html .= '<p class="fs-card-title">' . esc_html__( 'This order requires identity verification', 'fraud-shield' ) . '</p>';
		$html .= '<p class="fs-card-text">' . wp_kses_post( $text ) . ' ' . esc_html__( 'Verification is saved to your account, so please sign in or create an account first.', 'fraud-shield' ) . '</p>';
		$html .= '<p class="fs-card-actions"><a class="button" href="' . esc_url( $account_url ) . '">' . esc_html__( 'Sign in to verify', 'fraud-shield' ) . '</a></p>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * The green "you have been verified" confirmation card.
	 *
	 * @param string $context 'checkout' | 'page'.
	 * @return string
	 */
	public static function verified_card_html( $context = 'checkout' ) {
		$s = FS_Core::instance()->settings();

		$html  = '<div class="fs-idv fs-card fs-card--ok" id="fs-idv-box" data-state="approved" data-context="' . esc_attr( $context ) . '" role="status">';
		$html .= '<div class="fs-card-icon" aria-hidden="true">' . FS_Live::shield_svg( 28 ) . '</div>';
		$html .= '<div class="fs-card-body">';
		$html .= '<p class="fs-card-title">' . esc_html__( 'You have been verified.', 'fraud-shield' ) . '</p>';
		$sub   = trim( (string) $s['idv_verified_text'] );
		if ( '' !== $sub ) {
			$html .= '<p class="fs-card-text">' . wp_kses_post( $sub ) . '</p>';
		}
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * The verify block for the blocked-checkout gate.
	 *
	 * The gate is a standalone document with no `wp_head()`, so nothing enqueued
	 * is available here — not even jQuery. This returns a self-contained button
	 * plus a dependency-free script that drives the same two AJAX endpoints as
	 * `idv.js`, and reloads the page on approval so the (now lifted) gate lets
	 * the customer through.
	 *
	 * @return string Escaped HTML, or '' when there is nothing to offer.
	 */
	public static function gate_verify_html() {
		$uid = get_current_user_id();
		if ( ! self::configured() || ! self::requires_verification( $uid ) ) {
			return '';
		}

		$s          = FS_Core::instance()->settings();
		$user       = get_userdata( $uid );
		$email_norm = $user ? FS_Normalizer::email( $user->user_email ) : '';
		$pending    = self::pending_row( $uid, $email_norm );

		$label = $pending ? __( 'Resume verification', 'fraud-shield' ) : (string) $s['idv_button_text'];

		$cfg = array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'fs_idv' ),
			'pending' => (bool) $pending,
			'session' => $pending ? (string) $pending->session_id : '',
			'pollMs'  => 4000,
			'texts'   => array(
				'starting' => __( 'Opening the verification window…', 'fraud-shield' ),
				'waiting'  => (string) $s['idv_pending_text'],
				'approved' => __( 'Verified — taking you to checkout…', 'fraud-shield' ),
				'error'    => __( 'Something went wrong — please try again.', 'fraud-shield' ),
				'popup'    => __( 'Your browser blocked the verification window. Click the button again, or allow pop-ups for this site.', 'fraud-shield' ),
				'retry'    => __( 'That verification was not completed. You can start it again.', 'fraud-shield' ),
			),
		);

		$html  = '<div class="fs-idv-gate" id="fs-idv-gate">';
		// The gate has already stated why checkout is blocked, so this line only
		// carries live status. The usual checkout invitation copy is an upsell
		// ("want more payment options?") and would read as a contradiction here.
		$html .= '<p id="fs-idv-gate-text">' . ( $pending ? wp_kses_post( $s['idv_pending_text'] ) : esc_html__( 'Verification takes about a minute and opens in a new window.', 'fraud-shield' ) ) . '</p>';
		$html .= '<button type="button" id="fs-idv-gate-btn">' . esc_html( $label ) . '</button>';
		if ( '' !== trim( (string) $s['idv_consent_text'] ) ) {
			$html .= '<small>' . wp_kses_post( $s['idv_consent_text'] ) . '</small>';
		}
		$html .= '</div>';

		$html .= '<script>(function(){var C=' . wp_json_encode( $cfg ) . ';'
			// The flow is pinned to ONE session id: seeded by the server when a
			// session is already in flight, updated from fs_idv_start's answer,
			// and sent with every poll — so this page only ever watches its own
			// verification, never another customer's.
			. 'var btn=document.getElementById("fs-idv-gate-btn"),msg=document.getElementById("fs-idv-gate-text"),timer=null,pop=null,started=false,sess=C.session||"";'
			. 'function say(t){msg.textContent=t;}'
			// Tell the return page how this window left for Didit ('popup' or
			// 'tab'), since Didit's COOP header severs window.opener and the
			// return page cannot tell a popup from the customer's only tab.
			. 'function setMode(m){try{window.localStorage.setItem("fs_idv_mode",m);}catch(e){}}'
			. 'function post(action,extra){var b=new URLSearchParams();b.append("action",action);b.append("nonce",C.nonce);'
			. 'if(extra){for(var k in extra){b.append(k,extra[k]);}}'
			. 'return fetch(C.ajax,{method:"POST",credentials:"same-origin",body:b}).then(function(r){return r.json().catch(function(){return null;});});}'
			. 'function stop(){if(timer){clearInterval(timer);timer=null;}}'
			// The gate usually renders where billing fields exist (the payment
			// box on checkout). When they do, the typed name rides along so
			// the ID is matched against what the customer entered, same as
			// the regular checkout flow.
			. 'function fv(ids){for(var i=0;i<ids.length;i++){var el=document.getElementById(ids[i]);if(el&&el.value&&el.value.trim()){return el.value.trim();}}return "";}'
			. 'function names(){return{first_name:fv(["billing_first_name","billing-first_name","shipping-first_name"]),last_name:fv(["billing_last_name","billing-last_name","shipping-last_name"])};}'
			. 'function poll(){post("fs_idv_status",sess?{session:sess}:{}).then(function(res){if(!res||!res.success||!res.data){return;}'
			. 'var st=res.data.state;'
			. 'if(st==="approved"){stop();say(C.texts.approved);if(pop&&!pop.closed){try{pop.close();}catch(e){}}setTimeout(function(){window.location.reload();},900);}'
			. 'else if(st==="declined"||st==="mismatch"){stop();btn.disabled=false;say(res.data.message||C.texts.error);}'
			// A session that expired or was abandoned reports "none". The button is
			// the customer's only way out of this page, so it must come back.
			. 'else if(st==="none"&&started){stop();btn.disabled=false;say(C.texts.retry);}'
			. '}).catch(function(){});}'
			. 'function watch(){started=true;if(!timer){timer=setInterval(poll,C.pollMs);poll();}}'
			. 'btn.addEventListener("click",function(){btn.disabled=true;say(C.texts.starting);'
			. 'var nm=names();nm.context="checkout";'
			. 'post("fs_idv_start",nm).then(function(res){'
			. 'if(!res||!res.success||!res.data||!res.data.url){btn.disabled=false;say((res&&res.data&&res.data.message)?res.data.message:C.texts.error);return;}'
			. 'if(res.data.session){sess=res.data.session;}'
			. 'setMode("popup");'
			. 'pop=window.open(res.data.url,"fs_idv","width=480,height=780,menubar=no,toolbar=no");'
			. 'if(!pop){setMode("tab");say(C.texts.popup);btn.disabled=false;window.location.href=res.data.url;return;}'
			. 'say(C.texts.waiting);watch();'
			. '}).catch(function(){btn.disabled=false;say(C.texts.error);});});'
			// Completion signals from the return page: postMessage when the
			// opener link survived, the cross-window storage event when not.
			. 'window.addEventListener("message",function(e){if(e&&e.data==="fs-idv-complete"){watch();}});'
			. 'window.addEventListener("storage",function(e){if(e&&e.key==="fs_idv_done"){watch();}});'
			. 'if(C.pending){watch();}'
			. '})();</script>';

		return $html;
	}

	/**
	 * Enqueue the checkout script when the box could be shown.
	 */
	public function enqueue_assets() {
		if ( ! self::configured() || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		$on_checkout = is_checkout() && ! is_wc_endpoint_url( 'order-received' );
		if ( ! $on_checkout && ! self::$shortcode_used ) {
			return;
		}

		$uid        = get_current_user_id();
		$user       = $uid > 0 ? get_userdata( $uid ) : null;
		$email_norm = $user ? FS_Normalizer::email( $user->user_email ) : '';
		if ( '' === $email_norm && function_exists( 'WC' ) && WC()->customer ) {
			// A guest's only identity is the email WooCommerce holds on the session.
			$email_norm = FS_Normalizer::email( WC()->customer->get_billing_email() );
		}
		$pending     = self::pending_row( $uid, $email_norm );
		$offer       = self::should_offer();
		$requirement = self::outstanding_requirement( $uid, $email_norm );
		// "Verified" for this checkout means verified with nothing outstanding:
		// a customer being asked for a fresh check on a high-value order gets
		// the invitation, not the green card.
		$verified = self::is_verified( $uid, $email_norm ) && null === $requirement;

		// ALWAYS enqueued on the checkout when IDV is configured — never gated
		// on whether this page load already has something to show. The load-time
		// answer is provisional on a guest checkout: a fresh guest has no
		// session email yet, so nothing shows — then they type the email of a
		// restricted or velocity-locked customer and the answer flips. On the
		// blocks checkout only this script can surface the card (no PHP hook
		// re-render), and on the classic checkout the fragment re-render brings
		// the card back WITHOUT this script, leaving a button that does nothing.
		// Both read as "the ID verification button doesn't show / doesn't work"
		// — the script must be present to re-check state as identity arrives.
		wp_enqueue_script( 'fs-idv', FS_PLUGIN_URL . 'assets/idv.js', array( 'jquery' ), FS_VERSION, true );
		wp_localize_script(
			'fs-idv',
			'FS_IDV_JS',
			array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'fs_idv' ),
				'poll_ms'    => 4000,
				'state'      => $verified ? 'approved' : ( $pending ? 'pending' : ( $offer ? 'offer' : 'none' ) ),
				// The specific session this customer's flow is tied to, so a
				// resumed poll (page reload while the check is in flight) asks
				// about THIS session and no other.
				'session'    => $pending ? (string) $pending->session_id : '',
				'offer_html' => ( $verified || $offer || $pending || null !== $requirement ) ? self::render_box( 'checkout' ) : '',
				'texts'      => array(
					'verified'       => (string) FS_Core::instance()->settings()['idv_verified_text'],
					'verified_title' => __( 'You have been verified.', 'fraud-shield' ),
					'error'          => __( 'Something went wrong — please try again.', 'fraud-shield' ),
					'popup'          => __( 'Your browser blocked the verification window. Click the button again, or allow pop-ups for this site.', 'fraud-shield' ),
				),
			)
		);
	}

	/**
	 * Tracks whether the shortcode rendered on this request (so the script is
	 * enqueued for it, too).
	 *
	 * @var bool
	 */
	private static $shortcode_used = false;

	/**
	 * [fraud_shield_verify] — the same box on any page (e.g. My Account).
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! self::configured() ) {
			return '';
		}
		if ( ! is_user_logged_in() ) {
			return '<div class="woocommerce-info">' . esc_html__( 'Please log in to verify your identity.', 'fraud-shield' ) . '</div>';
		}
		self::$shortcode_used = true;
		// The shortcode renders before wp_enqueue_scripts on some themes; cover both orders.
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue_assets();
		}
		return self::render_box( 'page' );
	}

	/**
	 * Minimal page Didit redirects the popup back to: signal the original tab,
	 * then close (popup flow) or return to checkout (same-tab flow).
	 *
	 * Didit's hosted pages send a Cross-Origin-Opener-Policy header, which
	 * SEVERS window.opener inside the popup. The old logic used opener alone to
	 * tell "popup" from "the checkout tab itself travelled to Didit" — so a
	 * severed popup took the same-tab branch and navigated ITSELF to checkout.
	 * The customer ended up with two live checkout windows sharing one cart,
	 * which is how duplicate orders were placed.
	 *
	 * The flow now decides from a same-origin localStorage flag our own script
	 * sets before leaving for Didit ('popup' or 'tab' — COOP cannot touch
	 * localStorage), and the completion signal to the original tab rides a
	 * localStorage write (the 'storage' event fires across same-origin windows
	 * even with a severed opener; postMessage is kept as an accelerator when
	 * the opener survived). A popup NEVER loads the checkout: if the browser
	 * refuses to close it, it shows "close this window" guidance instead.
	 */
	public function maybe_render_return_page() {
		if ( ! isset( $_GET['fs-idv-done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		nocache_headers();
		$title    = esc_html( get_bloginfo( 'name' ) );
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		$msg      = esc_html__( 'Verification submitted.', 'fraud-shield' );
		$texts    = array(
			'close'    => __( 'Verification submitted. You can close this window and return to your checkout — your payment options update there automatically.', 'fraud-shield' ),
			'redirect' => __( 'Taking you back to checkout…', 'fraud-shield' ),
			'button'   => __( 'Close this window', 'fraud-shield' ),
		);
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title>' // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
			. '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f7f7;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:90vh;margin:0}.card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:28px 32px;text-align:center;max-width:420px}#fs-idv-close{display:none;margin-top:14px;padding:9px 18px;border:0;border-radius:4px;background:#2271b1;color:#fff;font-size:14px;cursor:pointer}#fs-idv-back{display:none;margin-top:10px;font-size:13px}</style></head>'
			. '<body><div class="card"><p style="font-size:2em;margin:.1em 0;">&#10003;</p><p id="fs-idv-msg">' . $msg . '</p>' // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
			. '<button type="button" id="fs-idv-close">' . esc_html( $texts['button'] ) . '</button>'
			. '<p><a id="fs-idv-back" href="' . esc_url( $checkout ) . '">' . esc_html__( 'Return to checkout', 'fraud-shield' ) . '</a></p></div>'
			. '<script>(function(){'
			. 'var T=' . wp_json_encode( array_map( 'wp_strip_all_tags', $texts ) ) . ';'
			. 'var checkoutUrl=' . wp_json_encode( esc_url_raw( $checkout ) ) . ';'
			. 'var mode="";'
			// Read which flow left for Didit, then broadcast completion: the
			// storage write fires a `storage` event in the checkout/gate tab,
			// which starts polling immediately — COOP cannot block this.
			. 'try{mode=window.localStorage.getItem("fs_idv_mode")||"";window.localStorage.removeItem("fs_idv_mode");window.localStorage.setItem("fs_idv_done",String(Date.now()));}catch(e){}'
			. 'try{if(window.opener&&!window.opener.closed){window.opener.postMessage("fs-idv-complete","*");}}catch(e){}'
			// Same-tab flow (popup was blocked): this IS the customer's only
			// window, so take it back to checkout.
			. 'if(mode==="tab"){document.getElementById("fs-idv-msg").textContent=T.redirect;setTimeout(function(){window.location.replace(checkoutUrl);},800);return;}'
			// Popup flow (or unknown): close. NEVER load the checkout here — a
			// popup that turns into a second checkout is how orders duplicate.
			. 'setTimeout(function(){try{window.close();}catch(e){}},600);'
			. 'setTimeout(function(){'
			. 'document.getElementById("fs-idv-msg").textContent=T.close;'
			. 'var b=document.getElementById("fs-idv-close");b.style.display="inline-block";'
			. 'b.addEventListener("click",function(){try{window.close();}catch(e){}});'
			// The checkout link appears only when the flow is unknown (storage
			// unavailable): a stranded same-tab visitor needs a way back, but a
			// known popup must not be invited to open checkout inside itself.
			. 'if(mode!=="popup"){document.getElementById("fs-idv-back").style.display="inline-block";}'
			. '},1500);'
			. '})();</script></body></html>';
		exit;
	}

	/* ----------------------------------------------------------------- *
	 *  Orders + admin
	 * ----------------------------------------------------------------- */

	/**
	 * Stamp new orders placed by an ID-verified customer — durable evidence
	 * for chargeback disputes.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public function stamp_order( $order_id, $order = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || 'yes' === $order->get_meta( '_fs_idv_verified' ) ) {
			return;
		}
		$uid        = (int) $order->get_customer_id();
		$email_norm = FS_Normalizer::email( $order->get_billing_email() );
		if ( ! self::is_verified( $uid, $email_norm ) ) {
			return;
		}
		$rec = self::record_for( $uid, $email_norm );
		$order->update_meta_data( '_fs_idv_verified', 'yes' );
		$order->update_meta_data( '_fs_idv_session', (string) ( $rec['session_id'] ?? '' ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Didit session id */
				__( 'Fraud Shield: customer is ID-verified (Didit session %s).', 'fraud-shield' ),
				(string) ( $rec['session_id'] ?? '—' )
			)
		);
		$order->save();
	}

	/**
	 * Register the compact ID Verification meta box on order screens.
	 */
	public function add_meta_box() {
		if ( ! self::enabled() ) {
			return;
		}
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box( 'fs_idv_box', __( 'ID Verification (Didit)', 'fraud-shield' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	/**
	 * Whether the feature is switched on (credentials or not) — used to decide
	 * if admin surfaces render.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return ! empty( FS_Core::instance()->settings()['idv_enabled'] );
	}

	/**
	 * Render the order meta box: the customer's state + one-click actions.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$uid        = (int) $order->get_customer_id();
		$email_norm = FS_Normalizer::email( $order->get_billing_email() );
		$row        = self::latest_row( $uid, $email_norm );
		$verified   = self::is_verified( $uid, $email_norm );

		echo '<div class="fs-idv-metabox">';
		if ( $verified ) {
			$rec = self::record_for( $uid, $email_norm );
			// Verified on a name mismatch is a materially different fact from a
			// clean verification, so it must not wear the same green badge.
			$mismatched = ( $rec && 'no' === (string) ( $rec['name_match'] ?? '' ) );
			if ( $mismatched ) {
				echo '<p style="margin:.2em 0;"><span style="display:inline-block;padding:3px 10px;border-radius:3px;color:#1d2327;font-weight:600;background:#f0b849;">' . esc_html__( 'VERIFIED — NAME MISMATCH', 'fraud-shield' ) . '</span></p>';
				echo '<p style="margin:.4em 0;">' . esc_html__( 'Didit approved the document, but the name on it did not match this customer. They were verified anyway per your settings — worth a look.', 'fraud-shield' ) . '</p>';
			} else {
				echo '<p style="margin:.2em 0;"><span style="display:inline-block;padding:3px 10px;border-radius:3px;color:#fff;font-weight:600;background:#46b450;">' . esc_html__( 'VERIFIED', 'fraud-shield' ) . '</span></p>';
			}
			if ( $rec ) {
				echo '<p style="margin:.4em 0;">' . esc_html__( 'Document name:', 'fraud-shield' ) . ' <strong>' . esc_html( $rec['full_name'] ? $rec['full_name'] : '—' ) . '</strong><br/>';
				echo esc_html__( 'Name match:', 'fraud-shield' ) . ' ' . esc_html( $rec['name_match'] ) . '<br/>';
				echo esc_html__( 'Verified:', 'fraud-shield' ) . ' ' . esc_html( $rec['at'] );
				if ( ! empty( $rec['expires'] ) ) {
					echo '<br/>' . esc_html__( 'Expires:', 'fraud-shield' ) . ' ' . esc_html( date_i18n( get_option( 'date_format' ), (int) $rec['expires'] ) );
				}
				echo '</p>';
			}
			echo '<p>' . $this->admin_action_link( 'revoke', $uid, $email_norm, $order->get_id(), __( 'Revoke verification', 'fraud-shield' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
		} elseif ( $row && 'name_mismatch' === (string) $row->result ) {
			echo '<p style="margin:.2em 0;"><span style="display:inline-block;padding:3px 10px;border-radius:3px;color:#fff;font-weight:600;background:#d63638;">' . esc_html__( 'NAME MISMATCH', 'fraud-shield' ) . '</span></p>';
			echo '<p style="margin:.4em 0;">' . esc_html__( 'Document approved by Didit, but the name did not match the customer.', 'fraud-shield' ) . '<br/>';
			echo esc_html__( 'Document name:', 'fraud-shield' ) . ' <strong>' . esc_html( (string) $row->full_name ) . '</strong><br/>';
			echo esc_html__( 'Expected:', 'fraud-shield' ) . ' <strong>' . esc_html( (string) $row->expected_name ) . '</strong></p>';
			echo '<p>' . $this->admin_action_link( 'accept', $uid, $email_norm, $order->get_id(), __( 'Accept & mark verified', 'fraud-shield' ), (int) $row->id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
		} elseif ( $row && in_array( (string) $row->status, self::$pending_statuses, true ) ) {
			echo '<p style="margin:.2em 0;">' . esc_html__( 'Verification in progress…', 'fraud-shield' ) . ' <span class="fs-muted">(' . esc_html( (string) $row->status ) . ')</span></p>';
		} elseif ( $row && 'declined' === (string) $row->result ) {
			echo '<p style="margin:.2em 0;"><span style="display:inline-block;padding:3px 10px;border-radius:3px;color:#fff;font-weight:600;background:#d63638;">' . esc_html__( 'DECLINED', 'fraud-shield' ) . '</span> <span class="fs-muted">' . esc_html( (string) $row->status ) . '</span></p>';
		} else {
			echo '<p style="margin:.2em 0;" class="fs-muted">' . esc_html__( 'No ID verification on file for this customer.', 'fraud-shield' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Accept a held name mismatch and verify the customer.
	 *
	 * A genuine document whose name differs for an innocent reason (married
	 * name, transliteration, a middle name on the ID) is a staff judgement
	 * call, so the mismatch is recorded rather than discarded and this promotes
	 * it. Refuses anything that is not actually a held mismatch, so the call
	 * cannot be used to verify an arbitrary session.
	 *
	 * @param int $row_id Session row id.
	 * @return bool True when a mismatch was accepted.
	 */
	public static function accept_mismatch( $row_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FS_Data::idv_sessions_table() . ' WHERE id = %d', (int) $row_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $row || 'name_mismatch' !== (string) $row->result ) {
			return false;
		}

		self::mark_verified( (int) $row->user_id, (string) $row->email_norm, (string) $row->session_id, (string) $row->full_name, 'manual', 'staff' );
		self::update_row(
			(int) $row->id,
			array(
				'result'     => 'approved',
				'name_match' => 'manual',
			)
		);
		return true;
	}

	/**
	 * Build a nonce-protected admin action link.
	 *
	 * @param string $do       Action: revoke | accept.
	 * @param int    $uid      User id.
	 * @param string $email    Normalized email.
	 * @param int    $order_id Order id for the redirect back (0 = profile).
	 * @param string $label    Link text.
	 * @param int    $row_id   Session row id (accept only).
	 * @return string
	 */
	private function admin_action_link( $do, $uid, $email, $order_id, $label, $row_id = 0 ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'fs_idv_admin',
					'do'     => $do,
					'uid'    => (int) $uid,
					'email'  => rawurlencode( (string) $email ),
					'order'  => (int) $order_id,
					'row'    => (int) $row_id,
				),
				admin_url( 'admin-post.php' )
			),
			'fs_idv_admin'
		);
		return '<a href="' . esc_url( $url ) . '" class="button button-small">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Handle the admin actions (revoke / accept-mismatch).
	 */
	public function handle_admin_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'fraud-shield' ) );
		}
		check_admin_referer( 'fs_idv_admin' );

		$do       = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$uid      = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
		$email    = ( isset( $_GET['email'] ) && is_string( $_GET['email'] ) ) ? FS_Normalizer::email( rawurldecode( sanitize_text_field( wp_unslash( $_GET['email'] ) ) ) ) : '';
		$order_id = isset( $_GET['order'] ) ? (int) $_GET['order'] : 0;
		$row_id   = isset( $_GET['row'] ) ? (int) $_GET['row'] : 0;

		if ( 'revoke' === $do ) {
			self::revoke( $uid, $email );
			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->add_order_note( __( 'Fraud Shield: ID verification revoked by staff.', 'fraud-shield' ) );
				}
			}
		} elseif ( 'accept' === $do && $row_id > 0 && self::accept_mismatch( $row_id ) && $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( __( 'Fraud Shield: name mismatch accepted by staff — customer marked ID-verified.', 'fraud-shield' ) );
			}
		}

		$back = $order_id > 0
			? ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
				? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
				: admin_url( 'post.php?post=' . $order_id . '&action=edit' ) )
			: ( $uid > 0 ? admin_url( 'user-edit.php?user_id=' . $uid ) : admin_url() );

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * User-profile section: state + revoke, for support workflows.
	 *
	 * @param WP_User $user User being edited.
	 */
	public function render_user_profile( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::enabled() ) {
			return;
		}
		$uid        = (int) $user->ID;
		$email_norm = FS_Normalizer::email( $user->user_email );
		$rec        = self::record_for_user( $uid );
		$verified   = self::is_verified( $uid, $email_norm );
		$row        = self::latest_row( $uid, $email_norm );

		echo '<h2>' . esc_html__( 'Fraud Shield — ID Verification', 'fraud-shield' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Status', 'fraud-shield' ) . '</th><td>';
		if ( $verified ) {
			echo '<strong style="color:#46b450;">' . esc_html__( 'Verified', 'fraud-shield' ) . '</strong>';
			if ( $rec ) {
				echo ' — ' . esc_html( $rec['full_name'] ? $rec['full_name'] : '' ) . ' <span class="description">(' . esc_html( $rec['at'] ) . ')</span>';
				if ( 'no' === (string) ( $rec['name_match'] ?? '' ) ) {
					echo ' <strong style="color:#bd8600;">' . esc_html__( '(name mismatch)', 'fraud-shield' ) . '</strong>';
				}
			}
			echo '<br/>' . $this->admin_action_link( 'revoke', $uid, $email_norm, 0, __( 'Revoke verification', 'fraud-shield' ) ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
		} elseif ( $row && 'name_mismatch' === (string) $row->result ) {
			echo '<strong style="color:#d63638;">' . esc_html__( 'Name mismatch', 'fraud-shield' ) . '</strong> — ' . esc_html( (string) $row->full_name );
			echo '<br/>' . $this->admin_action_link( 'accept', $uid, $email_norm, 0, __( 'Accept & mark verified', 'fraud-shield' ), (int) $row->id ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped
		} elseif ( $row ) {
			echo esc_html( (string) $row->status );
		} else {
			echo '<span class="description">' . esc_html__( 'No verification on file.', 'fraud-shield' ) . '</span>';
		}
		echo '</td></tr></table>';
	}
}

