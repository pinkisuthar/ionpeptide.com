<?php

$subtotal = wc_price(100);

?>

<# if ( data.totalsLocation !== 'body' ) { #>
	<?php echo $footer_template ?>
<# } #>


<# if ( data.footer.footerTxt ) { #>
<span class="xoo-wsc-footer-txt">{{{data.footer.footerTxt}}}</span>
<# } #>


<div class="xoo-wsc-ft-buttons-cont">

	<# _.each( data.footer.buttonsPosition, function( key ) { #>
		<# if( data.footer.buttonsText[key] ){ #>
			<a href="#" class="xoo-wsc-ft-btn">{{{data.footer.buttonsText[key]}}} <# if( key === 'checkout' && data.footer.checkoutTotal === 'yes' ){ #> - <?php echo $subtotal; ?> <# } #></a>
		<# } #>
	<# }) #>

</div>

