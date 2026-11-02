<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('MAEF_Plugin_Updater')) {
    class MAEF_Plugin_Updater {
        private $plugin_slug;
        private $plugin_file;
        private $update_url;
        private $plugin_data;
        private $transient_key;

        public function __construct($plugin_file) {
            $this->plugin_file   = $plugin_file;
            $this->plugin_slug   = plugin_basename($plugin_file);
            $this->transient_key = 'maef_update_' . md5($this->plugin_slug);

            // Defer get_plugin_data() to 'init' — calling it in the constructor
            // causes a critical error because pluggable.php isn't loaded yet.
            add_action('init', [$this, 'init_plugin_data']);
            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
            add_filter('plugins_api', [$this, 'plugin_api_call'], 10, 3);
        }

        public function init_plugin_data() {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $this->plugin_data = get_plugin_data($this->plugin_file);

            // Both parent and child define their own constant before instantiating this class.
            // Check for either so this single updater file works for both plugins.
            if (defined('MAEF_PARENT_UPDATE_SERVER')) {
                $this->update_url = MAEF_PARENT_UPDATE_SERVER . 'multi-agency-easy-flow-parent/info.json';
            } elseif (defined('MAEF_CLIENT_UPDATE_SERVER')) {
                $this->update_url = MAEF_CLIENT_UPDATE_SERVER . 'multi-agency-easy-flow-client/info.json';
            }
        }

        public function check_for_updates($transient) {
            if (empty($transient->checked) || empty($this->update_url)) return $transient;
            $update_data = $this->get_remote_update_data();
            if ($update_data && isset($this->plugin_data['Version']) && version_compare($this->plugin_data['Version'], $update_data->new_version, '<')) {
                $transient->response[$this->plugin_slug] = $update_data;
            }
            return $transient;
        }

        public function plugin_api_call($result, $action, $args) {
            $plugin_folder_name = dirname($this->plugin_slug);
            if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $plugin_folder_name) return $result;
            $remote_data = $this->get_remote_info();
            if (!$remote_data) return $result;
            $result = new stdClass();
            $result->name          = $remote_data->name          ?? '';
            $result->slug          = $plugin_folder_name;
            $result->version       = $remote_data->version       ?? '';
            $result->author        = $remote_data->author        ?? '';
            $result->homepage      = $remote_data->homepage      ?? '';
            $result->download_link = $remote_data->download_url  ?? '';
            $result->trunk         = $remote_data->download_url  ?? '';
            $result->requires      = $remote_data->requires      ?? '';
            $result->tested        = $remote_data->tested        ?? '';
            $result->last_updated  = $remote_data->last_updated  ?? '';
            $result->sections      = (array) ($remote_data->sections ?? []);
            return $result;
        }

        private function get_remote_update_data() {
            $remote_info = $this->get_remote_info();
            if (!$remote_info) return false;
            $update_data          = new stdClass();
            $update_data->slug    = dirname($this->plugin_slug);
            $update_data->plugin  = $this->plugin_slug;
            $update_data->new_version = $remote_info->version;
            $update_data->url     = $remote_info->homepage    ?? '';
            $update_data->package = $remote_info->download_url ?? '';
            return $update_data;
        }

        private function get_remote_info() {
            if (empty($this->update_url)) return false;
            $cached_data = get_transient($this->transient_key);
            if ($cached_data) return $cached_data;
            $response = wp_remote_get($this->update_url);
            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return false;
            $data = json_decode(wp_remote_retrieve_body($response));
            if (empty($data) || !isset($data->version)) return false;
            set_transient($this->transient_key, $data, 2 * HOUR_IN_SECONDS);
            return $data;
        }
    }
}
