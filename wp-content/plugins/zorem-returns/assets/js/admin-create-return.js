/**
 * Admin-initiated return request — "Create Return Request" modal on the
 * order edit screen (works on both HPOS and legacy order screens).
 */
( function ( $ ) {
	'use strict';

	/**
	 * Format an amount using the WooCommerce currency settings localized
	 * from PHP, so JS totals match wc_price() output.
	 */
	function formatPrice( amount ) {
		var s = ( typeof zorem_acr !== 'undefined' && zorem_acr.currency ) ? zorem_acr.currency : null;
		if ( ! s ) {
			return amount.toFixed( 2 );
		}
		var n     = parseFloat( amount ).toFixed( s.decimals );
		var parts = n.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, s.thousand_sep );
		var num = ( s.decimals > 0 ) ? parts.join( s.decimal_sep ) : parts[ 0 ];
		return s.format.replace( '%1$s', s.symbol ).replace( '%2$s', num );
	}

	/**
	 * Recalculate each row's total (unit price × qty) and the grand total.
	 */
	function updateTotals() {
		var grand = 0;
		$( '.zorem-acr-qty' ).each( function () {
			var qty      = parseInt( $( this ).val(), 10 ) || 0;
			var price    = parseFloat( $( this ).data( 'price' ) ) || 0;
			var rowTotal = qty * price;
			grand       += rowTotal;
			$( this ).closest( 'tr' ).find( '.zorem-acr-row-total' ).text( qty > 0 ? formatPrice( rowTotal ) : '—' );
		} );
		$( '.zorem-acr-grand-total' ).text( grand > 0 ? formatPrice( grand ) : '—' );
	}

	function closeModal() {
		$( '#zorem_acr_modal' ).hide();
	}

	$( document ).on( 'click', '#zorem_acr_open', function () {
		$( '#zorem_acr_modal' ).css( 'display', 'flex' );
		updateTotals();
	} );

	$( document ).on( 'click', '.zorem-acr-close', closeModal );

	// Click on the dark backdrop closes the modal.
	$( document ).on( 'click', '#zorem_acr_modal', function ( e ) {
		if ( e.target === this ) {
			closeModal();
		}
	} );

	$( document ).on( 'change', '.zorem-acr-qty', updateTotals );

	$( document ).on( 'change', '#zorem_acr_reason', function () {
		$( '.zorem-acr-other-wrap' ).toggle( $( this ).val() === 'other_reason' );
	} );

	$( document ).on( 'click', '#zorem_acr_submit', function () {
		var $btn   = $( this );
		var $error = $( '.zorem-acr-error' );
		$error.hide().text( '' );

		var data = {
			action: 'zorem_admin_create_return',
			security: $( '#zorem_acr_nonce' ).val(),
			order_id: $( '#zorem_acr_order_id' ).val(),
			notify_customer: $( '#zorem_acr_notify' ).is( ':checked' ) ? 'yes' : 'no'
		};

		var hasItem = false;
		$( '.zorem-acr-qty' ).each( function () {
			var qty    = parseInt( $( this ).val(), 10 ) || 0;
			var itemId = $( this ).data( 'itemId' );
			if ( qty > 0 ) {
				hasItem = true;
				data[ 'return_data[return_items][' + itemId + '][return_product_id]' ]  = itemId;
				data[ 'return_data[return_items][' + itemId + '][return_product_qty]' ] = qty;
			}
		} );
		if ( ! hasItem ) {
			$error.text( zorem_acr.select_item ).show();
			return;
		}

		var reason = $( '#zorem_acr_reason' ).val() || '';
		if ( ! reason ) {
			$error.text( zorem_acr.select_reason ).show();
			return;
		}
		data[ 'return_data[return_reason][no_other]' ]     = reason;
		data[ 'return_data[return_reason][other_reason]' ] = $( '#zorem_acr_other_reason' ).val() || '';
		data[ 'return_data[return_issue]' ]                = $( '#zorem_acr_issue' ).val() || '';
		data[ 'return_data[payment_method]' ]              = $( '#zorem_acr_resolution' ).val();
		data[ 'return_data[return_status]' ]               = $( '#zorem_acr_status' ).val();

		$btn.prop( 'disabled', true );
		$( '.zorem-acr-spinner' ).addClass( 'is-active' );

		$.post( window.ajaxurl, data, null, 'json' )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					// Reload so the new request appears in the Return Requests meta box.
					window.location.reload();
				} else {
					$btn.prop( 'disabled', false );
					$( '.zorem-acr-spinner' ).removeClass( 'is-active' );
					$error.text( ( resp && resp.data && resp.data.error ) ? resp.data.error : zorem_acr.network_error ).show();
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
				$( '.zorem-acr-spinner' ).removeClass( 'is-active' );
				$error.text( zorem_acr.network_error ).show();
			} );
	} );

} )( jQuery );
