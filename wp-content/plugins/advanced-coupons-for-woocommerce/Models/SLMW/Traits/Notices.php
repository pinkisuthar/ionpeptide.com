<?php
namespace ACFWP\Models\SLMW\Traits;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait that holds the logic for display license related notices.
 *
 * @since 3.6.0
 */
trait Notices {

    /**
     * Property that holds the active license related notices.
     *
     * @since 3.6.0
     * @access private
     * @var array
     */
    private $_active_notices = array();

    /**
     * Property that holds the is acfw screen flag.
     *
     * @since 3.6.0
     * @access private
     * @var bool
     */
    protected $_is_acfw_screen = false;

    /**
     * Get active license related notices.
     *
     * @since 3.6.0
     * @access public
     *
     * @return array
     */
    public function get_active_notices() {
        return $this->_active_notices;
    }

    /**
     * Maybe display license related notices.
     *
     * @since 3.6.0
     * @access public
     */
    public function maybe_display_license_notices() {

        // Skip if the current user doesn't have manage_woocommerce capability.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $license_data = $this->get_license_data();
        $last_check   = $this->get_last_license_check( time() );

        // Show the no license notice if the current time is within 7 days of the last check time and there is no license activated yet.
        if ( ( ! is_array( $license_data ) || empty( $license_data ) ) && time() > $last_check->getTimeStamp() ) {

            $this->_active_notices[] = 'no_license';

            // Show the no license reminder if the current time is beyond 6 hours of the last check time.
            if ( HOUR_IN_SECONDS * 6 < time() - $last_check->getTimeStamp() ) {
                $this->_active_notices[] = 'no_license_reminder';
            }

            // Show interstitial if the current time is beyond 2 days of the last check time.
            if ( $this->_helper_functions->is_days_interval_valid( $last_check, -2, 'beyond' ) ) {
                $this->_active_notices[] = 'no_license_interstitial';
            }

            return;
        }

        $subscription_status = $license_data['subscription_status'] ?? '';
        $license_status      = $license_data['license_status'] ?? '';
        $license_expire      = $license_data['expiration_timestamp'] ?? ''; // NOTE: the value store is not a timestamp but a string with mysql date format.

        // when the license is disabled and the subscription is on-hold, show the disabled notice for failed renewal.
        // for cancelled subscriptions, we don't show the disabled notice and will just let it be handled by the post expire notice.
        if ( 'disabled' === $license_status && 'on-hold' === $subscription_status && $this->_helper_functions->is_days_interval_valid( $license_expire, -30, 'within' ) ) {
            $this->_active_notices[] = 'license_disabled_failed_renew';
            return;
        }

        // when the license is disabled and the subscription is pending-cancel or active, show the disabled notice for manual disabling.
        if ( 'disabled' === $license_status && in_array( $subscription_status, array( 'pending-cancel', 'active' ), true ) ) {
            $this->_active_notices[] = 'license_disabled_manually';

            // show interstitial if days passed is beyond 2 days.
            if ( $this->_helper_functions->is_days_interval_valid( $last_check, -2, 'beyond' ) ) {
                $this->_active_notices[] = 'license_disabled_interstitial';
            }
            return;
        }

        // maybe show the license pre-expire notice.
        if ( 'pending-cancel' === $subscription_status && $this->_helper_functions->is_days_interval_valid( $license_expire, 14, 'within' ) ) {
            $this->_active_notices[] = 'license_pre_expire';
            return;
        }

        // Set post expire notice as active when the expiration timestamp is within the 2 days extension date.
        if ( $license_expire && time() > strtotime( $license_expire ) ) {
            $this->_active_notices[] = 'license_expired';

            // Set the post expire interstitial as active when the expiration timestamp is beyond the 14 days extension date.
            if ( $this->_helper_functions->is_days_interval_valid( $license_expire, -2, 'beyond' ) ) {
                $this->_active_notices[] = 'license_expired_interstitial';
            }
        }
    }

    /**
     * Display license related notices.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_license_notices() {
        $this->display_no_license_notice();
        $this->display_license_disabled_failed_renew_notice();
        $this->display_license_disabled_manually_notice();
        $this->display_license_pre_expire_notice();
        $this->display_post_expiry_notice();
    }

    /*
    |--------------------------------------------------------------------------
    | No license notice
    |--------------------------------------------------------------------------
    */

    /**
     * Display the no license notice.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_no_license_notice() {
        // Skip if the notice is not active.
        if ( ! in_array( 'no_license', $this->_active_notices, true ) || did_action( 'acfw_after_no_license_notice' ) ) {
            return;
        }

        $last_check  = $this->get_last_license_check( time() );
        $days_passed = abs( $this->_helper_functions->get_days_interval( $last_check ) );

        // Skip if the notice is dismissed for non-ACFW screens. We will still show the notice on ACFW screens.
        if ( ( 'yes' === get_site_transient( $this->_constants->NO_LICENSE_NOTICE_DISMISSED ) && 7 > $days_passed && ! $this->_is_acfw_screen ) ) {
            return;
        }

        // Don't show the notice on AC screens when the interstitial is already active.
        if ( $this->_is_acfw_screen && in_array( 'no_license_interstitial', $this->_active_notices, true ) ) {
            return;
        }

        $is_dismissible    = ! $this->_is_acfw_screen && 7 > $days_passed; // Notice should only be dismissible on non ACFW screens.
        $login_account_url = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/login/', 'nolicensenotice' );
        $purchase_url      = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/pricing/', 'nolicensenotice' );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-no-license-notice.php';
    }

    /**
     * Display the no license interstitial.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_no_license_interstitial() {
        // Skip if the interstitial is not active.
        if ( ! in_array( 'no_license_interstitial', $this->_active_notices, true ) || ! $this->_is_acfw_screen ) {
            return;
        }

        $this->_display_interstitial(
            array(
                'classname'       => 'acfw-no-license-interstitial',
                'title'           => __( 'Your Advanced Coupons license is missing!', 'advanced-coupons-for-woocommerce' ),
                'action_url'      => $this->_constants->get_license_page_url(),
                'action_text'     => __( 'Enter Licenses Now', 'advanced-coupons-for-woocommerce' ),
                'sub_action_url'  => $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/pricing/', 'interstitialpurchaselink' ),
                'sub_action_text' => __( 'Don’t have a license yet? Purchase here.', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }

    /**
     * Get the no license reminder content.
     *
     * @since 3.6.0
     * @access private
     *
     * @return string
     */
    private function _get_no_license_reminder_content() {
        $pricing_link = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/pricing/', 'licensereminderpopup' );

        ob_start();
        include $this->_constants->VIEWS_ROOT_PATH . 'slmw/view-no-license-reminder-pointer.php';
        return ob_get_clean();
    }

    /*
    |--------------------------------------------------------------------------
    | Disabled license notice
    |--------------------------------------------------------------------------
    */

    /**
     * Display the disabled license failed renewal notice.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_license_disabled_failed_renew_notice() {
        // Skip if the notice is not active or if another premium plugin is already showing the notice.
        if ( ! in_array( 'license_disabled_failed_renew', $this->_active_notices, true ) || did_action( 'acfw_after_license_disabled_failed_renew_notice' ) ) {
            return;
        }

        $license_data   = $this->get_license_data();
        $expiry_date    = new \WC_DateTime( $license_data['expiration_timestamp'], new \DateTimeZone( 'UTC' ) );
        $days_passed    = abs( $this->_helper_functions->get_days_interval( $expiry_date ) );
        $is_dismissible = 7 > $days_passed && ! $this->_is_acfw_screen;

        // Skip if the notice is dismissed and that the notice is dismissable.
        if ( ( 'yes' === get_site_transient( $this->_constants->LICENSE_DISABLED_NOTICE_DISMISSED ) && $is_dismissible ) ) {
            return;
        }

        // Extend the expiry date for 30 days.
        $expiry_date->add( new \DateInterval( 'P30D' ) );

        $extended_date     = $expiry_date->date_i18n( get_option( 'date_format', 'F j, Y' ) );
        $renew_license_url = $this->_add_drm_query_args( $license_data['management_url'], 'disabledlicensenotice' );
        $learn_more_url    = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/knowledgebase/what-happens-if-my-license-expires/', 'disabledlicensenotice' );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-license-disabled-failed-renew-notice.php';
    }

    /**
     * Display the disabled license manually notice.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_license_disabled_manually_notice() {
        // Skip if the notice is not active or if another premium plugin is already showing the notice.
        if ( ! in_array( 'license_disabled_manually', $this->_active_notices, true ) || did_action( 'acfw_after_license_disabled_manually_notice' ) ) {
            return;
        }

        // Don't show the notice on AC screens when the interstitial is already active.
        if ( $this->_is_acfw_screen && in_array( 'license_disabled_interstitial', $this->_active_notices, true ) ) {
            return;
        }

        $contact_support_url = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/support/', 'disabledlicensenotice' );
        $learn_more_url      = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/knowledgebase/what-happens-if-my-license-expires/', 'disabledlicensenotice' );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-license-disabled-manually-notice.php';
    }

    /**
     * Display the disabled license interstitial.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_disabled_license_interstitial() {
        // Skip if the interstitial is not active.
        if ( ! in_array( 'license_disabled_interstitial', $this->_active_notices, true ) || ! $this->_is_acfw_screen ) {
            return;
        }

        $this->_display_interstitial(
            array(
                'classname'       => 'acfw-disabled-license-interstitial',
                'title'           => __( 'Your Advanced Coupons license is disabled!', 'advanced-coupons-for-woocommerce' ),
                'action_url'      => $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/pricing/', 'disabledinterstitial' ),
                'action_text'     => __( 'Repurchase New License', 'advanced-coupons-for-woocommerce' ),
                'sub_action_url'  => $this->_constants->get_license_page_url(),
                'sub_action_text' => __( 'Enter a new license', 'advanced-coupons-for-woocommerce' ),
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | License pre-expire notice
    |--------------------------------------------------------------------------
    */

    /**
     * Display the pre expiry notice.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_license_pre_expire_notice() {
        // Skip if the notice is not active or if another premium plugin is already showing the notice.
        if ( ! in_array( 'license_pre_expire', $this->_active_notices, true ) || did_action( 'acfw_after_license_pre_expired_notice' ) ) {
            return;
        }

        // Skip if the notice is dismissed for non-ACFW screens. We will still show the notice on ACFW screens.
        if ( ( 'yes' === get_site_transient( $this->_constants->LICENSE_PRE_EXPIRE_NOTICE_DISMISSED ) && ! $this->_is_acfw_screen ) ) {
            return;
        }

        $license_data = $this->get_license_data();

        $expire_datetime = new \WC_DateTime( $license_data['expiration_timestamp'], new \DateTimeZone( 'UTC' ) );
        $expire_date     = $expire_datetime->date_i18n( get_option( 'date_format', 'F j, Y' ) );

        // calculate the days left for expiry. We need to increment the days by 1 so the current day is included in the count.
        $expire_datetime->setTimezone( new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );
        $expire_datetime->modify( '+1 day' );
        $days_left = $this->_helper_functions->get_days_interval( $expire_datetime );

        $is_dismissible       = ! $this->_is_acfw_screen; // Notice should only be dismissible on non ACFW screens.
        $login_reactivate_url = $this->_add_drm_query_args( $license_data['upgrade_url'], 'preexpirednotice' );
        $learn_more_url       = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/knowledgebase/what-happens-if-my-license-expires/', 'preexpirednotice' );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-license-pre-expired-notice.php';
    }

    /*
    |--------------------------------------------------------------------------
    | License post expire notice
    |--------------------------------------------------------------------------
    */

    /**
     * Display the post expiry notice.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_post_expiry_notice() {
        // Skip if the notice is not active.
        if ( ! in_array( 'license_expired', $this->_active_notices, true ) || did_action( 'acfw_after_license_post_expired_notice' ) ) {
            return;
        }

        // Skip if the notice is dismissed for non-ACFW screens. We will still show the notice on ACFW screens.
        if ( ( 'yes' === get_site_transient( $this->_constants->LICENSE_EXPIRE_NOTICE_DISMISSED ) && ! $this->_is_acfw_screen ) ) {
            return;
        }

        // Don't show the notice on AC screens when the interstitial is already active.
        if ( $this->_is_acfw_screen && in_array( 'license_expired_interstitial', $this->_active_notices, true ) ) {
            return;
        }

        $license_data         = $this->get_license_data();
        $expiry_extended_date = new \WC_DateTime( $license_data['expiration_timestamp'], new \DateTimeZone( 'UTC' ) );
        $is_dismissible       = ! $this->_is_acfw_screen; // Notice should only be dismissible on non ACFW screens.

        $expiry_extended_date->add( new \DateInterval( 'P2D' ) );
        $expiry_extended_date->setTimezone( new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() ) );

        $renew_license_url = $this->_add_drm_query_args( $license_data['management_url'], 'expirednotice' );
        $learn_more_url    = $this->_add_drm_query_args( 'https://advancedcouponsplugin.com/knowledgebase/what-happens-if-my-license-expires/', 'expirednotice' );

        include $this->_constants->VIEWS_ROOT_PATH . 'notices/view-license-expired-notice.php';
    }

    /**
     * Display the post expiry interstitial.
     *
     * @since 3.6.0
     * @access public
     */
    public function display_post_expiry_interstitial() {
        // Skip if the interstitial is not active.
        if ( ! in_array( 'license_expired_interstitial', $this->_active_notices, true ) || ! $this->_is_acfw_screen ) {
            return;
        }

        $this->_display_interstitial(
            array(
                'classname'            => 'acfw-license-expired-interstitial',
                'title'                => __( 'Your Advanced Coupons license has expired!', 'advanced-coupons-for-woocommerce' ),
                'action_url'           => $this->_add_drm_query_args( $acfwp_data['management_url'] ?? 'https://advancedcouponsplugin.com/my-account/downloads/', 'expiredinterstitial' ),
                'action_text'          => __( 'Renew License', 'advanced-coupons-for-woocommerce' ),
                'sub_action_url'       => $this->_constants->get_license_page_url(),
                'sub_action_text'      => __( 'Enter a new license', 'advanced-coupons-for-woocommerce' ),
                'show_refresh_license' => true,
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions
    |--------------------------------------------------------------------------
    */

    /**
     * AJAX dismiss license related notice.
     *
     * @since 3.6.0
     * @access public
     */
    public function ajax_dismiss_license_notice() {

        $post_data = $this->_helper_functions->validate_ajax_request(
            array(
                'required_parameters' => array( 'notice' ),
                'nonce_value_key'     => 'nonce',
                'nonce_action'        => 'acfw_dismiss_license_notice',
                'user_capability'     => 'manage_woocommerce',
            )
        );

        // Skip if the AJAX request is not valid.
        if ( is_wp_error( $post_data ) ) {
            wp_send_json(
                array(
                    'status'  => 'fail',
                    'message' => $post_data->get_error_message(),
                )
            );
        }

        $notice_id = sanitize_text_field( wp_unslash( $post_data['notice'] ) );

        switch ( $notice_id ) {
            case 'no_license':
                set_site_transient( $this->_constants->NO_LICENSE_NOTICE_DISMISSED, 'yes', DAY_IN_SECONDS );
                break;
            case 'license_expired':
                set_site_transient( $this->_constants->LICENSE_EXPIRE_NOTICE_DISMISSED, 'yes', DAY_IN_SECONDS );
                break;
            case 'license_pre_expired':
                set_site_transient( $this->_constants->LICENSE_PRE_EXPIRE_NOTICE_DISMISSED, 'yes', DAY_IN_SECONDS );
                break;
            case 'no_license_reminder':
                set_site_transient( $this->_constants->NO_LICENSE_REMINDER_DISMISSED, 'yes', DAY_IN_SECONDS );
                break;
        }

        $response = array( 'status' => 'success' );

        wp_send_json( $response );
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Functions
    |--------------------------------------------------------------------------
    */

    /**
     * Display the license interstitial.
     *
     * @since 3.6.0
     * @access private
     *
     * @param array $args Array of arguments for the license interstitial.
     */
    private function _display_interstitial( $args ) {

        $license_statuses = $this->get_all_license_status_for_display();
        $args             = wp_parse_args(
            $args,
            array(
                'classname'            => '',
                'title'                => '',
                'description'          => __( 'Without an active license, your coupons in the front end will still continue to work, but premium functionality has been disabled until a valid license is entered.', 'advanced-coupons-for-woocommerce' ),
                'action_url'           => '',
                'action_text'          => '',
                'sub_action_url'       => '',
                'sub_action_text'      => '',
                'show_refresh_license' => false,
            )
        );

        extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract

        include $this->_constants->VIEWS_ROOT_PATH . 'slmw/view-license-interstitial.php';
    }
}
