<?php
/**
 * Contact Controller Class
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWFCRM_Contact' ) && BWFAN_Common::is_pro_3_0() ) {
	/**
	 * Class BWFCRM_Contact
	 */
	#[\AllowDynamicProperties]
	class BWFCRM_Contact {

		/** @var WooFunnels_Contact $contact */
		public $contact = null;
		public $customer = null;
		public $fields = array();

		public $already_exists = true;
		public $task_localized = array();
		public $was_unsubscribed = false;

		public static $STATUS_NOT_OPTED_IN = 0;
		public static $STATUS_OPTED_IN = 1;
		public static $STATUS_BOUNCED = 2;
		public static $STATUS_SOFT_BOUNCED = 4;
		public static $STATUS_COMPLAINT = 5;

		public static $DISPLAY_STATUS_SUBSCRIBED = 1;
		public static $DISPLAY_STATUS_UNSUBSCRIBED = 2;
		public static $DISPLAY_STATUS_UNVERIFIED = 3;
		public static $DISPLAY_STATUS_BOUNCED = 4;
		public static $DISPLAY_STATUS_SOFT_BOUNCED = 5;
		public static $DISPLAY_STATUS_COMPLAINT = 6;

		/** Unsubscribe source types (c_type in bwfan_message_unsubscribe). */
		const UNSUBSCRIBE_SOURCE_AUTOMATION   = 1;
		const UNSUBSCRIBE_SOURCE_BROADCAST    = 2;
		const UNSUBSCRIBE_SOURCE_MANUAL       = 3;
		const UNSUBSCRIBE_SOURCE_FORM         = 4;
		const UNSUBSCRIBE_SOURCE_IMPORTER     = 5;
		const UNSUBSCRIBE_SOURCE_BULK_ACTION  = 6;
		const UNSUBSCRIBE_SOURCE_LINK_TRIGGER = 7;
		const UNSUBSCRIBE_SOURCE_TRANSACTIONAL_MAIL = 8;

		public $unsubscribe_date = null;

		/** Temporary terms storage for hooks firing */
		public $assigned_tags = array();
		public $assigned_lists = array();
		public $removed_tags = array();
		public $removed_lists = array();

		private $unsubscribe_context = [];

		/**
		 * Constructor
		 *
		 * @param $contact - WooFunnels_Contact instance | Contact db row data | cid | email
		 * @param false $force_create
		 * @param array $create_contact_args
		 * @param bool $update_fields
		 */
		public function __construct( $contact, $force_create = false, $create_contact_args = array(), $update_fields = true ) {
			if ( $contact instanceof WooFunnels_Contact ) {
				$this->contact = $contact;
				$this->fill_fields_data( $update_fields );

				return;
			}

			/** Contact row data */
			if ( is_array( $contact ) && isset( $contact['id'] ) ) {
				$this->create_obj_from_db_row( $contact );

				if ( class_exists( 'WooCommerce' ) && isset( $contact['customer_id'] ) && ! empty( $contact['customer_id'] ) ) {
					$this->customer = $this->fill_customer_from_array( $contact );
				}
				$this->fill_fields_data( $update_fields );

				return;
			}

			/** Contact ID */
			if ( is_numeric( $contact ) && absint( $contact ) > 0 ) {
				$this->contact = new WooFunnels_Contact( '', '', '', $contact );
				$this->fill_fields_data( $update_fields );

				return;
			}

			/** Email given */
			if ( is_email( $contact ) ) {
				$this->contact = new WooFunnels_Contact( '', $contact );

				if ( $this->contact->get_id() > 0 ) {
					$this->fill_fields_data( $update_fields );

					return;
				}

				( true === $force_create ) && $this->create_contact( $contact, $create_contact_args );
			}
		}

		/**
		 * This populates the contact fields data
		 *
		 * @param bool $override
		 */
		public function fill_fields_data( $override = true ) {
			if ( false === $override ) {
				return;
			}

			if ( false === $this->is_contact_exists() ) {
				return;
			}

			$fields = BWF_Model_Contact_Fields::get_contact_fields( $this->contact->get_id() );
			if ( empty( $fields ) && ! is_array( $fields ) ) {
				return;
			}

			$all_fields_type = BWFAN_Model_Fields::get_field_types();
			$all_fields_type = array_filter( $all_fields_type, function ( $field ) {
				return intval( $field['type'] ) === BWFCRM_Fields::$TYPE_DATE;
			} );
			if ( is_array( $all_fields_type ) && count( $all_fields_type ) > 0 ) {
				$all_fields_type = array_column( $all_fields_type, 'ID' );
				$all_fields_type = array_map( 'intval', $all_fields_type );
			}
			foreach ( $fields as $key => $value ) {
				if ( false === strpos( $key, 'f' ) ) {
					continue;
				}
				$field_id = str_replace( 'f', '', $key );
				/** Check date value is valid or not */
				if ( in_array( intval( $field_id ), $all_fields_type, true ) ) {
					$value = $this->validate_date( $value ) ? $value : '';
				}

				$unserialized              = bwf_safe_unserialize( $value );
				$this->fields[ $field_id ] = is_object( $unserialized ) ? $value : $unserialized;
			}

			unset( $field_id );
			unset( $fields );
		}

		public function validate_date( $value ) {
			$date = $value !== null ? DateTime::createFromFormat( "Y-m-d", $value ) : '';

			return ( $date && ( $date->format( "Y-m-d" ) === $value ) );
		}

		public function set_fields( $meta ) {
			if ( empty( $meta ) || ! is_array( $meta ) ) {
				return;
			}

			foreach ( $meta as $key => $item ) {
				if ( ! is_numeric( $key ) ) {
					unset( $meta[ $key ] );
				}
			}

			$this->fields = array_replace( $this->fields, $meta );
		}

		/** This method will convert the retrieved DB Rows into BWFCRM_Contact objects */
		public function create_obj_from_db_row( $db_row ) {
			$contact = new WooFunnels_Contact();

			/** Set cache if not set */
			$cached = $contact->get_cache_obj( 'cid', absint( $db_row['id'] ) );
			if ( false === $cached ) {
				$contact->set_cache_object( 'cid', absint( $db_row['id'] ), (object) $db_row );
			}
			unset( $contact );

			$this->contact = new WooFunnels_Contact( '', '', '', absint( $db_row['id'] ) );

			/** Extract fields data */
			if ( isset( $db_row['meta'] ) && is_array( $db_row['meta'] ) && count( $db_row['meta'] ) > 0 ) {
				$this->set_fields( $db_row['meta'] );
			}
		}

		/** This method will convert the retrieved DB Rows into BWFCRM_Contact objects */
		public function fill_customer_from_array( $contact_db ) {
			$customer = new WooFunnels_Customer( new WooFunnels_Contact( null, null ) );
			if ( ! is_array( $contact_db ) || ! isset( $contact_db['customer_id'] ) ) {
				return $customer;
			}

			/** Add Basic Details */
			$customer->set_id( absint( $contact_db['customer_id'] ) );

			( isset( $contact_db['l_order_date'] ) ) && $customer->set_l_order_date( $contact_db['l_order_date'] );
			( isset( $contact_db['f_order_date'] ) ) && $customer->set_f_order_date( $contact_db['f_order_date'] );
			( isset( $contact_db['total_order_count'] ) ) && $customer->set_total_order_count( absint( $contact_db['total_order_count'] ) );
			( isset( $contact_db['total_order_value'] ) ) && $customer->set_total_order_value( $contact_db['total_order_value'] );
			( isset( $contact_db['purchased_products'] ) ) && $customer->set_purchased_products( $contact_db['purchased_products'] );
			( isset( $contact_db['purchased_products_cats'] ) ) && $customer->set_purchased_products_cats( $contact_db['purchased_products_cats'] );
			( isset( $contact_db['purchased_products_tags'] ) ) && $customer->set_purchased_products_tags( $contact_db['purchased_products_tags'] );
			( isset( $contact_db['used_coupons'] ) ) && $customer->set_used_coupons( $contact_db['used_coupons'] );
			( isset( $contact_db['aov'] ) ) && $customer->set_aov( $contact_db['aov'] );

			return $customer;
		}

		public function create_contact( $email, $args ) {
			if ( ! is_email( $email ) || ! is_array( $args ) ) {
				return false;
			}

			$contact = new WooFunnels_Contact( 0, $email );
			! empty( $email ) && $contact->set_email( $email );
			! empty( $args['f_name'] ) && $contact->set_f_name( $args['f_name'] );
			! empty( $args['l_name'] ) && $contact->set_l_name( $args['l_name'] );
			! empty( $args['state'] ) && $contact->set_state( $args['state'] );
			! empty( $args['country'] ) && $contact->set_country( $args['country'] );
			! empty( $args['contact_no'] ) && $contact->set_contact_no( $args['contact_no'] );
			! empty( $args['timezone'] ) && $contact->set_timezone( $args['timezone'] );
			isset( $args['status'] ) && $contact->set_status( absint( $args['status'] ) );
			! empty( $args['wp_id'] ) && $contact->set_wpid( absint( $args['wp_id'] ) );
			! empty( $args['source'] ) && $contact->set_source( $args['source'] );
			! empty( $args['points'] ) && $contact->set_points( absint( $args['points'] ) );
			! empty( $args['tags'] ) && $contact->set_tags( $args['tags'] );
			! empty( $args['lists'] ) && $contact->set_lists( $args['lists'] );
			$contact->set_type( 'lead' );

			$creation_date = ! empty( $args['creation_date'] ) ? self::get_date_value( $args['creation_date'], 'Y-m-d H:i:s' ) : '';
			! empty( $creation_date ) && $contact->set_creation_date( $creation_date );

			// if ( ! empty( $contact->get_id() ) ) {
			// $contact_id = $contact->get_id();
			// ! empty( $args['f_name'] ) && $this->fire_hook_field_updated( 'f_name', $args['f_name'], '', $contact_id );
			// ! empty( $args['l_name'] ) && $this->fire_hook_field_updated( 'l_name', $args['l_name'], '', $contact_id );
			// ! empty( $args['state'] ) && $this->fire_hook_field_updated( 'state', $args['state'], '', $contact_id );
			// ! empty( $args['country'] ) && $this->fire_hook_field_updated( 'country', $args['country'], '', $contact_id );
			// ! empty( $args['contact_no'] ) && $this->fire_hook_field_updated( 'contact_no', $args['contact_no'], '', $contact_id );
			// }

			$disable_subscribe_event = false;
			if ( isset( $args['disable_events'] ) && true === $args['disable_events'] ) {
				$disable_subscribe_event = true;
				unset( $args['disable_events'] );
			}

			unset( $args['f_name'] );
			unset( $args['l_name'] );
			unset( $args['contact_no'] );
			unset( $args['state'] );
			unset( $args['country'] );
			unset( $args['creation_date'] );
			unset( $args['timezone'] );
			unset( $args['status'] );
			unset( $args['source'] );
			unset( $args['points'] );
			unset( $args['wp_id'] );

			/** Update Meta */
			if ( is_array( $args ) && ! empty( $args ) ) {
				/** Update Meta */
				$this->set_fields( $args );
			}

			$this->contact = $contact;

			if ( $disable_subscribe_event && isset( $this->contact->is_subscribed ) ) {
				$this->contact->is_subscribed = false;
			}

			$this->save();

			$this->already_exists = false;
			$this->save_fields();

			return $contact->get_id();
		}

		public function get_array( $slim_data = false, $get_wc_data = false, $get_wcs_data = false, $get_offer_data = false, $get_abandoned_data = false ) {
			if ( ! $this->is_contact_exists() ) {
				return array();
			}

			$meta = $this->fields;

			$contact_array = array(
				'id'            => $this->contact->get_id(),
				'wpid'          => $this->contact->get_wpid(),
				'email'         => $this->contact->get_email(),
				'f_name'        => $this->contact->get_f_name(),
				'l_name'        => $this->contact->get_l_name(),
				'contact_no'    => $this->contact->get_contact_no(),
				'state'         => $this->contact->get_state(),
				'country'       => $this->contact->get_country(),
				'creation_date' => ! empty( $this->contact->get_creation_date() ) ? $this->contact->get_creation_date() : '',
				'timezone'      => $this->contact->get_timezone(),
				'fields'        => $meta,
				'last_modified' => ! empty( $this->contact->get_last_modified() ) ? $this->contact->get_last_modified() : '',
				'unsubscribed'  => false,
				'source'        => $this->contact->get_source(),
				'type'          => $this->contact->get_type(),
				'status'        => $this->contact->get_status(),
			);

			if ( isset( $contact_array['country'] ) && ! empty( $contact_array['country'] ) && function_exists( 'WC' ) && isset( WC()->countries ) ) {
				$contact_array['country_formatted'] = isset( WC()->countries->get_countries()[ $contact_array['country'] ] ) ? html_entity_decode( WC()->countries->get_countries()[ $contact_array['country'] ], ENT_QUOTES | ENT_HTML401 ) : '';
			}

			if ( false === $slim_data ) {
				$contact_array['tags']  = $this->get_all_tags();
				$contact_array['lists'] = $this->get_all_lists();
			} else {
				$contact_array['tags']  = $this->get_tags();
				$contact_array['lists'] = $this->get_lists();
			}

			if ( class_exists( 'WooCommerce' ) && true === $get_wc_data ) {
				$contact_array['wc'] = $this->get_customer_as_array( $slim_data );
			}

			/** getting subscription details of the contact */
			if ( class_exists( 'WC_Subscriptions' ) && true === $get_wcs_data && false === $slim_data ) {
				$contact_array['wcs'] = $this->get_wcs_as_array();
			}

			/** getting offer details of the contact */
			if ( true === $get_offer_data && false === $slim_data ) {
				$contact_array['offer'] = $this->get_offer_as_array();
			}

			/** get cart abandoned data only when cart is enable */
			if ( class_exists( 'WooCommerce' ) && true === $get_abandoned_data && false === $slim_data ) {
				$cart_enabled    = true;
				$global_settings = BWFAN_Common::get_global_settings();
				if ( empty( $global_settings['bwfan_ab_enable'] ) ) {
					$cart_enabled = false;
				}

				if ( true === $cart_enabled ) {
					$contact_array['abandoned'] = $this->get_last_abandoned();
				}
			}

			/** Status whether the contact is Subscribed, Unsubscribed, Bounced or Unverified */
			if ( false === $slim_data ) {
				$contact_array['display_status'] = $this->get_display_status();
			}

			if ( false === $slim_data && ! empty( $this->unsubscribe_date ) ) {
				$contact_array['unsubscribe_date'] = $this->unsubscribe_date;
				$contact_array['status']           = 3;
				$contact_array['unsubscribed']     = true;
			}

			/** Get Contact Engagement data */
			if ( false === $slim_data ) {
				$contact_array['last_sent']  = $this->get_field_by_slug( 'last-sent' );
				$contact_array['last_open']  = $this->get_field_by_slug( 'last-open' );
				$contact_array['last_click'] = $this->get_field_by_slug( 'last-click' );
				$contact_array['last_login'] = $this->get_field_by_slug( 'last-login' );

				/** Get User Roles */
				$user_id = absint( $this->contact->get_wpid() );
				$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : null;
				if ( $user instanceof WP_User ) {
					$contact_array['roles'] = array_values( $user->roles );
				}
			}

			if ( false === $slim_data ) {
				$contact_array['link_triggers'] = $this->get_all_link_triggers();
			}

			return apply_filters( 'bwfan_single_contact_get_array', $contact_array, $this, $slim_data );
		}

		public function get_all_tags() {
			$tags = $this->get_tags();
			if ( empty( $tags ) ) {
				return array();
			}
			$all_tags = BWFCRM_Tag::get_tags( $tags );
			if ( $this->show_contact_terms_by_name() ) {
				usort( $all_tags, $this->sort_by_field( 'name' ) );
			}

			return $all_tags;
		}

		public function get_all_lists() {
			$lists = $this->get_lists();
			if ( empty( $lists ) ) {
				return array();
			}

			$all_lists = BWFCRM_Lists::get_lists( $lists );
			if ( $this->show_contact_terms_by_name() ) {
				usort( $all_lists, $this->sort_by_field( 'name' ) );
			}

			return $all_lists;
		}

		/**
		 * Get link triggers full data
		 *
		 * @return array|mixed
		 */
		public function get_all_link_triggers() {
			if ( ! bwfan_is_autonami_pro_active() ) {
				return [];
			}

			$links = $this->get_link_triggers();
			if ( empty( $links ) ) {
				return array();
			}

			$data = BWFAN_Model_Link_Triggers::get_link_triggers( '', '', 0, 0, false, $links );
			if ( isset( $data['links'] ) ) {
				if ( $this->show_contact_terms_by_name() ) {
					usort( $data['links'], $this->sort_by_field( 'title' ) );
				}

				return $data['links'];
			}

			return array();
		}

		public function show_contact_terms_by_name() {
			if ( ! is_null( BWFAN_Common::$show_tags_list_by_name ) ) {
				return BWFAN_Common::$show_tags_list_by_name;
			}

			BWFAN_Common::$show_tags_list_by_name = apply_filters( 'bwfan_contact_show_terms_by_name', false );

			return BWFAN_Common::$show_tags_list_by_name;
		}

		public function sort_by_field( $field ) {
			return function ( $a, $b ) use ( $field ) {
				return strcmp( $a[ $field ], $b[ $field ] );
			};
		}

		public function get_customer_as_array( $slim_data = false ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return [];
			}
			if ( ! $this->is_customer_exists() && class_exists( 'WooCommerce' ) ) {
				if ( ! $this->is_contact_exists() ) {
					return array();
				} else {
					$this->customer = bwf_get_customer( $this->contact );
				}
			}
			$purchased_products = array_unique( $this->customer->get_purchased_products() );
			$wc_data            = array(
				'id'                    => $this->customer->get_id(),
				'l_order_date'          => $this->customer->get_l_order_date(),
				'f_order_date'          => $this->customer->get_f_order_date(),
				'aov'                   => $this->customer->get_aov(),
				'total_order_count'     => $this->customer->get_total_order_count(),
				'total_order_value'     => $this->customer->get_total_order_value(),
				'total_purchased_items' => is_array( $purchased_products ) && ! empty( $purchased_products ) ? count( $purchased_products ) : 0,
			);

			if ( true === $slim_data ) {
				return $wc_data;
			}

			/** Get Purchased Products titles */
			$purchased_products            = BWFAN_Common::get_products_name( $purchased_products );
			$purchased_products            = array_map( function ( $product ) {
				return [
					'id'   => ! empty( $product['post_parent'] ) ? $product['post_parent'] : $product['id'],
					'name' => wp_strip_all_tags( $product['name'] )
				];
			}, $purchased_products );
			$wc_data['purchased_products'] = array_values( array_filter( $purchased_products ) );

			/** Get Purchased Products Cats & Tags */
			$purchased_products_cats = array_unique( $this->customer->get_purchased_products_cats() );
			$purchased_products_tags = array_unique( $this->customer->get_purchased_products_tags() );
			$terms                   = BWFAN_Common::get_wp_term( array_merge( $purchased_products_cats, $purchased_products_tags ) );

			$purchased_products_cats = array_filter( $terms, function ( $tags ) use ( $purchased_products_cats ) {
				return in_array( intval( $tags['id'] ), $purchased_products_cats, true );
			} );
			$purchased_products_tags = array_filter( $terms, function ( $tags ) use ( $purchased_products_tags ) {
				return in_array( intval( $tags['id'] ), $purchased_products_tags, true );
			} );

			$purchased_products_cats            = array_values( $purchased_products_cats );
			$purchased_products_tags            = array_values( $purchased_products_tags );
			$wc_data['purchased_products_cats'] = $purchased_products_cats;
			$wc_data['purchased_products_tags'] = $purchased_products_tags;

			/** Coupons and AOV data */
			$wc_data['used_coupons'] = $this->customer->get_used_coupons();
			$wc_data['aov_store']    = BWFAN_Common::get_store_aov();

			if ( $this->customer->get_total_order_count() !== 0 ) {
				$wc_data['aov_contact'] = $this->customer->get_aov();
			} else {
				$wc_data['aov_contact'] = 0;
			}

			/** Tags and Lists */

			return $wc_data;
		}

		/** get subscriptions details of contact
		 *
		 * @return array
		 */
		public function get_wcs_as_array() {
			if ( ! bwfan_is_autonami_pro_active() ) {
				return [];
			}
			$contact_wpid = $this->contact->get_wpid();

			if ( 0 === absint( $contact_wpid ) ) {
				return array();
			}

			$user_total_renewal          = 0;
			$user_total_renewal_amount   = 0;
			$user_renewal_dates          = array();
			$user_next_renewal_date      = '';
			$user_next_renewal_amount    = 0;
			$user_active_subscriptions   = 0;
			$user_inactive_subscriptions = 0;
			$user_subscriptions          = array();
			if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
				$user_subscriptions = wcs_get_users_subscriptions( $contact_wpid );
			}

			if ( empty( $user_subscriptions ) ) {
				return array();
			}

			foreach ( $user_subscriptions as $subscription_id => $subscriptions ) {
				if ( 'active' === $subscriptions->get_status() ) {
					$user_active_subscriptions ++;
					$user_next_renewal_date = $subscriptions->get_date( 'next_payment' );
					if ( ! empty( $user_next_renewal_date ) ) {
						$user_renewal_dates[ $subscription_id ] = $user_next_renewal_date;
					}
					$user_renewal_order = $subscriptions->get_related_orders( 'ids', array( 'renewal', 'resubscribe' ) );
				} else {
					$user_inactive_subscriptions ++;
				}
			}

			/** getting contact total renewal and total renewal amount */
			if ( ! empty( $user_renewal_order ) ) {
				$paid_statuses = wc_get_is_paid_statuses();
				foreach ( $user_renewal_order as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( ! $order instanceof WC_ORDER || ! $order->has_status( $paid_statuses ) ) {
						continue;
					}
					$user_total_renewal ++;
					$order_total               = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $order->get_total(), BWF_WC_Compatibility::get_order_currency( $order ) );
					$user_total_renewal_amount += $order_total;
				}
			}

			/** getting contact next renewal date and next renewal amount */
			if ( ! empty( $user_renewal_dates ) ) {
				uasort( $user_renewal_dates, function ( $date1, $date2 ) {
					return ( strtotime( $date1 ) <=> strtotime( $date2 ) );
				} );

				foreach ( $user_renewal_dates as $subscription_id => $subscription_dates ) {
					$subscription = wcs_get_subscription( $subscription_id );

					if ( ! $subscription instanceof WC_Subscription ) {
						break;
					}

					$user_next_renewal_amount = self::get_next_renewal_amount( $subscription );
					$user_next_renewal_date   = $subscription_dates;
					break;
				}
			}

			$user_subscriptions_data['active_subscriptions']   = $user_active_subscriptions;
			$user_subscriptions_data['inactive_subscriptions'] = $user_inactive_subscriptions;
			$user_subscriptions_data['next_renewal_date']      = $user_next_renewal_date;
			$user_subscriptions_data['next_renewal_amount']    = $user_next_renewal_amount;
			$user_subscriptions_data['total_renewal_count']    = $user_total_renewal;
			$user_subscriptions_data['total_renewal_amount']   = number_format( $user_total_renewal_amount, 2 );

			return $user_subscriptions_data;
		}

		/** get offers details of contact
		 *
		 * @return mixed
		 */
		public function get_offer_as_array() {
			$contact_id                     = $this->contact->get_id();
			$contact_offer_data['upsell']   = array();
			$contact_offer_data['bump']     = array();
			$contact_offer_data['checkout'] = array();
			$contact_offer_data['optin']    = array();
			/** checking oder bump activated  */
			if ( class_exists( 'WFOB_Core' ) && bwfan_is_woocommerce_active() ) {
				$contact_offer_data['bump'] = BWFCRM_Model_Contact::get_bump_details( $contact_id );
			}

			/** checking oder bump activated  */
			if ( class_exists( 'WFACP_Core' ) && bwfan_is_woocommerce_active() ) {
				$contact_offer_data['checkout'] = BWFCRM_Model_Contact::get_checkout_details( $contact_id );
			}
			/** checking optin activated */
			if ( function_exists( 'WFOPP_Core' ) ) {
				$contact_offer_data['optin'] = BWFCRM_Model_Contact::get_optin_details( $contact_id );
			}

			/** checking upstroke activated */
			if ( class_exists( 'WFOCU_Core' ) && bwfan_is_woocommerce_active() ) {
				$contact_offer_data['upsell'] = BWFCRM_Model_Contact::get_upstroke_details( $contact_id );
			}

			return $contact_offer_data;
		}

		/**
		 * @param $subscription WC_Subscription
		 *
		 * @return mixed
		 */
		public static function get_next_renewal_amount( $subscription ) {
			$order_total = 0;
			if ( $subscription->get_total() > 0 && '' !== $subscription->get_billing_period() && ! $subscription->is_one_payment() ) {
				$order_total = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $subscription->get_total(), $subscription->get_currency() );
			}

			return $order_total;
		}

		/**
		 * Create WP User
		 *
		 * @param $email
		 * @param string $first_name
		 * @param string $last_name
		 * @param string $password
		 *
		 * @return int|WP_Error
		 */
		public function create_wp_user( $email, $first_name = '', $last_name = '', $password = '', $notify_new_user = false ) {
			if ( ! is_email( $email ) ) {
				return BWFAN_Common::crm_error( __( 'Can\'t create WP User as provided email is not valid', 'wp-marketing-automations' ) );
			}

			$user             = new WP_User();
			$user->user_email = $email;
			$user->user_login = $email;
			$user->first_name = $first_name;
			$user->last_name  = $last_name;
			$user->user_pass  = ! empty( $password ) ? $password : wp_generate_password();

			$user_id = wp_insert_user( $user );
			if ( ! $user_id instanceof WP_Error && true === $notify_new_user ) {
				wp_send_new_user_notifications( $user_id, 'user' );
			}

			return $user_id;
		}

		public function is_contact_exists() {
			return $this->contact instanceof WooFunnels_Contact && $this->contact->get_id() > 0;
		}

		public function is_customer_exists() {
			return $this->customer instanceof WooFunnels_Customer && $this->customer->get_id() > 0;
		}

		public function get_id() {
			return absint( $this->contact->get_id() );
		}

		/**
		 * @return array|array[]|BWFCRM_Lists[]|BWFCRM_Tag[]|BWFCRM_Term[]
		 */
		public function get_tags() {
			$tags = $this->contact->get_tags();
			if ( ! is_array( $tags ) || empty( $tags ) ) {
				return array();
			}

			return $tags;
		}

		/**
		 * @return array|array[]|BWFCRM_Lists[]|BWFCRM_Term[]
		 */
		public function get_lists() {
			$lists = $this->contact->get_lists();
			if ( ! is_array( $lists ) || empty( $lists ) ) {
				return array();
			}

			return $lists;
		}

		/**
		 * Get link triggers IDs
		 *
		 * @return mixed|string
		 */
		public function get_link_triggers() {
			$links = $this->get_field_by_slug( 'link-trigger-click' );
			$links = json_decode( $links, true );

			return $links;
		}

		/**
		 * @param $tags
		 * @param bool $use_cache
		 * @param bool $stop_hooks
		 *
		 * @return BWFCRM_Term[]|WP_Error
		 */
		public function add_tags( $tags, $use_cache = false, $stop_hooks = false, $return_skipped = false ) {
			if ( ! $this->is_contact_exists() ) {
				return BWFAN_Common::crm_error( __( 'Contact doesn\'t exists', 'wp-marketing-automations' ) );
			}

			$tags = $this->set_tags( $tags, $use_cache, $stop_hooks, $return_skipped );
			if ( false === $tags ) {
				return BWFAN_Common::crm_error( __( 'Provided tags are empty / invalid', 'wp-marketing-automations' ) );
			}

			$this->save();

			return $tags;
		}

		public function set_tags( $tags, $use_cache = false, $stop_hooks = false, $return_skipped = false ) {
			if ( ! is_array( $tags ) || empty( $tags ) ) {
				return false;
			}

			$tags     = BWFCRM_Term::get_or_create_terms( $tags, BWFCRM_Term_Type::$TAG, true, false, $use_cache );
			$old_tags = $this->get_tags();

			/** Newly Assigned Tags IDs */
			$tag_ids = array_filter( array_map( function ( $tag ) use ( $old_tags ) {
				return in_array( $tag->get_id(), $old_tags ) ? false : $tag->get_id();
			}, $tags ) );

			$new_tags = array_values( array_unique( array_merge( $old_tags, $tag_ids ) ) );

			$this->contact->set_tags( $new_tags );

			/** To trigger automations we need to have contact save (ie need to get ID) */
			if ( empty( $this->get_id() ) && false === $stop_hooks ) {
				$this->contact->save();
			}

			$assigned_tags = array();
			$skipped       = array();
			foreach ( $tags as $tag ) {
				if ( ! in_array( $tag->get_id(), $tag_ids, true ) ) {
					$skipped[] = $tag;
					continue;
				}
				$assigned_tags[] = $tag;
			}

			if ( false === $stop_hooks && count( $assigned_tags ) > 0 ) {
				$this->assigned_tags = $assigned_tags;
			}

			if ( true === $return_skipped ) {
				return [
					'skipped'  => $skipped,
					'assigned' => $assigned_tags
				];
			}

			return $assigned_tags;
		}

		/**
		 * Set tags on a contact but don't save
		 * Run automations conditional - default run
		 * Version 2
		 * Tags array contains term id only
		 *
		 * @param $tags
		 * @param $stop_hooks
		 *
		 * @return array|bool
		 */
		public function set_tags_v2( $tags = [], $stop_hooks = false ) {
			$this->assigned_tags = [];

			if ( ! is_array( $tags ) || empty( $tags ) ) {
				return false;
			}

			$old_tags = $this->get_tags();

			/** Newly Assigned Tags IDs */
			$new_tags = array_diff( $tags, $old_tags );
			sort( $new_tags );
			if ( empty( $new_tags ) ) {
				/** No tag to update */
				return true;
			}

			$all_tags = array_values( array_unique( array_merge( $old_tags, $new_tags ) ) );

			/** Save tags on a contact */
			$this->contact->set_tags( $all_tags );

			if ( false === $stop_hooks && count( $new_tags ) > 0 ) {
				$this->assigned_tags = BWFAN_Model_Terms::get_term_objects( $new_tags );
			}

			return $all_tags;
		}

		public function remove_tags( $tags ) {
			if ( ! $this->is_contact_exists() ) {
				return array();
			}

			if ( ! is_array( $tags ) || empty( $tags ) ) {
				return array();
			}

			/** get contact tags from db */
			$applied_tags = $this->get_tags();
			$removed_tags = array();

			/** remove tags from the contact $tag */
			foreach ( $tags as $tag ) {
				if ( ! is_string( $tag ) && ! is_numeric( $tag ) ) {
					BWFAN_Common::log_test_data( 'contact id: ' . $this->get_id(), 'remove_tags', true );
					BWFAN_Common::log_test_data( $tags, 'remove_tags', true );
					BWFAN_Common::log_test_data( wp_debug_backtrace_summary(), 'remove_tags', true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- intentional diagnostic logging
					continue;
				}
				$list_key = array_search( strval( $tag ), $applied_tags );
				if ( false === $list_key ) {
					continue;
				}
				unset( $applied_tags[ $list_key ] );
				$removed_tags[] = $tag;
			}

			$diff_array = array_diff( $this->get_tags(), $applied_tags );
			if ( ! empty( $diff_array ) ) {
				$this->contact->set_tags( array_values( $applied_tags ) );
			}

			if ( count( $removed_tags ) > 0 ) {
				$this->removed_tags = $removed_tags;
			}

			return $removed_tags;
		}

		public function add_lists( $lists, $use_cache = false, $stop_hooks = false, $return_skipped = false ) {
			if ( ! $this->is_contact_exists() ) {
				return BWFAN_Common::crm_error( __( 'Contact doesn\'t exists', 'wp-marketing-automations' ) );
			}

			$lists = $this->set_lists( $lists, $use_cache, $stop_hooks, $return_skipped );
			if ( false === $lists ) {
				return BWFAN_Common::crm_error( __( 'Provided lists are empty / invalid', 'wp-marketing-automations' ) );
			}

			$this->save();

			return $lists;
		}

		public function set_lists( $lists, $use_cache = false, $stop_hooks = false, $return_skipped = false ) {
			if ( ! is_array( $lists ) || empty( $lists ) ) {
				return false;
			}

			/** @var BWFCRM_Lists[] $lists */
			$lists     = BWFCRM_Term::get_or_create_terms( $lists, BWFCRM_Term_Type::$LIST, true, false, $use_cache );
			$old_lists = $this->get_lists();

			/** Newly Assigned List IDs */
			$list_ids = array_filter( array_map( function ( $list ) use ( $old_lists ) {
				return in_array( $list->get_id(), $old_lists ) ? false : $list->get_id();
			}, $lists ) );

			$new_lists = array_values( array_unique( array_merge( $old_lists, $list_ids ) ) );
			$this->contact->set_lists( $new_lists );

			/** To trigger automations we need to have contact save (ie need to get ID) */
			if ( empty( $this->get_id() ) && false === $stop_hooks ) {
				$this->contact->save();
			}

			$assigned_lists = array();
			$skipped        = array();
			foreach ( $lists as $list ) {
				if ( ! in_array( $list->get_id(), $list_ids, true ) ) {
					$skipped[] = $list;
					continue;
				}

				$assigned_lists[] = $list;
			}

			if ( false === $stop_hooks && count( $assigned_lists ) > 0 ) {
				$this->assigned_lists = $assigned_lists;
			}

			if ( true === $return_skipped ) {
				return [
					'skipped'  => $skipped,
					'assigned' => $assigned_lists
				];
			}

			return $assigned_lists;
		}

		/**
		 * Set lists on a contact but don't save
		 * Run automations conditional - default run
		 * Version 2
		 * Lists array contains term id only
		 *
		 * @param $lists
		 * @param $stop_hooks
		 *
		 * @return array|bool
		 */
		public function set_lists_v2( $lists, $stop_hooks = false ) {
			$this->assigned_lists = [];

			if ( ! is_array( $lists ) || empty( $lists ) ) {
				return false;
			}

			$old_lists = $this->get_lists();

			/** Newly Assigned Tags IDs */
			$new_lists = array_diff( $lists, $old_lists );
			sort( $new_lists );
			if ( empty( $new_lists ) ) {
				/** No list to update */
				return true;
			}

			$all_lists = array_values( array_unique( array_merge( $old_lists, $new_lists ) ) );

			/** Save lists on a contact */
			$this->contact->set_lists( $all_lists );

			if ( false === $stop_hooks && count( $new_lists ) > 0 ) {
				$this->assigned_lists = BWFAN_Model_Terms::get_term_objects( $new_lists );
			}

			return $all_lists;
		}

		public static function get_contacts( $search, $offset, $limit, $filters = array(), $additional_info = array(), $return_type = ARRAY_N, $update_fields = false ) {
			ob_start();
			/** Send WC Data */
			$wc_data_send         = is_array( $additional_info ) && isset( $additional_info['customer_data'] ) && true === $additional_info['customer_data'];
			$grab_totals          = is_array( $additional_info ) && isset( $additional_info['grab_totals'] ) && true === $additional_info['grab_totals'];
			$customer_count       = is_array( $additional_info ) && isset( $additional_info['customer_count'] ) && true === $additional_info['customer_count'];
			$exclude_unsubs_lists = is_array( $additional_info ) && isset( $additional_info['exclude_unsubs_lists'] ) && true === $additional_info['exclude_unsubs_lists'];

			/** Unsubscribed Lists Filter Handling */
			if ( $exclude_unsubs_lists ) {
				$filters = bwfan_is_autonami_pro_active() ? BWFCRM_Filters::maybe_add_unsubscribe_lists_filter( $filters ) : [];
			}

			if ( bwfan_is_autonami_pro_active() && class_exists( 'BWFCRM_Load_Filters' ) ) {
				$old_filters = BWFCRM_Filters::_normalize_for_new_filters( $filters );
				$filters     = ! empty( $old_filters ) ? $old_filters : $filters;

				/** Get Contacts from DB */
				$contact_data = BWFCRM_Model_Contact::get_contacts_from_filters( $search, $limit, $offset, $filters, $additional_info );
			} else {
				$filters      = bwfan_is_autonami_pro_active() ? BWFCRM_Filters::_normalize_input_filters( $filters ) : [];
				$filter_match = isset( $filters['match'] ) && ! empty( $filters['match'] ) ? $filters['match'] : 'all';
				$filter_match = ( 'any' === $filter_match ? ' OR ' : ' AND ' );

				/** Get Contacts from DB */
				$contact_data = BWFCRM_Model_Contact::get_contacts( $search, $limit, $offset, $filters, $additional_info, $filter_match );
			}

			/** Return WP_Error in-case any WP_Error */
			if ( is_wp_error( $contact_data ) ) {
				return $contact_data;
			}

			/** Return an Empty array on non-array or empty content */
			if ( ! is_array( $contact_data ) || empty( $contact_data ) ) {
				return array(
					'contacts' => array(),
				);
			}
			$total_count = $contact_data['total'];
			$contacts    = $contact_data['contacts'];

			/** Prepare the output */
			$contact_details = array();
			foreach ( $contacts as $contact_db ) {
				$contact = new BWFCRM_Contact( $contact_db, false, array(), $update_fields );
				if ( ! $contact->is_contact_exists() ) {
					continue;
				}

				/** Return data is requested format */
				if ( ARRAY_N === $return_type ) {
					$contact_details[] = $contact->get_array( true, $wc_data_send );
				} elseif ( ARRAY_A === $return_type ) {
					$contact_details[ $contact_db['id'] ] = $contact->get_array( true, $wc_data_send );
				} else {
					$contact_details[] = $contact;
				}
			}

			if ( in_array( $return_type, array( ARRAY_A, ARRAY_N ) ) ) {
				$contact_details = self::add_terms_to_contacts_details( $contact_details );
			}

			ob_end_flush();

			$return_data = array(
				'contacts' => $contact_details,
			);

			if ( true === $grab_totals ) {
				$return_data['total_count'] = $total_count;
				if ( $customer_count ) {
					$return_data['customer_count'] = $contact_data['customer_count'] ?? 0;
					$return_data['total_revenue']  = $contact_data['total_revenue'] ?? 0;
				}
			}

			return $return_data;
		}

		public static function add_terms_to_contacts_details( $contact_details ) {
			if ( ! is_array( $contact_details ) || empty( $contact_details ) ) {
				return $contact_details;
			}

			$contact_tags  = array();
			$contact_lists = array();
			foreach ( $contact_details as $contact ) {
				if ( ! is_array( $contact ) || ( ! isset( $contact['tags'] ) && ! isset( $contact['lists'] ) ) ) {
					continue;
				}

				if ( isset( $contact['tags'] ) && ! empty( $contact['tags'] ) ) {
					if ( is_array( $contact['tags'] ) ) {
						$contact_tags[ absint( $contact['id'] ) ] = array_map( 'absint', $contact['tags'] );
					}
				}

				if ( isset( $contact['lists'] ) && ! empty( $contact['lists'] ) ) {
					if ( is_array( $contact['lists'] ) ) {
						$contact_lists[ absint( $contact['id'] ) ] = array_map( 'absint', $contact['lists'] );
					}
				}
			}

			$tags  = ! empty( $contact_tags ) ? call_user_func_array( 'array_merge', $contact_tags ) : array();
			$lists = ! empty( $contact_lists ) ? call_user_func_array( 'array_merge', $contact_lists ) : array();

			$terms = array_map( 'absint', array_merge( $tags, $lists ) );
			$terms = BWFAN_Model_Terms::get_terms( 0, 0, 0, '', $terms );

			$terms_with_keys = array();
			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) || ! isset( $term['ID'] ) ) {
					continue;
				}

				$terms_with_keys[ absint( $term['ID'] ) ] = $term;
			}

			$contact_details = array_map( function ( $contact ) use ( $terms_with_keys, $contact_tags, $contact_lists ) {
				if ( ! is_array( $contact ) || ! isset( $contact['id'] ) ) {
					return false;
				}

				$contact_id      = absint( $contact['id'] );
				$contact['tags'] = array();
				if ( isset( $contact_tags[ $contact_id ] ) && is_array( $contact_tags[ $contact_id ] ) ) {
					$tags = $contact_tags[ $contact_id ];
					foreach ( $tags as $tag_id ) {
						if ( ! isset( $terms_with_keys[ $tag_id ] ) || ! is_array( $terms_with_keys[ $tag_id ] ) || $terms_with_keys[ $tag_id ]['type'] != 1 ) {
							continue;
						}

						$contact['tags'][] = $terms_with_keys[ $tag_id ];
					}
				}

				$contact['lists'] = array();
				if ( isset( $contact_lists[ $contact_id ] ) && is_array( $contact_lists[ $contact_id ] ) ) {
					$lists = $contact_lists[ $contact_id ];
					foreach ( $lists as $list_id ) {
						if ( ! isset( $terms_with_keys[ $list_id ] ) || ! is_array( $terms_with_keys[ $list_id ] ) || $terms_with_keys[ $list_id ]['type'] != 2 ) {
							continue;
						}

						$contact['lists'][] = $terms_with_keys[ $list_id ];
					}
				}

				return $contact;
			}, $contact_details );

			return array_filter( $contact_details );
		}

		public function get_conversations( $mode, $offset = 0, $limit = 0 ) {
			if ( ! class_exists( 'BWFAN_Email_Conversations' ) || ! isset( BWFAN_Core()->conversations ) || ! BWFAN_Core()->conversations instanceof BWFAN_Email_Conversations ) {
				return BWFAN_Common::crm_error( 'Conversations module not found' );
			}

			return BWFAN_Core()->conversations->get_conversations_by_cid( $this->get_id(), $mode, $offset, $limit );
		}

		public function get_conversation_email( $con_id = 0 ) {
			if ( ! class_exists( 'BWFAN_Email_Conversations' ) || ! isset( BWFAN_Core()->conversations ) || ! BWFAN_Core()->conversations instanceof BWFAN_Email_Conversations ) {
				return BWFAN_Common::crm_error( 'Conversations module not found' );
			}

			if ( ! empty( $con_id ) ) {
				return BWFAN_Core()->conversations->get_conversation_email( $con_id );
			}

			$cons = BWFAN_Model_Engagement_Tracking::get_conversations_by_cid( $this->get_id(), 0 );
			if ( ! is_array( $cons ) || empty( $cons ) || ! is_array( $cons[0] ) || ! isset( $cons[0]['ID'] ) ) {
				return BWFAN_Common::crm_error( 'No Conversation for this email found' );
			}

			return BWFAN_Core()->conversations->get_conversation_email( $cons[0]['ID'] );
		}

		public function get_conversations_total( $mode ) {

			return BWFAN_Model_Engagement_Tracking::get_total_engagements( $this->get_id(), $mode );
		}

		public function remove_lists( $lists ) {

			if ( ! $this->is_contact_exists() ) {
				return BWFAN_Common::crm_error( __( 'Contact doesn\'t exists', 'wp-marketing-automations' ) );
			}

			if ( ! is_array( $lists ) || empty( $lists ) ) {
				return BWFAN_Common::crm_error( __( 'Provided Lists are empty / invalid', 'wp-marketing-automations' ) );
			}

			$applied_lists = $this->get_lists();
			$removed_lists = array();
			/** remove lists from the contact $lists */
			foreach ( $lists as $list ) {
				$list_key = array_search( $list, $applied_lists );
				if ( false === $list_key ) {
					continue;
				}
				$removed_lists[] = $list;
				unset( $applied_lists[ $list_key ] );
			}

			$diff_array = array_diff( $this->get_lists(), $applied_lists );
			if ( ! empty( $diff_array ) ) {
				$this->contact->set_lists( array_values( $applied_lists ) );
				$this->contact->save();
			}

			if ( count( $removed_lists ) > 0 ) {
				$this->removed_lists = $removed_lists;
			}

			return $removed_lists;
		}

		/**
		 * Update/add custom field to contact meta
		 *
		 * @param $fields
		 *
		 * @return bool
		 */
		public function update_custom_fields( $fields ) {
			if ( empty( $fields ) ) {
				return false;
			}

			return false !== $this->update( $fields );
		}

		/**
		 *  update contact info
		 */

		public function update_contact( $contact_details ) {
			$this->contact->db_operations->update_contact( $contact_details );
		}

		/**
		 * Improved Update
		 *
		 * @param $args
		 *
		 * @return mixed|WooFunnels_Contact|null
		 */
		public function update( $args ) {
			if ( ! $this->is_contact_exists() || ! is_array( $args ) ) {
				return false;
			}

			$result = $this->set_data( $args );
			if ( false === $result ) {
				return false;
			}

			$this->save();
			if ( true === $result['fields_changed'] ) {
				$this->save_fields();
			}

			/** Fire hooks on field update */ // isset( $args['f_name'] ) && ( $f_name !== $args['f_name'] ) && $this->fire_hook_field_updated( 'f_name', $args['f_name'], $f_name, $this->get_id() );
			// isset( $args['l_name'] ) && ( $l_name !== $args['l_name'] ) && $this->fire_hook_field_updated( 'l_name', $args['l_name'], $l_name, $this->get_id() );
			// isset( $args['state'] ) && ( $state !== $args['state'] ) && $this->fire_hook_field_updated( 'state', $args['state'], $state, $this->get_id() );
			// isset( $args['country'] ) && ( $country !== $args['country'] ) && $this->fire_hook_field_updated( 'country', $args['country'], $country, $this->get_id() );
			// isset( $args['contact_no'] ) && ( $contact_no !== $args['contact_no'] ) && $this->fire_hook_field_updated( 'contact_no', $args['contact_no'], $contact_no, $this->get_id() );

			return $this->contact;
		}

		public function set_data( $args, $cid = 0 ) {
			if ( empty( $args ) || ! is_array( $args ) ) {
				return false;
			}

			if ( ! $this->contact instanceof WooFunnels_Contact ) {
				if ( ! empty( $cid ) ) {
					$bwf_contacts  = BWF_Contacts::get_instance();
					$this->contact = $bwf_contacts->get_contact_by( 'id', absint( $cid ) );
				} else {
					$email         = isset( $args['email'] ) && is_email( $args['email'] ) ? $args['email'] : null;
					$this->contact = new WooFunnels_Contact( 0, $email );
				}
			}

			// if ( ! is_email( $this->contact->email ) ) {
			// $email = isset( $args['email'] ) && is_email( $args['email'] ) ? $args['email'] : null;
			// $this->contact->set_email( $email );
			// }

			$contact_cols = array( 'email', 'f_name', 'l_name', 'state', 'country', 'contact_no', 'timezone', 'creation_date' );

			$this->contact->blank_values_update = true;
			foreach ( $contact_cols as $cols ) {
				if ( ! isset( $args[ $cols ] ) ) {
					continue;
				}
				$value = $args[ $cols ];

				/** Get formatted creation date */
				if ( 'creation_date' === $cols ) {
					$value = self::get_date_value( $args['creation_date'], 'Y-m-d H:i:s' );
				}

				/** Get country code */
				if ( function_exists( 'bwf_get_countries_data' ) && 'country' === $cols && strlen( $value ) > 2 ) {
					$countries = bwf_get_countries_data();
					$country   = in_array( ucwords( $value ), $countries ) ? array_search( ucwords( $value ), $countries ) : false;
					$value     = ! empty( $country ) ? $country : '';
					if ( empty( $value ) ) {
						continue;
					}
				}

				call_user_func_array( array( $this->contact, 'set_' . $cols ), array( $value ) );
			}
			$this->contact->blank_values_update = false;

			$old_status = absint( $this->contact->get_status() );
			if ( isset( $args['status'] ) ) {
				$new_status = absint( $args['status'] );
				$this->contact->set_status( $new_status );

				/** If old and new status are same, but contact was unsubscribed, then he/she is subscribed now */
				if ( 1 === $old_status && 1 === $new_status && true === $this->was_unsubscribed ) {
					$this->contact->is_subscribed = true;
				}
			}

			/** If Disable Events, then turn is_subscribed = false */
			if ( isset( $args['disable_events'] ) && true === $args['disable_events'] && isset( $this->contact->is_subscribed ) ) {
				$this->contact->is_subscribed = false;
			}

			if ( isset( $args['wp_id'] ) ) {
				! empty( $args['wp_id'] ) && $this->contact->set_wpid( absint( $args['wp_id'] ) );
			}
			if ( isset( $args['points'] ) ) {
				! empty( $args['points'] ) && $this->contact->set_points( absint( $args['points'] ) );
			}

			$contact_cols = array( 'email', 'f_name', 'l_name', 'contact_no', 'state', 'country', 'creation_date', 'timezone', 'status', 'source', 'points', 'wp_id', 'disable_events' );
			foreach ( $contact_cols as $cols ) {
				if ( isset( $args[ $cols ] ) ) {
					unset( $args[ $cols ] );
				}
			}

			/** Update Meta */
			$fields_changed = false;
			if ( ! empty( $args ) ) {
				$this->set_fields( $args );
				$fields_changed = true;
			}

			return array( 'fields_changed' => $fields_changed );
		}

		public function fire_hook_field_updated( $field_id, $new_value, $old_value, $contact_id ) {
			/**
			 * $field_id - Field ID
			 * $new_value - Field New Value
			 * $old_value - Field Old Value
			 * $contact_id - Contact ID
			 */
			do_action( 'bwfcrm_contact_field_updated', $field_id, $new_value, $old_value, $contact_id );
		}

		public function save_fields() {
			$contact_id = $this->get_id();

			if ( ! is_array( $this->fields ) || 0 === count( $this->fields ) ) {
				return;
			}

			$contact_field = BWF_Model_Contact_Fields::get_contact_field_by_id( $contact_id );

			$field_ids      = array_keys( $this->fields );
			$field_ids      = implode( ',', $field_ids );
			$field_types_db = BWFAN_Model_Fields::get_results( 'SELECT ID, type, meta FROM {table_name} WHERE ID IN (' . $field_ids . ')' );

			$field_types = array();
			$field_meta  = array();
			foreach ( $field_types_db as $type ) {
				$field_types[ absint( $type['ID'] ) ] = absint( $type['type'] );
				$field_meta[ absint( $type['ID'] ) ]  = json_decode( $type['meta'], true );
			}

			$field_array = array();
			$format      = [];
			foreach ( $this->fields as $field_id => $field_value ) {
				$field_value = is_string( $field_value ) ? trim( $field_value ) : $field_value;

				/** Multi-value fields are stored as JSON, so a PHP-serialized string is never a legitimate value; reject it to block object injection on read. */
				if ( is_string( $field_value ) && is_serialized( $field_value ) ) {
					$field_value = '';
				}

				/** Checkbox, Radio and Select field values alter */
				$allowed_types = array(
					BWFCRM_Fields::$TYPE_CHECKBOX,
					BWFCRM_Fields::$TYPE_RADIO,
					BWFCRM_Fields::$TYPE_SELECT
				);
				if ( isset( $field_types[ $field_id ] ) && in_array( $field_types[ $field_id ], $allowed_types, true ) ) {
					$field_value = $this->get_field_values( $field_value, $field_meta[ $field_id ], $field_types[ $field_id ] );

					$field_array[ 'f' . $field_id ] = $field_value;
					$format[]                       = '%s';
					continue;
				}

				if ( is_array( $field_value ) ) {
					continue;
				}

				/** Date field */
				if ( isset( $field_types[ $field_id ] ) && ( absint( $field_types[ $field_id ] ) === BWFCRM_Fields::$TYPE_DATE ) ) {
					/** If date field value is empty */
					if ( empty( $field_value ) ) {
						$field_array[ 'f' . $field_id ] = null;
						$format[]                       = '%s';
						continue;
					}
					$field_value = self::get_date_value( $field_value );
				}


				/** Date time field */
				if ( isset( $field_types[ $field_id ] ) && ( absint( $field_types[ $field_id ] ) === BWFCRM_Fields::$TYPE_DATETIME ) ) {
					/** If the date time field value is empty */
					if ( empty( $field_value ) ) {
						$field_array[ 'f' . $field_id ] = null;
						continue;
					}
					$field_value = self::get_date_value( $field_value, 'Y-m-d H:i:s' );
				}

				/** Time field */
				if ( isset( $field_types[ $field_id ] ) && ( absint( $field_types[ $field_id ] ) === BWFCRM_Fields::$TYPE_TIME ) ) {
					/** If the date time field value is empty */
					if ( empty( $field_value ) ) {
						$field_array[ 'f' . $field_id ] = null;
						continue;
					}
					$field_value = self::get_date_value( $field_value, 'H:i:s' );
				}

				/** Else fields case */
				$field_array[ 'f' . $field_id ] = $field_value;
				$format[]                       = '%s';
			}

			if ( empty( $contact_field ) || ! is_array( $contact_field ) ) {
				$data = array_replace( array( 'cid' => $contact_id ), $field_array );
				BWF_Model_Contact_Fields::insert_ignore( $data, array_merge( [ '%d' ], $format ) );

				return;
			}

			BWF_Model_Contact_Fields::update( $field_array, array( 'cid' => $contact_id ) );

			// foreach ( $field_array as $field => $field_value ) {
			// $field_id = explode( 'f', $field )[1];
			// $this->fire_hook_field_updated( $field_id, $field_value, $this->fields[ $field_id ], $contact_id );
			// }
		}

		private function get_field_values( $values, $meta, $field_type ) {
			if ( empty( $values ) ) {
				return $values;
			}
			$options     = isset( $meta['options'] ) ? $meta['options'] : [];
			$json_values = ! is_array( $values ) ? json_decode( $values, true ) : $values;
			if ( null === $json_values && ! is_array( $values ) ) {
				$values = ( $field_type === BWFCRM_Fields::$TYPE_CHECKBOX ) ? array_map( 'trim', explode( ',', $values ) ) : [ $values ];
			} else {
				$values = is_array( $json_values ) ? $json_values : [ $json_values ];
			}

			$strtolower_options = array_map( function ( $option ) {
				return strtolower( $option );
			}, $options );

			$strtolower_values = array_map( function ( $value ) {
				return strtolower( $value );
			}, $values );

			$new_values = array_intersect( $strtolower_values, $strtolower_options );
			if ( empty( $new_values ) ) {
				return '';
			}

			/** get option value */
			$new_values = array_map( function ( $new_value ) use ( $strtolower_options, $options ) {
				$option_key = array_search( $new_value, $strtolower_options );

				return isset( $options[ $option_key ] ) ? $options[ $option_key ] : '';
			}, $new_values );

			/** return value for radio and dropdown field */
			if ( $field_type !== BWFCRM_Fields::$TYPE_CHECKBOX ) {
				return isset( $new_values[0] ) ? $new_values[0] : '';
			}

			sort( $new_values );

			/** return values for checkbox field */
			return wp_json_encode( $new_values, JSON_UNESCAPED_UNICODE );
		}

		/**
		 * Return always a date
		 * If supplied date value is empty then returns the current date
		 *
		 * @param $value
		 * @param $format
		 *
		 * @return mixed|string|void
		 */
		public static function get_date_value( $value = '', $format = 'Y-m-d' ) {
			if ( empty( $value ) ) {
				return '';
			}

			$value = trim( apply_filters( 'bwfan_modify_date_before_formatting', $value, $format ) );
			$value = ( strpos( $value, "/" ) !== false ) ? str_replace( "/", "-", $value ) : $value;

			try {
				/** Could be timestamp */
				if ( is_numeric( $value ) && ( strtotime( date( 'd-m-Y H:i:s', $value ) ) === (int) $value ) ) { // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Paired with strtotime() re-parse in default tz; gmdate breaks the timestamp check
					/** Valid timestamp */
					return gmdate( $format, $value );
				}

				$new = new DateTime( $value, wp_timezone() );
			} catch ( Error $e ) {
				return '';
			} catch ( Exception $e ) {
				return '';
			}

			if ( ! $new instanceof DateTime ) {
				return '';
			}

			return apply_filters( 'bwfan_contact_date_field_value', $new->format( $format ), $value, $format );
		}

		/**
		 * @param $offset
		 * @param $limit
		 * @param $active
		 *
		 * @return array
		 */
		public function get_subscriptions( $offset = 0, $limit = 10, $active = false ) {
			if ( ! $this->is_contact_exists() || ! bwfan_is_woocommerce_subscriptions_active() ) {
				return array();
			}

			$customer_id = $this->contact->get_wpid();
			if ( empty( $customer_id ) ) {
				$user = get_user_by( 'email', $this->contact->get_wpid() );
				if ( ! $user instanceof WP_User ) {
					return array();
				}

				$customer_id = $user->ID;
			}

			$args = array(
				'subscriptions_per_page' => empty( $offset ) && empty( $limit ) ? - 1 : absint( $limit ),
				'offset'                 => absint( $offset ),
				'customer_id'            => absint( $customer_id ),
				'subscription_status'    => true === $active ? array( 'active' ) : array( 'any' ),
			);

			return wcs_get_subscriptions( $args );
		}

		public function get_orders( $offset = 0, $limit = - 1 ) {
			$offset      = absint( $offset );
			$limit       = intval( $limit );
			$limit_query = '';
			if ( $limit > 0 ) {
				$limit_query = " LIMIT $offset, $limit";
			}

			global $wpdb;

			$query = $wpdb->prepare( "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}
                                WHERE {$wpdb->posts}.post_type = %s AND {$wpdb->posts}.ID in (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_woofunnel_cid' AND meta_value = %s )
                                ORDER BY {$wpdb->posts}.post_date DESC $limit_query", 'shop_order', (string) absint( $this->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- limit/offset cast with absint()/intval()
			if ( method_exists( 'BWF_WC_Compatibility', 'is_hpos_enabled' ) && BWF_WC_Compatibility::is_hpos_enabled() ) {
				$order_table      = $wpdb->prefix . 'wc_orders';
				$order_meta_table = $wpdb->prefix . 'wc_orders_meta';
				$query            = $wpdb->prepare( "SELECT ot.id FROM {$order_table} AS ot JOIN {$order_meta_table} AS otm ON ot.id = otm.order_id WHERE otm.meta_key = '_woofunnel_cid' AND ot.type = 'shop_order' AND otm.meta_value = %s ORDER BY ot.id DESC $limit_query", (string) absint( $this->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- query prepared (a string literal, so the meta_value index is used); tables from $wpdb->prefix; limit values are ints
			}

			/** Both branches above are already prepared; preparing again (non-HPOS) had no placeholders left and raised a notice */
			$contact_orders = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query prepared above in both branches

			if ( empty( $contact_orders ) ) {
				return array();
			}

			return $contact_orders;
		}

		/**
		 * @param int $offset
		 * @param int $limit
		 *
		 * @return array
		 */
		public function get_orders_array( $offset = 0, $limit = 10 ) {
			if ( ! $this->is_contact_exists() ) {
				return array();
			}

			$contact_orders = $this->get_orders( $offset, $limit );

			$contact_order_data = array();
			$co_id              = 0;
			foreach ( $contact_orders as $order ) {
				$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$status = $order->get_status();
				$status = wc_get_order_status_name( $status );

				$contact_order_data['orders'][ $co_id ]['id']         = $order->get_id();
				$order_date                                           = $order->get_date_created();
				$contact_order_data['orders'][ $co_id ]['date']       = ( $order_date instanceof WC_DateTime ) ? ( $order_date->date( 'Y-m-d H:i:s' ) ) : '';
				$contact_order_data['orders'][ $co_id ]['status']     = $status;
				$contact_order_data['orders'][ $co_id ]['first_name'] = $order->get_billing_first_name();
				$contact_order_data['orders'][ $co_id ]['last_name']  = $order->get_billing_last_name();
				$contact_order_data['orders'][ $co_id ]['total']      = $order->get_total();
				$contact_order_data['orders'][ $co_id ]['item_count'] = $order->get_item_count();
				$order_items                                          = $order->get_items();
				$contact_order_data['orders'][ $co_id ]['items']      = [];

				foreach ( $order_items as $item ) {
					$product_id                                                     = $item->get_product_id(); // the Product id
					$contact_order_data['orders'][ $co_id ]['items'][ $product_id ] = $item->get_name();
				}

				$co_id ++;

			}

			return $contact_order_data;
		}

		public function get_orders_count() {
			$contact_orders_ids = $this->get_orders();

			return count( $contact_orders_ids );
		}

		/**
		 * @param $offset
		 * @param $limit
		 *
		 * @return array
		 */
		public function get_subscriptions_array( $offset = 0, $limit = 10 ) {
			if ( ! bwfan_is_autonami_pro_active() ) {
				return [];
			}
			$contact_subscriptions = self::get_subscriptions( $offset, $limit );
			if ( empty( $contact_subscriptions ) ) {
				return array();
			}

			$contact_subscription_data = array();
			$co_id                     = 0;
			$wcs_statuses              = wcs_get_subscription_statuses();
			foreach ( $contact_subscriptions as $subscriptions ) {
				if ( ! $subscriptions instanceof WC_Subscription ) {
					continue;
				}

				$contact_subscription_data['subscriptions'][ $co_id ]['id']                = $subscriptions->get_id();
				$subscriptions_date                                                        = $subscriptions->get_date_created();
				$contact_subscription_data['subscriptions'][ $co_id ]['date']              = ( $subscriptions_date instanceof WC_DateTime ) ? ( $subscriptions_date->date( 'Y-m-d H:i:s' ) ) : '';
				$contact_subscription_data['subscriptions'][ $co_id ]['status']            = $wcs_statuses[ 'wc-' . $subscriptions->get_status() ];
				$contact_subscription_data['subscriptions'][ $co_id ]['status_arr']        = [
					'label' => $wcs_statuses[ 'wc-' . $subscriptions->get_status() ],
					'value' => 'wc-' . $subscriptions->get_status()
				];
				$contact_subscription_data['subscriptions'][ $co_id ]['first_name']        = $subscriptions->get_billing_first_name();
				$contact_subscription_data['subscriptions'][ $co_id ]['last_name']         = $subscriptions->get_billing_last_name();
				$contact_subscription_data['subscriptions'][ $co_id ]['total']             = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $subscriptions->get_total(), $subscriptions->get_currency() );
				$contact_subscription_data['subscriptions'][ $co_id ]['item_count']        = $subscriptions->get_item_count();
				$contact_subscription_data['subscriptions'][ $co_id ]['next_renewal_date'] = $subscriptions->get_date( 'next_payment' );

				$user_renewal_order                                                    = $subscriptions->get_related_orders( 'ids', array( 'renewal', 'resubscribe' ) );
				$contact_subscription_data['subscriptions'][ $co_id ]['total_renewal'] = BWFAN_Common::get_paid_orders_count( $user_renewal_order );

				$subscriptions_items = $subscriptions->get_items();

				foreach ( $subscriptions_items as $item ) {

					$product_id   = $item->get_product_id(); // the Product id
					$variation_id = $item->get_variation_id();

					if ( ! empty( $variation_id ) ) {
						$contact_subscription_data['subscriptions'][ $co_id ]['items'][ $variation_id ] = $item->get_name();
					} else {
						$contact_subscription_data['subscriptions'][ $co_id ]['items'][ $product_id ] = $item->get_name();
					}
				}

				$co_id ++;

			}

			return $contact_subscription_data;
		}

		public function get_total_subscriptions() {
			if ( ! $this->is_contact_exists() || ! bwfan_is_woocommerce_subscriptions_active() ) {
				return 0;
			}

			$customer_id = $this->contact->get_wpid();
			if ( empty( $customer_id ) ) {
				$user = get_user_by( 'email', $this->contact->get_wpid() );
				if ( ! $user instanceof WP_User ) {
					return 0;
				}

				$customer_id = $user->ID;
			}

			$subs = WCS_Customer_Store::instance()->get_users_subscription_ids( absint( $customer_id ) );

			return is_array( $subs ) ? count( $subs ) : 0;
		}

		/**
		 * @return array
		 */
		public function get_automation_array() {
			$db_automations = BWFCRM_Model_Contact::get_automations( $this->get_id() );

			$contact_automation_data = array();
			foreach ( $db_automations as $a_key => $contact_automation ) {
				$automation_id                                 = $contact_automation['automation_id'];
				$contact_automation_data[ $a_key ]['aid']      = $contact_automation['automation_id'];
				$contact_automation_data[ $a_key ]['run_time'] = gmdate( 'F j, Y', $contact_automation['time'] );
				$automation_title                              = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'title' );
				$contact_automation_data[ $a_key ]['name']     = false === $automation_title ? 'No Title' : $automation_title;

			}

			return array_filter( array_values( $contact_automation_data ) );
		}

		/**
		 * @return array
		 */
		public function get_tasks_array() {
			$automations_obj = new BWFCRM_Automations();

			return $automations_obj->get_contact_tasks( $this->get_id() );
		}

		public function get_carts_array() {
			$automations_obj         = new BWFCRM_Automations();
			$contact_email           = $this->contact->get_email();
			$automation_obj          = new BWFCRM_Automations();
			$contact_carts           = $automations_obj->get_contact_carts( $contact_email );
			$contact_recovered_carts = self::get_carts_recovered_array();
			$contact_carts           = array_merge( $contact_carts, $contact_recovered_carts );

			uasort( $contact_carts, function ( $item1, $item2 ) {
				return strtotime( $item2['created_time'] ) - strtotime( $item1['created_time'] );
			} );

			$contact_carts = array_filter( array_values( $contact_carts ) );

			foreach ( $contact_carts as $cart_key => $cart ) {
				$contact_carts[ $cart_key ]['created_time'] = $automation_obj->get_abandoned_time( $cart['created_time'] );
			}

			return $contact_carts;
		}

		/**
		 * @return mixed
		 */
		public function get_carts_lost_array() {
			$automations_obj = new BWFCRM_Automations();
			$contact_email   = $this->contact->get_email();

			return $automations_obj->get_contact_lost_carts( $contact_email );
		}

		/**
		 * @return mixed
		 */
		public function get_carts_abandoned_array() {
			$automations_obj = new BWFCRM_Automations();
			$contact_email   = $this->contact->get_email();

			return $automations_obj->get_contact_abandoned_carts( $contact_email );
		}

		/**
		 * @return mixed
		 */
		public function get_carts_recovered_array() {

			$recovered_cart = self::get_contact_recovered_carts();

			if ( empty( $recovered_cart ) ) {
				return array();
			}

			$recovered_order = array();
			foreach ( $recovered_cart as $rec_key => $cart_recover ) {
				$order = wc_get_order( $cart_recover['ID'] );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$products    = array();
				$order_items = $order->get_items();
				foreach ( $order_items as $product ) {
					$products[] = $product->get_name();
				}
				$recovered_order[ $rec_key ]['order_id']     = $order->get_id();
				$recovered_order[ $rec_key ]['phone']        = $order->get_billing_phone();
				$recovered_order[ $rec_key ]['email']        = $order->get_billing_email();
				$recovered_order[ $rec_key ]['username']     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$recovered_order[ $rec_key ]['total']        = $order->get_total();
				$recovered_order[ $rec_key ]['items']        = implode( ',', $products );
				$recovered_order[ $rec_key ]['created_time'] = $order->get_date_created()->date( 'Y-m-d H:i:s' );
				$recovered_order[ $rec_key ]['status']       = 'Recovered';

			}

			return $recovered_order;
		}

		public function get_contact_recovered_carts() {
			$automations_obj = new BWFAN_Abandoned_Cart();
			$contact_email   = $this->contact->get_email();

			return $automations_obj->get_contact_recovered_carts( $contact_email );
		}

		/**
		 * @param $notes
		 *
		 * @return false|int
		 */
		public function add_note_to_contact( $notes ) {
			$user_id                    = get_current_user_id();
			$notes_data['cid']          = $this->get_id();
			$notes_data['title']        = isset( $notes['title'] ) ? $notes['title'] : '';
			$notes_data['body']         = isset( $notes['body'] ) ? $notes['body'] : '';
			$notes_data['created_by']   = isset( $notes['created_by'] ) ? $notes['created_by'] : $user_id;
			$notes_data['created_date'] = current_time( 'mysql', 1 );
			$notes_data['date_time']    = isset( $notes['date_time'] ) ? date( 'Y-m-d H:i:s', strtotime( $notes['date_time'] ) ) : current_time( 'mysql', 1 ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Round-trips strtotime($string) in default tz; gmdate would shift the stored note time
			$notes_data['type']         = isset( $notes['type'] ) ? $notes['type'] : 'general';
			$notes_data['private']      = isset( $notes['private'] ) ? $notes['private'] : 0;

			BWFAN_Model_Contact_Note::insert( $notes_data );
			$contact_note_id = BWFAN_Model_Contact_Note::insert_id();
			if ( empty( $contact_note_id ) ) {
				return false;
			}

			/** Create conversation in case admin optin while adding contact note */
			if ( isset( $notes['private'] ) && 1 === absint( $notes['private'] ) ) {
				$this->send_note_conversation( $contact_note_id, $notes_data );
			}

			/** Update last modified */
			$this->save_last_modified();

			return $contact_note_id;
		}

		/**
		 * @return bool
		 */
		public function delete_notes( $note_id ) {
			return BWFCRM_Model_Contact::delete_notes( $this->get_id(), $note_id );
		}

		/**
		 * @param int $offset
		 * @param int $limit
		 *
		 * @return array
		 */
		public function get_contact_notes_array( $offset = 0, $limit = 0 ) {
			$contact_notes = self::get_contact_notes( $offset, $limit );
			if ( empty( $contact_notes ) ) {
				return array();
			}

			return array_map( function ( $contact_note ) {
				if ( isset( $contact_note['created_by'] ) ) {
					$created_user_data                = get_userdata( $contact_note['created_by'] );
					$created_user_name                = ! $created_user_data instanceof WP_USer ? '' : $created_user_data->first_name . ' ' . $created_user_data->last_name;
					$contact_note['created_username'] = $created_user_name;
				}

				if ( isset( $contact_note['modified_by'] ) ) {
					$modified_user_data                = get_userdata( $contact_note['modified_by'] );
					$modified_user_name                = ! $modified_user_data instanceof WP_USer ? '' : $modified_user_data->first_name . ' ' . $modified_user_data->last_name;
					$contact_note['modified_username'] = $modified_user_name;
				}

				if ( isset( $contact_note['created_date'] ) && ! empty( $contact_note['created_date'] ) ) {
					$contact_note['created_date'] = get_date_from_gmt( $contact_note['created_date'] );
				}

				if ( isset( $contact_note['date_time'] ) && ! empty( $contact_note['date_time'] ) ) {
					$contact_note['date_time'] = get_date_from_gmt( $contact_note['date_time'] );
				}

				if ( isset( $contact_note['body'] ) && ! empty( $contact_note['body'] ) ) {
					$action_object = BWFAN_Core()->integration->get_action( 'wp_sendemail' );
					if ( null === $action_object && class_exists( 'BWFAN_Wp_Sendemail' ) ) {
						/** Try to get the instance directly */
						$action_object = BWFAN_Wp_Sendemail::get_instance();
					}
					if ( null !== $action_object ) {
						$contact_note['body'] = $action_object->email_content_v2( [
							'body'     => $contact_note['body'],
							'template' => 1,
						] );
					}
					$dom                  = new DOMDocument;
					$dom->loadHTML( $contact_note['body'] );
					$bodies = $dom->getElementsByTagName( 'body' );
					assert( $bodies->length === 1 );
					$contact_note['body'] = str_replace( 'id="body_content"', 'class="note-body"', $dom->saveHTML( $bodies->item( 0 ) ) );
				}

				return $contact_note;
			}, $contact_notes );
		}

		public function get_contact_notes( $offset = 0, $limit = 0 ) {
			return BWFAN_Model_Contact_Note::get_contact_notes( $this->get_id(), $offset, $limit );
		}

		/**
		 * @param $notes
		 * @param $note_id
		 *
		 * @return bool|int
		 */
		public function update_contact_note( $notes, $note_id ) {
			$notes_data = array();

			if ( ! empty( $notes['title'] ) ) {
				$notes_data['title'] = $notes['title'];
			}

			if ( ! empty( $notes['created_by'] ) ) {
				$notes_data['created_by'] = $notes['created_by'];
			}

			if ( ! empty( $notes['body'] ) ) {
				$notes_data['body'] = $notes['body'];
			}

			if ( ! empty( $notes['date_time'] ) ) {
				$notes_data['date_time'] = date( 'Y-m-d H:i:s', strtotime( $notes['date_time'] ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Round-trips strtotime($string) in default tz; gmdate would shift the stored note time
			}

			if ( ! empty( $notes['type'] ) ) {
				$notes_data['type'] = $notes['type'];
			}

			$notes_data['modified_by']   = $notes['modified_by'];
			$notes_data['modified_date'] = current_time( 'mysql', 1 );
			$notes_data['cid']           = $this->get_id();

			/** Update last modified */
			$this->save_last_modified();

			return BWFAN_Model_Contact_Note::update_contact_note( $this->get_id(), $notes_data, $note_id );
		}

		/**
		 * @return array
		 */
		public function get_basic_array( $context = '' ) {

			if ( ! $this->is_contact_exists() ) {
				return array();
			}

			$contact_array = array(
				'id'         => $this->contact->get_id(),
				'first_name' => $this->contact->get_f_name(),
				'last_name'  => $this->contact->get_l_name(),
				'email'      => $this->contact->get_email(),
			);

			if ( 'terms' === $context ) {
				$contact_array['tags']  = $this->get_tags();
				$contact_array['lists'] = $this->get_lists();
			}

			return $contact_array;
		}

		/**
		 * @return array
		 */
		public function get_contact_funnels_array() {
			$contact_funnels = self::get_contact_funnels();

			return $contact_funnels;
		}

		/**
		 * @return array
		 */
		public function get_contact_funnels() {
			$contact_id    = $this->get_id();
			$funnel_object = new BWFAN_Funnels();

			return $funnel_object->get_contact_funnels( $contact_id );
		}

		public function get_contact_checkout_array() {
			$contact_id    = $this->get_id();
			$funnel_object = new BWFAN_Funnels();

			return $funnel_object->get_contact_checkout( $contact_id );
		}

		public function get_contact_order_bump_array() {
			$contact_id    = $this->get_id();
			$funnel_object = new BWFAN_Funnels();

			return $funnel_object->get_contact_bump( $contact_id );
		}


		public function get_contact_optin_array() {
			$contact_id    = $this->get_id();
			$funnel_object = new BWFAN_Funnels();

			return $funnel_object->get_contact_optin( $contact_id );
		}

		public function get_contact_upsell_array() {
			$contact_id    = $this->get_id();
			$funnel_object = new BWFAN_Funnels();

			return $funnel_object->get_contact_upsell( $contact_id );
		}

		/** adding conversation for contact notes  */
		public function send_note_conversation( $note_id, $note_data ) {

			/** @var  $global_email_settings BWFAN_Common settings */
			$global_email_settings = BWFAN_Common::get_global_settings();

			$email = $this->contact->get_email();

			$message   = BWFAN_Common::decode_merge_tags( wpautop( $note_data['body'] ) );
			$author_id = get_current_user_id();

			/** Create Engagement */
			$conversation    = BWFAN_Core()->conversation->create_campaign_conversation( $this, $note_id, 0, $author_id, BWFAN_Email_Conversations::$MODE_EMAIL, true, array(
				'subject'  => $note_data['title'],
				'template' => $message,
			), BWFAN_Email_Conversations::$TYPE_NOTE );
			$conversation_id = isset( $conversation['conversation_id'] ) ? $conversation['conversation_id'] : 0;

			if ( class_exists( 'BWFAN_Message' ) ) {
				$message_obj = new BWFAN_Message();
				$message_obj->set_message( 0, $conversation_id, $note_data['title'], $message );
				$message_obj->save();
			}

			$from_email     = $global_email_settings['bwfan_email_from'];
			$from_name      = $global_email_settings['bwfan_email_from_name'];
			$reply_to_email = $global_email_settings['bwfan_email_reply_to'];

			$headers   = array();
			$headers[] = 'MIME-Version: 1.0';
			if ( ! empty( $from_name ) && ! empty( $from_email ) ) {
				$headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
			}
			$headers[] = 'Content-type:text/html;charset=UTF-8';

			if ( ! empty( $reply_to_email ) ) {
				$headers[] = BWFAN_Common::build_reply_to_header( $reply_to_email, '', $from_name );
			}

			/** Set unsubscribe link in header */
			$unsubscribe_link = BWFAN_Common::get_unsubscribe_link( [ 'uid' => $this->contact->get_uid() ] );
			if ( ! empty( $unsubscribe_link ) ) {
				$headers[] = "List-Unsubscribe: <$unsubscribe_link>";
				$headers[] = "List-Unsubscribe-Post: List-Unsubscribe=One-Click";
			}

			$email_subject = BWFAN_Core()->conversation->prepare_email_subject( $note_data['title'], array() );

			try {
				BWFAN_Core()->conversation->engagement_type = BWFAN_Email_Conversations::$TYPE_NOTE;
				$email_body                                 = BWFAN_Core()->conversation->prepare_email_body( $conversation['conversation_id'], $note_data['cid'], $conversation['hash_code'], 'rich', $message );
			} catch ( Error $e ) {
				BWFAN_Core()->conversation->fail_the_conversation( $conversation_id, $e->getMessage() );

				return false;
			}

			$headers = apply_filters( 'bwfan_email_headers', $headers );

			/** Removed wp mail filters */
			BWFAN_Common::bwf_remove_filter_before_wp_mail();

			$send_email = wp_mail( $email, $email_subject, $email_body, $headers );
			if ( ! $send_email ) {
				BWFAN_Core()->conversation->fail_the_conversation( $conversation_id, __( 'Email not sent', 'wp-marketing-automations' ) );

				return false;
			}

			/** Save the time of last sent engagement **/
			$data = array( 'cid' => $this->contact->get_id() );
			BWFAN_Conversation::save_last_sent_engagement( $data );

			return BWFAN_Core()->conversation->update_conversation_status( $conversation_id, BWFAN_Email_Conversations::$STATUS_SEND );
		}

		/** getting mail error while sending email
		 *
		 * @return array|false
		 */
		public function maybe_get_failed_mail_error() {
			global $phpmailer;

			if ( ! class_exists( '\WPMailSMTP\MailCatcher' ) ) {
				return false;
			}

			if ( ! ( $phpmailer instanceof \WPMailSMTP\MailCatcher ) ) {
				return false;
			}

			$debug_log = get_option( 'wp_mail_smtp_debug', false );
			if ( empty( $debug_log ) || ! is_array( $debug_log ) ) {
				return false;
			}

			return array( 'message' => $debug_log[0] );
		}

		public function check_contact_unsubscribed( $single_row = true ) {
			$email      = $this->contact->get_email();
			$contact_no = $this->contact->get_contact_no();
			$data       = array(
				'recipient' => array( $email, $contact_no ),
			);

			return BWFAN_Model_Message_Unsubscribe::get_message_unsubscribe_row( $data, $single_row );
		}

		/**
		 * @return mixed
		 */
		public function get_last_abandoned() {
			$automations_obj = new BWFAN_Abandoned_Cart();
			$contact_email   = $this->contact->get_email();

			return $automations_obj->get_last_abandoned_cart( $contact_email );
		}

		/**
		 * @param $offset
		 * @param $limit
		 * @param $additional_info
		 *
		 * @return array[]
		 */
		public static function get_recent_contacts( $offset, $limit, $additional_info ) {
			$wc_data_send = is_array( $additional_info ) && isset( $additional_info['customer_data'] ) && true === $additional_info['customer_data'];
			$contacts     = BWFCRM_Model_Contact::get_recent_contacts( $limit, $offset, $wc_data_send );
			$total_count  = $contacts['total'];
			$contacts     = $contacts['contacts'];

			/** Prepare the output */
			$contact_details = array();
			foreach ( $contacts as $contact_db ) {
				$contact = new BWFCRM_Contact( $contact_db, false );
				if ( ! $contact->is_contact_exists() ) {
					continue;
				}

				/** Return data is requested format */

				$contact_details[] = $contact->get_array( true, $wc_data_send );
			}

			$return_data = array(
				'contacts' => $contact_details,
			);

			$return_data['total_count'] = $total_count;

			return $return_data;
		}

		/**
		 * @param $limit
		 * @param $offset
		 * @param $additional_info
		 *
		 * @return array[]
		 */
		public static function get_recent_unsubscribers( $limit, $offset, $additional_info ) {
			$wc_data_send = is_array( $additional_info ) && isset( $additional_info['customer_data'] ) && true === $additional_info['customer_data'];
			$contacts     = BWFCRM_Model_Contact::get_recent_unsubscribers( $limit, $offset, $wc_data_send );
			$total_count  = $contacts['total'];
			$contacts     = $contacts['contacts'];
			/** Prepare the output */
			$contact_details = array();
			foreach ( $contacts as $contact_db ) {
				$contact = new BWFCRM_Contact( $contact_db, false );
				if ( ! $contact->is_contact_exists() ) {
					continue;
				}

				/** Return data is requested format */

				$contact_details[] = $contact->get_array( true, $wc_data_send );
			}

			$return_data = array(
				'contacts' => $contact_details,
			);

			$return_data['total_count'] = $total_count;

			return $return_data;
		}

		/**
		 * @param $limit
		 * @param $offset
		 * @param $additional_info
		 *
		 * @return array[]
		 */
		public static function get_recent_abandoned( $limit, $offset, $additional_info ) {
			$wc_data_send = is_array( $additional_info ) && isset( $additional_info['customer_data'] ) && true === $additional_info['customer_data'];
			$contacts     = BWFCRM_Model_Contact::get_recent_abandoned( $limit, $offset, $wc_data_send );
			$total_count  = $contacts['total'];
			$contacts     = $contacts['contacts'];
			/** Prepare the output */
			$contact_details = array();
			foreach ( $contacts as $contact_db ) {
				$contact = new BWFCRM_Contact( $contact_db, false );
				if ( ! $contact->is_contact_exists() ) {
					continue;
				}

				/** Return data is requested format */

				$contact_details[] = $contact->get_array( true, $wc_data_send );
			}

			$return_data = array(
				'contacts' => $contact_details,
			);

			$return_data['total_count'] = $total_count;

			return $return_data;
		}

		public function save_last_modified() {
			BWFCRM_Model_Contact::save_last_modified( $this->get_id() );
		}

		public function get_field_by_slug( $slug ) {
			if ( empty( $this->fields ) || ! is_array( $this->fields ) ) {
				return '';
			}

			$db_row = BWFAN_Model_Fields::get_field_by_slug( $slug );
			if ( ! is_array( $db_row ) || ! isset( $db_row['ID'] ) ) {
				return '';
			}

			$meta_id = absint( $db_row['ID'] );

			return isset( $this->fields[ $meta_id ] ) ? $this->fields[ $meta_id ] : '';
		}

		public function set_field_by_slug( $slug, $value ) {
			if ( empty( $this->fields ) || ! is_array( $this->fields ) ) {
				$this->fields = array();
			}

			$db_row = BWFAN_Model_Fields::get_field_by_slug( $slug );
			if ( ! is_array( $db_row ) || ! isset( $db_row['ID'] ) ) {
				return false;
			}

			$meta_id                  = absint( $db_row['ID'] );
			$this->fields[ $meta_id ] = $value;

			return true;
		}

		public function get_address_1() {
			return $this->get_field_by_slug( 'address-1' );
		}

		public function get_address_2() {
			return $this->get_field_by_slug( 'address-2' );
		}

		public function get_city() {
			return $this->get_field_by_slug( 'city' );
		}

		public function get_postcode() {
			return $this->get_field_by_slug( 'postcode' );
		}

		public function get_company() {
			return $this->get_field_by_slug( 'company' );
		}

		public function get_dob() {
			return $this->get_field_by_slug( 'dob' );
		}

		public function get_gender() {
			return $this->get_field_by_slug( 'gender' );
		}

		public function set_address_1( $value ) {
			$this->set_field_by_slug( 'address-1', $value );
		}

		public function set_address_2( $value ) {
			$this->set_field_by_slug( 'address-2', $value );
		}

		public function set_city( $value ) {
			$this->set_field_by_slug( 'city', $value );
		}

		public function set_postcode( $value ) {
			$this->set_field_by_slug( 'postcode', $value );
		}

		public function set_company( $value ) {
			$this->set_field_by_slug( 'company', $value );
		}

		public function set_gender( $value ) {
			$this->set_field_by_slug( 'gender', $value );
		}

		public function set_dob( $value ) {
			$this->set_field_by_slug( 'dob', $value );
		}

		public function get_display_status() {
			if ( ! $this->is_contact_exists() ) {
				return BWFAN_Common::crm_error( __( 'Contact not valid', 'wp-marketing-automations' ) );
			}

			$status                 = absint( $this->contact->get_status() );
			$is_unsubscribed        = $this->check_contact_unsubscribed();
			$this->unsubscribe_date = ! empty( $is_unsubscribed['c_date'] ) ? $is_unsubscribed['c_date'] : '';
			$is_unsubscribed        = is_array( $is_unsubscribed ) && count( $is_unsubscribed ) > 0;

			if ( $is_unsubscribed ) {
				return self::$DISPLAY_STATUS_UNSUBSCRIBED;
			}

			switch ( $status ) {
				case self::$STATUS_BOUNCED:
					return self::$DISPLAY_STATUS_BOUNCED;

				case self::$STATUS_SOFT_BOUNCED:
					return self::$DISPLAY_STATUS_SOFT_BOUNCED;

				case self::$STATUS_COMPLAINT:
					return self::$DISPLAY_STATUS_COMPLAINT;

				case self::$STATUS_NOT_OPTED_IN:
					return self::$DISPLAY_STATUS_UNVERIFIED;

				default:
					return self::$DISPLAY_STATUS_SUBSCRIBED;
			}
		}

		public function resubscribe( $stop_hooks = false ) {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			$to_be_deleted = array( $this->contact->get_email() );
			if ( ! empty( $this->contact->get_contact_no() ) ) {
				$to_be_deleted[] = $this->contact->get_contact_no();
			}

			/**
			 * A contact healed from Bounced (2), Soft Bounced (4) or Complaint (5) must signal downstream
			 * goals (e.g. the "Contact Subscribes" automation goal). Only an already-Subscribed contact
			 * (status 1 and not sitting in the unsubscribe table) should remain a no-op on repeat.
			 */
			$was_subscribed = ( 1 === absint( $this->contact->get_status() ) ) && empty( $this->check_contact_unsubscribed() );

			/**
			 * Signal the transition through the contact object rather than a second do_action here.
			 * is_subscribed is what actually makes WooFunnels_Contact::save() fire
			 * bwfcrm_after_contact_subscribed, and save() clears it as it fires, so routing everything
			 * through that one channel can never double fire. verify() -> set_status(1) arms it by itself
			 * for a real status change; arm it here for a contact that is only unsubscribed by table
			 * (status is already 1, so set_status() will not arm anything). Arming an already armed flag
			 * is a no-op, which is the point: a caller that armed it earlier without saving no longer
			 * results in one fire from save() plus one from here.
			 */
			if ( ! $was_subscribed && ! $stop_hooks ) {
				$this->contact->is_subscribed = true;
			}

			/** Clear the unsubscribe rows before verify(), so the contact is fully restored by the time it signals. */
			$deleted  = BWFAN_Model_Message_Unsubscribe::delete_unsubscribers( $to_be_deleted );
			$verified = $this->verify( $stop_hooks );
			$return   = $deleted && $verified;

			return $return;
		}

		/**
		 * Set unsubscribe context (object_id, step_id, source_type) for source tracking.
		 * @param int $object_id
		 * @param int $step_id
		 * @param int $source_type
		 * @return void
		 */
		public function set_unsubscribe_context( $object_id = 0, $step_id = 0, $source_type = 0 ) {
			$this->unsubscribe_context = [
				'object_id'   => $object_id,
				'step_id'     => $step_id,
				'source_type' => $source_type,
			];
		}

		/**
		 * Get human-readable label for unsubscribe source (c_type).
		 * Use filter bwfan_unsubscribe_source_labels to add more sources.
		 *
		 * @param int $c_type Unsubscribe source type from bwfan_message_unsubscribe.c_type. See UNSUBSCRIBE_SOURCE_* constants.
		 * @return string
		 */
		public static function get_unsubscribe_source_label( $c_type ) {
			$c_type = absint( $c_type );
			$labels = array(
				self::UNSUBSCRIBE_SOURCE_AUTOMATION   => __( 'Automation', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_BROADCAST    => __( 'Broadcast', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_MANUAL       => __( 'Manual', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_FORM         => __( 'Form', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_IMPORTER     => __( 'Importer', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_BULK_ACTION  => __( 'Bulk Action', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_LINK_TRIGGER => __( 'Link Trigger', 'wp-marketing-automations' ),
				self::UNSUBSCRIBE_SOURCE_TRANSACTIONAL_MAIL => __( 'Transactional Mail', 'wp-marketing-automations' ),
			);
			$labels = apply_filters( 'bwfan_unsubscribe_source_labels', $labels );

			return isset( $labels[ $c_type ] ) ? $labels[ $c_type ] : __( 'Manual', 'wp-marketing-automations' );
		}

		/**
		 * Unsubscribe contact. Use set_unsubscribe_context() before calling to pass object_id, step_id, source_type for source tracking.
		 * Backward compatible: when context not set, uses Manual. Pro plugin callers can use set_unsubscribe_context() or call without.
		 *
		 * @param bool $stop_hooks Whether to skip bwfcrm_after_contact_unsubscribed hook.
		 * @return bool
		 */
		public function unsubscribe( $stop_hooks = false ) {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			if ( ! empty( $this->check_contact_unsubscribed() ) ) {
				return true;
			}

			$to_be_added = array( $this->contact->get_email() );
			if ( ! empty( $this->contact->get_contact_no() ) ) {
				$to_be_added[] = $this->contact->get_contact_no();
			}

			$object_id   = 0;
			$step_id     = 0;
			$object_type = self::UNSUBSCRIBE_SOURCE_MANUAL;

			if ( ! empty( $this->unsubscribe_context ) && is_array( $this->unsubscribe_context ) ) {
				$object_id   = isset( $this->unsubscribe_context['object_id'] ) ? absint( $this->unsubscribe_context['object_id'] ) : 0;
				$step_id     = isset( $this->unsubscribe_context['step_id'] ) ? absint( $this->unsubscribe_context['step_id'] ) : 0;
				$object_type = isset( $this->unsubscribe_context['source_type'] ) && 0 < (int) $this->unsubscribe_context['source_type'] ? absint( $this->unsubscribe_context['source_type'] ) : self::UNSUBSCRIBE_SOURCE_MANUAL;
				if ( $object_id > 0 && self::UNSUBSCRIBE_SOURCE_MANUAL === $object_type ) {
					$object_type = self::UNSUBSCRIBE_SOURCE_AUTOMATION;
				}
				$this->unsubscribe_context = array();
			}

			return BWFAN_Model_Message_Unsubscribe::add_unsubscribers( $to_be_added, $object_id, $object_type, $stop_hooks, $step_id );
		}

		public function remove_unsubscribe_status() {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			/** If there are not any entries in Unsubscribe Table, return */
			if ( empty( $this->check_contact_unsubscribed() ) ) {
				return true;
			}

			/** Simply delete the entries */
			$to_be_deleted = array( $this->contact->get_email() );
			if ( ! empty( $this->contact->get_contact_no() ) ) {
				$to_be_deleted[] = $this->contact->get_contact_no();
			}

			BWFAN_Model_Message_Unsubscribe::delete_unsubscribers( $to_be_deleted );

			$this->was_unsubscribed = true;

			return true;
		}

		/**
		 * Mark contact subscribed
		 *
		 * @param bool $stop_hooks Suppress the subscribed action for callers that asked for no events.
		 *
		 * @return bool
		 */
		public function verify( $stop_hooks = false ) {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->contact->set_status( 1 );

			/**
			 * set_status() arms is_subscribed so save() fires bwfcrm_after_contact_subscribed on a genuine
			 * transition into Subscribed. That fire is inside the contact object and out of reach of the
			 * $stop_hooks checks around the callers, so disarm it here instead - otherwise an importer run
			 * with "disable events" still enrolls every healed contact into Contact Subscribes automations.
			 */
			if ( $stop_hooks ) {
				$this->contact->is_subscribed = false;
			}

			$this->save();

			return true;
		}

		public function unverify() {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			/** Remove data from unsubscribe table */
			$this->remove_unsubscribe_status();

			$this->contact->set_status( 0 );
			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->save();

			return true;
		}

		/**
		 * Returns contacts fields
		 *
		 * @param array $fields
		 *
		 * @return array
		 */
		public function get_contact_info_by_fields( $fields = array() ) {
			$data = array();
			if ( empty( $fields ) ) {
				return array();
			}

			$arr1 = array(
				'f_name',
				'l_name',
				'email',
				'contact_no',
				'country',
				'state',
				'creation_date',
			);

			$arr2 = array(
				'tags',
				'lists',
			);

			$arr3 = array(
				'last-open',
				'last-click',
				'last-login',
				'company',
				'gender',
				'dob',
				'address-1',
				'address-2',
				'city',
				'postcode',
			);

			$arr4 = array(
				'total_order_count',
				'total_order_value',
				'f_order_date',
				'l_order_date',
				'purchased_products',
				'purchased_products_cats',
				'purchased_products_tags',
				'used_coupons',
			);

			$arr5 = array(
				'has_purchased',
				'has_used_any_coupons',
				'l_order_days',
				'f_order_days',
				'creation_days',
				'aov'
			);

			$customer_data = $this->get_customer_as_array( false );

			foreach ( $fields as $ed ) {
				$fieldVal = '';

				switch ( true ) {
					case in_array( $ed, $arr1 ):
						$field    = 'get_' . $ed;
						$fieldVal = $this->contact->$field();
						break;

					case in_array( $ed, $arr2 ):
						$field    = 'get_all_' . $ed;
						$fieldVal = $this->$field();
						if ( is_array( $fieldVal ) ) {
							$value = array();
							foreach ( $fieldVal as $val ) {
								$value[] = $val['name'];
							}
							$fieldVal = implode( ',', $value );
						}
						break;

					case in_array( $ed, $arr3 ):
						$fieldVal = $this->get_field_by_slug( $ed );
						break;

					case strpos( $ed, 'bwf_cf' ) !== false:
						$fieldId  = str_replace( 'bwf_cf', '', $ed );
						$fieldVal = isset( $this->fields[ $fieldId ] ) ? $this->fields[ $fieldId ] : '';
						break;

					case in_array( $ed, $arr4 ) && $this->customer:
						$fieldVal = isset( $customer_data[ $ed ] ) ? $customer_data[ $ed ] : '';
						if ( is_array( $fieldVal ) ) {
							$value = array();
							foreach ( $fieldVal as $val ) {
								if ( isset( $val['name'] ) ) {
									$value[] = $val['name'];
								} else {
									$value[] = $val;
								}
							}
							$fieldVal = implode( ',', $value );
						}
						break;

					case in_array( $ed, $arr5 ):
						switch ( $ed ) {
							case 'has_purchased':
								$fieldVal = intval( $customer_data['total_order_count'] ) > 0;
								break;
							case 'has_used_any_coupons':
								$fieldVal = count( $customer_data['used_coupons'] ) > 0;
								break;
							case 'l_order_days':
								$fieldVal = 0;
								if ( isset( $customer_data['l_order_date'] ) ) {
									$fieldVal = self::get_creation_days( self::get_date_value( $customer_data['l_order_date'], 'Y-m-d H:i:s' ) );
								}
								break;
							case 'f_order_days':
								$fieldVal = 0;
								if ( isset( $customer_data['f_order_date'] ) ) {
									$fieldVal = self::get_creation_days( self::get_date_value( $customer_data['f_order_date'], 'Y-m-d H:i:s' ) );
								}
								break;
							case 'creation_days':
								$fieldVal = self::get_creation_days( self::get_date_value( $this->contact->get_creation_date(), 'Y-m-d H:i:s' ) );
								break;
							case 'aov':
								$fieldVal = wc_format_decimal( $customer_data['aov'], wc_get_price_decimals() );
								break;
						}
						break;

					case 'status' === $ed:
						$fieldVal = $this->get_marketing_status();
						break;

					default:
						$fieldVal = '';
						break;
				}

				$data[] = apply_filters( 'bwfan_get_contact_field_by_slug', $fieldVal, $ed, $this );
			}

			return $data;
		}

		/**
		 * Get days difference in two dates
		 */
		public static function get_creation_days( $start ) {
			if ( ! strtotime( $start ) || strtotime( $start ) < 0 ) {
				return 0;
			}
			$start = strtotime( $start );
			$end   = current_time( 'timestamp', 1 );

			return floor( abs( $end - $start ) / 86400 );
		}

		public function save() {
			if ( ! $this->contact instanceof WooFunnels_Contact ) {
				return false;
			}

			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->contact->save();

			/** Fire Tags added */
			if ( ! empty( $this->assigned_tags ) ) {
				$assigned_tags       = $this->assigned_tags;
				$this->assigned_tags = array();
				do_action( 'bwfan_tags_added_to_contact', $assigned_tags, $this );
			}

			/** Fire Tags removed */
			if ( ! empty( $this->removed_tags ) ) {
				$removed_tags       = $this->removed_tags;
				$this->removed_tags = array();
				do_action( 'bwfan_tags_removed_from_contact', $removed_tags, $this );
			}

			/** Fire Lists added */
			if ( ! empty( $this->assigned_lists ) ) {
				$assigned_lists       = $this->assigned_lists;
				$this->assigned_lists = array();
				do_action( 'bwfan_contact_added_to_lists', $assigned_lists, $this );
			}

			/** Fire Lists removed */
			if ( ! empty( $this->removed_lists ) ) {
				$removed_lists       = $this->removed_lists;
				$this->removed_lists = array();
				do_action( 'bwfan_contact_removed_from_lists', $removed_lists, $this );
			}

			return true;
		}

		/**
		 * Get Contacts by Audience id or name
		 * Check if contact is in or not in audience
		 *
		 * @param $audience
		 * @param string $search
		 * @param int $contact_id
		 *
		 * @return array|array[]|string
		 */
		public static function get_contacts_by_audience( $audience, $search = '', $contact_id = 0 ) {
			if ( empty( $audience ) ) {
				return [];
			}

			if ( is_numeric( $audience ) ) {
				/** Get audience by id */
				$audience      = new BWFCRM_Audience( absint( $audience ) );
				$audience_data = ! empty( $audience ) ? $audience->get_array() : [];
			} else {
				/** Get audience by name */
				$audience_data = BWFAN_Model_Terms::get_term_by_name( $audience, 3 );
			}
			/** Return if no audience data found */
			if ( empty( $audience_data ) || ! isset( $audience_data['data'] ) ) {
				return [];
			}

			$data = json_decode( $audience_data['data'], true );

			/** Return if no filters found in audience */
			if ( empty( $data ) || ! isset( $data['filters'] ) ) {
				return [];
			}
			$filters = $data['filters'];

			if ( ! empty( $contact_id ) ) {
				$filters['contact_id_is'] = absint( $contact_id );
			}

			return self::get_contacts( $search, 0, 1, $filters );
		}

		/**
		 * Mark contact unverified
		 *
		 * @return bool
		 */
		public function mark_as_unverified() {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			/** Remove data from unsubscribe table */
			$this->remove_unsubscribe_data();

			$this->contact->set_status( self::$STATUS_NOT_OPTED_IN );
			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->save();

			return true;
		}

		/**
		 * Mark contact bounced
		 *
		 * @param $stop_hooks
		 *
		 * @return bool
		 */
		public function mark_as_bounced( $stop_hooks = false ) {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			/** Check if already bounced */
			$is_already_bounced = null;
			if ( false === $stop_hooks ) {
				$is_already_bounced = self::$STATUS_BOUNCED === absint( $this->contact->get_status() );
			}

			/** save last status in contact meta */
			$this->save_last_status();
			/** Remove data from unsubscribe table */
			$this->remove_unsubscribe_data();
			$this->contact->set_status( self::$STATUS_BOUNCED );
			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->save();
			$this->contact->save_meta();

			/** Run action if contact earlier has a different status */
			if ( false === $is_already_bounced && ! $stop_hooks ) {
				do_action( 'bwfcrm_after_contact_bounced', $this->contact );
			}

			return true;
		}

		/**
		 * Mark contact soft bounced
		 *
		 * @param $stop_hooks
		 *
		 * @return bool
		 */
		public function mark_as_soft_bounced( $stop_hooks = false ) {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			$is_already_soft_bounced = self::$STATUS_SOFT_BOUNCED === absint( $this->contact->get_status() );

			/**
			 * Bail out if the contact is already soft bounced and there is nothing left to reconcile.
			 * Repeat/duplicate bounce webhooks would otherwise re-run the status writes on every
			 * notification. An unsubscribe row still has to be cleared below, since get_display_status()
			 * prioritises it over the status column, so a contact sitting in the unsubscribe table is not
			 * showing as Soft Bounced yet and an explicit status change must still take effect.
			 */
			if ( $is_already_soft_bounced && empty( $this->check_contact_unsubscribed() ) ) {
				return true;
			}

			/** save last status in contact meta */
			$this->save_last_status();
			/** Remove data from unsubscribe table */
			$this->remove_unsubscribe_data();

			/**
			 * Mark contact soft bounced.
			 * A soft bounce no longer counts toward auto-removal. Contacts are only marked bounced on a
			 * definitive (hard) bounce, handled separately by mark_as_bounced(). Removed the legacy
			 * 3-attempt soft-bounce counter that converted soft bounces into hard bounces.
			 */
			$this->contact->set_status( self::$STATUS_SOFT_BOUNCED );
			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->save();
			/** save() only writes the contact row, meta needs its own flush for save_last_status() above */
			$this->contact->save_meta();

			/**
			 * Only a genuine transition into Soft Bounced runs the trigger, mirroring mark_as_bounced()
			 * and mark_as_complaint(). Reaching this point on an already soft bounced contact means the
			 * call was only clearing an unsubscribe row, which is not a new bounce.
			 */
			if ( ! $is_already_soft_bounced && ! $stop_hooks ) {
				do_action( 'bwfcrm_after_contact_soft_bounced', $this->contact );
			}

			return true;
		}

		/**
		 * Mark contact complaint
		 *
		 * @param $stop_hooks
		 *
		 * @return bool
		 */
		public function mark_as_complaint( $stop_hooks = false ) {
			if ( ! $this->is_contact_exists() ) {
				return false;
			}

			/** Check if already bounced */
			$is_already_complaint = null;
			if ( false === $stop_hooks ) {
				$is_already_complaint = self::$STATUS_COMPLAINT === absint( $this->contact->get_status() );
			}

			/** Remove data from unsubscribe table */
			$this->remove_unsubscribe_data();

			$this->contact->set_status( self::$STATUS_COMPLAINT );
			$this->contact->set_last_modified( current_time( 'mysql', 1 ) );
			$this->save();

			/** Run action if contact earlier has a different status */
			if ( false === $is_already_complaint && ! $stop_hooks ) {
				do_action( 'bwfcrm_after_contact_complaint', $this->contact );
			}

			return true;
		}

		/**
		 * Remove contact entry from unsubscribe table
		 * Email and Phone no both.
		 *
		 * @return bool
		 */
		public function remove_unsubscribe_data() {
			$is_unsubscribed = ! empty( $this->check_contact_unsubscribed() );
			if ( empty( $is_unsubscribed ) ) {
				return false;
			}

			$to_be_deleted = [];
			if ( ! empty( $this->contact->get_email() ) ) {
				$to_be_deleted[] = $this->contact->get_email();
			}
			if ( ! empty( $this->contact->get_contact_no() ) ) {
				$to_be_deleted[] = $this->contact->get_contact_no();
			}

			return BWFAN_Model_Message_Unsubscribe::delete_unsubscribers( $to_be_deleted );
		}

		/**
		 * Get contact status to display in the front-end
		 *
		 * @return string
		 */
		public function get_marketing_status() {
			$status = '';
			switch ( $this->get_display_status() ) {
				case BWFCRM_Contact::$DISPLAY_STATUS_UNSUBSCRIBED:
					$status = __( 'Unsubscribed', 'wp-marketing-automations' );
					break;
				case BWFCRM_Contact::$DISPLAY_STATUS_SUBSCRIBED:
					$status = __( 'Subscribed', 'wp-marketing-automations' );
					break;
				case BWFCRM_Contact::$DISPLAY_STATUS_UNVERIFIED:
					$status = __( 'Unverified', 'wp-marketing-automations' );
					break;
				case BWFCRM_Contact::$DISPLAY_STATUS_BOUNCED:
					$status = __( 'Bounced', 'wp-marketing-automations' );
					break;
				case BWFCRM_Contact::$DISPLAY_STATUS_SOFT_BOUNCED:
					$status = __( 'Soft Bounced', 'wp-marketing-automations' );
					break;
				case BWFCRM_Contact::$DISPLAY_STATUS_COMPLAINT:
					$status = __( 'Complaint', 'wp-marketing-automations' );
					break;
			}

			return $status;
		}

		/**
		 * Update status
		 *
		 * @param $status
		 *
		 * @return bool
		 */
		public function update_status( $status ) {
			$res = false;
			switch ( $status ) {
				case 0:
					$res = $this->mark_as_unverified();
					break;
				case 1:
					$res = $this->resubscribe();
					break;
				case 2:
					$res = $this->mark_as_bounced();
					break;
				case 3:
					$res = $this->unsubscribe();
					break;
				case 4:
					$res = $this->mark_as_soft_bounced();
					break;
				case 5:
					$res = $this->mark_as_complaint();
					break;
			}

			return $res;
		}

		/**
		 * Save last status meta for a contact when status is changed to soft bounced or bounced
		 *
		 * @return void
		 */
		public function save_last_status() {
			if ( self::$DISPLAY_STATUS_SOFT_BOUNCED === $this->get_display_status() || self::$DISPLAY_STATUS_BOUNCED === $this->get_display_status() ) {
				return;
			}

			$this->contact->set_meta( 'last_status', [ 'status' => $this->get_display_status(), 'time' => current_time( 'mysql', 1 ) ] );
		}

		/**
		 * Get sublium subscriptions array
		 *
		 * @param int $offset
		 * @param int $limit
		 *
		 * @return array
		 */
		public function get_sublium_subscriptions_array( $offset = 0, $limit = 10 ) {
			if ( ! bwfan_is_sublium_active() ) {
				return [];
			}

			$user_id = $this->contact->get_wpid();
			if ( empty( $user_id ) ) {
				return [];
			}
			$subscriptions_db = new \Sublium_WCS\Includes\database\subscriptions();
			$subscriptions = $subscriptions_db->read( [ 'user_id' => $user_id ], $limit, $offset, 'id', 'DESC' );

			if ( empty( $subscriptions ) ) {
				return array();
			}

			$contact_subscription_data = array();
			$co_id                     = 0;
			$sublium_statuses          = \Sublium_WCS\Includes\Controller\Subscriptions\Subscription::get_default_status();

			foreach ( $subscriptions as $subscription_record ) {
				if ( ! isset( $subscription_record['id'] ) || empty( $subscription_record['id'] ) ) {
					continue;
				}

				$subscription = new \Sublium_WCS\Includes\Controller\Subscriptions\Subscription( $subscription_record['id'] );
				if ( ! $subscription->exists ) {
					continue;
				}

				$subscription_status = $subscription->get_status();
				$status_label        = isset( $sublium_statuses[ $subscription_status ] ) ? $sublium_statuses[ $subscription_status ] : '';

				$contact_subscription_data['subscriptions'][ $co_id ]['id']                = $subscription->get_id();
				$contact_subscription_data['subscriptions'][ $co_id ]['date']              = $subscription->get_created_at();
				$contact_subscription_data['subscriptions'][ $co_id ]['status']            = $status_label;
				$contact_subscription_data['subscriptions'][ $co_id ]['status_arr']        = [
					'label' => $status_label,
					'value' => $subscription_status
				];

				// Get billing name from parent order or meta
				$first_name = '';
				$last_name  = '';
				$parent_order_id = $subscription->get_parent_order_id();
				if ( ! empty( $parent_order_id ) ) {
					$order = wc_get_order( $parent_order_id );
					if ( $order instanceof WC_Order ) {
						$first_name = $order->get_billing_first_name();
						$last_name  = $order->get_billing_last_name();
					}
				}
				if ( empty( $first_name ) || empty( $last_name ) ) {
					$billing_details = $subscription->get_meta( 'billing_details' );
					if ( is_array( $billing_details ) ) {
						$first_name = isset( $billing_details['first_name'] ) ? $billing_details['first_name'] : '';
						$last_name  = isset( $billing_details['last_name'] ) ? $billing_details['last_name'] : '';
					}
				}

				$contact_subscription_data['subscriptions'][ $co_id ]['first_name']        = $first_name;
				$contact_subscription_data['subscriptions'][ $co_id ]['last_name']         = $last_name;
				$contact_subscription_data['subscriptions'][ $co_id ]['total']             = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $subscription->get_totals(), $subscription->get_currency() );

				// Get item count from subscription items (only product items)
				$subscription_items = $subscription->get_subscription_items();
				$item_count        = 0;
				if ( is_array( $subscription_items ) ) {
					foreach ( $subscription_items as $item ) {
						if ( is_array( $item ) && isset( $item['item_type'] ) && (int) $item['item_type'] === 1 ) {
							$item_count++;
						}
					}
				}
				$contact_subscription_data['subscriptions'][ $co_id ]['item_count']         = $item_count;
				$contact_subscription_data['subscriptions'][ $co_id ]['next_renewal_date'] = $subscription->get_next_payment_date();

				// Get renewal count (successful payments)
				$renewal_count = $subscription->get_renewal_count();
				if ( empty( $renewal_count ) ) {
					$renewal_count = $subscription->get_successful_payment_count();
				}
				$contact_subscription_data['subscriptions'][ $co_id ]['total_renewal'] = $renewal_count;

				$co_id ++;
			}

			return $contact_subscription_data;
		}

		/**
		 * Get total sublium subscriptions
		 *
		 * @return int
		 */
		public function get_total_sublium_subscriptions() {
			if ( ! bwfan_is_sublium_active() ) {
				return 0;
			}

			$user_id = $this->contact->get_wpid();
			if ( empty( $user_id ) ) {
				return 0;
			}

			$subscriptions_db = new \Sublium_WCS\Includes\database\subscriptions();

			return $subscriptions_db->count( array( 'user_id' => absint( $user_id ) ) );
		}
	}
}
