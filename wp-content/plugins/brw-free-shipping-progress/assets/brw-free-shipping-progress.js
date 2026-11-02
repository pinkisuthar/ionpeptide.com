(function($){
  var request = null;
  var timer = null;
  var sideTimer = null;

  function isCheckoutPage(){
    return $('body').hasClass('woocommerce-checkout');
  }

  function hasAjaxConfig(){
    return typeof brwFsp !== 'undefined' && brwFsp.ajaxUrl && brwFsp.nonce;
  }

  function fetchProgress(callback){
    if (!hasAjaxConfig()) return;

    if (request && typeof request.abort === 'function') {
      request.abort();
    }

    request = $.ajax({
      url: brwFsp.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'brw_fsp_refresh',
        nonce: brwFsp.nonce
      }
    }).done(function(response){
      if (response && response.success && response.data && response.data.html && typeof callback === 'function') {
        callback(response.data.html);
      }
    }).always(function(){
      request = null;
    });
  }

  function refreshExistingFragments(){
    if (!$('.brw-fsp-fragment').length) return;

    fetchProgress(function(html){
      $('.brw-fsp-fragment').each(function(){
        $(this).replaceWith(html);
      });
    });
  }

  function injectSideCart(){
    if (isCheckoutPage()) return;

    var body = $('.xoo-wsc-body').first();
    if (!body.length) return;
    if (body.find('.brw-fsp-fragment').length) return;

    fetchProgress(function(html){
      var freshBody = $('.xoo-wsc-body').first();
      if (!freshBody.length || freshBody.find('.brw-fsp-fragment').length) return;
      freshBody.prepend(html);
    });
  }

  function queueRefresh(){
    clearTimeout(timer);
    timer = setTimeout(function(){
      refreshExistingFragments();
      injectSideCart();
    }, 180);
  }

  function queueSideCartCheck(){
    clearTimeout(sideTimer);
    sideTimer = setTimeout(injectSideCart, 250);
  }

  $(document.body).on('wc_fragments_loaded wc_fragments_refreshed added_to_cart removed_from_cart updated_cart_totals updated_checkout', queueRefresh);
  $(document.body).on('xoo_wsc_cart_toggled xoo_wsc_cart_updated xoo_wsc_cart_loaded xoo_wsc_cart_opened', queueSideCartCheck);
  $(document).on('click', '.xoo-wsc-cart-trigger, .xoo-wsc-basket, .hfe-cart-container, #hfe-menu-cart__toggle_button', queueSideCartCheck);

  $(function(){
    queueRefresh();
    setTimeout(injectSideCart, 900);
    setTimeout(injectSideCart, 1800);
  });
})(jQuery);
