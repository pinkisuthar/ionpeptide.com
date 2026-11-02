<?php
namespace ACFWP\Models;

use ACFWP\Abstracts\Abstract_Main_Plugin_Class;

use ACFWP\Abstracts\Base_Model;
use ACFWP\Interfaces\Model_Interface;
use ACFWP\Interfaces\Initiable_Interface;
use ACFWP\Interfaces\Activatable_Interface;
use ACFWP\Interfaces\Deactivatable_Interface;

use ACFWP\Models\Objects\Advanced_Coupon;


use ACFWP\Helpers\Plugin_Constants;
use ACFWP\Helpers\Helper_Functions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Model that houses the logic of the Usage Limits module.
 * Public Model.
 *
 * @since 2.0
 */
class Usage_Limits extends Base_Model implements Model_Interface, Initiable_Interface, Activatable_Interface, Deactivatable_Interface {
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
        parent::__construct( $main_plugin, $constants, $helper_functions );
        $main_plugin->add_to_all_plugin_models( $this );
        $main_plugin->add_to_public_models( $this );
    }

    /*
    |--------------------------------------------------------------------------
    | Implementation.
    |--------------------------------------------------------------------------
    */

    /**
     * Get coupons that needs to reset usage limits.
     *
     * @since 2.0
     * @access private
     *
     * @return array List of coupons ids.
     */
    private function _get_coupons_with_usage_limit_reset() {

        global $wpdb;

        $timezone   = new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() );
        $today      = new \Datetime( 'now', $timezone );
        $nowstamp   = $today->getTimestamp();
        $expire_key = $this->_constants->META_PREFIX . 'usage_limit_reset_time';

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} AS p
                    INNER JOIN {$wpdb->postmeta} AS expire ON ( p.ID = expire.post_id AND expire.meta_key = %s )
                    WHERE p.post_type = 'shop_coupon'
                        AND expire.meta_value != ''
                        AND expire.meta_value <= %d
                ",
                $expire_key,
                $nowstamp
            )
        );
    }

    /**
     * Reset usage limit for coupons.
     *
     * @since 2.0
     * @access public
     *
     * @return int Number of coupons which usage limit was reset.
     */
    public function cron_reset_coupons_usage_limit() {

        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::USAGE_LIMITS_MODULE ) ) {
            return;
        }

        $coupon_ids = $this->_get_coupons_with_usage_limit_reset();

        if ( ! is_array( $coupon_ids ) || empty( $coupon_ids ) ) {
            return;
        }

        foreach ( $coupon_ids as $coupon_id ) {

            $coupon_id = absint( $coupon_id );
            $this->_reset_coupon_usage_count( $coupon_id );
            $this->set_coupon_usage_limit_reset_time( $coupon_id );
        }

        return count( $coupon_ids );
    }

    /**
     * Reset coupon usage count.
     *
     * @since 2.0
     * @access private
     *
     * @param int $coupon_id Coupon ID.
     */
    private function _reset_coupon_usage_count( $coupon_id ) {

        update_post_meta( $coupon_id, 'usage_count', 0 );
        delete_post_meta( $coupon_id, '_used_by' );
    }

    /**
     * Schedule daily CRON JOB.
     *
     * @since 2.0
     * @access private
     */
    private function _schedule_cron() {

        if ( wp_next_scheduled( $this->_constants->USAGE_LIMITS_CRON ) ) {
            return;
        }

        $timezone = new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() );
        $dateobj  = new \DateTime( '12:00am tomorrow', $timezone );

        wp_schedule_event( $dateobj->getTimestamp(), 'daily', $this->_constants->USAGE_LIMITS_CRON );
    }

    /**
     * Set coupon usage limit expiry.
     *
     * @since 2.0
     * @access public
     *
     * @param int             $coupon_id Coupon ID.
     * @param Advanced_Coupon $coupon Coupon object.
     */
    public function set_coupon_usage_limit_reset_time( $coupon_id, $coupon = null ) {

        $coupon   = $coupon instanceof Advanced_Coupon ? $coupon : new Advanced_Coupon( $coupon_id );
        $period   = $coupon->get_advanced_prop( 'reset_usage_limit_period' );
        $timezone = new \DateTimeZone( $this->_helper_functions->get_site_current_timezone() );

        switch ( $period ) {

            case 'yearly':
                $dateobj = new \DateTime( 'January 1 ' . ( current_time( 'Y' ) + 1 ), $timezone );
                $expire  = $dateobj->getTimestamp();
                break;

            case 'monthly':
                $dateobj = new \DateTime( 'first day of next month 12:00am', $timezone );
                $expire  = $dateobj->getTimestamp();
                break;

            case 'weekly':
                $week_days  = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thusrday', 'Friday', 'Saturday' );
                $week_start = get_option( 'start_of_week', 1 );
                $dateobj    = new \DateTime( 'Next ' . $week_days[ $week_start ], $timezone );
                $expire     = $dateobj->getTimestamp();
                break;

            case 'daily':
                $dateobj = new \DateTime( 'tomorrow', $timezone );
                $expire  = $dateobj->getTimestamp();
                break;

            case 'none':
            default:
                $expire = false;
                break;
        }

        update_post_meta( $coupon_id, $this->_constants->META_PREFIX . 'usage_limit_reset_time', $expire );
    }

    /**
     * Reschedule reset cron when timezone setting value is updated.
     *
     * @since 2.0
     * @access public
     *
     * @param string $value Timezone value.
     * @return string Filtered timezone value.
     */
    public function reschedule_cron_on_timezone_setting_change( $value ) {

        wp_clear_scheduled_hook( $this->_constants->USAGE_LIMITS_CRON );
        $this->_schedule_cron();

        return $value;
    }

    /**
     * Render reset usage limit setting field.
     *
     * @since 2.0
     * @access public
     *
     * @param array $value Array of options data. May vary depending on option type.
     */
    public function render_reset_usage_limit_setting_field( $value ) {
        ?>

        <tr valign="top">
            <th scope="row">
                <label><?php echo esc_html( $value['title'] ); ?></label>
            </th>
            <td>
                <div class="btn-wrap">
                    <button type="button" class="button-primary reset_usage_limit_setting" data-nonce="<?php echo esc_attr( wp_create_nonce( 'acfw_reset_coupon_usage_limit' ) ); ?>">
                        <?php esc_html_e( 'Manually reset usage limit', 'advanced-coupons-for-woocommerce' ); ?>
                    </button>
                    <span class="acfw-spinner" style="display:none;">
                        <img src="<?php echo esc_url( $this->_constants->IMAGES_ROOT_URL . 'spinner.gif' ); ?>">
                    </span>
                </div>
                <p class="acfw-notice" style="display:none; color: #46B450;"></p>
                <p class="description"><?php echo wp_kses_post( $value['desc'] ); ?></p>
            </td>
        </tr>

        <script type="text/javascript">
        jQuery(document).ready(function($){

            $('button.reset_usage_limit_setting').on( 'click', function() {

                var $button  = $(this),
                    $spinner = $button.siblings('.acfw-spinner'),
                    $row     = $button.closest('tr'),
                    $notice  = $row.find( '.acfw-notice' );

                $button.prop( 'disabled' , true );
                $spinner.show();

                $.post( ajaxurl , {
                    action : 'acfw_reset_coupon_usage_limit',
                    nonce  : $button.data('nonce')
                }, function( response ) {

                    if ( response.status == 'success' ) {

                        $notice.text( response.message );
                        $notice.show();

                        setTimeout(function() {
                            $notice.fadeOut('fast');
                        }, 5000);

                    } else 
                        alert( response.err_msg );

                }, 'json' ).always(function() {
                    $button.prop( 'disabled' , false );
                    $spinner.hide();
                });
            });
        });
        </script>

        <?php
    }

    /*
    |--------------------------------------------------------------------------
    | AJAX Functions.
    |--------------------------------------------------------------------------
    */

    /**
     * AJAX search for simple and variable products.
     *
     * @since 2.0
     * @access public
     */
    public function ajax_reset_coupon_usage_limit() {
        $nonce = sanitize_key( $_POST['nonce'] ?? '' );
        if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'Invalid AJAX call', 'advanced-coupons-for-woocommerce' ),
            );
        } elseif ( ! $nonce || ! wp_verify_nonce( $nonce, 'acfw_reset_coupon_usage_limit' ) || ! current_user_can( 'manage_woocommerce' ) ) {
            $response = array(
                'status'    => 'fail',
                'error_msg' => __( 'You are not allowed to do this', 'advanced-coupons-for-woocommerce' ),
            );
        } else {

            $count    = (int) $this->cron_reset_coupons_usage_limit();
            $response = array(
                'status'  => 'success',
                'message' => sprintf(
                    /* Translators: %s: Count of reset coupons with usage limit data. */
                    __( 'Usage limits for %s coupon(s) have been reset successfully.', 'advanced-coupons-for-woocommerce' ),
                    $count
                ),
            );
        }

        @header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) ); // phpcs:ignore
        echo wp_json_encode( $response );
        wp_die();
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
     * @implements ACFWP\Interfaces\Activatable_Interface
     */
    public function activate() {

        $this->_schedule_cron();
    }

    /**
     * Execute code base that needs to be run on plugin deactivation.
     *
     * @since 2.0
     * @implements ACFW\Interfaces\Deactivatable_Interface
     */
    public function deactivate() {

        wp_clear_scheduled_hook( $this->_constants->USAGE_LIMITS_CRON );
    }

    /**
     * Execute codes that needs to run plugin activation.
     *
     * @since 2.0
     * @access public
     * @implements ACFWP\Interfaces\Initializable_Interface
     */
    public function initialize() {
        add_action( 'wp_ajax_acfw_reset_coupon_usage_limit', array( $this, 'ajax_reset_coupon_usage_limit' ) );
    }

    /**
     * Execute Usage_Limits class.
     *
     * @since 2.0
     * @access public
     * @inherit ACFWP\Interfaces\Model_Interface
     */
    public function run() {
        if ( ! \ACFWF()->Helper_Functions->is_module( Plugin_Constants::USAGE_LIMITS_MODULE ) ) {
            return;
        }

        add_action( 'woocommerce_admin_field_acfw_reset_coupon_usage_limit', array( $this, 'render_reset_usage_limit_setting_field' ) );
        add_action( $this->_constants->USAGE_LIMITS_CRON, array( $this, 'cron_reset_coupons_usage_limit' ) );
        add_action( 'acfw_after_save_coupon', array( $this, 'set_coupon_usage_limit_reset_time' ), 99, 2 );
        add_filter( 'pre_update_option_timezone_string', array( $this, 'reschedule_cron_on_timezone_setting_change' ) );
    }
}
