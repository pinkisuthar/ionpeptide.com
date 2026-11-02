jQuery( document ).ready( function( $ ) {
	 
	$('.afpgbf_payment_methods').select2({
		multiple: true
	})
	 
	$('.afpgbf_include_countries').select2({
		multiple: true
	})

	$('.afpgbf_user_roles').select2({
		multiple: true
	})	

	min_max_amount_hide_show();
	$('.afpgbf_fixed_percentage').on('change', function(){
		min_max_amount_hide_show();
	})

	tax_classes();
	$('input[name="afpgbf_taxable"]').on('click', function(){
		tax_classes();
	})
	$('.afpgbf_customer_cl').select2();
	$('.af-pgbf-ajax-products-search').select2();
	$('.af-pgbf-categories-search').select2();
	$('.af-pgbf-brands-search').select2();

	fee_discount();
	$(document).on('change', 'select[name="afpgbf_fee_discount"]', function(){
		fee_discount();
	})	

	function fee_discount(argument) {
			
		jQuery('input[name=afpgbf_taxable]').closest('tr').hide();

		if( jQuery('select[name="afpgbf_fee_discount"]').children('option:selected').val() == 'fee' ) {

			jQuery("table.afpgbf_table_fee_or_discount .fee_discount_label").each(function(){

				text = jQuery(this).text();
				text = text.replace("Fee/Discount", "Fee");
				text = text.replace("Fee/Fee", "Fee");
				text = text.replace("Discount", "Fee");
				text = text.replace("discount reason", "why this additional fee has been added");

				jQuery(this).text( text );
			});

		jQuery('input[name=afpgbf_taxable]').closest('tr').show();

		} else {

			jQuery("table.afpgbf_table_fee_or_discount .fee_discount_label").each(function(){

				text = jQuery(this).text();
				text = text.replace("Fee/Discount", "Discount");
				text = text.replace("Fee", "Discount");
				text = text.replace("Discount/Discount", "Discount");
				text = text.replace("why this additional fee has been added", "discount reason");

				jQuery(this).text( text );
			});
		} 
	}

	// $('#menu_order').prop('type','number');
	// $('#menu_order').prop('min','1');

	// product selection ajax
	 $('.af-pgbf-ajax-products-search').select2(
        {
            ajax: {
                url: ajaxurl, // AJAX URL is predefined in WordPress admin.
                dataType: 'json',
                type: 'POST',
                delay: 250, // Delay in ms while typing when to perform a AJAX search.
                data: function (params) {
                    return {
                        q: params.term, // search query
                        action: 'af_pgbf_search_products', // AJAX action for admin-ajax.php.
                        nonce: afpgbf_php_vars.nonce // AJAX nonce for admin-ajax.php.
                    };
                },
                processResults: function ( data ) {
                    var options = [];
                    if (data ) {

                             // data is the array of arrays, and each of them contains ID and the Label of the option.
                            $.each(
                                data, function ( index, text ) {
                                    // do not forget that "index" is just auto incremented value.
                                    options.push({ id: text[0], text: text[1]  });
                                }
                            );
                    }
                    return {
                        results: options
                    };
                },
                cache: true
            },
            multiple: true,
            placeholder: 'Choose Products',
            minimumInputLength: 3 // the minimum of symbols to input before perform a search.
    
        }
    );
})


function min_max_amount_hide_show(){
	var val = jQuery('.afpgbf_fixed_percentage').children('option:selected').val();
	if ( val == 'percentage' ) {
		jQuery('.min_max_amount').show();
		jQuery('.afpgbf_required').prop('required', true);


	} else {
		jQuery('.min_max_amount').hide();
		jQuery('.afpgbf_required').prop('required', false);

	}	
}

function tax_classes(){
	var value = jQuery('input[name="afpgbf_taxable"]').is(':checked') ? 'TRUE' : 'FALSE';
	if ( value == 'TRUE' ) {
		jQuery('.tax_class_hide_show').show();
	} else {
		jQuery('.tax_class_hide_show').hide();
	}	
}