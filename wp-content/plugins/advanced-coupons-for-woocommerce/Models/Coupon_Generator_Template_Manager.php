<?php
namespace ACFWP\Models;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Coupon Generator Template Manager.
 *
 * Handles file-based storage for AI-generated coupon templates.
 *
 * Note: This is a standalone static utility class, not a Model.
 * It does not extend Base_Model or implement Model_Interface because
 * it has no hooks, no dependencies, and is consumed as a stateless helper
 * by API_Coupon_Generator_Storage.
 *
 * @since 4.1
 */
class Coupon_Generator_Template_Manager {

    const BASE_DIR         = 'advanced-coupons/ai-templates';
    const HTACCESS_CONTENT = "# Apache 2.2\nDeny from all\n\n# Apache 2.4\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>";

    /**
     * Initialize and return the WordPress filesystem instance.
     *
     * @since 4.1
     *
     * @return \WP_Filesystem_Base|false Filesystem instance or false on failure.
     */
    private static function _get_filesystem() {
        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        return $wp_filesystem;
    }

    /**
     * Get user's template directory path.
     *
     * @since 4.1
     *
     * @param int $user_id User ID.
     * @return string|false Directory path or false on failure.
     */
    public static function get_user_dir( $user_id ) {
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) ) {
            return false;
        }

        return $upload_dir['basedir'] . '/' . self::BASE_DIR . '/user-' . (int) $user_id;
    }

    /**
     * Ensure user directory exists.
     *
     * @since 4.1
     *
     * @param int $user_id User ID.
     * @return string|false Directory path or false on failure.
     */
    public static function ensure_user_dir( $user_id ) {
        $dir = self::get_user_dir( $user_id );

        if ( ! file_exists( $dir ) ) {
            $created = wp_mkdir_p( $dir );

            if ( ! $created ) {
                return false;
            }

            $wp_filesystem = self::_get_filesystem();

            if ( ! $wp_filesystem ) {
                return false;
            }

            // Add .htaccess for security (supports both Apache 2.2 and 2.4).
            // Note: Nginx servers require manual configuration to deny access to this directory.
            $htaccess_written = $wp_filesystem->put_contents( $dir . '/.htaccess', self::HTACCESS_CONTENT );

            if ( false === $htaccess_written ) {
                return false;
            }

            // Add index.php to prevent directory listing on servers without .htaccess support.
            $index_written = $wp_filesystem->put_contents( $dir . '/index.php', '<?php // Silence is golden.' );

            if ( false === $index_written ) {
                return false;
            }
        }

        return $dir;
    }

    /**
     * Save template to file.
     *
     * @since 4.1
     *
     * @param array  $template_data Template data.
     * @param string $prompt        Original AI prompt.
     * @param string $custom_name   Custom template name.
     * @return string|false Template ID on success, false on failure.
     */
    public static function save_template( $template_data, $prompt = '', $custom_name = '' ) {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return false;
        }

        $dir = self::ensure_user_dir( $user_id );

        if ( false === $dir ) {
            return false;
        }

        $wp_filesystem = self::_get_filesystem();

        if ( ! $wp_filesystem ) {
            return false;
        }

        // Generate unique filename.
        $id       = 'template-' . time() . '-' . wp_generate_password( 8, false );
        $filename = $id . '.json';
        $filepath = $dir . '/' . $filename;

        // Mark template data as AI-generated for reliable detection during enrichment.
        $template_data['generated_by_ai'] = true;

        // Prepare data.
        $data = array(
            'id'            => $id,
            'title'         => $custom_name ? $custom_name : self::generate_title( $template_data ),
            'prompt'        => $prompt,
            'template_data' => $template_data,
            'created_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'user_id'       => $user_id,
        );

        // Save to file.
        $json_data = wp_json_encode( $data );

        if ( false === $json_data ) {
            return false;
        }

        $result = $wp_filesystem->put_contents( $filepath, $json_data );

        return $result ? $id : false;
    }

    /**
     * List all templates for user.
     *
     * @since 4.1
     *
     * @param int|null $user_id User ID (defaults to current user).
     * @return array Array of templates.
     */
    public static function list_templates( $user_id = null ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            return array();
        }

        $dir = self::get_user_dir( $user_id );

        if ( ! file_exists( $dir ) ) {
            return array();
        }

        $files = glob( $dir . '/template-*.json' );

        if ( ! is_array( $files ) ) {
            return array();
        }

        $wp_filesystem = self::_get_filesystem();

        if ( ! $wp_filesystem ) {
            return array();
        }

        $templates = array();

        foreach ( $files as $file ) {
            $data = json_decode( $wp_filesystem->get_contents( $file ), true );

            if ( JSON_ERROR_NONE !== json_last_error() || ! $data ) {
                continue;
            }

            $templates[] = array(
                'id'         => $data['id'],
                'title'      => $data['title'],
                'prompt'     => isset( $data['prompt'] ) ? $data['prompt'] : '',
                'created_at' => $data['created_at'],
            );
        }

        // Sort by created_at descending.
        usort(
            $templates,
            function ( $a, $b ) {
                $a_time = isset( $a['created_at'] ) ? strtotime( $a['created_at'] ) : 0;
                $b_time = isset( $b['created_at'] ) ? strtotime( $b['created_at'] ) : 0;
                return $b_time - $a_time;
            }
        );

        return $templates;
    }

    /**
     * Get single template by ID.
     *
     * @since 4.1
     *
     * @param string   $id      Template ID.
     * @param int|null $user_id User ID (defaults to current user).
     * @return array|false Template data or false if not found.
     */
    public static function get_template( $id, $user_id = null ) {
        // Validate ID to prevent path traversal.
        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
            return false;
        }

        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            return false;
        }

        $dir      = self::get_user_dir( $user_id );
        $filepath = $dir . '/' . $id . '.json';

        if ( ! is_file( $filepath ) ) {
            return false;
        }

        $wp_filesystem = self::_get_filesystem();

        if ( ! $wp_filesystem ) {
            return false;
        }

        $data = json_decode( $wp_filesystem->get_contents( $filepath ), true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! $data ) {
            return false;
        }

        // Verify ownership.
        if ( isset( $data['user_id'] ) && (int) $data['user_id'] === (int) $user_id ) {
            return $data;
        }

        return false;
    }

    /**
     * Delete template by ID.
     *
     * @since 4.1
     *
     * @param string   $id      Template ID.
     * @param int|null $user_id User ID (defaults to current user).
     * @return bool True on success, false on failure.
     */
    public static function delete_template( $id, $user_id = null ) {
        // Validate ID to prevent path traversal.
        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
            return false;
        }

        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id ) {
            return false;
        }

        $dir      = self::get_user_dir( $user_id );
        $filepath = $dir . '/' . $id . '.json';

        if ( ! is_file( $filepath ) ) {
            return false;
        }

        $wp_filesystem = self::_get_filesystem();

        if ( ! $wp_filesystem ) {
            return false;
        }

        // Verify ownership before delete.
        $data = json_decode( $wp_filesystem->get_contents( $filepath ), true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! $data ) {
            return false;
        }

        if ( isset( $data['user_id'] ) && (int) $data['user_id'] === (int) $user_id ) {
            return $wp_filesystem->delete( $filepath );
        }

        return false;
    }

    /**
     * Generate title from template data.
     *
     * @since 4.1
     *
     * @param array $template_data Template data.
     * @return string Generated title.
     */
    private static function generate_title( $template_data ) {
        $discount_type = isset( $template_data['discount_type'] ) ? $template_data['discount_type'] : 'discount';
        $amount        = '';

        $fields = array();
        if ( isset( $template_data['template_data'] ) ) {
            $fields = $template_data['template_data'];
        } elseif ( isset( $template_data['coupon_template_data'] ) ) {
            $fields = $template_data['coupon_template_data'];
        }

        foreach ( $fields as $field ) {
            if ( isset( $field['field'] ) && 'coupon_amount' === $field['field'] ) {
                $amount = isset( $field['pre_filled_value'] ) ? $field['pre_filled_value'] : '';
                break;
            }
        }

        if ( $amount ) {
            $currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';

            switch ( $discount_type ) {
                case 'percent':
                    /* translators: %s: Discount percentage. */
                    return sprintf( __( '%s%% off coupon', 'advanced-coupons-for-woocommerce' ), $amount );
                case 'fixed_cart':
                    /* translators: %1$s: Currency symbol, %2$s: Discount amount. */
                    return sprintf( __( '%1$s%2$s off cart coupon', 'advanced-coupons-for-woocommerce' ), $currency, $amount );
                case 'fixed_product':
                    /* translators: %1$s: Currency symbol, %2$s: Discount amount. */
                    return sprintf( __( '%1$s%2$s off product coupon', 'advanced-coupons-for-woocommerce' ), $currency, $amount );
            }
        }

        return __( 'AI Generated Coupon', 'advanced-coupons-for-woocommerce' );
    }
}
