Ion Affiliate Compliance Flow

Upload and activate this plugin. It creates/uses:
- /affiliate-agreement/ with [ion_affiliate_agreement]
- /affiliate-application/ with [ion_affiliate_application]
- /affiliate-dashboard/ with [ion_affiliate_account]

Admin review screen:
Users > Affiliate Agreements

Workflow:
1. Applicant accepts the legal guidelines.
2. Agreement snapshot, terms hash, timestamp, IP, user agent, and digital signature are stored in wp_ion_affiliate_agreements.
3. Applicant submits name, email, social links, about text, and requested code.
4. A pending YITH affiliate request is created if YITH WooCommerce Affiliates Premium is active.
5. Admin approves or denies from Users > Affiliate Agreements. Approval enables the YITH affiliate.
6. Approved affiliates see an Affiliate Dashboard link in WooCommerce My Account that opens /affiliate-dashboard/ so the native YITH dashboard flow stays intact.
7. The standalone /affiliate-dashboard/ page receives Ion-styled page, tab, table, form, and button styling without hijacking YITH tab URLs.
