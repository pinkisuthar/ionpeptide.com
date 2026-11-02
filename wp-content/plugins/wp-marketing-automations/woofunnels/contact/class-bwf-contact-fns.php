<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact resolution and creation for WooCommerce orders
 *
 * Implementations behind the bwf_* functions in woofunnels-contact-functions.php —
 * the function signatures there are frozen API and delegate here, so this
 * file parses only when one of them is first called.
 *
 * @package WooFunnels
 */
if ( ! class_exists( 'BWF_Contact_Fns' ) ) {

	class BWF_Contact_Fns {

		public static function bwf_get_contact( $wp_id, $email ) {
			return new WooFunnels_Contact( $wp_id, $email );
		}

		/**
		 * Creating updating contact and customer table
		 * On offer accepted, on order status change and on order indexing
		 *
		 * @param $order_id
		 * @param $products
		 * @param $total
		 * @param false $force
		 *
		 * @return int|void
		 */
		public static function bwf_create_update_contact( $order_id, $products, $total, $force = false ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$wp_id    = $order->get_customer_id();
			$wp_email = '';

			if ( $wp_id > 0 ) {
				$wp_user  = get_user_by( 'id', $wp_id );
				$wp_email = ( $wp_user instanceof WP_User ) ? $wp_user->user_email : '';
			}

			$email = empty( $wp_email ) ? $order->get_billing_email() : $wp_email;

			if ( empty( $email ) ) {
				return;
			}

			$bwf_contact = bwf_get_contact( $wp_id, $email );

			$bwf_email = isset( $bwf_contact->db_contact->email ) ? $bwf_contact->db_contact->email : '';
			$bwf_wpid  = isset( $bwf_contact->db_contact->wpid ) ? $bwf_contact->db_contact->wpid : 0;

			if ( $wp_id > 0 && ( $bwf_wpid !== $wp_id ) ) {
				$bwf_contact->set_wpid( $wp_id );
			}

			if ( ( empty( $bwf_email ) && ! empty( $email ) ) || ( ! empty( $wp_email ) && ( $bwf_email !== $email ) ) ) {
				$bwf_contact->set_email( $email );
			}

			if ( true === $force ) {
				bwf_create_update_contact_object( $bwf_contact, $order );
			}

			bwf_contact_maybe_update_creation_date( $bwf_contact, $order );
			bwf_create_update_customer( $bwf_contact, $order, $order_id, $products, $total );

			if ( true === $force ) {
				do_action( 'bwf_normalize_contact_meta_before_save', $bwf_contact, $order_id, $order );
			}

			$bwf_contact->save();

			if ( true === $force ) {
				do_action( 'bwf_normalize_contact_meta_after_save', $bwf_contact, $order_id, $order );
			}

			$cid = $bwf_contact->get_id();

			/**
			 * Contact failed to persist to a positive id (e.g. a duplicate-email race on a
			 * REST-pushed guest order). Don't stamp the order so it can be retried later, and
			 * never index the customer profile against cid 0.
			 */
			if ( empty( $cid ) || absint( $cid ) < 1 ) {
				return 0;
			}

			$order->update_meta_data( '_woofunnel_cid', $cid );
			/**
			 * Stamp the indexed marker here, after the contact has a real id. It was previously
			 * written inside bwf_create_update_customer() before save(), stamping a premature 0
			 * for every new contact and marking the order as indexed against a non-existent customer.
			 */
			$order->update_meta_data( '_woofunnel_custid', $cid );
			$order->save_meta_data();

			return $cid;
		}

		public static function bwf_contact_maybe_update_creation_date( $bwf_contact, $order ) {
			$get_creation_date = $bwf_contact->get_creation_date();

			if ( empty( $get_creation_date ) || $get_creation_date === '0000-00-00' || ( ! empty( $get_creation_date ) && $order->get_date_created() instanceof DateTime && ( strtotime( $get_creation_date ) > $order->get_date_created()->getTimestamp() ) ) ) {
				$bwf_contact->set_creation_date( $order->get_date_created()->format( 'Y-m-d H:i:s' ) );
			}
		}

		/**
		 * Called on login, & checkout order processed
		 *
		 * @param $bwf_contact WooFunnels_Contact
		 * @param $order WC_Order
		 *
		 * @return mixed
		 */
		public static function bwf_create_update_contact_object( $bwf_contact, $order ) {
			$wp_id = $order->get_customer_id();

			/** If false then update the fields only when empty */
			$force     = ( true === WooFunnels_DB_Updater::$indexing ) ? false : true;
			$wp_f_name = '';
			$wp_l_name = '';
			if ( $wp_id > 0 ) {
				$wp_user   = get_user_by( 'id', $wp_id );
				$wp_f_name = ( $wp_user instanceof WP_User ) ? $wp_user->user_firstname : '';
				$wp_l_name = ( $wp_user instanceof WP_User ) ? $wp_user->user_lastname : '';
			}
			$f_name = $order->get_billing_first_name();
			$l_name = $order->get_billing_last_name();

			$f_name = empty( $f_name ) ? $wp_f_name : $f_name;
			$l_name = empty( $l_name ) ? $wp_l_name : $l_name;

			$update_name = apply_filters( 'bwf_update_contact_name', true, $bwf_contact );
			if ( ! empty( $f_name ) ) {
				$should_skip_update = ( false === $force || false === $update_name ) && ! empty( $bwf_contact->get_f_name() );
				if ( ! $should_skip_update ) {
					$bwf_contact->set_f_name( $f_name );
				}
			}

			if ( ! empty( $l_name ) ) {
				$should_skip_update = ( false === $force || false === $update_name ) && ! empty( $bwf_contact->get_l_name() );
				if ( ! $should_skip_update ) {
					$bwf_contact->set_l_name( $l_name );
				}
			}

			/** New contact */
			if ( '' === $bwf_contact->get_status() ) {
				if ( class_exists( 'BWFAN_Core' ) ) {
					/** Check if consent value is 1 */
					$marketing_status = $order->get_meta( 'marketing_status' );
					if ( 1 === intval( $marketing_status ) ) {
						$bwf_contact->set_status( 1 );
					} else {
						$bwf_contact->set_status( 0 );
					}
				} else {
					$bwf_contact->set_status( 1 );
				}
			}

			$order_country = $order->get_billing_country();
			if ( ! empty( $order_country ) && 2 === strlen( $order_country ) ) {
				$order_country = ( false === $force && ! empty( $bwf_contact->get_country() ) ) ? '' : $order_country;
				$bwf_contact->set_country( $order_country );
			}

			$order_state = $order->get_billing_state();
			if ( ! empty( $order_country ) && ! empty( $order_state ) ) {
				$state = bwf_get_states( $order_country, $order_state );
				$state = ( false === $force && ! empty( $bwf_contact->get_state() ) ) ? '' : $state;
				$bwf_contact->set_state( $state );
			}

			$bwf_contact->set_type( 'customer' );
			$contact_no = $order->get_billing_phone();
			if ( empty( $contact_no ) && method_exists( $order, 'get_shipping_phone' ) ) {
				$contact_no = $order->get_shipping_phone();
			}
			$timezone = bwf_get_timezone_from_order( $order );

			if ( ! empty( $contact_no ) ) {
				$contact_no = ( false === $force && ! empty( $bwf_contact->get_contact_no() ) ) ? '' : $contact_no;

				/** Appending country code in phone number if not added */
				if ( class_exists( 'BWFAN_Phone_Numbers' ) && ! empty( $contact_no ) && ! empty( $bwf_contact->get_country() ) ) {
					$contact_no = BWFAN_Phone_Numbers::add_country_code( $contact_no, $bwf_contact->get_country() );
				}

				$bwf_contact->set_contact_no( $contact_no );
			}
			if ( ! empty( $timezone ) ) {
				$timezone = ( false === $force && ! empty( $bwf_contact->get_timezone() ) ) ? '' : $timezone;
				$bwf_contact->set_timezone( $timezone );
			}

			if ( empty( $bwf_contact->get_source() ) ) {
				$bwf_contact->set_source( 'wc_order' );
			}

			return $bwf_contact;
		}

		/**
		 * @param $order WC_Order
		 *
		 * @return array|mixed|string
		 */
		public static function bwf_get_timezone_from_order( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return '';
			}

			$may_be_timezone = BWF_WC_Compatibility::get_order_data( $order, '_wfacp_timezone' );
			/** If set in order meta */
			if ( ! empty( $may_be_timezone ) && true === bwf_if_valid_timezone( $may_be_timezone ) ) {
				return $may_be_timezone;
			}

			/** Check for country */
			$country = $order->get_billing_country();

			$list = bwf_get_country_timezone_list();

			if ( ! is_array( $list ) || ! array_key_exists( $country, $list ) ) {
				return '';
			}

			if ( ! isset( $list[ $country ] ) || ! isset( $list[ $country ]['timezone'] ) || count( $list[ $country ]['timezone'] ) === 0 ) {
				return '';
			}

			return $list[ $country ]['timezone'][0];
		}

		/**
		 * @param $timezone
		 *
		 * @return bool
		 */
		public static function bwf_if_valid_timezone( $timezone ) {
			if ( empty( $timezone ) ) {
				return false;
			}
			$zones = timezone_identifiers_list();

			return in_array( $timezone, $zones, true );
		}

		/** countries data
		 * @return mixed|null
		 */
		public static function bwf_get_countries_data() {
			static $countries = null;
			if ( null === $countries ) {
				$countries_data = file_get_contents( dirname( __DIR__ ) . '/contact/data/countries.json' ); //phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				$countries      = json_decode( $countries_data, true );
			}

			return $countries;
		}

		/**
		 * Country => timezone map (memoized — the JSON is ~28K, avoid re-reading per call)
		 *
		 * @return array
		 */
		public static function bwf_get_country_timezone_list() {
			static $list = null;
			if ( null === $list ) {
				$json = file_get_contents( dirname( __DIR__ ) . '/contact/data/contries-timzone.json' ); //phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				$list = json_decode( $json, true );
				$list = is_array( $list ) ? $list : array();
			}

			return $list;
		}
	}
}
