/*header script*/
jQuery(document).on("click", "#activity-panel-tab-help", function () {
	"use strict";
	jQuery(this).addClass("is-active");
	jQuery(".woocommerce-layout__activity-panel-wrapper").addClass(
		"is-open is-switching"
	);
});

jQuery(document).click(function (event) {
	"use strict";
	var $trigger = jQuery(".woocommerce-layout__activity-panel");
	if ($trigger !== event.target && !$trigger.has(event.target).length) {
		jQuery("#activity-panel-tab-help").removeClass("is-active");
		jQuery(".woocommerce-layout__activity-panel-wrapper").removeClass(
			"is-open is-switching"
		);
	}
});


jQuery(document).ready(function () {
	"use strict";
	var url;
	var $table = jQuery("#rt4wc_fullfilments_table").DataTable({
		dom: "Bfrti<'datatable_footer'pl>",
		searching: false,
		fixedHeader: true,
		"processing": true,
		"serverSide": true,
		"pagingType": "simple",
		language: {
			emptyTable:   "No return or exchange requests found.",
			zeroRecords:  "No matching orders — try a different search term.",
			processing:   "Loading…",
			paginate: { previous: "Previous", next: "Next" }
		},
		buttons: [
			{
				extend: 'csvHtml5',
				title: 'Return_exchange_orders',
				exportOptions: {
					columns: [0, 1, 2, 3, 4, 5, 7],
					orthogonal: 'export'
				}
			}
		],
		"order": [[1, "desc"]],
		"ajax": {
			"type": "POST",
			"url": ajaxurl + "?action=get_return_orders",
			"data": function (d) {
				d.ajax_nonce = jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val();
				d.unfulfilled_order_status = jQuery("#return_orders_content #unfulfilled_order_status").val();
				d.fulfillment_filter = jQuery("#return_orders_content #rt4wc_fulfillment_filter").val();
				d.fulfillment_search_input = jQuery("#return_orders_content #rt4wc_fulfillment_search_input").val();
			},
		},
		"lengthMenu": [[25, 50, -1], [25, 50, "All"]],
		"pageLength":25,
		"drawCallback": function(settings) {
			jQuery(window).resize();
		},

		"columns": [
			{
				"width": 80,
				"orderable": false,
				"data": "request_id",
				"className": "text-center",
			},
			{
				"width": 125,
				"orderable": false,
				"data": "order_date",
			},
			{
				"width": 80,
				"orderable": false,
				"mRender": function (data, type, full) {
					var parentOrderLink = rt4wc_fulfillment_script.admin_url + "post.php?post=" + full.order_id + '&action=edit';
					var parentOrderHtml = '<a href="' + parentOrderLink + '">' + full.order_number + '</a>';

					// Check if a child order ID (from the new PHP field) exists
					if (full.child_order_id && full.child_order_id != '') {

						var childOrderLink = rt4wc_fulfillment_script.admin_url + "post.php?post=" + full.child_order_id + '&action=edit';
						// Prefer the customer-facing number (Sequential Order Numbers etc.).
						// Fall back to raw ID when older payloads don't include it.
						var childOrderDisplay = (full.child_order_number !== undefined && full.child_order_number !== null && full.child_order_number !== '')
							? full.child_order_number
							: full.child_order_id;
						var childOrderHtml = '<a href="' + childOrderLink + '">' + childOrderDisplay + '</a>';
						return parentOrderHtml + ' → ' + childOrderHtml;
					}

					return parentOrderHtml;
				},
			},
			{
				"width": 150,
				"orderable": false,
				"data": "order_status",
			},
			{
				"width": 80,
				"orderable": false,
				"data": "type",
			},
			{
				"width": 150,
				"orderable": false,
				"data": "customer",
			},
			{
				"width": 80,
				"orderable": false,
				"data": "item_count",
			},
			{
				"width": 100,
				"orderable": false,
				"data": "attachments",
				"className": "view-attachments",
					mRender: function (data, type, full) {
					if (type === 'display') {
						return data?.html || '';
					}

					if (type === 'export') {
						if (!data?.urls) return '';

						let urls = data.urls;

						if (Array.isArray(urls)) {
							return urls.join(', ');
						} else if (typeof urls === 'string') {
							return urls;
						} else if (typeof urls === 'object') {
							try {
								return Object.values(urls).join(', ');
							} catch (e) {
								return String(urls);
							}
						} else {
							return '';
						}
					}

					return data?.html ? jQuery('<div>').html(data.html).text().trim() : '';
				}
			},
			{
				"width": 80,
				"orderable": false,
				"data": "actions_html",
				"className": "text-right",
			},
		],
	});

	jQuery("#return_orders_content #rt4wc_fulfillment_filter").change(function () {
		$table.ajax.reload();
	});
	
	jQuery("#return_orders_content #rt4wc_fulfillment_search_submit").click(function () {
		$table.ajax.reload();
	});



	var label = jQuery('input.tab_input:checked').data('label');
	jQuery('.breadcums_page_heading').text(label);

	jQuery(document).on(
		"click",
		".return_approved_button, .return_rejected_button, .return_delete_icon, .return_completed_button",
		function () {

			if ( 'return-delete' == jQuery(this).attr("status") ) {
				var confirmation = window.confirm("Would you like to delete the request?");
			} else if ( 'return-completed' == jQuery(this).attr("status") ) {
				var confirmation = window.confirm("Would you like to mark this return as completed? Stock will be restocked.");
			} else {
				var confirmation = window.confirm("Would you like to update the status?");
			}
			
			if ( !confirmation ) {
				return false;
			}

			var returnStatus = jQuery(this).attr("status");
			var requestId = jQuery(this).attr("id");
			var returnNote = jQuery("#return_note").val();
			var nonce = jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val();
			var restockingFee = jQuery("#restocking_fee_amount").length ? jQuery("#restocking_fee_amount").val() : "";

			// Use FormData for approval to support PDF upload
			if ( 'return-approved' == returnStatus ) {
				var formData = new FormData();
				formData.append('ID', requestId);
				formData.append('return_status', returnStatus);
				formData.append('return_note', returnNote || '');
				formData.append('nonce', nonce);
				if ( restockingFee !== "" ) {
					formData.append('restocking_fee_amount', restockingFee);
				}

				var pdfInput = document.getElementById('return_label_pdf');
				if ( pdfInput && pdfInput.files.length > 0 ) {
					formData.append('return_label_pdf', pdfInput.files[0]);
				}

				jQuery.ajax({
					url: ajaxurl + "?action=update_return_request_status",
					data: formData,
					type: "POST",
					processData: false,
					contentType: false,
					success: function (response) {
						var data = JSON.parse(response);
						if (data.success) {
							jQuery("#returns_preview_data").remove();
							$table.ajax.reload();
						}
					},
				});
			} else {
				jQuery.ajax({
					url: ajaxurl + "?action=update_return_request_status",
					data: {
						ID: requestId,
						return_status: returnStatus,
						return_note: returnNote,
						nonce: nonce,
					},
					type: "POST",
					success: function (response) {
						var data = JSON.parse(response);
						if (data.success) {
							jQuery("#returns_preview_data").remove();
							$table.ajax.reload();
						}
					},
				});
			}
			return false;
		}
	);

	// Upload/Replace label on already approved returns
	jQuery(document).on("click", ".rt4wc-upload-label-btn", function() {
		var requestId = jQuery(this).data("id");
		var pdfInput = document.getElementById('return_label_pdf_update');
		if ( !pdfInput || pdfInput.files.length === 0 ) {
			alert("Please select a PDF file to upload.");
			return false;
		}

		var formData = new FormData();
		formData.append('ID', requestId);
		formData.append('return_label_pdf', pdfInput.files[0]);
		formData.append('nonce', jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val());

		jQuery.ajax({
			url: ajaxurl + "?action=rt4wc_upload_return_label",
			data: formData,
			type: "POST",
			processData: false,
			contentType: false,
			success: function (response) {
				if (response.success) {
					alert(response.data.message);
					jQuery("#returns_preview_data").remove();
					$table.ajax.reload();
				} else {
					alert(response.data.message || "Upload failed.");
				}
			},
		});
		return false;
	});

	jQuery(document).on(
		"click",
		".return_approved_icon, .return_rejected_icon",
		function () {
			jQuery.ajax({
				url: ajaxurl + "?action=show_return_preview_data",
				data: {
					ID: jQuery(this).attr("id"),
					type: 'submit',
					return_status: jQuery(this).attr("status"),
					nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
				},
				type: "POST",
				dataType: "json",
				success: function (response) {
					jQuery( "#returns_preview_data" ).remove();
					jQuery("body").append(response.show_preview_data);
					jQuery("#returns_preview_data").show();
				},
			});
			return false;
		}
	);


	jQuery(document).on(
		"click",
		".return_completed_icon",
		function () {
			var confirmation = window.confirm("Would you like to mark this return as completed? Stock will be restocked.");
			if ( !confirmation ) {
				return false;
			}

			jQuery.ajax({
				url: ajaxurl + "?action=update_return_request_status",
				data: {
					ID: jQuery(this).attr("id"),
					return_status: "return-completed",
					return_note: "",
					nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
				},
				type: "POST",
				success: function (response) {
					var data = JSON.parse(response);
					if (data.success) {
						$table.ajax.reload();
					}
				},
			});
			return false;
		}
	);

	// Exchange: modal buttons + delete icon submit status update
	jQuery(document).on(
		"click",
		".exchange_approved_button, .exchange_rejected_button, .exchange_delete_icon, .exchange_completed_button",
		function () {

			if ( 'exchange-delete' == jQuery(this).attr("status") ) {
				var confirmation = window.confirm("Would you like to delete the request?");
			} else if ( 'exchange-completed' == jQuery(this).attr("status") ) {
				var confirmation = window.confirm("Would you like to mark this exchange as completed?");
			} else {
				var confirmation = window.confirm("Would you like to update the status?");
			}

			if ( !confirmation ) {
				return false;
			}

			var exchangeStatus = jQuery(this).attr("status");
			var requestId = jQuery(this).attr("id");
			var exchangeNote = jQuery("#exchange_note").val();
			var nonce = jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val();

			// Use FormData for approval to support PDF upload
			if ( 'exchange-approved' == exchangeStatus ) {
				var formData = new FormData();
				formData.append('ID', requestId);
				formData.append('exchange_status', exchangeStatus);
				formData.append('exchange_note', exchangeNote || '');
				formData.append('nonce', nonce);

				var pdfInput = document.getElementById('exchange_label_pdf');
				if ( pdfInput && pdfInput.files.length > 0 ) {
					formData.append('exchange_label_pdf', pdfInput.files[0]);
				}

				jQuery.ajax({
					url: ajaxurl + "?action=update_exchange_request_status",
					data: formData,
					type: "POST",
					processData: false,
					contentType: false,
					success: function (response) {
						var data = JSON.parse(response);
						if (data.success) {
							jQuery("#exchanges_preview_data").remove();
							$table.ajax.reload();
						}
					},
				});
			} else {
				jQuery.ajax({
					url: ajaxurl + "?action=update_exchange_request_status",
					data: {
						ID: requestId,
						exchange_status: exchangeStatus,
						exchange_note: exchangeNote,
						nonce: nonce,
					},
					type: "POST",
					success: function (response) {
						var data = JSON.parse(response);
						if (data.success) {
							jQuery("#exchanges_preview_data").remove();
							$table.ajax.reload();
						}
					},
				});
			}
			return false;
		}
	);

	// Upload/Replace label on already approved exchanges
	jQuery(document).on("click", ".rt4wc-upload-exchange-label-btn", function() {
		var requestId = jQuery(this).data("id");
		var pdfInput = document.getElementById('exchange_label_pdf_update');
		if ( !pdfInput || pdfInput.files.length === 0 ) {
			alert("Please select a PDF file to upload.");
			return false;
		}

		var formData = new FormData();
		formData.append('ID', requestId);
		formData.append('exchange_label_pdf', pdfInput.files[0]);
		formData.append('nonce', jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val());

		jQuery.ajax({
			url: ajaxurl + "?action=rt4wc_upload_exchange_label",
			data: formData,
			type: "POST",
			processData: false,
			contentType: false,
			success: function (response) {
				if (response.success) {
					alert(response.data.message);
					jQuery("#exchanges_preview_data").remove();
					$table.ajax.reload();
				} else {
					alert(response.data.message || "Upload failed.");
				}
			},
		});
		return false;
	});

	// Exchange: approve/reject table icons open the preview modal
	jQuery(document).on(
		"click",
		".exchange_approved_icon, .exchange_rejected_icon",
		function () {
			jQuery.ajax({
				url: ajaxurl + "?action=show_exchange_preview_data",
				data: {
					ID: jQuery(this).attr("id"),
					type: 'submit',
					exchange_status: jQuery(this).attr("status"),
					nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
				},
				type: "POST",
				dataType: "json",
				success: function (response) {
					jQuery( "#exchanges_preview_data" ).remove();
					jQuery("body").append(response.show_preview_data);
					jQuery("#exchanges_preview_data").show();
				},
			});
			return false;
		}
	);

	// Exchange: completed icon submits status directly (no modal)
	jQuery(document).on(
		"click",
		".exchange_completed_icon",
		function () {
			var confirmation = window.confirm("Would you like to mark this exchange as completed?");
			if ( !confirmation ) {
				return false;
			}

			jQuery.ajax({
				url: ajaxurl + "?action=update_exchange_request_status",
				data: {
					ID: jQuery(this).attr("id"),
					exchange_status: "exchange-completed",
					exchange_note: "",
					nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
				},
				type: "POST",
				success: function (response) {
					var data = JSON.parse(response);
					if (data.success) {
						$table.ajax.reload();
					}
				},
			});
			return false;
		}
	);
});

jQuery("#rt4wc_fulfillment_search_input").keyup(function(event) {
	event.preventDefault()
	if (event.which === 13) {
		jQuery("#rt4wc_fulfillment_search_submit").click();
	}
});

jQuery(document).on("click", ".tab_input", function(){
	var tab = jQuery(this).data('tab');
	var label = jQuery(this).data('label');
	jQuery('.breadcums_page_heading').text(label);
	var url = window.location.protocol + "//" + window.location.host + window.location.pathname+"?page=zorem-returns-exchanges&tab="+tab;
	window.history.pushState({path:url},'',url);	
});

jQuery(document).on("click", "#rt4wc_fullfilments_table #showPreviewImages", function () {
	jQuery.ajax({
		url: ajaxurl + "?action=show_return_preview_images",
		data: {
			ID: jQuery(this).data("id"),
			nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
		},
		type: "POST",
		dataType: "json",
		success: function (response) {
			jQuery( "#returns_preview_images" ).remove();
			jQuery("body").append(response.show_preview_images);				
			jQuery("#returns_preview_images").show();
		},
	});
	return false;
});

jQuery(document).on("click", "#rt4wc_fullfilments_table #showPreviewData", function () {
	jQuery.ajax({
		url: ajaxurl + "?action=show_return_preview_data",
		data: {
			ID: jQuery(this).data("id"),
			nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
		},
		type: "POST",
		dataType: "json",
		success: function (response) {
			jQuery( "#returns_preview_data" ).remove();
			jQuery("body").append(response.show_preview_data);
			jQuery("#returns_preview_data").show();
		},
	});
	return false;
});

jQuery(document).on("click", "#rt4wc_fullfilments_table #showExchangePreviewImages", function () {
	jQuery.ajax({
		url: ajaxurl + "?action=show_exchange_preview_images",
		data: {
			ID: jQuery(this).data("id"),
			nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
		},
		type: "POST",
		dataType: "json",
		success: function (response) {
			jQuery( "#exchanges_preview_images" ).remove();
			jQuery("body").append(response.show_preview_images);
			jQuery("#exchanges_preview_images").show();
		},
	});
	return false;
});

jQuery(document).on("click", "#rt4wc_fullfilments_table #showExchangePreviewData", function () {
	jQuery.ajax({
		url: ajaxurl + "?action=show_exchange_preview_data",
		data: {
			ID: jQuery(this).data("id"),
			nonce: jQuery("#return_orders_content #nonce_fullfillment_dashbaord").val(),
		},
		type: "POST",
		dataType: "json",
		success: function (response) {
			jQuery( "#exchanges_preview_data" ).remove();
			jQuery("body").append(response.show_preview_data);
			jQuery("#exchanges_preview_data").show();
		},
	});
	return false;
});

let currImg = 1;
jQuery(document).on("click", ".popupclose, .popup_close_icon", function () {
	"use strict";
	jQuery("#returns_preview_images").remove();
	jQuery("#returns_preview_data").remove();
	jQuery("#exchanges_preview_images").remove();
	jQuery("#exchanges_preview_data").remove();
	currImg = 1;
});

/*attachment images view*/
jQuery(document).ready(function () {
	jQuery(document).on("click", ".next", function (element) {
		currImg++;
		updateImage(element);
	});

	jQuery(document).on("click", ".previous", function (element) {
		currImg--;
		updateImage(element);
	});

	function updateImage(element) {
		const numImg = jQuery(element.target).parents(".slider").find(".img-container").find("img").length;
		const imgcontainer = document.querySelector(".img-container");
		if (currImg > numImg) {
			currImg = 1;
		} else if (currImg < 1) {
			currImg = numImg;
		}
		imgcontainer.style.transform = `translateX(-${(currImg - 1) * 100}%)`;
	}
});
/*attachment images view end*/



// Trigger click on csv button
jQuery(document).on("click", ".export_orders", function(){
	jQuery('.dt-buttons .buttons-csv').trigger('click');
});
