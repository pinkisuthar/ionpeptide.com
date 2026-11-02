<?php
/**
 * Funnels Controller Class
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BWFAN_Funnels
 *
 */
#[\AllowDynamicProperties]
class BWFAN_Funnels {

	public function get_contact_funnels( $contact_id ) {
		$db_results = [];
		if ( function_exists( 'WFACP_Core' ) ) {
			$db_results = self::get_contact_checkouts( $contact_id );
		}

		if ( function_exists( 'WFOPP_Core' ) ) {
			$optin_results = self::get_contact_optins( $contact_id );
			$db_results    = array_merge( $db_results, $optin_results );
		}

		if ( function_exists( 'WFOB_Core' ) ) {
			$bump_results = self::get_contact_bumps( $contact_id );
			$db_results   = array_merge( $db_results, $bump_results );
		}

		if ( function_exists( 'WFOCU_Core' ) ) {
			$upsell_results = self::get_contact_upsells( $contact_id );
			$db_results     = array_merge( $db_results, $upsell_results );
		}

		$final_result = [];

		if ( count( $db_results ) === 0 ) {
			return $final_result;
		}

		foreach ( $db_results as $db_result ) {
			$fid    = $db_result['fid'];
			$funnel = new WFFN_Funnel( $fid );
			if ( ! ( $funnel instanceof WFFN_Funnel ) || $fid !== $funnel->get_id() || absint( $fid ) === 0 ) {
				continue;
			}

			$checkout = isset( $final_result[ $fid ]['checkout'] ) ? $final_result[ $fid ]['checkout'] : 0;
			$bump     = isset( $final_result[ $fid ]['bump'] ) ? $final_result[ $fid ]['bump'] : 0;
			$upsell   = isset( $final_result[ $fid ]['upsell'] ) ? $final_result[ $fid ]['upsell'] : 0;

			$final_result[ $fid ]['funnel_id']   = $fid;
			$final_result[ $fid ]['funnel_name'] = $funnel->get_title();
			$final_result[ $fid ]['checkout']    = isset( $db_result['checkout_revenue'] ) ? $db_result['checkout_revenue'] : $checkout;
			$final_result[ $fid ]['bump']        = isset( $db_result['bump_revenue'] ) ? $db_result['bump_revenue'] : $bump;;
			$final_result[ $fid ]['upsell'] = isset( $db_result['upsell_revenue'] ) ? $db_result['upsell_revenue'] : $upsell;

			if ( ! isset( $final_result[ $fid ]['total_revenue'] ) ) {
				$final_result[ $fid ]['total_revenue'] = 0;
			}
			$final_result[ $fid ]['total_revenue'] += isset( $db_result['total_revenue'] ) ? floatval( $db_result['total_revenue'] ) : 0;
			$final_result[ $fid ]['total_revenue'] = (string) $final_result[ $fid ]['total_revenue'];
		}

		usort( $final_result, function ( $a, $b ) {
			return ( $a['funnel_id'] > $b['funnel_id'] ) ? 1 : - 1;
		} );

		return [
			'records' => array_values( $final_result )
		];
	}

	public static function get_contact_checkouts( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );
		$query = "SELECT aero.fid as fid, SUM(aero.total_revenue) AS 'checkout_revenue', SUM(aero.total_revenue) AS 'total_revenue'  FROM " . $wpdb->prefix . 'bwf_contact' . " AS contact JOIN " . $wpdb->prefix . 'wfacp_stats' . " AS aero ON contact.id=aero.cid WHERE aero.cid=$contact_id AND (aero.fid != 0 OR aero.fid IS NOT NULL) GROUP BY `fid`";

		if ( ! empty( $orderby ) ) {
			$query .= " ORDER BY aero.date DESC";
		}

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	public static function get_contact_bumps( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );

		$query = "SELECT bump.fid as fid, SUM(bump.total) as 'bump_revenue', SUM(bump.total) as 'total_revenue' FROM " . $wpdb->prefix . 'wfob_stats' . " AS bump WHERE bump.cid=$contact_id AND (bump.fid != 0 OR bump.fid IS NOT NULL) GROUP BY bump.fid";

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	public function get_contact_upsells( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );

		$query = "SELECT session.fid as fid, SUM((CASE WHEN action_type_id = 4 THEN `value` END)) AS `upsell_revenue`, SUM((CASE WHEN action_type_id = 4 THEN `value` END)) AS `total_revenue`, session.id as session_id, event.action_type_id FROM " . $wpdb->prefix . 'wfocu_session' . " AS session LEFT JOIN " . $wpdb->prefix . 'wfocu_event' . " AS event ON session.id=event.sess_id WHERE session.cid=$contact_id AND (session.fid != 0 OR session.fid IS NOT NULL) GROUP BY fid";

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	public function get_contact_optins( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );
		$query = "SELECT optin.funnel_id as fid, optin.opid FROM " . $wpdb->prefix . 'bwf_contact' . " AS contact JOIN " . $wpdb->prefix . 'bwf_optin_entries' . " AS optin ON contact.id=optin.cid WHERE optin.cid=$contact_id AND (optin.funnel_id != 0 OR optin.funnel_id IS NOT NULL)";

		if ( ! empty( $orderby ) ) {
			$query .= " ORDER BY optin.date DESC";
		}

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	/**
	 * @param $freuslt
	 * @param $db_result
	 * @param $fid
	 *
	 * @return string
	 */
	public function is_in_checkout( $freuslt, $db_result, $fid ) {
		if ( isset( $db_result['wfacp_id'] ) && $db_result['wfacp_id'] > 0 ) {
			return true;
		}
		if ( isset( $freuslt[ $fid ] ) && isset( $freuslt[ $fid ]['in_checkout'] ) ) {
			return $freuslt[ $fid ]['in_checkout'];
		}

		return null;
	}

	/**
	 * @param $freuslt
	 * @param $db_result
	 * @param $fid
	 *
	 * @return string
	 */
	public function is_in_optin( $freuslt, $db_result, $fid ) {
		if ( isset( $db_result['opid'] ) && $db_result['opid'] ) {
			return true;
		}
		if ( isset( $freuslt[ $fid ] ) && isset( $freuslt[ $fid ]['in_optin'] ) ) {
			return $freuslt[ $fid ]['in_optin'];
		}

		return null;
	}

	/**
	 * @param $freuslt
	 * @param $db_result
	 * @param $fid
	 *
	 * @return string
	 */
	public function is_in_bump( $freuslt, $db_result, $fid ) {
		if ( isset( $db_result['bid'] ) && $db_result['bid'] > 0 && isset( $db_result['converted'] ) ) {
			return absint( $db_result['converted'] ) > 0 ? true : false;
		}

		if ( isset( $freuslt[ $fid ] ) && isset( $freuslt[ $fid ]['in_bump'] ) ) {
			return $freuslt[ $fid ]['in_bump'];
		}

		return null;
	}

	/**
	 * @param $fresult
	 * @param $db_result
	 * @param $fid
	 *
	 * @return string
	 */
	public function is_in_upsell( $fresult, $db_result, $fid ) {
		if ( isset( $db_result['session_id'] ) && absint( $db_result['session_id'] ) > 0 && isset( $db_result['action_type_id'] ) && 2 === absint( $db_result['action_type_id'] ) && empty( $db_result['total_revenue'] ) ) {
			return false;
		}
		if ( isset( $db_result['session_id'] ) && $db_result['session_id'] > 0 && isset( $db_result['total_revenue'] ) ) {
			return ( '' !== $db_result['total_revenue'] );
		}
		if ( isset( $fresult[ $fid ] ) && isset( $fresult[ $fid ]['in_upsell'] ) ) {
			return $fresult[ $fid ]['in_upsell'];
		}

		return null;
	}

	public function get_contact_checkout( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );
		$query = "SELECT p.post_title as 'name', aero.fid as 'funnel',aero.wfacp_id as wfacp_id, aero.total_revenue as 'amount', aero.order_id as 'order',funnel.title as funnel_name, DATE_FORMAT(aero.date, '%Y-%m-%dT%TZ') as 'date' 
				  FROM " . $wpdb->prefix . 'wfacp_stats' . " AS aero 
				  LEFT JOIN " . $wpdb->prefix . 'posts' . " as p ON aero.wfacp_id  = p.id
				  LEFT JOIN " . $wpdb->prefix . 'bwf_funnels' . " as funnel ON aero.fid=funnel.id
				  WHERE aero . cid = $contact_id AND ( aero.fid != 0 OR aero.fid IS NOT NULL ) order by aero . fid asc";

		return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	public function get_contact_bump( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );

		$query = "SELECT p . post_title as 'name',p . ID as 'bump_id', bump . fid as 'funnel',bump.converted as converted, bump . total as 'amount', bump . oid as 'order',funnel . title as funnel_name, DATE_FORMAT( bump . date, '%Y-%m-%dT%TZ' ) as 'date'
				  FROM " . $wpdb->prefix . 'wfob_stats' . " as bump
				  LEFT JOIN " . $wpdb->prefix . 'posts' . " as p ON bump . bid = p . id
				  LEFT JOIN " . $wpdb->prefix . 'bwf_funnels' . " as funnel ON bump . fid = funnel . id
				  WHERE bump . cid = $contact_id AND ( bump.fid != 0 OR bump.fid IS NOT NULL ) order by bump . fid asc";

		return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	public function get_contact_optin( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );
		$query = "SELECT p . post_title as 'name', optin . funnel_id as 'funnel', optin . step_id as 'id', optin.data as 'entry',funnel.title as funnel_name,optin.email, DATE_FORMAT( optin . date, '%Y-%m-%dT%TZ' ) as 'date' 
					FROM " . $wpdb->prefix . 'bwf_optin_entries' . " as optin 
					LEFT JOIN " . $wpdb->prefix . 'posts' . " as p ON optin . step_id = p . id 
					LEFT JOIN " . $wpdb->prefix . 'bwf_funnels' . " as funnel ON optin.funnel_id = funnel.id
					WHERE optin . cid = $contact_id AND ( optin.funnel_id != 0 OR optin.funnel_id IS NOT NULL ) order by optin . funnel_id asc";

		return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

	public function get_contact_upsell( $contact_id ) {
		global $wpdb;
		$contact_id = absint( $contact_id );
		$query = "SELECT event . object_id,event.action_type_id,event.value,
				  DATE_FORMAT( event . timestamp, '%Y-%m-%dT%TZ' ) as 'date',p . post_title as 'object_name',
				  'upsell' as 'type',session . order_id as order_id,session . fid as funnel_id,funnel . title as funnel_name
				  FROM " . $wpdb->prefix . 'wfocu_event' . " as event 
				  LEFT JOIN " . $wpdb->prefix . 'wfocu_session' . " as session ON event . sess_id = session . id
				  LEFT JOIN " . $wpdb->prefix . 'posts' . " as p ON event . object_id = p . id
				  LEFT JOIN " . $wpdb->prefix . 'bwf_funnels' . " as funnel ON session . fid = funnel . id
				  WHERE( event . action_type_id = 4 or event . action_type_id = 6 or event . action_type_id = 7 or event . action_type_id = 9 or event . action_type_id = 10 ) and session . cid = $contact_id
				  AND ( session . fid != 0 OR session . fid IS NOT NULL ) order by session . timestamp asc";

		return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- tables from $wpdb->prefix; contact_id absint()ed
	}

}