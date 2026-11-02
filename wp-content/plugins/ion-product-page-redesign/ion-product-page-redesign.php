<?php
/**
 * Plugin Name: ION Product Page Redesign
 * Description: Redesigns WooCommerce single product pages into the modern ION product layout with large product image, cleaned purchase panel, Details/COA tabs, related products styling, and support section.
 * Version: 1.1.36
 * Author: BuildRightWeb
 * Author URI: https://buildrightweb.com
 * Text Domain: ion-product-page-redesign
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ION_Product_Page_Redesign {
    const VERSION = '1.1.36';

    protected static $coa_tab_original_callback = null;
    protected static $coa_tab_original_tab = array();

    public static function init() {
        add_action('wp_loaded', array(__CLASS__, 'sync_variation_request'), 1);
        add_action('wp', array(__CLASS__, 'wire_product_hooks'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'), 30);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_footer', array(__CLASS__, 'sticky_buy_bar'), 30);

        /*
         * Keep a tiny static JSON media manifest current for each product.
         * Product pages fetch this file directly from /uploads, so stale page HTML
         * can repair itself without admin-ajax, REST, PHP, or a database query.
         */
        add_action('admin_init', array(__CLASS__, 'maybe_rebuild_media_manifests'), 1);
        add_action('save_post_product', array(__CLASS__, 'product_saved'), 100, 3);
        add_action('save_post_product_variation', array(__CLASS__, 'variation_saved'), 100, 3);
        add_action('added_post_meta', array(__CLASS__, 'media_meta_changed'), 100, 4);
        add_action('updated_post_meta', array(__CLASS__, 'media_meta_changed'), 100, 4);
        add_action('deleted_post_meta', array(__CLASS__, 'media_meta_changed'), 100, 4);

        /* Read the current product/media records directly instead of stale object-cache values. */
        add_filter('woocommerce_product_get_image_id', array(__CLASS__, 'fresh_product_image_id'), 999, 2);
        add_filter('woocommerce_product_variation_get_image_id', array(__CLASS__, 'fresh_product_image_id'), 999, 2);
        add_filter('woocommerce_product_get_gallery_image_ids', array(__CLASS__, 'fresh_gallery_image_ids'), 999, 2);
        add_filter('woocommerce_single_product_image_thumbnail_html', array(__CLASS__, 'fresh_gallery_image_html'), 999, 2);
        add_filter('woocommerce_available_variation', array(__CLASS__, 'fresh_available_variation'), 999, 3);
    }

    public static function is_product_page() {
        return function_exists('is_product') && is_product();
    }

    public static function body_class($classes) {
        if (self::is_product_page()) {
            $classes[] = 'ion-product-redesign';
        }
        return $classes;
    }

    public static function wire_product_hooks() {
        if (!self::is_product_page()) {
            return;
        }

        remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);

        add_action('woocommerce_before_single_product', array(__CLASS__, 'ruo_banner'), 1);
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'exact_product_title'), 5);
        add_action('woocommerce_before_single_product', array(__CLASS__, 'back_to_products'), 2);
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'product_badge'), 3);
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'price_panel'), 28);
        add_action('woocommerce_before_single_product_summary', array(__CLASS__, 'coa_button'), 25);
        add_action('woocommerce_after_single_product_summary', array(__CLASS__, 'support_section'), 35);

        add_filter('woocommerce_product_tabs', array(__CLASS__, 'trim_product_tabs'), 999);
        add_filter('woocommerce_product_related_products_heading', array(__CLASS__, 'related_heading'));
    }

    /**
     * Read the newest metadata value straight from wp_postmeta.
     * This bypasses Redis/object-cache entries left behind after product/media renames.
     */
    protected static function direct_meta($post_id, $meta_key) {
        global $wpdb;

        if (!$post_id || !$meta_key) {
            return '';
        }

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1",
                $post_id,
                $meta_key
            )
        );

        return null === $raw ? '' : maybe_unserialize($raw);
    }

    /** Return the exact current database product title, without display-name filters. */
    protected static function direct_product_title($product_id) {
        global $wpdb;

        if (!$product_id) {
            return '';
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                $product_id
            )
        );
    }

    /**
     * Return the current attachment image record using the actual files on disk.
     *
     * Renaming tools do not always update _wp_attached_file and
     * _wp_attachment_metadata together. Prefer the metadata filename the backend
     * is showing, but only when that file exists. If the renamed full image is
     * missing, use the largest existing generated size before falling back to the
     * obsolete attached-file value.
     */
    protected static function direct_attachment_image_record($attachment_id) {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return array();
        }

        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return array();
        }

        $metadata = self::direct_attachment_metadata($attachment_id);
        $attached_file = self::direct_meta($attachment_id, '_wp_attached_file');
        $attached_file = is_string($attached_file) ? trim($attached_file) : '';

        $candidates = array();

        if (!empty($metadata['file']) && is_string($metadata['file'])) {
            $relative = ltrim(wp_normalize_path(trim($metadata['file'])), '/');
            if ($relative && false === strpos($relative, '../')) {
                $candidates[] = array(
                    'relative' => $relative,
                    'width' => !empty($metadata['width']) ? absint($metadata['width']) : 0,
                    'height' => !empty($metadata['height']) ? absint($metadata['height']) : 0,
                    'priority' => 3000000000,
                );

                $dir = dirname($relative);
                $dir = '.' === $dir ? '' : trailingslashit($dir);

                if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size) {
                        if (empty($size['file']) || !is_string($size['file'])) {
                            continue;
                        }

                        $size_relative = ltrim(wp_normalize_path($dir . trim($size['file'])), '/');
                        if (!$size_relative || false !== strpos($size_relative, '../')) {
                            continue;
                        }

                        $width = !empty($size['width']) ? absint($size['width']) : 0;
                        $height = !empty($size['height']) ? absint($size['height']) : 0;

                        $candidates[] = array(
                            'relative' => $size_relative,
                            'width' => $width,
                            'height' => $height,
                            'priority' => 2000000000 + ($width * $height),
                        );
                    }
                }
            }
        }

        if ($attached_file) {
            $relative = ltrim(wp_normalize_path($attached_file), '/');
            if ($relative && false === strpos($relative, '../')) {
                $candidates[] = array(
                    'relative' => $relative,
                    'width' => !empty($metadata['width']) ? absint($metadata['width']) : 0,
                    'height' => !empty($metadata['height']) ? absint($metadata['height']) : 0,
                    'priority' => 1000000000,
                );
            }
        }

        usort(
            $candidates,
            static function ($a, $b) {
                return (int) $b['priority'] <=> (int) $a['priority'];
            }
        );

        $chosen = array();
        foreach ($candidates as $candidate) {
            $path = trailingslashit($uploads['basedir']) . $candidate['relative'];
            if (is_file($path)) {
                $chosen = $candidate;
                break;
            }
        }

        /*
         * Last-resort URL fallback. This keeps existing remote/offloaded-media
         * behavior intact when the upload is intentionally not on local disk.
         */
        if (!$chosen && $candidates) {
            $chosen = reset($candidates);
        }

        if (!$chosen) {
            $fallback = wp_get_attachment_url($attachment_id);
            if (!$fallback) {
                return array();
            }

            return array(
                'id' => $attachment_id,
                'url' => esc_url_raw($fallback),
                'width' => !empty($metadata['width']) ? absint($metadata['width']) : 1420,
                'height' => !empty($metadata['height']) ? absint($metadata['height']) : 1420,
                'alt' => wp_strip_all_tags((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true)),
                'title' => wp_strip_all_tags((string) get_the_title($attachment_id)),
            );
        }

        $segments = array_map(
            'rawurlencode',
            explode('/', ltrim($chosen['relative'], '/'))
        );
        $url = trailingslashit($uploads['baseurl']) . implode('/', $segments);

        $alt = (string) self::direct_meta($attachment_id, '_wp_attachment_image_alt');
        if ('' === trim($alt)) {
            $alt = (string) get_the_title($attachment_id);
        }

        return array(
            'id' => $attachment_id,
            'url' => esc_url_raw($url),
            'width' => !empty($chosen['width']) ? absint($chosen['width']) : 1420,
            'height' => !empty($chosen['height']) ? absint($chosen['height']) : 1420,
            'alt' => wp_strip_all_tags($alt),
            'title' => wp_strip_all_tags((string) get_the_title($attachment_id)),
        );
    }

    protected static function direct_attachment_url($attachment_id) {
        $record = self::direct_attachment_image_record($attachment_id);
        return !empty($record['url']) ? $record['url'] : '';
    }

    protected static function direct_attachment_metadata($attachment_id) {
        $metadata = self::direct_meta($attachment_id, '_wp_attachment_metadata');
        return is_array($metadata) ? $metadata : array();
    }

    /**
     * Return the uploads path and URL for one product's static media manifest.
     */
    protected static function media_manifest_location($product_id) {
        $product_id = absint($product_id);
        $uploads = wp_get_upload_dir();

        if (
            !$product_id ||
            !empty($uploads['error']) ||
            empty($uploads['basedir']) ||
            empty($uploads['baseurl'])
        ) {
            return array();
        }

        $dir = trailingslashit($uploads['basedir']) . 'ion-product-media';
        $url = trailingslashit($uploads['baseurl']) . 'ion-product-media/product-' . $product_id . '.json';

        return array(
            'dir' => $dir,
            'path' => trailingslashit($dir) . 'product-' . $product_id . '.json',
            'url' => $url,
        );
    }

    protected static function product_gallery_ids_direct($product_id) {
        $raw = (string) self::direct_meta($product_id, '_product_image_gallery');
        if ('' === trim($raw)) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map('absint', explode(',', $raw)))));
    }

    /**
     * Build one product's manifest directly from wp_posts/wp_postmeta.
     */
    protected static function build_product_media_manifest($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        if (!$product_id) {
            return array();
        }

        $post_type = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                $product_id
            )
        );

        if ('product' !== $post_type) {
            return array();
        }

        $main_id = absint(self::direct_meta($product_id, '_thumbnail_id'));
        $main = $main_id ? self::direct_attachment_image_record($main_id) : array();

        $gallery = array();
        foreach (self::product_gallery_ids_direct($product_id) as $attachment_id) {
            if ($attachment_id === $main_id) {
                continue;
            }

            $record = self::direct_attachment_image_record($attachment_id);
            if ($record) {
                $gallery[] = $record;
            }
        }

        $variation_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_parent = %d
                   AND post_type = 'product_variation'
                   AND post_status NOT IN ('trash', 'auto-draft')
                 ORDER BY menu_order ASC, ID ASC",
                $product_id
            )
        );

        $variations = array();
        foreach ((array) $variation_ids as $variation_id) {
            $variation_id = absint($variation_id);
            $image_id = absint(self::direct_meta($variation_id, '_thumbnail_id'));

            if (!$image_id) {
                $image_id = $main_id;
            }

            $record = $image_id ? self::direct_attachment_image_record($image_id) : array();
            $variations[(string) $variation_id] = $record ? $record : null;
        }

        $manifest = array(
            'version' => self::VERSION,
            'product_id' => $product_id,
            'generated_gmt' => gmdate('c'),
            'main' => $main,
            'gallery' => $gallery,
            'variations' => $variations,
        );

        $manifest['signature'] = md5(wp_json_encode($manifest));

        return $manifest;
    }

    /**
     * Atomically write one tiny JSON file. Frontend requests hit Nginx/static
     * storage only; PHP and MySQL are not involved in product-page refreshes.
     */
    protected static function write_product_media_manifest($product_id) {
        $product_id = absint($product_id);
        $location = self::media_manifest_location($product_id);
        $manifest = self::build_product_media_manifest($product_id);

        if (!$location || !$manifest) {
            return false;
        }

        if (!wp_mkdir_p($location['dir'])) {
            return false;
        }

        $json = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || '' === $json) {
            return false;
        }

        $tmp = $location['path'] . '.' . wp_generate_uuid4() . '.tmp';
        $written = @file_put_contents($tmp, $json, LOCK_EX);

        if (false === $written) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $location['path'])) {
            @unlink($location['path']);
            if (!@rename($tmp, $location['path'])) {
                @unlink($tmp);
                return false;
            }
        }

        return true;
    }

    public static function activate() {
        self::rebuild_all_media_manifests();
        update_option('ionpd_media_manifest_version', self::VERSION, false);
    }

    public static function maybe_rebuild_media_manifests() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if ((string) get_option('ionpd_media_manifest_version', '') === self::VERSION) {
            return;
        }

        self::rebuild_all_media_manifests();
        update_option('ionpd_media_manifest_version', self::VERSION, false);
    }

    protected static function rebuild_all_media_manifests() {
        global $wpdb;

        $product_ids = $wpdb->get_col(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status NOT IN ('trash', 'auto-draft')"
        );

        foreach ((array) $product_ids as $product_id) {
            self::write_product_media_manifest(absint($product_id));
        }
    }

    public static function product_saved($post_id, $post, $update) {
        if (
            !$post ||
            'product' !== $post->post_type ||
            wp_is_post_revision($post_id) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ) {
            return;
        }

        self::write_product_media_manifest($post_id);
    }

    public static function variation_saved($post_id, $post, $update) {
        if (
            !$post ||
            'product_variation' !== $post->post_type ||
            wp_is_post_revision($post_id) ||
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        ) {
            return;
        }

        $parent_id = absint($post->post_parent);
        if ($parent_id) {
            self::write_product_media_manifest($parent_id);
        }
    }

    /**
     * Keep manifests synchronized when product images or attachment filenames are
     * changed by WooCommerce, WordPress, or a media-renaming plugin.
     */
    public static function media_meta_changed($meta_id, $object_id, $meta_key, $meta_value) {
        $object_id = absint($object_id);
        $meta_key = (string) $meta_key;

        if (!$object_id) {
            return;
        }

        if ('_thumbnail_id' === $meta_key) {
            $post_type = get_post_type($object_id);

            if ('product' === $post_type) {
                self::write_product_media_manifest($object_id);
            } elseif ('product_variation' === $post_type) {
                $parent_id = (int) wp_get_post_parent_id($object_id);
                if ($parent_id) {
                    self::write_product_media_manifest($parent_id);
                }
            }

            return;
        }

        if ('_product_image_gallery' === $meta_key && 'product' === get_post_type($object_id)) {
            self::write_product_media_manifest($object_id);
            return;
        }

        if (
            '_wp_attached_file' === $meta_key ||
            '_wp_attachment_metadata' === $meta_key ||
            '_wp_attachment_image_alt' === $meta_key
        ) {
            self::refresh_manifests_for_attachment($object_id);
        }
    }

    protected static function refresh_manifests_for_attachment($attachment_id) {
        global $wpdb;

        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_type, p.post_parent
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_thumbnail_id'
                   AND pm.meta_value = %s
                   AND p.post_type IN ('product', 'product_variation')",
                (string) $attachment_id
            ),
            ARRAY_A
        );

        $product_ids = array();
        foreach ((array) $rows as $row) {
            if ('product' === $row['post_type']) {
                $product_ids[] = absint($row['ID']);
            } elseif (!empty($row['post_parent'])) {
                $product_ids[] = absint($row['post_parent']);
            }
        }

        $gallery_product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_product_image_gallery'
                   AND FIND_IN_SET(%s, meta_value)",
                (string) $attachment_id
            )
        );

        $product_ids = array_values(
            array_unique(
                array_filter(
                    array_merge($product_ids, array_map('absint', (array) $gallery_product_ids))
                )
            )
        );

        foreach ($product_ids as $product_id) {
            self::write_product_media_manifest($product_id);
        }
    }

    protected static function direct_variation_attributes($variation_id) {
        global $wpdb;
        $attributes = array();

        if (!$variation_id) {
            return $attributes;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE 'attribute\\_%%' ESCAPE '\\\\' ORDER BY meta_id ASC",
                $variation_id
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $attributes[(string) $row['meta_key']] = (string) maybe_unserialize($row['meta_value']);
        }

        return $attributes;
    }

    /**
     * Before WooCommerce validates Add to Cart, make the posted attributes match
     * the exact selected variation record. This is part of the redesign because
     * the redesign owns the custom variation UI.
     */
    public static function sync_variation_request() {
        if (!function_exists('wc_get_product') || empty($_REQUEST['variation_id'])) {
            return;
        }

        $variation_id = absint(wp_unslash($_REQUEST['variation_id']));
        if (!$variation_id) {
            return;
        }

        global $wpdb;
        $parent_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product_variation' LIMIT 1",
                $variation_id
            )
        );

        if (!$parent_id) {
            return;
        }

        $_POST['variation_id'] = $variation_id;
        $_REQUEST['variation_id'] = $variation_id;
        $_POST['product_id'] = $parent_id;
        $_REQUEST['product_id'] = $parent_id;
        $_POST['add-to-cart'] = $parent_id;
        $_REQUEST['add-to-cart'] = $parent_id;

        foreach (self::direct_variation_attributes($variation_id) as $name => $value) {
            if ('' === $value) {
                continue;
            }
            $_POST[$name] = wc_clean($value);
            $_REQUEST[$name] = wc_clean($value);
        }
    }

    public static function fresh_product_image_id($image_id, $product) {
        if (is_admin() || !self::is_product_page() || !($product instanceof WC_Product)) {
            return $image_id;
        }

        $fresh = absint(self::direct_meta($product->get_id(), '_thumbnail_id'));
        return $fresh ?: $image_id;
    }

    public static function fresh_gallery_image_ids($ids, $product) {
        if (is_admin() || !self::is_product_page() || !($product instanceof WC_Product)) {
            return $ids;
        }

        $raw = (string) self::direct_meta($product->get_id(), '_product_image_gallery');
        if ('' === trim($raw)) {
            return array();
        }

        return array_values(array_filter(array_map('absint', explode(',', $raw))));
    }

    /**
     * Render the gallery from the current attachment file URL only. Deliberately
     * omit srcset/intermediate sizes because those are where renamed stale files
     * were continuing to appear.
     */
    public static function fresh_gallery_image_html($html, $attachment_id) {
        if (is_admin() || !self::is_product_page()) {
            return $html;
        }

        $record = self::direct_attachment_image_record($attachment_id);
        if (empty($record['url'])) {
            return $html;
        }

        return sprintf(
            '<div data-thumb="%1$s" data-thumb-alt="%2$s" class="woocommerce-product-gallery__image"><a href="%1$s"><img width="%3$d" height="%4$d" src="%1$s" class="wp-post-image" alt="%2$s" decoding="async" data-caption="" data-src="%1$s" data-large_image="%1$s" data-large_image_width="%3$d" data-large_image_height="%4$d"></a></div>',
            esc_url($record['url']),
            esc_attr($record['alt']),
            absint($record['width']),
            absint($record['height'])
        );
    }

    /** Give WooCommerce's variation JS the current DB attributes and image URL. */
    public static function fresh_available_variation($data, $product, $variation) {
        if (is_admin() || !self::is_product_page() || !($variation instanceof WC_Product_Variation)) {
            return $data;
        }

        $attributes = self::direct_variation_attributes($variation->get_id());
        if ($attributes) {
            $data['attributes'] = $attributes;
        }

        $image_id = absint(self::direct_meta($variation->get_id(), '_thumbnail_id'));
        if (!$image_id && $product instanceof WC_Product) {
            $image_id = absint(self::direct_meta($product->get_id(), '_thumbnail_id'));
        }

        $record = $image_id ? self::direct_attachment_image_record($image_id) : array();
        if (!empty($record['url'])) {
            $data['image'] = array_merge(
                isset($data['image']) && is_array($data['image']) ? $data['image'] : array(),
                array(
                    'title' => $record['title'],
                    'caption' => '',
                    'url' => $record['url'],
                    'alt' => $record['alt'],
                    'src' => $record['url'],
                    'srcset' => '',
                    'sizes' => '',
                    'full_src' => $record['url'],
                    'full_src_w' => absint($record['width']),
                    'full_src_h' => absint($record['height']),
                    'gallery_thumbnail_src' => $record['url'],
                    'gallery_thumbnail_src_w' => absint($record['width']),
                    'gallery_thumbnail_src_h' => absint($record['height']),
                    'thumb_src' => $record['url'],
                    'thumb_src_w' => absint($record['width']),
                    'thumb_src_h' => absint($record['height']),
                    'src_w' => absint($record['width']),
                    'src_h' => absint($record['height']),
                )
            );
        }

        return $data;
    }

    /** Output the exact database title instead of any shortened display filter. */
    public static function exact_product_title() {
        global $product;
        if (!($product instanceof WC_Product)) {
            return;
        }

        $title = self::direct_product_title($product->get_id());
        if (!$title) {
            $title = $product->get_name();
        }

        echo '<h1 class="product_title entry-title" data-ionpd-exact-title="1">' . esc_html($title) . '</h1>';
    }

    public static function enqueue_assets() {
        if (!self::is_product_page()) {
            return;
        }

        $base = plugin_dir_url(__FILE__);
        wp_enqueue_style(
            'ion-product-page-redesign',
            $base . 'assets/css/ion-product-page-redesign.css',
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'ion-product-page-redesign',
            $base . 'assets/js/ion-product-page-redesign.js',
            array('jquery', 'wc-add-to-cart-variation'),
            self::VERSION,
            true
        );

        global $product;
        $product_data = array(
            'currencySymbol' => html_entity_decode(get_woocommerce_currency_symbol()),
            'currencyPosition' => get_option('woocommerce_currency_pos', 'left'),
            'decimalSeparator' => wc_get_price_decimal_separator(),
            'thousandSeparator' => wc_get_price_thousand_separator(),
            'decimals' => wc_get_price_decimals(),
            'basePrice' => 0,
            'regularPrice' => 0,
            'priceRangeText' => '',
            'priceHtml' => '',
            'productName' => '',
            'sku' => '',
            'productId' => 0,
            'mediaManifestUrl' => '',
            'bulkPreview' => false,
            'bulkPreviewTiers' => array(),
        );

        // Safe, admin-only visual preview for the bulk-pack UI.
        // It never enables Woo Discount Rules and is ignored for non-admin visitors.
        $bulk_preview_requested = isset($_GET['ion_bulk_preview']) && '1' === sanitize_text_field(wp_unslash($_GET['ion_bulk_preview']));
        if ($bulk_preview_requested && current_user_can('manage_options')) {
            $product_data['bulkPreview'] = true;
            $product_data['bulkPreviewTiers'] = array(
                array('min' => 3,  'max' => 4,   'discount' => 5),
                array('min' => 5,  'max' => 9,   'discount' => 10),
                array('min' => 10, 'max' => 19,  'discount' => 15),
                array('min' => 20, 'max' => 9999,'discount' => 20),
            );
        }

        if ($product instanceof WC_Product) {
            $product_id = $product->get_id();
            $location = self::media_manifest_location($product_id);

            if ($location && !is_file($location['path'])) {
                self::write_product_media_manifest($product_id);
            }

            $product_data['productId'] = $product_id;
            $product_data['mediaManifestUrl'] = !empty($location['url']) ? esc_url_raw($location['url']) : '';
            $product_data['productName'] = wp_strip_all_tags(self::direct_product_title($product_id) ?: $product->get_name());
            $product_data['sku'] = $product->get_sku() ? wp_strip_all_tags($product->get_sku()) : 'N/A';
            $product_data['basePrice'] = (float) wc_get_price_to_display($product);
            $product_data['regularPrice'] = (float) wc_get_price_to_display($product, array('price' => $product->get_regular_price()));
            $price_html = $product->get_price_html();
            $product_data['priceRangeText'] = wp_strip_all_tags($price_html);
            $product_data['priceHtml'] = wp_kses_post($price_html);

            if ($product->is_type('variable')) {
                $prices = $product->get_variation_prices(true);
                if (!empty($prices['price'])) {
                    $product_data['basePrice'] = (float) min($prices['price']);
                    $product_data['regularPrice'] = !empty($prices['regular_price']) ? (float) min($prices['regular_price']) : $product_data['basePrice'];
                }
            }
        }

        wp_localize_script('ion-product-page-redesign', 'ionProductRedesign', $product_data);
    }


    public static function ruo_banner() {
        echo '<div class="ionpd-ruo-banner"><strong>Research Use Only:</strong><span>All products currently listed on this site are for research purposes ONLY.</span></div>';
    }

    public static function back_to_products() {
        $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
        echo '<a class="ionpd-back-link" href="' . esc_url($shop_url) . '">← Back to Products</a>';
    }

    public static function product_badge() {
        global $product;
        $label = 'Peptide';

        if ($product instanceof WC_Product) {
            $terms = get_the_terms($product->get_id(), 'product_cat');
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    if (!in_array(strtolower($term->name), array('all', 'uncategorized'), true)) {
                        $label = $term->name;
                        break;
                    }
                }
            }
        }

        echo '<span class="ionpd-product-pill">' . esc_html($label) . '</span>';
    }

    public static function coa_button() {
        echo '<a class="ionpd-coa-button" href="#tab-coa_tab" data-ionpd-coa-link>';
        echo '  <span class="ionpd-coa-button__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/></svg></span>';
        echo '  <span class="ionpd-coa-button__label">Lab Test Report (CoA)</span>';
        echo '  <span class="ionpd-coa-button__arrow" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>';
        echo '</a>';
    }

    public static function price_panel() {
        echo '<div class="ionpd-price-panel" data-ionpd-price-panel>';
        echo '  <div class="ionpd-price-line ionpd-total-line">';
        echo '    <span class="ionpd-total-copy"><b>Order total <em data-ionpd-qty-label></em></b><small data-ionpd-saving-label></small></span>';
        echo '    <strong data-ionpd-order-total>—</strong>';
        echo '  </div>';
        echo '</div>';
    }

    public static function trim_product_tabs($tabs) {
        if (!is_array($tabs)) {
            $tabs = array();
        }

        /*
         * COA plugins do not all use the same tab key. Normalize any clearly
         * identified lab/certificate tab to the key expected by this design.
         */
        if (!isset($tabs['coa_tab'])) {
            foreach ($tabs as $key => $tab) {
                $haystack = strtolower($key . ' ' . (isset($tab['title']) ? wp_strip_all_tags($tab['title']) : ''));

                if (preg_match('/(^|[^a-z])(coa|certificate of (analysis|authenticity)|lab (test|result|report))([^a-z]|$)/i', $haystack)) {
                    $tabs['coa_tab'] = $tab;
                    unset($tabs[$key]);
                    break;
                }
            }
        }

        /* The live Lab Results snippet powers the archive but does not add a product tab. */
        if (!isset($tabs['coa_tab'])) {
            $tabs['coa_tab'] = array(
                'title'    => 'COA',
                'priority' => 20,
                'callback' => array(__CLASS__, 'render_live_coa_tab'),
            );
        }

        $keep = array('details_tab', 'coa_tab');
        foreach ($tabs as $key => $tab) {
            if (!in_array($key, $keep, true)) {
                unset($tabs[$key]);
            }
        }

        if (isset($tabs['details_tab'])) {
            $tabs['details_tab']['title'] = 'Details';
            $tabs['details_tab']['priority'] = 10;
        }

        if (isset($tabs['coa_tab'])) {
            $tabs['coa_tab']['title'] = 'COA';
            $tabs['coa_tab']['priority'] = 20;

            if (!empty($tabs['coa_tab']['callback']) && is_callable($tabs['coa_tab']['callback'])) {
                self::$coa_tab_original_callback = $tabs['coa_tab']['callback'];
                self::$coa_tab_original_tab = $tabs['coa_tab'];
                $tabs['coa_tab']['callback'] = array(__CLASS__, 'render_coa_tab');
            }
        }

        return $tabs;
    }

    protected static function normalize_lab_name($value) {
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, get_bloginfo('charset'));
        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = strtolower($value);

        return preg_replace('/[^a-z0-9]+/', '', $value);
    }

    protected static function split_lab_result_title($title) {
        $title = html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES, get_bloginfo('charset'));
        $title = str_replace("\xC2\xA0", ' ', trim($title));

        /* Accept hyphen, en/em dash, minus sign, or whitespace before a trailing dose. */
        $dose_pattern = '(?:\d+(?:\.\d+)?\s*(?:(?:mcg|mg|g|ml|iu|units?)\s*)?[\/+]\s*)+\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)|\d+(?:\.\d+)?\s*(?:mcg|mg|g|ml|iu|units?)';
        if (preg_match('/^(.*?)(?:\s*[-–—−]\s*|\s+)(' . $dose_pattern . ')\s*(\(\s*(?:No\s+DAC|With\s+DAC)\s*\))?\s*$/iu', $title, $match)) {
            $base = trim($match[1]);
            if (!empty($match[3]) && false === stripos($base, $match[3])) {
                $base .= ' - ' . trim($match[3]);
            }
            return array($base, preg_replace('/\s+/', '', trim($match[2])));
        }

        return array($title, '');
    }

    /**
     * Return Lab Results linked to the current WooCommerce product.
     *
     * v1.1.31 supports the new COA mapper stored by the Lab Results system:
     *   _ioncoa_product_id
     *   _ioncoa_variation_id
     *   _ioncoa_strength
     *
     * Explicit Product IDs are preferred. Unmapped legacy Lab Results still use
     * title matching so the rollout is backwards-compatible and does not hide
     * existing reports while records are being mapped.
     */
    protected static function current_product_lab_results() {
        static $results = null;

        if (null !== $results) {
            return $results;
        }

        $results = array();

        $product_id = get_queried_object_id();
        $product = $product_id && function_exists('wc_get_product') ? wc_get_product($product_id) : false;

        if (!$product) {
            return $results;
        }

        $wanted = self::normalize_lab_name($product->get_name());
        $query = new WP_Query(array(
            'post_type'              => 'lab_result',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ));
        $seen = array();

        while ($query->have_posts()) {
            $query->the_post();

            $lab_id = get_the_ID();
            $mapped_product_id = absint(get_post_meta($lab_id, '_ioncoa_product_id', true));
            $mapped_variation_id = absint(get_post_meta($lab_id, '_ioncoa_variation_id', true));
            $mapping_source = sanitize_key((string) get_post_meta($lab_id, '_ioncoa_mapping_source', true));
            $mapped_strength = trim((string) get_post_meta($lab_id, '_ioncoa_strength', true));

            list($lab_name, $legacy_dose) = self::split_lab_result_title(get_the_title());

            if ($mapped_product_id) {
                if ($mapped_product_id !== absint($product_id)) {
                    continue;
                }
            } else {
                /* A deliberate manual "none" must never fall back to title matching. */
                if ('manual_none' === $mapping_source) {
                    continue;
                }

                if (self::normalize_lab_name($lab_name) !== $wanted) {
                    continue;
                }
            }

            $dose = '' !== $mapped_strength ? $mapped_strength : $legacy_dose;
            $batches = get_post_meta($lab_id, '_lab_batches', true);
            if (!is_array($batches)) {
                continue;
            }

            $group_key = $mapped_variation_id ? 'v:' . $mapped_variation_id : 'd:' . strtolower(preg_replace('/[^a-z0-9.]+/i', '', (string) $dose));
            if (!isset($results[$group_key])) {
                $results[$group_key] = array(
                    'dose'         => $dose,
                    'variation_id' => $mapped_variation_id,
                    'batches'      => array(),
                );
            }

            foreach ($batches as $batch) {
                if (!is_array($batch)) {
                    continue;
                }

                $url = !empty($batch['image']) ? $batch['image'] : (!empty($batch['link']) ? $batch['link'] : '');
                if (!$url) {
                    continue;
                }

                $identity = strtolower($group_key . '|' . ($batch['batch'] ?? '') . '|' . $url);
                if (isset($seen[$identity])) {
                    continue;
                }

                $seen[$identity] = true;
                $results[$group_key]['batches'][] = $batch;
            }
        }

        wp_reset_postdata();

        uasort($results, static function ($a, $b) {
            return strnatcasecmp((string) ($a['dose'] ?? ''), (string) ($b['dose'] ?? ''));
        });

        return $results;
    }

    public static function render_live_coa_tab($key = '', $tab = array()) {
        $groups = self::current_product_lab_results();

        if (!$groups) {
            echo '<p>No lab report is currently available for this product.</p>';
            return;
        }

        echo '<div class="ionpd-live-coa-results">';

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $dose = isset($group['dose']) ? (string) $group['dose'] : '';
            $variation_id = !empty($group['variation_id']) ? absint($group['variation_id']) : 0;
            $batches = isset($group['batches']) && is_array($group['batches']) ? $group['batches'] : array();
            if (!$batches) {
                continue;
            }

            $dose_key = strtolower(preg_replace('/[^a-z0-9.]+/i', '', $dose));
            echo '<section class="ionpd-live-coa-dose" data-ionpd-coa-dose="' . esc_attr($dose_key) . '" data-ionpd-coa-variation="' . esc_attr($variation_id) . '">';
            if ('' !== trim($dose)) {
                echo '<h3>' . esc_html($dose) . ' Lab Reports</h3>';
            }

            usort($batches, static function ($a, $b) {
                return (int) !empty($b['current']) <=> (int) !empty($a['current']);
            });

            $current_batches = array();
            $older_batches = array();

            foreach ($batches as $batch) {
                if (!is_array($batch)) {
                    continue;
                }

                if (!empty($batch['current'])) {
                    $current_batches[] = $batch;
                } else {
                    $older_batches[] = $batch;
                }
            }

            if ($current_batches) {
                echo '<div class="ionpd-live-coa-current">';
                echo '<p class="ionpd-live-coa-label">Current verified report</p>';
                echo '<div class="ionpd-live-coa-links">';
                foreach ($current_batches as $batch) {
                    self::render_coa_report_link($batch, true);
                }
                echo '</div></div>';
            } else {
                echo '<p class="ionpd-live-coa-no-current">No report is currently marked as current.</p>';
            }

            if ($older_batches) {
                $older_count = count($older_batches);
                echo '<details class="ionpd-live-coa-archive">';
                echo '<summary>View older reports <span>' . esc_html($older_count) . '</span></summary>';
                echo '<div class="ionpd-live-coa-links ionpd-live-coa-older-links">';
                foreach ($older_batches as $batch) {
                    self::render_coa_report_link($batch, false);
                }
                echo '</div></details>';
            }

            echo '</section>';
        }

        echo '<p class="ionpd-live-coa-dose-empty" hidden>No lab report is available for the selected strength.</p>';
        echo '</div>';
    }

    protected static function render_coa_report_link($batch, $is_current) {
        if (!is_array($batch)) {
            return;
        }

        $url = !empty($batch['image']) ? $batch['image'] : (!empty($batch['link']) ? $batch['link'] : '');
        if (!$url) {
            return;
        }

        $label = !empty($batch['batch']) ? $batch['batch'] : 'View Lab Report';
        $classes = 'ionpd-live-coa-link ' . ($is_current ? 'is-current' : 'is-archived');
        echo '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">';
        echo '<span>' . esc_html($label) . '</span>';
        if ($is_current) {
            echo '<strong>Current</strong>';
        }
        echo '</a>';
    }

    public static function render_coa_tab($key, $tab) {
        if (empty(self::$coa_tab_original_callback) || !is_callable(self::$coa_tab_original_callback)) {
            return;
        }

        ob_start();
        call_user_func(self::$coa_tab_original_callback, $key, !empty(self::$coa_tab_original_tab) ? self::$coa_tab_original_tab : $tab);
        $html = ob_get_clean();

        echo str_replace('Certificate of Authenticity', 'Certificate of Analysis', $html);
    }

    public static function related_heading() {
        return 'Related Products';
    }

    public static function support_section() {
        $logo = 'https://ionpeptide.com/wp-content/uploads/2025/08/IP-01.png';
        $chat_img = 'https://ionpeptide.com/wp-content/uploads/2026/04/Live-Chat-717x1024.webp';
        ?>
        <section class="ionpd-support-section" aria-label="Ion Peptide support">
            <div class="ionpd-support-copy">
                <h2>Dedicated team is here to help</h2>
                <h3>Get a response within minutes</h3>
                <p>Our quality testing process ensures every peptide meets the highest standards for research-grade compounds through independent third-party verification.</p>
                <a class="ionpd-support-button ion-team-live-chat-button" href="#live_chat">Live Chat <span aria-hidden="true">→</span></a>
            </div>
            <div class="ionpd-chat-card" aria-hidden="true">
                <div class="ionpd-chat-head">
                    <img src="<?php echo esc_url($logo); ?>" alt="" loading="lazy" decoding="async">
                    <span>ⓘ</span>
                </div>
                <div class="ionpd-chat-body">
                    <div class="ionpd-chat-row ionpd-chat-left"><span></span><p>Hello! How can we assist you today?</p></div>
                    <div class="ionpd-chat-time">Support: 11 Apr 2026 10:28 a.m</div>
                    <div class="ionpd-chat-row ionpd-chat-right"><p>Do you offer BPC for research purposes?</p></div>
                    <div class="ionpd-chat-time ionpd-chat-time-right">Customer: 11 Apr 2026 10:30 a.m</div>
                    <div class="ionpd-chat-row ionpd-chat-left"><span></span><p>Yes, we offer bulk and kit discounts. All products are tested by third-party labs for purity and verification.</p></div>
                    <div class="ionpd-chat-time">Support: 11 Apr 2026 10:32 a.m</div>
                    <div class="ionpd-chat-row ionpd-chat-right"><p>When and how do you ship?</p></div>
                    <div class="ionpd-chat-time ionpd-chat-time-right">Customer: 11 Apr 2026 10:34 a.m</div>
                </div>
                <div class="ionpd-chat-input"><span>Write a message</span><b>▷</b></div>
                <img class="ionpd-chat-source" src="<?php echo esc_url($chat_img); ?>" alt="" loading="lazy" decoding="async">
            </div>
        </section>
        <?php
    }

    public static function sticky_buy_bar() {
        if (!self::is_product_page()) {
            return;
        }

        global $product;
        if (!($product instanceof WC_Product)) {
            return;
        }
        ?>
        <div id="ionpd-sticky-buy" class="ionpd-sticky-buy" aria-hidden="true">
            <div class="ionpd-sticky-inner">
                <div class="ionpd-sticky-info">
                    <strong class="ionpd-sticky-title"><?php echo esc_html(self::direct_product_title($product->get_id()) ?: $product->get_name()); ?></strong>
                    <span class="ionpd-sticky-subtitle">Order Now, Ships Today</span>
                </div>
                <div class="ionpd-sticky-variants" data-ionpd-sticky-variants></div>
                <div class="ionpd-sticky-qty" data-ionpd-sticky-qty>
                    <button type="button" class="ionpd-sticky-qty-btn" data-ionpd-sticky-minus aria-label="Decrease quantity">−</button>
                    <input type="number" min="1" step="1" value="1" data-ionpd-sticky-qty-input aria-label="Quantity">
                    <button type="button" class="ionpd-sticky-qty-btn" data-ionpd-sticky-plus aria-label="Increase quantity">+</button>
                    <span data-ionpd-sticky-unit-label>1 unit</span>
                </div>
                <div class="ionpd-sticky-price-wrap">
                    <strong data-ionpd-sticky-total>—</strong>
                    <span data-ionpd-sticky-savings></span>
                </div>
                <button type="button" class="ionpd-sticky-add" data-ionpd-sticky-add>Add to Cart</button>
            </div>
        </div>
        <?php
    }
}

register_activation_hook(__FILE__, array('ION_Product_Page_Redesign', 'activate'));
ION_Product_Page_Redesign::init();
