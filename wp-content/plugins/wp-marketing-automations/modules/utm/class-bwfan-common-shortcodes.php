<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

#[\AllowDynamicProperties]
class BWFAN_Common_Shortcodes {
    /**
     * Singleton instance
     *
     * @var BWFAN_Common_Shortcodes
     */
    private static $instance = null;
    /**
     * Current contact
     *
     * @var WooFunnels_Contact
     */
    private $contact = null;
    /**
     * Contact UID
     *
     * @var string
     */
    private $uid = '';
    /**
     * $settings
     * @var null
     */
    protected $settings = null;

    /**
     * Constructor
     */
    private function __construct() {
        $this->register_shortcodes();
    }

    /**
     * Get singleton instance
     *
     * @return BWFAN_Common_Shortcodes
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register all common shortcodes
     */
    private function register_shortcodes() {
        // Email shortcodes
        add_shortcode( 'bwfan_subscriber_recipient', array( $this, 'bwfan_contact_email' ) );
        add_shortcode( 'wfan_contact_email', array( $this, 'bwfan_contact_email' ) );
        add_shortcode( 'fka_contact_email', array( $this, 'bwfan_contact_email' ) );
        add_shortcode( 'fka_subscriber_recipient', array( $this, 'bwfan_contact_email' ) );

        // Name shortcodes
        add_shortcode( 'bwfan_subscriber_name', array( $this, 'bwfan_contact_name' ) );
        add_shortcode( 'wfan_contact_name', array( $this, 'bwfan_contact_name' ) );
        add_shortcode( 'fka_contact_name', array( $this, 'bwfan_contact_name' ) );
        add_shortcode( 'fka_subscriber_name', array( $this, 'bwfan_contact_name' ) );


        // First name shortcodes
        add_shortcode( 'bwfan_subscriber_firstname', array( $this, 'bwfan_contact_firstname' ) );
        add_shortcode( 'wfan_contact_firstname', array( $this, 'bwfan_contact_firstname' ) );
        add_shortcode( 'fka_contact_first_name', array( $this, 'bwfan_contact_firstname' ) );
        add_shortcode( 'fka_subscriber_first_name', array( $this, 'bwfan_contact_firstname' ) );

        // Last name shortcodes
        add_shortcode( 'bwfan_subscriber_lastname', array( $this, 'bwfan_contact_lastname' ) );
        add_shortcode( 'wfan_contact_lastname', array( $this, 'bwfan_contact_lastname' ) );
        add_shortcode( 'fka_contact_last_name', array( $this, 'bwfan_contact_lastname' ) );
        add_shortcode( 'fka_subscriber_last_name', array( $this, 'bwfan_contact_lastname' ) );
    }


    /**
     * Get the current contact
     *
     * @return WooFunnels_Contact|null
     */
    public function get_contact() {
        if ( empty( $this->contact ) ) {
            $this->set_data();
        }

        return $this->contact;
    }

    /**
     * Get contact details for shortcodes
     *
     * @return array Contact details
     */
    public function get_contact_details() {
        $contact = $this->get_contact();

        if ( empty( $contact ) ) {
            $user = wp_get_current_user();
            if ( $user instanceof WP_User && $user->exists() ) {
                return array(
                    'email'     => $user->user_email,
                    'name'      => ucwords( $user->first_name . ' ' . $user->last_name ),
                    'firstname' => ucwords( $user->first_name ),
                    'lastname'  => ucwords( $user->last_name ),
                );
            }

            return array();
        }

        return array(
            'email'     => $contact->get_email(),
            'phone'     => $contact->get_contact_no(),
            'name'      => ucwords( $contact->get_f_name() . ' ' . $contact->get_l_name() ),
            'firstname' => ucwords( $contact->get_f_name() ),
            'lastname'  => ucwords( $contact->get_l_name() ),
        );
    }

    /**
     * Common method to load contact data from URL parameters or logged-in user
     *
     * @return WooFunnels_Contact|null Contact object if found
     */
    public function set_data() {
        if ( ! empty( $this->contact ) ) {
            return $this->contact;
        }

        $uid = filter_input( INPUT_POST, 'uid' );
        $uid = empty( $uid ) ? filter_input( INPUT_GET, 'uid' ) : $uid;
        $uid = sanitize_key( $uid );

        /** If none available then check logged-in user */
        if ( empty( $uid ) && ! is_user_logged_in() ) {
            return null;
        }

        $contact = new WooFunnels_Contact( '', '', '', '', $uid );
        if ( 0 === $contact->get_id() ) {
            $contact = new WooFunnels_Contact( get_current_user_id() );
        }

        if ( $contact->get_id() > 0 ) {
            $this->contact = $contact;
            $this->uid     = $contact->get_uid();

            return $this->contact;
        }

        return null;
    }

    /**
     * Email shortcode callback
     *
     * @param array $attrs Shortcode attributes
     *
     * @return string Shortcode output
     */
    public function bwfan_contact_email( $attrs ) {
        $attr = shortcode_atts( array(
            'fallback' => 'john@example.com',
        ), $attrs );

        $this->set_data();
        $contact_details = $this->get_contact_details();

        // Check for mode parameter (specific to unsubscribe functionality)
        $mode = 1;
        $mode_param = filter_input( INPUT_GET, 'mode', FILTER_SANITIZE_NUMBER_INT );
        if ( ! empty( $mode_param ) && 2 === absint( $mode_param ) ) {
            $mode = 2;
        }

        if ( ! empty( $contact_details ) ) {
            if ( 1 === absint( $mode ) && isset( $contact_details['email'] ) ) {
                $attr['fallback'] = sanitize_text_field( $contact_details['email'] );
            } elseif ( isset( $contact_details['phone'] ) ) {
                $attr['fallback'] = sanitize_text_field( $contact_details['phone'] );
            }
        }

        return '<span id="bwfan_contact_email">' . esc_html( $attr['fallback'] ) . '</span>';
    }

    /**
     * Name shortcode callback
     *
     * @param array $attrs Shortcode attributes
     *
     * @return string Shortcode output
     */
    public function bwfan_contact_name( $attrs ) {
        $attr = shortcode_atts( array(
            'fallback' => 'John',
        ), $attrs );

        $this->set_data();
        $contact_details = $this->get_contact_details();

        if ( ! empty( $contact_details ) && isset( $contact_details['name'] ) ) {
            $attr['fallback'] = sanitize_text_field( $contact_details['name'] );
        }

        return '<span id="bwfan_contact_name">' . esc_html( $attr['fallback'] ) . '</span>';
    }

    /**
     * First name shortcode callback
     *
     * @param array $attrs Shortcode attributes
     *
     * @return string Shortcode output
     */
    public function bwfan_contact_firstname( $attrs ) {
        $attr = shortcode_atts( array(
            'fallback' => 'John',
        ), $attrs );

        $this->set_data();
        $contact_details = $this->get_contact_details();

        if ( ! empty( $contact_details ) && isset( $contact_details['firstname'] ) ) {
            $attr['fallback'] = sanitize_text_field( $contact_details['firstname'] );
        }

        return '<span id="bwfan_contact_firstname">' . esc_html( $attr['fallback'] ) . '</span>';
    }

    /**
     * Last name shortcode callback
     *
     * @param array $attrs Shortcode attributes
     *
     * @return string Shortcode output
     */
    public function bwfan_contact_lastname( $attrs ) {
        $attr = shortcode_atts( array(
            'fallback' => 'Doe',
        ), $attrs );

        $this->set_data();
        $contact_details = $this->get_contact_details();

        if ( ! empty( $contact_details ) && isset( $contact_details['lastname'] ) ) {
            $attr['fallback'] = sanitize_text_field( $contact_details['lastname'] );
        }

        return '<span id="bwfan_contact_lastname">' . esc_html( $attr['fallback'] ) . '</span>';
    }

    /**
     * get the global_settings
     * @return mixed|null
     */
    protected function get_global_settings() {
        if ( is_null( $this->settings ) ) {
            $this->settings = BWFAN_Common::get_global_settings();
        }

        return $this->settings;
    }

    /**
     * print the header
     * for both unsubscribe and mange profile
     *
     * @return void
     */
    public function print_header() {
        $global_settings = $this->get_global_settings();
        $header_logo     = ( isset( $global_settings['bwfan_setting_business_logo'] ) ) ? $global_settings['bwfan_setting_business_logo'] : '';
        if ( $header_logo ) {
            ?>
            <div class="bwfan_manage_profile_header">
                <a href="<?php echo esc_url( home_url() ); ?>">
                    <img src="<?php echo esc_url($header_logo); ?>" alt="logo" width="150" height="36">
                </a>
            </div>
            <?php
        }
    }
}

BWFAN_Common_Shortcodes::get_instance();
