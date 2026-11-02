<?php
namespace ACFWF\Models\Store_Credits;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Interfaces\Deactivatable_Interface;
use ACFWF\Models\Objects\Store_Credit_Entry;
use ACFWF\Models\Objects\Date_Period_Range;
use Automattic\WooCommerce\Utilities\NumberUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Registry module.
 *
 * @since 4.0
 */
class Calculate implements Model_Interface, Deactivatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that houses the model name to be used when calling publicly.
     *
     * @since 4.0
     * @access private
     * @var string
     */
    private $_model_name = 'Store_Credits_Calculate';

    /**
     * Property that holds the single main instance of URL_Coupon.
     *
     * @since 4.0
     * @access private
     * @var Registry
     */
    private static $_instance;

    /**
     * Model that houses all the plugin constants.
     *
     * @since 4.0
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Property that houses all the helper functions of the plugin.
     *
     * @since 4.0
     * @access private
     * @var Helper_Functions
     */
    private $_helper_functions;

    /**
     * Property that houses the last active date of the store credits.
     *
     * @since 4.0
     * @access private
     * @var \DateTime
     */
    private $_last_active;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;

        $main_plugin->add_to_all_plugin_models( $this, $this->_model_name );
        $main_plugin->add_to_public_models( $this, $this->_model_name );
    }

    /**
     * Ensure that only one instance of this class is loaded or can be loaded ( Singleton Pattern ).
     *
     * @since 4.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     * @return Registry
     */
    public static function get_instance( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        if ( ! self::$_instance instanceof self ) {
            self::$_instance = new self( $main_plugin, $constants, $helper_functions );
        }

        return self::$_instance;
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate methods
    |--------------------------------------------------------------------------
     */

    /**
     * Calculate store credit status and data based on the provided entries.
     *
     * @since 4.0
     * @access public
     *
     * @param array $entries List of entries.
     */
    public function calculate_credits_status_and_sources( $entries ) {
        $status = array(
            'total'     => 0,
            'unclaimed' => 0,
            'claimed'   => 0,
            'expired'   => 0,
            'deducted'  => 0,
        );

        $sources = \ACFWF()->Store_Credits_Registry->get_initial_counters( 'increase' );

        /**
         * Filter the store credit action types that are considered restorations.
         *
         * Restoration actions represent reversals of prior spend (e.g. cancelled orders, refunds),
         * not new credit issuances. They offset the claimed counter instead of inflating the total.
         *
         * @since 4.7.3
         *
         * @param array $restoration_actions List of restoration action type slugs.
         */
        $restoration_actions = apply_filters( 'acfw_store_credit_restoration_action_types', array( 'cancelled_order', 'refund' ) );
        $restoration_actions = is_array( $restoration_actions ) ? $restoration_actions : array();

        foreach ( $entries as $entry ) {
            $action = $entry['action'];

            if ( 'increase' === $entry['type'] ) {

                if ( in_array( $action, $restoration_actions, true ) ) {
                    // Restoration entries offset the claimed counter instead of inflating the total.
                    $status['claimed'] -= $entry['amount'];
                } else {
                    // increment all "increase" credits to the total counter.
                    $status['total'] += $entry['amount'];
                }

                // increment source counter.
                if ( isset( $sources[ $action ] ) ) {
                    $sources[ $action ] += $entry['amount'];
                }
            } elseif ( 'decrease' === $entry['type'] ) {

                switch ( $action ) {
                    case 'discount':
                        $status['claimed'] += $entry['amount'];
                        break;
                    case 'expire':
                        $status['expired'] += $entry['amount'];
                        break;
                    case 'admin_decrease':
                        $status['deducted'] += $entry['amount'];
                        break;
                }
            }
        }

        // Clamp claimed to zero to prevent negative values from data inconsistencies.
        $status['claimed'] = max( 0, $status['claimed'] );

        // calculate unclaimed credits by deducting the claimed, expired and deducted credits from the total.
        $status['unclaimed'] = $status['total'] - $status['claimed'] - $status['expired'] - $status['deducted'];

        return array(
            'status'  => \ACFWF()->Store_Credits_Registry->format_store_credits_status_data( $status ),
            'sources' => \ACFWF()->Store_Credits_Registry->format_store_credits_sources_data( $sources ),
        );
    }

    /**
     * Calculate unclaimed store credit entries.
     *
     * @since 4.0
     * @access public
     *
     * @return float Total unclaimed store credits.
     */
    public function calculate_unclaimed_store_credits() {
        $increase = $this->_get_entries_sum( array( 'type' => 'increase' ) );
        $decrease = $this->_get_entries_sum( array( 'type' => 'decrease' ) );

        return max( 0, $increase - $decrease );
    }

    /**
     * Calculate store credits period statistics (added and used).
     *
     * @deprecated 4.3
     *
     * @since 4.0
     * @access public
     *
     * @param string $before_date Range start date (mysql date format).
     * @param string $after_date  Range end date (mysql date format).
     * @return array Calculated period statistics.
     */
    public function calculate_store_credits_period_statistics( $before_date = '', $after_date = '' ) {
        \wc_deprecated_function( 'ACFWF\Models\Store_Credits\Calculate::' . __FUNCTION__, '4.3', 'ACFWF\Models\Store_Credits\Calculate::calculate_store_credits_report_period_statistics' );
        $report_period = new Date_Period_Range( $before_date, $after_date );
        return $this->calculate_store_credits_report_period_statistics( $report_period );
    }

    /**
     * Calculate statistics for gift cards sold and claimed within a given date period range data.
     *
     * @since 4.3
     * @access public
     *
     * @param Date_Period_Range $report_period Report period object.
     * @return array Calculated period statistics.
     */
    public function calculate_store_credits_report_period_statistics( Date_Period_Range $report_period ) {
        $report_period->use_utc_timezone();

        $cache_key   = $report_period->generate_period_cache_key( 'acfwf_store_credits_period_stats::%s::%s' );
        $cached_data = get_transient( $cache_key );

        // return cached data if already present in object cache.
        if ( is_array( $cached_data ) && isset( $cached_data['added_in_period'] ) && isset( $cached_data['used_in_period'] ) ) {
            $report_period->use_site_timezone(); // reset timezone back to site timezone.
            return $cached_data;
        }

        $period_params = array(
            'start_period' => $report_period->start_period->format( 'Y-m-d H:i:s' ),
            'end_period'   => $report_period->end_period->format( 'Y-m-d H:i:s' ),
        );

        $data = array(
            'added_in_period' => $this->_get_entries_sum( array_merge( array( 'type' => 'increase' ), $period_params ) ),
            'used_in_period'  => $this->_get_entries_sum( array_merge( array( 'type' => 'decrease' ), $period_params ) ),
        );

        // save data to the cache for a maximum of one day.
        set_transient( $cache_key, $data, DAY_IN_SECONDS );

        // reset timezone back to site timezone.
        $report_period->use_site_timezone();

        return $data;
    }

    /**
     * Get customer store credit balance.
     *
     * @since 4.0
     * @since 4.2 Add hook to trigger actions based on customer's current store credits balance.
     * @since 4.2.1 Wrap returned value with NumberUtil::round round function so it returns precise value.
     * @since 4.7.6 Use the cached balance when a fresh calculation isn't requested, and bail early for guests.
     * @access private
     *
     * @param int  $user_id  User ID.
     * @param bool $is_fresh Flag if need to calculate fresh again..
     * @return float Customer balance.
     */
    public function get_customer_balance( $user_id, $is_fresh = false ) {
        /**
         * Guests have no balance. Bail before any query, as an empty user ID makes
         * _get_entries_sum() drop its user filter and sum the whole ledger instead.
         */
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0.0;
        }

        /**
         * Expire user's credits when user last active is not valid anymore.
         * We also return 0.00 as user's balance here as user's full balance will be expired in this scenario.
         */
        if ( ! $this->validate_user_last_active( $user_id ) ) {
            $this->expire_user_credit_balance( $user_id );
            return 0.0;
        }

        $cached_balance = get_user_meta( $user_id, Plugin_Constants::STORE_CREDIT_USER_BALANCE, true );

        /**
         * Only recalculate when explicitly asked for, or when there is no cached value.
         * get_user_meta() returns an empty string when the meta key is unset, so that is
         * what a cache miss looks like here. A cached '0' is a valid hit and must not be
         * treated as a miss.
         */
        if ( $is_fresh || '' === $cached_balance ) {
            $balance = $this->_calculate_customer_balance( $user_id );
            update_user_meta( $user_id, Plugin_Constants::STORE_CREDIT_USER_BALANCE, $balance );

            /**
             * Filter for doing additional actions based on customer's new balance.
             * We are only running this filter when the user's balance is calculated and is not fetched from cache.
             */
            do_action( 'acfw_get_customer_store_credit_balance', $balance, $user_id );

        } else {
            $balance = (float) $cached_balance; // Meta values are stored as strings.
        }

        return NumberUtil::round( $balance, wc_get_price_decimals() );
    }

    /**
     * Freshly calculate customer store credit balance.
     *
     * @since 4.0
     * @since 4.2 Add action hooks to trigger actions based on user's total earned/used store credits.
     * @access private
     *
     * @param int  $user_id    User ID.
     * @param bool $skip_hooks Flag to skip triggering the hooks.
     * @return float Customer balance.
     */
    private function _calculate_customer_balance( $user_id, $skip_hooks = false ) {
        $total_earned = $this->_get_entries_sum(
            array(
                'user_id' => $user_id,
                'type'    => 'increase',
            )
        );
        $total_used   = $this->_get_entries_sum(
            array(
                'user_id' => $user_id,
                'type'    => 'decrease',
            )
        );

        /**
         * Allow third party plugins to run actions based on the total value of store credits earned and/or used by the customer.
         * the hook should only run when doing normal calculations of customer's balance.
         */
        if ( ! $skip_hooks ) {
            do_action( 'acfw_user_total_store_credits_earned', $total_earned, $user_id );
            do_action( 'acfw_user_total_store_credits_used', $total_used, $user_id );
        }

        return max( 0, $total_earned - $total_used );
    }

    /*
    |--------------------------------------------------------------------------
    | Credit balance expiry
    |--------------------------------------------------------------------------
     */

    /**
     * Get user last activate datetime.
     *
     * @since 4.0
     * @access public
     *
     * @param int $user_id User ID.
     * @return WC_DateTime Datetime object.
     */
    public function get_last_active( $user_id ) {
        global $wpdb;

        if ( ! isset( $this->_last_active[ $user_id ] ) ) {
            $last_date = $wpdb->get_var( $wpdb->prepare( "SELECT entry_date FROM {$wpdb->acfw_store_credits} WHERE user_id = %d ORDER BY entry_date DESC", $user_id ) );
            $last_date = $last_date ? $last_date : 'now'; // Check if last date is valid, if not use current time.

            $this->_last_active[ $user_id ] = new \WC_DateTime( $last_date, new \DateTimeZone( 'UTC' ) );
            $this->_last_active[ $user_id ]->setTimezone( new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );
        }

        return $this->_last_active[ $user_id ];
    }

    /**
     * Vaidate user last activate date.
     *
     * @since 4.0
     * @access public
     *
     * @param int $user_id User ID.
     * @return bool True if still active, false otherwise.
     */
    public function validate_user_last_active( $user_id ) {

        // Return as valid if store credits expiry is disabled.
        if ( ! $this->should_store_credits_expire() ) {
            return true;
        }

        $expire_date     = clone $this->get_last_active( $user_id );
        $interval_string = $this->get_store_credit_expiry_interval_string();
        $expire_date->add( \DateInterval::createFromDateString( $interval_string ) );

        // Return as valid if expiry date is not a valid datetime object.
        if ( ! $expire_date instanceof \WC_DateTime ) {
            return true;
        }

        $datetime = new \DateTime( 'now', new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );

        return $expire_date > $datetime;
    }

    /**
     * Expire user's store credit balance.
     *
     * @since 4.0
     * @access public
     *
     * @param int $user_id User ID.
     */
    public function expire_user_credit_balance( $user_id ) {
        $previous_balance = $this->_calculate_customer_balance( $user_id, true );
        $expire_entry     = new Store_Credit_Entry();

        $expire_entry->set_prop( 'amount', $previous_balance );
        $expire_entry->set_prop( 'user_id', $user_id );
        $expire_entry->set_prop( 'type', 'decrease' );
        $expire_entry->set_prop( 'action', 'expire' );
        $check = $expire_entry->save( true );

        if ( ! is_wp_error( $check ) ) {
            do_action( 'acfw_expire_user_store_credits', $previous_balance, $user_id, $expire_entry );
            update_user_meta( $user_id, Plugin_Constants::STORE_CREDIT_USER_BALANCE, 0 );
        }
    }

    /**
     * Maybe run check/validate for all user's store credit balance needs to be expired or not.
     * This function should only run once per day.
     *
     * @since 4.0
     * @access public
     *
     * @return bool True if it should run, false otherwise.
     */
    public function maybe_check_on_all_users_balance_expiry() {
        global $wpdb;

        // Skip if store credits expiry is disabled.
        if ( ! $this->should_store_credits_expire() ) {
            return;
        }

        $last_run = get_option( Plugin_Constants::STORE_CREDITS_EXPIRY_CHECK_DATE, null );

        // only run function once a day.
        if ( $last_run && $last_run > time() - DAY_IN_SECONDS ) {
            return;
        }

        // Pre-filter candidate users whose most recent entry is older than the configured expiry period.
        // Per-user expiry is still validated precisely by validate_user_last_active() below.
        $expire_timestamp = strtotime( '-' . $this->get_store_credit_expiry_interval_string() );

        $raw_data = $wpdb->get_results(
            "SELECT u.ID, s.entry_date FROM {$wpdb->users} AS u
            INNER JOIN {$wpdb->acfw_store_credits} AS s ON (u.ID = s.user_id)
            WHERE 1
            GROUP BY u.ID
            ORDER BY s.entry_date DESC"
        );

        // filter users to only list IDs of most recent entry date is earlier than the expiry date value.
        $data = array_filter(
            $raw_data,
            function ( $r ) use ( $expire_timestamp ) {
            $datetime = new \DateTime( $r->entry_date );
            return $datetime->getTimestamp() <= $expire_timestamp;
            }
        );

        // validate expiration for each user.
        if ( ! empty( $data ) ) {
            foreach ( $data as $row ) {
                $user_id = absint( $row->ID );
                if ( ! $this->validate_user_last_active( $user_id ) ) {
                    $this->expire_user_credit_balance( $user_id );
                }
            }
        }

        update_option( Plugin_Constants::STORE_CREDITS_EXPIRY_CHECK_DATE, time(), false );
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
     */

    /**
     * Get all store credit entries.
     *
     * @since 4.0
     * @access private
     *
     * @param string $start_period Start period date string.
     * @param string $end_period   End period date string.
     * @return array|WP_Error Store credit entries on success, error ojbect on failure.
     */
    private function _get_all_entries( $start_period = '', $end_period = '' ) {
        global $wpdb;

        $query = "SELECT entry_type,entry_action,entry_amount FROM {$wpdb->acfw_store_credits} WHERE 1";

        if ( $start_period && $end_period ) {
            $query .= $wpdb->prepare( ' AND entry_date BETWEEN %s AND %s', $start_period, $end_period );
        }

        $raw_data = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore

        if ( ! is_array( $raw_data ) ) {
            return new \WP_Error(
                'acfw_query_all_store_credit_entries_fail',
                __( 'There was an error loading store credits data.', 'advanced-coupons-for-woocommerce-free' ),
                array( 'status' => 400 )
            );
        }

        return array_map(
            function ( $r ) {
            return array(
                'type'   => $r['entry_type'],
                'action' => $r['entry_action'],
                'amount' => floatval( $r['entry_amount'] ),
            );
            },
            $raw_data
        );
    }

    /**
     * Get sum of all entries based on the provided parameters.
     *
     * @since 4.0
     * @access private
     *
     * @param array $params Query parameters.
     * @return float Sum of queried entries.
     */
    private function _get_entries_sum( $params = array() ) {
        global $wpdb;

        $params = wp_parse_args(
            $params,
            array(
                'user_id'      => 0,
                'type'         => '',
                'action'       => '',
                'object_id'    => 0,
                'start_period' => '',
                'end_period'   => '',
                'precision'    => $this->get_decimal_precision(),
                'decimals'     => wc_get_price_decimals(),
            )
        );

        // Replace extract() with explicit variable assignment for security.
        $user_id      = isset( $params['user_id'] ) ? $params['user_id'] : 0;
        $type         = isset( $params['type'] ) ? $params['type'] : '';
        $action       = isset( $params['action'] ) ? $params['action'] : '';
        $object_id    = isset( $params['object_id'] ) ? $params['object_id'] : 0;
        $start_period = isset( $params['start_period'] ) ? $params['start_period'] : '';
        $end_period   = isset( $params['end_period'] ) ? $params['end_period'] : '';
        $precision    = isset( $params['precision'] ) ? $params['precision'] : $this->get_decimal_precision();
        $decimals     = isset( $params['decimals'] ) ? $params['decimals'] : wc_get_price_decimals();

        $user_query    = $user_id ? $wpdb->prepare( 'AND user_id = %d', $user_id ) : '';
        $type_query    = $type ? $wpdb->prepare( 'AND entry_type = %s', $type ) : '';
        $action_query  = $action ? $wpdb->prepare( 'AND entry_action = %s', $action ) : '';
        $related_query = $object_id ? $wpdb->prepare( 'AND object_id = %d', $object_id ) : '';
        $period_query  = $start_period && $end_period ? $wpdb->prepare( 'AND entry_date BETWEEN %s AND %s', $start_period, $end_period ) : '';

        // build the querys.
        // phpcs:disable
        $query = $wpdb->prepare(
            "SELECT SUM(CONVERT(entry_amount, DECIMAL(%d,%d)))
            FROM {$wpdb->acfw_store_credits}
            WHERE 1
            {$user_query} {$type_query} {$action_query} {$related_query} 
            {$period_query}
        ",
            $precision,
            $decimals
        );
        // phpcs:enable

        return (float) $wpdb->get_var( $query ); // phpcs:ignore
    }

    /**
     * Delete store credits cached data.
     *
     * @since 4.3
     * @access public
     */
    public function delete_store_credits_cached_data() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%acfwf_store_credits_period_stats%'" );
    }

    /**
     * Refresh a customer's cached store credit balance.
     *
     * Hooked to 'acfw_store_credits_total_changed', which Store_Credit_Entry fires after every
     * entry it saves or deletes. Every write that goes through that object is covered, including
     * the ones that never refresh the balance themselves (third party imports, loyalty point and
     * gift card redemptions). The two paths that bypass the object and delete rows with direct
     * SQL (the pro plugin's bulk "delete all entries" and store credits admin) set the balance
     * meta themselves, so they need no help from here.
     *
     * This is not optional bookkeeping: Store_Credit_Entry::_validate_customer_balance() asks for
     * a fresh balance *before* the row is written, so without this the meta would be left holding
     * the pre-save figure after every entry.
     *
     * The balance is recalculated and rewritten rather than blanked out. A blank would work as a
     * cache miss for get_customer_balance(), but the meta is also read directly elsewhere and an
     * empty string is not a neutral placeholder in those places: the customer list endpoint would
     * report the balance as 0, and the pro plugin filters customers on this key with a
     * 'DECIMAL(10,2)' BETWEEN meta query, where an empty string casts to 0.00. That would silently
     * mis-target bulk adjustments at exactly the customers whose balance just changed. Keeping the
     * stored value true at all times avoids handing those readers a sentinel they cannot recognise.
     *
     * The sum is written straight to the meta instead of going through get_customer_balance(),
     * because that method does more than read a total: it runs the balance expiry check and fires
     * 'acfw_get_customer_store_credit_balance' for third parties. Both are wrong in a write hook.
     * get_last_active() memoises the customer's last activity for the request and is populated
     * before the row lands, so the expiry check here would still see the pre-write date and could
     * expire the credit that was just added. This listener only keeps the cache honest.
     *
     * The entry is null when the whole ledger changed at once (bulk adjustments). Those callers
     * set the balance meta themselves, so there is nothing to refresh.
     *
     * @since 4.7.6
     * @access public
     *
     * @param Store_Credit_Entry|null $entry Store credit entry that changed.
     */
    public function refresh_customer_balance_cache( $entry = null ) {
        if ( ! $entry instanceof Store_Credit_Entry ) {
            return;
        }

        // Read in edit context so this matches the user the row was actually written against.
        $user_id = absint( $entry->get_prop( 'user_id', 'edit' ) );

        if ( ! $user_id ) {
            return;
        }

        update_user_meta(
            $user_id,
            Plugin_Constants::STORE_CREDIT_USER_BALANCE,
            $this->_calculate_customer_balance( $user_id, true )
        );
    }

    /**
     * Get the total store credits discount for a given order.
     *
     * @since 4.5.2
     * @access public
     *
     * @param int $order_id Order ID.
     * @return float Total store credits discount for order.
     */
    public function get_total_store_credits_discount_for_order( int $order_id ) {
        return $this->_get_entries_sum(
            array(
                'object_id' => $order_id,
                'type'      => 'decrease',
                'action'    => 'discount',
            )
        );
    }

    /**
     * Get the total refunded store credits discount for a given order.
     *
     * @since 4.5.9
     * @access public
     *
     * @param \WC_Order $order Order object.
     * @return float Total refunded store credits discount for order.
     */
    public function get_total_refunded_store_credits_discount_for_order( $order ) {
        global $wpdb;

        $meta_data = $order->get_meta( Plugin_Constants::REFUND_STORE_CREDIT_DISCOUNT_ENTRY, false );

        if ( empty( $meta_data ) ) {
            return 0.0;
        }

        $entry_ids     = array_map(
            function ( $e ) {
                return $e->value;
            },
            $meta_data
        );
        $entry_ids_str = esc_sql( implode( ',', $entry_ids ) );

        // build the querys.
        // phpcs:disable
        $query = $wpdb->prepare(
            "SELECT SUM(CONVERT(entry_amount, DECIMAL(%d,%d)))
            FROM {$wpdb->acfw_store_credits}
            WHERE entry_id IN ({$entry_ids_str})
        ",
            $this->get_decimal_precision(),
            wc_get_price_decimals()
        );
        // phpcs:enable

        return (float) $wpdb->get_var( $query ); // phpcs:ignore
    }

    /*
    |--------------------------------------------------------------------------
    | Calculate query constants
    |--------------------------------------------------------------------------
     */

    /**
     * Get the decimal precision value used in converting "string" to "decimal" type in MySQL.
     * Note: the entry_amount value is saved as string in the db. We need to convert the values into a decimal type in queries which requires a decimal value.
     *       for reference: https://www.mysqltutorial.org/mysql-decimal/
     *
     * @since 4.0
     * @access public
     *
     * @return int Decimal precision
     */
    public function get_decimal_precision() {
        return apply_filters( 'acfw_store_credits_decimal_precision', 19 );
    }

    /**
     * Check if the store credits should expire or not.
     *
     * @since 4.5.4.2
     * @access public
     *
     * @return bool True if store credits should expire, false otherwise.
     */
    public function should_store_credits_expire() {

        $expiry = get_option( Plugin_Constants::STORE_CREDIT_EXPIRY, 'noexpiry' );

        // Store credits expire only when a positive expiry value is set. 'noexpiry' (legacy) and 0 both disable expiry.
        $should_expire = 'noexpiry' !== $expiry && absint( $expiry ) > 0;

        return apply_filters( 'acfw_should_store_credits_expire', $should_expire );
    }

    /**
     * Get the store credits expiry period as a relative date string (e.g. "30 days", "6 months", "2 years").
     *
     * The value is derived from the configured expiry quantity and unit, so it works for periods
     * shorter than a year. Legacy installs that stored a plain integer default to the "years" unit.
     *
     * @since 4.7.5
     * @access public
     *
     * @return string Relative date string, or empty string when expiry is disabled.
     */
    public function get_store_credit_expiry_interval_string() {

        if ( ! $this->should_store_credits_expire() ) {
            return '';
        }

        $quantity = absint( get_option( Plugin_Constants::STORE_CREDIT_EXPIRY, 0 ) );
        $unit     = get_option( Plugin_Constants::STORE_CREDIT_EXPIRY_UNIT, 'years' );

        // Guard against unexpected unit values so the relative date string stays valid.
        if ( ! in_array( $unit, array( 'days', 'months', 'years' ), true ) ) {
            $unit = 'years';
        }

        return "$quantity $unit";
    }

    /**
     * Get the number of days after which the store credits should expire.
     *
     * @since 4.5.4.2
     * @access public
     *
     * @return int Number of days after which the store credits should expire.
     */
    public function get_store_credit_expiry_days() {
        return apply_filters( 'acfw_store_credit_expiry_days', 365 );
    }

    /**
     * Calculate recently imported store credits.
     *
     * @since 4.6.7
     * @access public
     *
     * @param string $start_period Date to calculate store credits for.
     * @param string $end_period   Optional end date to calculate store credits for.
     * @return int Total store credits imported.
     */
    public function calculate_recently_imported_store_credits( $start_period, $end_period = '' ) {
        $end_period = $end_period ? $end_period : current_time( 'mysql', true );
        $params     = array(
            'action'       => 'imported_sc',
            'start_period' => $start_period,
            'end_period'   => $end_period,
        );

        return $this->_get_entries_sum( $params );
    }

    /**
     * Get expire date for user's store credits.
     *
     * @since 4.7.0
     * @access public
     *
     * @param int $user_id User ID.
     * @return string Formatted expire date or null if expiry is disabled.
     */
    public function get_expire_date_for_user_store_credits( $user_id ) {

        // Return as valid if store credits expiry is disabled.
        if ( ! $this->should_store_credits_expire() ) {
            return null;
        }

        $expire_date     = clone $this->get_last_active( $user_id );
        $interval_string = $this->get_store_credit_expiry_interval_string();
        $expire_date->add( \DateInterval::createFromDateString( $interval_string ) );

        // Return as null if expiry date is not a valid datetime object.
        if ( ! $expire_date instanceof \WC_DateTime ) {
            return null;
        }

        $datetime = new \DateTime( 'now', new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );

        // Return as null if the store credits are expired.
        if ( $expire_date <= $datetime ) {
            return null;
        }

        return $this->_helper_functions->convert_datetime_to_site_standard_format( $expire_date->format( 'Y-m-d H:i:s' ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Contract for deactivate.
     *
     * @since 1.0.1
     * @access public
     * @implements ACFWF\Interfaces\Deactivatable_Interface
     */
    public function deactivate() {
        $this->delete_store_credits_cached_data();
    }

    /**
     * Execute Store_Credits class.
     *
     * @since 4.0
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'acfw_store_credits_total_changed', array( $this, 'delete_store_credits_cached_data' ) );
        add_action( 'acfw_store_credits_total_changed', array( $this, 'refresh_customer_balance_cache' ) );
    }
}
