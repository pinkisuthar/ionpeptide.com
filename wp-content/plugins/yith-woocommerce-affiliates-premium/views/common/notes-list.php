<?php
/**
 * Notes list
 *
 * @author  YITH <plugins@yithemes.com>
 * @package YITH\Affiliates\Views
 * @version 1.0.0
 */

/**
 * Template variables:
 *
 * @var $notes YITH_WCAF_Note[]
 */

if ( ! defined( 'YITH_WCAF' ) ) {
	exit;
} // Exit if accessed directly
?>

<ul class="order_notes">

	<?php if ( ! empty( $notes ) ) : ?>
		<?php foreach ( $notes as $note ) : ?>
			<li rel="<?php echo esc_attr( $note->id ); ?>" class="note">
				<div class="note_content">
					<p><?php echo wp_kses_post( $note->content ); ?></p>
				</div>
				<p class="meta">
					<?php
					/**
					 * Note creation date
					 *
					 * @var $created_at WC_DateTime
					 */
					$created_at = $note->get_created_at();

					if ( $created_at ) :
						?>
						<abbr class="exact-date" title="<?php echo esc_attr( $created_at->date_i18n( 'Y-m-d H:i:s' ) ); ?>">
							<?php
							// translators: 1. Note creation date (formatted). 2. Note creation time (formatted).
							echo esc_html( sprintf( _x( 'added on %1$s at %2$s', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' ), $created_at->date_i18n( wc_date_format() ), $created_at->date_i18n( wc_time_format() ) ) );
							?>
						</abbr>
						<?php
					endif;
					?>
					<a href="#" class="delete_note">
						<?php echo esc_html_x( 'Delete note', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' ); ?>
					</a>
				</p>
			</li>
		<?php endforeach; ?>
	<?php endif; ?>
	<li class="no_notes" <?php echo ! empty( $notes ) ? 'style="display: none;"' : ''; ?> >
		<?php echo esc_html_x( 'There are no notes yet.', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' ); ?>
	</li>
</ul>
<div class="add_note">
	<h4><?php echo esc_html_x( 'Add a note', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' ); ?></h4>

	<p class="form-row">
		<textarea name="order_note" id="add_order_note" class="input-text" cols="20" rows="5"></textarea>
	</p>

	<p>
		<a href="#" id="add_note" class="action button">
			<?php echo esc_html_x( 'Add', '[ADMIN] Object notes', 'yith-woocommerce-affiliates' ); ?>
		</a>
	</p>
</div>
