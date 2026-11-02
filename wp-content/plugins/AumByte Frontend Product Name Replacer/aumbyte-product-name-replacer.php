
<?php
/**
 * Plugin Name: AumByte Frontend Product Name Replacer
 * Description: Replaces selected product-name text in frontend HTML without changing the database.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

final class AumByte_Frontend_Product_Name_Replacer {

    private static $replacements = array(
        'AOD-9604 5mg'       => 'AOD-960 5mg',
        'AOD-9604'           => 'AOD-960',
        'BPC-157'            => 'BPC-15',
        'Bronchogen'         => 'Bronchogan',
        'CJC-1295'           => 'CJC-129',
        'Cortagen'           => 'Cortagan',
        'Epithalon'          => 'Epitholon',
        'GHRP-2'             => 'GHRP',
        'GHRP-6'             => 'GHRP',
        'LL-37'              => 'LL-3',
        'MOTs-C'             => 'MOTC',
        'PEG-MGF'            => 'PEG-MG',
        'Pinealon'           => 'Pineal0n',
        'Selank'             => 'Selaank',
        'Semax'              => 'SMX',
        'TB-500'             => 'TB5OO',
        'Thymosin Alpha-1'   => 'Thymosen Alpha 1',
    );

    public static function init() {
        add_action(
            'template_redirect',
            array(__CLASS__, 'start_buffer'),
            0
        );
    }

    public static function start_buffer() {
        if (
            is_admin()
            || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || is_feed()
            || is_trackback()
            || is_robots()
            || is_favicon()
        ) {
            return;
        }

        // Do not buffer non-HTML requests.
        $uri = isset($_SERVER['REQUEST_URI'])
            ? strtolower($_SERVER['REQUEST_URI'])
            : '';

        if (
            strpos($uri, '/wp-json/') !== false
            || strpos($uri, 'wc-ajax=') !== false
        ) {
            return;
        }

        ob_start(array(__CLASS__, 'filter_html'));
    }

    private static function replace_text($text) {
        $map = self::$replacements;

        // Match longer terms first to avoid partial replacements.
        uksort($map, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $pattern = '~' . implode(
            '|',
            array_map(
                function ($term) {
                    return preg_quote($term, '~');
                },
                array_keys($map)
            )
        ) . '~i';

        return preg_replace_callback(
            $pattern,
            function ($match) use ($map) {
                foreach ($map as $from => $to) {
                    if (strcasecmp($match[0], $from) === 0) {
                        return $to;
                    }
                }

                return $match[0];
            },
            $text
        );
    }

    public static function filter_html($html) {
        if (
            !is_string($html)
            || $html === ''
            || stripos($html, '<html') === false
            || stripos($html, '<body') === false
        ) {
            return $html;
        }

        /*
         * Protect executable code, structured data, comments,
         * and elements whose contents should remain untouched.
         */
        $protected = array();

        $html = preg_replace_callback(
            '~<!--.*?-->|<(script|style|noscript|textarea|pre|code)\b[^>]*>.*?</\1\s*>~is',
            function ($match) use (&$protected) {
                $token = 'AUMBYTEPROTECTED' . count($protected) . 'TOKEN';
                $protected[$token] = $match[0];

                return $token;
            },
            $html
        );

        /*
         * Process ordinary text between HTML tags.
         * Also process selected descriptive attributes,
         * without modifying href/src/action URLs.
         */
        $parts = preg_split(
            '~(<[^>]+>)~s',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        foreach ($parts as &$part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '<') {
                // Only update descriptive attributes.
                $part = preg_replace_callback(
                    '~\b(title|alt|aria-label)\s*=\s*(["\'])(.*?)\2~is',
                    function ($match) {
                        return $match[1] . '=' . $match[2]
                            . self::replace_text($match[3])
                            . $match[2];
                    },
                    $part
                );
            } else {
                $part = self::replace_text($part);
            }
        }
        unset($part);

        $html = implode('', $parts);

        // Restore untouched protected sections.
        if ($protected) {
            $html = strtr($html, $protected);
        }

        return $html;
    }
}

AumByte_Frontend_Product_Name_Replacer::init();
