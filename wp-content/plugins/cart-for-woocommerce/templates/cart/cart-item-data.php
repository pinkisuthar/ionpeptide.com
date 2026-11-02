<?php
/**
 * @var $item_data []
 */
foreach ( $item_data as $data ) {
	echo sprintf( '<span class="fkcart-attr-wrap"><span class="fkcart-attr-key" data-attr-key="%s">%s:</span><span class="fkcart-attr-value">%s</span></span>', sanitize_html_class( 'variation-' . $data['key'] ), wp_kses_post( $data['key'] ), wp_kses_post( $data['display'] ) ) . "\n";
}
