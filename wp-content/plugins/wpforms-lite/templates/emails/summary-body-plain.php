<?php
/**
 * Email Summary body template (plain text).
 *
 * This template can be overridden by copying it to yourtheme/wpforms/emails/summary-body-plain.php.
 *
 * @since 1.5.4
 * @since 2.0.3 Rebuilt around `$tiles`, `$forms`, `$columns`, `$blocks` and `$cta`.
 *
 * @var array  $tiles              Rows of stat tiles: each tile has `label`, `value`, `delta` (int|null), `note`.
 * @var array  $forms              Per-form rows: `title`, `entries`, `trends`, `views`, `conversion`, `payments`.
 * @var array  $columns            Optional table columns to show: `payments`, `conversion` => bool; `locked` => bool
 *                                  when a lapsed license locks the conversion values.
 * @var array  $blocks             Resolved slot blocks: `top` (one), `middle` (one), `bottom` (list); each `id`, `args`.
 * @var array  $cta                Primary call to action: `text`, `url`.
 * @var array  $notification_block Notification block shown before the promos.
 * @var array  $entries            Deprecated. `$forms` in the pre-2.0.3 shape, kept for overridden templates.
 * @var bool   $has_trends         Deprecated. Whether any `$entries` row has trends, kept for overridden templates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$separator = '   |   ';
$divider   = "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

// Values arrive HTML-encoded, the currency symbol among them, so decode the whole body once.
ob_start();

/**
 * Print a block as plain text: title, paragraphs, then every button or link as "Text: URL".
 *
 * @since 2.0.3
 *
 * @param array $args Block arguments.
 */
$print_block = static function ( array $args ) {

	echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

	if ( ! empty( $args['title'] ) ) {
		// A two-line title keeps its break as a newline instead of running the sentences together.
		echo esc_html( wp_strip_all_tags( str_replace( '<br>', "\n", $args['title'] ) ) ) . "\n\n";
	}

	foreach ( array_filter( (array) ( $args['content_plain'] ?? $args['content'] ?? [] ) ) as $paragraph ) {
		echo esc_html( wp_strip_all_tags( $paragraph ) ) . "\n\n";
	}

	$links = [
		[ $args['main']['text'] ?? '', $args['main']['url'] ?? '' ],
		[ $args['alt']['text'] ?? '', $args['alt']['url'] ?? '' ],
	];

	// Links embedded in the content markup are lost to wp_strip_all_tags(), so pull them out explicitly.
	foreach ( array_filter( (array) ( $args['content'] ?? [] ) ) as $paragraph ) {
		preg_match_all( '~<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.+?)</a>~is', (string) $paragraph, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$links[] = [ html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES ), html_entity_decode( $match[1], ENT_QUOTES ) ];
		}
	}

	$printed_urls = [];

	foreach ( $links as list( $text, $url ) ) {
		if ( $text === '' || $url === '' || isset( $printed_urls[ $url ] ) ) {
			continue;
		}

		$printed_urls[ $url ] = true;

		echo esc_html( $text ) . ': ' . esc_url_raw( $url ) . "\n\n";
	}
};

$top_block    = $blocks['top'] ?? [];
$intro_blocks = [ 'baseline_recap', 'headline', 'milestone' ];

// Intro blocks carry their own heading; notice cards get the default heading first, like the HTML version.
if ( ! in_array( $top_block['id'] ?? '', $intro_blocks, true ) ) {
	$default_title = ( $top_block['id'] ?? '' ) === 'activity_dropoff'
		? __( 'Your Weekly Forms Recap is Here', 'wpforms-lite' )
		: __( 'Your Weekly Recap is Here', 'wpforms-lite' );

	echo esc_html( $default_title ) . "\n\n";
}

if ( $top_block ) {
	$print_block( $top_block['args'] );
}

echo $divider; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

foreach ( $tiles as $tile_row ) {
	foreach ( $tile_row as $tile ) {
		echo esc_html( $tile['label'] ) . ': ' . esc_html( $tile['value'] );

		if ( ! empty( $tile['delta'] ) ) {
			echo esc_html( $separator . ( $tile['delta'] >= 0 ? '↑' : '↓' ) . abs( (int) $tile['delta'] ) . '%' );
		} elseif ( ! empty( $tile['note'] ) ) {
			echo esc_html( $separator . $tile['note'] );
		}

		echo "\n";
	}
}

echo "\n";

if ( ! empty( $blocks['middle'] ) ) {
	$print_block( $blocks['middle']['args'] );
}

echo $divider; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

$headers = [ esc_html__( 'Form', 'wpforms-lite' ), esc_html__( 'Entries', 'wpforms-lite' ) ];

if ( ! empty( $columns['payments'] ) ) {
	$headers[] = esc_html__( 'Payments', 'wpforms-lite' );
}

$headers[] = esc_html__( 'Views', 'wpforms-lite' );

if ( ! empty( $columns['conversion'] ) ) {
	$headers[] = esc_html__( 'Conversion', 'wpforms-lite' );
}

echo implode( esc_html( $separator ), $headers ) . "\n\n" . $divider; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

foreach ( $forms as $row ) {
	$cells = [ esc_html( $row['title'] ), number_format_i18n( (int) $row['entries'] ) . ( ! empty( $row['trends'] ) ? ' ' . ( $row['trends'] >= 0 ? '↑' : '↓' ) . abs( (int) $row['trends'] ) . '%' : '' ) ];

	if ( ! empty( $columns['payments'] ) ) {
		$cells[] = number_format_i18n( (int) $row['payments'] );
	}

	$cells[] = number_format_i18n( (int) $row['views'] );

	if ( ! empty( $columns['conversion'] ) ) {
		$cells[] = empty( $columns['locked'] ) ? number_format_i18n( (float) $row['conversion'], 1 ) . '%' : esc_html__( 'Locked', 'wpforms-lite' );
	}

	echo implode( esc_html( $separator ), $cells ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( empty( $forms ) ) {
	echo esc_html__( 'It appears you do not have any form entries yet.', 'wpforms-lite' ) . "\n\n";
}

echo esc_html( $cta['text'] ) . ': ' . esc_url_raw( $cta['url'] ) . "\n\n";

foreach ( $blocks['bottom'] ?? [] as $promo ) {
	$print_block( $promo['args'] );
}

if ( ! empty( $notification_block ) ) {
	$print_block(
		[
			'title'   => $notification_block['title'] ?? '',
			'content' => $notification_block['content'] ?? '',
			'main'    => $notification_block['btns']['main'] ?? [],
			'alt'     => $notification_block['btns']['alt'] ?? [],
		]
	);
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text body, escaped above.
echo html_entity_decode( (string) ob_get_clean(), ENT_QUOTES, 'UTF-8' );
