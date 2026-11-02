jQuery(document).ready(function () {
	jQuery(document).on(
		"click",
		".return_approved_button, .return_rejected_button, .return_completed_button",
		function () {
			if ( 'return-completed' == jQuery(this).attr("status") ) {
				var confirmation = window.confirm("Would you like to mark this return as completed? Stock will be restocked.");
				if ( !confirmation ) {
					return false;
				}
			}

			jQuery.ajax({
				url: ajaxurl + "?action=update_return_request_status",
				data: {
					ID: jQuery(this).attr("id"),
					return_status: jQuery(this).attr("status"),
					return_note: jQuery("#return_note").val(),
					restocking_fee_amount: jQuery("#restocking_fee_amount").length ? jQuery("#restocking_fee_amount").val() : "",
					nonce: jQuery("#nonce_fullfillment_dashbaord").val(),
				},
				type: "POST",
				success: function (response) {
					var data = JSON.parse(response);
					if (data.success) {
						window.location.reload();
					}
				},
			});
			return false;
		}
	);
});

jQuery(document).ready(function () {
	jQuery(document).on(
		"click",
		".exchange_approved_button, .exchange_rejected_button, .exchange_completed_button",
		function () {
			jQuery.ajax({
				url: ajaxurl + "?action=update_exchange_request_status",
				data: {
					ID: jQuery(this).attr("id"),
					exchange_status: jQuery(this).attr("status"),
					exchange_note: jQuery("#exchange_note").val(),
					nonce: jQuery("#nonce_fullfillment_dashbaord").val(),
				},
				type: "POST",
				success: function (response) {
					var data = JSON.parse(response);
					if (data.success) {
						window.location.reload();
					}
				},
			});
			return false;
		}
	);
});