<?php
/**
 * Core bootstrap: settings, the custom "Charged Back" order status, and wiring
 * up the live + admin + scanner subsystems.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Core {

	/**
	 * Singleton instance.
	 *
	 * @var FS_Core|null
	 */
	private static $instance = null;

	/**
	 * Merged settings cache.
	 *
	 * @var array|null
	 */
	private $settings_cache = null;

	/**
	 * @var FS_Scanner
	 */
	public $scanner;

	/**
	 * @var FS_Importer
	 */
	public $importer;

	/**
	 * @var FS_Live
	 */
	public $live;

	/**
	 * @var FS_Admin
	 */
	public $admin;

	/**
	 * @var FS_IDV
	 */
	public $idv;

	/**
	 * @var FS_Cardholder
	 */
	public $cardholder;

	/**
	 * @var FS_REST_API
	 */
	public $api;

	/**
	 * @var FS_Webhooks
	 */
	public $webhooks;

	/**
	 * @var FS_API_Admin
	 */
	public $api_admin;

	/**
	 * Get/create the instance.
	 *
	 * @return FS_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register status + subsystems.
	 */
	private function __construct() {
		add_action( 'init', array( __CLASS__, 'register_order_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_order_status_to_list' ) );

		// Show our status in the HPOS/legacy reports + bulk where applicable.
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( __CLASS__, 'register_post_status_args' ) );

		// Background geo backfill (v1.31): re-derives street / door keys after
		// a parser change, and finishes the v14 backfill on sites that never
		// pressed the button. Re-arms itself until nothing remains.
		add_action( FS_Data::CRON_GEO, array( 'FS_Data', 'cron_geo_backfill' ) );

		$this->scanner    = new FS_Scanner();
		$this->importer   = new FS_Importer();
		$this->live       = new FS_Live();
		$this->idv        = new FS_IDV();
		$this->cardholder = new FS_Cardholder();

		// The API registers on rest_api_init and the webhook queue on cron, so
		// neither adds anything to an ordinary page load or a checkout.
		$this->api      = new FS_REST_API();
		$this->webhooks = new FS_Webhooks();

		if ( is_admin() ) {
			// Before FS_Admin: its menu() points a submenu at this object, and
			// the action handlers have to be registered on admin-post.php
			// requests, which never build a menu.
			$this->api_admin = FS_API_Admin::instance();
			$this->admin     = new FS_Admin();
		}
	}

	/**
	 * Register the custom "Charged Back" order status.
	 */
	public static function register_order_status() {
		register_post_status(
			'wc-charged-back',
			array(
				'label'                     => _x( 'Charged Back', 'Order status', 'fraud-shield' ),
				'public'                    => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: order count */
				'label_count'               => _n_noop( 'Charged Back <span class="count">(%s)</span>', 'Charged Back <span class="count">(%s)</span>', 'fraud-shield' ),
			)
		);
	}

	/**
	 * Provide args so WooCommerce knows about the status (HPOS-friendly).
	 *
	 * @param array $statuses Existing.
	 * @return array
	 */
	public static function register_post_status_args( $statuses ) {
		$statuses['wc-charged-back'] = array(
			'label'                     => _x( 'Charged Back', 'Order status', 'fraud-shield' ),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Charged Back <span class="count">(%s)</span>', 'Charged Back <span class="count">(%s)</span>', 'fraud-shield' ),
		);
		return $statuses;
	}

	/**
	 * Add the status to the order-status dropdown.
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function add_order_status_to_list( $statuses ) {
		$new = array();
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-refunded' === $key ) {
				$new['wc-charged-back'] = _x( 'Charged Back', 'Order status', 'fraud-shield' );
			}
		}
		if ( ! isset( $new['wc-charged-back'] ) ) {
			$new['wc-charged-back'] = _x( 'Charged Back', 'Order status', 'fraud-shield' );
		}
		return $new;
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'mode'                         => 'observe', // observe | enforce.
			'action_high'                  => 'hold',    // hold | block | flag (what to do at HIGH).
			'block_message'                => __( 'We were unable to process this order. Please contact support for assistance.', 'fraud-shield' ),
			'count_refunded_as_chargeback' => false,

			// Auto-extend blocks: when an order matches an existing block, link its
			// own credentials to the owning action so future orders re-using any of
			// them stay blocked. Independent of observe/enforce (like the blocklist).
			'auto_extend_blocks'           => true,

			// Linked-identity auto-cancel (v1.26): an order placed by a customer
			// who SHARES a credential with a blocked identity — the same IP,
			// phone, account or shipping address as someone on the blocklist,
			// on this order or on any earlier one they placed — is cancelled the
			// moment it is created, before payment is taken, and the customer
			// reads the message below. This catches what a direct blocklist
			// match cannot: the ring member whose own credentials were never
			// blocked, the drop address a blocked customer used under a fresh
			// email, the imported or manually-added block that never cascaded.
			// Independent of observe/enforce (like the blocklist). Verified
			// customers, verified credentials and completed ID checks are
			// exempt, because the link is an inference, not a human verdict.
			// Which credential types count is configurable: IP is the noisiest
			// (carrier and campus NAT), so a store can leave it out.
			'linked_cancel_enabled' => false,
			'linked_cancel_types'   => array( 'email', 'phone', 'ip', 'address', 'user' ),
			'linked_cancel_message' => __( 'We were unable to process this order. Please contact support for assistance.', 'fraud-shield' ),

			// Financial Protection assumptions (v1.26). The page never invents
			// these: a fee of 0 reads as "not configured" and fees are shown
			// as unavailable; the loss ratio is the share of a charged-back
			// order's value that is actually lost (100 = the goods are gone
			// and the money reversed); the minimum window floors how far a
			// short burst of chargebacks is annualized (90 days = at most x4).
			'fin_chargeback_fee'   => 0,
			'fin_loss_ratio'       => 100,
			'fin_min_window_days'  => 90,

			// Email alerts for risky orders.
			'notify_enabled'               => false,
			'notify_emails'                => get_option( 'admin_email' ),
			'notify_min_level'             => 'high', // medium | high | block.

			// Checkout velocity rule (independent of observe/enforce mode). A
			// plain count, never points: once the customer already has
			// `threshold` orders inside the window, the next order is held for
			// review (default), refused at checkout, or pushed onto the
			// restricted gateways.
			'velocity_lock'                => false,
			'velocity_lock_threshold'      => 2,  // act once the customer already has this many orders in the window.
			'velocity_lock_window'         => 30, // the window's length…
			'velocity_lock_window_unit'    => 'days', // …in 'days' or 'hours'.
			'velocity_lock_action'         => 'hold', // hold (order On-Hold for review) | block (refuse checkout) | restrict (limited gateways).
			'velocity_restrict_gateways'   => array(), // gateways a velocity-restricted customer may use.
			'velocity_lock_new_only'       => true,
			'velocity_lock_new_months'     => 3,
			// v1.31: what else counts as "the same customer" for the lock. The
			// phone is a person (on by default); the door is a place — unit-blind,
			// so in an apartment building it is every resident — hence off
			// unless the merchant wants it.
			'velocity_lock_by_phone'       => true,
			'velocity_lock_by_door'        => false,
			'velocity_lock_message'        => __( 'For your security, your account needs a quick manual verification before you can place another order. Please contact our support team to verify your account.', 'fraud-shield' ),

			// Address validation for destination locks (v1.31). Off until a key
			// is entered; only ever consulted when a street or building lock
			// exists to resolve against. See FS_Address_Check.
			'addr_check_enabled'           => false,
			'addr_check_provider'          => 'census', // census (free, US) | google (key) | nominatim (free, worldwide, 1 req/s).
			'addr_check_google_key'        => '',
			'addr_check_contact'           => '', // Nominatim's required contact in the User-Agent (admin email when empty).
			'addr_check_ambiguous_action'  => 'block', // block (stop for correction) | allow (typed-only matching).
			'addr_check_ambiguous_message' => __( 'We could not verify this shipping address against postal records. Please check the street, city, state and ZIP code and try again — or contact us and we will help.', 'fraud-shield' ),

			// New-account high-value verification hold (independent of observe/enforce).
			// A recently-registered account whose order exceeds the amount is locked
			// from placing further orders until an admin verifies it.
			'hold_enabled'                 => false,
			'hold_days'                    => 90,   // "registered within the last N days".
			'hold_amount'                  => 500,  // order total that triggers the hold.
			'hold_order'                   => true, // also put the triggering order On-Hold for review.
			'hold_emails'                  => get_option( 'admin_email' ),
			'hold_message'                 => __( 'Thanks for your order! For your security, your account needs a quick manual verification before we can accept further orders. Our team will reach out shortly, or you can contact support to verify your account.', 'fraud-shield' ),

			// High-value order review (v1.32): every order whose total reaches
			// this amount is listed on the High-Value Review page for a person
			// to look at — profile, address, network — and record a verdict:
			// looks good, hold the package, or cancel now. A queue, not a
			// control: nothing is held or refused by it until someone decides.
			// Compared against the order total as indexed (what the customer
			// paid), so orders placed before this version qualify on the same
			// footing as new ones. 0 switches the page off.
			'hv_review_amount'             => 500,

			// Which number every money threshold in the plugin compares against:
			// 'subtotal' = the item total after coupon discounts, before shipping
			// and tax (what most merchants mean by "a $500 order"); 'total' = the
			// grand total the customer actually pays, shipping and tax included.
			// Used by the low-value allowance and the high-value ID rules alike,
			// so the two can never disagree about what an order is worth.
			'amount_basis' => 'subtotal',

			// New-customer payment gateway restriction: accounts registered within
			// the last N days may only use the allowed gateways (e.g. keep new
			// accounts off card processors). Verified customers are exempt.
			'gateway_restrict_enabled'     => false,
			'gateway_restrict_days'        => 90,
			'gateway_restrict_allowed'     => array(), // gateway ids new customers may use.
			'gateway_restrict_notice'      => '',      // optional message shown at checkout (empty = none).
			// Low-value allowance: these gateways also become available to a new
			// customer when the order amount is under the threshold (0 = disabled).
			'gateway_restrict_lowvalue_amount'   => 0,
			'gateway_restrict_lowvalue_gateways' => array(),

			// Shown when a customer submits an order on a gateway they are not
			// allowed to use (the allow-list is also applied to the payment
			// methods on display, so this is the belt-and-braces case).
			'gateway_restrict_reject_message' => __( 'That payment method is not available on your account yet. Please choose one of the other methods shown, or verify your identity to unlock every payment method.', 'fraud-shield' ),

			// Per-customer restricted checkout is a probation, not a life
			// sentence: it lapses on its own after this many months, and an
			// approved ID check ends it early. 0 months = never lapses.
			'gw_restrict_expire_months'    => 6,
			'gw_restrict_release_on_idv'   => true,

			// Established, clean customers earn their way out of the automatic
			// controls: an account this old, with at least this many paid orders
			// and no chargebacks or refunds, is treated as verified (gateway
			// restrictions, velocity lock, new-account hold). NEVER the
			// blocklist — that stays a human decision.
			'auto_verify_enabled'          => true,
			'auto_verify_min_days'         => 180,
			'auto_verify_min_orders'       => 1,
			'auto_verify_require_clean'    => true,

			// High-value orders: above a per-tier amount, the order requires an
			// ID check. Which threshold applies depends on how much the store
			// already trusts the customer: no verification at all, automatic
			// verification earned from history, or a completed Didit check that
			// is now being asked for again. 0 disables a tier. Customers who
			// have not completed the required check keep checking out on the
			// fallback gateway set below (empty = no restriction, invite only),
			// except at or above the hard amount, where the order is refused
			// until the check is done. Only the staff Verified account flag is
			// exempt from the hard rule.
			'hv_enabled'         => false,
			'hv_amount_none'     => 1000,  // customers with no verification.
			'hv_amount_auto'     => 3000,  // automatically verified customers.
			'hv_amount_idv'      => 10000, // ID-verified customers: ask again above this.
			'hv_hard_amount'     => 0,     // at/above this the order is refused until verified (0 = off).
			'hv_fresh_hours'     => 24,    // how recent a check must be to count for re-verification.
			'hv_gateways'        => array(), // gateways still allowed while the check is outstanding.
			'hv_notice_text'     => __( 'Orders of this size need a quick identity check. Verify your ID to unlock every payment method for this order. It takes about a minute.', 'fraud-shield' ),
			'hv_reverify_text'   => __( 'For an order of this size we need to confirm your identity again, even though you have verified with us before. It takes about a minute.', 'fraud-shield' ),
			'hv_block_message'   => __( 'Orders of this value require identity verification before they can be placed. Please use the Verify my ID button at checkout, then submit your order again.', 'fraud-shield' ),

			// ID verification via Didit (v1.14): step-up government-ID check
			// offered to gateway-restricted customers. Approval (plus a name
			// match) makes them a verified credential — full payment methods,
			// exempt from the velocity lock and the new-account hold. Never
			// bypasses the blocklist. Document images never touch this site.
			'idv_enabled'        => false,
			'idv_api_key'        => '',
			'idv_workflow_id'    => '',
			'idv_webhook_secret' => '',
			'idv_name_match'     => 'lenient', // off | lenient | strict.
			// What happens when Didit approves the document but the name on it
			// does not match the customer: 'verify' completes the verification
			// anyway (and still flags the mismatch for staff), 'hold' withholds
			// verification until staff accept it.
			'idv_mismatch_action' => 'verify',
			'idv_valid_days'     => 365,       // 0 = never expires.
			'idv_max_attempts'   => 3,         // new sessions per customer per 24 h.
			'idv_notice_text'    => __( 'Verify your identity once to unlock every payment method, including credit and debit cards. It takes about a minute and only has to be done once.', 'fraud-shield' ),
			'idv_button_text'    => __( 'Verify my ID', 'fraud-shield' ),
			'idv_pending_text'   => __( 'Your ID verification is in progress. This usually takes under a minute, and this page will update automatically.', 'fraud-shield' ),
			'idv_verified_text'  => __( 'All payment methods are now available on your account.', 'fraud-shield' ),

			// Cardholder check via NMI (v1.20): after a card payment, ask the
			// gateway for the issuer's AVS/CVV verdict on that transaction and
			// bind it to the customer's ID-verification state. An ID check
			// proves the person; only the issuer can attest the card. One
			// security key per NMI account (MID), one per line, "Label|key".
			'card_check_enabled'        => false,
			'card_check_keys'           => '',
			'card_check_endpoint'       => 'https://secure.nmi.com/api/query.php',
			'card_check_gateways'       => array(), // gateway ids to check (empty = every order with a transaction id).
			'card_check_accept_partial' => false,   // count a ZIP-only / street-only AVS match as a pass.
			'card_check_action'         => 'flag',  // none | flag | hold — what a FAILED check does.
			'card_check_scope'          => 'all',   // all | idv — apply the action to every failed order, or only ID-verified customers (the stolen-card-with-real-ID case).

			// Reassurance banner at the top of checkout.
			'trust_banner'       => true,
			'trust_banner_text'  => __( 'This checkout is protected by Fraud Shield.', 'fraud-shield' ),

			// Deleting the plugin keeps its data unless this is ticked. The
			// blocklist, the order index, and completed ID verifications are
			// unrecoverable by hand; wiping them must be a decision, not a
			// side effect of the Delete button.
			'remove_data_on_uninstall' => false,
			'idv_declined_text'  => __( 'We could not verify your ID this time. You can try again, or continue with the payment methods shown.', 'fraud-shield' ),
			'idv_consent_text'   => __( 'By continuing you agree that your ID document will be processed by our verification partner Didit to confirm your identity.', 'fraud-shield' ),

			// Shown on the blocked-checkout gate when staff have demanded an ID
			// check from a specific account ("Block checkout & request ID").
			// The gate also renders the verify button, so this is a request the
			// customer can answer without contacting anyone.
			'idv_required_message' => __( 'Before you can place another order we need to confirm your identity. Verifying your ID takes about a minute and unlocks checkout straight away.', 'fraud-shield' ),

			'weights'                      => FS_Rules::default_weights(),
			'thresholds'                   => FS_Rules::default_thresholds(),
			'levels'                       => FS_Rules::default_levels(),
			'disposable_domains'           => FS_Rules::default_disposable(),
		);
	}

	/**
	 * Merged settings (stored over defaults), cached per request.
	 *
	 * @return array
	 */
	public function settings() {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}
		$defaults = self::default_settings();
		$stored   = get_option( 'fs_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged                = array_merge( $defaults, $stored );
		$merged['weights']     = array_merge( $defaults['weights'], isset( $stored['weights'] ) && is_array( $stored['weights'] ) ? $stored['weights'] : array() );
		$merged['thresholds']  = array_merge( $defaults['thresholds'], isset( $stored['thresholds'] ) && is_array( $stored['thresholds'] ) ? $stored['thresholds'] : array() );
		$merged['levels']      = array_merge( $defaults['levels'], isset( $stored['levels'] ) && is_array( $stored['levels'] ) ? $stored['levels'] : array() );
		if ( empty( $merged['disposable_domains'] ) || ! is_array( $merged['disposable_domains'] ) ) {
			$merged['disposable_domains'] = $defaults['disposable_domains'];
		}

		$this->settings_cache = $merged;
		return $merged;
	}

	/**
	 * Clear the settings cache (after a save).
	 */
	public function flush_settings() {
		$this->settings_cache = null;
	}

	/**
	 * Merchant-assigned payment-method labels: [ payment_method_string => 'card'|'other' ].
	 * A method is "card" only if explicitly labeled so (unlabeled = not card).
	 *
	 * @return array
	 */
	public static function payment_labels() {
		$labels = get_option( 'fs_payment_labels', array() );
		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * Merchant-assigned shipping-method labels: [ ship_method_string => 'expedited'|'other' ].
	 *
	 * @return array
	 */
	public static function shipping_labels() {
		$labels = get_option( 'fs_shipping_labels', array() );
		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * Whether a payment-method label is a (chargebackable) card processor.
	 *
	 * @param string $method Payment-method string as stored in the index.
	 * @return bool
	 */
	public static function is_card_gateway( $method ) {
		$method = (string) $method;
		if ( '' === $method ) {
			return false;
		}
		$labels = self::payment_labels();
		return isset( $labels[ $method ] ) && 'card' === $labels[ $method ];
	}

	/**
	 * Whether a shipping-method label is expedited / overnight.
	 *
	 * @param string $method Shipping-method string as stored in the index.
	 * @return bool
	 */
	public static function is_expedited_shipping( $method ) {
		$method = (string) $method;
		if ( '' === $method ) {
			return false;
		}
		$labels = self::shipping_labels();
		return isset( $labels[ $method ] ) && 'expedited' === $labels[ $method ];
	}
}
