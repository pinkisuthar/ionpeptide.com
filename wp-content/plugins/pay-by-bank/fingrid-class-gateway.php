<?php
class Fingrid_Gateway extends WC_Payment_Gateway
{
    // Constructor method
    public function __construct()
    {
        $this->has_fields = true;
        $this->id = "my_fingrid_gateway";
        $this->method_title = "Pay By Bank";
        $this->method_description = "Pay by your Bank.";

        // Other initialization code goes here

        $this->supports = array_merge(
            $this->supports,
            ['products', 'wc-payment-blocks']
        );

        $this->init_form_fields();
        $this->init_settings();

        // Get settings.
        $this->test_mode = $this->get_option("test_mode");
        $this->title = $this->get_option("title");
        $this->description = $this->get_option("description");
        $this->client_name = $this->get_option("client_name");
        $this->test_gateway_url = $this->get_option("test_gateway_url");
        $this->test_client_id = $this->get_option("test_client_id");
        $this->test_client_secret = $this->get_option("test_client_secret");
        $this->test_connected_account = $this->get_option("test_connected_account");
        $this->test_script_url = $this->get_option("test_script_url");

        $this->live_gateway_url = $this->get_option("live_gateway_url");
        $this->live_client_id = $this->get_option("live_client_id");
        $this->live_secret = $this->get_option("live_secret");
        $this->live_account = $this->get_option("live_account");
        $this->live_script_url = $this->get_option("live_script_url");

        $this->discount_percentage = $this->get_option("discount_percentage");

        if ($this->discount_percentage > 0) {
            $this->title .= " (" . $this->discount_percentage . "% Off)";
        }

        add_action("woocommerce_update_options_payment_gateways_" . $this->id, [
            $this,
            "process_admin_options",
        ]);
        add_action("wp_enqueue_scripts", [$this, "custom_gateway_enqueue_scripts"]);

        add_action("wp_ajax_generate_link_token", [
            $this,
            "generate_link_token",
        ]);
        add_action("wp_ajax_nopriv_generate_link_token", [
            $this,
            "generate_link_token",
        ]);

        add_action("wp_ajax_exchange_public_token", [
            $this,
            "exchange_public_token",
        ]);
        add_action("wp_ajax_nopriv_exchange_public_token", [
            $this,
            "exchange_public_token",
        ]);
        add_action('admin_footer', [$this, 'inline_update_script']);

        // Current plugin version from plugin header
        $plugin_file = WP_PLUGIN_DIR . '/pay-by-bank/fingrid-gateway.php';
        $plugin_data = get_file_data($plugin_file, ['Version' => 'Version'], 'plugin');
        $this->current_version = $plugin_data['Version'];

        // 🔹 Server version (fetch once from your server JSON or header file)
        $this->server_version = $this->get_server_version();

        add_action('wc_ajax_get_saved_banks', array($this, 'get_saved_banks_ajax'));

        add_action('wp_ajax_fingrid_save_bank_token', array($this, 'fingrid_save_bank_token'));
        //add_action('wp_ajax_nopriv_fingrid_save_bank_token', 'fingrid_save_bank_token');
    }

    public function init_form_fields()
    {
        $this->form_fields = [
            "test_mode" => [
                "title" => "Test mode",
                "label" => "Enable Test Mode",
                "type" => "checkbox",
                "description" =>
                    "Place the payment gateway in test mode using test API keys.",
                "default" => "yes",
                "desc_tip" => true,
            ],
            "title" => [
                "title" => __("Title", "fingrid-custom-payment-gateway"),
                "type" => "text",
                "description" => __(
                    "This controls the title displayed during checkout.",
                    "fingrid-custom-payment-gateway"
                ),
                "default" => __(
                    "Pay By Bank",
                    "fingrid-custom-payment-gateway"
                ),
                "desc_tip" => true,
            ],
            "description" => [
                "title" => __("Description", "fingrid-custom-payment-gateway"),
                "type" => "textarea",
                "description" => __(
                    "This controls the description displayed during checkout.",
                    "fingrid-custom-payment-gateway"
                ),
                "default" => __(
                    "Log in with your online banking credentials to connect and verify your account. Orders may stay \"On Hold\" for up to 3 business days while processing. If you see an error, don’t refresh—check your order status via the My Account icon instead.",
                    "fingrid-custom-payment-gateway"
                ),
                "desc_tip" => true,
            ],
            "client_name" => [
                "title" => "Client Name",
                "type" => "text",
            ],
            "test_gateway_url" => [
                "title" => "Test Gateway URL",
                "type" => "text",
            ],
            "test_client_id" => [
                "title" => "Test Client Id",
                "type" => "password",
            ],
            "test_client_secret" => [
                "title" => "Test Client Secret",
                "type" => "password",
            ],
            "test_connected_account" => [
                "title" => "Test Connected Account",
                "type" => "password",
            ],
            "test_script_url" => [
                "title" => "Test Script URL",
                "type" => "text",
            ],
            "test_redirect_url" => [
                "title" => "Test Redirect URL",
                "type" => "text",
            ],
            "live_gateway_url" => [
                "title" => "Live Gateway URL",
                "type" => "text",
            ],
            "live_client_id" => [
                "title" => "Live Client ID",
                "type" => "password",
            ],
            "live_client_secret" => [
                "title" => "Live Client Secret",
                "type" => "password",
            ],
            "live_connected_account" => [
                "title" => "Live Connected Account",
                "type" => "password",
            ],
            "live_script_url" => [
                "title" => "Production Script URL",
                "type" => "text",
            ],
            "live_redirect_url" => [
                "title" => "Live Redirect URL",
                "type" => "text",
            ],
            "discount_percentage" => [
                "title" => "Discount Percentage",
                "type" => "number",
                "description" =>
                    "Provide a discount percentage (e.g., 5 for 5%) when this gateway is used.",
                "default" => "0",
                "desc_tip" => true,
            ],
            'transaction_speed' => [
                'title' => 'Transaction Process Speed',
                'type' => 'select',
                'description' => 'Choose how quickly transactions should be processed.',
                'default' => 'next_day',
                'options' => [
                    'same_day' => 'Same Day',
                    'next_day' => 'Next Day',
                    'standard' => 'Standard',
                ],
            ],
            "theme_color" => [
                "title" => "Theme Color",
                "type" => "text",
            ],
            "theme_logo" => [
                "title" => "Theme Logo",
                "type" => "text",
            ],
            'post_transaction_status' => [
                'title' => __('Order Status After Bank Transaction initiated', 'woocommerce'),
                'type' => 'select',
                'description' => __('Select the order status to apply after the bank transaction is initiated.'),
                'default' => 'on-hold',
                'desc_tip' => true,
                'options' => wc_get_order_statuses(), // Automatically pulls all WooCommerce order statuses
            ],
            'webhook_success_status' => [
                'title' => __('Order Status After Webhook success', 'woocommerce'),
                'type' => 'select',
                'description' => __('Select the order status to apply after the webhook status is complated.'),
                'default' => 'pending',
                'desc_tip' => true,
                'options' => wc_get_order_statuses(), // Automatically pulls all WooCommerce order statuses
            ],
            'webhook_failed_status' => [
                'title' => __('Order Status After Webhook failed', 'woocommerce'),
                'type' => 'select',
                'description' => __('Select the order status to apply after the webhook status is failed.'),
                'default' => 'failed',
                'desc_tip' => true,
                'options' => wc_get_order_statuses(), // Automatically pulls all WooCommerce order statuses
            ],
            'refund_order_status' => [
                'title' => __('Order Status After Cancel/Refund', 'woocommerce'),
                'type' => 'select',
                'description' => __('Select the order status to apply after refund.'),
                'default' => 'Cancelled',
                'desc_tip' => true,
                'options' => wc_get_order_statuses(), // Automatically pulls all WooCommerce order statuses
            ],
            'allowed_roles_for_transaction_report' => [
                'title' => __('Roles Allowed to View Transaction Report", "fingrid-custom-payment-gateway'),
                'type' => 'multiselect',
                'description' => __('Select which user roles should be allowed to access the Pay By Bank transaction report.'),
                'desc_tip' => true,
                'default' => '',
                'options' => $this->get_editable_roles_options(), // Populates roles dynamically
            ],
            'manual_update' => [
                'title' => 'Manual Update',
                'type' => 'manual_update_button',
                'description' => 'Click the button to manually update this plugin to the latest version.',
            ],
        ];
    }
    public function get_saved_banks_ajax()
    {
        check_ajax_referer('fingrid_nonce', 'nonce');

        $user_id = get_current_user_id();
        $saved_banks = array();

        if ($user_id > 0) {
            $saved_banks = get_user_meta($user_id, '_fingrid_saved_banks', true);
            if (!is_array($saved_banks)) {
                $saved_banks = array();
            }
        }

        // Filter out sensitive data if needed
        $response_banks = array_map(function ($bank) {
            return array(
                'token' => $bank['token'],
                'bank_name' => $bank['bank_name'] ?? '',
                'last4' => $bank['last4'] ?? '',
                'saved_date' => $bank['saved_date'] ?? ''
            );
        }, $saved_banks);

        wp_send_json_success(array(
            'banks' => $response_banks,
            'user_id' => $user_id
        ));
    }
    private function get_server_version()
    {
        $response = wp_remote_get('https://cabbagepay.com/woo-commerce/production/paybybank/plugin-version.json');
        if (is_wp_error($response)) {
            return 'N/A';
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return !empty($data['version']) ? $data['version'] : 'N/A';
    }

    public function fingrid_save_bank_token()
    {
        if (!WC()->session) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        $bank_token = isset($_POST['fingrid_saved_bank_token']) ? sanitize_text_field($_POST['fingrid_saved_bank_token']) : '';

        if (!empty($bank_token)) {
            WC()->session->set('fingrid_saved_bank_token', $bank_token);


            wp_send_json_success(['message' => 'Token saved to session']);
        } else {
            wp_send_json_error(['message' => 'No token provided']);
        }
    }

    public function get_editable_roles_options()
    {
        $roles = wp_roles()->roles;
        $options = [];

        foreach ($roles as $role => $details) {
            $options[$role] = translate_user_role($details['name']);
        }

        return $options;
    }

    public function payment_fields()
    {

        $user_id = get_current_user_id();

        // 🔄 Migrate old single bank token (if any)
        $existing_token = get_user_meta($user_id, '_fingrid_bank_token', true);
        $existing_name = get_user_meta($user_id, '_fingrid_bank_name', true);
        $saved_banks = get_user_meta($user_id, '_fingrid_saved_banks', true);
        $last4 = 'XXXX';

        if (!is_array($saved_banks)) {
            $saved_banks = [];
        }

        // Only migrate if legacy token exists and not already stored
        if (!empty($existing_token) && !empty($existing_name)) {
            $already_exists = false;
            foreach ($saved_banks as $bank) {
                if ($bank['token'] === $existing_token) {
                    $already_exists = true;
                    break;
                }
            }
            if (preg_match('/^(.*)\s+\*{4}(\d{4})$/', $existing_name, $matches)) {
                $existing_name = trim($matches[1]);
                $last4 = $matches[2];
            }

            if (!$already_exists) {
                $saved_banks[] = [
                    'token' => $existing_token,
                    'bank_name' => $existing_name,
                    'last4' => $last4,
                ];

                update_user_meta($user_id, '_fingrid_saved_banks', $saved_banks);

                // Optional: clean up old keys
                delete_user_meta($user_id, '_fingrid_bank_token');
                delete_user_meta($user_id, '_fingrid_bank_name');
            }
        }

        // ✅ Display the saved banks
        if (!empty($saved_banks)) {
            foreach ($saved_banks as $index => $bank) {
                $token = esc_attr($bank['token']);
                $label = esc_html($bank['bank_name'] . ' (**** ' . $bank['last4'] . ')');
                $checked = ($index === 0) ? 'checked' : '';
                echo "<p><label><input type='radio' name='fingrid_bank_choice' value='{$token}' {$checked}> {$label}</label></p>";
            }
            echo '<label><input type="radio" name="fingrid_bank_choice" value="new"/> Add new bank account</label>';
        } else {
            echo $this->get_option("description");

        }
        echo '<div id="saved-bank-selected" style="display:none;">';
        echo '<input type="text" id="fingrid_bank_choice" name="fingrid_bank_choice" placeholder="Bank Token" />';
        echo '</div>';
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        $response = $this->api_process_payment($order_id);

        if ($response["success"]) {
            // Payment was successful
            // Get the selected status from settings

            WC()->session->__unset("bank_token");
            WC()->cart->empty_cart();
            // Redirect to thank you page
            return [
                "result" => "success",
                "redirect" => $this->get_return_url($order),
            ];
        } else {
            // Payment failed
            wc_add_notice(
                __("Payment failed: " . $response["message"], "woocommerce"),
                "error"
            );
            return [
                "result" => "failure",
                //"redirect" => "",
            ];
        }
    }

    // Function to generate link token
    public function generate_link_token()
    {
        //check_ajax_referer('fingrid_nonce', 'nonce');

        wc_get_logger()->debug("Getting link token", ['source' => 'fingrid_gateway_log']);

        $email = $_POST["email"];
        $fname = $_POST["fname"];
        $lname = $_POST["lname"];

        $payment_gateway = WC()->payment_gateways->payment_gateways()[
            "my_fingrid_gateway"
        ];

        $is_test_mode = $payment_gateway->get_option("test_mode") === "yes";

        wc_get_logger()->debug("test mode -" . $is_test_mode, ['source' => 'fingrid_gateway_log']);

        $creds = $is_test_mode
            ? [
                "api_url" => $payment_gateway->get_option("test_gateway_url"),
                "client_id" => $payment_gateway->get_option("test_client_id"),
                "secret" => $payment_gateway->get_option("test_client_secret"),
                "account" => $payment_gateway->get_option("test_connected_account"),
                "redirect_url" => $payment_gateway->get_option("test_redirect_url"),
            ]
            : [
                "api_url" => $payment_gateway->get_option("live_gateway_url"),
                "client_id" => $payment_gateway->get_option("live_client_id"),
                "secret" => $payment_gateway->get_option("live_client_secret"),
                "account" => $payment_gateway->get_option("live_connected_account"),
                "redirect_url" => $payment_gateway->get_option("live_redirect_url"),
            ];

        $client_name = $payment_gateway->get_option("client_name");
        $theme_color = $payment_gateway->get_option("theme_color");
        $theme_logo = $payment_gateway->get_option("theme_logo");

        $api_url = $creds["api_url"] . "link/token/create"; // Replace with the payment gateway token URL

        // Data to be sent in the request
        $data = [
            "client_id" => $creds["client_id"],
            "secret" => $creds["secret"],
            "client_name" => $client_name,
            "redirect_uri" => '',
            "cust_email" => $email,
            "cust_first_name" => $fname,
            "cust_last_name" => $lname,
            "theme_color" => $theme_color,
            "theme_logo" => $theme_logo,
        ];

        // Initialize cURL
        $ch = curl_init();

        // Set the cURL options
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Set headers if needed
        $headers = ["Content-Type: application/json"];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Execute the request
        $response = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error: " . $error_msg);
        }

        // Close the cURL session
        curl_close($ch);

        // Decode the response (assuming it's JSON)
        $response_data = json_decode($response, true);

        // Check if the response contains a token
        if (isset($response_data["link_token"])) {
            //return $response_data['link_token']; // Return the token
            wp_send_json_success([
                "link_token" => $response_data["link_token"],
            ]);
        } else {
            wp_send_json_error(["message" => $response]);
        }
    }

    public function exchange_public_token()
    {

        // check_ajax_referer('fingrid_nonce', 'nonce');

        $public_token = sanitize_text_field($_POST["public_token"]);

        wc_get_logger()->debug("Public Token response: " . $public_token, ['source' => 'fingrid_gateway_log']);

        $payment_gateway = WC()->payment_gateways->payment_gateways()[
            "my_fingrid_gateway"
        ];

        $is_test_mode = $payment_gateway->get_option("test_mode") === "yes";

        $creds = $is_test_mode
            ? [
                "api_url" => $payment_gateway->get_option("test_gateway_url"),
                "client_id" => $payment_gateway->get_option("test_client_id"),
                "secret" => $payment_gateway->get_option("test_client_secret"),
                "account" => $payment_gateway->get_option(
                    "test_connected_account"
                ),
            ]
            : [
                "api_url" => $payment_gateway->get_option("live_gateway_url"),
                "client_id" => $payment_gateway->get_option("live_client_id"),
                "secret" => $payment_gateway->get_option("live_client_secret"),
                "account" => $payment_gateway->get_option(
                    "live_connected_account"
                ),
            ];

        $api_url = $creds["api_url"] . "link/public_token/exchange";

        // Data to be sent in the request
        $data = [
            "client_id" => $creds["client_id"],
            "secret" => $creds["secret"],
            "public_token" => $public_token,
        ];

        // Initialize cURL
        $ch = curl_init();

        // Set the cURL options
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Set headers if needed
        $headers = ["Content-Type: application/json"];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Execute the request
        $response = curl_exec($ch);

        // Check for errors
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL error: " . $error_msg);
        }

        // Close the cURL session
        curl_close($ch);

        // Decode the response (assuming it's JSON)
        $response_data = json_decode($response, true);

        wc_get_logger()->debug('Bank Token response: ' . $response, ['source' => 'fingrid_gateway_log']);

        // Check if the response contains a token
        if (isset($response_data["bank_token"])) {
            if (!WC()->session) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }

            // Store the bank data in session
            WC()->session->set('fingrid_bank_token', $response_data['bank_token']);
            WC()->session->set('fingrid_bank_name', $response_data['bank_name']);
            WC()->session->set('fingrid_bank_last4', $response_data['bank_account_last_four']);
            WC()->session->set('fingrid_token_type', 'new');

            // Also set a timestamp for validation
            WC()->session->set('fingrid_data_timestamp', time());

            // Log session setting for debugging
            wc_get_logger()->debug('Session data set in exchange_public_token: ' . print_r([
                'bank_token' => $response_data['bank_token'],
                'bank_name' => $response_data['bank_name'],
                'last4' => $response_data['bank_account_last_four'],
                'fingrid_token_type' => 'new'
            ], true), ['source' => 'fingrid_gateway_log']);


            wp_send_json_success([
                "bank_token" => $response_data["bank_token"],
                //"bank_name" => $response_data["bank_name"],
                //"last4" => $response_data["bank_account_last_four"],
                //'redirect_url' => wc_get_checkout_url()
            ]);
        } else {
            wp_send_json_error(["message" => $response]);
        }
    }

    public function api_process_payment($order_id)
    {
        // Process the payment
        if ($order_id) {
            //error_log('3. sss api_process_payment ');
            $order = wc_get_order($order_id);


            // ✅ GET DATA FROM SESSION
            if (!WC()->session) {
                WC()->session = new WC_Session_Handler();
                WC()->session->init();
            }

            $bank_token = WC()->session->get('fingrid_bank_token');
            $bank_name = WC()->session->get('fingrid_bank_name');
            $bank_last4 = WC()->session->get('fingrid_bank_last4');
            $data_timestamp = WC()->session->get('fingrid_data_timestamp');
            $fingrid_token_type = WC()->session->get('fingrid_token_type');

            // Log what we retrieved from session
            wc_get_logger()->debug('Session data retrieved in process_payment: ' . print_r([
                'bank_token' => $bank_token,
                'bank_name' => $bank_name,
                'last4' => $bank_last4,
                'timestamp' => $data_timestamp
            ], true), ['source' => 'fingrid_gateway_log']);

            if ($fingrid_token_type === 'new') {
                $user_id = get_current_user_id();

                // Save it
                $this->fingrid_save_bank_token_to_user($user_id, [
                    'token' => $bank_token,
                    'bank_name' => $bank_name,
                    'last4' => $bank_last4,
                ]);

                $selected_token = $bank_token;
            } else {
                // Use selected saved token
                //$selected_token = sanitize_text_field($_POST['fingrid_saved_bank_token']);
                //$selected_token = $order->get_meta('fingrid_saved_bank_token');

                $selected_token = WC()->session->get('fingrid_saved_bank_token');
            }

            wc_get_logger()->debug("selected_bank_token: " . $selected_token, ['source' => 'fingrid_gateway_log']);

            $payment_gateway = WC()->payment_gateways->payment_gateways()[
                "my_fingrid_gateway"
            ];

            $is_test_mode = $payment_gateway->get_option("test_mode") === "yes";

            $creds = $is_test_mode
                ? [
                    "api_url" => $payment_gateway->get_option(
                        "test_gateway_url"
                    ),
                    "client_id" => $payment_gateway->get_option(
                        "test_client_id"
                    ),
                    "secret" => $payment_gateway->get_option(
                        "test_client_secret"
                    ),
                    "account" => $payment_gateway->get_option(
                        "test_connected_account"
                    ),
                ]
                : [
                    "api_url" => $payment_gateway->get_option(
                        "live_gateway_url"
                    ),
                    "client_id" => $payment_gateway->get_option(
                        "live_client_id"
                    ),
                    "secret" => $payment_gateway->get_option(
                        "live_client_secret"
                    ),
                    "account" => $payment_gateway->get_option(
                        "live_connected_account"
                    ),
                ];

            $api_url = $creds["api_url"] . "transaction/move_cabbage"; // Replace with the payment gateway token URL

            $speed = $payment_gateway->get_option('transaction_speed') ?? 'next_day';

            wc_get_logger()->debug("speed: " . $speed, ['source' => 'fingrid_gateway_log']);

            // Data to be sent in the request
            $data = [
                "client_id" => $creds["client_id"],
                "secret" => $creds["secret"],
                "ip_address" => 'WooPlugin: 2025.9.04',
                "metadata" => "OrderId#" . $order->get_id(),
                "final_amount" => $order->get_total(),
                "connected_acct" => $creds["account"],
                "bank_token" => $selected_token,
                "transaction_type" => "charge",
                "billing_type" => "single",
                "speed" => $speed,
            ];

            // Initialize cURL
            $ch = curl_init();

            // Set the cURL options
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            // Set headers if needed
            $headers = ["Content-Type: application/json"];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute the request
            $response = curl_exec($ch);

            // Check for errors
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                curl_close($ch);
                throw new Exception("cURL error: " . $error_msg);
            }

            // Close the cURL session
            curl_close($ch);

            // Decode the response (assuming it's JSON)
            $response_data = json_decode($response, true);

            wc_get_logger()->debug("Transaction response: " . $response, ['source' => 'fingrid_gateway_log']);

            if (isset($response_data["cabbage_return_code"])) {

                if ($response_data["cabbage_return_code"] == "pk1998") {

                    WC()->cart->empty_cart(); // Clear the cart

                    // Optionally store the bank token for future use
                    if ($order->get_user_id()) {
                        $user_id = $order->get_user_id();
                        update_post_meta($order_id, '_fingrid_transaction_id', sanitize_text_field($response_data['transaction_id']));
                        update_post_meta($order_id, '_fingrid_bank_token', sanitize_text_field($selected_token));
                    }
                    $is_payment_successful = true;
                } else {
                    $is_payment_successful = false;
                }


                // Simulate payment processing

                if ($is_payment_successful) {
                    // Mark order as processing   

                    if (WC()->session) {
                        WC()->session->__unset('fingrid_bank_token');
                        WC()->session->__unset('fingrid_bank_name');
                        WC()->session->__unset('fingrid_bank_last4');
                        WC()->session->__unset('fingrid_public_token');
                        WC()->session->__unset('fingrid_token_type');
                        WC()->session->__unset('fingrid_data_timestamp');
                        WC()->session->__unset('fingrid_saved_bank_token');

                        wc_get_logger()->debug('Session data cleaned up', ['source' => 'fingrid_gateway_log']);
                    }

                    $order->payment_complete();

                    $selected_status = $this->get_option('post_transaction_status');

                    // Set the order status
                    if ($selected_status) {
                        $order->update_status(str_replace('wc-', '', $selected_status), '[Pay By Bank] ' . $response_data['transaction_id'] . " initiated via  Pay-by-bank. ");
                    }
                    return [
                        "success" => true, // Change to false to simulate a failed payment
                        "message" => "Payment processed successfully.",
                    ];

                } else {
                    // Payment failed                
                    return [
                        "success" => false, // Change to false to simulate a failed payment
                        "message" => "We cannot use this bank account to perform the payment at this time.",
                    ];
                }
            }
        }
    }

    public function fingrid_save_bank_token_to_user($user_id, $bank_data)
    {

        if (!$user_id || empty($bank_data['token'])) {
            //$logger->error("fingrid: Invalid user_id or missing token", ['source' => 'fingrid']);
            return;
        }

        $saved_banks = get_user_meta($user_id, '_fingrid_saved_banks', true);
        if (!is_array($saved_banks)) {
            $saved_banks = [];
        }

        foreach ($saved_banks as $bank) {
            if ($bank['token'] === $bank_data['token']) {
                //$logger->debug("fingrid: Token already exists, skipping save", ['source' => 'fingrid']);
                return;
            }
        }

        $saved_banks[] = $bank_data;

        $result = update_user_meta($user_id, '_fingrid_saved_banks', $saved_banks);
        //$logger->debug("fingrid: update_user_meta result: " . var_export($result, true), ['source' => 'fingrid']);
    }

    // Add hidden fields and enqueue custom JS
    public function custom_gateway_enqueue_scripts()
    {
        //error_log('4. enque script called!');
        $payment_gateway = WC()->payment_gateways->payment_gateways()["my_fingrid_gateway"];

        $is_test_mode = $payment_gateway->get_option("test_mode") === "yes";

        $script_url = $is_test_mode
            ? $payment_gateway->get_option("test_script_url")  //'https://cabbagepay.com/js/sandbox/cabbage.js?v7' // Sandbox URL for test mode
            : $payment_gateway->get_option("live_script_url"); //'https://cabbagepay.com/js/production/cabbage.js'; // Production URL for live mode

        if (is_checkout()) {
            wp_enqueue_script(
                "cabbage-library",
                $script_url,
                ["jquery"],
                "1.0",
                true
            );
            wp_enqueue_script(
                "fingrid-payment-script",
                plugins_url("/fingrid-payment-script.js", __FILE__),
                ["jquery", "wc-checkout"],
                "1.0",
                true
            );

            wp_enqueue_style(
                'my-plugin-blockui-style',
                plugin_dir_url(__FILE__) . 'fingrid-styles.css',
                [], // No dependencies
                '1.0.0'  // Version
            );

            wp_localize_script("fingrid-payment-script", "customGatewayParams", [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fingrid_nonce'),
                'user_id' => get_current_user_id(),
                'text_loading' => __('Loading your saved banks...', 'woocommerce'),
                'text_select_bank' => __('Select a saved bank:', 'woocommerce'),
                'text_new_bank' => __('Add new bank account', 'woocommerce')
            ]);           
        }
    }

    public function inline_update_script()
    {

        $screen = get_current_screen();

        if (
            !$screen
            || $screen->id !== 'woocommerce_page_wc-settings'
        ) {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $('.fingrid-plugin-update-btn').off('click').on('click', function (e) {
                    e.preventDefault();
                    e.stopImmediatePropagation(); // Add this to prevent multiple handlers

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Updating...');

                    $.post(ajaxurl, {
                        action: 'fingrid_plugin_manual_update',
                        nonce: '<?php echo wp_create_nonce("fingrid_plugin_update_nonce"); ?>'
                    }, function (response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert("Update failed: " + response.data.message);
                            $btn.prop('disabled', false).text('Update Plugin');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function generate_manual_update_button_html($key, $data)
    {
        // Compare versions
        if (version_compare($this->current_version, $this->server_version, '>=')) {
            $status_text = '<span style="color:green; font-weight:bold;">✅ Up to Date</span>';
            $button_html = '<button class="button" disabled>Latest Installed</button>';
        } else {
            $status_text = '<span style="color:red; font-weight:bold;">⚠️ Update Available</span>';
            $button_html = '<button type="button" 
                                            class="button button-primary fingrid-plugin-update-btn" 
                                            data-action="fingrid_plugin_manual_update">
                                            Update Plugin
                                        </button>';
        }

        ob_start(); ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <?php echo esc_html($data['title']); ?>
            </th>
            <td class="forminp">
                <div style="padding:10px; border:1px solid #ccd0d4; background:#f9f9f9; width:25%">
                    <p><strong>Current Version:</strong> <?php echo esc_html($this->current_version); ?></p>
                    <p><strong>Server Version:</strong> <?php echo esc_html($this->server_version); ?></p>
                    <p><strong>Status:</strong> <?php echo $status_text; // NOT esc_html ?></p>
                    <?php echo $button_html; // NOT esc_html ?>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }


}

add_action('wp_ajax_fingrid_plugin_manual_update', function () {
    check_ajax_referer('fingrid_plugin_update_nonce', 'nonce');

    if (!current_user_can('update_plugins')) {
        wp_send_json_error(['message' => 'Permission denied.']);
    }

    $plugin_slug = 'pay-by-bank';
    $plugin_zip_url = 'https://cabbagepay.com/woo-commerce/production/paybybank/pay-by-bank.zip';

    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    include_once ABSPATH . 'wp-admin/includes/file.php';
    include_once ABSPATH . 'wp-admin/includes/misc.php';
    include_once ABSPATH . 'wp-admin/includes/plugin.php';

    global $wp_filesystem;

    $creds = request_filesystem_credentials('', '', false, false, null);
    if (!WP_Filesystem($creds)) {
        wp_send_json_error(['message' => 'Filesystem credentials not available.']);
    }

    // 🔹 Force overwrite options
    add_filter('upgrader_package_options', function ($options) {
        $options['clear_destination'] = true;
        $options['abort_if_destination_exists'] = false;
        return $options;
    });
    $logger = wc_get_logger();
    $logger->debug("Starting manual update...", ['source' => 'fingrid_gateway_log']);
    $logger->debug("Download URL: " . $plugin_zip_url, ['source' => 'fingrid_gateway_log']);

    // Run the upgrade
    $skin = new Automatic_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $upgrader->install($plugin_zip_url);

    // The real result object/array
    $result = $upgrader->result;

    $logger->debug("Raw upgrader result: " . print_r($result, true), ['source' => 'fingrid_gateway_log']);
    $logger->debug("Skin messages: " . print_r($skin->get_upgrade_messages(), true), ['source' => 'fingrid_gateway_log']);

    // Handle errors
    if (is_wp_error($result)) {
        $logger->error("Update failed: " . $result->get_error_message(), ['source' => 'fingrid_gateway_log']);
        wp_send_json_error(['message' => 'Update failed: ' . $result->get_error_message()]);
    }

    if (!empty($result['error'])) {
        $logger->error("Update error: " . $result['error'], ['source' => 'fingrid_gateway_log']);
        wp_send_json_error(['message' => 'Update error: ' . $result['error']]);
    }

    // Try reactivating plugin after install
    if (!empty($result['destination_name'])) {
        $plugin_file = $result['destination_name'] . '/fingrid-payment-gateway.php'; // adjust if needed
        $activation = activate_plugin($plugin_file);

        // if (is_wp_error($activation)) {
        //     $logger->error("Re-activation failed: " . $activation->get_error_message(), ['source' => 'fingrid_gateway_log']);
        // } else {
        //     $logger->debug("Plugin re-activated successfully.", ['source' => 'fingrid_gateway_log']);
        // }

        wp_send_json_success(['message' => 'Plugin updated successfully! Please refresh.']);
    } else {
        wp_send_json_success(['message' => 'Plugin updated failed!']);
    }


});