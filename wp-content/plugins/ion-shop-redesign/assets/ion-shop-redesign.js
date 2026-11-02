(function ($) {
  'use strict';

  var ION_BAG_SVG = '<span class="ion-shop-bag" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M6.5 9.5h11l-1 10h-9l-1-10Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"></path><path d="M9 9.5V7a3 3 0 0 1 6 0v2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path></svg></span>';


  var ionShopMediaCatalog = null;
  var ionShopMediaCatalogPromise = null;

  function getCardProductId($card) {
    var direct = parseInt($card.attr('data-ion-shop-product-id') || 0, 10);
    if (direct > 0) return direct;

    var buttonId = parseInt($card.find('[data-product_id]').first().attr('data-product_id') || 0, 10);
    if (buttonId > 0) return buttonId;

    var idMatch = String($card.attr('id') || '').match(/(?:product-)?(\d+)/);
    if (idMatch) return parseInt(idMatch[1], 10) || 0;

    var classMatch = String($card.attr('class') || '').match(/(?:^|\s)post-(\d+)(?:\s|$)/);
    return classMatch ? (parseInt(classMatch[1], 10) || 0) : 0;
  }

  function getMediaCatalogUrl() {
    if (window.IonShopRedesign && IonShopRedesign.mediaCatalogUrl) {
      return String(IonShopRedesign.mediaCatalogUrl);
    }

    return window.location.origin.replace(/\/+$/, '') + '/wp-content/uploads/ion-shop-media/catalog.json';
  }

  function applyLiveProductImages($scope) {
    if (!ionShopMediaCatalog || !ionShopMediaCatalog.products) return;

    var $cards = $scope && $scope.is && $scope.is('li.product, .ion-shop-card') ? $scope : $();
    if ($scope && $scope.find) {
      $cards = $cards.add($scope.find('li.product, .ion-shop-card'));
    }

    $cards.each(function () {
      var $card = $(this);
      var productId = getCardProductId($card);
      if (!productId) return;

      $card.attr('data-ion-shop-product-id', productId);

      var record = ionShopMediaCatalog.products[String(productId)];
      if (!record || !record.url) return;

      var $box = $card.find('.ion-shop-card-image').first();
      if (!$box.length) return;

      var $img = $box.find('img.ion-shop-product-image, img').first();
      if (!$img.length) {
        $img = $('<img class="ion-shop-product-image" loading="lazy" decoding="async" alt="">');
        $box.prepend($img);
      }

      $img.off('error.ionShopRedesign')
        .removeAttr('srcset sizes data-srcset data-lazy-srcset data-original data-lazy-src data-ll-status style')
        .attr({
          src: String(record.url),
          alt: String(record.alt || $card.find('h2, h3').first().text() || 'Product image'),
          loading: 'lazy',
          decoding: 'async'
        })
        .show();

      if (parseInt(record.width || 0, 10) > 0) {
        $img.attr('width', parseInt(record.width, 10));
      }
      if (parseInt(record.height || 0, 10) > 0) {
        $img.attr('height', parseInt(record.height, 10));
      }

      $box.find('.ion-shop-image-fallback').remove();
    });
  }

  function loadLiveMediaCatalog($root) {
    if (ionShopMediaCatalogPromise || !window.fetch) return ionShopMediaCatalogPromise;

    var url = getMediaCatalogUrl();
    if (!url) return null;

    var separator = url.indexOf('?') === -1 ? '?' : '&';
    var oneMinuteBucket = Math.floor(Date.now() / 60000);

    ionShopMediaCatalogPromise = window.fetch(url + separator + 'ion_shop_media=' + oneMinuteBucket, {
      cache: 'no-store',
      credentials: 'same-origin'
    })
      .then(function (response) {
        if (!response.ok) throw new Error('Shop media catalog unavailable');
        return response.json();
      })
      .then(function (catalog) {
        if (!catalog || typeof catalog !== 'object' || !catalog.products) return null;
        ionShopMediaCatalog = catalog;
        applyLiveProductImages($root || $('#ion-shop-redesign'));
        fixBrokenProductImages($root || $('#ion-shop-redesign'));
        return catalog;
      })
      .catch(function () {
        return null;
      });

    return ionShopMediaCatalogPromise;
  }

  function openIonLiveChat() {
    if (window.Tawk_API && typeof window.Tawk_API.maximize === 'function') {
      window.Tawk_API.maximize();
      return;
    }

    if (window.Tawk_API && typeof window.Tawk_API.toggle === 'function') {
      window.Tawk_API.toggle();
      return;
    }

    if (window.Tidio && typeof window.Tidio.open === 'function') {
      window.Tidio.open();
      return;
    }

    if (window.LiveChatWidget && typeof window.LiveChatWidget.call === 'function') {
      window.LiveChatWidget.call('maximize');
      return;
    }

    window.location.href = '/contact/';
  }

  function fixBrokenProductImages($scope) {
    $scope.find('.ion-shop-product-image').each(function () {
      var img = this;
      var $img = $(img);
      var markBroken = function () {
        var $box = $img.closest('.ion-shop-card-image');
        if (!$box.find('.ion-shop-image-fallback').length) {
          $box.append('<div class="ion-shop-image-fallback" aria-hidden="true"><span>ION</span><strong>Product Image</strong></div>');
        }
        $img.hide();
      };

      $img.off('error.ionShopRedesign').on('error.ionShopRedesign', markBroken);

      if (img.complete && typeof img.naturalWidth !== 'undefined' && img.naturalWidth === 0) {
        markBroken();
      }
    });
  }

  function getState($root) {
    return {
      action: 'ion_shop_redesign_filter',
      nonce: IonShopRedesign.nonce,
      search: $.trim($root.find('.ion-shop-search-input').val() || ''),
      category: $root.find('.ion-shop-category-select').val() || '',
      sort: $root.find('.ion-shop-sort-select').val() || 'az',
      page: 1
    };
  }

  function updateUrl(state) {
    if (!window.history || !window.history.replaceState) return;

    var url = new URL(window.location.href);
    var params = url.searchParams;

    if (state.search) params.set('search', state.search);
    else params.delete('search');

    if (state.category) params.set('category', state.category);
    else params.delete('category');

    if (state.sort && state.sort !== 'az') params.set('sort', state.sort);
    else params.delete('sort');

    params.delete('page');
    params.delete('paged');

    window.history.replaceState({}, '', url.toString());
  }

  function getFilterUrl($root) {
    var state = getState($root);
    var baseUrl = (window.IonShopRedesign && IonShopRedesign.shopUrl) ? IonShopRedesign.shopUrl : window.location.pathname;
    var url = new URL(baseUrl, window.location.origin);
    var params = url.searchParams;

    if (state.search) params.set('search', state.search);
    else params.delete('search');

    if (state.category) params.set('category', state.category);
    else params.delete('category');

    if (state.sort && state.sort !== 'az') params.set('sort', state.sort);
    else params.delete('sort');

    params.delete('page');
    params.delete('paged');

    return url.toString();
  }

  function hardApplyFilters($root) {
    window.location.href = getFilterUrl($root);
  }

  function hasActiveCategoryFilter($root) {
    return !!String($root.find('.ion-shop-category-select').val() || '');
  }

  function getActiveCategoryTerms($root) {
    var category = String($root.find('.ion-shop-category-select').val() || '');
    if (!category) return [];

    var map = (window.IonShopRedesign && IonShopRedesign.categoryTerms) || {};
    var terms = map[category];

    if (!Array.isArray(terms) || !terms.length) terms = [category];

    return terms.map(function (term) {
      return String(term || '').replace(/[^a-z0-9_-]/gi, '');
    }).filter(Boolean);
  }

  function productMatchesActiveCategory($product, terms) {
    var classes = ' ' + String($product.attr('class') || '') + ' ';

    return terms.some(function (term) {
      return classes.indexOf(' product_cat-' + term + ' ') !== -1;
    });
  }

  function setFilteredGridClosed($root) {
    if (!hasActiveCategoryFilter($root)) return;

    $root.attr({
      'data-page': '1',
      'data-max-pages': '1',
      'data-loading-more': '1'
    });

    $root.find('nav.ion-shop-yith-nav, .yith-infs-loader').remove();
  }

  function establishActiveCategoryGrid($root) {
    if (!hasActiveCategoryFilter($root)) return;

    var terms = getActiveCategoryTerms($root);

    setFilteredGridClosed($root);

    $root.find('ul.products.ion-shop-grid > li.product').each(function () {
      var $product = $(this);
      if (productMatchesActiveCategory($product, terms)) {
        $product.attr('data-ion-category-result', '1');
      } else {
        $product.remove();
      }
    });
  }

  function lockActiveCategoryGrid($root) {
    if (!hasActiveCategoryFilter($root)) return;

    var terms = getActiveCategoryTerms($root);

    setFilteredGridClosed($root);

    $root.find('ul.products.ion-shop-grid > li.product').each(function () {
      var $product = $(this);
      if ($product.attr('data-ion-category-result') !== '1' || !productMatchesActiveCategory($product, terms)) {
        $product.remove();
      }
    });
  }

  function installExternalLoaderGuard($root) {
    if (!$.ajaxPrefilter) return;

    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
      if (!hasActiveCategoryFilter($root)) return;

      var data = originalOptions && originalOptions.data;
      var action = '';

      if (typeof data === 'string') {
        var match = data.match(/(?:^|&)action=([^&]+)/);
        action = match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
      } else if (data && typeof data.get === 'function') {
        action = String(data.get('action') || '');
      } else if (data && typeof data === 'object') {
        action = String(data.action || '');
      }

      if (action === 'ion_mu_shop_products' || action === 'peptide_ajax_products') {
        jqXHR.abort('ion-shop-category-lock');
      }
    });
  }

  function reinitYithInfiniteScroll($root) {
    if (hasActiveCategoryFilter($root)) {
      lockActiveCategoryGrid($root);
      return;
    }

    if (!$.fn.yit_infinitescroll) return;

    var options = (window.IonShopRedesign && IonShopRedesign.yithOptions) || window.yith_infs || null;
    if (!options) return;

    options = $.extend({}, options, {
      navSelector: 'nav.ion-shop-yith-nav',
      nextSelector: 'nav.ion-shop-yith-nav a.next',
      itemSelector: 'ul.products.ion-shop-grid > li.product',
      contentSelector: 'ul.products.ion-shop-grid',
      shop: true,
      is_shop: true
    });

    window.yith_infs = $.extend({}, window.yith_infs || {}, options);

    $(window).off('yith_infs_start');
    $('.yith-infs-loader').remove();

    if ($root.find(options.nextSelector).length && $root.find(options.itemSelector).length) {
      $(options.contentSelector).yit_infinitescroll(options);
    }
  }

  function runFilter($root) {
    if ($root.attr('data-filter-loading') === '1') return;

    var state = getState($root);
    var $gridWrap = $root.find('.ion-shop-grid-wrap');
    var $loading = $root.find('.ion-shop-loading');
    var $count = $root.find('.ion-shop-result-count');

    $root.attr('data-filter-loading', '1');
    $gridWrap.addClass('is-loading');
    $loading.prop('hidden', false);

    $(window).off('yith_infs_start');
    $('.yith-infs-loader').remove();

    $.ajax({
      url: IonShopRedesign.ajaxUrl,
      type: 'POST',
      dataType: 'json',
      data: state
    })
      .done(function (response) {
        if (!response || !response.success || !response.data) return;

        $gridWrap.html(response.data.grid || '');
        establishActiveCategoryGrid($root);
        normalizeNativeWooCards($gridWrap);
        applyLiveProductImages($gridWrap);
        fixBrokenProductImages($gridWrap);
        forceMobileTwoColumnGrid();

        var found = parseInt(response.data.found || 0, 10);
        $count.text(found === 1 ? '1 product found' : found.toLocaleString() + ' products found');
        updateUrl(state);
        reinitYithInfiniteScroll($root);
      })
      .always(function () {
        $root.attr('data-filter-loading', '0');
        $gridWrap.removeClass('is-loading');
        $loading.prop('hidden', true);
      });
  }

  function getBadgeFromProductClasses($li) {
    var className = String($li.attr('class') || '');

    if (className.indexOf('product_cat-lyophilized') !== -1 || className.indexOf('product_cat-lyophilized-peptides') !== -1) return 'Lyophilized';
    if (className.indexOf('product_cat-raws') !== -1 || className.indexOf('product_cat-raw') !== -1) return 'Raws';
    if (className.indexOf('product_cat-complex-elixir') !== -1 || className.indexOf('product_cat-complex') !== -1 || className.indexOf('product_cat-elixir') !== -1 || className.indexOf('product_cat-elixirs') !== -1 || className.indexOf('product_cat-creams') !== -1 || className.indexOf('product_cat-cream') !== -1 || className.indexOf('product_cat-serums') !== -1 || className.indexOf('product_cat-serum') !== -1 || className.indexOf('product_cat-shampoos') !== -1 || className.indexOf('product_cat-shampoo') !== -1 || className.indexOf('product_cat-specifications') !== -1) return 'Complex/Elixir';
    if (className.indexOf('product_cat-drops') !== -1 || className.indexOf('product_cat-drop') !== -1) return 'Drops';
    if (className.indexOf('product_cat-sprays') !== -1 || className.indexOf('product_cat-spray') !== -1) return 'Sprays';
    if (className.indexOf('product_cat-lab-supplies') !== -1 || className.indexOf('product_cat-lab-supply') !== -1) return 'Lab Supplies';

    return 'Products';
  }

  function sanitizeText(text) {
    return $.trim(String(text || '').replace(/\s+/g, ' '));
  }

  function normalizeNativeWooCards($scope) {
    var $root = $('#ion-shop-redesign');
    if (!$root.length) return;

    var $items = $scope.find ? $scope.find('ul.products.ion-shop-grid > li.product, ul.products > li.product') : $();
    if ($scope.is && $scope.is('li.product')) {
      $items = $items.add($scope);
    }

    $items.each(function () {
      var $li = $(this);
      var normalizedProductId = getCardProductId($li);

      if (normalizedProductId) {
        $li.attr('data-ion-shop-product-id', normalizedProductId);
      }

      if ($li.hasClass('ion-shop-normalized')) {
        return;
      }

      if ($li.hasClass('ion-shop-card') && $li.find('.ion-shop-card-body').length && $li.find('.ion-shop-card-image').length) {
        $li.addClass('ion-shop-normalized');
        return;
      }

      var $mainLink = $li.find('a.woocommerce-LoopProduct-link, a.woocommerce-loop-product__link').first();
      var $button = $li.find('a.button, button.button').last();
      var href = $mainLink.attr('href') || $button.attr('href') || '#';
      var $titleEl = $li.find('.woocommerce-loop-product__title, h2, h3').first();
      var title = sanitizeText($titleEl.text());
      if (!title) {
        title = sanitizeText($mainLink.attr('aria-label') || $button.attr('aria-label') || 'Product');
      }

      var $img = $li.find('img').first().clone();
      var imageHtml = '';
      if ($img.length) {
        $img.addClass('ion-shop-product-image');
        $img.removeAttr('style srcset sizes data-srcset data-lazy-srcset data-sizes data-original data-lazy-src data-src data-ll-status').attr({ loading: 'lazy', decoding: 'async' });
        if ($img.attr('src')) {
          $img.attr('data-ion-shop-backend-src', $img.attr('src'));
        }
        imageHtml = $('<div>').append($img).html();
      } else {
        imageHtml = '<div class="ion-shop-image-fallback" aria-hidden="true"><span>ION</span><strong>Product Image</strong></div>';
      }

      var $priceEl = $li.find('.price').first();
      var priceHtml = $priceEl.length ? $priceEl.html() : $li.find('.woocommerce-Price-amount, .amount').first().parent().html();
      priceHtml = priceHtml || '';

      var buttonText = sanitizeText($button.text()) || ($li.hasClass('product-type-variable') ? 'Select Options' : 'Add to Cart');
      var isOut = $li.hasClass('outofstock') || /out of stock/i.test(buttonText);
      var buttonHtml = '';

      if (isOut) {
        buttonHtml = '<button class="ion-shop-card-button ion-shop-card-button--disabled" type="button" disabled>Out of Stock</button>';
      } else if ($button.length && $button.is('a')) {
        var attrs = [];
        $.each(thisAttributes($button[0]), function (name, value) {
          if (name === 'class') return;
          attrs.push(name + '="' + escapeAttr(value) + '"');
        });
        buttonHtml = '<a class="ion-shop-card-button ' + escapeAttr($button.attr('class') || '') + '" ' + attrs.join(' ') + '>' + ION_BAG_SVG + escapeHtml(buttonText) + '</a>';
      } else {
        buttonHtml = '<a class="ion-shop-card-button" href="' + escapeAttr(href) + '">' + ION_BAG_SVG + escapeHtml(buttonText) + '</a>';
      }

      var badge = getBadgeFromProductClasses($li);

      $li.addClass('ion-shop-card ion-shop-normalized')
        .removeAttr('style')
        .html(
          '<a class="ion-shop-card-image-link" href="' + escapeAttr(href) + '" aria-label="' + escapeAttr(title) + '">' +
            '<div class="ion-shop-card-image">' + imageHtml + '</div>' +
          '</a>' +
          '<div class="ion-shop-card-body">' +
            '<div class="ion-shop-card-title-row">' +
              '<h2><a href="' + escapeAttr(href) + '">' + escapeHtml(title) + '</a></h2>' +
              '<span class="ion-shop-card-badge">' + escapeHtml(badge) + '</span>' +
            '</div>' +
            '<div class="ion-shop-card-price">' + priceHtml + '</div>' +
            buttonHtml +
          '</div>'
        );
    });

    applyLiveProductImages($root);
    fixBrokenProductImages($root);
  }

  function thisAttributes(node) {
    var out = {};
    if (!node || !node.attributes) return out;

    for (var i = 0; i < node.attributes.length; i++) {
      var attr = node.attributes[i];
      out[attr.name] = attr.value;
    }

    return out;
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (match) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[match];
    });
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
  }


  function isIonPhoneViewport() {
    var matchedViewport = false;
    var matchedDevice = false;
    var matchedVisual = false;

    if (window.matchMedia) {
      matchedViewport = window.matchMedia('(max-width: 767px)').matches;
    } else {
      matchedViewport = window.innerWidth <= 767;
    }

    if (window.visualViewport) {
      matchedVisual = Math.min(window.visualViewport.width || 9999, window.visualViewport.height || 9999) <= 767;
    }

    if (window.screen) {
      matchedDevice = Math.min(window.screen.width || 9999, window.screen.height || 9999) <= 520 && Math.max(window.screen.width || 0, window.screen.height || 0) <= 950;
    }

    return !!(matchedViewport || matchedVisual || (matchedDevice && navigator.maxTouchPoints > 0));
  }

  function setImportantStyle(node, property, value) {
    if (!node || !node.style) return;

    if (value === '') {
      node.style.removeProperty(property);
      return;
    }

    node.style.setProperty(property, value, 'important');
  }

  function forceMobileTwoColumnGrid() {
    var $root = $('#ion-shop-redesign');
    if (!$root.length) return;

    var isMobile = isIonPhoneViewport();
    var rootEl = document.documentElement;

    if (rootEl) {
      if (isMobile) {
        rootEl.classList.add('ion-shop-force-mobile-two-col');
      } else {
        rootEl.classList.remove('ion-shop-force-mobile-two-col');
      }
    }

    var $grids = $root.find('ul.products.ion-shop-grid, .ion-shop-grid');
    var $items = $grids.children('li.product, .ion-shop-card');

    if (!isMobile) {
      $grids.each(function () {
        setImportantStyle(this, 'display', '');
        setImportantStyle(this, 'flex-flow', '');
        setImportantStyle(this, 'flex-wrap', '');
        setImportantStyle(this, 'align-items', '');
        setImportantStyle(this, 'justify-content', '');
        setImportantStyle(this, 'gap', '');
        setImportantStyle(this, 'grid-template-columns', '');
        setImportantStyle(this, 'width', '');
        setImportantStyle(this, 'min-width', '');
        setImportantStyle(this, 'max-width', '');
        setImportantStyle(this, 'margin', '');
        setImportantStyle(this, 'padding', '');
      });

      $items.each(function () {
        setImportantStyle(this, 'flex', '');
        setImportantStyle(this, 'width', '');
        setImportantStyle(this, 'max-width', '');
        setImportantStyle(this, 'min-width', '');
        setImportantStyle(this, 'margin', '');
        setImportantStyle(this, 'padding', '');
        setImportantStyle(this, 'float', '');
        setImportantStyle(this, 'clear', '');
        setImportantStyle(this, 'grid-column', '');
        setImportantStyle(this, 'box-sizing', '');
        setImportantStyle(this, 'overflow', '');
      });

      return;
    }

    $root.find('.ion-shop-layout').each(function () {
      setImportantStyle(this, 'display', 'block');
      setImportantStyle(this, 'grid-template-columns', '1fr');
    });

    $root.find('.ion-shop-sidebar').each(function () {
      setImportantStyle(this, 'display', 'none');
    });

    $grids.each(function () {
      setImportantStyle(this, 'display', 'flex');
      setImportantStyle(this, 'flex-flow', 'row wrap');
      setImportantStyle(this, 'align-items', 'stretch');
      setImportantStyle(this, 'justify-content', 'flex-start');
      setImportantStyle(this, 'gap', '0');
      setImportantStyle(this, 'grid-template-columns', 'none');
      setImportantStyle(this, 'width', '100%');
      setImportantStyle(this, 'min-width', '0');
      setImportantStyle(this, 'max-width', '100%');
      setImportantStyle(this, 'margin', '0 -7px');
      setImportantStyle(this, 'padding', '0');
      setImportantStyle(this, 'list-style', 'none');
    });

    $items.each(function () {
      setImportantStyle(this, 'flex', '0 0 50%');
      setImportantStyle(this, 'width', '50%');
      setImportantStyle(this, 'max-width', '50%');
      setImportantStyle(this, 'min-width', '0');
      setImportantStyle(this, 'margin', '0 0 14px');
      setImportantStyle(this, 'padding', '0 7px');
      setImportantStyle(this, 'float', 'none');
      setImportantStyle(this, 'clear', 'none');
      setImportantStyle(this, 'grid-column', 'auto');
      setImportantStyle(this, 'box-sizing', 'border-box');
      setImportantStyle(this, 'overflow', 'hidden');
    });

    $grids.children('li.ion-shop-empty').each(function () {
      setImportantStyle(this, 'flex', '0 0 100%');
      setImportantStyle(this, 'width', '100%');
      setImportantStyle(this, 'max-width', '100%');
    });
  }

  function observeShopGrid() {
    var gridWrap = document.querySelector('#ion-shop-redesign .ion-shop-grid-wrap');
    if (!gridWrap || !window.MutationObserver) return;

    var timer = null;
    var observer = new MutationObserver(function () {
      lockActiveCategoryGrid($('#ion-shop-redesign'));
      clearTimeout(timer);
      timer = setTimeout(function () {
        normalizeNativeWooCards($('#ion-shop-redesign'));
        applyLiveProductImages($('#ion-shop-redesign'));
        forceMobileTwoColumnGrid();
      }, 35);
    });

    observer.observe(gridWrap, { childList: true, subtree: true });
  }

  $(function () {
    var $root = $('#ion-shop-redesign');
    if (!$root.length || typeof IonShopRedesign === 'undefined') return;

    var searchTimer = null;

    installExternalLoaderGuard($root);
    establishActiveCategoryGrid($root);
    normalizeNativeWooCards($root);
    loadLiveMediaCatalog($root);
    applyLiveProductImages($root);
    fixBrokenProductImages($root);
    forceMobileTwoColumnGrid();
    reinitYithInfiniteScroll($root);
    observeShopGrid();

    $root.on('input', '.ion-shop-search-input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        runFilter($root);
      }, 280);
    });

    $root.on('change', '.ion-shop-category-select, .ion-shop-sort-select', function () {
      hardApplyFilters($root);
    });

    $root.on('submit', '.ion-shop-filters', function (event) {
      event.preventDefault();
      hardApplyFilters($root);
    });

    $(document).on('yith_infs_adding_elem yith_infs_added_elem post-load', function (event, elem) {
      var $target = elem ? $(elem) : $('#ion-shop-redesign');
      normalizeNativeWooCards($target.length ? $target : $('#ion-shop-redesign'));
      applyLiveProductImages($('#ion-shop-redesign'));
      fixBrokenProductImages($('#ion-shop-redesign'));
      forceMobileTwoColumnGrid();
    });

    $(document.body).on('ion_mu_shop_products_loaded', function () {
      normalizeNativeWooCards($('#ion-shop-redesign'));
      applyLiveProductImages($('#ion-shop-redesign'));
      fixBrokenProductImages($('#ion-shop-redesign'));
      forceMobileTwoColumnGrid();
    });

    window.setTimeout(function () { normalizeNativeWooCards($('#ion-shop-redesign')); applyLiveProductImages($('#ion-shop-redesign')); forceMobileTwoColumnGrid(); }, 50);
    window.setTimeout(function () { normalizeNativeWooCards($('#ion-shop-redesign')); applyLiveProductImages($('#ion-shop-redesign')); forceMobileTwoColumnGrid(); }, 250);
    window.setTimeout(function () { normalizeNativeWooCards($('#ion-shop-redesign')); applyLiveProductImages($('#ion-shop-redesign')); forceMobileTwoColumnGrid(); }, 900);
    window.setTimeout(function () { normalizeNativeWooCards($('#ion-shop-redesign')); applyLiveProductImages($('#ion-shop-redesign')); forceMobileTwoColumnGrid(); }, 1800);
    window.setTimeout(function () { normalizeNativeWooCards($('#ion-shop-redesign')); applyLiveProductImages($('#ion-shop-redesign')); forceMobileTwoColumnGrid(); }, 3200);
    $(window).on('resize.ionShopMobileGrid orientationchange.ionShopMobileGrid pageshow.ionShopMobileGrid', function () {
      window.setTimeout(forceMobileTwoColumnGrid, 40);
      window.setTimeout(forceMobileTwoColumnGrid, 160);
      window.setTimeout(forceMobileTwoColumnGrid, 500);
    });

    $root.on('click', '.ion-shop-live-chat-button', function (event) {
      event.preventDefault();
      openIonLiveChat();
    });
  });
})(jQuery);
