<?php

namespace ACFWF\Models\Objects;

use ACFWF\Helpers\Plugin_Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared session-backed cache for expensive discount calculations.
 *
 * Generalizes the caching pattern used by the BOGO Deals calculation: the calculation's
 * inputs are hashed, and the calculation's structural output is stored in the WC session
 * together with that hash. On subsequent requests the stored output is reused while the
 * hash still matches, and the expensive calculation is skipped. Any change to a hashed
 * input invalidates the entry implicitly.
 *
 * Contract for callers:
 * - Include every input that affects the result in the hash: the applied coupon codes AND
 *   the relevant coupon configuration (codes alone go stale when an admin edits a coupon),
 *   the cart items reduced to identifying fields (key, product ID, variation ID, quantity),
 *   the currency, and any feature-specific inputs.
 * - Cache structural decisions only (matched entries, resolved configuration), never money
 *   amounts — recompute amounts from the cached structure on every request so price changes
 *   and currency conversions never serve stale values.
 *
 * A per-request in-memory layer sits in front of the session so repeated
 * `calculate_totals()` passes within one request never re-read or re-write the session.
 *
 * @since 4.8
 */
class Session_Calculation_Cache {
    /*
    |--------------------------------------------------------------------------
    | Traits
    |--------------------------------------------------------------------------
     */
    use \ACFWF\Traits\Singleton;

    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * WC session key that houses all cached calculations (feature slug keyed).
     *
     * @since 4.8
     * @var string
     */
    const SESSION_KEY = 'acfw_calc_cache';

    /**
     * Property that houses the per-request memoization layer (feature slug keyed
     * list of `array( 'hash' => string, 'data' => array )` entries).
     *
     * @since 4.8
     * @access private
     * @var array
     */
    private $_memo = array();

    /*
    |--------------------------------------------------------------------------
    | Implementation related functions.
    |--------------------------------------------------------------------------
     */

    /**
     * Build a cache hash from the given calculation inputs.
     *
     * The plugin version is appended as a salt so plugin updates never deserialize
     * cache entries with a stale shape. Callers must pass inputs with a stable array
     * key order, as the hash is derived from the JSON encoding.
     *
     * @since 4.8
     * @access public
     *
     * @param array $inputs Calculation inputs.
     * @return string Hash value.
     */
    public function build_hash( $inputs ) {
        return md5( wp_json_encode( $inputs ) . '|' . Plugin_Constants::VERSION );
    }

    /**
     * Get the cached calculation output for a feature when the hash still matches.
     *
     * @since 4.8
     * @access public
     *
     * @param string $feature Feature slug.
     * @param string $hash    Hash of the calculation's current inputs.
     * @return array|null Cached output, or null on cache miss.
     */
    public function get( $feature, $hash ) {
        // Per-request memoization layer.
        if ( isset( $this->_memo[ $feature ] ) && $this->_memo[ $feature ]['hash'] === $hash ) {
            return $this->_memo[ $feature ]['data'];
        }

        $session_data = $this->_get_session_data();
        $entry        = isset( $session_data[ $feature ] ) ? $session_data[ $feature ] : null;

        if ( ! is_array( $entry ) || ! isset( $entry['hash'], $entry['data'] ) || $entry['hash'] !== $hash ) {
            return null;
        }

        $this->_memo[ $feature ] = $entry;

        return $entry['data'];
    }

    /**
     * Store a calculation output for a feature.
     *
     * @since 4.8
     * @access public
     *
     * @param string $feature Feature slug.
     * @param string $hash    Hash of the calculation's inputs.
     * @param array  $data    Calculation output to cache.
     */
    public function set( $feature, $hash, $data ) {
        $entry                   = array(
            'hash' => $hash,
            'data' => $data,
        );
        $this->_memo[ $feature ] = $entry;

        if ( ! $this->_is_session_available() ) {
            return;
        }

        $session_data             = $this->_get_session_data();
        $session_data[ $feature ] = $entry;

        \WC()->session->set( self::SESSION_KEY, $session_data );
    }

    /**
     * Clear the cached calculation output for a feature.
     *
     * @since 4.8
     * @access public
     *
     * @param string $feature Feature slug.
     */
    public function clear( $feature ) {
        unset( $this->_memo[ $feature ] );

        if ( ! $this->_is_session_available() ) {
            return;
        }

        $session_data = $this->_get_session_data();

        if ( isset( $session_data[ $feature ] ) ) {
            unset( $session_data[ $feature ] );
            \WC()->session->set( self::SESSION_KEY, $session_data );
        }
    }

    /**
     * Clear all cached calculation outputs.
     *
     * @since 4.8
     * @access public
     */
    public function clear_all() {
        $this->_memo = array();

        if ( $this->_is_session_available() ) {
            \WC()->session->set( self::SESSION_KEY, null );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Utility methods.
    |--------------------------------------------------------------------------
     */

    /**
     * Check if the WC session is available for use.
     *
     * @since 4.8
     * @access private
     *
     * @return bool True if available, false otherwise.
     */
    private function _is_session_available() {
        return function_exists( 'WC' ) && \WC()->session instanceof \WC_Session;
    }

    /**
     * Get all cached calculations from the WC session.
     *
     * @since 4.8
     * @access private
     *
     * @return array Feature slug keyed list of cache entries.
     */
    private function _get_session_data() {
        if ( ! $this->_is_session_available() ) {
            return array();
        }

        $session_data = \WC()->session->get( self::SESSION_KEY );

        return is_array( $session_data ) ? $session_data : array();
    }
}
