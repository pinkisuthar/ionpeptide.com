=== Monarch ACH Payment Gateway for WooCommerce ===
Contributors: monarchtechnologies
Tags: woocommerce, payment gateway, ach, monarch
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 2.23
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure ACH bank transfers for WooCommerce via Monarch.

== Description ==

ACH payment gateway for WooCommerce via the Monarch API.

---

# Monarch ACH Payment Gateway for WooCommerce

A custom WooCommerce payment gateway plugin that enables ACH (Automated Clearing House) bank transfers through the Monarch payment processing API.

---

## Overview

This plugin integrates Monarch's ACH payment system into WooCommerce, allowing customers to pay directly from their bank accounts. It supports both automatic bank verification (via Yodlee/FastLink) and manual bank entry for flexibility.

---

## Features

### For Customers
- **Connect Bank Account**: Customers can securely link their bank account during checkout
- **Two Connection Methods**:
  - **Automatic**: Uses Monarch's embedded bank linking (Yodlee/FastLink) for secure, verified connections
  - **Manual Entry**: Allows customers to enter bank details directly (routing number, account number)
- **Saved Bank Account**: Once connected, the bank account is saved for future purchases
- **Disconnect Option**: Customers can disconnect their bank and connect a different one

### For Store Owners
- **Admin Configuration**: Easy setup through WooCommerce payment settings
- **Test Mode**: Sandbox environment for testing before going live
- **Transaction Logging**: All API calls and transactions are logged for debugging
- **Order Notes**: Detailed transaction information added to each order

---

## How It Works

### The Payment Flow (4 Steps)

1. **Create Organization**: When a customer clicks "Connect Bank Account", we create a purchaser organization in Monarch's system using their billing information (name, email, address, phone, date of birth).

2. **Create PayToken**: The customer's bank account details are tokenized. This creates a secure token representing their bank account without storing sensitive data directly.

3. **Assign PayToken**: The bank account token is linked to the customer's organization, establishing the payment relationship.

4. **Sale Transaction**: When the customer places an order, a sale transaction is processed using their verified bank account.

### Bank Connection Methods

#### Automatic (Recommended)
- Opens a modal with Monarch's embedded bank linking interface
- Customer logs into their bank through a secure third-party service (Yodlee)
- Bank account is verified automatically
- Most secure option with instant verification

#### Manual Entry
- Customer enters bank details directly:
  - Bank Name
  - Routing Number (9 digits)
  - Account Number
  - Account Type (Checking/Savings)
- Useful for testing or when automatic linking isn't available

---

## Technical Implementation

### Plugin Structure

```
wordpress-payment/
├── woocommerce-monarch-ach.php      # Main plugin file
├── assets/
│   ├── css/
│   │   └── monarch-ach.css          # Checkout form styling
│   └── js/
│       └── monarch-ach.js           # Frontend JavaScript
└── includes/
    ├── class-monarch-api.php        # Monarch API wrapper
    ├── class-wc-monarch-ach-gateway.php  # WooCommerce gateway
    ├── class-wc-monarch-admin.php   # Admin settings
    └── class-wc-monarch-logger.php  # Logging utility
```

### Key Configuration Settings

| Setting | Description |
|---------|-------------|
| Enable/Disable | Turn the payment method on or off |
| Title | Payment method name shown at checkout |
| Description | Text displayed to customers |
| API Key | Your Monarch API key |
| App ID | Your Monarch application ID |
| Merchant Org ID | Your merchant organization ID |
| Partner Name | Your partner name in Monarch's system |
| Test Mode | Enable sandbox environment for testing |

### API Credentials

The plugin handles two types of credentials:

1. **Partner/Merchant Credentials**: Used to create organizations and initial setup
2. **Purchaser Org Credentials**: Each customer organization receives its own API credentials, which are used for processing their transactions

This dual-credential system is required by Monarch's API architecture.

### ODFI Endpoint

The plugin uses ODFI210 for the sandbox environment. This setting determines the routing for ACH transactions.

---

## Data Storage

### Customer Data (WordPress User Meta)
- `_monarch_org_id`: Customer's Monarch organization ID
- `_monarch_user_id`: Customer's Monarch user ID
- `_monarch_paytoken_id`: Tokenized bank account reference
- `_monarch_org_api_key`: Customer's API key for transactions
- `_monarch_org_app_id`: Customer's App ID for transactions

### Transaction Data (Custom Database Table)
- Order ID
- Transaction ID
- Organization ID
- PayToken ID
- Amount and currency
- Transaction status
- Full API response

---

## Checkout Experience

### New Customer Flow
1. Customer selects "Pay with Bank Account (ACH)" at checkout
2. Fills in billing information
3. Enters phone number and date of birth (required by Monarch)
4. Clicks "Connect Bank Account"
5. Modal opens with two tabs: Automatic / Manual Entry
6. Completes bank connection
7. Returns to checkout with bank connected
8. Places order

### Returning Customer Flow
1. Customer selects "Pay with Bank Account (ACH)"
2. Sees their connected bank account displayed
3. Option to disconnect and use a different bank
4. Places order directly

---

## Error Handling

The plugin handles various error scenarios:

- **Email Already Exists**: If a customer tries to connect with an email already registered in Monarch
- **Invalid Bank Details**: Validation for routing numbers (must be 9 digits) and account numbers
- **API Errors**: All API errors are logged and user-friendly messages are displayed
- **Missing Fields**: Required billing fields are validated before bank connection

---

## Security Considerations

- Bank account numbers are never stored directly in WordPress
- All sensitive data is tokenized through Monarch's API
- HTTPS is required for all API communications
- Nonce verification on all AJAX requests
- Credentials are stored securely in WordPress user meta

---

## Testing

### Sandbox Credentials
Get your sandbox credentials from your Monarch dashboard and enter them in the plugin settings:
1. Go to `WooCommerce > Settings > Payments > Monarch ACH`
2. Enable "Test Mode"
3. Enter your sandbox credentials:
   - Sandbox API Key
   - Sandbox App ID
   - Sandbox Merchant Org ID
   - Partner Name

### Test Bank Details
For manual entry testing in sandbox mode, Monarch provides test bank account numbers. Contact Monarch support for the current test account details.

---

## Troubleshooting

### Common Issues

**"Org id is not valid" Error**
- Cause: Using partner credentials instead of purchaser org credentials for sale transactions
- Solution: The plugin automatically uses the correct credentials

**"ODFI is not supported" Error**
- Cause: Using wrong ODFI endpoint
- Solution: Plugin uses ODFI210 for sandbox environment

**"Email already exists" Error**
- Cause: Customer email already registered in Monarch
- Solution: Customer should use "Disconnect" to reset their bank connection

### Debug Logging
Enable WooCommerce logging to see detailed API requests and responses in:
`WooCommerce > Status > Logs`

---

## Version History

### 1.0.0
- Initial release
- Automatic bank connection via Yodlee/FastLink
- Manual bank entry option
- Full 4-step payment flow
- Transaction logging
- Test mode support

---

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- SSL Certificate (HTTPS required)
- Monarch API account

---

## Support

For issues with:
- **This plugin**: Contact the development team
- **Monarch API**: Contact Monarch support
- **Bank verification (Yodlee)**: Contact Monarch support

---

## License

GPL v2 or later
