<?php
$user_id     = get_current_user_id();
$saved_banks = get_user_meta($user_id, '_fingrid_saved_banks', true);
if (!is_array($saved_banks)) $saved_banks = [];

echo '<h4>My Saved Bank Accounts</h4>'; // <- h4 closing tag fixed (was </h2>)

if (empty($saved_banks)) {
    echo '<p id="no-banks-msg">No bank accounts saved.</p>';
} else {
    echo '<style>#myTable th{font-weight:bold}</style>';
    echo '<table id="myTable" border="1" class="shop_table shop_table_responsive account-orders-table">';
    echo '<thead><tr><th>Bank Name</th><th>Last 4</th><th>Action</th></tr></thead><tbody>';
    foreach ($saved_banks as $bank) {
        echo '<tr>';
        echo '<td>' . esc_html($bank['bank_name']) . '</td>';
        echo '<td>' . esc_html($bank['last4']) . '</td>';
        echo '<td><button class="remove-bank-button button" data-bank-token="' . esc_attr($bank['token']) . '">Remove</button></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p id="no-banks-msg" style="display:none">No bank accounts saved.</p>';
}
?>
