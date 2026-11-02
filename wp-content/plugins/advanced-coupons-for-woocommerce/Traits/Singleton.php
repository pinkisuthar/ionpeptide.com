<?php
namespace ACFWP\Traits;

// Exit if accessed directly.
use ACFWP\Abstracts\Abstract_Main_Plugin_Class;
use ACFWP\Helpers\Helper_Functions;
use ACFWP\Helpers\Plugin_Constants;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait that houses singleton pattern function.
 *
 * @since 3.5.7
 */
trait Singleton {
    /*
    |--------------------------------------------------------------------------
    | Class Properties
    |--------------------------------------------------------------------------
     */

    /**
     * Property that holds the single main instance of URL_Coupon.
     *
     * @since 3.5.7
     * @access private
     * @var mixed $_instance
     */
    protected static $_instance;

    /*
    |--------------------------------------------------------------------------
    | Class Methods
    |--------------------------------------------------------------------------
     */

    /**
     * Ensure that only one instance of this class is loaded or can be loaded ( Singleton Pattern ).
     *
     * @since 3.5.7
     * @access public
     *
     * @param mixed ...$args Arguments to pass when instantiating the class.
     * @return mixed
     */
    public static function get_instance( ...$args ) {
        if ( ! static::$_instance instanceof static ) {
            static::$_instance = new static( ...$args ); // Instantiate the class with the passed arguments.
        }

        return static::$_instance;
    }
}
