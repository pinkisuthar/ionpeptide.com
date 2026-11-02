<?php
/**
 * Transparent, weighted scoring engine.
 *
 * Each rule that fires adds points and a human-readable reason. Reasons are the
 * whole point: when you dispute a chargeback or explain a hold to a customer,
 * you want "shipping address linked to 2 prior charged-back orders", not a
 * black-box number.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Rules {

	/**
	 * Default rule weights. Surfaced (and editable) in Settings.
	 *
	 * @return array
	 */
	public static function default_weights() {
		return array(
			'cb_email'          => 80, // email previously linked to a chargeback.
			'cb_phone'          => 60, // phone previously linked to a chargeback.
			'cb_ship'           => 70, // exact shipping address linked to a chargeback.
			'cb_ship_loose'     => 40, // same first address line + zip (different unit) linked to a chargeback.
			'cb_ip'             => 35, // order IP linked to a chargeback.
			'addr_multi_email'  => 25, // 3+ distinct customer emails ship to one address.
			'addr_multi_email_each' => 5, // per extra distinct email beyond the threshold (capped).
			'addr_multi_name'   => 15, // 3+ distinct names at one address.
			'email_multi_addr'  => 10, // one email shipping to many addresses.
			'ip_multi_account'  => 20, // many accounts ordering from one IP.
			'new_account'       => 15, // account younger than X days at order time.
			'velocity_7d'       => 20, // N+ orders within 7 days.
			'velocity_7d_each'  => 10, // per extra order beyond the 7-day threshold (capped).
			'velocity_30d'      => 12, // N+ orders within 30 days.
			'order_burst'       => 20, // N+ orders back-to-back within 24 hours.
			'order_burst_each'  => 25, // per extra order beyond the burst threshold (capped).
			'disposable_email'  => 20, // throwaway email domain.
			'country_mismatch'  => 10, // billing country != shipping country.
			'bill_ship_mismatch' => 20, // N+ orders where billing != shipping address.
			'card_only'         => 25, // pays exclusively via card processors (impactful, not overpowering).
			'fast_shipping'     => 15, // chose an expedited / overnight shipping method.

			// Contradictions inside an approved ID check (v1.18). The check
			// itself is a good signal; these score the parts of it that argue
			// with the rest of the customer's story.
			'idv_name_mismatch'      => 30, // approved document names someone else.
			'idv_doc_state_mismatch' => 15, // document state differs from where this order ships.
			'idv_geo_mismatch'       => 15, // verified from a different country than the order.
			'idv_vpn'                => 10, // verified from behind a VPN or Tor.
		);
	}

	/**
	 * Default tuning thresholds for the rules above.
	 *
	 * @return array
	 */
	public static function default_thresholds() {
		return array(
			'addr_email_min'   => 3,  // distinct emails at an address before flagging.
			'addr_name_min'    => 3,  // distinct names at an address before flagging.
			'email_addr_min'   => 4,  // distinct addresses for one email before flagging.
			'ip_account_min'   => 4,  // distinct accounts at one IP before flagging.
			'new_account_days' => 7,  // "new" account threshold in days.
			'velocity_7d_min'  => 3,  // orders in 7 days before flagging.
			'velocity_30d_min' => 6,  // orders in 30 days before flagging.
			'order_burst_min'  => 3,  // orders within a 24-hour window before flagging.
			'cap_addr_email'   => 40, // cap on the cumulative addr_multi_email_each bonus.
			'cap_velocity_7d'  => 40, // cap on the cumulative velocity_7d_each bonus.
			'cap_order_burst'  => 60, // cap on the cumulative order_burst_each bonus.
			'bill_ship_min'    => 2,  // orders with billing != shipping before flagging.
			'card_only_min'    => 2,  // counted orders required before the card-only pattern flags.
		);
	}

	/**
	 * Default risk-level cutoffs (score -> level).
	 *
	 * @return array
	 */
	public static function default_levels() {
		return array(
			'medium' => 40,
			'high'   => 70,
			'block'  => 110,
		);
	}

	/**
	 * Default disposable email domains. Editable in Settings.
	 *
	 * @return array
	 */
	public static function default_disposable() {
		return array(
			'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'sharklasers.com',
			'10minutemail.com', '10minutemail.net', 'tempmail.com', 'temp-mail.org',
			'yopmail.com', 'trashmail.com', 'getnada.com', 'nada.email', 'dispostable.com',
			'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mailnesia.com',
			'mohmal.com', 'emailondeck.com', 'mintemail.com', 'spamgourmet.com',
			'mytemp.email', 'discard.email', 'inboxbear.com', 'tempinbox.com',
			'fakemail.net', 'tempr.email', 'moakt.com', 'luxusmail.org', 'burnermail.io',
		);
	}

	/**
	 * Whether a domain is in the configured disposable list.
	 *
	 * @param string $domain Email domain.
	 * @return bool
	 */
	public static function is_disposable_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		if ( '' === $domain ) {
			return false;
		}
		$settings = FS_Core::instance()->settings();
		$list     = $settings['disposable_domains'];
		return in_array( $domain, $list, true );
	}

	/**
	 * Score a profile.
	 *
	 * @param array $profile  Output of FS_Data::build_profile().
	 * @param array $settings Plugin settings (weights/thresholds/levels).
	 * @return array { score:int, level:string, reasons:string[], rules:string[] }
	 */
	public static function score_profile( $profile, $settings ) {
		$w       = $settings['weights'];
		$th      = $settings['thresholds'];
		$score   = 0;
		$reasons = array();
		$rules   = array();

		$add = function ( $key, $points, $reason ) use ( &$score, &$reasons, &$rules ) {
			$points = (int) $points;
			if ( $points <= 0 ) {
				return;
			}
			$score    += $points;
			$reasons[] = $reason;
			$rules[]   = $key;
		};

		// --- Confirmed chargeback links (strongest signals) ---
		if ( ! empty( $profile['chargeback_email'] ) ) {
			$add( 'cb_email', $w['cb_email'], __( 'Email was used on a prior charged-back order', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['chargeback_ship'] ) ) {
			$add( 'cb_ship', $w['cb_ship'], __( 'Shipping address was used on a prior charged-back order', 'fraud-shield' ) );
		} elseif ( ! empty( $profile['chargeback_ship_loose'] ) ) {
			$add( 'cb_ship_loose', $w['cb_ship_loose'], __( 'Same street/ZIP (different unit) was used on a prior charged-back order', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['chargeback_phone'] ) ) {
			$add( 'cb_phone', $w['cb_phone'], __( 'Phone number was used on a prior charged-back order', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['chargeback_ip'] ) ) {
			$add( 'cb_ip', $w['cb_ip'], __( 'Order IP was used on a prior charged-back order', 'fraud-shield' ) );
		}

		// --- Address clustering ---
		$emails_at = (int) ( $profile['emails_at_address'] ?? 1 );
		if ( $emails_at >= (int) $th['addr_email_min'] ) {
			$bonus = min( (int) $th['cap_addr_email'], ( $emails_at - (int) $th['addr_email_min'] ) * (int) $w['addr_multi_email_each'] );
			$add(
				'addr_multi_email',
				(int) $w['addr_multi_email'] + $bonus,
				/* translators: %d: number of distinct customer emails */
				sprintf( _n( 'Shipping address is shared by %d different customer email', 'Shipping address is shared by %d different customer emails', $emails_at, 'fraud-shield' ), $emails_at )
			);
		}

		$names_at = (int) ( $profile['names_at_address'] ?? 1 );
		if ( $names_at >= (int) $th['addr_name_min'] ) {
			/* translators: %d: number of distinct customer names */
			$add( 'addr_multi_name', $w['addr_multi_name'], sprintf( _n( 'Shipping address is shared by %d different customer name', 'Shipping address is shared by %d different customer names', $names_at, 'fraud-shield' ), $names_at ) );
		}

		$addr_for_email = (int) ( $profile['addresses_for_email'] ?? 1 );
		if ( $addr_for_email >= (int) $th['email_addr_min'] ) {
			/* translators: %d: number of distinct shipping addresses */
			$add( 'email_multi_addr', $w['email_multi_addr'], sprintf( _n( 'This email has shipped to %d different address', 'This email has shipped to %d different addresses', $addr_for_email, 'fraud-shield' ), $addr_for_email ) );
		}

		$accts_at_ip = (int) ( $profile['accounts_at_ip'] ?? 0 );
		if ( $accts_at_ip >= (int) $th['ip_account_min'] ) {
			/* translators: %d: number of distinct customer emails */
			$add( 'ip_multi_account', $w['ip_multi_account'], sprintf( _n( '%d different customer email has ordered from this IP', '%d different customer emails have ordered from this IP', $accts_at_ip, 'fraud-shield' ), $accts_at_ip ) );
		}

		// --- Newness + velocity ---
		$age = $profile['account_age_days'];
		if ( null !== $age && $age <= (int) $th['new_account_days'] ) {
			/* translators: %d: number of days */
			$add( 'new_account', $w['new_account'], sprintf( _n( 'Customer first seen %d day before this order', 'Customer first seen %d days before this order', (int) $age, 'fraud-shield' ), (int) $age ) );
		}

		// Velocity escalates with volume (v1.31). A flat weight scored six
		// orders in a day exactly like three, and six orders in a day never
		// reached Block on velocity alone — which is how a same-day spree to
		// one address stayed at MEDIUM/HIGH. Each order beyond the threshold
		// now adds points, capped, the way addr_multi_email already did.
		$o7 = (int) ( $profile['orders_7d'] ?? 0 );
		if ( $o7 >= (int) $th['velocity_7d_min'] ) {
			$bonus = min( (int) ( $th['cap_velocity_7d'] ?? 0 ), ( $o7 - (int) $th['velocity_7d_min'] ) * (int) ( $w['velocity_7d_each'] ?? 0 ) );
			/* translators: %d: number of prior orders */
			$add( 'velocity_7d', (int) $w['velocity_7d'] + $bonus, sprintf( _n( '%d prior order in the last 7 days', '%d prior orders in the last 7 days', $o7, 'fraud-shield' ), $o7 ) );
		} elseif ( (int) ( $profile['orders_30d'] ?? 0 ) >= (int) $th['velocity_30d_min'] ) {
			/* translators: %d: number of prior orders */
			$add( 'velocity_30d', $w['velocity_30d'], sprintf( _n( '%d prior order in the last 30 days', '%d prior orders in the last 30 days', (int) $profile['orders_30d'], 'fraud-shield' ), (int) $profile['orders_30d'] ) );
		}

		// Back-to-back orders within a 24-hour window (separate from the 7/30-day
		// velocity above — this catches rapid bursts), escalating the same way.
		$ob = (int) ( $profile['orders_burst'] ?? 0 );
		if ( isset( $th['order_burst_min'] ) && $ob >= (int) $th['order_burst_min'] ) {
			$bonus = min( (int) ( $th['cap_order_burst'] ?? 0 ), ( $ob - (int) $th['order_burst_min'] ) * (int) ( $w['order_burst_each'] ?? 0 ) );
			/* translators: %d: number of orders */
			$add( 'order_burst', (int) $w['order_burst'] + $bonus, sprintf( _n( '%d order within 24 hours (rapid succession)', '%d orders within 24 hours (rapid succession)', $ob, 'fraud-shield' ), $ob ) );
		}

		// --- Heuristics ---
		if ( ! empty( $profile['disposable_email'] ) ) {
			$add( 'disposable_email', $w['disposable_email'], __( 'Disposable / throwaway email domain', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['country_mismatch'] ) ) {
			$add( 'country_mismatch', $w['country_mismatch'], __( 'Billing country does not match shipping country', 'fraud-shield' ) );
		}

		// Billing vs shipping divergence (count of the identity's orders that
		// shipped somewhere other than the billing address).
		$mismatch = (int) ( $profile['bill_ship_mismatch'] ?? 0 );
		if ( isset( $th['bill_ship_min'] ) && $mismatch >= (int) $th['bill_ship_min'] ) {
			/* translators: %d: number of orders */
			$add( 'bill_ship_mismatch', $w['bill_ship_mismatch'], sprintf( _n( '%d order shipped to a different address than billing', '%d orders shipped to a different address than billing', $mismatch, 'fraud-shield' ), $mismatch ) );
		}

		// Pays exclusively via (chargebackable) card processors — the population
		// most capable of a chargeback. Only fires once the merchant has labeled
		// gateways and the customer has enough counted orders to be a pattern.
		if ( ! empty( $profile['pay_card_only'] ) && isset( $th['card_only_min'] ) && (int) ( $profile['pay_card_orders'] ?? 0 ) >= (int) $th['card_only_min'] ) {
			/* translators: %d: number of orders */
			$add( 'card_only', $w['card_only'], sprintf( _n( 'Pays exclusively with a card processor (%d order)', 'Pays exclusively with card processors (%d orders)', (int) $profile['pay_card_orders'], 'fraud-shield' ), (int) $profile['pay_card_orders'] ) );
		}

		// Chose an expedited / overnight shipping method on this order.
		if ( ! empty( $profile['fast_shipping'] ) ) {
			$add( 'fast_shipping', $w['fast_shipping'], __( 'Chose an expedited / overnight shipping method', 'fraud-shield' ) );
		}

		// --- ID verification contradictions (only present when the customer has
		// an approved Didit check on file; see FS_Data::build_profile) ---
		if ( ! empty( $profile['idv_name_mismatch'] ) ) {
			$add( 'idv_name_mismatch', $w['idv_name_mismatch'] ?? 0, __( 'Approved ID document names a different person than this customer', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['idv_doc_state_mismatch'] ) ) {
			$add( 'idv_doc_state_mismatch', $w['idv_doc_state_mismatch'] ?? 0, __( 'ID document is from a different state than this order ships to', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['idv_geo_mismatch'] ) ) {
			$add( 'idv_geo_mismatch', $w['idv_geo_mismatch'] ?? 0, __( 'ID verification was performed from a different country than this order', 'fraud-shield' ) );
		}
		if ( ! empty( $profile['idv_vpn'] ) ) {
			$add( 'idv_vpn', $w['idv_vpn'] ?? 0, __( 'ID verification was performed over a VPN or Tor connection', 'fraud-shield' ) );
		}

		return array(
			'score'   => (int) $score,
			'level'   => self::level_for_score( $score, $settings ),
			'reasons' => $reasons,
			'rules'   => $rules,
		);
	}

	/**
	 * Map a numeric score to a level key.
	 *
	 * @param int   $score    Score.
	 * @param array $settings Settings.
	 * @return string low|medium|high|block
	 */
	public static function level_for_score( $score, $settings ) {
		$l = $settings['levels'];
		if ( $score >= (int) $l['block'] ) {
			return 'block';
		}
		if ( $score >= (int) $l['high'] ) {
			return 'high';
		}
		if ( $score >= (int) $l['medium'] ) {
			return 'medium';
		}
		return 'low';
	}
}
