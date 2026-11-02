<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wp-payment-form-container">
    <form class="wp-payment-form" id="wp-payment-form">
        <h3>Payment Details</h3>
        
        <div class="wp-payment-field">
            <label for="payment-amount">Amount *</label>
            <input type="number" id="payment-amount" name="amount" step="0.01" min="0.01" required>
        </div>
        
        <div class="wp-payment-field">
            <label for="payment-currency">Currency</label>
            <select id="payment-currency" name="currency">
                <option value="USD">USD - US Dollar</option>
                <option value="EUR">EUR - Euro</option>
                <option value="GBP">GBP - British Pound</option>
                <option value="CAD">CAD - Canadian Dollar</option>
                <option value="AUD">AUD - Australian Dollar</option>
            </select>
        </div>
        
        <div class="wp-payment-field">
            <label for="payment-email">Email Address *</label>
            <input type="email" id="payment-email" name="email" required>
        </div>
        
        <div class="wp-payment-field">
            <label for="cardholder-name">Cardholder Name *</label>
            <input type="text" id="cardholder-name" name="cardholder_name" required>
        </div>
        
        <div class="wp-payment-field">
            <label for="card-number">Card Number *</label>
            <input type="text" id="card-number" name="card_number" placeholder="1234 5678 9012 3456" maxlength="23" required>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
            <div class="wp-payment-field">
                <label for="expiry-month">Month *</label>
                <select id="expiry-month" name="expiry_month" required>
                    <option value="">Month</option>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo sprintf('%02d', $i); ?>"><?php echo sprintf('%02d', $i); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="wp-payment-field">
                <label for="expiry-year">Year *</label>
                <select id="expiry-year" name="expiry_year" required>
                    <option value="">Year</option>
                    <?php 
                    $current_year = date('Y');
                    for ($i = 0; $i < 15; $i++): 
                        $year = $current_year + $i;
                    ?>
                        <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="wp-payment-field">
                <label for="cvv">CVV *</label>
                <input type="text" id="cvv" name="cvv" placeholder="123" maxlength="4" required>
            </div>
        </div>
        
        <button type="submit" class="wp-payment-submit">Process Payment</button>
    </form>
</div>

<script>
// Shortcode usage example
function wp_payment_form_shortcode($atts) {
    $atts = shortcode_atts(array(
        'amount' => '',
        'currency' => 'USD',
        'email' => '',
        'description' => '',
    ), $atts);
    
    ob_start();
    include WP_PAYMENT_PLUGIN_PATH . 'templates/payment-form.php';
    return ob_get_clean();
}
add_shortcode('wp_payment_form', 'wp_payment_form_shortcode');
</script>