<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rewards       = FKCart\Includes\Data::get_rewards();
$preview_class = '';
if ( fkcart_is_preview() && is_null( $rewards ) ) {
	$rewards       = FKCart\Includes\Front::get_dummy_rewards();
	$preview_class = 'fkcart-preview-reward fkcart-hide';
}
if ( empty( $rewards ) ) {
	return;
}

$svg_icons  = [
	'freeshipping' => '<svg width="20" height="20" viewBox="0 0 21 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2.16675 4.25C2.16675 3.2835 2.98504 2.5 3.99445 2.5H12.828C13.8374 2.5 14.6557 3.2835 14.6557 4.25V5.50006L15.5768 5.49994C16.1702 5.49986 16.7128 5.82087 16.9782 6.32912L18.668 9.5651C18.7768 9.77338 18.8334 10.0031 18.8334 10.2359V13.9999C18.8334 14.8283 18.132 15.4999 17.2668 15.4999H15.6479C15.406 16.641 14.3523 17.5 13.0891 17.5C11.8259 17.5 10.7723 16.6411 10.5303 15.5H9.38147C9.13955 16.6411 8.08588 17.5 6.8227 17.5C5.55952 17.5 4.50584 16.6411 4.26392 15.5H3.99445C2.98504 15.5 2.16675 14.7165 2.16675 13.75V4.25ZM10.5303 14.5C10.7723 13.3589 11.8259 12.5 13.0891 12.5C13.2679 12.5 13.4426 12.5172 13.6113 12.55V4.25C13.6113 3.83579 13.2606 3.5 12.828 3.5H3.99445C3.56185 3.5 3.21115 3.83579 3.21115 4.25V13.75C3.21115 14.1642 3.56185 14.5 3.99445 14.5H4.26392C4.50584 13.3589 5.55952 12.5 6.8227 12.5C8.08588 12.5 9.13955 13.3589 9.38147 14.5H10.5303ZM14.6557 12.9998C15.1571 13.3604 15.5185 13.8898 15.6478 14.4999H17.2668C17.5552 14.4999 17.789 14.276 17.789 13.9999V10.4999H14.6557V12.9998ZM14.6557 9.49994H17.4663L16.0441 6.77633C15.9556 6.60692 15.7747 6.49991 15.5769 6.49994L14.6557 6.50006V9.49994ZM6.8227 13.5C5.95749 13.5 5.25609 14.1716 5.25609 15C5.25609 15.8284 5.95749 16.5 6.8227 16.5C7.68791 16.5 8.3893 15.8284 8.3893 15C8.3893 14.1716 7.68791 13.5 6.8227 13.5ZM11.5225 15C11.5225 15.8284 12.2239 16.5 13.0891 16.5C13.9543 16.5 14.6557 15.8284 14.6557 15C14.6557 14.1716 13.9543 13.5 13.0891 13.5C12.2239 13.5 11.5225 14.1716 11.5225 15Z" fill="#000000"/>
                    </svg>',
	'discount'     => ' <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M16.8751 4.16699C17.6805 4.16699 18.3335 4.81991 18.3335 5.62529V7.50884C18.3335 7.83412 18.0841 8.10503 17.7599 8.13176C16.7927 8.21151 16.0417 9.02305 16.0417 10.0003C16.0417 10.9776 16.7927 11.7891 17.7599 11.8689C18.0841 11.8956 18.3335 12.1665 18.3335 12.4918V14.3753C18.3335 15.1807 17.6805 15.8337 16.8751 15.8337H3.12508C2.31967 15.8337 1.66675 15.1807 1.66675 14.3752L1.66699 12.4917C1.66703 12.1665 1.91645 11.8956 2.24054 11.8689C3.2076 11.789 3.95841 10.9775 3.95841 10.0003C3.95841 9.02314 3.2076 8.21166 2.24054 8.13178C1.91645 8.10501 1.66703 7.83418 1.66699 7.50898L1.66675 5.62533C1.66675 4.81991 2.31967 4.16699 3.12508 4.16699H16.8751ZM17.0835 6.98761V5.62533C17.0835 5.51027 16.9901 5.41699 16.8751 5.41699H3.12508C3.01002 5.41699 2.91675 5.51027 2.91675 5.62524L2.91692 6.98768C4.24436 7.35371 5.20841 8.57032 5.20841 10.0003C5.20841 11.4303 4.24436 12.6469 2.91692 13.013L2.91675 14.3753C2.91675 14.4904 3.01002 14.5837 3.12508 14.5837H16.8751C16.9901 14.5837 17.0835 14.4904 17.0835 14.3753V13.013C15.7559 12.6471 14.7917 11.4304 14.7917 10.0003C14.7917 8.62742 15.6804 7.45122 16.926 7.03557L17.0835 6.98761Z" fill="#000000"/>
                </svg>',
	'freegift'     => ' <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11.875 1.66699C13.3132 1.66699 14.4792 2.83292 14.4792 4.27116C14.4792 4.85787 14.2851 5.39926 13.9578 5.83467L16.0417 5.83366C16.617 5.83366 17.0833 6.30003 17.0833 6.87533V10.0003C17.0833 10.5756 16.617 11.042 16.0417 11.042V15.7295C16.0417 17.1677 14.8757 18.3337 13.4375 18.3337H6.14583C4.70759 18.3337 3.54167 17.1677 3.54167 15.7295V11.042C2.96637 11.042 2.5 10.5756 2.5 10.0003V6.87533C2.5 6.30003 2.96637 5.83366 3.54167 5.83366L5.62557 5.83467C5.29819 5.39926 5.10417 4.85787 5.10417 4.27116C5.10417 2.83292 6.27009 1.66699 7.70833 1.66699C8.56082 1.66699 9.31763 2.07661 9.79269 2.70977C10.2657 2.07661 11.0225 1.66699 11.875 1.66699ZM9.27083 11.042H4.58333V15.7295C4.58333 16.5924 5.28289 17.292 6.14583 17.292H9.27083V11.042ZM15 11.042H10.3125V17.292H13.4375C14.3004 17.292 15 16.5924 15 15.7295V11.042ZM9.27083 6.87533H3.54167V10.0003H9.27083V6.87533ZM16.0417 6.87533H10.3125V10.0003H16.0417V6.87533ZM11.875 2.70866C11.0121 2.70866 10.3125 3.40821 10.3125 4.27116V5.83366H11.875C12.7379 5.83366 13.4375 5.1341 13.4375 4.27116C13.4375 3.40821 12.7379 2.70866 11.875 2.70866ZM7.70833 2.70866C6.84539 2.70866 6.14583 3.40821 6.14583 4.27116C6.14583 5.08334 6.76551 5.75079 7.55785 5.82651L7.70833 5.83366H9.27083V4.27116L9.26368 4.12068C9.18797 3.32833 8.52052 2.70866 7.70833 2.70866Z" fill="#000000"/>
                </svg>'
];
$max_amount = $rewards['max_amount'];

if ( ! isset( $rewards ['subtotal'] ) && class_exists( 'FKCart\Pro\Rewards' ) ) {
	$rewards ['subtotal'] = FKCart\Pro\Rewards::get_cart_total();
}
?>
<div class="fkcart-reward-panel  fkcart-progress-container fkcart-design-modern <?php echo $preview_class; ?>">
    <div class="fkcart-progress-title">
		<?php echo wp_kses_post( $rewards['title'] ) ?>
    </div>
    <div class="fkcart-progress-bar">
        <div class="fkcart-milestone">
			<?php
			$counter        = 1;
			$empty_count    = 0;
			$percent_count  = 0;
			$progress_bar   = 0;
			$tmp_percentage = [];
			$i              = 0;
			$prev_width     = 0;
			$last_key       = array_key_last( $rewards['rewards'] );
			foreach ( $rewards['rewards'] as $key => $reward ) {
				if ( ! isset( $reward['amount'] ) ) {
					$reward['amount'] = 0;
				}
				$is_activated = '';
				$empty_class  = '';
				$width        = ( $key === $last_key ) ? "18px" : "6px";

				$wstyle     = is_rtl() ? "right:calc(" . intval( $reward['progress_width'] ) . "% - " . $width . ")" : "left:calc(" . intval( $reward['progress_width'] ) . "% - " . $width . ")";
				$icon_title = isset( $reward['icon_title'] ) ? nl2br( $reward['icon_title'] ) : '';
				$svg_icon   = $svg_icons[ $reward['type'] ] ?? '';
				if ( ! empty( $icon_title ) ) {
					$empty_count ++;
				}
				if ( $empty_count > 0 ) {
					$empty_class = 'fkcart-icon-label-non-empty';
				}
				if ( isset( $reward ['amount'] ) && $reward ['amount'] == 0 ) {
					$tmp_percentage[] = [ 'progress' => 12, 'amount' => floatval( $reward ['amount'] ) ];
				} elseif ( isset( $reward ['amount'] ) && $reward ['amount'] > 0 ) {
					$tmp_percentage[] = [ 'progress' => $reward ['amount'] / $max_amount * 100, 'amount' => $reward ['amount'] ];
				} else {
					$tmp_percentage[] = [ 'progress' => 0, 'amount' => floatval( $reward ['amount'] ) ];
				}
				if ( $reward['type'] === "freeshipping" && isset( $reward['achieved'] ) && $reward['achieved'] === true ) {
					$progress_bar = 33.33 + $progress_bar;
				} elseif ( $progress_bar <= 100 && isset( $rewards ['subtotal'] ) && isset( $reward ['amount'] ) && $reward ['amount'] > 0 ) {
					$progress_bar = $rewards ['subtotal'] / $reward ['amount'] * 100;
				}
				$prev_width = $reward['progress_width'];
				?>
                <div class="fkcart-icon-wrap fkcart-milestone-<?php echo esc_attr( $counter ); ?> <?php echo( ( true === $reward['achieved'] ) ? 'is-activated' : '' ); ?> <?php echo esc_attr( $empty_class ); ?>" style="<?php echo esc_attr( $wstyle ); ?>">
                    <div class="fkcart-icon ">
						<?php echo $svg_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG icon is hardcoded ?>
                    </div>
					<?php echo '<div class="fkcart-label">' . wp_kses_post( $icon_title ) . '</div>'; ?>
                </div>
				<?php
				$counter ++;
				$i ++;
			}
			$subtotal         = $rewards['subtotal'];
			$percentage_count = $rewards['progress_bar'];
			$percentage_count = $percentage_count > 0 ? number_format( $percentage_count, 2 ) : '0';
			$achieved         = array_filter( $rewards['rewards'], function ( $reward ) {
				return isset( $reward['achieved'] ) && ( 1 === intval( $reward['achieved'] ) );
			} );
			?>
            <div class="fkcart-progress fkcart-achieved-<?php echo esc_attr( count( $achieved ) ); ?>"></div>
        </div>
    </div>
</div>
<style>
    .fkcart-progress:after {
        width: <?php esc_html_e($percentage_count) ?>%;
    }
</style>