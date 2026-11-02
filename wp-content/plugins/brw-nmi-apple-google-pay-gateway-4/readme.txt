=== BRW NMI Apple / Google Pay Gateway 4 (Argyle) ===
Contributors: Custom
Tags: woocommerce, nmi, payment gateway, credit card
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.8
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

An independent fourth PCI-compliant NMI gateway instance for WooCommerce. Use it for Argyle alongside the existing NMI 3 plugin without sharing gateway settings.

== Description ==

Licensed WooCommerce card, Apple Pay, and Google Pay processing for compatible NMI-powered merchant accounts. Its nmi4 identifiers allow it to operate alongside the older nmi3 gateway without overwriting that gateway's settings.

**Features:**
* PCI compliant via Collect.js tokenization
* Supports both API Key and Legacy Username/Password authentication
* Authorization and Capture support
* Refund support
* WooCommerce Blocks checkout support
* Line items in transaction descriptions
* Gateway receipt emails
* Full logging and debug options

== Installation ==

1. Upload the `brw-nmi-apple-google-pay-gateway-4` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments > NMI - Card
4. Enter the Argyle NMI account's API keys and public tokenization key
5. Enable the gateway

== Changelog ==

= 1.0.0 =
* Initial commercial release with card, Apple Pay, Google Pay, and BRW licensing support.
