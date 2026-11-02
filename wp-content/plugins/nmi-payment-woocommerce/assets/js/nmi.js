jQuery( function( $ ) {
	'use strict';

    let nmi_error = {},
        card_allowed;

	/**
	 * Object to handle NMI payment forms.
	 */
	let wc_nmi_form = {

        /**
		 * Creates all NMI elements that will be used to enter cards or IBANs.
		 */
		createElements: function() {

			const customCss = ! ( $('#cfw-payment-method').length || $('.woolentor-step--payment').length || $('.avada-checkout').length || $('.ro-checkout-process').length || $('button.wfacp_next_page_button').length ) ? { "pointer-events": "auto"} : {
				"height": "30px"
            }

			if (window.CollectJS !== undefined) {

				CollectJS.configure({
					//"paymentSelector" : "#place_order",
					"variant": "inline",
					"styleSniffer": "true",
					"customCss": customCss,
					//"googleFont": "Montserrat:400",
					"fields": {
						"ccnumber": {
							"selector": "#nmi-card-number-element",
							"placeholder": "•••• •••• •••• ••••"
						},
						"ccexp": {
							"selector": "#nmi-card-expiry-element",
							"placeholder": wc_nmi_params.placeholder_expiry
						},
						"cvv": {
							"display": "show",
							"selector": "#nmi-card-cvc-element",
							"placeholder": wc_nmi_params.placeholder_cvc
						}
					},
					'validationCallback': function (field, status, message) {
						if (status) {
							message = field + " is OK: " + message;
							nmi_error[field] = '';
						} else {
							nmi_error[field] = message;
						}
						console.log(message);
					},
					"timeoutDuration": 20000,
					"timeoutCallback": function () {
						$(document).trigger('nmiError', wc_nmi_params.timeout_error);
					},
					"fieldsAvailableCallback": function () {
						wc_nmi_form.unblock();
						wc_nmi_form.unblockPayment();
						console.log("Collect.js loaded the fields onto the form");
					},
					'callback': function (response) {
						wc_nmi_form.onNMIResponse(response);
					}
				});
			} else {
				$(document).trigger('nmiError', wc_nmi_params.collect_js_error);
				$('#wc-nmi-cc-form label, #wc-nmi-cc-form .wc-nmi-elements-field').hide();
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
					'checkout_place_order_nmi',
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
					'#wc-nmi-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'nmiError',
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
					if ( $('.wc_payment_methods').height() > 0 && wc_nmi_form.isNMIChosen() ) {
						wc_nmi_form.blockPayment();
						wc_nmi_form.createElements();
					}
				});

            if ( wc_nmi_form.isNMIChosen() ) {
				wc_nmi_form.blockPayment();
                wc_nmi_form.createElements();
            }

		    // CheckoutWC and woolentor, La Forat theme, Avada multi step checkout
			$( 'body' ).on( 'click', '.custom_checkout_page a[href="#next"], a[href="#cfw-payment-method"], a[data-tab="#cfw-payment-method"], a[data-step="step--payment"], a.ro-tab-2, a.ro-btn-2, button.wfacp_next_page_button, .woocommerce-checkout-nav a,.continue-checkout', function() {
                // Don't re-mount if already mounted in DOM.
                if ( wc_nmi_form.isNMIChosen() ) {
					wc_nmi_form.blockPayment();
                    wc_nmi_form.createElements();
                }
            } );

            /**
			 * Only in checkout page we need to delay the mounting of the
			 * card as some AJAX process needs to happen before we do.
			 */
			if ( 'yes' === wc_nmi_params.is_checkout ) {
				$( document.body ).on( 'updated_checkout', function() {
					// Re-mount  on updated checkou
                    if ( wc_nmi_form.isNMIChosen() ) {
						wc_nmi_form.blockPayment();
				        wc_nmi_form.createElements();
                    }

				} );
			}

            $( document.body ).on( 'payment_method_selected', function() {
                // Don't re-mount if already mounted in DOM.
				if ( wc_nmi_form.isNMIChosen() && ! $( '#nmi-card-number-element' ).children().length ) {
                    wc_nmi_form.block();
                    wc_nmi_form.createElements();
                }
            } );

            if( this.form !== undefined ) {
                this.form.on( 'click change', 'input[name="wc-nmi-payment-token"]', function() {
                    if ( wc_nmi_form.isNMIChosen() && ! $( '#nmi-card-number-element' ).children().length ) {
                        wc_nmi_form.block();
                        wc_nmi_form.createElements();
                    }
                } );
            }
		},

		isNMIChosen: function() {
			return $( '#payment_method_nmi' ).is( ':checked' ) && ( ! $( 'input[name="wc-nmi-payment-token"]:checked' ).length || 'new' === $( 'input[name="wc-nmi-payment-token"]:checked' ).val() );
		},

		hasToken: function() {
			return ( 0 < $( 'input.nmi_js_token' ).length ) && ( 0 < $( 'input.nmi_js_response' ).length );
		},

		block: function() {
			wc_nmi_form.form.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},

		unblock: function() {
			wc_nmi_form.form.unblock();
		},

		blockPayment: function() {
			wc_nmi_form.form.find('#payment, .wfacp_payment').block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},

		unblockPayment: function() {
			wc_nmi_form.form.find('#payment, .wfacp_payment').unblock();
		},

        getSelectedPaymentElement: function() {
			return $( '.payment_methods input[name="payment_method"]:checked' );
		},

		onError: function( e, result ) {
			//console.log(responseObject.response);
			let message = result;
			let selectedMethodElement = wc_nmi_form.getSelectedPaymentElement().closest( 'li' );
			let savedTokens = selectedMethodElement.find( '.woocommerce-SavedPaymentMethods-tokenInput' );
			let errorContainer;

			if ( savedTokens.length ) {
				// In case there are saved cards too, display the message next to the correct one.
				let selectedToken = savedTokens.filter( ':checked' );

				if ( selectedToken.closest( '.woocommerce-SavedPaymentMethods-new' ).length ) {
					// Display the error next to the CC fields if a new card is being entered.
					errorContainer = $( '#wc-nmi-cc-form .nmi-source-errors' );
				} else {
					// Display the error next to the chosen saved card.
					errorContainer = selectedToken.closest( 'li' ).find( '.nmi-source-errors' );
				}
			} else {
				// When no saved cards are available, display the error next to CC fields.
				errorContainer = selectedMethodElement.find( '.nmi-source-errors' );
			}

			wc_nmi_form.onCCFormChange();
			$( '.woocommerce-NoticeGroup-checkout' ).remove();
			console.log( result ); // Leave for troubleshooting.
			$( errorContainer ).html( '<ul class="woocommerce_error woocommerce-error wc-nmi-error"><li /></ul>' );
			$( errorContainer ).find( 'li' ).text( message ); // Prevent XSS

			if ( $( '.wc-nmi-error' ).length ) {
				$( 'html, body' ).animate({
					scrollTop: ( $( '.wc-nmi-error' ).offset().top - 200 )
				}, 200 );
			}
			wc_nmi_form.unblock();
			wc_nmi_form.unblockPayment();
		},

		onSubmit: function( e ) {
			if ( wc_nmi_form.isNMIChosen() && ! wc_nmi_form.hasToken() ) {
				e.preventDefault();
				wc_nmi_form.block();
                let error_message;

                console.log(nmi_error);

                let validCardNumber = document.querySelector("#nmi-card-number-element .CollectJSValid") !== null;
                let validCardExpiry = document.querySelector("#nmi-card-expiry-element .CollectJSValid") !== null;
                let validCardCvv = document.querySelector("#nmi-card-cvc-element .CollectJSValid") !== null;

                if( !validCardNumber ) {
                    error_message = wc_nmi_params.card_number_error + ( nmi_error.ccnumber ? ' ' + wc_nmi_params.error_ref.replace( '[ref]', nmi_error.ccnumber ) : '' );
                    $( document.body ).trigger( 'nmiError', error_message );
					return false;
                }

                if( !validCardExpiry ) {
                    error_message = wc_nmi_params.card_expiry_error + ( nmi_error.ccexp ? ' ' + wc_nmi_params.error_ref.replace( '[ref]', nmi_error.ccexp ) : '' );
                    $( document.body ).trigger( 'nmiError', error_message );
					return false;
                }

                if( !validCardCvv ) {
                    error_message = wc_nmi_params.card_cvc_error + ( nmi_error.cvv ? ' ' + wc_nmi_params.error_ref.replace( '[ref]', nmi_error.cvv ) : '' );
                    $( document.body ).trigger( 'nmiError', error_message );
					return false;
                }

                CollectJS.startPaymentRequest();

				// Prevent form submitting
				return false;
			}
		},

		onCCFormChange: function() {
			$( '.wc-nmi-error, .nmi_js_token, .nmi_js_response' ).remove();
		},

		onNMIResponse: function( response ) {
            console.log(response);

            if( response.card.type != null ) {
                wc_nmi_params.allowed_card_types.forEach(function (card_type) {
                    if( response.card.type == card_type.replace( 'diners-club', 'diners' ) ) {
                        card_allowed = true;
                    }
                });

                if( ! card_allowed ) {
                    $( document.body ).trigger( 'nmiError', wc_nmi_params.card_disallowed_error );
					return false;
                }
            }

			wc_nmi_form.form.append( "<input type='hidden' class='nmi_js_token' name='nmi_js_token' value='" + response.token + "'/>" );
			$('<input>').attr({
				type: 'hidden',
				class: 'nmi_js_response',
				name: 'nmi_js_response',
				value: JSON.stringify(response)
			}).appendTo(wc_nmi_form.form);
            //wc_nmi_form.form.append( "<input type='hidden' class='nmi_js_response' name='nmi_js_response' value='" + JSON.stringify(response) + "'/>" );
            wc_nmi_form.form.submit();
		},

		clearToken: function() {
			$( '.nmi_js_token, .nmi_js_response' ).remove();
		}
	};

	wc_nmi_form.init();
} );
