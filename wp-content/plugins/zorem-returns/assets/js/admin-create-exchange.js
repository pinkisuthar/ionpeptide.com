/**
 * Admin-initiated exchange request — "Create Exchange Request" modal on the
 * order edit screen (works on both HPOS and legacy order screens).
 *
 * Two modes, mirroring the customer exchange form:
 *  - same-variation (default): pick an equal-value replacement variant per item.
 *  - cross-product (when "Allow Cross-Product Exchange" is enabled): each item
 *    row has its OWN product-search widget, so every exchanged item can be
 *    swapped for a different replacement product. Price difference is shown
 *    live per item (pay-more / store credit / even) using the same AJAX
 *    endpoints as the frontend widget, and each item becomes its own exchange
 *    request on submit.
 */
( function ( $ ) {
	'use strict';

	var CROSS = ( typeof zorem_ace !== 'undefined' && zorem_ace.cross ) ? zorem_ace.cross : false;

	function debounce( fn, delay ) {
		var timer;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () { fn.apply( ctx, args ); }, delay );
		};
	}

	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	function closeModal() {
		$( '#zorem_ace_modal' ).hide();
	}

	$( document ).on( 'click', '#zorem_ace_open', function () {
		$( '#zorem_ace_modal' ).css( 'display', 'flex' );
	} );

	$( document ).on( 'click', '.zorem-ace-close', closeModal );

	// Click on the dark backdrop closes the modal.
	$( document ).on( 'click', '#zorem_ace_modal', function ( e ) {
		if ( e.target === this ) {
			closeModal();
		}
	} );

	$( document ).on( 'change', '#zorem_ace_reason', function () {
		$( '.zorem-ace-other-wrap' ).toggle( $( this ).val() === 'other_reason' );
	} );

	// ── Cross-product per-widget state ─────────────────────────────────────────
	// Each .zorem-ace-cp-wrap (one per item row) keeps its own selection in
	// jQuery data: { product, variation, attrs, varData }.
	function cpState( $wrap ) {
		var st = $wrap.data( 'aceCp' );
		if ( ! st ) {
			st = { product: null, variation: 0, attrs: {}, varData: null };
			$wrap.data( 'aceCp', st );
		}
		return st;
	}

	function itemQty( itemId ) {
		var $sel = $( '.zorem-ace-qty[data-item-id="' + itemId + '"]' );
		return parseInt( $sel.val(), 10 ) || 0;
	}

	// ── Cross-product: live search (scoped to the widget the admin typed in) ───
	$( document ).on( 'input', '.zorem-ace-cp-wrap .zre-cp-search-input', debounce( function () {
		if ( ! CROSS ) { return; }
		var $wrap    = $( this ).closest( '.zorem-ace-cp-wrap' );
		var $results = $wrap.find( '.zre-cp-results' );
		var kw       = $( this ).val().trim();
		if ( kw.length < 2 ) { $results.hide().empty(); return; }
		$results.html( '<div class="zre-cp-loading">' + escHtml( CROSS.i18n.searching ) + '</div>' ).show();

		$.post( window.ajaxurl, {
			action: 'zre_search_products',
			nonce: CROSS.nonce,
			keyword: kw
		}, function ( res ) {
			$results.empty();
			if ( ! res.success || ! res.data || ! res.data.length ) {
				$results.html( '<div class="zre-cp-no-results">' + escHtml( CROSS.i18n.no_results ) + '</div>' ).show();
				return;
			}
			$.each( res.data, function ( i, p ) {
				var $row = $( '<div class="zre-cp-result-row" tabindex="0"></div>' )
					.data( 'product', p )
					.html(
						'<img src="' + escHtml( p.image ) + '" alt="" class="zre-cp-result-img">' +
						'<div class="zre-cp-result-info">' +
							'<span class="zre-cp-result-name">' + escHtml( p.name ) + '</span>' +
							'<span class="zre-cp-result-price">' + p.price + '</span>' +
						'</div>'
					);
				$results.append( $row );
			} );
			$results.show();
		} );
	}, 300 ) );

	// ── Cross-product: pick a product from results ─────────────────────────────
	$( document ).on( 'click keypress', '.zorem-ace-cp-wrap .zre-cp-result-row', function ( e ) {
		if ( e.type === 'keypress' && e.which !== 13 ) { return; }
		var $wrap = $( this ).closest( '.zorem-ace-cp-wrap' );
		var p     = $( this ).data( 'product' );
		var st    = cpState( $wrap );

		st.product   = p;
		st.variation = 0;
		st.attrs     = {};
		st.varData   = null;

		$wrap.find( '.zre-cp-results' ).hide().empty();
		$wrap.find( '.zre-cp-search-input' ).val( '' );

		$wrap.find( '.zre-cp-selected-img' ).attr( 'src', p.image ).attr( 'alt', p.name );
		$wrap.find( '.zre-cp-selected-name' ).text( p.name );
		$wrap.find( '.zre-cp-selected-price' ).html( p.price );
		$wrap.find( '.zre-cp-selected' ).show();

		$wrap.find( '.zre-cp-attr-selects' ).empty();
		$wrap.find( '.zre-cp-attr-price, .zre-cp-attr-msg, .zre-cp-price-banner' ).hide();
		$wrap.find( '.zre-cp-variation-wrap' ).hide();

		if ( p.is_variable ) {
			loadVariations( $wrap, p.id );
		} else {
			calcPriceDiff( $wrap );
		}
	} );

	// ── Cross-product: load variations + per-attribute pickers ────────────────
	function loadVariations( $wrap, productId ) {
		$.post( window.ajaxurl, {
			action: 'zre_get_product_variations',
			nonce: CROSS.nonce,
			product_id: productId
		}, function ( res ) {
			if ( ! res.success || ! res.data || ! res.data.attributes || ! res.data.variations ) { return; }
			var st = cpState( $wrap );
			st.varData = res.data;

			var $attrSelects = $wrap.find( '.zre-cp-attr-selects' );
			$attrSelects.empty();
			$.each( st.varData.attributes, function ( i, attr ) {
				var $sel = $( '<select class="zre-cp-attr-select"></select>' ).attr( 'data-attr', attr.key );
				$sel.append( $( '<option></option>' ).val( '' ).text( CROSS.i18n.choose_option ) );
				$.each( attr.options, function ( j, o ) {
					$sel.append( $( '<option></option>' ).val( o.slug ).text( o.name ) );
				} );
				$attrSelects.append(
					$( '<div class="zre-cp-attr-row"></div>' )
						.append( $( '<label class="zre-cp-attr-label"></label>' ).text( attr.label ) )
						.append( $sel )
				);
			} );
			$wrap.find( '.zre-cp-variation-wrap' ).show();
		} );
	}

	// ── Cross-product: resolve chosen attributes to a variation ───────────────
	$( document ).on( 'change', '.zorem-ace-cp-wrap .zre-cp-attr-select', function () {
		var $wrap     = $( this ).closest( '.zorem-ace-cp-wrap' );
		var st        = cpState( $wrap );
		var chosen    = {};
		var allChosen = true;

		$wrap.find( '.zre-cp-attr-select' ).each( function () {
			var val = $( this ).val();
			if ( ! val ) { allChosen = false; }
			chosen[ $( this ).data( 'attr' ) ] = val;
		} );

		st.variation = 0;
		st.attrs     = {};
		$wrap.find( '.zre-cp-attr-price, .zre-cp-attr-msg, .zre-cp-price-banner' ).hide();

		if ( ! allChosen || ! st.varData ) { return; }

		// A variation matches when every attribute equals the chosen value or is
		// '' ("Any"). Prefer the most specific match (explicit beats catch-all).
		var best = null, bestScore = -1;
		$.each( st.varData.variations, function ( i, vr ) {
			var ok = true, score = 0;
			$.each( vr.attributes, function ( k, val ) {
				if ( '' === val || null === val ) { return; }
				if ( val !== chosen[ k ] ) { ok = false; return false; }
				score++;
			} );
			if ( ok && score > bestScore ) { best = vr; bestScore = score; }
		} );

		if ( best ) {
			st.variation = best.variation_id;
			st.attrs     = chosen;
			$wrap.find( '.zre-cp-attr-price' ).html( best.price ).show();
			calcPriceDiff( $wrap );
		} else {
			$wrap.find( '.zre-cp-attr-msg' ).text( CROSS.i18n.combo_unavailable ).show();
		}
	} );

	// ── Cross-product: per-item price difference banner ────────────────────────
	// Compares THIS item (qty from its row) against ITS chosen replacement —
	// each item is its own exchange request, so the math never mixes items.
	function calcPriceDiff( $wrap ) {
		var st     = cpState( $wrap );
		var itemId = $wrap.data( 'itemId' );
		if ( ! st.product ) { return; }
		var $banner = $wrap.find( '.zre-cp-price-banner' );
		var qty     = itemQty( itemId );
		if ( ! qty ) {
			$banner.hide().removeClass( 'zre-banner-pay-more zre-banner-credit zre-banner-even' );
			return;
		}
		$.post( window.ajaxurl, {
			action: 'zre_calculate_price_difference',
			nonce: CROSS.nonce,
			original_items: JSON.stringify( [ { item_id: itemId, qty: qty } ] ),
			new_product_id: st.product.id,
			new_variation_id: st.variation,
			new_qty: qty
		}, function ( res ) {
			if ( ! res.success ) { return; }
			$banner
				.removeClass( 'zre-banner-pay-more zre-banner-credit zre-banner-even' )
				.addClass( 'zre-banner-' + res.data.scenario.replace( '_', '-' ) )
				.html( res.data.message )
				.show();
		} );
	}

	// Refresh that item's banner when its quantity changes.
	$( document ).on( 'change', '.zorem-ace-qty', function () {
		if ( ! CROSS ) { return; }
		var itemId = $( this ).data( 'itemId' );
		var $wrap  = $( '.zorem-ace-cp-wrap[data-item-id="' + itemId + '"]' );
		if ( ! $wrap.length ) { return; }
		var st = cpState( $wrap );
		if ( st.product && ( ! st.product.is_variable || st.variation ) ) {
			calcPriceDiff( $wrap );
		}
	} );

	// ── Cross-product: clear selection ─────────────────────────────────────────
	$( document ).on( 'click', '.zorem-ace-cp-wrap .zre-cp-clear', function () {
		var $wrap = $( this ).closest( '.zorem-ace-cp-wrap' );
		var st    = cpState( $wrap );
		st.product   = null;
		st.variation = 0;
		st.attrs     = {};
		st.varData   = null;
		$wrap.find( '.zre-cp-selected, .zre-cp-variation-wrap' ).hide();
		$wrap.find( '.zre-cp-price-banner' ).hide().removeClass( 'zre-banner-pay-more zre-banner-credit zre-banner-even' );
	} );

	// Hide results on outside click.
	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.zre-cp-search-box' ).length ) {
			$( '.zorem-ace-cp-wrap .zre-cp-results' ).hide();
		}
	} );

	// ── Submit ────────────────────────────────────────────────────────────────
	$( document ).on( 'click', '#zorem_ace_submit', function () {
		var $btn      = $( this );
		var $error    = $( '.zorem-ace-error' );
		var crossMode = CROSS && $( '#zorem_ace_modal .zorem-ace-cp-wrap' ).length > 0;
		$error.hide().text( '' );

		var data = {
			action: 'zorem_admin_create_exchange',
			security: $( '#zorem_ace_nonce' ).val(),
			order_id: $( '#zorem_ace_order_id' ).val(),
			notify_customer: $( '#zorem_ace_notify' ).is( ':checked' ) ? 'yes' : 'no'
		};

		var hasItem        = false;
		var missingVariant = false;
		var missingProduct = false;
		$( '.zorem-ace-qty' ).each( function () {
			var qty    = parseInt( $( this ).val(), 10 ) || 0;
			var itemId = $( this ).data( 'itemId' );
			if ( qty < 1 ) { return; }
			hasItem = true;
			data[ 'exchange_data[exchange_items][' + itemId + '][exchange_product_id]' ]  = itemId;
			data[ 'exchange_data[exchange_items][' + itemId + '][exchange_product_qty]' ] = qty;

			if ( crossMode ) {
				// This item's own replacement product (and concrete variant when
				// the replacement is a variable product).
				var $wrap = $( '.zorem-ace-cp-wrap[data-item-id="' + itemId + '"]' );
				var st    = $wrap.length ? cpState( $wrap ) : null;
				if ( ! st || ! st.product || ( st.product.is_variable && ! st.variation ) ) {
					missingProduct = true;
					$wrap.addClass( 'zorem-ace-cp-missing' );
					return;
				}
				$wrap.removeClass( 'zorem-ace-cp-missing' );
				data[ 'exchange_data[exchange_items][' + itemId + '][new_product_id]' ]   = st.product.id;
				data[ 'exchange_data[exchange_items][' + itemId + '][new_variation_id]' ] = st.variation;
				// Chosen attribute values so "Any …" variations resolve for fulfilment.
				$.each( st.attrs, function ( key, val ) {
					if ( val ) {
						data[ 'exchange_data[exchange_items][' + itemId + '][new_variation_attrs][' + key + ']' ] = val;
					}
				} );
			} else {
				// Every variant attribute of this item must be chosen.
				$( '.zorem-ace-variant[data-item-id="' + itemId + '"]' ).each( function () {
					var attr = $( this ).data( 'attr' );
					var val  = $( this ).val() || '';
					if ( ! val ) {
						missingVariant = true;
						$( this ).addClass( 'zorem-ace-variant-missing' );
					} else {
						$( this ).removeClass( 'zorem-ace-variant-missing' );
						data[ 'exchange_data[exchange_items][' + itemId + '][variants][' + attr + ']' ] = val;
					}
				} );
			}
		} );
		if ( ! hasItem ) {
			$error.text( zorem_ace.select_item ).show();
			return;
		}
		if ( missingVariant ) {
			$error.text( zorem_ace.select_variant ).show();
			return;
		}
		if ( missingProduct ) {
			$error.text( zorem_ace.select_product ).show();
			return;
		}

		if ( crossMode ) {
			data[ 'exchange_data[exchange_type]' ] = 'cross-product';
		}

		var reason = $( '#zorem_ace_reason' ).val() || '';
		if ( ! reason ) {
			$error.text( zorem_ace.select_reason ).show();
			return;
		}
		data[ 'exchange_data[exchange_reason][no_other]' ]     = reason;
		data[ 'exchange_data[exchange_reason][other_reason]' ] = $( '#zorem_ace_other_reason' ).val() || '';
		data[ 'exchange_data[exchange_issue]' ]                = $( '#zorem_ace_issue' ).val() || '';
		data[ 'exchange_data[exchange_status]' ]               = $( '#zorem_ace_status' ).val();

		$btn.prop( 'disabled', true );
		$( '.zorem-ace-spinner' ).addClass( 'is-active' );

		$.post( window.ajaxurl, data, null, 'json' )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					// Reload so the new request(s) appear in the Exchange Requests meta box.
					window.location.reload();
				} else {
					$btn.prop( 'disabled', false );
					$( '.zorem-ace-spinner' ).removeClass( 'is-active' );
					$error.text( ( resp && resp.data && resp.data.error ) ? resp.data.error : zorem_ace.network_error ).show();
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
				$( '.zorem-ace-spinner' ).removeClass( 'is-active' );
				$error.text( zorem_ace.network_error ).show();
			} );
	} );

} )( jQuery );
