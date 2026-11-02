<?php
namespace ACFWF\Abstracts;

use ACFWF\Models\Objects\Date_Period_Range;
use ACFWF\Models\Objects\Report_Coupon_Usage_Query;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract report widget class.
 *
 * @since 4.3
 */
abstract class Abstract_Report_Widget {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
    */

    /**
     * Property that houses data of the report widget.
     *
     * @since 4.3
     * @access protected
     * @var array
     */
    protected $_data = array(
        'key'           => '',
        'widget_name'   => '',
        'type'          => '',
        'title'         => '',
        'description'   => '',
        'page_link'     => '',
        'tooltip'       => '',
        'table_data'    => null,
        'raw_data'      => null,
        'report_period' => null,
    );

    /**
     * Flag whether _load_report_data() has run. Set BEFORE the load runs, because the load
     * itself reads $this->report_period through __get(), which would otherwise recurse.
     *
     * @since 4.7.6
     * @access private
     * @var bool
     */
    private $_data_loaded = false;

    /**
     * Flag whether _format_report_data() has run. Separate from $_data_loaded so that reading
     * raw_data can load without formatting. See __get().
     *
     * @since 4.7.6
     * @access private
     * @var bool
     */
    private $_formatted = false;

    /**
     * Props produced by _format_report_data(), so reading one lazily loads AND formats.
     *
     * 'raw_data' is deliberately absent: it is produced by _load_report_data() and is handled
     * separately in __get(), because formatting reads it back. 'report_period' is absent too --
     * the load itself reads it.
     *
     * @since 4.7.6
     * @access private
     * @var array
     */
    private $_formatted_props = array( 'table_data', 'title', 'description', 'tooltip' );

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Create a new Report Widget object.
     * Construction is deliberately free of any query: report data is loaded lazily, the first
     * time it is actually read (see _ensure_loaded()). This lets a widget be dropped via the
     * 'acfw_register_dashboard_report_widgets' filter before its query ever runs.
     *
     * @since 4.3
     * @since 4.7.6 Defer report data loading until it is actually read.
     * @access public
     *
     * @param Date_Period_Range $report_period Date period range object.
     */
    public function __construct( Date_Period_Range $report_period ) {
        $this->report_period = $report_period;
    }

    /*
    |--------------------------------------------------------------------------
    | Getter methods
    |--------------------------------------------------------------------------
     */

    /**
     * Access public report widget data.
     *
     * @since 4.3
     * @access public
     *
     * @param string $prop Model to access.
     * @return mixed Prop value.
     * @throws \Exception Invalid prop error message.
     */
    public function __get( $prop ) {
        if ( 'raw_data' === $prop ) {
            // Load only. Formatting is what reads raw_data back, and PHP refuses to re-enter
            // __get() for a property while that same property is still resolving — so
            // formatting must never run inside __get( 'raw_data' ).
            $this->_ensure_data_loaded();
        } elseif ( in_array( $prop, $this->_formatted_props, true ) ) {
            $this->_ensure_loaded();
        }

        if ( array_key_exists( $prop, $this->_data ) ) {
            return $this->_data[ $prop ];
        } else {
            throw new \Exception( 'Trying to access unknown property ' . esc_html( $prop ) . ' on Abstract_Report_Widget instance.' );
        }
    }

    /**
     * Get the report data response for REST API.
     *
     * @since 4.3
     * @since 4.7.6 Ensure report data is loaded before building the response.
     * @access public
     *
     * @return array Report data response for REST API.
     */
    public function get_api_response() {
        $this->_ensure_loaded();

        $response = array(
            'key'              => $this->key,
            'widget_name'      => $this->widget_name,
            'type'             => $this->type,
            'page_link'        => $this->page_link,
            'title_html'       => wp_kses_post( $this->title ),
            'description_html' => wp_kses_post( $this->description ),
            'tooltip_html'     => wp_kses_post( $this->tooltip ),
        );

        if ( ! is_null( $this->table_data ) ) {
            $response['table_data'] = $this->table_data;
        }

        if ( ! is_null( $this->raw_data ) ) {
            $response['raw_data'] = $this->raw_data;
        }

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Setter methods
    |--------------------------------------------------------------------------
     */

    /**
     * Set report widget data value.
     * Setting values can only be done within the class.
     *
     * @since 4.3
     * @access public
     *
     * @param string $prop Model to access.
     * @param mixed  $value Value to set.
     * @throws \Exception Invalid prop error message.
     */
    public function __set( $prop, $value ) {
        if ( array_key_exists( $prop, $this->_data ) ) {
            $this->_data[ $prop ] = $value;
        } else {
            throw new \Exception( 'Trying to access unknown property ' . esc_html( $prop ) . ' on Abstract_BOGO_Deal instance.' );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Query methods
    |--------------------------------------------------------------------------
     */

    /**
     * Ensure the report data has been queried. Idempotent, and does NOT format: formatting is
     * what reads raw_data back, so it must stay out of the __get( 'raw_data' ) call frame.
     *
     * @since 4.7.6
     * @access protected
     */
    protected function _ensure_data_loaded() {
        if ( $this->_data_loaded ) {
            return;
        }

        $this->_data_loaded = true;

        $this->_load_report_data();
    }

    /**
     * Ensure the report data has been queried and formatted for display. Idempotent.
     *
     * Because this runs the format step outside any __get( 'raw_data' ) frame, a subclass's
     * _format_report_data() can read $this->raw_data normally -- which is what keeps the
     * sibling plugins (AGCFW, LPFW) working against this abstract with no changes of their own.
     *
     * @since 4.7.6
     * @access protected
     */
    protected function _ensure_loaded() {
        $this->_ensure_data_loaded();

        if ( $this->_formatted ) {
            return;
        }

        $this->_formatted = true;

        $this->_format_report_data();
    }

    /**
     * Read an already-loaded prop directly, bypassing the __get() magic method.
     *
     * _query_report_data()/_format_report_data() implementations need this when reading a value
     * (typically 'raw_data') that was set earlier in the SAME _ensure_loaded() pass that a caller
     * outside the class triggered by reading that very prop through __get(). PHP's engine refuses
     * to re-enter __get() for a property while it is still resolving that property (it falls back
     * to an "Undefined property" warning + null instead) — this reads the underlying array slot
     * directly so the reentrant read gets the real value instead of null.
     *
     * @since 4.7.6
     * @access protected
     *
     * @param string $prop Prop to read.
     * @return mixed Prop value, or null if not set.
     */
    protected function _get_loaded_data( $prop ) {
        return $this->_data[ $prop ] ?? null;
    }

    /**
     * Load report data.
     *
     * @since 4.3
     * @access protected
     */
    protected function _load_report_data() {
        // load report data from cache if it's present.
        if ( $this->is_cache() ) {
            $cache_key  = sprintf( 'acfwf_dashboard_%s_%s_%s', $this->key, $this->report_period->start_period->getTimestamp(), $this->report_period->end_period->getTimestamp() );
            $cache_data = get_transient( $cache_key );

            if ( false !== $cache_data ) {
                $this->raw_data = $cache_data;
                return;
            }
        }

        // fetch fresh data from db.
        $this->_query_report_data();

        // save data to cache.
        if ( $this->is_cache() ) {
            set_transient( $cache_key, $this->_get_loaded_data( 'raw_data' ), DAY_IN_SECONDS );
        }
    }

    /**
     * Query report data freshly from the database.
     * NOTE: Use custom SQL here or WP/WC Query objects to fetch the data.
     *       This method needs to be override on the child class.
     *
     * @since 4.3
     * @access protected
     */
    protected function _query_report_data() {     }

    /**
     * Query orders based on the given report period.
     *
     * @since 4.5.6
     * @since 4.7.6 Accept an $args override (e.g. 'return' => 'ids', batched 'limit'/'offset') so
     *            the bounded fallback path can page through orders without hydrating every one
     *            of them up front. With no override this behaves exactly as before.
     * @access protected
     *
     * @param array $args Optional wc_get_orders() argument overrides.
     */
    protected function _query_orders( $args = array() ) {

        $this->report_period->use_utc_timezone();

        $defaults = array(
            'limit'     => -1,
            'orderby'   => 'date',
            'order'     => 'DESC',
            'date_paid' => sprintf( '%s...%s', $this->report_period->start_period->getTimestamp(), $this->report_period->end_period->getTimestamp() ),
            'status'    => $this->_report_widget_order_statuses(),
        );

        return wc_get_orders( array_merge( $defaults, $args ) );
    }

    /**
     * Order statuses report widgets consider paid, with the 'acfw_report_widget_order_statuses'
     * filter applied.
     *
     * @since 4.7.6
     * @access protected
     *
     * @return array Order statuses, each prefixed with 'wc-'.
     */
    protected function _report_widget_order_statuses() {
        return apply_filters(
            'acfw_report_widget_order_statuses',
            array_map(
                function ( $status ) {
                    return 'wc-' . $status;
                },
                wc_get_is_paid_statuses()
            ),
            $this
        );
    }

    /**
     * Iterate every order in the report period in fixed-size batches, hydrating only one batch
     * of order ids at a time and flushing the runtime object cache between batches so per-order
     * caching does not accumulate for the whole period. Used only by the bounded fallback path.
     *
     * @since 4.7.6
     * @access protected
     *
     * @param int $batch_size Orders to hydrate per batch.
     * @return \Generator<\WC_Order> Hydrated orders, one at a time.
     */
    protected function _iterate_orders( $batch_size = 200 ) {
        $offset = 0;

        while ( true ) {
            $ids = $this->_query_orders(
                array(
                    'return' => 'ids',
                    'limit'  => $batch_size,
                    'offset' => $offset,
                )
            );

            if ( empty( $ids ) ) {
                return;
            }

            foreach ( $ids as $id ) {
                $order = wc_get_order( $id );

                if ( $order ) {
                    yield $order;
                }
            }

            $offset += $batch_size;

            // Drop the in-process (runtime) cache between batches so hydrated order data does
            // not accumulate across the whole period. Deliberately NOT wp_cache_flush(), which
            // would evict a site-wide persistent Redis/Memcached cache. Note that on a site with
            // no external object cache every group is non-persistent, so this clears them all --
            // acceptable here because it only runs on the fallback path.
            if ( function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }

            if ( count( $ids ) < $batch_size ) {
                return;
            }
        }
    }

    /**
     * Get one row per coupon line item in the report period, shared by every coupon report
     * widget. Uses the bounded aggregate SQL query when nothing needs real order/item objects;
     * otherwise falls back to batched order hydration so integrations that read $order/$item
     * directly (e.g. a currency switcher reading order meta) still see correct figures. Both
     * paths return identically shaped rows, see Report_Coupon_Usage_Query.
     *
     * @since 4.7.6
     * @access protected
     *
     * @param bool $needs_item_objects Whether this widget applies a filter that is handed the
     *                                  real $item/$order objects. Pass false when the widget only
     *                                  reads scalar row values, so it can stay on the fast path
     *                                  even on a site that has such an integration.
     * @return iterable Rows, see Report_Coupon_Usage_Query::get_rows(). Always iterate the result
     *                  with foreach: the fallback path returns a Generator, not an array.
     */
    protected function _get_coupon_usage_rows( $needs_item_objects = true ) {
        if ( $this->_can_use_aggregate_query( $needs_item_objects ) ) {
            return Report_Coupon_Usage_Query::get_rows( $this->report_period, $this->_report_widget_order_statuses() );
        }

        return $this->_iterate_coupon_usage_rows();
    }

    /**
     * Yield fallback rows one at a time, keeping the hydrated $order/$item objects alive only
     * while the batch that produced them is being consumed.
     *
     * This deliberately does NOT collect the rows into an array. The rows carry the real
     * WC_Order and WC_Order_Item_Coupon objects (integrations subscribed to the report filters
     * need them), so buffering every row would hold every order of the period in memory at once
     * and reproduce the exhaustion this fix exists to remove — _iterate_orders()' cache flushing
     * cannot help while a caller still holds hard references.
     *
     * The consequence is that the fallback rows are not memoised, so each widget re-hydrates.
     * That matches the pre-4.7.6 behaviour (every widget ran its own order query) and keeps the
     * filter call counts and arguments identical, which is what parity depends on.
     *
     * @since 4.7.6
     * @access protected
     *
     * @return \Generator<array> Rows, one at a time.
     */
    protected function _iterate_coupon_usage_rows() {
        foreach ( $this->_iterate_orders() as $order ) {
            foreach ( $order->get_coupons() as $item ) {
                yield Report_Coupon_Usage_Query::build_row_from_order_item( $item, $order );
            }
        }
    }

    /**
     * Check whether the bounded aggregate SQL query can be used, or whether a subscriber needs
     * real $item/$order objects and forces the batched-hydration fallback. Evaluated at query
     * time (not at construction), because currency integrations remove their own filters on
     * 'acfw_rest_api_context' before the widgets are built for a REST request.
     *
     * @since 4.7.6
     * @access protected
     *
     * @param bool $needs_item_objects Whether the caller needs real $item/$order objects.
     * @return bool True when no subscriber needs a real order/item object.
     */
    protected function _can_use_aggregate_query( $needs_item_objects = true ) {
        /**
         * Opt out of the bounded aggregate query and use batched order hydration instead.
         *
         * A site that genuinely restricts order visibility through the WooCommerce order-query
         * filters should return false here to keep wc_get_orders() semantics. See the
         * "DIVERGENCE FROM wc_get_orders()" contract on
         * ACFWF\Models\Objects\Report_Coupon_Usage_Query, which states in full what the
         * aggregate path bypasses and why it cannot be gated on has_filter().
         *
         * @since 4.7.6
         *
         * @param bool                   $use_aggregate Whether to use the aggregate query.
         * @param Abstract_Report_Widget $widget        The report widget instance.
         */
        if ( ! apply_filters( 'acfw_dashboard_report_use_aggregate_query', true, $this ) ) {
            return false;
        }

        if ( ! $needs_item_objects ) {
            return true;
        }

        // These are handed the real $item/$order objects, which SQL cannot supply. Only widgets
        // that actually apply them have to fall back; a widget that just counts rows does not,
        // and keeping it on the fast path removes two of the six hydration passes on a store
        // that has such an integration installed.
        $item_dependent_filters = array(
            'acfw_query_report_get_discount',
            'acfw_query_report_get_discount_tax',
            'acfw_query_report_extra_discount',
            'acfw_query_report_data_order_total',
        );

        foreach ( $item_dependent_filters as $filter ) {
            if ( has_filter( $filter ) ) {
                return false;
            }
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Conditional methods
    |--------------------------------------------------------------------------
     */

    /**
     * Check if a report widget is valid and should be displayed in the report.
     * NOTE: This method needs to be override on the child class.
     *
     * @since 4.3
     * @access public
     *
     * @return bool True if valid, false otherwise.
     */
    public function is_valid() {
        return true;
    }

    /**
     * Check if the report widget data cache should be handled in this class.
     * NOTE: This method needs to be override on the child class.
     *
     * @since 4.3
     * @access public
     */
    public function is_cache() {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods
    |--------------------------------------------------------------------------
     */

    /**
     * Format report data to for display in the UI.
     * NOTE: This method needs to be override on the child class.
     *
     * @since 4.3
     * @access public
     */
    protected function _format_report_data() {     }

    /**
     * Utility function to format price properly for admin context while keeping the HTML markup from wc_price.
     *
     * @since 4.3
     * @access protected
     *
     * @param float $price Price to format.
     * @return string Formatted price markup.
     */
    protected function _format_price( $price ) {
        return \wc_price(
            $price,
            array(
                'currency' => get_option( 'woocommerce_currency' ),
            )
        );
    }
}
