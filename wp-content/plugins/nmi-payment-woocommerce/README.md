=== NMI Payment Gateway For WooCommerce (Pro) ===  
Contributors: Pledged Plugins  
Tags: woocommerce Network Merchants (NMI), Network Merchants (NMI), payment gateway, woocommerce, woocommerce payment gateway  
Plugin URI: https://pledgedplugins.com/products/nmi-payment-gateway-woocommerce/  
Requires at least: 4.0  
Tested up to: 6.9  
License: GPLv3  
License URI: https://www.gnu.org/licenses/gpl-3.0.html  

This Payment Gateway For WooCommerce extends the functionality of WooCommerce to accept payments from credit/debit cards using the NMI payment gateway. Since customers will be entering credit cards directly on your store you should sure that your checkout pages are protected by SSL.

== Description ==

`NMI Payment Gateway for WooCommerce` allows you to accept credit cards directly on your WooCommerce store by utilizing the NMI payment gateway.

= Features =

1. Accept Credit Cards directly on your website by using the NMI gateway.
2. No redirecting your customer back and forth.
3. Very easy to install and configure. Ready in Minutes!
4. Safe and secure method to process credit cards using the NMI payment gateway.
5. Internally processes credit cards, safer, quicker, and more secure!

If you need any assistance with this or any of our other plugins, please visit our support portal:  
https://pledgedplugins.com/support

== Installation ==

Easy steps to install the plugin:

1. Upload `nmi-payment-woocommerce` folder/directory to the `/wp-content/plugins/` directory.
2. Activate the plugin (WordPress -> Plugins).
3. Go to the WooCommerce settings page (WordPress -> WooCommerce -> Settings) and select the Payments tab.
4. Under the Payments tab, you will find all the available payment methods. Find the 'NMI' link in the list and click it.
5. On this page you will find all of the configuration options for this payment gateway.
6. Enable the method by using the checkbox.
7. Enter the NMI API keys (Private Key and Public Key).

That's it! You are ready to accept credit cards with your NMI payment gateway now connected to WooCommerce.

== Frequently Asked Questions ==

`Is SSL Required to use this plugin?`  
A valid SSL certificate is required to ensure your customer credit card details are safe and make your site PCI DSS compliant. This plugin does not store the customer credit card numbers or sensitive information on your website.

`Does the plugin support direct updates from the WP dashboard?`  
Yes. You can navigate to WordPress -> Tools -> WooCommerce NMI License page and activate the license key you received with your order. Once that is done you will be able to directly update the plugin to the latest version from the WordPress dashboard itself.

== Changelog ==

5.3.11  
Changed wording for Test Mode to Sandbox Mode  
Used sandbox.nmi.com domain for newer sandbox accounts
Added headers to license update API endpoint  
Fixed state being passed as NA when the address fields are empty  
Updated compatibility info to WordPress 6.9  
Updated "WC tested up to" header to 10.6

5.3.10  
Removed jquery-payment script handle  

5.3.9  
Made compatible with Theme high multi step checkout  
Added backward compatibility for refunds  
Updated "WC tested up to" header to 10.3  

5.3.8  
Made compatible with Avada multi step checkout  
Updated "WC tested up to" header to 10.1  

5.3.7  
Added Dynamic Descriptor option  
Updated "WC tested up to" header to 10.0  

5.3.6  
Added better error handling for card fields validation  
Fixed issues with WC version < 8.4.0  
Fixed gateway log URL  
Updated "WC tested up to" header to 9.9  

5.3.5  
Mentioned payment amount in order note  
Fixed PHP notice on loading translation  
Fixed issue with data erasure  
Added filter for capturing on order status change  
Updated compatibility info to WordPress 6.8  

5.3.4  
Added card validation error to order notes when collect.js is disabled  
Updated "WC tested up to" header to 9.8  

5.3.3  
Added block checkout styling improvements  
Updated compatibility info to WordPress 6.7  
Updated "WC tested up to" header to 9.5  

5.3.2  
Set order status to Failed only once on unsuccessful capture from Edit Order screen  
Added "Capture authorized transaction on status change" option  
Prevent blocking the billing details area for distraction free checkout  
Updated "WC tested up to" header to 9.4  

5.3.1  
Fixed error message not showing on block checkout  
Fixed issue with collect.js being enqueued everywhere  
Updated compatibility info to WordPress 6.6  
Updated "WC tested up to" header to 9.2  

5.3.0  
Added checkout block payments support  
Updated "WC tested up to" header to 9.0  

5.2.8  
Fixed CSS issue with FunnelKit plugin  
Updated "WC tested up to" header to 8.7  
Updated compatibility info to WordPress 6.5  

5.2.7  
Updated "WC tested up to" header to 8.3  
Updated compatibility info to WordPress 6.4  
Declared incompatibility with cart and checkout blocks  

5.2.6  
Updated "WC tested up to" header to 8.2  

5.2.5  
Saved card type and last4 to order meta  
Updated "WC tested up to" header to 8.0  
Updated compatibility info to WordPress 6.3  

5.2.4  
Made compatible with LaForat theme  
Updated "WC tested up to" header to 7.7  

5.2.3  
Made compatible with WooCommerce HPOS  
Fixed captured payments being voided on cancelling orders  
Updated compatibility info to WordPress 6.2  
Updated "WC tested up to" header to 7.5  

5.2.2  
Updated "WC tested up to" header to 7.3  

5.2.1  
Made compatible with Avada Checkout  
Made compatible with Woolentor plugin  
Added minor improvements in code base  
Updated "WC tested up to" header to 7.0  
Updated compatibility info to WordPress 6.1  
Fixed PHP notices  

5.2.0  
Added AVS and CVV responses to order notes  
Capture or void payment if the order is authorized regardless of whether it was changed from on-hold or not  
Saved "authcode" from transaction response to order meta  
Made compatible with CheckoutWC plugin  
Updated "WC tested up to" header to 6.6  
Updated compatibility info to WordPress 6.0  
General code clean up  

5.1.3  
Fixed occasional fatal error during WooCommerce upgrade  
Updated "WC tested up to" header to 5.0  

5.1.2  
Added filter on error message displayed at checkout  
Added error code alongside the failed transaction response reason in order notes  
Added shipping and tax amounts to gateway requests  
Added shipping fields to gateway request  
Updated "WC tested up to" header to 4.9  
Updated minimum WC version to 3.3  
Tested with WordPress 5.6  

5.1.1  
Sanitized user input in POST variable  
Print failed transaction response reason in order notes  
Updated "WC tested up to" header to 4.3  
Fixed order line items  

5.1.0  
Made PCI compliant by adding Collect.js tokenization  
Added functionality for processing transactions via API keys  
Set up environment check and global notices  
Added dashboard refund functionality, and line item and gateway receipt options  

5.0.0  
Modified nmi_request() method to use wp_remote_post() to make it fully GPL compatible  
Removed NMI SDK files  
Added filters for NMI request parameters and transaction POST URL  
Updated "WC tested up to" header to 4.0  

4.0.13  
Updated "WC tested up to" header to 3.9  

4.0.12  
Made compatible with WooCommerce Sequential Order Numbers Pro  
Fixed order status not changing to Failed on decline  
Updated "WC tested up to" header to 3.8  

4.0.11  
Updated "WC tested up to" header to 3.7  

4.0.10  
Removed currency restrictions  

4.0.9  
Fixed customer sites crashing on update  

4.0.8  
Removed $_POST fields from being sent in gateway requests  

4.0.7  
Replaced deprecated function "reduce_order_stock" with "wc_reduce_stock_levels"  

4.0.6  
Updated "WC tested up to" header to 3.6  

4.0.5  
Fixed PHP notices  
Changed logging method  
Removed deprecated script code  
Updated post meta saving method  
Prevented the "state" parameter from being sent in "capture" or "void" transactions  

4.0.4  
Integrated auto-update API  

4.0.3  
Added GDPR retention setting and logic  

4.0.2  
Added GDPR privacy support  
Added "minimum required" and "tested upto" headers  

4.0.1  
Added JCB, Maestro and Diners Club as options for allowed card types  
Made state field default to "NA" to support countries without a state  
Convert card expiry date to MM/YY before processing when customer enters it in MM/YYYY format  
Passed billing details to "Pay for Order" page  

4.0.0  
Added "authorize only" option  
Added logging option  
Added option to restrict card types  
Added test mode option and made HTTPS mandatory for live mode  
Complete overhaul of the plugin with massive improvements to the code base  

3.5.1  
Made "Order Received" link dynamic  
Included customer IP in the data sent to the gateway  
Added POT file for translation  

3.5  
Fixed compatibility issues with other payment gateway plugins  

3.2.1  
Compatible to WooCommerce 2.3.x  
Compatible to WordPress 4.x  

3.0  
Compatible to WooCommerce 2.2.2  
Compatible to WordPress 4.0  

2.0  
Compatible to WooCommerce 2.1.1
