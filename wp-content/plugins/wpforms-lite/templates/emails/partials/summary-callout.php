<?php
/**
 * Email Summary callout partial: a tinted, bordered card with a badge, title, text, button and link.
 *
 * Used for the top-slot notices (license notice, activity drop-off) and the middle slot
 * (payments education, Lite Connect). The compact variant is a single row with the text on the
 * left and the link on the right. The bar variant is a compact row with stacked copy and an icon.
 *
 * @since 2.0.3
 *
 * @var array  $block       Block arguments: `title`, `content` (string or list of paragraphs), `main` and `alt`
 *                          as [ 'text', 'url' ], optional `icon`, `icon_width`, `icon_height`, `icon_dark`
 *                          (a whole circle shown in dark mode), `layout` (`bar` for the Lite Connect row).
 * @var string $tone        One of `orange`, `red`, `yellow`, `blue`.
 * @var string $badge       Small uppercase label, empty for none.
 * @var bool   $compact     Whether to render the single-row variant.
 * @var bool   $badge_above Whether the badge sits above the title rather than beside it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$callout_title       = (string) ( $block['title'] ?? '' );
$callout_paragraphs  = array_filter( (array) ( $block['content'] ?? [] ) );
$callout_button_text = (string) ( $block['main']['text'] ?? '' );
$callout_button_url  = (string) ( $block['main']['url'] ?? '' );
$callout_link_text   = (string) ( $block['alt']['text'] ?? '' );
$callout_link_url    = (string) ( $block['alt']['url'] ?? '' );
$callout_icon        = (string) ( $block['icon'] ?? '' );
$callout_icon_width  = (int) ( $block['icon_width'] ?? 28 );
$callout_icon_height = (int) ( $block['icon_height'] ?? 28 );
$callout_icon_dark   = (string) ( $block['icon_dark'] ?? '' );
$is_bar              = ( $block['layout'] ?? '' ) === 'bar';
// The glyph sits in a tinted circle: 52px in the bar layout, 32px in the compact one.
$callout_icon_size = $is_bar ? 52 : 32;

/**
 * Render the icon glyph inside its tinted circle.
 *
 * @since 2.0.3
 *
 * @param string $icon   Icon URL.
 * @param int    $width  Glyph width.
 * @param int    $height Glyph height.
 * @param int    $size   Circle diameter.
 */
$callout_icon_html = static function ( string $icon, int $width, int $height, int $size ): void {
	?>
	<table border="0" cellpadding="0" cellspacing="0" role="presentation">
		<tr>
			<td class="summary-callout-icon-circle" align="center" valign="middle" width="<?php echo esc_attr( (string) $size ); ?>" height="<?php echo esc_attr( (string) $size ); ?>">
				<img src="<?php echo esc_url( $icon ); ?>" width="<?php echo esc_attr( (string) $width ); ?>" height="<?php echo esc_attr( (string) $height ); ?>" alt="" />
			</td>
		</tr>
	</table>
	<?php
};
?>
<table class="summary-callout summary-callout-<?php echo esc_attr( $tone ); ?>" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
	<tr>
		<?php if ( $is_bar ) : ?>
			<td class="summary-callout-card summary-callout-compact summary-callout-bar" valign="middle">
				<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
					<tr>
						<td valign="middle">
							<?php if ( $callout_title !== '' ) : ?>
								<p class="summary-callout-bar-title"><?php echo esc_html( $callout_title ); ?></p>
							<?php endif; ?>
							<?php foreach ( $callout_paragraphs as $callout_paragraph ) : ?>
								<p><?php echo wp_kses_post( $callout_paragraph ); ?></p>
							<?php endforeach; ?>
						</td>
						<?php if ( $callout_icon !== '' ) : ?>
							<td class="summary-callout-icon" align="right" valign="middle" width="<?php echo esc_attr( (string) $callout_icon_size ); ?>">
								<div class="summary-callout-icon-light">
									<?php $callout_icon_html( $callout_icon, $callout_icon_width, $callout_icon_height, $callout_icon_size ); ?>
								</div>
								<?php if ( $callout_icon_dark !== '' ) : ?>
									<?php // Outlook for Windows has no dark mode, so it never shows the dark circle. ?>
									<img class="summary-callout-icon-dark" src="<?php echo esc_url( $callout_icon_dark ); ?>" width="<?php echo esc_attr( (string) $callout_icon_size ); ?>" height="<?php echo esc_attr( (string) $callout_icon_size ); ?>" alt="" style="mso-hide: all;" />
								<?php endif; ?>
							</td>
						<?php endif; ?>
					</tr>
				</table>
			</td>
		<?php elseif ( ! empty( $compact ) ) : ?>
			<td class="summary-callout-card summary-callout-compact" valign="middle">
				<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
					<tr>
						<?php if ( $callout_icon !== '' ) : ?>
							<td class="summary-callout-icon" valign="middle" width="<?php echo esc_attr( (string) $callout_icon_size ); ?>">
								<?php $callout_icon_html( $callout_icon, $callout_icon_width, $callout_icon_height, $callout_icon_size ); ?>
							</td>
						<?php endif; ?>
						<td class="summary-callout-compact-text" valign="middle"><?php echo esc_html( $callout_title ); ?></td>
						<?php if ( $callout_link_url !== '' && $callout_link_text !== '' ) : ?>
							<td align="right" valign="middle" class="summary-callout-compact-link">
								<a class="summary-callout-link" href="<?php echo esc_url( $callout_link_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $callout_link_text ); ?></a>
							</td>
						<?php endif; ?>
					</tr>
				</table>
			</td>
		<?php else : ?>
			<td class="summary-callout-card<?php echo empty( $badge_above ) ? '' : ' summary-callout-stacked'; ?>">
				<?php if ( $badge !== '' && ! empty( $badge_above ) ) : ?>
					<table class="summary-badge-wrap" border="0" cellpadding="0" cellspacing="0" role="presentation">
						<tr>
							<td class="summary-badge summary-badge-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $badge ); ?></td>
						</tr>
					</table>
				<?php endif; ?>
				<?php if ( $callout_title !== '' ) : ?>
					<h4>
						<?php echo esc_html( $callout_title ); ?>
						<?php if ( $badge !== '' && empty( $badge_above ) ) : ?>
							<span class="summary-badge summary-badge-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $badge ); ?></span>
						<?php endif; ?>
					</h4>
				<?php endif; ?>
				<?php foreach ( $callout_paragraphs as $callout_paragraph ) : ?>
					<p><?php echo wp_kses_post( $callout_paragraph ); ?></p>
				<?php endforeach; ?>
				<?php if ( $callout_button_url !== '' && $callout_button_text !== '' ) : ?>
					<?php
					echo wpforms_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						'emails/partials/summary-actions',
						[
							'prefix'     => 'summary-callout',
							'tone'       => $tone,
							'main'       => [
								'text' => $callout_button_text,
								'url'  => $callout_button_url,
							],
							'alt'        => [
								'text' => $callout_link_text,
								'url'  => $callout_link_url,
							],
							'link_class' => 'summary-callout-link',
						],
						true
					);
					?>
				<?php elseif ( $callout_link_url !== '' && $callout_link_text !== '' ) : ?>
					<p><a class="summary-callout-link" href="<?php echo esc_url( $callout_link_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $callout_link_text ); ?></a></p>
				<?php endif; ?>
			</td>
		<?php endif; ?>
	</tr>
</table>
