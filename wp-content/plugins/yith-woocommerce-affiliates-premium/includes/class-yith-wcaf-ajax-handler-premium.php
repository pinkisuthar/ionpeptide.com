<?php
/**
 * Static class that will handle all ajax calls for the plugin
 *
 * @author YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Classes
 * @version 2.0.0
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( 'YITH_WCAF_Ajax_Handler_Premium' ) ) {
	/**
	 * WooCommerce Affiliates Ajax Handler
	 *
	 * @since 2.0.0
	 */
	class YITH_WCAF_Ajax_Handler_Premium extends YITH_WCAF_Ajax_Handler {

		/**
		 * Returns available AJAX call handlers
		 *
		 * @param string $context Context of the operation.
		 *
		 * @return array
		 */
		public static function get_handlers( $context = 'view' ) {
			if ( empty( self::$handlers ) ) {
				self::$handlers = array_merge(
					parent::get_handlers( 'edit' ),
					array(
						// AJAX note handling.
						'add_note',
						'delete_note',

						// AJAX profile fields handling.
						'save_affiliate_profile_field',
						'clone_affiliate_profile_field',
						'delete_affiliate_profile_field',
						'sort_affiliate_profile_fields',
						'restore_default_affiliate_profile_fields',

						// AJAX rule rate handling.
						'save_rate_rule',
						'clone_rate_rule',
						'delete_rate_rule',
						'sort_rate_rules',
						'enable_rate_rule'  => array(
							'callback' => array( self::class, 'change_rate_rule_status' ),
						),
						'disable_rate_rule' => array(
							'callback' => array( self::class, 'change_rate_rule_status' ),
						),

						// AJAX withdraw handling.
						'request_withdraw',

						// AJAX set referrer.
						'set_referrer'      => array(
							'nopriv' => true,
						),
					)
				);
			}

			if ( 'view' === $context ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_ajax_handlers
				 *
				 * Filters the AJAX handlers.
				 *
				 * @param array $ajax_handlers AJAX handlers.
				 */
				return apply_filters( 'yith_wcaf_ajax_handlers', self::$handlers );
			}

			return self::$handlers;
		}

		/* === NOTES HANDLING === */

		/**
		 * Create a new note in DB for the specified object
		 * Uses WC_Data_Store and YITH_WCAF_Trait_DB_Note to know how to interact with DB.
		 */
		public static function add_note() {
			ob_start();

			check_ajax_referer( 'add_note', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$object_id    = isset( $_REQUEST['object_id'] ) ? (int) $_REQUEST['object_id'] : false;
			$object_type  = isset( $_REQUEST['object_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['object_type'] ) ) : false;
			$note_content = isset( $_REQUEST['note_content'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['note_content'] ) ) : false;

			if ( ! $object_id || ! $note_content || ! $object_type ) {
				wp_send_json_error();
			}

			try {
				/**
				 * Retrieve data store object
				 *
				 * @var $data_store YITH_WCAF_Note_Data_Store_Interface
				 */
				$data_store = WC_Data_store::load( $object_type );
			} catch ( Exception $e ) {
				wp_send_json_error();
			}

			$new_note = new YITH_WCAF_Note(
				array(
					'content' => $note_content,
				)
			);

			$note_id   = $data_store->add_note( $object_id, $new_note );
			$note_date = current_time( 'mysql' );

			if ( ! $note_id ) {
				wp_send_json_error();
			}

			$template = sprintf(
				'<li rel="%s" class="note">
							<div class="note_content">
								<p>%s</p>
							</div>
							<p class="meta">
								<abbr class="exact-date" title="%s">%s</abbr>
								<a href="#" class="delete_note">%s</a>
							</p>
						 </li>',
				$note_id,
				$note_content,
				$note_date,
				// translators: 1. Note creation date (formatted). 2. Note creation time (formatted).
				sprintf( _x( 'added on %1$s at %2$s', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' ), date_i18n( wc_date_format(), strtotime( $note_date ) ), date_i18n( wc_time_format(), strtotime( $note_date ) ) ),
				_x( 'Delete note', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' )
			);

			wp_send_json_success(
				array(
					'template' => $template,
				)
			);
		}

		/**
		 * Delete a note in DB for the specified object
		 * Uses WC_Data_Store and YITH_WCAF_Trait_DB_Note to know how to interact with DB.
		 */
		public static function delete_note() {
			ob_start();

			check_ajax_referer( 'delete_note', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$object_id   = isset( $_REQUEST['object_id'] ) ? (int) $_REQUEST['object_id'] : false;
			$note_id     = isset( $_REQUEST['note_id'] ) ? (int) $_REQUEST['note_id'] : false;
			$object_type = isset( $_REQUEST['object_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['object_type'] ) ) : false;

			if ( ! $object_id || ! $note_id || ! $object_type ) {
				wp_send_json_error();
			}

			try {
				/**
				 * Retrieve data store object
				 *
				 * @var $data_store YITH_WCAF_Note_Data_Store_Interface
				 */
				$data_store = WC_Data_store::load( $object_type );
			} catch ( Exception $e ) {
				wp_send_json_error();
			}

			$res = $data_store->delete_note( $object_id, $note_id );

			wp_send_json(
				array(
					'success' => (bool) $res,
				)
			);
		}

		/* === PROFILE FIELDS HANDLING === */

		/**
		 * Save profile field via AJAX (creates a new one, or updates existing)
		 */
		public static function save_affiliate_profile_field() {
			ob_start();

			check_ajax_referer( 'save_profile_field', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$posted_field = isset( $_POST['field'] ) ? $_POST['field'] : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$field_name   = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : false;

			// sanitize posted field.
			$field = array();

			foreach ( $posted_field as $field_key => $field_value ) {
				switch ( $field_key ) {
					case 'label':
					case 'admin_label':
					case 'error_message':
						$sanitized_value = wp_kses_post( wp_unslash( $field_value ) );
						break;
					case 'type':
						$sanitized_value = in_array( $field_value, array_keys( YITH_WCAF_Affiliates_Profile::get_supported_field_types() ), true ) ? $field_value : false;
						break;
					case 'validation':
						$sanitized_value = in_array( $field_value, array_keys( YITH_WCAF_Affiliates_Profile::get_supported_field_validations() ), true ) ? $field_value : false;
						break;
					case 'required':
					case 'enabled':
						$sanitized_value = yith_plugin_fw_is_true( $field_value );
						break;
					case 'show_in':
						$sanitized_value = array();

						foreach ( (array) $field_value as $show_in_key => $show_in_value ) {
							if ( ! in_array( $show_in_key, array_keys( YITH_WCAF_Affiliates_Profile::get_supported_show_locations() ), true ) ) {
								continue;
							}

							$sanitized_value[ $show_in_key ] = yith_plugin_fw_is_true( $show_in_value );
						}
						break;
					case 'options':
						$sanitized_value = array();

						foreach ( $field_value as $option ) {
							if ( empty( $option['value'] ) || empty( $option['label'] ) ) {
								continue;
							}

							$sanitized_value[] = array(
								'label' => sanitize_text_field( wp_unslash( $option['label'] ) ),
								'value' => sanitize_text_field( wp_unslash( $option['value'] ) ),
							);
						}
						break;
					default:
						$sanitized_value = sanitize_text_field( wp_unslash( $field_value ) );
				}

				if ( ! $sanitized_value ) {
					continue;
				}

				$field[ $field_key ] = $sanitized_value;
			}

			try {
				if ( ! empty( $field_name ) ) {
					YITH_WCAF_Affiliates_Profile::update_field( $field_name, $field );
				} else {
					$field['enabled'] = true;

					YITH_WCAF_Affiliates_Profile::add_field( $field );
				}
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => $e->getMessage(),
					)
				);
			}

			if ( class_exists( 'YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium' ) ) {
				$table = new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium();
			} else {
				$table = new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table();
			}

			$table->prepare_items();
			$table->display();

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/**
		 * Clone profile field via AJAX
		 */
		public static function clone_affiliate_profile_field() {
			ob_start();

			check_ajax_referer( 'clone_profile_field', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : false;

			if ( ! $field_name ) {
				wp_send_json_error();
			}

			YITH_WCAF_Affiliates_Profile::clone_field( $field_name );

			if ( class_exists( 'YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium' ) ) {
				$table = new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium();
			} else {
				$table = new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table();
			}

			$table->prepare_items();
			$table->display();

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/**
		 * Delete profile field via AJAX
		 */
		public static function delete_affiliate_profile_field() {
			ob_start();

			check_ajax_referer( 'delete_profile_field', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : false;

			if ( ! $field_name ) {
				wp_send_json_error();
			}

			YITH_WCAF_Affiliates_Profile::remove_field( $field_name );

			wp_send_json_success();
		}

		/**
		 * Sort profile fields via AJAX
		 */
		public static function sort_affiliate_profile_fields() {
			ob_start();

			check_ajax_referer( 'sort_profile_fields', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$order = isset( $_POST['order'] ) ? $_POST['order'] : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$order = array_map(
				function( $item ) {
					return sanitize_text_field( wp_unslash( $item ) );
				},
				is_array( $order ) ? $order : array()
			);

			if ( ! $order ) {
				wp_send_json_error();
			}

			YITH_WCAF_Affiliates_Profile::sort_fields( $order );

			wp_send_json_success();
		}

		/**
		 * Restore default fields for Affiliate's profile
		 */
		public static function restore_default_affiliate_profile_fields() {
			ob_start();

			check_ajax_referer( 'restore_profile_fields', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			YITH_WCAF_Affiliates_Profile::restore_default_fields();

			if ( class_exists( 'YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium' ) ) {
				$table = new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table_Premium();
			} else {
				$table = new YITH_WCAF_Affiliates_Profile_Fields_Admin_Table();
			}

			$table->prepare_items();
			$table->display();

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/* === RATE RULE HANDLING === */

		/**
		 * Change status of a rate rule via AJAX
		 */
		public static function change_rate_rule_status() {
			ob_start();

			check_ajax_referer( 'change_rate_rule_status', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$rule_id = isset( $_POST['rule_id'] ) ? (int) $_POST['rule_id'] : false;

			if ( ! $rule_id ) {
				wp_send_json_error();
			}

			$rule = YITH_WCAF_Rate_Rule_Factory::get_rule( $rule_id );

			if ( ! $rule ) {
				wp_send_json_error();
			}

			$action = 'wp_ajax_yith_wcaf_enable_rate_rule' === current_action();

			$rule->set_enabled( $action );
			$rule->save();

			$table = new YITH_WCAF_Rate_Rules_Admin_Table();
			$table->prepare_items();
			$table->display();

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/**
		 * Save rate rule via AJAX (creates a new one, or updates existing)
		 */
		public static function save_rate_rule() {
			ob_start();

			check_ajax_referer( 'save_rate_rule', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$posted_field = isset( $_POST['rule'] ) ? $_POST['rule'] : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$rule_id      = isset( $posted_field['id'] ) ? (int) $posted_field['id'] : false;

			// sanitize posted field.
			$rule_data = array();

			foreach ( $posted_field as $field_key => $field_value ) {
				switch ( $field_key ) {
					case 'name':
						$sanitized_value = wp_kses_post( wp_unslash( $field_value ) );
						break;
					case 'type':
						$sanitized_value = in_array( $field_value, array_keys( YITH_WCAF_Rate_Handler_Premium::get_supported_rule_types() ), true ) ? $field_value : false;
						break;
					case 'affiliate_ids':
					case 'product_ids':
					case 'product_categories':
						$sanitized_value = array_map( 'intval', (array) $field_value );
						break;
					case 'user_roles':
						$field_value     = (array) $field_value;
						$sanitized_value = array();

						foreach ( $field_value as $role ) {
							$sanitized_value[] = sanitize_text_field( wp_unslash( $role ) );
						}
						break;
					case 'priority':
						$sanitized_value = (int) $field_value;
						break;
					case 'rate':
						$sanitized_value = (float) $field_value;
						break;
					default:
						$sanitized_value = sanitize_text_field( wp_unslash( $field_value ) );
				}

				if ( ! $sanitized_value ) {
					continue;
				}

				$rule_data[ $field_key ] = $sanitized_value;
			}

			try {
				$rule = YITH_WCAF_Rate_Rule_Factory::create_rule( $rule_id );

				$rule->set_props( $rule_data );
				$rule->save();
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => $e->getMessage(),
					)
				);
			}

			$table = new YITH_WCAF_Rate_Rules_Admin_Table();
			$table->prepare_items();
			$table->display();

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/**
		 * Clone rate rule via AJAX
		 */
		public static function clone_rate_rule() {
			ob_start();

			check_ajax_referer( 'clone_rate_rule', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$rule_id = isset( $_POST['rule_id'] ) ? (int) $_POST['rule_id'] : false;

			if ( ! $rule_id ) {
				wp_send_json_error();
			}

			$rule = YITH_WCAF_Rate_Rule_Factory::get_rule( $rule_id );

			if ( ! $rule ) {
				wp_send_json_error();
			}

			// retrieve data to use for clone.
			$rule_data = $rule->get_data();

			// remove id from clone data.
			unset( $rule_data['id'] );

			try {
				$rule = YITH_WCAF_Rate_Rule_Factory::create_rule( $rule_data );
				$rule->save();
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => $e->getMessage(),
					)
				);
			}

			$table = new YITH_WCAF_Rate_Rules_Admin_Table();
			$table->prepare_items();
			$table->display();

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/**
		 * Delete rate rule via AJAX
		 */
		public static function delete_rate_rule() {
			ob_start();

			check_ajax_referer( 'delete_rate_rule', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$rule_id = isset( $_POST['rule_id'] ) ? (int) $_POST['rule_id'] : false;

			if ( ! $rule_id ) {
				wp_send_json_error();
			}

			$rule = YITH_WCAF_Rate_Rule_Factory::get_rule( $rule_id );

			if ( ! $rule || ! $rule->delete() ) {
				wp_send_json_error();
			}

			wp_send_json_success();
		}

		/**
		 * Sort rate rules via AJAX
		 */
		public static function sort_rate_rules() {
			ob_start();

			check_ajax_referer( 'sort_rate_rules', 'security' );

			if ( ! YITH_WCAF_Admin()->current_user_can_manage_panel() ) {
				die( - 1 );
			}

			$order = isset( $_POST['order'] ) ? $_POST['order'] : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$order = array_map(
				function( $item ) {
					return (int) $item;
				},
				is_array( $order ) ? $order : array()
			);

			if ( ! $order ) {
				wp_send_json_error();
			}

			$formatted_order = array();
			$priority        = 1;

			foreach ( $order as $rule_id ) {
				$formatted_order[ $rule_id ] = $priority++;
			}

			try {
				$data_store = WC_Data_Store::load( 'rate_rule' );
				$data_store->update_priorities( $formatted_order );
			} catch ( Exception $e ) {
				wp_send_json_error();
			}

			wp_send_json_success();
		}

		/* === WITHDRAW REQUEST === */

		/**
		 * Handles withdraw requests
		 */
		public static function request_withdraw() {
			ob_start();

			check_ajax_referer( 'request_withdraw', 'security' );

			try {
				YITH_WCAF_Withdraws()->process_withdraw( $_POST );
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => $e->getMessage(),
					)
				);
			}

			yith_wcaf_get_template( 'withdraw-modal-success.php', array(), 'shortcodes/dashboard-summary' );

			wp_send_json_success(
				array(
					'template' => ob_get_clean(),
				)
			);
		}

		/* === SET REFERRER === */

		/**
		 * Set affiliate token via AJAX
		 */
		public static function set_referrer() {
			ob_start();

			check_ajax_referer( 'set_referrer', 'security' );

			$token = isset( $_POST['referrer'] ) ? sanitize_text_field( wp_unslash( $_POST['referrer'] ) ) : false;

			if ( ! $token ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_missing_token_error_message
				 *
				 * Filters the error message when the token is missing.
				 *
				 * @param string $error_message Error message.
				 */
				wc_add_notice( apply_filters( 'yith_wcaf_missing_token_error_message', _x( 'Please, enter the affiliate code.', '[FRONTEND] Set referrer form error', 'yith-woocommerce-affiliates' ) ), 'error' );
			} elseif ( ! YITH_WCAF_Affiliates()->is_valid_token( $token ) ) {
				/**
				 * APPLY_FILTERS: yith_wcaf_invalid_token_error_message
				 *
				 * Filters the error message when the token is not valid.
				 *
				 * @param string $error_message Error message.
				 */
				wc_add_notice( apply_filters( 'yith_wcaf_invalid_token_error_message', _x( 'The affiliate code you provided is not valid; please, double-check it!', '[FRONTEND] Set referrer form error', 'yith-woocommerce-affiliates' ) ), 'error' );
			} else {
				/**
				 * APPLY_FILTERS: yith_wcaf_valid_token_success_message
				 *
				 * Filters the success message when the token not valid.
				 *
				 * @param string $success_message Success message.
				 */
				wc_add_notice( apply_filters( 'yith_wcaf_valid_token_success_message', _x( 'Thank you! We will give this user a special thanks!', '[FRONTEND] Set referrer form error', 'yith-woocommerce-affiliates' ) ), 'success' );

				YITH_WCAF_Session()->set_token( $token, 'ajax', true );

				/**
				 * DO_ACTION: yith_wcaf_referrer_set
				 *
				 * Allows to trigger some action after the token has been set.
				 *
				 * @param string $token Token.
				 */
				do_action( 'yith_wcaf_referrer_set', $token );
			}

			if ( wc_notice_count( 'error' ) ) {
				wp_send_json_error(
					array(
						'template' => wc_print_notices( true ),
					)
				);
			}

			$template = wc_print_notices( true );
			$message  = wp_strip_all_tags( $template );

			wp_send_json_success(
				array(
					'template' => $template,
					'message'  => $message,
				)
			);
		}
	}
}
