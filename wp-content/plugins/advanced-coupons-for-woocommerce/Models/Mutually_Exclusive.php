<?php
namespace ACFWP\Models;

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
 * Model that houses the logic of the Mutually Exclusive Coupon Category.
 *
 * @since 3.5.5
 */
class Mutually_Exclusive extends Base_Model implements Model_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 3.5.5
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
     */

    /**
     * Add custom fields to the coupon category taxonomy on the add coupon category form.
     *
     * @since 3.5.5
     * @access public
     */
    public function taxonomy_form_fields_add() {
        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'category-form-add-mutually-exclusive-field.php';
    }

    /**
     * Add custom fields to the coupon category taxonomy on the edit coupon category form.
     *
     * @since 3.5.5
     * @access public
     *
     * @param object $term     Term object.
     * @param string $taxonomy Taxonomy slug.
     */
    public function taxonomy_form_fields_edit( $term, $taxonomy ) {
        // Mutually exclusive option.
        $mutually_exclusive = get_term_meta( $term->term_id, $this->_constants->MUTUALLY_EXCLUSIVE, true );
        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'category-form-edit-mutually-exclusive-field.php';
    }

    /**
     * Save custom fields' values.
     *
     * @since 3.5.5
     * @access public
     *
     * @param int   $term_id  Term ID.
     * @param int   $tt_id    Term taxonomy ID.
     * @param array $args     Arguments used to update term.
     */
    public function save_taxonomy_custom_fields( $term_id, $tt_id, $args ) {
        $data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification

        // Mutually exclusive option.
        $mutually_exclusive = isset( $data[ $this->_constants->MUTUALLY_EXCLUSIVE ] ) ? 'yes' : 'no';
        update_term_meta( $term_id, $this->_constants->MUTUALLY_EXCLUSIVE, $mutually_exclusive );
    }

    /*
    |--------------------------------------------------------------------------
    | Frontend Implementation
    |--------------------------------------------------------------------------
     */

    /**
     * Get the coupon category that is mutually exclusive with the given coupon.
     *
     * @since 3.3
     * @access public
     *
     * @param array     $coupon_exclude Excluded coupon.
     * @param WC_Coupon $coupon Coupon object.
     */
    public function implement_mutually_exclusive_category( $coupon_exclude, $coupon ) {
        // Get coupon category.
        $coupon_categories = wp_get_post_terms( $coupon->get_id(), 'shop_coupon_cat' );

        // Extract mutually exclusive category.
        $mutually_exclusive_category = array();
        foreach ( $coupon_categories as $coupon_category ) {
            $mutually_exclusive = get_term_meta( $coupon_category->term_id, $this->_constants->MUTUALLY_EXCLUSIVE, true );
            if ( 'yes' === $mutually_exclusive ) {
                $mutually_exclusive_category[] = 'cat_' . $coupon_category->slug;
            }
        }

        // Add mutually exclusive category to the excluded coupon.
        $coupon_exclude = array_merge( $coupon_exclude, $mutually_exclusive_category );
        $coupon_exclude = array_unique( $coupon_exclude );

        return $coupon_exclude;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute Cashback_Coupon class.
     *
     * @since 3.5.5
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        // Add custom fields to the coupon category taxonomy.
        add_filter( 'shop_coupon_cat_add_form_fields', array( $this, 'taxonomy_form_fields_add' ), 10, 0 );
        add_filter( 'shop_coupon_cat_edit_form_fields', array( $this, 'taxonomy_form_fields_edit' ), 10, 2 );

        // Save custom fields' values.
        add_action( 'create_shop_coupon_cat', array( $this, 'save_taxonomy_custom_fields' ), 10, 3 );
        add_action( 'edit_shop_coupon_cat', array( $this, 'save_taxonomy_custom_fields' ), 10, 3 );

        // Frontend implementation.
        add_filter( 'acfwp_coupon_excluded_coupons', array( $this, 'implement_mutually_exclusive_category' ), 10, 2 );
    }

}
