<?php
namespace ACFWF\Models;

use ACFWF\Abstracts\Abstract_Main_Plugin_Class;
use ACFWF\Abstracts\Base_Model;
use ACFWF\Helpers\Helper_Functions;
use ACFWF\Helpers\Plugin_Constants;
use ACFWF\Interfaces\Model_Interface;
use ACFWF\Interfaces\Activatable_Interface;
use ACFWF\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the Feature Custom Taxonomy module.
 * This creates a private custom taxonomy for Advanced Coupons features.
 *
 * @since 4.6.9
 */
class Feature_Custom_Taxonomy extends Base_Model implements Model_Interface, Activatable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Class constructor.
     *
     * @since 4.6.9
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
    | Private Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Register the custom taxonomy.
     *
     * @since 4.6.9
     * @access public
     */
    public function register_taxonomy() {
        register_taxonomy(
            Plugin_Constants::FEATURE_CUSTOM_TAXONOMY,
            'shop_coupon',
            array(
                'hierarchical'       => false,
                'public'             => false,
                'show_ui'            => false,
                'show_in_menu'       => false,
                'show_in_nav_menus'  => false,
                'show_in_rest'       => true,
                'show_tagcloud'      => false,
                'show_in_quick_edit' => false,
                'show_admin_column'  => false,
                'rewrite'            => false,
                'capabilities'       => array(
                    'manage_terms' => 'manage_woocommerce',
                    'edit_terms'   => 'manage_woocommerce',
                    'delete_terms' => 'manage_woocommerce',
                    'assign_terms' => 'manage_woocommerce',
                ),
            )
        );
    }

    /**
     * Create feature terms based on available modules.
     *
     * @since 4.6.9
     * @access public
     */
    public function create_feature_terms() {
        $free_modules = Plugin_Constants::ALL_MODULES();
        $features     = array_merge(
            $free_modules,
            apply_filters( 'acfw_feature_custom_taxonomy_modules', $free_modules )
        );

        $features = array_unique( $features );

        foreach ( $features as $slug ) {
            $dashed_slug = str_replace( '_', '-', $slug );

            if ( ! term_exists( $dashed_slug, Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
                $result = wp_insert_term(
                    $dashed_slug,
                    Plugin_Constants::FEATURE_CUSTOM_TAXONOMY,
                    array(
                        'slug' => $dashed_slug,
                    )
                );
            }
        }
    }

    /**
     * Get excluded feature modules that should not appear in dropdowns.
     *
     * @since 4.6.9
     * @access private
     * @return array Array of excluded feature module constants.
     */
    private function _get_excluded_feature_modules() {
        return array(
            Plugin_Constants::STORE_CREDITS_MODULE,
            Plugin_Constants::COUPON_TEMPLATES_MODULE,
        );
    }

    /**
     * Get custom display name mappings for feature modules.
     *
     * @since 4.6.9
     * @access private
     * @return array Array mapping feature names to custom display names.
     */
    private function _get_feature_display_name_mappings() {
        return array(
            'Role Restrict' => 'Role Restriction',
            'Bogo Deals'    => 'BOGO Deals',
            'Url Coupons'   => 'URL Coupons',
        );
    }

    /**
     * Get valid feature slugs with dashes instead of underscores.
     *
     * @since 4.6.9
     * @access private
     * @return array Array of valid feature slugs.
     */
    private function _get_valid_feature_slugs() {
        $free_modules = Plugin_Constants::ALL_MODULES();
        $features     = array_merge(
            $free_modules,
            apply_filters( 'acfw_feature_custom_taxonomy_modules', $free_modules )
        );

        // Filter out excluded modules.
        $excluded_modules = $this->_get_excluded_feature_modules();
        $features         = array_diff( array_unique( $features ), $excluded_modules );

        // Convert underscores to dashes.
        return array_map(
            function ( $slug ) {
                return str_replace( '_', '-', $slug );
            },
            $features
        );
    }

    /**
     * Validate term creation to ensure only valid feature slugs are allowed.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy name.
     */
    public function validate_term_creation( $term_id, $tt_id, $taxonomy ) {
        if ( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY !== $taxonomy ) {
            return;
        }

        // Get the term object.
        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return;
        }

        // Get valid slugs.
        $valid_slugs = $this->_get_valid_feature_slugs();

        // If the term's slug is not valid, delete it.
        if ( ! in_array( $term->slug, $valid_slugs, true ) ) {
            wp_delete_term( $term_id, $taxonomy );
        }
    }

    /**
     * Protect valid feature terms from deletion and restore if deleted.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int    $term     Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy name.
     * @param object $deleted_term Deleted term object.
     */
    public function protect_feature_terms( $term, $tt_id, $taxonomy, $deleted_term ) {
        if ( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY !== $taxonomy ) {
            return;
        }

        // Get valid slugs.
        $valid_slugs = $this->_get_valid_feature_slugs();

        // If the term is protected (should NOT be deleted).
        if ( in_array( $deleted_term->slug, $valid_slugs, true ) ) {
            // Get all 'shop_coupon' posts related to this term.
            $related_coupons = get_posts(
                array(
                    'post_type'   => 'shop_coupon',
                    'numberposts' => -1,
                    'fields'      => 'ids',
                    'tax_query'   => array(
                        array(
                            'taxonomy' => $taxonomy,
                            'field'    => 'term_id',
                            'terms'    => $deleted_term->term_id,
                        ),
                    ),
                )
            );

            // Remove term from related coupons (temporarily).
            foreach ( $related_coupons as $post_id ) {
                wp_remove_object_terms( $post_id, (int) $deleted_term->term_id, $taxonomy );
            }

            // Re-insert the term.
            $restored = wp_insert_term(
                $deleted_term->name,
                $taxonomy,
                array(
                    'slug'        => $deleted_term->slug,
                    'description' => $deleted_term->description,
                    'parent'      => $deleted_term->parent,
                )
            );

            if ( ! is_wp_error( $restored ) && isset( $restored['term_id'] ) ) {
                $new_term_id = $restored['term_id'];

                // Reassign term to all coupons.
                foreach ( $related_coupons as $post_id ) {
                    wp_set_object_terms( $post_id, array( (int) $new_term_id ), $taxonomy, true );
                }
            }
        }
    }

    /**
     * Get feature checks configuration array.
     *
     * @since 4.6.9
     * @access private
     *
     * @param int    $coupon_id Coupon ID.
     * @param object $coupon    Advanced coupon object.
     * @return array Feature checks configuration.
     */
    private function _get_feature_checks( $coupon_id, $coupon ) {
        // Feature detection map.
        $feature_checks = array(
            // Free.
            'acfw-url-coupons-module'        => array( // URL Coupons.
                array( 'disable_url_coupon', 'yes', '!=' ), // URL enabled if NOT disabled.
                array( 'force_apply_url_coupon', 'yes' ),
                array( 'code_url_override', '', '!=' ), // Only if has actual override URL.
                array( 'success_message', '', '!=' ),   // Only if has success message.
                array( 'after_redirect_url', '', '!=' ), // Only if has redirect URL.
                array( 'redirect_to_origin_url', 'yes' ),
            ),
            'acfw-cart-conditions-module'    => array( // Cart Conditions.
                array( 'cart_conditions' ),
                array( 'cart_condition_notice' ),
            ),
            'acfw-bogo-deals-module'         => array( // BOGO Deals.
                array( 'discount_type', 'acfw_bogo' ),
                array( 'bogo_deals' ),
            ),
            'acfw-role-restrict-module'      => array( // Role Restrict.
                array( 'enable_role_restriction', 'yes' ),
            ),
            'acfw-scheduler-module'          => array( // Scheduler.
                array( 'enable_date_range_schedule', 'yes' ),
            ),
            'acfw-auto-apply-module'         => array( // Auto Apply.
                array( 'auto_apply_coupon', true ),
            ),
            'acfw-apply-notification-module' => array( // Apply Notification (One Click Apply).
                array( 'enable_apply_notification', true ),
            ),
        );

        return apply_filters( 'acfw_coupon_feature_checks', $feature_checks, $coupon_id, $coupon );
    }

    /**
     * Get property value from coupon object.
     *
     * @since 4.6.9
     * @access private
     *
     * @param object $coupon   Advanced coupon object.
     * @param string $prop_key Property key to retrieve.
     * @return mixed Property value.
     */
    private function _get_property_value( $coupon, $prop_key ) {
        $prop_value = null;

        if ( 'discount_type' === $prop_key ) {
            $prop_value = $coupon->get_discount_type();
        } else {
            $prop_value = $coupon->get_advanced_prop( $prop_key );
        }

        /**
         * Filter to allow modification of property values for feature detection.
         * This is especially useful for properties stored in wp_options instead of coupon meta.
         *
         * @since 4.6.9
         *
         * @param mixed  $prop_value The current property value.
         * @param string $prop_key   The property key being retrieved.
         * @param object $coupon     The Advanced Coupon object.
         * @return mixed The filtered property value.
         */
        return apply_filters( 'acfw_feature_taxonomy_get_property_value', $prop_value, $prop_key, $coupon );
    }

    /**
     * Check if feature is enabled based on property value and expected conditions.
     *
     * @since 4.6.9
     * @access private
     *
     * @param mixed  $prop_value     Current property value.
     * @param mixed  $expected_value Expected value to compare against.
     * @param string $third_param    Additional comparison parameter.
     * @return bool True if feature is enabled, false otherwise.
     */
    private function _is_feature_enabled( $prop_value, $expected_value, $third_param ) {
        if ( null !== $expected_value ) {
            if ( is_array( $expected_value ) ) {
                // Check if value is in array.
                return in_array( $prop_value, $expected_value, true );
            } elseif ( '!=' === $third_param ) {
                // Check if value is NOT equal to expected.
                return $expected_value !== $prop_value;
            } elseif ( '>' === $third_param ) {
                // Check if value is greater than expected.
                return (float) $prop_value > (float) $expected_value;
            } elseif ( 'yes' === $expected_value ) {
                // For 'yes' values, check for exact match or true.
                return ( 'yes' === $prop_value || true === $prop_value );
            } elseif ( 'no' === $expected_value ) {
                // For 'no' values, check for exact match.
                return 'no' === $prop_value;
            } else {
                // For other values, use strict comparison.
                return $expected_value === $prop_value;
            }
        } elseif ( is_array( $prop_value ) ) {
            return ! empty( $prop_value );
        } elseif ( is_string( $prop_value ) ) {
            return '' !== trim( $prop_value );
        } else {
            return ! empty( $prop_value );
        }
    }

    /**
     * Process feature checks and return enabled features.
     *
     * @since 4.6.9
     * @access private
     *
     * @param object $coupon        Advanced coupon object.
     * @param array  $feature_checks Feature checks configuration.
     * @return array Enabled features.
     */
    private function _process_feature_checks( $coupon, $feature_checks ) {
        $features = array();
        $coupon   = apply_filters( 'acfw_feature_custom_taxonomy_coupon', $coupon );

        foreach ( $feature_checks as $feature_slug => $checks ) {
            foreach ( $checks as $check ) {
                $prop_key       = $check[0];
                $expected_value = $check[1] ?? null;
                $third_param    = $check[2] ?? null;

                $prop_value = $this->_get_property_value( $coupon, $prop_key );
                $is_enabled = $this->_is_feature_enabled( $prop_value, $expected_value, $third_param );

                if ( $is_enabled ) {
                    $features[] = $feature_slug;
                    break;
                }
            }
        }

        return $features;
    }

    /**
     * Validate and filter features against known feature slugs.
     *
     * @since 4.6.9
     * @access private
     *
     * @param array $features Raw features array.
     * @return array Validated and filtered features.
     */
    private function _validate_and_filter_features( $features ) {
        $features    = array_unique( $features );
        $valid_slugs = $this->_get_valid_feature_slugs();

        return array_filter(
            $features,
            function ( $slug ) use ( $valid_slugs ) {
                return in_array( $slug, $valid_slugs, true );
            }
        );
    }

    /**
     * Get current feature taxonomy terms for a coupon.
     *
     * @since 4.6.9
     * @access private
     *
     * @param int $coupon_id Coupon ID.
     * @return array Array of current feature slugs.
     */
    private function _get_current_coupon_features( $coupon_id ) {
        if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
            return array();
        }

        $terms = wp_get_object_terms( $coupon_id, Plugin_Constants::FEATURE_CUSTOM_TAXONOMY );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        return wp_list_pluck( $terms, 'slug' );
    }

    /**
     * Update coupon feature taxonomy terms based on enabled features.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function update_coupon_features( $coupon_id ) {

        $coupon = new Advanced_Coupon( $coupon_id );
        if ( ! $coupon instanceof Advanced_Coupon || ! $coupon->get_id() ) {
            return;
        }

        // Get current features before updating (for usage tracking).
        $old_features = $this->_get_current_coupon_features( $coupon_id );

        // Get feature checks configuration.
        $feature_checks = $this->_get_feature_checks( $coupon_id, $coupon );

        // Process feature checks to get enabled features.
        $features = $this->_process_feature_checks( $coupon, $feature_checks );

        // Validate and filter features.
        $features = $this->_validate_and_filter_features( $features );

        // Update taxonomy terms only if taxonomy is properly registered.
        $this->_update_coupon_taxonomy_terms( $coupon_id, $features );

        // Update feature counts based on changes.
        $this->_update_feature_taxonomy_counts( $old_features, $features );
    }

    /**
     * Handle WooCommerce coupon created event.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int       $coupon_id Coupon ID.
     * @param WC_Coupon $coupon    WooCommerce coupon object.
     */
    public function handle_woocommerce_coupon_created( $coupon_id, $coupon ) {
        if ( ! $coupon_id ) {
            return;
        }

        // With priority 999, meta data should be fully saved.
        $this->update_coupon_features( $coupon_id );
    }

    /**
     * Handle WooCommerce coupon updated event.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int       $coupon_id Coupon ID.
     * @param WC_Coupon $coupon WooCommerce coupon object.
     */
    public function handle_woocommerce_coupon_updated( $coupon_id, $coupon ) {
        if ( ! $coupon_id ) {
            return;
        }

        // With priority 999, meta data should be fully saved.
        $this->update_coupon_features( $coupon_id );
    }

    /**
     * Handle WooCommerce coupon meta processing completion.
     * This runs after all WooCommerce meta data has been saved.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function handle_coupon_meta_saved( $post_id, $post ) {
        if ( ! $post_id || 'shop_coupon' !== $post->post_type ) {
            return;
        }

        // Process immediately since meta data is guaranteed to be saved.
        $this->update_coupon_features( $post_id );
    }

    /**
     * Update taxonomy terms for coupon with proper error handling.
     *
     * @since 4.6.9
     * @access private
     *
     * @param int   $coupon_id Coupon ID.
     * @param array $features  Features to assign.
     */
    private function _update_coupon_taxonomy_terms( $coupon_id, $features ) {
        // Validate coupon ID.
        if ( ! $coupon_id || ! is_numeric( $coupon_id ) ) {
            return;
        }

        // Check if taxonomy is registered.
        if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
            // Register taxonomy if not exists.
            $this->register_taxonomy();

            // Double check if registration succeeded.
            if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
                return;
            }
        }

        // Clear all existing feature terms first to ensure clean state.
        $deleted = wp_delete_object_term_relationships( $coupon_id, array( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) );

        // Check if deletion failed.
        if ( is_wp_error( $deleted ) ) {
            return;
        }

        // Update the taxonomy terms for this coupon (replace all, not append).
        if ( ! empty( $features ) ) {
            $result = wp_set_object_terms( $coupon_id, $features, Plugin_Constants::FEATURE_CUSTOM_TAXONOMY, false );

            // Handle potential errors.
            if ( is_wp_error( $result ) ) {
                return;
            }
        }
    }

    /**
     * Schedule bulk update of feature taxonomy for all existing coupons using Action Scheduler.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int $batch_size Number of coupons to process per batch.
     * @return bool True if scheduling was successful, false otherwise.
     */
    public function schedule_bulk_update_coupon_features( $batch_size = 50 ) {
        // Check if WooCommerce and Action Scheduler are available.
        if ( ! function_exists( 'WC' ) || ! function_exists( 'as_schedule_single_action' ) ) {
            return false;
        }

        // Get total coupon count.
        $total_coupons = wp_count_posts( 'shop_coupon' );
        $total_count   = $total_coupons->publish + $total_coupons->draft + $total_coupons->private;

        if ( $total_count <= 0 ) {
            return false;
        }

        // Clear any existing bulk update actions.
        as_unschedule_all_actions( Plugin_Constants::BULK_UPDATE_COUPON_FEATURES );

        // Schedule batch actions.
        $offset      = 0;
        $total_batch = ceil( $total_count / $batch_size );

        for ( $batch = 1; $batch <= $total_batch; $batch++ ) {
            as_schedule_single_action(
                time() + ( $batch * 10 ), // Stagger by 10 seconds per batch.
                Plugin_Constants::BULK_UPDATE_COUPON_FEATURES,
                array(
                    'offset'     => $offset,
                    'batch_size' => $batch_size,
                    'batch'      => $batch,
                    'total'      => $total_batch,
                ),
                'acfw-feature-taxonomy'
            );

            $offset += $batch_size;
        }

        return true;
    }

    /**
     * Process a batch of coupons for feature taxonomy update via Action Scheduler.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int $offset     Offset for the batch.
     * @param int $batch_size Number of coupons in this batch.
     * @param int $batch      Current batch number.
     * @param int $total      Total number of batches.
     */
    public function process_bulk_update_batch( $offset, $batch_size, $batch, $total ) {
        $coupons = get_posts(
            array(
                'post_type'   => 'shop_coupon',
                'numberposts' => $batch_size,
                'offset'      => $offset,
                'fields'      => 'ids',
                'post_status' => array( 'publish', 'draft', 'private' ),
            )
        );

        if ( empty( $coupons ) ) {
            return;
        }

        foreach ( $coupons as $coupon_id ) {
            try {
                $this->update_coupon_features( $coupon_id );
            } catch ( Exception $e ) {
                // Continue processing even if individual coupon update fails.
                continue;
            }
        }
    }

    /**
     * Calculate and update feature taxonomy counts for usage tracking.
     *
     * @since 4.6.9
     * @access public
     *
     * @param bool $force_recalculate Force recalculation even if disabled.
     * @return bool True on success, false on failure.
     */
    public function calculate_and_update_feature_counts( $force_recalculate = false ) {
        global $wpdb;

        // Check if feature counting is disabled (unless forced).
        if ( ! $force_recalculate && ! $this->_is_feature_counting_enabled() ) {
            return true;
        }

        // Check if taxonomy exists.
        if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
            // Mark as completed even if taxonomy doesn't exist yet.
            update_option( 'acfwf_feature_counts_initialized', time(), false );
            return true;
        }

        // Get all valid feature slugs.
        $valid_feature_slugs = $this->_get_valid_feature_slugs();

        if ( empty( $valid_feature_slugs ) ) {
            // Mark as completed even if no features.
            update_option( 'acfwf_feature_counts_initialized', time(), false );
            return true;
        }

        $errors = array();

        // Calculate counts for each feature.
        foreach ( $valid_feature_slugs as $feature_slug ) {
            try {
                $count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) 
                        FROM {$wpdb->posts} p
                        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                        WHERE p.post_type = 'shop_coupon'
                        AND p.post_status IN ('publish', 'draft', 'private')
                        AND tt.taxonomy = %s
                        AND t.slug = %s",
                        Plugin_Constants::FEATURE_CUSTOM_TAXONOMY,
                        $feature_slug
                    )
                );

                $option_key = $this->_get_feature_count_option_key( $feature_slug );
                update_option( $option_key, (int) $count, false );

            } catch ( Exception $e ) {
                $errors[] = "Failed to calculate count for {$feature_slug}: " . $e->getMessage();
                continue;
            }
        }

        // Allow premium plugins to calculate their own counts.
        do_action( 'acfw_calculate_feature_taxonomy_counts', $errors );

        // Mark initialization as complete with timestamp.
        update_option( 'acfwf_feature_counts_initialized', time(), false );

        return empty( $errors );
    }

    /**
     * Update feature taxonomy count when coupon features change.
     * Uses transient-based batching to optimize performance.
     *
     * @since 4.6.9
     * @access private
     *
     * @param array $old_features Previous feature slugs.
     * @param array $new_features Current feature slugs.
     */
    private function _update_feature_taxonomy_counts( $old_features, $new_features ) {
        // Check if feature counting is disabled.
        if ( ! $this->_is_feature_counting_enabled() ) {
            return;
        }

        // Get valid feature slugs.
        $valid_feature_slugs = $this->_get_valid_feature_slugs();

        if ( empty( $valid_feature_slugs ) ) {
            return;
        }

        // Filter to only valid features.
        $old_features = array_intersect( $old_features, $valid_feature_slugs );
        $new_features = array_intersect( $new_features, $valid_feature_slugs );

        // Find features that were removed.
        $removed_features = array_diff( $old_features, $new_features );

        // Find features that were added.
        $added_features = array_diff( $new_features, $old_features );

        // Batch count updates to reduce DB calls.
        $count_changes = array();

        // Prepare count changes for removed features.
        foreach ( $removed_features as $feature_slug ) {
            $count_changes[ $feature_slug ] = isset( $count_changes[ $feature_slug ] )
                ? $count_changes[ $feature_slug ] - 1
                : -1;
        }

        // Prepare count changes for added features.
        foreach ( $added_features as $feature_slug ) {
            $count_changes[ $feature_slug ] = isset( $count_changes[ $feature_slug ] )
                ? $count_changes[ $feature_slug ] + 1
                : 1;
        }

        // Apply count changes if any.
        if ( ! empty( $count_changes ) ) {
            $this->_apply_count_changes( $count_changes );
        }
    }

    /**
     * Apply batched count changes with proper error handling.
     *
     * @since 4.6.9
     * @access private
     *
     * @param array $count_changes Array of feature_slug => count_change pairs.
     */
    private function _apply_count_changes( $count_changes ) {
        foreach ( $count_changes as $feature_slug => $change ) {
            if ( 0 === $change ) {
                continue; // No change needed.
            }

            $option_key    = $this->_get_feature_count_option_key( $feature_slug );
            $current_count = get_option( $option_key, 0 );
            $new_count     = max( 0, (int) $current_count + $change );

            update_option( $option_key, $new_count, false );
        }
    }

    /**
     * Get the option key for a feature count with proper prefixing.
     *
     * @since 4.6.9
     * @access private
     *
     * @param string $feature_slug Feature slug.
     * @return string Option key.
     */
    private function _get_feature_count_option_key( $feature_slug ) {
        // Allow premium plugins to customize their prefix.
        $prefix = apply_filters( 'acfw_feature_count_option_prefix', 'acfwf', $feature_slug );
        return "{$prefix}_feature_taxonomy_count_{$feature_slug}";
    }

    /**
     * Check if feature counting is enabled.
     *
     * @since 4.6.9
     * @access private
     *
     * @return bool True if enabled, false otherwise.
     */
    private function _is_feature_counting_enabled() {
        // Allow disabling via filter for performance.
        return apply_filters( 'acfw_enable_feature_counting', true );
    }

    /**
     * Handle coupon deletion to update feature counts.
     *
     * @since 4.6.9
     * @access public
     *
     * @param int $coupon_id Coupon ID being deleted.
     */
    public function handle_coupon_deletion( $coupon_id ) {
        // Only process shop_coupon post type.
        if ( 'shop_coupon' !== get_post_type( $coupon_id ) ) {
            return;
        }

        // Get current features for the coupon being deleted.
        $current_features = $this->_get_current_coupon_features( $coupon_id );

        // Update counts by removing all current features.
        $this->_update_feature_taxonomy_counts( $current_features, array() );
    }

    /**
     * Add feature filter dropdown to coupon admin list.
     *
     * @since 4.6.9
     * @access public
     */
    public function add_feature_filter_dropdown() {
        global $typenow;

        if ( 'shop_coupon' !== $typenow ) {
            return;
        }

        // Check if taxonomy exists.
        if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
            return;
        }

        // Get all terms for the feature taxonomy.
        $terms = get_terms(
            array(
                'taxonomy'   => Plugin_Constants::FEATURE_CUSTOM_TAXONOMY,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            )
        );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return;
        }

        // Get current selected filter.
        $selected = isset( $_GET['acfw_feature_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['acfw_feature_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-feature-filter-dropdown.php';
    }

    /**
     * Get user-friendly display name for feature slug.
     *
     * @since 4.6.9
     * @access private
     *
     * @param string $slug Feature slug.
     * @return string Display name.
     */
    private function _get_feature_display_name( $slug ) {
        // Validate input slug.
        if ( empty( $slug ) || ! is_string( $slug ) ) {
            return '';
        }

        // Get valid feature slugs for validation.
        $valid_slugs = $this->_get_valid_feature_slugs();

        // Check if this is a valid feature slug.
        if ( ! in_array( $slug, $valid_slugs, true ) ) {
            // If not valid, don't display it.
            return '';
        }

        // Additional validation: ensure slug follows expected format.
        if ( ! str_starts_with( $slug, 'acfw-' ) || ! str_contains( $slug, '-module' ) ) {
            // Invalid format, don't display.
            return '';
        }

        // Convert slug to readable name.
        // Remove 'acfw-' prefix and '-module' suffix.
        $clean_name = $slug;
        $clean_name = substr( $clean_name, 5 ); // Remove 'acfw-'.
        $clean_name = substr( $clean_name, 0, -7 ); // Remove '-module'.

        // Validate that we have a clean name after processing.
        if ( empty( $clean_name ) ) {
            return '';
        }

        // Convert dashes/underscores to spaces and capitalize.
        $display_name = ucwords( str_replace( array( '-', '_' ), ' ', $clean_name ) );

        // Apply custom display name mappings if available.
        $name_mappings = $this->_get_feature_display_name_mappings();
        if ( isset( $name_mappings[ $display_name ] ) ) {
            $display_name = $name_mappings[ $display_name ];
        }

        return $display_name;
    }

    /**
     * Filter coupons by selected feature in admin list.
     *
     * @since 4.6.9
     * @access public
     *
     * @param WP_Query $query The WP_Query instance.
     */
    public function filter_coupons_by_feature( $query ) {
        global $pagenow, $typenow;

        // Only apply on admin coupon list page.
        if ( ! is_admin() || 'edit.php' !== $pagenow || 'shop_coupon' !== $typenow ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        // Only apply to main query.
        if ( ! $query->is_main_query() ) {
            return;
        }

        // Check if feature filter is set.
        if ( ! isset( $_GET['acfw_feature_filter'] ) || empty( $_GET['acfw_feature_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $feature_filter = sanitize_text_field( wp_unslash( $_GET['acfw_feature_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Validate that the feature filter is a valid term.
        $valid_slugs = $this->_get_valid_feature_slugs();
        if ( ! in_array( $feature_filter, $valid_slugs, true ) ) {
            return;
        }

        // Check if taxonomy exists.
        if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
            return;
        }

        // Add tax query to filter by feature.
        $tax_query = $query->get( 'tax_query' );
        if ( ! is_array( $tax_query ) ) {
            $tax_query = array();
        }

        $tax_query[] = array(
            'taxonomy' => Plugin_Constants::FEATURE_CUSTOM_TAXONOMY,
            'field'    => 'slug',
            'terms'    => $feature_filter,
        );

        $query->set( 'tax_query', $tax_query );
    }

    /**
     * Add feature filter to the query vars for admin.
     *
     * @since 4.6.9
     * @access public
     *
     * @param array $vars Current query vars.
     * @return array Modified query vars.
     */
    public function add_feature_filter_query_vars( $vars ) {
        $vars[] = 'acfw_feature_filter';
        return $vars;
    }

    /**
     * Add Features column to coupon admin list.
     *
     * @since 4.6.9
     * @access public
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public function add_features_column( $columns ) {
        $columns['acfw_features'] = __( 'Features', 'advanced-coupons-for-woocommerce-free' );
        return $columns;
    }

    /**
     * Populate the Features column content.
     *
     * @since 4.6.9
     * @access public
     *
     * @param string $column  Current column name.
     * @param int    $post_id Post ID (coupon ID).
     */
    public function populate_features_column( $column, $post_id ) {
        if ( 'acfw_features' !== $column ) {
            return;
        }

        // Check if taxonomy exists.
        if ( ! taxonomy_exists( Plugin_Constants::FEATURE_CUSTOM_TAXONOMY ) ) {
            echo '—';
            return;
        }

        // Get feature terms for this coupon.
        $terms = wp_get_object_terms( $post_id, Plugin_Constants::FEATURE_CUSTOM_TAXONOMY );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            echo '—';
            return;
        }

        $feature_links = array();
        foreach ( $terms as $term ) {
            $display_name = $this->_get_feature_display_name( $term->slug );
            if ( empty( $display_name ) ) {
                continue;
            }

            // Create filter URL.
            $filter_url = add_query_arg(
                array(
                    'post_type'           => 'shop_coupon',
                    'acfw_feature_filter' => $term->slug,
                ),
                admin_url( 'edit.php' )
            );

            $feature_links[] = sprintf(
                '<a href="%s" title="%s">%s</a>',
                esc_url( $filter_url ),
                /* translators: %s: feature display name */
                esc_attr( sprintf( __( 'Filter by %s feature', 'advanced-coupons-for-woocommerce-free' ), $display_name ) ),
                esc_html( $display_name )
            );
        }

        if ( empty( $feature_links ) ) {
            echo '—';
        } else {
            echo implode( ', ', $feature_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
    */

    /**
     * Activate the taxonomy registration.
     *
     * @since 4.6.9
     * @access public
     * @inherit ACFWF\Interfaces\Activatable_Interface
     */
    public function activate() {
        $this->register_taxonomy();
        $this->create_feature_terms();
        $this->schedule_bulk_update_coupon_features();
        $this->calculate_and_update_feature_counts( true );
    }

    /**
     * Execute Feature_Custom_Taxonomy class.
     *
     * @since 4.6.9
     * @access public
     * @inherit ACFWF\Interfaces\Model_Interface
     */
    public function run() {
        // Hook into advanced coupon save to update feature taxonomy terms.
        add_action( 'acfw_after_advanced_coupon_save', array( $this, 'update_coupon_features' ), 20, 1 );

        // Hook for WooCommerce coupon operations with lower priority to ensure meta data is saved.
        add_action( 'woocommerce_new_coupon', array( $this, 'handle_woocommerce_coupon_created' ), 999, 2 );
        add_action( 'woocommerce_update_coupon', array( $this, 'handle_woocommerce_coupon_updated' ), 999, 2 );
        add_action( 'woocommerce_process_shop_coupon_meta', array( $this, 'handle_coupon_meta_saved' ), 10, 2 );

        // Hook into Action Scheduler for bulk updates.
        add_action( Plugin_Constants::BULK_UPDATE_COUPON_FEATURES, array( $this, 'process_bulk_update_batch' ), 10, 4 );

        // Hook into term creation/deletion for validation and protection.
        add_action( 'created_term', array( $this, 'validate_term_creation' ), 10, 3 );
        add_action( 'delete_term', array( $this, 'protect_feature_terms' ), 10, 4 );

        // Admin hooks for feature filtering.
        add_action( 'restrict_manage_posts', array( $this, 'add_feature_filter_dropdown' ) );
        add_action( 'parse_query', array( $this, 'filter_coupons_by_feature' ) );
        add_filter( 'query_vars', array( $this, 'add_feature_filter_query_vars' ) );

        // Admin hooks for features column with higher priority.
        add_filter( 'manage_shop_coupon_posts_columns', array( $this, 'add_features_column' ), 15 );
        add_action( 'manage_shop_coupon_posts_custom_column', array( $this, 'populate_features_column' ), 15, 2 );

        // Hook for coupon deletion to update feature counts.
        add_action( 'before_delete_post', array( $this, 'handle_coupon_deletion' ), 10, 1 );

        add_action( 'admin_init', array( $this, 'register_taxonomy' ) );
    }
}
