<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
if ( ! class_exists( 'Pie_WCWL_Admin_Ajax' ) ) {
	/**
	 * Class Pie_WCWL_Admin_Ajax
	 */
	class Pie_WCWL_Admin_Ajax {
		/**
		 * Initialise ajax class
		 */
		public function init() {
			add_action( 'wp_ajax_wcwl_get_products', array( $this, 'get_all_products_ajax' ) );
			add_action( 'wp_ajax_wcwl_get_products_with_waitlist_or_archive', array( $this, 'wcwl_get_products_with_waitlist_or_archive_ajax' ) );
			add_action( 'wp_ajax_wcwl_update_counts', array( $this, 'update_waitlist_counts_ajax' ) );
			add_action( 'wp_ajax_wcwl_update_meta', array( $this, 'update_waitlist_meta_ajax' ) );
			add_action( 'wp_ajax_wcwl_add_user_to_waitlist', array( $this, 'process_add_user_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_remove_waitlist', array( $this, 'process_waitlist_remove_users_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_email_instock', array( $this, 'process_send_instock_mail_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_dismiss_archive_notice', array( $this, 'permanently_dismiss_archive_notice_for_user_ajax' ) );
			add_action( 'wp_ajax_wcwl_remove_archive', array( $this, 'process_archive_remove_users_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_return_to_waitlist', array( $this, 'process_return_users_to_waitlist_request_ajax' ) );
			add_action( 'wp_ajax_wcwl_update_waitlist_options', array( $this, 'update_waitlist_options_ajax' ) );
			add_action( 'wp_ajax_wcwl_generate_csv', array( $this, 'generate_csv_ajax' ) );
		}

		/**
		 * Return all product IDs
		 */
		public function get_all_products_ajax() {
			$nonce = isset( $_POST['wcwl_get_products'] ) ? sanitize_text_field( $_POST['wcwl_get_products'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'wcwl-ajax-get-products-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			$products = WooCommerce_Waitlist_Plugin::return_all_product_ids();
			wp_send_json_success( $products );
		}

		/**
		 * Return all product IDs with waitlist/archive data
		 */
		public function wcwl_get_products_with_waitlist_or_archive_ajax() {
			$nonce = isset( $_POST['wcwl_get_products'] ) ? sanitize_text_field( $_POST['wcwl_get_products'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'wcwl-ajax-get-products-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			$products = WooCommerce_Waitlist_Plugin::return_all_waitlist_archive_product_ids();
			wp_send_json_success( $products );
		}

		/**
		 * Update waitlists for the given products - 10 at a time
		 */
		public function update_waitlist_counts_ajax() {
			$nonce = isset( $_POST['wcwl_update_counts'] ) ? sanitize_text_field( $_POST['wcwl_update_counts'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'wcwl-ajax-update-counts-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			$products = isset( $_POST['products'] ) ? array_map( 'absint', (array) $_POST['products'] ) : array();
			$response = array();
			foreach ( $products as $product_id ) {
				$count      = $this->get_waitlist_count( $product_id );
				/* translators: %1$d is the product ID, %2$d is the count */
				$response[] = sprintf( __( 'Product %1$d - count updated to %2$d', 'woocommerce-waitlist' ), $product_id, $count );
			}
			if ( isset( $_POST['remaining'] ) && 0 === absint( $_POST['remaining'] ) ) {
				update_option( '_' . WCWL_SLUG . '_counts_updated', true, false );
			}
			wp_send_json_success( $response );
		}

		/**
		 * Return number of users on requested waitlist and update meta so it can be quickly retrieved in the future
		 *
		 * @param  int $product product ID
		 *
		 * @static
		 * @return int
		 */
		protected function get_waitlist_count( $product ) {
			$product  = wc_get_product( $product );
			if ( ! $product ) {
				return 0;
			}
			$waitlist = array();
			if ( $product->has_child() ) {
				foreach ( $product->get_children() as $child_id ) {
					$current_waitlist = get_post_meta( $child_id, WCWL_SLUG, true );
					$current_waitlist = is_array( $current_waitlist ) ? $current_waitlist : array();
					// update variation count
					update_post_meta( $child_id, '_' . WCWL_SLUG . '_count', count( $current_waitlist ) );
					$waitlist         = array_merge( $waitlist, $current_waitlist );
				}
			} else {
				$waitlist = get_post_meta( $product->get_id(), WCWL_SLUG, true );
			}
			$count = ! $waitlist ? 0 : count( $waitlist );
			update_post_meta( $product->get_id(), '_' . WCWL_SLUG . '_count', $count );
			delete_post_meta( $product->get_id(), WCWL_SLUG . '_count' );

			return $count;
		}

		/**
		 * Update all metadata relating to waitlists
		 */
		public function update_waitlist_meta_ajax() {
			$nonce = isset( $_POST['wcwl_update_meta'] ) ? sanitize_text_field( $_POST['wcwl_update_meta'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'wcwl-ajax-update-meta-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			$products = isset( $_POST['products'] ) ? array_map( 'absint', (array) $_POST['products'] ) : array();
			$response = array();
			foreach ( $products as $product_id ) {
				$archives   = get_post_meta( $product_id, 'wcwl_waitlist_archive', true );
				if ( ! is_array( $archives ) ) {
					$archives = array();
				}
				self::fix_multiple_entries_for_days( $archives, $product_id );
				$product  = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}
				$waitlist = new Pie_WCWL_Waitlist( $product );
				$waitlist->save_waitlist();
				/* translators: %d is the product ID */
				$response[] = sprintf( __( 'Meta updated for Product %d', 'woocommerce-waitlist' ), $product->get_id() );
			}
			if ( isset( $_POST['remaining'] ) && 0 === absint( $_POST['remaining'] ) ) {
				update_option( '_' . WCWL_SLUG . '_metadata_updated', true );
			}
			wp_send_json_success( $response );
		}

		/**
		 * Fix any duplicate entries for certain days when displaying the waitlist archives
		 * We check for the old timestamp as array key. If meta is old we adjust it over to the new dates
		 * Update meta afterwards to make sure everything remains updated
		 *
		 * @param $archives
		 * @param $product_id
		 *
		 * @return array
		 */
		public static function fix_multiple_entries_for_days( $archives, $product_id ) {
			$updated_archives = array();
			foreach ( $archives as $date => $archive ) {
				$date = strtotime( gmdate( 'Ymd', $date ) );
				if ( $archive ) {
					foreach ( $archive as $user_id ) {
						$user = get_user_by( 'id', $user_id );
						if ( ! $user ) {
							$user_email = $user_id;
						} else {
							$user_email = $user->user_email;
						}
						$updated_archives[ $date ][ $user_email ] = $user_email;
					}
					$updated_archives[ $date ] = array_unique( $updated_archives[ $date ] );
				}
			}
			krsort( $updated_archives );
			update_post_meta( $product_id, 'wcwl_waitlist_archive', $updated_archives );

			return $updated_archives;
		}

		/**
		 * Handle the request to add user to waitlist
		 */
		public function process_add_user_request_ajax() {
			$nonce = isset( $_POST['wcwl_add_user_nonce'] ) ? sanitize_text_field( $_POST['wcwl_add_user_nonce'] ) : '';

			if ( ! wp_verify_nonce( $nonce, 'wcwl-add-user-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}

			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$archive	= get_option( 'woocommerce_waitlist_archive_on' );
			$product    = $this->setup_product( $product_id, $archive );

			if ( ! isset( $_POST['emails'] ) ) {
				$emails = array();
			} else {
				$emails = isset( $_POST['emails'] ) ? array_map( 'sanitize_email', (array) $_POST['emails'] ) : array();
			}

			$emails     = $this->organise_emails( $emails );
			$users      = array();
			foreach ( $emails as $email ) {
				$response = wcwl_add_user_to_waitlist( $email, $product->get_id() );
				if ( ! is_wp_error( $response ) ) {
					$users[] = $this->generate_required_userdata( $email, 'waitlist' );
				}
			}
			$data = array(
				'type'    => 'success',
				'message' => __( 'The waitlist has been updated', 'woocommerce-waitlist' ),
				'archive' => $archive,
				'users'   => $users,
			);
			wp_send_json_success( $data );
		}

		/**
		 * Process the given emails to add user to the waitlist
		 *
		 * @param $emails
		 *
		 * @return array
		 */
		public function organise_emails( $emails ) {
			$processed_emails = array();
			if ( is_array( $emails ) ) {
				foreach ( $emails as $email ) {
					$processed_emails[] = $email;
				}
			} else {
				$processed_emails[] = $emails;
			}

			return $processed_emails;
		}

		/**
		 * Return users from the archive to the waitlist
		 */
		public function process_return_users_to_waitlist_request_ajax() {
			$nonce   = isset( $_POST['wcwl_action_nonce'] ) ? sanitize_text_field( $_POST['wcwl_action_nonce'] ) : '';

			if ( ! wp_verify_nonce( $nonce, 'wcwl-action-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}

			// phpcs ignore due to sanitization being done through array_map
			$posted_users = isset( $_POST['users'] ) ? array_map( array( $this, 'sanitize_user' ), $_POST['users'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$archive      = get_option( 'woocommerce_waitlist_archive_on' );

			$this->verify_action_request( $posted_users, $archive );

			$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product      = $this->setup_product( $product_id, $archive );
			$waitlist     = new Pie_WCWL_Waitlist( $product );
			$users        = array();

			foreach ( $posted_users as $user ) {
				if ( $user ) {
					$email   = isset( $user['email'] ) ? $user['email'] : '';
					$lang    = wcwl_get_user_language( $email, $product->get_id() );
					$waitlist->register_user( $email, $lang );
					$users[] = $this->generate_required_userdata( $email, 'waitlist' );
				}
			}
			$data = array(
				'type'    => 'success',
				'message' => count( $users ) > 1 ? __( 'The selected users have been added to the waitlist', 'woocommerce-waitlist' ) : __( 'The selected user has been added to the waitlist', 'woocommerce-waitlist' ),
				'archive' => $archive,
				'users'   => $users,
			);
			wp_send_json_success( $data );
		}

		/**
		 * Handle the request to remove users from the waitlist
		 * 
		 * @todo refactor to appease phpcs
		 */
		public function process_waitlist_remove_users_request_ajax() {
			$nonce   = isset( $_POST['wcwl_action_nonce'] ) ? sanitize_text_field( $_POST['wcwl_action_nonce'] ) : '';

			if ( ! wp_verify_nonce( $nonce, 'wcwl-action-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}

			// phpcs ignore due to sanitization being done through array_map
			$posted_users = isset( $_POST['users'] ) ? array_map( array( $this, 'sanitize_user' ), $_POST['users'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$archive      = get_option( 'woocommerce_waitlist_archive_on' );

			$this->verify_action_request( $posted_users, $archive );

			$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product      = $this->setup_product( $product_id, $archive );
			$waitlist     = new Pie_WCWL_Waitlist( $product );
			$users        = array();

			foreach ( $posted_users as $user ) {
				$email    = isset( $user['email'] ) ? $user['email'] : '';
				$response = $waitlist->unregister_user( $email );
				WC_Emails::instance();
				/**
				 * Triggers when a user is removed to possibly send user left waitlist email
				 * 
				 * @hooked Pie_WCWL_Waitlist_Left_Email::trigger 10
				 * @since 2.4.0
				 */
				do_action( 'wcwl_left_mailout_send_email', $email, $product->get_id() );
				$waitlist->maybe_add_user_to_archive( $email );
				if ( ! $response ) {
					$data = array(
						'type'    => 'error',
						/* translators: %s is the email address */
						'message' => sprintf( __( 'There was an error when trying to remove %s from the waitlist', 'woocommerce-waitlist' ), $email ),
						'archive' => $archive,
					);
					wp_send_json_error( $data );
				}
				$users[] = $this->generate_required_userdata( $email, 'archive' );
			}
			$data = array(
				'type'    => 'success',
				'message' => count( $users ) > 1 ? __( 'The selected users have been removed from the waitlist', 'woocommerce-waitlist' ) : __( 'The selected user has been removed from the waitlist', 'woocommerce-waitlist' ),
				'archive' => $archive,
				'users'   => $users,
			);
			wp_send_json_success( $data );
		}

		/**
		 * Handle the request to email in stock notifications to given users
		 * 
		 * @todo refactor to appease phpcs
		 */
		public function process_send_instock_mail_request_ajax() {
			$nonce = isset( $_POST['wcwl_action_nonce'] ) ? sanitize_text_field( $_POST['wcwl_action_nonce'] ) : '';

			if ( ! wp_verify_nonce( $nonce, 'wcwl-action-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			// phpcs ignore due to sanitization being done through array_map
			$posted_users = isset( $_POST['users'] ) ? array_map( array( $this, 'sanitize_user' ), $_POST['users'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$archive	  = get_option( 'woocommerce_waitlist_archive_on' );

			$this->verify_action_request( $posted_users, $archive );

			$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product      = $this->setup_product( $product_id, $archive );
			$users        = array();
			foreach ( $posted_users as $user ) {
				WC_Emails::instance();
				$email = isset( $user['email'] ) ? $user['email'] : '';
				$user  = get_user_by( 'email', $email );
				if ( $user ) {
					/**
					 * Triggers when a user is sent an in stock notification, passes the user ID if available
					 * 
					 * @deprecated use 'wcwl_mailout_send_customer_email' instead
					 * @since 2.4.0
					 */
					do_action( 'wcwl_mailout_send_email', $user->ID, $product->get_id(), true );
				}
				/**
				 * Triggers when a user is sent an in stock notification, passes the user email
				 * 
				 * @since 2.4.0
				 */
				do_action( 'wcwl_mailout_send_customer_email', $email, $product->get_id(), true );

				$users[] = $this->generate_required_userdata( $email, 'archive' );
			}
			$data = array(
				'type'    => 'success',
				'message' => __( 'The selected users have been sent an in stock notification', 'woocommerce-waitlist' ),
				'archive' => $archive,
				'users'   => $users,
			);
			wp_send_json_success( $data );
		}

		/**
		 * Remove selected users from given archive
		 * 
		 * @todo refactor to appease phpcs
		 */
		public function process_archive_remove_users_request_ajax() {
			$nonce = isset( $_POST['wcwl_action_nonce'] ) ? sanitize_text_field( $_POST['wcwl_action_nonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'wcwl-action-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			// phpcs ignore due to sanitization being done through array_map
			$posted_users = isset( $_POST['users'] ) ? array_map( array( $this, 'sanitize_user' ), $_POST['users'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$archive      = get_option( 'woocommerce_waitlist_archive_on' );

			$this->verify_action_request( $posted_users, $archive );
			
			$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$archive      = get_post_meta( $product_id, 'wcwl_waitlist_archive', true );

			foreach ( $posted_users as $user ) {
				$email       = isset( $user['email'] ) ? $user['email'] : '';
				$user_object = get_user_by( 'email', $email );
				$date        = $user['date'];
				$key         = array_search( $email, $archive[ $date ] );
				if ( ! $key ) {
					$key = array_search( $user_object->ID, $archive[ $date ] );
				}
				if ( $key ) {
					unset( $archive[ $date ][ $key ] );
				}
				if ( empty( $archive[ $date ] ) ) {
					unset( $archive[ $date ] );
				}
			}
			update_post_meta( $product_id, 'wcwl_waitlist_archive', $archive );
			$data = array(
				'type'    => 'success',
				'message' => __( 'Selected users have been removed', 'woocommerce-waitlist' ),
				'archive' => $archive,
				'users'   => $posted_users,
			);
			wp_send_json_success( $data );
		}

		/**
		 * Update waitlist options
		 * 
		 * @todo refactor to appease phpcs
		 */
		public function update_waitlist_options_ajax() {
			$nonce   = isset( $_POST['wcwl_update_nonce'] ) ? sanitize_text_field( $_POST['wcwl_update_nonce'] ) : '';
			$archive = get_option( 'woocommerce_waitlist_archive_on' );

			if ( ! wp_verify_nonce( $nonce, 'wcwl-update-nonce' ) ) {
				$data = array(
					'type'    => 'success',
					'message' => __( 'Verification failed for your request', 'woocommerce-waitlist' ),
					'archive' => $archive,
				);
				wp_send_json_error( $data );
			}
			// phpcs ignore due to sanitization being done through array_map
			$options = isset( $_POST['options'] ) ? array_map( array( $this, 'sanitize_option' ), $_POST['options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $options ) {
				$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
				update_post_meta( $product_id, 'wcwl_options', $options );
				$data = array(
					'type'    => 'success',
					'message' => __( 'Waitlist options have been updated for this product', 'woocommerce-waitlist' ),
					'archive' => $archive,
				);
				wp_send_json_success( $data );
			} else {
				$data = array(
					'type'    => 'success',
					'message' => __( 'Something went wrong with your request. Options not recognised', 'woocommerce-waitlist' ),
					'archive' => $archive,
				);
				wp_send_json_error( $data );
			}
		}

		/**
		 * Verify request is valid by checking posted users
		 * 
		 * @param $users
		 * @param $archive
		 */
		protected function verify_action_request( $users, $archive ) {
			if ( ! $users ) {
				$data = array(
					'type'    => 'success',
					'message' => __( 'No users selected', 'woocommerce-waitlist' ),
					'archive' => $archive,
				);
				wp_send_json_error( $data );
			}
		}

		/**
		 * Sanitize user data
		 *
		 * @return array
		 */
		public function sanitize_user( $user ) {
			return array(
				'date'  => sanitize_text_field( $user['date'] ),
				'email' => sanitize_email( $user['email'] )
			);
		}

		/**
		 * Sanitize the given option
		 *
		 * @param string|int $option
		 * @return string|int
		 */
		public function sanitize_option( $option ) {
			if ( is_int( $option ) ) {
				return absint( $option );
			} else {
				return sanitize_text_field( $option );
			}
		}

		/**
		 * Retrieve the product from the given ID and output an error notice if not found
		 *
		 * @param $product_id
		 * @param $archive
		 *
		 * @return false|null|WC_Product
		 */
		protected function setup_product( $product_id, $archive ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$data = array(
					'type'    => 'success',
					'message' => __( 'Invalid product ID', 'woocommerce-waitlist' ),
					'archive' => $archive,
				);
				wp_send_json_error( $data );
			}

			return $product;
		}

		/**
		 * Gather required information for user
		 *
		 * @param $email
		 * @param $table
		 *
		 * @return array
		 */
		protected function generate_required_userdata( $email, $table ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$data = array(
					'id'        => $user->ID,
					'link'      => get_edit_user_link( $user->ID ),
					'email'     => $user->user_email,
					'join_date' => gmdate( 'd M, y' ),
				);
			} else {
				$data = array(
					'id'        => 0,
					'link'      => '#',
					'email'     => $email,
					'join_date' => gmdate( 'd M, y' ),
				);
			}
			if ( 'archive' === $table ) {
				$data['date'] = strtotime( gmdate( 'Ymd' ) );
			}

			return $data;
		}

		/**
		 * Generate CSV with all product waitlist data (10 at a time)
		 * 
		 * @todo refactor to appease phpcs
		 */
		public function generate_csv_ajax() {
			$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'wcwl-ajax-export-nonce' ) ) {
				wp_send_json_error( __( 'Verification failed for your request', 'woocommerce-waitlist' ) );
			}
			$string   = '';
			$products = isset( $_POST['products'] ) ? array_map( 'absint', (array) $_POST['products'] ) : array();
			foreach ( $products as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				if ( WooCommerce_Waitlist_Plugin::is_variation( $product ) || WooCommerce_Waitlist_Plugin::is_simple( $product ) || WooCommerce_Waitlist_Plugin::is_bundle( $product )) {
					$waitlist = get_post_meta( $product_id, 'woocommerce_waitlist', true );
					$archives = $this->get_formatted_archives( $product_id );
					if ( $this->no_users( $waitlist ) && $this->no_users( $archives ) ) {
						continue;
					}
					$product_name = str_replace( array( '"', '#' ), array( '""', '' ), wp_kses_decode_entities( $product->get_formatted_name() ) );
					$string      .= $product_id . ',"' . $product_name . '",';
					if ( $this->no_users( $waitlist ) ) {
						$string .= ',';
					} else {
						$emails = '"';
						foreach ( $waitlist as $user => $timestamp ) {
							if ( ! is_email( $user ) ) {
								$user_object = get_user_by( 'id', $user );
								$email       = isset( $user_object->user_email ) ? $user_object->user_email : '';
							} else {
								$email = $user;
							}
							$emails .= $email;
							end( $waitlist );
							if ( key( $waitlist ) === $user ) {
								$emails .= '",';
							} elseif ( $email ) {
								$emails .= ',';
							}
						}
						$string .= $emails;
					}
					if ( $this->no_users( $archives ) ) {
						$string .= "\r\n";
					} else {
						$emails = '"';
						foreach ( $archives as $key => $user ) {
							if ( ! is_email( $user ) ) {
								$user_object = get_user_by( 'id', $user );
								$email       = isset( $user_object->user_email ) ? $user_object->user_email : '';
							} else {
								$email = $user;
							}
							$emails .= $email;
							end( $archives );
							if ( key( $archives ) === $key ) {
								$emails .= '"' . "\r\n";
							} elseif ( $email ) {
								$emails .= ',';
							}
						}
						$string .= $emails;
					}
					/**
					 * Filter the CSV data per row
					 * 
					 * @since 2.4.0
					 */
					$string = apply_filters( 'wcwl_csv_export_data_per_row', $string );
				} else {
					continue;
				}
			}
			wp_send_json_success( $string );
		}

		/**
		 * Retrieve and format the products archive
		 *
		 * @param $product_id
		 *
		 * @return array
		 */
		public function get_formatted_archives( $product_id ) {
			$archives       = get_post_meta( $product_id, 'wcwl_waitlist_archive', true );
			$archived_users = array();
			if ( $this->no_users( $archives ) ) {
				return $archived_users;
			}
			foreach ( $archives as $users ) {
				if ( $users ) {
					$archived_users = array_merge( $archived_users, $users );
				}
			}

			return array_unique( $archived_users );
		}

		/**
		 * Are there any users on the given list?
		 *
		 * @param $list
		 *
		 * @return bool
		 */
		public function no_users( $list ) {
			if ( ! $list || ! is_array( $list ) ) {
				return true;
			}

			return false;
		}
	}
}
