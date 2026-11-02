<?php
//Updating contact and customer tables functions in background
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BWF_THRESHOLD_ORDERS', 0 ); //defining it more than 0 means you want the background to run only on "n" orders
define( 'BWF_ORDERS_PER_BATCH', 20 ); //defining it means how many orders to process per batch operation

/*** Updating customer tables ***/
if ( ! function_exists( 'bwf_create_update_contact_customer' ) ) {
	/**
	 *
	 * @return bool|string
	 */
	function bwf_create_update_contact_customer() {
		return BWF_DB_Updater_Fns::bwf_create_update_contact_customer( ...func_get_args() );
	}
}


/*
 * CONTACTS DATABASE STARTS
 */
if ( ! function_exists( 'bwf_contacts_v1_0_init_db_setup' ) ) {
	function bwf_contacts_v1_0_init_db_setup() {
		return BWF_DB_Updater_Fns::bwf_contacts_v1_0_init_db_setup( ...func_get_args() );
	}
}