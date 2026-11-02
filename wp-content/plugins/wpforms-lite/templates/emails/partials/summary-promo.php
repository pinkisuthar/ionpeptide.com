<?php
/**
 * Email Summary promo partial: the bottom-slot card used by Tip, Feature Spotlight and Get More Responses.
 *
 * @since 2.0.3
 *
 * @var string $badge Small uppercase label, e.g. "Tip" or "New".
 * @var string $tone  `orange` or `blue`.
 * @var array  $block Block arguments: `title`, `content`, `main` and `alt` as [ 'text', 'url' ].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$promo_main = $block['main'] ?? [];
$promo_alt  = $block['alt'] ?? [];

// The design sets the card copy as one paragraph, so feed paragraph breaks collapse to spaces.
$promo_text = trim( preg_replace( '/(?:<\/?(?:p|br)\b[^>]*>|\s)+/', ' ', (string) ( $block['content'] ?? '' ) ) );
?>
<table class="summary-promo summary-promo-<?php echo esc_attr( $tone ); ?>" border="0" cellpadding="0" cellspacing="0" width="100%" height="100%" role="presentation">
	<tr>
		<td class="summary-promo-card">
			<?php // Two rows so side-by-side cards share a height and the button sits at the bottom. ?>
			<table border="0" cellpadding="0" cellspacing="0" width="100%" height="100%" role="presentation">
				<tr>
					<td class="summary-promo-body" valign="top">
						<table class="summary-badge-wrap" border="0" cellpadding="0" cellspacing="0" role="presentation">
							<tr>
								<td class="summary-badge summary-badge-<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $badge ); ?></td>
							</tr>
						</table>
						<h4><?php echo esc_html( $block['title'] ?? '' ); ?></h4>
						<p><?php echo wp_kses_post( $promo_text ); ?></p>
					</td>
				</tr>
				<?php if ( ! empty( $promo_main['url'] ) && ! empty( $promo_main['text'] ) ) : ?>
					<tr>
						<td class="summary-promo-footer" valign="bottom">
							<?php
							echo wpforms_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								'emails/partials/summary-actions',
								[
									'prefix'     => 'summary-promo',
									'tone'       => $tone,
									'main'       => $promo_main,
									'alt'        => $promo_alt,
									'link_class' => '',
								],
								true
							);
							?>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>
