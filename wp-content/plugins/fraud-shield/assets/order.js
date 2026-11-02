/* global jQuery, FS_ORDER */
( function ( $ ) {
	'use strict';

	$( function () {
		$( document ).on( 'click', '.fs-metabox .fs-action', function ( e ) {
			e.preventDefault();

			var $btn    = $( this );
			var $box    = $btn.closest( '.fs-metabox' );
			var $result = $box.find( '.fs-action-result' );
			var action  = $btn.data( 'action' );
			var orderId = $box.data( 'order' );
			var nonce   = $box.data( 'nonce' );

			if ( $btn.prop( 'disabled' ) ) { return; }

			// Confirm the destructive blocklist actions.
			if ( action.indexOf( 'blocklist_' ) === 0 ) {
				if ( ! window.confirm( 'Add this to the blocklist? Future checkouts matching it will be rejected in Enforce mode.' ) ) {
					return;
				}
			}
			// High-value review: holding and cancelling change the order, so say so first.
			if ( action === 'hv_held' ) {
				if ( ! window.confirm( 'Mark this order as "something off" and set it On-Hold so the package does not ship?' ) ) {
					return;
				}
			}
			if ( action === 'hv_cancelled' ) {
				if ( ! window.confirm( 'Cancel this order now? This cancels it in WooCommerce; it does NOT refund the payment — issue any refund from the order screen.' ) ) {
					return;
				}
			}

			var original = $btn.text();
			$btn.prop( 'disabled', true ).text( 'Working…' );
			$result.css( 'color', '#2271b1' ).text( '' );

			var payload = {
				action:   'fs_order_action',
				fs_action: action,
				order_id: orderId,
				nonce:    nonce
			};
			// Recording a recovery carries the amount typed next to the button.
			if ( action === 'cb_recover' ) {
				payload.amount = $box.find( '.fs-recover-amount' ).val();
			}
			// A high-value verdict carries the reviewer's note.
			if ( action.indexOf( 'hv_' ) === 0 ) {
				payload.note = $box.find( '.fs-hv-note' ).val();
			}

			$.post( FS_ORDER.ajax, payload )
				.done( function ( res ) {
					if ( res && res.success ) {
						$result.css( 'color', '#1a7f37' ).text( res.data.message || 'Done.' );
						// Chargeback toggles, counting a stop and review verdicts change the box (and possibly the order status) — reload to re-render.
						if ( action === 'mark_chargeback' || action === 'clear_chargeback' || action === 'cb_recover' || action === 'cb_recover_clear' || action === 'count_prevented' || action.indexOf( 'hv_' ) === 0 ) {
							setTimeout( function () { window.location.reload(); }, 1200 );
						} else {
							$btn.text( 'Done ✓' );
						}
					} else {
						var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Action failed.';
						$result.css( 'color', '#d63638' ).text( msg );
						$btn.prop( 'disabled', false ).text( original );
					}
				} )
				.fail( function () {
					$result.css( 'color', '#d63638' ).text( 'Network error. Please try again.' );
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );
	} );

} )( jQuery );
