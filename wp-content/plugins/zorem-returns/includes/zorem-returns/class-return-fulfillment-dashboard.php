<?php

// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Return_Fulfillment_Dashboard {

	/**
	 * Instance of this class.
	 *
	 * @var object Class Instance
	 */
	private static $instance;

	/**
	 * Initialize the main plugin function
	 */
	public function __construct() {

		global $wpdb;
		$this->init();
	}

	/**
	 * Get the class instance
	 *
	 * @return Return_Fulfillment_Dashboard
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/*
	* init from parent mail class
	*/
	public function init() {
		// load shipments css js
		add_action( 'admin_enqueue_scripts', array( $this, 'fulfillment_dashboard_styles' ), 1 );

		add_action( 'wp_ajax_get_return_orders', array( $this, 'get_return_orders' ) );
		add_action( 'wp_ajax_show_return_preview_images', array( $this, 'display_return_preview_images' ) );
		add_action( 'wp_ajax_show_return_preview_data', array( $this, 'display_return_preview_data' ) );
		add_action( 'wp_ajax_update_return_request_status', array( $this, 'update_return_request_status' ) );
		add_action( 'wp_ajax_rt4wc_upload_return_label', array( $this, 'upload_return_label' ) );
	}

	/**
	 * Load trackship styles.
	 */
	public function fulfillment_dashboard_styles( $hook ) {

		if ( ! isset( $_GET['page'] ) ) {
			return;
		}

		if ( 'zorem-returns-exchanges' == $_GET['page'] ) {

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script( 'wc-backbone-modal', WC()->plugin_url() . '/assets/js/admin/backbone-modal' . $suffix . '.js', array( 'underscore', 'backbone', 'wp-util' ), zorem_returns_exchanges()->version );

			wp_register_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
			wp_enqueue_style( 'woocommerce_admin_styles' );

			// dataTables library
			wp_register_script( 'rt4wc_fulfillment_script', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/fulfillment.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );

			wp_enqueue_script( 'wc-backbone-modal' );
			wp_enqueue_script( 'rt4wc_fulfillment_script' );
			wp_localize_script(
				'rt4wc_fulfillment_script',
				'rt4wc_fulfillment_script',
				array(
					'admin_url'     => admin_url(),
					'preview_nonce' => wp_create_nonce( 'woocommerce-preview-order' ),
				)
			);

			// dataTables library
			wp_enqueue_script( 'Returns_dataTable', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/jquery.dataTables.min.js', array( 'jquery' ), '2.3.4', true );
			wp_enqueue_style( 'Returns_dataTable', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/jquery.dataTables.min.css', array(), '2.3.4', 'all' );
			wp_enqueue_style( 'rt4wc_fulfillment_styles', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/fulfillment.css', array(), zorem_returns_exchanges()->version );

			// ✅ Add Buttons extension for DataTables v2.x
			wp_enqueue_script( 'DataTable-Buttons', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/datatable-button.js', array( 'Returns_dataTable' ), '3.1.2', true );
			wp_enqueue_script( 'DataTable-Buttons-HTML5', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/datatable-button-html.js', array( 'DataTable-Buttons' ), '3.1.2', true );
			wp_enqueue_style( 'DataTable-Buttons-CSS', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/datatable-button.css', array( 'Returns_dataTable' ), '3.1.2', 'all' );

			/**
			 * Modern redesign overlay — rounded pill toolbar, table card,
			 * customer avatar, modernized status pills, icon-only action
			 * buttons, flex-row footer. Dependencies are listed in the
			 * `array(...)` so WordPress emits this `<link>` AFTER both
			 * `rt4wc_fulfillment_styles` (the legacy fulfillment.css whose
			 * aggressive .wc-action-button + column-2 gray-tint rules we
			 * need to override) AND `DataTable-Buttons-CSS` (which could
			 * re-style the Export CSV pill). That way our modern rules
			 * always win the cascade.
			 */
			wp_enqueue_style(
				'rt4wc_fulfillment_modern_styles',
				zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/fulfillment-modern.css',
				array( 'rt4wc_fulfillment_styles', 'DataTable-Buttons-CSS' ),
				zorem_returns_exchanges()->version
			);

			// Internal Admin Notes — JS + CSS for the per-request notes section in the preview modal.
			wp_enqueue_script(
				'zorem-internal-notes',
				zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/internal-notes.js',
				array( 'jquery' ),
				zorem_returns_exchanges()->version,
				true
			);
			wp_enqueue_style(
				'zorem-internal-notes',
				zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/internal-notes.css',
				array(),
				zorem_returns_exchanges()->version
			);
		}
	}

	public function display_return_preview_images() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'nonce_fullfillment_dashbaord' ) ) {
			return;
		}

		$id          = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
		$images      = ( null != $return_data->return_items_image ) ? unserialize( $return_data->return_items_image ) : array();
		ob_start();
		?>
		
		<div id="returns_preview_images" class="popupwrapper attachment-images">
			<div class="popuprow">
				<span class="dashicons dashicons-no-alt popup_close_icon"></span>
				<div class="slider">
					<span class="dashicons dashicons-arrow-left-alt2 previous"></span>
					<div class="img-container">
						<?php
						foreach ( $images as $image ) {
							echo '<div class="slide"><img src="' . esc_html( $image ) . '" alt=""></div>';
						}
						?>
					</div>
					<span class="dashicons dashicons-arrow-right-alt2 next"></span>
				</div>
			</div>
			<div class="popupclose"></div>
		</div>
		<?php
		$html = ob_get_clean();
		echo json_encode( array( 'show_preview_images' => $html ) );
		die();
	}

	public function display_return_preview_data() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'nonce_fullfillment_dashbaord' ) ) {
			return;
		}

		$id            = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$type          = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$return_status = isset( $_POST['return_status'] ) ? sanitize_text_field( wp_unslash( $_POST['return_status'] ) ) : '';

		$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
		if ( empty( $return_data ) ) {
			return;
		}

		$order         = wc_get_order( $return_data->order_id );
		$return_reason = ( null != $return_data->return_reason ) ? unserialize( $return_data->return_reason ) : array();
		$no_other      = isset( $return_reason['no_other'] ) ? $return_reason['no_other'] : '';
		$other_reason  = isset( $return_reason['other_reason'] ) ? $return_reason['other_reason'] : '';
		$return_issue  = isset( $return_data->return_issue ) ? $return_data->return_issue : '';
		$return_note   = isset( $return_data->return_note ) ? $return_data->return_note : '';

		$Status            = strtolower( str_replace( '-', '_', $return_data->return_status ) );
		$defaults          = zorem_returns_exchanges()->common->generate_defaults();
		$key               = ( 'return_cancelled' == $Status ) ? 'admin_' . $Status : 'customer_' . $Status;
		$email_settings    = get_option( 'rt4wc_customer_' . $Status . '_settings', $defaults[ $key ] );
		$item_heading_text = ! empty( $email_settings['item_heading_text'] ) ? esc_html( $email_settings['item_heading_text'] ) : esc_html__( 'Returned Product Summary', 'zorem-returns' );

		// Prepare return items
		$items        = array();
		$return_items = ( null != $return_data->return_items ) ? unserialize( $return_data->return_items ) : array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( empty( $return_items[ $item_id ] ) ) {
				continue;
			}

			$product = wc_get_product( $item->get_product_id() );
			if ( ! is_object( $product ) ) {
				continue;
			}

			$return_qty = isset( $return_items[ $item_id ]['return_product_qty'] ) ? $return_items[ $item_id ]['return_product_qty'] : 0;
			if ( $return_qty <= 0 ) {
				continue;
			}

			$product_price = $item->get_total() + $item->get_total_tax();

			$return_variation = '';
			$item_all_meta    = $item->get_all_formatted_meta_data();
			foreach ( $item_all_meta as $key => $meta ) {
				$display_key       = $meta->display_key;
				$display_value     = wp_strip_all_tags( $meta->display_value );
				$return_variation .= wp_kses_post( $display_key . ' : ' . $display_value );
				$keys              = array_keys( $item_all_meta );
				$return_variation .= ( end( $keys ) !== $key ) ? ' | ' : '';
			}

			// Fallback: get variation attributes directly from the variation product
			if ( empty( $return_variation ) && $item->get_variation_id() ) {
				$variation_product = wc_get_product( $item->get_variation_id() );
				if ( is_object( $variation_product ) && $variation_product->is_type( 'variation' ) ) {
					$variation_attributes = $variation_product->get_variation_attributes();
					$parts                = array();
					foreach ( $variation_attributes as $attr_key => $attr_value ) {
						$attr_slug  = str_replace( 'attribute_', '', $attr_key );
						$attr_label = wc_attribute_label( $attr_slug, $product );
						if ( taxonomy_exists( $attr_slug ) ) {
							$term = get_term_by( 'slug', $attr_value, $attr_slug );
							if ( $term && ! is_wp_error( $term ) ) {
								$attr_value = $term->name;
							}
						}
						if ( ! empty( $attr_value ) ) {
							$parts[] = wp_kses_post( $attr_label . ' : ' . ucfirst( $attr_value ) );
						}
					}
					$return_variation = implode( ' | ', $parts );
				}
			}

			$items[] = array(
				'name'      => $product->get_name(),
				'quantity'  => $return_qty,
				'variation' => ! empty( $return_variation ) ? $return_variation : '',
				'price'     => $product_price,
			);
		}

		ob_start();
		?>
		<div id="returns_preview_data" class="popupwrapper return-data">
			<div class="popuprow">
				<span class="dashicons dashicons-no-alt popup_close_icon"></span>
				<div class="return-request-details">
					<div class="return_request_header <?php echo esc_attr( sanitize_html_class( 'status-' . $return_data->return_status ) ); ?>">
						<?php if ( 'return-rejected' == $return_data->return_status || 'return-cancelled' == $return_data->return_status ) { ?>
							<span class="dashicons dashicons-dismiss"></span>
						<?php } else { ?>
							<span class="dashicons dashicons-yes-alt"></span>
						<?php } ?>
						<h4><?php echo esc_html( ucfirst( str_replace( '-', ' ', $return_data->return_status ) ) ); ?></h4>
					</div>

				<?php if ( isset( $return_data->keep_the_item ) && 'yes' === $return_data->keep_the_item ) { ?>
					<div class="keep-item-admin-notice">
						<strong><?php esc_html_e( 'Keep the Item', 'zorem-returns' ); ?></strong>
						<?php esc_html_e( 'No return shipping required. Customer retains the product. Refund will be processed automatically on approval.', 'zorem-returns' ); ?>
					</div>
				<?php } ?>
					<?php if ( ! empty( $items ) ) { ?>
						<h3 class="exchange-request-title"><?php echo esc_html( $item_heading_text ); ?></h3>
						<table class="exchange-request-table">
							<thead>
								<tr class="exchange-request-table-row">
									<th><?php echo esc_html__( 'Returned Item (Ordered)', 'zorem-returns' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $items as $returned ) {
									echo '<tr><td>';
									echo '<div class="product-name"><b>Product:</b> ' . esc_html( $returned['name'] ) . '</div>';
									if ( ! empty( $returned['variation'] ) ) {
										echo '<div>' . esc_html( $returned['variation'] ) . '</div>';
									}
									echo '<div><b>' . esc_html__( 'Qty:', 'zorem-returns' ) . '</b> ' . esc_html( $returned['quantity'] ) . '</div>';
									echo '<div><b>' . esc_html__( 'Price:', 'zorem-returns' ) . '</b> ' . wp_kses_post( wc_price( $returned['price'] ) ) . '</div>';
									echo '</td></tr>';
								}
								?>
							</tbody>
						</table>
					<?php } ?>

					<?php
					$reason = ( 'other_reason' === $no_other ) ? stripslashes( $other_reason ) : stripslashes( $no_other );
					echo '<p class="exchange-common"><b>' . esc_html__( 'Return Reason:', 'zorem-returns' ) . '</b> ' . esc_html( $reason ) . '</p>';

					// Selected resolution (Refund to Store Credit / Original Payment / Keep the Item & Partial Refund).
					if ( ! empty( $return_data->payment_method ) ) {
						$rt_resolution_label = Return_Exchange_Common::get_return_resolution_label( $return_data->payment_method );
						echo '<p class="exchange-common"><b>' . esc_html__( 'Selected Resolution:', 'zorem-returns' ) . '</b> ' . esc_html( $rt_resolution_label ) . '</p>';
						if ( 'keep_item_partial' === $return_data->payment_method ) {
							echo '<p class="exchange-common keep-item-partial-note"><em>' . esc_html__( 'Customer keeps the item. Process the partial refund manually from the WooCommerce Edit Order page — the plugin does not issue it automatically.', 'zorem-returns' ) . '</em></p>';
						}
					}

					if ( ! empty( $return_issue ) ) {
						echo '<p class="exchange-common"><b>' . esc_html__( 'Return Message:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $return_issue ) ) . '</p>';
					}

					if ( ! empty( $return_note ) && ( 'return-approved' == $return_data->return_status || 'return-completed' == $return_data->return_status ) ) {
						echo '<p class="exchange-common"><b>' . esc_html__( 'Return Approved Note:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $return_note ) ) . '</p>';
					}

					if ( ! empty( $return_note ) && 'return-rejected' == $return_data->return_status ) {
						echo '<p class="exchange-common"><b>' . esc_html__( 'Return Rejected Note:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $return_note ) ) . '</p>';
					}

					// Restocking fee — show snapshot for any status; allow edit only while return-requested.
					$has_fee_snapshot = isset( $return_data->restocking_fee_amount ) && (float) $return_data->restocking_fee_amount > 0;
					$fee_label_admin  = zorem_returns_exchanges()->restocking_fee ? zorem_returns_exchanges()->restocking_fee->get_label() : __( 'Restocking Fee', 'zorem-returns' );
					$fee_amount_admin = $has_fee_snapshot ? (float) $return_data->restocking_fee_amount : 0;

					if ( $has_fee_snapshot && 'return-requested' !== $return_data->return_status ) {
						echo '<p class="exchange-common"><b>' . esc_html( $fee_label_admin ) . ':</b> ' . wp_kses_post( wc_price( $fee_amount_admin ) ) . '</p>';
					}

					if ( isset( $return_data->return_status ) && 'return-requested' === $return_data->return_status ) {

						$placeholder = __( 'Add a note...', 'zorem-returns' );

						if ( 'return-approved' === $return_status ) {
							$placeholder = __( 'Add a note for return approval...', 'zorem-returns' );
						} elseif ( 'return-rejected' === $return_status ) {
							$placeholder = __( 'Add a note for return rejection...', 'zorem-returns' );
						}

						echo '<textarea name="return_note" id="return_note" rows="4" placeholder="' . esc_attr( $placeholder ) . '"></textarea>';

						// Editable restocking fee field — admin can adjust before approval.
						if ( $has_fee_snapshot || ( zorem_returns_exchanges()->restocking_fee && zorem_returns_exchanges()->restocking_fee->is_enabled() ) ) {
							echo '<div class="zorem-rf-admin-edit">';
							echo '<label for="restocking_fee_amount"><strong>' . esc_html( $fee_label_admin ) . '</strong></label>';
							echo '<input type="number" step="0.01" min="0" name="restocking_fee_amount" id="restocking_fee_amount" value="' . esc_attr( $fee_amount_admin ) . '" />';
							echo '<p class="description">' . esc_html__( 'Edit the restocking fee before approving the request. Leave 0 to refund the full amount.', 'zorem-returns' ) . '</p>';
							echo '</div>';
						}

						$is_keep_the_item_preview = ( isset( $return_data->keep_the_item ) && 'yes' === $return_data->keep_the_item );

						if ( 'return-approved' === $return_status ) {
							if ( ! $is_keep_the_item_preview ) {
								echo '<div class="return-label-upload-wrap">';
								echo '<label for="return_label_pdf"><strong>' . esc_html__( 'Attach Return Shipping Label (PDF)', 'zorem-returns' ) . '</strong></label>';
								echo '<input type="file" id="return_label_pdf" name="return_label_pdf" accept=".pdf" />';
								echo '<p class="description">' . esc_html__( 'Upload a return shipping label PDF (max 10MB). The customer will be able to download this.', 'zorem-returns' ) . '</p>';
								echo '</div>';
							}
							echo '<button type="button" class="button wc-action-button return_approved_button" status="return-approved" title="' . esc_attr__( 'Approve this return request', 'zorem-returns' ) . '" id="' . esc_attr( $return_data->id ) . '">' . esc_html__( 'Approve this return request', 'zorem-returns' ) . '</button>';
						} elseif ( 'return-rejected' === $return_status ) {
							echo '<button type="button" class="button wc-action-button return_rejected_button" status="return-rejected" title="' . esc_attr__( 'Reject Return', 'zorem-returns' ) . '" id="' . esc_attr( $return_data->id ) . '">' . esc_html__( 'Reject Return', 'zorem-returns' ) . '</button>';
						} else {
							if ( ! $is_keep_the_item_preview ) {
								echo '<div class="return-label-upload-wrap">';
								echo '<label for="return_label_pdf"><strong>' . esc_html__( 'Attach Return Shipping Label (PDF)', 'zorem-returns' ) . '</strong></label>';
								echo '<input type="file" id="return_label_pdf" name="return_label_pdf" accept=".pdf" />';
								echo '<p class="description">' . esc_html__( 'Upload a return shipping label PDF (max 10MB). The customer will be able to download this.', 'zorem-returns' ) . '</p>';
								echo '</div>';
							}
							echo '<div class="zorem-return-action-buttons-row">';
							echo '<button type="button" class="button wc-action-button return_rejected_button" status="return-rejected" title="' . esc_attr__( 'Reject Return', 'zorem-returns' ) . '" id="' . esc_attr( $return_data->id ) . '">' . esc_html__( 'Reject Return', 'zorem-returns' ) . '</button>';
							echo '<button type="button" class="button wc-action-button return_approved_button" status="return-approved" title="' . esc_attr__( 'Approve this return request', 'zorem-returns' ) . '" id="' . esc_attr( $return_data->id ) . '">' . esc_html__( 'Approve this return request', 'zorem-returns' ) . '</button>';
							echo '</div>';
						}
					}

					if ( isset( $return_data->return_status ) && 'return-approved' === $return_data->return_status ) {
						if ( ! empty( $return_data->return_label_pdf ) ) {
							$admin_download_url = admin_url( 'admin-ajax.php?action=rt4wc_download_return_label&request_id=' . $return_data->id . '&nonce=' . wp_create_nonce( 'rt4wc_download_label' ) );
							echo '<p class="return-label-info"><span class="dashicons dashicons-media-default"></span> ' . esc_html__( 'Return shipping label attached.', 'zorem-returns' ) . ' <a href="' . esc_url( $admin_download_url ) . '" target="_blank">' . esc_html__( 'View PDF', 'zorem-returns' ) . '</a></p>';
						}
						echo '<button type="button" class="button wc-action-button return_completed_button" status="return-completed" title="' . esc_attr__( 'Mark as Completed', 'zorem-returns' ) . '" id="' . esc_attr( $return_data->id ) . '">' . esc_html__( 'Mark as Completed', 'zorem-returns' ) . '</button>';
					}

					// Internal Admin Notes — private notes for the support team (admin-only).
					if ( isset( zorem_returns_exchanges()->internal_notes ) && is_object( zorem_returns_exchanges()->internal_notes ) ) {
						echo zorem_returns_exchanges()->internal_notes->render_section_html( $return_data->id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
			</div>
			<div class="popupclose"></div>
		</div>
		<?php
		$html = ob_get_clean();
		echo json_encode( array( 'show_preview_data' => $html ) );
		die();
	}

	public function update_return_request_status() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'nonce_fullfillment_dashbaord' ) ) {
			return;
		}

		$id            = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$return_status = isset( $_POST['return_status'] ) ? sanitize_text_field( wp_unslash( $_POST['return_status'] ) ) : '';
		$return_note   = isset( $_POST['return_note'] ) ? sanitize_text_field( wp_unslash( $_POST['return_note'] ) ) : '';

		$data = array(
			'return_status' => $return_status,
		);

		// Only update note for statuses that set a note (not for return-completed)
		if ( 'return-completed' !== $return_status ) {
			$data['return_note'] = $return_note;
		}

		// If admin edited the restocking fee before approval, persist the new value.
		// Only accept it on the approval transition; ignore otherwise so it can't be silently changed later.
		if ( 'return-approved' === $return_status ) {
			$edited_fee = 0.0;

			if ( isset( $_POST['restocking_fee_amount'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Numeric value is sanitized using floatval().
				$edited_fee = floatval( wp_unslash( $_POST['restocking_fee_amount'] ) );
			}

			$edited_fee = round( max( 0, $edited_fee ), 2 );

			$data['restocking_fee_amount'] = $edited_fee;
		}

		$where = array(
			'id' => $id,
		);

		// database functions
		global $wpdb;
		$table = $wpdb->prefix . 'return_orders';

		if ( 'return-delete' == $return_status ) {
			// Delete associated PDF label if exists
			$existing = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
			if ( ! empty( $existing->return_label_pdf ) ) {
				// Sanitize path — strips phar:// and other stream wrappers
				$existing_file_path = realpath( $existing->return_label_pdf );

				if ( false !== $existing_file_path && is_file( $existing_file_path ) ) {
					// Verify the file is within the expected directory
					$labels_dir = realpath( WP_CONTENT_DIR . '/uploads/rt4wc-labels' );

					if ( $labels_dir && strpos( $existing_file_path, $labels_dir ) === 0 ) {
						// Reconstruct path from trusted base dir + filename only
						$safe_filename       = basename( $existing_file_path );
						$reconstructed_path  = $labels_dir . DIRECTORY_SEPARATOR . $safe_filename;

						if ( file_exists( $reconstructed_path ) ) {
							wp_delete_file( $reconstructed_path );
						}
					}
				}
			}
			$wpdb->delete( $table, $where );
		} else {
			// Handle PDF upload when approving (skip for keep_the_item requests)
			$current_return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
			$is_keep_the_item    = ( isset( $current_return_data->keep_the_item ) && 'yes' === $current_return_data->keep_the_item );

			if ( 'return-approved' == $return_status && ! $is_keep_the_item && ! empty( $_FILES['return_label_pdf']['name'] ) ) {
				$pdf_path = $this->handle_return_label_upload( $id );
				if ( $pdf_path ) {
					$data['return_label_pdf'] = $pdf_path;
				}
			}
			$wpdb->update( $table, $data, $where );
		}

		$return_data      = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
		$is_keep_the_item = ( isset( $return_data->keep_the_item ) && 'yes' === $return_data->keep_the_item );

		if ( 'return-approved' == $return_status ) {

			// Send Email to Admin
			zorem_returns_exchanges()->front->send_return_admin_email_func( $id, $return_data->order_id, $return_status );

			// Process refund automatically for Keep the Item requests on approval
			if ( $is_keep_the_item ) {
				$pm = isset( $return_data->payment_method ) ? $return_data->payment_method : '';
				if ( 'customer_account' === $pm ) {
					zorem_returns_exchanges()->common->process_keep_item_refund( $return_data->id );
				} elseif ( 'tera_wallet' === $pm ) {
					zorem_returns_exchanges()->common->refund_to_tera_wallet( $return_data->id );
				}
			}

			$order            = wc_get_order( $return_data->order_id );
			$return_form_data = zorem_returns_exchanges()->common->get_returned_form_data( $order );
			$return_datas     = zorem_returns_exchanges()->common->get_data_by_order_id( $return_data->order_id );
			$is_valid_status  = false;
			foreach ( (array) $return_datas as $return_data ) {
				$return_status = isset( $return_data->return_status ) ? $return_data->return_status : '';
				if ( 'return-approved' == $return_status ) {
					$is_valid_status = true;
				}
			}

			if ( $is_valid_status && empty( $return_form_data ) ) {
				// update order status
				$order->set_status( 'return' );
				$order->save();
			} else {
				// update order status
				$order->set_status( 'partial-return' );
				$order->save();
			}
		}

		// Send admin email for completed/rejected statuses too (gated by per-key toggle).
		// Note: only run when the approved branch above was skipped, since that branch
		// shadows $return_status inside its foreach loop.
		if ( 'return-completed' === $return_status || 'return-rejected' === $return_status ) {
			zorem_returns_exchanges()->front->send_return_admin_email_func( $id, $return_data->order_id, $return_status );
		}

		if ( ! empty( $return_data ) ) {
			zorem_returns_exchanges()->front->send_return_email_func( $return_data->id, $return_data->order_id, $return_status );
		}

		// Restock items only when return is marked as completed (two-step workflow).
		// Skip restock for Keep the Item and for the "Keep the Item & Partial Refund"
		// resolution — customer retains the product in both cases.
		$is_keep_item_partial = isset( $return_data->payment_method ) && 'keep_item_partial' === $return_data->payment_method;
		if ( 'return-completed' == $return_status ) {
			if ( ! $is_keep_the_item && ! $is_keep_item_partial ) {
				zorem_returns_exchanges()->common->restock_return_items_by_request_id( $return_data->id );
			}

			// Refund to TeraWallet on completion (skip for keep_the_item — already refunded on approval)
			if ( isset( $return_data->payment_method ) && 'tera_wallet' == $return_data->payment_method && ! $is_keep_the_item ) {
				zorem_returns_exchanges()->common->refund_to_tera_wallet( $return_data->id );
			}

			// Generate Store Credit coupon + send the store-credit email on completion only.
			if ( isset( $return_data->payment_method ) && 'store_credit' == $return_data->payment_method ) {
				zorem_returns_exchanges()->common->create_coupon_by_return_requestId( $return_data->id );
			}
		}

		$array = array(
			'success' => true,
			'id'      => $id,
		);

		echo json_encode( $array );
		die();
	}

	/**
	 * Fetch WC order created-at timestamps in bulk for a list of order IDs.
	 *
	 * IMPORTANT: this exists specifically to keep the fulfillment-dashboard
	 * sort O(N) instead of O(N log N) `wc_get_order()` hydrations. Calling
	 * `wc_get_order()` inside a `usort()` comparator was blowing PHP's memory
	 * limit on this AJAX endpoint on sites with heavy WC order meta or lots
	 * of third-party plugins hooked into WC data-store hydration.
	 *
	 * Uses direct SQL against the HPOS `wc_orders` table when available and
	 * falls back to `wp_posts` for legacy CPT-mode installs, so both storage
	 * modes are covered without hydrating a WC_Order object.
	 *
	 * @param int[] $order_ids
	 * @return array<int,int>  order_id => unix timestamp (0 when unknown)
	 */
	protected function fetch_order_created_timestamps( $order_ids ) {
		$order_ids = array_unique( array_filter( array_map( 'intval', (array) $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		global $wpdb;
		$map          = array();
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// HPOS orders (WooCommerce 8+, HPOS-enabled installs).
		$hpos_table = $wpdb->prefix . 'wc_orders';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hpos_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_table}'" );
		if ( $hpos_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, date_created_gmt FROM {$hpos_table} WHERE id IN ({$placeholders})", $order_ids ) );
			foreach ( (array) $rows as $r ) {
				if ( ! empty( $r->date_created_gmt ) && '0000-00-00 00:00:00' !== $r->date_created_gmt ) {
					$map[ (int) $r->id ] = (int) strtotime( $r->date_created_gmt );
				}
			}
		}

		// Legacy CPT-mode storage — fill any order IDs still missing from wp_posts.
		$missing = array_diff( $order_ids, array_keys( $map ) );
		if ( ! empty( $missing ) ) {
			$missing_placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ({$missing_placeholders}) AND post_type IN ('shop_order','shop_order_placehold')", array_values( $missing ) ) );
			foreach ( (array) $rows as $r ) {
				if ( ! empty( $r->post_date_gmt ) && '0000-00-00 00:00:00' !== $r->post_date_gmt ) {
					$map[ (int) $r->ID ] = (int) strtotime( $r->post_date_gmt );
				}
			}
		}

		// Guarantee every requested id has an entry (default 0 for unknown / deleted orders).
		foreach ( $order_ids as $oid ) {
			if ( ! isset( $map[ $oid ] ) ) {
				$map[ $oid ] = 0;
			}
		}

		return $map;
	}

	public function get_return_orders() {

		check_ajax_referer( 'nonce_fullfillment_dashbaord', 'ajax_nonce' );

		$fulfillment_filter = isset( $_POST['fulfillment_filter'] ) ? wc_clean( wp_unslash( $_POST['fulfillment_filter'] ) ) : '';

		$row          = ( isset( $_POST['start'] ) ) ? wc_clean( wp_unslash( $_POST['start'] ) ) : '';
		$rowperpage   = ( isset( $_POST['length'] ) ) ? wc_clean( wp_unslash( $_POST['length'] ) ) : '';
		$search_input = ( isset( $_POST['fulfillment_search_input'] ) ) ? wc_clean( wp_unslash( $_POST['fulfillment_search_input'] ) ) : null;

		$return_general_settings_option = get_option( 'return_general_settings_option', array() );
		$exchange_enable                = isset( $return_general_settings_option['exchange_enable'] ) ? $return_general_settings_option['exchange_enable'] : 'yes';

		global $wpdb;

		if ( null != $search_input && ! empty( $fulfillment_filter ) ) {
			$order_query          = 'AND return_orders.order_id = ' . $search_input;
			$exchange_order_query = 'AND exchange_orders.order_id = ' . $search_input;
		} elseif ( null != $search_input && empty( $fulfillment_filter ) ) {
			$order_query          = 'WHERE return_orders.order_id = ' . $search_input;
			$exchange_order_query = 'WHERE exchange_orders.order_id = ' . $search_input;
		} else {
			$order_query          = '';
			$exchange_order_query = '';
		}

		$total_orders_query = $wpdb->get_var(
			"
			SELECT COUNT(*) AS total_orders
			FROM {$wpdb->prefix}return_orders AS return_orders 
		"
		);

		$exchnage_total_orders_query = 'yes' == $exchange_enable ? $wpdb->get_var(
			"
			SELECT COUNT(*) AS total_orders
			FROM {$wpdb->prefix}exchange_orders AS exchange_orders 
		"
		) : '';

		if ( '-1' == $rowperpage || ! empty( $search_input ) ) {
			$rowperpage = (int) $total_orders_query + (int) $exchnage_total_orders_query;

		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		if ( ! empty( $search_input ) && ( class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) || class_exists( 'Wt_Advanced_Order_Number' ) || class_exists( 'WC_Seq_Order_Number' ) || class_exists( 'WCJ_Order_Numbers' ) ) ) {

			$orders = $wpdb->get_results(
				$wpdb->prepare(
					"
				SELECT
					return_orders.ID AS request_id,
					return_orders.return_status AS status,
					return_orders.order_id AS order_id
				FROM {$wpdb->prefix}return_orders AS return_orders
				ORDER BY request_id DESC
				LIMIT %2d, %3d
			",
					$row,
					$rowperpage
				)
			);

			if ( 'yes' == $exchange_enable ) {
				$exchange_orders = $wpdb->get_results(
					$wpdb->prepare(
						"
					SELECT
						exchange_orders.ID AS request_id,
						exchange_orders.exchange_status AS status,
						exchange_orders.order_id AS order_id
					FROM {$wpdb->prefix}exchange_orders AS exchange_orders
					ORDER BY request_id DESC
					LIMIT %2d, %3d
				",
						$row,
						$rowperpage
					)
				);

				$orders = array_merge( $orders, $exchange_orders );


				// Sort merged results by WC order date descending (newest request first).
				// Pre-fetch dates via SQL — the comparator must not hydrate a WC_Order
				// (usort would do it O(N log N) times and blow PHP's memory limit).
				$order_dates = $this->fetch_order_created_timestamps( wp_list_pluck( $orders, 'order_id' ) );
				usort( $orders, function( $a, $b ) use ( $order_dates ) {
					$date_a = isset( $order_dates[ (int) $a->order_id ] ) ? $order_dates[ (int) $a->order_id ] : 0;
					$date_b = isset( $order_dates[ (int) $b->order_id ] ) ? $order_dates[ (int) $b->order_id ] : 0;
					if ( $date_a === $date_b ) {
						return $b->request_id - $a->request_id;
					}
					return $date_b - $date_a;
				} );
			}
		} elseif ( ! empty( $fulfillment_filter ) && $fulfillment_filter ) {

			$orders = $wpdb->get_results(
				$wpdb->prepare(
					"
				SELECT
					return_orders.ID AS request_id,
					return_orders.return_status AS status,
					return_orders.order_id AS order_id
				FROM {$wpdb->prefix}return_orders AS return_orders
				WHERE return_orders.return_status = '%1s'
				%2s
				ORDER BY request_id DESC
				LIMIT %3d, %4d
			",
					$fulfillment_filter,
					$order_query,
					$row,
					$rowperpage
				)
			);

			if ( 'yes' == $exchange_enable ) {
				$exchange_orders = $wpdb->get_results(
					$wpdb->prepare(
						"
					SELECT
						exchange_orders.ID AS request_id,
						exchange_orders.exchange_status AS status,
						exchange_orders.order_id AS order_id
					FROM {$wpdb->prefix}exchange_orders AS exchange_orders
					WHERE exchange_orders.exchange_status = '%1s'
					%2s
					ORDER BY request_id DESC
					LIMIT %3d, %4d
				",
						$fulfillment_filter,
						$exchange_order_query,
						$row,
						$rowperpage
					)
				);

				$orders = array_merge( $orders, $exchange_orders );


				// Sort merged results by WC order date descending (newest request first).
				// Pre-fetch dates via SQL — the comparator must not hydrate a WC_Order
				// (usort would do it O(N log N) times and blow PHP's memory limit).
				$order_dates = $this->fetch_order_created_timestamps( wp_list_pluck( $orders, 'order_id' ) );
				usort( $orders, function( $a, $b ) use ( $order_dates ) {
					$date_a = isset( $order_dates[ (int) $a->order_id ] ) ? $order_dates[ (int) $a->order_id ] : 0;
					$date_b = isset( $order_dates[ (int) $b->order_id ] ) ? $order_dates[ (int) $b->order_id ] : 0;
					if ( $date_a === $date_b ) {
						return $b->request_id - $a->request_id;
					}
					return $date_b - $date_a;
				} );
			}
		} else {

			$orders = $wpdb->get_results(
				$wpdb->prepare(
					"
				SELECT
					return_orders.ID AS request_id,
					return_orders.return_status AS status,
					return_orders.order_id AS order_id
				FROM {$wpdb->prefix}return_orders AS return_orders
				%2s
				ORDER BY request_id DESC
				LIMIT %d, %d
			",
					$order_query,
					$row,
					$rowperpage
				)
			);

			if ( 'yes' == $exchange_enable ) {
				$exchange_orders = $wpdb->get_results(
					$wpdb->prepare(
						"
					SELECT
						exchange_orders.ID AS request_id,
						exchange_orders.exchange_status AS status,
						exchange_orders.order_id AS order_id
					FROM {$wpdb->prefix}exchange_orders AS exchange_orders
					%2s
					ORDER BY request_id DESC
					LIMIT %d, %d
				",
						$exchange_order_query,
						$row,
						$rowperpage
					)
				);

				$orders = array_merge( $orders, $exchange_orders );

				// Sort merged results by WC order date descending (newest request first)
				usort( $orders, function( $a, $b ) {
					$order_a = wc_get_order( $a->order_id );
					$order_b = wc_get_order( $b->order_id );
					if ( ! $order_a || ! $order_b ) {
						return 0;
					}
					$date_a = $order_a->get_date_created() ? $order_a->get_date_created()->getTimestamp() : 0;
					$date_b = $order_b->get_date_created() ? $order_b->get_date_created()->getTimestamp() : 0;
					if ( $date_a === $date_b ) {
						// Same order date: sort by request_id desc (newest request first)
						return $b->request_id - $a->request_id;
					}
					return $date_b - $date_a;
				} );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder

		$search_orders          = array();
		$has_sequential_plugin  = class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) || class_exists( 'Wt_Advanced_Order_Number' ) || class_exists( 'WC_Seq_Order_Number' ) || class_exists( 'WCJ_Order_Numbers' );

		// This loop exists ONLY to filter by a customer-facing (sequential) order
		// number the shopper typed into the search box. When no search is active
		// it produces nothing — but it still hydrates every fetched WC order via
		// wc_get_order() and instantiates a fresh third-party plugin class per
		// row, which blows PHP's memory limit on the initial dashboard load if
		// the site has hundreds of return rows or fat order meta. Only run it
		// when it can actually contribute matches.
		if ( ! empty( $search_input ) && $has_sequential_plugin ) {
			foreach ( $orders as $order_data ) {
				$_order = wc_get_order( $order_data->order_id );
				// Third-party sequential-order-number plugins call `->get_id()` on
				// `$_order` without a null-guard and fatal on `false`, so skip rows
				// that point to a trashed or deleted WC order.
				if ( ! $_order ) {
					continue;
				}
				if ( class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) ) {
					$Alg_orders    = new Alg_WC_Custom_Order_Numbers_Core();
					$_order_number = $Alg_orders->display_order_number( $order_data->order_id, $_order );
				} elseif ( class_exists( 'Wt_Advanced_Order_Number' ) ) {
					$Wt_orders     = new Wt_Advanced_Order_Number();
					$_order_number = $Wt_orders->display_sequence_number( $order_data->order_id, $_order );
				} elseif ( class_exists( 'WC_Seq_Order_Number' ) ) {
					$Seq_orders    = new WC_Seq_Order_Number();
					$_order_number = $Seq_orders->get_order_number( $order_data->order_id, $_order );
				} elseif ( class_exists( 'WCJ_Order_Numbers' ) ) {
					$WCJ_orders    = new WCJ_Order_Numbers();
					$_order_number = $WCJ_orders->display_order_number( $order_data->order_id, $_order );
				} else {
					continue;
				}

				if ( $_order_number === $search_input ) {
					$search_orders[] = $order_data;
				}
			}

			// Always overwrite with the filtered subset — including the empty case,
			// so searches with no matches correctly show the "no records" empty
			// state instead of every row.
			$orders = $search_orders;
		}

		$i      = 0;
		$result = array();
		foreach ( $orders as $order_data ) {
			$order = wc_get_order( $order_data->order_id );

			if ( empty( $order ) || is_a( $order, 'WC_Order_Refund' ) ) {
				continue;
			}

			$result[ $i ] = new \stdClass();

			if ( 'return-requested' == $order_data->status || 'return-approved' == $order_data->status || 'return-completed' == $order_data->status || 'return-rejected' == $order_data->status || 'return-cancelled' == $order_data->status ) {
				$return_data = zorem_returns_exchanges()->common->get_data_by_return_id( $order_data->request_id );
				$images      = ( null != $return_data->return_items_image ) ? unserialize( $return_data->return_items_image ) : array();

				$result[ $i ]->request_id   = $return_data->id;
				$datetime                   = $order->get_date_created();
				$result[ $i ]->order_date   = $datetime->format( 'M j' ) != gmdate( 'M j' ) ? $datetime->format( 'M j' ) . esc_html__( ' at ', 'zorem-returns' ) . $datetime->format( 'H:ia' ) : esc_html__( 'Today at', 'zorem-returns' ) . ' ' . $datetime->format( 'H:ia' );
				$result[ $i ]->order_id     = $order_data->order_id;
				$result[ $i ]->order_number = $order->get_order_number();
				$result[ $i ]->order_status = sprintf( '<span id="showPreviewData" class="order_status_label %s" data-id="%s">%s</span>', esc_attr( sanitize_html_class( 'status-' . $return_data->return_status ) ), $return_data->id, esc_html__( ucfirst( str_replace( '-', ' ', $return_data->return_status ) ), 'zorem-returns' ) );
				$result[ $i ]->attachments  = array(
					'html' => ! empty( $return_data->return_items_image ) ? '<button id="showPreviewImages" data-id="' . $return_data->id . '" >' . esc_html__( 'View', 'zorem-returns' ) . '</button>' : '',
					'urls' => $images,
				);
				$result[ $i ]->customer     = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
				$result[ $i ]->type         = esc_html__( 'Return', 'zorem-returns' );

				$qty          = 0;
				$return_items = ( null != $return_data->return_items ) ? unserialize( $return_data->return_items ) : array();
				foreach ( $return_items as $item ) {
					$qty += $item['return_product_qty'];
				}
				$result[ $i ]->item_count   = $qty;
				$actions                    = $this->action_data( $return_data );
				$result[ $i ]->actions_html = $this->return_render_action_buttons( $actions );

			} else {

				if ( 'no' == $exchange_enable ) {
					continue;
				}

				$exchange_data   = ( null != $order_data->request_id ) ? zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $order_data->request_id ) : '';
				$exchange_images = ( null != $exchange_data->exchange_items_image ) ? unserialize( $exchange_data->exchange_items_image ) : array(); // ✅ ADDED

				$parent_id = $order->get_id();
				/**
				 * Get child order ID from parent order ID using wc_get_orders()
				 */
				$child_orders = wc_get_orders(
					array(
						'meta_key'   => '_parent_order_id',
						'meta_value' => $parent_id,
						'limit'      => 1,
						'return'     => 'ids', // return only IDs for performance
					)
				);

				$child_order_id = ! empty( $child_orders ) ? $child_orders[0] : false;
				$child_order    = $child_order_id ? wc_get_order( $child_order_id ) : false;

				// Build the plain customer-facing numbers for parent and child.
				// 1. Temporarily detach our own filter so it does NOT prepend
				//    "parent → " — the dashboard JS handles that concatenation,
				//    and parent orders carrying their own legacy _parent_order_id
				//    meta would otherwise produce chains like "1676 → 1676 → 1677".
				// 2. Use the same explicit plugin-detection pattern as the search
				//    loop above (Alg / Wt / WC_Seq / WCJ). Some of these plugins
				//    don't reliably hook the woocommerce_order_number filter, so
				//    calling them directly is the only way to guarantee the child
				//    shows its customer-facing sequential number.
				$exFront        = isset( zorem_returns_exchanges()->exFront ) ? zorem_returns_exchanges()->exFront : null;
				$filter_removed = false;
				if ( $exFront && method_exists( $exFront, 'change_woocommerce_order_number' ) ) {
					$filter_removed = remove_filter( 'woocommerce_order_number', array( $exFront, 'change_woocommerce_order_number' ), 99 );
				}

				// Central helper on Return_Exchange_Common handles the lookup chain
				// (sequential plugin → _order_number meta → WC filter fallback).
				$rxCommon            = zorem_returns_exchanges()->rxCommon;
				$parent_order_number = $rxCommon->get_display_order_number( $order );
				$child_order_number  = $child_order ? $rxCommon->get_display_order_number( $child_order ) : $child_order_id;

				if ( $filter_removed ) {
					add_filter( 'woocommerce_order_number', array( $exFront, 'change_woocommerce_order_number' ), 99, 2 );
				}

				$result[ $i ]->request_id         = $exchange_data->id;
				$datetime                         = $order->get_date_created();
				$result[ $i ]->order_date         = $datetime->format( 'M j' ) != gmdate( 'M j' ) ? $datetime->format( 'M j' ) . esc_html__( ' at ', 'zorem-returns' ) . $datetime->format( 'H:ia' ) : esc_html__( 'Today at', 'zorem-returns' ) . ' ' . $datetime->format( 'H:ia' );
				$result[ $i ]->order_id           = $order_data->order_id;
				$result[ $i ]->child_order_id     = $child_order_id;
				$result[ $i ]->child_order_number = $child_order_number;
				$result[ $i ]->order_number       = $parent_order_number;
				$result[ $i ]->order_status   = sprintf( '<span id="showExchangePreviewData" class="order_status_label %s" data-id="%s">%s</span>', esc_attr( sanitize_html_class( 'status-' . $exchange_data->exchange_status ) ), $exchange_data->id, esc_html__( ucfirst( str_replace( '-', ' ', $exchange_data->exchange_status ) ), 'zorem-returns' ) );
				$result[ $i ]->attachments    = array(
					'html' => ! empty( $exchange_data->exchange_items_image )
						? '<button id="showExchangePreviewImages" data-id="' . $exchange_data->id . '" >' . esc_html__( 'View', 'zorem-returns' ) . '</button>'
						: '',
					'urls' => $exchange_images,
				);
				$result[ $i ]->customer       = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
				$result[ $i ]->type           = esc_html__( 'Exchange Item', 'zorem-returns' );

				$qty            = 0;
				$exchange_items = ( null != $exchange_data->exchange_items ) ? unserialize( $exchange_data->exchange_items ) : array();
				foreach ( $exchange_items as $item ) {
					$qty += $item['exchange_product_qty'];
				}
				$result[ $i ]->item_count = $qty;

				$actions                    = zorem_returns_exchanges()->exFulfillment->exchange_action_data( $exchange_data );
				$result[ $i ]->actions_html = zorem_returns_exchanges()->exFulfillment->exchange_render_action_buttons( $actions );

			}

			++$i;
		}

		$draw = ( isset( $_POST['draw'] ) ) ? wc_clean( wp_unslash( $_POST['draw'] ) ) : '';

		$obj_result                  = new \stdclass();
		$obj_result->draw            = intval( $draw );
		$obj_result->recordsTotal    = intval( count( $orders ) );
		$obj_result->recordsFiltered = intval( count( $orders ) );
		$obj_result->data            = $result;
		$obj_result->filter          = 100;
		$obj_result->last_sql        = '';
		$obj_result->is_success      = true;
		echo json_encode( $obj_result );
		exit;
	}

	public function return_render_action_buttons( $actions ) {

		$actions_html = '';

		foreach ( $actions as $key => $action ) {
			if ( isset( $action['img'] ) ) {
				$actions_html .= sprintf( '<button type="button" class="button wc-action-img-button wc-action-button-%1$s %1$s" href="%2$s" status="%3$s" title="%5$s" id="%4$s" target="blank">%5$s</button>', esc_attr( $action['action'] ), esc_url( isset( $action['url'] ) ? $action['url'] : '' ), esc_attr( isset( $key ) ? $key : $action['name'] ), esc_attr( $action['request_id'] ), '<img src="' . $action['img'] . '" width="16">' );
			} elseif ( isset( $action['url'] ) && ! empty( $action['url'] ) ) {
					$actions_html .= sprintf( '<a class="button wc-action-button wc-action-button-%1$s %1$s" href="%2$s" target="_blank">%3$s</a>', esc_attr( $action['action'] ), esc_url( isset( $action['url'] ) ? $action['url'] : '' ), esc_html( $action['name'] ) );
			} else {
				$actions_html .= sprintf( '<button type="button" class="button wc-action-button wc-action-button-%1$s %1$s" href="%2$s" status="%3$s" title="%5$s" id="%4$s">%5$s</button>', esc_attr( $action['action'] ), esc_url( isset( $action['url'] ) ? $action['url'] : '' ), esc_attr( isset( $key ) ? $key : $action['name'] ), esc_attr( $action['request_id'] ), esc_html( $action['name'] ) );
			}
		}

		return $actions_html;
	}


	public function action_data( $return_data ) {

		$actions = array();

		if ( 'return-requested' == $return_data->return_status ) {
			$actions['return-approved'] = array(
				'request_id' => $return_data->id,
				'name'       => esc_html__( 'Approve this return request', 'zorem-returns' ),
				'action'     => 'return_approved_icon',
			);
			$actions['return-rejected'] = array(
				'request_id' => $return_data->id,
				'name'       => esc_html__( 'Reject Return', 'zorem-returns' ),
				'action'     => 'return_rejected_icon',
			);
		}

		if ( 'return-approved' == $return_data->return_status ) {
			$actions['return-completed'] = array(
				'request_id' => $return_data->id,
				'name'       => esc_html__( 'Mark as Completed', 'zorem-returns' ),
				'action'     => 'return_completed_icon',
			);
		}

		if ( 'return-cancelled' == $return_data->return_status || 'return-rejected' == $return_data->return_status ) {
			$actions['return-delete'] = array(
				'request_id' => $return_data->id,
				'name'       => esc_html__( 'Delete', 'zorem-returns' ),
				'action'     => 'return_delete_icon',
			);
		}

		if ( ( 'return-approved' == $return_data->return_status || 'return-completed' == $return_data->return_status ) && 'customer_account' == $return_data->payment_method ) {

			$wc_order_table = $this->check_hpos_enabled();
			if ( $wc_order_table ) {
				$edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $return_data->order_id . '#woocommerce-order-items' );
			} else {
				$edit_url = admin_url( 'post.php?post=' . $return_data->order_id . '&action=edit#woocommerce-order-items' );
			}

			$actions['return-refund'] = array(
				'request_id' => $return_data->id,
				'url'        => $edit_url,
				'name'       => esc_html__( 'Refund', 'zorem-returns' ),
				'action'     => 'return_refund_icon',
			);
		}

		return $actions;
	}

	public function check_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handle return label PDF upload.
	 *
	 * @param int $request_id The return request ID.
	 * @return string|false File path on success, false on failure.
	 */
	public function handle_return_label_upload( $request_id ) {
		if ( empty( $_FILES['return_label_pdf']['name'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = isset( $_FILES['return_label_pdf'] ) ? wp_unslash( $_FILES['return_label_pdf'] ) : array();

		$file_name     = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$file_tmp_name = isset( $file['tmp_name'] ) ? wp_unslash( $file['tmp_name'] ) : '';
		$file_size     = isset( $file['size'] ) ? intval( $file['size'] ) : 0;
		$file_error    = isset( $file['error'] ) ? intval( $file['error'] ) : 0;

		if ( empty( $file_name ) ) {
			return false;
		}

		// Check upload errors
		if ( UPLOAD_ERR_OK !== $file_error ) {
			return false;
		}

		// Validate file type by extension
		$file_type = wp_check_filetype( $file_name, array( 'pdf' => 'application/pdf' ) );
		if ( empty( $file_type['ext'] ) || 'pdf' !== $file_type['ext'] ) {
			return false;
		}

		// Validate actual MIME type
		if ( function_exists( 'finfo_open' ) ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$real_mime = finfo_file( $finfo, $file_tmp_name );
			finfo_close( $finfo );

			if ( 'application/pdf' !== $real_mime ) {
				return false;
			}
		}

		// Validate file size (max 10MB)
		if ( $file_size > 10 * 1024 * 1024 ) {
			return false;
		}

		// Create upload directory
		$upload_dir = WP_CONTENT_DIR . '/uploads/rt4wc-labels/';
		if ( ! file_exists( $upload_dir ) ) {
			wp_mkdir_p( $upload_dir );

			// Secure directory
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$wp_filesystem->put_contents( $upload_dir . '.htaccess', "Order Deny,Allow\nDeny from all\n", FS_CHMOD_FILE );
			$wp_filesystem->put_contents( $upload_dir . 'index.php', '<?php // Silence is golden.', FS_CHMOD_FILE );
		}

		// Delete old file
		$existing = zorem_returns_exchanges()->common->get_data_by_return_id( $request_id );
		if ( ! empty( $existing->return_label_pdf ) && file_exists( $existing->return_label_pdf ) ) {
			wp_delete_file( $existing->return_label_pdf );
		}

		// Generate filename
		$filename = 'return-label-' . intval( $request_id ) . '-' . time() . '.pdf';
		$filepath = $upload_dir . $filename;

		// Move uploaded file via WP_Filesystem instead of move_uploaded_file() so we
		// pass the WordPress.org Plugin Check "forbidden functions" rule. Callers have
		// already validated extension, real MIME, and file size above.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file_tmp_name );
		if ( false === $contents ) {
			return false;
		}

		if ( $wp_filesystem->put_contents( $filepath, $contents, FS_CHMOD_FILE ) ) {
			return $filepath;
		}

		return false;
	}

	/**
	 * AJAX handler for uploading/replacing return label on already approved returns.
	 */
	public function upload_return_label() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'nonce_fullfillment_dashbaord' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'zorem-returns' ) ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'zorem-returns' ) ) );
		}

		$id = isset( $_POST['ID'] ) ? absint( $_POST['ID'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request ID.', 'zorem-returns' ) ) );
		}

		$pdf_path = $this->handle_return_label_upload( $id );
		if ( ! $pdf_path ) {
			wp_send_json_error( array( 'message' => __( 'Failed to upload PDF. Please ensure the file is a valid PDF under 10MB.', 'zorem-returns' ) ) );
		}

		// Update database
		global $wpdb;
		$table = $wpdb->prefix . 'return_orders';
		$wpdb->update( $table, array( 'return_label_pdf' => $pdf_path ), array( 'id' => $id ) );

		wp_send_json_success( array( 'message' => __( 'Return shipping label uploaded successfully.', 'zorem-returns' ) ) );
	}

	/**
	 * Change a return request's status from the MCP bridge, with the full dashboard side
	 * effects (emails, refunds, restock, coupons, order-status change).
	 *
	 * Unlike update_return_request_status() this takes explicit arguments instead of reading
	 * $_POST/nonce, and returns an array (or WP_Error) for the MCP client rather than emitting
	 * JSON. Kept beside the dashboard handler on purpose so the two stay in step.
	 *
	 * @param int        $id                    Return request id.
	 * @param string     $return_status         Target status slug.
	 * @param string     $return_note           Optional admin note.
	 * @param float|null $restocking_fee_amount Optional fee override (only on return-approved).
	 * @return array|WP_Error
	 */
	public function mcp_update_status( $id, $return_status, $return_note = '', $restocking_fee_amount = null ) {

		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error( 'zorem_returns_invalid_id', __( 'Invalid return request id.', 'zorem-returns' ) );
		}

		$allowed = array( 'return-requested', 'return-approved', 'return-completed', 'return-rejected', 'return-cancelled', 'return-delete' );
		if ( ! in_array( $return_status, $allowed, true ) ) {
			/* translators: %s: comma-separated list of allowed status slugs */
			return new WP_Error( 'zorem_returns_bad_status', sprintf( __( 'Unknown status. Allowed: %s', 'zorem-returns' ), implode( ', ', $allowed ) ) );
		}

		$existing = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
		if ( empty( $existing ) ) {
			return new WP_Error( 'zorem_returns_not_found', __( 'Return request not found.', 'zorem-returns' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'return_orders';
		$where = array( 'id' => $id );

		if ( 'return-delete' === $return_status ) {
			$wpdb->delete( $table, $where );
			return array( 'success' => true, 'id' => $id, 'return_status' => 'return-delete' );
		}

		$data = array( 'return_status' => $return_status );
		if ( 'return-completed' !== $return_status ) {
			$data['return_note'] = $return_note;
		}
		if ( 'return-approved' === $return_status && null !== $restocking_fee_amount ) {
			$data['restocking_fee_amount'] = round( max( 0, floatval( $restocking_fee_amount ) ), 2 );
		}

		$wpdb->update( $table, $data, $where );

		$return_data      = zorem_returns_exchanges()->common->get_data_by_return_id( $id );
		$is_keep_the_item = ( isset( $return_data->keep_the_item ) && 'yes' === $return_data->keep_the_item );

		if ( 'return-approved' === $return_status ) {

			zorem_returns_exchanges()->front->send_return_admin_email_func( $id, $return_data->order_id, $return_status );

			if ( $is_keep_the_item ) {
				$pm = isset( $return_data->payment_method ) ? $return_data->payment_method : '';
				if ( 'customer_account' === $pm ) {
					zorem_returns_exchanges()->common->process_keep_item_refund( $return_data->id );
				} elseif ( 'tera_wallet' === $pm ) {
					zorem_returns_exchanges()->common->refund_to_tera_wallet( $return_data->id );
				}
			}

			$order = wc_get_order( $return_data->order_id );
			if ( $order ) {
				$return_form_data = zorem_returns_exchanges()->common->get_returned_form_data( $order );
				$return_datas     = zorem_returns_exchanges()->common->get_data_by_order_id( $return_data->order_id );
				$is_valid_status  = false;
				foreach ( (array) $return_datas as $rd ) {
					if ( isset( $rd->return_status ) && 'return-approved' === $rd->return_status ) {
						$is_valid_status = true;
					}
				}
				if ( $is_valid_status && empty( $return_form_data ) ) {
					$order->set_status( 'return' );
					$order->save();
				} else {
					$order->set_status( 'partial-return' );
					$order->save();
				}
			}
		}

		if ( 'return-completed' === $return_status || 'return-rejected' === $return_status ) {
			zorem_returns_exchanges()->front->send_return_admin_email_func( $id, $return_data->order_id, $return_status );
		}

		if ( ! empty( $return_data ) ) {
			zorem_returns_exchanges()->front->send_return_email_func( $return_data->id, $return_data->order_id, $return_status );
		}

		$is_keep_item_partial = isset( $return_data->payment_method ) && 'keep_item_partial' === $return_data->payment_method;
		if ( 'return-completed' === $return_status ) {
			if ( ! $is_keep_the_item && ! $is_keep_item_partial ) {
				zorem_returns_exchanges()->common->restock_return_items_by_request_id( $return_data->id );
			}
			if ( isset( $return_data->payment_method ) && 'tera_wallet' === $return_data->payment_method && ! $is_keep_the_item ) {
				zorem_returns_exchanges()->common->refund_to_tera_wallet( $return_data->id );
			}
			if ( isset( $return_data->payment_method ) && 'store_credit' === $return_data->payment_method ) {
				zorem_returns_exchanges()->common->create_coupon_by_return_requestId( $return_data->id );
			}
		}

		return array( 'success' => true, 'id' => $id, 'return_status' => $return_status );
	}
}
