(function($){ 
    
  $(document).ready(function() {    
    $('#dp-admin-tabs a:first').addClass('nav-tab-active');
    $('#dp-admin-tabs a:not(:first)').addClass('nav-tab-inactive');
    $('.dp-admin-nav-container').hide();
    $('.dp-admin-nav-container:first').show();
      
    $('#dp-admin-tabs a').on('click', function(){
      var t = $(this).attr('id');
      if($(this).hasClass('nav-tab-inactive')){ 
        $('#dp-admin-tabs a').addClass('nav-tab-inactive');           
        $(this).removeClass('nav-tab-inactive');
        $(this).addClass('nav-tab-active');
        
        $('.dp-admin-nav-container').hide();
        $('#'+ t + 'C').fadeIn('slow');
      }
    });

    setTimeout(function(){
      $(".dpwap-notice-pre .notice-dismiss").on('click', function(){
        jQuery.ajax({
          type: "POST",
          url: admin_vars.ajax_url,
          data: {action: 'dpwap_dismiss_notice_action'},
          success: function (response) { }
        });
      });  
    }, 1000);


    $(document).on('click', '.dpwap-dismissible .notice-dismiss', function() {
        var notice = $(this).closest('.dpwap-dismissible').data('notice');
        if (!notice) {
            return;
        }
        $.post(admin_vars.ajax_url, {
            action: 'dpwap_dismiss_admin_notice',
            nonce: admin_vars.nonce,
            notice: notice
        });
    });

    var reviewNotice = $('.dpwap-review-notice');
    if (reviewNotice.length) {
      var dismissReviewNotice = function() {
        $.post(admin_vars.ajax_url, {
          action: 'dpwap_dismiss_admin_notice',
          nonce: admin_vars.nonce,
          notice: 'review-notice'
        });
      };

      $(document).on('click', '.dpwap-review-notice [data-action="dismiss"]', function() {
        dismissReviewNotice();
      });

      $(document).on('click', '.dpwap-review-notice [data-action="review"]', function() {
        dismissReviewNotice();
      });
    }

    var proModal = $('#dpwap-pro-welcome-modal');
    if (proModal.length) {
      var openWelcomeModal = function() {
        $('body').addClass('dpwap-pro-modal-open');
        window.setTimeout(function() {
          proModal.addClass('is-visible').attr('aria-hidden', 'false');
        }, 30);
      };

      var dismissWelcomeModal = function() {
        proModal.removeClass('is-visible').attr('aria-hidden', 'true');
        $('body').removeClass('dpwap-pro-modal-open');

        $.post(admin_vars.ajax_url, {
          action: 'dpwap_dismiss_admin_notice',
          nonce: admin_vars.nonce,
          notice: 'welcome-modal'
        });
      };

      if (proModal.attr('data-auto-open') !== '0') {
        openWelcomeModal();
      }

      $(document).on('click', '[data-action="open-pro-modal"]', function(event) {
        var checkoutUrl = $(this).data('checkout-url');
        var checkoutButton = proModal.find('.dpwap-pro-modal__checkout');

        event.preventDefault();

        if (checkoutUrl && checkoutButton.length) {
          checkoutButton.attr('href', checkoutUrl);
        }

        openWelcomeModal();
      });

      $(document).on('click', '#dpwap-pro-welcome-modal [data-action="dismiss"], #dpwap-pro-welcome-modal [data-action="guide"], #dpwap-pro-welcome-modal .dpwap-pro-modal__backdrop', dismissWelcomeModal);

      $(document).on('keydown.dpwapProModal', function(event) {
        if (event.key === 'Escape' && proModal.hasClass('is-visible')) {
          dismissWelcomeModal();
          $(document).off('keydown.dpwapProModal');
        }
      });
    }
  });
})(jQuery);
