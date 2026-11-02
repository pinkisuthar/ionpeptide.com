/* rt4wc_snackbar jquery */
(function ( $ ) {
	$.fn.rt4wc_snackbar = function (msg) {
		if ( jQuery( '.snackbar-logs' ).length === 0 ) {
			$( "body" ).append( "<section class=snackbar-logs></section>" );
		}
		var rt4wc_snackbar = $( "<article></article>" ).addClass( 'snackbar-log snackbar-log-success snackbar-log-show' ).text( msg );
		$( ".snackbar-logs" ).append( rt4wc_snackbar );
		setTimeout(
			function () {
				rt4wc_snackbar.remove(); },
			3000
		);
		return this;
	};
})( jQuery );

/* rt4wc_snackbar_warning jquery */
(function ( $ ) {
	$.fn.rt4wc_snackbar_warning = function (msg) {
		if ( jQuery( '.snackbar-logs' ).length === 0 ) {
			$( "body" ).append( "<section class=snackbar-logs></section>" );
		}
		var rt4wc_snackbar_warning = $( "<article></article>" ).addClass( 'snackbar-log snackbar-log-error snackbar-log-show' ).html( msg );
		$( ".snackbar-logs" ).append( rt4wc_snackbar_warning );
		setTimeout(
			function () {
				rt4wc_snackbar_warning.remove(); },
			3000
		);
		return this;
	};
})( jQuery );


/*header script*/
jQuery( document ).on(
	"click",
	"#activity-panel-tab-help",
	function () {
		"use strict";
		jQuery( this ).addClass( "is-active" );
		jQuery( ".woocommerce-layout__activity-panel-wrapper" ).addClass(
			"is-open is-switching"
		);
	}
);

jQuery( document ).click(
	function (event) {
		"use strict";
		var $trigger = jQuery( ".woocommerce-layout__activity-panel" );
		if ($trigger !== event.target && ! $trigger.has( event.target ).length) {
			jQuery( "#activity-panel-tab-help" ).removeClass( "is-active" );
			jQuery( ".woocommerce-layout__activity-panel-wrapper" ).removeClass(
				"is-open is-switching"
			);
		}
	}
);

jQuery( document ).ready(
	function () {
		'use strict';
		jQuery( ".tipTip" ).tipTip();
		showAllProducts();
	}
);

jQuery( document ).on(
	"input",
	"#return_policy_days",
	function () {
		if (jQuery( this ).val() > 90) {
			jQuery( this ).val( 90 );
		}
	}
);

jQuery( document ).on(
	"click",
	".zorem_return_tab_name .zorem_plugin_tab_input",
	function () {
		"use strict";
		var tab   = jQuery( this ).data( 'tab' );
		var label = jQuery( this ).data( 'label' );
		jQuery( '.zorem-layout__header .header-breadcrumbs-last' ).text( label );
		var url = window.location.protocol + "//" + window.location.host + window.location.pathname + "?page=zorem-returns-exchanges&tab=" + tab;
		window.history.pushState( {path:url},'',url );
		jQuery( '.inner_tab_section' ).hide();
		jQuery( '.inner_tab_section#content_return_' + tab ).show();
		jQuery( window ).trigger( 'resize' );
	}
);

// Function to get content from TinyMCE editor
function getEditorContent( editor_id ) {
	// Check if TinyMCE is active and get the content from the editor
	if (typeof tinymce !== 'undefined') {
		let editor = tinymce.get( editor_id );
		if (editor && editor instanceof tinymce.Editor) {
			return editor.getContent();
		}
	}
	// Fallback to get content from the textarea
	return jQuery( '#' + editor_id ).val();
}

/*ajax call for settings tab form save*/
jQuery( document ).on(
	"click",
	".return_settings_update_btn .button-return",
	function () {
		"use strict";
		var spinner = jQuery( this ).parent().find( ".spinner" ).addClass( "active" );
		var form    = jQuery( ".return_plugin_setting_tab_form" );

		var store_credit     = jQuery( "#store_credit" ).prop( 'checked' );
		var customer_account = jQuery( "#customer_account" ).prop( 'checked' );

		jQuery( "#store_credit, #customer_account" ).css( 'border-color', '' );
		if ( ! customer_account && ! store_credit ) {
			jQuery( "#store_credit, #customer_account" ).css( 'border-color', 'red' );
			jQuery( document ).rt4wc_snackbar_warning( "Please choose refund payment option." );
			var spinner = jQuery( this ).parent().find( ".spinner" ).removeClass( "active" );
			return false;
		}

		var returnEditorContent   = getEditorContent( 'return_guideline_content' );
		var exchangeEditorContent = getEditorContent( 'exchange_guideline_content' );
		var formEditorContent     = '&return_guideline_content=' + returnEditorContent + '&exchange_guideline_content=' + exchangeEditorContent;

		jQuery( "#todo-list li .dashicons-no-alt" ).trigger( "click" );

		jQuery.ajax(
			{
				url: ajaxurl,
				data: form.serialize() + formEditorContent,
				type: 'POST',
				dataType:"json",
				success: function (response) {
					spinner.removeClass( "active" );
					jQuery( document ).rt4wc_snackbar( "Settings Successfully Saved." );
				},
				error: function (response, jqXHR, exception) {
					console.log( response );
					spinner.removeClass( "active" );
					var warning_msg = '';
					if (jqXHR.status === 0) {
						warning_msg = 'Not connect.\n Verify Network.';
					} else if (jqXHR.status === 404) {
						warning_msg = 'Requested page not found. [404]';
					} else if (jqXHR.status === 500) {
						warning_msg = 'Internal Server Error [500].';
					} else if (exception === 'parsererror') {
						warning_msg = 'Requested JSON parse failed.';
					} else if (exception === 'timeout') {
						warning_msg = 'Time out error.';
					} else if (exception === 'abort') {
						warning_msg = 'Ajax request aborted.';
					} else {
						warning_msg = 'Uncaught Error.\n' + jqXHR.responseText;
					}
					jQuery( document ).rt4wc_snackbar_warning( warning_msg );
				}
			}
		);
		return false;
	}
);

// Create a new list item when clicking on the "Add" button
function newElement( key ) {
	var li         = document.createElement( "li" );
	var inputValue = document.getElementById( key ).value;

	if (inputValue === '') {
		alert( "You must write something!" );
	} else {
		document.getElementById( key + "_todo" ).appendChild( li );

		// Add dashicons-menu-alt span
		var Span       = document.createElement( "SPAN" );
		Span.className = "dashicons dashicons-menu-alt sorting-handler";
		li.appendChild( Span );
		// Add hidden INPUT
		var INPUT  = document.createElement( "INPUT" );
		INPUT.type = 'hidden';
		INPUT.setAttribute( "type", "hidden" );
		INPUT.setAttribute( "name", key + "[]" );
		INPUT.setAttribute( "value", inputValue );
		li.appendChild( INPUT );
		// Add reason text span
		var span             = document.createElement( "SPAN" );
		var txt              = document.createTextNode( inputValue );
		span.className       = "reason_text";
		span.contentEditable = true;
		span.appendChild( txt );
		li.appendChild( span );
		// Add dashicons-edit span
		var Span       = document.createElement( "SPAN" );
		Span.className = "dashicons dashicons-edit";
		li.appendChild( Span );
		// Add dashicons-trash span
		var Span       = document.createElement( "SPAN" );
		Span.className = "dashicons dashicons-trash";
		li.appendChild( Span );
	}
	document.getElementById( key ).value = "";

}

// Create a new list item when clicking on the "Add" button
jQuery( document ).on(
	"click",
	".todo-list li .dashicons-trash",
	function () {
		jQuery( this ).parents( 'li' ).remove();
	}
);

jQuery( document ).on(
	"click",
	".todo-list li .dashicons-edit",
	function () {
		jQuery( ".todo-list li .dashicons-no-alt" ).trigger( "click" );
		jQuery( this ).parents( 'li' ).children( ".reason_text" ).addClass( "editable" );
		jQuery( this ).parents( 'li' ).children( ".reason_text" ).attr( "contenteditable", true );
		jQuery( this ).addClass( "dashicons-no-alt" ).removeClass( "dashicons-edit" );
	}
);

jQuery( document ).on(
	"click",
	".todo-list li .dashicons-no-alt",
	function () {
		jQuery( this ).parents( 'li' ).children( ".reason_text" ).removeClass( "editable" );
		jQuery( this ).parents( 'li' ).children( ".reason_text" ).attr( "contenteditable", false );
		jQuery( this ).addClass( "dashicons-edit" );
		jQuery( this ).removeClass( "dashicons-no-alt" );
	}
);

jQuery( '.todo-list' ).sortable(
	{
		handle: '.sorting-handler',
		axis: 'y',
		helper: function (e, ui) {
			ui.children().each(
				function () {
					jQuery( this ).width( jQuery( this ).width() );
				}
			);
			return ui;
		},
		stop: function () {
		},
	}
);

jQuery( document ).on(
	"keyup",
	".reason_text",
	function () {
		jQuery( this ).parent().children( "input" ).val( jQuery( this ).text() );
	}
);

jQuery( document ).on(
	"submit",
	'#return-licence-form',
	function (e) {
		e.preventDefault();
		var licence_form   = jQuery( this );
		var action         = licence_form.find( ".licence_action" ).val();
		var data           = licence_form.serialize();
		var licence_button = licence_form.find( ".licence_submit" );

		jQuery( "#return-licence-form" ).block(
			{
				message: null,
				overlayCSS: {
					background: "#fff",
					opacity: .6
				}
			}
		);

		jQuery.ajax(
			{
				type: "POST",
				url : ajaxurl,
				data: data,
				beforeSend: function () {
					licence_button.prop( 'disabled', true ).val( '...' );
				},
				success : function (data) {
					var btn_value = 'Deactivate';
					if ( data.success === true ) {
						if ( action === 'return_license_activate' ) {
							jQuery( licence_button ).css( 'backgroundColor', '#395da4' );
							jQuery( licence_button ).css( 'color', '#fff' );
							licence_form.find( ".licence_action" ).val( 'return_license_deactivate' );
							jQuery( "#return-licence-form" ).unblock();
							window.location.reload();
						} else {
							jQuery( 'a.deactivate' ).hide();
							licence_form.find( ".licence_action" ).val( 'return_license_activate' );
							licence_form.find( "#license_key" ).val( '' );
							jQuery( "#return-licence-form" ).unblock();
							window.location.reload();
						}
					} else if ( data.success === false ) {
						jQuery( 'a.deactivate' ).html( '<span style="color:red;line-height:2;">' + data.error + '</span>' );
					} else {
						jQuery( 'a.deactivate' ).html( '<span style="color:red;line-height:2;">' + data.message + '</span>' );// '+data.message+'
					}

					// licence_button.prop('disabled', false).val(btn_value);
				},
				error: function (data) {
					console.log( data );
				}
			}
		);

	}
);

jQuery( document ).on(
	"click",
	".accordion",
	function () {
		"use strict";
		if (jQuery( this ).next( '.panel' ).hasClass( 'active' )) {
				jQuery( ".accordion" ).css( 'border-color','' );
			jQuery( ".accordion" ).removeClass( 'active' );
			jQuery( ".accordion" ).next( '.panel' ).removeClass( 'active' ).slideUp( "slow" );
			jQuery( ".accordion" ).css( 'cursor', '' );
			jQuery( ".accordion" ).find( '.return_settings_update_btn' ).hide();
			jQuery( ".accordion" ).find( 'label span.dashicons' ).addClass( 'dashicons-arrow-right-alt2' );
			jQuery( ".accordion" ).find( 'label' ).css( 'color','' );
		} else {
			jQuery( ".accordion" ).css( 'border-color','' );
			jQuery( ".accordion" ).removeClass( 'active' );
			jQuery( ".accordion" ).next( '.panel' ).removeClass( 'active' ).slideUp( "slow" );
			jQuery( ".accordion" ).css( 'cursor', '' );
			jQuery( ".accordion" ).find( '.return_settings_update_btn' ).hide();
			jQuery( ".accordion" ).find( 'label span.dashicons' ).addClass( 'dashicons-arrow-right-alt2' );
			jQuery( ".accordion" ).find( 'label' ).css( 'color','' );
			jQuery( this ).addClass( 'active' );
			jQuery( this ).css( 'cursor', 'default' );
			jQuery( this ).find( '.return_settings_update_btn' ).show();
			jQuery( this ).find( 'label span.dashicons' ).removeClass( 'dashicons-arrow-right-alt2' );
			jQuery( this ).find( 'label' ).css( 'color','#212121' );
			jQuery( this ).next( '.panel' ).addClass( 'active' ).slideDown( 'slow' );
			showAllProducts();
		}
	}
);

/*ajax call for settings tab form save*/
jQuery( document ).on(
	"change",
	".notifications-status .tgl-flat-rt4wc",
	function () {
		"use strict";

		jQuery.ajax(
			{
				url: ajaxurl,
				data: jQuery( '#email_notifications_status' ).serialize(),
				type: 'POST',
				dataType:"json",
				success: function (response) {
					jQuery( document ).rt4wc_snackbar( "Settings Successfully Saved." );
				},
				error: function (response, jqXHR, exception) {
					console.log( response );
					jQuery( document ).rt4wc_snackbar_warning( warning_msg );
				}
			}
		);
		return false;
	}
);

function showAllProducts() {
	jQuery( "#return_exclude_products" ).select2(
		{
			ajax: {
				url: ajaxurl, // WordPress AJAX URL
				dataType: "json",
				delay: 250,
				data: function (params) {
					return {
						action: "rt4wc_fetch_products", // AJAX action name
						batch_size: 10, // Batch size
						offset: (params.page || 1) * 10, // Calculate offset based on current page
						search: params.term,
					};
				},
				processResults: function (data, params) {
					params.page = params.page || 1;
					return {
						results: jQuery.map(
							data.items || data,
							function (item) {
								return {
									id: item.product_id,
									text: item.product_name,
									selected: item.selected,
								};
							}
						),
					pagination: {
						more: params.page * 10 < (data.total_count || 0), // Check if more results exist
						},
					};
				},
				cache: true,
			},
			placeholder: "Select a product",
			minimumInputLength: 0,
			multiple: true,
		}
	);
}


/* -------------------------------------------------------
 * ZUI sidebar + sub-tab switching for the Settings and
 * Notifications pages.
 * ----------------------------------------------------- */
/* -------------------------------------------------------
 * ZUI wiring for the Zorem Returns settings shell.
 *
 * Uses the library APIs:
 *   ZUI.snackbar(msg, {type})        — top-right toast
 *   ZUI.btnSaving(btn) / btnSaved()  — Saving…/Saved button states
 *   data-zui-multiselect             — auto-inits multi-selects
 *   #zui-lic-grid / #zui-lic-search  — auto-inits license filter/search
 * ----------------------------------------------------- */
jQuery(function ($) {

	function snack(msg, type) {
		if (window.ZUI && typeof ZUI.snackbar === 'function') {
			ZUI.snackbar(msg, { type: type || 'success' });
			return;
		}
		// Fallback to plugin's legacy snackbar.
		if (type === 'error' && typeof $.fn.rt4wc_snackbar_warning === 'function') {
			$(document).rt4wc_snackbar_warning(msg);
		} else if (typeof $.fn.rt4wc_snackbar === 'function') {
			$(document).rt4wc_snackbar(msg);
		}
	}

	function btnSaving($btn) {
		var el = $btn[0];
		if (window.ZUI && typeof ZUI.btnSaving === 'function' && el) {
			ZUI.btnSaving(el);
		} else {
			$btn.addClass('is-saving');
		}
	}
	function btnReset($btn) {
		var el = $btn[0];
		if (window.ZUI && typeof ZUI.btnReset === 'function' && el) {
			ZUI.btnReset(el);
		} else {
			$btn.removeClass('is-saving is-saved');
		}
	}

	// ----- Sidebar item click (Settings sub-nav) ---------------------
	$(document).on('click', '.rt4wc-settings-app .zui-sidebar__item', function () {
		var slug = $(this).data('section');
		if (!slug) return;
		var $layout = $(this).closest('.zui-layout');
		$layout.find('.zui-sidebar__item').removeClass('is-active').removeAttr('aria-current');
		$(this).addClass('is-active').attr('aria-current', 'true');
		$layout.find('.zui-section[data-section]').attr('hidden', true).removeClass('is-active');
		$layout.find('.zui-section[data-section="' + slug + '"]').removeAttr('hidden').addClass('is-active');
	});

	// ----- Notifications sub-tabs (Customer | Admin) -----------------
	$(document).on('click', '.rt4wc-notif-subtabs .zui-tabs__item', function () {
		var target = $(this).data('subtarget');
		if (!target) return;
		var $section = $(this).closest('.zui-section');
		$section.find('.rt4wc-notif-subtabs .zui-tabs__item').removeClass('is-active').attr('aria-selected', 'false');
		$(this).addClass('is-active').attr('aria-selected', 'true');
		$section.find('.rt4wc-notif-subpanel').hide();
		$('#' + target).show();
	});

	// ----- Notification toggle → live badge + AJAX save --------------
	$(document).on('change', '.rt4wc-notif-item__input', function () {
		var $badge = $(this).closest('.rt4wc-notif-item').find('.rt4wc-notif-item__status');
		if (this.checked) {
			$badge.text('ACTIVE').removeClass('is-inactive').addClass('is-active');
		} else {
			$badge.text('INACTIVE').removeClass('is-active').addClass('is-inactive');
		}

		var $form = $('#email_notifications_status');
		if (!$form.length) return;

		$.ajax({
			url: (typeof ajaxurl !== 'undefined') ? ajaxurl : window.ajaxurl,
			type: 'POST',
			data: $form.serialize(),
			dataType: 'json'
		}).done(function () {
			snack('Data saved successfully', 'success');
		}).fail(function () {
			snack('Failed to save data', 'error');
		});
	});

	// ----- Top-tab switch WITHOUT page reload ------------------------
	$(document).on('click', '.rt4wc-settings-app .zui-tabs__item[data-tab]', function (e) {
		var $link = $(this);
		var slug  = $link.data('tab');
		if (!slug) return;
		var validTabs = ['settings', 'return-emails', 'ai-assistant', 'license', 'dashboard', 'warranty-claims'];
		if (validTabs.indexOf(slug) === -1) return;
		var $app = $link.closest('.rt4wc-settings-app');
		var $panel = $app.find('.zui-tab-panel[data-tab-panel="' + slug + '"]');
		if (!$panel.length) return; // Panel not rendered on this view (e.g. Dashboard ⇄ Settings) — navigate normally.
		e.preventDefault();
		$app.find('.zui-tabs__item[data-tab]').removeClass('is-active').removeAttr('aria-current');
		$link.addClass('is-active').attr('aria-current', 'page');
		$app.find('.zui-tab-panel').attr('hidden', true).removeClass('is-active');
		$panel.removeAttr('hidden').addClass('is-active');
		if (window.history && window.history.replaceState) {
			var href = $link.attr('href');
			if (href) {
				window.history.replaceState({}, '', href);
			} else {
				// Dashboard / Warranty tabs are <button>s without href — update the
				// URL query string manually so the current tab survives a reload.
				var params = new URLSearchParams(window.location.search);
				if (slug === 'dashboard') {
					params.delete('tab');
				} else if (slug === 'warranty-claims') {
					params.set('tab', 'warranty-claims');
				}
				var qs = params.toString();
				window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
			}
		}
	});

	// ----- Save button (settings form) — Saving… / snackbar ---------
	$(document).on('click', '.rt4wc-settings-form .zui-savebtn', function (e) {
		var $btn  = $(this);
		var $form = $btn.closest('form.rt4wc-settings-form');
		if (!$form.length) return;
		if ($btn.hasClass('is-saving')) return;

		e.preventDefault();
		btnSaving($btn);

		$.ajax({
			url: (typeof ajaxurl !== 'undefined') ? ajaxurl : window.ajaxurl,
			type: 'POST',
			data: $form.serialize(),
			dataType: 'json'
		}).done(function () {
			snack('Data saved successfully', 'success');
		}).fail(function () {
			snack('Failed to save data', 'error');
		}).always(function () {
			btnReset($btn);
		});
	});

	// ----- Fraud Prevention: conditional field visibility ------------
	function rt4wcSyncFraudVisibility() {
		var $master  = $('.fraud_prevention_enable_toggle');
		var $limit   = $('.fraud_prevention_limit_enable_toggle');
		var $notify  = $('.fraud_prevention_admin_notify_toggle');
		var $body        = $('[data-rt4wc-fp-body]');
		var $limitBody   = $('[data-rt4wc-fp-limit-body]');
		var $notifyBody  = $('[data-rt4wc-fp-admin-body]');

		var masterOn = $master.length ? $master.is(':checked') : true;
		$body.toggle(masterOn);

		var limitOn = $limit.length ? $limit.is(':checked') : false;
		$limitBody.toggle(masterOn && limitOn);

		var notifyOn = $notify.length ? $notify.is(':checked') : false;
		$notifyBody.toggle(masterOn && notifyOn);
	}
	$(document).on('change', '.fraud_prevention_enable_toggle, .fraud_prevention_limit_enable_toggle, .fraud_prevention_admin_notify_toggle', rt4wcSyncFraudVisibility);

	// ----- Warranty: conditional field visibility --------------------
	function rt4wcSyncWarrantyVisibility() {
		var $master = $('.warranty_enable_toggle');
		var on = $master.length ? $master.is(':checked') : true;
		$('[data-rt4wc-warranty-body]').toggle(on);
	}
	$(document).on('change', '.warranty_enable_toggle', rt4wcSyncWarrantyVisibility);

	// ----- Restocking Fee: conditional field visibility -------------
	// When "Enable Restocking Fee" is off, hide the three dependent fields:
	// Restocking Fee Type / Amount / Label. Rows are wrapped by the ZUI
	// `get_html()` renderer as `[data-field="KEY"]`.
	function rt4wcSyncRestockingFeeVisibility() {
		var $master = $('.restocking_fee_enable_toggle');
		var on = $master.length ? $master.is(':checked') : true;
		$('[data-field="restocking_fee_type"], [data-field="restocking_fee_amount"], [data-field="restocking_fee_label"]').toggle(on);
	}
	$(document).on('change', '.restocking_fee_enable_toggle', rt4wcSyncRestockingFeeVisibility);

	// ----- Keep-Item Partial Refund: hide % field unless the checkbox is ticked ----
	// The "Keep the Item & Receive a Partial Refund" option lives inside the
	// `refund_payment_options[keep_item_partial]` multi-checkbox row, so we
	// listen on that specific checkbox by name. When the % field is visible we
	// also add `.refund-payment-open` on the refund_payment_options row so the
	// CSS can drop the divider between the two rows — they visually belong to
	// the same group.
	function rt4wcSyncKeepItemPartialVisibility() {
		var $cb = $('input[type="checkbox"][name="refund_payment_options[keep_item_partial]"]');
		var on  = $cb.length ? $cb.is(':checked') : false;
		$('[data-field="keep_item_partial_refund_percentage"]').toggle(on);
		$('[data-field="refund_payment_options"]').toggleClass('refund-payment-open', on);
	}
	$(document).on('change', 'input[type="checkbox"][name="refund_payment_options[keep_item_partial]"]', rt4wcSyncKeepItemPartialVisibility);

	// Initial sync on page load.
	$(function () {
		rt4wcSyncFraudVisibility();
		rt4wcSyncWarrantyVisibility();
		rt4wcSyncRestockingFeeVisibility();
		rt4wcSyncKeepItemPartialVisibility();
	});

	// ----- Fraud Prevention: Bulk Upload to Blocklist ----------------
	$(document).on('click', '#zorem_blocklist_csv_upload_btn', function (e) {
		e.preventDefault();
		var $btn    = $(this);
		var $file   = $('#zorem_blocklist_csv_file');
		var $result = $('#zorem_blocklist_csv_result');
		var $spin   = $('#zorem_blocklist_csv_spinner');
		var files   = $file[0] ? $file[0].files : null;

		if (!files || !files.length) {
			$result.css('color', '#b91c1c').text('Please choose a CSV or TXT file first.');
			return;
		}

		var fd = new FormData();
		fd.append('action', 'zorem_upload_blocklist_csv');
		fd.append('zorem_blocklist_csv_nonce', $('#zorem_blocklist_csv_nonce').val());
		fd.append('blocklist_csv', files[0]);

		$spin.addClass('is-active');
		$btn.prop('disabled', true);
		$result.css('color', '').text('');

		$.ajax({
			url:         (typeof ajaxurl !== 'undefined') ? ajaxurl : window.ajaxurl,
			type:        'POST',
			data:         fd,
			dataType:    'json',
			processData: false,
			contentType: false
		}).done(function (resp) {
			if (!resp || !resp.success) {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Upload failed.';
				$result.css('color', '#b91c1c').text(msg);
				return;
			}
			var d = resp.data || {};
			$result.css('color', '#065f46').text(d.message || 'Blocklist updated.');
			// Refresh the visible blocklist textarea with the new merged list.
			if (typeof d.new_blocklist === 'string') {
				$('textarea.fraud_prevention_blocklist_field').val(d.new_blocklist);
			}
			// Clear the file input so subsequent uploads pick up a fresh selection.
			$file.val('');
		}).fail(function () {
			$result.css('color', '#b91c1c').text('Request failed. Please try again.');
		}).always(function () {
			$spin.removeClass('is-active');
			$btn.prop('disabled', false);
		});
	});

	// ----- Fraud Prevention: Check Customer Return Count -------------
	$(document).on('click', '#zorem_fp_check_btn', function (e) {
		e.preventDefault();
		var $btn    = $(this);
		var lookup  = ($('#zorem_fp_lookup').val() || '').trim();
		var $result = $('#zorem_fp_check_result');
		var $spin   = $('#zorem_fp_check_spinner');

		if (!lookup) {
			$result.removeAttr('hidden').html('<div class="rt4wc-fp-check-result__err">' +
				'Enter a customer email or numeric user ID.' + '</div>');
			return;
		}

		$spin.addClass('is-active');
		$btn.prop('disabled', true);
		$result.attr('hidden', true).empty();

		$.ajax({
			url: (typeof ajaxurl !== 'undefined') ? ajaxurl : window.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'zorem_fraud_diagnostic',
				zorem_fraud_diagnostic_nonce: $('#zorem_fp_diagnostic_nonce').val(),
				lookup: lookup
			}
		}).done(function (resp) {
			if (!resp || !resp.success) {
				var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Lookup failed.';
				$result.removeAttr('hidden').html('<div class="rt4wc-fp-check-result__err">' +
					$('<div/>').text(msg).html() + '</div>');
				return;
			}
			var d = resp.data || {};
			var blocked = d.would_be_blocked ? 'YES' : 'NO';
			var html = '<div class="rt4wc-fp-check-result__ok">' +
				'<div><strong>Customer:</strong> ' + $('<i/>').text(d.email || ('#' + (d.user_id || 0))).html() + '</div>' +
				'<div><strong>Returns counted (last ' + d.window_days + ' days):</strong> ' + d.counted_now + ' / ' + d.max_returns + '</div>' +
				'<div><strong>On blocklist:</strong> ' + (d.on_blocklist ? 'YES' : 'NO') + '</div>' +
				'<div><strong>Would be blocked now:</strong> ' + blocked + '</div>' +
				'</div>';
			$result.removeAttr('hidden').html(html);
		}).fail(function () {
			$result.removeAttr('hidden').html('<div class="rt4wc-fp-check-result__err">' +
				'Request failed. Please try again.' + '</div>');
		}).always(function () {
			$spin.removeClass('is-active');
			$btn.prop('disabled', false);
		});
	});

	// ----- License page: Telemetry Save button ----------------------
	$(document).on('click', '.zui-lic-savebtn', function () {
		var $btn = $(this);
		var targetSel = $btn.data('target');
		if (!targetSel) return;
		var $form = $(targetSel);
		if (!$form.length) return;
		if ($btn.hasClass('is-saving')) return;

		btnSaving($btn);
		$.ajax({
			url: (typeof ajaxurl !== 'undefined') ? ajaxurl : window.ajaxurl,
			type: 'POST',
			data: $form.serialize(),
			dataType: 'json'
		}).done(function () {
			snack('Data saved successfully', 'success');
		}).fail(function () {
			snack('Failed to save data', 'error');
		}).always(function () {
			btnReset($btn);
		});
	});
});
