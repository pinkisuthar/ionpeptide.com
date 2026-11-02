<?php
/**
 * The template for displaying the current users list of products they are on the waitlist for
 * By default, this is displayed on the "Your Waitlists" tab within the "My Account" section
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/waitlist-user-waitlist.php.
 *
 * HOWEVER, on occasion WooCommerce Waitlist will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @version 2.4.16
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Ensure required parameters are defined (required to pass PHP checks when pushing new release)
$products = isset( $products ) ? $products : array();
$archives = isset( $archives ) ? $archives : array();
$title = isset( $title ) ? $title : '';

wc_print_notices();
$user = get_user_by( 'id', get_current_user_id() );
?>
<noscript>
	<p>
		<?php
		/* translators: %1$s opening <a> tag linking to enabling js site, %2$s closing <a> tag */
		printf( esc_html( 'It appears you have disabled Javascript. To use the waitlist features you must %1$senable Javascript in your browser%2$s' ), '<a href="https://www.enable-javascript.com/">', '</a>' );
		?>
	</p>
</noscript>
<h2 class="my_account_titles wcwl_nojs" id="wcwl_my_waitlist">
	<?php
	/**
	 * Filter the shortcode title
	 * 
	 * @since 2.4.0
	 */
	esc_html_e( apply_filters( 'wcwl_shortcode_title', $title ) );
	?>
</h2>
<div class="waitlist-user-waitlist-wrapper wcwl_nojs">
	<?php
	if ( $products && is_array( $products ) ) {
		$intro_text = __( 'You are currently on the waitlist for the following products.', 'woocommerce-waitlist' );
		?>
		<p>
			<?php
			/**
			 * Filter the shortcode intro text
			 * 
			 * @since 2.4.0
			 */
			esc_html_e( apply_filters( 'wcwl_shortcode_intro_text', $intro_text ) );
			?>
		</p>
		<div class="waitlist-products">
			<?php
			foreach ( $products as $product ) {
				if ( ! $product ) {
					continue;
				}
				if ( $product->is_type( 'variable' ) ) {
					continue;
				}

				// Custom function to check if we need to return the translated name for the original product ID
				// Based off of current language selection for the user
				$product_name = wcwl_get_product_name( $product );

				/**
				 * Filter the shortcode product title
				 * 
				 * @since 2.4.0
				 */
				$product_title = apply_filters( 'wcwl_shortcode_product_title', $product_name, $product->get_id() );
				?>
				<div class="waitlist-single-product">
					<a href="<?php echo esc_url( $product->get_permalink() ); ?>">
						<h4 class="waitlist-title-link">
							<?php
							esc_html_e( $product_title );
							?>
						</h4>
						<span class="waitlist-thumbnail">
							<?php
							/**
							 * Filter the shortcode product thumbnail
							 * 
							 * @since 2.4.0
							 */
							echo wp_kses( $product->get_image(), array(
								'img' => array(
									'src'     => true,
									'width'   => true,
									'height'  => true,
									'class'   => true,
									'alt'     => true,
									'loading' => true,
								),
							));
							?>
						</span>
					</a>
					<p style="text-align: center">
						<a href="#" rel="nofollow" class="wcwl_remove_product" data-nonce="<?php esc_attr_e( wp_create_nonce( 'wcwl-ajax-remove-user-nonce' ) ); ?>" data-product-id="<?php esc_attr_e( $product->get_id() ); ?>" data-url="<?php echo esc_url( Pie_WCWL_Frontend_User_Waitlist::get_remove_link( $product ) ); ?>">
							<?php
							$remove_text = __( 'Remove me from this waitlist', 'woocommerce-waitlist' );
							/**
							 * Filter the shortcode remove from waitlist text
							 * 
							 * @since 2.4.0
							 */
							esc_html_e( apply_filters( 'wcwl_shortcode_remove_text', $remove_text ) );
							?>
						</a>
					</p>
					<div class="spinner"></div>
					<hr>
				</div>
			<?php } ?>
		</div>
	<?php } else { ?>
		<p>
			<?php
			$not_joined_text = __( 'You have not yet joined the waitlist for any products.', 'woocommerce-waitlist' );
			/**
			 * Filter the shortcode no waitlists joined text
			 * 
			 * @since 2.4.0
			 */
			esc_html_e( apply_filters( 'wcwl_shortcode_no_waitlists_text', $not_joined_text ) );
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
			<?php
			$shop_text = __( 'Visit shop now!', 'woocommerce-waitlist' );
			/**
			 * Filter the shortcode visit shop text
			 * 
			 * @since 2.4.0
			 */
			esc_html_e( apply_filters( 'wcwl_shortcode_visit_shop_text', $shop_text ) );
			?>
			</a>
		</p>
		<hr />
	<?php } ?>
</div>

<?php if ( $archives && is_array( $archives ) ) { ?>
	<div class="waitlist-user-waitlist-archive-wrapper">
		<p>
			<?php
			$archive_text = __( 'Your email address is also stored on an archived waitlist for the following products:', 'woocommerce-waitlist' );
			/**
			 * Filter the shortcode archive intro text
			 * 
			 * @since 2.4.0
			 */
			esc_html_e( apply_filters( 'wcwl_shortcode_archive_intro_text', $archive_text ) );
			?>
		</p>
		<ul class="waitlist-archives">
			<?php
			foreach ( $archives as $archive ) {
				$product      = wc_get_product( $archive->post_id );
				// Custom function to check if we need to return the translated name for the original product ID
				// Based off of current language selection for the user
				$product_name = wcwl_get_product_name( $product );
			?>
				<li>
					<?php
					/**
					 * Filter the shortcode archive product title
					 * 
					 * @since 2.4.0
					 */
					esc_html_e( apply_filters( 'wcwl_shortcode_archive_product_title', $product_name, $product->get_id() ) );
					?>
				</li>
			<?php } ?>
		</ul>
		<p>
			<a href="#" rel="nofollow" id="wcwl_remove_archives" data-nonce="<?php esc_attr_e( wp_create_nonce( 'wcwl-ajax-remove-user-archive-nonce' ) ); ?>" data-url="<?php echo esc_url( Pie_WCWL_Frontend_User_Waitlist::get_unarchive_link() ); ?>">
				<?php
				$remove_archive_text = __( 'Remove my email from all waitlist archives', 'woocommerce-waitlist' );
				/**
				 * Filter the shortcode remove from archive text
				 * 
				 * @since 2.4.0
				 */
				esc_html_e( apply_filters( 'wcwl_shortcode_archive_remove_text', $remove_archive_text ) );
				?>
			</a>
		</p>
	</div>
<?php } ?>
