<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Models\Objects\Advanced_Coupon;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Model that houses the logic of the coupon editing interface.
 * Public Model.
 *
 * @since 2.0
 */
class Edit_Coupon implements Model_Interface, Initiable_Interface {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that holds the single main instance of URL_Coupon.
     *
     * @since 2.0
     * @access private
     * @var Edit_Coupon
     */
    private static $_instance;

    /**
     * Model that houses all the plugin constants.
     *
     * @since 2.0
     * @access private
     * @var Plugin_Constants
     */
    private $_constants;

    /**
     * Property that houses all the helper functions of the plugin.
     *
     * @since 2.0
     * @access private
     * @var Helper_Functions
     */
    private $_helper_functions;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Class constructor.
     *
     * @since 2.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     */
    public function __construct( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {
        $this->_constants        = $constants;
        $this->_helper_functions = $helper_functions;

        $main_plugin->add_to_all_plugin_models( $this );
        $main_plugin->add_to_public_models( $this );
    }

    /**
     * Ensure that only one instance of this class is loaded or can be loaded ( Singleton Pattern ).
     *
     * @since 2.0
     * @access public
     *
     * @param Abstract_Main_Plugin_Class $main_plugin      Main plugin object.
     * @param Plugin_Constants           $constants        Plugin constants object.
     * @param Helper_Functions           $helper_functions Helper functions object.
     * @return Edit_Coupon
     */
    public static function get_instance( Abstract_Main_Plugin_Class $main_plugin, Plugin_Constants $constants, Helper_Functions $helper_functions ) {

        if ( ! self::$_instance instanceof self ) {
            self::$_instance = new self( $main_plugin, $constants, $helper_functions );
        }

        return self::$_instance;
    }

    /*
    |--------------------------------------------------------------------------
    | One click apply notifications data
    |--------------------------------------------------------------------------
     */

    /**
     * Add "Apply notification" data tab to woocommerce coupon admin data tabs.
     *
     * @since 2.0
     * @access public
     *
     * @param array $coupon_data_tabs Array of coupon admin data tabs.
     * @return array Modified array of coupon admin data tabs.
     */
    public function apply_notification_admin_data_tab( $coupon_data_tabs ) {

        $coupon_data_tabs['acfw_apply_notification'] = array(
            'label'  => __( 'One Click Apply', 'advanced-coupons-for-woocommerce' ),
            'target' => 'acfw_apply_notification',
            'class'  => '',
        );

        return $coupon_data_tabs;
    }

    /**
     * Add "Apply notification" data panel to woocommerce coupon admin data panels.
     *
     * @since 2.0
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function apply_notification_admin_data_panel( $coupon_id ) {

        $panel_id            = 'acfw_apply_notification';
        $help_slug           = 'one-click-apply';
        $descriptions        = array( __( 'Show a WooCommerce notification on the cart page with a button to apply the coupon when the customer is eligible to apply this coupon.', 'advanced-coupons-for-woocommerce' ) );
        $coupon              = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $apply_notifications = \ACFWF()->Helper_Functions->get_option( $this->_constants->APPLY_NOTIFICATION_CACHE, array() );
        $additional_classes  = 'toggle-enable-fields';
        $title               = __( 'One Click Apply', 'advanced-coupons-for-woocommerce' );
        $fields              = apply_filters(
            'acfw_apply_notification_admin_data_panel_fields',
            array(

                array(
                    'cb'   => 'woocommerce_wp_checkbox',
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'enable_apply_notification',
                        'class'       => 'toggle-trigger-field',
                        'label'       => __( 'Enable one click apply notifications', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( 'When checked, this will enable one click apply notifications for this coupon which will then show a notification for customers in the cart when the coupon can be applied.', 'advanced-coupons-for-woocommerce' ),
                        'value'       => in_array( $coupon_id, $apply_notifications, true ) ? 'yes' : '',
                    ),
                ),

                array(
                    'cb'   => 'woocommerce_wp_textarea_input',
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'apply_notification_message',
                        'label'       => __( 'Notification message', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( "The notification message that will be displayed after checking that the coupon is elegible in the customer's cart.", 'advanced-coupons-for-woocommerce' ),
                        'desc_tip'    => true,
                        'type'        => 'text',
                        'placeholder' => __( 'Your current cart is eligible for a coupon.', 'advanced-coupons-for-woocommerce' ),
                        'value'       => $coupon->get_advanced_prop( 'apply_notification_message' ),
                    ),
                ),

                array(
                    'cb'   => 'woocommerce_wp_text_input',
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'apply_notification_btn_text',
                        'label'       => __( 'Apply Button Text', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( 'The text for the button to apply the coupon.', 'advanced-coupons-for-woocommerce' ),
                        'desc_tip'    => true,
                        'type'        => 'text',
                        'placeholder' => __( 'Apply Coupon', 'advanced-coupons-for-woocommerce' ),
                        'value'       => $coupon->get_advanced_prop( 'apply_notification_btn_text' ),
                    ),
                ),

                array(
                    'cb'   => 'woocommerce_wp_select',
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'apply_notification_type',
                        'label'       => __( 'Notification type', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( 'The type of notification to display.', 'advanced-coupons-for-woocommerce' ),
                        'desc_tip'    => true,
                        'type'        => 'text',
                        'placeholder' => __( 'Select...', 'advanced-coupons-for-woocommerce' ),
                        'value'       => $coupon->get_advanced_prop( 'apply_notification_type' ),
                        'options'     => array(
                            'notice'  => __( 'Info', 'advanced-coupons-for-woocommerce' ),
                            'success' => __( 'Success', 'advanced-coupons-for-woocommerce' ),
                            'error'   => __( 'Error', 'advanced-coupons-for-woocommerce' ),
                        ),
                    ),
                ),
            )
        );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-generic-admin-data-panel.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Payment methods restriction data
    |--------------------------------------------------------------------------
     */

    /**
     * Add payment methods restriction data tab to woocommerce coupon admin data tabs.
     *
     * @since 2.5
     * @access public
     *
     * @param array $coupon_data_tabs Array of coupon admin data tabs.
     * @return array Modified array of coupon admin data tabs.
     */
    public function payment_methods_restrict_admin_data_tab( $coupon_data_tabs ) {

        $coupon_data_tabs['acfw_payment_methods_restrict'] = array(
            'label'  => __( 'Payment Methods Restriction', 'advanced-coupons-for-woocommerce' ),
            'target' => 'acfw_payment_methods_restrict',
            'class'  => '',
        );

        return $coupon_data_tabs;
    }

    /**
     * Add payment methods restriction data panel to woocommerce coupon admin data panels.
     *
     * @since 1.0
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function payment_methods_restrict_admin_data_panel( $coupon_id ) {
        $panel_id           = 'acfw_payment_methods_restrict';
        $help_slug          = 'payment-methods-restriction';
        $descriptions       = array( __( 'Restrict the payment methods that are selectable on the checkout if this coupon is applied. The payment methods shown to the user on the checkout will be filtered via the rules below.', 'advanced-coupons-for-woocommerce' ) );
        $coupon             = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $additional_classes = 'toggle-enable-fields';
        $title              = __( 'Payment Methods Restriction', 'advanced-coupons-for-woocommerce' );
        $payment_methods    = \ACFWP()->Payment_Methods_Restrict->get_payment_gateway_options();
        $fields             = apply_filters(
            'acfw_payment_methods_restriction_admin_data_panel_fields',
            array(
                array(
                    'cb'   => 'woocommerce_wp_checkbox',
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'enable_payment_methods_restrict',
                        'class'       => 'toggle-trigger-field',
                        'label'       => __( 'Enable payment methods restriction', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( 'When checked, will enable payment methods restriction check when coupon is applied', 'advanced-coupons-for-woocommerce' ),
                        'value'       => $coupon->get_advanced_prop_edit( 'enable_payment_methods_restrict' ),
                    ),
                ),
                array(
                    'cb'   => 'woocommerce_wp_select',
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'payment_methods_restrict_type',
                        'style'       => 'width:50%;',
                        'label'       => __( 'Type', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( 'The type of implementation for this restriction. Select "allowed" to only allow payment via the selected methods. Select "disallowed" to only payment that don\'t fall under the selected methods.', 'advanced-coupons-for-woocommerce' ),
                        'desc_tip'    => true,
                        'type'        => 'select',
                        'options'     => array(
                            'allowed'    => __( 'Allowed', 'advanced-coupons-for-woocommerce' ),
                            'disallowed' => __( 'Disallowed', 'advanced-coupons-for-woocommerce' ),
                        ),
                        'value'       => $coupon->get_advanced_prop_edit( 'payment_methods_restrict_type' ),
                    ),
                ),
                array(
                    'cb'   => array( \ACFWF()->Edit_Coupon, 'acfw_multiselect_field' ),
                    'args' => array(
                        'id'          => $this->_constants->META_PREFIX . 'payment_methods_restrict_selection',
                        'class'       => 'wc-enhanced-select',
                        'style'       => 'width:50%;',
                        'label'       => __( 'Payment Methods', 'advanced-coupons-for-woocommerce' ),
                        'description' => __( 'The payment methods that should/shouldn\'t be used to process payment with this coupon.', 'advanced-coupons-for-woocommerce' ),
                        'desc_tip'    => true,
                        'type'        => 'text',
                        'options'     => $payment_methods,
                        'value'       => $coupon->get_advanced_prop_edit( 'payment_methods_restrict_selection' ),
                    ),

                ),
            )
        );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-generic-admin-data-panel.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Exclude Coupons
    |--------------------------------------------------------------------------
     */

    /**
     * Display exlcude coupons field inside "Usage restriction" tab.
     *
     * @since 2.0
     * @since 3.3 Display coupon categories in the field options.
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function display_exclude_coupons_field( $coupon_id ) {

        $coupon      = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $excluded    = $coupon->get_advanced_prop( 'excluded_coupons', array() );
        $options     = $this->_helper_functions->prepare_coupon_select_options( $excluded );
        $classname   = 'acfw_exclude_coupons_field acfw-coupons-restriction-field';
        $field_name  = '_acfw_exclude_coupon_ids[]';
        $field_label = __( 'Exclude coupons', 'advanced-coupons-for-woocommerce' );
        $placeholder = __( 'Search coupons and/or coupon categories&hellip;', 'advanced-coupons-for-woocommerce' );
        $tooltip     = __( 'This is the advanced version of the "Individual use only" field. Coupons listed here or coupons under the categories listed cannot be used in conjunction with this coupon.', 'advanced-coupons-for-woocommerce' );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-select-multiple-coupon-field.php';
    }

    /**
     * Add help link in usage restrictions tab.
     *
     * @since 2.7
     * @access public
     */
    public function usage_restrictions_add_help_link() {
        echo '<div class="acfw-help-link" data-module="usage-restrictions"></div>';
    }

    /*
    |--------------------------------------------------------------------------
    | Product Attributes
    |--------------------------------------------------------------------------
     */

    /**
     * Display product attributes field inside "Usage restriction" tab.
     *
     * @since 3.6.1 Display product attributes in the field options.
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function display_product_attributes_field( $coupon_id ) {

        $coupon             = new Advanced_Coupon( $coupon_id );
        $product_attributes = $coupon->get_advanced_prop( 'product_attributes' );
        $options            = $this->_helper_functions->prepare_product_attribute_select_options( $product_attributes );
        $classname          = 'acfw_product_attributes_field acfw-product-attributes-restriction-field';
        $label_for          = 'acfw_product_attributes';
        $field_name         = '_acfw_product_attributes_ids[]';
        $field_label        = __( 'Product attributes', 'advanced-coupons-for-woocommerce' );
        $placeholder        = __( 'Search product attributes&hellip;', 'advanced-coupons-for-woocommerce' );
        $tooltip            = __( 'Search and select product attributes that are eligible to only use this coupon.', 'advanced-coupons-for-woocommerce' );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-select-multiple-product-attribute-field.php';
    }

    /**
     * Display exclude product attributes field inside "Usage restriction" tab.
     *
     * @since 3.6.1 Display exclude product attributes in the field options.
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function display_exclude_product_attributes_field( $coupon_id ) {

        $coupon                      = new Advanced_Coupon( $coupon_id );
        $excluded_product_attributes = $coupon->get_advanced_prop( 'excluded_product_attributes' );
        $options                     = $this->_helper_functions->prepare_product_attribute_select_options( $excluded_product_attributes );
        $classname                   = 'acfw_exclude_product_attributes_field acfw-exclude-product-attributes-restriction-field';
        $label_for                   = 'acfw_exclude_product_attributes';
        $field_name                  = '_acfw_exclude_product_attributes_ids[]';
        $field_label                 = __( 'Exclude product attributes', 'advanced-coupons-for-woocommerce' );
        $placeholder                 = __( 'Search product attributes&hellip;', 'advanced-coupons-for-woocommerce' );
        $tooltip                     = __( 'Search and select product attributes that will be excluded from using this coupon.', 'advanced-coupons-for-woocommerce' );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-select-multiple-product-attribute-field.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Add Products Data
    |--------------------------------------------------------------------------
     */

    /**
     * Add new "add free products" data tab to woocommerce coupon admin data tabs.
     *
     * @since 2.0
     * @access public
     *
     * @param array $coupon_data_tabs Array of coupon admin data tabs.
     * @return array Modified array of coupon admin data tabs.
     */
    public function add_products_admin_data_tab( $coupon_data_tabs ) {

        $coupon_data_tabs['acfw_add_products'] = array(
            'label'  => __( 'Add Products', 'advanced-coupons-for-woocommerce' ),
            'target' => 'acfw_add_products',
            'class'  => '',
        );

        return $coupon_data_tabs;
    }

    /**
     * Add "add free products" data panel to woocommerce coupon admin data panels.
     *
     * @since 2.0
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function add_products_admin_data_panel( $coupon_id ) {

        $panel_id        = 'acfw_add_products';
        $coupon          = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $spinner_img     = $this->_constants->IMAGES_ROOT_URL . 'spinner-2x.gif';
        $add_products    = $coupon->get_add_products_data( 'edit' );
        $exclude         = is_array( $add_products ) ? array_column( $add_products, 'product_id' ) : array();
        $panel_data_atts = apply_filters( 'acfwp_add_products_panel_data_atts', array(), $add_products, $coupon );

        // Get enable state - auto-enable if coupon already has add products data.
        $enable_add_products = $coupon->get_advanced_prop( 'enable_add_products' );
        if ( '' === $enable_add_products && ! empty( $add_products ) ) {
            $enable_add_products = 'yes';
        }

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-add-products-data-panel.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Auto Apply Data
    |--------------------------------------------------------------------------
     */

    /**
     * Register auto apply coupon metabox.
     *
     * @since 2.0
     * @access public
     *
     * @param string  $post_type Post type.
     * @param WP_Post $post      Post object.
     */
    public function register_auto_apply_metabox( $post_type, $post ) {

        if ( 'shop_coupon' !== $post_type ) {
            return;
        }

        $metabox = function ( $post ) {

            $auto_apply_coupons = \ACFWF()->Helper_Functions->get_option( $this->_constants->AUTO_APPLY_COUPONS, array() );
            $input_name         = $this->_constants->META_PREFIX . 'auto_apply_coupon';

            ?>
            <label>
                <input id="acfw_auto_apply_coupon_field" type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" value="yes" <?php checked( in_array( $post->ID, $auto_apply_coupons, true ), true ); ?>>
                <?php esc_html_e( 'Enable auto apply for this coupon.', 'advanced-coupons-for-woocommerce' ); ?>
            </label>
            <div class="auto-apply-warning">
                <?php
                echo wp_kses_post(
                    sprintf(
                        /* Translators: %1$s: Formatting tag start. %2$s: Formatting tag end. */
                        __(
                            '%1$sNote:%2$s this coupon won\'t be auto applied when "Virtual coupons" is enabled, or when "Usage limit per user" is set and the shopper is not logged in. A coupon restricted by "Allowed emails" is auto applied once the customer\'s email is known — a logged-in customer, or a guest who has entered their email on the block-based checkout.',
                            'advanced-coupons-for-woocommerce'
                        ),
                        '<strong>',
                        '</strong>'
                    )
                );
                    ?>
            </div>
            <?php
        };

        add_meta_box( 'acfw-auto-apply-coupon', __( 'Auto Apply Coupon', 'advanced-coupons-for-woocommerce' ), $metabox, 'shop_coupon', 'side' );
    }

    /*
    |--------------------------------------------------------------------------
    | Shipping Overrides
    |--------------------------------------------------------------------------
     */

    /**
     * Add shipping ovverides data tab to woocommerce coupon admin data tabs.
     *
     * @since 2.0
     * @access public
     *
     * @param array $coupon_data_tabs Array of coupon admin data tabs.
     * @return array Modified array of coupon admin data tabs.
     */
    public function shipping_overrides_admin_data_tab( $coupon_data_tabs ) {

        $coupon_data_tabs['acfw_shipping_overrides'] = array(
            'label'  => __( 'Shipping Overrides', 'advanced-coupons-for-woocommerce' ),
            'target' => 'acfw_shipping_overrides',
            'class'  => '',
        );

        return $coupon_data_tabs;
    }

    /**
     * Add "Shipping Overrides" data panel to woocommerce coupon admin data panels.
     *
     * @since 2.0
     * @access public
     *
     * @param int $coupon_id WC_Coupon ID.
     */
    public function shipping_overrides_admin_data_panel( $coupon_id ) {

        $panel_id     = 'acfw_shipping_overrides';
        $coupon       = \ACFWF()->Edit_Coupon->get_shared_advanced_coupon( $coupon_id );
        $spinner_img  = $this->_constants->IMAGES_ROOT_URL . 'spinner-2x.gif';
        $zone_methods = apply_filters( 'acfw_shipping_override_selectable_options', array() );
        $overrides    = $coupon->get_shipping_overrides_data_edit();
        $exclude      = array_map(
            function ( $row ) {
            return array(
                'zone'   => $row['shipping_zone'],
                'method' => $row['shipping_method'],
            );
            },
            $overrides
        );

        // Get enable state - auto-enable if coupon already has shipping overrides data.
        $enable_shipping_overrides = $coupon->get_advanced_prop( 'enable_shipping_overrides' );
        if ( '' === $enable_shipping_overrides && ! empty( $overrides ) ) {
            $enable_shipping_overrides = 'yes';
        }

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-shipping-overrides-data-panel.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Advanced Usage Limits.
    |--------------------------------------------------------------------------
     */

    /**
     * Advanced usage limits fields.
     *
     * @since 2.0
     * @access public
     *
     * @param int       $coupon_id Coupon ID.
     * @param WC_Coupon $coupon    Coupon object.
     */
    public function advanced_usage_limits_fields( $coupon_id, $coupon ) {

        $period = $coupon->get_meta( $this->_constants->META_PREFIX . 'reset_usage_limit_period', true );
        $reset  = $coupon->get_meta( $this->_constants->META_PREFIX . 'usage_limit_reset_time', true );

        woocommerce_wp_select(
            array(
                'id'          => $this->_constants->META_PREFIX . 'reset_usage_limit_period',
                'label'       => __( 'Reset usage count every:', 'advanced-coupons-for-woocommerce' ),
                'options'     => array(
                    'none'    => __( 'Never reset', 'advanced-coupons-for-woocommerce' ),
                    'yearly'  => __( 'Every year', 'advanced-coupons-for-woocommerce' ),
                    'monthly' => __( 'Every month', 'advanced-coupons-for-woocommerce' ),
                    'weekly'  => __( 'Every week', 'advanced-coupons-for-woocommerce' ),
                    'daily'   => __( 'Every day', 'advanced-coupons-for-woocommerce' ),
                ),
                'description' => __( 'Set the time period to reset the usage limit count. <strong>Yearly:</strong> resets at start of the year. <strong>Monthly:</strong> resets at start of the month. <strong>Weekly:</strong> resets at the start of every week (day depends on the <em>"Week Starts On"</em> setting). <strong>Daily:</strong> resets everyday. Time is always set at 12:00am of the local timezone settings.', 'advanced-coupons-for-woocommerce' ),
                'desc_tip'    => true,
                'value'       => $period,
            )
        );

        if ( $period && 'none' !== $period && $reset ) :
            $timezone = new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() );
            $dateobj  = new \DateTime();

            $dateobj->setTimestamp( $reset );
            $dateobj->setTimezone( $timezone );

            $next_reset_date = date_i18n( $this->_helper_functions->get_datetime_format(), $dateobj->getTimestamp() + $dateobj->getOffset() );

            ?>
                <div class="acfw-usage-limit-info">
                    <p class="reset-time">
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* Translators: %1$s: Next reset date. %2$s: Timezone. */
                                __( 'Next reset for coupon usage count is on %1$s %2$s', 'advanced-coupons-for-woocommerce' ),
                                '<code>' . $next_reset_date,
                                $timezone->getName() . '</code>'
                            )
                        );
                            ?>
                            </p>
                </div>
            <?php
            endif;
    }

    /**
     * Add help link in usage limits tab.
     *
     * @since 2.7
     * @access public
     */
    public function usage_limits_add_help_link() {
        echo '<div class="acfw-help-link" data-module="usage-limits"></div>';
    }

    /*
    |--------------------------------------------------------------------------
    | Sort Coupons
    |--------------------------------------------------------------------------
     */

    /**
     * Display sort coupon priority field under general tab.
     *
     * @since 2.5
     * @access public
     *
     * @param int       $coupon_id Coupon ID.
     * @param WC_Coupon $coupon    Coupon object.
     */
    public function display_coupon_sort_priority_field( $coupon_id, $coupon ) {
        $coupon_priority  = (int) $coupon->get_meta( $this->_constants->META_PREFIX . 'coupon_sort_priority', true );
        $coupon_priority  = $coupon_priority ? $coupon_priority : 30;
        $priority_options = array(
            '1'  => __( 'Very high (1)', 'advanced-coupons-for-woocommerce' ),
            '10' => __( 'High (10)', 'advanced-coupons-for-woocommerce' ),
            '30' => __( 'Normal (30)', 'advanced-coupons-for-woocommerce' ),
            '50' => __( 'Low (50)', 'advanced-coupons-for-woocommerce' ),
            '90' => __( 'Very Low (90)', 'advanced-coupons-for-woocommerce' ),
        );
        $is_custom        = ! in_array( $coupon_priority, array_keys( $priority_options ), true );

        include $this->_constants->VIEWS_ROOT_PATH . 'coupons' . DIRECTORY_SEPARATOR . 'view-coupon-sort-priority-field.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Save coupon
    |--------------------------------------------------------------------------
     */

    /**
     * Save coupon data.
     *
     * @since 2.0
     * @since 2.1 Delete _acfw_schedule_expire meta on save.
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon    Advanced coupon object.
     */
    public function save_coupon_data( $coupon_id, $coupon ) {

        // Verify ACFW's dedicated nonce to ensure the request is valid before saving ACFW data.
        // Uses _acfw_nonce (not _wpnonce) to avoid conflicts with plugins like WooPayments that
        // may modify the shared _wpnonce field before form submission.
        if ( ! isset( $_POST['_acfw_nonce'] ) || false === wp_verify_nonce( sanitize_key( $_POST['_acfw_nonce'] ), 'acfw_save_coupon_data_' . $coupon_id ) ) {
            return;
        }

        // get ACFW related data only.
        $post_data = $this->filter_unslash_acfw_data( $_POST );

        // Exclude coupons.
        $excluded_coupons = isset( $post_data['exclude_coupon_ids'] ) ? $post_data['exclude_coupon_ids'] : array();
        $excluded_coupons = is_array( $excluded_coupons ) && ! empty( $excluded_coupons ) ? array_map( 'sanitize_text_field', $excluded_coupons ) : array();
        $coupon->set_advanced_prop( 'excluded_coupons', $excluded_coupons );

        // Product attributes.
        $product_attributes = isset( $post_data['product_attributes_ids'] ) ? $post_data['product_attributes_ids'] : array();
        $product_attributes = is_array( $product_attributes ) && ! empty( $product_attributes ) ? array_map( 'sanitize_text_field', $product_attributes ) : array();
        $coupon->set_advanced_prop( 'product_attributes', $product_attributes );
        $excluded_product_attributes = isset( $post_data['exclude_product_attributes_ids'] ) ? $post_data['exclude_product_attributes_ids'] : array();
        $excluded_product_attributes = is_array( $excluded_product_attributes ) && ! empty( $excluded_product_attributes ) ? array_map( 'sanitize_text_field', $excluded_product_attributes ) : array();
        $coupon->set_advanced_prop( 'excluded_product_attributes', $excluded_product_attributes );

        // Set coupon as auto apply.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::AUTO_APPLY_MODULE ) ) {

            $auto_apply_coupon = isset( $post_data['auto_apply_coupon'] ) && 'yes' === $post_data['auto_apply_coupon'];
            $coupon->set_advanced_prop( 'auto_apply_coupon', $auto_apply_coupon );
        }

        // apply notification module.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::APPLY_NOTIFICATION_MODULE ) ) {

            $enable_apply_notification   = isset( $post_data['enable_apply_notification'] ) && 'yes' === $post_data['enable_apply_notification'];
            $apply_notification_message  = isset( $post_data['apply_notification_message'] ) ? wp_kses_post( $post_data['apply_notification_message'] ) : '';
            $apply_notification_btn_text = isset( $post_data['apply_notification_btn_text'] ) ? sanitize_text_field( $post_data['apply_notification_btn_text'] ) : '';
            $apply_notification_btn_type = isset( $post_data['apply_notification_type'] ) ? sanitize_text_field( $post_data['apply_notification_type'] ) : '';

            $coupon->set_advanced_prop( 'enable_apply_notification', $enable_apply_notification );

            if ( $enable_apply_notification ) {

                $coupon->set_advanced_prop( 'apply_notification_message', $apply_notification_message );
                $coupon->set_advanced_prop( 'apply_notification_btn_text', $apply_notification_btn_text );
                $coupon->set_advanced_prop( 'apply_notification_type', $apply_notification_btn_type );

            }
        }

        // Payment methods restriction.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::PAYMENT_METHODS_RESTRICT ) ) {

            $enable_payment_methods_restrict    = isset( $post_data['enable_payment_methods_restrict'] ) ? sanitize_text_field( $post_data['enable_payment_methods_restrict'] ) : '';
            $payment_methods_restrict_type      = isset( $post_data['payment_methods_restrict_type'] ) ? sanitize_text_field( $post_data['payment_methods_restrict_type'] ) : 'allowed';
            $payment_methods_restrict_selection = isset( $post_data['payment_methods_restrict_selection'] ) && is_array( $post_data['payment_methods_restrict_selection'] ) ? array_map( 'sanitize_text_field', $post_data['payment_methods_restrict_selection'] ) : array();

            $coupon->set_advanced_prop( 'enable_payment_methods_restrict', $enable_payment_methods_restrict );

            if ( 'yes' === $enable_payment_methods_restrict ) {
                $coupon->set_advanced_prop( 'payment_methods_restrict_type', $payment_methods_restrict_type );
                $coupon->set_advanced_prop( 'payment_methods_restrict_selection', $payment_methods_restrict_selection );
            }
        }

        // Usage limits module.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::USAGE_LIMITS_MODULE ) ) {
            $reset_usage_limit_period = isset( $post_data['reset_usage_limit_period'] ) && $post_data['reset_usage_limit_period'] ? sanitize_text_field( $post_data['reset_usage_limit_period'] ) : 'none';
            $coupon->set_advanced_prop( 'reset_usage_limit_period', $reset_usage_limit_period );
        }

        // Sort coupons.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SORT_COUPONS_MODULE ) ) {
            $coupon_sort = isset( $post_data['coupon_sort_priority'] ) && $post_data['coupon_sort_priority'] ? absint( $post_data['coupon_sort_priority'] ) : -1;

            if ( $coupon_sort > -1 ) {
                $coupon->set_advanced_prop( 'coupon_sort_priority', $coupon_sort );
            }
        }

        // Add Products enable state.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::ADD_PRODUCTS_MODULE ) ) {
            $enable_add_products = isset( $post_data['enable_add_products'] ) && 'yes' === $post_data['enable_add_products'] ? 'yes' : 'no';
            $coupon->set_advanced_prop( 'enable_add_products', $enable_add_products );
        }

        // Shipping Overrides enable state.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SHIPPING_OVERRIDES_MODULE ) ) {
            $enable_shipping_overrides = isset( $post_data['enable_shipping_overrides'] ) && 'yes' === $post_data['enable_shipping_overrides'] ? 'yes' : 'no';
            $coupon->set_advanced_prop( 'enable_shipping_overrides', $enable_shipping_overrides );
        }
    }

    /**
     * Filter the $_POST array with only ACFW related data and unslash the values.
     *
     * @since 3.5.3
     * @access private
     *
     * @param array $raw_data Raw data from $_POST.
     * @return array Filtered and unslashed data.
     */
    private function filter_unslash_acfw_data( $raw_data ) {

        $post_data = array();
        foreach ( $raw_data as $raw_key => $value ) {
            if ( str_contains( $raw_key, $this->_constants->META_PREFIX ) ) {
                $key               = str_replace( $this->_constants->META_PREFIX, '', $raw_key );
                $post_data[ $key ] = $value;
            }
        }

        return wp_unslash( $post_data );
    }

    /**
     * Remove coupon from apply notifications cache on delete.
     *
     * @since 2.0
     * @access public
     *
     * @param int $coupon_id Coupon ID.
     */
    public function remove_coupon_from_global_options_cache( $coupon_id ) {

        if ( get_post_type( $coupon_id ) !== 'shop_coupon' ) {
            return;
        }

        remove_action( 'save_post', array( \ACFWF()->Edit_Coupon, 'save_url_coupons_data' ), 10 );

        foreach ( $this->_constants->CACHE_OPTIONS() as $cache_option ) {

            $cache = \ACFWF()->Helper_Functions->get_option( $cache_option, array() );
            $key   = array_search( $coupon_id, $cache, true );

            if ( false !== $key && in_array( $coupon_id, $cache, true ) ) {

                if ( $key >= 0 ) {
                    unset( $cache[ $key ] );
                }

                update_option( $cache_option, $cache );
            }
        }
    }

    /**
     * Register 'exists' and 'noexist' condition values.
     *
     * @since 3.2.1
     * @access public
     *
     * @param array $allowed_values List of allowed condition values.
     * @return array Filtered list of allowed condition values.
     */
    public function register_condition_exist_type_values( $allowed_values ) {
        $allowed_values[] = 'exists';
        $allowed_values[] = 'notexist';

        return $allowed_values;
    }

    /**
     * Register contains condition values.
     *
     * @since 4.0.4
     * @access public
     *
     * @param array $allowed_values List of allowed condition values.
     * @return array Filtered list of allowed condition values.
     */
    public function register_condition_contains_type_values( $allowed_values ) {
        $allowed_values[] = 'contains';

        return $allowed_values;
    }

    /*
    |--------------------------------------------------------------------------
    | Help modal
    |--------------------------------------------------------------------------
     */

    /**
     * Replace utm souce parameter value of all links in help modal from 'acfwf' to 'acfwp'.
     *
     * @since 3.0.1
     * @since 3.5.5 Added support for WP remote GET response data as string.
     * @access public
     *
     * @param array $content WP remote GET response data.
     * @return array Filtered WP remote GET response data..
     */
    public function replace_utm_source_in_help_modal_content( $content ) {

        if ( isset( $content['body'] ) && is_string( $content['body'] ) ) {
            $content['body'] = str_replace( 'utm_source=acfwf', 'utm_source=acfwp', $content['body'] );
        } elseif ( is_string( $content ) ) {
            $content = str_replace( 'utm_source=acfwf', 'utm_source=acfwp', $content );
        }

        return $content;
    }

    /*
    |--------------------------------------------------------------------------
    | Fulfill implemented interface contracts
    |--------------------------------------------------------------------------
     */

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {     }

    /**
     * Execute Edit_Coupon class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        add_action( 'acfw_before_save_coupon', array( $this, 'save_coupon_data' ), 10, 2 );
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'display_exclude_coupons_field' ) );
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'usage_restrictions_add_help_link' ) );
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'display_product_attributes_field' ) );
        add_action( 'woocommerce_coupon_options_usage_restriction', array( $this, 'display_exclude_product_attributes_field' ) );

        // Auto apply.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::AUTO_APPLY_MODULE ) ) {
            add_action( 'add_meta_boxes', array( $this, 'register_auto_apply_metabox' ), 10, 2 );
        }

        // Shipping Overrides module.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SHIPPING_OVERRIDES_MODULE ) ) {

            add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'shipping_overrides_admin_data_tab' ), 70, 1 );
            add_action( 'woocommerce_coupon_data_panels', array( $this, 'shipping_overrides_admin_data_panel' ) );
        }

        // Usage limit.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::USAGE_LIMITS_MODULE ) ) {
            add_action( 'woocommerce_coupon_options_usage_limit', array( $this, 'advanced_usage_limits_fields' ), 10, 2 );
            add_action( 'woocommerce_coupon_options_usage_limit', array( $this, 'usage_limits_add_help_link' ) );
        }

        // Add products.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::ADD_PRODUCTS_MODULE ) ) {

            add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'add_products_admin_data_tab' ), 30, 1 );
            add_action( 'woocommerce_coupon_data_panels', array( $this, 'add_products_admin_data_panel' ) );
        }

        // Apply notification module.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::APPLY_NOTIFICATION_MODULE ) ) {

            add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'apply_notification_admin_data_tab' ), 70, 1 );
            add_action( 'woocommerce_coupon_data_panels', array( $this, 'apply_notification_admin_data_panel' ) );
        }

        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::PAYMENT_METHODS_RESTRICT ) ) {
            add_filter( 'woocommerce_coupon_data_tabs', array( $this, 'payment_methods_restrict_admin_data_tab' ), 50, 1 );
            add_action( 'woocommerce_coupon_data_panels', array( $this, 'payment_methods_restrict_admin_data_panel' ) );
        }

        // Sort coupons.
        if ( \ACFWF()->Helper_Functions->is_module( Plugin_Constants::SORT_COUPONS_MODULE ) ) {

            add_action( 'woocommerce_coupon_options', array( $this, 'display_coupon_sort_priority_field' ), 10, 2 );
        }

        // Remove coupon on global options cache when trashed or deleted.
        add_action( 'wp_trash_post', array( $this, 'remove_coupon_from_global_options_cache' ) );
        add_action( 'before_delete_post', array( $this, 'remove_coupon_from_global_options_cache' ) );
        add_action( 'untrashed_post', array( $this, 'remove_coupon_from_global_options_cache' ) );

        // disable permission check for help links.
        add_filter(
            'pre_option_' . $this->_constants->ALLOW_FETCH_CONTENT_REMOTE,
            function () {
            return 'yes';
            }
        );

        add_filter( 'acfwf_help_modal_content_response', array( $this, 'replace_utm_source_in_help_modal_content' ) );

        add_filter( 'acfw_condition_select_allowed_values', array( $this, 'register_condition_exist_type_values' ) );
        add_filter( 'acfw_condition_select_allowed_values', array( $this, 'register_condition_contains_type_values' ) );
    }
}
