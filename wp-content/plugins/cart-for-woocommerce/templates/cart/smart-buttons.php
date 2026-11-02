<?php
?>
<div class='fkcart-smart-buttons-wrapper'>
	<?php
	$smart_buttons = apply_filters( 'fkcart_smart_buttons', [] );
	foreach ( $smart_buttons as $button_id => $btn ) {
		$display = '';
		if ( isset( $btn['show'] ) && $btn['show'] === false ) {
			$display = 'display:none';
		}
		?>
        <div class='fkcart-smart-button-wrap fkcart-panel' id="<?php echo esc_attr( $btn['hook'] ) ?>" style="<?php echo esc_attr( $display ) ?>">
			<?php do_action( $btn['hook'] ); ?>
        </div>
		<?php
	}
	?>
</div>
