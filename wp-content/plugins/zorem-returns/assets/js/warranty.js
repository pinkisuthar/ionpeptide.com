/**
 * Warranty Workflow — frontend claim form + admin dashboard status update.
 */
( function ( $ ) {
	'use strict';

	/* ---------- Customer-facing: guest order lookup ([warranty-center] shortcode) ---------- */

	$( document ).on( 'submit', '#warranty-center-form', function ( e ) {
		e.preventDefault();
		var $form = $( this );
		var $btn  = $form.find( '.warranty-center-btn' );

		$form.find( '.error' ).remove();
		$form.closest( '.warranty_center_container' ).find( '.warranty-guest-container' ).remove();
		$btn.prop( 'disabled', true );
		if ( $.fn.start_loader ) {
			$btn.start_loader();
		}

		$.ajax( {
			url: ( typeof ajax_object !== 'undefined' && ajax_object.ajax_url ) ? ajax_object.ajax_url : ( window.ajaxurl || '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: $form.serialize(),
			dataType: 'json',
			success: function ( resp ) {
				if ( $.fn.stop_loader ) {
					$form.stop_loader();
				}
				$btn.prop( 'disabled', false );
				if ( resp && resp.success && resp.data.warranty_center_order_content ) {
					$form.after( resp.data.warranty_center_order_content );
					$form.hide();
				} else {
					var msg = ( resp && resp.data && resp.data.error ) ? resp.data.error : 'Could not find the order.';
					$btn.after( '<span class="error">' + msg + '</span>' );
				}
			},
			error: function () {
				if ( $.fn.stop_loader ) {
					$form.stop_loader();
				}
				$btn.prop( 'disabled', false );
				$btn.after( '<span class="error">Network error. Please try again.</span>' );
			}
		} );
	} );

	$( document ).on( 'click', '.warranty-back-btn', function () {
		var $container = $( this ).closest( '.warranty_center_container' );
		$container.find( '.warranty-guest-container' ).remove();
		$container.find( '#warranty-center-form' ).show();
	} );

	/* ---------- Customer-facing: submit claim form ---------- */

	$( document ).on( 'submit', '#zorem-warranty-claim-form', function ( e ) {
		e.preventDefault();
		var $form    = $( this );
		var $btn     = $form.find( '.zorem-warranty-submit-btn' );
		var $spinner = $form.find( '.zorem-warranty-spinner' );
		var $result  = $form.find( '.zorem-warranty-result' );

		$result.attr( 'hidden', true ).removeClass( 'is-success is-error' ).html( '' );
		$btn.prop( 'disabled', true );
		$spinner.removeAttr( 'hidden' );

		var fd = new FormData( $form[ 0 ] );

		$.ajax( {
			url: ( typeof ajax_object !== 'undefined' && ajax_object.ajax_url ) ? ajax_object.ajax_url : ( window.ajaxurl || '/wp-admin/admin-ajax.php' ),
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function ( resp ) {
				$btn.prop( 'disabled', false );
				$spinner.attr( 'hidden', true );
				if ( resp && resp.success ) {
					$result.removeAttr( 'hidden' ).addClass( 'is-success' ).text( resp.data.message );
					$form.find( 'input[type="text"], textarea, select, input[type="file"]' ).val( '' );
				} else {
					var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Could not submit the claim.';
					$result.removeAttr( 'hidden' ).addClass( 'is-error' ).text( msg );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false );
				$spinner.attr( 'hidden', true );
				$result.removeAttr( 'hidden' ).addClass( 'is-error' ).text( 'Network error. Please try again.' );
			}
		} );
	} );

	/* ---------- Admin dashboard: client-side status + order-id filter ----------
	 * Reads the status from each row's data-status attribute and the order id from
	 * data-order-id (both set in PHP). When the admin updates a claim inline, we
	 * also refresh data-status so subsequent filter changes still match.
	 */
	function applyWarrantyFilter() {
		var picked = String( $( '#zorem_warranty_status_filter' ).val() || '' );
		var query  = String( $( '#zorem_warranty_search_input' ).val() || '' ).trim().toLowerCase();
		// Strip a leading '#' so "#1589" matches order 1589.
		if ( query.charAt( 0 ) === '#' ) {
			query = query.substring( 1 );
		}
		var $rows  = $( '#zorem_warranty_claims_table tbody tr.zorem-warranty-row' );
		var visibleCount = 0;
		$rows.each( function () {
			var $row    = $( this );
			var status  = String( $row.attr( 'data-status' ) || '' );
			var orderId = String( $row.attr( 'data-order-id' ) || '' ).toLowerCase();
			var $detail = $( '.zorem-warranty-detail-row[data-claim-id="' + $row.attr( 'data-claim-id' ) + '"]' );
			var statusOk = ( picked === '' || status === picked );
			var queryOk  = ( query === '' || orderId.indexOf( query ) !== -1 );
			if ( statusOk && queryOk ) {
				$row.show();
				visibleCount++;
			} else {
				$row.hide();
				$detail.hide();
			}
		} );
		// Show the empty-state row when filter matched nothing.
		$( '#zorem_warranty_claims_table .zorem-warranty-empty-row' ).toggle( visibleCount === 0 );
	}
	$( document ).on( 'change', '#zorem_warranty_status_filter', applyWarrantyFilter );
	$( document ).on( 'input', '#zorem_warranty_search_input', applyWarrantyFilter );
	$( document ).on( 'click', '#zorem_warranty_search_submit', function ( e ) {
		e.preventDefault();
		applyWarrantyFilter();
	} );
	$( document ).on( 'keydown', '#zorem_warranty_search_input', function ( e ) {
		if ( e.which === 13 ) {
			e.preventDefault();
			applyWarrantyFilter();
		}
	} );

	/* ---------- Admin dashboard: toggle detail + status update ---------- */

	$( document ).on( 'click', '.zorem-warranty-view-btn', function ( e ) {
		e.preventDefault();
		var $row   = $( this ).closest( 'tr' );
		var id     = $row.data( 'claimId' );
		var $detail = $( '.zorem-warranty-detail-row[data-claim-id="' + id + '"]' );
		$detail.toggle();
	} );

	$( document ).on( 'click', '.zorem-warranty-save-btn', function ( e ) {
		e.preventDefault();
		var $btn      = $( this );
		var $detail   = $btn.closest( '.zorem-warranty-detail-row' );
		var claimId   = $detail.data( 'claimId' );
		var status    = $detail.find( '.zorem-warranty-new-status' ).val();
		var resolution = $detail.find( '.zorem-warranty-resolution' ).val();
		var note      = $detail.find( '.zorem-warranty-resolution-note' ).val();
		var $status   = $detail.find( '.zorem-warranty-save-status' );
		var nonce     = $( '#zorem_warranty_admin_nonce' ).val();

		$btn.prop( 'disabled', true );
		$status.text( '' );

		$.ajax( {
			url: window.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zorem_update_warranty_status',
				nonce: nonce,
				claim_id: claimId,
				status: status,
				resolution_type: resolution,
				resolution_note: note
			},
			success: function ( resp ) {
				$btn.prop( 'disabled', false );
				if ( resp && resp.success ) {
					$status.css( 'color', '#2e7d32' ).text( resp.data.message );

					// Update the visible status pill in the summary row + sync data-status
					// so the client-side filter keeps matching after a status change.
					var $summary = $( '.zorem-warranty-row[data-claim-id="' + claimId + '"]' );
					var $pill    = $summary.find( '.zorem-warranty-status-pill' );
					$pill.attr( 'class', 'zorem-warranty-status-pill status-' + resp.data.status ).text( resp.data.status_label );
					$summary.attr( 'data-status', resp.data.status );
				} else {
					var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Save failed.';
					$status.css( 'color', '#b32d2e' ).text( msg );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false );
				$status.css( 'color', '#b32d2e' ).text( 'Network error.' );
			}
		} );
	} );

} )( jQuery );
