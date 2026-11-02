jQuery( document ).ready( function( $ ) {
	$( 'form.checkout' ).on( 'change', 'input[name^=\'payment_method\']', function() {
		$('body').trigger('update_checkout');
	});
})