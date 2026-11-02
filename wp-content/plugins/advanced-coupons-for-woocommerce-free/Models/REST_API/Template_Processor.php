<?php
namespace ACFWF\Models\REST_API;

use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Template Processor
 *
 * Shared processor for both regular (database) and AI-generated (transient) coupon templates.
 * Provides consistent field processing, fixture enrichment, and cart condition handling.
 *
 * @since 4.7.2
 */
class Template_Processor {

    /**
     * Plugin constants object.
     *
     * @since 4.7.2
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Helper functions object.
     *
     * @since 4.7.2
     * @access private
     * @var Helper_Functions
     */
    private $_helper_functions;

    /**
     * Class constructor.
     *
     * @since 4.7.2
     * @access public
     *
     * @param Plugin_Constants $constants        Plugin constants object.
     * @param Helper_Functions $helper_functions Helper functions object.
     */
    public function __construct( Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;
    }

    /**
     * Process any template (DB or AI) into consistent REST API format.
     *
     * @since 4.7.2
     * @access public
     *
     * @param array $raw_template Raw template from any source.
     * @return array Processed template ready for frontend.
     */
    public function process_template( $raw_template ) {
        // Step 1: Normalize input structure (handles both DB and AI formats).
        $normalized = $this->_normalize_template_structure( $raw_template );

        // Step 2: Prepend required fields (coupon_code, discount_type) with fixtures.
        $normalized['fields'] = $this->_prepend_required_fields( $normalized );

        // Step 3: Add remaining fields from template_data with fixtures.
        if ( ! empty( $normalized['template_data'] ) ) {
            foreach ( $normalized['template_data'] as $row ) {
                $row['fixtures'] = $this->_get_field_fixture_data( $row['field'] );

                /**
                 * Filter field value for template processing.
                 *
                 * Allows premium plugin to enrich field values with labels for select-type fields.
                 * Premium plugin handles enrichment for AI-generated templates (premium feature).
                 *
                 * @since 4.7.2
                 *
                 * @param mixed  $raw_value      The original raw value.
                 * @param string $field_name     The field name being processed.
                 * @param array  $raw_template   The complete raw template data.
                 */
                $field_value = apply_filters(
                    'acfwf_template_processor_enrich_field_value',
                    $row['pre_filled_value'] ?? '',
                    $row['field'],
                    $raw_template
                );

                // Frontend uses 'value' for current field value.
                $row['value']           = $field_value;
                $normalized['fields'][] = $row;
            }
        }

        // Step 4: Process cart conditions with i18n.
        if ( ! empty( $normalized['cart_conditions'] ) ) {
            $normalized['cart_conditions'] = $this->_prepare_template_cart_condition_data(
                $normalized['cart_conditions'],
                $raw_template
            );
        }

        // Clean up temporary keys.
        unset( $normalized['template_data'] );
        unset( $normalized['coupon_template_data'] );

        /**
         * Filter processed template data before returning.
         *
         * Allows premium plugin to modify the entire processed template output,
         * useful for adding premium-specific fields or transformations.
         *
         * @since 4.7.2
         *
         * @param array $normalized    The processed template data.
         * @param array $raw_template  The original raw template data.
         */
        return apply_filters( 'acfwf_template_processor_process_template', $normalized, $raw_template );
    }

    /**
     * Normalize template structure.
     *
     * Handles both 'template_data' (from DB) and 'coupon_template_data' (from AI) keys.
     *
     * @since 4.7.2
     * @access private
     *
     * @param array $raw Raw template data.
     * @return array Normalized template with 'template_data' key.
     */
    private function _normalize_template_structure( $raw ) {
        // If has completion field (raw AI response), parse it first.
        if ( isset( $raw['completion'] ) && is_string( $raw['completion'] ) ) {
            $parsed = json_decode( $raw['completion'], true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
                // Merge parsed completion data into raw array.
                $raw = array_merge( $raw, $parsed );
            } else {
                wc_get_logger()->error(
                    'Failed to decode AI completion JSON - ' . json_last_error_msg(),
                    array( 'source' => 'acfwf-template-processor' )
                );
                unset( $raw['completion'] );
                $raw['template_data'] = array();
                return $raw;
            }
            unset( $raw['completion'] ); // Always remove raw completion data.
        }

        // If already has template_data, return as-is.
        if ( isset( $raw['template_data'] ) ) {
            return $raw;
        }

        // If has coupon_template_data (AI format), normalize to template_data.
        if ( isset( $raw['coupon_template_data'] ) ) {
            $raw['template_data'] = $raw['coupon_template_data'];
            unset( $raw['coupon_template_data'] );
            return $raw;
        }

        // If neither key exists, assume empty template_data.
        $raw['template_data'] = array();
        return $raw;
    }

    /**
     * Prepend required fields (coupon_code, discount_type) with fixtures.
     *
     * @since 4.7.2
     * @access private
     *
     * @param array $template Template data.
     * @return array Fields array with required fields prepended (with fixtures).
     */
    private function _prepend_required_fields( $template ) {
        $discount_type = $template['discount_type'] ?? 'percent';

        $fields = array(
            array(
                'field'            => 'coupon_code',
                'field_value'      => 'editable',
                'fixtures'         => $this->_get_field_fixture_data( 'coupon_code' ),
                'is_required'      => true,
                'pre_filled_value' => '',
                'value'            => '', // Frontend uses 'value' for current field value.
            ),
            array(
                'field'            => 'discount_type',
                'field_value'      => 'editable',
                'fixtures'         => $this->_get_field_fixture_data( 'discount_type' ),
                'is_required'      => true,
                'pre_filled_value' => $discount_type,
                'value'            => $discount_type, // Frontend uses 'value' for current field value.
            ),
        );

        return $fields;
    }

    /**
     * Get the fixture data (labels, options, description, tooltip, etc.) for a specific field.
     *
     * Extracted from API_Coupon_Templates for reuse.
     *
     * @since 4.7.2
     * @access private
     *
     * @param string $field_key Field key.
     * @return array Field fixture data.
     */
    private function _get_field_fixture_data( $field_key ) {
        $fixture_file = $this->_constants->DATA_ROOT_PATH . 'coupon-fields-fixture-data.php';

        if ( ! file_exists( $fixture_file ) ) {
            return array();
        }

        $field_data = require $fixture_file;

        if ( ! isset( $field_data[ $field_key ] ) ) {
            return array();
        }

        return $field_data[ $field_key ];
    }

    /**
     * Prepare the cart condition data for the template.
     *
     * Extracted from API_Coupon_Templates for reuse.
     *
     * @since 4.7.2
     * @access private
     *
     * @param array $cart_conditions Cart conditions data.
     * @param array $raw_template    Raw template data for context.
     * @return array Prepared cart conditions data.
     */
    private function _prepare_template_cart_condition_data( $cart_conditions, $raw_template = array() ) {
        $prepared    = array();
        $fields_i18n = \ACFWF()->Cart_Conditions->condition_fields_localized_data( array() );

        foreach ( $cart_conditions as $group ) {
            if ( 'group_logic' === $group['type'] ) {
                $prepared[] = $group;
                continue;
            }

            // Append the i18n data to the fields.
            $group['fields'] = array_map(
                function ( $field ) use ( $fields_i18n, $raw_template ) {
                    if ( 'logic' === $field['type'] ) {
                        return $field;
                    }

                    // Append the translatable strings data to the condition fields.
                    $field_type    = str_replace( '-', '_', $field['type'] );
                    $field['i18n'] = isset( $fields_i18n['cart_condition_fields'][ $field_type ] ) ? $this->_helper_functions->decode_html_entities_recursive( $fields_i18n['cart_condition_fields'][ $field_type ] ) : array();

                    // Append user role options to the role related cart condition fields.
                    if ( str_contains( $field['type'], 'customer-user-role' ) ) {
                        $field['i18n']['options'] = $this->_helper_functions->get_default_allowed_user_roles();
                    }

                    // If 'data' already exists (AI format), preserve it but enrich value if needed.
                    if ( isset( $field['data'] ) ) {
                        // Enrich cart condition values for select-type fields (AI-generated templates).
                        if ( isset( $field['data']['value'] ) ) {
                            /**
                             * Filter cart condition value for enrichment.
                             *
                             * Allows premium plugin to enrich cart condition values with labels.
                             *
                             * @since 4.7.2
                             *
                             * @param mixed  $value         The condition value.
                             * @param string $field_type    The condition field type.
                             * @param array  $raw_template  The complete raw template data.
                             */
                            $field['data']['value'] = apply_filters(
                                'acfwf_template_processor_enrich_cart_condition_value',
                                $field['data']['value'],
                                $field['type'],
                                $raw_template
                            );
                        }
                        return $field;
                    }

                    // Otherwise, wrap flat structure into 'data' property (database format).
                    // Extract non-structural keys (everything except 'type', 'i18n', 'errors').
                    $data = array();
                    foreach ( $field as $key => $value ) {
                        if ( ! in_array( $key, array( 'type', 'i18n', 'errors' ), true ) ) {
                            $data[ $key ] = $value;
                        }
                    }
                    $field['data'] = $data;

                    // Clean up the flat structure (move everything into 'data').
                    foreach ( array_keys( $data ) as $key ) {
                        unset( $field[ $key ] );
                    }

                    return $field;
                },
                $group['fields']
            );

            $prepared[] = $group;
        }

        return $prepared;
    }
}
