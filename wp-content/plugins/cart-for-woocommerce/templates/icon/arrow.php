<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$width     = isset( $width ) && intval( $width ) > 0 ? $width : 16;
$height    = isset( $height ) && intval( $height ) > 0 ? $height : 16;
$direction = isset( $direction ) ? $direction : 'up';
$dir_style = '';
switch ( $direction ) {
	case 'right':
		$dir_style = 'transform: rotate(-90deg);';
		break;
	case 'left':
		$dir_style = 'transform: rotate(90deg);';
		break;
	case 'down':
		$dir_style = 'transform: rotate(180deg);';
		break;
}
$hide = ! isset( $hide ) ? '' : ( ! $hide ? "fkcart-hide" : "" );
?>
<svg width="<?php esc_attr_e( $width ) ?>" height="<?php esc_attr_e( $height ) ?>" viewBox="0 0 24 24" class="fkcart-arrow-<?php esc_attr_e( $direction ) ?>-icon <?php esc_attr_e( $hide ) ?>" fill="none" xmlns="http://www.w3.org/2000/svg" style="<?php esc_attr_e( $dir_style ) ?>">
    <path d="M2.21967 7.21967C2.51256 6.92678 2.98744 6.92678 3.28033 7.21967L12 15.9393L20.7197 7.21967C21.0126 6.92678 21.4874 6.92678 21.7803 7.21967C22.0732 7.51256 22.0732 7.98744 21.7803 8.28033L12.5303 17.5303C12.2374 17.8232 11.7626 17.8232 11.4697 17.5303L2.21967 8.28033C1.92678 7.98744 1.92678 7.51256 2.21967 7.21967Z" fill="currentColor"></path>
</svg>