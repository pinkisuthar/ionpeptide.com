/* global jQuery, FS_ADMIN */
( function ( $ ) {
	'use strict';

	var running     = false;
	var scanStop    = false;
	var scanRetries = 0;
	var impRetries  = 0;

	// Pause between batches so we don't flood admin-ajax.php (firewall friendly).
	var THROTTLE = 400;

	// HTTP codes that usually mean "blocked / rate-limited" and are worth a retry.
	function blocked( code ) {
		return code === 403 || code === 0 || code === 429 || code === 502 || code === 503 || code === 520 || code === 522;
	}

	function nf( n ) {
		n = parseInt( n, 10 ) || 0;
		return n.toString().replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function ui() {
		return {
			box:    $( '#fs-progress' ),
			fill:   $( '#fs-progress-fill' ),
			text:   $( '#fs-progress-text' ),
			start:  $( '#fs-scan-start' ),
			score:  $( '#fs-rescore-start' ),
			resume: $( '#fs-scan-resume' ),
			pause:  $( '#fs-scan-pause' ),
			cancel: $( '#fs-scan-cancel' ),
			scope:  $( '#fs-scan-scope' ),
			force:  $( '#fs-scan-force' )
		};
	}

	function setBar( pct, label ) {
		var u = ui();
		pct = Math.max( 0, Math.min( 100, Math.round( pct ) ) );
		u.fill.css( 'width', pct + '%' );
		u.text.text( label );
	}

	// Show/hide the scan control buttons based on the server status.
	function setControls( status ) {
		var u         = ui();
		var isRunning = status === 'running';
		var isPaused  = status === 'paused';
		var active    = isRunning || isPaused;
		u.pause.toggle( isRunning ).prop( 'disabled', false );
		u.resume.toggle( isPaused );
		u.cancel.toggle( active );
		u.start.prop( 'disabled', isRunning );
		u.score.prop( 'disabled', isRunning );
		if ( active ) { u.box.show(); }
	}

	function post( data ) {
		return $.post( FS_ADMIN.ajax, $.extend( { nonce: FS_ADMIN.nonce }, data ) );
	}

	/**
	 * Translate a server scan-state into an overall 0–100 percentage.
	 *
	 * @param {Object} st Scan state.
	 * @return {number}
	 */
	function pctFor( st ) {
		if ( st.status === 'complete' ) { return 100; }
		var offset = parseInt( st.offset, 10 ) || 0;
		if ( st.kind === 'rescore' ) {
			var ts = parseInt( st.score_total, 10 ) || 0;
			return ts ? ( offset / ts ) * 100 : 0;
		}
		if ( st.phase === 'index' ) {
			var ti = parseInt( st.index_total, 10 ) || 0;
			return ti ? Math.min( 50, ( offset / ti ) * 50 ) : 0;
		}
		var tsc = parseInt( st.score_total, 10 ) || 0;
		return 50 + ( tsc ? Math.min( 50, ( offset / tsc ) * 50 ) : 0 );
	}

	function labelFor( st ) {
		if ( st.status === 'complete' ) { return 'Scan complete. Reloading…'; }
		var offset = nf( st.offset );
		if ( st.kind === 'rescore' ) {
			return 'Recomputing all scores — ' + offset + ' / ' + nf( st.score_total );
		}
		if ( st.phase === 'index' ) {
			return 'Pass 1 of 2: indexing orders — ' + offset + ' / ' + nf( st.index_total );
		}
		return 'Pass 2 of 2: scoring orders — ' + offset + ' / ' + nf( st.score_total );
	}

	function render( st ) {
		setBar( pctFor( st ), labelFor( st ) );
	}

	function finish() {
		setBar( 100, 'Scan complete. Reloading…' );
		setTimeout( function () { window.location.reload(); }, 900 );
	}

	function failWith( msg ) {
		setBar( 0, String( msg ) );
		running = false;
		setControls( 'paused' ); // allow Resume / Cancel.
	}

	/**
	 * Step the scan to completion, one batch per request, reading the
	 * authoritative cursor from the server each time.
	 */
	function loop() {
		post( { action: 'fs_scan_step' } )
			.done( function ( res ) {
				scanRetries = 0;
				if ( ! res || ! res.success ) {
					failWith( ( res && res.data && res.data.message ) || 'Scan failed.' );
					return;
				}
				var st = res.data;
				FS_ADMIN.scanState = st;
				render( st );

				if ( st.status === 'complete' ) {
					finish();
					return;
				}
				// Pause requested: persist it AFTER this committed batch (no race).
				if ( scanStop ) {
					running = false;
					post( { action: 'fs_scan_pause' } ).done( function ( r ) {
						if ( r && r.data ) { FS_ADMIN.scanState = r.data; }
						setControls( 'paused' );
					} );
					return;
				}
				if ( st.status === 'running' ) {
					setTimeout( loop, THROTTLE );
				} else {
					running = false;
					setControls( st.status );
				}
			} )
			.fail( function ( jqXHR ) {
				var code = jqXHR ? jqXHR.status : 0;
				if ( blocked( code ) && scanRetries < 5 && ! scanStop ) {
					scanRetries++;
					var wait = 4000 * scanRetries;
					setBar( pctFor( FS_ADMIN.scanState || {} ), 'Server returned ' + ( code || 'no response' ) + ' (likely a firewall / rate limit). Waiting ' + ( wait / 1000 ) + 's, then retrying… (' + scanRetries + '/5)' );
					setTimeout( loop, wait );
				} else {
					failWith( 403 === code
						? 'Blocked by the server/firewall (HTTP 403). Your progress is saved — wait a minute and click Resume. If it keeps happening, your host security (ModSecurity / Wordfence / Cloudflare) is rate-limiting admin-ajax.php; ask them to allowlist it for administrators.'
						: 'Network error during scan. Your progress was saved — click Resume to continue.' );
				}
			} );
	}

	function startScan( kind ) {
		if ( running ) { return; }
		if ( FS_ADMIN.scanState && ( FS_ADMIN.scanState.status === 'running' || FS_ADMIN.scanState.status === 'paused' ) ) {
			if ( ! window.confirm( 'A scan is already in progress. Start a new one from the beginning? To keep your place instead, cancel this dialog and click Resume.' ) ) {
				return;
			}
		}
		running  = true;
		scanStop = false;
		setControls( 'running' );
		setBar( 0, kind === 'rescore' ? 'Starting score recompute…' : 'Counting orders…' );

		var data = { action: 'fs_scan_start', kind: kind };
		if ( kind !== 'rescore' ) {
			data.range = ui().scope.val() || 'all';
			data.force = ui().force.is( ':checked' ) ? 1 : 0;
		}

		post( data )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					failWith( ( res && res.data && res.data.message ) || 'Could not start the scan.' );
					return;
				}
				FS_ADMIN.scanState = res.data;
				render( res.data );
				loop();
			} )
			.fail( function () { failWith( 'Could not reach the server.' ); } );
	}

	function resumeScan() {
		if ( running ) { return; }
		running  = true;
		scanStop = false;
		setControls( 'running' );
		if ( FS_ADMIN.scanState ) { render( FS_ADMIN.scanState ); }
		post( { action: 'fs_scan_resume' } )
			.done( function ( res ) {
				if ( res && res.success ) { FS_ADMIN.scanState = res.data; render( res.data ); }
				loop();
			} )
			.fail( function () { failWith( 'Could not reach the server.' ); } );
	}

	function pauseScan() {
		if ( ! running ) { return; }
		scanStop = true;
		ui().pause.prop( 'disabled', true );
		ui().text.text( 'Pausing after the current batch…' );
	}

	function cancelScan() {
		post( { action: 'fs_scan_cancel' } ).always( function () {
			window.location.reload();
		} );
	}

	/* ----------------------------------------------------------------- *
	 *  Cluster drill-down: lazy-load per-order detail
	 * ----------------------------------------------------------------- */

	function bindExpanders() {
		$( document ).on( 'click', '.fs-expand', function ( e ) {
			e.preventDefault();
			var $btn   = $( this );
			var id     = $btn.data( 'order' );
			var $row   = $( '#fs-detail-' + id );
			var $body  = $row.find( '.fs-detail-body' );

			if ( $row.is( ':visible' ) ) {
				$row.hide();
				$btn.text( $btn.data( 'label-open' ) || '+ Details' );
				return;
			}

			$row.show();
			$btn.text( $btn.data( 'label-close' ) || '− Hide' );

			if ( $row.data( 'loaded' ) ) { return; }

			$body.html( '<em>Loading…</em>' );
			$.post( FS_ADMIN.ajax, {
				action: 'fs_cluster_order',
				nonce: FS_ADMIN.clusterNonce,
				order_id: id
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						$body.html( res.data.html );
						$row.data( 'loaded', true );
					} else {
						$body.html( '<span style="color:#d63638;">' + ( ( res && res.data && res.data.message ) || 'Could not load order.' ) + '</span>' );
					}
				} )
				.fail( function () {
					$body.html( '<span style="color:#d63638;">Network error loading order.</span>' );
				} );
		} );
	}

	/* ----------------------------------------------------------------- *
	 *  CSV import (server-authoritative + resumable, like the scan)
	 * ----------------------------------------------------------------- */

	var importing = false;

	function impUi() {
		return {
			box:    $( '#fs-import-progress' ),
			fill:   $( '#fs-import-fill' ),
			text:   $( '#fs-import-text' ),
			start:  $( '#fs-import-start' ),
			resume: $( '#fs-import-resume' ),
			cancel: $( '#fs-import-cancel' )
		};
	}

	function impSetBar( pct, label ) {
		var u = impUi();
		pct = Math.max( 0, Math.min( 100, Math.round( pct ) ) );
		u.fill.css( 'width', pct + '%' );
		u.text.text( label );
	}

	function impLock( on ) {
		var u = impUi();
		importing = on;
		u.start.prop( 'disabled', on );
		u.resume.prop( 'disabled', on );
		u.box.toggle( true );
	}

	function impPct( st ) {
		if ( st.status === 'complete' ) { return 100; }
		if ( st.phase === 'import' ) {
			var fs = parseInt( st.filesize, 10 ) || 0;
			var bo = parseInt( st.byte_offset, 10 ) || 0;
			return fs ? Math.min( 50, ( bo / fs ) * 50 ) : 0;
		}
		var t = parseInt( st.score_total, 10 ) || 0;
		var o = parseInt( st.score_offset, 10 ) || 0;
		return 50 + ( t ? Math.min( 50, ( o / t ) * 50 ) : 0 );
	}

	function impLabel( st ) {
		if ( st.status === 'complete' ) { return 'Import complete. Reloading…'; }
		if ( st.phase === 'import' ) {
			return 'Pass 1 of 2: importing rows — ' + nf( st.imported ) + ' imported';
		}
		return 'Pass 2 of 2: scoring orders — ' + nf( st.score_offset ) + ' / ' + nf( st.score_total );
	}

	function impRender( st ) { impSetBar( impPct( st ), impLabel( st ) ); }

	function impFail( msg ) {
		impSetBar( 0, String( msg ) );
		impLock( false );
		impUi().resume.show();
	}

	function impLoop() {
		post( { action: 'fs_import_batch' } )
			.done( function ( res ) {
				impRetries = 0;
				if ( ! res || ! res.success ) {
					impFail( ( res && res.data && res.data.message ) || 'Import failed.' );
					return;
				}
				var st = res.data;
				FS_ADMIN.importState = st;
				impRender( st );
				if ( st.status === 'running' ) {
					setTimeout( impLoop, THROTTLE );
				} else if ( st.status === 'complete' ) {
					impSetBar( 100, 'Import complete. Reloading…' );
					setTimeout( function () { window.location.reload(); }, 900 );
				} else {
					impLock( false );
				}
			} )
			.fail( function ( jqXHR ) {
				var code = jqXHR ? jqXHR.status : 0;
				if ( blocked( code ) && impRetries < 5 ) {
					impRetries++;
					var wait = 4000 * impRetries;
					impSetBar( impPct( FS_ADMIN.importState || {} ), 'Server returned ' + ( code || 'no response' ) + ' (likely a firewall / rate limit). Waiting ' + ( wait / 1000 ) + 's, then retrying… (' + impRetries + '/5)' );
					setTimeout( impLoop, wait );
				} else {
					impFail( 403 === code
						? 'Blocked by the server/firewall (HTTP 403). Progress saved — wait a minute and click Resume. If it persists, ask your host to allowlist admin-ajax.php for administrators.'
						: 'Network error during import. Your progress was saved — click Resume to continue.' );
				}
			} );
	}

	function collectMapping() {
		var map = {};
		$( '.fs-map' ).each( function () {
			map[ $( this ).data( 'field' ) ] = parseInt( $( this ).val(), 10 );
		} );
		return map;
	}

	function impStart() {
		if ( importing ) { return; }
		if ( parseInt( collectMapping().order_id, 10 ) < 0 ) {
			window.alert( 'Please map the Order ID column before starting.' );
			return;
		}
		impLock( true );
		impUi().resume.hide();
		impSetBar( 0, 'Starting import…' );
		$.post( FS_ADMIN.ajax, { action: 'fs_import_start', nonce: FS_ADMIN.nonce, mapping: JSON.stringify( collectMapping() ) } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					impFail( ( res && res.data && res.data.message ) || 'Could not start the import.' );
					return;
				}
				FS_ADMIN.importState = res.data;
				impRender( res.data );
				impLoop();
			} )
			.fail( function () { impFail( 'Could not reach the server.' ); } );
	}

	function impResume() {
		if ( importing ) { return; }
		impLock( true );
		if ( FS_ADMIN.importState ) { impRender( FS_ADMIN.importState ); }
		impLoop();
	}

	function impCancel() {
		post( { action: 'fs_import_cancel' } ).always( function () { window.location.reload(); } );
	}

	/* ----------------------------------------------------------------- *
	 *  Geography: backfill destination columns on older orders. NULL
	 *  ship_city is the server-side cursor, so a run is naturally resumable —
	 *  just click again.
	 * ----------------------------------------------------------------- */

	var geoRunning = false;
	var geoDone    = 0;
	var geoRetries = 0;

	function geoStatus( msg ) {
		$( '.fs-geo-backfill-status' ).text( msg );
	}

	function geoLoop() {
		$.post( FS_ADMIN.ajax, { action: 'fs_geo_backfill', nonce: FS_ADMIN.geoNonce } )
			.done( function ( res ) {
				geoRetries = 0;
				if ( ! res || ! res.success ) {
					geoStatus( ( res && res.data && res.data.message ) || 'Backfill failed.' );
					geoRunning = false;
					$( '#fs-geo-backfill' ).prop( 'disabled', false );
					return;
				}
				var processed = parseInt( res.data.processed, 10 ) || 0;
				var remaining = parseInt( res.data.remaining, 10 ) || 0;
				geoDone += processed;
				geoStatus( 'Backfilled ' + nf( geoDone ) + ' · ' + nf( remaining ) + ' remaining' );
				if ( remaining > 0 && processed > 0 ) {
					setTimeout( geoLoop, THROTTLE );
				} else {
					geoStatus( 'Backfill complete. Reloading…' );
					setTimeout( function () { window.location.reload(); }, 900 );
				}
			} )
			.fail( function ( jqXHR ) {
				var code = jqXHR ? jqXHR.status : 0;
				if ( blocked( code ) && geoRetries < 5 ) {
					geoRetries++;
					var wait = 4000 * geoRetries;
					geoStatus( 'Server returned ' + ( code || 'no response' ) + '. Waiting ' + ( wait / 1000 ) + 's, then retrying… (' + geoRetries + '/5)' );
					setTimeout( geoLoop, wait );
				} else {
					geoStatus( 'Network error. Click "Backfill now" to resume — progress is saved.' );
					geoRunning = false;
					$( '#fs-geo-backfill' ).prop( 'disabled', false );
				}
			} );
	}

	function geoStart() {
		if ( geoRunning ) { return; }
		geoRunning = true;
		geoDone    = 0;
		$( '#fs-geo-backfill' ).prop( 'disabled', true );
		geoStatus( 'Backfilling…' );
		geoLoop();
	}

	$( function () {
		$( '#fs-scan-start' ).on( 'click', function ( e ) { e.preventDefault(); startScan( 'full' ); } );
		$( '#fs-rescore-start' ).on( 'click', function ( e ) { e.preventDefault(); startScan( 'rescore' ); } );
		$( '#fs-scan-resume' ).on( 'click', function ( e ) { e.preventDefault(); resumeScan(); } );
		$( '#fs-scan-pause' ).on( 'click', function ( e ) { e.preventDefault(); pauseScan(); } );
		$( '#fs-scan-cancel' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( window.confirm( 'Cancel the scan and discard its saved progress?' ) ) { cancelScan(); }
		} );

		// Surface an in-progress or paused scan on load (no loop is running yet,
		// so present it as resumable).
		if ( FS_ADMIN.scanState && ( FS_ADMIN.scanState.status === 'running' || FS_ADMIN.scanState.status === 'paused' ) ) {
			ui().box.show();
			setControls( 'paused' );
			render( FS_ADMIN.scanState );
			ui().text.text( 'Scan paused at ' + nf( FS_ADMIN.scanState.offset ) + '. Click Resume to continue.' );
		}

		$( '#fs-import-start' ).on( 'click', function ( e ) { e.preventDefault(); impStart(); } );
		$( '#fs-import-resume' ).on( 'click', function ( e ) { e.preventDefault(); impResume(); } );
		$( '#fs-import-cancel' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( window.confirm( 'Discard this uploaded file and its import progress?' ) ) { impCancel(); }
		} );

		if ( FS_ADMIN.importState && FS_ADMIN.importState.status === 'running' ) {
			impUi().box.show();
			impUi().resume.show();
			impRender( FS_ADMIN.importState );
			impSetBar( impPct( FS_ADMIN.importState ), 'Import paused. Click Resume to continue.' );
		}

		$( '#fs-geo-backfill' ).on( 'click', function ( e ) { e.preventDefault(); geoStart(); } );

		// Multi-column sort: a plain click on a column header sorts by that column
		// (primary). Ctrl/Cmd+click keeps the current primary and adds this column
		// as a secondary sort — e.g. "newest, then by spend".
		$( document ).on( 'click', '.fs-sort-link', function ( e ) {
			if ( e.ctrlKey || e.metaKey ) {
				var url2 = $( this ).data( 'fs-sort2' );
				if ( url2 ) {
					e.preventDefault();
					window.location.href = url2;
				}
			}
		} );

		// "Select all" checkbox in bulk tables (Checkout Locks): toggles every
		// enabled row checkbox inside the same table.
		$( document ).on( 'change', '.fs-check-all', function () {
			var on = $( this ).prop( 'checked' );
			$( this ).closest( 'table' ).find( 'tbody input[type="checkbox"]:enabled' ).prop( 'checked', on );
		} );

		// Blocklist page: every credential row's checkbox is bound (by the
		// form attribute) to the one bulk form in the bar above the cards.
		// The bar keeps a live count, offers select-all / select-IPs / clear,
		// and asks before removing.
		var $bulk = $( '#fs-bulk-form' );
		if ( $bulk.length ) {
			var rows = function () {
				return $( 'input.fs-sel[form="fs-bulk-form"]' );
			};
			var refresh = function () {
				var n = rows().filter( ':checked' ).length;
				$bulk.find( '.fs-sel-count' ).text( nf( n ) );
				$( '#fs-bulk-remove' ).prop( 'disabled', n === 0 );
			};
			$( document ).on( 'change', 'input.fs-sel, .fs-check-all', refresh );
			$( '#fs-sel-all' ).on( 'click', function () {
				rows().prop( 'checked', true );
				$( '.fs-blocklist-table .fs-check-all' ).prop( 'checked', true );
				refresh();
			} );
			$( '#fs-sel-ips' ).on( 'click', function () {
				rows().prop( 'checked', false ).filter( '[data-type="ip"]' ).prop( 'checked', true );
				$( '.fs-blocklist-table .fs-check-all' ).each( function () {
					var $t = $( this ).closest( 'table' );
					$( this ).prop( 'checked', $t.find( 'input.fs-sel' ).length > 0 && $t.find( 'input.fs-sel' ).length === $t.find( 'input.fs-sel:checked' ).length );
				} );
				refresh();
			} );
			$( '#fs-sel-none' ).on( 'click', function () {
				rows().prop( 'checked', false );
				$( '.fs-blocklist-table .fs-check-all' ).prop( 'checked', false );
				refresh();
			} );
			$bulk.on( 'submit', function () {
				var n = rows().filter( ':checked' ).length;
				if ( ! n ) {
					return false;
				}
				var msg = $( '#fs-bulk-remove' ).data( 'confirm' ) || '';
				return msg ? window.confirm( msg.replace( '%d', nf( n ) ) ) : true;
			} );
			refresh();
		}

		bindExpanders();
	} );

} )( jQuery );
