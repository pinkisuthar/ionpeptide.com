/**
 * Zorem MCP - the connection screen's behaviour.
 *
 * Ships with the package alongside its markup and its stylesheet, for the same reason: a
 * plugin joining the shared connection should get a working screen, not three things to
 * reimplement. Copy, the self-test and the WooCommerce key are all the library's own
 * controls, and they answer the library's own AJAX actions.
 *
 * Every handler is delegated from `document` and keyed off a data attribute, so it does not
 * care which plugin drew the screen, whether the controls sit inside a form, or whether the
 * markup arrived with the page or was painted in afterwards.
 *
 * jQuery is used only for $.ajax, which the WordPress admin already loads everywhere.
 *
 * @package Zorem_MCP
 * @since 1.1.0
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		if ( ! window.jQuery ) {
			return;
		}

		var $ = window.jQuery;

	/* ---- Say what happened ----
	   Through ZUI's snackbar, which every Zorem plugin ships, and silently when it is absent:
	   a missing toast is a smaller problem than a thrown error, and nothing on this screen
	   depends on the message being seen.

	   This used to call a notify() belonging to AST PRO's own script -- declared inside that
	   file's closure, so never actually reachable from here. It threw, quietly, on the one
	   path that reached it. */
	function notify( message, type ) {
		if ( window.ZUI && window.ZUI.snackbar ) {
			window.ZUI.snackbar( message, { type: type || 'success', duration: 1500 } );
		}
	}


	/* ---- Test the link from outside ----
	   The answer comes from a request that left the server and came back through
	   whatever sits in front of WordPress, so a firewall refusing AI clients refuses
	   this too -- which is the whole point of asking from out there. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-selftest]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		ev.preventDefault();

		// Look these up on the document, not inside the form. The button is rendered by a
		// shared package that any Zorem plugin can put on any screen, and the day one of
		// them places it outside this form, form.querySelector returns null and the button
		// stops responding -- with nothing on screen to say why, which is the worst way for
		// a control to fail.
		var nonce  = document.getElementById( 'zorem_mcp_form_nonce' );
		var result = document.querySelector( '[data-zorem-mcp-testresult]' );

		if ( ! result ) {
			return;
		}

		// A missing nonce means the page did not finish rendering. Say so: a merchant who
		// clicks and gets silence concludes the plugin is broken and opens a ticket, and
		// the one instruction that actually fixes it -- reload -- is one they never get.
		if ( ! nonce ) {
			result.textContent = 'This page did not load completely, so the test cannot run. Reload the page and try again.';
			result.classList.add( 'is-bad' );
			result.classList.remove( 'is-good' );
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || 'Testing…';

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_selftest',
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( reply ) {
			var data   = ( reply && reply.data ) ? reply.data : {};
			var links  = document.querySelector( '[data-zorem-mcp-testlinks]' );
			var detail = document.querySelector( '[data-zorem-mcp-testdetail]' );

			result.textContent = data.message || 'The test could not be completed.';

			// Verdict on one line, what to do about it on the next. As one paragraph this
			// ran to four lines of prose, and a merchant who has just been told something is
			// broken does not read four lines -- the finding and the instruction both got
			// lost in the middle of it.
			if ( detail ) {
				detail.textContent = data.detail || '';
				detail.hidden      = ! data.detail;
			}

			var fix = document.querySelector( '[data-zorem-mcp-fixwrap]' );

			if ( fix ) {
				fix.hidden = ! data.fix;
			}

			// Somewhere to go, not just something to read. Told "allow this path in your
			// security plugin", a merchant still has to find the screen -- and these
			// settings are buried three levels down in every one of these plugins.
			if ( links ) {
				links.innerHTML = '';

				( data.links || [] ).forEach( function ( link, i ) {
					var a = document.createElement( 'a' );

					a.className   = 'ptw_a';
					a.href        = link.url;
					a.textContent = link.label;

					if ( i ) {
						links.appendChild( document.createTextNode( ' · ' ) );
					}

					links.appendChild( a );
				} );

				links.hidden = ( 'ok' === data.status ) || ! ( data.links || [] ).length;
			}
			// Green only for 'ok', which means something from outside genuinely got
			// through. A link that merely answers when the server calls itself has
			// proved nothing about the callers that matter.
			var clean = ( 'ok' === data.status );

			result.classList.toggle( 'is-bad', ! clean );
			result.classList.toggle( 'is-good', clean );
		} ).fail( function () {
			result.textContent = 'The test could not be completed.';
			result.classList.add( 'is-bad' );

			var links  = document.querySelector( '[data-zorem-mcp-testlinks]' );
			var detail = document.querySelector( '[data-zorem-mcp-testdetail]' );

			if ( links ) {
				links.hidden = true;
			}

			if ( detail ) {
				detail.hidden = true;
			}
		} ).always( function () {
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	/* ---- Turn one person's connection off ----
	   The row is removed from service, not from the table: the merchant's record that this
	   connection existed, and when it was last used, is the reason they are on this screen.
	   Confirmed first, because it takes effect immediately and cannot be undone -- the AI
	   client on the other end simply stops working. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-revoke]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		ev.preventDefault();

		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ! window.confirm( ask ) ) {
			return;
		}

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );
		var row   = btn.closest( '[data-zorem-mcp-conn]' );

		if ( ! nonce || ! row ) {
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || 'Working…';

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_revoke',
				id:     btn.getAttribute( 'data-zorem-mcp-revoke' ),
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( reply ) {
			if ( ! reply || ! reply.success ) {
				btn.disabled    = false;
				btn.textContent = label;
				return;
			}

			// Repaint the row in place: everything that changed is in it, and a reload here
			// would throw away anything else the merchant had touched.
			var badge = row.querySelector( '.zorem-mcp-badge' );

			if ( badge ) {
				badge.classList.remove( 'zorem-mcp-badge--ok' );
				badge.classList.add( 'zorem-mcp-badge--error' );
				badge.textContent = 'Revoked';
			}

			row.classList.add( 'is-revoked' );
			btn.remove();
		} ).fail( function () {
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	/* ---- Take this store's own bad-bot list off this one path ----
	   The result is reported from a real request made afterwards, not from whether the file
	   was written -- the rule can live somewhere .htaccess cannot reach, and a green message
	   based on a successful write would be exactly the kind of false assurance this screen
	   exists to stop. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-fix]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		ev.preventDefault();

		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ! window.confirm( ask ) ) {
			return;
		}

		var nonce  = document.getElementById( 'zorem_mcp_form_nonce' );
		var result = document.querySelector( '[data-zorem-mcp-testresult]' );
		var detail = document.querySelector( '[data-zorem-mcp-testdetail]' );

		if ( ! nonce || ! result ) {
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || 'Fixing…';

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_htaccess',
				zorem_mcp_form_nonce: nonce.value
			}
		} ).always( function ( reply ) {
			var ok   = !! ( reply && reply.success );
			var data = ( reply && reply.data ) ? reply.data : {};

			result.textContent = data.message || 'The fix could not be applied.';
			result.classList.toggle( 'is-good', ok );
			result.classList.toggle( 'is-bad', ! ok );

			if ( detail ) {
				detail.hidden = true;
			}

			btn.disabled    = false;
			btn.textContent = label;

			// Once it has worked there is nothing left to offer.
			var wrap = btn.closest( '[data-zorem-mcp-fixwrap]' );

			if ( wrap && ok ) {
				wrap.hidden = true;
			}
		} );
	} );

	/* ---- Copy the connection URL, or a ready-made command ----
	   Two sources, one behaviour. [data-zorem-mcp-copy] points at a field already on the
	   page; [data-zorem-mcp-copy-text] carries the text itself, which the per-client rows
	   need -- their commands are long, and showing five more copies of the token would
	   bury the card. */
	function flashLabel( btn, label ) {
		if ( btn.dataset.zoremMcpFlashing ) {
			return;
		}

		var before = btn.textContent;

		btn.dataset.zoremMcpFlashing = '1';
		btn.textContent = label;

		window.setTimeout( function () {
			btn.textContent = before;
			delete btn.dataset.zoremMcpFlashing;
		}, 1500 );
	}

	function flashCopied( btn ) {
		flashLabel( btn, btn.getAttribute( 'data-label-copied' ) || 'Copied' );
	}

	function flashFailed( btn ) {
		flashLabel( btn, btn.getAttribute( 'data-label-copyfailed' ) || 'Press Ctrl+C' );
	}

	// The older route: copy whatever is selected. Needs a field, so text that has none gets
	// a throwaway one to be selected in.
	function copyByExec( text ) {
		if ( ! document.execCommand ) {
			return false;
		}

		var tmp = document.createElement( 'textarea' );

		tmp.value = text;
		tmp.setAttribute( 'readonly', '' );
		tmp.style.position = 'fixed';
		tmp.style.left = '-9999px';
		document.body.appendChild( tmp );
		tmp.select();

		var ok = false;

		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		document.body.removeChild( tmp );

		return ok;
	}

	/* The clipboard API answers with a promise, and it refuses more often than it looks:
	   an insecure origin, a permissions policy, a document that does not have focus. The
	   first version ignored the promise and reported success immediately, so a refusal
	   looked exactly like a copy. Wait for the answer, fall back when it says no, and say
	   so when neither route works -- a button that silently does nothing is the one thing
	   a merchant cannot act on. */
	function copyText( text, done ) {
		if ( navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then(
				function () {
					done( true );
				},
				function () {
					done( copyByExec( text ) );
				}
			);
			return;
		}

		done( copyByExec( text ) );
	}

	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-copy], [data-zorem-mcp-copy-text]' );

		if ( ! btn ) {
			return;
		}

		ev.preventDefault();

		var text = btn.getAttribute( 'data-zorem-mcp-copy-text' );

		if ( null === text ) {
			var field = document.querySelector( btn.getAttribute( 'data-zorem-mcp-copy' ) );

			// Say so rather than doing nothing. A button wired to a selector that matches
			// nothing is a bug in this screen, and it used to fail in complete silence.
			if ( ! field ) {
				flashFailed( btn );
				return;
			}

			field.select();
			field.setSelectionRange( 0, 99999 );
			text = field.value;
		}

		copyText( text, function ( ok ) {
			if ( ok ) {
				flashCopied( btn );
			} else {
				flashFailed( btn );
			}
		} );
	} );

	/* ---- Repaint the WooCommerce key card ----
	   Entering a key and forgetting one change the same four things: the sentence saying
	   which key is stored, the setup rows, the Forget button, and the tool list -- what the
	   route offers depends on the key that asks for it. Both replies bring the pieces, and
	   this puts them where they go, so nothing on this screen needs a page load. */
	function paintWooKey( parts ) {
		var hint   = document.querySelector( '[data-zorem-mcp-woo-hint]' );
		var wrap   = document.querySelector( '[data-zorem-mcp-woo]' );
		var rows   = document.querySelector( '[data-zorem-mcp-woo-rows]' );
		var forget = document.querySelector( '[data-zorem-mcp-woo-forget]' );
		var tools  = document.querySelector( '[data-zorem-mcp-woo-tools]' );
		var about  = document.querySelector( '[data-zorem-mcp-woo-about]' );
		var aboutB = document.querySelector( '[data-zorem-mcp-woo-about-body]' );
		var conns  = document.querySelector( '[data-zorem-mcp-woo-conns]' );
		var connsB = document.querySelector( '[data-zorem-mcp-woo-conns-body]' );

		if ( hint && parts.hint ) {
			hint.textContent = parts.hint;
		}

		if ( rows ) {
			rows.innerHTML = parts.rows || '';
		}

		if ( wrap ) {
			wrap.hidden = ! parts.hasKey;
		}

		if ( forget ) {
			forget.hidden = ! parts.hasKey;
		}

		if ( tools && 'string' === typeof parts.tools ) {
			tools.innerHTML = parts.tools;
		}

		if ( aboutB && 'string' === typeof parts.about ) {
			aboutB.innerHTML = parts.about;
		}

		if ( about ) {
			about.hidden = ! parts.hasKey;
		}

		if ( connsB && 'string' === typeof parts.conns ) {
			connsB.innerHTML = parts.conns;
		}

		if ( conns ) {
			conns.hidden = ! parts.hasKey;
		}
	}

	/* ---- Delete the WooCommerce key ----
	   The real revoke, and the only one this route has: closing a session sends the app back
	   for another, so access ends when the key does. It ends for every app at once, which is
	   why the confirm says so before anything happens. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-deletekey]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		ev.preventDefault();

		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ! window.confirm( ask ) ) {
			return;
		}

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );

		if ( ! nonce ) {
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || 'Working…';

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_delete_woo_key',
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( reply ) {
			btn.disabled    = false;
			btn.textContent = label;

			if ( ! reply || ! reply.success ) {
				return;
			}

			var data = reply.data || {};

			notify( data.message );

			paintWooKey( {
				hint:   data.hint,
				tools:  data.tools,
				about:  data.about,
				conns:  data.conns,
				rows:   '',
				hasKey: false
			} );
		} ).fail( function () {
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	/* ---- Forget the stored WooCommerce key ----
	   This site's copy only. The key stays valid in WooCommerce, where the merchant can see
	   what else might be using it before deleting anything. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-forgetkey]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		ev.preventDefault();

		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ! window.confirm( ask ) ) {
			return;
		}

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );

		if ( ! nonce ) {
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || 'Working…';

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_forget_woo_key',
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( reply ) {
			btn.disabled    = false;
			btn.textContent = label;

			if ( ! reply || ! reply.success ) {
				return;
			}

			var data = reply.data || {};

			paintWooKey( {
				hint:   data.hint,
				tools:  data.tools,
				about:  data.about,
				conns:  data.conns,
				rows:   '',
				hasKey: false
			} );
		} ).fail( function () {
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	/* ---- Take the WooCommerce key the merchant made ----
	   Nothing is created here any more: the merchant makes the key on WooCommerce's own
	   screen, where the warnings and the permission choices belong, and pastes both halves.
	   What comes back is the awkward part done for them -- the config each AI client wants,
	   already filled in.

	   The reply is injected rather than reloaded into, because the secret exists only in the
	   two fields on this page and a reload is exactly what would lose it. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-usekey]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		ev.preventDefault();

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );
		var wrap  = document.querySelector( '[data-zorem-mcp-woo]' );
		var rows  = document.querySelector( '[data-zorem-mcp-woo-rows]' );
		var error = document.querySelector( '[data-zorem-mcp-keyerror]' );
		var ck    = document.getElementById( 'zorem-mcp-ck' );
		var cs    = document.getElementById( 'zorem-mcp-cs' );

		if ( ! nonce || ! wrap || ! rows || ! ck || ! cs ) {
			return;
		}

		// Asked after the fields are found but before anything is sent, and only when there
		// is something to send: confirming an empty form would be a dialog about nothing.
		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ck.value.trim() && cs.value.trim() && ! window.confirm( ask ) ) {
			return;
		}

		function fail( message ) {
			if ( error ) {
				error.textContent = message;
				error.hidden      = false;
				error.classList.add( 'is-bad' );
			}
		}

		if ( error ) {
			error.hidden = true;
		}

		// Answered here rather than sent to the server and back: the merchant has simply not
		// finished typing, and a round trip to say so is a round trip to say nothing.
		if ( ! ck.value.trim() || ! cs.value.trim() ) {
			fail( 'Paste both halves of the key — the one starting ck_ and the one starting cs_.' );
			( ck.value.trim() ? cs : ck ).focus();
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || label;

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_accept_woo_key',
				consumer_key: ck.value.trim(),
				consumer_secret: cs.value.trim(),
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				fail( ( res && res.data && res.data.message ) || 'That key could not be used.' );
				return;
			}

			paintWooKey( {
				hint:   res.data.hint,
				tools:  res.data.tools,
				about:  res.data.about,
				conns:  res.data.conns,
				rows:   res.data.rows,
				hasKey: true
			} );

			notify( 'Ready \u2014 pick your app below' );
		} ).fail( function ( xhr ) {
			var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;

			fail( msg || 'That key could not be used.' );
		} ).always( function () {
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	/* ---- Save the library's own settings, one at a time ----
	   There is no Save button on this screen, so each field posts itself the moment it
	   changes. Posting the surrounding form would be the obvious alternative and is wrong
	   here: the screen belongs to the library, but the form around it belongs to whichever
	   plugin drew the page -- and asking that form to carry these three options is exactly
	   how they came to be saved three different ways.

	   The switch is the one field whose reply matters. Turning the connection on issues its
	   token, and the link sits directly below it, so the reply carries the URL and the field
	   fills itself in. A reload would do the same and would also discard anything else the
	   merchant had touched. */
	document.addEventListener( 'change', function ( ev ) {
		var field = ev.target.closest( '[data-zorem-mcp-field]' );

		if ( ! field ) {
			return;
		}

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );

		if ( ! nonce ) {
			return;
		}

		var key   = field.getAttribute( 'data-zorem-mcp-field' );
		var value = ( 'checkbox' === field.type ) ? ( field.checked ? 1 : 0 ) : field.value;

		field.disabled = true;

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_save_setting',
				key: key,
				value: value,
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				notify( 'That could not be saved', 'error' );
				return;
			}

			/* The link and everything around it start hidden, because before the switch is
			   thrown there is no link to show. Filling the field was not enough: the wrapper
			   stayed hidden, so a merchant switched the connection on, saw nothing appear,
			   and only found their link after reloading the page. */
			if ( 'enabled' === key ) {
				var endpoint = document.getElementById( 'zorem-mcp-endpoint' );
				var wrap     = document.querySelector( '[data-zorem-mcp-linkwrap]' );
				var url      = ( res.data && res.data.url ) ? res.data.url : '';

				if ( endpoint ) {
					endpoint.value = url;
				}

				if ( wrap ) {
					wrap.hidden = ( '' === url );
				}
			}

			notify( 'Saved' );
		} ).fail( function () {
			notify( 'That could not be saved', 'error' );
		} ).always( function () {
			field.disabled = false;
		} );
	} );

	/* ---- Handing off to a desktop app ----
	   cursor:// and vscode:// leave the browser entirely. Nothing comes back, so the page
	   cannot be told the app opened -- but it can watch for the one thing that follows when
	   it does: this window stops being the focused one. That is the difference between
	   "opened" and "nothing happened", and it is the only honest signal available.

	   Three outcomes, and the button says which:
	     focus leaves     the app took over. Reset quietly; the merchant is elsewhere.
	     nothing, 6s      probably not installed. Ask, do not accuse, and let it fade back.
	     a second click   ignored while the first is still waiting. */
	document.addEventListener( 'click', function ( ev ) {
		var link = ev.target.closest( '[data-zorem-mcp-open]' );

		if ( ! link || link.classList.contains( 'is-opening' ) ) {
			return;
		}

		var label   = link.textContent.trim();
		var opening = link.getAttribute( 'data-label-opening' ) || label;
		var missing = link.getAttribute( 'data-label-missing' ) || label;
		var timer   = null;
		var done    = false;

		function finish( text, state ) {
			if ( done ) {
				return;
			}

			done = true;
			window.clearTimeout( timer );
			document.removeEventListener( 'visibilitychange', left );
			window.removeEventListener( 'blur', left );

			link.classList.remove( 'is-opening' );

			if ( ! state ) {
				link.textContent = label;
				return;
			}

			link.classList.add( state );
			link.textContent = text;

			window.setTimeout( function () {
				link.classList.remove( state );
				link.textContent = label;
			}, 4000 );
		}

		function left() {
			/* The permission dialog does not take focus away on every browser, so this is
			   checked rather than assumed. */
			if ( document.hidden || ! document.hasFocus() ) {
				finish( label, '' );
			}
		}

		link.classList.add( 'is-opening' );
		link.textContent = opening;

		document.addEventListener( 'visibilitychange', left );
		window.addEventListener( 'blur', left );

		timer = window.setTimeout( function () {
			finish( missing, 'is-missing' );
		}, 6000 );
	} );

	/* ---- Sign everyone out ----
	   The same shape as one Revoke, and deliberately so: same confirm, same working label,
	   same reload afterwards. The difference is only in scope, and the confirm text is
	   where that difference is spelled out. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-revoke-all]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );

		if ( ! nonce ) {
			return;
		}

		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ! window.confirm( ask ) ) {
			return;
		}

		var label = btn.textContent;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || label;

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_revoke_all',
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				notify( ( res && res.data && res.data.message ) || 'Nothing was turned off', 'error' );
				btn.disabled    = false;
				btn.textContent = label;
				return;
			}

			notify( res.data.message );

			/* Every row's status changed, so the table is redrawn rather than patched -- but
			   in place, because a reload would throw away everything else on the screen. */
			var table = document.querySelector( '[data-zorem-mcp-connections]' );

			if ( table && 'string' === typeof res.data.table ) {
				table.innerHTML = res.data.table;
			}

			btn.disabled    = false;
			btn.textContent = label;
		} ).fail( function () {
			notify( 'Nothing was turned off', 'error' );
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	/* ---- Reset one app ----
	   Withdraw the token this app is holding, then open the app again so the merchant does
	   not have to find the Add button a second time. Two steps that always go together, so
	   they are one press.

	   The app is opened only after the server has confirmed, because opening first would
	   have the app re-read a token that is still valid and change nothing. */
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest( '[data-zorem-mcp-reset]' );

		if ( ! btn || btn.disabled ) {
			return;
		}

		var nonce = document.getElementById( 'zorem_mcp_form_nonce' );

		if ( ! nonce ) {
			return;
		}

		var ask = btn.getAttribute( 'data-zorem-mcp-confirm' );

		if ( ask && ! window.confirm( ask ) ) {
			return;
		}

		var label = btn.textContent;
		var add   = btn.parentNode ? btn.parentNode.querySelector( '[data-zorem-mcp-open]' ) : null;

		btn.disabled    = true;
		btn.textContent = btn.getAttribute( 'data-label-working' ) || label;

		$.ajax( {
			url:  window.ajaxurl,
			type: 'POST',
			data: {
				action: 'zorem_mcp_reset_client',
				match: btn.getAttribute( 'data-zorem-mcp-reset' ),
				zorem_mcp_form_nonce: nonce.value
			}
		} ).done( function ( res ) {
			notify( ( res && res.data && res.data.message ) || 'Turned off' );

			/* Straight back into the app, so the sign-in it now needs happens at once. */
			if ( add ) {
				add.click();
			}
		} ).fail( function () {
			notify( 'That could not be reset', 'error' );
		} ).always( function () {
			btn.disabled    = false;
			btn.textContent = label;
		} );
	} );

	} );
}() );
