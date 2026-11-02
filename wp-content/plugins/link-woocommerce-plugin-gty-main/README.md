# WooCommerce – Secure Bank Payments via Link Money (US Only)

Accept secure bank (ACH) payments at checkout using Link Money. Customers can pay with a saved bank account or link a new one in a quick redirect flow. Works with both WooCommerce Checkout Blocks and the classic checkout.

⸻

## Requirements
•	WordPress 6.0+

•	WooCommerce 7.9+

•	PHP 7.4+

•	US store (USD currency)

•	Your Link Money Client ID, Client Secret, and Merchant ID

You’ll receive credentials from Link Money. If you don’t have them yet, contact your Link Money representative.

⸻

## Install the plugin (ZIP)
1.	In WordPress Admin Dashboard, go to Plugins → Add New → Upload Plugin.
2.	Click Choose File, select the ZIP you received, then Install Now.
3.	Click Activate.

Updating later? Upload the new ZIP the same way and choose Replace current with uploaded when prompted (or deactivate/delete the old version, then upload the new one).

⸻

## Configure the gateway (one-time)
1.	Go to WooCommerce → Settings → Payments.
2.	Find **_Secure Bank Payment_** and click Manage (or Finish set up).
3.	Enable the method.
4.	Set Environment:
* Sandbox for testing
* Production for live transactions
5. Enter your Client ID, Client Secret, and Merchant ID.
6.	Set a Webhook Secret:
* Enter a strong random string (you choose it).
* Click Save changes. The plugin will automatically (re)subscribe your site to Link Money webhooks using this secret.
7. (Optional) Enable Debug log while testing.

If your site uses a firewall or security plugin, allow inbound POST requests to:

https://YOURDOMAIN.com/wp-json/linkmoney/v1/webhook

⸻

What your customers will see
* At checkout, customers can choose Secure Bank Payment.
* If they’ve used Link Money before, their saved bank account(s) will appear with the bank’s logo.
* They can also select Link a new bank account (they’ll be redirected to Link Money, then returned to your store).

This works with both Checkout Blocks and the classic checkout—no extra setup needed.

⸻

## Refunds

You can issue refunds from the WooCommerce Order screen:
1.	Open the order in WooCommerce → Orders.
2.	Click Refund and enter the amount.
3.	Confirm. The refund is sent to Link Money and the result is noted on the order.

⸻

## Going live checklist
1.	Switch Environment to Production.
2.	Enter Production Client ID, Client Secret, and Merchant ID.
3.	Keep the same Webhook Secret (or set a new one) and Save changes.
4.	Place a small live test order to confirm checkout and webhooks are working.

⸻

Troubleshooting
* Ensure it’s Enabled in WooCommerce → Settings → Payments.
* Confirm your store currency is USD and your store base/location is US.
* If using a page cache, exclude the checkout and cart pages from caching.
* Order statuses not updating
* Confirm your site is publicly reachable (no maintenance mode, basic auth, or firewall blocking).
* Re-enter your Webhook Secret and Save changes to re-subscribe.
* Check logs (below) for webhook errors.
* Redirect loop or blank page after “Link a new bank account”
* Clear any caching (server/CDN) for checkout and thank-you pages.
* Test again in a private/incognito window.

⸻

## Logs (helpful for support)
1.	Go to WooCommerce → Status → Logs.
2.	In the dropdown, select recent logs with sources like linkmoney, webhook, or refund.
3.	Review entries for errors or share them with your developer/Link Money support.

⸻

## Need help?

If you run into issues during setup or checkout testing, contact your Link Money representative or your implementation partner with:
* A brief description of the issue
* Recent log excerpts (see above)
* Your site URL and WordPress/WooCommerce versions

⸻

Thanks for choosing Link Money for secure bank payments!