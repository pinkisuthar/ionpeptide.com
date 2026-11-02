<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Email_Conversations' ) && BWFAN_Common::is_pro_3_0() ) {

	/**
	 * Bwfan Email Conversations
	 *
	 * @since 1.0.0
	 */
	#[AllowDynamicProperties]
	class BWFAN_Email_Conversations {

		private static $ins = null;

		/** Lookups prefetched for the list being built: messages by track id, merge tags by engagement, sources by owner. */
		private $batch = null;
		public $track_open_url = '';
		public $track_click_utm_parameters = array();
		public $body;
		public $track_id;
		public $hash_code;
		public static $subject_merge_tag = [];
		public $engagement_mode = 'email';

		public $contact = null;

		public static $MODE_EMAIL = 1;
		public static $MODE_SMS = 2;
		public static $MODE_WHATSAPP = 3;
		public static $MODE_NOTIFICATION = 4;

		public static $TYPE_AUTOMATION = 1;
		public static $TYPE_CAMPAIGN = 2;
		public static $TYPE_NOTE = 3;
		public static $TYPE_EMAIL = 4;
		public static $TYPE_SMS = 5;
		public static $TYPE_INCENTIVE = 6;

		public static $TYPE_TRANSACTIONAL = 9;

		public static $STATUS_DRAFT = 1;
		public static $STATUS_SEND = 2;
		public static $STATUS_ERROR = 3;
		public static $STATUS_BOUNCED = 4;

		public static $query_cache = [];

		public static $link_id = null;

		public $days = array(
			'monday'    => 1,
			'tuesday'   => 2,
			'wednesday' => 3,
			'thursday'  => 4,
			'friday'    => 5,
			'saturday'  => 6,
			'sunday'    => 7,
		);

		public static $template = null;
		public static $template_body = null;

		/**
		 *   Construct
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'handle_track_open' ), 99 );
			add_action( 'plugins_loaded', array( $this, 'handle_track_click' ), 100 );
			add_action( 'plugins_loaded', array( $this, 'modify_unsubscribe_link' ), 101 );

			add_filter( 'bwfan_sendemail_make_data', array( $this, 'get_subject_mergetags' ), 10, 2 );
			add_action( 'bwfan_conversation_sendemail_action', array( $this, 'updating_email_conversation_status' ), 10, 3 );
			add_action( 'bwfan_sendsms_action_response', array( $this, 'update_engagement_status' ), 10, 2 );
			add_action( 'bwfan_external_global_settings', array( $this, 'bwfan_order_conversation_settings' ), 10, 1 );
		}

		public static function get_instance() {
			if ( null === self::$ins ) {
				self::$ins = new self();
			}

			return self::$ins;
		}

		/**
		 * Get subject and pre header merge tags and assign to class properties
		 *
		 * @param $data_to_set
		 * @param $task_meta
		 *
		 * @return mixed
		 */
		public function get_subject_mergetags( $data_to_set, $task_meta ) {

			if ( empty( $task_meta['data']['subject'] ) && empty( $task_meta['data']['preheading'] ) ) {
				return $data_to_set;
			}
			$subject    = [];
			$pre_header = [];

			if ( ! empty( $task_meta['data']['subject'] ) ) {
				$subject = method_exists( 'BWFAN_Common', 'fetch_merge_tags' ) ? BWFAN_Common::fetch_merge_tags( $task_meta['data']['subject'] ) : $task_meta['data']['subject'];
			}

			if ( ! empty( $task_meta['data']['preheading'] ) ) {
				$pre_header = method_exists( 'BWFAN_Common', 'fetch_merge_tags' ) ? BWFAN_Common::fetch_merge_tags( $task_meta['data']['preheading'] ) : $task_meta['data']['preheading'];
			}

			self::$subject_merge_tag = array_merge( $subject, $pre_header );

			return $data_to_set;
		}

		/**
		 * Add tracking code in email body
		 *
		 * @param $body
		 * @param $data
		 * @param $hash_code
		 * @param $automation_id
		 * @param false $automation_open_click
		 * @param int $mode
		 *
		 * @return array|mixed|string|string[]|null
		 */
		public function add_tracking_code( $body, $data, $hash_code, $automation_id, $automation_open_click = false, $mode = 1 ) {
			$body = BWFAN_Common::bwfan_correct_protocol_url( $body );
			/** Check for click tracking */
			if ( $automation_open_click ) {
				$this->track_click_utm_parameters['bwfan-track-action'] = 'click';
				$this->track_click_utm_parameters['bwfan-track-id']     = $hash_code;
			}

			$cid     = $data['cid'];
			$contact = new BWFCRM_Contact( $cid );
			if ( $contact->is_contact_exists() ) {
				$uid                                           = $contact->contact->get_uid();
				$this->track_click_utm_parameters['bwfan-uid'] = $uid;
				$this->contact                                 = $contact;
			}

			$is_disable_click_tracking = apply_filters( 'bwfan_disable_click_tracking_for_automation_' . $automation_id, false );

			/** Replace URLs for click tracking */
			if ( ! empty( $this->track_click_utm_parameters ) && false === $is_disable_click_tracking ) {
				$this->track_click_utm_parameters = apply_filters( 'bwfan_track_click_utm_parameters', $this->track_click_utm_parameters, $data );
				$this->body                       = $body;
				$this->engagement_mode            = $mode === self::$MODE_EMAIL ? 'email' : ( $mode === self::$MODE_SMS ? 'sms' : 'whatsapp' );
				// Pre-load template cache links to avoid database queries for cached links
				BWFAN_Core()->conversation->template = self::$template;
				$cached_links = array();
				if ( ! empty( self::$template['data'] ) ) {
					$template_data = json_decode( self::$template['data'], true );
					$cached_links  = ! empty( $template_data['links'] ) ? $template_data['links'] : array();
				}

				$old_links = array_keys( $cached_links );
				$new_links = array();
				$regex_pattern = BWFAN_Common::get_regex_pattern( 'email' !== $this->engagement_mode ? 3 : 1 );
				$body          = preg_replace_callback( $regex_pattern, function ( $matches ) use ( $hash_code, $data, $cached_links, &$new_links ) {
					/** According to Href (1) regex, URL is at 1 index. And for Link (3) Regex, 0 index. */
					$url = 'email' !== $this->engagement_mode ? $matches[0] : $matches[1];

					/** Check url needs to exclude from click track */
					if ( BWFAN_Common::is_exclude_url( $url ) || isset( $cached_links[ $url ] ) ) {
						return $matches[0];
					}

					/** Exclude click tracking for unsubscribe link and view email browser link*/
					if ( false === strpos( $url, 'bwfan-action=unsubscribe' ) && false === strpos( $url, 'bwfan-action=view_in_browser' ) ) {
						$new_links[] = $url;
						return $matches[0];
					}

					/** Add step id in unsubscribe link */
					if ( false !== strpos( $url, 'bwfan-action=unsubscribe' ) ) {
						$link = str_replace( 'amp;', '', $url );

						/** If step id exists in data then no need to pass track id */
						if ( ! empty( $data['step_id'] ) ) {
							$url = add_query_arg( [
								'sid' => $data['step_id'],
							], $link );
						} else {
							$url = add_query_arg( [
								'bwfan-track-id' => $hash_code,
								'bwfan-link'     => rawurlencode( $link )
							], home_url() );
						}
					}

					/** Add hash_code if view email browser link */
					if ( false !== strpos( $url, 'bwfan-action=view_in_browser' ) ) {
						/** Get hash code from url */
						$bwfan_ehash = filter_input( INPUT_GET, 'bwfan-ehash', FILTER_UNSAFE_RAW );
						$bwfan_ehash = $bwfan_ehash ? sanitize_text_field( $bwfan_ehash ) : '';

						/** If hashcode is set in url then add hash */
						$url = empty( $bwfan_ehash ) ? add_query_arg( array(
							'bwfan-ehash' => $hash_code
						), $url ) : '#';
					}

					/** In case of Href regex, replace old URL with new one in 'HREF' string, otherwise return */
					return 'email' !== $this->engagement_mode ? $url : str_replace( $matches[1], $url, $matches[0] );
				}, $body );

			// Merge new and cached links, process all in one loop
			$all_links = array_unique( array_merge( $old_links, $new_links ) );
			$url_replacements = [];

			foreach ( $all_links as $link ) {
				// Use cached hash if available, otherwise get/create hash (DB query only for new links)
				// Pass false to prevent individual template updates (batch update at end)
				$l_hash = isset( $cached_links[ $link ] ) ? $cached_links[ $link ] : ( $cached_links[ $link ] = BWFAN_Core()->conversation->get_link_hash( $link, $data, false ) );
				$url_replacements[ $link ] = $this->append_tracking_in_url( $link, $l_hash );
			}

			// Sort by length (longest first) to avoid partial matches, then replace all at once
			if ( ! empty( $url_replacements ) ) {
				uksort( $url_replacements, function( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				} );
				$url_replacements_keys = array_keys( $url_replacements );

				/** For email mode, replace only href with href="url" or href='url' */
				if ( 'email' === $this->engagement_mode ) {
					/**
					 * Use a single regex pass to replace URLs in href attributes.
					 * Matches: href="url", href='url', or href=url (no quotes), preserving the original quote style.
					 */
					$body = preg_replace_callback(
						'#href=(["\']?)([^"\'>\s]+)\1#i',
						function ( $matches ) use ( $url_replacements ) {
							$quote = $matches[1];
							$url   = $matches[2];
							if ( isset( $url_replacements[ $url ] ) ) {
								return 'href=' . $quote . $url_replacements[ $url ] . $quote;
							}
							// No replacement for this URL; return the original match.
							return $matches[0];
						},
						$body
					);
				} else {
					// Replace all URLs in one operation (sorted order prevents partial matches) for other modes
					$body = str_replace( $url_replacements_keys, array_values( $url_replacements ), $body );
				}
			}

				// Batch update template with all new links at once
				if ( ! empty( $new_links ) && ! empty( self::$template['ID'] ) ) {
					$template_data = ! empty( self::$template['data'] ) ? json_decode( self::$template['data'], true ) : array();
					$template_data['links'] = $cached_links;

					// Update template once with all links
					BWFAN_Model_Templates::bwfan_update_template( self::$template['ID'], array(
						'data' => wp_json_encode( $template_data ),
					) );

					// Update the static template cache
					self::$template['data'] = wp_json_encode( $template_data );
				}
			}

			/** Add image for email open tracking */
			if ( $automation_open_click && 1 === $mode ) {
				$this->track_open_url = add_query_arg( array(
					'bwfan-track-action' => 'open',
					'bwfan-track-id'     => $hash_code,
				), home_url() );
				$this->track_open_url = apply_filters( 'bwfan_track_open_url', $this->track_open_url, $data );
				$old_body             = $body;
				$body                 = preg_replace_callback( '/<\/body[^>]*>/', array( $this, 'append_tracking_pixel' ), $body, 1 );

				if ( $old_body === $body ) {
					$body = $body . '<img src="' . $this->track_open_url . '" height="1" width="1" alt="" style="height:0;display:inherit">';
				}
			}
			$this->body = $body;

			return $this->body;
		}

		/** modify email body content before sending
		 *
		 * @param $body
		 * @param $data
		 *
		 * @return string|string[]|null
		 */
		public function bwfan_modify_email_body_data( $body, $data ) {
			$action_obj = BWFAN_Core()->integration->get_action( 'wp_sendemail' );
			if ( null === $action_obj && class_exists( 'BWFAN_Wp_Sendemail' ) ) {
				/** Try to get the instance directly */
				$action_obj = BWFAN_Wp_Sendemail::get_instance();
			}
			if ( null === $action_obj ) {
				return $body;
			}

			if ( true === $action_obj->is_preview ) {
				$body = BWFAN_Common::decode_merge_tags( $body );

				return $body;
			}

			$this->body = $this->insert_automation_conversation( $data, $body, self::$MODE_EMAIL );

			$data['conversation_id']   = $this->track_id;
			$data['subject_merge_tag'] = self::$subject_merge_tag;
			$data['hash_code']         = $this->hash_code;
			$action_obj->set_data( $data );

			return $this->body;
		}

		/**
		 * Bwfan Modify Sms Body Data
		 *
		 * @since 1.0.0
		 */
		public function bwfan_modify_sms_body_data( $sms_body, $data ) {
			if ( ! empty( $data['test'] ) ) {
				return $sms_body;
			}

			return $this->insert_automation_conversation( $data, $sms_body, self::$MODE_SMS );
		}

		/**
		 * Insert engagement in DB
		 *
		 * @param $data
		 * @param $body
		 * @param $mode
		 *
		 * @return array|mixed|string|string[]|null
		 */
		public function insert_automation_conversation( $data, $body, $mode ) {
			$automation_id   = ! empty( $data['automation_id'] ) ? $data['automation_id'] : 0;
			$step_id         = ! empty( $data['step_id'] ) ? $data['step_id'] : 0;
			$email           = isset( $data['email'] ) ? $data['email'] : '';
			$phone           = isset( $data['phone'] ) ? $data['phone'] : '';
			$template_type   = isset( $data['template'] ) ? $data['template'] : '';
			$is_track_enable = self::is_automation_open_click_track( $automation_id );
			$send_to         = in_array( $mode, [ self::$MODE_SMS, self::$MODE_WHATSAPP ], true ) ? $phone : $email;
			$hash_code       = wp_generate_password( 32, false );
			$contact_email   = '';
			$cid             = 0;
			if ( isset( $data['contact_id'] ) && ! empty( $data['contact_id'] ) ) {
				$contact       = new WooFunnels_Contact( '', '', '', $data['contact_id'] );
				$contact_email = $contact->get_email();
				$cid           = $contact->get_id();
			}
			$e_cid       = ( $send_to !== $contact_email ) ? BWFAN_Common::get_cid_from_contact( $email, 0, $phone ) : $cid;
			$create_time = current_time( 'mysql', 1 );

			/** Template Addition */
			$subject = $mode === self::$MODE_EMAIL && isset( $data['subject'] ) ? $data['subject'] : '';
			if ( empty( $subject ) && $mode === self::$MODE_NOTIFICATION ) {
				$subject = isset( $data['notification_title'] ) ? $data['notification_title'] : $subject;
			}

			$data['cid'] = $cid;
			$template_id = self::check_already_exists_template( $subject, $body, $mode, $data );
			$body        = ! empty( self::$template_body ) ? self::$template_body : $body;

			$data['template_id'] = $template_id;

            $insert_data = array(
                'cid'           => $e_cid,
                'hash_code'     => $hash_code,
                'created_at'    => $create_time,
                'updated_at'    => $create_time,
                'mode'          => $mode,
                'send_to'       => $send_to,
                'type'          => self::$TYPE_AUTOMATION,
                'open'          => 0,
                'click'         => 0,
                'oid'           => $automation_id,
                'sid'           => $step_id,
                'author_id'     => get_current_user_id(),
                'tid'           => $template_id,
                'o_interaction' => '',
                'c_interaction' => '',
                'c_status'      => self::$STATUS_DRAFT,
            );

			/** Insert Conversation, (Before adding merge tags) */
			BWFAN_Model_Engagement_Tracking::insert_ignore( $insert_data, [
				'%d', // cid
				'%s', // hash_code
				'%s', // created_at
				'%s', // updated_at
				'%d', // mode
				'%s', // send_to
				'%d', // type
				'%d', // open
				'%d', // click
				'%d', // oid
				'%d', // sid
				'%d', // author_id
				'%d', // tid
				'%s', // o_interaction
				'%s', // c_interaction
				'%d', // c_status
			] );
			$this->track_id = BWFAN_Model_Engagement_Tracking::insert_id();
			$body           = BWFAN_Common::correct_shortcode_string( $body, $data['template'] );
			/** Fetch Merge tags and add to Conversation Meta, for quick uses */
			$subject_merge_tags = self::get_email_merge_tags( $subject, $template_type );
			$merge_tags         = self::get_email_merge_tags( $body, $template_type );
			$merge_tags         = ! empty( $subject_merge_tags ) ? array_merge( $merge_tags, $subject_merge_tags ) : $merge_tags;
			ksort( $merge_tags );
			self::insert_conversation_meta( $this->track_id, $merge_tags );

            /** Merge tags replaced body */
            if ( method_exists( 'BWFAN_Common', 'replace_merge_tags' ) ) {
                $body = BWFAN_Common::replace_merge_tags( $body, $merge_tags, $cid );
            } else {
                foreach ( $merge_tags as $tag => $value ) {
                    $body = str_replace( $tag, $value, $body );
                }
            }

            $global_settings        = BWFAN_Common::get_global_settings();
            $disable_click_tracking = isset( $global_settings['bwfan_disable_sms_tracking'] ) && ! empty( $global_settings['bwfan_disable_sms_tracking'] ) ? $global_settings['bwfan_disable_sms_tracking'] : 0;

            /** return body without adding tracking detail in url if disabled and mode not email */
            if ( $disable_click_tracking && $mode !== self::$MODE_EMAIL ) {
                return $body;
            }

            /** Add engagement type for links it will be use when saving links */
            $data['type'] = self::$TYPE_AUTOMATION;
            /** Add tracking code */
            $body = $this->add_tracking_code( $body, $data, $hash_code, $automation_id, $is_track_enable, $mode );

            if ( $mode === self::$MODE_NOTIFICATION ) {
                return [
                    'body' => $body,
                    'url'  => $this->add_tracking_code( $data['notification_url'], $data, $hash_code, $automation_id, $is_track_enable, $mode ),
                ];
            }

            return $body;
        }

		/**
		 * Checking template exists
		 *
		 * @param $template_subject
		 * @param $template_body
		 * @param int $type
		 * @param array $data
		 * @param bool $create_if_canned
		 *
		 * @return int
		 */
		public static function check_already_exists_template( $template_subject, $template_body, $type = 1, $data = [], $create_if_canned = true ) {
			/** Reset template body */
			self::$template_body = null;

			/** Decode mail footer merge tag */
			$template_body = self::decode_mail_footer( $template_body );

			$create_time = current_time( 'mysql', 1 );
			$mode        = isset( $data['template'] ) ? intval( $data['template'] ) : 1;
			unset( $data['template'] );

			/** Generate hash for template lookup */
			$template_hash = sha1( $template_body );

			$templates_data = array(
				'template'      => $template_body,
				'subject'       => $template_subject,
				'type'          => $type,
				'mode'          => $mode,
				'created_at'    => $create_time,
				'updated_at'    => $create_time,
			);

			if ( ! empty( $data ) ) {
				$templates_data['data'] = wp_json_encode( $data );
			}

			$canned_query = ( true === $create_if_canned ) ? ' AND canned = 0' : '';
			global $wpdb;
			$table_name = $wpdb->prefix . 'bwfan_templates';

			$global_settings = BWFAN_Common::get_global_settings();

			/** Check if database update is complete, key __db_update_3_6_5_completed is not set in global settings */
			if ( empty( $global_settings['__db_update_3_6_5_completed'] ) ) {
				$last_id = bwf_options_get( 'last_template_hash_id' );
				/** Check if template_hash column exists (for backward compatibility) */
				$column_exists = $wpdb->get_row( "SHOW COLUMNS FROM {$table_name} LIKE 'template_hash'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; rest hardcoded
				if ( ! empty( $column_exists ) && '' === $last_id ) {
					$global_settings['__db_update_3_6_5_completed'] = true;
					update_option( 'bwfan_global_settings', $global_settings );
				}
			}

			/** Use hash for lookup if database update is complete, otherwise fallback to old method */
			if ( ! empty( $global_settings['__db_update_3_6_5_completed'] ) ) {
				$templates_data['template_hash'] = $template_hash;
				$query = $wpdb->prepare( 'SELECT `ID` FROM ' . $table_name . ' WHERE `template_hash` = %s AND `subject` = %s' . $canned_query . ' LIMIT 1', $template_hash, $template_subject ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; canned clause hardcoded; values via %s
			} else {
				/** Fallback to old method for backward compatibility */
				$query = $wpdb->prepare( 'SELECT `ID` FROM ' . $table_name . ' WHERE `template` = %s AND `subject` = %s' . $canned_query . ' LIMIT 1', $template_body, $template_subject ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table from $wpdb->prefix; canned clause hardcoded; values via %s
			}

			$core_cache_obj = WooFunnels_Cache::get_instance();

			$template_id = $core_cache_obj->get_cache( md5( $query ), 'fka-automation' );
			if ( false === $template_id ) {
				$template_data = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above

				/** Fix collation issue */
				if ( false !== strpos( $wpdb->last_error, 'collations' ) ) {
					$wp_collation = $wpdb->get_var( "SELECT @@collation_database" ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( ! empty( $wp_collation ) && false !== strpos( $wp_collation, 'unicode' ) ) {
						$wp_collation = str_replace( 'unicode', 'general', $wp_collation );
					}

					if ( empty( $wp_collation ) ) {
						$wp_collation = 'utf8mb4_general_ci';
					}

					$charset = ( ! empty( $wpdb->charset ) && 'utf8mb3' !== $wpdb->charset ) ? $wpdb->charset : 'utf8mb4';
					$wpdb->query( $wpdb->prepare( "SET NAMES %s COLLATE %s", $charset, $wp_collation ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$template_data = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare() above
				}

				if ( ! empty( $template_data[0]['ID'] ) ) {
					$template_id = intval( $template_data[0]['ID'] );
					$core_cache_obj->set_cache( md5( $query ), $template_id, 'fka-automation' );
					self::$template = $template_data[0];

					return $template_id;
				}

				BWFAN_Model_Templates::insert( $templates_data );

				$template_id = BWFAN_Model_Templates::insert_id();
				$core_cache_obj->set_cache( md5( $query ), $template_id, 'fka-automation' );
			}
			$templates_data['ID'] = $template_id;
			self::$template       = $templates_data;

			return $template_id;
		}

		public static function get_email_merge_tags( $body, $type = '' ) {
			$stripped_merge_tags = array();

			if ( is_array( $body ) ) {
				return $stripped_merge_tags;
			}

			$merge_tags = array();
			if ( method_exists( 'BWFAN_Common', 'fetch_merge_tags' ) ) {
				$merge_tags = BWFAN_Common::fetch_merge_tags( $body );
			} else {
				preg_match_all( '/\{\{(.*?)\}\}/', $body, $more_merge_tags );

				if ( is_array( $more_merge_tags[0] ) && count( $more_merge_tags[0] ) > 0 ) {
					$merge_tags = array_filter( array_values( $more_merge_tags[0] ) );
				}
			}

			if ( empty( $merge_tags ) ) {
				return $stripped_merge_tags;
			}

			/** Checking if merge tag in available in subject */
			if ( ! empty( self::$subject_merge_tag ) ) {
				$merge_tags = array_unique( array_merge( $merge_tags, self::$subject_merge_tag ) );
			}

			foreach ( $merge_tags as $tag ) {
				if ( isset( $stripped_merge_tags[ $tag ] ) ) {
					continue;
				}
				$decoded_val = BWFAN_Common::decode_merge_tags( $tag );
				/** Check for block editor inside mergetags */
				if ( 'block' === $type || 5 === $type ) {
					$decoded_val = BWFAN_Common::decode_merge_tags( $decoded_val );
				}

				$stripped_merge_tags[ $tag ] = $decoded_val;
			}

			return $stripped_merge_tags;
		}

		public static function insert_conversation_meta( $track_id, $merge_tags = array(), $err_msg = false, $conditional_set = [] ) {
			$meta = array();

			if ( ! empty( $merge_tags ) ) {
				$meta['merge_tags'] = $merge_tags;
			}

			if ( ! empty( $err_msg ) ) {
				$meta['error_msg'] = $err_msg;
			}

			if ( ! empty( $conditional_set ) ) {
				$meta['conditional_set'] = $conditional_set;
			}

			foreach ( $meta as $key => $value ) {
				$data = array(
					'eid'        => $track_id,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
					'meta_value' => is_array( $value ) ? wp_json_encode( $value ) : $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- column of the plugin own meta table row (insert/update by ID), not a WP_Query meta lookup
				);

				BWFAN_Model_Engagement_Trackingmeta::insert( $data );
			}
		}

        /**
         * @param $matches
         *
         * @return string
         */
        public function append_tracking_pixel( $matches ) {
            return '<img src="' . esc_url( $this->track_open_url ) . '" height="1" width="1" alt="" style="height:0;display:inherit">' . $matches[0];
        }

		/**
		 * Append tracking code
		 *
		 * @param $url
		 * @param $l_hash
		 *
		 * @return mixed|string
		 */
		public function append_tracking_in_url( $url, $l_hash = '' ) {
			if ( empty( $url ) ) {
				return $url;
			}

			/** escape url with mailto **/
			if ( false !== strpos( $url, 'mailto:' ) || false !== strpos( $url, 'tel:' ) ) {
				return $url;
			}

			/** Skip tracking for URLs whose clean form exceeds the DB column size */
			if ( strlen( BWFAN_Core()->conversation->get_cleaned_url( $url ) ) > 190 ) {
				return $url;
			}

			$is_link_trigger = apply_filters( 'bwfan_is_link_trigger_url', false, $url );
			$home_url        = $url;
			if ( false === $is_link_trigger ) {
				/** When multiple links and if unset because of link trigger URL, then needs to add again */
				$this->track_click_utm_parameters['bwfan-track-action'] = 'click';
				if ( ! empty( $l_hash ) ) {
					$this->track_click_utm_parameters['l_hash'] = $l_hash;
				}
				$this->track_click_utm_parameters['bwfan-link'] = rawurlencode( str_replace( 'amp;', '', $url ) );
				$home_url                                       = home_url();
			} else {
				unset( $this->track_click_utm_parameters['bwfan-track-action'] );
				unset( $this->track_click_utm_parameters['bwfan-link'] );

				/** Add the Auth Hash */
				if ( bwfan_is_autonami_pro_active() && ! is_null( $this->contact ) ) {
					$auth_hash = BWFCRM_Core()->link_trigger_handler->get_auth_hash( $this->contact, $home_url );
					if ( false !== $auth_hash ) {
						$this->track_click_utm_parameters['bwfan-auth'] = $auth_hash;
					}
				}
			}

			return add_query_arg( $this->track_click_utm_parameters, $home_url );
		}

		/**
		 * Handle open tracking code
		 *
		 * @return void
		 */
		public function handle_track_open() {
			if ( ! isset( $_GET['bwfan-track-action'] ) || ! isset( $_GET['bwfan-track-id'] ) || 'open' !== $_GET['bwfan-track-action'] || empty( $_GET['bwfan-track-id'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			/** Checking source of open */
			if ( self::skipping_user_agent() ) {
				return;
			}

			global $wpdb;
			$track_id = filter_input( INPUT_GET, 'bwfan-track-id', FILTER_UNSAFE_RAW );
			$track_id = $track_id ? sanitize_text_field( $track_id ) : '';
			$query    = $wpdb->prepare( "SELECT * FROM {table_name} WHERE `hash_code` = %s AND `c_status` = 2 LIMIT 0,1", $track_id ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$get_row  = BWFAN_Model_Engagement_Tracking::get_results( $query );

            /** If no engagement found */
            if ( ! is_array( $get_row ) || 0 === count( $get_row ) ) {
                exit;
            }

            if ( true === $this->should_skip_open_click_track( $track_id, $get_row ) ) {
                exit;
            }

            $this->record_tracking( $track_id, 'open', $get_row );
            $image_path = BWFAN_PLUGIN_DIR . '/admin/assets/img/blank.gif';

            // render image
            header( 'Content-Type: image/gif' );
            header( 'Pragma: public' ); // required
            header( 'Expires: 0' ); // no cache
            header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
            header( 'Cache-Control: private', false );
            header( 'Content-Disposition: attachment; filename="blank.gif"' );
            header( 'Content-Transfer-Encoding: binary' );
            header( 'Content-Length: ' . filesize( $image_path ) ); // provide file size
            readfile( $image_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- serves the 1x1 email open-tracking GIF; WP_Filesystem has no streaming read

            exit;
        }

        /**
         * Saving tracking details of email against a contact
         *
         * @param $bwfan_track_id
         * @param $type
         * @param $get_row
         *
         * @return void
         */
        public function record_tracking( $bwfan_track_id, $type, $get_row ) {
            if ( empty( $bwfan_track_id ) || empty( $type ) ) {
                return;
            }

            if ( empty( $get_row ) || ! is_array( $get_row ) || ! isset( $get_row[0][ $type ] ) ) {
                return;
            }

            $day            = $get_row[0]['day'];
            $hour           = $get_row[0]['hour'];
            $count          = $get_row[0][ $type ];
            $first_o_c      = $get_row[0][ 'f_' . $type ];
            $action         = ( $type === 'click' ) ? 'c' : 'o';
            $interactions   = ! empty( $get_row[0][ $action . '_interaction' ] ) ? json_decode( $get_row[0][ $action . '_interaction' ], true ) : array();
            $current_time   = current_time( 'mysql' );
            $interactions[] = $current_time;

            /** Checking last 10 interactions */
            if ( count( $interactions ) > 10 ) {
                /** Remove first interaction */
                array_shift( $interactions );
            }
            $count ++;
            $values = array(
                'updated_at'             => current_time( 'mysql', 1 ),
                $type                    => $count,
                $action . '_interaction' => wp_json_encode( $interactions ),
            );

			/** Check if action clicked and open count is zero */
			if ( 'c' === $action && 1 === intval( $get_row[0]['mode'] ) && 0 === absint( $get_row[0]['open'] ) ) {
				$o_time        = current_time( 'timestamp' );
				$o_time        = $o_time - 20;
				$o_time        = gmdate( "Y-m-d H:i:s", $o_time );
				$o_interaction = [ $o_time ];

                $values['open']          = 1;
                $values['f_open']        = $o_time;
                $values['o_interaction'] = wp_json_encode( $o_interaction );
            }

            if ( empty( $day ) ) {
                $values['day'] = current_time( 'N' );
            }

            if ( empty( $hour ) ) {
                $hour           = current_time( 'H' );
                $values['hour'] = $hour;
            }

            if ( empty( $first_o_c ) ) {
                $values[ 'f_' . $type ] = $current_time;
            }

            $where = array(
                'ID' => $get_row[0]['ID'],
            );
            BWFAN_Model_Engagement_Tracking::update( $values, $where );
            $cid = $get_row[0]['cid'];
            if ( empty( $cid ) ) {
                return;
            }

            $contact = BWFCRM_Model_Contact::get_contact_id( $cid );

            /** Set cookie */
            if ( $type === 'click' && is_object( $contact ) && ! empty( $contact->uid ) ) {
                BWFAN_Common::set_cookie( '_fk_contact_uid', $contact->uid, time() + 10 * 365 * 24 * 60 * 60 );
            }

            /** Set last open & click custom field */
            $this->update_contact_engagement( $cid, $type );

            /** Update contact status if needed */
            $this->maybe_subscribe_contact( $contact );

            /** Update link metrics */
            if ( 'click' === $type ) {
                self::update_link_metrics( $get_row[0] );
            }
        }

        /**
         * Update contact last open and click time
         *
         * @param $cid
         * @param $type
         *
         * @return void
         */
        public function update_contact_engagement( $cid, $type = 'open' ) {
            $current_time = current_time( 'mysql' );
            $fields       = [];

            $col = BWFAN_Model_Fields::get_field_by_slug( "last-open" );
            if ( isset( $col['ID'] ) ) {
                $fields[ 'f' . $col['ID'] ] = $current_time;
            }

            if ( $type === 'click' ) {
                $col = BWFAN_Model_Fields::get_field_by_slug( "last-click" );
                if ( isset( $col['ID'] ) ) {
                    $fields[ 'f' . $col['ID'] ] = $current_time;
                }
            }

            if ( empty( $fields ) ) {
                return;
            }

            $where = array( 'cid' => intval( $cid ) );
            BWF_Model_Contact_Fields::update( $fields, $where );
        }

		/**
		 * Mark contact subscribed if status is bounce or soft-bounce
		 *
		 * @param $contact - db row
		 *
		 * @return void
		 */
		public function maybe_subscribe_contact( $contact ) {
			// skip tracking if open from admin
			if ( is_admin() ) {
				return;
			}

			/**
			 * BWFCRM_Model_Contact::get_contact_id() is a get_row(), so it hands back null when the row is
			 * gone, and the contact is looked up by uid below. Without a uid the lookup yields an empty
			 * contact whose id is 0, which would go on to signal a transition for nobody.
			 */
			if ( ! is_object( $contact ) || empty( $contact->uid ) ) {
				return;
			}

			/** BWFCRM_Contact is only defined when PRO 3.0+ is active */
			if ( ! class_exists( 'BWFCRM_Contact' ) ) {
				return;
			}

			$contact_obj = new WooFunnels_Contact( '', '', '', '', $contact->uid );
			$get_contact = new BWFCRM_Contact( $contact_obj );

			$allowed_status = apply_filters( 'bwfan_contact_status_to_resubscribe', [ $get_contact::$STATUS_BOUNCED, $get_contact::$STATUS_SOFT_BOUNCED ] );
			if ( ! is_array( $allowed_status ) || ! in_array( intval( $contact->status ), $allowed_status, true ) ) {
				return;
			}

			$last_status = $get_contact->contact->get_meta( 'last_status' );
			$last_status = ( ! empty( $last_status ) && is_array( $last_status ) && isset( $last_status['status'] ) ) ? $last_status['status'] : '';

			if ( $last_status === $get_contact::$DISPLAY_STATUS_UNSUBSCRIBED ) {
				$get_contact->unsubscribe();
				return;
			}

			switch ( $last_status ) {
				case $get_contact::$DISPLAY_STATUS_BOUNCED:
					$last_status = $get_contact::$STATUS_BOUNCED;
					break;

				case $get_contact::$DISPLAY_STATUS_SOFT_BOUNCED:
					$last_status = $get_contact::$STATUS_SOFT_BOUNCED;
					break;

				case $get_contact::$DISPLAY_STATUS_COMPLAINT:
					$last_status = $get_contact::$STATUS_COMPLAINT;
					break;

				case $get_contact::$DISPLAY_STATUS_UNVERIFIED:
					$last_status = $get_contact::$STATUS_NOT_OPTED_IN;
					break;

				default:
					$last_status = $get_contact::$STATUS_OPTED_IN;
					break;
			}

			$status = ( $last_status === $get_contact::$STATUS_BOUNCED || $last_status === $get_contact::$STATUS_SOFT_BOUNCED )
				? $get_contact::$STATUS_OPTED_IN
				: $last_status;

			/**
			 * Kept as a raw single column write on purpose: this runs on every tracked open and click, and
			 * WooFunnels_Contact::save() would rewrite every contact column plus its child actors. The
			 * status condition also makes the heal atomic. Duplicate opens are routine (proxy prefetch,
			 * multi device, a click and an open arriving together) and each one reads the same pre-heal row,
			 * so an unconditional UPDATE would let every one of them think it made the transition. Here the
			 * WHERE keeps matched rows equal to changed rows, so a non zero result means THIS request won.
			 */
			global $wpdb;
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bwf_contact SET `status` = %d WHERE `id` = %d AND `status` != %d", $status, $contact->id, $status ) ); //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			/** Keep the in-memory contact, the instance held in the request cache, in sync with the row. */
			$get_contact->contact->set_status( $status );

			if ( $status === $get_contact::$STATUS_OPTED_IN ) {
				/**
				 * set_status() above arms is_subscribed so the NEXT save() fires the subscribed action.
				 * Nothing is saved here, so clear it and signal directly, otherwise the flag leaks into an
				 * unrelated later save and completes the goal a second time.
				 */
				$get_contact->contact->is_subscribed = false;

				/**
				 * The UPDATE bypasses the contact status API, so signal the transition it just made.
				 * Downstream goals (e.g. the "Contact Subscribes" automation goal) must fire once per heal.
				 *
				 * Open/click tracking requests exit at plugins_loaded (handle_track_open, priority 99),
				 * before event listeners register on init 8 via BWFAN_Load_Connectors::load_native_integrations.
				 * On those requests the action would fire with no listener and the event would be lost, so
				 * queue the event row directly instead — the events queue cron replays it through the same
				 * capture chain the listener's async call would have used.
				 */
				if ( $updated > 0 ) {
					if ( has_action( 'bwfcrm_after_contact_subscribed' ) ) {
						do_action( 'bwfcrm_after_contact_subscribed', $get_contact->contact, array(
							'restored_from_bounce' => 1,
							'last_status'          => $last_status,
						) );
					} else {
						BWFAN_Model_Automation_Events::insert_data( array(
							'source'               => 'autonami',
							'event'                => 'crm_contact_subscribed',
							'contact_id'           => $get_contact->contact->get_id(),
							'email'                => $get_contact->contact->get_email(),
							'restored_from_bounce' => 1,
							'last_status'          => $last_status,
						) );
					}
				}
			}
		}

		/**
		 * Handle click tracking code
		 *
		 * @return void
		 */
		public function handle_track_click() {
			$track_id     = filter_input( INPUT_GET, 'bwfan-track-id', FILTER_UNSAFE_RAW );
			$track_id     = $track_id ? sanitize_text_field( $track_id ) : '';
			$track_action = filter_input( INPUT_GET, 'bwfan-track-action', FILTER_UNSAFE_RAW );
			$track_action = $track_action ? sanitize_text_field( $track_action ) : '';
			if ( empty( $track_id ) || empty( $track_action ) || ( 'click' !== $track_action ) ) {
				return;
			}

			$link   = filter_input( INPUT_GET, 'bwfan-link' );
			$l_hash = filter_input( INPUT_GET, 'l_hash', FILTER_UNSAFE_RAW );
			$l_hash = $l_hash ? sanitize_text_field( $l_hash ) : '';
			if ( empty( $link ) && empty( $l_hash ) ) {
				$this->track_click_skip();

				return;
			}
			$engagement_data = $this->get_engagement_data();
			$link            = BWFAN_Common::validate_target_link( $link, $l_hash, $engagement_data );

			/** Checking source of click */
			if ( self::skipping_user_agent() ) {
				BWFAN_Common::wp_redirect( $link );
				exit;
			}

			/** Redirect if incentive email action */
			if ( false !== strpos( $link, 'bwfan-action=incentive' ) ) {
				$this->track_click_skip();
				BWFAN_Common::wp_redirect( $link );
				exit;
			}

			$link = self::validate_link( $link );
			if ( false === BWFAN_Common::bwfan_is_valid_link( $link ) ) {
				/** check if uid is available in the link */
				if ( strpos( $link, 'uid' ) !== false ) {
					/** Redirect to home */
					$home_url = esc_url_raw( home_url( '/?' ) );
					$link     = str_replace( 'http://?', $home_url, $link );
					$link     = str_replace( 'https://?', $home_url, $link );
					if ( false !== BWFAN_Common::bwfan_is_valid_link( $link ) ) {
						/** Re-encode the incoming query: add_query_arg() does not encode values, so a decoded bwfan-link containing & or # used to leak into the redirect */
						$home_url = add_query_arg( rawurlencode_deep( wp_unslash( $_GET ) ), home_url( '/' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public link opened from an email; such links cannot carry a nonce
						$home_url = add_query_arg( array(
							'bwfan-link' => rawurlencode( $link )
						), $home_url );
						BWFAN_Common::wp_redirect( $home_url );
						exit;
					}
				}

				$this->track_click_skip();
				BWFAN_Common::wp_redirect( $link );
				exit;
			}

			$link = bwfan_is_autonami_pro_active() ? BWFCRM_Core()->link_trigger_handler->may_append_query_arg( $link ) : $link;

			/** Record tracking */
			$this->track_click_skip( $link, $engagement_data );

			/** Filter to modify link after click tracking */
			$link = apply_filters( 'bwfan_modify_target_link', $link );
			BWFAN_Common::wp_redirect( $link );
			exit;
		}

		/**
		 * Append step id in unsubscribe link
		 *
		 * @return void
		 */
		public function modify_unsubscribe_link() {
			$track_id = filter_input( INPUT_GET, 'bwfan-track-id', FILTER_UNSAFE_RAW );
			$track_id = $track_id ? sanitize_text_field( $track_id ) : '';
			$link     = filter_input( INPUT_GET, 'bwfan-link' );
			if ( empty( $track_id ) || empty( $link ) ) {
				return;
			}

			if ( false === strpos( $link, 'bwfan-action=unsubscribe' ) ) {
				return;
			}

			$engagement_data = $this->get_engagement_data();
			$link            = BWFAN_Common::validate_target_link( $link, '', $engagement_data );
			if ( empty( $engagement_data ) ) {
				return;
			}
			/** Append sid in unsubscribe link */
			$link = add_query_arg( array(
				'sid' => $engagement_data[0]['sid'],
			), $link );
			$link = self::validate_link( $link );
			BWFAN_Common::wp_redirect( $link );
			exit;
		}

		/**
		 * Track Click Skip
		 *
		 * @since 1.0.0
		 */
		public function track_click_skip( $target_url = '', $get_row = [] ) {
			$track_id = filter_input( INPUT_GET, 'bwfan-track-id', FILTER_UNSAFE_RAW );
			$track_id = $track_id ? sanitize_text_field( $track_id ) : '';

			if ( empty( $get_row ) ) {
				$get_row = $this->get_engagement_data();
			}

			/** If no engagement found */
			if ( ! is_array( $get_row ) || 0 === count( $get_row ) ) {
				return;
			}

			/**
			 * Maybe machine interaction found
			 * Stop further processing and redirect to the given URL or home page
			 */
			if ( true === $this->should_skip_open_click_track( $track_id, $get_row ) ) {
				$target_url = empty( $target_url ) ? home_url() : $target_url;
				BWFAN_Common::wp_redirect( $target_url );
				exit;
			}

			$this->record_tracking( $track_id, 'click', $get_row );
		}

		/**
		 * Get Engagement Data
		 *
		 * @since 1.0.0
		 */
		public function get_engagement_data() {
			global $wpdb;
			$track_id  = filter_input( INPUT_GET, 'bwfan-track-id', FILTER_UNSAFE_RAW );
			$track_id  = $track_id ? sanitize_text_field( $track_id ) : '';
			$query     = $wpdb->prepare( "SELECT * FROM {table_name} WHERE `hash_code`=%s AND `c_status` = 2 LIMIT 0,1", $track_id );
			$query_md5 = md5( $query );

			if ( isset( self::$query_cache[ $query_md5 ] ) ) {
				return self::$query_cache[ $query_md5 ];
			}
			$data                            = BWFAN_Model_Engagement_Tracking::get_results( $query );
			self::$query_cache[ $query_md5 ] = $data;

			return $data;
		}

		/**
		 * @param $desc
		 * @param string $size
		 * @param string $position
		 *
		 * @return false|string
		 */
		public function add_description( $desc, $size = 'm', $position = 'top' ) {
			if ( empty( $desc ) ) {
				return '';
			}

		ob_start();
		?>
			<div class="bwfan_tooltip" data-size="<?php echo esc_attr( $size ); ?>">
				<span class="bwfan_tooltip_text" data-position="<?php echo esc_attr( $position ); ?>"><?php echo esc_js( $desc ); ?></span>
			</div>
		<?php
		$return = ob_get_clean();

		return $return;
	}


        /** checking if settings is on in automations
         *
         * @param $automation_id
         *
         * @return bool
         */
        public static function is_automation_open_click_track( $automation_id ) {

            return true;
        }

        /**
         * Get engagements by contact id
         *
         * @param $cid
         * @param $mode
         * @param int $offset
         * @param int $limit
         *
         * @return array|array[]
         */
        public function get_conversations_by_cid( $cid, $mode, $offset = 0, $limit = 0 ) {
            $cons = BWFAN_Model_Engagement_Tracking::get_conversations_by_cid( $cid, $mode, $offset, $limit );
            if ( ! is_array( $cons ) || empty( $cons ) ) {
                return array();
            }

            /** One query per lookup for the page, not three per row */
            $ids         = array();
            $untemplated = array();
            foreach ( $cons as $con ) {
                $ids[] = absint( $con['ID'] );
                if ( 0 === absint( $con['tid'] ) ) {
                    $untemplated[] = absint( $con['ID'] );
                }
            }

            $messages = array();
            if ( ! empty( $untemplated ) ) {
                foreach ( (array) BWFAN_Model_Message::get_message_by_track_id( $untemplated ) as $message ) {
                    if ( ! isset( $messages[ (int) $message['track_id'] ] ) ) {
                        $messages[ (int) $message['track_id'] ] = $message;
                    }
                }
            }

            $this->batch = array(
                'messages'   => $messages,
                'merge_tags' => BWFAN_Model_Engagement_Trackingmeta::get_merge_tags_for( $ids ),
                'sources'    => array(),
            );

            try {
                return array_map( function ( $con ) {
                    return $this->get_conversation_array( $con );
                }, $cons );
            } finally {
                $this->batch = null;
            }
        }

		/**
		 * Get Conversation By Cid
		 *
		 * @since 1.0.0
		 */
		public function get_conversation_by_cid( $cid ) {
			$con = BWFAN_Model_Engagement_Tracking::get_specific_rows( 'cid', $cid );
			if ( ! is_array( $con ) || empty( $con ) ) {
				return array();
			}

			return $this->get_conversation_array( $con );
		}

        /**
         * Get engagement data
         *
         * @param $con_id
         *
         * @return array
         */
        public function get_conversation_email( $con_id ) {
            $con = BWFAN_Model_Engagement_Tracking::get( $con_id );
            if ( ! is_array( $con ) || empty( $con ) || ! isset( $con['ID'] ) ) {
                return array( 'error' => __( 'Email Conversation doesn\'t exists', 'wp-marketing-automations' ) );
            }

            $template_id = absint( $con['tid'] );
            $template    = BWFAN_Model_Templates::get( $template_id );
            if ( empty( $template_id ) ) {
                $template = BWFAN_Model_Message::get_message_by_track_id( $con['ID'] );
            }
            if ( ! is_array( $template ) || empty( $template ) || ! isset( $template['ID'] ) ) {
                return array( 'error' => __( 'Template attached to Email not found', 'wp-marketing-automations' ) );
            }
            $notification_data = [];
            if ( intval( $con['mode'] ) === BWFAN_Email_Conversations::$MODE_NOTIFICATION ) {
                if ( isset( $template['data'] ) && ! empty( $template['data'] ) ) {
                    $template_data     = json_decode( $template['data'], true );
                    $notification_data = [
                        'title'                   => $template_data['notification_title'] ?? '',
                        'url'                     => $template_data['notification_url'] ?? '',
                        'image'                   => $template_data['notification_image'] ?? '',
                        'enable_large_image'      => $template_data['enable_large_image'] ?? '',
                        'large_image_url'         => $template_data['large_image_url'] ?? '',
                        'multiple_buttons_enable' => $template_data['multiple_buttons_enable'] ?? '',
                        'first_button_url'        => $template_data['first_button_url'] ?? '',
                        'first_button_title'      => $template_data['first_button_title'] ?? '',
                        'first_button_image'      => $template_data['first_button_image'] ?? '',
                        'second_button_enable'    => $template_data['second_button_enable'] ?? '',
                        'second_button_title'     => $template_data['second_button_title'] ?? '',
                        'second_button_url'       => $template_data['second_button_url'] ?? '',
                        'second_button_image'     => $template_data['second_button_image'] ?? '',
                    ];
                }
            }

			$subject       = isset( $template['subject'] ) ? $template['subject'] : '';
			$template_type = isset( $template['mode'] ) ? absint( $template['mode'] ) : 0;
			$template      = isset( $template['template'] ) ? $template['template'] : '';
			$template_mode = isset( $template['mode'] ) ? $template['mode'] : '';

			BWFAN_Merge_Tag_Loader::set_data( [ 'is_engagement' => true ] );

			BWFAN_Common::bwfan_before_send_mail( $template_type );

			$meta_data = BWFAN_Model_Engagement_Trackingmeta::get_meta( $con_id );
			$con_meta  = [];
			foreach ( $meta_data as $item ) {
				if ( isset( $item['meta_key'] ) && isset( $item['meta_value'] ) ) {
					$con_meta[ $item['meta_key'] ] = json_decode( $item['meta_value'], true );
				}
			}

			$merge_tags = $con_meta['merge_tags'] ?? '';

			if ( ! empty( $con_meta['conditional_set'] ) ) {
				BWFAN_Merge_Tag_Loader::set_data( [ 'conditional_set' => $con_meta['conditional_set'] ] );
			}

			$template                     = BWFAN_Common::correct_shortcode_string( $template, $template_type );
			$interaction                  = array();
			$interaction['o_interaction'] = isset( $con['o_interaction'] ) && ! empty( $con['o_interaction'] ) && intval( $con['mode'] ) === 1 ? json_decode( $con['o_interaction'], true ) : array();
			$interaction['c_interaction'] = isset( $con['c_interaction'] ) && ! empty( $con['c_interaction'] ) ? json_decode( $con['c_interaction'], true ) : array();


            $final_data = array();
            if ( ! empty( $interaction ) ) {
                foreach ( $interaction as $interaction_key => $interaction_data ) {
                    foreach ( $interaction_data as $data ) {
                        if ( 'o_interaction' === $interaction_key ) {
                            $final_data[ $data ] = __( 'Opened', 'wp-marketing-automations' );
                        } else {
                            $final_data[ $data ] = __( 'Clicked', 'wp-marketing-automations' );
                        }
                    }
                }
            }

            krsort( $final_data );

            $final_data = array_slice( $final_data, 0, 10 );
            $merge_tags = BWFAN_Model_Engagement_Trackingmeta::get_merge_tags( $con['ID'] );

            if ( is_array( $merge_tags ) && 0 < count( $merge_tags ) ) {
                $subject  = $this->parse_email_merge_tags( $subject, $merge_tags );
                $template = $this->parse_email_merge_tags( $template, $merge_tags, $con['cid'] );
            }

            if ( false !== strpos( $template, '{{contact_first_name}}' ) || false !== strpos( $template, '{{contact_last_name}}' ) ) {
                $bwf_contact = new WooFunnels_Contact( '', '', '', $con['cid'] );
                $template    = str_replace( [ '{{contact_first_name}}', '{{contact_last_name}}' ], [ $bwf_contact->get_f_name(), $bwf_contact->get_l_name() ], $template );
            }

			// Get remaining shortcode list in template
			$shortcode_list = BWFAN_Common::fetch_merge_tags( $template );

			if ( in_array( $template_type, [ 5, 7 ], true ) && class_exists( 'BWFCRM_Block_Editor' ) ) {
				$global_val = BWFCRM_Block_Editor::$global_settings_var;
				if ( ! empty( $global_val ) ) {
					$global_val_k = array_keys( $global_val );
					$global_val_v = array_values( $global_val );
					$template     = str_replace( $global_val_k, $global_val_v, $template );
				}
				// Get remaining shortcode list in template
				$shortcode_lists = get_shortcode_tags_in_content( $template );
				// Filter out block shortcodes
				$block_shortcode_list = array_filter( $shortcode_lists, function ( $tag ) {
					return strpos( $tag, 'bwfbe_' ) === 0;
				} );

				// Decode block shortcodes
				if ( ! empty( $block_shortcode_list ) && method_exists( 'BWFCRM_Block_Editor', 'decode_specific_blocks_before' ) ) {
					$template = BWFCRM_Block_Editor::decode_specific_blocks_before( $template, $block_shortcode_list );
				}
			}

			$data = array(
				'oid'           => $con['oid'],
				'type'          => $con['type'],
				'o_interaction' => ! empty( $con['o_interaction'] ) ? json_decode( $con['o_interaction'], true ) : [],
				'c_interaction' => ! empty( $con['c_interaction'] ) ? json_decode( $con['c_interaction'], true ) : [],
				'subject'       => $subject,
				'body'          => $template,
				'timeline'      => $final_data,
				'mode'          => $con['mode'],
				'template_mode' => $template_mode,
				'email'         => $con['send_to'],
				'empty_meta'    => empty( $meta_data ) && ! empty( $shortcode_list ),
			);
			if ( ! empty( $notification_data ) ) {
				$data['notification_data'] = $notification_data;
			}

            if ( empty( $merge_tags ) ) {
                $data['merge_tags'] = 0;
            }

			//Reset merge tag data
			BWFAN_Merge_Tag_Loader::reset_data();

			return $data;
		}

		/**
		 * Get Conversations Total By Cid
		 *
		 * @since 1.0.0
		 */
		public function get_conversations_total_by_cid( $cid ) {
			$count = BWFAN_Model_Engagement_Tracking::count( array(
				'cid' => array(
					'operator' => '%d',
					'value'    => $cid,
				),
			) );

			return empty( $count ) ? 0 : absint( $count );
		}

		/**
		 * Get Conversation Array
		 *
		 * @since 1.0.0
		 */
		public function get_conversation_array( $con ) {
			if ( ! is_array( $con ) || empty( $con ) ) {
				return array();
			}

            if ( null === $this->batch ) {
                $source = $this->get_source( $con );
            } else {
                $key = absint( $con['type'] ) . ':' . absint( $con['oid'] ) . ':' . ( isset( $con['author_id'] ) ? absint( $con['author_id'] ) : 0 );
                if ( ! array_key_exists( $key, $this->batch['sources'] ) ) {
                    $this->batch['sources'][ $key ] = $this->get_source( $con );
                }
                $source = $this->batch['sources'][ $key ];
            }
            if ( is_array( $source ) && ! empty( $source ) ) {
                $con['source'] = $source;
            }

            $con['is_tracking'] = ! empty( $con['hash_code'] );

            if ( isset( $con['author_id'] ) && absint( $con['author_id'] ) > 0 ) {
                $user = get_user_by( 'id', absint( $con['author_id'] ) );
                if ( $user instanceof WP_User ) {
                    $con['author'] = array(
                        'email' => $user->user_email,
                        'name'  => $user->display_name,
                        'id'    => $user->ID,
                        'link'  => add_query_arg( 'user_id', $user->ID, admin_url( 'user-edit.php' ) ),
                    );
                }
            }

            if ( 0 === absint( $con['tid'] ) ) {
                if ( null === $this->batch ) {
                    $template = BWFAN_Model_Message::get_message_by_track_id( $con['ID'] );
                } else {
                    $template = isset( $this->batch['messages'][ absint( $con['ID'] ) ] ) ? $this->batch['messages'][ absint( $con['ID'] ) ] : array();
                }
                $con['subject'] = ! empty( $template ) ? $template['subject'] : '';
                $con['message'] = ! empty( $template ) ? $template['template'] : '';
            }

            if ( null === $this->batch ) {
                $merge_tags = BWFAN_Model_Engagement_Trackingmeta::get_merge_tags( $con['ID'] );
            } else {
                $merge_tags = isset( $this->batch['merge_tags'][ absint( $con['ID'] ) ] ) ? $this->batch['merge_tags'][ absint( $con['ID'] ) ] : array();
            }
            if ( is_array( $merge_tags ) && 0 < count( $merge_tags ) ) {
                $con['subject'] = $this->parse_email_merge_tags( $con['subject'], $merge_tags, $con['cid'] );
            }

            unset( $con['hash_code'] );

            return $con;
        }

		/**
		 * Parse Email Merge Tags
		 *
		 * @since 1.0.0
		 */
		public function parse_email_merge_tags( $string, $merge_tags, $cid = 0 ) {
			if ( empty( $string ) || ! is_string( $string ) ) {
				return $string;
			}

            /** Merge tags replaced body */
            if ( method_exists( 'BWFAN_Common', 'replace_merge_tags' ) ) {
                $string = BWFAN_Common::replace_merge_tags( $string, $merge_tags, $cid );

                return $string;
            }

            foreach ( $merge_tags as $tag => $value ) {
                $string = str_replace( $tag, $value, $string );
            }

            return $string;
        }

		/**
		 * Get Source
		 *
		 * @since 1.0.0
		 */
		public function get_source( $con, $template_data = [] ) {
			if ( ! isset( $con['oid'] ) || ( ! absint( $con['oid'] ) > 0 && absint( $con['type'] ) < 4 ) ) {
				return false;
			}

            $oid = absint( $con['oid'] );
            switch ( absint( $con['type'] ) ) {
                case 1:
                    $automation = BWFAN_Model_Automations::get_automation_with_data( $oid );
                    if ( ! is_array( $automation ) || ! isset( $automation['meta'] ) ) {
                        return array(
                            'name' => __( 'NOT EXISTS', 'wp-marketing-automations' ),
                            'type' => __( 'Automation', 'wp-marketing-automations' ),
                        );
                    }

                    return array(
                        'id'     => $automation['ID'],
                        'name'   => isset( $automation['meta']['title'] ) ? $automation['meta']['title'] : ( $automation['title'] ?? '' ),
                        'status' => $automation['status'],
                        'type'   => __( 'Automation', 'wp-marketing-automations' ),
                        'link'   => add_query_arg( array(
                            'page' => 'autonami',
                            'path' => '/automation/' . $automation['ID'],
                        ), admin_url( 'admin.php' ) ),
                    );
                case 2:
                    if ( ! class_exists( 'BWFAN_Model_Broadcast' ) ) {
                        return array();
                    }

                    $campaign = BWFAN_Model_Broadcast::get( $oid );

                    if ( ! is_array( $campaign ) || ! isset( $campaign['data'] ) || empty( $campaign['data'] ) ) {
                        return array(
                            'name' => __( '-', 'wp-marketing-automations' ),
                            'type' => __( 'Broadcast', 'wp-marketing-automations' ),
                        );
                    }

                    return array(
                        'id'     => $campaign['id'],
                        'name'   => $campaign['title'],
                        'status' => $campaign['status'],
                        'type'   => __( 'Broadcast', 'wp-marketing-automations' ),
                        'link'   => add_query_arg( array(
                            'page' => 'autonami',
                            'path' => '/broadcast/' . $campaign['id'],
                        ), admin_url( 'admin.php' ) ),
                    );
                case 3:
                    if ( ! class_exists( 'BWFCRM_Note' ) ) {
                        return array(
                            'name' => __( 'MODULE NOT FOUND', 'wp-marketing-automations' ),
                            'type' => __( 'Note', 'wp-marketing-automations' ),
                        );
                    }

                    $note = new BWFCRM_Note( $oid );
                    if ( $note->exists() ) {
                        $note_data = $note->get_array();

                        return array(
                            'id'   => $note_data['id'],
                            'name' => $note_data['title'],
                            'type' => __( 'Note', 'wp-marketing-automations' ),
                            'link' => '',
                        );
                    }

                    return array(
                        'name' => __( 'NOT EXISTS', 'wp-marketing-automations' ),
                        'type' => __( 'Note', 'wp-marketing-automations' ),
                    );
                case 4:
                case 5:
                    $user = get_user_by( 'id', absint( $con['author_id'] ) );
                    if ( ! $user instanceof WP_User ) {
                        return array(
                            'name' => __( 'USER NOT FOUND', 'wp-marketing-automations' ),
                            'type' => '',
                        );
                    }

                    return array(
                        'id'   => $user->ID,
                        'name' => $user->display_name,
                        'type' => __( 'Direct Message', 'wp-marketing-automations' ),
                        'link' => get_edit_user_link( $user->ID ),
                    );
                case 6:
                    $feed = class_exists( 'BWFCRM_Form_Feed' ) ? new BWFCRM_Form_Feed( $oid ) : '';
                    if ( $feed instanceof BWFCRM_Form_Feed && $feed->is_feed_exists() ) {
                        return array(
                            'id'   => $feed->get_id(),
                            'name' => $feed->get_title(),
                            'type' => __( 'Form Feed', 'wp-marketing-automations' ),
                            'link' => add_query_arg( array(
                                'page' => 'autonami',
                                'path' => '/form/' . $feed->get_id(),
                            ), admin_url( 'admin.php' ) ),
                        );
                    }

                    return array(
                        'name' => __( 'FORM FEED NOT EXISTS', 'wp-marketing-automations' ),
                        'type' => __( 'Form Feed', 'wp-marketing-automations' ),
                    );
                case 9:
                    $data = [
                        'type' => __( 'Transactional', 'wp-marketing-automations' )
                    ];
                    if ( ! empty( $template_data ) && ! empty( $template_data['title'] ) ) {
                        if ( ! empty( $template_data['title'] ) ) {
                            $data['name'] = $template_data['title'];
                        }
                    }

                    return $data;
                default:
                    return false;
            }
        }

        /**
         * Updating email conversations status
         *
         * @param $email_instance
         * @param $body
         * @param $conversations
         */
        public function updating_email_conversation_status( $email_instance, $body, $conversations ) {

            if ( ! $email_instance instanceof BWFAN_Wp_Sendemail ) {
                return;
            }

            if ( empty( $conversations ) ) {
                return;
            }

            foreach ( $conversations as $conversation ) {
                if ( empty( $conversation['conversation_id'] ) ) {
                    continue;
                }
                $this->track_id = $conversation['conversation_id'];
                $sent           = true;
                if ( ! $conversation['res'] ) {
                    $sent = $email_instance->maybe_get_failed_mail_error();
                }
                $status    = 200;
                $error_msg = '';
                if ( true !== $sent ) {
                    $error_msg = is_array( $sent ) && ! empty( $sent['message'] ) ? $sent['message'] : __( 'Email not sent (Error Unknown)', 'wp-marketing-automations' );
                    $status    = 500;
                }

                $response = array(
                    'response' => $status,
                    'body'     => array(
                        'errors' => array( 'message' => $error_msg ),
                    ),
                );
                $this->update_engagement_status( $response, $conversation );
            }
        }


        /**
         * @param $response
         * @param $data
         */
        public function update_engagement_status( $response, $data = array() ) {
            if ( empty( $this->track_id ) || empty( $response ) || ! is_array( $response ) ) {
                return;
            }
            $status = self::$STATUS_ERROR;
            if ( ( isset( $response['response'] ) && 200 === $response['response'] ) || ( isset( $response['status'] ) && 3 === absint( $response['status'] ) ) ) {
                /** Save the time of last sent engagement **/
                BWFAN_Conversation::save_last_sent_engagement( $data );
                $status = self::$STATUS_SEND;
            }

            $conversation_data               = array();
            $conversation_data['updated_at'] = current_time( 'mysql', 1 );
            $conversation_data['c_status']   = $status;


            BWFAN_Model_Engagement_Tracking::update( $conversation_data, array(
                'ID' => $this->track_id,
            ) );

			$conditional_set = ! empty( $data['conditional_set'] ) ? $data['conditional_set'] : [];
			if ( self::$STATUS_SEND === $status ) {
				self::insert_conversation_meta( $this->track_id, array(), false, $conditional_set );
				return;
			}


			/** Error message handling for sms */
			$error_msg = isset( $response['body']['errors'] ) && isset( $response['body']['errors']['message'] ) ? $response['body']['errors']['message'] : '';
			$error_msg = empty( $error_msg ) && isset( $response['reponse'][0] ) && ! is_array( $response['body'][0] ) ? $response['body'][0] : $error_msg;
			$error_msg = empty( $error_msg ) && isset( $response['message'] ) && ! empty( $response['message'] ) ? $response['message'] : $error_msg;
			$error_msg = empty( $error_msg ) && isset( $response['body']['message'] ) && ! empty( $response['body']['message'] ) ? $response['body']['message'] : $error_msg;
			$error_msg = empty( $error_msg ) ? __( 'SMS could not be sent. ', 'wp-marketing-automations' ) : $error_msg;

			self::insert_conversation_meta( $this->track_id, array(), $error_msg, $conditional_set );
		}

        /**
         * @param $global_settings
         */
        public function bwfan_order_conversation_settings( $global_settings ) {
            $order_tracking_conversation = isset( $global_settings['bwfan_order_tracking_conversion'] ) && ! empty( $global_settings['bwfan_order_tracking_conversion'] ) ? $global_settings['bwfan_order_tracking_conversion'] : 15;
            ?>
            <div class="form-group field-input">
                <label><?php esc_html_e( 'Order Tracking Conversion', 'wp-marketing-automations' ); ?></label>
                <div class="field-wrap">
                    <div class="wrapper">
                        <input type="number" placeholder="Days to track order" name="bwfan_order_tracking_conversion" min="1" value="<?php echo esc_attr( $order_tracking_conversation ); ?>"/>
                    </div>
                    <span class="hint"><?php esc_html_e( 'Days to Track order details for conversion', 'wp-marketing-automations' ); ?></span>
                </div>
            </div>
            <?php
        }

		/**
		 * Get Stats Total
		 *
		 * @since 1.0.0
		 */
		public function get_stats_total( $after, $before ) {

			return BWFAN_Model_Engagement_Tracking::get_stats( $after, $before );
		}

        public static function validate_link( $link ) {
            if ( strpos( $link, 'bwfan-action=subscribe' ) ) {
                return BWFAN_Common::bwfan_correct_protocol_url( $link );
            }
            $link = urldecode( $link );
            $link = str_replace( 'amp;', '', $link );
            $link = str_replace( '&#038;', '&', $link );

            return BWFAN_Common::bwfan_correct_protocol_url( $link );
        }

        /**
         * Track email opens and clicks after 3 secs from email sent
         *
         * @param $track_id
         * @param $get_row
         *
         * @return bool
         */
        public function should_skip_open_click_track( $track_id, $get_row ) {
            if ( empty( $track_id ) || ! is_array( $get_row ) || ! isset( $get_row[0]['created_at'] ) ) {
                return false;
            }

            $current_time    = current_time( 'timestamp', 1 );
            $created_at_time = strtotime( $get_row[0]['created_at'] );
            $default_time    = apply_filters( 'bwfan_default_skip_track_time', 5, $track_id, $get_row );

            return ( ( $current_time - $created_at_time ) <= $default_time );
        }

        /**
         * Checking source of link click and email open
         *
         * @return bool
         */
        public static function skipping_user_agent() {
            $skip_user_agents = apply_filters( 'bwfan_skip_user_agents', [] );
            if ( empty( $skip_user_agents ) || ! is_array( $skip_user_agents ) ) {
                return false;
            }

			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			foreach ( $skip_user_agents as $skip_user_agent ) {
				if ( false !== strpos( $user_agent, $skip_user_agent ) ) {
					return true;
				}
			}

            return false;
        }

        /**
         * update link metrics
         *
         * @param $engagement
         *
         * @return void
         */
        public static function update_link_metrics( $engagement ) {
            if ( empty( $engagement ) ) {
                return;
            }

			/** If link trigger found */
			$link_trigger = filter_input( INPUT_GET, 'bwfan-link-trigger', FILTER_UNSAFE_RAW );
			$link_trigger = $link_trigger ? sanitize_text_field( $link_trigger ) : '';
			if ( ! empty( $link_trigger ) ) {
				$cleaned_url   = BWFAN_Core()->conversation->get_cleaned_url( urldecode( site_url( '/?bwfan-link-trigger=' . $link_trigger ) ) );
				self::$link_id = BWFAN_Model_Links::get_link_id_by_tid( $cleaned_url, $engagement );
			}

			if ( empty( self::$link_id ) ) {
				return;
			}
			$metrics = BWFAN_Model_Link_Metrics::get_link_metrics( self::$link_id, $engagement['cid'] );
			if ( empty( $metrics ) ) {
				$data = [
					'link_id'    => self::$link_id,
					'contact_id' => $engagement['cid'] ?? 0,
					'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					'count'      => 1,
					'created_at' => current_time( 'mysql', 1 ),
				];
				BWFAN_Model_Link_Metrics::insert( $data );

                return;
            }

			try {
				BWFAN_Model_Link_Metrics::update( [
					'count' => intval( $metrics['count'] ) + 1
				], [ 'ID' => $metrics['ID'] ] );
			} catch ( Exception $e ) {
			}
		}

		/**
		 * Decode mail footer merge tag
		 *
		 * @param $template_body
		 *
		 * @return mixed
		 */
		public static function decode_mail_footer( $template_body ) {
			if ( false === strpos( $template_body, '{{mail_footer}}' ) ) {
				return $template_body;
			}

			$merge_tags = self::get_email_merge_tags( '{{mail_footer}}' );
			if ( ! empty( $merge_tags['{{mail_footer}}'] ) ) {
				$template_body       = str_replace( '{{mail_footer}}', $merge_tags['{{mail_footer}}'], $template_body );
				$template_body       = str_replace( [ '{_{', '}_}' ], [ '{{', '}}' ], $template_body );
				self::$template_body = $template_body;
			}

			return $template_body;
		}
	}

    BWFAN_Core::register( 'conversations', 'BWFAN_Email_Conversations' );
}
