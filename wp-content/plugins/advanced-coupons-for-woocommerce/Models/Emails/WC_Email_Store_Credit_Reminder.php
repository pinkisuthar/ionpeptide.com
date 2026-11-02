<?php

namespace ACFWP\Models\Emails;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Instance of \WC_Email that houses store credit reminder email logic.
 *
 * @since 3.5.5
 */
class WC_Email_Store_Credit_Reminder extends \WC_Email {

    /**
     * Class constructor.
     *
     * @since 3.5.5
     * @access public
     *
     * @param string $id Email id.
     */
    public function __construct( $id ) {
        // Assign variables.
        $this->id             = $id;
        $this->customer_email = true;
        $this->title          = __( 'Advanced Coupons - store credit reminder', 'advanced-coupons-for-woocommerce' );
        $this->description    = __( 'Store credit reminder email that is sent to the customer.', 'advanced-coupons-for-woocommerce' );
        $this->template_html  = 'emails/store-credit-reminder.php';
        $this->template_plain = 'emails/plain/store-credit-reminder.php';
        $this->placeholders   = array(
            '{customer_name}'    => '',
            '{customer_email}'   => '',
            '{customer_balance}' => '',
        );

        // Call parent constructor.
        parent::__construct();
    }

    /**
     * Get email default text.
     *
     * @since 3.5.5
     * @access public
     *
     * @param string $type Email text type.
     * @return string
     */
    public function get_default_text( $type ) {
        switch ( $type ) {
            case 'subject':
                /* Translators: %s: Site title */
                return sprintf( __( '%s: Store credits balance reminder!', 'advanced-coupons-for-woocommerce' ), '[{site_title}]' );
            case 'heading':
                return __( 'You have unused store credits', 'advanced-coupons-for-woocommerce' );
            case 'message':
                /* Translators: %s: Customer Name */
                $message = __( 'Hey %s, we just want to remind you about your unused store credits that you can use as discount/payment in your next order.', 'advanced-coupons-for-woocommerce' );
                return sprintf( $message, '{customer_name}' );
            case 'balance':
                /* Translators: %s: Store Credit Balance */
                $text = __( 'Store credit balance: %s', 'advanced-coupons-for-woocommerce' );
                return sprintf( $text, '{customer_balance}' );
            case 'additional_content':
                return '';
            case 'promotion_text':
                return __( 'Here are some products that you might like:', 'advanced-coupons-for-woocommerce' );
            case 'add_to_cart_text':
                return __( 'Add to cart', 'advanced-coupons-for-woocommerce' );
            case 'button_text':
                return __( 'View Shop', 'advanced-coupons-for-woocommerce' );
        }
    }

    /**
     * Get email text either from option or default text.
     *
     * @since 3.5.5
     * @access public
     *
     * @param string $type Email text type.
     * @param string $format Whether to format the text or not.
     *
     * @retrun string
     */
    public function get_text( $type, $format = true ) {
        $message = $this->get_option( $type, $this->get_default_text( $type ) );
        $message = ( $format ) ? $this->format_string( $message ) : $message;

        return apply_filters( $this->id . '_email_' . $type, $message, $this->object, $this );
    }

    /**
     * Get store credit balance
     *
     * @since 3.5.5
     * @access public
     *
     * @return string
     */
    public function get_balance() {
        $balance = \ACFWF()->Store_Credits_Calculate->get_customer_balance( $this->customer->get_id() ); // Get balance.
        $balance = wc_price( $balance ); // Add currency symbol.
        $balance = apply_filters( $this->id . '_email_balance', $balance, $this->object, $this ); // Apply filter.

        return $balance;
    }

    /**
     * Set wc customer.
     *
     * @since 3.5.5
     * @access public
     *
     * @param \WC_Customer $customer Customer object.
     */
    public function set_customer( \WC_Customer $customer ) {
        $this->customer                           = $customer;
        $this->placeholders['{customer_name}']    = $customer->get_display_name();
        $this->placeholders['{customer_email}']   = $customer->get_email();
        $this->placeholders['{customer_balance}'] = $this->get_balance();
    }

    /**
     * Override setup locale function to remove customer email check.
     *
     * @since 3.5.5
     * @access public
     */
    public function setup_locale() {
        if ( apply_filters( 'woocommerce_email_setup_locale', true ) ) {
            wc_switch_to_site_locale();
        }
    }

    /**
     * Override restore locale function to remove customer email check.
     *
     * @since 3.5.5
     * @access public
     */
    public function restore_locale() {
        if ( apply_filters( 'woocommerce_email_restore_locale', true ) ) {
            wc_restore_locale();
        }
    }

    /**
     * Get email content html.
     *
     * @since 3.5.5
     * @access public
     *
     * @return string Email html content.
     */
    public function get_content_html() {
        ob_start();

        \ACFWP()->Helper_Functions->load_template(
            $this->template_html,
            array(
                'email' => $this,
            )
        );

        return ob_get_clean();
    }

    /**
     * Get email plain content.
     *
     * @since 3.5.5
     * @access public
     *
     * @return string Email plain content.
     */
    public function get_content_plain() {
        ob_start();

        \ACFWP()->Helper_Functions->load_template(
            $this->template_plain,
            array(
                'email' => $this,
            )
        );

        return ob_get_clean();
    }

    /**
     * Get Promotional Product Type Option
     * - This is required because it used both in email template and in functions.
     *
     * @since 3.5.5
     * @access public
     */
    public function get_promotional_products_type() {
        $option = get_option( $this->id . '_promotion', 'none' );
        $option = ( 'none' === $option ) ? '' : $option;

        return $option;
    }

    /**
     * Get Promotional Products
     *
     * @since 3.5.5
     * @access public
     */
    public function get_promotional_products() {
        $option   = $this->get_promotional_products_type();
        $products = array();
        if ( $option ) {
            $hook_name = $this->id . '_promotional_product_arguments';
            switch ( $option ) :
                case 'random':
                    $args     = array(
                        'limit'   => 3,
                        'status'  => 'publish',
                        'orderby' => 'rand',
                    );
                    $args     = apply_filters( $hook_name, $args );
                    $products = wc_get_products( $args );
                    break;

                case 'popular':
                    $featured_product_ids = wc_get_featured_product_ids();
                    $args                 = array(
                        'limit'    => 3,
                        'status'   => 'publish',
                        'orderby'  => 'meta_value_num',
                        'meta_key' => 'total_sales',
                        'include'  => $featured_product_ids,
                    );
                    $args                 = apply_filters( $hook_name, $args );
                    $products             = wc_get_products( $args );
                    break;

                case 'highrating':
                    $args     = array(
                        'limit'   => 3,
                        'status'  => 'publish',
                        'orderby' => 'rating',
                        'order'   => 'DESC',
                    );
                    $args     = apply_filters( $hook_name, $args );
                    $products = wc_get_products( $args );
                    break;

                case ( str_contains( $option, 'cat-' ) ):
                    $category = str_replace( 'cat-', '', $option );
                    $category = get_term_by( 'id', $category, 'product_cat' );
                    $args     = array(
                        'limit'    => 3,
                        'status'   => 'publish',
                        'orderby'  => 'date',
                        'order'    => 'DESC',
                        'category' => array( $category->slug ),
                    );
                    $args     = apply_filters( $hook_name, $args );
                    $products = wc_get_products( $args );
                    break;
            endswitch;
        }

        return $products;
    }

    /**
     * Initialize email setting form fields.
     *
     * @since 3.5.5
     * @access public
     */
    public function init_form_fields() {
        /* Translators: %s: list of available placeholder tags */
        $placeholder_text  = sprintf( __( 'Available placeholders: %s', 'advanced-coupons-for-woocommerce' ), '<code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>' );
        $this->form_fields = array(
            'enabled'            => array(
                'title'   => __( 'Enable/Disable', 'advanced-coupons-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email', 'advanced-coupons-for-woocommerce' ),
                'default' => 'yes',
            ),
            'subject'            => array(
                'title'       => __( 'Subject', 'advanced-coupons-for-woocommerce' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_text( 'subject' ),
                'default'     => '',
            ),
            'heading'            => array(
                'title'       => __( 'Email heading', 'advanced-coupons-for-woocommerce' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_text( 'heading' ),
                'default'     => '',
            ),
            'message'            => array(
                'title'       => __( 'Message', 'advanced-coupons-for-woocommerce' ),
                'type'        => 'textarea',
                'css'         => 'width:400px; height: 75px;',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_text( 'message' ),
                'default'     => '',
            ),
            'additional_content' => array(
                'title'       => __( 'Additional content', 'advanced-coupons-for-woocommerce' ),
                'description' => __( 'Text to appear below the main email content.', 'advanced-coupons-for-woocommerce' ) . ' ' . $placeholder_text,
                'css'         => 'width:400px; height: 75px;',
                'placeholder' => __( 'N/A', 'advanced-coupons-for-woocommerce' ),
                'type'        => 'textarea',
                'default'     => $this->get_default_text( 'additional_content' ),
                'desc_tip'    => true,
            ),
            'email_type'         => array(
                'title'       => __( 'Email type', 'advanced-coupons-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'advanced-coupons-for-woocommerce' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ),
        );
    }
}
