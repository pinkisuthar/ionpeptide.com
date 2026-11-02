/**
 * Internal Admin Notes — interactive behavior for the Returns Dashboard preview modal.
 *
 * - Add note via AJAX (POST zorem_add_internal_note)
 * - Delete note via AJAX (POST zorem_delete_internal_note)
 * - @mention autocomplete (POST zorem_search_admin_users)
 *
 * The notes section is injected into the preview modal HTML returned by
 * show_return_preview_data, so all event bindings are delegated.
 */
( function ( $ ) {
	'use strict';

	// ---------- Helpers ----------

	function getSectionNonce( $section ) {
		return $section.find( '.zorem-internal-notes-nonce' ).val() || '';
	}

	function setStatus( $section, msg, isError ) {
		var $status = $section.find( '.zorem-internal-notes-status' );
		$status.text( msg || '' );
		$status.css( 'color', isError ? '#b32d2e' : '#2e7d32' );
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// ---------- Add note ----------

	$( document ).on( 'click', '.zorem-internal-notes-submit', function ( e ) {
		e.preventDefault();
		var $btn     = $( this );
		var $section = $btn.closest( '.zorem-internal-notes-section' );
		var $input   = $section.find( '.zorem-internal-notes-input' );
		var content  = $.trim( $input.val() );

		setStatus( $section, '', false );

		if ( ! content ) {
			setStatus( $section, 'Please type something first.', true );
			return;
		}

		var requestId = $section.data( 'requestId' );
		var nonce     = getSectionNonce( $section );

		// Mentioned user IDs are tracked on the section as a data attribute set
		// by the @mention picker.
		var mentioned = $section.data( 'mentionedIds' ) || [];

		$btn.prop( 'disabled', true );

		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zorem_add_internal_note',
				nonce: nonce,
				request_id: requestId,
				content: content,
				mentioned_user_ids: mentioned
			},
			success: function ( resp ) {
				$btn.prop( 'disabled', false );
				if ( ! resp || ! resp.success ) {
					var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Could not save note.';
					setStatus( $section, msg, true );
					return;
				}
				// Prepend the new note to the list.
				var $list = $section.find( '.zorem-internal-notes-list' );
				$list.find( '.zorem-internal-notes-empty' ).remove();
				$list.prepend( resp.data.html );

				// Update the count badge.
				var $count = $section.find( '.zorem-internal-notes-count' );
				var n = parseInt( ( $count.text().match( /\d+/ ) || [ '0' ] )[ 0 ], 10 ) || 0;
				$count.text( '(' + ( n + 1 ) + ')' );

				// Reset form state.
				$input.val( '' );
				$section.data( 'mentionedIds', [] );
				setStatus( $section, 'Note added.', false );
				setTimeout( function () { setStatus( $section, '', false ); }, 2500 );
			},
			error: function () {
				$btn.prop( 'disabled', false );
				setStatus( $section, 'Network error. Please try again.', true );
			}
		} );
	} );

	// ---------- Delete note ----------

	$( document ).on( 'click', '.zorem-internal-note-delete', function ( e ) {
		e.preventDefault();
		var $link    = $( this );
		var $note    = $link.closest( '.zorem-internal-note' );
		var $section = $link.closest( '.zorem-internal-notes-section' );
		var noteId   = $note.data( 'noteId' );
		var nonce    = getSectionNonce( $section );

		if ( ! window.confirm( 'Delete this note? This cannot be undone.' ) ) {
			return;
		}

		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zorem_delete_internal_note',
				nonce: nonce,
				note_id: noteId
			},
			success: function ( resp ) {
				if ( ! resp || ! resp.success ) {
					var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'Could not delete.';
					setStatus( $section, msg, true );
					return;
				}
				$note.slideUp( 150, function () {
					$note.remove();
					// Update count badge.
					var $count = $section.find( '.zorem-internal-notes-count' );
					var n = parseInt( ( $count.text().match( /\d+/ ) || [ '0' ] )[ 0 ], 10 ) || 0;
					$count.text( '(' + Math.max( 0, n - 1 ) + ')' );
					// Restore "no notes" placeholder if last one.
					var $list = $section.find( '.zorem-internal-notes-list' );
					if ( ! $list.find( '.zorem-internal-note' ).length ) {
						$list.html( '<div class="zorem-internal-notes-empty">No notes yet.</div>' );
					}
				} );
			}
		} );
	} );

	// ---------- @mention autocomplete ----------

	var mentionState = {
		$section: null,
		$input: null,
		$dropdown: null,
		atIndex: -1,
		term: ''
	};

	function closeMentionDropdown() {
		if ( mentionState.$dropdown ) {
			mentionState.$dropdown.attr( 'hidden', true ).empty();
		}
		mentionState.atIndex = -1;
		mentionState.term    = '';
	}

	function positionMentionDropdown() {
		if ( ! mentionState.$input || ! mentionState.$dropdown ) {
			return;
		}
		// Position the dropdown directly below the textarea (full width of the input wrap).
		mentionState.$dropdown.removeAttr( 'hidden' );
	}

	function renderMentionResults( users ) {
		if ( ! mentionState.$dropdown ) {
			return;
		}
		if ( ! users || ! users.length ) {
			mentionState.$dropdown.html( '<div class="zorem-mention-empty">No matches</div>' );
			positionMentionDropdown();
			return;
		}
		var html = users.map( function ( u ) {
			return '<div class="zorem-mention-item" data-user-id="' + u.id + '" data-user-name="' + escapeHtml( u.name ) + '">' +
				'<img class="zorem-mention-avatar" src="' + escapeHtml( u.avatar ) + '" width="24" height="24" alt="" />' +
				'<span class="zorem-mention-name">' + escapeHtml( u.name ) + '</span>' +
				'<span class="zorem-mention-email">' + escapeHtml( u.email ) + '</span>' +
				'</div>';
		} ).join( '' );
		mentionState.$dropdown.html( html );
		positionMentionDropdown();
	}

	function lookupMention( term ) {
		var $section = mentionState.$section;
		var nonce    = getSectionNonce( $section );
		$.ajax( {
			url: ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zorem_search_admin_users',
				nonce: nonce,
				term: term
			},
			success: function ( resp ) {
				if ( resp && resp.success ) {
					renderMentionResults( resp.data.users );
				}
			}
		} );
	}

	$( document ).on( 'keyup', '.zorem-internal-notes-input', function ( e ) {
		var $input = $( this );
		var $section = $input.closest( '.zorem-internal-notes-section' );
		var $dropdown = $section.find( '.zorem-mention-dropdown' );

		var caret = $input[ 0 ].selectionStart || 0;
		var text  = $input.val().substring( 0, caret );
		var atIdx = text.lastIndexOf( '@' );

		if ( atIdx === -1 || ( atIdx > 0 && /\S/.test( text.charAt( atIdx - 1 ) ) ) ) {
			// No @ trigger (or @ is mid-word like email).
			closeMentionDropdown();
			return;
		}

		var term = text.substring( atIdx + 1 );
		if ( /\s/.test( term ) ) {
			closeMentionDropdown();
			return;
		}

		mentionState.$section  = $section;
		mentionState.$input    = $input;
		mentionState.$dropdown = $dropdown;
		mentionState.atIndex   = atIdx;
		mentionState.term      = term;

		lookupMention( term );
	} );

	$( document ).on( 'click', '.zorem-mention-item', function ( e ) {
		e.preventDefault();
		if ( ! mentionState.$input || mentionState.atIndex < 0 ) {
			return;
		}
		var $item   = $( this );
		var userId  = parseInt( $item.data( 'userId' ), 10 );
		var userName = String( $item.data( 'userName' ) );

		var $input  = mentionState.$input;
		var caret   = $input[ 0 ].selectionStart || 0;
		var value   = $input.val();
		var before  = value.substring( 0, mentionState.atIndex );
		var after   = value.substring( caret );

		var insertion = '@' + userName + ' ';
		$input.val( before + insertion + after );

		// Track mentioned IDs (no duplicates).
		var $section = mentionState.$section;
		var existing = $section.data( 'mentionedIds' ) || [];
		if ( existing.indexOf( userId ) === -1 ) {
			existing.push( userId );
			$section.data( 'mentionedIds', existing );
		}

		closeMentionDropdown();

		// Place caret right after the inserted mention.
		var pos = before.length + insertion.length;
		$input[ 0 ].setSelectionRange( pos, pos );
		$input.focus();
	} );

	// Close the dropdown when clicking outside it.
	$( document ).on( 'click', function ( e ) {
		if ( ! mentionState.$dropdown ) {
			return;
		}
		if ( $( e.target ).closest( '.zorem-mention-dropdown, .zorem-internal-notes-input' ).length === 0 ) {
			closeMentionDropdown();
		}
	} );

} )( jQuery );
