jQuery(
    function ($) {
        const isUE = function (country) {
            return ywpi_spanish_checkout_data.ue_countries.indexOf(country) !== -1;
        };

        const shouldShowBillingDocumentFields = function (country, receiverType) {
            if (country === 'ES') {
                return false;
            }

            if (isUE(country)) {
                return 'private' === receiverType;
            }

            return '' !== country;
        };

        const showBillingDocumentType = function (country, receiverType) {
            const show = shouldShowBillingDocumentFields(country, receiverType);
            const documentTypeRow = $(document).find('#billing_document_type_field');
            const billingDocumentNumberRow = $(document).find('#billing_document_number_field');

            if (show) {
                documentTypeRow.show();
                billingDocumentNumberRow.show();
            } else {
                documentTypeRow.hide();
                billingDocumentNumberRow.hide();
            }
        };

        const showVatNumber = function (country, receiverType) {
            let show = false;

            if ('ES' === country) {
                show = true;
            } else if (isUE(country) && 'private' !== receiverType) {
                show = true;
            }

            const vatNumberRow = $('#' + ywpi_spanish_checkout_data.billing_vat_id + '_field');

            if (show) {
                vatNumberRow.show();
            } else {
                vatNumberRow.hide();
            }
        };

        const maybeSetFieldAsRequired = function (field, required = true) {
            if (required) {
                const requiredHtml = '<abbr class="required" title="required">*</abbr>';

                if (field.closest('.form-row').find('.optional').length !== 0) {
                    field.closest('.form-row').find('.optional').remove();
                    field.closest('.form-row').find('label').append(requiredHtml);
                }

                if ('' === field.val()) {
                    field.closest('.form-row').addClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
                }
            } else {
                const optionalHtml = '<span class="optional">' + ywpi_spanish_checkout_data.optional_label + '</span>';

                if (field.closest('.form-row').find('.optional').length === 0) {
                    field.closest('.form-row').find('abbr').remove();
                    field.closest('.form-row').find('label').append(optionalHtml);
                }

                field.closest('.form-row').removeClass('validate-required woocommerce-invalid woocommerce-invalid-required-field');
            }
        };

        const billingCountry = $('#billing_country');
        const billingCompany = $('#billing_company');
        const billingType = $("select[name='billing_receiver_type']");
        const billingReceiverVatNumber = $('#' + ywpi_spanish_checkout_data.billing_vat_id);
        const billingDocumentNumber = $('#billing_document_number');
        const billingDocumentType = $("select[name='billing_document_type']");

        const syncCheckoutFields = function (country, receiverType) {
            const showDocumentFields = shouldShowBillingDocumentFields(country, receiverType);

            showBillingDocumentType(country, receiverType);
            showVatNumber(country, receiverType);
            maybeSetFieldAsRequired(billingCompany, 'private' !== receiverType);
            maybeSetFieldAsRequired(billingDocumentType, showDocumentFields);
            maybeSetFieldAsRequired(billingDocumentNumber, showDocumentFields);
            maybeSetFieldAsRequired(billingReceiverVatNumber, 'private' !== receiverType || 'ES' === country);
        };

        syncCheckoutFields(billingCountry.val(), billingType.val());

        billingCountry.on('change', function () {
            syncCheckoutFields($(this).val(), billingType.val());
        });

        billingType.on('change', function () {
            const receiverType = $(this).val();

            syncCheckoutFields(billingCountry.val(), receiverType);

            if ('private' === receiverType) {
                billingCompany.val('').trigger('change');
                billingReceiverVatNumber.val('').trigger('change');
                $(document.body).trigger('update_checkout');
            }
        }).trigger('change');
    }
);
