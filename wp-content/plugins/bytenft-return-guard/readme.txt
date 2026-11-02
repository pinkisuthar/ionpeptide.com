=== ByteNFT Return Guard ===
Requires at least: 5.6
Requires PHP: 7.2
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A defensive add-on for WooCommerce stores that use the ByteNFT Payment Gateway. It hardens the plugin's payment-return endpoint so an order's status changes only with the order's key and ByteNFT's own confirmation.

== Description ==

This add-on is for stores running the ByteNFT Payment Gateway plugin.

On the affected versions, the endpoint a shopper lands on after paying can change an order's payment status without first verifying the order's key or confirming the outcome with ByteNFT. This add-on sits in front of that return and applies a status only when both of these are true:

1. the request carries the order's own key, and
2. ByteNFT's status endpoint reports that outcome for the payment id stored on the order.

ByteNFT's signed server-to-server notification and its after-payment check are left untouched, so genuine payments are still recorded normally. The add-on makes no outbound calls when it loads and does nothing until a matching return arrives. It only ever acts on orders paid with ByteNFT.

This plugin is independent of ByteNFT and is provided as-is, with no warranty. You should also report the underlying issue to ByteNFT so it is fixed in their plugin for every store. If a later ByteNFT release secures the endpoint itself, you can remove this add-on.

== Installation ==

1. In WordPress admin, go to Plugins, then Add New, then Upload Plugin.
2. Choose bytenft-return-guard.zip and click Install Now, then Activate.
3. Nothing else is required. The guard takes effect immediately and only on the ByteNFT return endpoint.

To confirm it is working, go to WooCommerce, then Status, then Logs, and look for the "bytenft-return-guard" log after any ByteNFT checkout activity.

== Frequently Asked Questions ==

= Does this change how my customers pay? =

No. The ByteNFT checkout and the normal payment confirmation are unchanged. The guard only adds a verification step to the return endpoint.

= Will it break if I do not have the ByteNFT plugin installed? =

No. Without the ByteNFT plugin its endpoint does not exist, so the guard never runs.

= My store uses a separate storefront domain. =

Add your storefront host to the allowed redirect hosts:

`add_filter( 'bnft_return_guard_allowed_redirect_hosts', function ( $hosts ) { $hosts[] = 'shop.example.com'; return $hosts; } );`

== Changelog ==

= 1.0.0 =
* Initial release. Verifies the order key and confirms the outcome with ByteNFT before a payment-return request can change an order's status.
