<?php
defined( 'ABSPATH' ) || exit;
if ( ! class_exists( 'BWFAN_Recoverable_Carts' ) ) {
	#[\AllowDynamicProperties]
	class BWFAN_Recoverable_Carts {

		public static function get_abandoned_carts( $search_by = '', $search_term = '', $offset = '', $limit = '', $status = '', $count = false ) {
			global $wpdb;

			if ( empty( $status ) ) {
				$status = '0,1,3,4,5';
			}
			$status = implode( ',', array_map( 'absint', explode( ',', (string) $status ) ) );
			$where  = "WHERE status IN ($status)";
			/** Check for search query */
			if ( ! empty( $search_by ) && ! empty( $search_term ) ) { //phpcs:ignore WordPress.Security.NonceVerification
				$search_term = sanitize_text_field( $search_term ); //phpcs:ignore WordPress.Security.NonceVerification
				$allowed_search_by = array( 'email' );
				$search_by         = in_array( (string) $search_by, $allowed_search_by, true ) ? (string) $search_by : 'email';
				$search_col        = '`' . str_replace( '`', '``', $search_by ) . '`';
				$where       .= $wpdb->prepare( " AND {$search_col} LIKE %s", '%' . $wpdb->esc_like( $search_term ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column whitelisted via in_array strict; term bound via %s
			}
			$result = [];
			if ( $count === false ) {
				$order_by = apply_filters( 'bwfan_cart_listing_order_by', 'last_modified' );
				$result   = BWFAN_Model_Abandonedcarts::get_abandoned_data( $where, $offset, $limit, $order_by );
			}
			$query                 = "SELECT COUNT(*) FROM {$wpdb->prefix}bwfan_abandonedcarts $where";
			$result['total_count'] = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- status absint()ed; search fragment built with prepare()

			return $result;
		}

		/**
		 * Delete abandoned carts
		 *
		 * @param $ids
		 */
		public static function delete_abandoned_cart( $ids ) {
			$not_deleted_cart = [];
			foreach ( $ids as $id ) {
				$cart_details = BWFAN_Model_Abandonedcarts::get( $id );

				if ( empty( $cart_details ) ) {
					$not_deleted_cart [] = $id;
					continue;
				}

				$where = array(
					'ID' => $id,
				);
				BWFAN_Model_Abandonedcarts::delete_abandoned_cart_row( $where );
			}

			$not_deleted_cart = array_filter( array_values( $not_deleted_cart ) );

			do_action( 'bwfan_bulk_delete_abandoned_carts' );

			if ( ! empty( $not_deleted_cart ) ) {
				return $not_deleted_cart;
			}

			return true;
		}

		/**
		 * Retry abandoned carts
		 *
		 * @param $ids
		 */
		public static function retry_abandoned_cart( $ids ) {
			BWFAN_Common::update_abandoned_rows( $ids, 4 );

			do_action( 'bwfan_bulk_retry_abandoned_carts' );
		}

		/** Made the data for recovered carts screen.
		 * @return array
		 */
		public static function get_recovered_carts( $search = '', $offset = '', $limit = '', $only_count = false, $only_data = false ) {
			global $wpdb;
			$where          = '';
			$left_join      = '';
			$hpos_where     = '';
			$hpos_left_join = '';
			$search_like    = '';

			/** Check for search query */
			if ( isset( $search ) && ! empty( $search ) && $only_count === false ) { //phpcs:ignore WordPress.Security.NonceVerification
				$search_like = '%' . $wpdb->esc_like( $search ) . '%';
				$left_join   = " LEFT JOIN {$wpdb->prefix}postmeta as m1 ON p.ID = m1.post_id ";
				$where       = ' AND m1.meta_key = "_billing_email" AND m1.meta_value LIKE %s ';

				if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
					$hpos_where = ' AND p.billing_email LIKE %s ';
				}
			}

			$post_statuses = apply_filters( 'bwfan_recovered_cart_excluded_statuses', array(
				'wc-pending',
				'wc-failed',
				'wc-cancelled',
				'wc-refunded',
				'trash',
				'draft'
			) );
			$post_statuses = is_array( $post_statuses ) ? esc_sql( $post_statuses ) : $post_statuses; // filter output is placed inside quoted SQL literals
			$post_status   = "('" . implode( "','", array_filter( $post_statuses ) ) . "')";
			$found_posts   = array();
			if ( $only_count === false ) {

				if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
					$args = array( 'shop_order', '_bwfan_ab_cart_recovered_a_id' );
					if ( '' !== $hpos_where ) {
						$args[] = $search_like;
					}
					$args[] = absint( $offset );
					$args[] = absint( $limit );
					$query  = $wpdb->prepare( "SELECT p.id as id FROM {$wpdb->prefix}wc_orders as p LEFT JOIN {$wpdb->prefix}wc_orders_meta as m ON p.id = m.order_id WHERE p.type = %s AND p.status NOT IN $post_status AND m.meta_key = %s AND m.meta_value > 0 $hpos_where ORDER BY p.date_created_gmt DESC LIMIT %d,%d", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
				} else {
					$args = array( 'shop_order', '_bwfan_ab_cart_recovered_a_id' );
					if ( '' !== $where ) {
						$args[] = $search_like;
					}
					$args[] = absint( $offset );
					$args[] = absint( $limit );
					$query  = $wpdb->prepare( "SELECT p.ID as id FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id $left_join WHERE p.post_type = %s AND p.post_status NOT IN $post_status AND m.meta_key = %s AND m.meta_value > 0 $where ORDER BY p.post_modified DESC LIMIT %d,%d", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
				}
				$recovered_carts = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared

				if ( empty( $recovered_carts ) ) {
					return array();
				}
				$items = array();

				foreach ( $recovered_carts as $recovered_cart ) {
					if ( function_exists( 'wc_get_order' ) ) {
						$items[] = wc_get_order( $recovered_cart['id'] );
					}
				}

				$found_posts['items'] = $items;
			}

			if ( true === $only_data ) {
				return $found_posts;
			}

			if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
				$count_args = array( 'shop_order', '_bwfan_ab_cart_recovered_a_id' );
				if ( '' !== $hpos_where ) {
					$count_args[] = $search_like;
				}
				$count_query                = $wpdb->prepare( "SELECT DISTINCT COUNT(p.id) FROM {$wpdb->prefix}wc_orders as p LEFT JOIN {$wpdb->prefix}wc_orders_meta as m ON p.id = m.order_id WHERE p.type = %s AND p.status NOT IN $post_status AND m.meta_key = %s AND m.meta_value > 0 $hpos_where ", $count_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
				$found_posts['total_count'] = $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared

				return $found_posts;
			}

			$count_args = array( 'shop_order', '_bwfan_ab_cart_recovered_a_id' );
			if ( '' !== $where ) {
				$count_args[] = $search_like;
			}
			$count_query                = $wpdb->prepare( "SELECT COUNT(p.ID) FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id $left_join WHERE p.post_type = %s AND p.post_status NOT IN $post_status AND m.meta_key = %s AND m.meta_value > 0 $where ", $count_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
			$found_posts['total_count'] = $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared

			return $found_posts;
		}

		/** task details for abandoned id
		 *
		 * @param $abandoned_id
		 *
		 * @return array
		 */
		public static function get_cart_tasks( $abandoned_id ) {
			if ( ! class_exists( 'BWFCRM_Automations' ) ) {
				return array();
			}

			global $wpdb;
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			$abandoned_tasks = $wpdb->get_results( $wpdb->prepare( "
								SELECT t.ID as id, t.integration_slug as slug, t.integration_action as action, t.automation_id as a_id, t.status as status, t.e_date as date
								FROM {$wpdb->prefix}bwfan_tasks as t
								LEFT JOIN {$wpdb->prefix}bwfan_taskmeta as m
								ON t.ID = m.bwfan_task_id
								WHERE m.meta_key = %s
								AND m.meta_value = %d
								ORDER BY t.e_date DESC
								", 'c_a_id', $abandoned_id ), ARRAY_A );
			//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL
			$abandoned_logs = $wpdb->get_results( $wpdb->prepare( "
								SELECT l.ID as id, l.integration_slug as slug, l.integration_action as action, l.automation_id as a_id, l.status as status, l.e_date as date
								FROM {$wpdb->prefix}bwfan_logs as l
								LEFT JOIN {$wpdb->prefix}bwfan_logmeta as m
								ON l.ID = m.bwfan_log_id
								WHERE m.meta_key = %s
								AND m.meta_value = %d
								ORDER BY l.e_date DESC
								", 'c_a_id', $abandoned_id ), ARRAY_A );

			if ( empty( $abandoned_tasks ) && empty( $abandoned_logs ) ) {
				return array();
			}

			BWFAN_Core()->automations->return_all = true;
			$active_automations                   = BWFAN_Core()->automations->get_all_automations();
			BWFAN_Core()->automations->return_all = false;

			$crm_automation = new BWFCRM_Automations();

			if ( ! empty( $abandoned_tasks ) ) {
				$tasks = $crm_automation->get_tasks_data( $active_automations, $abandoned_tasks );
				if ( is_array( $tasks ) && count( $tasks ) > 0 ) {
					$crm_automation->get_tasks_items( $active_automations, $tasks );
				}
			}
			if ( ! empty( $abandoned_logs ) ) {
				$logs = $crm_automation->get_tasks_data( $active_automations, $abandoned_logs, 'logs' );
				if ( is_array( $logs ) && count( $logs ) > 0 ) {
					$crm_automation->get_tasks_items( $active_automations, $logs, 'logs' );
				}
			}

			if ( empty( $crm_automation->task_localized ) ) {
				return array();
			}

			krsort( $crm_automation->task_localized['result'] );
			$items = [];
			foreach ( $crm_automation->task_localized['result'] as $value ) {
				$items[] = $crm_automation->task_localized[ $value['type'] ][ $value['id'] ];
			}

			return $items;
		}

		/**
		 * Getting contact id
		 *
		 * @param $email
		 *
		 * @return int
		 */
		public static function get_contact_id( $email ) {
			$contact_id = 0;
			if ( ! is_email( $email ) ) {
				return $contact_id;
			}

			$contact_obj = bwf_get_contact( null, $email );
			if ( ! $contact_obj instanceof WooFunnels_Contact ) {
				return $contact_id;
			}

			$contact_id = $contact_obj->id;
			if ( is_null( $contact_id ) || empty( $contact_id ) ) {
				return 0;
			}

			return $contact_id;
		}

		/**
		 * @param $contact_id
		 * @param $checkout_data
		 *
		 * @return string[]
		 */
		public static function get_name( $contact_id, $checkout_data ) {
			$data = array( 'f_name' => '', 'l_name' => '' );
			if ( ! empty( $contact_id ) ) {
				$contact_array  = new WooFunnels_Contact( '', '', '', $contact_id );
				$data['f_name'] = $contact_array->get_f_name();
				$data['l_name'] = $contact_array->get_l_name();

				return $data;
			}

			$checkout_data = json_decode( $checkout_data, true );

			if ( ! isset( $checkout_data['fields'] ) || ! is_array( $checkout_data['fields'] ) ) {
				return $data;
			}

			foreach ( $checkout_data['fields'] as $field_key => $value ) {
				if ( 'billing_first_name' === $field_key ) {
					$data['f_name'] = $value;
				}

				if ( 'billing_last_name' === $field_key ) {
					$data['l_name'] = $value;
				}
			}

			return $data;
		}

		/** Add f_name, l_name, contact_id to carts */
		public static function populate_contact_info( $result ) {
			if ( ! is_array( $result ) || empty( $result ) ) {
				return $result;
			}

			global $wpdb;
			$table  = $wpdb->prefix . 'bwf_contact';
			$emails = array_column( $result, 'email' );
			$emails = array_map( 'trim', array_filter( $emails ) );

			if ( empty( $emails ) ) {
				return $result;
			}

			$placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
			$sql       = $wpdb->prepare( "SELECT id, email, f_name, l_name from $table WHERE email IN ($placeholders)", ...$emails ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated via array_fill; emails bound via %s
			$db_result = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
			$contacts  = [];

			if ( is_array( $db_result ) && ! empty( $db_result ) ) {
				foreach ( $db_result as $item ) {
					$contacts[ $item['email'] ] = $item;
				}
			}

			foreach ( $result as $index => $item ) {
				$email = $item['email'];

				/** Set first name and last name from Checkout Data */
				if ( isset( $item['checkout_data'] ) && ! empty( $item['checkout_data'] ) ) {
					$checkout_data = json_decode( $item['checkout_data'], true );
					if ( ! isset( $checkout_data['fields'] ) || ! is_array( $checkout_data['fields'] ) ) {
						continue;
					}

					foreach ( $checkout_data['fields'] as $field_key => $value ) {
						if ( empty( $result[ $index ]['f_name'] ) && 'billing_first_name' === $field_key && ! empty( $value ) ) {
							$result[ $index ]['f_name'] = $value;
						}

						if ( empty( $result[ $index ]['l_name'] ) && 'billing_last_name' === $field_key && ! empty( $value ) ) {
							$result[ $index ]['l_name'] = $value;
						}
					}

					unset( $result[ $index ]['checkout_data'] );
				}

				/** If Contact ID found in DB Results */
				if ( isset( $contacts[ $email ]['id'] ) && ! empty( $contacts[ $email ]['id'] ) ) {
					if ( empty( $result[ $index ]['f_name'] ) ) {
						$result[ $index ]['f_name'] = $contacts[ $email ]['f_name'];
					}

					if ( empty( $result[ $index ]['l_name'] ) ) {
						$result[ $index ]['l_name'] = $contacts[ $email ]['l_name'];
					}

					$result[ $index ]['contact_id'] = $contacts[ $email ]['id'];
				}
			}

			return $result;
		}

		/**
		 * Get lost carts
		 *
		 * @param $offset
		 * @param $limit
		 *
		 * @return array|object|stdClass[]|null
		 */
		public static function get_lost_carts( $offset = '', $limit = '' ) {
			$where = "WHERE status = 2 ";
			$carts = BWFAN_Model_Abandonedcarts::get_abandoned_data( $where, $offset, $limit, 'last_modified', ARRAY_A );

			return is_array( $carts ) && count( $carts ) > 0 ? array_map( function ( $cart_data ) {
				$cart = array_merge( [
					'ID'           => '',
					'email'        => '',
					'f_name'       => '',
					'l_name'       => '',
					'total'        => 0,
					'currency'     => '',
					'created_time' => '',
				], $cart_data );

				return [
					'id'         => $cart['ID'],
					'email'      => $cart['email'],
					'f_name'     => $cart['f_name'],
					'l_name'     => $cart['l_name'],
					'revenue'    => $cart['total'],
					'currency'   => BWFAN_Automations::get_currency( $cart['currency'] ),
					'created_on' => $cart['created_time'],
				];
			}, $carts ) : [];
		}

		/**
		 * Delete recovered carts
		 *
		 * @param $ids
		 *
		 * @return void
		 */
		public static function delete_recovered_carts( $ids ) {
			global $wpdb;
			// Sanitize IDs to prevent SQL injection
			$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array( absint( $ids ) );
			$ids = array_filter( $ids ); // Remove any zero values
			if ( empty( $ids ) ) {
				return;
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$query        = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}postmeta WHERE `post_id` IN($placeholders) AND `meta_key` = '_bwfan_ab_cart_recovered_a_id'", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- ids absint()ed; placeholders generated via array_fill
			if ( BWF_WC_Compatibility::is_hpos_enabled() ) {
				$query = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE `order_id` IN($placeholders) AND `meta_key` = '_bwfan_ab_cart_recovered_a_id'", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- ids absint()ed; placeholders generated via array_fill
			}
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

			/** Delete from conversion table */
			$query = $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bwfan_conversions WHERE `wcid` IN($placeholders) ", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- ids absint()ed; placeholders generated via array_fill
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
		}

		/**
		 * Get currency data
		 *
		 * @param $item
		 *
		 * @return array
		 */
		public static function get_currency( $item ) {
			if ( $item instanceof WC_Order ) {
				$currency = ! is_null( $item->get_currency() ) ? $item->get_currency() : get_option( 'woocommerce_currency' );
			} else {
				$currency = ! is_null( $item->currency ) ? $item->currency : get_option( 'woocommerce_currency' );
			}

			$currency_symbol = '';
			if ( method_exists( 'BWF_Plugin_Compatibilities', 'get_currency_symbol' ) ) {
				$currency_symbol = BWF_Plugin_Compatibilities::get_currency_symbol( $currency );
			}
			$currency_symbol = empty( $currency_symbol ) ? get_woocommerce_currency_symbol( $currency ) : $currency_symbol;
			$price_format    = apply_filters( 'bwfan_get_price_format_cart', get_woocommerce_price_format(), $currency );

			return [
				'code'              => $currency,
				'precision'         => wc_get_price_decimals(),
				'symbol'            => html_entity_decode( $currency_symbol, ENT_QUOTES | ENT_HTML401 ),
				'symbolPosition'    => get_option( 'woocommerce_currency_pos' ),
				'decimalSeparator'  => wc_get_price_decimal_separator(),
				'thousandSeparator' => wc_get_price_thousand_separator(),
				'priceFormat'       => html_entity_decode( $price_format, ENT_QUOTES | ENT_HTML401 ),
			];
		}
	}
}