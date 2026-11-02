<?php
// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
/**
 * Warranty Request Workflow
 *
 * Separate from the existing return/exchange flow:
 *  - Admin sets a warranty duration per product or category (or a global default)
 *  - Customer sees a "Submit Warranty Claim" button on the order page once the
 *    standard return window has passed (and while the warranty is still valid)
 *  - Customer files a claim with reason + description + photos
 *  - Admin reviews and resolves with: repair, replacement, or partial refund
 *  - Both parties get email notifications on status changes
 *
 * Storage: `{prefix}warranty_claims` (added in installation migration 1.11).
 *
 * @package Zorem_Returns_Exchanges
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Warranty {

	/**
	 * Singleton instance.
	 *
	 * @var Return_Warranty
	 */
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->init();
	}

	public function init() {
		// Register customer-facing endpoint /my-account/warranty/.
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ), 0 );
		add_action( 'woocommerce_account_warranty_endpoint', array( $this, 'render_endpoint_content' ) );

		// Render warranty button on customer order page.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'maybe_render_warranty_button' ), 20, 1 );

		// Per-product warranty days field (admin product edit screen).
		add_action( 'woocommerce_product_options_shipping', array( $this, 'product_warranty_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_warranty_field' ) );

		// Per-category warranty days field (admin product category edit screen).
		add_action( 'product_cat_add_form_fields', array( $this, 'category_warranty_add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'category_warranty_edit_field' ) );
		add_action( 'edited_product_cat', array( $this, 'save_category_warranty_field' ) );
		add_action( 'create_product_cat', array( $this, 'save_category_warranty_field' ) );

		// AJAX — customer-facing.
		add_action( 'wp_ajax_zorem_submit_warranty_claim', array( $this, 'ajax_submit_claim' ) );
		add_action( 'wp_ajax_nopriv_zorem_submit_warranty_claim', array( $this, 'ajax_submit_claim' ) );

		// AJAX — admin.
		add_action( 'wp_ajax_zorem_update_warranty_status', array( $this, 'ajax_update_status' ) );
	}

	/* --------------------------------------------------------------------
	 * Endpoint registration
	 * ------------------------------------------------------------------ */

	public function register_endpoint() {
		add_rewrite_endpoint( 'warranty', EP_ROOT | EP_PAGES );
		if ( ! get_option( 'plugin_warranty_permalinks_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'plugin_warranty_permalinks_flushed', 1 );
		}
	}

	public function add_query_var( $vars ) {
		$vars[] = 'warranty';
		return $vars;
	}

	/* --------------------------------------------------------------------
	 * Settings & configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Read a warranty setting from the plugin settings store.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	protected function setting( $key, $default = '' ) {
		return zorem_returns_exchanges()->common->return_settings( $key, $default );
	}

	public function is_enabled() {
		return 'yes' === $this->setting( 'warranty_enable', 'no' );
	}

	/**
	 * Default warranty duration (in days) used when neither product nor category
	 * specifies one. 0 = no warranty.
	 *
	 * @return int
	 */
	public function get_default_period_days() {
		return absint( $this->setting( 'warranty_default_days', 365 ) );
	}

	/**
	 * Returns warranty days for a given product, considering: product meta first,
	 * then category meta, then global default. Returns 0 if not under warranty.
	 *
	 * @param int $product_id
	 * @return int
	 */
	public function get_product_warranty_days( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return 0;
		}
		$override = get_post_meta( $product_id, '_zorem_warranty_period_days', true );
		if ( '' !== $override && null !== $override ) {
			$override = (int) $override;
			// -1 means "explicitly no warranty for this product".
			if ( -1 === $override ) {
				return 0;
			}
			if ( $override > 0 ) {
				return $override;
			}
			// 0 = fall through to category/default.
		}
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term_id ) {
				$cat_days = get_term_meta( $term_id, '_zorem_warranty_period_days', true );
				if ( '' === $cat_days || null === $cat_days ) {
					continue;
				}
				$cat_days = (int) $cat_days;
				if ( -1 === $cat_days ) {
					return 0;
				}
				if ( $cat_days > 0 ) {
					return $cat_days;
				}
			}
		}
		return $this->get_default_period_days();
	}

	/**
	 * Default warranty reasons (shown when admin hasn't customised them).
	 *
	 * @return string[]
	 */
	public function get_default_reasons() {
		return array(
			__( 'Stopped working', 'zorem-returns' ),
			__( 'Manufacturing defect', 'zorem-returns' ),
			__( 'Broken or damaged', 'zorem-returns' ),
			__( 'Missing parts after some use', 'zorem-returns' ),
			__( 'Performance issue', 'zorem-returns' ),
		);
	}

	public function get_reasons() {
		$reasons = $this->setting( 'warranty_reasons', array() );
		if ( empty( $reasons ) || ! is_array( $reasons ) ) {
			$reasons = $this->get_default_reasons();
		}
		return $reasons;
	}

	public function get_resolution_types() {
		return array(
			'repair'         => __( 'Repair', 'zorem-returns' ),
			'replacement'    => __( 'Replacement Product', 'zorem-returns' ),
			'partial_refund' => __( 'Partial Refund', 'zorem-returns' ),
		);
	}

	public function get_statuses() {
		return array(
			'submitted'   => __( 'Submitted', 'zorem-returns' ),
			'under-review' => __( 'Under Review', 'zorem-returns' ),
			'approved'    => __( 'Approved', 'zorem-returns' ),
			'rejected'    => __( 'Rejected', 'zorem-returns' ),
			'resolved'    => __( 'Resolved', 'zorem-returns' ),
		);
	}

	public function get_status_label( $status ) {
		$all = $this->get_statuses();
		return isset( $all[ $status ] ) ? $all[ $status ] : ucfirst( str_replace( '-', ' ', $status ) );
	}

	/* --------------------------------------------------------------------
	 * Eligibility / window checks
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the customer is past the standard return window for this order.
	 * Warranty claims become available once returns are no longer possible.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public function is_past_return_window( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		$return_window_start_date = $order->get_meta( '_return_window_start_date' );
		if ( $return_window_start_date ) {
			$order_date = $return_window_start_date;
		} elseif ( $order->get_date_completed() ) {
			$order_date = $order->get_date_completed()->date( 'Y-m-d' );
		} else {
			$order_date = $order->get_date_created()->date( 'Y-m-d' );
		}
		$valid_days = absint( zorem_returns_exchanges()->common->return_settings( 'return_policy_days', '14' ) );

		// Per-user extension.
		$per_user_enabled = zorem_returns_exchanges()->common->return_settings( 'per_user_return_window_enable', 'no' );
		if ( 'yes' === $per_user_enabled ) {
			$customer_id = $order->get_customer_id();
			if ( $customer_id ) {
				$custom_days = absint( get_user_meta( $customer_id, '_zorem_return_window_days', true ) );
				if ( $custom_days > 0 ) {
					$valid_days = $custom_days;
				}
			}
		}

		$days_since = ( strtotime( gmdate( 'Y-m-d' ) ) - strtotime( $order_date ) ) / DAY_IN_SECONDS;
		return $days_since > $valid_days;
	}

	/**
	 * Returns an array of items in this order that are STILL within warranty
	 * (and past the return window). Each entry: [ item_id, product_id, name, days_left ].
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public function get_eligible_items( $order ) {
		$out = array();
		if ( ! $this->is_enabled() || ! is_object( $order ) ) {
			return $out;
		}
		// Warranty applies *after* the return window expires.
		if ( ! $this->is_past_return_window( $order ) ) {
			return $out;
		}

		$base_date = $order->get_date_completed() ? $order->get_date_completed() : $order->get_date_created();
		if ( ! $base_date ) {
			return $out;
		}
		$base_ts = $base_date->getTimestamp();

		// Items that already have a warranty claim on this order — these are excluded so
		// the customer can't open a second claim for the same item while one is in flight
		// (or after it's been resolved/rejected). They can still file for OTHER items in
		// the same order that haven't been claimed yet. To re-file a rejected claim, the
		// customer should contact support.
		$existing_claims = $this->get_claims_by_order( $order->get_id() );
		$claimed_item_ids = array();
		foreach ( $existing_claims as $existing ) {
			$claimed_item_ids[ (int) $existing->item_id ] = true;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = method_exists( $item, 'get_product_id' ) ? $item->get_product_id() : 0;
			if ( ! $product_id ) {
				continue;
			}
			if ( isset( $claimed_item_ids[ (int) $item_id ] ) ) {
				continue;
			}
			$warranty_days = $this->get_product_warranty_days( $product_id );
			if ( $warranty_days <= 0 ) {
				continue;
			}
			$elapsed_days = (int) floor( ( current_time( 'timestamp', true ) - $base_ts ) / DAY_IN_SECONDS );
			$days_left    = $warranty_days - $elapsed_days;
			if ( $days_left <= 0 ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			$out[]   = array(
				'item_id'    => (int) $item_id,
				'product_id' => (int) $product_id,
				'name'       => $product ? $product->get_name() : $item->get_name(),
				'days_left'  => $days_left,
				'warranty_days' => $warranty_days,
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Per-product field (woocommerce_product_options_shipping)
	 * ------------------------------------------------------------------ */

	public function product_warranty_field() {
		echo '</div><div class="options_group per_product_warranty">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_zorem_warranty_period_days',
				'label'             => __( 'Warranty Period (Days)', 'zorem-returns' ),
				'description'       => __( 'Days the warranty is valid from the order completion date. Leave blank or 0 to use the category / global default. Enter -1 to explicitly disable warranty for this product.', 'zorem-returns' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '-1',
					'step' => '1',
				),
			)
		);
		wp_nonce_field( 'save_zorem_warranty_field', 'zorem_warranty_field_nonce' );
	}

	public function save_product_warranty_field( $post_id ) {
		$nonce = isset( $_POST['zorem_warranty_field_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['zorem_warranty_field_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'save_zorem_warranty_field' ) ) {
			return;
		}
		if ( isset( $_POST['_zorem_warranty_period_days'] ) && '' !== $_POST['_zorem_warranty_period_days'] ) {
			$val = intval( wp_unslash( $_POST['_zorem_warranty_period_days'] ) );
			update_post_meta( $post_id, '_zorem_warranty_period_days', $val );
		} else {
			delete_post_meta( $post_id, '_zorem_warranty_period_days' );
		}
	}

	/* --------------------------------------------------------------------
	 * Per-category field
	 * ------------------------------------------------------------------ */

	public function category_warranty_add_field() {
		?>
		<div class="form-field">
			<label for="zorem_warranty_period_days"><?php esc_html_e( 'Warranty Period (Days)', 'zorem-returns' ); ?></label>
			<input type="number" name="zorem_warranty_period_days" id="zorem_warranty_period_days" value="" min="-1" />
			<p class="description"><?php esc_html_e( 'Default warranty days for products in this category. Leave blank or 0 to inherit the global default. Enter -1 to disable warranty for this category.', 'zorem-returns' ); ?></p>
		</div>
		<?php
	}

	public function category_warranty_edit_field( $term ) {
		$days = get_term_meta( $term->term_id, '_zorem_warranty_period_days', true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="zorem_warranty_period_days"><?php esc_html_e( 'Warranty Period (Days)', 'zorem-returns' ); ?></label></th>
			<td>
				<input type="number" name="zorem_warranty_period_days" id="zorem_warranty_period_days" value="<?php echo esc_attr( $days ); ?>" min="-1" />
				<p class="description"><?php esc_html_e( 'Default warranty days for products in this category. Leave blank or 0 to inherit the global default. Enter -1 to disable warranty for this category.', 'zorem-returns' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function save_category_warranty_field( $term_id ) {
		if ( isset( $_POST['_wpnonce_add-tag'] ) ) {
			$nonce  = sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) );
			$action = 'add-tag';
		} elseif ( isset( $_POST['_wpnonce'] ) ) {
			$nonce  = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
			$action = 'update-tag_' . $term_id;
		} else {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		if ( isset( $_POST['zorem_warranty_period_days'] ) && '' !== $_POST['zorem_warranty_period_days'] ) {
			$val = intval( wp_unslash( $_POST['zorem_warranty_period_days'] ) );
			update_term_meta( $term_id, '_zorem_warranty_period_days', $val );
		} else {
			delete_term_meta( $term_id, '_zorem_warranty_period_days' );
		}
	}

	/* --------------------------------------------------------------------
	 * Customer-facing UI
	 * ------------------------------------------------------------------ */

	/**
	 * Show the Warranty section under the order details. Two parts:
	 *  - Existing-claims list (always shown if the customer has any claim — so they can
	 *    track status, even after the warranty window closes or every item is claimed).
	 *  - Submit button (shown only if there's at least one item still warranty-eligible
	 *    AND not already claimed — otherwise hidden so the customer can't double-file).
	 *
	 * @param WC_Order $order
	 */
	public function maybe_render_warranty_button( $order ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$eligible        = $this->get_eligible_items( $order );
		$existing_claims = $this->get_claims_by_order( $order->get_id() );

		// Nothing to show at all? Hide the section entirely.
		if ( empty( $eligible ) && empty( $existing_claims ) ) {
			return;
		}

		echo '<div class="zorem-warranty-section">';
		echo '<h2>' . esc_html__( 'Warranty', 'zorem-returns' ) . '</h2>';

		if ( ! empty( $existing_claims ) ) {
			echo '<div class="zorem-warranty-claims-list">';
			foreach ( $existing_claims as $claim ) {
				$status_label = $this->get_status_label( $claim->claim_status );
				echo '<div class="zorem-warranty-claim-row status-' . esc_attr( $claim->claim_status ) . '">';
				echo '<div class="zorem-warranty-claim-meta">';
				echo '<strong>#' . esc_html( $claim->id ) . '</strong> &middot; ';
				echo '<span class="zorem-warranty-status-pill status-' . esc_attr( $claim->claim_status ) . '">' . esc_html( $status_label ) . '</span> ';
				echo '<span class="zorem-warranty-claim-date">' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $claim->created_at . ' UTC' ) ) ) . '</span>';
				echo '</div>';
				if ( ! empty( $claim->claim_reason ) ) {
					echo '<div class="zorem-warranty-claim-reason"><b>' . esc_html__( 'Reason', 'zorem-returns' ) . ':</b> ' . esc_html( $claim->claim_reason ) . '</div>';
				}
				if ( ! empty( $claim->resolution_type ) ) {
					$resolution_label = isset( $this->get_resolution_types()[ $claim->resolution_type ] ) ? $this->get_resolution_types()[ $claim->resolution_type ] : $claim->resolution_type;
					echo '<div class="zorem-warranty-claim-resolution"><b>' . esc_html__( 'Resolution', 'zorem-returns' ) . ':</b> ' . esc_html( $resolution_label );
					if ( ! empty( $claim->resolution_note ) ) {
						echo ' &mdash; ' . esc_html( $claim->resolution_note );
					}
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		// Only show the Submit button if at least one item in the order is still
		// warranty-eligible AND hasn't already been claimed.
		if ( ! empty( $eligible ) ) {
			$url = wc_get_account_endpoint_url( 'warranty' ) . '?order_id=' . $order->get_id();
			echo '<div class="zorem-warranty-action"><a href="' . esc_url( $url ) . '" class="button zorem-warranty-button">' . esc_html__( 'Submit Warranty Claim', 'zorem-returns' ) . '</a></div>';
		} elseif ( ! empty( $existing_claims ) ) {
			echo '<p class="zorem-warranty-all-claimed" style="margin-top:8px;color:#646970;font-style:italic;">' . esc_html__( 'All eligible items for this order have an active warranty claim. Contact support if you need to file again.', 'zorem-returns' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * /my-account/warranty/?order_id=N — render the claim form OR a status view.
	 */
	public function render_endpoint_content() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Invalid order.', 'zorem-returns' ) . '</p>';
			return;
		}
		// Ownership check.
		if ( is_user_logged_in() && (int) $order->get_customer_id() !== get_current_user_id() ) {
			echo '<p>' . esc_html__( 'You do not have permission to view this order.', 'zorem-returns' ) . '</p>';
			return;
		}

		$eligible = $this->get_eligible_items( $order );
		if ( empty( $eligible ) ) {
			echo '<p>' . esc_html__( 'No items in this order are eligible for a warranty claim.', 'zorem-returns' ) . '</p>';
			return;
		}

		$reasons = $this->get_reasons();
		include __DIR__ . '/htmls/warranty-claim-form.php';
	}

	/* --------------------------------------------------------------------
	 * Claim CRUD
	 * ------------------------------------------------------------------ */

	public function get_claims_by_order( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return array();
		}
		$table = $wpdb->prefix . 'warranty_claims';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY created_at DESC, id DESC", $order_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	public function get_claim( $claim_id ) {
		global $wpdb;
		$claim_id = absint( $claim_id );
		if ( ! $claim_id ) {
			return null;
		}
		$table = $wpdb->prefix . 'warranty_claims';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $claim_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function list_claims( $args = array() ) {
		global $wpdb;
		$defaults = array(
			'status' => '',
			'limit'  => 50,
			'offset' => 0,
		);
		$args  = wp_parse_args( $args, $defaults );
		$table = $wpdb->prefix . 'warranty_claims';

		$where = '1=1';
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND claim_status = %s';
			$params[] = $args['status'];
		}
		$params[] = absint( $args['limit'] );
		$params[] = absint( $args['offset'] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name and WHERE clause are built from validated values.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/* --------------------------------------------------------------------
	 * AJAX — submit claim
	 * ------------------------------------------------------------------ */

	public function ajax_submit_claim() {
		check_ajax_referer( 'zorem_warranty_nonce', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$item_id  = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$desc     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'zorem-returns' ) ) );
		}
		if ( is_user_logged_in() && (int) $order->get_customer_id() !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'zorem-returns' ) ) );
		}
		// Guest submissions (via the [warranty-center] shortcode) must prove ownership
		// with the order's billing email — same verification as the guest return form.
		if ( ! is_user_logged_in() ) {
			$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
			if ( '' === $customer_email || strtolower( $order->get_billing_email() ) !== strtolower( $customer_email ) ) {
				wp_send_json_error( array( 'message' => __( 'This email address does not match the order number.', 'zorem-returns' ) ) );
			}
		}
		if ( '' === $reason || '' === $desc ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a reason and describe the issue.', 'zorem-returns' ) ) );
		}

		// Verify the item is still warranty-eligible.
		$eligible    = $this->get_eligible_items( $order );
		$eligible_ids = wp_list_pluck( $eligible, 'item_id' );
		if ( ! in_array( $item_id, $eligible_ids, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This item is no longer covered by warranty.', 'zorem-returns' ) ) );
		}

		// Handle attachments (reuse the existing upload helper for return attachments).
		$attachments = array();
		if ( ! empty( $_FILES['warranty_attachments']['name'][0] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File uploads are validated in handle_attachment_uploads().
			$attachments = $this->handle_attachment_uploads( wp_unslash( $_FILES['warranty_attachments'] ) );
			if ( is_wp_error( $attachments ) ) {
				wp_send_json_error( array( 'message' => $attachments->get_error_message() ) );
			}
		}

		// Resolve product_id from item.
		$product_id = 0;
		foreach ( $eligible as $e ) {
			if ( $e['item_id'] === $item_id ) {
				$product_id = $e['product_id'];
				break;
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'warranty_claims';
		$wpdb->insert(
			$table,
			array(
				'order_id'          => $order_id,
				'item_id'           => $item_id,
				'product_id'        => $product_id,
				'user_id'           => (int) $order->get_customer_id(),
				'claim_status'      => 'submitted',
				'claim_reason'      => $reason,
				'claim_description' => $desc,
				'claim_attachments' => empty( $attachments ) ? null : wp_json_encode( $attachments ),
				'created_at'        => current_time( 'mysql', true ),
			)
		);
		$claim_id = (int) $wpdb->insert_id;
		if ( ! $claim_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save the claim.', 'zorem-returns' ) ) );
		}

		// Notify admin + customer.
		$this->send_notification( $claim_id, 'submitted' );

		wp_send_json_success(
			array(
				'message'  => __( 'Warranty claim submitted. We will review and contact you shortly.', 'zorem-returns' ),
				'claim_id' => $claim_id,
			)
		);
	}

	/**
	 * Upload claim photo attachments. Mirrors the validation used by the return-attachment
	 * upload (jpg/jpeg/png/gif/webp, 5MB max). Returns array of attachment URLs OR WP_Error.
	 *
	 * @param array $files
	 * @return array|WP_Error
	 */
	protected function handle_attachment_uploads( $files ) {
		$allowed_types = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
		$max_size      = 5 * 1024 * 1024;
		$count         = isset( $files['name'] ) ? count( (array) $files['name'] ) : 0;
		if ( ! $count ) {
			return array();
		}

		// Pre-validate.
		for ( $i = 0; $i < $count; $i++ ) {
			$ext = strtolower( pathinfo( $files['name'][ $i ], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_types, true ) ) {
				return new WP_Error( 'invalid_type', __( 'Only image files (jpg, png, gif, webp) are allowed.', 'zorem-returns' ) );
			}
			if ( (int) $files['size'][ $i ] > $max_size ) {
				return new WP_Error( 'too_large', __( 'Each file must be under 5 MB.', 'zorem-returns' ) );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$urls = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$single = array(
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
			$_FILES['warranty_single'] = $single;
			$id = media_handle_upload( 'warranty_single', 0 );
			unset( $_FILES['warranty_single'] );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$urls[] = wp_get_attachment_url( $id );
		}
		return $urls;
	}

	/* --------------------------------------------------------------------
	 * AJAX — admin status update
	 * ------------------------------------------------------------------ */

	public function ajax_update_status() {
		check_ajax_referer( 'zorem_warranty_admin_nonce', 'nonce' );

		$capability = zorem_returns_exchanges()->admin->get_admin_capability();
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'zorem-returns' ) ) );
		}

		$claim_id = isset( $_POST['claim_id'] ) ? absint( $_POST['claim_id'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$resolution = isset( $_POST['resolution_type'] ) ? sanitize_text_field( wp_unslash( $_POST['resolution_type'] ) ) : '';
		$note     = isset( $_POST['resolution_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['resolution_note'] ) ) : '';

		if ( ! $claim_id || ! array_key_exists( $status, $this->get_statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'zorem-returns' ) ) );
		}
		$claim = $this->get_claim( $claim_id );
		if ( ! $claim ) {
			wp_send_json_error( array( 'message' => __( 'Claim not found.', 'zorem-returns' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'warranty_claims';
		$data  = array(
			'claim_status' => $status,
			'admin_user_id' => get_current_user_id(),
			'updated_at'   => current_time( 'mysql', true ),
		);
		if ( 'resolved' === $status ) {
			if ( '' === $resolution ) {
				wp_send_json_error( array( 'message' => __( 'Please choose a resolution type before resolving.', 'zorem-returns' ) ) );
			}
			$data['resolution_type'] = $resolution;
			$data['resolution_note'] = $note;
		} elseif ( '' !== $resolution ) {
			$data['resolution_type'] = $resolution;
			if ( '' !== $note ) {
				$data['resolution_note'] = $note;
			}
		}

		$wpdb->update( $table, $data, array( 'id' => $claim_id ) );

		$this->send_notification( $claim_id, $status );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: new status label */
					__( 'Claim updated to: %s.', 'zorem-returns' ),
					$this->get_status_label( $status )
				),
				'status' => $status,
				'status_label' => $this->get_status_label( $status ),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Email notifications
	 * ------------------------------------------------------------------ */

	/**
	 * Default subject/heading/content for all warranty emails. Used both by the
	 * customizer (so admins can edit them) and by send_notification() as a fallback
	 * when the admin hasn't saved any customizations yet.
	 *
	 * Storage key per email: `wt4wc_<key>_settings` (e.g. `wt4wc_customer_warranty_approved_settings`).
	 * On/off toggle per email: `<key>_status` (e.g. `customer_warranty_approved_status`).
	 *
	 * @return array
	 */
	public function generate_email_defaults() {
		return array(
			/* ----- Customer-facing ----- */
			'customer_warranty_submitted'    => array(
				'subject'            => esc_html__( 'Your Warranty Claim Has Been Received', 'zorem-returns' ),
				'heading'            => esc_html__( 'Your Warranty Claim Has Been Received', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'Thank you for submitting your warranty claim. Our team will review your request and get back to you shortly.', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Warranty Claim Details', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_warranty_under_review' => array(
				'subject'            => esc_html__( 'Your Warranty Claim Is Under Review', 'zorem-returns' ),
				'heading'            => esc_html__( 'Your Warranty Claim Is Under Review', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'We are currently reviewing your warranty claim. We will contact you as soon as we have an update.', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Warranty Claim Details', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you for your patience', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_warranty_approved'     => array(
				'subject'            => esc_html__( 'Your Warranty Claim Has Been Approved', 'zorem-returns' ),
				'heading'            => esc_html__( 'Warranty Claim Approved', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'Good news — your warranty claim has been approved. We will follow up shortly with next steps.', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Approved Warranty Claim', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_warranty_rejected'     => array(
				'subject'            => esc_html__( 'Update On Your Warranty Claim', 'zorem-returns' ),
				'heading'            => esc_html__( 'Warranty Claim Rejected', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'We are sorry to inform you that your warranty claim was not approved. Please see the note below for more information, and feel free to reply with any questions.', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Warranty Claim Details', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			'customer_warranty_resolved'     => array(
				'subject'            => esc_html__( 'Your Warranty Claim Has Been Resolved', 'zorem-returns' ),
				'heading'            => esc_html__( 'Warranty Claim Resolved', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'Your warranty claim has been resolved. Thank you for giving us the opportunity to make it right.', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Resolved Warranty Claim', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Thank you', 'zorem-returns' ) . ', {site_title}',
			),
			/* ----- Admin-facing ----- */
			'admin_warranty_submitted'       => array(
				'subject'            => esc_html__( 'New Warranty Claim Received', 'zorem-returns' ) . ' – #{order_number}',
				'heading'            => esc_html__( 'A New Warranty Claim Has Been Submitted', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'A new warranty claim has just been submitted:', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Warranty Claim Details', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Open the Warranty Claims dashboard to review and update the claim.', 'zorem-returns' ),
			),
			'admin_warranty_under_review'    => array(
				'subject'            => esc_html__( 'Warranty Claim Moved To Under Review', 'zorem-returns' ) . ' – #{order_number}',
				'heading'            => esc_html__( 'Warranty Claim Under Review', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'The following warranty claim has been moved to "Under Review":', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Warranty Claim Details', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Open the Warranty Claims dashboard to take the next step.', 'zorem-returns' ),
			),
			'admin_warranty_approved'        => array(
				'subject'            => esc_html__( 'Warranty Claim Approved', 'zorem-returns' ) . ' – #{order_number}',
				'heading'            => esc_html__( 'Warranty Claim Approved', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'The following warranty claim has been approved:', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Approved Warranty Claim', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Open the Warranty Claims dashboard to complete the resolution.', 'zorem-returns' ),
			),
			'admin_warranty_rejected'        => array(
				'subject'            => esc_html__( 'Warranty Claim Rejected', 'zorem-returns' ) . ' – #{order_number}',
				'heading'            => esc_html__( 'Warranty Claim Rejected', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'The following warranty claim has been rejected:', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Warranty Claim Details', 'zorem-returns' ),
				'additional_content' => esc_html__( 'Open the Warranty Claims dashboard if you need to revisit this claim.', 'zorem-returns' ),
			),
			'admin_warranty_resolved'        => array(
				'subject'            => esc_html__( 'Warranty Claim Resolved', 'zorem-returns' ) . ' – #{order_number}',
				'heading'            => esc_html__( 'Warranty Claim Resolved', 'zorem-returns' ),
				'email_content'      => htmlspecialchars_decode( __( 'The following warranty claim has been marked as resolved:', 'zorem-returns' ) ),
				'claim_items_title'  => esc_html__( 'Resolved Warranty Claim', 'zorem-returns' ),
				'additional_content' => esc_html__( 'No further action is required.', 'zorem-returns' ),
			),
		);
	}

	/**
	 * Map a claim status to the {customer_key, admin_key} pair of email IDs that
	 * should fire when a claim enters that status. Used by send_notification().
	 *
	 * @param string $status
	 * @return array|null
	 */
	private function emails_for_status( $status ) {
		$map = array(
			'submitted'    => array( 'customer' => 'customer_warranty_submitted',    'admin' => 'admin_warranty_submitted' ),
			'under-review' => array( 'customer' => 'customer_warranty_under_review', 'admin' => 'admin_warranty_under_review' ),
			'approved'     => array( 'customer' => 'customer_warranty_approved',     'admin' => 'admin_warranty_approved' ),
			'rejected'     => array( 'customer' => 'customer_warranty_rejected',     'admin' => 'admin_warranty_rejected' ),
			'resolved'     => array( 'customer' => 'customer_warranty_resolved',     'admin' => 'admin_warranty_resolved' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : null;
	}

	/**
	 * Send notification email(s) for a claim status change. Each (customer/admin)
	 * email respects its own `<key>_status` enable toggle on the Notifications tab
	 * and pulls subject/heading/content from `wt4wc_<key>_settings` (set via the
	 * customizer). Plain wp_mail fallback was replaced with the WC mailer so the
	 * messages render in the same branded email frame as the return emails.
	 *
	 * @param int    $claim_id
	 * @param string $status
	 */
	public function send_notification( $claim_id, $status ) {
		$claim = $this->get_claim( $claim_id );
		if ( ! $claim ) {
			return;
		}
		$order = wc_get_order( $claim->order_id );
		if ( ! $order ) {
			return;
		}

		$keys = $this->emails_for_status( $status );
		if ( ! $keys ) {
			return;
		}

		// Customer email — recipient is the order's billing email.
		// WPML: rendered in the language the order was placed in.
		$customer_email = $order->get_billing_email();
		if ( $customer_email && 'yes' === get_option( $keys['customer'] . '_status', 'yes' ) ) {
			zorem_returns_exchanges()->wpml->switch_to_order_language( $order );
			$this->dispatch_email( $keys['customer'], $customer_email, $claim, $order );
			zorem_returns_exchanges()->wpml->restore_language();
		}

		// Admin email — admin_email option is the recipient by default.
		// WPML: rendered in the site's default language.
		$admin_email = get_option( 'admin_email' );
		if ( $admin_email && 'yes' === get_option( $keys['admin'] . '_status', 'yes' ) ) {
			zorem_returns_exchanges()->wpml->switch_to_site_language();
			$this->dispatch_email( $keys['admin'], $admin_email, $claim, $order );
			zorem_returns_exchanges()->wpml->restore_language();
		}
	}

	/**
	 * Render a warranty email template + send it through the WC mailer (same
	 * styling pipeline as the return/exchange emails). Subject, heading, and
	 * body come from `wt4wc_<key>_settings`, falling back to the defaults from
	 * generate_email_defaults() when the admin hasn't customized them.
	 *
	 * @param string   $key            e.g. customer_warranty_approved
	 * @param string   $recipient
	 * @param object   $claim
	 * @param WC_Order $order
	 */
	private function dispatch_email( $key, $recipient, $claim, $order ) {
		$defaults       = $this->generate_email_defaults();
		$email_settings = get_option( 'wt4wc_' . $key . '_settings', isset( $defaults[ $key ] ) ? $defaults[ $key ] : array() );

		$subject       = isset( $email_settings['subject'] ) ? $email_settings['subject'] : ( isset( $defaults[ $key ]['subject'] ) ? $defaults[ $key ]['subject'] : '' );
		$email_heading = isset( $email_settings['heading'] ) ? $email_settings['heading'] : ( isset( $defaults[ $key ]['heading'] ) ? $defaults[ $key ]['heading'] : '' );

		$customer     = new WC_Customer( $order->get_customer_id() );
		$replace_keys = array( '{site_title}', '{order_number}', '{order_date}', '{customer_full_name}', '{customer_first_name}', '{customer_last_name}', '{customer_username}', '{claim_id}', '{claim_status}' );
		$replace_vals = array(
			get_bloginfo( 'name' ),
			$order->get_order_number(),
			wc_format_datetime( $order->get_date_created() ),
			$order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$customer->get_username(),
			$claim->id,
			$this->get_status_label( $claim->claim_status ),
		);
		$subject       = str_replace( $replace_keys, $replace_vals, $subject );
		$email_heading = str_replace( $replace_keys, $replace_vals, $email_heading );

		$template_slug = str_replace( '_', '-', $key );

		// Allow theme overrides at /yourtheme/woocommerce/zorem-returns-exchanges/emails/<slug>.php — same pattern as return emails.
		$local_template = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/emails/' . $template_slug . '.php';
		if ( file_exists( $local_template ) && wp_is_writable( $local_template ) ) {
			$directory_path = get_stylesheet_directory() . '/woocommerce/zorem-returns-exchanges/';
		} else {
			$directory_path = zorem_returns_exchanges()->get_plugin_path() . '/templates/';
		}

		$mailer = WC()->mailer();
		$email  = new WC_Email();

		ob_start();
		wc_get_template(
			'emails/' . $template_slug . '.php',
			array(
				'claim_id'       => $claim->id,
				'claim'          => $claim,
				'order'          => $order,
				'email'          => $email,
				'email_settings' => $email_settings,
			),
			'zorem-returns-exchanges/',
			$directory_path
		);
		$message = ob_get_clean();

		$message = apply_filters( 'woocommerce_mail_content', $email->style_inline( $mailer->wrap_message( $email_heading, $message ) ) );

		add_filter( 'wp_mail_from', array( zorem_returns_exchanges()->front, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( zorem_returns_exchanges()->front, 'get_from_name' ) );

		wp_mail( $recipient, $subject, $message, $email->get_headers() );
	}
}
