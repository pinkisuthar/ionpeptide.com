(function ($) {
	"use strict";
	$.fn.start_loader = function () {
		if (this.find( ".zorem_loader" ).length === 0) {
			this.append( "<span class=zorem_loader></span>" );
		}
		return this;
	};
})( jQuery );

(function ($) {
	"use strict";
	$.fn.stop_loader = function () {
		this.find( ".zorem_loader" ).remove();
		return this;
	};
})( jQuery );

/**
 * Third-party snippets (support widgets etc.) sometimes echo markup before
 * the JSON body of admin-ajax responses. Strict JSON.parse would then fail
 * and silently stop the return/exchange flow, so salvage the JSON payload
 * that follows any stray output. Valid JSON is parsed exactly as before.
 */
(function ($) {
	"use strict";
	$.ajaxSetup(
		{
			converters: {
				"text json": function (text) {
					try {
						return JSON.parse( text );
					} catch (e) {
						var start = text.indexOf( "{" );
						while (start !== -1) {
							try {
								return JSON.parse( text.slice( start ) );
							} catch (err) {
								start = text.indexOf( "{", start + 1 );
							}
						}
						throw e;
					}
				},
			},
		}
	);
})( jQuery );

jQuery( document ).ready(
	function () {
		var element = document.getElementById( "image-upload" );
		if (element) {
			element.addEventListener( "change", previewImages );
		}
		updateKeepItemOption();
	}
);

/**
 * Show keep-item notice and enable the keep_the_item flag input when all
 * selected products are keep-item eligible. Resolution section always stays
 * visible so customers can choose their refund method.
 */
function updateKeepItemOption() {
	var $form = jQuery( "#return-multi-step-form" );
	if ( ! $form.length ) {
		return;
	}

	var $notice    = $form.find( ".return-keep-item-notice" );
	var $flagInput = $form.find( ".keep-item-flag-input" );

	if ( ! $notice.length ) {
		return;
	}

	var $checkboxes = $form.find( ".product_checked" );
	var allKeepItem = true;
	var hasProducts = false;

	if ( $checkboxes.length ) {
		// Multi-product: only check selected products
		var $checked = $checkboxes.filter( ":checked" );
		if ( $checked.length === 0 ) {
			$notice.hide();
			$flagInput.prop( "disabled", true );
			return;
		}
		$checked.each(
			function () {
				hasProducts = true;
				var $productDiv = jQuery( this ).closest( ".product-details" );
				if ( $productDiv.data( "keep-item" ) !== "yes" ) {
					allKeepItem = false;
				}
			}
		);
	} else {
		// Single product (hidden input)
		var $productDiv = $form.find( ".product-details" );
		if ( $productDiv.length ) {
			hasProducts = true;
			if ( $productDiv.data( "keep-item" ) !== "yes" ) {
				allKeepItem = false;
			}
		}
	}

	if ( hasProducts && allKeepItem ) {
		$notice.show();
		$flagInput.prop( "disabled", false );
	} else {
		$notice.hide();
		$flagInput.prop( "disabled", true );
	}
}

jQuery( document ).on(
	"change",
	".product_checked",
	function () {
		updateKeepItemOption();
	}
);

function returnwc_showerror(element) {
	"use strict";
	element.addClass( "error" );
}
function returnwc_hideerror(element) {
	"use strict";
	element.removeClass( "error" );
}

jQuery( document ).on(
	"change",
	"#return_reason",
	function () {
		"use strict";

		var provider = jQuery( this ).val();
		if (provider === "other_reason") {
			jQuery( ".return_reason" ).show();
		} else {
			jQuery( ".return_reason" ).hide();
		}
	}
);

/*
 * checkbox change
 */
jQuery( document ).on(
	"change",
	".order_quantity_update",
	function () {
		"use strict";
		jQuery( ".single-method .woocommerce-Price-amount" ).css( "filter", "blur(2px)" );
		jQuery( ".product-price .woocommerce-Price-amount" ).css( "filter", "blur(2px)" );
		var data = {
			action: "fetch_return_items_data",
			order_id: jQuery( "#order_id" ).val(),
			item_id: jQuery( "#item_id" ).val(),
			qty: jQuery( ".order_quantity_number" ).val(),
			return_center_multiStep_nonce: jQuery(
				"#return_center_multiStep_nonce"
			).val(),
		};
		jQuery.ajax(
			{
				url: ajax_object.ajax_url,
				data: data,
				type: "POST",
				success: function (response) {
					if (response.success !== false) {
						if (response.data.totalHtml) {
							jQuery( ".single-method .woocommerce-Price-amount" ).replaceWith(
								response.data.totalHtml
							);
							jQuery( ".product-price .woocommerce-Price-amount" ).replaceWith(
								response.data.totalHtml
							);
						}
					} else {
						jQuery( ".single-method .woocommerce-Price-amount" ).css( "filter", "" );
						jQuery( ".product-price .woocommerce-Price-amount" ).css( "filter", "" );
					}
				},
				error: function (response) {
					console.log( response );
				},
			}
		);
	}
);

function showReturnStep(stepNumber) {
	const steps = jQuery( "#return-multi-step-form .step" ).length;
	jQuery( ".tabs" ).removeClass( "active" );
	jQuery( ".tabs" ).removeClass( "current" );
	for (let i = 1; i <= steps; i++) {
		jQuery( "#step-" + i ).hide();
		if (i < stepNumber) {
			jQuery( "#step" + i )
			.parent()
			.addClass( "active" );
		}
		if (stepNumber == i) {
			jQuery( "#step" + i )
			.parent()
			.addClass( "current" );
			jQuery( "#step-" + i ).show();
			jQuery( "#step-" + i ).start_loader();
		}
	}

	if (stepNumber == "2") {
		var formElement = document.getElementById( "return-multi-step-form" );
		var formData    = new FormData( formElement );
		var item_id     = jQuery( "#item_id" ).val();
		jQuery( "#step-3" ).start_loader();
		jQuery.ajax(
			{
				url: ajax_object.ajax_url,
				data: formData,
				type: "POST",
				processData: false,
				contentType: false,
				success: function (response) {
					if (response.success !== false) {
						if (response.data.step3Html) {
							jQuery( "#step-3" ).replaceWith( response.data.step3Html );
							jQuery(
								'.return_replace_button[data-item-id="' + item_id + '"]'
							).remove();
							jQuery( "#step-2" ).stop_loader();
							jQuery( "#step-3" ).stop_loader();
						} else {
							jQuery( "#step-2" ).stop_loader();
							jQuery( "#step-3" ).stop_loader();
						}
					} else {
						error = true;
					}
				},
				error: function (response) {
					console.log( response );
				},
			}
		);
	}
}

jQuery( document ).on(
	"click",
	"#return-multi-step-form .next-step",
	function () {
		"use strict";
		var error;
		var currentStep = jQuery( this ).data( "step" );

		if (currentStep == "1") {
			jQuery( ".select_one, .select_item, .no_agree, .select_product_item" ).hide();

			// Every item selected for return must have a quantity of at least 1.
			var $rtBoxes   = jQuery( "#return-multi-step-form .product_checked" );
			var $rtChecked = $rtBoxes.filter( ":checked" );
			if ( $rtBoxes.length ) {
				if ( $rtChecked.length === 0 ) {
					jQuery( ".select_product_item" ).show();
					error = true;
				} else {
					$rtChecked.each( function () {
						var rowQty = parseInt( jQuery( this ).closest( ".request_item_details" ).find( ".order_quantity_number" ).val(), 10 ) || 0;
						if ( rowQty < 1 ) {
							jQuery( ".select_item" ).show();
							error = true;
						}
					} );
				}
			} else {
				// No checkboxes (single-item / forced whole-order form): any
				// rendered quantity select must be at least 1.
				jQuery( "#return-multi-step-form .order_quantity_number" ).each( function () {
					if ( ( parseInt( jQuery( this ).val(), 10 ) || 0 ) < 1 ) {
						jQuery( ".select_item" ).show();
						error = true;
					}
				} );
			}

			var return_issue  = jQuery( "#return_issue" );
			var return_reason = jQuery( "#return_reason" ).val();

			if (return_reason === "other_reason") {
				var other_reason = jQuery( "#other_reason" );
				if (other_reason.val() === "") {
					returnwc_showerror( other_reason );
					error = true;
				} else {
					returnwc_hideerror( other_reason );
				}
			}
			/*if (return_issue.val() === "") {
			returnwc_showerror(return_issue);
			error = true;
			} else {
			returnwc_hideerror(return_issue);
			}*/

			var return_terms_checked = jQuery( "#return_terms:checked" ).length;
			if ( ! return_terms_checked) {
				jQuery( ".no_agree" ).show();
				error = true;
			}
		}

		if (error) {
			jQuery( "#step-2" ).stop_loader();
			return false;
		}

		showReturnStep( currentStep + 1 );
	}
);

function previewImages() {
	var preview       = document.getElementById( "preview-container" );
	preview.innerHTML = "";
	var files         = document.getElementById( "image-upload" ).files;

	for (var i = 0; i < files.length; i++) {
		var file   = files[i];
		var reader = new FileReader();

		reader.onload = function (event) {
			var img       = new Image();
			img.src       = event.target.result;
			img.className = "selected-media";
			preview.appendChild( img );
			var input   = document.createElement( "input" );
			input.type  = "hidden";
			input.value = event.target.result;
			preview.appendChild( input );
		};
		reader.readAsDataURL( file );
	}
}

jQuery( document ).on(
	"click",
	".return_media .dashicons-no-alt",
	function () {
		jQuery( this ).parent( ".preview-container" ).remove();
	}
);

jQuery( document ).on(
	"click",
	".return-request-cancel",
	function () {
		var request_id = jQuery( ".return-request-cancel" ).data( "request_id" );

		var data = {
			action: "return_request_cancel",
			request_id: request_id,
			nonce: jQuery( "return_request_cancel_nonce" ).val(),
		};

		var confirmation = window.confirm(
			"Would you like to cancel the return request?"
		);

		if (confirmation) {
			jQuery.ajax(
				{
					url: ajax_object.ajax_url,
					data: data,
					type: "POST",
					success: function (response) {
						if (response.success !== false) {
							window.location.reload();
						}
					},
					error: function (response) {
						console.log( response );
					},
				}
			);
		}
	}
);

/***********/

// Exchange Scripts

jQuery( document ).on(
	"change",
	"#exchange_reason",
	function () {
		"use strict";

		var provider = jQuery( this ).val();
		if (provider === "other_reason") {
			jQuery( ".exchange_reason" ).show();
		} else {
			jQuery( ".exchange_reason" ).hide();
		}
	}
);

function showExchangeStep(stepNumber) {
	const steps = jQuery( "#exchange-multi-step-form .step" ).length;
	jQuery( ".tabs" ).removeClass( "active" );
	jQuery( ".tabs" ).removeClass( "current" );
	for (let i = 1; i <= steps; i++) {
		jQuery( "#step-" + i ).hide();
		if (i < stepNumber) {
			jQuery( "#step" + i )
			.parent()
			.addClass( "active" );
		}
		if (stepNumber == i) {
			jQuery( "#step" + i )
			.parent()
			.addClass( "current" );
			jQuery( "#step-" + i ).show();
			jQuery( "#step-" + i ).start_loader();
		}
	}

	if (stepNumber == "2") {
		var formElement = document.getElementById( "exchange-multi-step-form" );
		var formData    = new FormData( formElement );
		var item_id     = jQuery( "#item_id" ).val();
		jQuery( "#step-3" ).start_loader();
		jQuery.ajax(
			{
				url: ajax_object.ajax_url,
				data: formData,
				type: "POST",
				processData: false,
				contentType: false,
				success: function (response) {
					if (response.success !== false) {
						if (response.data.step3Html) {
							jQuery( "#step-3" ).replaceWith( response.data.step3Html );
							jQuery(
								'.exchange_request_button[data-item-id="' + item_id + '"]'
							).remove();
							jQuery( "#step-2" ).stop_loader();
							jQuery( "#step-3" ).stop_loader();
						} else {
							jQuery( "#step-2" ).stop_loader();
							jQuery( "#step-3" ).stop_loader();
						}
					} else {
						error = true;
					}
				},
				error: function (response) {
					console.log( response );
				},
			}
		);
	}
}

jQuery( document ).on(
	"click",
	"#exchange-multi-step-form .next-step",
	function () {
		"use strict";
		var error;
		var currentStep = jQuery( this ).data( "step" );
		if (currentStep == "1") {
			jQuery( ".select_one, .select_item, .no_agree, .select_product_item" ).hide();

			// Every item selected for exchange must have a quantity of at least 1.
			var $exBoxes   = jQuery( "#exchange-multi-step-form .product_checked" );
			var $exChecked = $exBoxes.filter( ":checked" );
			if ( $exBoxes.length ) {
				if ( $exChecked.length === 0 ) {
					jQuery( ".select_product_item" ).show();
					error = true;
				} else {
					$exChecked.each( function () {
						var rowQty = parseInt( jQuery( this ).closest( ".request_item_details" ).find( ".order_quantity_number" ).val(), 10 ) || 0;
						if ( rowQty < 1 ) {
							jQuery( ".select_item" ).show();
							error = true;
						}
					} );
				}
			} else {
				// No checkboxes (single-item / forced whole-order form): any
				// rendered quantity select must be at least 1.
				jQuery( "#exchange-multi-step-form .order_quantity_number" ).each( function () {
					if ( ( parseInt( jQuery( this ).val(), 10 ) || 0 ) < 1 ) {
						jQuery( ".select_item" ).show();
						error = true;
					}
				} );
			}

			var variants = jQuery( ".product_checked:checked" ).parents( ".request_item_details" ).find( "select.variants" );
			if ( variants.length > 0 ) {
				variants.each(
					function () {
						if ( ! jQuery( this ).val()) {
							returnwc_showerror( jQuery( this ) );
							error = true;
						} else {
							returnwc_hideerror( jQuery( this ) );
						}
					}
				);
			} else {
				jQuery( "select.variants" ).each(
					function () {
						if ( ! jQuery( this ).val()) {
							returnwc_showerror( jQuery( this ) );
							error = true;
						} else {
							returnwc_hideerror( jQuery( this ) );
						}
					}
				);
			}

			// Cross-product exchange validation — one search widget PER ITEM.
			// Every item selected for exchange must have its own replacement
			// product (and a concrete variant when the replacement is variable).
			var $cpWraps = jQuery( '#exchange-multi-step-form .zre-cross-product-wrap' );
			if ( ! $cpWraps.length ) {
				$cpWraps = jQuery( '.zre-cross-product-wrap' );
			}
			if ( $cpWraps.length ) {
				var anyCpSelected = false;
				$cpWraps.each( function () {
					var $w   = jQuery( this );
					var $row = $w.closest( '.request_item_details' );
					var $chk = $row.find( '.product_checked' );
					var qty  = parseInt( $row.find( '.order_quantity_number' ).val(), 10 );

					// Skip items not selected for exchange (unchecked, or qty 0).
					var active = ( ! $chk.length || $chk.is( ':checked' ) ) && ( isNaN( qty ) || qty > 0 );
					$w.find( '.zre-cp-no-selection-msg' ).hide();
					if ( ! active ) {
						return;
					}

					var pid = ( $w.find( '.zre-cp-pid-field' ).val() || '' ).trim();
					if ( ! pid ) {
						var $msg = $w.find( '.zre-cp-no-selection-msg' );
						if ( ! $msg.length ) {
							$w.prepend( '<p class="zre-cp-no-selection-msg" style="color:#c00;font-size:13px;margin:8px 0;">Please select a replacement product for this item.</p>' );
						} else {
							$msg.show();
						}
						error = true;
						return;
					}
					anyCpSelected = true;

					// Validate variable replacement: product picked but no variant resolved.
					if ( $w.find( '.zre-cp-variation-wrap:visible' ).length ) {
						var cpVariantChosen  = ( $w.find( '.zre-cp-vid-field' ).val() || '' ).trim();
						var $variantControls = $w.find( '.zre-cp-attr-select' ).length
							? $w.find( '.zre-cp-attr-select' )
							: $w.find( '.zre-cp-variation-select' );
						if ( ! cpVariantChosen ) {
							$variantControls.css( 'border-color', '#c00' );
							error = true;
						} else {
							$variantControls.css( 'border-color', '' );
						}
					}
				} );

				if ( anyCpSelected ) {
					jQuery( '#exchange-multi-step-form .zre-cp-type-field' ).val( 'cross-product' );
					// Same-variation selects never apply in cross-product mode.
					jQuery( '#exchange-multi-step-form select.variants' ).removeAttr( 'required' );
				}
			}

			var exchange_issue  = jQuery( "#exchange_issue" );
			var exchange_reason = jQuery( "#exchange_reason" ).val();

			if (exchange_reason === "other_reason") {
				var other_reason = jQuery( "#other_reason" );
				if (other_reason.val() === "") {
					returnwc_showerror( other_reason );
					error = true;
				} else {
					returnwc_hideerror( other_reason );
				}
			}
			/*if (exchange_issue.val() === "") {
			returnwc_showerror(exchange_issue);
			error = true;
			} else {
			returnwc_hideerror(exchange_issue);
			}*/

			var exchange_terms_checked = jQuery( "#exchange_terms:checked" ).length;
			if ( ! exchange_terms_checked) {
				jQuery( ".no_agree" ).show();
				error = true;
			}
		}

		if (error) {
			jQuery( "#step-2" ).stop_loader();
			return false;
		}

		showExchangeStep( currentStep + 1 );
	}
);

/*
 * checkbox change
 */
jQuery( document ).on(
	"change",
	".variants",
	function () {
		"use strict";

		var variants = {};
		jQuery( ".exchange_variations" )
		.find( "select.variants" )
		.each(
			function () {
				returnwc_hideerror( jQuery( this ) );
				if (jQuery( this ).val()) {
					var item_id     = jQuery( this )
					.parents( ".request_item_details" )
					.find( "#item_id" )
					.val();
					var variant_key = jQuery( this ).data( "key" );
					var variant_val = jQuery( this ).val();

					if ( ! variants[item_id]) {
						variants[item_id] = {};
					}

					variants[item_id][variant_key] = variant_val;
				}
			}
		);

		jQuery.each(
			variants,
			function (item_id, variants) {
				var variantCount = Object.keys( variants ).length;
				var hiddenInput  = jQuery(
					'input[type="hidden"][value="' + item_id + '"]'
				).parents( ".request_item_details" );
				if (variantCount === hiddenInput.find( "select.variants" ).length) {
						var exchange_variations = hiddenInput.find( ".exchange_variations" );
						jQuery( ".next-step" ).attr( "disabled", true );
						jQuery( ".select_available_variation" ).hide();
						exchange_variations.find( ".variants" ).css( "filter", "blur(2px)" );

						var data = {
							action: "fetch_exchange_items_data",
							item_id: hiddenInput.find( "#item_id" ).val(),
							variation_id: hiddenInput.find( "#variation_id" ).val(),
							variants: variants,
							exchange_center_multiStep_nonce: jQuery(
								"#exchange_center_multiStep_nonce"
							).val(),
					};

						jQuery.ajax(
							{
								url: ajax_object.ajax_url,
								data: data,
								type: "POST",
								success: function (response) {
									if (response.success == false) {
										hiddenInput.find( ".select_available_variation" ).show();
										jQuery( ".variants" ).css( "filter", "" );
										jQuery( ".next-step" ).attr( "disabled", true );
									} else {
										jQuery( ".variants" ).css( "filter", "" );
										hiddenInput.find( ".select_available_variation" ).hide();
										jQuery( ".next-step" ).attr( "disabled", false );
									}
								},
								error: function (response) {
									console.log( response );
								},
							}
						);
				}
			}
		);
	}
);

jQuery( document ).on(
	"click",
	".exchange-request-cancel",
	function () {
		var request_id = jQuery( ".exchange-request-cancel" ).data( "request_id" );

		var data = {
			action: "exchange_request_cancel",
			request_id: request_id,
			nonce: jQuery( "exchange_request_cancel_nonce" ).val(),
		};

		var confirmation = window.confirm(
			"Would you like to cancel the exchange request?"
		);

		if (confirmation) {
			jQuery.ajax(
				{
					url: ajax_object.ajax_url,
					data: data,
					type: "POST",
					success: function (response) {
						if (response.success !== false) {
							window.location.reload();
						}
					},
					error: function (response) {
						console.log( response );
					},
				}
			);
		}
	}
);

jQuery( document ).on(
	"submit",
	"#return-center-form",
	function () {
		"use strict";
		var error;

		var customer_email = jQuery( ".customer_email" );
		if (customer_email.val() === "") {
			returnwc_showerror( customer_email );
			error = true;
		} else {
			returnwc_hideerror( customer_email );
		}

		var order_id = jQuery( ".order_id" );
		if (order_id.val() === "") {
			returnwc_showerror( order_id );
			error = true;
		} else {
			returnwc_hideerror( order_id );
		}

		if (error === true) {
			return false;
		}

		jQuery( ".error" ).remove();
		jQuery( ".return_center_container #return-multi-step-form" ).remove();
		jQuery( ".request-center-btn" ).attr( "disabled", true );
		jQuery( ".request-center-btn" ).start_loader();
		jQuery.ajax(
			{
				url: ajax_object.ajax_url,
				data: jQuery( "#return-center-form" ).serialize(),
				type: "POST",
				success: function (response) {
					if (response.success !== false) {
						if (response.data.return_center_order_content) {
							jQuery( "#return-center-form" ).after(
								response.data.return_center_order_content
							);
						}
						if (response.data.show_return_requested_data) {
							jQuery( "#return-center-form" ).after(
								response.data.show_return_requested_data
							);
							jQuery( "#return-multi-step-form, #return-center-form" ).hide();
							jQuery( "#step-2" ).after( response.data.show_return_requested_data );
							showStep( response.data.step );
						}
						window.history.pushState(
							window.location.href,
							"",
							"?order_id=" + jQuery( ".order_id" ).val()
						);
						jQuery( "#return-center-form" ).stop_loader();
						jQuery( ".request-center-btn" ).attr( "disabled", false );
						jQuery( "#return-center-form" ).hide();

						var element = document.getElementById( "image-upload" );
						if (element) {
								element.addEventListener( "change", previewImages );
						}
					} else {
						if (response.data.error) {
							jQuery( ".request-center-btn" ).after(
								"<span class='error'>" + response.data.error + "</span>"
							);
						}
						jQuery( "#return-center-form" ).stop_loader();
						jQuery( ".request-center-btn" ).attr( "disabled", false );
					}
				},
				error: function (response) {
					console.log( response );
					jQuery( "#return-center-form" ).stop_loader();
				},
			}
		);
		return false;
	}
);

jQuery( document ).on(
	"click",
	".cancel-form-btn",
	function () {
		jQuery(
			".return_center_container .multi-guest-container, #return-multi-step-form, #exchange-multi-step-form"
		).remove();
		jQuery( "#return-center-form" ).show();
	}
);

jQuery( document ).on(
	"click",
	".guest_exchange_request_button, .guest_return_request_button",
	function () {
		var order_id = jQuery( this ).data( "order-id" );
		var item_id  = jQuery( this ).data( "item-id" );
		var type     = jQuery( this ).data( "type" );

		var This = jQuery( this );

		jQuery( This ).start_loader();
		jQuery.ajax(
			{
				url: ajax_object.ajax_url,
				data: {
					action: "fetch_guest_return_form",
					order_id: order_id,
					item_id: item_id,
					type: type,
					nonce: jQuery( "#guest_return_center_order_nonce" ).val(),
				},
				type: "POST",
				success: function (response) {
					if (response.success !== false) {
						if (response.data.guest_return_form_content) {
							jQuery( "#return-center-form" ).after(
								response.data.guest_return_form_content
							);
							jQuery( This ).stop_loader();
							jQuery( ".return_center_container .multi-guest-container" ).remove();
						}

						var element = document.getElementById( "image-upload" );
						if (element) {
							element.addEventListener( "change", previewImages );
						}
					} else {
						if (response.data.error) {
							jQuery( ".request-center-btn" ).after(
								"<span class='error'>" + response.data.error + "</span>"
							);
						}
						jQuery( This ).stop_loader();
					}
				},
				error: function (response) {
					console.log( response );
					jQuery( This ).stop_loader();
				},
			}
		);
	}
);

/* ----------------------------------------------------------------------------
 * Restocking fee — live recalculation on the customer return form.
 * Fires whenever item selection or quantity changes; updates the summary box.
 * ---------------------------------------------------------------------------- */
(function ($) {
	"use strict";

	// True when the "Keep the Item & Receive a Partial Refund" radio is the
	// currently-selected return resolution. In that case the customer keeps the
	// product and the store owner refunds manually — the estimated-refund summary
	// does not apply and must stay hidden even if item selection triggers a
	// recalc, and even if an in-flight AJAX response arrives after the switch.
	function zoremIsKeepItemPartialSelected() {
		return $("input[name='return_data[payment_method]']:checked").val() === "keep_item_partial";
	}

	function zoremRestockingFeeRecalc() {
		var $summary = $(".zorem-restocking-fee-summary");
		if (!$summary.length) {
			return; // Feature disabled — summary box was not rendered.
		}

		// Guard: even if items are selected, the summary is not meaningful for
		// the "Keep the Item & Partial Refund" resolution.
		if (zoremIsKeepItemPartialSelected()) {
			$summary.hide();
			return;
		}

		var orderId = $("#order_id").val();
		if (!orderId) {
			return;
		}

		// Build return_items payload from currently selected products.
		var returnItems = {};
		$(".product_checked:checked").each(function () {
			var $row     = $(this).closest(".request_item_details, .item-row, tr, li").first();
			var itemId   = $(this).val();
			// Try to find a matching qty input in the same row; fallback to the hidden full-qty input.
			var $qtyInput = $row.find("[name='return_data[return_items][" + itemId + "][return_product_qty]']").first();
			var qty       = $qtyInput.length ? parseInt($qtyInput.val(), 10) : 0;
			if (qty > 0) {
				returnItems[itemId] = { return_product_qty: qty };
			}
		});

		// If no items selected, hide summary.
		if ($.isEmptyObject(returnItems)) {
			$summary.hide();
			return;
		}

		var nonce = $("[name='return_center_multiStep_nonce']").val();
		$.ajax({
			url:  ajax_object.ajax_url,
			type: "POST",
			data: {
				action:        "zorem_calculate_restocking_fee",
				order_id:      orderId,
				return_items:  returnItems,
				nonce:         nonce
			},
			success: function (resp) {
				if (!resp || !resp.success || !resp.data) {
					return;
				}
				// Re-check at response time — the user may have switched to
				// keep_item_partial while this request was in flight. Without this
				// guard the response would re-show a box we just hid.
				if (zoremIsKeepItemPartialSelected()) {
					$summary.hide();
					return;
				}
				var d = resp.data;
				$summary.find(".zorem-rf-total-refund").html(d.total_refund);
				$summary.find(".zorem-rf-net").html("<strong>" + d.net_refund + "</strong>");
				if (d.has_fee) {
					$summary.find(".zorem-rf-fee").html("&minus; " + d.total_fee);
					$summary.find(".zorem-rf-fee-row").show();
				} else {
					$summary.find(".zorem-rf-fee-row").hide();
				}
				$summary.show();
			}
		});
	}

	// Recalculate on item check/uncheck and on qty change.
	$(document).on("change", ".product_checked, .order_quantity_update", function () {
		// Slight delay so the existing fetch_return_items_data handler completes first.
		setTimeout(zoremRestockingFeeRecalc, 50);
	});

	// "Keep the Item & Partial Refund" resolution — hide the Refund Summary
	// because the customer keeps the product and the store owner will issue the
	// partial refund manually from the WC Edit Order page. The estimated-refund
	// numbers do not apply to that path and are misleading. Switching back to
	// a different resolution re-runs the recalc so the summary reappears.
	$(document).on("change", "input[name='return_data[payment_method]']", function () {
		if ($(this).val() === "keep_item_partial") {
			$(".zorem-restocking-fee-summary").hide();
		} else {
			setTimeout(zoremRestockingFeeRecalc, 50);
		}
	});

	// Initial render once the form is in view.
	$(function () {
		setTimeout(zoremRestockingFeeRecalc, 200);
	});
})(jQuery);
