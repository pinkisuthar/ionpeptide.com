<?php


defined( 'ABSPATH' ) || exit;



class Af_PGBF_Admin {

	public function __construct() {
		// meta boxes
		add_action( 'admin_init', array( $this, 'afpgbf_custom_meta_boxes' ), 10 );
		add_action( 'save_post_afpgbf_rules', array( $this, 'afpgbf_mata_box_save' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'afpgbf_admin_scripts' ) );
		add_filter( 'manage_afpgbf_rules_posts_columns', array( $this, 'afpgbf_columns' ) );
		add_action( 'manage_afpgbf_rules_posts_custom_column', array( $this, 'afpgbf_custom_column' ), 10, 2 );

		add_action( 'wp_ajax_af_pgbf_search_products', array( $this, 'af_pgbf_search_products' ));

		add_filter( 'post_updated_messages', array( $this, 'af_pgbf_custom_cpt_msg' ));
		add_filter( 'bulk_post_updated_messages', array( $this, 'af_pgbf_bulk_custom_cpt_msg' ), 10, 2 );
	}
	public function af_pgbf_custom_cpt_msg( $custom_messages ) {

			global $post, $post_ID;

			$post_types_array = array( 'afpgbf_rules' );


		if ( !in_array($post->post_type, $post_types_array) ) {

			return $custom_messages;
		}


		if ('afpgbf_rules' == $post->post_type) {

			// Define your custom post type messages
			$custom_post_type               = 'afpgbf_rules'; // Replace with your actual post type name
			$custom_post_type_singular_name = 'Payment method based fee rule'; // Replace with singular name of your post type

		}


			$custom_messages[ $custom_post_type ] = array(
				0  => '', // Unused. Messages start at index 1.
			/* translators: %s: custom post update */
				1  => sprintf( __( '%s updated.', 'Addify_pgbf' ), $custom_post_type_singular_name ),
				2  => __( 'Payment method based fee rule updated.', 'Addify_pgbf' ),
				3  => __( 'Payment method based fee rule deleted.', 'Addify_pgbf' ),
				/* translators: %s: custom post update */
				4  => sprintf( __( '%s updated.', 'Addify_pgbf' ), $custom_post_type_singular_name ),
				/* translators: %s: custom post revision */
				5  => isset( $_GET['revision'] ) ? sprintf( __( '%1$s restored to revision from %2$s', 'Addify_pgbf' ), $custom_post_type_singular_name, wp_post_revision_title( (int) $_GET['revision'], false ) ) : false,
				/* translators: %s: custom post published */
				6  => sprintf( __( '%s published.', 'Addify_pgbf' ), $custom_post_type_singular_name ),
				/* translators: %s: custom post saved */
				7  => sprintf( __( '%s saved.', 'Addify_pgbf' ), $custom_post_type_singular_name ),
				/* translators: %s: custom post submitted */
				8  => sprintf( __( '%s submitted.', 'Addify_pgbf' ), $custom_post_type_singular_name),
				/* translators: %s: custom post scheduled update */
				9  => sprintf( __( '%1$s scheduled for: <strong>%2$s</strong>.', 'Addify_pgbf' ), $custom_post_type_singular_name, date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
				/* translators: %s: custom post draft update */
				10 => sprintf( __( '%s draft updated.', 'Addify_pgbf' ), $custom_post_type_singular_name ),
			);

			return $custom_messages;
	}

	public function af_pgbf_bulk_custom_cpt_msg( $bulk_messages, $bulk_counts ) {
	
		$bulk_messages['afreg_fields'] = array(
			/* translators: %s: rules count */
			'updated'   => _n( '%s Registration Field updated.', '%s rules updated.', $bulk_counts['updated'], 'Addify_pgbf' ),
			/* translators: %s: rules count */
			'locked'    => _n( '%s Registration Field not updated, somebody is editing it.', '%s rules not updated, somebody is editing them.', $bulk_counts['locked'], 'Addify_pgbf' ),
			/* translators: %s: rules count */
			'deleted'   => _n( '%s Registration Field permanently deleted.', '%s rules permanently deleted.', $bulk_counts['deleted'], 'Addify_pgbf' ),
			/* translators: %s: rules count */
			'trashed'   => _n( '%s Registration Field moved to the Trash.', '%s rules moved to the Trash.', $bulk_counts['trashed'], 'Addify_pgbf' ),
			/* translators: %s: rules count */
			'untrashed' => _n( '%s Registration Field restored from the Trash.', '%s rules restored from the Trash.', $bulk_counts['untrashed'], 'Addify_pgbf' ),
		);

		return $bulk_messages;
	}
	public function af_pgbf_search_products() {
		$nonce = isset( $_POST['nonce'] ) && '' !== $_POST['nonce'] ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : 0;

		if ( isset( $_POST['q'] ) && '' !== $_POST['q'] ) {
			if ( ! wp_verify_nonce( $nonce, 'af-pgbf-ajax-nonce' ) ) {
				die( 'Failed ajax security check!' );
			}
			$pro = sanitize_text_field( wp_unslash( $_POST['q'] ) );
		} else {
			$pro = '';
		}

		$data_array = array();
		$args       = array(
			'post_type'   => array( 'product' ),
			'post_status' => 'publish',
			'numberposts' => -1,
			's'           => $pro,
		);
		$pros       = get_posts( $args );
		if ( ! empty( $pros ) ) {
			foreach ( $pros as $proo ) {
				$title        = ( mb_strlen( $proo->post_title ) > 50 ) ? mb_substr( $proo->post_title, 0, 49 ) . '...' : $proo->post_title;
				$data_array[] = array( $proo->ID, $title ); // array( Post ID, Post Title ).
			}
		}

		echo wp_json_encode( $data_array );
		die();
	}

	public function afpgbf_admin_scripts() {
		
		wp_enqueue_style( 'afpgbf_admin_css', AFPGBF_DIR_URL . 'assets/css/admin.css', false, '1.0.0' );

		wp_enqueue_script( 'afpgbf_admin_js', AFPGBF_DIR_URL . 'assets/js/admin.js', array( 'jquery' ), '1.0.0', true );

		wp_enqueue_script( 'afpgbf_select2js', AFPGBF_DIR_URL . 'assets/js/select2.js', array( 'jquery' ), '1.0.0', true );

		wp_enqueue_style( 'afpgbf_select2', AFPGBF_DIR_URL . 'assets/css/select2.css', false, '1.0.0' );
		//      wp_enqueue_script( 'jquery' );
		// wp_enqueue_script( 'select2' );
		// wp_enqueue_style( 'select2' );
		
		$afpgbf_data = array(
			'admin_url' => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'af-pgbf-ajax-nonce' ),

		);
		wp_localize_script( 'afpgbf_admin_js', 'afpgbf_php_vars', $afpgbf_data );
	}

	public function afpgbf_columns( $columns ) {

		$columns['priority'] = esc_html__( 'Priority', 'Addify_pgbf' );
		$columns['status']   = esc_html__( 'Status', 'Addify_pgbf' );
		return $columns;
	}

	public function afpgbf_custom_column( $columns, $post_id ) {

		$post = get_post( $post_id );

		switch ( $columns ) {
			case 'priority':
				echo esc_attr( $post->menu_order );
				break;

			case 'status':
				if ( 'publish' == $post->post_status ) {
					echo esc_html__( 'Active', 'Addify_pgbf' );
				} else {
					echo esc_html__( 'In Active', 'Addify_pgbf' );
				}
				break;

			default:
				// code...
				break;
		}
	}

	public function afpgbf_custom_meta_boxes() {

		add_meta_box(
			'pgbf_fees', // id
			esc_html__( 'Fee/Discount Settings', 'Addify_pgbf' ),
			array( $this, 'afpgbf_fees_discount_callback' ),
			'afpgbf_rules',
			'normal',
			'high'
		);

		add_meta_box(
			'pgbf_user_roles', // id
			esc_html__( 'General Options', 'Addify_pgbf' ),
			array( $this, 'afpgbf_user_roles_callback' ),
			'afpgbf_rules',
			'normal',
			'default'
		);

		add_meta_box(
			'pgbf_restriction', // id
			esc_html__( 'Restrictions', 'Addify_pgbf' ),
			array( $this, 'afpgbf_restrictions_callback' ),
			'afpgbf_rules',
			'normal',
			'default'
		);
	}

	public function afpgbf_fees_discount_callback() {

		global $post;
		wp_nonce_field( 'afpgbf_nonce_action', 'afpgbf_nonce_field' );
		$fee_discount           = get_post_meta( get_the_ID(), 'afpgbf_fee_discount', true );
		$fixed_percentage       = get_post_meta( get_the_ID(), 'afpgbf_fixed_percentage', true );
		$checkout_title         = get_post_meta( get_the_ID(), 'afpgbf_checkout_title', true );
		$fee_and_discount_value = get_post_meta( get_the_ID(), 'afpgbf_fee_discount_value', true );
		$min_amt_for_percent    = get_post_meta( get_the_ID(), 'afpgbf_min_fee', true );
		$max_amt_for_percent    = get_post_meta( get_the_ID(), 'afpgbf_max_fee', true );
		$apply_fee_on_pro_sub   = get_post_meta( get_the_ID(), 'apply_fee_on_pro_sub', true );
		$apply_fee_on_pro_sub   = !empty($apply_fee_on_pro_sub) ? $apply_fee_on_pro_sub : 'apply_on_subtotal';

		?>
		<table class="afpgbf_table afpgbf_table_fee_or_discount">
			<tbody class="afpgbf_tbody">

				<tr>
					<th>
						<label><?php echo esc_html__( 'Select Fee/Discount', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<select class='afpgbf_fee_discount' name="afpgbf_fee_discount">
							<option value="fee"
							<?php echo selected( 'fee', $fee_discount ); ?>
							><?php echo esc_html__( 'Fee', 'Addify_pgbf' ); ?></option>
							<option value="discount"
							<?php echo selected( 'discount', $fee_discount ); ?>								
							><?php echo esc_html__( 'Discount', 'Addify_pgbf' ); ?></option>
						</select>
						<p><?php echo esc_html__( 'Choose the option to add Fee/Discount on the value of the entire cart.', 'Addify_pgbf' ); ?></p>						
					</td>
				</tr>				

				<tr>
					<th>
						<label class="fee_discount_label"><?php echo esc_html__( 'Fee/Discount Title', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<input type="text" name="afpgbf_checkout_title" required value="<?php echo esc_attr( $checkout_title ); ?>" >
						<p class="fee_discount_label" ><?php echo esc_html__( 'Add custom title here, this will appear on checkout page as line item to explain why this additional fee has been added.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
						<label class="fee_discount_label"><?php echo esc_html__( 'Fee/Discount Type', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<select name="afpgbf_fixed_percentage" class="afpgbf_fixed_percentage">
							<option value="fixed"
							<?php echo selected( $fixed_percentage, 'fixed' ); ?> 	
							><?php echo esc_html__( 'Fixed', 'Addify_pgbf' ); ?></option>
							<option value="percentage"
							<?php echo selected( $fixed_percentage, 'percentage' ); ?> 	
							><?php echo esc_html__( 'Percentage', 'Addify_pgbf' ); ?></option>
						</select>
					</td>
				</tr>	


				<tr>
					<th>
						<label class="fee_discount_label"><?php echo esc_html__( 'Fee/Discount Value', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<input type="number" required name="afpgbf_fee_discount_value" min="0.1" step="any" value="<?php echo esc_attr( $fee_and_discount_value ); ?>">
					</td>
				</tr>

				<tr class="min_max_amount">
					<th>
						<label class="fee_discount_label"><?php echo esc_html__( 'Minimum Fee/Discount Value', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<input type="number" class="afpgbf_required" name="afpgbf_min_fee" step="any" value="<?php echo esc_attr( $min_amt_for_percent ); ?>" min="0">	
						<p><?php echo esc_html__( 'Minimum fee(or discount) value.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr class="min_max_amount">
					<th>
						<label class="fee_discount_label"><?php echo esc_html__( 'Maximum Fee/Discount Value', 'Addify_pgbf' ); ?></label>							
					</th>
					<td>
						<input type="number" class="afpgbf_required" name="afpgbf_max_fee" step="any" value="<?php echo esc_attr( $max_amt_for_percent ); ?>" min="0">	
						<p><?php echo esc_html__( 'Maximum fee(or discount) value.', 'Addify_pgbf' ); ?></p>					
					</td>
				</tr>	
				
				<tr>
					<th>
						<label class="fee_discount_label"><?php echo esc_html__( 'Apply on', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<input <?php echo checked($apply_fee_on_pro_sub, 'apply_on_subtotal'); ?> type="radio" name="apply_fee_on_pro_sub" value="apply_on_subtotal">
						<label for=""><?php esc_html_e('Subtotal', 'Addify_pgbf'); ?></label>
						
						<input <?php echo checked($apply_fee_on_pro_sub, 'apply_on_products'); ?> style="margin-left:33px" type="radio" name="apply_fee_on_pro_sub" value="apply_on_products">
						<label for=""><?php esc_html_e('Each Product', 'Addify_pgbf'); ?></label>
					</td>
				</tr>	

			</tbody>
		</table>	

		<?php
	}

	public function afpgbf_user_roles_callback() {

		global $post;
		wp_nonce_field( 'afpgbf_nonce_action', 'afpgbf_nonce_field' );
		$methods   = (array) get_post_meta( get_the_ID(), 'afpgbf_payment_method', true );
		$tax_class = get_post_meta( get_the_ID(), 'afpgbf_tax_classes', true );
		$taxable   = get_post_meta( get_the_ID(), 'afpgbf_taxable', true );
		$gateways  = WC()->payment_gateways->payment_gateways();
		$wc_tax    = (array) WC_Tax::get_tax_rate_classes();
		?>

		<table class="afpgbf_table">
			<tbody class="afpgbf_tbody">

				<tr>
					<th>
						<label><?php echo esc_html__( 'Payment Methods', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<select name="afpgbf_payment_methods[]" class="afpgbf_payment_methods" multiple>
							<?php foreach ( $gateways as $gateway_key => $obj ) : ?>
								<option value="<?php echo esc_attr( $gateway_key ); ?>"
									<?php if ( in_array( $gateway_key, $methods ) ) : ?>
										selected
									<?php endif ?>
									> <?php echo esc_attr( $obj->get_title() ); ?> </option>
							<?php endforeach ?>
						</select>
						<p><?php echo esc_html__( 'Select payment gateways to apply this rule to.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
						<label><?php echo esc_html__( 'Taxes', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<input type="checkbox" name="afpgbf_taxable" value="true"
						<?php echo checked( esc_attr( $taxable ), 'true' ); ?> 
						class="afpgbf_checkbox"> <span> <?php echo esc_html__( 'Enable', 'Addify_pgbf' ); ?></span>
						<p><?php echo esc_html__( 'Is taxable?', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>						

				<tr class="tax_class_hide_show">
					<th>
						<label><?php echo esc_html__( 'Tax Class', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<select name="afpgbf_tax_classes">
							<?php foreach ( $wc_tax as $key => $value ) : ?>
								<option value="<?php echo esc_attr( $value->slug ); ?>"
									<?php echo selected( $value->slug, $tax_class ); ?>
									> <?php echo esc_attr( $value->name ); ?> </option>
							<?php endforeach ?>
						</select>
					</td>
				</tr>		

			</tbody>
		</table>		
		<?php
	}


	public function afpgbf_restrictions_callback() {

		global $post, $wp_roles;
		wp_nonce_field( 'afpgbf_nonce_action', 'afpgbf_nonce_field' );
		$user_roles                    = (array) get_post_meta( get_the_ID(), 'afpgbf_user_roles', true );
		$countries                     = (array) get_post_meta( get_the_ID(), 'include_exclude_countries', true );
		$incl_excl_countries_radio_btn = get_post_meta( get_the_ID(), 'incl_excl_countries_radio_btn', true );
		$min_cart_amount               = get_post_meta( get_the_ID(), 'min_cart_amount', true );
		$max_cart_amount               = get_post_meta( get_the_ID(), 'max_cart_amount', true );

		$date_start           = get_post_meta( get_the_ID(), 'date_start', true );
		$date_end             = get_post_meta( get_the_ID(), 'date_end', true );
		$af_pgbf_ex_customers = (array) get_post_meta( get_the_ID(), 'af_pgbf_ex_customers', true );
		$sel_products         = get_post_meta( get_the_ID(), 'af_pgbf_select_products', true );
		$sel_cateogires       = (array) get_post_meta( get_the_ID(), 'af_pgbf_select_categories', true );
		
		$sel_brand = (array) get_post_meta( get_the_ID(), 'af_pgbf_brands_search', true ) ;
		?>

		<table class="afpgbf_table">
			<tbody class="afpgbf_tbody">

			<!-- /////////////// -->

				<tr>
					<th>
						<label><?php echo esc_html__( 'Date Range', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<label for="start"><?php echo esc_html__( 'From:', 'Addify_pgbf' ); ?></label>
						<input type="date" id="start" name="date_start" value="<?php echo esc_attr( $date_start ); ?>">
						<label for="start"><?php echo esc_html__( 'To:', 'Addify_pgbf' ); ?></label>
						<input type="date" id="start1" name="date_end" value="<?php echo esc_attr( $date_end ); ?>">
						<p><?php echo esc_html__( 'Rule applies only if the date is within the given range. Leave empty to apply to all dates.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
						<label><?php echo esc_html__( 'Cart Amount Restriction', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<input type="number" name="min_cart_amount" value="<?php echo esc_attr( $min_cart_amount ); ?>" min='0'; >
						<p><?php echo esc_html__( 'Minimum cart amount for adding fee/discount.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
					</th>
					<td>
						<input type="number" name="max_cart_amount" value="<?php echo esc_attr( $max_cart_amount ); ?>" min='0'; >
						<p><?php echo esc_html__( 'Maximum cart amount for adding fee/discount.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

								<!-- ------------------------------------------------------------------------------------ -->
				<tr>
					<th>
						<label><?php echo esc_html__( 'Apply by Countries', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<label><?php echo esc_html__( 'Include', 'Addify_pgbf' ); ?></label>
						<input type="radio" name="incl_excl_countries_radio_btn" value="include"
						<?php echo esc_attr( 'exclude' != $incl_excl_countries_radio_btn ) ? 'checked' : ''; ?>
						>
						<label><?php echo esc_html__( 'Exclude', 'Addify_pgbf' ); ?></label>
						<input type="radio" name="incl_excl_countries_radio_btn" value="exclude"
						<?php echo checked( 'exclude', $incl_excl_countries_radio_btn ); ?>
						>
						<p><?php echo esc_html__( 'Restrict this rule by countries. Leave empty to apply it for all countries.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>							

				<tr>
					<th>
					</th>
					<td>
						<?php
						$wc_countries = new WC_Countries();
						$nations      = $wc_countries->get_countries();
						?>
						<select name="include_exclude_countries[]" class="afpgbf_include_countries" multiple>
							<?php foreach ( $nations as $key => $state ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"
									<?php if ( in_array( esc_attr( $key ), $countries ) ) : ?>
										selected	
									<?php endif ?>
									><?php echo esc_attr( $state ); ?>				
								</option>
							<?php endforeach; ?>
						</select>
						<p><?php echo esc_html__( 'Countries to include or exclude.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>
				
				<tr>
					<th>
						<label><?php echo esc_html__( 'Users Roles', 'Addify_pgbf' ); ?></label>
					</th>

					
					<td>
						<?php
						$user_roles_ob  = new WP_Roles();
						$names          = $user_roles_ob->get_names();
						$names['guest'] = 'Guest';
						?>
						<select class="afpgbf_user_roles" name="afpgbf_user_roles[]" multiple>
							<?php foreach ( $names as $key => $value ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"
									<?php if ( in_array( $key, $user_roles ) ) : ?>
										selected
									<?php endif ?>
									><?php echo esc_attr( $value ); ?>		
								</option>
							<?php endforeach ?>
						</select>
						<p><?php echo esc_html__( 'Apply this rule to specific user roles. Leave empty to apply it for everyone.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
						<label><?php echo esc_html__( 'Exclude Customers', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
						<?php
							$customers = get_users(array(
								'orderby' => 'display_name',
								'order'   => 'ASC',
							));
						?>
						<select class="afpgbf_customer_cl" name="af_pgbf_ex_customers[]" multiple>
							<?php foreach ( $customers as $customer ) : ?>
								<option 
								<?php
								if (in_array($customer->ID, $af_pgbf_ex_customers)) {
echo 'selected';}
								?>
								value="<?php echo esc_attr($customer->ID); ?>">
									<?php echo esc_attr($customer->user_email); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p><?php echo esc_html__( 'Exclude specific user. Leave empty to apply it for everyone.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>


				<!-- ------------------------------------------------------------------------------ -->

				<tr>
					<th>
						<label><?php echo esc_html__( 'Apply by Products', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
					<select name="af_pgbf_select_products[]" data-placeholder="Choose Products..." class="af-pgbf-ajax-products-search" multiple>
					<?php

					if (is_array($sel_products)) {
						
						foreach ( $sel_products as $product_id ) {
							?>
							<option value="<?php echo esc_attr( $product_id ); ?>" selected>
							<?php echo esc_attr( get_the_title( intval( $product_id ) ) ); ?>
							</option>
							<?php
						}
					}
					?>
				</select>
						<p><?php echo esc_html__( 'Restrict this rule by products. It will apply only to the selected products. Leave empty to apply to all products.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
						<label><?php echo esc_html__( 'Apply by Categories', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
					<?php
						$categories = get_terms(array(
							'taxonomy'   => 'product_cat',
							'hide_empty' => false,
						));
					?>
						<select name="af_pgbf_select_categories[]" data-placeholder="Choose Categories..." id="" class="af-pgbf-categories-search" multiple>
							<?php foreach ($categories as $cat) : ?>
								<option 
								<?php
								if (in_array($cat->term_id, $sel_cateogires)) {
echo 'selected';}
								?>
								value="<?php echo esc_attr($cat->term_id); ?>">
									<?php echo esc_attr($cat->name); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p><?php echo esc_html__( 'Restrict this rule by categories. It will apply only to the selected categories. Leave empty to apply to all categories.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>

				<tr>
					<th>
						<label><?php echo esc_html__( 'Apply by Brands', 'Addify_pgbf' ); ?></label>
					</th>
					<td>
					<?php
					$brands = get_terms( array(
						'taxonomy'   => 'product_brand',
						'hide_empty' => false,
					) );
					?>
					<select multiple="multiple" class="af-pgbf-brands-search" name="af_pgbf_brands_search[]" data-placeholder='<?php esc_html_e('Select Brands...', 'Addify_pgbf'); ?>' tabindex="-1">
					<?php

					if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
						foreach ( $brands as $brand ) {
							?>
							<option value="<?php echo intval($brand->term_id); ?>" <?php echo in_array( $brand->term_id, $sel_brand) ? 'selected' : ''; ?>><?php echo esc_html($brand->name); ?></option>
							<?php
						}
					}
					?>
					</select>
					<p><?php echo esc_html__( 'Restrict this rule by brands. It will apply only to the selected brands. Leave empty to apply to all brands.', 'Addify_pgbf' ); ?></p>
					</td>
				</tr>		

			</tbody>
		</table>
		<?php
	}

	public function afpgbf_mata_box_save( $post_id ) {

		// Check if the current user can edit the post with a specific post ID:

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}


		$exclude_statuses = array(
			'auto-draft',
			'trash',
		);

		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( in_array( get_post_status( $post_id ), $exclude_statuses ) || is_ajax() || 'untrash' === $action ) {
			return;
		}

		$bulk_actions = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( ! in_array( get_post_status( $post_id ), $exclude_statuses ) && ! is_ajax() && 'untrash' != $bulk_actions ) {

			$nonce = isset( $_POST['afpgbf_nonce_field'] ) ? sanitize_text_field( wp_unslash( $_POST['afpgbf_nonce_field'] ) ) : 0;

			if ( ! wp_verify_nonce( $nonce, 'afpgbf_nonce_action' ) ) {
				die( esc_html__( 'Failed security check', 'Addify_pgbf' ) );
			}

			if ( isset( $_POST['afpgbf_payment_methods'] ) ) {
				update_post_meta( $post_id, 'afpgbf_payment_method', sanitize_meta( '', wp_unslash( $_POST['afpgbf_payment_methods'] ), '' ) );
			} else {
				update_post_meta( $post_id, 'afpgbf_payment_method', array() );
			}

			if ( isset( $_POST['afpgbf_tax_classes'] ) ) {
				update_post_meta( $post_id, 'afpgbf_tax_classes', sanitize_text_field( wp_unslash( $_POST['afpgbf_tax_classes'] ) ) );
			}

			if ( isset( $_POST['afpgbf_taxable'] ) ) {
				update_post_meta( $post_id, 'afpgbf_taxable', sanitize_text_field( wp_unslash( $_POST['afpgbf_taxable'] ) ) );
			} else {
				update_post_meta( $post_id, 'afpgbf_taxable', '' );
			}

			if ( isset( $_POST['afpgbf_fee_discount'] ) ) {
				update_post_meta( $post_id, 'afpgbf_fee_discount', sanitize_text_field( wp_unslash( $_POST['afpgbf_fee_discount'] ) ) );
			}

			if ( isset( $_POST['afpgbf_fixed_percentage'] ) ) {
				update_post_meta( $post_id, 'afpgbf_fixed_percentage', sanitize_text_field( wp_unslash( $_POST['afpgbf_fixed_percentage'] ) ) );
			}

			if ( isset( $_POST['apply_fee_on_pro_sub'] ) ) {
				update_post_meta( $post_id, 'apply_fee_on_pro_sub', sanitize_text_field( wp_unslash( $_POST['apply_fee_on_pro_sub'] ) ) );
			}

			if ( isset( $_POST['afpgbf_checkout_title'] ) ) {
				update_post_meta( $post_id, 'afpgbf_checkout_title', sanitize_text_field( wp_unslash( $_POST['afpgbf_checkout_title'] ) ) );
			}

			if ( isset( $_POST['afpgbf_fee_discount_value'] ) ) {
				update_post_meta( $post_id, 'afpgbf_fee_discount_value', sanitize_text_field( wp_unslash( $_POST['afpgbf_fee_discount_value'] ) ) );
			}

			if ( isset( $_POST['afpgbf_min_fee'] ) ) {
				update_post_meta( $post_id, 'afpgbf_min_fee', sanitize_text_field( wp_unslash( $_POST['afpgbf_min_fee'] ) ) );
			}

			if ( isset( $_POST['afpgbf_max_fee'] ) ) {
				update_post_meta( $post_id, 'afpgbf_max_fee', sanitize_text_field( wp_unslash( $_POST['afpgbf_max_fee'] ) ) );
			}

			if ( isset( $_POST['afpgbf_user_roles'] ) ) {
				update_post_meta( $post_id, 'afpgbf_user_roles', sanitize_meta( '', wp_unslash( $_POST['afpgbf_user_roles'] ), '' ) );
			} else {
				update_post_meta( $post_id, 'afpgbf_user_roles', '' );
			}

			update_post_meta( $post_id, 'include_exclude_countries', '' );
			if ( isset( $_POST['include_exclude_countries'] ) ) {
				update_post_meta( $post_id, 'include_exclude_countries', sanitize_meta( '', wp_unslash( $_POST['include_exclude_countries'] ), '' ) );
			}

			update_post_meta( $post_id, 'min_cart_amount', 0 );
			if ( ! empty( $_POST['min_cart_amount'] ) ) {
				update_post_meta( $post_id, 'min_cart_amount', sanitize_text_field( wp_unslash( $_POST['min_cart_amount'] ) ) );
			}

			update_post_meta( $post_id, 'max_cart_amount', 0 );
			if ( ! empty( $_POST['max_cart_amount'] ) ) {
				update_post_meta( $post_id, 'max_cart_amount', sanitize_text_field( wp_unslash( $_POST['max_cart_amount'] ) ) );
			}

			if ( isset( $_POST['incl_excl_countries_radio_btn'] ) ) {
				update_post_meta( $post_id, 'incl_excl_countries_radio_btn', sanitize_text_field( wp_unslash( $_POST['incl_excl_countries_radio_btn'] ) ) );
			} else {
				update_post_meta( $post_id, 'incl_excl_countries_radio_btn', 'Include' );
			}


			if ( isset( $_POST['af_pgbf_select_products'] ) ) {
				update_post_meta( $post_id, 'af_pgbf_select_products', sanitize_meta('', wp_unslash( $_POST['af_pgbf_select_products'] ), '' ) );
			} else {
				update_post_meta( $post_id, 'af_pgbf_select_products', array() );
			}

			if ( isset( $_POST['af_pgbf_select_categories'] ) ) {
				update_post_meta( $post_id, 'af_pgbf_select_categories', sanitize_meta('', wp_unslash( $_POST['af_pgbf_select_categories'] ), '' ) );
			} else {
				update_post_meta( $post_id, 'af_pgbf_select_categories', array() );
			}

			if ( isset( $_POST['af_pgbf_brands_search'] ) ) {
				update_post_meta( $post_id, 'af_pgbf_brands_search', sanitize_meta('', wp_unslash( $_POST['af_pgbf_brands_search'] ), '' ) );
			} else {
				update_post_meta( $post_id, 'af_pgbf_brands_search', array() );
			}


			if ( isset( $_POST['date_start'] ) ) {
				update_post_meta( $post_id, 'date_start', sanitize_text_field( wp_unslash( $_POST['date_start'] ) ) );
			} else {
				update_post_meta( $post_id, 'date_start', '' );
			}

			if ( isset( $_POST['date_end'] ) ) {
				update_post_meta( $post_id, 'date_end', sanitize_text_field( wp_unslash( $_POST['date_end'] ) ) );
			} else {
				update_post_meta( $post_id, 'date_end', '' );
			}

			if ( isset( $_POST['af_pgbf_ex_customers'] ) ) {
				update_post_meta( $post_id, 'af_pgbf_ex_customers', sanitize_meta('', wp_unslash( $_POST['af_pgbf_ex_customers'] ), '' ) );
			} else {
				update_post_meta( $post_id, 'af_pgbf_ex_customers', array() );
			}

		}
	}
}

new Af_PGBF_Admin();
