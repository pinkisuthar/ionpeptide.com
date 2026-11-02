<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Conversions' ) && BWFAN_Common::is_pro_3_0() ) {
	#[\AllowDynamicProperties]
	class BWFAN_Conversions {
		private static $ins = null;

		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		public function __construct() {
			add_action( 'woocommerce_order_refunded', array( $this, 'remove_conversion' ) );
			add_action( 'woocommerce_order_status_cancelled', array( $this, 'remove_conversion' ) );
			add_action( 'before_delete_post', array( $this, 'remove_conversion_on_deletion' ), 10, 2 );
			add_filter( 'bwfan_get_global_settings', array( $this, 'modify_conversion_def_value_global' ), 10, 1 );
			add_action( 'bwfan_external_event_settings', array( $this, 'bwfan_event_global_tracking_enable' ) );

			add_action( 'bwf_normalize_contact_meta_after_save', array( $this, 'process_order_for_conversion' ), 5, 3 );
		}

		/**
		 * Helper function
		 *
		 * Runs on bwf_normalize_contact_meta_after_save
		 *
		 * @param $bwf_contact WooFunnels_Contact
		 * @param $order_id
		 * @param $order WC_Order
		 */
		public function process_order_for_conversion( $bwf_contact, $order_id, $order ) {
			/** Don't process orders for conversion during the WC Import (Indexing) */
			if ( true === WooFunnels_DB_Updater::$indexing ) {
				return;
			}

			/** If renewal order, don't mark conversion */
			if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
				return;
			}

			/** Check if the Conversion already exists: */
			$existing_conversion = BWFAN_Model_Conversions::get_specific_rows( 'wcid', absint( $order_id ) );
			if ( ! empty( $existing_conversion ) ) {
				return;
			}

			$order_id = absint( $order_id );
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( absint( $order_id ) );
			}

			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				return;
			}

			/** Should skip order conversions */
			$skip = apply_filters( 'bwfan_skip_order_conversions', false, $order_id, $order, $bwf_contact );
			if ( true === $skip ) {
				return;
			}

			$contact_id = $bwf_contact->get_id();
			if ( empty( $contact_id ) ) {
				return;
			}

			/** checking if there is any used $coupons of autonami generated */
			$coupons = $order->get_coupon_codes();
			if ( is_array( $coupons ) && count( $coupons ) > 0 ) {
				$is_conversion = $this->check_coupon_for_conversion( $contact_id, $order_id, $order, $coupons );
				if ( true === $is_conversion ) {
					/** Found used coupon created by automation or broadcast */
					return;
				}
			}

			global $wpdb;
			$global_settings             = BWFAN_Common::get_global_settings();
			$email_order_conversion_days = $global_settings['bwfan_order_tracking_conversion'];
			$email_order_strtotime       = $email_order_conversion_days * DAY_IN_SECONDS;
			$order_creation_date         = $order->get_date_created();
			$order_from                  = ( strtotime( $order_creation_date ) - $email_order_strtotime );
			$order_from                  = gmdate( 'Y-m-d', $order_from );
			$order_to                    = gmdate( 'Y-m-d', strtotime( $order_creation_date ) );

			/** Get automation ids in which don't need to mark conversion */
			$skip_aids = BWFAN_Common::get_skip_conversion_automations();
			$skip_aids = array_map( 'absint', $skip_aids );

			$email_open = '(`mode` = 1 && `open` > 0)';
			if ( false === apply_filters( 'bwfan_conversion_on_email_open', true ) ) {
				$email_open = '(`mode` = 1 && `click` > 0)';
			}

			/**
			 * Fetching conversations where email opened or sms clicked of automation or broadcast within given date range
			 */
			$query_params = array( $order_from, $order_to, $contact_id );
			$not_in_clause = '';
			
			if ( ! empty( $skip_aids ) ) {
				$stringPlaceholders = array_fill( 0, count( $skip_aids ), '%d' );
				$placeholder_skip_aids = implode( ', ', $stringPlaceholders );
				$not_in_clause = " AND ( `oid` NOT IN ($placeholder_skip_aids) )";
				$query_params = array_merge( $query_params, $skip_aids );
			}

			$query   = $wpdb->prepare( "SELECT `ID`, `cid`, `oid`, `type` FROM `{$wpdb->prefix}bwfan_engagement_tracking` WHERE DATE(`created_at`) >= %s AND DATE(`created_at`) <= %s AND ($email_open OR ((`mode` = 2 || `mode` = 3) && `click` > 0)) AND `hash_code` != '' AND `cid` = %d AND `oid` > 0 AND `type` IN (1,2)$not_in_clause ORDER BY `updated_at` DESC LIMIT 0,1", $query_params ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders from array_fill('%d'); args passed as array match count
			$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

			/** No conversations found */
			if ( ! is_array( $results ) || 0 === count( $results ) ) {
				return;
			}

			foreach ( $results as $conversation_data ) {
				$this->add_new( $order, $conversation_data['ID'], $contact_id, $conversation_data['oid'], $conversation_data['type'] );
			}
		}

		/**
		 * @param WC_Order $order
		 * @param $conversation_id
		 * @param $contact_id
		 * @param $source_id
		 * @param $source_type
		 *
		 * @return false|int
		 */
		public function add_new( $order, $conversation_id, $contact_id, $source_id, $source_type = 1 ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return false;
			}

			if ( ! $order instanceof WC_Order || ! absint( $conversation_id ) > 0 || ! absint( $contact_id ) > 0 || ! absint( $source_id ) > 0 ) {
				return false;
			}

			/** checking if conversion enable for automation event */
			if ( 1 === absint( $source_type ) ) {
				$is_conversion_enable = self::is_automation_conversion_track( $source_id );

				if ( ! $is_conversion_enable ) {
					return false;
				}
			}
			if ( in_array( $order->get_status(), array( 'failed', 'pending', 'cancelled', 'refunded' ) ) ) {
				return false;
			}

			$order_base_total = $order->get_meta( '_bwfan_order_total_base' );
			$order_total      = ! empty( $order_base_total ) ? $order_base_total : '';
			if ( empty( $order_total ) ) {
				$order_total = BWF_Plugin_Compatibilities::get_fixed_currency_price_reverse( $order->get_total(), BWF_WC_Compatibility::get_order_currency( $order ) );
			}
			/** @todo order base total needs to calculate and add like we did in abandonment */
			$order_date = $order->get_date_paid();
			$order_date = ! $order_date instanceof WC_DateTime ? $order->get_date_created() : $order_date;
			if ( ! $order_date instanceof WC_DateTime ) {
				$order_date = current_time( 'mysql', 1 );
			} else {
				$order_date->setTimezone( new DateTimeZone( 'UTC' ) );
				$order_date = $order_date->format( 'Y-m-d H:i:s' );
			}
			$data = array(
				'wcid'    => $order->get_id(),
				'cid'     => absint( $contact_id ),
				'trackid' => absint( $conversation_id ),
				'oid'     => absint( $source_id ),
				'otype'   => absint( $source_type ),
				'wctotal' => $order_total,
				'date'    => $order_date
			);

			BWFAN_Model_Conversions::insert( $data );

			return BWFAN_Model_Conversions::insert_id();
		}

		/** checking if settings is on in automations
		 *
		 * @param $automation_id
		 *
		 * @return bool
		 */
		public static function is_automation_conversion_track( $automation_id ) {
			$automation_meta       = BWFAN_Core()->automations->get_automation_data_meta( $automation_id );
			$automation_event_data = isset( $automation_meta['event_meta'] ) && ! empty( $automation_meta['event_meta'] ) ? $automation_meta['event_meta'] : array();

			/** if automation version 2 then create conversion */
			if ( isset( $automation_meta['v'] ) && 2 === absint( $automation_meta['v'] ) ) {
				return true;
			}

			/** when email_open_click not set don't create conversations */
			if ( ! isset( $automation_event_data['conversion_track'] ) || 1 !== absint( $automation_event_data['conversion_track'] ) ) {
				return false;
			}

			return true;
		}

		/**
		 * @param int $order_id
		 */
		public function remove_conversion( $order_id ) {
			if ( empty( $order_id ) ) {
				return;
			}
			$conversions = BWFAN_Model_Conversions::get_specific_rows( 'wcid', $order_id );
			if ( empty( $conversions ) ) {
				return;
			}

			foreach ( $conversions as $conversion ) {
				BWFAN_Model_Conversions::delete( $conversion['ID'] );
			}
		}

		public function modify_conversion_def_value_global( $settings ) {
			if ( ! isset( $settings['bwfan_order_tracking_conversion'] ) || empty( $settings['bwfan_order_tracking_conversion'] ) ) {
				$settings['bwfan_order_tracking_conversion'] = 15;
			}

			return $settings;
		}

		/**
		 * check coupon for conversions
		 *
		 * @param $contact_id
		 * @param $order_id
		 * @param $order
		 * @param $coupons
		 *
		 * @return bool
		 */
		public function check_coupon_for_conversion( $contact_id, $order_id, $order, $coupons ) {
			$order_email = $order->get_billing_email();
			$order_phone = $order->get_billing_phone();

			if ( ! is_array( $coupons ) || 0 === count( $coupons ) ) {
				return false;
			}

			foreach ( $coupons as $coupon ) {
				$coupon_id            = wc_get_coupon_id_by_code( $coupon );
				$coupon_automation_id = absint( get_post_meta( $coupon_id, '_bwfan_automation_id', true ) );
				/** checking if coupon created with automation */
				if ( $coupon_automation_id > 0 ) {
					/** @todo should check tracking id by automation id, if not then any tracking id */
					$track_id = $this->get_tracking_id( $order_email, $order_phone );
					$this->add_new( $order, $track_id, $contact_id, $coupon_automation_id, 1 );

					return true;
				}

				/** checking if coupon created using broadcast */
				$coupon_broadcast_id = absint( get_post_meta( $coupon_id, '_bwfan_broadcast_id', true ) );
				if ( $coupon_broadcast_id > 0 ) {
					$track_id = $this->get_tracking_id( $order_email, $order_phone );
					$this->add_new( $order, $track_id, $contact_id, $coupon_broadcast_id, 2 );

					return true;
				}
			}

			return false;
		}

		public function bwfan_event_global_tracking_enable() {
			?>
            <div class="bwfan-events-email-tracking bwfan-col-sm-12 bwfan-p-0 bwfan-mt-15">
                <#
                conversion_tracking = '';
                if(_.has(bwfan_automation_ui_data_detail.trigger.event_meta, 'conversion_track') ){
                conversion_tracking = 'checked' ;
                }
                #>
                <label class="bwfan-label-title">Conversion Tracking</label>
                <div class="bwfan_email_tracking bwfan-mb-15">
                    <label for="bwfan_email_open">
                        <input type="checkbox" name="event_meta[conversion_track]" id="bwfan_conversion_track" value="1" {{conversion_tracking}}/>
						<?php
						esc_html_e( 'Track Conversions', 'wp-marketing-automations' );
						?>
                    </label>
                </div>
            </div>
			<?php
		}

		/**
		 * Get track id using email, phone and automation id
		 *
		 * @param $order_email
		 * @param $order_phone
		 *
		 * @return int|mixed
		 */
		public static function get_tracking_id( $order_email, $order_phone ) {
			global $wpdb;
			$order_email   = sanitize_email( $order_email );
			$order_phone   = sanitize_text_field( $order_phone );
			$track_query   = $wpdb->prepare( "SELECT ID, oid, type FROM {$wpdb->prefix}bwfan_engagement_tracking WHERE ((mode = 1 AND open > 0) OR (mode = 2 AND click > 0)) AND hash_code != '' AND send_to IN (%s, %s) ORDER BY created_at DESC LIMIT 0, 1", $order_email, $order_phone );
			$track_results = $wpdb->get_results( $track_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
			$track_id      = ! empty( $track_results[0]['ID'] ) ? $track_results[0]['ID'] : 0;

			return $track_id;
		}

		/**
		 * Delete conversion on deletion of order
		 *
		 * @param $order_id
		 * @param $post
		 */
		public function remove_conversion_on_deletion( $order_id, $post ) {
			if ( empty( $order_id ) ) {
				return;
			}

			if ( 'shop_order' !== $post->post_type ) {
				return;
			}

			global $wpdb;

			$query = $wpdb->prepare( "DELETE FROM {table_name} WHERE `wcid` = %d", $order_id );
			BWFAN_Model_Conversions::query( $query );
		}
	}

	BWFAN_Core::register( 'conversions', 'BWFAN_Conversions' );
}