<?php
/**
 * Plugin Name: ION Shop Category Infinite Scroll Fix (MU)
 * Description: Keeps custom shop category tabs and infinite scroll from mixing category results with the full shop catalog. Disables the conflicting YITH frontend infinite scroll loader on shop archives.
 * Version: 1.1.0
 * Author: BuildRightWeb
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ION_MU_SHOP_FIX_VERSION')) {
    define('ION_MU_SHOP_FIX_VERSION', '1.1.0');
}

final class ION_MU_Shop_Category_Infinite_Scroll_Fix {
    const AJAX_ACTION  = 'ion_mu_shop_products';
    const NONCE_ACTION = 'ion_mu_shop_products';
    const PER_PAGE     = 15;

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'disable_conflicting_infinite_scroll'), 999);
        add_action('wp_print_scripts', array($this, 'disable_conflicting_infinite_scroll'), 1);
        add_action('wp_print_styles', array($this, 'disable_conflicting_infinite_scroll'), 1);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 1000);

        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_products'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'ajax_products'));
    }

    private function is_shop_context() {
        if (is_admin()) {
            return false;
        }

        if (!function_exists('is_shop') || !function_exists('is_product_taxonomy')) {
            return false;
        }

        return is_shop() || is_product_taxonomy() || is_post_type_archive('product');
    }

    private function is_disabled_by_url() {
        return isset($_GET['ion_mu_shop_fix']) && sanitize_text_field(wp_unslash($_GET['ion_mu_shop_fix'])) === 'off';
    }

    public function disable_conflicting_infinite_scroll() {
        if (!$this->is_shop_context() || $this->is_disabled_by_url()) {
            return;
        }

        /*
         * Critical fix:
         * YITH Infinite Scrolling targets the same ul.products/li.product list and native Woo pagination.
         * If it remains active, it can append /shop/page/2/ products while this custom tab loader
         * is appending filtered/category products, causing duplicated or mixed product order.
         */
        $script_handles = array(
            'yith-infinitescroll',
            'yith-infs',
            'yith-infinite-scrolling',
            'yith-infinitescroll-js',
            'yith-infs-js',
        );

        foreach ($script_handles as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }

        $style_handles = array(
            'yith-infs-style',
            'yith-infs',
            'yith-infinite-scrolling',
            'yith-infs-style-css',
        );

        foreach ($style_handles as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }

    public function enqueue_assets() {
        if (!$this->is_shop_context() || $this->is_disabled_by_url()) {
            return;
        }

        wp_enqueue_script('jquery');

        wp_register_script(
            'ion-mu-shop-scroll-fix',
            '',
            array('jquery'),
            ION_MU_SHOP_FIX_VERSION,
            true
        );

        wp_enqueue_script('ion-mu-shop-scroll-fix');

        wp_localize_script('ion-mu-shop-scroll-fix', 'ionMuShopFix', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'perPage' => self::PER_PAGE,
        ));

        wp_add_inline_script('ion-mu-shop-scroll-fix', $this->frontend_js());

        wp_register_style(
            'ion-mu-shop-scroll-fix-style',
            false,
            array(),
            ION_MU_SHOP_FIX_VERSION
        );

        wp_enqueue_style('ion-mu-shop-scroll-fix-style');
        wp_add_inline_style('ion-mu-shop-scroll-fix-style', $this->frontend_css());
    }

    private function frontend_css() {
        return '
#ion-mu-shop-loader {
    clear: both;
    width: 100%;
    min-height: 36px;
    text-align: center;
    font-family: inherit;
    font-size: 14px;
    color: #0D0D67;
    padding: 18px 0;
    visibility: hidden;
}
#ion-mu-shop-loader.ion-loading {
    visibility: visible;
}
ul.products.loading {
    opacity: .45;
    pointer-events: none;
}
ul.products li.product.ion-duplicate-blocked {
    display: none !important;
}
';
    }

    private function frontend_js() {
        return <<<'JS'
(function($){
    'use strict';

    var cfg = window.ionMuShopFix || {};

    var state = {
        category: 'all',
        page: 1,
        maxPages: null,
        loading: false,
        done: false,
        orderby: 'menu_order',
        request: null,
        token: 0,
        seenIds: {}
    };

    function getProductsWrap(){
        return $('ul.products').first();
    }

    function getActiveCategory(){
        var active = $('.peptide-tab.active').first();
        return active.length ? String(active.data('slug') || 'all') : 'all';
    }

    function getOrderby(){
        var val = $('form.woocommerce-ordering select.orderby').first().val();
        return val ? String(val) : 'menu_order';
    }

    function getProductIdFromLi(li){
        var className = li && li.className ? String(li.className) : '';
        var match = className.match(/\bpost-(\d+)\b/);
        return match ? match[1] : '';
    }

    function rebuildSeenIds(){
        state.seenIds = {};

        getProductsWrap().find('li.product').each(function(){
            var id = getProductIdFromLi(this);
            if (id) {
                state.seenIds[id] = true;
            }
        });
    }

    function filterNewItems(items){
        var clean = $();

        items.each(function(){
            var id = getProductIdFromLi(this);

            if (id && state.seenIds[id]) {
                return;
            }

            if (id) {
                state.seenIds[id] = true;
            }

            clean = clean.add(this);
        });

        return clean;
    }

    function removeNativePagination(){
        $('nav.woocommerce-pagination').remove();
        $('.yith-infs-loader, .yith-infs-loader-wrapper, .yith-infs-button-wrapper, .yith-infs-button').remove();
    }

    function killConflictingScrollers(){
        removeNativePagination();

        try {
            $(window).off('scroll.yith-infs resize.yith-infs');
            $(window).off('scroll.yith_infinitescroll resize.yith_infinitescroll');
            $(document).off('scroll.yith-infs');
        } catch(e) {}
    }

    function ensureLoader(){
        removeNativePagination();

        if (!$('#ion-mu-shop-loader').length) {
            var products = getProductsWrap();
            if (products.length) {
                products.after('<div id="ion-mu-shop-loader" aria-live="polite">Loading more products...</div>');
            }
        }
    }

    function setLoading(isLoading){
        state.loading = !!isLoading;
        getProductsWrap().toggleClass('loading', !!isLoading);
        $('#ion-mu-shop-loader').toggleClass('ion-loading', !!isLoading);
    }

    function updateResultCount(text){
        if (!text) {
            return;
        }

        var count = $('p.woocommerce-result-count').first();
        if (count.length) {
            count.text(text);
            return;
        }

        var ordering = $('form.woocommerce-ordering').first();
        if (ordering.length) {
            ordering.before('<p class="woocommerce-result-count" role="alert" aria-relevant="all">' + $('<div>').text(text).html() + '</p>');
        }
    }

    function updateMeta(data){
        if (data.title) {
            $('#peptide-category-meta h1').text(data.title);
        }

        if (typeof data.subtitle !== 'undefined') {
            $('#peptide-category-meta p').text(data.subtitle || '');
        }

        if (data.result_count) {
            updateResultCount(data.result_count);
        }
    }

    function normalizeProductItems(productsHtml){
        var parsed = $('<div>').append($.parseHTML(productsHtml || '', document, true));
        return parsed.find('ul.products li.product');
    }

    function requestProducts(page, replaceMode){
        if (state.loading && !replaceMode) {
            return;
        }

        if (state.done && !replaceMode) {
            return;
        }

        if (state.request && typeof state.request.abort === 'function') {
            state.request.abort();
        }

        state.token++;
        var thisToken = state.token;

        setLoading(true);
        ensureLoader();

        state.request = $.ajax({
            url: cfg.ajaxUrl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'ion_mu_shop_products',
                nonce: cfg.nonce || '',
                category: state.category || 'all',
                page: page || 1,
                orderby: state.orderby || 'menu_order'
            }
        }).done(function(resp){
            if (thisToken !== state.token) {
                return;
            }

            if (!resp || !resp.success || !resp.data) {
                state.done = true;
                return;
            }

            var data = resp.data;
            var currentProducts = getProductsWrap();

            if (replaceMode) {
                currentProducts.replaceWith(data.products || '<ul class="products columns-3"></ul>');
                state.page = parseInt(data.current_page || 1, 10) || 1;
                rebuildSeenIds();
            } else {
                var incomingItems = normalizeProductItems(data.products || '');
                var newItems = filterNewItems(incomingItems);

                if (newItems.length && currentProducts.length) {
                    currentProducts.append(newItems);
                }

                state.page = parseInt(data.current_page || page || (state.page + 1), 10) || (state.page + 1);

                if (!newItems.length && !data.has_more) {
                    state.done = true;
                }
            }

            state.maxPages = parseInt(data.max_pages || 0, 10) || null;
            state.done = !data.has_more;

            updateMeta(data);
            killConflictingScrollers();
            ensureLoader();

            $(document.body).trigger('post-load');
            $(document.body).trigger('ion_mu_shop_products_loaded', [data]);
        }).fail(function(xhr, status){
            if (thisToken !== state.token) {
                return;
            }

            if (status !== 'abort') {
                state.done = true;
            }
        }).always(function(){
            if (thisToken === state.token) {
                setLoading(false);
            }
        });
    }

    function resetAndLoad(category){
        state.category = category || 'all';
        state.page = 0;
        state.maxPages = null;
        state.done = false;
        state.orderby = getOrderby();
        state.seenIds = {};
        requestProducts(1, true);
    }

    function maybeLoadMore(){
        if (state.loading || state.done) {
            return;
        }

        var loader = $('#ion-mu-shop-loader');
        if (!loader.length) {
            ensureLoader();
            loader = $('#ion-mu-shop-loader');
        }

        if (!loader.length) {
            return;
        }

        var scrollBottom = $(window).scrollTop() + $(window).height();
        var triggerAt = loader.offset().top - 650;

        if (scrollBottom >= triggerAt) {
            requestProducts((state.page || 1) + 1, false);
        }
    }

    document.addEventListener('click', function(event){
        var tab = event.target && event.target.closest ? event.target.closest('.peptide-tab') : null;
        if (!tab) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        $('.peptide-tab').removeClass('active');
        $(tab).addClass('active');

        resetAndLoad(String(tab.getAttribute('data-slug') || 'all'));
    }, true);

    document.addEventListener('change', function(event){
        var target = event.target;
        if (!target || !target.matches || !target.matches('form.woocommerce-ordering select.orderby')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();

        state.orderby = getOrderby();
        resetAndLoad(state.category || getActiveCategory());
    }, true);

    $(function(){
        state.category = getActiveCategory();
        state.orderby = getOrderby();
        state.page = 1;
        state.done = false;

        rebuildSeenIds();
        killConflictingScrollers();
        ensureLoader();

        $(window).off('scroll.ionMuShopFix resize.ionMuShopFix');
        $(window).on('scroll.ionMuShopFix resize.ionMuShopFix', maybeLoadMore);

        window.setTimeout(killConflictingScrollers, 250);
        window.setTimeout(killConflictingScrollers, 1000);
        window.setTimeout(maybeLoadMore, 650);
    });

})(jQuery);
JS;
    }

    private function get_ordering_args($orderby) {
        if (function_exists('wc_get_catalog_ordering_args')) {
            return wc_get_catalog_ordering_args($orderby);
        }

        if (function_exists('WC') && WC() && isset(WC()->query) && is_object(WC()->query) && method_exists(WC()->query, 'get_catalog_ordering_args')) {
            return WC()->query->get_catalog_ordering_args($orderby);
        }

        return array(
            'orderby' => 'menu_order title',
            'order'   => 'ASC',
        );
    }

    public function ajax_products() {
        if (!function_exists('WC') || !function_exists('wc_get_template_part') || !class_exists('WP_Query')) {
            wp_send_json_error(array('message' => 'WooCommerce is not available.'), 400);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'), 403);
        }

        $category = isset($_POST['category']) ? sanitize_title(wp_unslash($_POST['category'])) : 'all';
        $page     = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $orderby  = isset($_POST['orderby']) ? wc_clean(wp_unslash($_POST['orderby'])) : 'menu_order';
        $per_page = self::PER_PAGE;

        $tax_query  = array();
        $meta_query = array();

        if (function_exists('WC') && WC() && isset(WC()->query) && is_object(WC()->query)) {
            if (method_exists(WC()->query, 'get_tax_query')) {
                $tax_query = WC()->query->get_tax_query();
            }

            if (method_exists(WC()->query, 'get_meta_query')) {
                $meta_query = WC()->query->get_meta_query();
            }
        }

        $title = 'All';
        $subtitle = '';

        if ($category && $category !== 'all') {
            $term = get_term_by('slug', $category, 'product_cat');

            if (!$term || is_wp_error($term)) {
                wp_send_json_success(array(
                    'products'      => '<ul class="products columns-3"></ul>',
                    'title'         => ucwords(str_replace('-', ' ', $category)),
                    'subtitle'      => '',
                    'result_count'  => 'No products found',
                    'current_page'  => 1,
                    'max_pages'     => 0,
                    'has_more'      => false,
                ));
            }

            $title = $term->name;
            $subtitle = wp_strip_all_tags(term_description($term->term_id, 'product_cat'));

            $tax_query[] = array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => array($category),
                'operator' => 'IN',
            );
        }

        $ordering_args = $this->get_ordering_args($orderby);

        $query_args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'ignore_sticky_posts'    => true,
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'orderby'                => isset($ordering_args['orderby']) ? $ordering_args['orderby'] : 'menu_order title',
            'order'                  => isset($ordering_args['order']) ? $ordering_args['order'] : 'ASC',
            'meta_query'             => $meta_query,
            'tax_query'              => $tax_query,
            'no_found_rows'          => false,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        );

        if (!empty($ordering_args['meta_key'])) {
            $query_args['meta_key'] = $ordering_args['meta_key'];
        }

        $products = new WP_Query($query_args);

        if (function_exists('wc_set_loop_prop')) {
            wc_set_loop_prop('total', (int) $products->found_posts);
            wc_set_loop_prop('total_pages', (int) $products->max_num_pages);
            wc_set_loop_prop('per_page', $per_page);
            wc_set_loop_prop('current_page', $page);
            wc_set_loop_prop('columns', 3);
            wc_set_loop_prop('is_paginated', $products->max_num_pages > 1);
        }

        ob_start();

        woocommerce_product_loop_start();

        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                wc_get_template_part('content', 'product');
            }
        }

        woocommerce_product_loop_end();

        wp_reset_postdata();

        $html = ob_get_clean();

        $total = (int) $products->found_posts;

        if ($total < 1) {
            $result_count = 'No products found';
        } else {
            $first = (($page - 1) * $per_page) + 1;
            $last  = min($total, $page * $per_page);
            $result_count = sprintf('Showing %1$d–%2$d of %3$d results', $first, $last, $total);
        }

        wp_send_json_success(array(
            'products'      => $html,
            'title'         => $title,
            'subtitle'      => $subtitle,
            'result_count'  => $result_count,
            'current_page'  => $page,
            'max_pages'     => (int) $products->max_num_pages,
            'has_more'      => $page < (int) $products->max_num_pages,
        ));
    }
}

new ION_MU_Shop_Category_Infinite_Scroll_Fix();
