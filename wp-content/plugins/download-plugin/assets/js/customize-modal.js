jQuery(document).ready(function($) {
    const modal = $('#dtwap-customizeModal');
    const closeButtons = $('.dtwap-close-button');
    const customizeLinks = $('.dpwap_customize_link');
    const pluginSelect = $('#pluginSelect');
    const form = $('#dtwap-customizeForm');
    const formResponse = $('#formResponse');
    const successResponse = $('#successResponse');
    const failureResponse = $('#failureResponse');
    const spinner = $('#spinner');
    const userRequirements = $('#userRequirements');
    const copyButton = $('#copyButton');
    // const modalLogo = $('.modal-logo');
    const modalHeadings = $('#dtwap-customizeModal h2, #dtwap-customizeModal #p3');
     // Validation Error Messages
     const emailError = "Please enter a valid email address.";
     const customizationError = "Please provide details about your customization request.";
 

    customizeLinks.each(function() {
        $(this).on('click', function(event) {
            event.preventDefault();
            pluginSelect.val($(this).data('plugin'));
            modal.show();
        });
    });

    closeButtons.each(function() {
        $(this).on('click', function() {
            modal.hide();
        });
    });

    $(window).on('click', function(event) {
        if ($(event.target).is(modal)) {
            modal.hide();
        }
    });


    // Remove validation error on typing in customizationType field
    $('#customizationType').on('input', function() {
        $(this).next('.error-message').remove();
    });

    form.on('submit', function(event) {
        event.preventDefault();
        $(".spinner").css("display", "inline-block");
        // Clear previous error messages
        $('.error-message').remove();

        // Validation Logic
        const email = $('#email').val();
        const customizationType = $('#customizationType').val();
        let isValid = true;

        // Email Validation
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            isValid = false;
            $('#email').after(`<span class="error-message" style="color: red;">${emailError}</span>`);
        }

        // Customization Type Validation
        if (!customizationType || customizationType.trim() === '') {
            isValid = false;
            $('#customizationType').after(`<span class="error-message" style="color: red;">${customizationError}</span>`);
        }

        if (!isValid) {
            $(".spinner").css("display", "none");
            return; // Stop form submission if validation fails
        }

        // const formData = new FormData(form[0]);
        const formData = new FormData();
        formData.append('action', 'dpwap_customize_plugin');
        formData.append('user_email', $('#email').val());
        formData.append('plugin_select', $('#pluginSelect').val());
        formData.append('customizationType', $('#customizationType').val());
        formData.append('security', $('#customize_plugin_nonce').val());
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(data) {
                // console.log(data);
                //  return;
                $(".spinner").css("display", "none");
                if (data.success) {
                    // console.log(data);
                    form.hide();
                    formResponse.show();
                    // formResponse.show();
                    // modalLogo.hide(); // Hide the logo
                    modalHeadings.hide(); // Hide the headings
                    $('.modal-logo-text').hide(); // Hide the modal logo text

                    successResponse.show();
                } else {
                    form.hide();
                    formResponse.show();
                    // modalLogo.hide(); // Hide the logo
                    modalHeadings.hide(); // Hide the headings
                    $('.modal-logo-text').hide(); // Hide the modal logo text
                    failureResponse.show();
                    userRequirements.val(formData.get('customizationType'));
                }
            },
            error: function() {
                $(".spinner").css("display", "none");
                form.hide();
                formResponse.show();
                // modalLogo.hide(); // Hide the logo
                modalHeadings.hide(); // Hide the headings
                $('.modal-logo-text').hide(); // Hide the modal logo text
                failureResponse.show();
                userRequirements.val(formData.get('customizationType'));
            }
        });
    });

    copyButton.on('click', function() {
        userRequirements.select();
        document.execCommand('copy');
    });
    closeButtons.each(function() {
        $(this).on('click', function() {
            form.show();
            formResponse.hide();
            successResponse.hide();
            failureResponse.hide();
            // modalLogo.show(); // Show the logo again
            modalHeadings.show(); // Show the headings again
            $('.modal-logo-text').show(); // Show the modal logo text again

        });
    });
});