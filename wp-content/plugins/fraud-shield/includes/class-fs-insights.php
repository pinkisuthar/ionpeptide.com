<?php
/**
 * Insights (v1.26): the numbers behind the Financial Protection page and the
 * Customer Network page.
 *
 * Financial Protection keeps four measurements apart and never lets one
 * masquerade as another:
 *
 *  - Historical losses     OBSERVED  recorded chargebacks, net of recorded
 *                                    recoveries, plus fees when configured.
 *  - Blocked value         OBSERVED  the money on checkouts a verdict stopped.
 *                                    Not savings: some of it was never fraud.
 *  - Losses prevented      ESTIMATE  blocked value weighted by how often
 *                                    customers like these actually charged
 *                                    back, from this store's own outcomes.
 *  - Annual protection     FORECAST  what currently blocked customers cost
 *                                    per year while they were active, so what
 *                                    the block avoids if that behaviour would
 *                                    have continued.
 *
 * Every figure names its evidence and its assumptions, and anything the store
 * has not recorded is reported as unavailable (null) rather than as zero.
 *
 * The Customer Network reads the same order index the risk engine reads and
 * describes who shares what with whom — strength, evidence, recency — without
 * ever deciding anything on its own: blocking stays a human click on the
 * existing Block toggle.
 *
 * @package FraudShield
 */

defined( 'ABSPATH' ) || exit;

class FS_Insights {

	/**
	 * Shrinkage weight for a customer's own chargeback rate: their history is
	 * blended with the class rate as if the class rate had been observed on
	 * this many extra orders, so one chargeback on one order does not read
	 * as a 100% certainty and one clean order does not read as 0%.
	 */
	const SHRINK_ORDERS = 3;

	/**
	 * Settled orders a calibration class needs before its rate is trusted
	 * over a broader class.
	 */
	const CLASS_MIN_ORDERS = 20;

	/**
	 * Rows read per customer when gathering their credentials.
	 */
	const SELF_ROWS = 500;

	/**
	 * Neighbour rows examined per credential column when exploring one
	 * customer's network.
	 */
	const NEIGHBOUR_ROWS = 500;

	/**
	 * Distinct values of one credential type carried into a neighbour query.
	 */
	const VALUES_CAP = 200;

	/**
	 * Identities the second hop of a network exploration may examine.
	 */
	const HOP2_CAP = 60;

	/**
	 * Controls whose stops count as fraud prevented (a verdict), in the
	 * fraud-prevented ledger.
	 */
	const VERDICT_KINDS = array( 'blocklist', 'linked', 'score' );

	/**
	 * Days of observed activity behind a projection's confidence grade:
	 * medium needs this long (about three months) with 2+ chargebacks.
	 */
	const CONFIDENCE_MEDIUM_DAYS = 90;

	/**
	 * Days of observed activity for a high grade (about a year) with 3+
	 * chargebacks.
	 */
	const CONFIDENCE_HIGH_DAYS = 365;

	/**
	 * Days in a mean calendar month, for turning leftover days into a
	 * fraction of a month.
	 */
	const DAYS_PER_MONTH = 30.44;

	/* ===================================================================== *
	 *  Shared helpers
	 * ===================================================================== */

	/**
	 * Site-local bounds for "the last N days" ('' = open).
	 *
	 * @param int $days Days back; 0 = all time.
	 * @return array { from:string, to:string, days:int, label:string }
	 */
	public static function period( $days ) {
		$days = (int) $days;
		$now  = strtotime( current_time( 'mysql' ) );
		if ( $days <= 0 ) {
			return array(
				'from'  => '',
				'to'    => '',
				'days'  => 0,
				'label' => __( 'All time', 'fraud-shield' ),
			);
		}
		return array(
			'from'  => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS ),
			'to'    => '',
			'days'  => $days,
			/* translators: %d: number of days */
			'label' => sprintf( __( 'Last %d days', 'fraud-shield' ), $days ),
		);
	}

	/**
	 * WHERE fragment for a date window on a column.
	 *
	 * @param string $from   Lower bound ('' = none).
	 * @param string $to     Upper bound ('' = none).
	 * @param array  $params Placeholders, appended.
	 * @param string $col    Column name (trusted, from code).
	 * @return string Starts with ' AND ' or is ''.
	 */
	private static function window_sql( $from, $to, &$params, $col = 'order_date' ) {
		$sql = '';
		if ( '' !== (string) $from ) {
			$sql     .= " AND {$col} >= %s";
			$params[] = (string) $from;
		}
		if ( '' !== (string) $to ) {
			$sql     .= " AND {$col} <= %s";
			$params[] = (string) $to;
		}
		return $sql;
	}

	/**
	 * How far apart two dates are, as a person counts it: whole calendar
	 * months plus the leftover days as a fraction of a month, and the plain
	 * day count. "June 22 to September 22" is exactly 3.0 months (92 days);
	 * "June 27 to September 25" is 2 months and 29 days, 2.95.
	 *
	 * @param string $from Earlier datetime ('Y-m-d H:i:s').
	 * @param string $to   Later datetime.
	 * @return array { months:float, days:float } both 0 when the order is wrong or a date is missing.
	 */
	public static function span_months( $from, $to ) {
		$a = date_create( (string) $from );
		$b = date_create( (string) $to );
		if ( ! $a || ! $b || $b <= $a ) {
			return array(
				'months' => 0.0,
				'days'   => 0.0,
			);
		}
		$d = $a->diff( $b );
		return array(
			'months' => $d->y * 12 + $d->m + ( $d->d + $d->h / 24 ) / self::DAYS_PER_MONTH,
			'days'   => ( $b->getTimestamp() - $a->getTimestamp() ) / DAY_IN_SECONDS,
		);
	}

	/**
	 * The merchant-supplied assumptions the money figures depend on.
	 *
	 * @return array { fee:float|null, loss_ratio:float (0..1), min_window_days:int, currency:string }
	 */
	public static function assumptions() {
		$s   = FS_Core::instance()->settings();
		$fee = (float) ( $s['fin_chargeback_fee'] ?? 0 );
		return array(
			'fee'             => $fee > 0 ? $fee : null,
			'loss_ratio'      => min( 1.0, max( 0.01, (int) ( $s['fin_loss_ratio'] ?? 100 ) / 100 ) ),
			'min_window_days' => min( 365, max( 30, (int) ( $s['fin_min_window_days'] ?? 90 ) ) ),
			'currency'        => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
		);
	}

	/**
	 * How far back the order index reaches, and how much it holds — the
	 * first thing every observed figure depends on.
	 *
	 * @return array { orders:int, first_order:string|null, last_order:string|null, chargebacks:int }
	 */
	public static function coverage() {
		global $wpdb;
		$t   = FS_Data::index_table();
		$row = $wpdb->get_row( "SELECT COUNT(*) orders, MIN(order_date) first_order, MAX(order_date) last_order, COALESCE(SUM(is_chargeback),0) chargebacks FROM {$t}", ARRAY_A ); // phpcs:ignore
		return array(
			'orders'      => (int) ( $row['orders'] ?? 0 ),
			'first_order' => ! empty( $row['first_order'] ) ? (string) $row['first_order'] : null,
			'last_order'  => ! empty( $row['last_order'] ) ? (string) $row['last_order'] : null,
			'chargebacks' => (int) ( $row['chargebacks'] ?? 0 ),
		);
	}

	/**
	 * SQL expression naming one customer identity on an index row: the
	 * account when there is one, else the email. The same expression is used
	 * on every table so identities line up across the ledgers.
	 *
	 * @param string $alias Table alias.
	 * @return string
	 */
	private static function ident_sql( $alias ) {
		return "CASE WHEN {$alias}.user_id > 0 THEN CONCAT('u:', {$alias}.user_id) ELSE CONCAT('e:', COALESCE({$alias}.email_norm, '')) END";
	}

	/**
	 * Account ids on the blocklist, as integers, once per request. Account
	 * entries are stored as strings; comparing them to the index's integer
	 * column inside SQL means a CAST, and a CAST takes the connection's
	 * collation rather than the table's — which some hosts refuse to compare.
	 * A short integer IN-list has neither problem and uses the index.
	 *
	 * @return array
	 */
	private static function blocked_user_ids() {
		static $ids = null;
		if ( null === $ids ) {
			global $wpdb;
			$ids = array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( "SELECT value FROM " . FS_Data::blocklist_table() . " WHERE type = 'user' LIMIT 5000" ) ) ) ); // phpcs:ignore
		}
		return $ids;
	}

	/**
	 * Join fragments marking index rows whose identity is on the blocklist by
	 * email or by account.
	 *
	 * @param string $alias Index alias.
	 * @return array { join:string, hit:string } hit is a boolean SQL expression.
	 */
	private static function blocked_join_sql( $alias ) {
		$b     = FS_Data::blocklist_table();
		$users = self::blocked_user_ids();
		$hit   = 'fbe.id IS NOT NULL';
		if ( ! empty( $users ) ) {
			$hit .= " OR {$alias}.user_id IN (" . implode( ',', $users ) . ')';
		}
		return array(
			'join' => " LEFT JOIN {$b} fbe ON fbe.type = 'email' AND fbe.value = {$alias}.email_norm",
			'hit'  => '(' . $hit . ')',
		);
	}

	/* ===================================================================== *
	 *  1. Historical losses (observed)
	 * ===================================================================== */

	/**
	 * Recorded chargeback losses in a window, with what is known about fees
	 * and recoveries.
	 *
	 * @param array $period Output of period().
	 * @return array
	 */
	public static function losses( $period ) {
		global $wpdb;
		$t    = FS_Data::index_table();
		$a    = self::assumptions();
		$paid = FS_Data::paid_in_sql();

		$params = array();
		$win    = self::window_sql( $period['from'], $period['to'], $params );
		$ident  = self::ident_sql( 'i' );
		$sql    = "SELECT COUNT(*) cb_orders,
				COALESCE(SUM(order_total), 0) gross,
				COALESCE(SUM(recovered), 0) recovered,
				SUM(CASE WHEN recovered > 0 THEN 1 ELSE 0 END) recovered_orders,
				COUNT(DISTINCT {$ident}) identities,
				SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) refunded_as_cb
			FROM {$t} i WHERE is_chargeback = 1{$win}";
		$cb     = $wpdb->get_row( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		// Settled = money moved: paid, or charged back (a chargeback WAS a paid
		// order). One pass with the two told apart by is_chargeback, so an
		// order flagged as charged back while keeping its paid status counts
		// once — the same rule calibration() applies, so the two panels agree.
		$params2 = array();
		$win2    = self::window_sql( $period['from'], $period['to'], $params2 );
		$sql2    = "SELECT SUM(CASE WHEN is_chargeback = 0 THEN 1 ELSE 0 END) paid_orders,
				COALESCE(SUM(CASE WHEN is_chargeback = 0 THEN order_total ELSE 0 END), 0) paid_amount,
				COUNT(*) settled_orders,
				COALESCE(SUM(order_total), 0) settled_amount
			FROM {$t} WHERE (status IN ({$paid}) OR is_chargeback = 1){$win2}";
		$pd      = $wpdb->get_row( empty( $params2 ) ? $sql2 : $wpdb->prepare( $sql2, $params2 ), ARRAY_A ); // phpcs:ignore

		$cb_orders   = (int) ( $cb['cb_orders'] ?? 0 );
		$gross       = (float) ( $cb['gross'] ?? 0 );
		$recovered   = (float) ( $cb['recovered'] ?? 0 );
		$paid_orders = (int) ( $pd['paid_orders'] ?? 0 );
		$paid_amount = (float) ( $pd['paid_amount'] ?? 0 );
		$fees        = null === $a['fee'] ? null : $cb_orders * $a['fee'];
		$settled_n   = (int) ( $pd['settled_orders'] ?? 0 );
		$settled_amt = (float) ( $pd['settled_amount'] ?? 0 );

		return array(
			'cb_orders'        => $cb_orders,
			'gross'            => $gross,
			'recovered'        => $recovered,
			'recovered_orders' => (int) ( $cb['recovered_orders'] ?? 0 ),
			'identities'       => (int) ( $cb['identities'] ?? 0 ),
			'refunded_as_cb'   => (int) ( $cb['refunded_as_cb'] ?? 0 ),
			'fees'             => $fees,
			'net'              => $gross - $recovered + ( null === $fees ? 0.0 : $fees ),
			'net_has_fees'     => null !== $fees,
			'paid_orders'      => $paid_orders,
			'paid_amount'      => $paid_amount,
			'rate_orders'      => $settled_n > 0 ? $cb_orders / $settled_n : null,
			'rate_amount'      => $settled_amt > 0 ? $gross / $settled_amt : null,
			'counts_refunds'   => ! empty( FS_Core::instance()->settings()['count_refunded_as_chargeback'] ),
		);
	}

	/**
	 * Charged-back losses per customer identity, largest first.
	 *
	 * @param array $period Output of period().
	 * @param int   $limit  Rows.
	 * @return array
	 */
	public static function loss_breakdown( $period, $limit = 25 ) {
		global $wpdb;
		$t      = FS_Data::index_table();
		$bj     = self::blocked_join_sql( 'i' );
		$ident  = self::ident_sql( 'i' );
		$params = array();
		$win    = self::window_sql( $period['from'], $period['to'], $params, 'i.order_date' );
		$params[] = (int) $limit;
		$rows   = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT {$ident} ident, MAX(i.email_norm) email_norm, MAX(i.user_id) user_id, MAX(i.customer_name) customer_name,
					COUNT(*) cb_orders, COALESCE(SUM(i.order_total), 0) gross, COALESCE(SUM(i.recovered), 0) recovered,
					MIN(i.order_date) first_cb, MAX(i.order_date) last_cb,
					MAX(CASE WHEN {$bj['hit']} THEN 1 ELSE 0 END) blocked
				FROM {$t} i{$bj['join']}
				WHERE i.is_chargeback = 1{$win}
				GROUP BY ident ORDER BY gross DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/* ===================================================================== *
	 *  2. Blocked transaction value (observed)
	 * ===================================================================== */

	/**
	 * Per-identity value of the checkouts a VERDICT stopped in a window, from
	 * the fraud-prevented ledger, with later-approved orders set aside.
	 *
	 * A cancelled order whose status has since moved to a paid state was
	 * approved by staff after the fact — it was not fraud, and it is excluded
	 * from every figure (and reported, so the exclusion is visible). Money the
	 * verification controls held back is summarised separately and never
	 * counted: those customers may still complete their purchase.
	 *
	 * Each row also carries `linked_to` — the blocked identities the
	 * customer's stops were tied to (the person behind the shared address,
	 * phone or account, or behind the matched blocklist entry), merged across
	 * their rows — and `staff_n`, how many of their orders a person cancelled
	 * or refunded rather than a control (v1.30).
	 *
	 * @param array $period Output of period().
	 * @return array { rows:array, totals:array, verify:array }
	 */
	public static function blocked_value( $period ) {
		global $wpdb;
		$p     = FS_Data::prevented_table();
		$t     = FS_Data::index_table();
		$paid  = FS_Data::paid_in_sql();
		$kinds = "'" . implode( "','", array_map( 'esc_sql', self::VERDICT_KINDS ) ) . "'";
		$ident = self::ident_sql( 'p' );

		$params = array();
		$win    = self::window_sql( $period['from'], $period['to'], $params, 'p.first_at' );
		$sql    = "SELECT {$ident} ident, MAX(p.user_id) user_id, MAX(p.email_norm) email_norm, MAX(p.customer_name) customer_name,
				SUM(CASE WHEN p.stage = 'cancelled' AND (i.status IS NULL OR i.status NOT IN ({$paid})) THEN p.amount ELSE 0 END) cancelled_amount,
				SUM(CASE WHEN p.stage = 'cancelled' AND (i.status IS NULL OR i.status NOT IN ({$paid})) THEN 1 ELSE 0 END) cancelled_n,
				SUM(CASE WHEN p.stage = 'cancelled' AND i.status IN ({$paid}) THEN p.amount ELSE 0 END) approved_amount,
				SUM(CASE WHEN p.stage = 'cancelled' AND i.status IN ({$paid}) THEN 1 ELSE 0 END) approved_n,
				SUM(CASE WHEN p.stage = 'refused' THEN p.amount ELSE 0 END) refused_amount,
				SUM(CASE WHEN p.stage = 'refused' THEN 1 ELSE 0 END) refused_n,
				SUM(CASE WHEN p.stage = 'refused' OR i.status IS NULL OR i.status NOT IN ({$paid}) THEN p.amount ELSE 0 END) stopped_value,
				SUM(CASE WHEN p.stopped_by = 'staff' AND (i.status IS NULL OR i.status NOT IN ({$paid})) THEN 1 ELSE 0 END) staff_n,
				SUM(CASE WHEN p.stopped_by = 'staff' AND (i.status IS NULL OR i.status NOT IN ({$paid})) THEN p.amount ELSE 0 END) staff_amount,
				COALESCE(SUM(p.attempts), 0) attempts,
				GROUP_CONCAT(DISTINCT p.kind ORDER BY p.kind SEPARATOR ',') kinds,
				MAX(p.last_at) last_at
			FROM {$p} p LEFT JOIN {$t} i ON p.order_id > 0 AND i.order_id = p.order_id
			WHERE p.kind IN ({$kinds}){$win}
			GROUP BY ident ORDER BY stopped_value DESC";
		$rows   = (array) $wpdb->get_results( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		// Whom each identity's stops were tied to, merged across their rows
		// (a second, narrow pass: JSON does not aggregate in SQL).
		$params_l = array();
		$win_l    = self::window_sql( $period['from'], $period['to'], $params_l, 'p.first_at' );
		$sql_l    = "SELECT {$ident} ident, p.linked_to FROM {$p} p WHERE p.kind IN ({$kinds}) AND p.linked_to IS NOT NULL AND p.linked_to <> ''{$win_l} ORDER BY p.last_at DESC, p.id DESC";
		$tied     = array();
		foreach ( (array) $wpdb->get_results( empty( $params_l ) ? $sql_l : $wpdb->prepare( $sql_l, $params_l ), ARRAY_A ) as $lr ) { // phpcs:ignore
			$decoded = json_decode( (string) $lr['linked_to'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$key = (string) $lr['ident'];
			if ( ! isset( $tied[ $key ] ) ) {
				$tied[ $key ] = array();
			}
			$tied[ $key ] = array_merge( $tied[ $key ], array_values( $decoded ) );
		}

		$totals = array(
			'value'         => 0.0,
			'events'        => 0,
			'attempts'      => 0,
			'identities'    => 0,
			'cancelled_n'   => 0,
			'refused_n'     => 0,
			'approved_n'    => 0,
			'approved_amt'  => 0.0,
			'staff_n'       => 0,
			'staff_amount'  => 0.0,
			'by_kind'       => array(),
		);
		foreach ( $rows as &$r ) {
			$r['value']              = (float) $r['cancelled_amount'] + (float) $r['refused_amount'];
			$r['events']             = (int) $r['cancelled_n'] + (int) $r['refused_n'];
			$r['linked_to']          = FS_Data::prevented_identities( $tied[ (string) $r['ident'] ] ?? array() );
			$totals['value']        += $r['value'];
			$totals['events']       += $r['events'];
			$totals['attempts']     += (int) $r['attempts'];
			$totals['cancelled_n']  += (int) $r['cancelled_n'];
			$totals['refused_n']    += (int) $r['refused_n'];
			$totals['approved_n']   += (int) $r['approved_n'];
			$totals['approved_amt'] += (float) $r['approved_amount'];
			$totals['staff_n']      += (int) $r['staff_n'];
			$totals['staff_amount'] += (float) $r['staff_amount'];
			if ( $r['events'] > 0 ) {
				$totals['identities']++;
			}
		}
		unset( $r );

		// Per control, same exclusion rule.
		$params_k = array();
		$win_k    = self::window_sql( $period['from'], $period['to'], $params_k, 'p.first_at' );
		$sql_k    = "SELECT p.kind,
				SUM(CASE WHEN p.stage = 'refused' OR i.status IS NULL OR i.status NOT IN ({$paid}) THEN p.amount ELSE 0 END) amount,
				SUM(CASE WHEN p.stage = 'refused' OR i.status IS NULL OR i.status NOT IN ({$paid}) THEN 1 ELSE 0 END) events
			FROM {$p} p LEFT JOIN {$t} i ON p.order_id > 0 AND i.order_id = p.order_id
			WHERE 1=1{$win_k} GROUP BY p.kind";
		foreach ( (array) $wpdb->get_results( empty( $params_k ) ? $sql_k : $wpdb->prepare( $sql_k, $params_k ), ARRAY_A ) as $k ) { // phpcs:ignore
			$totals['by_kind'][ (string) $k['kind'] ] = array(
				'amount' => (float) $k['amount'],
				'events' => (int) $k['events'],
			);
		}

		// Verification holds: shown, never counted.
		$verify = array(
			'amount' => 0.0,
			'events' => 0,
		);
		foreach ( FS_Data::prevented_kinds() as $kind => $meta ) {
			if ( 'verify' === $meta['group'] && isset( $totals['by_kind'][ $kind ] ) ) {
				$verify['amount'] += $totals['by_kind'][ $kind ]['amount'];
				$verify['events'] += $totals['by_kind'][ $kind ]['events'];
			}
		}

		return array(
			'rows'   => $rows,
			'totals' => $totals,
			'verify' => $verify,
		);
	}

	/* ===================================================================== *
	 *  3. Estimated losses prevented (estimate)
	 * ===================================================================== */

	/**
	 * Observed chargeback rates for the classes an estimate can calibrate
	 * against, all time, most specific first:
	 *
	 *  blocked — orders placed by identities now on the blocklist,
	 *  risky   — orders the risk engine scored HIGH or BLOCK,
	 *  store   — every settled order.
	 *
	 * "Settled" = paid or charged back; pending, failed and cancelled orders
	 * have no outcome and are left out of every denominator.
	 *
	 * @return array [ class => { settled:int, chargebacks:int, rate:float|null } ]
	 */
	public static function calibration() {
		global $wpdb;
		$t    = FS_Data::index_table();
		$paid = FS_Data::paid_in_sql();
		$bj   = self::blocked_join_sql( 'i' );

		$q = function ( $where, $join = '' ) use ( $wpdb, $t, $paid ) {
			$row = $wpdb->get_row( "SELECT SUM(CASE WHEN i.status IN ({$paid}) OR i.is_chargeback = 1 THEN 1 ELSE 0 END) settled, COALESCE(SUM(i.is_chargeback), 0) chargebacks FROM {$t} i{$join} WHERE {$where}", ARRAY_A ); // phpcs:ignore
			$settled = (int) ( $row['settled'] ?? 0 );
			$cbs     = (int) ( $row['chargebacks'] ?? 0 );
			return array(
				'settled'     => $settled,
				'chargebacks' => $cbs,
				'rate'        => $settled > 0 ? $cbs / $settled : null,
			);
		};

		return array(
			'blocked' => $q( $bj['hit'], $bj['join'] ),
			'risky'   => $q( "i.risk_level IN ('high','block')" ),
			'store'   => $q( '1=1' ),
		);
	}

	/**
	 * Which calibration class an estimate should borrow its prior from: the
	 * most specific class with enough settled orders and at least one
	 * chargeback. Null when the store has nothing to calibrate against.
	 *
	 * @param array $cal Output of calibration().
	 * @return array|null { class:string, rate:float, settled:int, chargebacks:int }
	 */
	public static function prior( $cal ) {
		foreach ( array( 'blocked', 'risky', 'store' ) as $class ) {
			$c = $cal[ $class ];
			if ( $c['settled'] >= self::CLASS_MIN_ORDERS && $c['chargebacks'] > 0 && null !== $c['rate'] ) {
				return array(
					'class'       => $class,
					'rate'        => $c['rate'],
					'settled'     => $c['settled'],
					'chargebacks' => $c['chargebacks'],
				);
			}
		}
		return null;
	}

	/**
	 * Estimated losses avoided on the checkouts a verdict stopped.
	 *
	 * For each stopped identity, the probability that their order would have
	 * become a chargeback is the observed rate on the history BEHIND the
	 * stop, shrunk towards the class rate (see SHRINK_ORDERS). That history
	 * is the customer's own settled orders — and, when the stop was tied to a
	 * blocked identity (the person behind the shared address, phone or
	 * account the linked rule found, or behind the blocklist entry that
	 * matched), that identity's settled orders as well. A customer stopped
	 * for shipping to a charged-back fraudster's door is otherwise a
	 * first-timer with no history at all, and the very reason they were
	 * stopped would count for nothing (v1.30). Two figures are produced:
	 *
	 *  conservative — only stops with at least one recorded chargeback behind
	 *                 them, at the raw rate of that history (no borrowed prior);
	 *  expected     — every stop, its history blended with the class prior;
	 *                 stops with no history at all use the prior alone.
	 *
	 * Expected loss per stopped checkout = value x probability x loss ratio,
	 * plus the chargeback fee x probability when a fee is configured. Money
	 * on identities that cannot be calibrated at all (no history, no usable
	 * class) is reported as uncalibrated, not as zero and not as saved.
	 *
	 * @param array      $period Output of period().
	 * @param array|null $bv     Output of blocked_value() for the same period, when the caller already has it.
	 * @return array
	 */
	public static function prevented_estimate( $period, $bv = null ) {
		global $wpdb;
		$t     = FS_Data::index_table();
		$paid  = FS_Data::paid_in_sql();
		$a     = self::assumptions();
		$bv    = is_array( $bv ) ? $bv : self::blocked_value( $period );
		$cal   = self::calibration();
		$prior = self::prior( $cal );

		$rows = $bv['rows'];
		// History for every stopped identity AND for every identity a stop was
		// tied to, in one query per identity form. An identity is an account
		// when it has one, else an email — the same key ident_sql() builds.
		$users  = array();
		$emails = array();
		$tied   = array(); // ident => [ tied ident keys ]
		foreach ( $rows as $r ) {
			if ( (int) $r['user_id'] > 0 ) {
				$users[] = (int) $r['user_id'];
			} elseif ( '' !== (string) $r['email_norm'] ) {
				$emails[] = (string) $r['email_norm'];
			}
			$tied[ (string) $r['ident'] ] = array();
			foreach ( (array) ( $r['linked_to'] ?? array() ) as $id ) {
				$uid   = (int) ( $id['user_id'] ?? 0 );
				$email = (string) ( $id['email'] ?? '' );
				if ( $uid > 0 ) {
					$key     = 'u:' . $uid;
					$users[] = $uid;
				} elseif ( '' !== $email ) {
					$key      = 'e:' . $email;
					$emails[] = $email;
				} else {
					continue;
				}
				if ( $key !== (string) $r['ident'] ) {
					$tied[ (string) $r['ident'] ][ $key ] = true;
				}
			}
		}
		$history = array();
		if ( ! empty( $users ) ) {
			$in   = implode( ',', array_map( 'intval', array_unique( $users ) ) );
			$hist = $wpdb->get_results( "SELECT CONCAT('u:', user_id) ident, SUM(CASE WHEN status IN ({$paid}) OR is_chargeback = 1 THEN 1 ELSE 0 END) settled, COALESCE(SUM(is_chargeback), 0) chargebacks FROM {$t} WHERE user_id IN ({$in}) GROUP BY user_id", ARRAY_A ); // phpcs:ignore
			foreach ( (array) $hist as $h ) {
				$history[ (string) $h['ident'] ] = $h;
			}
		}
		if ( ! empty( $emails ) ) {
			$emails = array_values( array_unique( $emails ) );
			$ph     = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$hist   = $wpdb->get_results( $wpdb->prepare( "SELECT CONCAT('e:', email_norm) ident, SUM(CASE WHEN status IN ({$paid}) OR is_chargeback = 1 THEN 1 ELSE 0 END) settled, COALESCE(SUM(is_chargeback), 0) chargebacks FROM {$t} WHERE user_id = 0 AND email_norm IN ({$ph}) GROUP BY email_norm", $emails ), ARRAY_A ); // phpcs:ignore
			foreach ( (array) $hist as $h ) {
				$history[ (string) $h['ident'] ] = $h;
			}
		}

		$conservative = 0.0;
		$expected     = 0.0;
		$fee_expected = 0.0;
		$uncalibrated = 0.0;
		$covered      = 0.0; // value on stops with a chargeback behind them.
		$out_rows     = array();
		foreach ( $rows as $r ) {
			$value = (float) $r['value'];
			if ( $value <= 0 ) {
				continue;
			}
			$h           = $history[ $r['ident'] ] ?? null;
			$own_settled = $h ? (int) $h['settled'] : 0;
			$own_cbs     = $h ? (int) $h['chargebacks'] : 0;

			// The blocked identities behind the stop bring their history in.
			$tied_settled = 0;
			$tied_cbs     = 0;
			foreach ( array_keys( $tied[ (string) $r['ident'] ] ?? array() ) as $key ) {
				$th            = $history[ $key ] ?? null;
				$tied_settled += $th ? (int) $th['settled'] : 0;
				$tied_cbs     += $th ? (int) $th['chargebacks'] : 0;
			}
			$settled = $own_settled + $tied_settled;
			$cbs     = $own_cbs + $tied_cbs;
			$own     = $settled > 0 ? min( 1.0, $cbs / $settled ) : null;

			$p = null;
			if ( null !== $prior ) {
				$p = ( $cbs + self::SHRINK_ORDERS * $prior['rate'] ) / ( $settled + self::SHRINK_ORDERS );
			} elseif ( null !== $own ) {
				$p = $own;
			}
			$p = null === $p ? null : min( 1.0, max( 0.0, $p ) );

			$row_expected = null;
			if ( null === $p ) {
				$uncalibrated += $value;
			} else {
				$row_expected  = $value * $p * $a['loss_ratio'];
				$expected     += $row_expected;
				$fee_expected += null === $a['fee'] ? 0.0 : $a['fee'] * $p * (int) $r['events'];
			}
			$row_conservative = 0.0;
			if ( $cbs > 0 && null !== $own ) {
				$row_conservative = $value * $own * $a['loss_ratio'];
				$conservative    += $row_conservative;
				$covered         += $value;
			}
			$out_rows[] = array(
				'ident'            => $r['ident'],
				'email_norm'       => (string) $r['email_norm'],
				'user_id'          => (int) $r['user_id'],
				'customer_name'    => (string) $r['customer_name'],
				'kinds'            => (string) $r['kinds'],
				'value'            => $value,
				'events'           => (int) $r['events'],
				'attempts'         => (int) $r['attempts'],
				'staff_n'          => (int) ( $r['staff_n'] ?? 0 ),
				'settled'          => $own_settled,
				'chargebacks'      => $own_cbs,
				'linked_to'        => (array) ( $r['linked_to'] ?? array() ),
				'tied_settled'     => $tied_settled,
				'tied_chargebacks' => $tied_cbs,
				'basis_settled'    => $settled,
				'basis_chargebacks' => $cbs,
				'own_rate'         => $own,
				'probability'      => $p,
				'expected'         => $row_expected,
				'conservative'     => $row_conservative,
				'last_at'          => (string) $r['last_at'],
			);
		}
		usort(
			$out_rows,
			function ( $x, $y ) {
				return ( $y['expected'] ?? 0 ) <=> ( $x['expected'] ?? 0 );
			}
		);

		// Confidence: how much outcome evidence stands behind the probabilities.
		$evidence_cbs = null === $prior ? 0 : (int) $prior['chargebacks'];
		if ( null === $prior && 0.0 === $expected && 0.0 === $conservative ) {
			$confidence = 'none';
		} elseif ( $evidence_cbs >= 20 ) {
			$confidence = 'high';
		} elseif ( $evidence_cbs >= 5 ) {
			$confidence = 'medium';
		} else {
			$confidence = 'low';
		}
		$total_value = (float) $bv['totals']['value'];
		if ( 'none' !== $confidence && $total_value > 0 && $uncalibrated / $total_value > 0.25 ) {
			$confidence = 'high' === $confidence ? 'medium' : 'low';
		}

		return array(
			'rows'          => $out_rows,
			'blocked_value' => $total_value,
			'conservative'  => $conservative,
			'expected'      => $expected,
			'fees_expected' => null === $a['fee'] ? null : $fee_expected,
			'uncalibrated'  => $uncalibrated,
			'covered_value' => $covered,
			'prior'         => $prior,
			'calibration'   => $cal,
			'confidence'    => $confidence,
			'loss_ratio'    => $a['loss_ratio'],
			'shrink'        => self::SHRINK_ORDERS,
		);
	}

	/* ===================================================================== *
	 *  4. Projected annual protection (forecast)
	 * ===================================================================== */

	/**
	 * What the customers currently on the blocklist were costing per year
	 * while they were active — so what keeping them blocked avoids, IF the
	 * same behaviour would have continued.
	 *
	 * Per blocked identity (account, else email) with at least one recorded
	 * chargeback:
	 *
	 *   window     = months from their first order to their last, floored at
	 *                the configured minimum window (default 90 days = 3
	 *                months, so a short burst is annualized at most x4);
	 *   net loss   = (charged-back amount - recorded recoveries) x loss ratio;
	 *   annualized = net loss x 12 / window (+ fee x chargebacks x 12 / window).
	 *
	 * A customer who charged back $10,000 across two orders three months
	 * apart therefore projects to $40,000 a year: a simple run-rate, not a
	 * promise. Each row carries a confidence grade from the length of the
	 * history and the number of chargebacks behind it, and the total is split
	 * by grade. Identities blocked only by address, phone or IP (never by
	 * email or account) are not identifiable as customers and are left out,
	 * as are blocked customers with no chargeback on record.
	 *
	 * @return array
	 */
	public static function projection() {
		global $wpdb;
		$t   = FS_Data::index_table();
		$b   = FS_Data::blocklist_table();
		$a   = self::assumptions();
		$bj  = self::blocked_join_sql( 'i' );
		$idn = self::ident_sql( 'i' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore
			"SELECT {$idn} ident, MAX(i.email_norm) email_norm, MAX(i.user_id) user_id, MAX(i.customer_name) customer_name,
				COUNT(*) orders,
				COALESCE(SUM(i.is_chargeback), 0) cb_orders,
				COALESCE(SUM(CASE WHEN i.is_chargeback = 1 THEN i.order_total ELSE 0 END), 0) cb_gross,
				COALESCE(SUM(CASE WHEN i.is_chargeback = 1 THEN i.recovered ELSE 0 END), 0) recovered,
				COALESCE(SUM(CASE WHEN i.is_chargeback = 1 AND i.order_date >= DATE_SUB(NOW(), INTERVAL 365 DAY) THEN i.order_total ELSE 0 END), 0) cb_trailing,
				MIN(i.order_date) first_order, MAX(i.order_date) last_order,
				MIN(CASE WHEN i.is_chargeback = 1 THEN i.order_date END) first_cb,
				MAX(CASE WHEN i.is_chargeback = 1 THEN i.order_date END) last_cb
			FROM {$t} i{$bj['join']}
			WHERE {$bj['hit']}
			GROUP BY ident
			ORDER BY cb_gross DESC",
			ARRAY_A
		);

		$min_months = max( 1, (int) round( $a['min_window_days'] / 30.44 ) );
		$out        = array();
		$total      = 0.0;
		$fees_total = null === $a['fee'] ? null : 0.0;
		$trailing   = 0.0;
		$by_conf    = array(
			'high'   => 0.0,
			'medium' => 0.0,
			'low'    => 0.0,
		);
		$no_history = 0;
		foreach ( $rows as $r ) {
			if ( (int) $r['cb_orders'] <= 0 ) {
				$no_history++;
				continue;
			}
			// The active window in calendar months, fractional: first order to
			// last, as a person would count it ("three months apart" is x4
			// whether that is 89 days or 92), with the remaining days as a
			// fraction of a month. No rounding step, so one extra day never
			// moves the projection by a whole month. Floored at the minimum
			// window; never annualized over less.
			$span        = self::span_months( (string) $r['first_order'], (string) $r['last_order'] );
			$span_months = $span['months'];
			$months      = max( (float) $min_months, $span_months );
			$factor      = 12 / $months;
			$net         = max( 0.0, (float) $r['cb_gross'] - (float) $r['recovered'] ) * $a['loss_ratio'];
			$annual      = $net * $factor;
			$fees        = null === $a['fee'] ? null : $a['fee'] * (int) $r['cb_orders'] * $factor;

			// Confidence from how long and how often the behaviour was seen,
			// measured in days so "three months" reads the same however the
			// calendar fell: a year and 3+ chargebacks is high, 90 days and
			// 2+ is medium, anything shorter or a single chargeback is low.
			if ( $span['days'] >= self::CONFIDENCE_HIGH_DAYS && (int) $r['cb_orders'] >= 3 ) {
				$conf = 'high';
			} elseif ( $span['days'] >= self::CONFIDENCE_MEDIUM_DAYS && (int) $r['cb_orders'] >= 2 ) {
				$conf = 'medium';
			} else {
				$conf = 'low';
			}

			$total            += $annual;
			$by_conf[ $conf ] += $annual;
			$trailing         += (float) $r['cb_trailing'];
			if ( null !== $fees_total ) {
				$fees_total += $fees;
			}
			$out[] = array(
				'ident'         => $r['ident'],
				'email_norm'    => (string) $r['email_norm'],
				'user_id'       => (int) $r['user_id'],
				'customer_name' => (string) $r['customer_name'],
				'orders'        => (int) $r['orders'],
				'cb_orders'     => (int) $r['cb_orders'],
				'cb_gross'      => (float) $r['cb_gross'],
				'recovered'     => (float) $r['recovered'],
				'net'           => $net,
				'first_order'   => (string) $r['first_order'],
				'last_order'    => (string) $r['last_order'],
				'first_cb'      => (string) $r['first_cb'],
				'last_cb'       => (string) $r['last_cb'],
				'span_months'   => $span_months,
				'window_months' => $months,
				'factor'        => $factor,
				'annual'        => $annual,
				'fees_annual'   => $fees,
				'confidence'    => $conf,
			);
		}
		usort(
			$out,
			function ( $x, $y ) {
				return $y['annual'] <=> $x['annual'];
			}
		);

		// Blocked identities that are not customers we can name: entries
		// blocked by address / phone / IP with no email or account behind
		// them are places and devices, not people, and project nothing.
		$named  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$b} WHERE type IN ('email','user')" ); // phpcs:ignore
		$listed = count( $out ) + $no_history;

		return array(
			'rows'          => $out,
			'total'         => $total,
			'fees_total'    => $fees_total,
			'trailing_12m'  => $trailing,
			'by_confidence' => $by_conf,
			'identities'    => count( $out ),
			'no_history'    => $no_history,
			'blocked_named' => $named,
			'blocked_seen'  => $listed,
			'min_months'    => $min_months,
			'loss_ratio'    => $a['loss_ratio'],
			'fee'           => $a['fee'],
		);
	}

	/* ===================================================================== *
	 *  5. Customer network
	 * ===================================================================== */

	/**
	 * The credential columns a connection can run over, with the strength of
	 * the association each one supports.
	 *
	 *  identity — the same account: one person, two emails.
	 *  strong   — a phone number or a specific door: shared by people who
	 *             live or trade together, and by nobody else.
	 *  medium   — the same building or first address line: a shared
	 *             apartment block or drop house, unit varying.
	 *  weak     — a street, an IP address or a browser signature: shared by
	 *             strangers every day. Never grounds to block on its own.
	 *
	 * @return array [ via => { label:string, strength:string, col:string } ]
	 */
	public static function edge_types() {
		return array(
			'account'  => array(
				'label'    => __( 'Same account', 'fraud-shield' ),
				'strength' => 'identity',
				'col'      => 'user_id',
			),
			'phone'    => array(
				'label'    => __( 'Phone number', 'fraud-shield' ),
				'strength' => 'strong',
				'col'      => 'phone_norm',
			),
			'address'  => array(
				'label'    => __( 'Shipping address', 'fraud-shield' ),
				'strength' => 'strong',
				'col'      => 'ship_hash',
			),
			'door'     => array(
				'label'    => __( 'Same door', 'fraud-shield' ),
				'strength' => 'strong',
				'col'      => 'ship_door_hash',
			),
			'building' => array(
				'label'    => __( 'Same building / address line', 'fraud-shield' ),
				'strength' => 'medium',
				'col'      => 'ship_loose_hash',
			),
			'street'   => array(
				'label'    => __( 'Same street', 'fraud-shield' ),
				'strength' => 'weak',
				'col'      => 'street',
			),
			'ip'       => array(
				'label'    => __( 'IP address', 'fraud-shield' ),
				'strength' => 'weak',
				'col'      => 'ip',
			),
			'device'   => array(
				'label'    => __( 'Browser signature', 'fraud-shield' ),
				'strength' => 'weak',
				'col'      => 'user_agent',
			),
		);
	}

	/**
	 * Rank of a strength word, higher = stronger.
	 *
	 * @param string $strength identity|strong|medium|weak.
	 * @return int
	 */
	public static function strength_rank( $strength ) {
		$ranks = array(
			'identity' => 4,
			'strong'   => 3,
			'medium'   => 2,
			'weak'     => 1,
		);
		return $ranks[ $strength ] ?? 0;
	}

	/**
	 * Street key for an index row: country|state|city|street, or '' when any
	 * part is missing.
	 *
	 * @param array $r Index row.
	 * @return string
	 */
	private static function street_key( $r ) {
		$parts = array( (string) ( $r['shipping_country'] ?? '' ), (string) ( $r['ship_state'] ?? '' ), (string) ( $r['ship_city'] ?? '' ), (string) ( $r['ship_street'] ?? '' ) );
		foreach ( $parts as $p ) {
			if ( '' === trim( $p ) ) {
				return '';
			}
		}
		return implode( '|', $parts );
	}

	/**
	 * Risk-ranked customer list. One row per email identity, like the
	 * Profiles page, plus whether the identity is on the blocklist. The
	 * default order is risk: blocked first, then by recorded chargebacks, then
	 * by the highest risk score any of their orders reached.
	 *
	 * @param array $args { search, orderby, dir, limit, offset }.
	 * @return array
	 */
	public static function network_list( $args = array() ) {
		global $wpdb;
		$a = wp_parse_args(
			$args,
			array(
				'search'  => '',
				'orderby' => 'risk',
				'dir'     => 'desc',
				'limit'   => 50,
				'offset'  => 0,
			)
		);
		$t    = FS_Data::index_table();
		$paid = FS_Data::paid_in_sql();
		$bj   = self::blocked_join_sql( 'i' );
		$dir  = 'asc' === strtolower( (string) $a['dir'] ) ? 'ASC' : 'DESC';
		$sort = array(
			'risk'        => "blocked DESC, chargebacks DESC, max_risk {$dir}, last_order DESC",
			'chargebacks' => "chargebacks {$dir}, cb_amount {$dir}, last_order DESC",
			'spend'       => "spend {$dir}, last_order DESC",
			'orders'      => "orders {$dir}, last_order DESC",
			'last'        => "last_order {$dir}",
			'name'        => "customer_name {$dir}, email_norm ASC",
		);
		$order_by = $sort[ $a['orderby'] ] ?? $sort['risk'];

		$params = array();
		$search = trim( (string) $a['search'] );
		$where  = "i.email_norm <> ''";
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (i.email_norm LIKE %s OR i.customer_name LIKE %s OR i.phone_norm LIKE %s OR i.ip LIKE %s OR i.ship_text LIKE %s)';
			$params = array( $like, $like, $like, $like, $like );
		}
		$params[] = (int) $a['limit'];
		$params[] = (int) $a['offset'];

		return (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT i.email_norm, MAX(i.customer_name) customer_name, MAX(i.user_id) user_id,
					COUNT(*) orders,
					SUM(CASE WHEN i.status IN ({$paid}) THEN 1 ELSE 0 END) paid_orders,
					COALESCE(SUM(CASE WHEN i.status IN ({$paid}) THEN i.order_total ELSE 0 END), 0) spend,
					COALESCE(SUM(i.is_chargeback), 0) chargebacks,
					COALESCE(SUM(CASE WHEN i.is_chargeback = 1 THEN i.order_total ELSE 0 END), 0) cb_amount,
					MAX(i.risk_score) max_risk,
					COUNT(DISTINCT i.phone_norm) phones, COUNT(DISTINCT i.ip) ips, COUNT(DISTINCT i.ship_hash) addresses,
					MIN(i.order_date) first_order, MAX(i.order_date) last_order,
					MAX(CASE WHEN {$bj['hit']} THEN 1 ELSE 0 END) blocked
				FROM {$t} i{$bj['join']}
				WHERE {$where}
				GROUP BY i.email_norm
				ORDER BY {$order_by}
				LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
	}

	/**
	 * Number of identities the list would show.
	 *
	 * @param string $search Free text.
	 * @return int
	 */
	public static function network_count( $search = '' ) {
		return FS_Data::profiles_count( $search );
	}

	/**
	 * Every index row of a set of identities (by email), newest first, capped.
	 *
	 * @param array $emails Normalized emails.
	 * @param int   $cap    Rows.
	 * @return array
	 */
	private static function rows_for( $emails, $cap ) {
		global $wpdb;
		$emails = array_values( array_unique( array_filter( array_map( 'strval', (array) $emails ) ) ) );
		if ( empty( $emails ) ) {
			return array();
		}
		$ph       = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		$params   = $emails;
		$params[] = (int) $cap;
		return (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( 'SELECT order_id, order_date, user_id, email_norm, customer_name, phone_norm, ip, ship_hash, ship_loose_hash, ship_door_hash, ship_text, shipping_country, ship_state, ship_city, ship_street, user_agent, payment_method, order_total, recovered, status, is_chargeback, risk_score, risk_level FROM ' . FS_Data::index_table() . " WHERE email_norm IN ({$ph}) ORDER BY order_date DESC, id DESC LIMIT %d", $params ),
			ARRAY_A
		);
	}

	/**
	 * Group a set of index rows into per-credential value maps.
	 *
	 * @param array $rows Index rows.
	 * @return array [ via => [ value => [ 'emails' => [email => true], 'last' => 'Y-m-d H:i:s', 'orders' => int, 'label' => string ] ] ]
	 */
	private static function credential_map( $rows ) {
		$map = array();
		foreach ( self::edge_types() as $via => $meta ) {
			$map[ $via ] = array();
		}
		$note = function ( $via, $value, $r, $label = '' ) use ( &$map ) {
			$value = (string) $value;
			if ( '' === $value || '0' === $value ) {
				return;
			}
			if ( ! isset( $map[ $via ][ $value ] ) ) {
				$map[ $via ][ $value ] = array(
					'emails' => array(),
					'last'   => (string) $r['order_date'],
					'first'  => (string) $r['order_date'],
					'orders' => 0,
					'label'  => $label,
				);
			}
			$map[ $via ][ $value ]['emails'][ (string) $r['email_norm'] ] = true;
			$map[ $via ][ $value ]['orders']++;
			if ( (string) $r['order_date'] > $map[ $via ][ $value ]['last'] ) {
				$map[ $via ][ $value ]['last'] = (string) $r['order_date'];
			}
			if ( '' !== (string) $r['order_date'] && ( '' === $map[ $via ][ $value ]['first'] || (string) $r['order_date'] < $map[ $via ][ $value ]['first'] ) ) {
				$map[ $via ][ $value ]['first'] = (string) $r['order_date'];
			}
			if ( '' === $map[ $via ][ $value ]['label'] && '' !== $label ) {
				$map[ $via ][ $value ]['label'] = $label;
			}
		};
		foreach ( $rows as $r ) {
			if ( (int) $r['user_id'] > 0 ) {
				$note( 'account', (string) (int) $r['user_id'], $r );
			}
			$note( 'phone', $r['phone_norm'], $r );
			$note( 'address', $r['ship_hash'], $r, (string) $r['ship_text'] );
			$note( 'door', $r['ship_door_hash'], $r, (string) $r['ship_text'] );
			$note( 'building', $r['ship_loose_hash'], $r, (string) $r['ship_text'] );
			$sk = self::street_key( $r );
			if ( '' !== $sk ) {
				$note( 'street', $sk, $r, ucwords( (string) $r['ship_street'] ) . ', ' . ucwords( (string) $r['ship_city'] ) . ' ' . (string) $r['ship_state'] );
			}
			$note( 'ip', $r['ip'], $r );
			$note( 'device', $r['user_agent'], $r );
		}
		return $map;
	}

	/**
	 * Rows of OTHER identities that share any credential in the map: one
	 * indexed branch per credential column, UNION ALL, each row tagged with
	 * the edge it came through. Street matching uses the composite key.
	 *
	 * @param array $map      Output of credential_map().
	 * @param array $exclude  Emails that are not neighbours (the identities themselves).
	 * @param array $vias     Edge types to travel (default all).
	 * @return array Rows with 'via' and 'match' columns added.
	 */
	private static function neighbour_rows( $map, $exclude, $vias = null ) {
		global $wpdb;
		$t     = FS_Data::index_table();
		$types = self::edge_types();
		$vias  = null === $vias ? array_keys( $types ) : (array) $vias;

		$exclude = array_values( array_unique( array_filter( array_map( 'strval', (array) $exclude ) ) ) );
		$not     = '';
		$not_p   = array();
		if ( ! empty( $exclude ) ) {
			$not   = ' AND email_norm NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')';
			$not_p = $exclude;
		}

		$select   = 'SELECT order_id, order_date, user_id, email_norm, customer_name, phone_norm, ip, ship_hash, ship_loose_hash, ship_door_hash, ship_text, shipping_country, ship_state, ship_city, ship_street, user_agent, order_total, status, is_chargeback, risk_score, risk_level';
		$branches = array();
		$params   = array();
		foreach ( $vias as $via ) {
			if ( ! isset( $types[ $via ] ) || empty( $map[ $via ] ) ) {
				continue;
			}
			$values = array_slice( array_keys( $map[ $via ] ), 0, self::VALUES_CAP );
			if ( 'street' === $via ) {
				$tuples = array();
				foreach ( $values as $key ) {
					$parts = explode( '|', $key );
					if ( 4 === count( $parts ) ) {
						$tuples[] = '(%s,%s,%s,%s)';
						array_push( $params, $parts[0], $parts[1], $parts[2], $parts[3] );
					}
				}
				if ( empty( $tuples ) ) {
					continue;
				}
				// The matched value is rebuilt in PHP from the row's own columns:
				// a CONCAT here would carry the connection's collation into the
				// UNION against real columns, which some hosts refuse.
				$branches[] = "({$select}, 'street' AS via, NULL AS match_value FROM {$t} WHERE (shipping_country, ship_state, ship_city, ship_street) IN (" . implode( ',', $tuples ) . "){$not} ORDER BY order_date DESC, id DESC LIMIT %d)";
				$params     = array_merge( $params, $not_p, array( self::NEIGHBOUR_ROWS ) );
				continue;
			}
			$col = $types[ $via ]['col'];
			if ( 'user_id' === $col ) {
				$in         = implode( ',', array_map( 'intval', $values ) );
				$branches[] = "({$select}, 'account' AS via, NULL AS match_value FROM {$t} WHERE user_id IN ({$in}){$not} ORDER BY order_date DESC, id DESC LIMIT %d)";
				$params     = array_merge( $params, $not_p, array( self::NEIGHBOUR_ROWS ) );
				continue;
			}
			$ph         = implode( ',', array_fill( 0, count( $values ), '%s' ) );
			$branches[] = "({$select}, '" . esc_sql( $via ) . "' AS via, {$col} AS match_value FROM {$t} WHERE {$col} IN ({$ph}){$not} ORDER BY order_date DESC, id DESC LIMIT %d)";
			$params     = array_merge( $params, $values, $not_p, array( self::NEIGHBOUR_ROWS ) );
		}
		if ( empty( $branches ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $branches ), $params ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$r ) {
			if ( 'account' === $r['via'] ) {
				$r['match_value'] = (string) (int) $r['user_id'];
			} elseif ( 'street' === $r['via'] ) {
				$r['match_value'] = self::street_key( $r );
			}
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Which of a set of identities are on the blocklist, by email or account.
	 *
	 * @param array $emails Normalized emails.
	 * @param array $users  Account ids.
	 * @return array { emails:[email=>true], users:[id=>true] }
	 */
	private static function blocked_among( $emails, $users ) {
		global $wpdb;
		$b      = FS_Data::blocklist_table();
		$out    = array(
			'emails' => array(),
			'users'  => array(),
		);
		$emails = array_values( array_unique( array_filter( array_map( 'strval', (array) $emails ) ) ) );
		$users  = array_values( array_unique( array_filter( array_map( 'intval', (array) $users ) ) ) );
		if ( ! empty( $emails ) ) {
			$ph = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT value FROM {$b} WHERE type = 'email' AND value IN ({$ph})", $emails ) ) as $v ) { // phpcs:ignore
				$out['emails'][ (string) $v ] = true;
			}
		}
		if ( ! empty( $users ) ) {
			$ph = implode( ',', array_fill( 0, count( $users ), '%s' ) );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT value FROM {$b} WHERE type = 'user' AND value IN ({$ph})", array_map( 'strval', $users ) ) ) as $v ) { // phpcs:ignore
				$out['users'][ (int) $v ] = true;
			}
		}
		return $out;
	}

	/**
	 * Connection summaries for a page of identities, in a handful of queries:
	 * how many other customers each one is connected to, the strongest link,
	 * and whether any DIRECT connection is a blocked customer (and how strong
	 * that connection is). Browser signatures are left out of the counts —
	 * too many strangers share one.
	 *
	 * @param array $emails Normalized emails on the page.
	 * @return array [ email => { connections:int, strong:int, strongest:string, blocked_via:string|null, blocked_strength:string|null, blocked_with:string|null } ]
	 */
	public static function connections_batch( $emails ) {
		$emails = array_values( array_unique( array_filter( array_map( 'strval', (array) $emails ) ) ) );
		$out    = array();
		foreach ( $emails as $e ) {
			$out[ $e ] = array(
				'connections'      => 0,
				'strong'           => 0,
				'strongest'        => '',
				'blocked_via'      => null,
				'blocked_strength' => null,
				'blocked_with'     => null,
			);
		}
		if ( empty( $emails ) ) {
			return $out;
		}
		$rows = self::rows_for( $emails, self::SELF_ROWS * 6 );
		$map  = self::credential_map( $rows );
		$vias = array( 'account', 'phone', 'address', 'door', 'building', 'street', 'ip' );
		$nb   = self::neighbour_rows( $map, array(), $vias );
		if ( empty( $nb ) ) {
			return $out;
		}
		$types = self::edge_types();

		// page email => [ neighbour email => strongest edge ]
		$pairs    = array();
		$n_users  = array();
		$n_emails = array();
		foreach ( $nb as $r ) {
			$via   = (string) $r['via'];
			$value = (string) $r['match_value'];
			$ne    = (string) $r['email_norm'];
			if ( '' === $ne || ! isset( $map[ $via ][ $value ] ) ) {
				continue;
			}
			$n_emails[ $ne ] = true;
			if ( (int) $r['user_id'] > 0 ) {
				$n_users[ (int) $r['user_id'] ] = true;
			}
			foreach ( array_keys( $map[ $via ][ $value ]['emails'] ) as $pe ) {
				if ( $pe === $ne ) {
					continue;
				}
				$strength = $types[ $via ]['strength'];
				if ( ! isset( $pairs[ $pe ][ $ne ] ) || self::strength_rank( $strength ) > self::strength_rank( $pairs[ $pe ][ $ne ]['strength'] ) ) {
					$pairs[ $pe ][ $ne ] = array(
						'strength' => $strength,
						'via'      => $via,
						'user_id'  => (int) $r['user_id'],
					);
				}
			}
		}
		$blocked = self::blocked_among( array_keys( $n_emails ), array_keys( $n_users ) );

		foreach ( $pairs as $pe => $neigh ) {
			if ( ! isset( $out[ $pe ] ) ) {
				continue;
			}
			foreach ( $neigh as $ne => $edge ) {
				$out[ $pe ]['connections']++;
				if ( self::strength_rank( $edge['strength'] ) >= self::strength_rank( 'strong' ) ) {
					$out[ $pe ]['strong']++;
				}
				if ( self::strength_rank( $edge['strength'] ) > self::strength_rank( $out[ $pe ]['strongest'] ) ) {
					$out[ $pe ]['strongest'] = $edge['strength'];
				}
				$is_blocked = isset( $blocked['emails'][ $ne ] ) || ( $edge['user_id'] > 0 && isset( $blocked['users'][ $edge['user_id'] ] ) );
				if ( $is_blocked && self::strength_rank( $edge['strength'] ) > self::strength_rank( (string) $out[ $pe ]['blocked_strength'] ) ) {
					$out[ $pe ]['blocked_via']      = $edge['via'];
					$out[ $pe ]['blocked_strength'] = $edge['strength'];
					$out[ $pe ]['blocked_with']     = $ne;
				}
			}
		}
		return $out;
	}

	/**
	 * Everything the Customer Network shows for one customer: who they are,
	 * what they have used, who they are connected to and how, with the
	 * evidence and recency behind every connection, and a second hop to any
	 * blocked customer their connections lead to.
	 *
	 * @param string $email Normalized email.
	 * @return array|null Null when the identity has no orders.
	 */
	public static function explore( $email ) {
		global $wpdb;
		$email = FS_Normalizer::email( $email );
		if ( '' === $email ) {
			return null;
		}
		$rows = self::rows_for( array( $email ), self::SELF_ROWS );
		if ( empty( $rows ) ) {
			return null;
		}
		$types   = self::edge_types();
		$summary = FS_Data::profile_summary( $email );
		$map     = self::credential_map( $rows );
		$uid     = (int) ( $summary['user_id'] ?? 0 );

		// Credentials in a displayable shape.
		$creds = array();
		foreach ( $map as $via => $values ) {
			foreach ( $values as $value => $info ) {
				$creds[ $via ][] = array(
					'value'  => (string) $value,
					'label'  => (string) $info['label'],
					'orders' => (int) $info['orders'],
					'first'  => (string) $info['first'],
					'last'   => (string) $info['last'],
				);
			}
		}

		// First hop.
		$nb = self::neighbour_rows( $map, array( $email ) );

		$neighbours = array(); // email => data.
		foreach ( $nb as $r ) {
			$via   = (string) $r['via'];
			$value = (string) $r['match_value'];
			$ne    = (string) $r['email_norm'];
			if ( '' === $ne || ! isset( $map[ $via ][ $value ] ) ) {
				continue;
			}
			if ( ! isset( $neighbours[ $ne ] ) ) {
				$neighbours[ $ne ] = array(
					'email_norm'    => $ne,
					'user_id'       => (int) $r['user_id'],
					'customer_name' => (string) $r['customer_name'],
					'edges'         => array(),
					'strongest'     => '',
					'rows'          => array(),
				);
			}
			if ( (int) $r['user_id'] > 0 && $neighbours[ $ne ]['user_id'] <= 0 ) {
				$neighbours[ $ne ]['user_id'] = (int) $r['user_id'];
			}
			$ek = $via . '|' . $value;
			if ( ! isset( $neighbours[ $ne ]['edges'][ $ek ] ) ) {
				$mine                                  = $map[ $via ][ $value ];
				$neighbours[ $ne ]['edges'][ $ek ]     = array(
					'via'          => $via,
					'label'        => $types[ $via ]['label'],
					'strength'     => $types[ $via ]['strength'],
					'value'        => $value,
					'display'      => self::display_value( $via, $value, (string) $mine['label'], (string) $r['ship_text'] ),
					'mine_orders'  => (int) $mine['orders'],
					'mine_last'    => (string) $mine['last'],
					'their_orders' => 0,
					'their_last'   => '',
					'their_first'  => '',
					'order_ids'    => array(),
				);
			}
			$e = &$neighbours[ $ne ]['edges'][ $ek ];
			$e['their_orders']++;
			if ( (string) $r['order_date'] > $e['their_last'] ) {
				$e['their_last'] = (string) $r['order_date'];
			}
			if ( '' === $e['their_first'] || (string) $r['order_date'] < $e['their_first'] ) {
				$e['their_first'] = (string) $r['order_date'];
			}
			if ( count( $e['order_ids'] ) < 5 ) {
				$e['order_ids'][ (int) $r['order_id'] ] = (string) $r['order_date'];
			}
			unset( $e );
			if ( self::strength_rank( $types[ $via ]['strength'] ) > self::strength_rank( $neighbours[ $ne ]['strongest'] ) ) {
				$neighbours[ $ne ]['strongest'] = $types[ $via ]['strength'];
			}
		}

		// Neighbour standing: orders, spend, chargebacks, risk, blocked, verified.
		$n_emails = array_keys( $neighbours );
		if ( ! empty( $n_emails ) ) {
			$paid = FS_Data::paid_in_sql();
			$ph   = implode( ',', array_fill( 0, count( $n_emails ), '%s' ) );
			$agg  = $wpdb->get_results( $wpdb->prepare( "SELECT email_norm, MAX(user_id) user_id, MAX(customer_name) customer_name, COUNT(*) orders, COALESCE(SUM(CASE WHEN status IN ({$paid}) THEN order_total ELSE 0 END), 0) spend, COALESCE(SUM(is_chargeback), 0) chargebacks, COALESCE(SUM(CASE WHEN is_chargeback = 1 THEN order_total ELSE 0 END), 0) cb_amount, MAX(risk_score) max_risk, MIN(order_date) first_order, MAX(order_date) last_order FROM " . FS_Data::index_table() . " WHERE email_norm IN ({$ph}) GROUP BY email_norm", $n_emails ), ARRAY_A ); // phpcs:ignore
			$n_users = array();
			foreach ( (array) $agg as $g ) {
				$ne = (string) $g['email_norm'];
				if ( ! isset( $neighbours[ $ne ] ) ) {
					continue;
				}
				$neighbours[ $ne ]['orders']        = (int) $g['orders'];
				$neighbours[ $ne ]['spend']         = (float) $g['spend'];
				$neighbours[ $ne ]['chargebacks']   = (int) $g['chargebacks'];
				$neighbours[ $ne ]['cb_amount']     = (float) $g['cb_amount'];
				$neighbours[ $ne ]['max_risk']      = (int) $g['max_risk'];
				$neighbours[ $ne ]['first_order']   = (string) $g['first_order'];
				$neighbours[ $ne ]['last_order']    = (string) $g['last_order'];
				$neighbours[ $ne ]['customer_name'] = '' !== (string) $g['customer_name'] ? (string) $g['customer_name'] : $neighbours[ $ne ]['customer_name'];
				if ( (int) $g['user_id'] > 0 ) {
					$neighbours[ $ne ]['user_id'] = (int) $g['user_id'];
					$n_users[]                    = (int) $g['user_id'];
				}
			}
			$blocked = self::blocked_among( $n_emails, $n_users );
			$creds_v = FS_Live::verified_creds();
			foreach ( $neighbours as $ne => &$n ) {
				$n['blocked']  = isset( $blocked['emails'][ $ne ] ) || ( $n['user_id'] > 0 && isset( $blocked['users'][ $n['user_id'] ] ) );
				$n['verified'] = isset( $creds_v[ 'email:' . $ne ] ) || ( $n['user_id'] > 0 && FS_Live::is_verified_customer( $n['user_id'] ) );
				$n['edges']    = array_values( $n['edges'] );
				usort(
					$n['edges'],
					function ( $x, $y ) {
						return self::strength_rank( $y['strength'] ) <=> self::strength_rank( $x['strength'] );
					}
				);
			}
			unset( $n );
		}

		// Second hop: do the (unblocked) neighbours lead, over a strong or
		// identity link, to a blocked customer? Bounded to the strongest
		// neighbours so a busy street never turns into a full-index walk.
		$hop2_from = array();
		foreach ( $neighbours as $ne => $n ) {
			if ( empty( $n['blocked'] ) && self::strength_rank( $n['strongest'] ) >= self::strength_rank( 'medium' ) ) {
				$hop2_from[] = $ne;
			}
		}
		$hop2_from = array_slice( $hop2_from, 0, self::HOP2_CAP );
		if ( ! empty( $hop2_from ) ) {
			$rows2 = self::rows_for( $hop2_from, self::SELF_ROWS * 4 );
			$map2  = self::credential_map( $rows2 );
			$nb2   = self::neighbour_rows( $map2, array_merge( array( $email ), array_keys( $neighbours ) ), array( 'account', 'phone', 'address', 'door', 'building' ) );
			$cands = array();
			$cusr  = array();
			foreach ( $nb2 as $r ) {
				$cands[ (string) $r['email_norm'] ] = true;
				if ( (int) $r['user_id'] > 0 ) {
					$cusr[] = (int) $r['user_id'];
				}
			}
			$blocked2 = self::blocked_among( array_keys( $cands ), $cusr );
			foreach ( $nb2 as $r ) {
				$far = (string) $r['email_norm'];
				if ( ! isset( $blocked2['emails'][ $far ] ) && ! ( (int) $r['user_id'] > 0 && isset( $blocked2['users'][ (int) $r['user_id'] ] ) ) ) {
					continue;
				}
				$via   = (string) $r['via'];
				$value = (string) $r['match_value'];
				if ( ! isset( $map2[ $via ][ $value ] ) ) {
					continue;
				}
				foreach ( array_keys( $map2[ $via ][ $value ]['emails'] ) as $mid ) {
					if ( ! isset( $neighbours[ $mid ] ) || ! empty( $neighbours[ $mid ]['blocked'] ) ) {
						continue;
					}
					$path_key = $far . '|' . $via;
					if ( ! isset( $neighbours[ $mid ]['paths'][ $path_key ] ) ) {
						$neighbours[ $mid ]['paths'][ $path_key ] = array(
							'blocked_email' => $far,
							'blocked_name'  => (string) $r['customer_name'],
							'via'           => $via,
							'label'         => $types[ $via ]['label'],
							'strength'      => $types[ $via ]['strength'],
							'display'       => self::display_value( $via, $value, (string) $map2[ $via ][ $value ]['label'], (string) $r['ship_text'] ),
							'last'          => (string) $r['order_date'],
						);
					}
				}
			}
			foreach ( $neighbours as &$n ) {
				if ( isset( $n['paths'] ) ) {
					$n['paths'] = array_values( $n['paths'] );
				}
			}
			unset( $n );
		}

		// Contribution to risk, then order by it.
		foreach ( $neighbours as &$n ) {
			$n = self::score_connection( $n );
		}
		unset( $n );
		usort(
			$neighbours,
			function ( $x, $y ) {
				if ( $x['risk_score'] !== $y['risk_score'] ) {
					return $y['risk_score'] <=> $x['risk_score'];
				}
				return strcmp( (string) $y['last_order'], (string) $x['last_order'] );
			}
		);

		// The customer's own standing.
		$own_blocked  = self::blocked_among( array( $email ), $uid > 0 ? array( $uid ) : array() );
		$block_cards  = self::block_cards_for( $email, $uid, $map );
		$creds_v      = FS_Live::verified_creds();
		$idv_verified = class_exists( 'FS_IDV' ) ? FS_IDV::is_verified( $uid, $email ) : false;

		return array(
			'email'        => $email,
			'summary'      => $summary,
			'user_id'      => $uid,
			'name'         => (string) ( $summary['customer_name'] ?? '' ),
			'credentials'  => $creds,
			'neighbours'   => array_values( $neighbours ),
			'blocked'      => isset( $own_blocked['emails'][ $email ] ) || ( $uid > 0 && isset( $own_blocked['users'][ $uid ] ) ),
			'block_cards'  => $block_cards,
			'verified'     => isset( $creds_v[ 'email:' . $email ] ) || ( $uid > 0 && FS_Live::is_verified_customer( $uid ) ),
			'idv_verified' => (bool) $idv_verified,
			'chargebacks'  => array_values(
				array_filter(
					$rows,
					function ( $r ) {
						return ! empty( $r['is_chargeback'] );
					}
				)
			),
			'orders'       => array_slice( $rows, 0, 25 ),
			'rows_capped'  => count( $rows ) >= self::SELF_ROWS,
		);
	}

	/**
	 * A shared value as staff should read it: never a raw hash.
	 *
	 * @param string $via    Edge type.
	 * @param string $value  Stored value.
	 * @param string $mine   The customer's own text for it (address text).
	 * @param string $theirs The neighbour's text for it.
	 * @return string
	 */
	private static function display_value( $via, $value, $mine, $theirs ) {
		switch ( $via ) {
			case 'address':
			case 'door':
			case 'building':
				// Both spellings when they differ: "Apt 9" on one side and
				// "#4" on the other is exactly the evidence a same-door match is.
				if ( '' !== $mine && '' !== $theirs && strcasecmp( trim( $mine ), trim( $theirs ) ) !== 0 ) {
					return $mine . ' ↔ ' . $theirs;
				}
				return '' !== $mine ? $mine : ( '' !== $theirs ? $theirs : substr( $value, 0, 12 ) . '…' );
			case 'street':
				$p = explode( '|', $value );
				return 4 === count( $p ) ? ucwords( $p[3] ) . ', ' . ucwords( $p[2] ) . ' ' . $p[1] . ' (' . $p[0] . ')' : $value;
			case 'account':
				/* translators: %s: account id */
				return sprintf( __( 'account #%s', 'fraud-shield' ), $value );
			case 'device':
				return mb_substr( $value, 0, 60 ) . ( mb_strlen( $value ) > 60 ? '…' : '' );
			default:
				return $value;
		}
	}

	/**
	 * How much a connection should move the needle, and why. The strongest
	 * edge sets the weight of the link; the neighbour's own standing sets
	 * what that link connects to. A weak link to a blocked customer stays a
	 * low-to-medium note: sharing a street or an IP is not evidence of fraud.
	 *
	 * @param array $n Neighbour record.
	 * @return array The record with risk_score (0..1), risk_level and risk_reason.
	 */
	private static function score_connection( $n ) {
		$sw = array(
			'identity' => 1.0,
			'strong'   => 0.8,
			'medium'   => 0.5,
			'weak'     => 0.2,
		);
		$w  = $sw[ $n['strongest'] ] ?? 0.2;

		$standing = 0.1;
		$why      = __( 'no adverse history on record', 'fraud-shield' );
		if ( ! empty( $n['blocked'] ) ) {
			$standing = 1.0;
			$why      = __( 'a blocked customer', 'fraud-shield' );
		} elseif ( (int) ( $n['chargebacks'] ?? 0 ) > 0 ) {
			$standing = 0.9;
			/* translators: %d: number of chargebacks */
			$why = sprintf( _n( '%d recorded chargeback', '%d recorded chargebacks', (int) $n['chargebacks'], 'fraud-shield' ), (int) $n['chargebacks'] );
		} elseif ( ! empty( $n['paths'] ) ) {
			$standing = 0.6;
			$why      = __( 'themselves linked to a blocked customer', 'fraud-shield' );
		} elseif ( (int) ( $n['max_risk'] ?? 0 ) >= (int) FS_Core::instance()->settings()['levels']['high'] ) {
			$standing = 0.5;
			$why      = __( 'orders that scored HIGH risk', 'fraud-shield' );
		} elseif ( (int) ( $n['max_risk'] ?? 0 ) >= (int) FS_Core::instance()->settings()['levels']['medium'] ) {
			$standing = 0.25;
			$why      = __( 'orders that scored MEDIUM risk', 'fraud-shield' );
		}
		if ( ! empty( $n['verified'] ) ) {
			$standing = min( $standing, 0.15 );
			$why      = __( 'a verified customer', 'fraud-shield' );
		}

		$score = $w * $standing;
		if ( $score >= 0.7 ) {
			$level = 'high';
		} elseif ( $score >= 0.35 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}
		$strength_words = array(
			'identity' => __( 'the same account', 'fraud-shield' ),
			'strong'   => __( 'a strong identifier', 'fraud-shield' ),
			'medium'   => __( 'the same building or address line', 'fraud-shield' ),
			'weak'     => __( 'only a weak association (street, IP or browser)', 'fraud-shield' ),
		);
		$n['risk_score']  = $score;
		$n['risk_level']  = $level;
		/* translators: 1: strength of the link, 2: the connected customer's standing */
		$n['risk_reason'] = sprintf( __( 'Connected through %1$s to %2$s.', 'fraud-shield' ), $strength_words[ $n['strongest'] ] ?? $strength_words['weak'], $why );
		if ( 'weak' === $n['strongest'] && $standing >= 0.6 ) {
			$n['risk_reason'] .= ' ' . __( 'A shared street or IP is not evidence of fraud on its own and does not justify blocking.', 'fraud-shield' );
		}
		return $n;
	}

	/**
	 * The block cards and entries that hold this customer or their
	 * credentials on the blocklist: what is blocked, under which card, why
	 * and since when.
	 *
	 * @param string $email Normalized email.
	 * @param int    $uid   Account id.
	 * @param array  $map   Output of credential_map() for the customer.
	 * @return array Rows { type, value, display, reason, created_at, action_id, action_kind, action_title }.
	 */
	private static function block_cards_for( $email, $uid, $map ) {
		global $wpdb;
		$b       = FS_Data::blocklist_table();
		$je      = FS_Data::block_action_entries_table();
		$actions = FS_Data::block_actions_table();

		$checks = array(
			'email'   => array( $email => true ),
			'user'    => $uid > 0 ? array( (string) $uid => true ) : array(),
			'phone'   => $map['phone'] ?? array(),
			'ip'      => $map['ip'] ?? array(),
			'address' => array_merge( $map['address'] ?? array(), $map['door'] ?? array(), $map['building'] ?? array() ),
		);
		$or     = array();
		$params = array();
		foreach ( $checks as $type => $values ) {
			if ( empty( $values ) ) {
				continue;
			}
			$vals     = array_slice( array_keys( $values ), 0, self::VALUES_CAP );
			$or[]     = '(b.type = %s AND b.value IN (' . implode( ',', array_fill( 0, count( $vals ), '%s' ) ) . '))';
			$params[] = $type;
			$params   = array_merge( $params, array_map( 'strval', $vals ) );
		}
		if ( empty( $or ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT b.id, b.type, b.value, b.reason, b.created_at, a.id action_id, a.kind action_kind, a.title action_title
				FROM {$b} b LEFT JOIN {$je} j ON j.entry_id = b.id LEFT JOIN {$actions} a ON a.id = j.action_id
				WHERE " . implode( ' OR ', $or ) . ' ORDER BY b.created_at DESC, b.id DESC',
				$params
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$label = '';
			if ( 'address' === $r['type'] ) {
				foreach ( array( 'address', 'door', 'building' ) as $via ) {
					if ( isset( $map[ $via ][ $r['value'] ] ) ) {
						$label = (string) $map[ $via ][ $r['value'] ]['label'];
						break;
					}
				}
			}
			$r['display'] = '' !== $label ? $label : FS_Blocklist::entry_label( (string) $r['type'], (string) $r['value'] );
		}
		unset( $r );
		return $rows;
	}
}
