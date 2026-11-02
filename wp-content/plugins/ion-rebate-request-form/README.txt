Ion Rebate Request Form

Version: 2.4.7

Shortcode:
[ion_rebate_form]

This version was rebuilt to follow the same working structure as the Ion Return Request Form plugin.

Main fixes:
- Uses the same asset loading structure as the working return plugin.
- Uses the same AJAX pattern as the working return plugin.
- Uses the same cache-safe nonce behavior as the working return plugin.
- Testing Lab Company is now a normal typing field, not a dropdown.
- No Other / Not Listed logic.
- No required uploads.
- Inline errors appear inside the form.
- Inline success confirmation appears inside the form.
- Saves submissions in WP Admin > Rebate Requests.
- Sends admin notification to support@ionpeptide.com.
- Sends customer confirmation email.

Required fields:
- Name
- Order Number
- Email
- Testing Lab Company
- Test Results Link

Optional fields:
- COA / Test Result File
- Pre-Shipment Photo(s)
- Additional Notes

Accepted uploads:
- JPG
- PNG
- WEBP
- PDF

Max file size:
- 8 MB per file

Install:
1. Deactivate/delete every older Ion Rebate Request Form plugin version.
2. Upload ion-rebate-request-form-v2-2-0.zip in WordPress > Plugins > Add New > Upload Plugin.
3. Activate the plugin.
4. Add the shortcode to the rebate page:
   [ion_rebate_form]
5. Clear all cache/CDN/cache plugin cache.
6. Test in an incognito window.

Important:
Do not keep any older rebate plugin active at the same time.


Changes in v2.3.0:
- Test Results Link is now optional.
- If a Test Results Link is entered, it is still validated as a proper http/https URL.
- Added required agreement checkbox before submit.
- Agreement is saved in WP Admin > Rebate Requests.
- Agreement is included in the admin notification email.


Changes in v2.4.1:
- Added hardcoded Google reCAPTCHA v3 protection.
- Added visible security/reCAPTCHA design block inside the form.
- The form generates a reCAPTCHA token before AJAX submit.
- The server verifies the token with Google before saving or sending the request.
- Minimum score threshold is 0.5.
- Test Results Link remains optional.
- Agreement checkbox remains required.


Changes in v2.4.2:
- Fixed admin dashboard saving behavior.
- Rebate submissions are now saved as published admin-only Rebate Requests, so they show clearly in WP Admin > Rebate Requests.
- Added explicit save failure handling: the form will show an inline error if WordPress cannot save the request.
- Added Dashboard Saved and Request Status fields in the request details.
- Added Request Status column to WP Admin > Rebate Requests.


Changes in v2.4.3:
- Fixed submissions being blocked by reCAPTCHA score/verification failures.
- The form now still saves valid customer submissions to WP Admin > Rebate Requests if Google reCAPTCHA returns a low score or server-side verification issue.
- reCAPTCHA result is stored in each dashboard entry as Passed or Review Required.
- Admin notification now includes reCAPTCHA status, message, and score.
- Lowered the score threshold from 0.5 to 0.3 to reduce false positives for real users.


Changes in v2.4.4:
- Fixed the actual dashboard-saving issue.
- Removed the old "too fast submission" false-success check that could return success before saving the request.
- Stopped JavaScript from resetting ion_rebate_started_at immediately before AJAX submit.
- Success message now only appears after save_request() completes.
- Added Dashboard Saved At field for easier verification.
- Admin list now includes publish/private/draft/pending statuses for Rebate Requests.


Changes in v2.4.5:
- Replaced the client-facing success message with a customer-friendly message.
- Confirmed the hardcoded reCAPTCHA v3 keys match the provided keys.
- reCAPTCHA still runs before submit and verifies server-side.
- Added reCAPTCHA action and hostname to dashboard details and admin email for easier debugging.
- Kept dashboard saving behavior from v2.4.4.


Changes in v2.4.6:
- Removed the visible reCAPTCHA/security design block from the form.
- reCAPTCHA v3 now runs invisibly in the background.
- Dashboard details now show only reCAPTCHA Status, no message, score, action, or hostname.
- Admin notification now includes only reCAPTCHA Status.
- Kept the same hardcoded reCAPTCHA keys provided earlier.


Changes in v2.4.7:
- Fixed global asset loading.
- Rebate form CSS, JS, and Google reCAPTCHA v3 now load only on pages containing [ion_rebate_form] or common rebate page slugs.
- Prevents reCAPTCHA and rebate form scripts from loading on normal pages like /team/.
