=== NMI Gateway 2 for WooCommerce ===
Contributors: Custom
Tags: woocommerce, nmi, payment gateway, credit card
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A second instance PCI compliant payment gateway for NMI and WooCommerce. Use alongside the original NMI plugin for a second NMI account.

== Description ==

This plugin is a replica of the Pledged Plugins PCI Gateway for NMI, designed to run as a second instance alongside the original. It uses a unique gateway ID (nmi2) so both plugins can be active simultaneously, each connected to a different NMI merchant account.

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

1. Upload the `nmi-gateway-2` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Settings > Payments > NMI Gateway 2
4. Enter your second NMI account's API keys or credentials
5. Enable the gateway

== Changelog ==

= 1.0.0 =
* Initial release - replica of Pledged Plugins NMI PCI Gateway v1.2.10 with unique gateway ID (nmi2)
