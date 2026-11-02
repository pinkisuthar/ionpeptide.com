<?php
namespace ACFWP\Models\REST_API;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Abstracts\Base_Model;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Model_Interface;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for bulk store credit adjustments.
 *
 * Public Model.
 *
 * @since 4.0.8
 */
class API_Bulk_Adjust extends Base_Model implements Model_Interface {
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
    | REST API Routes
    |--------------------------------------------------------------------------
     */

    /**
     * Register REST API routes for bulk store credit adjustments.
     *
     * @since 4.0.8
     * @access public
     */
    public function register_routes() {
        \register_rest_route(
            \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_API_NAMESPACE,
            '/bulk',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'schedule_bulk_operation' ),
                ),
            )
        );

        \register_rest_route(
            \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_API_NAMESPACE,
            '/bulk/preview',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'get_bulk_preview' ),
                ),
            )
        );

        \register_rest_route(
            \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_API_NAMESPACE,
            '/bulk/status',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'get_operation_status' ),
                ),
                array(
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'dismiss_completed_operation' ),
                ),
            )
        );

        \register_rest_route(
            \ACFWF\Helpers\Plugin_Constants::STORE_CREDIT_API_NAMESPACE,
            '/bulk/export',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'permission_callback' => array( $this, 'get_admin_permissions_check' ),
                    'callback'            => array( $this, 'export_bulk_users' ),
                ),
            )
        );
    }

    /**
     * Admin permissions check for REST API endpoints.
     *
     * @since 4.0.8
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return bool|\WP_Error True if the request has access, WP_Error object otherwise.
     */
    public function get_admin_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return new \WP_Error(
                'rest_forbidden_context',
                __( 'Sorry, you are not allowed to access this resource.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | REST API Callbacks
    |--------------------------------------------------------------------------
     */

    /**
     * REST callback to schedule a bulk store credit operation.
     *
     * Accepts `filters` rather than a raw `user_ids` list — the set of affected users
     * is resolved server-side via Bulk_Adjust::get_filtered_users() to keep the
     * scheduling contract consistent with the preview endpoint. Legacy callers that
     * still pass `user_ids` are supported by mapping the IDs onto `filters['include_users']`,
     * and the endpoint refuses to run when no filter is supplied so an empty payload
     * cannot silently widen to "every user on the site".
     *
     * @since 4.0.8
     *
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return \WP_REST_Response|\WP_Error Response object on success, WP_Error on failure.
     */
    public function schedule_bulk_operation( $request ) {
        $config = $this->sanitize_adjustment( $request->get_param( 'adjustment' ) );
        if ( is_wp_error( $config ) ) {
            return $config;
        }

        $filters = $this->sanitize_filters( $request->get_param( 'filters' ) );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }

        // Backward compatibility: legacy callers passed `user_ids` directly. Map
        // them onto `filters['include_users']` so an existing targeted request
        // continues to hit only those users instead of being read as "no filters"
        // (which under the new contract resolves to the entire customer base).
        $legacy_user_ids = $request->get_param( 'user_ids' );
        if ( ! empty( $legacy_user_ids ) && is_array( $legacy_user_ids ) ) {
            $legacy_user_ids = array_values( array_filter( array_map( 'absint', $legacy_user_ids ) ) );
            if ( ! empty( $legacy_user_ids ) ) {
                $existing                 = isset( $filters['include_users'] ) ? $filters['include_users'] : array();
                $filters['include_users'] = array_values( array_unique( array_merge( $existing, $legacy_user_ids ) ) );
            }
        }

        // Refuse to schedule against every user unless the caller has explicitly
        // opted in via at least one filter constraint or include list. The same
        // guard is applied to preview/export so all three endpoints share the
        // same blast-radius contract — see require_bounded_filters().
        $bounded = $this->require_bounded_filters( $filters );
        if ( is_wp_error( $bounded ) ) {
            return $bounded;
        }

        $over_cap = false;
        $user_ids = \ACFWP()->Bulk_Adjust->get_filtered_users( $filters, $over_cap );

        if ( $over_cap ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_too_many_users',
                __( 'The filters matched too many users to process in a single bulk operation. Please narrow your filters and try again.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 413 )
            );
        }

        if ( empty( $user_ids ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_no_users',
                __( 'No users matched the provided filters.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        $result = \ACFWP()->Bulk_Adjust->schedule_bulk_operation( $user_ids, $config );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'message' => __( 'Bulk store credit adjustment has been scheduled.', 'advanced-coupons-for-woocommerce' ),
                'data'    => $result,
            )
        );
    }

    /**
     * REST callback to preview users affected by a bulk store credit adjustment.
     *
     * Returns a paginated slice of matched users with their projected new balance,
     * plus the total user count and projected total adjustment amount across all
     * matches (not just the current page).
     *
     * @since 4.0.8
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return \WP_REST_Response|\WP_Error Response object on success, WP_Error on failure.
     */
    public function get_bulk_preview( $request ) {
        $config = $this->sanitize_adjustment( $request->get_param( 'adjustment' ) );
        if ( is_wp_error( $config ) ) {
            return $config;
        }

        $filters = $this->sanitize_filters( $request->get_param( 'filters' ) );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }

        $bounded = $this->require_bounded_filters( $filters );
        if ( is_wp_error( $bounded ) ) {
            return $bounded;
        }

        $page     = max( 1, absint( $request->get_param( 'page' ) ) );
        $per_page = absint( $request->get_param( 'per_page' ) );
        $per_page = ( $per_page > 0 && $per_page <= 100 ) ? $per_page : 20;

        $over_cap = false;
        $user_ids = \ACFWP()->Bulk_Adjust->get_filtered_users( $filters, $over_cap );

        if ( $over_cap ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_too_many_users',
                __( 'The filters matched too many users to preview at once. Please narrow your filters and try again.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 413 )
            );
        }

        $total_users = count( $user_ids );
        $total_pages = $total_users > 0 ? (int) ceil( $total_users / $per_page ) : 0;

        // Compute aggregate total amount across all matches — cheap for fixed, requires a
        // single SUM query on the balance meta for percentage mode to avoid loading
        // every matched user's meta row individually.
        $total_amount = $this->calculate_total_adjustment( $user_ids, $config );

        // Build page slice.
        $offset         = ( $page - 1 ) * $per_page;
        $page_user_ids  = $total_users > 0 ? array_slice( $user_ids, $offset, $per_page ) : array();
        $users_response = array();

        if ( ! empty( $page_user_ids ) ) {
            // cache_users() loads WP_User objects and primes the user_meta cache for each
            // ID in a single batched query, so we don't need to call update_meta_cache()
            // before it — that would be a redundant round-trip.
            cache_users( $page_user_ids );

            foreach ( $page_user_ids as $uid ) {
                $user = get_user_by( 'id', $uid );
                if ( ! $user ) {
                    continue;
                }

                $balance           = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( $uid, false );
                $adjustment_amount = $this->calculate_user_adjustment( $balance, $config, (int) $uid );
                $signed            = ( 'decrease' === $config['type'] ) ? -1 * $adjustment_amount : $adjustment_amount;
                $new_balance       = max( 0, $balance + $signed );

                $users_response[] = array(
                    'user_id'           => (int) $uid,
                    'name'              => \ACFWP()->Bulk_Adjust->format_user_name( $user ),
                    'email'             => $user->user_email,
                    'role'              => ! empty( $user->roles ) ? $user->roles[0] : '',
                    'current_balance'   => (float) wc_format_decimal( $balance, 2 ),
                    'adjustment_amount' => (float) wc_format_decimal( $adjustment_amount, 2 ),
                    'new_balance'       => (float) wc_format_decimal( $new_balance, 2 ),
                );
            }
        }

        return rest_ensure_response(
            array(
                'message' => __( 'Bulk adjustment preview generated.', 'advanced-coupons-for-woocommerce' ),
                'data'    => array(
                    'total_users'  => $total_users,
                    'total_amount' => (float) wc_format_decimal( $total_amount, 2 ),
                    'users'        => $users_response,
                    'page'         => $page,
                    'per_page'     => $per_page,
                    'total_pages'  => $total_pages,
                ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
     */

    /**
     * Sanitize and validate the raw `adjustment` request param.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param mixed $adjustment Raw adjustment param from the request.
     * @return array|\WP_Error Sanitized config array on success, WP_Error on failure.
     */
    protected function sanitize_adjustment( $adjustment ) {
        if ( empty( $adjustment ) || ! is_array( $adjustment ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_missing_adjustment',
                __( 'Adjustment configuration is required.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        // Operation type drives whether the amount-related fields apply. Defaults to
        // 'adjust' so legacy callers (no operation_type) keep the old behaviour.
        $allowed_operation_types = array(
            $this->_constants->BULK_OPERATION_TYPE_ADJUST,
            $this->_constants->BULK_OPERATION_TYPE_RESET,
            $this->_constants->BULK_OPERATION_TYPE_DELETE,
        );
        $operation_type          = isset( $adjustment['operation_type'] )
            ? sanitize_text_field( $adjustment['operation_type'] )
            : $this->_constants->BULK_OPERATION_TYPE_ADJUST;
        if ( ! in_array( $operation_type, $allowed_operation_types, true ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_operation_type',
                __( 'Operation type must be one of "adjust", "reset", or "delete".', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        // Emails are opt-in per bulk operation; the email class itself still enforces its
        // own global is_enabled() setting, so send_email=true never overrides a globally-disabled email.
        $send_email = isset( $adjustment['send_email'] ) ? (bool) rest_sanitize_boolean( $adjustment['send_email'] ) : false;
        $note       = isset( $adjustment['note'] ) ? sanitize_text_field( $adjustment['note'] ) : '';

        // Reset/delete don't carry a meaningful type/amount/amount_mode — the per-user
        // work derives those server-side from the user's live balance (or wipes the row
        // set entirely). Return a minimal config so apply_reset/apply_delete don't have
        // to guard against missing keys.
        if ( $this->_constants->BULK_OPERATION_TYPE_ADJUST !== $operation_type ) {
            return array(
                'operation_type' => $operation_type,
                'type'           => '',
                'amount_mode'    => '',
                'amount'         => 0,
                'note'           => $note,
                'send_email'     => $send_email,
            );
        }

        $type = isset( $adjustment['type'] ) ? sanitize_text_field( $adjustment['type'] ) : '';
        if ( ! in_array( $type, array( 'increase', 'decrease' ), true ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_type',
                __( 'Adjustment type must be "increase" or "decrease".', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        $amount = isset( $adjustment['amount'] ) ? floatval( $adjustment['amount'] ) : 0;
        if ( $amount <= 0 ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_amount',
                __( 'Adjustment amount must be greater than zero.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        $amount_mode = isset( $adjustment['amount_mode'] ) ? sanitize_text_field( $adjustment['amount_mode'] ) : 'fixed';
        if ( ! in_array( $amount_mode, array( 'fixed', 'percentage' ), true ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_amount_mode',
                __( 'Adjustment amount mode must be "fixed" or "percentage".', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        // Guard against nonsensical percentage values (>100% decrease is meaningless
        // and >1000% increase is almost certainly a UI bug).
        if ( 'percentage' === $amount_mode && $amount > 1000 ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_percentage',
                __( 'Percentage amount is out of range.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        return array(
            'operation_type' => $operation_type,
            'type'           => $type,
            'amount_mode'    => $amount_mode,
            'amount'         => $amount,
            'note'           => $note,
            'send_email'     => $send_email,
        );
    }

    /**
     * Sanitize the raw `filters` request param.
     *
     * Accepts an empty/missing filter object — the model treats that as "match all users"
     * which is useful for previewing the full customer base.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param mixed $filters Raw filters param from the request.
     * @return array|\WP_Error Sanitized filter array on success, WP_Error on failure.
     */
    protected function sanitize_filters( $filters ) {
        if ( null === $filters || '' === $filters ) {
            return array();
        }

        if ( ! is_array( $filters ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_invalid_filters',
                __( 'Filters must be provided as an object.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        $clean = array();

        if ( ! empty( $filters['roles'] ) && is_array( $filters['roles'] ) ) {
            $clean['roles'] = array_values( array_filter( array_map( 'sanitize_key', $filters['roles'] ) ) );
        }

        if ( isset( $filters['balance_min'] ) && '' !== $filters['balance_min'] ) {
            $clean['balance_min'] = max( 0, (float) $filters['balance_min'] );
        }
        if ( isset( $filters['balance_max'] ) && '' !== $filters['balance_max'] ) {
            $clean['balance_max'] = max( 0, (float) $filters['balance_max'] );
        }

        foreach ( array( 'registered_after', 'registered_before', 'last_order_after', 'last_order_before' ) as $date_key ) {
            if ( ! empty( $filters[ $date_key ] ) ) {
                $clean[ $date_key ] = sanitize_text_field( $filters[ $date_key ] );
            }
        }

        foreach ( array( 'include_users', 'exclude_users' ) as $list_key ) {
            if ( ! empty( $filters[ $list_key ] ) && is_array( $filters[ $list_key ] ) ) {
                $clean[ $list_key ] = array_values( array_filter( array_map( 'absint', $filters[ $list_key ] ) ) );
            }
        }

        return $clean;
    }

    /**
     * Refuse to run against the entire customer base when no filter is supplied.
     *
     * Shared guard for `/bulk`, `/bulk/preview`, and `/bulk/export`. Each of these
     * endpoints reads or mutates data scoped to the matched-user set, so an empty
     * filter payload would silently widen to "every customer on the site". The
     * three endpoints fail closed in lockstep so an unbounded request can't slip
     * past one entry point while being blocked at another.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param array $filters Sanitized filter array.
     * @return true|\WP_Error True when bounded, WP_Error on empty filters.
     */
    protected function require_bounded_filters( $filters ) {
        if ( empty( $filters ) ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_unbounded_filters',
                __( 'At least one filter or user must be specified.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 400 )
            );
        }

        return true;
    }

    /**
     * Calculate the adjustment amount for a single user given their current balance.
     *
     * Thin wrapper around Bulk_Adjust::calculate_amount_for_balance() so the preview
     * row figure and the execution-time write share a single source of truth — both
     * paths route through the same `acfwp_bulk_adjust_calculated_amount` filter chain.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param float $balance Current balance.
     * @param array $config  Sanitized adjustment config.
     * @param int   $user_id User ID being previewed (forwarded to the filter; 0 when unknown).
     * @return float Unsigned adjustment amount (always >= 0).
     */
    protected function calculate_user_adjustment( $balance, $config, $user_id = 0 ) {
        return (float) \ACFWP()->Bulk_Adjust->calculate_amount_for_balance( $balance, $config, (int) $user_id );
    }

    /**
     * Calculate the projected total adjustment amount across every matched user.
     *
     * Fixed-increase is a trivial multiplication. Decrease and percentage modes pull
     * every matched user's balance in one query and then accumulate via the same
     * per-user helper the row preview uses, so the displayed total can never exceed
     * Σ(adjustment_amount) for the rendered rows.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param int[] $user_ids Matched user IDs.
     * @param array $config   Sanitized adjustment config.
     * @return float Projected total adjustment.
     */
    protected function calculate_total_adjustment( $user_ids, $config ) {
        if ( empty( $user_ids ) ) {
            return 0.0;
        }

        $needs_balance = 'percentage' === $config['amount_mode'] || 'decrease' === $config['type'];

        // Fixed-increase doesn't depend on per-user balance — the per-user amount is
        // identical for every matched user.
        if ( ! $needs_balance ) {
            return count( $user_ids ) * (float) $config['amount'];
        }

        $balances = \ACFWP()->Bulk_Adjust->fetch_balances_in_bulk( $user_ids );

        $total = 0.0;
        foreach ( $user_ids as $uid ) {
            $balance = isset( $balances[ (int) $uid ] ) ? $balances[ (int) $uid ] : 0.0;
            $total  += $this->calculate_user_adjustment( $balance, $config, (int) $uid );
        }

        return (float) $total;
    }

    /**
     * Escape a CSV cell value to defuse spreadsheet formula injection.
     *
     * If the value starts with a character that Excel/Sheets interprets as a
     * formula trigger ("=", "+", "-", "@", TAB, CR), prefix a single quote so
     * the cell is rendered as text.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param string $value Raw cell value.
     * @return string Cell value safe for CSV output.
     */
    protected function escape_csv_value( $value ) {
        $value = (string) $value;

        if ( '' === $value ) {
            return $value;
        }

        if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * REST callback to get the current bulk operation status.
     *
     * @since 4.0.8
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return \WP_REST_Response Response object.
     */
    public function get_operation_status( $request ) {
        return rest_ensure_response(
            array(
                'message' => __( 'Bulk adjustment status retrieved.', 'advanced-coupons-for-woocommerce' ),
                'data'    => \ACFWP()->Bulk_Adjust->get_operation_status(),
            )
        );
    }

    /**
     * REST callback to dismiss a completed/failed bulk operation record.
     *
     * Lets the admin permanently dismiss the last-completed history row so
     * "Start New Adjustment" actually starts fresh on subsequent page loads.
     * Refuses to clear an in-progress record so an in-flight operation can't
     * be hidden from the UI.
     *
     * @since 4.0.8
     * @access public
     *
     * @param \WP_REST_Request $_request Full details about the request.
     * @return \WP_REST_Response|\WP_Error Response object on success, WP_Error on failure.
     */
    public function dismiss_completed_operation( $_request ) {
        if ( \ACFWP()->Bulk_Adjust->is_operation_running() ) {
            return new \WP_Error(
                'acfwp_bulk_adjust_in_progress',
                __( 'Cannot dismiss an in-progress operation.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 409 )
            );
        }

        \ACFWP()->Bulk_Adjust->dismiss_last_completed_operation();

        return rest_ensure_response(
            array(
                'message' => __( 'Bulk adjustment record dismissed.', 'advanced-coupons-for-woocommerce' ),
                'data'    => \ACFWP()->Bulk_Adjust->get_operation_status(),
            )
        );
    }

    /**
     * REST callback to export matched users as CSV-ready data.
     *
     * Returns the full filtered user list with each user's current balance so the
     * admin can save a snapshot before running a destructive bulk operation
     * (reset/delete). The frontend serializes the rows to CSV via
     * react-csv-downloader — this endpoint never streams CSV directly.
     *
     * @since 4.0.8
     * @access public
     *
     * @param \WP_REST_Request $request Full details about the request.
     * @return \WP_REST_Response|\WP_Error Response object on success, WP_Error on failure.
     */
    public function export_bulk_users( $request ) {
        $filters = $this->sanitize_filters( $request->get_param( 'filters' ) );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }

        $bounded = $this->require_bounded_filters( $filters );
        if ( is_wp_error( $bounded ) ) {
            return $bounded;
        }

        $over_cap = false;
        $user_ids = \ACFWP()->Bulk_Adjust->get_filtered_users( $filters, $over_cap );

        if ( $over_cap ) {
            return new \WP_Error(
                'acfwp_export_over_cap',
                __( 'The filters matched too many users to export at once. Please narrow your filters and try again.', 'advanced-coupons-for-woocommerce' ),
                array( 'status' => 413 )
            );
        }

        $headers = array(
            'user_id'         => __( 'User ID', 'advanced-coupons-for-woocommerce' ),
            'email'           => __( 'Email', 'advanced-coupons-for-woocommerce' ),
            'display_name'    => __( 'Display Name', 'advanced-coupons-for-woocommerce' ),
            'current_balance' => __( 'Current Balance', 'advanced-coupons-for-woocommerce' ),
            'registered_at'   => __( 'Registered At', 'advanced-coupons-for-woocommerce' ),
        );

        $rows = array();

        if ( ! empty( $user_ids ) ) {
            // Batch-prime the user/user-meta caches so per-row get_user_by() and
            // get_customer_balance() calls don't trigger N+1 reads. cache_users()
            // accepts the full array at once.
            cache_users( $user_ids );

            foreach ( $user_ids as $uid ) {
                $user = get_user_by( 'id', $uid );
                if ( ! $user ) {
                    continue;
                }

                // Read the cached balance instead of forcing a per-user recalc.
                // A forced refresh at 50k users issues ~100k SUM queries on one request.
                $balance = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( $uid, false );

                $rows[] = array(
                    'user_id'         => (int) $uid,
                    'email'           => $this->escape_csv_value( $user->user_email ),
                    'display_name'    => $this->escape_csv_value( \ACFWP()->Bulk_Adjust->format_user_name( $user ) ),
                    'current_balance' => (float) wc_format_decimal( $balance, 2 ),
                    'registered_at'   => $this->escape_csv_value( $user->user_registered ),
                );
            }
        }

        return rest_ensure_response(
            array(
                'message' => __( 'Bulk adjustment export generated.', 'advanced-coupons-for-woocommerce' ),
                'data'    => array(
                    'headers' => $headers,
                    'rows'    => $rows,
                ),
            )
        );
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
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }
}
