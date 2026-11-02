<?php
/**
 * Automations Controller Class
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWFCRM_Automations' ) && BWFAN_Common::is_pro_3_0() ) {
	/**
	 * Class BWFCRM_Automations
	 *
	 */
	#[AllowDynamicProperties]
	class BWFCRM_Automations {
		public $task_localized = [];
		public $localize_data = [];

		public function get_contact_tasks( $contact_id ) {
			if ( 0 === $contact_id ) {
				return array();
			}

			$scheduled_task = BWFAN_Model_Automations::get_tasks_for_contact( $contact_id );
			$contact_logs   = BWFAN_Model_Automations::get_logs_for_contact( $contact_id );

			if ( empty( $scheduled_task ) && empty( $contact_logs ) ) {
				return array();
			}

			BWFAN_Core()->automations->return_all = true;
			$active_automations                   = BWFAN_Core()->automations->get_all_automations();
			BWFAN_Core()->automations->return_all = false;

			if ( ! empty( $scheduled_task ) ) {
				$tasks = self::get_tasks_data( $active_automations, $scheduled_task );
				if ( is_array( $tasks ) && count( $tasks ) > 0 ) {
					self::get_tasks_items( $active_automations, $tasks );
				}
			}

			if ( ! empty( $contact_logs ) ) {
				$logs = self::get_tasks_data( $active_automations, $contact_logs, 'logs' );
				if ( is_array( $logs ) && count( $logs ) > 0 ) {
					self::get_tasks_items( $active_automations, $logs, 'logs' );
				}
			}

			if ( empty( $this->task_localized ) ) {
				return array();
			}

			krsort( $this->task_localized['result'] );

			$items = [];
			foreach ( $this->task_localized['result'] as $value ) {
				$items[] = $this->task_localized[ $value['type'] ][ $value['id'] ];
			}

			return $items;
		}

		/**
		 * @param $active_automations
		 * @param $data
		 * @param string $type
		 *
		 * @return array
		 */
		public function get_tasks_data( $active_automations, $data, $type = 'task' ) {
			$result = [];
			foreach ( $data as $tasks ) {
				$task_id       = $tasks['id'];
				$automation_id = $tasks['a_id'];
				if ( ! isset( $active_automations[ $automation_id ] ) ) {
					continue;
				}
				$tasks['title'] = $active_automations[ $automation_id ]['meta']['title'];
				if ( 'task' === $type ) {
					$tasks['meta'] = BWFAN_Model_Taskmeta::get_task_meta( $task_id );
				}
				if ( 'logs' === $type ) {
					$tasks['meta'] = BWFAN_Model_Logmeta::get_log_meta( $task_id );
				}
				$result[ $task_id ] = $tasks;

				unset( $tasks );
			}

			return $result;
		}

		public function get_tasks_items( $active_automations, $tasks, $type = 'tasks' ) {
			$items = [];
			$gif   = admin_url() . 'images/wpspin_light.gif';

			foreach ( $tasks as $task_id => $task ) {
				$automation_id = $task['a_id'];
				if ( ! isset( $active_automations[ $automation_id ] ) && 'tasks' === $type ) {
					$status = 't_1';
				} else {
					$status = 't_0';
				}

				if ( 'logs' === $type ) {
					$status = 0 === absint( $task['status'] ) ? "l_0" : "l_1";
				}

				$source_slug      = isset( $task['meta']['integration_data']['event_data'] ) ? $task['meta']['integration_data']['event_data']['event_source'] : null;
				$event_slug       = isset( $task['meta']['integration_data']['event_data'] ) ? $task['meta']['integration_data']['event_data']['event_slug'] : null;
				$integration_slug = $task['slug'];

				// Event plugin is deactivated, so don't show the automations
				$source_instance = BWFAN_Core()->sources->get_source( $source_slug );

				/**
				 * @var $event_instance BWFAN_Event
				 */
				$event_instance = BWFAN_Core()->sources->get_event( $event_slug );

				$task_details = isset( $task['meta']['integration_data']['global'] ) ? $task['meta']['integration_data']['global'] : array();
				$format       = BWFAN_Common::bwfan_get_date_format();
				$message      = ( isset( $task['meta']['task_message'] ) ) ? BWFAN_Common::get_parsed_time( $format, maybe_unserialize( $task['meta']['task_message'] ) ) : array();

				$automation_url = add_query_arg( array(
					'page'    => 'autonami',
					'section' => 'automation',
					'edit'    => $automation_id,
				), admin_url( 'admin.php' ) );

				$action_slug                = $task['action'];
				$items[ $type ][ $task_id ] = array(
					'id'                      => $task_id,
					'automation_id'           => $automation_id,
					'automation_name'         => $task['title'],
					'automation_url'          => $automation_url,
					'automation_source'       => ! is_null( $source_instance ) ? $source_instance->get_name() : __( 'Data unavailable. Contact Support.', 'wp-marketing-automations' ),
					'automation_event'        => ! is_null( $event_instance ) ? $event_instance->get_name() : __( 'Data unavailable. Contact Support.', 'wp-marketing-automations' ),
					'task_integration'        => esc_html__( 'Not Found', 'wp-marketing-automations' ),
					'task_integration_action' => esc_html__( 'Not Found', 'wp-marketing-automations' ),
					'task_date'               => BWFAN_Common::get_human_readable_time( $task['date'], get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $task['date'] ), BWFAN_Common::bwfan_get_date_format() ) ),
					'status'                  => $status,
					'gif'                     => $gif,
					'task_message'            => $message,
					'task_details'            => '',
					'task_email'              => '',
					'task_corrupted'          => false
				);

				if ( 'logs' === $type ) {
					$items[ $type ][ $task_id ]['task_id'] = $task['meta']['task_id'];
				}
				/**
				 * @var $action_instance BWFAN_Action
				 */
				$action_instance = BWFAN_Core()->integration->get_action( $action_slug );
				if ( ! is_null( $action_instance ) ) {
					$items[ $type ][ $task_id ]['task_integration_action'] = $action_instance->get_name();
				} else {
					$action_name = BWFAN_Common::get_entity_nice_name( 'action', $action_slug );
					if ( ! empty( $action_name ) ) {
						$items[ $type ][ $task_id ]['task_integration_action'] = $action_name;
					}
				}

				/**
				 * @var $event_instance BWFAN_Event
				 */
				$integration_instance = BWFAN_Core()->integration->get_integration( $integration_slug );
				if ( ! is_null( $integration_instance ) ) {
					$items[ $type ][ $task_id ]['task_integration'] = $integration_instance->get_name();
					$task_details['task_integration']               = $integration_instance->get_name();
				} else {
					$integration_name = BWFAN_Common::get_entity_nice_name( 'integration', $integration_slug );
					if ( ! empty( $integration_name ) ) {
						$items[ $type ][ $task_id ]['task_integration'] = $integration_name;
						$task_details['task_integration']               = $integration_name;
					}
				}
				$items[ $type ][ $task_id ]['task_details']   = ! is_null( $event_instance ) ? $event_instance->get_task_view( $task_details ) : '<b>' . __( 'Data unavailable. Contact Support.', 'wp-marketing-automations' ) . '</b>';
				$items[ $type ][ $task_id ]['task_corrupted'] = is_null( $event_instance ) || is_null( $source_instance );
				$items[ $type ][ $task_id ]['task_email']     = isset( $task_details['email'] ) ? $task_details['email'] : '';
				$this->task_localized[ $type ][ $task_id ]    = $items[ $type ][ $task_id ];

				if ( isset( $this->localize_data['result'] ) && isset( $this->localize_data['result'][ $task['date'] ] ) ) {
					$task['date'] = absint( $task['date'] ) + 1;
				}
				$this->task_localized['result'][ $task['date'] ] = array(
					'id'   => $task_id,
					'type' => $type,
				);
			}
		}


		public function get_contact_carts( $contact_email ) {
			$contact_cart_details = self::get_carts( $contact_email );
			$cart_data            = array();
			if ( empty( $contact_cart_details ) ) {
				return array();
			}
			foreach ( $contact_cart_details as $cart_key => $cart ) {
				$cart_data[ $cart_key ]['email']        = $cart->email;
				$cart_data[ $cart_key ]['status']       = $cart->status;
				$cart_data[ $cart_key ]['total']        = $cart->total;
				$cart_data[ $cart_key ]['order_id']     = $cart->order_id;
				$cart_data[ $cart_key ]['created_time'] = $cart->last_modified;
				$cart_data[ $cart_key ]['items']        = self::get_abandoned_items( $cart->items );
				$cart_data[ $cart_key ]['phone']        = self::get_abandoned_phone( $cart->checkout_data );
				$cart_data[ $cart_key ]['username']     = self::get_abandoned_username( $cart->order_id );
			}

			return $cart_data;
		}

		public function get_abandoned_items( $items ) {
			$items = maybe_unserialize( $items );
			if ( empty( $items ) ) {
				return '';
			}

			$hide_free_products = BWFAN_Common::hide_free_products_cart_order_items();
			$names              = [];
			foreach ( $items as $value ) {
				if ( true === $hide_free_products && empty( $value['line_total'] ) ) {
					continue;
				}
				$names[] = $value['data']->get_name();
			}

			if ( empty( $names ) ) {
				return '';
			}

			$names = implode( ', ', $names );

			return $names;
		}

		public function get_abandoned_phone( $checkout_data ) {
			$checkout_data = json_decode( $checkout_data, true );
			$phone_value   = ( is_array( $checkout_data ) && isset( $checkout_data['fields'] ) && is_array( $checkout_data['fields'] ) && isset( $checkout_data['fields']['billing_phone'] ) && ! empty( $checkout_data['fields']['billing_phone'] ) ) ? $checkout_data['fields']['billing_phone'] : __( 'N.A.', 'wp-marketing-automations' );

			return $phone_value;
		}

		public function get_abandoned_time( $time ) {
			$timestamp = strtotime( $time );
			$date      = BWFAN_Common::get_human_readable_time( $timestamp, get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), get_option( 'date_format' ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Round-trips strtotime(DB datetime string); gmdate would shift under non-UTC tz

			return $date;
		}

		public function get_abandoned_username( $order_id ) {

			$obj   = wc_get_order( $order_id );
			$buyer = '';

			if ( ! $obj instanceof WC_Order ) {
				return $buyer;
			}

			if ( $obj->get_billing_first_name() || $obj->get_billing_last_name() ) {
				/* translators: 1: first name 2: last name */
				$buyer = trim( sprintf( _x( '%1$s %2$s', 'full name', 'woocommerce' ), $obj->get_billing_first_name(), $obj->get_billing_last_name() ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			} elseif ( $obj->get_billing_company() ) {
				$buyer = trim( $obj->get_billing_company() );
			} elseif ( $obj->get_customer_id() ) {
				$user  = get_user_by( 'id', $obj->get_customer_id() );
				$buyer = ucwords( $user->display_name );
			}

			return $buyer;
		}

		public function get_carts( $contact_email ) {
			if ( ! is_email( $contact_email ) ) {
				return array();
			}
			global $wpdb;
			$where         = $wpdb->prepare( ' where email=%s', $contact_email );
			$contact_carts = BWFAN_Model_Abandonedcarts::get_abandoned_data( $where, '', '', 'last_modified' );

			return $contact_carts;
		}

		/**
		 * @param $contact_email
		 *
		 * @return array|object|null
		 */
		public function get_contact_lost_carts( $contact_email ) {
			if ( ! is_email( $contact_email ) ) {
				return array();
			}

			global $wpdb;
			$where              = $wpdb->prepare( 'where email=%s And status=2', $contact_email );
			$contact_lost_carts = BWFAN_Model_Abandonedcarts::get_abandoned_data( $where, '', '', 'last_modified' );

			return $contact_lost_carts;
		}

		/**
		 * @param $contact_email
		 *
		 * @return array|object|null
		 */
		public function get_contact_abandoned_carts( $contact_email ) {
			if ( ! is_email( $contact_email ) ) {
				return array();
			}
			global $wpdb;
			$where                   = $wpdb->prepare( 'WHERE status IN (0,1,3,4) and email=%s', $contact_email );
			$contact_abandoned_carts = BWFAN_Model_Abandonedcarts::get_abandoned_data( $where, '', '', 'last_modified' );

			return $contact_abandoned_carts;
		}

		/**
		 * @param $contact_email
		 *
		 * @return array|object|null
		 */
		public function get_contact_recovered_carts( $contact_email ) {
			if ( ! is_email( $contact_email ) ) {
				return array();
			}
			global $wpdb;
			$where         = 'AND m1.meta_key = "_billing_email"';
			$where         .= ' AND m1.meta_value = %s';
			/** Only genuine automation-attributed recoveries (meta_value > 0), matching Cart Analytics and the Recovered Carts list */
			$where         .= ' AND m2.meta_value > 0';
			$post_statuses = apply_filters( 'bwfan_recovered_cart_excluded_statuses', array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'trash', 'draft' ) );
			/** Trailing '' keeps NOT IN () valid when the filter returns an empty list */
			$post_statuses  = array_merge( array_map( 'strval', (array) $post_statuses ), array( '' ) );
			$status_holders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
			$query_args     = array_merge( array( '_bwfan_ab_cart_recovered_a_id', 'shop_order' ), $post_statuses, array( $contact_email ) );
			$prepare_query  = $wpdb->prepare( "SELECT p.ID FROM {$wpdb->prefix}posts p, {$wpdb->prefix}postmeta m1, {$wpdb->prefix}postmeta m2 WHERE p.ID = m1.post_id and p.ID = m2.post_id AND m2.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ($status_holders) $where ORDER BY p.post_modified DESC", ...$query_args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder count matches spread args; filter output bound via %s
			$recovered_carts_ids = $wpdb->get_results( $prepare_query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- query built with $wpdb->prepare() above

			if ( empty( $recovered_carts_ids ) ) {
				return array();
			}

			return $recovered_carts_ids;
		}

		/**
		 * @param $contact_email
		 *
		 * @return array|object|null
		 */
		public function get_last_abandoned_cart( $contact_email ) {
			$abandoned_data = array(
				'items_count'   => 0,
				'last_modified' => 0,
				'total'         => 0,
			);
			if ( ! is_email( $contact_email ) ) {
				return $abandoned_data;
			}
			global $wpdb;
			/** Was passing the bare email as the WHERE clause (invalid SQL); same clause as BWFAN_Abandoned_Cart::get_last_abandoned_cart() */
			$where                   = $wpdb->prepare( 'WHERE status IN (1,3,4) and email = %s', $contact_email );
			$contact_abandoned_carts = BWFAN_Model_Automations::get_last_abandoned_cart( $where );

			if ( empty( $contact_abandoned_carts ) ) {
				return $abandoned_data;
			}
			$cart_items                      = maybe_unserialize( $contact_abandoned_carts[0]['items'] );
			$abandoned_data['items_count']   = is_array( $cart_items ) ? count( $cart_items ) : 0;
			$abandoned_data['last_modified'] = isset( $contact_abandoned_carts[0]['last_modified'] ) ? $contact_abandoned_carts[0]['last_modified'] : '';
			$abandoned_data['total']         = isset( $contact_abandoned_carts[0]['total'] ) ? $contact_abandoned_carts[0]['total'] : 0;

			return $abandoned_data;
		}

		/**
		 * @return array|object
		 */
		public static function get_top_automations() {
			$top_automations = BWFAN_Model_Automations::get_top_automations();

			$automation_array['top_automations'] = self::get_automations_array( $top_automations );

			return $automation_array;
		}

		/** getting top automation with conversions
		 *
		 * @param $automations
		 *
		 * @return array
		 */
		public static function get_automations_array( $automations ) {
			if ( empty( $automations ) ) {
				return [];
			}

			return array_map( function ( $automation ) {
				$automation_id       = $automation['aid'];
				$automation['name']  = ! empty( $automation['name'] ) ? $automation['name'] : BWFAN_Model_Automationmeta::get_meta( $automation_id, 'title' );
				$automation['event'] = BWFAN_Common::get_automation_event_name( $automation['event'] );

				return $automation;
			}, $automations );
		}

		/** Made the data for recent recovered cart in dashboard screen.
		 * @return array
		 */
		public static function get_recovered_carts( $offset, $limit ) {
			global $wpdb;
			$where         = '';
			$post_statuses = apply_filters( 'bwfan_recovered_cart_excluded_statuses', array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'trash', 'draft' ) );
			$post_statuses = is_array( $post_statuses ) ? esc_sql( $post_statuses ) : $post_statuses; // filter output is placed inside quoted SQL literals
			$post_status   = '(';
			foreach ( $post_statuses as $status ) {
				$post_status .= "'" . $status . "',";
			}
			$post_status     .= "'')";
			/** Only count genuine automation-attributed recoveries (meta_value > 0), matching Cart Analytics and the Recovered Carts list */
			$where           .= ' AND m.meta_value > 0 ';
			$query           = $wpdb->prepare( "SELECT p.ID as id FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id WHERE p.post_type = %s AND p.post_status NOT IN $post_status AND m.meta_key = %s $where ORDER BY p.post_modified DESC LIMIT %d,%d", 'shop_order', '_bwfan_ab_cart_recovered_a_id', absint( $offset ), absint( $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
			$recovered_carts = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared
			if ( empty( $recovered_carts ) ) {
				return array();
			}

			$found_posts = array();
			$items       = array();

			if ( ! function_exists( 'wc_get_order' ) ) {
				return $found_posts;
			}

			foreach ( $recovered_carts as $recovered_cart ) {
				$items[] = wc_get_order( $recovered_cart['id'] );
			}

			$found_posts['items']        = $items;
			$found_posts['total_record'] = $wpdb->get_var( $wpdb->prepare( "SELECT count(p.ID) as total FROM {$wpdb->prefix}posts as p LEFT JOIN {$wpdb->prefix}postmeta as m ON p.ID = m.post_id WHERE p.post_type = %s AND p.post_status NOT IN $post_status AND m.meta_key = %s $where ORDER BY p.post_modified DESC LIMIT %d,%d", 'shop_order', '_bwfan_ab_cart_recovered_a_id', absint( $offset ), absint( $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- excluded statuses esc_sql()ed after the filter; tables from $wpdb->prefix; values prepared

			return $found_posts;
		}

		public static function get_conversions( $automation_id, $offset = 0, $limit = 25 ) {
			if ( empty( $automation_id ) ) {
				return [ 'conversions' => [], 'total' => 0 ];
			}

			return BWFAN_Model_Conversions::get_conversions_by_source_type( $automation_id, BWFAN_Email_Conversations::$TYPE_AUTOMATION, $limit, $offset );
		}

		/**
		 * @param $automation_id
		 * @param $split_id
		 * @param $automation_obj
		 *
		 * @return array
		 */
		public static function get_split_preview_data( $automation_id, $split_id, $automation_obj = null ) {
			$automation_obj   = ! $automation_obj instanceof BWFAN_Automation_V2 ? BWFAN_Automation_V2::get_instance( $automation_id ) : $automation_obj;
			if ( ! $automation_obj instanceof BWFAN_Automation_V2 ) {
				return [];
			}
			$automation_steps = $automation_obj->get_steps();

			if ( empty($automation_steps ) || ! isset( $automation_steps[ $split_id ] ) ) {
				return [];
			}

			/** Get all split steps */
			$split_steps = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'split_steps' );
			/** Get single split step by id */
			$split_steps = isset( $split_steps[ $split_id ] ) ? $split_steps[ $split_id ] : [];
			/** Split node id */
			$split_node_id = $automation_obj->get_steps_node_id( $split_id );
			/** Get split step data */
			$split_data = BWFAN_Model_Automation_Step::get( $split_id );
			$created_at = isset( $split_data['created_at'] ) ? $split_data['created_at'] : '';

			/** Add split step data */
			$split_meta                      = $automation_steps[ $split_id ];
			$completed                       = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( [ $split_id ] );
			$queued                          = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( [ $split_id ], 2 );
			$skipped                         = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( [ $split_id ], 4 );
			$failed                          = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( [ $split_id ], 3 );
			$split_meta['data']['stats']     = [
				'completed' => isset( $completed[0]['count'] ) ? $completed[0]['count'] : 0,
				'queued'    => isset( $queued[0]['count'] ) ? $queued[0]['count'] : 0,
				'skipped'   => isset( $skipped[0]['count'] ) ? $skipped[0]['count'] : 0,
				'failed'    => isset( $failed[0]['count'] ) ? $failed[0]['count'] : 0,
			];
			$split_data                      = isset( $split_data['data'] ) ? json_decode( $split_data['data'], true ) : [];
			$split_title                     = isset( $split_data['sidebarData']['title'] ) ? $split_data['sidebarData']['title'] : '';
			$split_meta['data']['desc_text'] = $split_title;

			$finale_data    = [ $split_meta ];
			$split_node_ids = [ $split_node_id ];

			$inte_ins = BWFAN_Load_Integrations::get_instance();
			$load_src = BWFAN_Load_Sources::get_instance();
			foreach ( $split_steps as $path => $split_step ) {
				/** Get stats for path node */
				$path                  = str_replace( 'p', 'path', $path );
				$meta                  = $automation_steps[ $split_node_id . '-' . $path ];
				$split_node_ids[]      = $split_node_id . '-' . $path;
				$meta['data']['stats'] = self::get_path_stats( $automation_id, $split_step, $created_at );
				$finale_data[]         = $meta;
				/** Loop on split's steps to modify data */
				foreach ( $split_step as $step ) {
					$step_data        = BWFAN_Model_Automation_Step::get( $step );
					$meta             = $automation_steps[ $step ];
					$split_node_ids[] = $meta['id'];
					$action_data      = isset( $step_data['action'] ) ? json_decode( $step_data['action'], true ) : [];
					$data             = isset( $step_data['data'] ) ? json_decode( $step_data['data'], true ) : [];

					$completed             = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( [ $step ], 1, $created_at );
					$queued                = BWFAN_Model_Automation_Contact_Trail::get_bulk_step_count( [ $step ], 2, $created_at );
					$meta['data']['stats'] = [
						'completed' => isset( $completed[0]['count'] ) ? $completed[0]['count'] : 0,
						'queued'    => isset( $queued[0]['count'] ) ? $queued[0]['count'] : 0
					];

					if ( empty( $action_data ) ) {
						/** Check if yes node */
						if ( false !== strpos( $step, 'yes' ) ) {
							$meta['data']['direction'] = 'yes';
							$meta['data']['parent']    = str_replace( 'yes', '', $step );
							$finale_data[]             = $meta;
							continue;
						}
						/** Check if no node */
						if ( false !== strpos( $step, 'no' ) ) {
							$meta['data']['direction'] = 'no';
							$meta['data']['parent']    = str_replace( 'no', '', $step );
							$finale_data[]             = $meta;
							continue;
						}
						$meta['data']['sidebarValues'] = $data['sidebarData'];
						$finale_data[]                 = $meta;
						continue;
					}
					$action_slug = isset( $action_data['action'] ) ? $action_data['action'] : '';
					$integration = isset( $action_data['intergration'] ) ? $action_data['intergration'] : '';

					$body      = isset( $data['sidebarData']['body'] ) ? $data['sidebarData']['body'] : '';
					$subject   = empty( $body ) && isset( $data['sidebarData']['bwfan_email_data']['subject'] ) ? $data['sidebarData']['bwfan_email_data']['subject'] : $body;
					$desc_text = ! empty( $subject ) ? $subject : $body;

					if ( empty( $desc_text ) ) {
						$tags         = isset( $data['sidebarData']['tags'] ) ? $data['sidebarData']['tags'] : [];
						$sidebar_data = empty( $tags ) && isset( $data['sidebarData']['list_id'] ) ? $data['sidebarData']['list_id'] : $tags;
						$desc_text    = ! empty( $sidebar_data ) ? wp_json_encode( array_column( $sidebar_data, 'name' ) ) : '';
					}

					$action_name = '';
					if ( ! empty( $action_slug ) ) {
						$action_ins  = $inte_ins->get_action( $action_slug );
						$action_name = $action_ins instanceof BWFAN_Action ? $action_ins->get_name() : '-';
					}
					$source_name = '';
					if ( ! empty( $integration ) ) {
						$integration = 'wp_adv' === $integration ? 'wp' : $integration;
						try {
							$source_ins  = $load_src->get_source( $integration );
							$source_name = $source_ins->get_name();
						} catch ( Error $e ) {

						}
					}
					$meta['data']['desc_text']   = $desc_text;
					$meta['data']['selected']    = $action_name;
					$meta['data']['integration'] = $source_name;
					$meta['data']['action_slug'] = $action_slug;
					$meta['data']['stepId']      = $step;
					$finale_data[]               = $meta;
				}
			}

			$finale_data[] = [
				'id'              => 'end',
				'type'            => 'end',
				'data'            => [],
				'hidden'          => false,
				'targetPosition'  => 'top',
				'sourcePosition'  => 'bottom',
				'hasMultiParents' => true
			];

			$split_preview_data = array_merge( $finale_data, self::get_split_links( $split_node_ids, $automation_id ) );
			foreach ( $split_preview_data as $key => $data ) {
				if ( isset( $data['position'] ) ) {
					unset($split_preview_data[$key]['position']);
				}
			}
			return $split_preview_data;
		}

		/**
		 * Get path's stats
		 *
		 * @param $automation_id
		 * @param $step_ids
		 *
		 * @return array|WP_Error
		 */
		public static function get_path_stats( $automation_id, $step_ids, $after_date, $only_tiles = true ) {
			if ( empty( $step_ids ) ) {
				return [];
			}
			$data = BWFAN_Model_Engagement_Tracking::get_automation_step_analytics( $automation_id, $step_ids, $after_date );

			$open_rate        = isset( $data['open_rate'] ) ? number_format( $data['open_rate'], 1 ) : 0;
			$click_rate       = isset( $data['click_rate'] ) ? number_format( $data['click_rate'], 1 ) : 0;
			$revenue          = isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : 0;
			$unsubscribes     = isset( $data['unsbuscribers'] ) ? absint( $data['unsbuscribers'] ) : 0;
			$conversions      = isset( $data['conversions'] ) ? absint( $data['conversions'] ) : 0;
			$sent             = isset( $data['sent'] ) ? absint( $data['sent'] ) : 0;
			$open_count       = isset( $data['open_count'] ) ? absint( $data['open_count'] ) : 0;
			$click_count      = isset( $data['click_count'] ) ? absint( $data['click_count'] ) : 0;
			$contacts_count   = isset( $data['contacts_count'] ) ? absint( $data['contacts_count'] ) : 1;
			$rev_per_person   = empty( $contacts_count ) || empty( $revenue ) ? 0 : number_format( $revenue / $contacts_count, 1 );
			$unsubscribe_rate = empty( $contacts_count ) || empty( $unsubscribes ) ? 0 : ( $unsubscribes / $contacts_count ) * 100;

			/** Get click rate from total opens */
			$click_to_open_rate = ( empty( $click_count ) || empty( $open_count ) ) ? 0 : number_format( ( $click_count / $open_count ) * 100, 1 );

			$tiles = [
				[
					'l' => __( 'Contacts', 'wp-marketing-automations' ),
					'v' => '',
				],
				[
					'l' => __( 'Sent', 'wp-marketing-automations' ),
					'v' => empty( $sent ) ? '-' : $sent,
				],
				[
					'l' => __( 'Opened', 'wp-marketing-automations' ),
					'v' => empty( $open_count ) ? '-' : $open_count . ' (' . $open_rate . '%)',
				],
				[
					'l' => __( 'Clicked', 'wp-marketing-automations' ),
					'v' => empty( $click_count ) ? '-' : $click_count . ' (' . $click_rate . '%)',
				],
				[
					'l' => __( 'Click to Open', 'wp-marketing-automations' ),
					'v' => empty( $click_to_open_rate ) ? '-' : $click_to_open_rate . '%',
				]
			];

			$revenue_without_symbol = 0;
			if ( bwfan_is_woocommerce_active() ) {
				$currency_symbol        = get_woocommerce_currency_symbol();
				$revenue_without_symbol = $revenue;
				$revenue                = empty( $revenue ) ? '' : html_entity_decode( $currency_symbol . $revenue, ENT_QUOTES | ENT_HTML401 );
				$rev_per_person         = empty( $rev_per_person ) ? '' : html_entity_decode( $currency_symbol . $rev_per_person, ENT_QUOTES | ENT_HTML401 );

				$revenue_tiles = [
					[
						'l' => 'Rev.',
						'v' => empty( $revenue ) ? '-' : $revenue . ' (' . $conversions . ')',
					],
					[
						'l' => 'Rev. Per Contact',
						'v' => empty( $rev_per_person ) ? '-' : $rev_per_person,
					]
				];

				$tiles = array_merge( $tiles, $revenue_tiles );
			}

			$tiles[] = [
				'l' => 'Unsubscribed',
				'v' => empty( $unsubscribes ) ? '-' : $unsubscribes . '( ' . number_format( $unsubscribe_rate, 1 ) . '% )',
			];

			if ( true === $only_tiles ) {
				return $tiles;
			}

			return [
				'tiles' => $tiles,
				'stats' => [
					'contacts_count' => $contacts_count,
					'revenue'        => $revenue_without_symbol,
					'conversions'    => $conversions,
					'sent'           => $sent,
					'open_count'     => $open_count,
					'click_count'    => $click_count,
					'unsubscribes'   => $unsubscribes,
				]
			];
		}

		/**
		 * Get split links
		 *
		 * @param $node_ids
		 * @param $automation_id
		 *
		 * @return array
		 */
		public static function get_split_links( $node_ids, $automation_id ) {
			$links = BWFAN_Model_Automationmeta::get_meta( $automation_id, 'links' );
			if ( empty( $node_ids ) ) {
				return [];
			}

			$split_links = [];

			foreach ( $links as $link ) {
				if ( ! in_array( $link['source'], $node_ids ) && ! in_array( $link['target'], $node_ids ) ) {
					continue;
				}
				if ( in_array( $link['source'], $node_ids ) && ! in_array( $link['target'], $node_ids ) ) {
					$link['target'] = 'end';
				}
				$split_links[] = $link;
			}

			return $split_links;
		}
	}
}
