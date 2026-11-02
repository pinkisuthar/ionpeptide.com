<?php
/**
 * Email Summary body template.
 *
 * This template can be overridden by copying it to yourtheme/wpforms/emails/summary-body.php.
 *
 * @since 1.5.4
 * @since 1.8.8 Added `$notification_block` and `$icons` parameters.
 * @since 2.0.3 Rebuilt around `$tiles`, `$forms`, `$columns`, `$blocks` and `$cta`.
 *
 * @var array  $tiles              Rows of stat tiles: each tile has `label`, `value`, `delta` (int|null), `note`.
 * @var array  $forms              Per-form rows: `title`, `edit_url`, `entries`, `trends`, `views`, `conversion`, `payments`.
 * @var array  $columns            Optional table columns to show: `payments`, `conversion` => bool; `locked` => bool
 *                                  when a lapsed license locks the conversion values.
 * @var array  $blocks             Resolved slot blocks: `top` (one), `middle` (one), `bottom` (list); each `id`, `args`.
 * @var array  $cta                Primary call to action: `text`, `url`.
 * @var array  $notification_block Notification block shown before the promos.
 * @var array  $entries            Deprecated. `$forms` in the pre-2.0.3 shape, kept for overridden templates.
 * @var bool   $has_trends         Deprecated. Whether any `$entries` row has trends, kept for overridden templates.
 * @var array  $icons              Icons used for the design purposes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// License notice sits above the white card; dropoff stays inside, between intro and tiles.
$top_block     = $blocks['top'] ?? [];
$intro_blocks  = [ 'baseline_recap', 'headline', 'milestone' ];
$intro         = in_array( $top_block['id'] ?? '', $intro_blocks, true ) ? $top_block['args'] : [];
$is_above      = ( $top_block['id'] ?? '' ) === 'license_notice';
$is_dropoff    = ( $top_block['id'] ?? '' ) === 'activity_dropoff';
$is_baseline   = ! $is_above && ( ! $intro || ( $top_block['id'] ?? '' ) === 'baseline_recap' || $is_dropoff );
$eyebrow       = $is_baseline ? __( 'Here’s the Rundown', 'wpforms-lite' ) : __( 'Your Week with WPForms', 'wpforms-lite' );
$default_title = $is_dropoff
	? __( 'Your Weekly Forms Recap is Here', 'wpforms-lite' )
	: __( 'Your Weekly Recap is Here', 'wpforms-lite' );
$align         = is_rtl() ? 'right' : 'left';
$title_tags    = [
	'strong' => [],
	'br'     => [],
];
$promo_badges  = [
	'tip'                => [ __( 'Tip', 'wpforms-lite' ), 'orange' ],
	'spotlight'          => [ __( 'New', 'wpforms-lite' ), 'blue' ],
	'get_more_responses' => [ __( 'Tip', 'wpforms-lite' ), 'orange' ],
];

/**
 * Render a top or middle slot block as a callout, with the badge, tone and layout its id calls for.
 *
 * The license notice is red once the license is gone, orange while it is only expiring.
 *
 * @since 2.0.3
 *
 * @param array $slot_block Slot block: `id`, `args`, optional `variant`.
 */
$render_callout = static function ( array $slot_block ) {

	// Badge, tone, compact row, and whether the badge sits above the title rather than beside it.
	$styles = [
		'license_notice'     => [ __( 'Important', 'wpforms-lite' ), 'orange', false, false ],
		'activity_dropoff'   => [ __( 'Attention', 'wpforms-lite' ), 'yellow', false, true ],
		'payments_education' => [ '', 'orange', true, false ],
		'lite_connect'       => [ '', 'orange', false, false ],
	];

	list( $badge, $tone, $compact, $badge_above ) = $styles[ $slot_block['id'] ] ?? [ '', 'blue', false, false ];

	if ( $slot_block['id'] === 'license_notice' && in_array( $slot_block['variant'] ?? '', [ 'expired', 'disabled', 'invalid' ], true ) ) {
		$tone = 'red';
	}

	echo wpforms_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'emails/partials/summary-callout',
		[
			'block'       => $slot_block['args'],
			'badge'       => $badge,
			'tone'        => $tone,
			'compact'     => $compact,
			'badge_above' => $badge_above,
		],
		true
	);
};

/**
 * Format a week-over-week change: arrow icon plus the absolute percentage.
 *
 * @since 2.0.3
 *
 * @param int   $delta Signed percentage change.
 * @param array $icons Icon URLs keyed by direction.
 */
$render_trend = static function ( int $delta, array $icons ) {

	$direction = $delta < 0 ? 'downward' : 'upward';

	printf(
		'<span class="summary-trend-value trend-%1$s" dir="ltr"><img src="%2$s" width="8" height="10" alt="%3$s" /> %4$s%%</span>',
		esc_attr( $direction ),
		esc_url( $icons[ $direction ] ),
		esc_attr( $delta < 0 ? '↓' : '↑' ),
		esc_html( number_format_i18n( abs( $delta ) ) )
	);
};
?>

<?php if ( $is_above ) : ?>
	<table class="summary-above" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
		<tr>
			<td align="center" valign="top">
				<div class="summary-above-inner summary-slot summary-slot-top">
					<?php $render_callout( $top_block ); ?>
				</div>
			</td>
		</tr>
	</table>
<?php endif; ?>

<table class="summary-container" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
	<tbody>
		<tr>
			<td class="summary-content" bgcolor="#ffffff">
				<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
					<tbody>
						<tr>
							<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
							<td class="summary-content-inner" align="center" valign="top" width="600">
								<div class="summary-header" width="100%">
									<p class="summary-eyebrow<?php echo $is_baseline ? ' summary-eyebrow-accent' : ''; ?>"><?php echo esc_html( $eyebrow ); ?></p>
									<h2><?php echo wp_kses( $intro['title'] ?? $default_title, $title_tags ); ?></h2>
									<?php if ( ! empty( $intro['content'] ) ) : ?>
										<p><?php echo wp_kses_post( $intro['content'] ); ?></p>
									<?php endif; ?>
								</div>

								<?php if ( $top_block && ! $intro && ! $is_above ) : ?>
									<div class="summary-slot summary-slot-top">
										<?php $render_callout( $top_block ); ?>
									</div>
								<?php endif; ?>

								<div class="summary-tiles-wrapper" width="100%">
									<?php foreach ( $tiles as $tile_row ) : ?>
										<?php // One table per row, or rows of two and three tiles would share columns. ?>
										<table class="summary-tiles<?php echo in_array( true, array_column( $tile_row, 'amount' ), true ) ? ' summary-tiles-amount' : ''; ?>" border="0" cellpadding="0" cellspacing="10" width="100%" role="presentation">
											<tr>
												<?php foreach ( $tile_row as $tile ) : ?>
													<td class="summary-tile-card" align="center" valign="middle" width="<?php echo esc_attr( (string) floor( 100 / count( $tile_row ) ) ); ?>%">
														<p class="summary-tile-label"><?php echo esc_html( $tile['label'] ); ?></p>
														<p class="summary-tile-value<?php echo empty( $tile['amount'] ) ? '' : ' summary-tile-value-amount'; ?>"><?php echo esc_html( $tile['value'] ); ?></p>
														<?php if ( ! empty( $tile['delta'] ) ) : ?>
															<p class="summary-tile-delta"><?php $render_trend( (int) $tile['delta'], $icons ); ?></p>
														<?php elseif ( ! empty( $tile['note'] ) ) : ?>
															<p class="summary-tile-note"><?php echo esc_html( $tile['note'] ); ?></p>
														<?php endif; ?>
													</td>
												<?php endforeach; ?>
											</tr>
										</table>
									<?php endforeach; ?>
								</div>

								<?php if ( ! empty( $blocks['middle'] ) ) : ?>
									<div class="summary-slot summary-slot-middle">
										<?php $render_callout( $blocks['middle'] ); ?>
									</div>
								<?php endif; ?>

								<div class="email-summaries-wrapper" width="100%">
									<?php // A phone stacks the form name over the values, which share the row in two or three equal columns. ?>
									<table class="email-summaries<?php echo empty( $columns['payments'] ) ? '' : ' email-summaries-has-payments'; ?><?php echo empty( $columns['payments'] ) && empty( $columns['conversion'] ) ? ' email-summaries-two-values' : ''; ?>" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
										<thead>
										<tr>
											<th class="summary-col-form" align="<?php echo esc_attr( $align ); ?>" valign="top"><?php esc_html_e( 'Form', 'wpforms-lite' ); ?></th>
											<th class="summary-col-entries" align="<?php echo esc_attr( $align ); ?>" valign="top" colspan="2"><?php esc_html_e( 'Entries', 'wpforms-lite' ); ?></th>
											<?php if ( ! empty( $columns['payments'] ) ) : ?>
												<th class="summary-col-payments" align="center" valign="top"><?php esc_html_e( 'Payments', 'wpforms-lite' ); ?></th>
											<?php endif; ?>
											<th class="summary-col-views" align="center" valign="top"><?php esc_html_e( 'Views', 'wpforms-lite' ); ?></th>
											<?php if ( ! empty( $columns['conversion'] ) ) : ?>
												<th class="summary-col-conversion" align="center" valign="top"><?php esc_html_e( 'Conversion', 'wpforms-lite' ); ?></th>
											<?php endif; ?>
										</tr>
										</thead>
										<tbody>
										<?php foreach ( $forms as $row ) : ?>
											<tr>
												<td class="form-name" valign="middle">
													<?php if ( empty( $row['edit_url'] ) ) : ?>
														<?php echo esc_html( $row['title'] ); ?>
													<?php else : ?>
														<a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
													<?php endif; ?>
												</td>
												<td class="entry-count summary-col-entries" align="<?php echo esc_attr( $align ); ?>" valign="middle"><?php echo esc_html( number_format_i18n( (int) $row['entries'] ) ); ?></td>
												<td class="summary-trend" align="<?php echo esc_attr( $align ); ?>" valign="middle">
													<?php if ( ! empty( $row['trends'] ) ) : ?>
														<?php $render_trend( (int) $row['trends'], $icons ); ?>
													<?php else : ?>
														&mdash;
													<?php endif; ?>
												</td>
												<?php if ( ! empty( $columns['payments'] ) ) : ?>
													<td class="entry-count summary-col-payments" align="center" valign="middle"><?php echo esc_html( number_format_i18n( (int) $row['payments'] ) ); ?></td>
												<?php endif; ?>
												<td class="entry-count summary-col-views" align="center" valign="middle"><?php echo esc_html( number_format_i18n( (int) $row['views'] ) ); ?></td>
												<?php if ( ! empty( $columns['conversion'] ) ) : ?>
													<?php if ( empty( $columns['locked'] ) ) : ?>
														<td class="entry-count summary-col-conversion" align="center" valign="middle"><?php echo esc_html( number_format_i18n( (float) $row['conversion'], 1 ) ); ?>%</td>
													<?php else : ?>
														<?php // A lapsed license keeps the column but locks the value until renewal. ?>
														<td class="entry-count summary-col-conversion summary-conversion-locked" align="center" valign="middle"><img src="<?php echo esc_url( $icons['locked'] ); ?>" width="87" height="28" alt="<?php esc_attr_e( 'Locked', 'wpforms-lite' ); ?>" /></td>
													<?php endif; ?>
												<?php endif; ?>
											</tr>
										<?php endforeach; ?>
										<?php if ( empty( $forms ) ) : ?>
											<tr>
												<?php // Form, the two Entries cells and Views, plus whichever optional columns show. ?>
												<td class="form-name" colspan="<?php echo esc_attr( (string) ( 4 + (int) ! empty( $columns['payments'] ) + (int) ! empty( $columns['conversion'] ) ) ); ?>"><?php esc_html_e( 'It appears you do not have any form entries yet.', 'wpforms-lite' ); ?></td>
											</tr>
										<?php endif; ?>
										</tbody>
									</table>
								</div>

								<div class="summary-cta" align="center">
									<table class="button-wrapper" border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
										<tr>
											<td class="button button-dark" align="center" valign="middle">
												<a href="<?php echo esc_url( $cta['url'] ); ?>" class="button-link" rel="noopener noreferrer" target="_blank"><?php echo esc_html( $cta['text'] ); ?> <?php echo is_rtl() ? '&larr;' : '&rarr;'; ?></a>
											</td>
										</tr>
									</table>
								</div>

								<?php if ( ! empty( $blocks['bottom'] ) ) : ?>
									<table class="summary-promos" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
										<tr>
											<?php $promo_count = count( $blocks['bottom'] ); ?>
											<?php foreach ( array_values( $blocks['bottom'] ) as $promo_index => $promo ) : ?>
												<?php
												list( $promo_badge, $promo_tone ) = $promo_badges[ $promo['id'] ] ?? [ __( 'Tip', 'wpforms-lite' ), 'orange' ];

												// Outlook on Windows ignores :first-child/:last-child, so the gutter is inlined per position.
												if ( $promo_count === 1 ) {
													$promo_padding = 'padding: 0;';
												} elseif ( $promo_index === 0 ) {
													$promo_padding = 'padding: 0 12px 0 0;';
												} elseif ( $promo_index === $promo_count - 1 ) {
													$promo_padding = 'padding: 0 0 0 12px;';
												} else {
													$promo_padding = 'padding: 0 12px;';
												}
												?>
												<td class="summary-promo-cell" valign="top" width="<?php echo esc_attr( (string) floor( 100 / $promo_count ) ); ?>%" style="<?php echo esc_attr( $promo_padding ); ?>">
													<?php
													echo wpforms_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
														'emails/partials/summary-promo',
														[
															'badge' => $promo_badge,
															'tone'  => $promo_tone,
															'block' => $promo['args'],
														],
														true
													);
													?>
												</td>
											<?php endforeach; ?>
										</tr>
									</table>
								<?php endif; ?>
							</td>
							<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
						</tr>
					</tbody>
				</table>
			</td>
		</tr>
		<?php if ( ! empty( $notification_block ) ) : ?>
			<tr class="summary-notice" align="center">
				<td class="summary-notification-block" bgcolor="#edf3f7">
					<table class="summary-notification-table" border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
						<tbody>
						<?php if ( ! empty( $icons['notification_block'] ) ) : ?>
							<tr>
								<td class="summary-notice-icon" align="center" valign="middle">
									<img src="<?php echo esc_url( $icons['notification_block'] ); ?>" width="52" height="52" alt="<?php esc_attr_e( 'Notification', 'wpforms-lite' ); ?>" />
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<td class="summary-notice-content" align="center" valign="middle">
								<?php if ( ! empty( $notification_block['title'] ) ) : ?>
									<h4><?php echo esc_html( $notification_block['title'] ); ?></h4>
								<?php endif; ?>
								<?php if ( ! empty( $notification_block['content'] ) ) : ?>
									<p><?php echo wp_kses_post( $notification_block['content'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( ! empty( $notification_block['btns']['main']['url'] ) && ! empty( $notification_block['btns']['main']['text'] ) ) : ?>
							<tr>
								<td class="button-container" align="center" valign="middle">
									<table class="button-wrapper" cellspacing="24">
										<tr>
											<td class="button button-blue" align="center" valign="middle">
												<a href="<?php echo esc_url( $notification_block['btns']['main']['url'] ); ?>" class="button-link" rel="noopener noreferrer" target="_blank"><?php echo esc_html( $notification_block['btns']['main']['text'] ); ?></a>
											</td>
											<?php if ( ! empty( $notification_block['btns']['alt']['url'] ) && ! empty( $notification_block['btns']['alt']['text'] ) ) : ?>
												<td class="button button-blue-outline" align="center" valign="middle">
													<a href="<?php echo esc_url( $notification_block['btns']['alt']['url'] ); ?>" class="button-link" rel="noopener noreferrer" target="_blank"><?php echo esc_html( $notification_block['btns']['alt']['text'] ); ?></a>
												</td>
											<?php endif; ?>
										</tr>
									</table>
								</td>
							</tr>
						<?php endif; ?>
						</tbody>
					</table>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
