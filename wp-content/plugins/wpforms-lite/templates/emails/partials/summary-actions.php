<?php
/**
 * Email Summary actions partial: the main button with an optional secondary text link.
 *
 * @since 2.0.3
 *
 * @var string $prefix     Card class prefix: `summary-callout` or `summary-promo`.
 * @var string $tone       Button tone, e.g. `orange` or `blue`.
 * @var array  $main       Main button: `text`, `url`.
 * @var array  $alt        Optional secondary link: `text`, `url`.
 * @var string $link_class Class attribute for the secondary link, empty for none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<table border="0" cellpadding="0" cellspacing="0" role="presentation" class="<?php echo esc_attr( $prefix ); ?>-actions">
	<tr>
		<td class="button button-<?php echo esc_attr( $tone ); ?>" align="center" valign="middle">
			<a href="<?php echo esc_url( $main['url'] ); ?>" class="button-link" rel="noopener noreferrer" target="_blank"><?php echo esc_html( $main['text'] ); ?> <?php echo is_rtl() ? '&larr;' : '&rarr;'; ?></a>
		</td>
		<?php if ( ! empty( $alt['url'] ) && ! empty( $alt['text'] ) ) : ?>
			<td class="<?php echo esc_attr( $prefix ); ?>-alt" valign="middle">
				<a <?php echo $link_class !== '' ? 'class="' . esc_attr( $link_class ) . '" ' : ''; ?>href="<?php echo esc_url( $alt['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $alt['text'] ); ?></a>
			</td>
		<?php endif; ?>
	</tr>
</table>
