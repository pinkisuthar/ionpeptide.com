<?php
namespace ACFWP\Models\Store_Credits;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;
use ACFWF\Models\Objects\Store_Credit_Entry;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic for bulk store credit adjustments.
 * Provides Action Scheduler-based batch processing and operation lock
 * mechanism for bulk credit adjustments.
 *
 * Public Model.
 *
 * @since 4.0.8
 */
class Bulk_Adjust extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 4.0.8
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
        $main_plugin->add_to_public_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | User Filtering
    |--------------------------------------------------------------------------
     */

    /**
     * Query users matching a set of bulk adjustment filters.
     *
     * Supported filter keys (all optional; empty filters match every user):
     * - roles              array  : WP user role slugs (role__in).
     * - balance_min        float  : Minimum acfw_store_credit_balance meta value.
     * - balance_max        float  : Maximum acfw_store_credit_balance meta value.
     * - registered_after   string : Date (YYYY-MM-DD) — users registered on/after.
     * - registered_before  string : Date (YYYY-MM-DD) — users registered on/before.
     * - last_order_after   string : Date (YYYY-MM-DD) — users with an order on/after.
     * - last_order_before  string : Date (YYYY-MM-DD) — users with an order on/before.
     * - include_users      array  : User IDs to add to the result set.
     * - exclude_users      array  : User IDs to remove from the result set.
     *
     * Returns user IDs only (not WP_User objects) to keep memory use bounded
     * when previewing or scheduling against large customer bases.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $filters  Filter criteria (see above).
     * @param bool  $over_cap (Output) Set to true when the matched WP_User_Query result exceeds
     *                        the safety cap (filterable via `acfwp_bulk_adjust_max_users`,
     *                        default 50000). Callers can surface a `WP_Error` 413 to the UI so
     *                        admins know they need to narrow their filters.
     * @return int[] Array of user IDs matching all supplied filters (capped to the safety bound).
     */
    public function get_filtered_users( $filters, &$over_cap = false ) {
        $filters  = is_array( $filters ) ? $filters : array();
        $over_cap = false;

        $include = ! empty( $filters['include_users'] ) && is_array( $filters['include_users'] )
            ? array_filter( array_map( 'absint', $filters['include_users'] ) )
            : array();
        $exclude = ! empty( $filters['exclude_users'] ) && is_array( $filters['exclude_users'] )
            ? array_filter( array_map( 'absint', $filters['exclude_users'] ) )
            : array();

        /**
         * Hard ceiling on the number of users a single bulk adjustment can resolve to.
         *
         * Protects the preview / scheduling endpoints from OOM and request timeouts when
         * filters resolve to the entire customer base on large stores (every preview hits
         * this code path). Callers fail closed with a 413-style error when exceeded.
         *
         * @since 4.0.8
         *
         * @param int $cap Maximum number of users to materialise per request.
         */
        $cap = max( 1, (int) apply_filters( 'acfwp_bulk_adjust_max_users', 50000 ) );

        $args = array(
            'fields'      => 'ID',
            // count_total is needed so we can detect when the unlimited match count exceeds
            // the cap (and signal `$over_cap` to the caller) without materialising the full
            // ID list.
            'count_total' => true,
            'number'      => $cap,
        );

        // Role filter.
        if ( ! empty( $filters['roles'] ) && is_array( $filters['roles'] ) ) {
            $roles = array_filter( array_map( 'sanitize_key', $filters['roles'] ) );
            if ( ! empty( $roles ) ) {
                $args['role__in'] = $roles;
            }
        }

        // Balance range filter — only applied when at least one bound is provided so
        // that users without the balance meta aren't silently excluded from previews
        // that don't care about balance.
        $has_min = isset( $filters['balance_min'] ) && '' !== $filters['balance_min'];
        $has_max = isset( $filters['balance_max'] ) && '' !== $filters['balance_max'];

        if ( $has_min || $has_max ) {
            $min = $has_min ? (float) $filters['balance_min'] : 0;
            // DECIMAL(10,2) can represent up to 99999999.99 — use that as the upper bound
            // sentinel rather than PHP_INT_MAX which exceeds the column range.
            $max = $has_max ? (float) $filters['balance_max'] : 99999999.99;

            $args['meta_query'] = array(
                array(
                    'key'     => \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_USER_BALANCE,
                    'value'   => array( $min, $max ),
                    'compare' => 'BETWEEN',
                    'type'    => 'DECIMAL(10,2)',
                ),
            );
        }

        // Registration date range filter.
        if ( ! empty( $filters['registered_after'] ) || ! empty( $filters['registered_before'] ) ) {
            $date_query = array( 'inclusive' => true );

            if ( ! empty( $filters['registered_after'] ) ) {
                $date_query['after'] = sanitize_text_field( $filters['registered_after'] );
            }
            if ( ! empty( $filters['registered_before'] ) ) {
                $date_query['before'] = sanitize_text_field( $filters['registered_before'] );
            }

            $args['date_query'] = array( $date_query );
        }

        // Exclude list is applied in WP_User_Query so BETWEEN/role filters can short-circuit.
        if ( ! empty( $exclude ) ) {
            $args['exclude'] = array_values( $exclude );
        }

        $has_last_order_filter = ! empty( $filters['last_order_after'] ) || ! empty( $filters['last_order_before'] );
        $has_narrowing_filter  = isset( $args['role__in'] )
            || isset( $args['meta_query'] )
            || isset( $args['date_query'] )
            || $has_last_order_filter;

        // Skip the all-users query when the admin has only supplied an include list —
        // "pick these specific users" shouldn't silently balloon into the entire site.
        if ( $has_narrowing_filter || empty( $include ) ) {
            $query         = new \WP_User_Query( $args );
            $user_ids      = array_map( 'intval', (array) $query->get_results() );
            $matched_total = (int) $query->get_total();
            // The narrowing-filter branch is the OOM hot path (e.g. an empty filter set
            // resolves to "every user"); flag the caller when the unlimited match count
            // exceeds the safety cap so the API can return a 413 instead of silently
            // operating on the first $cap rows.
            if ( $matched_total > $cap ) {
                $over_cap = true;
            }
        } else {
            $user_ids = array();
        }

        // Last-order date filter — resolved via wc_get_orders() so it respects HPOS.
        if ( $has_last_order_filter ) {
            $order_customers = $this->get_customers_with_orders_in_range(
                ! empty( $filters['last_order_after'] ) ? $filters['last_order_after'] : '',
                ! empty( $filters['last_order_before'] ) ? $filters['last_order_before'] : ''
            );
            $user_ids        = array_values( array_intersect( $user_ids, $order_customers ) );
        }

        // Manual include list is unioned in after filtering so admins can force-add users
        // who wouldn't otherwise match (e.g. wrong role but should still receive credit).
        if ( ! empty( $include ) ) {
            $user_ids = array_values( array_unique( array_merge( $user_ids, array_values( $include ) ) ) );
        }

        // Re-apply exclude after include so an excluded ID can never slip back in.
        if ( ! empty( $exclude ) ) {
            $user_ids = array_values( array_diff( $user_ids, array_values( $exclude ) ) );
        }

        /**
         * Filter the final list of user IDs returned by get_filtered_users().
         *
         * @since 4.0.8
         *
         * @param int[] $user_ids Array of user IDs matching the filters.
         * @param array $filters  The original filter array.
         */
        return (array) apply_filters( 'acfwp_bulk_adjust_filtered_users', $user_ids, $filters );
    }

    /**
     * Get IDs of customers who have placed an order within a date range.
     *
     * Uses wc_get_orders() in paginated chunks with `return => 'objects'` so each
     * page is hydrated in a single batch read — this avoids the N+1 pattern of
     * fetching IDs first and then looking up each order individually, while
     * keeping peak memory bounded on stores with large order volumes. The query
     * stays HPOS-aware because it goes through wc_get_orders().
     *
     * @since 4.0.8
     *
     * @access protected
     *
     * @param string $after  Inclusive lower bound (YYYY-MM-DD), or empty for no lower bound.
     * @param string $before Inclusive upper bound (YYYY-MM-DD), or empty for no upper bound.
     * @return int[] Unique customer user IDs with at least one matching order.
     */
    protected function get_customers_with_orders_in_range( $after, $before ) {
        $date_clause = '';

        if ( ! empty( $after ) && ! empty( $before ) ) {
            $date_clause = sanitize_text_field( $after ) . '...' . sanitize_text_field( $before );
        } elseif ( ! empty( $after ) ) {
            $date_clause = '>=' . sanitize_text_field( $after );
        } elseif ( ! empty( $before ) ) {
            $date_clause = '<=' . sanitize_text_field( $before );
        }

        /**
         * Filter the page size used when scanning orders for customer extraction.
         *
         * Larger pages mean fewer queries but higher peak memory; smaller pages
         * keep memory bounded on very large stores at the cost of more round-trips.
         *
         * @since 4.0.8
         *
         * @param int $page_size Number of orders fetched per chunk.
         */
        $page_size = (int) apply_filters( 'acfwp_bulk_adjust_orders_chunk_size', 500 );
        if ( $page_size <= 0 ) {
            $page_size = 500;
        }

        /**
         * Filter the order statuses considered when matching customers by last-order date.
         *
         * Defaults to revenue-bearing statuses only — `wc-cancelled`, `wc-failed`,
         * `wc-refunded`, and `wc-checkout-draft` are excluded so a customer whose only
         * order in the date range was cancelled, failed, refunded, or never completed
         * checkout is not treated as having "placed an order" for bulk-adjust purposes.
         *
         * @since 4.0.8
         *
         * @param string[] $statuses Order status slugs (with `wc-` prefix).
         */
        $statuses = (array) apply_filters(
            'acfwp_bulk_adjust_order_statuses',
            array( 'wc-processing', 'wc-on-hold', 'wc-completed' )
        );

        $base_args = array(
            'type'    => 'shop_order',
            'status'  => $statuses,
            'return'  => 'objects',
            'limit'   => $page_size,
            'orderby' => 'ID',
            'order'   => 'ASC',
        );

        if ( '' !== $date_clause ) {
            $base_args['date_created'] = $date_clause;
        }

        $customer_ids = array();
        $page         = 1;

        do {
            $base_args['paged'] = $page;
            $orders             = wc_get_orders( $base_args );

            if ( empty( $orders ) ) {
                break;
            }

            foreach ( $orders as $order ) {
                $cid = (int) $order->get_customer_id();
                if ( $cid > 0 ) {
                    $customer_ids[ $cid ] = $cid;
                }
            }

            $fetched = count( $orders );
            ++$page;

            // Drop the page reference so PHP can free the order objects before
            // the next iteration loads the following chunk.
            unset( $orders );
        } while ( $fetched === $page_size );

        return array_values( $customer_ids );
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Operation Scheduling
    |--------------------------------------------------------------------------
     */

    /**
     * Schedule a bulk store credit adjustment operation.
     *
     * Validates that no operation is currently running, stores operation metadata,
     * splits user IDs into batches, and schedules each batch via Action Scheduler.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $user_ids Array of user IDs to adjust.
     * @param array $config   Operation configuration (type, amount_mode, amount, note).
     * @return array|\WP_Error Operation metadata on success, WP_Error on failure.
     */
    public function schedule_bulk_operation( $user_ids, $config ) {
        if ( $this->is_operation_running() ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_already_running',
                __( 'A bulk store credit adjustment is already in progress. Please wait for it to complete.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 409 )
            );
        }

        // Default the operation type to "adjust" for legacy callers that omit it. Reset/delete
        // operations don't carry an amount, so the negative-amount guard below only runs for
        // the adjust path.
        if ( ! isset( $config['operation_type'] ) || '' === $config['operation_type'] ) {
            $config['operation_type'] = $this->_constants->BULK_OPERATION_TYPE_ADJUST;
        }

        // Reject negative amounts at the boundary. The intended UX is `type=decrease`
        // with a positive amount; a negative input would otherwise be silently skipped
        // by apply_adjustment()'s `$amount <= 0` short-circuit and produce no ledger row.
        if (
            $this->_constants->BULK_OPERATION_TYPE_ADJUST === $config['operation_type']
            && isset( $config['amount'] )
            && (float) $config['amount'] < 0
        ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_amount',
                __( 'Bulk adjustment amount must be non-negative. Use type=decrease with a positive amount to reduce balances.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        $batch_size = (int) apply_filters( 'acfwp_bulk_adjust_batch_size', 200 );
        $stagger    = (int) apply_filters( 'acfwp_bulk_adjust_batch_stagger', 10 );
        $user_ids   = array_values( $user_ids );

        $operation = array(
            'operation_id'  => 'bulk_' . wp_generate_uuid4(),
            'status'        => 'queued',
            'total'         => count( $user_ids ),
            'processed'     => 0,
            'failed'        => 0,
            'config'        => $config,
            'admin_user_id' => get_current_user_id(),
            'created_at'    => current_time( 'mysql', true ),
            'completed_at'  => '',
            // Queue metadata — drives sequential batch scheduling in process_batch().
            'user_ids'      => $user_ids,
            'batch_size'    => $batch_size,
            'batch_index'   => 0,
        );

        // Clear any stale scheduled actions from previous operations before
        // writing new metadata so there is no window where a pending worker
        // could load the new operation through a stale action.
        as_unschedule_all_actions( $this->_constants->BULK_ADJUST_SCHEDULE_HOOK );

        // Save operation metadata.
        update_option( $this->_constants->BULK_ADJUST_IN_PROGRESS, $operation, false );

        // Schedule only the first batch; subsequent batches chain from process_batch().
        // This serialises batch execution and removes the read-modify-write race
        // that a parallel Action Scheduler runner could otherwise cause on the
        // BULK_ADJUST_IN_PROGRESS option.
        as_schedule_single_action(
            time() + $stagger,
            $this->_constants->BULK_ADJUST_SCHEDULE_HOOK,
            array( 0, $operation['operation_id'] ),
            'acfwp_bulk_adjust'
        );

        // Don't echo the full user_ids queue back to the caller.
        $response = $operation;
        unset( $response['user_ids'] );

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
     */

    /**
     * Process a single batch of store credit adjustments.
     *
     * This is the Action Scheduler callback. It processes each user in the batch,
     * creates Store_Credit_Entry records, and updates the operation progress.
     *
     * @since 4.0.8
     * @access public
     *
     * @param int    $batch_index  Zero-based index of the batch to process.
     * @param string $operation_id The operation ID to validate against.
     */
    public function process_batch( $batch_index, $operation_id ) {
        $operation = get_option( $this->_constants->BULK_ADJUST_IN_PROGRESS, array() );

        // Bail if operation doesn't exist or ID doesn't match (stale/cancelled job).
        if ( empty( $operation ) || $operation['operation_id'] !== $operation_id ) {
            return;
        }

        // Transition from queued to in_progress on first batch.
        if ( 'queued' === $operation['status'] ) {
            $operation['status'] = 'in_progress';
        }

        // Derive this batch's user IDs from the stored queue so that batches
        // don't need to carry their user ID list through Action Scheduler args.
        $batch_size = isset( $operation['batch_size'] ) ? (int) $operation['batch_size'] : 200;
        $all_ids    = isset( $operation['user_ids'] ) && is_array( $operation['user_ids'] ) ? $operation['user_ids'] : array();
        $batch_ids  = array_slice( $all_ids, (int) $batch_index * $batch_size, $batch_size );

        $operation_type = isset( $operation['config']['operation_type'] )
            ? $operation['config']['operation_type']
            : $this->_constants->BULK_OPERATION_TYPE_ADJUST;

        foreach ( $batch_ids as $user_id ) {
            switch ( $operation_type ) {
                case $this->_constants->BULK_OPERATION_TYPE_RESET:
                    $result = $this->apply_reset( absint( $user_id ), $operation['config'], $operation['admin_user_id'] );
                    break;
                case $this->_constants->BULK_OPERATION_TYPE_DELETE:
                    $result = $this->apply_delete( absint( $user_id ), $operation['config'], $operation['admin_user_id'] );
                    break;
                default:
                    $result = $this->apply_adjustment( absint( $user_id ), $operation['config'], $operation['admin_user_id'] );
                    break;
            }

            if ( is_wp_error( $result ) ) {
                ++$operation['failed'];
            } else {
                /**
                 * Fires after a single store credit entry is created during a bulk adjustment.
                 *
                 * @since 4.0.8
                 *
                 * @param Store_Credit_Entry|int $result    The created entry object, or 0 if skipped (zero balance).
                 * @param int                    $user_id   The user ID that was adjusted.
                 * @param array                  $operation The full operation metadata.
                 */
                do_action( 'acfwp_bulk_adjust_entry_created', $result, $user_id, $operation );
            }

            ++$operation['processed'];
        }

        $operation['batch_index'] = (int) $batch_index + 1;

        // Check if operation is complete.
        if ( $operation['processed'] >= $operation['total'] ) {
            $operation['status']       = 'completed';
            $operation['completed_at'] = current_time( 'mysql', true );

            // Drop the queue from the stored record — it isn't useful after completion
            // and keeping it bloats the option row for the 24h history window.
            unset( $operation['user_ids'] );

            // Move the finalised record out of the in-progress slot into a
            // separate history option so a subsequent schedule_bulk_operation()
            // call during the 24h cleanup window can't overwrite these figures.
            update_option( $this->_constants->BULK_ADJUST_LAST_COMPLETED, $operation, false );
            delete_option( $this->_constants->BULK_ADJUST_IN_PROGRESS );

            /**
             * Fires when a bulk store credit adjustment operation is completed.
             *
             * @since 4.0.8
             *
             * @param array $operation The full operation metadata.
             */
            do_action( 'acfwp_bulk_adjust_completed', $operation );

            // Schedule cleanup 24 hours after completion. Pass the operation ID
            // so cleanup only clears the history row it was scheduled for,
            // in case a new operation completes inside this window.
            as_schedule_single_action(
                time() + DAY_IN_SECONDS,
                'acfwp_bulk_adjust_cleanup',
                array( $operation['operation_id'] ),
                'acfwp_bulk_adjust'
            );

            return;
        }

        // Persist progress and schedule the next batch sequentially.
        update_option( $this->_constants->BULK_ADJUST_IN_PROGRESS, $operation, false );

        $stagger = (int) apply_filters( 'acfwp_bulk_adjust_batch_stagger', 10 );

        as_schedule_single_action(
            time() + $stagger,
            $this->_constants->BULK_ADJUST_SCHEDULE_HOOK,
            array( $operation['batch_index'], $operation_id ),
            'acfwp_bulk_adjust'
        );
    }

    /**
     * Calculate the unsigned adjustment amount for a single user.
     *
     * Single source of truth for both the preview (API_Bulk_Adjust::calculate_user_adjustment)
     * and the execution path (apply_adjustment) so the value displayed to admins matches
     * what is actually written.
     *
     * Routes through the `acfwp_bulk_adjust_calculated_amount` filter so the percentage
     * resolver (`calculate_percentage_amount()`, hooked in run()) and any third-party
     * overrides are applied uniformly. Then enforces the per-user decrease floor so the
     * amount never exceeds the available balance.
     *
     * @since 4.0.8
     * @access public
     *
     * @param float $balance Current store credit balance for the user.
     * @param array $config  Sanitized adjustment config (type, amount_mode, amount).
     * @param int   $user_id User ID being adjusted (forwarded to the filter; 0 for aggregate paths).
     * @return float Unsigned adjustment amount (>= 0).
     */
    public function calculate_amount_for_balance( $balance, $config, $user_id = 0 ) {
        $balance = (float) $balance;
        $type    = isset( $config['type'] ) ? $config['type'] : '';
        $base    = (float) ( isset( $config['amount'] ) ? $config['amount'] : 0 );

        /** This filter is documented at the apply_adjustment() call site below. */
        $amount = (float) apply_filters(
            'acfwp_bulk_adjust_calculated_amount',
            $base,
            (int) $user_id,
            $config,
            $balance
        );

        if ( 'decrease' === $type ) {
            $amount = max( 0, min( $amount, $balance ) );
        }

        return (float) $amount;
    }

    /**
     * Apply a store credit adjustment to a single user.
     *
     * Creates a Store_Credit_Entry record for the user. Resolves percentage amounts
     * from the user's current balance and caps decreases at the available balance
     * (zero-floor enforcement). Re-fetches the balance at execution time to avoid
     * race conditions between scheduling and execution.
     *
     * This method is public so it can be called directly by WP-CLI (Issue #08).
     *
     * @since 4.0.8
     * @access public
     *
     * @param int   $user_id      The user ID to adjust.
     * @param array $config       Adjustment configuration (type, amount_mode, amount, note).
     * @param int   $admin_user_id The admin user ID who initiated the operation.
     * @return Store_Credit_Entry|int|\WP_Error The created entry on success, 0 if skipped, WP_Error on failure.
     */
    public function apply_adjustment( $user_id, $config, $admin_user_id ) {
        // Resolve the user's live balance once if the math depends on it (percentage
        // resolution or the decrease zero-floor). For fixed increases we never read
        // the ledger. Passing this through to the helper means the percentage filter
        // callback doesn't re-fetch — without this, a percentage-decrease batch was
        // running get_customer_balance($id, true) twice per user, doubling ledger sums.
        $needs_balance = (
            ( isset( $config['type'] ) && 'decrease' === $config['type'] ) ||
            ( ! empty( $config['amount_mode'] ) && 'percentage' === $config['amount_mode'] )
        );
        $balance       = $needs_balance
            ? (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( absint( $user_id ), true )
            : 0.0;

        /**
         * Filter the calculated adjustment amount for a single user.
         *
         * The percentage resolver `calculate_percentage_amount()` is hooked here at
         * priority 10 (see run()), so the default value the filter sees is the raw
         * `$config['amount']` and listeners can replace it with the percentage-resolved
         * figure or any third-party override. Both the preview and the execution path
         * route through the shared helper `calculate_amount_for_balance()` so the
         * filter fires in both places and admins see what will actually be written.
         *
         * @since 4.0.8
         *
         * @param float      $amount  The adjustment amount.
         * @param int        $user_id The user ID being adjusted.
         * @param array      $config  The full adjustment configuration.
         * @param float|null $balance The user's live balance (always pre-fetched at
         *                            execution time when the math depends on it).
         */
        $amount = $this->calculate_amount_for_balance( $balance, $config, (int) $user_id );

        /*
         * No-op skip. Four cases land here:
         * 1. Percentage mode resolves to $0 for a user with a zero balance.
         * 2. Decrease against a zero balance — the helper's floor clamps to 0.
         * 3. Fixed mode with amount=0 (sanitize already rejects this at the API boundary,
         *    but we double-check here for direct WP-CLI callers).
         * 4. A negative resolved amount returned by a third-party filter.
         * Treated as a skip (not a failure) so it doesn't pollute the ledger or inflate
         * the batch failure counter.
         */
        if ( $amount <= 0 ) {
            return 0;
        }

        $entry = new Store_Credit_Entry();
        $entry->set_prop( 'user_id', absint( $user_id ) );
        $entry->set_prop( 'type', $config['type'] );
        $entry->set_prop( 'action', $this->_constants->BULK_ADJUST_ACTION );
        $entry->set_prop( 'amount', (float) wc_format_decimal( $amount ) );
        $entry->set_prop( 'object_id', absint( $admin_user_id ) );
        $entry->set_prop( 'note', isset( $config['note'] ) ? $config['note'] : '' );

        // Skip balance validation for decreases since we already handled zero-floor above.
        $skip_balance_validate = ( 'decrease' === $config['type'] );
        $check                 = $entry->save( $skip_balance_validate );

        if ( is_wp_error( $check ) ) {
            return $check;
        }

        return $entry;
    }

    /**
     * Apply a "reset to zero" operation to a single user.
     *
     * Writes a decrease entry for the user's full current balance so the ledger
     * preserves a complete audit trail of what was zeroed. Users with a zero (or
     * negative) balance are skipped — there is nothing to reset and inserting a
     * zero-amount ledger row would just add noise.
     *
     * @since 4.0.8
     * @access public
     *
     * @param int   $user_id       The user ID to reset.
     * @param array $config        Operation configuration (note, send_email).
     * @param int   $admin_user_id The admin user ID who initiated the operation.
     * @return Store_Credit_Entry|int|\WP_Error The created entry on success, 0 if skipped, WP_Error on failure.
     */
    public function apply_reset( $user_id, $config, $admin_user_id ) {
        $balance = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( absint( $user_id ), true );

        if ( $balance <= 0 ) {
            return 0;
        }

        $entry = new Store_Credit_Entry();
        $entry->set_prop( 'user_id', absint( $user_id ) );
        $entry->set_prop( 'type', 'decrease' );
        $entry->set_prop( 'action', $this->_constants->BULK_RESET_ACTION );
        $entry->set_prop( 'amount', (float) wc_format_decimal( $balance ) );
        $entry->set_prop( 'object_id', absint( $admin_user_id ) );
        $entry->set_prop( 'note', isset( $config['note'] ) ? $config['note'] : '' );

        // The zero-floor is enforced by construction (we just read the current balance),
        // so skip the redundant validation pass.
        $check = $entry->save( true );

        if ( is_wp_error( $check ) ) {
            return $check;
        }

        return $entry;
    }

    /**
     * Apply a "delete all entries" operation to a single user.
     *
     * Wipes every Store_Credit_Entry row belonging to the user and forcibly resets the
     * cached balance user-meta to zero. This is intentionally lossy — history is gone —
     * and is gated behind the destructive-operation confirmation in the UI.
     *
     * @since 4.0.8
     * @access public
     *
     * @param int   $user_id       The user ID whose entries should be deleted.
     * @param array $config        Operation configuration (note flows through to the audit log).
     * @param int   $admin_user_id The admin user ID who initiated the operation.
     * @return int|\WP_Error 1 on success, WP_Error on failure.
     */
    public function apply_delete( $user_id, $config, $admin_user_id ) {
        global $wpdb;

        $user_id       = absint( $user_id );
        $admin_user_id = absint( $admin_user_id );
        $table_name    = $wpdb->prefix . \ACFWF\Helpers\Plugin_Constants::STORE_CREDITS_DB_NAME;

        // Snapshot the pre-delete state so the audit log captures what was wiped.
        // The deletion itself leaves no row-level evidence behind.
        $pre_balance = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( $user_id, false );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->delete(
            $table_name,
            array( 'user_id' => $user_id ),
            array( '%d' )
        );
        // phpcs:enable

        if ( false === $deleted ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_delete_failed',
                __( 'Failed to delete store credit entries for the user.', 'advanced-coupons-for-woocommerce' ),
                array(
                    'status'  => 500,
                    'user_id' => $user_id,
                )
            );
        }

        // Force the cached balance meta to zero so subsequent reads don't return a
        // stale value derived from rows we just removed.
        update_user_meta( $user_id, \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_USER_BALANCE, 0 );

        // The destructive operation leaves no ledger trail by design — write a
        // structured audit entry to the WooCommerce logger so admins can always
        // trace who deleted what and when.
        $note = isset( $config['note'] ) ? (string) $config['note'] : '';
        wc_get_logger()->info(
            sprintf(
                'Bulk delete store credits: admin_user_id=%1$d user_id=%2$d entries_deleted=%3$d pre_balance=%4$s%5$s',
                $admin_user_id,
                $user_id,
                (int) $deleted,
                wc_format_decimal( $pre_balance, 2 ),
                '' !== $note ? ' note=' . wp_strip_all_tags( $note ) : ''
            ),
            array( 'source' => 'acfwp-bulk-store-credits' )
        );

        // Notify listeners (e.g., period stats cache invalidator) that totals
        // changed. Without this, reporting views can show stale numbers that
        // include the entries we just deleted.
        do_action( 'acfw_store_credits_total_changed', null );

        return 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Percentage Calculation
    |--------------------------------------------------------------------------
     */

    /**
     * Resolve a percentage-mode adjustment amount against the user's current balance.
     *
     * Hooked onto `acfwp_bulk_adjust_calculated_amount` at execution time so the
     * calculation uses the balance as it stands when the batch runs — never a stale
     * preview figure — which is the mitigation called out in the issue spec for the
     * preview/execute race.
     *
     * Rounds to `wc_get_price_decimals()` (passed explicitly to `wc_format_decimal()`)
     * so the resolved figure matches what the rest of the WooCommerce admin renders.
     * Note: `wc_format_decimal()` without the `$dp` argument formats to internal-math
     * precision (~6 dp), not display precision — so the explicit dp is required.
     *
     * @since 4.0.8
     * @access public
     *
     * @param float      $amount  Incoming amount (the raw percentage value when amount_mode=percentage).
     * @param int        $user_id User ID being adjusted.
     * @param array      $config  Full operation config.
     * @param float|null $balance Optional pre-fetched balance from apply_adjustment(); when null,
     *                            this method fetches it itself (e.g. external callers of the filter).
     * @return float Resolved dollar-amount for this user, or the input amount unchanged when not in percentage mode.
     */
    public function calculate_percentage_amount( $amount, $user_id, $config, $balance = null ) {
        if ( empty( $config['amount_mode'] ) || 'percentage' !== $config['amount_mode'] ) {
            return $amount;
        }

        if ( null === $balance ) {
            $balance = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( absint( $user_id ), true );
        }

        // Zero-balance users get a zero adjustment in percentage mode — a no-op that
        // apply_adjustment() will short-circuit before hitting Store_Credit_Entry::save().
        if ( $balance <= 0 ) {
            return 0.0;
        }

        return (float) wc_format_decimal( $balance * ( (float) $amount / 100 ), wc_get_price_decimals() );
    }

    /*
    |--------------------------------------------------------------------------
    | Operation Status & Lock
    |--------------------------------------------------------------------------
     */

    /**
     * Check if a bulk adjustment operation is currently running.
     *
     * @since 4.0.8
     * @access public
     *
     * @return bool True if an operation is queued or in progress.
     */
    public function is_operation_running() {
        $operation = get_option( $this->_constants->BULK_ADJUST_IN_PROGRESS, array() );

        if ( empty( $operation ) ) {
            return false;
        }

        return in_array( $operation['status'], array( 'queued', 'in_progress' ), true );
    }

    /**
     * Get the current operation status.
     *
     * Returns the in-progress operation if one exists; otherwise falls back to
     * the most recently completed operation (available for 24h post-completion);
     * otherwise returns an idle marker.
     *
     * @since 4.0.8
     * @access public
     *
     * @return array Operation metadata, or array with 'status' => 'idle' if no operation.
     */
    public function get_operation_status() {
        $operation = get_option( $this->_constants->BULK_ADJUST_IN_PROGRESS, array() );

        if ( ! empty( $operation ) ) {
            // Strip the user_ids queue — callers only need progress counters.
            unset( $operation['user_ids'] );
            return $operation;
        }

        $completed = get_option( $this->_constants->BULK_ADJUST_LAST_COMPLETED, array() );

        if ( ! empty( $completed ) ) {
            return $completed;
        }

        return array( 'status' => 'idle' );
    }

    /**
     * Permanently clear the last-completed history row regardless of the
     * scheduled 24h cleanup window.
     *
     * Called by DELETE /bulk/status so the admin's "Start New Adjustment"
     * click survives a page reload — without this the next status poll
     * resurfaces the completed record and the progress view re-appears.
     *
     * Intentionally a no-op when no completed record exists (idempotent).
     * Does NOT touch acfwp_bulk_adjust_in_progress; the REST callback's
     * is_operation_running() guard prevents reaching this method while a
     * job is still queued or processing.
     *
     * @since 4.0.8
     * @access public
     */
    public function dismiss_last_completed_operation() {
        delete_option( $this->_constants->BULK_ADJUST_LAST_COMPLETED );
    }

    /**
     * Clean up completed operation metadata.
     *
     * Called by Action Scheduler 24 hours after an operation completes. The
     * operation ID is passed through so we only clear the history row that
     * this cleanup was scheduled for — in case a newer operation has already
     * completed and replaced the last-completed record.
     *
     * @since 4.0.8
     * @access public
     *
     * @param string $operation_id The operation ID whose history row this cleanup should remove.
     */
    public function cleanup_operation( $operation_id = '' ) {
        $completed = get_option( $this->_constants->BULK_ADJUST_LAST_COMPLETED, array() );

        if ( empty( $completed ) ) {
            return;
        }

        // If no operation_id was passed (legacy scheduled action prior to this
        // refactor), fall back to clearing unconditionally.
        if ( '' === $operation_id || ( isset( $completed['operation_id'] ) && $completed['operation_id'] === $operation_id ) ) {
            delete_option( $this->_constants->BULK_ADJUST_LAST_COMPLETED );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Email Notifications
    |--------------------------------------------------------------------------
     */

    /**
     * Fire the store-credit adjustment email for a bulk-created entry, when enabled.
     *
     * Runs on the `acfwp_bulk_adjust_entry_created` action so emails are naturally
     * spread across Action Scheduler batches rather than flooding the queue at once.
     *
     * The email itself (ACFWF Store_Credit_Adjustment WC_Email) enforces its own
     * `is_enabled()` setting, so the `send_email` flag here is only an opt-in gate
     * for this particular bulk operation — it never overrides the global admin toggle.
     *
     * @since 4.0.8
     * @access public
     *
     * @param \ACFWF\Models\Objects\Store_Credit_Entry|int $result    The created entry, or 0 if skipped.
     * @param int                                          $user_id   The user ID that was adjusted.
     * @param array                                        $operation The full operation metadata.
     */
    public function maybe_send_entry_email( $result, $user_id, $operation ) {
        if ( ! ( $result instanceof Store_Credit_Entry ) ) {
            return;
        }

        if ( empty( $operation['config']['send_email'] ) ) {
            return;
        }

        $customer = $result->get_customer();
        if ( ! $customer || ! $customer->get_email() ) {
            return;
        }

        /**
         * Filter whether to send the adjustment email for this specific bulk entry.
         *
         * Allows integrations to suppress emails for specific users (e.g. unsubscribed
         * customers) without touching the global email toggle.
         *
         * @since 4.0.8
         *
         * @param bool                                         $should_send Whether to fire the email action.
         * @param \ACFWF\Models\Objects\Store_Credit_Entry     $result      The created entry.
         * @param int                                          $user_id     The user ID being adjusted.
         * @param array                                        $operation   The full operation metadata.
         */
        $should_send = (bool) apply_filters( 'acfwp_bulk_adjust_should_send_email', true, $result, $user_id, $operation );
        if ( ! $should_send ) {
            return;
        }

        do_action( 'acfwf_send_store_credit_adjustment_email', $result, $customer );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Type Registration
    |--------------------------------------------------------------------------
     */

    /**
     * Register the bulk_adjust action type in the store credit entry registry.
     *
     * Registered in both increase and decrease registries since the same
     * action type covers both directions.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $types Existing action types.
     * @return array Modified action types.
     */
    public function register_bulk_adjust_action_type( $types ) {
        $types[ $this->_constants->BULK_ADJUST_ACTION ] = array(
            'name'    => __( 'Bulk Adjustment', 'advanced-coupons-for-woocommerce' ),
            'slug'    => $this->_constants->BULK_ADJUST_ACTION,
            'related' => array(
                'object_type' => 'bulk_adjust',
                // Em-dash to match the UI's "no related object" convention and keep it translatable.
                'admin_label' => __( '—', 'advanced-coupons-for-woocommerce' ),
                'label'       => __( '—', 'advanced-coupons-for-woocommerce' ),
            ),
        );

        return $types;
    }

    /**
     * Register decrease-only bulk action types (currently just bulk_reset).
     *
     * Bulk reset is logically a decrease (it zeroes the balance via a `decrease` entry),
     * so it only ever surfaces in the decrease registry and is registered separately
     * from the dual-registry helper above.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $types Existing action types.
     * @return array Modified action types.
     */
    public function register_bulk_decrease_only_action_types( $types ) {
        $types[ $this->_constants->BULK_RESET_ACTION ] = array(
            'name'    => __( 'Bulk Reset', 'advanced-coupons-for-woocommerce' ),
            'slug'    => $this->_constants->BULK_RESET_ACTION,
            'related' => array(
                'object_type' => 'bulk_adjust',
                'admin_label' => __( '—', 'advanced-coupons-for-woocommerce' ),
                'label'       => __( '—', 'advanced-coupons-for-woocommerce' ),
            ),
        );

        return $types;
    }

    /*
    |--------------------------------------------------------------------------
    | Shared Helpers
    |--------------------------------------------------------------------------
     */

    /**
     * Bulk-fetch the cached store-credit balance for every user in $user_ids.
     *
     * A single SELECT … WHERE user_id IN (…) replaces one get_user_meta() call
     * per user. Used by both the REST preview and CLI dry-run aggregate paths.
     *
     * IMPORTANT — Cache semantics: this reads the cached `acfw_store_credit_balance`
     * usermeta, which is lazily refreshed by `Store_Credits_Calculate::get_customer_balance()`.
     * Between a ledger write and the next `get_customer_balance()` read for the
     * same user, this method may return a value that lags the actual ledger sum.
     * Per-row dry-run rendering (`render_dry_run` → `build_dry_run_rows`) triggers
     * the lazy refresh for visible users, but the aggregate footer total reads
     * the (potentially stale) cached values for the full match set. Callers that
     * need authoritative live balances after a CLI write should rely on
     * `get_customer_balance()` per user instead.
     *
     * @since 4.0.8
     * @access public
     *
     * @param int[] $user_ids User IDs to fetch balances for.
     * @return array<int,float> Map of user_id => cached balance (callers default
     *                          missing keys to 0.0 to match the live-balance behaviour).
     */
    public function fetch_balances_in_bulk( $user_ids ) {
        if ( empty( $user_ids ) ) {
            return array();
        }

        global $wpdb;

        $ids      = array_map( 'absint', $user_ids );
        $meta_key = \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_USER_BALANCE;
        $balances = array();

        // Chunk the IN () clause so the prepared SQL stays well under MySQL's
        // `max_allowed_packet` (commonly 4MB). The CLI raises the user cap
        // to 10M and a single ~1M-id IN () clause would otherwise build a
        // multi-megabyte SQL string. 5,000 ids per query keeps the statement
        // around 35KB while still amortising round-trips across batches.
        $chunk_size = (int) apply_filters( 'acfwp_bulk_adjust_fetch_balances_chunk_size', 5000 );
        if ( $chunk_size < 1 ) {
            $chunk_size = 5000;
        }

        foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $args         = array_merge( array( $meta_key ), $chunk );

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT user_id, CAST(meta_value AS DECIMAL(10,2)) AS balance FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ({$placeholders})",
                    $args
                ),
                ARRAY_A
            );
            // phpcs:enable

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $balances[ (int) $row['user_id'] ] = (float) $row['balance'];
                }
            }
        }

        return $balances;
    }

    /**
     * Build the display name for a user, falling back to display_name / login.
     *
     * Used by both the REST preview and CLI dry-run table so name formatting
     * is consistent across surfaces.
     *
     * @since 4.0.8
     * @access public
     *
     * @param \WP_User $user The user object.
     * @return string
     */
    public function format_user_name( $user ) {
        $first = trim( (string) $user->first_name );
        $last  = trim( (string) $user->last_name );

        if ( '' !== $first || '' !== $last ) {
            return trim( $first . ' ' . $last );
        }

        if ( ! empty( $user->display_name ) ) {
            return $user->display_name;
        }

        return $user->user_login;
    }

    /*
    |--------------------------------------------------------------------------
    | Execute Model
    |--------------------------------------------------------------------------
     */

    /**
     * Execute the model.
     *
     * @since 4.0.8
     * @access public
     * @implements \ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        // Action Scheduler batch processing callback.
        add_action( $this->_constants->BULK_ADJUST_SCHEDULE_HOOK, array( $this, 'process_batch' ), 10, 2 );

        // Cleanup callback (24 hours after operation completes).
        add_action( 'acfwp_bulk_adjust_cleanup', array( $this, 'cleanup_operation' ), 10, 1 );

        // Register bulk_adjust action type in both increase and decrease registries.
        add_filter( 'acfw_get_store_credits_increase_source_types', array( $this, 'register_bulk_adjust_action_type' ) );
        add_filter( 'acfw_get_store_credit_decrease_action_types', array( $this, 'register_bulk_adjust_action_type' ) );

        // bulk_reset is logically a decrease — register it only in the decrease registry.
        add_filter( 'acfw_get_store_credit_decrease_action_types', array( $this, 'register_bulk_decrease_only_action_types' ) );

        // Resolve percentage adjustments against each user's live balance at batch execution time.
        add_filter( 'acfwp_bulk_adjust_calculated_amount', array( $this, 'calculate_percentage_amount' ), 10, 4 );

        // Per-entry email notification (gated by config.send_email + the email's own is_enabled()).
        add_action( 'acfwp_bulk_adjust_entry_created', array( $this, 'maybe_send_entry_email' ), 10, 3 );
    }
}
