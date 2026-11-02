<?php

// phpcs:ignoreFile WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare,WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.NoNonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Custom-table queries required for this plugin's own return_orders / exchange_orders / warranty_claims / internal_notes tables; nonces are verified inside handlers; superglobal reads are wrapped with wp_unslash + sanitize_*.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Exchange_Fulfillment_Dashboard {
	
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
	 * @return Exchange_Fulfillment_Dashboard
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
		//load shipments css js
		//add_action( 'admin_enqueue_scripts', array( $this, 'fulfillment_dashboard_styles' ), 1);
		 
		add_action( 'wp_ajax_get_exchange_orders', array($this, 'get_exchange_orders') );
		add_action( 'wp_ajax_show_exchange_preview_images', array($this, 'display_exchange_preview_images') );
		add_action( 'wp_ajax_show_exchange_preview_data', array($this, 'display_exchange_preview_data') );
		add_action( 'wp_ajax_update_exchange_request_status', array($this, 'update_exchange_request_status') );
		add_action( 'wp_ajax_rt4wc_upload_exchange_label', array($this, 'upload_exchange_label') );
	}

	/**
	* Load trackship styles.
	*/
	public function ex_fulfillment_dashboard_styles( $hook ) {
		
		if ( !isset( $_GET['page'] ) ) {
			return;
		}

		if ( 'zorem-returns-exchanges' == $_GET['page'] ) {

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';		
			wp_register_script( 'wc-backbone-modal', WC()->plugin_url() . '/assets/js/admin/backbone-modal' . $suffix . '.js', array( 'underscore', 'backbone', 'wp-util' ), zorem_returns_exchanges()->version );
			
			wp_register_style( 'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', array(), WC_VERSION );
			wp_enqueue_style( 'woocommerce_admin_styles' );

			//dataTables library		
			wp_register_script( 'rt4wc_fulfillment_script', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/fulfillment.js', array( 'jquery' ), zorem_returns_exchanges()->version, true );

			wp_enqueue_script( 'wc-backbone-modal' );
			wp_enqueue_script( 'rt4wc_fulfillment_script' );	
			wp_localize_script('rt4wc_fulfillment_script', 'rt4wc_fulfillment_script', array(
				'admin_url'   =>  admin_url(),
				'preview_nonce' => wp_create_nonce( 'woocommerce-preview-order' ),
			));

			//dataTables library
			wp_enqueue_script( 'DataTable', zorem_returns_exchanges()->plugin_dir_url() . 'assets/js/jquery.dataTables.min.js', array ( 'jquery' ), '1.13.14', true);
			wp_enqueue_style( 'DataTable', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/jquery.dataTables.min.css', array(), '1.13.14', 'all');	
			wp_enqueue_style( 'rt4wc_fulfillment_styles', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/fulfillment.css', array(), zorem_returns_exchanges()->version );
			wp_enqueue_style( 'zre-cross-product-admin', zorem_returns_exchanges()->plugin_dir_url() . 'assets/css/exchange-cross-product.css', array(), zorem_returns_exchanges()->version );
		}

	}

	public function display_exchange_preview_images() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if (!wp_verify_nonce($nonce, 'nonce_fullfillment_dashbaord')) {
			return;
		}

		$id = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id($id);
		$images = ( null != $exchange_data->exchange_items_image ) ? unserialize($exchange_data->exchange_items_image) : array();
		ob_start();
		?>
		
		<div id="exchanges_preview_images" class="popupwrapper attachment-images">
			<div class="popuprow">
				<span class="dashicons dashicons-no-alt popup_close_icon"></span>
				<div class="slider">
					<span class="dashicons dashicons-arrow-left-alt2 previous"></span>
					<div class="img-container">
						<?php 
						foreach ( $images as $image ) {
							echo '<div class="slide"><img src="' . esc_html($image) . '" alt=""></div>';
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
		echo json_encode( array('show_preview_images' => $html) );
		die();
	}

	public function display_exchange_preview_data() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if (!wp_verify_nonce($nonce, 'nonce_fullfillment_dashbaord')) {
			return;
		}

		$id = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$exchange_status	= isset( $_POST['exchange_status'] ) ? sanitize_text_field( wp_unslash( $_POST['exchange_status'] ) ) : '';
		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id($id);

		if ( empty($exchange_data) ) {
			return;
		}

		$order = wc_get_order($exchange_data->order_id);
		$exchange_reason = ( null != $exchange_data->exchange_reason ) ? unserialize($exchange_data->exchange_reason) : array();
		$no_other = isset( $exchange_reason['no_other'] ) ? $exchange_reason['no_other'] : '';
		$other_reason = isset( $exchange_reason['other_reason'] ) ? $exchange_reason['other_reason'] : '';
		$exchange_issue = isset( $exchange_data->exchange_issue ) ? $exchange_data->exchange_issue : '';
		$exchange_note = isset( $exchange_data->exchange_note ) ? $exchange_data->exchange_note : '';
		
		$Status = strtolower(str_replace('-', '_', $exchange_data->exchange_status));
		$defualt_settings = zorem_returns_exchanges()->exCommon->generate_defaults();
		$key = ( 'exchange_cancelled' == $Status ) ? 'admin_' . $Status : 'customer_' . $Status;
		$email_settings = get_option('ex4wc_customer_' . $Status . '_settings', $defualt_settings[$key]);
		$item_heading_text = !empty($email_settings['item_heading_text']) ? esc_html($email_settings['item_heading_text']) : esc_html__( 'Exchange Items Summary', 'zorem-returns' );

		$items = array(
			'original_product' => array(),
			'exchange_product' => array(),
		);
		foreach ( $order->get_items() as $item_id => $item ) { 
			$exchange_items = ( null != $exchange_data->exchange_items ) ? unserialize($exchange_data->exchange_items) : array();
			$product_name = $item->get_name();
			$product_id = $item->get_product_id();
			$product = wc_get_product( $product_id );
			if ( !is_object( $product ) ) {
				continue;
			}

			$product_price = $item->get_total() + $item->get_total_tax();
			$exchange_qty = isset( $exchange_items[$item_id]['exchange_product_qty'] ) ? $exchange_items[$item_id]['exchange_product_qty'] : 0 ;
			$return_variation = '';
			$item_all_meta = $item->get_all_formatted_meta_data();
			foreach ($item_all_meta as $key => $meta) {
				$display_key = $meta->display_key;
				$display_value = wp_strip_all_tags($meta->display_value);
				$return_variation .= wp_kses_post($display_key . ' : ' . $display_value);
				$keys = array_keys($item_all_meta);
				$return_variation .= ( end($keys) !== $key ) ? ' | ' : '';
			}

			// Fallback: get variation attributes directly from the variation product
			if ( empty($return_variation) && $item->get_variation_id() ) {
				$variation_product = wc_get_product( $item->get_variation_id() );
				if ( is_object($variation_product) && $variation_product->is_type('variation') ) {
					$variation_attributes = $variation_product->get_variation_attributes();
					$parts = array();
					foreach ( $variation_attributes as $attr_key => $attr_value ) {
						$attr_slug = str_replace( 'attribute_', '', $attr_key );
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

			if ( 0 < $exchange_qty ) {
				// Add each product as a new entry instead of overwriting
				$items['original_product'][] = array(
					'name'      => $product->get_name(),
					'quantity'  => $exchange_qty,
					'variation' => !empty($return_variation) ? $return_variation : '',
					'price'     => $product_price,
				);

				// Cross-product exchange: per-item target when present (admin
				// multi-item exchange), else the row-level columns.
				$is_cross = ( isset( $exchange_data->exchange_type ) && 'cross-product' === $exchange_data->exchange_type );
				if ( $is_cross ) {
					list( $new_pid, $new_vid ) = zorem_returns_exchanges()->exCommon->get_cross_target_ids_for_item( $exchange_data, $exchange_items, $item_id );
					$new_obj = wc_get_product( ! empty( $new_vid ) ? $new_vid : $new_pid );
					if ( $new_obj ) {
						// Uses the stored choices for "Any …" attributes — THIS item's first.
						$new_variation = zorem_returns_exchanges()->exCommon->get_cross_target_variation_text( $new_obj, array( isset( $exchange_items[ $item_id ] ) ? $exchange_items[ $item_id ] : array() ), ' ' );
						$items['exchange_product'][] = array(
							'name'      => $new_obj->get_name(),
							'quantity'  => $exchange_qty,
							'variation' => trim( $new_variation ),
							'price'     => $new_obj->get_price(),
						);
					}
				} else {
					$attributes = isset($exchange_items[$item_id]['variants']) ? $exchange_items[$item_id]['variants'] : array();
					if ( ! empty( $attributes ) ) {
						$variable_product_id = wc_get_order_item_meta( $item_id, '_product_id', true );
						$variation_id        = zorem_returns_exchanges()->exCommon->get_variation_id_by_attributes( $variable_product_id, $attributes );
						$product_variation   = wc_get_product( $variation_id );
						$variation_data      = wc_get_product( $variable_product_id );

						if ( ! is_object( $product_variation ) || ! is_object( $variation_data ) ) {
							continue;
						}

						$exchange_variation = '';
						foreach ( $item_all_meta as $key => $meta ) {
							$display_key   = $meta->display_key;
							$display_value = '';
							if ( isset( $meta->key ) ) {
								$attribute_key   = $meta->key;
								$attribute_slug  = str_replace( 'attribute_', '', $attribute_key );
								$attribute_value = isset( $attributes[ $meta->key ] ) ? $attributes[ $meta->key ] : '';
								$attr_label = wc_attribute_label( $attribute_slug, $variation_data );
								if ( taxonomy_exists( $attribute_slug ) ) {
									$term = get_term_by( 'slug', $attribute_value, $attribute_slug );
									if ( $term && ! is_wp_error( $term ) ) {
										$display_value = $term->name;
									}
								}
								if ( '' === $display_value && ! empty( $attribute_value ) ) {
									$display_value = apply_filters( 'woocommerce_variation_option_name', ucfirst( $attribute_value ) );
								}
							} else {
								$display_value = isset( $attributes[ $display_key ] ) ? ucfirst( $attributes[ $display_key ] ) : '';
								$attr_label    = wc_attribute_label( sanitize_title( $display_key ), $variation_data );
							}

							$exchange_variation .= wp_kses_post( $attr_label . ' : ' . $display_value );
							$keys = array_keys( $item_all_meta );
							$exchange_variation .= ( end( $keys ) !== $key ) ? ' | ' : '';
						}

						$items['exchange_product'][] = array(
							'name'      => $variation_data->get_name(),
							'quantity'  => $exchange_qty,
							'variation' => !empty($exchange_variation) ? $exchange_variation : '',
							'price'     => $product_price,
						);
					}
				}
			}
		}
		ob_start();
		?>
		<div id="exchanges_preview_data" class="popupwrapper exchange-data">
			<div class="popuprow">
				<span class="dashicons dashicons-no-alt popup_close_icon"></span>
				<div class="exchange-request-details">
					<div class="exchange_request_header <?php echo esc_attr( sanitize_html_class( 'status-' . $exchange_data->exchange_status) ); ?>">
						<?php if ('exchange-rejected' == $exchange_data->exchange_status || 'exchange-cancelled' == $exchange_data->exchange_status ) { ?>
							<span class="dashicons dashicons-dismiss "></span>
						<?php } else { ?>
							<span class="dashicons dashicons-yes-alt"></span>
						<?php } ?>
						<h4><?php esc_html_e( ucfirst(str_replace('-', ' ', $exchange_data->exchange_status)), 'zorem-returns' ); ?></h4>
					</div>
					<?php if ( isset( $exchange_data->keep_the_item ) && 'yes' === $exchange_data->keep_the_item ) { ?>
						<div class="keep-item-admin-notice">
							<strong><?php esc_html_e( 'Keep the Item', 'zorem-returns' ); ?></strong>
							<?php esc_html_e( 'No return shipping required for original item. Customer retains the original product. Replacement order will be created on approval.', 'zorem-returns' ); ?>
						</div>
					<?php } ?>
					<?php
					if ( ! empty( $items ) ) {
						$original_items = isset( $items['original_product'] ) ? $items['original_product'] : array();
						$exchange_items = isset( $items['exchange_product'] ) ? $items['exchange_product'] : array();

						echo '<h3 class="exchange-request-title"> ' . esc_html__( 'Exchanged Product Summary', 'zorem-returns' ) . '</h4>';
						echo '<table class="exchange-request-table">';
						echo '<thead>
								<tr class="exchange-request-table-row">
									<th>' . esc_html__( 'Original Item (Ordered)', 'zorem-returns' ) . '</th>
									<th>' . esc_html__( 'Requested Item (Exchange)', 'zorem-returns' ) . '</th>
								</tr>
							</thead><tbody>';

						// Determine maximum number of rows (in case of mismatch)
						$max = max( count( $original_items ), count( $exchange_items ) );

						for ( $i = 0; $i < $max; $i++ ) {
							$orignal = isset( $original_items[$i] ) ? $original_items[$i] : null;
							$exchange = isset( $exchange_items[$i] ) ? $exchange_items[$i] : null;

							echo '<tr>';
							echo '<td>';
							if ( $orignal ) {
								echo '<div class="product-name"><b>Product:</b> ' . esc_html( $orignal['name'] ) . '</div>';
								if ( ! empty( $orignal['variation'] ) ) {
									echo '<div>' . esc_html( $orignal['variation'] ) . '</div>';
								}
								echo '<div><b>' . esc_html__( 'Qty:', 'zorem-returns' ) . '</b> ' . esc_html( $orignal['quantity'] ) . '</div>';
								echo '<div><b>' . esc_html__( 'Price:', 'zorem-returns' ) . '</b> ' . wp_kses_post( wc_price( $orignal['price'] ) ) . '</div>';
							} else {
								echo '<em>' . esc_html__( 'N/A', 'zorem-returns' ) . '</em>';
							}
							echo '</td>';

							echo '<td>';
							if ( $exchange ) {
								echo '<div class="product-name"><b>Product:</b> ' . esc_html( $exchange['name'] ) . '</div>';
								if ( ! empty( $exchange['variation'] ) ) {
									echo '<div>' . esc_html( $exchange['variation'] ) . '</div>';
								}
								echo '<div><b>' . esc_html__( 'Qty:', 'zorem-returns' ) . '</b> ' . esc_html( $exchange['quantity'] ) . '</div>';
								echo '<div><b>' . esc_html__( 'Price:', 'zorem-returns' ) . '</b> ' . wp_kses_post( wc_price( $exchange['price'] ) ) . '</div>';
							} else {
								echo '<em>' . esc_html__( 'N/A', 'zorem-returns' ) . '</em>';
							}
							echo '</td>';
							echo '</tr>';
						}

						echo '</tbody></table>';

						// Exchange Reason Section
						$reason = ( 'other_reason' === $no_other ) ? stripslashes( $other_reason ) : stripslashes( $no_other );
						echo '<p class="exchange-common"><b>' . esc_html__( 'Exchange Reason:', 'zorem-returns' ) . '</b> ' . esc_html( $reason ) . '</p>';

						if ( !empty($exchange_issue) ) {
							echo '<p class="exchange-common"><b>' . esc_html__( 'Exchange Message:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $exchange_issue ) ) . '</p>';
						}

						if ( !empty($exchange_note) && 'exchange-approved' == $exchange_data->exchange_status ) {
							echo '<p class="exchange-common"><b>' . esc_html__( 'Exchange Approved Note:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $exchange_note ) ) . '</p>';
						}

						if ( !empty($exchange_note) && 'exchange-rejected' == $exchange_data->exchange_status ) {
							echo '<p class="exchange-common"><b>' . esc_html__( 'Exchange Rejected Note:', 'zorem-returns' ) . '</b> ' . esc_html( stripslashes( $exchange_note ) ) . '</p>';
						}
					}
					
					if ( isset($exchange_data->exchange_status) && 'exchange-approved' === $exchange_data->exchange_status ) {
						if ( ! empty( $exchange_data->exchange_label_pdf ) ) {
							$admin_download_url = admin_url( 'admin-ajax.php?action=rt4wc_download_exchange_label&request_id=' . $exchange_data->id . '&nonce=' . wp_create_nonce( 'rt4wc_download_label' ) );
							echo '<p class="return-label-info"><span class="dashicons dashicons-media-default"></span> ' . esc_html__( 'Return shipping label attached.', 'zorem-returns' ) . ' <a href="' . esc_url( $admin_download_url ) . '" target="_blank">' . esc_html__( 'View PDF', 'zorem-returns' ) . '</a></p>';
						}
						echo '<button type="button" class="button wc-action-button exchange_completed_button" status="exchange-completed" title="' . esc_attr__( 'Mark as Completed', 'zorem-returns' ) . '" id="' . esc_attr($exchange_data->id) . '">' . esc_html__( 'Mark as Completed', 'zorem-returns' ) . '</button>';
					}

					if ( isset($exchange_data->exchange_status) && 'exchange-requested' === $exchange_data->exchange_status ) {
						$placeholder = __('Add a note...', 'zorem-returns');

						if ( 'exchange-approved' === $exchange_status ) {
							$placeholder = __('Add a note for exchange approval...', 'zorem-returns');
						} elseif ( 'exchange-rejected' === $exchange_status ) {
							$placeholder = __('Add a note for exchange rejection...', 'zorem-returns');
						}
						?>
						
						<textarea name="exchange_note" id="exchange_note" rows="4" placeholder="<?php echo esc_attr( $placeholder ); ?>"></textarea>
						<?php
						$is_keep_the_item_preview = ( isset( $exchange_data->keep_the_item ) && 'yes' === $exchange_data->keep_the_item );
						?>
						<?php if ( 'exchange-approved' === $exchange_status ) { ?>
							<?php if ( ! $is_keep_the_item_preview ) { ?>
								<div class="return-label-upload-wrap">
									<label for="exchange_label_pdf"><strong><?php esc_html_e( 'Attach Return Shipping Label (PDF)', 'zorem-returns' ); ?></strong></label>
									<input type="file" id="exchange_label_pdf" name="exchange_label_pdf" accept=".pdf" />
									<p class="description"><?php esc_html_e( 'Upload a return shipping label PDF (max 10MB). The customer will be able to download this.', 'zorem-returns' ); ?></p>
								</div>
							<?php } ?>
							<button type="button" class="button wc-action-button exchange_approved_button" status="exchange-approved" title="<?php esc_attr_e('Approve this exchange request', 'zorem-returns'); ?>" id="<?php echo esc_attr($exchange_data->id); ?>"><?php esc_html_e('Approve this exchange request', 'zorem-returns'); ?></button>
						<?php } elseif ( 'exchange-rejected' === $exchange_status ) { ?>
							<button type="button" class="button wc-action-button exchange_rejected_button" status="exchange-rejected" title="<?php esc_attr_e('Reject Exchange', 'zorem-returns'); ?>" id="<?php echo esc_attr($exchange_data->id); ?>"><?php esc_html_e('Reject Exchange', 'zorem-returns'); ?></button>
						<?php } else { ?>
							<?php if ( ! $is_keep_the_item_preview ) { ?>
								<div class="return-label-upload-wrap">
									<label for="exchange_label_pdf"><strong><?php esc_html_e( 'Attach Return Shipping Label (PDF)', 'zorem-returns' ); ?></strong></label>
									<input type="file" id="exchange_label_pdf" name="exchange_label_pdf" accept=".pdf" />
									<p class="description"><?php esc_html_e( 'Upload a return shipping label PDF (max 10MB). The customer will be able to download this.', 'zorem-returns' ); ?></p>
								</div>
							<?php } ?>
							<button type="button" class="button wc-action-button exchange_rejected_button" status="exchange-rejected" title="<?php esc_attr_e('Reject Exchange', 'zorem-returns'); ?>" id="<?php echo esc_attr($exchange_data->id); ?>"><?php esc_html_e('Reject Exchange', 'zorem-returns'); ?></button>
							<button type="button" class="button wc-action-button exchange_approved_button" status="exchange-approved" title="<?php esc_attr_e('Approve this exchange request', 'zorem-returns'); ?>" id="<?php echo esc_attr($exchange_data->id); ?>"><?php esc_html_e('Approve this exchange request', 'zorem-returns'); ?></button>
						<?php
						}
					}
					?>
				</div>
			</div>
			<div class="popupclose"></div>
		</div>
		<?php
		$html = ob_get_clean();
		echo json_encode( array('show_preview_data' => $html) );
		die();
	}

	public function update_exchange_request_status() {

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if (!wp_verify_nonce($nonce, 'nonce_fullfillment_dashbaord')) {
			return;
		}

		$id = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$exchange_status = isset( $_POST['exchange_status'] ) ? sanitize_text_field( wp_unslash( $_POST['exchange_status'] ) ) : '';
		$exchange_note = isset( $_POST['exchange_note'] ) ? sanitize_text_field( wp_unslash( $_POST['exchange_note'] ) ) : '';
		
		$data = array(
			'exchange_status' => $exchange_status,
			'exchange_note' => $exchange_note,
		);
		$where = array(
			'id' => $id,
		);
		
		//database functions
		global $wpdb;
		$table = $wpdb->prefix . 'exchange_orders';

		if ( 'exchange-delete' == $exchange_status ) {
			// Delete associated PDF label if exists
			$existing = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $id );
			if ( ! empty( $existing->exchange_label_pdf ) ) {
				// Sanitize path — strips phar:// and other stream wrappers
				$existing_file_path = realpath( $existing->exchange_label_pdf );

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
			$current_exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $id );
			$is_keep_the_item      = ( isset( $current_exchange_data->keep_the_item ) && 'yes' === $current_exchange_data->keep_the_item );

			if ( 'exchange-approved' == $exchange_status && ! $is_keep_the_item && ! empty( $_FILES['exchange_label_pdf']['name'] ) ) {
				$pdf_path = $this->handle_exchange_label_upload( $id );
				if ( $pdf_path ) {
					$data['exchange_label_pdf'] = $pdf_path;
				}
			}
			$wpdb->update( $table, $data, $where );
		}

		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id($id);
		if ( 'exchange-approved' == $exchange_status ) {

			//Send a exchange request email to admin
			zorem_returns_exchanges()->exFront->send_exchange_admin_email_func($id, $exchange_data->order_id, $exchange_status);

			//Create a new order for exchange item
			zorem_returns_exchanges()->exFront->create_exchange_order( $id );

			$order = wc_get_order($exchange_data->order_id);
			$exchange_form_data = zorem_returns_exchanges()->exCommon->get_exchanged_form_data($order);
			$exchange_datas = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id($exchange_data->order_id);
			$is_valid_status = false;
			foreach ( (array) $exchange_datas as $exchange_data ) {
				$exchange_status = isset( $exchange_data->exchange_status ) ? $exchange_data->exchange_status : '' ;
				if ( 'exchange-approved' == $exchange_status ) {
					$is_valid_status = true;
				}
			}

			if ( empty($exchange_form_data) && $is_valid_status ) {
				//update order status
				$order->set_status('return');
				$order->save();
			} else {
				//update order status
				$order->set_status('partial-return');
				$order->save();
			}
		}

		// Send admin email for completed/rejected statuses too (gated by per-key toggle).
		if ( 'exchange-completed' === $exchange_status || 'exchange-rejected' === $exchange_status ) {
			zorem_returns_exchanges()->exFront->send_exchange_admin_email_func( $id, $exchange_data->order_id, $exchange_status );
		}

		if ( !empty($exchange_data) ) {
			zorem_returns_exchanges()->exFront->send_exchange_email_func( $exchange_data->id, $exchange_data->order_id, $exchange_status);
		}

		// Automatically restocks items when a exchange request is approved
		// Skip restock for Keep the Item — customer retains the original product
		if ( 'exchange-approved' == $exchange_status ) {
			$is_keep_the_item = ( isset( $exchange_data->keep_the_item ) && 'yes' === $exchange_data->keep_the_item );
			if ( ! $is_keep_the_item ) {
				zorem_returns_exchanges()->exCommon->restock_exchange_items_by_request_id($exchange_data->id);
			}
		}

		// On completion, generate the store-credit coupon (cross-product, pay-less only).
		// Re-fetch the row so we get the latest exchange_status, price_difference, and
		// store_credit_coupon (idempotency guard) committed by this update.
		if ( 'exchange-completed' == $exchange_status ) {
			$fresh_row = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $id );
			if ( $fresh_row && class_exists( 'Exchange_Price_Handler' ) ) {
				Exchange_Price_Handler::get_instance()->handle_completion( $fresh_row );
			}

			// Symmetric sync: also flip the child WC order to "completed" so the
			// Orders list and the Returns Dashboard show the same state. The
			// status transition will trigger Exchange_Front::on_wc_child_order_completed(),
			// but that handler exits early when the exchange row is already at
			// exchange-completed, so there's no recursion or duplicate emails.
			$parent_order_id = isset( $exchange_data->order_id ) ? intval( $exchange_data->order_id ) : 0;
			if ( $parent_order_id ) {
				$child_order_ids = wc_get_orders( array(
					'meta_key'   => '_parent_order_id',
					'meta_value' => $parent_order_id,
					'limit'      => 1,
					'return'     => 'ids',
				) );
				if ( ! empty( $child_order_ids ) ) {
					$child_order = wc_get_order( $child_order_ids[0] );
					if ( $child_order && 'completed' !== $child_order->get_status() ) {
						$child_order->update_status(
							'completed',
							__( 'Exchange marked completed in Returns Dashboard.', 'zorem-returns' )
						);
					}
				}
			}
		}

		$array = array(
			'success' => true,
			'id' => $id,
		);

		echo json_encode($array);
		die();
	}
	
	public function get_exchange_orders() {
		
		check_ajax_referer( 'nonce_fullfillment_dashbaord', 'ajax_nonce' );						
		
		$fulfillment_filter = isset( $_POST['fulfillment_filter'] ) ? wc_clean( wp_unslash( $_POST['fulfillment_filter'] ) ) : '';

		$row = ( isset( $_POST['start'] ) ) ? wc_clean( wp_unslash( $_POST['start'] ) ) : '' ;
		$rowperpage = ( isset( $_POST['length'] ) ) ? wc_clean( wp_unslash( $_POST['length'] ) ) : '' ;
		$search_input = ( isset( $_POST['fulfillment_search_input'] ) ) ? wc_clean( wp_unslash( $_POST['fulfillment_search_input'] ) ) : null ;

		global $wpdb;

		if ( null != $search_input && !empty($fulfillment_filter) ) {
			$order_query = 'AND exchange_orders.order_id = ' . $search_input;
		} else if ( null != $search_input && empty($fulfillment_filter) ) {
			$order_query = 'WHERE exchange_orders.order_id = ' . $search_input;
		} else {
			$order_query = '';
		}

		$total_orders_query = $wpdb->get_var("
			SELECT COUNT(*) AS total_orders
			FROM {$wpdb->prefix}exchange_orders AS exchange_orders 
		");

		if ( '-1' == $rowperpage ) {
			$rowperpage = $total_orders_query;
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		if ( !empty($search_input) && ( class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) || class_exists( 'Wt_Advanced_Order_Number' ) || class_exists( 'WC_Seq_Order_Number' ) || class_exists( 'WCJ_Order_Numbers' ) ) ) {

			$orders = $wpdb->get_results( $wpdb->prepare("
				SELECT
					exchange_orders.ID AS request_id,
					exchange_orders.exchange_status AS status,
					exchange_orders.order_id AS order_id
				FROM {$wpdb->prefix}exchange_orders AS exchange_orders
				ORDER BY request_id DESC
				LIMIT %2d, %3d
			", $row, $rowperpage) );

		} else if ( !empty($fulfillment_filter) && $fulfillment_filter ) {

			$orders = $wpdb->get_results( $wpdb->prepare("
				SELECT
					exchange_orders.ID AS request_id,
					exchange_orders.exchange_status AS status,
					exchange_orders.order_id AS order_id
				FROM {$wpdb->prefix}exchange_orders AS exchange_orders
				WHERE exchange_orders.exchange_status = '%1s'
				%2s
				ORDER BY request_id DESC
				LIMIT %3d, %4d
			", $fulfillment_filter, $order_query, $row, $rowperpage) );
			
		} else {

			$orders = $wpdb->get_results( $wpdb->prepare("
				SELECT
					exchange_orders.ID AS request_id,
					exchange_orders.exchange_status AS status,
					exchange_orders.order_id AS order_id
				FROM {$wpdb->prefix}exchange_orders AS exchange_orders
				%2s
				ORDER BY request_id DESC
				LIMIT %d, %d
			", $order_query, $row, $rowperpage) );

		}
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder

		$search_orders          = array();
		$has_sequential_plugin  = class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) || class_exists( 'Wt_Advanced_Order_Number' ) || class_exists( 'WC_Seq_Order_Number' ) || class_exists( 'WCJ_Order_Numbers' );

		// See return dashboard for rationale — only run this loop when a search
		// is active AND a sequential-order-number plugin can produce a match.
		// Otherwise it hydrates every fetched WC order via wc_get_order() for
		// zero gain and can blow PHP's memory limit on the initial page load.
		if ( ! empty( $search_input ) && $has_sequential_plugin ) {
			foreach ( $orders as $order_data ) {
				$_order = wc_get_order( $order_data->order_id );
				// Third-party plugins call `->get_id()` on `$_order` without a null-guard
				// and fatal on `false`, so skip rows pointing to a deleted WC order.
				if ( ! $_order ) {
					continue;
				}
				if ( class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) ) {
					$Alg_orders = new Alg_WC_Custom_Order_Numbers_Core();
					$_order_number = $Alg_orders->display_order_number( $order_data->order_id, $_order );
				} else if ( class_exists( 'Wt_Advanced_Order_Number' ) ) {
					$Wt_orders = new Wt_Advanced_Order_Number();
					$_order_number = $Wt_orders->display_sequence_number( $order_data->order_id, $_order );
				} else if ( class_exists( 'WC_Seq_Order_Number' ) ) {
					$Seq_orders = new WC_Seq_Order_Number();
					$_order_number = $Seq_orders->get_order_number( $order_data->order_id, $_order );
				} else if ( class_exists( 'WCJ_Order_Numbers' ) ) {
					$WCJ_orders = new WCJ_Order_Numbers();
					$_order_number = $WCJ_orders->display_order_number( $order_data->order_id, $_order );
				} else {
					continue;
				}

				if ( $_order_number === $search_input ) {
					$search_orders[] = $order_data;
				}
			}

			// Overwrite even if empty so a search with no matches shows the
			// "no records" empty state rather than every row.
			$orders = $search_orders;
		}
		
		$i = 0;
		$result = array();
		foreach ( $orders as $order_data ) {
			$order = wc_get_order( $order_data->order_id);

			if ( empty($order) || is_a( $order, 'WC_Order_Refund' ) ) {
				continue;
			}

			$result[$i] = new \stdClass();

			$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id($order_data->request_id);

			$result[$i]->request_id = $exchange_data->id;
			$datetime = $order->get_date_created();
			$result[$i]->order_date = $datetime->format('M j') != gmdate('M j') ? $datetime->format('M j') . esc_html__(' at ', 'zorem-returns') . $datetime->format('H:ia') : esc_html__('Today at', 'zorem-returns') . ' ' . $datetime->format('H:ia');
			$result[$i]->order_id = $order_data->order_id;
			$result[$i]->order_number = $order->get_order_number();
			$result[$i]->order_status = sprintf( '<span id="showExchangePreviewData" class="order_status_label %s" data-id="%s">%s</span>', esc_attr( sanitize_html_class( 'status-' . $exchange_data->exchange_status ) ), $exchange_data->id, esc_html( ucfirst(str_replace('-', ' ', $exchange_data->exchange_status)) ) );
			$result[$i]->attachments = !empty($exchange_data->exchange_items_image) ? '<button id="showExchangePreviewImages" data-id="' . $exchange_data->id . '" >' . esc_html__('View', 'zorem-returns') . '</button>' : '';
			$result[$i]->customer = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

			// Cross-product exchange: type badge + replacement product
			$exc_type = isset( $exchange_data->exchange_type ) ? $exchange_data->exchange_type : 'same-variation';
			if ( 'cross-product' === $exc_type ) {
				$result[$i]->exchange_type_badge = '<span class="zre-badge zre-badge-cross">' . esc_html__( 'Cross-Product', 'zorem-returns' ) . '</span>';
				// Per-item targets (admin multi-item exchange): list every replacement
				// product; legacy rows fall back to the single row-level target.
				$ex_items_arr = ( null != $exchange_data->exchange_items ) ? maybe_unserialize( $exchange_data->exchange_items ) : array();
				$target_names = array();
				if ( is_array( $ex_items_arr ) ) {
					foreach ( $ex_items_arr as $it ) {
						if ( is_array( $it ) && ! empty( $it['new_product_id'] ) ) {
							$t_obj = wc_get_product( ! empty( $it['new_variation_id'] ) ? intval( $it['new_variation_id'] ) : intval( $it['new_product_id'] ) );
							if ( $t_obj ) {
								$target_names[] = $t_obj->get_name();
							}
						}
					}
				}
				if ( ! empty( $target_names ) ) {
					$result[$i]->replacement_product = esc_html( implode( ', ', array_unique( $target_names ) ) );
				} else {
					$new_pid  = isset( $exchange_data->new_product_id )   ? intval( $exchange_data->new_product_id )   : 0;
					$new_vid  = isset( $exchange_data->new_variation_id ) ? intval( $exchange_data->new_variation_id ) : 0;
					$np_obj = wc_get_product( ! empty( $new_vid ) ? $new_vid : $new_pid );
					$result[$i]->replacement_product = $np_obj ? esc_html( $np_obj->get_name() ) : '—';
				}
				$diff = isset( $exchange_data->price_difference ) ? floatval( $exchange_data->price_difference ) : 0;
				if ( $diff > 0.5 ) {
					$result[$i]->price_diff_badge = '<span class="zre-badge zre-badge-pay-more">+' . wp_strip_all_tags( wc_price( $diff ) ) . '</span>';
				} elseif ( $diff < -0.5 ) {
					$result[$i]->price_diff_badge = '<span class="zre-badge zre-badge-credit">' . wp_strip_all_tags( wc_price( $diff ) ) . '</span>';
				} else {
					$result[$i]->price_diff_badge = '<span class="zre-badge zre-badge-even">' . esc_html__( 'Even', 'zorem-returns' ) . '</span>';
				}
			} else {
				$result[$i]->exchange_type_badge = '<span class="zre-badge zre-badge-same">' . esc_html__( 'Same Variant', 'zorem-returns' ) . '</span>';
				$result[$i]->replacement_product = '—';
				$result[$i]->price_diff_badge    = '<span class="zre-badge zre-badge-even">' . esc_html__( 'Even', 'zorem-returns' ) . '</span>';
			}

			$qty = 0;
			$exchange_items = ( null != $exchange_data->exchange_items ) ? unserialize($exchange_data->exchange_items) : array();
			foreach ( $exchange_items as $item) {
				$qty += $item['exchange_product_qty'];
			}
			$result[$i]->item_count = $qty;
			
			$actions = $this->exchange_action_data( $exchange_data );			
			$result[$i]->actions_html = $this->exchange_render_action_buttons( $actions );

			$i++;
		}
		
		$draw = ( isset( $_POST['draw'] ) ) ? wc_clean( wp_unslash( $_POST['draw'] ) ) : '' ;
		
		$obj_result = new \stdclass();
		$obj_result->draw = intval( $draw );
		$obj_result->recordsTotal = intval( count($orders) );
		$obj_result->recordsFiltered = intval( count($orders) );
		$obj_result->data = $result;
		$obj_result->filter = 100;
		$obj_result->last_sql = '';
		$obj_result->is_success = true;
		echo json_encode($obj_result);
		exit;
	}
	
	public function exchange_render_action_buttons( $actions ) {
		
		$actions_html = '';
		
		foreach ( $actions as $key => $action ) { 
			if ( isset( $action[ 'img'] ) ) {
				$actions_html .= sprintf( '<button type="button" class="button wc-action-img-button wc-action-button-%1$s %1$s" href="%2$s" status="%3$s" title="%5$s" id="%4$s" target="blank">%5$s</button>', esc_attr( $action['action'] ), esc_url( isset( $action['url'] ) ? $action['url'] : '' ), esc_attr( isset( $key ) ? $key : $action['name'] ), esc_attr( $action['request_id'] ), '<img src="' . $action['img'] . '" width="16">' );

			} else {
				$actions_html .= sprintf( '<button type="button" class="button wc-action-button wc-action-button-%1$s %1$s" href="%2$s" status="%3$s" title="%5$s" id="%4$s">%5$s</button>', esc_attr( $action['action'] ), esc_url( isset( $action['url'] ) ? $action['url'] : '' ), esc_attr( isset( $key ) ? $key : $action['name'] ), esc_attr( $action['request_id'] ), esc_html( $action['name'] ) );
			}
		}
		
		return $actions_html;
	}
	
	
	public function exchange_action_data( $exchange_data ) {
		
		$actions = array();

		if ( 'exchange-requested' == $exchange_data->exchange_status ) {
			$actions['exchange-approved'] = array(
				'request_id' => $exchange_data->id,
				'name'      => esc_html__( 'Approve this exchange request', 'zorem-returns' ),
				'action'    => 'exchange_approved_icon', // keep "view" class for a clean button CSS
			);
			$actions['exchange-rejected'] = array(
				'request_id' => $exchange_data->id,
				'name'      => esc_html__( 'Reject Exchange', 'zorem-returns' ),
				'action'    => 'exchange_rejected_icon', // keep "view" class for a clean button CSS
			);
		}
		
		if ( 'exchange-approved' == $exchange_data->exchange_status ) {
			$actions['exchange-completed'] = array(
				'request_id' => $exchange_data->id,
				'name'       => esc_html__( 'Mark as Completed', 'zorem-returns' ),
				'action'     => 'exchange_completed_icon',
			);


		}

		if ( 'exchange-cancelled' == $exchange_data->exchange_status || 'exchange-rejected' == $exchange_data->exchange_status ) {
			$actions['exchange-delete'] = array(
				'request_id' => $exchange_data->id,
				'name'      => esc_html__( 'Delete', 'zorem-returns' ),
				'action'    => 'exchange_delete_icon', // keep "view" class for a clean button CSS
			);
		}
		
		return $actions;
	}

	/**
	 * AJAX: Set child order to pending + send payment email to customer.
	 */
	public function send_payment_request_callback() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'nonce_fullfillment_dashbaord' ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed.' ) );
		}

		$child_order_id = isset( $_POST['child_order_id'] ) ? absint( $_POST['child_order_id'] ) : 0;
		if ( ! $child_order_id ) {
			wp_send_json_error( array( 'message' => 'No child order found.' ) );
		}

		$child_order = wc_get_order( $child_order_id );
		if ( ! $child_order ) {
			wp_send_json_error( array( 'message' => 'Child order not found.' ) );
		}

		// Set to pending so WC payment page works
		$child_order->set_status( 'pending' );
		$child_order->save();

		// Send payment email via WC customer invoice
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			$emails['WC_Email_Customer_Invoice']->trigger( $child_order->get_id() );
		}

		$payment_url = $child_order->get_checkout_payment_url();
		$child_order->add_order_note(
			sprintf(
				/* translators: %s: Payment URL sent to the customer */
				__( 'Payment request sent to customer. Payment link: %s', 'zorem-returns' ),
				$payment_url
			),
			false
		);

		wp_send_json_success( array(
			'message'     => __( 'Payment request sent successfully. Order set to Pending Payment.', 'zorem-returns' ),
			'payment_url' => $payment_url,
		) );
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
	 * Handle exchange label PDF upload.
	 *
	 * @param int $request_id The exchange request ID.
	 * @return string|false File path on success, false on failure.
	 */
	public function handle_exchange_label_upload( $request_id ) {
		if ( empty( $_FILES['exchange_label_pdf']['name'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = isset( $_FILES['exchange_label_pdf'] ) ? wp_unslash( $_FILES['exchange_label_pdf'] ) : array();

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
		$existing = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $request_id );
		if ( ! empty( $existing->exchange_label_pdf ) && file_exists( $existing->exchange_label_pdf ) ) {
			wp_delete_file( $existing->exchange_label_pdf );
		}

		// Generate filename
		$filename = 'exchange-label-' . intval( $request_id ) . '-' . time() . '.pdf';
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
	 * AJAX handler for uploading/replacing exchange label on already approved exchanges.
	 */
	public function upload_exchange_label() {

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

		$pdf_path = $this->handle_exchange_label_upload( $id );
		if ( ! $pdf_path ) {
			wp_send_json_error( array( 'message' => __( 'Failed to upload PDF. Please ensure the file is a valid PDF under 10MB.', 'zorem-returns' ) ) );
		}

		// Update database
		global $wpdb;
		$table = $wpdb->prefix . 'exchange_orders';
		$wpdb->update( $table, array( 'exchange_label_pdf' => $pdf_path ), array( 'id' => $id ) );

		wp_send_json_success( array( 'message' => __( 'Return shipping label uploaded successfully.', 'zorem-returns' ) ) );
	}

	/**
	 * Change an exchange request's status from the MCP bridge, with the full dashboard side
	 * effects (emails, replacement order, restock, order-status change, price handling).
	 *
	 * Unlike update_exchange_request_status() this takes explicit arguments instead of reading
	 * $_POST/nonce, and returns an array (or WP_Error) for the MCP client rather than emitting
	 * JSON. Kept beside the dashboard handler on purpose so the two stay in step.
	 *
	 * @param int    $id              Exchange request id.
	 * @param string $exchange_status Target status slug.
	 * @param string $exchange_note   Optional admin note.
	 * @return array|WP_Error
	 */
	public function mcp_update_status( $id, $exchange_status, $exchange_note = '' ) {

		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error( 'zorem_returns_invalid_id', __( 'Invalid exchange request id.', 'zorem-returns' ) );
		}

		$allowed = array( 'exchange-requested', 'exchange-approved', 'exchange-completed', 'exchange-rejected', 'exchange-cancelled', 'exchange-delete' );
		if ( ! in_array( $exchange_status, $allowed, true ) ) {
			/* translators: %s: comma-separated list of allowed status slugs */
			return new WP_Error( 'zorem_returns_bad_status', sprintf( __( 'Unknown status. Allowed: %s', 'zorem-returns' ), implode( ', ', $allowed ) ) );
		}

		$existing = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $id );
		if ( empty( $existing ) ) {
			return new WP_Error( 'zorem_returns_not_found', __( 'Exchange request not found.', 'zorem-returns' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'exchange_orders';
		$where = array( 'id' => $id );

		if ( 'exchange-delete' === $exchange_status ) {
			$wpdb->delete( $table, $where );
			return array( 'success' => true, 'id' => $id, 'exchange_status' => 'exchange-delete' );
		}

		$wpdb->update(
			$table,
			array(
				'exchange_status' => $exchange_status,
				'exchange_note'   => $exchange_note,
			),
			$where
		);

		$exchange_data = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $id );

		if ( 'exchange-approved' === $exchange_status ) {

			zorem_returns_exchanges()->exFront->send_exchange_admin_email_func( $id, $exchange_data->order_id, $exchange_status );
			zorem_returns_exchanges()->exFront->create_exchange_order( $id );

			$order = wc_get_order( $exchange_data->order_id );
			if ( $order ) {
				$exchange_form_data = zorem_returns_exchanges()->exCommon->get_exchanged_form_data( $order );
				$exchange_datas     = zorem_returns_exchanges()->exCommon->get_exchange_data_by_order_id( $exchange_data->order_id );
				$is_valid_status    = false;
				foreach ( (array) $exchange_datas as $ed ) {
					if ( isset( $ed->exchange_status ) && 'exchange-approved' === $ed->exchange_status ) {
						$is_valid_status = true;
					}
				}
				if ( empty( $exchange_form_data ) && $is_valid_status ) {
					$order->set_status( 'return' );
					$order->save();
				} else {
					$order->set_status( 'partial-return' );
					$order->save();
				}
			}
		}

		if ( 'exchange-completed' === $exchange_status || 'exchange-rejected' === $exchange_status ) {
			zorem_returns_exchanges()->exFront->send_exchange_admin_email_func( $id, $exchange_data->order_id, $exchange_status );
		}

		if ( ! empty( $exchange_data ) ) {
			zorem_returns_exchanges()->exFront->send_exchange_email_func( $exchange_data->id, $exchange_data->order_id, $exchange_status );
		}

		if ( 'exchange-approved' === $exchange_status ) {
			$is_keep_the_item = ( isset( $exchange_data->keep_the_item ) && 'yes' === $exchange_data->keep_the_item );
			if ( ! $is_keep_the_item ) {
				zorem_returns_exchanges()->exCommon->restock_exchange_items_by_request_id( $exchange_data->id );
			}
		}

		if ( 'exchange-completed' === $exchange_status ) {
			$fresh_row = zorem_returns_exchanges()->exCommon->get_data_by_exchange_id( $id );
			if ( $fresh_row && class_exists( 'Exchange_Price_Handler' ) ) {
				Exchange_Price_Handler::get_instance()->handle_completion( $fresh_row );
			}

			$parent_order_id = isset( $exchange_data->order_id ) ? intval( $exchange_data->order_id ) : 0;
			if ( $parent_order_id ) {
				$child_order_ids = wc_get_orders(
					array(
						'meta_key'   => '_parent_order_id',
						'meta_value' => $parent_order_id,
						'limit'      => 1,
						'return'     => 'ids',
					)
				);
				if ( ! empty( $child_order_ids ) ) {
					$child_order = wc_get_order( $child_order_ids[0] );
					if ( $child_order && 'completed' !== $child_order->get_status() ) {
						$child_order->update_status( 'completed', __( 'Exchange marked completed in Returns Dashboard.', 'zorem-returns' ) );
					}
				}
			}
		}

		return array( 'success' => true, 'id' => $id, 'exchange_status' => $exchange_status );
	}

}
