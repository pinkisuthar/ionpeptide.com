<?php

namespace ACFWF\Models\Objects\Emails;

use ACFWF\Models\Objects\Store_Credit_Entry;

/**
 * Model that houses the data model of a store credit adjustment email.
 *
 * @since 4.7.1
 */
class Store_Credit_Adjustment extends \WC_Email {

    /**
     * The store credit entry object used in the email.
     *
     * @since 4.7.1
     * @var Store_Credit_Entry
     */
    protected $store_credit_entry;

    /**
     * The customer object associated with the store credit adjustment email.
     *
     * @since 4.7.1
     * @var \WC_Customer
     */
    protected $customer;

    /**
     * The new balance after the adjustment.
     *
     * @since 4.7.1
     * @var float
     */
    protected $new_balance = 0.0;

    /**
     * Class constructor.
     *
     * @since 4.7.1
     * @access public
     */
    public function __construct() {
        $this->id             = 'acfw_store_credit_adjustment_email';
        $this->customer_email = true;
        $this->title          = __( 'Advanced Coupons - store credit adjustment', 'advanced-coupons-for-woocommerce-free' );
        $this->description    = __( 'Email of the store credit adjustment that is sent to the customer.', 'advanced-coupons-for-woocommerce-free' );
        $this->template_html  = 'emails/store-credit-adjustment.php';
        $this->template_plain = 'emails/plain/store-credit-adjustment.php';
        $this->placeholders   = array(
            '{store_credit_entry_id}' => '',
            '{customer_name}'         => '',
            '{customer_email}'        => '',
            '{adjustment_amount}'     => '',
            '{adjustment_type}'       => '',
            '{new_balance}'           => '',
        );

        add_action( 'acfwf_send_store_credit_adjustment_email', array( $this, 'trigger' ), 10, 2 );

        parent::__construct();
    }

    /**
     * Get email's default subject.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_default_subject() {
        /* Translators: %s: Site title */
        return sprintf( __( '%s: Your store credit balance has been updated!', 'advanced-coupons-for-woocommerce-free' ), '[{site_title}]' );
    }

    /**
     * Get email's default heading.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_default_heading() {
        return __( 'Your store credit balance has been updated!', 'advanced-coupons-for-woocommerce-free' );
    }

    /**
     * Get default message content.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_default_message() {
        /* Translators: %s: Site title */
        return sprintf( __( 'Your store credit balance at %s has been updated.', 'advanced-coupons-for-woocommerce-free' ), '{site_title}' );
    }

    /**
     * Get default button text.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_default_button_text() {
        return __( 'Shop Now', 'advanced-coupons-for-woocommerce-free' );
    }

    /**
     * Default content to show below main email content.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_default_additional_content() {
        return '';
    }

    /**
     * Set store credit entry instance.
     *
     * @since 4.7.1
     * @access public
     *
     * @param Store_Credit_Entry $store_credit_entry Store credit entry object.
     */
    public function set_store_credit_entry( Store_Credit_Entry $store_credit_entry ) {
        $this->store_credit_entry = $store_credit_entry;

        $amount = $store_credit_entry->get_prop( 'amount', 'edit' );
        $type   = $store_credit_entry->get_prop( 'type', 'edit' );

        $this->placeholders['{store_credit_entry_id}'] = $store_credit_entry->get_id();
        $this->placeholders['{adjustment_amount}']     = \ACFWF()->Helper_Functions->api_wc_price( $amount );
        $this->placeholders['{adjustment_type}']       = 'increase' === $type
            ? __( 'increased', 'advanced-coupons-for-woocommerce-free' )
            : __( 'decreased', 'advanced-coupons-for-woocommerce-free' );
    }

    /**
     * Get the store credit entry object. If not set, creates a dummy entry for preview.
     *
     * @since 4.7.1
     * @access public
     *
     * @return Store_Credit_Entry The store credit entry object.
     */
    public function get_store_credit_entry() {
        if ( ! $this->store_credit_entry ) {
            $this->store_credit_entry = new Store_Credit_Entry();
        }

        return $this->store_credit_entry;
    }

    /**
     * Set customer instance.
     *
     * @since 4.7.1
     * @access public
     *
     * @param \WC_Customer $customer Customer object.
     */
    public function set_customer( \WC_Customer $customer ) {
        $this->customer = $customer;

        $this->placeholders['{customer_name}']  = $customer->get_display_name();
        $this->placeholders['{customer_email}'] = $customer->get_email();
    }

    /**
     * Set the new balance after adjustment.
     *
     * @since 4.7.1
     * @access public
     *
     * @param float $balance New balance amount.
     */
    public function set_new_balance( $balance ) {
        $this->new_balance = floatval( $balance );

        $this->placeholders['{new_balance}'] = \ACFWF()->Helper_Functions->api_wc_price( $this->new_balance );
    }

    /**
     * Get the new balance.
     *
     * @since 4.7.1
     * @access public
     *
     * @return float The new balance.
     */
    public function get_new_balance() {
        return $this->new_balance;
    }

    /**
     * Get the formatted new balance.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string The formatted new balance.
     */
    public function get_formatted_new_balance() {
        return \ACFWF()->Helper_Functions->api_wc_price( $this->new_balance );
    }

    /**
     * Trigger sending of this email.
     *
     * @since 4.7.1
     * @access public
     *
     * @param Store_Credit_Entry $store_credit_entry Store credit entry object.
     * @param \WC_Customer       $customer           Customer object.
     */
    public function trigger( $store_credit_entry, $customer ) {
        do_action( 'acfw_before_send_store_credit_adjustment_email', $store_credit_entry, $customer );

        $this->setup_locale();
        $this->set_store_credit_entry( $store_credit_entry );
        $this->set_customer( $customer );

        // Calculate and set the new balance.
        $new_balance = \ACFWF()->Store_Credits_Calculate->get_customer_balance( $customer->get_id(), true );
        $this->set_new_balance( $new_balance );

        $this->recipient = $customer->get_email();

        if ( $this->is_enabled() && $this->get_recipient() ) {

            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );

        }

        $this->restore_locale();

        do_action( 'acfw_after_send_store_credit_adjustment_email', $store_credit_entry, $customer );
    }

    /**
     * Override setup locale function to remove customer email check.
     *
     * @since 4.7.1
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
     * @since 4.7.1
     * @access public
     */
    public function restore_locale() {
        if ( apply_filters( 'woocommerce_email_restore_locale', true ) ) {
            wc_restore_locale();
        }
    }

    /**
     * Get email message.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_message() {
        return apply_filters( 'acfw_email_message_' . $this->id, $this->format_string( $this->get_option( 'message', $this->get_default_message() ) ), $this->object, $this );
    }

    /**
     * Get button text.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string
     */
    public function get_button_text() {
        return apply_filters( 'acfw_email_button_text_' . $this->id, $this->format_string( $this->get_option( 'button_text', $this->get_default_button_text() ) ), $this->object, $this );
    }

    /**
     * Get email content html.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string Email html content.
     */
    public function get_content_html() {
        ob_start();

        \ACFWF()->Helper_Functions->load_template(
            $this->template_html,
            array(
                'store_credit_entry' => $this->get_store_credit_entry(),
                'customer'           => $this->get_customer(),
                'new_balance'        => $this->get_formatted_new_balance(),
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'email'              => $this,
            )
        );

        return ob_get_clean();
    }

    /**
     * Get email plain content.
     *
     * @since 4.7.1
     * @access public
     *
     * @return string Email plain content.
     */
    public function get_content_plain() {
        ob_start();

        \ACFWF()->Helper_Functions->load_template(
            $this->template_plain,
            array(
                'store_credit_entry' => $this->get_store_credit_entry(),
                'customer'           => $this->get_customer(),
                'new_balance'        => $this->get_formatted_new_balance(),
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'email'              => $this,
            )
        );

        return ob_get_clean();
    }

    /**
     * Get the customer object. If the customer is not set, this will create and set a dummy customer.
     *
     * @since 4.7.1
     * @access public
     *
     * @return \WC_Customer The WooCommerce customer object.
     */
    public function get_customer() {
        if ( ! $this->customer ) {
            $address = array(
                'first_name' => 'John',
                'last_name'  => 'Doe',
                'company'    => 'Company',
                'email'      => 'john@company.com',
                'phone'      => '555-555-5555',
                'address_1'  => '123 Fake Street',
            );

            $customer = new \WC_Customer();
            $customer->set_props( $address );

            $this->set_customer( $customer );
        }

        return $this->customer;
    }

    /**
     * Initialize email setting form fields.
     *
     * @since 4.7.1
     * @access public
     */
    public function init_form_fields() {
        $placeholder_list = implode( ', ', array_keys( $this->placeholders ) );
        $placeholder_text = sprintf(
            /* Translators: %s: list of available placeholder tags */
            __( 'Available placeholders: %s', 'advanced-coupons-for-woocommerce-free' ),
            '<code>' . esc_html( $placeholder_list ) . '</code>'
        );
        $this->form_fields = array(
            'enabled'            => array(
                'title'   => __( 'Enable/Disable', 'advanced-coupons-for-woocommerce-free' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email', 'advanced-coupons-for-woocommerce-free' ),
                'default' => 'yes',
            ),
            'subject'            => array(
                'title'       => __( 'Subject', 'advanced-coupons-for-woocommerce-free' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
            ),
            'heading'            => array(
                'title'       => __( 'Email heading', 'advanced-coupons-for-woocommerce-free' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_heading(),
                'default'     => '',
            ),
            'message'            => array(
                'title'       => __( 'Message', 'advanced-coupons-for-woocommerce-free' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_message(),
                'default'     => '',
            ),
            'button_text'        => array(
                'title'       => __( 'Button text', 'advanced-coupons-for-woocommerce-free' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_button_text(),
                'default'     => '',
            ),
            'additional_content' => array(
                'title'       => __( 'Additional content', 'advanced-coupons-for-woocommerce-free' ),
                'description' => __( 'Text to appear below the main email content.', 'advanced-coupons-for-woocommerce-free' ) . ' ' . $placeholder_text,
                'css'         => 'width:400px; height: 75px;',
                'placeholder' => __( 'N/A', 'advanced-coupons-for-woocommerce-free' ),
                'type'        => 'textarea',
                'default'     => $this->get_default_additional_content(),
                'desc_tip'    => true,
            ),
            'email_type'         => array(
                'title'       => __( 'Email type', 'advanced-coupons-for-woocommerce-free' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'advanced-coupons-for-woocommerce-free' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ),
        );
    }
}
