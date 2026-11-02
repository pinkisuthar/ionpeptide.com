/* decodeURIComponent that never throws: a value can hold a bare % ("50% off"), which is not valid
   percent-encoding and raises "URIError: URI malformed". */
function bwfan_safe_decode( value ) {
	try {
		return decodeURIComponent( value );
	} catch ( e ) {
		return value;
	}
}

/* Deserialize the form data */
function bwfan_deserialize_obj( query ) {
	if ( '' === query ) {
		return '';
	}
	const setValue = function ( root, path, value ) {
		if ( path.length > 1 ) {
			const dir = path.shift();
			if ( typeof root[ dir ] === 'undefined' ) {
				root[ dir ] = '' === path[ 0 ] ? [] : {};
			}

			arguments.callee( root[ dir ], path, value );
		} else if ( root instanceof Array ) {
			root.push( value );
		} else {
			root[ path ] = value;
		}
	};
	const nvp = query.split( '&' );
	const data = {};
	for ( let i = 0; i < nvp.length; i++ ) {
		const pair = nvp[ i ].split( '=' );
		const name = bwfan_safe_decode( pair[ 0 ] );
		const value = bwfan_safe_decode( pair[ 1 ] );

		let path = name.match( /(^[^\[]+)(\[.*\]$)?/ );
		const first = path[ 1 ];
		if ( path[ 2 ] ) {
			//case of 'array[level1]' || 'array[level1][level2]'
			path = path[ 2 ].match( /(?=\[(.*)\]$)/ )[ 1 ].split( '][' );
		} else {
			//case of 'name'
			path = [];
		}
		path.unshift( first );

		setValue( data, path, value );
	}
	return data;
}

( function ( $ ) {
	$( document ).ready( function () {
		const adminSyncButton = $( '#bwfan-admin-resync-order' );
		adminSyncButton.on( 'click', function () {
			$( this ).addClass( 'loading' );
			$( this ).html( bwfanProObj.localize_text.loading );
			$( '#bwfan-editorder-message-section' )
				.hide()
				.removeClass( 'bwf-success bwf-error' );
			fetch(
				// wpApiSettings.root instead of siteUrl + '/wp-json/': works with plain
				// permalinks (?rest_route=) and sites that rename the REST prefix
				wpApiSettings.root +
					bwfanProObj.apiNamespace +
					'/contact/' +
					bwfanProObj.contactId +
					'/resync-order',
				{
					credentials: 'include',
					headers: {
						'content-type': 'application/json',
						'X-WP-Nonce': wpApiSettings.nonce,
					},
				}
			)
				.then( ( response ) =>
					response
						.json()
						.catch( () => null )
						.then( ( parsed ) => {
							if ( ! parsed || typeof parsed !== 'object' ) {
								// Unparseable/empty body (WAF page, PHP fatal): a failure
								// regardless of HTTP status — never let it look like success
								throw new Error( 'HTTP ' + response.status );
							}
							if ( ! response.ok ) {
								return {
									httpFailed: true,
									message: parsed.message
										? parsed.message
										: bwfanProObj.localize_text.error +
										  ' (HTTP ' +
										  response.status +
										  ')',
								};
							}
							return parsed;
						} )
				)
				.then( function ( result ) {
					let message = '';
					const isSuccess = ! result.httpFailed && result.code == 200;
					$( '#bwfan-editorder-message-section' ).addClass(
						isSuccess ? 'bwf-success' : 'bwf-error'
					);
					$( '#bwfan-admin-resync-order' )
						.html( bwfanProObj.localize_text.text )
						.removeClass( 'loading' );
					if ( result.hasOwnProperty( 'message' ) ) {
						message = result.message;
					}

					if (
						result.hasOwnProperty( 'result' ) &&
						result.result.hasOwnProperty( 'wc' )
					) {
						window.location.reload();
					}

					if ( message !== '' ) {
						$( '#bwfan-editorder-message-section' )
							.html( message )
							.show();
						// Errors stay visible; only success auto-hides
						if ( isSuccess ) {
							setTimeout( function () {
								$( '#bwfan-editorder-message-section' )
									.hide()
									.removeClass( 'bwf-success bwf-error' );
							}, 3000 );
						}
					}
				} )
				.catch( function ( err ) {
					$( '#bwfan-admin-resync-order' )
						.html( bwfanProObj.localize_text.text )
						.removeClass( 'loading' );
					$( '#bwfan-editorder-message-section' )
						.addClass( 'bwf-error' )
						.html(
							bwfanProObj.localize_text.error +
								( err && err.message
									? ' (' + err.message + ')'
									: '' )
						)
						.show();
				} );
		} );
	} );
} )( jQuery );
