jQuery( function( $ ) {
	'use strict';

    let nmi3_error = {},
        card_allowed;

	/**
	 * Object to handle NMI3 payment forms.
	 * 
	 * Uses CollectJS_NMI3 — a captured reference to Collect.js loaded from
	 * NMI3's own endpoint. This avoids collision with the original NMI plugin's
	 * window.CollectJS instance.
	 */
	let wc_nmi3_form = {

        /**
		 * Creates all NMI3 elements using our own Collect.js instance.
		 */
		createElements: function() {

			const customCss = ! ( $('#cfw-payment-method').length || $('.woolentor-step--payment').length || $('.avada-checkout').length || $('.ro-checkout-process').length || $('button.wfacp_next_page_button').length ) ? { "pointer-events": "auto"} : {
				"height": "30px"
            }

			if (window.CollectJS_NMI3 !== undefined) {

				CollectJS_NMI3.configure({
					"variant": "inline",
					"styleSniffer": "true",
					"customCss": customCss,
					"fields": {
						"ccnumber": {
							"selector": "#nmi3-card-number-element",
							"placeholder": "•••• •••• •••• ••••"
						},
						"ccexp": {
							"selector": "#nmi3-card-expiry-element",
							"placeholder": wc_nmi3_params.placeholder_expiry
						},
						"cvv": {
							"display": "show",
							"selector": "#nmi3-card-cvc-element",
							"placeholder": wc_nmi3_params.placeholder_cvc
						}
					},
					'validationCallback': function (field, status, message) {
						if (status) {
							message = field + " is OK: " + message;
							nmi3_error[field] = '';
						} else {
							nmi3_error[field] = message;
						}
						console.log('[NMI3] ' + message);
					},
					"timeoutDuration": 20000,
					"timeoutCallback": function () {
						$(document).trigger('nmi3Error', wc_nmi3_params.timeout_error);
					},
					"fieldsAvailableCallback": function () {
						wc_nmi3_form.unblock();
						wc_nmi3_form.unblockPayment();
						console.log("[NMI3] Collect.js loaded the fields onto the form");
					},
					'callback': function (response) {
						wc_nmi3_form.onNMIResponse(response);
					}
				});
			} else {
				$(document).trigger('nmi3Error', wc_nmi3_params.collect_js_error);
				$('#wc-nmi3-cc-form label, #wc-nmi3-cc-form .wc-nmi3-elements-field').hide();
			}

        },

		/**
		 * Initialize event handlers and UI state.
		 */
		init: function() {
			// checkout page
			if ( $( 'form.woocommerce-checkout' ).length ) {
				this.form = $( 'form.woocommerce-checkout' );
			}

			$( 'form.woocommerce-checkout' )
				.on(
					'checkout_place_order_nmi3',
					this.onSubmit
				);

			// pay order page
			if ( $( 'form#order_review' ).length ) {
				this.form = $( 'form#order_review' );
			}

			$( 'form#order_review' )
				.on(
					'submit',
					this.onSubmit
				);

			// add payment method page
			if ( $( 'form#add_payment_method' ).length ) {
				this.form = $( 'form#add_payment_method' );
			}

			$( 'form#add_payment_method' )
				.on(
					'submit',
					this.onSubmit
				);

			$( document )
				.on(
					'change',
					'#wc-nmi3-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'nmi3Error',
					this.onError
				)
				.on(
					'checkout_error',
					this.clearToken
				);

			// Theme high multi step checkout
			$(document.body)
				.on('step_jumped', function(step) {
					console.log($('.wc_payment_methods').height());
					if ( $('.wc_payment_methods').height() > 0 && wc_nmi3_form.isNMI3Chosen() ) {
						wc_nmi3_form.blockPayment();
						wc_nmi3_form.createElements();
					}
				});

            if ( wc_nmi3_form.isNMI3Chosen() ) {
                wc_nmi3_form.blockPayment();
                wc_nmi3_form.createElements();
            }

		    // CheckoutWC and woolentor, La Forat theme, Avada multi step checkout
			$( 'body' ).on( 'click', '.custom_checkout_page a[href="#next"], a[href="#cfw-payment-method"], a[data-tab="#cfw-payment-method"], a[data-step="step--payment"], a.ro-tab-2, a.ro-btn-2, button.wfacp_next_page_button, .woocommerce-checkout-nav a,.continue-checkout', function() {
                if ( wc_nmi3_form.isNMI3Chosen() ) {
                    wc_nmi3_form.blockPayment();
                    wc_nmi3_form.createElements();
                }
            } );

            /**
			 * Only in checkout page we need to delay the mounting of the
			 * card as some AJAX process needs to happen before we do.
			 */
			if ( 'yes' === wc_nmi3_params.is_checkout ) {
				$( document.body ).on( 'updated_checkout', function() {
                    if ( wc_nmi3_form.isNMI3Chosen() ) {
                        wc_nmi3_form.blockPayment();
				        wc_nmi3_form.createElements();
                    }

				} );
			}

            $( document.body ).on( 'payment_method_selected', function() {
                if ( wc_nmi3_form.isNMI3Chosen() ) {
                    wc_nmi3_form.block();
                    wc_nmi3_form.createElements();
                }
            } );

            if( this.form !== undefined ) {
                this.form.on( 'click change', 'input[name="wc-nmi3-payment-token"]', function() {
                    if ( wc_nmi3_form.isNMI3Chosen() && ! $( '#nmi3-card-number-element' ).children().length ) {
                        wc_nmi3_form.block();
                        wc_nmi3_form.createElements();
                    }
                } );
            }
		},

		isNMI3Chosen: function() {
			return $( '#payment_method_nmi3' ).is( ':checked' ) && ( ! $( 'input[name="wc-nmi3-payment-token"]:checked' ).length || 'new' === $( 'input[name="wc-nmi3-payment-token"]:checked' ).val() );
		},

		hasToken: function() {
			return ( 0 < $( 'input.nmi3_js_token' ).length ) && ( 0 < $( 'input.nmi3_js_response' ).length );
		},

		block: function() {
			wc_nmi3_form.form.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},

		unblock: function() {
			wc_nmi3_form.form.unblock();
		},

		blockPayment: function() {
			wc_nmi3_form.form.find('#payment, .wfacp_payment').block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},
		unblockPayment: function() {
			wc_nmi3_form.form.find('#payment, .wfacp_payment').unblock();
		},

        getSelectedPaymentElement: function() {
			return $( '.payment_methods input[name="payment_method"]:checked' );
		},

		onError: function( e, result ) {
			let message = result;
			let selectedMethodElement = wc_nmi3_form.getSelectedPaymentElement().closest( 'li' );
			let savedTokens = selectedMethodElement.find( '.woocommerce-SavedPaymentMethods-tokenInput' );
			let errorContainer;

			if ( savedTokens.length ) {
				let selectedToken = savedTokens.filter( ':checked' );

				if ( selectedToken.closest( '.woocommerce-SavedPaymentMethods-new' ).length ) {
					errorContainer = $( '#wc-nmi3-cc-form .nmi3-source-errors' );
				} else {
					errorContainer = selectedToken.closest( 'li' ).find( '.nmi3-source-errors' );
				}
			} else {
				errorContainer = selectedMethodElement.find( '.nmi3-source-errors' );
			}

			wc_nmi3_form.onCCFormChange();
			$( '.woocommerce-NoticeGroup-checkout' ).remove();
			console.log( result );
			$( errorContainer ).html( '<ul class="woocommerce_error woocommerce-error wc-nmi3-error"><li /></ul>' );
			$( errorContainer ).find( 'li' ).text( message );

			if ( $( '.wc-nmi3-error' ).length ) {
				$( 'html, body' ).animate({
					scrollTop: ( $( '.wc-nmi3-error' ).offset().top - 200 )
				}, 200 );
			}
			wc_nmi3_form.unblock();
			wc_nmi3_form.unblockPayment();
		},

		onSubmit: function( e ) {
			if ( wc_nmi3_form.isNMI3Chosen() && ! wc_nmi3_form.hasToken() ) {
				e.preventDefault();
				wc_nmi3_form.block();

                let error_message;

                console.log(nmi3_error);

                let validCardNumber = document.querySelector("#nmi3-card-number-element .CollectJSValid") !== null;
                let validCardExpiry = document.querySelector("#nmi3-card-expiry-element .CollectJSValid") !== null;
                let validCardCvv = document.querySelector("#nmi3-card-cvc-element .CollectJSValid") !== null;

                if( !validCardNumber ) {
                    error_message = wc_nmi3_params.card_number_error + ( nmi3_error.ccnumber ? ' ' + wc_nmi3_params.error_ref.replace( '[ref]', nmi3_error.ccnumber ) : '' );
                    $( document.body ).trigger( 'nmi3Error', error_message );
					return false;
                }

                if( !validCardExpiry ) {
                    error_message = wc_nmi3_params.card_expiry_error + ( nmi3_error.ccexp ? ' ' + wc_nmi3_params.error_ref.replace( '[ref]', nmi3_error.ccexp ) : '' );
                    $( document.body ).trigger( 'nmi3Error', error_message );
					return false;
                }

                if( !validCardCvv ) {
                    error_message = wc_nmi3_params.card_cvc_error + ( nmi3_error.cvv ? ' ' + wc_nmi3_params.error_ref.replace( '[ref]', nmi3_error.cvv ) : '' );
                    $( document.body ).trigger( 'nmi3Error', error_message );
					return false;
                }

                CollectJS_NMI3.startPaymentRequest();

				// Prevent form submitting
				return false;
			}
		},

		onCCFormChange: function() {
			$( '.wc-nmi3-error, .nmi3_js_token, .nmi3_js_response' ).remove();
		},

		onNMIResponse: function( response ) {
            console.log('[NMI3] Token response:', response);

            if( response.card.type != null ) {
                wc_nmi3_params.allowed_card_types.forEach(function (card_type) {
                    if( response.card.type == card_type.replace( 'diners-club', 'diners' ) ) {
                        card_allowed = true;
                    }
                });

                if( ! card_allowed ) {
                    $( document.body ).trigger( 'nmi3Error', wc_nmi3_params.card_disallowed_error );
					return false;
                }
            }

			wc_nmi3_form.form.append( "<input type='hidden' class='nmi3_js_token' name='nmi3_js_token' value='" + response.token + "'/>" );
            wc_nmi3_form.form.append( "<input type='hidden' class='nmi3_js_response' name='nmi3_js_response' value='" + JSON.stringify(response) + "'/>" );
            wc_nmi3_form.form.submit();
		},

		clearToken: function() {
			$( '.nmi3_js_token, .nmi3_js_response' ).remove();
		}
	};

	wc_nmi3_form.init();
} );
