<?php
/*
Plugin Name: Product Catalog Feed by PixelYourSite
Description: WooCommerce auto-updated XML feeds for Facebook Product Catalogs (Dynamic Product Ads, Facebook Shops, Instagram), Google Merchant, and Pinterest Catalogs.
Plugin URI: https://www.pixelyoursite.com/product-catalog-facebook
Author: PixelYourSite
Author URI: https://www.pixelyoursite.com
Version: 2.2.0
WC requires at least: 3.0.0
WC tested up to: 8.2
*/
/* Following are used for updating plugin */

if( !is_admin() && !wp_doing_cron () ){
	return;
}
//Plugin Version
define( 'WPWOOF_VERSION', '2.2.0');
//NOTIFICATION VERSION
define( 'WPWOOF_VERSION_NOTICE', '0.0.0');

//Plugin Update URL
define( 'WPWOOF_SL_STORE_URL', 'https://www.pixelyoursite.com' );
//Plugin Name
define( 'WPWOOF_SL_ITEM_NAME', 'Product Catalog Feed' );
define( 'WPWOOF_SL_ITEM_SHNAME', 'Product Catalog Feed' );

//Plugin Base
define( 'WPWOOF_BASE', plugin_basename( __FILE__ ) );
//Plugin PAtH
define( 'WPWOOF_PATH', plugin_dir_path( __FILE__ ) );
//Plugin URL
define( 'WPWOOF_URL', plugin_dir_url( __FILE__ ) );
//Plugin assets URL
define( 'WPWOOF_ASSETS_URL', WPWOOF_URL . 'assets/' );
//Plugin
define( 'WPWOOF_PLUGIN', 'wp-woocommerce-feed');

//Plugin
define( 'WPWOOF_WOO',  'woocommerce/woocommerce.php');
define( 'WPWOOF_YSEO', 'wordpress-seo/wp-seo.php');
define( 'WPWOOF_SMART_OGR', 'smart-opengraph/catalog-plugin.php');
//Brands plugins
// woocommerce brands */
define( 'WPWOOF_BRAND_YWBA',    'yith-woocommerce-brands-add-on/init.php');
define( 'WPWOOF_BRAND_PEWB',    'perfect-woocommerce-brands/main.php');
define( 'WPWOOF_BRAND_PRWB',    'premmerce-woocommerce-brands/premmerce-brands.php');
define( 'WPWOOF_BRAND_PBFW',    'product-brands-for-woocommerce/product-brands-for-woocommerce.php');
define('WPWOOF_MULTI_CRRNC',    'woo-multi-currency/woo-multi-currency.php');
define('WPWOOF_CURRN_SWTCH',    'currency-switcher-woocommerce/currency-switcher-woocommerce.php');
define('WPWOOF_CURRN_SWTPR',    'currency-switcher-woocommerce-pro/currency-switcher-woocommerce-pro.php');
define('WPWOOF_WCPBC',          'woocommerce-product-price-based-on-countries/woocommerce-product-price-based-on-countries.php');
define('WPWOOF_ALLIMPP',        'wp-all-import-pro/wp-all-import-pro.php');
define('WPWOOF_ALLIMP',         'wp-all-import/plugin.php');


//Plugin

require_once('inc/helpers.php');
require_once('inc/generate-feed.php');
require_once('inc/admin.php');
require_once('inc/feed-list-table.php');
require_once('inc/admin_notices.php' );
require_once('inc/tools.php');

if(isset($_GET['WPWOOF_DEBUG']))  update_option('WPWOOF_DEBUG', boolval ($_GET['WPWOOF_DEBUG']));
define( 'WPWOOF_DEBUG', get_option('WPWOOF_DEBUG') );

if( WPWOOF_DEBUG ){
    if (!function_exists('trace')) {
        function trace ($obj,$onexit=0){
            echo "<pre>".print_r($obj,true)."</pre>";
            if($onexit) exit();
        }
    }
	if (!function_exists('wpwoofStoreDebug')) {
		function wpwoofStoreDebug( $data, $file = null ) {
//		trace( date( 'Y-m-d H:i:s' ) . "\t" . print_r( $data, true ) . "\n" );
			if (empty($file)) {
				global $woocommerce_wpwoof_common;
				$file = $woocommerce_wpwoof_common->getDebugFile();
			}
			if ( ! empty( $file ) ) {
				file_put_contents( $file, date( 'Y-m-d H:i:s' ) . "\t" . print_r( $data, true ) . "\n", FILE_APPEND );
			}
		}
	}


}

if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach($arr as $key => $unused) {
            return $key;
        }
        return NULL;
    }
}

class wpwoof_product_catalog
{
     static $interval = '86400';
     static $oTools = null;
     static $schedule = array(
        '0' => 'never',
        '3600' => 'hourly',
        '43200' => 'twicedaily',
        '86400' => 'daily',
        '604800' => 'weekly'
    );
     static $field_names = array(
        'feed_google_category' => array(
            'title' => 'Google Taxonomy:',
            'type' => 'googleTaxonomy',
            "toImport" => 'text'
        ),
        'wpfoof-mpn-name' => array(
            "title" => 'MPN:',
            // "subscription"=>'Manufacturer part number',
            "type" => 'text',
            "toImport" => 'text'
        ),
        'wpfoof-gtin-name' => array(
            "title" => 'GTIN:',
            // "subscription"=>'Global Trade Item Number(GTINs may be 8, 12, 13 or 14 digits long)',
            "type" => 'text',
            "toImport" => 'text'
        ),
        'wpfoof-brand' => array(
            "title" => 'Brand:',
            "type" => 'text',
        ),
         'wpfoof-identifier_exists' => array(
             'title' => 'identifier_exists:',
             'type' => 'select',
             'options' => array(
                 'true' => 'select',
                 'yes' => 'Yes',
                 'output'=> 'No'
             )
         ),
        'wpfoof-condition' => array(
            'title' => 'Condition:',
            'type' => 'select',
            'topHr' => true,
            'options' => array(
                '' => 'Select',
                'new' => 'new',
                'refurbished' => 'refurbished',
                'used' => 'used'
            ),
            "toImport" => 'radio'
        )
    );
     static  $WWC;
     static $category_field_names = array(

         'wpfoof-identifier_exists' => array(
             'title' => 'identifier_exists:',
             'type' => 'select',
             'options' => array(
                 'true' => '',
                 'yes' => 'Yes',
                 'output'=> 'No'
             )
         ),
        'feed_google_category' => array(
            'title' => 'Google Taxonomy:',
            'type' => 'googleTaxonomy'
        ),
        'wpfoof-adult' => array(
            'title' => 'Adult:',
            'type' => 'select',
            'options' => array(
                'no' => 'No',
                'yes' => 'Yes'
            )
        ),
        'wpfoof-shipping-label' => array(
            'title' => 'shipping_label:',
            'type' => 'text'
        ),
        'wpfoof-tax-category' => array(
            'title' => 'tax_category:',
            'type' => 'text'
        )
    );

    static $tag_field_names = array(



    );


    function __construct()
    {
        global $xml_has_some_error, $woocommerce_wpwoof_common;


        self::$WWC =  $woocommerce_wpwoof_common;
        $xml_has_some_error = false;
        self::$oTools = new wpWoofTools();
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
        add_action( 'upgrader_process_complete', array(__CLASS__, 'on_upgrade_completed'), 10, 2 );

        add_action('init', array(__CLASS__, 'init'),90);
        add_action('admin_init', array(__CLASS__, 'admin_init'),90);
        //



        // extra fields on category form
        add_action('product_cat_edit_form_fields', array(__CLASS__, 'edit_extra_fields_category'), 20, 2);
        add_action('product_cat_add_form_fields', array(__CLASS__, 'add_extra_fields_category'), 20, 2);

        add_action('edited_product_cat', array(__CLASS__, 'save_extra_fields_category'), 10, 2);
        add_action('create_product_cat', array(__CLASS__, 'save_extra_fields_category'), 10, 2);




        // extra fields on product form
        //'woocommerce_product_options_general_product_data'
        add_filter( 'woocommerce_product_data_tabs',array(__CLASS__, 'woo_woof_product_tab'), 99, 1 );
        //add_action('woocommerce_product_options_woof_tab_product_data', array(__CLASS__, 'add_extra_fields'), 10);



        add_action('woocommerce_product_after_variable_attributes', array(__CLASS__, 'add_extra_fields_variable'), 10, 3);
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_extra_fields'), 10, 2);
        add_action('woocommerce_save_product_variation', array(__CLASS__, 'save_extra_fields'), 10, 2);


        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_scripts'));

        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('wpwoof_feed_update', array(__CLASS__, 'wpwoof_feed_update'));
        add_action('wpwoof_generate_feed', array(__CLASS__, 'do_this_generate'), 10, 3);

        // Declaring extension compatibility with WooCommerce High-Performance Order Storage (HPOS)
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });

    }


    static function  woo_woof_product_tab( $default_tabs ) {
        $default_tabs['woof_tab'] = array(
            'label'    =>  __( WPWOOF_SL_ITEM_SHNAME, 'feedpro' ),
            'target'   =>  'woof_add_extra_fields',
            'priority' =>  90,
            //'class'    =>  array('panel', 'woocommerce_options_panel')
        );
        add_action( 'woocommerce_product_data_panels', array(__CLASS__, 'woof_add_extra_fields') );
        return $default_tabs;
    }



    static function init() {
        self::$interval = self::$WWC->getInterval();
    }

	static function set_wpwoof_disable_status() {
		check_ajax_referer( 'wpwoof_settings' );
		if ( isset( $_POST['set_wpwoof_disable_status'] ) && ! empty( $_POST['feed_id'] ) ) {
			$fid   = (int) $_POST['feed_id'];
			$value = wpwoof_get_feed( $fid );

			if ( ! empty( $value['feed_name'] ) ) {
				$value['noGenAuto'] = empty( $_POST['set_wpwoof_disable_status'] ) ? 0 : 1;
				wpwoof_update_feed( $value, $fid, true );
				self::schedule_feed( $value );
			}

			header( 'Content-Type: application/json' );
			exit( json_encode( array( "status" => "OK" ) ) );
		}
		wp_die();
	}

	static function set_wpwoof_category() {
		check_ajax_referer( 'wpwoof_settings' );
		$data = array();
		if ( isset( $_POST['wpwoof_feed_google_category'] ) ) {
			$data['name'] = $_POST['wpwoof_feed_google_category'];
			self::$WWC->setGlobalGoogleCategory( $data );

		}
		exit( 'OK' );
	}

	static function wpwoof_status() {
		check_ajax_referer( 'wpwoof_settings' );
		$result = array();
		if ( isset( $_POST['wpwoof_status'] ) && isset( $_POST['feedids'] ) && ! empty( $_POST['feedids'] ) ) {
			foreach ( $_POST['feedids'] as $val ) {
				$val            = (int) $val;
				$result[ $val ] = array();
				$status         = self::$WWC->get_feed_status( $val );
				$feedConfig     = wpwoof_get_feed( $val );
				if ( isset( $feedConfig['generated_time'] ) || ! empty( $feedConfig['generated_time'] ) ) {
					$date = new DateTime();
					$date->setTimestamp( $feedConfig['generated_time'] );
					$date->setTimezone( new DateTimeZone( self::$WWC->getWpTimezone() ) );
					$result[ $val ]['timestr'] = $date->format( 'd/m/Y H:i:s' );
					$nextRun                   = wp_get_scheduled_event( 'wpwoof_generate_feed', array( $val ) );
					if ( ! empty( $nextRun ) && ! empty( $nextRun->timestamp ) ) {
						$date->setTimestamp( $nextRun->timestamp );
						$result[ $val ]['timestr'] .= '<br>Next update:<br>' . $date->format( 'd/m/Y H:i:s' );
					}
				}
				$result[ $val ]['total']           = $status['total_products'];
				$result[ $val ]['processed']       = $status['parsed_products'];
				$result[ $val ]['show_loader']     = $status['show_loader'];
				$upload_dir                        = wpwoof_feed_dir( $feedConfig['feed_name'], $feedConfig['feed_type'] == "adsensecustom" ? 'csv' : 'xml' );
				$result[ $val ]['hideFeedButtons'] = ! file_exists( $upload_dir['path'] );
				if ( ! empty( $feedConfig['status_feed'] ) && ! in_array( $feedConfig['status_feed'], array(
						'finished',
						'starting'
					) ) ) {
					$result[ $val ]['error'] = $feedConfig['status_feed'];
				}

			}
		}
		header( 'Content-Type: application/json' );
		exit( json_encode( $result ) );
	}

	static function wpwoof_addfeed_submit() {
		check_ajax_referer( 'wpwoof_settings' );
		$values = $_POST;
		unset( $values['wpwoof_addfeed_submit'] );
		unset( $values['action'] );
		$values['added_time'] = time();
		$feed_name            = sanitize_text_field( $values['feed_name'] );
		//trace($values,1);
		if ( isset( $_POST['edit_feed'] ) && ! empty( $_POST['edit_feed'] ) ) {
			if ( isset( $_POST['old_feed_name'] ) && ! empty( $_POST['old_feed_name'] ) ) {
				$oldfile = trim( $_POST['old_feed_name'] );
				$oldfile = strtolower( $oldfile );
				$newfile = trim( $_POST['feed_name'] );
				$newfile = strtolower( $newfile );
				if ( $newfile != $oldfile ) {
					wpwoof_delete_feed_file( (int) $_POST['edit_feed'] );
					wpwoof_update_feed( $values, (int) $_POST['edit_feed'], false, $feed_name );
				}
			}
			wpwoof_create_feed( $values );
			update_option( 'wpwoof_message', 'Feed Updated Successully.' );
			$wpwoof_message = 'success';
		} else {
			if ( update_option( 'wpwoof_feedlist_' . $feed_name, $values ) ) {
				global $wpdb;
				$sql    = "SELECT * FROM $wpdb->options WHERE option_name = 'wpwoof_feedlist_" . esc_sql( $feed_name ) . "' Limit 1";
				$result = $wpdb->get_results( $sql, 'ARRAY_A' );
				if ( count( $result ) == 1 ) {
					$values['edit_feed'] = $result[0]['option_id'];
					wpwoof_create_feed( $values );
				}
			}
		}
		/* Reload the current page */
		if ( isset( $wpwoof_message ) ) {
			wpwoof_refresh( $wpwoof_message );
		}

	}

	static function wpwoof_check_feed_name() {
		global $wpdb;
		check_ajax_referer( 'wpwoof_settings' );
		$feed_name = sanitize_text_field( $_POST['wpwoof_check_feed_name'] );
		header( 'Content-Type: application/json' );
		if ( ! get_option( 'wpwoof_feedlist_' . $feed_name, false ) ) {
			exit( json_encode( array( "status" => "OK" ) ) );
		}
		$aExists = array();
		$sql     = "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'wpwoof_feedlist_" . $feed_name . "%'";
		$res     = $wpdb->get_results( $sql, 'ARRAY_A' );
		foreach ( $res as $val ) {
			$aExists[] = $val['option_name'];
		}
		exit( json_encode( $aExists ) );
	}

	static function set_wpwoof_global_data() {
		check_ajax_referer( 'wpwoof_settings' );
		$data = array(
			'extra' => isset( $_POST['extra'] ) ? (array) $_POST['extra'] : array(),
			'brand' => isset( $_POST['brand'] ) ? (array) $_POST['brand'] : array(),
		);
		self::$WWC->setGlobalData( $data );
		exit( 'OK' );

	}

	static function set_wpwoof_schedule() {
		check_ajax_referer( 'wpwoof_settings' );
		if ( isset( $_POST['wpwoof_schedule'] ) ) {
			$option = $_POST['wpwoof_schedule'];


			if ( ! empty( self::$schedule[ $option ] ) ) {
				self::$interval = $option;
				update_option( 'wpwoof_schedule', self::$interval );


				wp_clear_scheduled_hook( 'wpwoof_feed_update' );
				if ( self::$interval * 1 > 0 ) {
					wp_schedule_event( time(), self::$schedule[ $option ], 'wpwoof_feed_update' );
				}

				global $wpdb;
				$sql    = "SELECT option_id,option_value  FROM $wpdb->options WHERE option_name LIKE 'wpwoof_feedlist_%' and option_value not like '%noGenAuto\";i:1%'";
				$result = $wpdb->get_results( $sql, 'ARRAY_A' );
				if ( ! empty( $result ) ) {
					foreach ( $result as $value ) {
						self::schedule_feed( unserialize( $value['option_value'] ) );
					}
				}
				exit( 'OK' );
			}

		}
		wp_die();
	}
    static protected function _is_canRun(){
            if( is_user_logged_in() ) {
                $roles_selected = get_option('wpwoof_permissions_role',array('administrator'));
                $user = wp_get_current_user();
                if(is_super_admin($user->ID)) return true;

                $roles = ( array )$user->roles;
                foreach ($roles as $r) {
                    if (in_array($r, $roles_selected))  return true;
                }
            }
            return false;
    }
    static function admin_init()
    {

        global $wpdb, $wpwoof_values, $wpwoof_add_button, $wpwoof_add_tab, $wpwoof_message, $wpwoofeed_oldname;
        $wpwoof_values = array();
        $wpwoof_add_button = 'Save & Generate the Feed';
        $wpwoof_add_tab = 'Add New Feed';
        $wpwoof_message = '';
        $wpwoofeed_oldname = '';


        if ( self::_is_canRun() ) {

            add_action('wp_ajax_set_wpwoof_disable_status', array(__CLASS__, 'set_wpwoof_disable_status'));
            add_action('wp_ajax_set_wpwoof_category', array(__CLASS__, 'set_wpwoof_category'));
            add_action('wp_ajax_set_wpwoof_schedule', array(__CLASS__, 'set_wpwoof_schedule'));
            add_action('wp_ajax_set_wpwoof_global_data', array(__CLASS__, 'set_wpwoof_global_data'));
            add_action('wp_ajax_wpwoof_check_feed_name', array(__CLASS__, 'wpwoof_check_feed_name'));
            add_action('wp_ajax_wpwoof_addfeed_submit', array(__CLASS__, 'wpwoof_addfeed_submit'));
            add_action('wp_ajax_wpwoof_status', array(__CLASS__, 'wpwoof_status'));

            if (!self::$WWC->checkSchedulerStatus()) {
                add_action('admin_notices', array(__CLASS__, 'showSchedulerError'));
            }

	        $notice_actions = get_option( 'wpwoof_notice_actions', array() );

	        // #3651 Notification 1 for CoG integration
	        if ( ! empty( $notice_actions['cog_integrated'] ) && empty( $notice_actions['dismiss_cog_1'] )
	             && ( time() - $notice_actions['cog_integrated'] > 86400 ) && ! file_exists( WP_PLUGIN_DIR . '/pixel-cost-of-goods/pixel-cost-of-goods.php' ) ) {
		        add_action('admin_notices', array(__CLASS__, 'show_COG_notification_1'));
	        }

	        // #3651 Notification 2 for CoG integration
	        if ( ! empty( $notice_actions['dismiss_cog_1'] ) && empty( $notice_actions['dismiss_cog_2'] )
	             && ( time() - $notice_actions['dismiss_cog_1'] > 604800 ) && ! file_exists( WP_PLUGIN_DIR . '/pixel-cost-of-goods/pixel-cost-of-goods.php' ) ) {
		        add_action('admin_notices', array(__CLASS__, 'show_COG_notification_2'));
	        }


	        // check if wpwoof_feed_update sheduled
            $license_status =  get_option( 'pcbpys_license_status' );
            $interval = self::$WWC->getInterval();
            if($license_status == 'valid' && !wp_next_scheduled('wpwoof_feed_update') && $interval > 0) {
                wp_schedule_event(time(), self::$schedule[$interval], 'wpwoof_feed_update');
                if(WPWOOF_DEBUG) file_put_contents(self::$WWC->feedBaseDir.'cron-wpfeed.log',date("Y-m-d H:i:s")."\tReScheduled wpwoof_feed_update\n",FILE_APPEND);
            }
            // end: check if wpwoof_feed_update sheduled

            if (!isset($_REQUEST['page']) || $_REQUEST['page'] != 'wpwoof-settings') {
                return;
            }

            $nonce = isset($_REQUEST['_wpnonce']) ? wp_verify_nonce($_REQUEST['_wpnonce'], 'wooffeed-nonce') : false ;


            if ($nonce && isset($_REQUEST['delete']) && !empty($_REQUEST['delete'])) {
                $id = (int)$_REQUEST['delete'];
                $deleted = wpwoof_delete_feed($id);
                wp_clear_scheduled_hook('wpwoof_generate_feed', array($id));

                if ($deleted) {
                    wp_cache_flush();
                    update_option('wpwoof_message', 'Feed Deleted Successully.');
                    $wpwoof_message = 'success';
                } else {
                    update_option('wpwoof_message', 'Failed To Delete Feed.');
                    $wpwoof_message = 'error';
                }
                /* Reload the current page */
                wpwoof_refresh($wpwoof_message);

            } else if ($nonce && isset($_REQUEST['edit']) && !empty($_REQUEST['edit'])) {
                $option_id = (int)$_REQUEST['edit'];
                $wpwoof_values = wpwoof_get_feed($option_id);
                $wpwoof_values['edit_feed'] = $option_id;
                $wpwoofeed_oldname = isset($wpwoof_values['feed_name']) ? $wpwoof_values['feed_name'] : '';
                $wpwoof_add_button = 'Update the Feed';
                $wpwoof_add_tab = 'Edit Feed : ' . $wpwoof_values['feed_name'];
            } else if ( $nonce &&  isset($_REQUEST['update']) && !empty($_REQUEST['update'])) {
                $option_id = (int)$_REQUEST['update'];
                $wpwoof_values = wpwoof_get_feed($option_id);

                $wpwoof_values['edit_feed'] = $option_id;
                self::schedule_feed($wpwoof_values,time());
                exit(json_encode(array("status" => "OK")));
            } else if ($nonce &&  isset($_REQUEST['copy']) && !empty($_REQUEST['copy'])) {
                $option_id = $_REQUEST['copy'];
                $wpwoof_values = wpwoof_get_feed($option_id);
                unset($wpwoof_values['edit_feed']);
                $aExists =  Array();
                $copy_suffix = " - Copy ";
                $sql = "SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'wpwoof_feedlist_" . sanitize_text_field($wpwoof_values['feed_name'].$copy_suffix) . "%'";
                $res = $wpdb->get_results($sql, 'ARRAY_A');
                foreach($res as $val){
                    $aExists[]=$val['option_name'];
                }
                $ind=0;
                do {
                    $ind++;
                    $feed_name = sanitize_text_field('wpwoof_feedlist_'.$wpwoof_values['feed_name'].$copy_suffix.$ind);
                } while (array_search($feed_name, $aExists)!==false);
                $feed_name = str_replace('wpwoof_feedlist_', "", $feed_name);
                $wpwoof_values['feed_name'] =$wpwoof_values['old_feed_name'] = $wpwoof_values['feed_name'].$copy_suffix.$ind;
                $wpwoof_values['noGenAuto'] = 1;
                $wpwoof_values['added_time'] = time();
//                trace($wpwoof_values);
                wpwoof_create_feed($wpwoof_values);
                $wpwoof_message = '';

                /* Reload the current page */
                wpwoof_refresh($wpwoof_message);
            } elseif( isset( $_POST['pcbpys_add_permissions_role'] ) && isset($_POST['roles']) && is_array($_POST['roles']) && count($_POST['roles'])>0) {
                if( ! check_admin_referer( 'pcbpys_nonce', 'pcbpys_nonce' ) )
                    return; // get out if we didn't click the Activate button
                $old_roles = get_option('wpwoof_permissions_role',array('administrator'));
                update_option('wpwoof_permissions_role',$_POST['roles']);
                global $wp_roles;
                foreach ( $wp_roles->roles as $role => $options ) {
                    if ( in_array( $role, $_POST['roles'] ) ) {
                        if(!in_array( $role, $old_roles )) {
                            $wp_roles->add_cap( $role, 'manage_feedpro' );
                        }
                    } else {
                        if(in_array( $role, $old_roles )) {
                            $wp_roles->remove_cap( $role, 'manage_feedpro' );
                        }
                    }
                }
            }
        } //current_user_can('administrator')
    }

	static function showSchedulerError() {
		echo '<div class="notice notice-error is-dismissible"> <p><b>' . WPWOOF_SL_ITEM_NAME . '</b>: Feeds won\'t be generated if your WordPress Cron is disabled, or if your website is password protected. </p></div>';
	}

	static function show_COG_notification_1() {
		echo '<div class="notice notice-info is-dismissible wpwoof-notice-active" data-name="dismiss_cog_1"> <p><b>' . WPWOOF_SL_ITEM_NAME . '</b>: Send [cost_of_goods_sold] to Google Merchant to get additional reporting on gross profit. Use this plugin to add the cost to your products: <a href="https://www.pixelyoursite.com/plugins/woocommerce-cost-of-goods?utm_source=feed-plugin&utm_medium=feed-plugin-notification1&utm_campaign=feed-plugin-notification1">WooCommerce Cost of Goods</a>.</p></div>';
	}

	static function show_COG_notification_2() {
		echo '<div class="notice notice-info is-dismissible wpwoof-notice-active" data-name="dismiss_cog_2"> <p><b>' . WPWOOF_SL_ITEM_NAME . '</b>: Add [cost_of_goods_sold] to your Google Merchant feed to get additional reporting on gross profit. Use this plugin to add the cost to your products: <a href="https://www.pixelyoursite.com/plugins/woocommerce-cost-of-goods?utm_source=feed-plugin&utm_medium=feed-plugin-notification2&utm_campaign=feed-plugin-notification2">WooCommerce Cost of Goods</a>.</p></div>';
	}


	static function notice_action() {
		if ( ! empty( $_POST['element'] ) && in_array( $_POST['element'], array(
				'dismiss_cog_1',
				'dismiss_cog_2'
			) ) ) {
			$notice_actions = get_option( 'wpwoof_notice_actions', array() );
			$notice_actions[ $_POST['element'] ] = time();
			update_option( 'wpwoof_notice_actions', $notice_actions, 'no' );
			header( 'Content-Type: application/json' );
			exit( json_encode( array( "status" => "OK" ) ) );
		}
		wp_die();
	}


    static function admin_menu() {
        if ( !self::_is_canRun() ) return;
        add_menu_page( 'Product Catalog', 'Product Catalog',  'manage_feedpro', 'wpwoof-settings', array(__CLASS__, 'menu_page_callback'), WPWOOF_URL . '/assets/img/favicon.png');
    }

    static function menu_page_callback() {
        require_once('view/admin/admin-settings.php');
    }

    static function admin_enqueue_scripts() {
        wp_enqueue_style( WPWOOF_PLUGIN.'-fastselect', WPWOOF_ASSETS_URL . 'css/fastselect.min.css', array(), WPWOOF_VERSION, false );
        wp_enqueue_script( WPWOOF_PLUGIN.'-fastselect', WPWOOF_ASSETS_URL . 'js/fastselect.min.js', array('jquery'), WPWOOF_VERSION, false );
        if(isset($_GET['page']) && $_GET['page'] == 'wpwoof-settings' ){
            //Admin Style

            wp_enqueue_style( WPWOOF_PLUGIN.'-style', WPWOOF_ASSETS_URL . 'css/admin-dashboard.css', array(), WPWOOF_VERSION, false );
            //Admin Javascript
            wp_enqueue_script( WPWOOF_PLUGIN.'-script', WPWOOF_ASSETS_URL . 'js/admin.js', array('jquery'), WPWOOF_VERSION, false );
            wp_enqueue_script( WPWOOF_PLUGIN.'-optionTree', WPWOOF_ASSETS_URL . 'js/jquery.optionTree.js', array('jquery'), WPWOOF_VERSION, false );

            wp_enqueue_script( 'jquery.inputmask.bundle.min.js', WPWOOF_ASSETS_URL . 'js/jquery.inputmask.bundle.min.js', array('jquery'), '4.0.9', false );

            wp_enqueue_media();
            wp_enqueue_script( WPWOOF_PLUGIN.'-media-script', WPWOOF_ASSETS_URL . 'js/media.js', array('jquery'), WPWOOF_VERSION, false );

	        wp_localize_script( WPWOOF_PLUGIN . '-script', 'WPWOOF', array(
		        'ajaxurl' => admin_url( 'admin-ajax.php' ),
		        'loading' => admin_url( 'images/loading.gif' ),
		        'nonce'   => wp_create_nonce( 'wpwoof_settings' )
	        ) );
        }
    }
    static function cron_schedules($schedules) {
        $interval = self::$interval;

        foreach(self::$schedule as $sec => $name){
            if($sec*1>0 && !isset($schedules[$name])){
                $schedules[$name] = array(
                    'interval' => $sec*1,
                    'display' => __($name));
            }
        }

        return $schedules;
    }
    static function wpwoof_feed_update() {
        global $wpdb;
        $var = "wpwoof_feedlist_";
        $sql = "SELECT option_id,option_value FROM $wpdb->options WHERE option_name LIKE '".$var."%' and option_value not like '%noGenAuto\";i:1%'";
        if(WPWOOF_DEBUG) file_put_contents(self::$WWC->feedBaseDir.'cron-wpfeed.log',date("Y-m-d H:i:s")."\tSTART wpwoof_feed_update\n",FILE_APPEND);
        $autoUpdFeeds = $wpdb->get_results($sql, 'ARRAY_A');
        if(!empty($autoUpdFeeds)) {
            $scheduledFeeds = self::$WWC->getScheduledFeeds();
            foreach ($autoUpdFeeds as $value) {
                if(!in_array($value['option_id'], $scheduledFeeds)) {
                    self::schedule_feed(unserialize($value['option_value']));
                }
            }
        }
    }
    static function do_this_generate($feed_id) {
        if (WPWOOF_DEBUG)
            file_put_contents(self::$WWC->feedBaseDir . 'cron-wpfeed.log', date("Y-m-d H:i:s") . "\tSTART do_this_generate\t" . $feed_id . "\n", FILE_APPEND);
        if ($feed_id) {

            $wpwoof_values = wpwoof_get_feed($feed_id);
            $wpwoof_values['edit_feed'] = $feed_id;

            if (!isset($wpwoof_values['feed_name'])) {
                file_put_contents(self::$WWC->feedBaseDir . 'critical.log', date("Y-m-d H:i:s") . "\tERROR Structure:ID:|" . $feed_id . "|\t" . print_r($wpwoof_values, true) . "\n", FILE_APPEND);
                exit;
            }

            wpwoofeed_generate_feed($wpwoof_values);
        }
    }
    static function activate() {
        $interval = self::$WWC->getInterval();
        if(!isset( self::$schedule[$interval])) {
            $interval = self::$interval;
            update_option('wpwoof_schedule', $interval);
        }
        wp_schedule_event(time(), self::$schedule[$interval], 'wpwoof_feed_update');

        $path_upload 	= wp_upload_dir();
        $path_upload 	= $path_upload['basedir'];


        $pathes = array(
            array('wpwoof-feed', 'xml'),
            array('wpwoof-feed', 'csv'),
        );
        foreach($pathes as $path) {
            $path_folder = $path_upload;
            foreach($path as $folder) {
                $path_created = false;
                if( is_writable($path_folder) ) {
                    $path_folder = $path_folder.'/'.$folder;
                    $path_created = is_dir($path_folder);
                    if( ! $path_created ) {
                        $path_created = mkdir($path_folder, 0755);
                    }
                }
                if( ! is_writable($path_folder) || ! $path_created ) {
                    self::deactivate_generate_error('Cannot create folders in uploads folder', true, true);
                    die('Cannot create folders in uploads folder');
                }
            }
        }
        if (!file_exists($path_folder.'/wpwoof-feed/xml/.htaccess')) {
            file_put_contents($path_upload.'/wpwoof-feed/xml/.htaccess', '<ifModule mod_rewrite.c>'.PHP_EOL.'RewriteEngine Off'.PHP_EOL.'</IfModule>' );
        }
        if (!file_exists($path_folder.'/wpwoof-feed/.htaccess')) {
            file_put_contents($path_upload.'/wpwoof-feed/.htaccess', '<ifModule mod_autoindex.c>'.PHP_EOL.'Options -Indexes'.PHP_EOL.'</IfModule>' );
        }
        global $wp_roles;
        $roles_selected = get_option('wpwoof_permissions_role',array('administrator'));
        foreach ( $wp_roles->roles as $role => $options ) {
            if ( in_array( $role, $roles_selected ) ) {
                $wp_roles->add_cap( $role, 'manage_feedpro' );
            }
        }
	    self::$WWC->saveFileFromUrl("http://www.google.com/basepages/producttype/taxonomy.en-US.txt", $path_upload. "/wpwoof-feed/google-taxonomy.en.txt");

        self::dbMigration();
    }
    static function deactivate() {
        global $wp_roles,$wpdb;
        wp_clear_scheduled_hook('wpwoof_feed_update');
        wp_unschedule_hook('wpwoof_generate_feed');
        $roles_selected = get_option('wpwoof_permissions_role',array('administrator'));
        foreach ( $wp_roles->roles as $role => $options ) {
            $wp_roles->remove_cap( $role, 'manage_feedpro' );
        }
    }
    static function deactivate_generate_error($error_message, $deactivate = true, $echo_error = false) {
        if( $deactivate ) {
            deactivate_plugins(array(__FILE__));
        }
        if($error_message) {
            $message = "<div class='notice notice-error is-dismissible'>
            <p>" . $error_message . "</p></div>";
            if ($echo_error) {
                echo $message;
            } else {
                add_action('admin_notices', create_function('', 'echo "' . $message . '";'), 9999);
            }
        }
    }

    /**
     * This function runs when WordPress completes its upgrade process
     * It iterates through each plugin updated to see if ours is included
     * @since 2.0.2
     * @param $upgrader_object Array
     * @param $options Array
     */
    static function on_upgrade_completed($upgrader_object, $options) {
        // If an update has taken place and the updated type is plugins and the plugins element exists
        if ($options['action'] == 'update' && $options['type'] == 'plugin' && isset($options['plugins'])) {
            // Iterate through the plugins being updated and check if ours is there
            foreach ($options['plugins'] as $plugin) {
                if ($plugin == WPWOOF_BASE) {
                    self::dbMigration();
                    return;
                }
            }
        }
    }

    private static function dbMigration() {
        global $wpdb;
        $dbVersion = get_option('WPWOOF_DB_VERSION');
        if (empty($dbVersion)) {  //@since 2.0.2
            $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key regexp '^[0-9]+\-wpfoof\-' OR meta_key regexp '^[0-9]+wpfoof\-'");
        }
        if ('free|'.WPWOOF_VERSION !== $dbVersion) {
            update_option('WPWOOF_DB_VERSION', 'free|'.WPWOOF_VERSION, false);
        }

	    //  Cost of Goods (CoG) integration
	    $notice_actions = get_option( 'wpwoof_notice_actions', array() );
	    if (!isset($notice_actions[ 'cog_integrated' ] )) {
		    $notice_actions[ 'cog_integrated' ] = time();
	    }

    }
    static function edit_extra_fields_category($term, $isTag = false) {
        $termData = get_term_meta($term->term_id);
        //echo "TERMDATA:";
        //trace($termData);
        wp_enqueue_script( WPWOOF_PLUGIN.'-optionTree', WPWOOF_ASSETS_URL . 'js/jquery.optionTree.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_script( WPWOOF_PLUGIN.'-script', WPWOOF_ASSETS_URL . 'js/admin.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_style( WPWOOF_PLUGIN.'-style', WPWOOF_ASSETS_URL . 'css/admin.css', array(), WPWOOF_VERSION, false );
        ?>
        <!-- /table><br><br><br -->
        <tr><td colspan="2"><h1>Product Catalog Options:</h1></td></tr>
        <!-- table class="form-table" -->
        <?php
        $cats = $isTag=="tag" ? self::$tag_field_names : self::$category_field_names;
        foreach($cats as $fieldId => $field) {
            switch ($field['type']) {
                case 'toggle':
                    ?>
                    <tr class="form-field">
                        <th>
                            <input  name="<?php echo $fieldId; ?>" type="hidden" value="0" />
                            <input id="<?php echo $fieldId; ?>" name="<?php echo $fieldId; ?>" type="checkbox" class="ios-switch" <?php echo (isset($termData[$fieldId][0]) && $termData[$fieldId][0] ? 'checked="checked"' : ''); ?> />
                            <div class="switch"></div>
                        </th>
                        <td><label for="<?php echo $fieldId; ?>"><?php echo $field['title']; ?></label></td>
                    </tr>
                    <?php
                    break;
                case 'text':
                    ?>
                    <tr class="form-field">
                        <th><?php echo $field['title']; ?></th>
                        <td>
                            <input type='text' name="<?php echo $fieldId; ?>" value="<?php echo (isset($termData[$fieldId][0]) && $termData[$fieldId][0] ? $termData[$fieldId][0] : ''); ?>" />
                        </td>
                    </tr>
                    <?php
                    break;
                case 'select':
                    ?>
                    <tr class="form-field">
                        <th><?php echo $field['title']; ?></th>
                        <td>
                            <select name="<?php echo $fieldId; ?>">
                                <?php
                                if (isset($field['options']) && $field['options'])
                                    foreach ($field['options'] as $key => $text) {
                                        echo '<option value="' . $key . '" ' . (isset($termData[$fieldId][0]) && $termData[$fieldId][0] && $termData[$fieldId][0] == $key ? 'selected' : '') . '>' . $text . '</option>';
                                    }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <?php
                    break;
                case 'googleTaxonomy':
                     $textCats =  isset($termData[$fieldId][0]) ? $termData[$fieldId][0] : "";
                    ?>
                    <tr class="form-field">
                        <th>
                            <?php echo $field['title']; ?>
                        </th>
                        <td class="addfeed-top-value">
                            <input class="wpwoof_google_category_cat_name" type="hidden" name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($textCats,ENT_QUOTES); ?>"  />
                            <input type="text" name="wpwoof_google_category_cat" class="wpwoof_google_category_cat" value="" style='display:none;' />
                        </td>
                    </tr>
                    <script type="text/javascript">
                        jQuery(function($) {
                            loadTaxomomy(".wpwoof_google_category_cat");
                        });
                    </script>
                    <?php
                    break;

            }
        }
        /* adding custom fields */

    }
    static function add_extra_fields_category($term, $isTag=false) {
        $termData = (!isset($term) || !isset($term->term_id)) ? array() : get_term_meta($term->term_id);


        wp_enqueue_script( WPWOOF_PLUGIN.'-optionTree', WPWOOF_ASSETS_URL . 'js/jquery.optionTree.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_script( WPWOOF_PLUGIN.'-script', WPWOOF_ASSETS_URL . 'js/admin.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_style( WPWOOF_PLUGIN.'-style', WPWOOF_ASSETS_URL . 'css/admin.css', array(), WPWOOF_VERSION, false );
        ?>
        <!-- /table><br><br><br -->

        <tr><td colspan="2"><h1>Product Catalog Options:</h1></td></tr>
        <!-- table class="form-table" -->
        <?php
        $cats = $isTag ? self::$tag_field_names : self::$category_field_names;
        foreach($cats as $fieldId => $field) {
            switch ($field['type']) {
                case 'toggle':
                    ?>
                    <div class="form-field">
                        <input  name="<?php echo $fieldId; ?>" type="hidden" value="0" />
                        <label for="<?php echo $fieldId; ?>"><?php echo $field['title']; ?></label>
                        <input id="<?php echo $fieldId; ?>" name="<?php echo $fieldId; ?>" type="checkbox" class="ios-switch" <?php echo (isset($termData[$fieldId][0]) && $termData[$fieldId][0] ? 'checked="checked"' : ''); ?> />
                        <div class="switch"></div>
                    </div>
                    <?php
                    break;
                case 'text':
                    ?>
                    <div class="form-field">
                        <label><?php echo $field['title']; ?></label>
                        <input type='text' name="<?php echo $fieldId; ?>" value="<?php echo (isset($termData[$fieldId][0]) && $termData[$fieldId][0] ? $termData[$fieldId][0] : ''); ?>" />
                    </div>
                    <?php
                    break;
                case 'select':
                    ?>
                    <div class="form-field">
                        <label><?php echo $field['title']; ?></label>
                        <select name="<?php echo $fieldId; ?>">
                            <?php
                            if (isset($field['options']) && $field['options'])
                                foreach ($field['options'] as $key => $text) {
                                    echo '<option value="' . $key . '" ' . (isset($termData[$fieldId][0]) && $termData[$fieldId][0] && $termData[$fieldId][0] == $key ? 'selected' : '') . '>' . $text . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <?php
                    break;
                case 'googleTaxonomy':
                     $textCats =  isset($termData[$fieldId][0]) ? $termData[$fieldId][0] : "";
                    ?>
                    <div class="form-field">
                        <label>
                            <?php echo $field['title']; ?>
                        </label>
                        <div>
                            <input class="wpwoof_google_category_cat_name" type="hidden" name="<?php echo $fieldId; ?>" value="<?php echo htmlspecialchars($textCats,ENT_QUOTES); ?>"  />
                            <input type="text" name="wpwoof_google_category" class="wpwoof_google_category_cat" value="" style='display:none;' />
                        </div>
                    </div>
                    <script type="text/javascript">
                        jQuery(function($) {
                            loadTaxomomy(".wpwoof_google_category_cat");
                        });
                    </script>
                    <?php
                    /*
                    $taxSrc = admin_url('admin-ajax.php');
                    $taxSrc = add_query_arg( array( 'action'=>'wpwoofgtaxonmy'), $taxSrc);
                    $preselect = !empty($preselect) ? self::$oTools->convertToJSStringArray($preselect) : "";
                    ?>
                    <script>
                    var WPWOOFtaxSrc    =  '<?php echo $taxSrc; ?>';
                    var WPWOOFpreselect =  [<?php echo $preselect; ?>];
                    var WPWOOFspiner    =  '<?php echo home_url( '/wp-includes/images/wpspin.gif'); ?>';
                    </script>
                    <?php
                    */
                    break;

            }
        }
    }
    static function save_extra_fields_category($term_id) {

        $term = get_term($term_id);
        $fields = $term->taxonomy=="product_tag" ?  self::$tag_field_names : self::$category_field_names;
        foreach($fields as $fieldId => $field){
            if( isset( $_POST[$fieldId."_id"] ) ){ update_term_meta($term_id, $fieldId."_id", $_POST[$fieldId."_id"]); }
            if( isset($_POST[$fieldId]) ) update_term_meta($term_id, $fieldId, $_POST[$fieldId]);
        }
    }
    static function add_extra_fields_variable($loop, $variation_data, $post){
        ?><div class="woocommerce_variable_attributes product-catalog-feed-pro">
        <br><strong class="woof-extra-title">Product Catalog Options for Variable:</strong>
        <br><br> You must configure shipping from inside your Google Merchant account - <a target="_blank" href="https://support.google.com/merchants/answer/6069284">help</a>
        <?php
        self::extra_fields_box_func( $post, $loop, $variation_data );
        ?></div><?php
    }
    static function woof_add_extra_fields(){
        global $post;
        ?><div id="woof_add_extra_fields"  class="panel woocommerce_options_panel" style="display:none;"><?php /* class="woocommerce_options_panel" */ ?>
        <p><strong class="woof-extra-title">&nbsp;&nbsp;Product Catalog Options:</strong></p>
        <p>You must configure shipping from inside your Google Merchant account - <a target="_blank" href="https://support.google.com/merchants/answer/6069284">help</a></p>
        <?php
        self::extra_fields_box_func($post);
        //trace(self::$tabs);
        ?></div><?php
      /*  add_meta_box( 'extra_fields', 'Product Catalog Feed Ads Images',array(__CLASS__, 'extra_fields_box_func'), 'product', 'normal', 'high'  );*/
    }

    static function save_extra_fields( $post_id, $post ){
        $loop = is_int($post) ? $post :  false;
        if ( !isset( $_POST['wpfoof-box-media'] ) ) return;
        if ( ! isset( $_POST['nonce_name'] ) ) //make sure our custom value is being sent
            return;
        if ( ! wp_verify_nonce( $_POST['nonce_name'], 'nonce_action' ) ) //verify intent
            return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) //no auto saving
            return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) //verify permissions
            return;
        //exit(print_r($_POST,true));



        $new_value = $_POST['wpfoof-box-media']; //array_map( 'trim', $_POST['wpfoof-box-media'] ); //sanitize

        if(isset( $_POST["feed_google_category"][$post_id."-feed_google_category"] )){
            //kostyl for js optiontree

            $new_value[$post_id."-feed_google_category"]    = $_POST["feed_google_category"][$post_id."-feed_google_category"];
        }

        //trace($new_value,true);

        foreach ( $new_value as $k => $v ) {
//            $k = str_replace($post_id."-","",$k);
//            $k = str_replace("0-","",$k);
            $data = $loop===false? $v : $v[$loop];
            if($k == 'extra'){
                update_post_meta( $post_id, 'wpwoof'.$k, $data );
            }else {
                $old_val = trim(get_post_meta($post_id, $k, true));
                if ($old_val != $data || !empty($data)) {
                    update_post_meta($post_id, $k, trim($data) );
                } //save
            }
            //else { delete_post_meta( $post_id, $k); }
        }

    }

    static function extra_fields_box_func( $post,$loop=false, $variation_data=false){
        global $woocommerce_wpwoof_common;
        $isMain=$loop===false;
        $loopStr=$loop===false?"":"[".$loop."]";
        $post_id = (isset($post->ID)) ? $post->ID : '0';
        wp_enqueue_media();
        wp_enqueue_script( WPWOOF_PLUGIN.'-media-script', WPWOOF_ASSETS_URL . 'js/media.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_script( WPWOOF_PLUGIN.'-optionTree', WPWOOF_ASSETS_URL . 'js/jquery.optionTree.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_script( WPWOOF_PLUGIN.'-script', WPWOOF_ASSETS_URL . 'js/admin.js', array('jquery'), WPWOOF_VERSION, false );
        wp_enqueue_style( WPWOOF_PLUGIN.'-style', WPWOOF_ASSETS_URL . 'css/admin-product.css', array(), WPWOOF_VERSION, false );
        wp_nonce_field( 'nonce_action', 'nonce_name' );
        require_once dirname(__FILE__).'/inc/feedfbgooglepro.php';
        $all_fields = wpwoof_get_all_fields();


        $meta_keys        = wpwoof_get_product_fields();
        $meta_keys_sort   = wpwoof_get_product_fields_sort();
        $attributes       = wpwoof_get_all_attributes();
        $gm = get_post_meta( $post_id, 'wpwoofextra', true );

        //compatibility <= 4.1.4
        if (empty($gm)) {
            $gm = @array_merge((array)get_post_meta( $post_id, 'wpwoofgoogle', true ),(array)get_post_meta( $post_id, 'wpwoofadsensecustom', true ));
        }
        $oFeed = new FeedFBGooglePro( $meta_keys, $meta_keys_sort, $attributes);
        $select_values = $helpLinks = array();
        foreach ($all_fields['dashboardExtra'] as $key => $value) {
            if (isset($value['custom']) && !empty($value['custom'])) {
                $select_values[$key] = $value['custom'];
            }
            $helpLinks[$key]= $oFeed->getHelpLinks($value);
        }
        $link2mainFieldlist = array(
            'wpfoof-custom-descr' => 'description',
            'wpfoof-custom-title' => 'title',
            'wpfoof-mpn-name' => 'mpn',
            'wpfoof-gtin-name' => 'gtin',
            'wpfoof-brand' => 'brand',
            'wpfoof-identifier_exists' => 'identifier_exists',
            'wpfoof-condition' => 'condition',
        );

        foreach ( self::$field_names as $key => $val ) {
            if( !$isMain && empty($val['main']) || $isMain ){

                $value = $rawvalue = ($post_id) ? get_post_meta( $post_id, $key, true ) : '';
                $key   = esc_attr( $key );
                $value = esc_attr( $value );

                //compatibility <= 4.1.4
                if($key=='wpfoof-identifier_exists' && $value==='') {
                    if(isset($gm['identifier_exists']['value'])) $value = $gm['identifier_exists']['value'];
                }


                if (isset($val['topHr']) && $val['topHr'])
                        echo '<hr>';
                ?><div><p class="form-field custom_field_type"><?php
                if( empty($val['type'])){
                    $s = explode("x",$val['size']);
                    $image = ! $rawvalue ? '' : wp_get_attachment_image( $rawvalue, 'full', false, array('style' => 'display:block; /*margin-left:auto;*/ margin-right:auto;max-width:30%;height:auto;') );
                    ?>
                    <span  id='IDprev-<?php echo $post_id."-".$key; ?>'class='image-preview'><?php echo ($image) ? ($image."<br/>") : "" ?></span>
                    <label  for="<?php echo $post_id."-".$key; ?>-value"><?php echo $val['title'];?></label>
                    <span class="wrap wpwoof-required-value">
                    <input type='hidden' id='_value-<?php echo $post_id."-".$key; ?>'      name='wpfoof-box-media[<?=$key.']'.$loopStr?>'   value='<?php echo $value?>' />
                    <input type='button' id='<?php echo $post_id."-".$key; ?>'   onclick="jQuery.fn.clickWPfoofClickUpload(this);"     class='button wpfoof-box-upload-button'        value='Upload' />
                    <input type='button' id='<?php echo $post_id."-".$key; ?>-remove' onclick="jQuery.fn.clickWPfoofClickRemove(this);" <?php if(empty($image)) {?>style="display:none;"<?php } ?> class='button wpfoof-box-upload-button-remove' value='Remove' />
                    </span>
                    <span class="unlock_pro_features" data-size='<?php echo esc_attr( $val['size']);?>'  id='<?php echo $post_id."-".$key; ?>-alert'>
                    </span>
                    <?php
                }//if(empty($val['type'])){

                else if($val['type']=="checkbox"){
                    ?>
                    <label for="<?php echo $post_id."-".$key; ?>-value"><?php echo $val['title'];?></label>
                    <span class="wrap wpwoof-required-value">
                    <input type='hidden'   id='value-<?php echo $post_id."-".$key; ?>'      name='wpfoof-box-media[<?=$key.']'.$loopStr?>'   value='0' />
                    <input type='checkbox' id='_value-<?php echo $post_id."-".$key; ?>'     name='wpfoof-box-media[<?=$key.']'.$loopStr?>'   value='1'  <?php if($value) echo "checked='true'"; ?> />
                    </span>
                    <?php
                }   else if($val['type']=="textarea"){
                    ?>
                    <label for="<?php echo $post_id."-".$key; ?>-value"><?php echo $val['title'];?></label>
                    <span class="wrap wpwoof-required-value">
                        <textarea   class='short wc_input_<?php echo $key; ?>' id='value-<?php echo $post_id."-".$key; ?>'      name='wpfoof-box-media[<?=$key.']'.$loopStr?>' ><?php echo $value; ?></textarea>
                    </span>
                <?php
                    echo isset($link2mainFieldlist[$key])?'<span class="extra-link-2-wrapper">'.$oFeed->getHelpLinks($woocommerce_wpwoof_common->product_fields[$link2mainFieldlist[$key]]).'</span>':'';
                }  else if($val['type']=="text"){
                    ?>
                    <label for="<?php echo $post_id."-".$key; ?>-value"><?php echo $val['title'];?></label>
                    <span class="wrap wpwoof-required-value">
                    <input type='text' id='_value-<?php echo $post_id."-".$key; ?>'   class='short wc_input_<?php echo $key; ?>'  name='wpfoof-box-media[<?=$key.']'.$loopStr?>'   value='<?php echo $value; ?>' />
                    </span>
                    <?php
                    echo isset($link2mainFieldlist[$key])?'<span class="extra-link-2-wrapper">'.$oFeed->getHelpLinks($woocommerce_wpwoof_common->product_fields[$link2mainFieldlist[$key]]).'</span>':'';
                }   else if($val['type'] == 'select') {
                    ?>
                    <label><?php echo $val['title'];?></label>
                    <select name='wpfoof-box-media[<?=$key.']'.$loopStr?>' id='_value-<?php echo $post_id."-".$key; ?>' class="select short">
                        <?php
                        if (isset($val['options']) && $val['options'])
                            foreach ($val['options'] as $key2 => $text)
                                echo '<option value="' . $key2 . '" ' . (isset($value) && $value && $value == $key2 ? 'selected' : '') . '>' . $text . '</option>';
                        ?>
                    </select>
                    <?php
                    echo isset($link2mainFieldlist[$key])?'<span class="extra-link-2-wrapper">'.$oFeed->getHelpLinks($woocommerce_wpwoof_common->product_fields[$link2mainFieldlist[$key]]).'</span>':'';
                } else if ($val['type'] == 'googleTaxonomy') {
                    ?>
                    <label><?php echo $val['title']; ?></label>
                    <span class="catalog-pro-variations-google-taxonomy-container">
                        <input class="wpwoof_google_category<?php echo $post_id;?>_name" type="hidden" name="wpfoof-box-media[<?=$key.']'.$loopStr?>" value="<?php echo htmlspecialchars($value,ENT_QUOTES);?>"  />
                        <input type="text"   class="wpwoof_google_category<?php echo $post_id;?>" id="wpwoof_google_category_<?php echo $post_id;?>" name="wpwoof_google_category_<?php echo $post_id;?>"  value="" style='display:none;' />

                    </span>
                    <?php

                        $taxSrc = admin_url('admin-ajax.php');
                        $taxSrc = add_query_arg( array( 'action'=>'wpwoofgtaxonmy'), $taxSrc);
                    ?>
                    <script>
                         <?php if($isMain) { ?>
                                jQuery(function($) {
                                    loadTaxomomy("#wpwoof_google_category_<?php echo $post_id; ?>");
                                });
                         <?php } else {
                           ?>loadTaxomomy("#wpwoof_google_category_<?php echo $post_id;?>"); <?php
                         }  ?>

                    </script>
                    <?php

                }

                if (isset($val['subscription']) && $val['subscription']) {
                    ?><span class="woocommerce-help-tip" data-tip="<?php echo esc_attr($val['subscription']); ?>" ></span><?php
                }

                echo '</p></div>';
                if (isset($val['type']) && $val['type'] == 'trigger') {
                    ?><div class="trigger_div">
                        <input type='hidden'   name='wpfoof-box-media[<?=$key.']'.$loopStr?>' value="0" />
                        <input type='checkbox'  value="1" <?php if(!empty($val['show'])){ ?> onclick="jQuery.fn.wpwoofOpenCloseFieldList('<?php echo $post_id.$val['show']; ?>',this.checked);"<?php } ?> class="ios-switch" id='_value-<?php
                            echo $post_id . '-' . $key;
                        ?>' name='wpfoof-box-media[<?=$key.']'.$loopStr?>' <?php
                            echo ($value ? "checked='true'" : "");
                        ?> />

                        <div class="switch"></div>
                        <?php echo !empty($va['subtitle']) ? $va['subtitle'] : ''; ?>
                        <label class="woof-switcher-title" for="_value-<?php echo $post_id . '-' . $key; ?>"><?php echo $val['title'];?></label>
                    </div>
                    <?php
                }

                if(!empty($val['show'])){
                    $WpWoofTopSave = "";
                    ?> <div id="id<?php echo $post_id.$val['show']; ?>Fields" style="display:<?php echo !empty($value) ? 'block' : 'none'; ?>;"><?php
                    //trace(($post_id) ? get_post_meta( $post_id, 'wpwoof'.$val['show'], true ) : array());
                    $oFeed->renderFieldsToTab( $all_fields['toedittab'], $val['show'] ,($post_id) ? get_post_meta( $post_id, 'wpwoof'.$val['show'], true ) : array() );
                    ?></div><?php
                }


            }//if(!$isMain && empty($val['main']) || $isMain){

        }
    }

    static function schedule_feed($feed_config, $regenerateTime = false) {
        if(self::$WWC->isPro($feed_config)) {            return false;}
        $status = self::$WWC->get_feed_status($feed_config['edit_feed']);
        if ( ! empty($status['products_left']) && (time() - $status['time'] < 300)) {
//            if(WPWOOF_DEBUG) file_put_contents(self::$WWC->feedBaseDir.'cron-wpfeed.log',date("Y-m-d H:i:s")."\t schedule_feed exclude:".$feed_config['edit_feed']." products_left:".count($status['products_left'])."\n",FILE_APPEND);
            return false;
        }
        if (!$regenerateTime && (!empty($feed_config['noGenAuto']) || self::$interval*1 == 0 )) {
            wp_clear_scheduled_hook('wpwoof_generate_feed', array((int)$feed_config['edit_feed']));
        } else {
            $nextRun = $regenerateTime?$regenerateTime:(isset($feed_config['generated_time']) ? $feed_config['generated_time'] + self::$interval : time());
//            if(WPWOOF_DEBUG) file_put_contents(self::$WWC->feedBaseDir.'cron-wpfeed.log',date("Y-m-d H:i:s")."\t schedule_feed nextRun:".$feed_config['edit_feed']." time:".$nextRun."\n",FILE_APPEND);
            if (wp_next_scheduled('wpwoof_generate_feed', array((int)$feed_config['edit_feed'])) != $nextRun) {
                wp_clear_scheduled_hook('wpwoof_generate_feed', array((int)$feed_config['edit_feed']));
                wp_schedule_single_event($nextRun, 'wpwoof_generate_feed', array((int)$feed_config['edit_feed']));
            }
        }
    }


}
global $wpWoofProdCatalog;
$wpWoofProdCatalog = new wpwoof_product_catalog();
