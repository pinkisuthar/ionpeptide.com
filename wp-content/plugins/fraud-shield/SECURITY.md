# Fraud Shield — Security Model & Audit Notes

This document describes the plugin's threat model, the controls it implements,
exactly what customer PII it stores and why, the findings of the most recent
internal security review, and the risks that are accepted by design. It is
written to be auditable: every claim names the function or file that implements
it.

> Last full review: 2026-07-08 (v1.11.0, pre-release). Scope: entire plugin.
> v1.12.0 note: the Verified Customers list (`fs_verified_creds`) and the
> per-account gateway restriction (`_fs_gw_restrict`) are new. A verified
> credential bypasses the velocity lock, the new-account hold, and gateway
> restrictions, but **never** an explicit blocklist / destination-lock entry —
> an explicit block always wins. All new admin write paths are capability- +
> nonce-guarded like the rest of the plugin.
> v1.13.0 note: applying a per-account gateway restriction no longer marks the
> account Verified (that combination silently bypassed the restriction itself);
> the restriction alone releases the customer from the velocity lock and holds,
> and per-account restrictions are evaluated **before** any verified exemption.
> Bulk restrict/release actions (`fs_bulk_gw_restrict`, `fs_bulk_gw_clear`) are
> capability- + nonce-guarded POSTs and refuse an empty gateway set (which
> would fail open).
> v1.14.0 note: ID verification via Didit (`FS_IDV`) introduces the plugin's
> first REST route, its first outbound HTTP calls, its first frontend AJAX
> endpoints, and its first third-party sub-processor. See §2 for the webhook's
> HMAC authentication and the ID-verification endpoints, §3 for what leaves the
> store and what is stored (document images never touch this site), and §5.6–7
> for the limits of what an approved document proves.
> v1.15.0 note: a completed ID verification now also writes a **verified
> credential** (`fs_verified_creds`, marked `src => idv`), so ID-verified
> customers appear on the Verified Customers page. It deliberately does **not**
> set the `_fs_verified` account flag — that flag is the only exemption that
> overrides the blocklist, and an approved document must never readmit an
> explicitly blocked customer. A revoked verification withdraws only credentials
> carrying that marker, never a staff decision. The new per-account
> `_fs_idv_required` flag ("Block checkout & request ID") blocks checkout until
> the customer verifies; it is enforced on all three checkout surfaces, ranks
> below the blocklist, and **fails open** when the feature is disabled or loses
> its credentials so a customer can never be stranded behind a check the store
> cannot run. See §5.9.
> v1.16.0 note: a per-account gateway restriction now records the date it was
> applied and **expires on its own** after `gw_restrict_expire_months` (default
> 6, 0 = never), and an approved ID check releases it early when
> `gw_restrict_release_on_idv` is on. This narrows an enforcement control rather
> than widening one, so the failure mode is a customer regaining payment methods,
> never a blocked customer regaining checkout: the blocklist, destination locks,
> and the ID-required flag are untouched, and a released customer becomes subject
> to the velocity lock and new-account hold again (the restriction was what
> exempted them). Expiry is evaluated at read time from the stored date, so a
> lapsed restriction stops applying without any scheduled task; the sweep that
> deletes lapsed records runs on the Checkout Locks page and once on upgrade, and
> only ever deletes plugin-owned user meta. Records written before v1.16 carry no
> date and are treated as **not expired** until the upgrade stamps them, so the
> unknown-age case fails safe (restriction stands).
>
> v1.16.0 also **closes a bypass** in the new-customer gateway restriction, which
> previously applied only to signed-in accounts: any visitor with no user id
> (a guest, or a customer whose account is created during checkout) was handed
> the full gateway set. The rule is now evaluated from the customer's identity —
> registration date, else the account owning the checkout email, else the first
> indexed order for that email — and **unknown history counts as new**, so the
> default is now to restrict rather than to exempt. The submitted payment method
> is additionally validated server-side on both checkout paths from the same
> policy object the display filter uses, so a stale or cached checkout page can
> no longer place an order on a withheld processor; fail-open behaviour (empty
> allow-list) is identical on both surfaces by construction.
>
> The new **automatic verification** of established clean customers
> (`auto_verify_*`) is an exemption and therefore widens access: a customer
> meeting the age, paid-order, and clean-history conditions is treated as a
> verified credential. Its blast radius is deliberately limited to the automatic
> controls — the gateway restriction, the velocity lock, and the new-account hold.
> It is wired into `is_exempt()`, `is_signal_verified()`, and
> `is_account_gateway_exempt()` only, and **not** into `is_verified_customer()`,
> which is the sole exemption that overrides the blocklist. Blocklist entries,
> destination locks, and `_fs_idv_required` demands are unaffected. The test reads
> the plugin's own order index (no order objects are loaded) and is recomputed on
> every request rather than stored, so a chargeback or refund revokes it at once
> and there is no cached grant to go stale. Note that a PARTIAL refund does not
> change an order's status and so is not counted as unclean.
>
> v1.16.1 note: the gateway filter now runs at `PHP_INT_MAX` and re-registers
> itself on `wp_loaded`, because at its previous priority (20) any plugin that
> rebuilt `woocommerce_available_payment_gateways` afterwards — a payment rotator
> being the realistic case — silently reinstated a withheld method. The filter
> also no longer bails on `admin-ajax.php` requests, where `is_admin()` is true
> while a shopper is being served; genuine wp-admin screens are still exempt. A
> new option, `fs_gw_seen` (non-autoloaded), records the gateway ids observed on
> the storefront so ids registered only on the frontend can be brought under the
> allow-list; it holds gateway ids and method titles, no customer data, is capped
> at 60 entries, and is removed on uninstall. The allow-list's fail-open behaviour
> is unchanged — it remains deliberate, so a misconfiguration cannot leave a
> customer with no way to pay — but Settings now reports when the rule is failing
> open instead of leaving it to be discovered at checkout.
>
> v1.17.0 note: this **relaxes** the name-match control, and the trade-off is
> deliberate. Previously a Didit-approved document whose name did not match the
> customer verified nothing until staff accepted it; now, by default
> (`idv_mismatch_action => 'verify'`), the customer is verified anyway and their
> gateways unlock. The scenario this weakens is the one §5.6 describes: a stolen
> card presented with a third party's genuine ID. What remains is that the
> mismatch is still detected, recorded on the session row as `name_mismatch`,
> stored on the verification record as `name_match => 'no'`, and surfaced with a
> distinct amber badge on the order screen and the customer profile plus its own
> filter on the ID Verifications page — so it is auditable after the fact rather
> than preventive. Set `idv_mismatch_action` to `hold` (Settings → ID
> verification → "On a name mismatch") to restore the preventive posture, or set
> name matching to `strict` to tighten what counts as a match. As before, an ID
> verification never overrides the blocklist, a destination lock, or the
> `_fs_verified` account flag's blocklist exemption. The new checkout banner and
> verification card render escaped, plugin-owned markup with no customer-supplied
> content, and the banner text is stored via `sanitize_text_field()`.
>
> v1.18.0 note: the ID-verification session table (DB v11) now keeps decision
> CONTEXT Didit already returned and the plugin previously discarded: the
> document's issuing state and country, the country/state of the IP the
> verification was performed from, a VPN-or-Tor flag, and Didit's risk-warning
> short descriptions (JSON, capped at 4000 chars). Deliberately still excluded:
> document images, document numbers, dates of birth, raw addresses. This
> context feeds four new risk-score signals and is displayed on the customer
> profile. New high-value order rules can demand a FRESH ID check above
> per-tier amounts; they fail open when Didit is unconfigured (a requirement
> nobody can satisfy is a locked door with no key), never touch the blocklist,
> and exempt the `_fs_verified` account flag, which remains the master
> override. The soft tier narrows payment methods to a configured fallback set
> and fails open to the pre-rule list when that set matches nothing; the hard
> tier refuses the order at submission with a message and hides nothing, so a
> customer can always adjust their cart. Verification records now carry the
> method they were earned by ('didit' or 'staff') and an epoch timestamp used
> for the freshness comparison. Money thresholds compare on a configurable
> basis (item subtotal by default, grand total optionally) resolved by one
> shared helper, so the displayed gateway list and the submit-time check can
> never measure the same order differently.
>
> v1.19.0 note: two new staff-only recovery surfaces on the ID Verifications
> page, both capability- + nonce-guarded POSTs. "Sync from Didit" reads
> `GET /v3/sessions/` with the stored API key and rebuilds verification state
> from the sessions this store created; sessions resolve to customers only
> through the vendor keys the plugin itself issued (`fs-u{id}`, or the guest
> email hash matched against addresses the store already knows) or the contact
> email Didit kept, so a session can never attach to a customer the store
> cannot name, and unmatched approvals become history rows that grant nothing.
> The CSV import reads the upload's tmp file directly (nothing stored), caps
> at 2000 rows, sanitizes every cell, and is idempotent through session-id
> dedupe. Both restore verifications with their ORIGINAL dates, so expiry and
> the high-value freshness rules cannot be reset by a re-import. Note the
> trust consequence: an import grants verified-credential standing to every
> listed email, so treat import files with the same care as the Verified
> Customers list itself. Uninstall now keeps all data unless the merchant
> opted in to removal (see §2); the previous behaviour deleted fraud evidence
> and customers' completed ID verifications as a side effect of the Delete
> button.
>
> v1.20.0 note: the cardholder check (`FS_Cardholder`) adds the plugin's second
> outbound integration — the NMI Query API — and with it a **new class of
> secret**: NMI security keys, stored in `fs_settings` alongside the Didit key
> and entered on the same admin-only settings screen. Treat them as the
> highest-value secrets the plugin holds: depending on the account's user
> permissions, an NMI security key can do far more than query (including
> processing transactions), so the settings copy tells merchants to issue keys
> from a restricted NMI user where their reseller allows it. The keys are used
> server-side only, sent exclusively in the body of HTTPS POSTs to the
> configured endpoint — which the settings sanitizer **forces to https://**,
> falling back to the default on anything else — and never appear on any
> surface other than the settings form itself. The per-gateway cache
> (`fs_card_key_map`) stores an MD5 of the key for identification, never the
> key. Responses are parsed with SimpleXML under `LIBXML_NONET` and without
> entity substitution, so a hostile or compromised endpoint cannot reach XXE.
> What is stored per order is the issuer's result CODES plus masked card data
> (brand, BIN, last four, and NMI's `cc_hash` card fingerprint — a pseudonymous
> per-card identifier, noted in §3); a full card number never reaches this
> site because NMI itself only returns it masked. Enforcement posture: the
> check runs after payment and can only narrow fulfilment — the `hold` action
> moves a Processing order to On-Hold and nothing else; it never voids or
> refunds a payment, never touches Completed orders, and only a positive
> issuer "no match" (or a wrong CVV) counts as failure — "issuer does not
> participate" and other non-verdicts are recorded but can never trigger the
> action, because absence of evidence must not punish a customer. The manual
> re-check (`fs_card_check_now`) is a capability- + nonce-guarded GET-to-POST
> admin action like every other handler; scheduled checks run from WP-Cron
> with no user context, by design, off the `woocommerce_payment_complete`
> hook. Note the honest limit (compare §5.6): AVS attests an *address*, CVV
> attests *possession of the code* — an issuer-verified pass is strong
> evidence the payer controls the cardholder's details, not a proof of
> identity, and legitimate customers do fail AVS (recent moves, some
> international issuers), which is why the default failure action is `flag`,
> not `hold`.
> v1.24.0 note: the API and webhook subsystem (`FS_REST_API`, `FS_API_Keys`,
> `FS_Webhooks`, `FS_API_Admin`) is the first time this plugin exposes its data
> to callers other than the store's own staff, and the first time it makes
> outbound requests to a destination an administrator types in. Both are new
> attack surfaces and are treated as such.
>
> **Authentication.** Credentials are `fsk_<key id>_<secret>` where the secret is
> 192 bits from `random_bytes()`. Only `sha256(secret)` is stored, so a database
> disclosure yields no usable key; the plaintext exists in exactly one HTTP
> response and one 5-minute admin transient. Key stretching is deliberately
> absent and would add nothing: there is no low-entropy password to guess, so the
> attack is the search space itself. Verification is one indexed lookup on the
> public key id plus one `hash_equals()`, never a timing-visible compare.
>
> **Authorization.** Every route declares the single scope it needs and there is
> no wildcard scope, so the credential given to a reporting dashboard cannot
> write to the blocklist. Unknown scopes are dropped at mint time rather than
> stored, so a typo can never widen access and a scope withdrawn in a later
> version stops granting anything on upgrade. Keys additionally support an
> IP/CIDR allow-list that **fails closed**, an expiry, and a per-key rate limit.
>
> **Replay and integrity.** A key can be required — individually or store-wide —
> to sign every request. The signed material is method, REST route, canonical
> sorted query, `sha256(body)`, timestamp and nonce, so nothing security-relevant
> is left unsigned and no two distinct requests share a signature. Acceptance is
> ±300 s and each nonce is refused for 600 s, which makes a captured request
> either a duplicate nonce or an expired timestamp. HTTPS is required by default.
>
> **Caller identity.** `FS_REST_API::client_ip()` reads `REMOTE_ADDR`, *not*
> `X-Forwarded-For`, because an allow-list that trusts a caller-supplied header
> enforces nothing. Trusting proxy headers is an explicit opt-in for stores whose
> proxy overwrites them (compare §5.2, where the same trade-off is accepted in
> the opposite direction for geolocation, which grants nothing).
>
> **SSRF.** A webhook URL is an address this store will connect to on demand.
> `FS_Webhooks::validate_url()` requires HTTPS, rejects embedded credentials,
> resolves the host, and refuses any target in private, loopback, link-local or
> reserved space — which is what stops "add a webhook" from becoming a probe
> against cloud metadata endpoints, internal admin panels or services on
> localhost. Deliveries do not follow redirects and verify TLS. The development
> escape hatch (`allow_private_urls`) is off by default. Accepted limit: DNS is
> checked at validation time only, so a hostname that later re-points into
> private space is not re-validated — the ability to add a webhook is therefore
> a privileged action, and it is gated on `manage_woocommerce` or the
> `manage:webhooks` scope.
>
> **Disclosure.** Authentication failures return an identical `401` whether the
> key id is unknown, the secret is wrong, the key was revoked or expired, or the
> caller's address is not allow-listed; the distinction is written only to the
> store's own capped security log, keyed by the **public** key id. No token,
> secret or pairing code is ever written to that log, to a URL, or to any error
> message. Order and event payloads reduce addresses to the store's existing
> hashes, and a subscription can be configured to replace emails, names and IPs
> with stable SHA-256 digests so a partner can correlate without receiving PII.
>
> **Reach (1.30).** The API now covers every admin screen: three more scopes
> (`write:orders`, `write:customers`, `read:insights`), 45 more routes and ten
> more topics. The Addresses registry and its detail route return the shipping
> address text the Addresses page shows (the order rows still carry only the
> hash), which is why `read:customers` is an investigation scope. Two rules
> kept the expansion from widening the attack surface. Every write
> route calls the *same function* as the admin button it mirrors
> (`FS_Data::set_chargeback()` / `set_recovery()`, `FS_Live::verify_account()` /
> `apply_gw_restrict()` / `release_hold()`, `FS_Blocklist::unblock_action()`),
> so a write over the API has exactly the side effects, notes, ledger rows and
> events a staff click has, and is undone the same way. And the data a refusal
> or a linked cancel records about *third parties* — the matched credentials
> with their provenance, the identities behind a shared credential — is never
> pushed off-site: webhooks carry credential types and card titles (hashed on
> digest subscriptions), while the detail is readable only through authenticated,
> scoped reads (`GET /checkout-locks`, `GET /linked-cancels`, `GET /customers/{ident}`).
> The investigation scopes (`read:customers`, `read:orders`, `read:insights`,
> `read:events`) return customer emails, phones and IPs and should be granted as
> deliberately as the writes.
>
> **Pairing.** The handshake exists so no permanent secret is copied by hand. The
> code is single-use, hashed at rest, expires, is rate-limited to 10 attempts per
> hour per address, and burns after five wrong guesses. It grants only the scopes
> an administrator picked when opening the window.
>
> **Admin surface.** Every mutation is an `admin_post_*` handler with
> `current_user_can( 'manage_woocommerce' )` plus a per-action nonce, exactly like
> the rest of the plugin, and new secrets reach the browser through a short-lived
> transient rather than a query argument (a token in a URL lands in history, in
> the next request's referrer, and in every proxy log on the path).
>
> **Accepted risk.** Webhook signing secrets are stored in plaintext, because
> signing requires the secret itself and WordPress offers no key management that
> would meaningfully protect it — encrypting with a key sitting in the same
> filesystem is theatre. Database access is therefore equivalent to holding those
> secrets; rotation is delete-and-recreate. See also §5 for the standing
> decisions this subsystem inherits.

---

## 1. Threat model

| Actor | Capability | What they might attempt |
|---|---|---|
| Unauthenticated visitor / fraudster | Can browse, register, and submit checkout | Inject hostile data through checkout fields (names, addresses, emails, user agents); probe which credentials are blocked; evade IP/address matching; flood plugin tables |
| Authenticated customer | Same as above plus a logged-in session | Same as above with a persistent identity |
| Shop staff (`manage_woocommerce`) | Full plugin access | Not treated as an adversary — but their **browser and workstation are targets** for data the plugin exports (see CSV injection, §4.1) |
| Hosting environment | Web server, DB, backups | Direct DB/file access is out of the plugin's control; see §6 |

The plugin's core risk profile: it **deliberately collects and displays data
authored by fraudsters**. Every admin surface and every export is therefore
treated as a sink for hostile input.

---

## 2. Control inventory

### Authentication & authorization
- Every admin page and form handler calls `FS_Admin::guard()` →
  `current_user_can( 'manage_woocommerce' )` (`FS_Admin::CAP`).
- Every state-changing handler additionally verifies a **purpose-specific
  nonce** (`check_admin_referer` / `check_ajax_referer`) — 20+ distinct nonce
  actions (`fs_block_cluster`, `fs_unblock_action`, `fs_verify_customer`,
  `fs_geo_backfill`, …), so a token for one operation cannot be replayed
  against another.
- All admin AJAX endpoints (`fs_scan_step`, `fs_import_batch`,
  `fs_cluster_order`, `fs_geo_backfill`, `fs_order_action`) check capability
  **and** nonce.
- Two frontend AJAX endpoints exist for ID verification (`fs_idv_start`,
  `fs_idv_status`). Both are nonce-checked (`fs_idv`); they are registered on
  the `nopriv` side too so a lapsed session gets an honest error instead of a
  bare `0`, but the offer itself is account-scoped — `FS_IDV::should_offer()`
  returns false without a logged-in account, so an anonymous caller cannot
  start a session. Neither endpoint can grant an exemption on its own:
  `fs_idv_start` only mints a Didit session (rate-limited per customer, and
  refused unless a restriction ID verification can actually lift is in force),
  and the verified flag is written only from a Didit decision. The session is
  bound to the **account** identity, never to a client-supplied email, so an
  attacker cannot get a third-party address marked verified with their own
  document.
- One REST route is registered: `POST /wp-json/fraud-shield/v1/didit`, the
  Didit webhook. Its permission callback is open by necessity; authentication
  is an HMAC-SHA256 signature over the raw body compared with `hash_equals()`,
  plus a ±5-minute `X-Timestamp` window. The route is inert until a webhook
  secret is configured, and unsigned or mis-signed requests change nothing.
- Apart from that webhook and the two ID-verification endpoints, no frontend
  endpoint mutates state except the checkout enforcement hooks WooCommerce
  itself invokes.

### SQL injection
- All user-supplied values reach SQL through `$wpdb->prepare()` placeholders.
- Search strings additionally pass `$wpdb->esc_like()`.
- `ORDER BY` / direction are never interpolated from input: every sortable
  report maps the request through a hard-coded whitelist (`$sort_map`) and
  `FS_Data::dir_sql()`.
- The only non-prepared fragments are internal: table names built from
  `$wpdb->prefix`, integer lists passed through `array_map('intval')` before
  `implode()`, and status lists escaped via `esc_sql()`
  (`FS_Data::paid_in_sql()`).

### XSS (stored + reflected)
- Admin output escapes at the sink: `esc_html()` / `esc_attr()` / `esc_url()`
  on every customer-controlled value (names, emails, address text, ledger
  rows, method labels).
- Merchant-authored checkout messages allow limited HTML via `wp_kses_post()`
  on input **and** output; the Store API rejection strips all markup
  (`wp_strip_all_tags`) because the blocks checkout renders plain text.
- The blocked-checkout gate page is a self-contained document assembled from
  escaped parts (`FS_Live::checkout_gate_html()`).

### CSRF
- Covered by the per-action nonces above. State-changing GET links (Block /
  Unblock / Verify toggles) follow the WordPress `admin-post.php` +
  `wp_nonce_url()` convention.

### File handling
- CSV order-import uploads: extension whitelist (`csv`/`txt`),
  `is_uploaded_file()` check, stored under a **random unguessable name**
  (`wp_generate_password(16)`) in `uploads/fraud-shield/`, which is hardened
  with `.htaccess` (`Require all denied`) + blank `index.html`
  (`FS_Importer::ensure_storage_dir()`). Files are deleted on completion,
  cancel, and uninstall; deletion is path-prefix-checked to the storage dir.
- Blocklist import reads the PHP upload tmp file directly; nothing is stored.

### CSV exports (see §4.1)
- Every data row passes `FS_Data::csv_escape_cell()` — spreadsheet formula
  injection (CWE-1236) neutralization — via `FS_Admin::csv_row()` and the
  scanner's index export. Responses send `Cache-Control: no-store` and
  `X-Content-Type-Options: nosniff`.

### Checkout gate hardening
- The blocked page returns **403** with `nocache_headers()`,
  `DONOTCACHEPAGE`, `Cache-Control: no-store` and
  `<meta name="robots" content="noindex">` so a page cache or CDN can never
  cache "you are blocked" for the checkout URL and serve it to everyone
  (`FS_Live::render_gate_and_exit()`).

### Other
- Every PHP file begins with `defined( 'ABSPATH' ) || exit;`;
  `uninstall.php` requires `WP_UNINSTALL_PLUGIN`.
- All redirects use `wp_safe_redirect()` (host-validated); referer fallbacks
  point to `admin_url()`.
- Outbound HTTP is limited to the Didit verification API
  (`https://verification.didit.me`), and only when ID verification is enabled
  and configured — creating a session and reading its decision. Requests go
  through `wp_remote_request()` with a 20-second timeout; the API key travels
  in a header and is never logged or echoed. The other egress is `wp_mail()`
  for merchant-configured alert/hold notifications.
- Ledger writes on the unauthenticated path are deduplicated by a UNIQUE key
  (one row per identity+kind) and only occur for identities that are actually
  blocked — there is no unbounded unauthenticated write amplification.
- Uninstall keeps all data by default (v1.19). When the merchant has opted in
  via "Remove all data on uninstall", deleting the plugin removes all six
  plugin tables, every `fs_*` option, all `_fs_*` order/user meta (classic +
  HPOS), and the upload directory; otherwise only the code is removed and a
  reinstall picks the data back up. The opt-in flag lives inside `fs_settings`
  and is read directly by `uninstall.php`.

---

## 3. Customer PII: what is stored, where, and why

| Store | PII | Purpose | Removal |
|---|---|---|---|
| `fs_order_index` | Normalized email, phone digits, customer name, shipping address text + city/state/postcode, billing/shipping address **SHA-256 hashes**, IP, user agent, coupon codes, totals | Cross-order fraud matching and investigation. Mirrors data WooCommerce already stores in plaintext — the plugin adds a normalized copy, not a new exposure class | Dropped on uninstall; rebuilt from orders by scans |
| `fs_blocklist` | Blocked emails/phones/IPs/user-ids in plaintext; addresses only as SHA-256 hashes; `place` keys | Exact-match checkout enforcement + admin display | Per-entry Remove, per-block Unblock, uninstall |
| `fs_block_actions` | Card titles may embed a customer name/email (profile blocks) | Human-readable grouping | Unblock removes the action; uninstall |
| `fs_checkout_blocks` | Name, email, block reason, attempt timestamps of people actually blocked; since 1.29, the evidence of the latest refusal — each matched credential (email / phone digits / IP / account id in plaintext, the shipping address as typed), who blocked it and when, the owning block card, the reason, and the identities behind the credential (name, normalized email, account id, blocked flag) | The Checkout Locks release queue and the profile's "Refused at checkout" panel: which credentials matched, and whom the customer was matched to | Verify/Allow/Dismiss resolves the queue row (the evidence stays for the profile); uninstall drops |
| `fs_linked_cancels` | Order id, user id, normalized email, customer name, order total; the shared credential (email / phone digits / IP / account id in plaintext, addresses only as SHA-256 hashes) and the blocked entry it leads to (same forms), the owning block card's title, and the identities behind the credential (name, normalized email, account id, blocked flag); retry count and timestamps | The Auto-Cancelled page: evidence for each order the linked-to-blocklist rule cancelled — including which accounts and profiles it leads to — and the count of refused retries | Uninstall drops |
| `fs_prevented` | Per stopped checkout: the control, whether it cancelled an order or refused an attempt, order id, user id, normalized email, customer name, the grand total and currency, attempt count and timestamps; since 1.30 also whether a control or a staff member stopped it, and the blocked identities it was tied to (email, account id, name — the person behind the shared credential or the matched blocklist entry) | The Dashboard's "Fraud prevented" savings panel and the Financial Protection estimate | Uninstall drops |
| Order meta (`_fs_linked_cancel`) | The same evidence as the ledger row, bound to the cancelled order | The Fraud Shield column on WooCommerce → Orders and the order screen | Uninstall (opt-in) |
| Order meta (`_fs_cb_recovered`) and the index's `recovered` column | The amount staff recorded as recovered on a charged-back order (no PII) | Net historical losses on the Financial Protection page | Cleared with the order-screen "clear" action; uninstall (opt-in) |
| `fs_idv_sessions` | Didit session id, user id, normalized email, the billing name we expected, the name and document type Didit extracted from the ID, decision, the document's issuing state/country, the country/state and VPN flag of the IP the check was performed from, Didit's risk-warning texts | Evidence that a customer proved their identity, the name-match audit trail, and the contradiction signals the risk score reads (document vs shipping state, verification geography, VPN use) | Uninstall drops |
| User meta | `_fs_verified`, `_fs_hold` (order id, amount, age), `_fs_gw_restrict` (per-account gateway allow-list + the date it was applied), `_fs_idv` (ID-verification record: session id, document name, expiry), `_fs_idv_required` (an open staff demand for an ID: timestamp + requesting staff id) | Exemptions, verification queue, per-customer gateway limits, step-up demands | Cleared on verify / revoke / withdrawal / restriction lapse; uninstall |
| Options | Email allowlist; verified credentials (`fs_verified_creds`: emails / address hashes / IPs); ID-verified emails (`fs_idv_verified`); accounts owing an ID check, indexed by email (`fs_idv_required`); notify recipient addresses | Exemptions, alerting, step-up demands | Uninstall |
| Order meta (`_fs_card_check`, `_fs_card_outcome`, `_fs_cardholder`) | AVS/CVV result codes, card brand, BIN, last four, NMI's `cc_hash` card fingerprint (a pseudonymous per-card identifier), 3-D Secure fields, NMI transaction id, outcome, cardholder-confirmed/mismatch state | The issuer's verdict on the payment, bound permanently to the order as dispute evidence and the basis of the cardholder-mismatch signal | Uninstall (opt-in) |
| Options (`fs_card_checks`, `fs_card_key_map`) | Rolling log of the last 200 card checks (order id, normalized email, result codes, brand + last four); gateway-to-key map storing an **MD5 of the key**, never the key | The Card Checks page, the profile "latest card check" line, and single-request lookups on rotator setups | Uninstall (opt-in) |
| `uploads/fraud-shield/` | Transient uploaded order-CSV during import | Import staging | Auto-deleted after import/cancel; uninstall |
| Outbound email | Alert/hold notifications include order + customer contact details | Merchant alerting | Ensure recipients are staff mailboxes |
| CSV exports | Full report contents | Merchant-initiated analysis/backup/transfer | Merchant's custody once downloaded |

**Access control:** every read path for this data sits behind
`manage_woocommerce`. Nothing is ever rendered to the frontend — a blocked
visitor sees only the merchant-authored message, and a customer offered ID
verification sees only the merchant-authored invitation text.

**Third-party processing (ID verification).** When the feature is enabled,
Didit becomes a sub-processor for the customers who choose to verify. The
document images and any selfie go browser → Didit and never reach this site;
what crosses the wire from the store is the workflow id, an opaque customer
key, the store URL, and — so Didit can pre-fill and notify — the customer's
email and the billing first/last name the match will be checked against. What
comes back and is stored is the decision, the name and document type extracted
from the ID, and timestamps. Verification is opt-in per customer, expires after
a configurable window (default 365 days), and can be revoked by staff at any
time. Name that sub-processor in your privacy policy before switching this on.

**Third-party processing (cardholder check).** The NMI lookup introduces no new
sub-processor: NMI is already the store's payment gateway for the very
transactions being queried, so no party learns anything it does not already
hold. What crosses the wire is the stored security key and a transaction id,
over HTTPS only; what comes back — and the small part of it that is stored —
is listed in the table above. The security keys themselves live in
`fs_settings` like the Didit credentials, are readable only by
`manage_woocommerce` admins on the settings screen, and are the reason §6
recommends issuing them from a restricted NMI user.

**Data minimization decisions:** addresses are matched via SHA-256 hashes;
the plaintext `ship_text`/city/state/postcode copies exist because merchants
need to *read* addresses to investigate and lock destinations. Emails/phones
on the blocklist are plaintext because admins must be able to see and manage
what they blocked. Hashing those at rest would break the admin UX while
adding little: WooCommerce stores the same values in plaintext in the same
database.

**GDPR / right-to-erasure:** the plugin intentionally does **not** hook the
WordPress personal-data eraser. Fraud-prevention records are retained under
legitimate-interest: erasing a fraudster's identifiers on request would defeat
the blocklist. Merchants who must purge an identity can remove their blocklist
entries, resolve their ledger rows, and (if required) delete their
`fs_order_index` rows manually; uninstalling removes everything. Document this
retention basis in your privacy policy.

---

## 4. Findings from the 2026-07-08 review (all resolved)

### 4.1 CSV / spreadsheet formula injection — **fixed**
- **Evidence:** all 11 report/blocklist export sites and the scanner's index
  export wrote customer-controlled strings verbatim with `fputcsv()`. A
  fraudster's billing name of `=HYPERLINK(...)` or `+cmd|' /C ...'!A0` would
  execute in Excel/Sheets on the merchant's machine when the export is opened
  — and this plugin exists to funnel exactly such actors' data into exports.
- **Fix:** `FS_Data::csv_escape_cell()` prefixes any non-numeric cell starting
  with `=` `+` `-` `@` TAB CR with `'` (the OWASP-recommended literal marker),
  applied at every export site via `FS_Admin::csv_row()`. Numeric cells are
  exempt so negative totals survive. Our own import paths
  (`restore_index_row`, blocklist import) strip the guard, keeping
  export→import round-trips byte-faithful. Verified with a 26-case harness
  (hostile payloads neutralized, legit values untouched, round-trip exact).

### 4.2 Type confusion in classification save — **fixed**
- **Evidence:** `save_method_labels()` mapped `sanitize_text_field` over raw
  `$_POST` arrays; a crafted nested array (`fs_pm_key[0][]=x`) would reach the
  sanitizer as an array (PHP 8 warning, garbage label). Requires an
  authenticated admin with a valid nonce, so impact was robustness only.
- **Fix:** elements are `is_string()`-checked (key and value pairwise) before
  sanitization.

### 4.3 Missing `nosniff` on CSV downloads — **fixed**
- `X-Content-Type-Options: nosniff` added to both CSV senders.

---

## 5. Accepted risks & by-design decisions

1. **Block-message oracle.** An unauthenticated visitor can submit checkouts
   with arbitrary credentials and learn from the distinct error messages
   whether a credential is blocked. This is inherent to the product decision
   that blocked customers see a configurable, honest message. A per-block
   "quiet mode" (generic payment-failure wording) is a possible future option
   for confirmed rings.
2. **Client IP trust.** IP matching uses WooCommerce's
   `WC_Geolocation::get_ip_address()`. Behind a proxy/CDN, the server must be
   configured to pass the real client IP, or IP blocklist entries can be
   evaded by header spoofing (never escalated into access — evasion only).
3. **`.htaccess` is Apache-only.** On nginx, the uploads hardening file is
   ignored. Mitigations already in place: random 16-char filenames and prompt
   deletion. nginx users should also add:
   `location ~* /wp-content/uploads/fraud-shield/ { deny all; }`
4. **Nonced GET state changes** (Block/Unblock/Verify links) follow the
   standard WordPress admin-post pattern; the nonce is the CSRF control.
5. **PII parity with WooCommerce.** The plugin stores normalized copies of
   data WooCommerce already holds in the same database; it does not create a
   new class of exposure. Protecting the database/backups is the host's job.
6. **ID verification raises the cost of fraud; it does not prove card
   ownership.** An approved document plus a billing-name match lifts the
   gateway restriction, the velocity lock, and the new-account hold — never a
   blocklist entry, and never a per-customer restriction staff set
   deliberately. A fraudster whose own genuine ID happens to match the name on
   the stolen card still passes; what the layer removes is the cheap,
   scriptable path. The name match is the control that defeats the common
   combination (someone else's real ID, someone else's real card): a mismatch
   fails closed and is queued for staff review rather than silently accepted.
7. **Verification decisions come from Didit.** The store trusts the signed
   webhook (or, as a fallback, its own throttled read of Didit's decision API)
   for the approve/decline outcome. Compromise of the webhook secret would let
   an attacker forge an approval — treat it like any other API credential, and
   note that a forged approval still only reaches the unlock scope in §5.6.
8. **An ID verification is recorded against the email as well as the account**,
   so it survives a logged-out session — which means someone who knows a
   verified customer's registered email can type it at checkout and inherit
   that exemption, exactly as they could with the staff-managed email allowlist
   or verified-credential list. What is new in v1.14 is that this set grows
   without staff action. It is bounded: only logged-in accounts can start a
   verification (the offer is account-scoped), the email recorded is always the
   account's own registered address rather than anything the client supplies,
   and the exemption still never reaches the blocklist. As of v1.15 the same
   email is also written to `fs_verified_creds`, which widens *where* that
   exemption is read from but not *what* it grants — both paths stop at the
   blocklist.
9. **"Block checkout & request ID" fails open, by choice.** A block whose only
   exit is a verification is only safe if the verification can actually run, so
   `FS_IDV::requires_verification()` returns false whenever the feature is
   disabled or missing its API key / workflow id, and the staff action refuses
   to apply until Didit is configured. The trade-off is explicit: losing the
   Didit credentials releases these particular customers rather than trapping
   them. Everything else about them still applies — blocklist entries, velocity
   lock, holds and gateway rules are untouched by this flag — so the failure
   mode is "back to normal enforcement", not "unguarded". The flag is also
   deliberately not a blocklist entry: a blocked customer who passes an ID check
   remains blocked, because only this flag clears on verification.
10. **The ID demand is account-scoped but enforced by email too.** It is stored
    against an account, so at submission the checkout email is resolved back to
    the owning account through the `fs_idv_required` index — otherwise signing
    out and ordering as a guest on the same address would walk straight past it.
    The pre-submit page gate can only see a logged-in visitor's identity (like
    every other gate check), so a guest is caught at submission rather than
    earlier, and told to sign in so the verify button is reachable. The index
    holds the account's own registered address only; it grants nothing, so a
    guessed entry cannot be used to exempt anyone — it can only add a block.

---

## 6. Operational hardening checklist (for merchants)

- Serve the store exclusively over HTTPS; PII and admin nonces transit here.
- Limit `manage_woocommerce` accounts; enforce strong passwords + 2FA — every
  plugin control is gated on that capability.
- Treat exported CSVs as sensitive documents (they contain customer PII).
- Configure your proxy/CDN to pass the real client IP (see §5.2).
- nginx: deny web access to `uploads/fraud-shield/` (see §5.3).
- Keep WordPress, WooCommerce, and PHP current; the plugin supports PHP 7.4+
  but runs fine on 8.x.
- Alert/hold notification recipients should be staff mailboxes only.
- If ID verification is enabled: set the webhook secret (so decisions arrive
  signed instead of being polled), keep the name match at *lenient* or
  *strict* rather than *off*, prefer a finite validity window over 0, and name
  Didit as a sub-processor in your privacy policy.
- If the API is enabled: grant each key the narrowest scope set that works, pin
  keys to the caller's IP or CIDR range where the integration has a fixed
  address, prefer **Require signed requests** for anything long-lived, give keys
  an expiry, revoke rather than delete so the audit trail keeps the name, and
  check the security log for repeated authentication failures. Leave **Allow
  webhook URLs on private addresses** off outside development, and leave **Read
  the caller IP from proxy headers** off unless a proxy overwrites them. Prefer
  hashed identifiers on any subscription that leaves your infrastructure.
- If the cardholder check is enabled: create each NMI security key under a
  **dedicated, least-privilege NMI user** (the check only ever queries, but a
  key inherits its user's permissions — on many accounts a full key can also
  process transactions), rotate keys if staff with settings access leave, and
  leave the query endpoint on `https://secure.nmi.com` unless your processor
  runs a white-labelled portal.

---

## 7. Reporting a vulnerability

Report suspected vulnerabilities privately to the plugin author (Bones R&D)
rather than opening a public issue. Include reproduction steps and impact.
Fixes are released as patch versions; the changelog documents every
security-relevant change.
