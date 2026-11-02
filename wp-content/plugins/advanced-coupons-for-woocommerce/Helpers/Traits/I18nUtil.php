<?php
namespace ACFWP\Helpers\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait that houses all the helper functions specificly for product category.
 *
 * @since 3.5.5
 */
trait I18nUtil {
    /**
     * Get the weight unit label.
     * - Provide backward compatibility for WooCommerce 7.4.1 and below.
     *
     * @since 3.5.7
     *
     * @param string $weight_unit The abbreviated weight unit in English, e.g. kg.
     *
     * @return string
     */
    public function get_weight_unit_label( $weight_unit ) {
        $class  = 'Automattic\WooCommerce\Utilities\I18nUtil';
        $method = 'get_weight_unit_label';

        // Check if the class and method exist before using them.
        if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
            return $weight_unit;
        }

        return \Automattic\WooCommerce\Utilities\I18nUtil::get_weight_unit_label( $weight_unit );
    }
}
