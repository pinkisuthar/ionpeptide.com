<?php
/*
Plugin Name:  USAPayments - Pay-By-Bank
Description: Accept instant bank-to-bank payments at checkout with the Pay-by-Bank WooCommerce plugin.
Version: 2025.9.20
Author:  FinGrid® Inc 
Author URI: fingrid.io
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: fingrid-gateway
Domain Path: /languages
*/

// Your plugin code goes here

add_action("plugins_loaded", "woocommerce_myplugin", 0);
function woocommerce_myplugin()
{
    if (!class_exists("WC_Payment_Gateway")) {
        return;
    } // if the WC payment gateway class

    include plugin_dir_path(__FILE__) . "fingrid-class-gateway.php";
}

add_filter("woocommerce_payment_gateways", "add_my_fingrid_gateway");

function add_my_fingrid_gateway($gateways)
{
    $gateways[] = "Fingrid_Gateway";
    return $gateways;
}

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
function declare_cart_checkout_blocks_compatibility()
{
    // Check if the required class exists
    if (class_exists("\Automattic\WooCommerce\Utilities\FeaturesUtil")) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            "cart_checkout_blocks",
            __FILE__,
            true
        );
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action(
    "before_woocommerce_init",
    "declare_cart_checkout_blocks_compatibility"
);

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action(
    "woocommerce_blocks_loaded",
    "fingrid_register_order_approval_payment_method_type"
);

/**
 * Custom function to register a payment method type

 */
function fingrid_register_order_approval_payment_method_type()
{
    // Check if the required class exists
    if (
        !class_exists(
            "Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType"
        )
    ) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . "fingrid-class-block.php";

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action("woocommerce_blocks_payment_method_type_registration", function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
        // Register an instance of my_fingrid_gateway_Blocks
        $payment_method_registry->register(new my_fingrid_gateway_Blocks());
    });
}

// Adds a WooCommerce submenu page for transaction report
add_action("admin_menu", "fingrid_payment_add_transaction_report_menu");

function fingrid_payment_add_transaction_report_menu()
{
    add_submenu_page(
        "woocommerce",
        "Pay By Bank Transaction Report",
        "Pay By Bank Transaction Report",
        "manage_woocommerce",
        "fingrid-payment-transactions",
        "fingrid_payment_transaction_report_page"
    );
}

function fingrid_payment_transaction_report_page()
{
    $today = date("Y-m-d");
    $from_date1 = isset($_POST["from_date"]) ? sanitize_text_field($_POST["from_date"]) : $today;
    $to_date1 = isset($_POST["to_date"]) ? sanitize_text_field($_POST["to_date"]) : date("Y-m-d", strtotime("+1 day", strtotime($today)));
    ?>

    <div class="wrap">
        <h1>Pay By Bank Transaction Report</h1>

        <!-- Loader -->
        <div id="transaction-loader"
            style="display:none; position: fixed; top: 0;left: 0; width: 100%; height: 100%; background-color: rgba(16, 16, 16, 0.5); text-align: center; padding-top: 200px;">
            <p style="color:white;">Loading, please wait...</p>
            <img src="https://res.cloudinary.com/dkrvxw1ql/image/upload/v1748873618/ZKZg_yar7rc.gif" style="height:100px"
                alt="Loading..." />
        </div>

        <!-- AJAX Form -->
        <form id="fingrid-transaction-form" action="javascript:void(0)">
            <table style='width:40%'>
                <tr>
                    <td><label for="from_date">From:</label></td>
                    <td><input type="date" name="from_date" id="from_date" value="<?php echo esc_attr($from_date1); ?>"
                            required></td>
                    <td><label for="to_date">To:</label></td>
                    <td><input type="date" name="to_date" id="to_date" value="<?php echo esc_attr($to_date1); ?>" required>
                    </td>
                    <td><button type="submit" id="fetch-transactions-btn" class="button button-primary">Fetch
                            Transactions</button></td>
                </tr>
            </table>

            <div id="transactions-search-box" style="display:none; margin:15px 0;">
                <input type="text" id="transactions-search" placeholder="Search Order ID, Customer, Amount, or Transfer ID"
                    style="width:350px;" />
                <button id="clear-search" class="button">Clear</button>
            </div>
        </form>

        <!-- Transactions Table Placeholder -->
        <div id="transactions-result"></div>

        <!-- jQuery AJAX script -->
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $('#fingrid-transaction-form').on('submit', function (e) {
                    e.preventDefault();

                    var from_date = $('#from_date').val();
                    var to_date = $('#to_date').val();

                    $('#transaction-loader').show();
                    $('#transactions-result').html(''); // Clear old results

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'fingrid_fetch_transactions',
                            from_date: from_date,
                            to_date: to_date
                        },
                        success: function (response) {
                            if (response.success) {
                                $('#transactions-result').html(response.data.html);
                                $('#transactions-search-box').show();
                            } else {
                                alert('Error: ' + response.data.message);
                            }
                        },
                        error: function (xhr, status, error) {
                            alert('AJAX Error: ' + error);
                        },
                        complete: function () {
                            $('#transaction-loader').hide();
                        }
                    });
                });

                // Refund logic (already there)
                $(document).on('click', '.custom-refund-btn', function () {
                    var transaction_id = $(this).data('transaction-id');
                    var bank_token = $(this).data('bank-token');
                    var metadata = $(this).data('meta-data');
                    var amount = $(this).data('amount');
                    if (confirm('Are you sure you want to cancel/refund this transaction?')) {
                        $('#transaction-loader').show();
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'fingrid_gateway_refund_transaction',
                                transaction_id: transaction_id,
                                bank_token: bank_token,
                                metadata: metadata,
                                amount: amount
                            },
                            success: function (response) {
                                alert(response.data.message);
                                //location.reload();
                                // ✅ Re-fetch the transaction report via AJAX
                                var from_date = $('#from_date').val();
                                var to_date = $('#to_date').val();

                                $.ajax({
                                    url: ajaxurl,
                                    method: 'POST',
                                    data: {
                                        action: 'fingrid_fetch_transactions',
                                        from_date: from_date,
                                        to_date: to_date
                                    },
                                    success: function (response) {
                                        if (response.success) {
                                            $('#transactions-result').html(response.data.html);
                                        } else {
                                            alert('Error: ' + response.data.message);
                                        }
                                    },
                                    complete: function () {
                                        $('#transaction-loader').hide();
                                    }
                                });

                            },
                            complete: function () {
                                $('#transaction-loader').hide();
                            }
                        });
                    }
                });

                $('#transactions-search').on('keyup', function () {
                    var value = $(this).val().toLowerCase();

                    $('#transactions-result table tbody tr').each(function () {
                        var rowText = $(this).text().toLowerCase(); // entire row text
                        if (rowText.indexOf(value) > -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });

                // Clear search
                $('#clear-search').on('click', function () {
                    $('#transactions-search').val('');
                    $('#transactions-result table tbody tr').show();
                });
            });

        </script>
    </div>
    <?php
}

add_action('wp_ajax_fingrid_fetch_transactions', 'fingrid_fetch_transactions_ajax');

function fingrid_fetch_transactions_ajax()
{
    wc_get_logger()->debug("Fetching Transactions", ['source' => 'fingrid_gateway_log']);
    // Validate
    // if ( ! current_user_can('manage_woocommerce') ) {
    //     wp_send_json_error( [ 'message' => 'Access denied' ] );
    // }

    $payment_gateway = WC()->payment_gateways->payment_gateways()['my_fingrid_gateway'];

    $settings = $payment_gateway->get_option('allowed_roles_for_transaction_report');

    $allowed_roles = isset($settings) ? (array) $settings : [];

    //wc_get_logger()->debug("Allowed Roles " . print_r($allowed_roles, true), [ 'source' => 'fingrid_gateway_log' ] );

    $current_user = wp_get_current_user();
    $user_roles = (array) $current_user->roles;

    $has_access = false;
    foreach ($user_roles as $role) {
        if (in_array($role, $allowed_roles)) {
            $has_access = true;
            break;
        }
    }

    if (!$has_access) {
        //wp_die(__('You do not have permission to view this report.', 'fingrid_gateway'));
        wp_send_json_error(['message' => 'You do not have permission to view this report.']);
    }

    $from_date = sanitize_text_field($_POST['from_date']);
    $to_date = sanitize_text_field($_POST['to_date']);

    //echo '<pre>From: ' . $from_date . ' | To: ' . $to_date . '</pre>';

    $is_test_mode = $payment_gateway->get_option('test_mode') === 'yes';

    $creds = $is_test_mode
        ? [
            "api_url" => $payment_gateway->get_option("test_gateway_url"),
            "client_id" => $payment_gateway->get_option("test_client_id"),
            "secret" => $payment_gateway->get_option("test_client_secret"),
            "account" => $payment_gateway->get_option("test_connected_account"),
        ]
        : [
            "api_url" => $payment_gateway->get_option("live_gateway_url"),
            "client_id" => $payment_gateway->get_option("live_client_id"),
            "secret" => $payment_gateway->get_option("live_client_secret"),
            "account" => $payment_gateway->get_option("live_connected_account"),
        ];

    $json = [
        "client_id" => $creds["client_id"],
        "secret" => $creds["secret"],
        "connected_acct" => $creds["account"],
        "start_date" => $from_date,
        "end_date" => $to_date,
    ];

    $response = wp_remote_post($creds["api_url"] . "transactions", [
        "headers" => ["Content-Type" => "application/json"],
        "body" => json_encode($json),
        "timeout" => 30,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $body = wp_remote_retrieve_body($response);

    //wc_get_logger()->debug('API Raw Response:' . $body , [ 'source' => 'fingrid_gateway_log' ]) ;

    $data = json_decode($body, true);
    $transactions = $data["transfers"] ?? [];

    ob_start();

    echo '<style> .failed-row { background-color: #ffe6e6 !important; color: #cc0000; } </style>';
    if (empty($transactions)) {
        echo "<p>No transactions found for the selected period.</p>";
    } else {
        echo "<h2>Results</h2>";

        echo '<table class="widefat striped">';
        echo "<thead><tr><th>S.No</th><th>Transaction Date</th><th>Order Id</th><th>Customer</th><th>Status</th><th>Amount</th><th>Transaction Id</th><th>Message</th><th>Cancel/Refund</th></tr></thead>";
        echo "<tbody>";

        $serial = 1;
        foreach ($transactions as $txn) {
            $is_failed = strtolower($txn['status']) === 'failed' || strtolower($txn['status']) === 'cancelled' || strtolower($txn['status']) === 'refunded';
            $row_class = $is_failed ? 'failed-row' : '';
            $disabled = $is_failed ? 'disabled' : '';
            $customer_name = get_billing_name_by_order_id($txn["metadata"]);


            echo "<tr class='{$row_class}'>";
            echo "<td>{$serial}</td>";
            echo "<td>" . esc_html($txn["transaction_date"] ?? "-") . "</td>";
            echo "<td>" . esc_html($txn["metadata"] ?? "-") . "</td>";
            echo "<td>" . esc_html($customer_name) . "</td>";
            echo "<td>" . esc_html($txn["status"] ?? "-") . "</td>";
            echo "<td> $" . esc_html(number_format((float) $txn["final_charged_amount"], 2, '.', '') ?? "-") . "</td>";
            echo "<td>" . esc_html($txn["transaction_id"] ?? "0.00") . "</td>";
            echo "<td>" . esc_html($txn["fail_explanation"] ?? "-") . "</td>";
            echo '<td><button class="button button-secondary custom-refund-btn"
                                data-transaction-id="' . esc_attr($txn['transaction_id']) . '"
                                data-bank-token="' . esc_attr($txn['bank_token']) . '"
                                data-meta-data="' . esc_attr($txn['metadata']) . '"
                                data-amount="' . esc_attr($txn['final_charged_amount']) . '"
                                 ' . $disabled . '>Cancel/Refund</button></td>';

            echo "</tr>";

            $serial++;
        }

        echo "</tbody>";
        echo "</table>";
    }

    $html = ob_get_clean();
    wp_send_json_success(['html' => $html]);
}

function get_billing_name_by_order_id($meta)
{

    //wc_get_logger()->debug('Meta:' . $meta , [ 'source' => 'fingrid_gateway_log' ]) ;

    $order_id = (int) explode('#', $meta)[1];

    //wc_get_logger()->debug('OrderId from Meta:' . $order_id , [ 'source' => 'fingrid_gateway_log' ]) ;

    if ($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            return trim($first_name . ' ' . $last_name);
        }
    }

    // If not found
    return '';
}

add_action('wp_ajax_fingrid_gateway_refund_transaction', 'fingrid_gateway_refund_transaction');

function fingrid_gateway_refund_transaction()
{
    $transaction_id = sanitize_text_field($_POST['transaction_id']);
    $bank_token = sanitize_text_field($_POST['bank_token']);
    $meta = sanitize_text_field($_POST['metadata']);
    $amount = sanitize_text_field($_POST['amount']);

    wc_get_logger()->debug("4. meta -" . $meta, ['source' => 'fingrid_gateway_log']);
    wc_get_logger()->debug("4. bank_token -" . $bank_token, ['source' => 'fingrid_gateway_log']);

    // Call your gateway's refund API endpoint
    WC()->payment_gateways(); // Initialize gateways

    $payment_gateway = WC()->payment_gateways->payment_gateways()["my_fingrid_gateway"];

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

    //wc_get_logger()->debug( 'api url -' . $creds['api_url']);

    $client_id = $creds["client_id"];
    $secret = $creds["secret"];
    $account = $creds["account"];
    $api_url = $creds["api_url"];

    $json = [
        "client_id" => $client_id,
        "secret" => $secret,
        "transfer_id" => $transaction_id,
    ];

    // API call to your payment gateway
    $response = wp_remote_post($api_url . "transaction/cancel", [
        "headers" => ["Content-Type" => "application/json"],
        "body" => json_encode($json),
        "timeout" => 30,
    ]);

    if (is_wp_error($response)) {
        echo '<div class="notice notice-error"><p>Error: ' .
            esc_html($response->get_error_message()) .
            "</p></div>";
        return;
    }

    $body = wp_remote_retrieve_body($response);

    $data = json_decode($body, true);

    //$data["cabbage_return_code"] = "0294";

    if (isset($data["cabbage_return_code"])) {

        if ($data["cabbage_return_code"] == "pk1998") {

            $order_id = (int) explode('#', $meta)[1];
            $order = wc_get_order($order_id);

            $refund_status = $payment_gateway->get_option('refund_order_status');

            // Set the order status
            if ($refund_status) {
                $order->update_status(str_replace('wc-', '', $refund_status), '[Pay By Bank] ' . $data['transaction_id'] . " cancel/refund initiated via Pay-by-bank. ");
            }

            wp_send_json_success(['message' => 'Cancel/Refund successful!']);

            // Set the order status
            if ($refund_status) {
                $order->update_status(str_replace('wc-', '', $refund_status), '[FINGRID] ' . $data['transaction_id'] . " cancel/refund initiated via Pay-by-bank. ");
            }

        } else if ($data["cabbage_return_code"] == "0294") {

            wc_get_logger()->debug("1. Return Code 0294 - " . $transaction_id, ['source' => 'fingrid_gateway_log']);

            $response = api_refund_payment($transaction_id, $bank_token, $meta, $amount);

            wc_get_logger()->debug("Refund response  - " . $response, ['source' => 'fingrid_gateway_log']);

            if ($response["success"]) {

                $order_id = (int) explode('#', $meta)[1];
                $order = wc_get_order($order_id);

                $refund_status = $payment_gateway->get_option('refund_order_status');

                //wc_get_logger()->debug("Refund status  - " . $refund_status, ['source' => 'fingrid_gateway_log']);

                // Set the order status
                if ($refund_status) {
                    $order->update_status(str_replace('wc-', '', $refund_status), '[Pay By Bank] ' . $data['transaction_id'] . " cancel/refund initiated via  Pay-by-bank. ");
                    //wc_get_logger()->debug("Refund status Updated", ['source' => 'fingrid_gateway_log']);
                }

                wp_send_json_success(['message' => '1. Cancel/Refund successful!']);

            } else {
                wp_send_json_error(['message' => 'Cancel/Refund failed']);
            }
        }
    } else {
        wp_send_json_error(['message' => '2. Cancel/Refund failed: ' . $data['message']]);
    }
}

function api_refund_payment($transaction_id, $bank_token, $meta, $amount)
{
    // Process the payment
    if ($transaction_id) {

        // global $wpdb;
        $transaction_id = sanitize_text_field($transaction_id);

        $order_id = (int) explode('#', $meta)[1];
        $order = wc_get_order($order_id);

        // Step 1: Get Order ID from meta
        // $order_id = $wpdb->get_var($wpdb->prepare(
        //     "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_fingrid_transaction_id' AND meta_value = %s LIMIT 1",
        //     $transaction_id
        // ));

        //wc_get_logger()->debug("2. transaction_id -" . $transaction_id, ['source' => 'fingrid_gateway_log']);

        //wc_get_logger()->debug("3. OrderId -" . $order_id, ['source' => 'fingrid_gateway_log']);

        $payment_gateway = WC()->payment_gateways->payment_gateways()["my_fingrid_gateway"];

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

        // Data to be sent in the request
        $data = [
            "client_id" => $creds["client_id"],
            "secret" => $creds["secret"],
            "ip_address" => 'WooPlugin: 2025.9.04',
            "metadata" => "Refund Ord#" . $order_id,
            "final_amount" => round((float) $amount, 2),
            "connected_acct" => $creds["account"],
            "bank_token" => $bank_token,
            "transaction_type" => "send",
            "billing_type" => "single",
        ];

        //wc_get_logger()->debug("Refund Request - " . print_r($data, true), ['source' => 'fingrid_gateway_log']);

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

        //wc_get_logger()->debug("Refund Response - " . print_r($response_data, true), ['source' => 'fingrid_gateway_log']);

        if (isset($response_data["cabbage_return_code"])) {
            if ($response_data["cabbage_return_code"] == "pk1998") {
                $is_payment_successful = true;
            } else {
                $is_payment_successful = false;
            }

            // Simulate payment processing

            if ($is_payment_successful) {
                // Mark order as processing
                //$order->payment_complete();
                $order->add_order_note(
                    __(
                        $response_data['transaction_id'] . " refund initiated via  Pay-by-bank. ",
                        "woocommerce"
                    )
                );
                return [
                    "success" => true, // Change to false to simulate a failed payment
                    "message" => "Refund processed successfully.",
                ];

            } else {
                // Payment failed
                //wc_add_notice( __( 'Refund failed. Please try again.', 'woocommerce' ), 'error' );
                return [
                    "success" => false, // Change to false to simulate a failed refund
                    "message" => "Error processing refund." . $response_data["message"],
                ];
            }
        }
    }
}

add_filter(
    "woocommerce_cart_calculate_fees",
    "apply_custom_gateway_discount",
    10,
    1
);

function apply_custom_gateway_discount($cart)
{
    WC()->payment_gateways(); // Initialize gateways

    // Get chosen payment method
    $chosen_gateway = WC()->session->get("chosen_payment_method");
    if ($chosen_gateway !== "my_fingrid_gateway") {
        return; // Exit early if another gateway is selected
    }

    $payment_gateway = WC()->payment_gateways->payment_gateways()[
        "my_fingrid_gateway"
    ];

    if (!$payment_gateway) {
        return;
    }

    $discount_percentage = floatval(
        $payment_gateway->get_option("discount_percentage")
    );

    if ($discount_percentage > 0) {
        $discount = ($cart->get_subtotal() * $discount_percentage) / 100;
        $cart->add_fee(
            "{$discount_percentage}% Discount (Bank Payment)",
            -$discount,
            false
        );
    }
}

add_action('init', 'fingrid_register_webhook_endpoint');
function fingrid_register_webhook_endpoint()
{
    add_rewrite_rule(
        '^fingrid-webhook/?',
        'index.php?fingrid_webhook=1',
        'top'
    );
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'fingrid_webhook';
    return $vars;
});

add_action('template_redirect', 'fingrid_handle_webhook');

function fingrid_handle_webhook()
{
    if (intval(get_query_var('fingrid_webhook')) !== 1) {
        return;
    }

    // Make sure WP doesn't send theme headers/HTML
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');

    // Read the raw POST body
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);

    // Log for debugging
    wc_get_logger()->debug('Webhook received: ' . $payload, ['source' => 'fingrid_gateway_log']);

    // Basic verification (you can enhance this with signatures, etc.)
    if (empty($data['resourceId']) || empty($data['topic'])) {
        wp_send_json_error(['message' => 'Missing data'], 400);
    }

    // Find the order linked to this transaction
    $transaction_id = sanitize_text_field($data['resourceId']);
    $status = sanitize_text_field(strtolower($data['topic']));
    $webhookId = sanitize_text_field(strtolower($data['id']));

    $order_id = get_fingrid_transaction_details($transaction_id);

    if (!$order_id) {
        wp_send_json_error(['message' => 'Order not found'], 404);
    }

    $order = wc_get_order($order_id);

    $gateways = WC()->payment_gateways()->payment_gateways();
    $gateway = $gateways['my_fingrid_gateway'] ?? null;

    // Update the order status based on your mapping
    if ($status === 'transfer_completed') {

        // Get the selected status from settings
        $selected_status = $gateway->get_option('webhook_success_status');
        // Set the order status
        if ($selected_status) {
            $order->update_status(str_replace('wc-', '', $selected_status), '[Pay By Bank] Transaction status updated via (' . $webhookId . ').');
        }
        //$order->add_order_note( 'Transaction completed via webhook. WebhookId = ' .$webhookId );

    } elseif ($status === 'transfer_failed') {
        // Get the selected status from settings
        $selected_status = $gateway->get_option('webhook_failed_status');
        // Set the order status
        if ($selected_status) {
            $order->update_status(str_replace('wc-', '', $selected_status), '[Pay By Bank] Transaction status updated via (' . $webhookId . ').');
        }

    } else {
        $order->add_order_note('Webhook received: status = ' . $status);
    }

    // Respond with clean JSON (no theme output!)
    wp_send_json_success(['message' => 'Webhook processed'], 200);
}

// Helper to find order ID by transaction ID
function get_order_id_by_transaction_id($transaction_id)
{
    global $wpdb;

    $order_id = $wpdb->get_var($wpdb->prepare("
                SELECT post_id FROM $wpdb->postmeta
                WHERE meta_key = '_fingrid_transaction_id'
                AND meta_value = %s
                LIMIT 1
            ", $transaction_id));

    return $order_id ? intval($order_id) : false;
}

// Register new tab in My Account menu
add_filter('woocommerce_account_menu_items', 'fingrid_add_account_tab', 50);
function fingrid_add_account_tab($items)
{
    $items['fingrid-banks'] = 'Bank Accounts';
    return $items;
}

// Register endpoint
add_action('init', 'fingrid_add_account_endpoint');
function fingrid_add_account_endpoint()
{
    add_rewrite_endpoint('fingrid-banks', EP_ROOT | EP_PAGES);
}

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, 'fingrid_flush_rewrite_rules');
function fingrid_flush_rewrite_rules()
{
    fingrid_add_account_endpoint();
    flush_rewrite_rules();
}

add_action('woocommerce_account_fingrid-banks_endpoint', 'fingrid_account_tab_content');
function fingrid_account_tab_content()
{
    $file_path = plugin_dir_path(__FILE__) . 'pages/account-bank-page.php';
    if (file_exists($file_path)) {
        include $file_path;
    } else {
        echo '<p>Page not found.</p>';
    }
}

// Helper to get transaction details
function get_fingrid_transaction_details($transaction_id)
{
    $payment_gateway = WC()->payment_gateways->payment_gateways()["my_fingrid_gateway"];

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

    $api_url = $creds["api_url"] . "transaction/status"; // Replace with the payment gateway token URL

    // Data to be sent in the request
    $data = [
        "client_id" => $creds["client_id"],
        "secret" => $creds["secret"],
        "transfer_id" => $transaction_id,
    ];

    //wc_get_logger()->debug("Refund Request - " . print_r($data, true), ['source' => 'fingrid_gateway_log']);

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

    //wc_get_logger()->debug("Transaction details Resp - " . print_r($response_data, true), ['source' => 'fingrid_gateway_log']);

    if (isset($response_data["cabbage_return_code"])) {
        if ($response_data["cabbage_return_code"] == "pk1998") {
            $meta = $response_data['transfer'][0]['metadata'];
            $order_id = (int) explode('#', $meta)[1];
            return $order_id;
        } else {
            wc_get_logger()->debug("TransferId :" . $transaction_id . " not found", ['source' => 'fingrid_gateway_log']);
            return 0;
        }

    }

}


add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('fingrid-banks', plugins_url("/pages/fingrid-banks.js", __FILE__), ['jquery'], null, true);
    wp_localize_script('fingrid-banks', 'fingridVars', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fingrid_remove_bank_nonce'),
    ]);
});



add_action('wp_ajax_fingrid_remove_bank_token', 'fingrid_remove_bank_token_cb');
// If this should work for guests too, also add:
// add_action('wp_ajax_nopriv_fingrid_remove_bank_token', 'fingrid_remove_bank_token_cb');

function fingrid_remove_bank_token_cb()
{
    check_ajax_referer('fingrid_remove_bank_nonce', 'nonce');

    $user_id = get_current_user_id();
    $bank_token = isset($_POST['bank_token']) ? sanitize_text_field(wp_unslash($_POST['bank_token'])) : '';

    if (!$user_id || !$bank_token) {
        wp_send_json_error(['message' => 'Invalid request.'], 400);
    }

    $saved_banks = get_user_meta($user_id, '_fingrid_saved_banks', true);
    if (!is_array($saved_banks))
        $saved_banks = [];

    $updated_banks = array_filter($saved_banks, function ($bank) use ($bank_token) {
        return !isset($bank['token']) || $bank['token'] !== $bank_token;
    });

    if (count($updated_banks) === count($saved_banks)) {
        wp_send_json_error(['message' => 'Bank token not found.'], 404);
    }

    update_user_meta($user_id, '_fingrid_saved_banks', array_values($updated_banks));
    wp_send_json_success(['message' => 'Bank removed successfully.']);
}
