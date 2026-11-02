<?php
namespace ACFWP\Models\Store_Credits\CLI;

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
 * WP-CLI command for bulk store credit adjustments.
 *
 * Registers `wp acfwp store-credits bulk-adjust` and reuses the existing
 * Bulk_Adjust model methods synchronously (no Action Scheduler) with a
 * progress bar suitable for long CLI runs.
 *
 * Public Model.
 *
 * @since 4.0.8
 */
class Bulk_Adjust_Command extends Base_Model implements Model_Interface {
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
    | Command Registration
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
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        \WP_CLI::add_command(
            'acfwp store-credits bulk-adjust',
            array( $this, 'invoke' ),
            array(
                'shortdesc' => 'Bulk adjust store credit balances synchronously.',
                'longdesc'  => "## EXAMPLES\n\n"
                    . "    # Increase balance by \$5 for all customers\n"
                    . "    $ wp acfwp store-credits bulk-adjust --type=increase --amount=5 --role=customer\n\n"
                    . "    # Decrease balance by 10% for customers registered before a date\n"
                    . "    $ wp acfwp store-credits bulk-adjust --type=decrease --amount=10 --amount-mode=percentage --registered-before=2024-01-01\n\n"
                    . "    # Reset to zero for matched users without confirmation\n"
                    . "    $ wp acfwp store-credits bulk-adjust --type=reset --role=customer --yes\n\n"
                    . "    # Preview affected users (dry run)\n"
                    . '    $ wp acfwp store-credits bulk-adjust --type=increase --amount=5 --role=customer --dry-run',
                'synopsis'  => array(
                    array(
                        'type'        => 'assoc',
                        'name'        => 'type',
                        'description' => 'Operation type.',
                        'optional'    => false,
                        'options'     => array( 'increase', 'decrease', 'reset', 'delete' ),
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'amount',
                        'description' => 'Numeric amount (required for increase/decrease).',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'amount-mode',
                        'description' => 'Fixed or percentage.',
                        'optional'    => true,
                        'default'     => 'fixed',
                        'options'     => array( 'fixed', 'percentage' ),
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'role',
                        'description' => 'Comma-separated user roles.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'balance-min',
                        'description' => 'Minimum balance.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'balance-max',
                        'description' => 'Maximum balance.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'registered-after',
                        'description' => 'YYYY-MM-DD.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'registered-before',
                        'description' => 'YYYY-MM-DD.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'last-order-after',
                        'description' => 'YYYY-MM-DD.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'last-order-before',
                        'description' => 'YYYY-MM-DD.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'include',
                        'description' => 'Comma-separated user IDs to add.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'exclude',
                        'description' => 'Comma-separated user IDs to remove.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'note',
                        'description' => 'Admin note attached to each entry.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'flag',
                        'name'        => 'send-email',
                        'description' => 'Send adjustment email per affected user.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'flag',
                        'name'        => 'dry-run',
                        'description' => 'Preview matched users without executing.',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'assoc',
                        'name'        => 'batch-size',
                        'description' => 'Users per chunk (default 200).',
                        'optional'    => true,
                    ),
                    array(
                        'type'        => 'flag',
                        'name'        => 'yes',
                        'description' => 'Skip the interactive confirmation.',
                        'optional'    => true,
                    ),
                ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Command Body
    |--------------------------------------------------------------------------
     */

    /**
     * Handle the bulk-adjust CLI command.
     *
     * Mirrors the REST API contract: parses + validates flags into the same
     * `$config` and `$filters` shapes the model already understands, refuses
     * unbounded filter sets, resolves matched users, optionally previews them,
     * and otherwise iterates each user synchronously through the existing
     * apply_* model methods. Fires `acfwp_bulk_adjust_entry_created` for every
     * non-WP_Error result — same condition as the Action Scheduler path
     * (Bulk_Adjust::process_batch()) — so the per-user email hook and any
     * third-party listeners receive every CLI entry, including int 0/1 results
     * from reset/delete operations.
     *
     * @since 4.0.8
     * @access public
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative flags from the synopsis.
     */
    public function invoke( $args, $assoc_args ) {
        $raw_type = isset( $assoc_args['type'] ) ? sanitize_text_field( $assoc_args['type'] ) : '';
        if ( ! in_array( $raw_type, array( 'increase', 'decrease', 'reset', 'delete' ), true ) ) {
            \WP_CLI::error( __( 'Invalid --type. Must be one of: increase, decrease, reset, delete.', 'advanced-coupons-for-woocommerce' ) );
        }

        // Build the $config payload (same shape as API_Bulk_Adjust::sanitize_adjustment()).
        $note       = isset( $assoc_args['note'] ) ? sanitize_text_field( $assoc_args['note'] ) : '';
        $send_email = ! empty( $assoc_args['send-email'] );

        if ( 'reset' === $raw_type || 'delete' === $raw_type ) {
            $operation_type = ( 'reset' === $raw_type )
                ? $this->_constants->BULK_OPERATION_TYPE_RESET
                : $this->_constants->BULK_OPERATION_TYPE_DELETE;

            $config = array(
                'operation_type' => $operation_type,
                'type'           => '',
                'amount_mode'    => '',
                'amount'         => 0,
                'note'           => $note,
                'send_email'     => $send_email,
            );
        } else {
            $amount = isset( $assoc_args['amount'] ) ? floatval( $assoc_args['amount'] ) : 0;
            if ( $amount <= 0 ) {
                \WP_CLI::error( __( 'Adjustment amount must be greater than zero.', 'advanced-coupons-for-woocommerce' ) );
            }

            $amount_mode = isset( $assoc_args['amount-mode'] ) ? sanitize_text_field( $assoc_args['amount-mode'] ) : 'fixed';
            if ( ! in_array( $amount_mode, array( 'fixed', 'percentage' ), true ) ) {
                \WP_CLI::error( __( 'Invalid --amount-mode. Must be "fixed" or "percentage".', 'advanced-coupons-for-woocommerce' ) );
            }

            if ( 'percentage' === $amount_mode && $amount > 1000 ) {
                \WP_CLI::error( __( 'Percentage amount is out of range.', 'advanced-coupons-for-woocommerce' ) );
            }

            $config = array(
                'operation_type' => $this->_constants->BULK_OPERATION_TYPE_ADJUST,
                'type'           => $raw_type,
                'amount_mode'    => $amount_mode,
                'amount'         => $amount,
                'note'           => $note,
                'send_email'     => $send_email,
            );
        }

        // Build the $filters payload (same shape as API_Bulk_Adjust::sanitize_filters()).
        $filters = array();

        if ( ! empty( $assoc_args['role'] ) ) {
            $roles = $this->parse_csv_role_list( $assoc_args['role'] );
            if ( ! empty( $roles ) ) {
                $filters['roles'] = $roles;
            }
        }

        if ( isset( $assoc_args['balance-min'] ) && '' !== $assoc_args['balance-min'] ) {
            $filters['balance_min'] = max( 0, (float) $assoc_args['balance-min'] );
        }
        if ( isset( $assoc_args['balance-max'] ) && '' !== $assoc_args['balance-max'] ) {
            $filters['balance_max'] = max( 0, (float) $assoc_args['balance-max'] );
        }

        foreach ( array( 'registered-after', 'registered-before', 'last-order-after', 'last-order-before' ) as $flag ) {
            if ( ! empty( $assoc_args[ $flag ] ) ) {
                $value = sanitize_text_field( $assoc_args[ $flag ] );

                // Enforce the documented YYYY-MM-DD shape — sanitize_text_field()
                // would otherwise accept any string and silently produce a query
                // that matches no users, surfacing as a confusing
                // "No users matched the provided filters." error.
                $parsed = \DateTime::createFromFormat( 'Y-m-d', $value );
                if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
                    \WP_CLI::error(
                        sprintf(
                            /* translators: 1: flag name (e.g. registered-after), 2: invalid value supplied. */
                            __( 'Invalid --%1$s value: %2$s. Expected YYYY-MM-DD.', 'advanced-coupons-for-woocommerce' ),
                            $flag,
                            $value
                        )
                    );
                }

                $key             = str_replace( '-', '_', $flag );
                $filters[ $key ] = $value;
            }
        }

        if ( ! empty( $assoc_args['include'] ) ) {
            $include = $this->parse_csv_int_list( $assoc_args['include'] );
            if ( ! empty( $include ) ) {
                $filters['include_users'] = $include;
            }
        }
        if ( ! empty( $assoc_args['exclude'] ) ) {
            $exclude = $this->parse_csv_int_list( $assoc_args['exclude'] );
            if ( ! empty( $exclude ) ) {
                $filters['exclude_users'] = $exclude;
            }
        }

        // Mirror the REST guard — refuse unbounded runs that would silently
        // resolve to the entire customer base.
        if ( empty( $filters ) ) {
            \WP_CLI::error( __( 'At least one filter (role/balance/date/include) must be specified.', 'advanced-coupons-for-woocommerce' ) );
        }

        // Resolve the admin user ID. CLI runs typically have no current user, so
        // fall back to the first administrator account on the site so audit logs
        // and entry attribution land on a real user.
        $admin_id = (int) get_current_user_id();
        if ( $admin_id <= 0 ) {
            $admins = get_users(
                array(
                    'role'   => 'administrator',
                    'number' => 1,
                    'fields' => 'ID',
                )
            );
            if ( ! empty( $admins ) ) {
                $admin_id = (int) $admins[0];
            }
        }
        if ( $admin_id <= 0 ) {
            \WP_CLI::error( __( 'No administrator user found to attribute the operation to.', 'advanced-coupons-for-woocommerce' ) );
        }

        // Raise the safety cap for the duration of this CLI run only. The cap
        // exists to protect REST/UI from OOM; CLI runs are explicit and can
        // tolerate the larger working set. Use try/finally so a third-party
        // filter throwing during get_filtered_users() can't leave the raised
        // cap attached for the rest of the request.
        add_filter( 'acfwp_bulk_adjust_max_users', array( $this, 'raise_cli_user_cap' ), 999 );

        $over_cap = false;
        try {
            $user_ids = \ACFWP()->Bulk_Adjust->get_filtered_users( $filters, $over_cap );
        } finally {
            remove_filter( 'acfwp_bulk_adjust_max_users', array( $this, 'raise_cli_user_cap' ), 999 );
        }

        if ( $over_cap ) {
            \WP_CLI::warning( __( 'The matched user count exceeds the bulk-adjust safety cap. A third-party filter may be clamping the cap below the CLI limit.', 'advanced-coupons-for-woocommerce' ) );
        }

        if ( empty( $user_ids ) ) {
            \WP_CLI::error( __( 'No users matched the provided filters.', 'advanced-coupons-for-woocommerce' ) );
        }

        if ( ! empty( $assoc_args['dry-run'] ) ) {
            $this->render_dry_run( $user_ids, $config );
            return;
        }

        if ( empty( $assoc_args['yes'] ) ) {
            if ( $this->_constants->BULK_OPERATION_TYPE_DELETE === $config['operation_type'] ) {
                \WP_CLI::log( __( 'WARNING: Delete is irreversible — every store credit entry for the matched users will be permanently removed.', 'advanced-coupons-for-woocommerce' ) );
            }
            \WP_CLI::confirm(
                sprintf(
                    /* translators: 1: operation type (increase/decrease/reset/delete), 2: number of matched users. */
                    __( 'Apply %1$s to %2$d users?', 'advanced-coupons-for-woocommerce' ),
                    $raw_type,
                    count( $user_ids )
                ),
                $assoc_args
            );
        }

        $batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 200;

        // Build the operation payload in the same shape the Action-Scheduler
        // path uses (Bulk_Adjust::schedule_bulk_operation()). Listeners on
        // `acfwp_bulk_adjust_entry_created` read the same keys (`status`,
        // `processed`, `failed`, `batch_index`, `batch_size`, `completed_at`)
        // regardless of the surface that fired the action. The `source` key
        // is CLI-specific and lets integrators differentiate when needed.
        $operation = array(
            'operation_id'  => 'cli_' . wp_generate_uuid4(),
            'status'        => 'in_progress',
            'total'         => count( $user_ids ),
            'processed'     => 0,
            'failed'        => 0,
            'config'        => $config,
            'admin_user_id' => $admin_id,
            'created_at'    => current_time( 'mysql', true ),
            'completed_at'  => '',
            'batch_size'    => $batch_size,
            'batch_index'   => 0,
            'source'        => 'wp-cli',
        );

        $progress     = \WP_CLI\Utils\make_progress_bar( 'Processing', count( $user_ids ) );
        $start        = microtime( true );
        $processed    = 0;
        $failed       = 0;
        $total_amount = 0.0;

        foreach ( array_chunk( $user_ids, $batch_size ) as $batch_ids ) {
            foreach ( $batch_ids as $uid ) {
                $uid = absint( $uid );

                switch ( $config['operation_type'] ) {
                    case $this->_constants->BULK_OPERATION_TYPE_RESET:
                        $result = \ACFWP()->Bulk_Adjust->apply_reset( $uid, $config, $admin_id );
                        break;
                    case $this->_constants->BULK_OPERATION_TYPE_DELETE:
                        $result = \ACFWP()->Bulk_Adjust->apply_delete( $uid, $config, $admin_id );
                        break;
                    default:
                        $result = \ACFWP()->Bulk_Adjust->apply_adjustment( $uid, $config, $admin_id );
                        break;
                }

                if ( is_wp_error( $result ) ) {
                    ++$failed;
                    ++$operation['failed'];
                } else {
                    if ( $result instanceof Store_Credit_Entry ) {
                        $total_amount += (float) $result->get_prop( 'amount' );
                    }

                    /**
                     * Fires after a single store credit entry is created during a bulk
                     * adjustment. Mirrors the Action-Scheduler firing condition
                     * (Bulk_Adjust::process_batch()) — fires for every non-WP_Error
                     * result so third-party listeners see reset short-circuits
                     * (int 0) and delete successes (int 1) on the CLI path the same
                     * way they see them on the Action Scheduler path.
                     *
                     * @since 4.0.8
                     *
                     * @param Store_Credit_Entry|int $result    The created entry, or an int
                     *                                          (0 = skipped/zero-balance reset, 1 = delete success).
                     * @param int                    $uid       The user ID that was adjusted.
                     * @param array                  $operation The full operation metadata.
                     */
                    do_action( 'acfwp_bulk_adjust_entry_created', $result, $uid, $operation );
                    ++$processed;
                }

                // Mirror the AS path: $operation['processed'] tallies every
                // entry handled (success + failure) so listeners reading the
                // shared payload see the same semantics on both surfaces.
                ++$operation['processed'];
                $progress->tick();
            }

            ++$operation['batch_index'];

            // Bound memory across very large runs by clearing only the
            // in-process runtime cache between batches. wp_cache_flush()
            // would clear the entire site object cache on persistent
            // backends (Redis/Memcached), hammering front-end TTFB once
            // per batch for the duration of the CLI run.
            if ( function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }
        }

        $operation['status']       = 'completed';
        $operation['completed_at'] = current_time( 'mysql', true );

        $progress->finish();

        $duration = microtime( true ) - $start;

        switch ( $config['operation_type'] ) {
            case $this->_constants->BULK_OPERATION_TYPE_RESET:
                \WP_CLI::log(
                    sprintf(
                        /* translators: 1: number of users processed, 2: total amount zeroed (formatted currency), 3: elapsed seconds. */
                        __( 'Done! %1$d users processed. Total: %2$s zeroed in %3$.2f seconds.', 'advanced-coupons-for-woocommerce' ),
                        $processed,
                        $this->format_money( $total_amount ),
                        $duration
                    )
                );
                break;
            case $this->_constants->BULK_OPERATION_TYPE_DELETE:
                \WP_CLI::log(
                    sprintf(
                        /* translators: 1: number of users processed, 2: elapsed seconds. */
                        __( 'Done! %1$d users processed in %2$.2f seconds. Total entries deleted.', 'advanced-coupons-for-woocommerce' ),
                        $processed,
                        $duration
                    )
                );
                break;
            default:
                $log_template = ( 'decrease' === $config['type'] )
                    /* translators: 1: number of users processed, 2: total amount decreased (formatted currency), 3: elapsed seconds. */
                    ? __( 'Done! %1$d users processed. Total: %2$s decreased in %3$.2f seconds.', 'advanced-coupons-for-woocommerce' )
                    /* translators: 1: number of users processed, 2: total amount increased (formatted currency), 3: elapsed seconds. */
                    : __( 'Done! %1$d users processed. Total: %2$s increased in %3$.2f seconds.', 'advanced-coupons-for-woocommerce' );

                \WP_CLI::log(
                    sprintf(
                        $log_template,
                        $processed,
                        $this->format_money( $total_amount ),
                        $duration
                    )
                );
                break;
        }

        if ( $failed > 0 ) {
            \WP_CLI::warning(
                sprintf(
                    /* translators: %d: number of users for which the adjustment failed. */
                    __( '%d users failed.', 'advanced-coupons-for-woocommerce' ),
                    $failed
                )
            );
        }
    }

    /**
     * Raise the bulk-adjust user cap for the duration of a CLI run.
     *
     * The REST/UI cap (`acfwp_bulk_adjust_max_users`, default 50k) exists to
     * keep web requests within their PHP memory/timeout budget. CLI runs have
     * a much higher ceiling, so we filter the cap up to ~10M for CLI only.
     *
     * @since 4.0.8
     * @access public
     *
     * @param int $cap Existing cap from previous filters.
     * @return int Raised cap.
     */
    public function raise_cli_user_cap( $cap ) {
        return max( (int) $cap, 10000000 );
    }

    /*
    |--------------------------------------------------------------------------
    | Output Helpers
    |--------------------------------------------------------------------------
     */

    /**
     * Render the dry-run table and summary footer.
     *
     * Caps the visible rows to 100 so the terminal isn't flooded for very
     * large match sets — admins still see the total user and amount figures
     * in the footer.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param int[] $user_ids Matched user IDs.
     * @param array $config   Sanitized adjustment config.
     */
    protected function render_dry_run( $user_ids, $config ) {
        $total_users  = count( $user_ids );
        $row_cap      = 100;
        $visible_ids  = array_slice( $user_ids, 0, $row_cap );
        $rows         = $this->build_dry_run_rows( $visible_ids, $config );
        $total_amount = 0.0;
        $is_delete    = ( $this->_constants->BULK_OPERATION_TYPE_DELETE === $config['operation_type'] );

        if ( ! $is_delete ) {
            // Aggregate the full match set via a single SQL fetch — without
            // this, a dry-run against tens of thousands of users would issue
            // one get_user_meta() read per user and easily OOM the CLI process.
            // Mirrors the batched pattern in API_Bulk_Adjust::calculate_total_adjustment().
            //
            // Note: fetch_balances_in_bulk() reads the cached
            // `acfw_store_credit_balance` usermeta. The per-row table
            // (build_dry_run_rows) triggers lazy refresh for the 100 visible
            // users, but this aggregate reads cached values for the full match
            // set — so the footer total may lag freshly-written entries from a
            // CLI run that happened immediately before this dry-run. See the
            // PHPDoc on Bulk_Adjust::fetch_balances_in_bulk() for the full
            // cache-semantics trade-off.
            $balances = \ACFWP()->Bulk_Adjust->fetch_balances_in_bulk( $user_ids );
            foreach ( $user_ids as $uid ) {
                $balance = isset( $balances[ (int) $uid ] ) ? $balances[ (int) $uid ] : 0.0;
                if ( $this->_constants->BULK_OPERATION_TYPE_RESET === $config['operation_type'] ) {
                    $total_amount += max( 0, $balance );
                } else {
                    $total_amount += \ACFWP()->Bulk_Adjust->calculate_amount_for_balance( $balance, $config, (int) $uid );
                }
            }
        }

        \WP_CLI\Utils\format_items(
            'table',
            $rows,
            array( 'ID', 'Name', 'Email', 'Balance', 'Adjustment' )
        );

        if ( $total_users > $row_cap ) {
            \WP_CLI::log(
                sprintf(
                    /* translators: 1: number of rows shown, 2: total number of matched users. */
                    __( '%1$d rows shown; %2$d total matched.', 'advanced-coupons-for-woocommerce' ),
                    count( $rows ),
                    $total_users
                )
            );
        }

        if ( $is_delete ) {
            \WP_CLI::log(
                sprintf(
                    /* translators: %d: number of users matched by the delete dry-run. */
                    __( '%d users matched. All store credit entries would be deleted.', 'advanced-coupons-for-woocommerce' ),
                    $total_users
                )
            );
        } else {
            \WP_CLI::log(
                sprintf(
                    /* translators: 1: number of users matched, 2: total adjustment amount (formatted currency). */
                    __( '%1$d users matched. Total adjustment: %2$s', 'advanced-coupons-for-woocommerce' ),
                    $total_users,
                    $this->format_money( $total_amount )
                )
            );
        }
    }

    /**
     * Build the row set rendered by the dry-run table.
     *
     * Primes the user/meta cache once via cache_users() so the per-row balance
     * and user lookups don't trigger N+1 reads.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param int[] $user_ids Visible user IDs (already capped by render_dry_run()).
     * @param array $config   Sanitized adjustment config.
     * @return array<int,array<string,string>> Rows ready for format_items().
     */
    protected function build_dry_run_rows( $user_ids, $config ) {
        $rows = array();

        if ( empty( $user_ids ) ) {
            return $rows;
        }

        cache_users( $user_ids );

        $is_delete = ( $this->_constants->BULK_OPERATION_TYPE_DELETE === $config['operation_type'] );
        $is_reset  = ( $this->_constants->BULK_OPERATION_TYPE_RESET === $config['operation_type'] );

        foreach ( $user_ids as $uid ) {
            $user = get_user_by( 'id', $uid );
            if ( ! $user ) {
                continue;
            }

            $balance = (float) \ACFWF()->Store_Credits_Calculate->get_customer_balance( $uid, false );

            if ( $is_delete ) {
                $adjustment = '(all entries)';
            } elseif ( $is_reset ) {
                $adjustment = $this->format_money( max( 0, $balance ), true, '-' );
            } else {
                $amount     = (float) \ACFWP()->Bulk_Adjust->calculate_amount_for_balance( $balance, $config, (int) $uid );
                $sign       = ( 'decrease' === $config['type'] ) ? '-' : '+';
                $adjustment = $this->format_money( $amount, true, $sign );
            }

            $rows[] = array(
                'ID'         => (string) (int) $uid,
                'Name'       => \ACFWP()->Bulk_Adjust->format_user_name( $user ),
                'Email'      => (string) $user->user_email,
                'Balance'    => $this->format_money( $balance ),
                'Adjustment' => $adjustment,
            );
        }

        return $rows;
    }

    /**
     * Format a dollar amount as plain text for WP-CLI output.
     *
     * Uses `wc_price()` for the currency symbol/decimals but strips the HTML
     * wrapper and decodes entities so the terminal sees `$25.00` rather than
     * `<span ...>&#36;25.00</span>`. Optionally prepends a signed prefix.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param float  $amount The unsigned amount.
     * @param bool   $signed Whether to prefix the value with a sign.
     * @param string $sign   The sign character to prefix when $signed is true.
     * @return string Plain-text dollar amount safe for CLI output.
     */
    protected function format_money( $amount, $signed = false, $sign = '' ) {
        $base = html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );

        if ( $signed && '' !== $sign ) {
            return $sign . $base;
        }

        return $base;
    }

    /**
     * Parse a comma-separated string of integer IDs.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param string $raw Raw CSV value from the flag.
     * @return int[] Sanitized, non-zero integer IDs.
     */
    protected function parse_csv_int_list( $raw ) {
        $parts = array_map( 'trim', explode( ',', (string) $raw ) );
        $ids   = array_map( 'absint', $parts );

        return array_values( array_filter( $ids ) );
    }

    /**
     * Parse a comma-separated string of role slugs.
     *
     * @since 4.0.8
     * @access protected
     *
     * @param string $raw Raw CSV value from the flag.
     * @return string[] Sanitized, non-empty role slugs.
     */
    protected function parse_csv_role_list( $raw ) {
        $parts = array_map( 'trim', explode( ',', (string) $raw ) );
        $roles = array_map( 'sanitize_key', $parts );

        return array_values( array_filter( $roles ) );
    }
}
