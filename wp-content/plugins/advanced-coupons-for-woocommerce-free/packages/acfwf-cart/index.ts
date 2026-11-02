jQuery(function ($: JQueryStatic) {
  function decodeCouponNotice(context?: HTMLElement | Document): void {
    const $ctx = context ? $(context) : $(document);
    const selector = '#coupon-error-notice';

    $ctx.find(selector).each((_i, el) => {
      const $el = $(el);
      const text = $el.text();
      const rawHtml = $el.html();
      let decoded = '';

      // Case 1: HTML entities (old WooCommerce behavior)
      if (text.includes('&lt;')) {
        decoded = $('<textarea/>').html(text).text();
        $el.html(decoded);
      }

      // Case 2: Escaped raw HTML (new WooCommerce behavior)
      else if (rawHtml.match(/&lt;.*&gt;/)) {
        const temp = document.createElement('textarea');
        temp.innerHTML = rawHtml;
        decoded = temp.value;
        $el.html(decoded);
      }

      // If decoded HTML was successfully inserted
      if (decoded && /<.*>/.test(decoded)) {
        $el.css('margin-top', '10px');
      }
    });
  }

  // Run once initially
  decodeCouponNotice();

  // Listen to WooCommerce events
  $(document).on('updated_checkout updated_cart_totals updated_wc_div applied_coupon removed_coupon', () =>
    decodeCouponNotice()
  );

  // Observe DOM changes
  const targetNode = document.querySelector('.coupon') ?? document.body;
  if (window.MutationObserver && targetNode) {
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType !== Node.ELEMENT_NODE) return;
          const element = node as HTMLElement;
          if (element.id === 'coupon-error-notice' || element.querySelector('#coupon-error-notice')) {
            decodeCouponNotice(element);
          }
        });
      });
    });

    observer.observe(targetNode, { childList: true, subtree: true });
  }
});
