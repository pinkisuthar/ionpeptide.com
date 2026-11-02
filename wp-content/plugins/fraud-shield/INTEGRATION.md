# Fraud Shield — API & Webhook Integration Guide

Plugin version **1.30.0** · API version **v1** · Database version 19 · October 2026

This guide covers everything needed to connect an external system to a store running Fraud Shield: what the plugin does, how to set up API access and webhooks inside WordPress, the complete REST API reference (65 routes), the 23 webhook topics and how to verify them, and the rules an integration must follow. It is self-contained and written to be handed to a developer or pasted whole into an LLM context.

**Two kinds of example appear here.** Examples labelled *captured verbatim* come from the plugin's live-run capture (`API.md`) against a fixture store called *Atomik Labz*; their values are internally consistent. Examples labelled *shape* document routes added in 1.30 and are derived from the implementation: the field names, types and enumerations are exact, the values are illustrative. Routes and fields new in 1.30 are marked **★ 1.30**.

---

## Contents

- [What's new in 1.30](#whats-new-in-130)
- [Part 1 — What Fraud Shield is](#part-1--what-fraud-shield-is)
  - [The mental model](#the-mental-model)
  - [Trust states](#trust-states)
- [Part 2 — Setting it up in Fraud Shield](#part-2--setting-it-up-in-fraud-shield)
  - [Prerequisites](#prerequisites)
  - [The API & Webhooks screen](#the-api--webhooks-screen)
  - [Settings panel](#settings-panel)
  - [Option A — Pair an external platform (recommended)](#option-a--pair-an-external-platform-recommended)
  - [Option B — Create an API key by hand](#option-b--create-an-api-key-by-hand)
  - [Adding a webhook by hand](#adding-a-webhook-by-hand)
  - [Monitoring: Recent deliveries and the Security log](#monitoring-recent-deliveries-and-the-security-log)
  - [Key and secret lifecycle](#key-and-secret-lifecycle)
  - [Local development](#local-development)
  - [Troubleshooting](#troubleshooting)
- [Part 3 — API reference](#part-3--api-reference)
  - [Basics](#basics)
  - [Authentication](#authentication)
  - [Scopes](#scopes)
  - [Rate limits and response headers](#rate-limits-and-response-headers)
  - [Errors](#errors)
  - [Route index](#route-index)
  - [Shared objects](#shared-objects)
  - [POST /handshake](#post-handshake)
  - [GET /ping](#get-ping)
  - [GET /stats](#get-stats)
  - [GET /settings ★](#get-settings-)
  - [GET /scan ★](#get-scan-)
  - [POST /risk/evaluate](#post-riskevaluate)
  - [GET /customers ★](#get-customers-)
  - [GET /customers/{ident}](#get-customersident)
  - [POST /customers/{ident}/restriction ★](#post-customersidentrestriction-)
  - [DELETE /customers/{ident}/restriction ★](#delete-customersidentrestriction-)
  - [DELETE /customers/{ident}/hold ★](#delete-customersidenthold-)
  - [GET /addresses and GET /ips ★](#get-addresses-and-get-ips-)
  - [GET /addresses/{hash} and GET /ips/{ip} ★](#get-addresseshash-and-get-ipsip-)
  - [GET /reports/repeat-customers ★](#get-reportsrepeat-customers-)
  - [GET /reports/new-customers ★](#get-reportsnew-customers-)
  - [GET /restrictions ★](#get-restrictions-)
  - [GET /holds ★](#get-holds-)
  - [GET /checkout-locks ★](#get-checkout-locks-)
  - [GET /orders](#get-orders)
  - [GET /orders/{id}](#get-ordersid)
  - [POST and DELETE /orders/{id}/chargeback ★](#post-and-delete-ordersidchargeback-)
  - [POST and DELETE /orders/{id}/recovery ★](#post-and-delete-ordersidrecovery-)
  - [POST /orders/{id}/card-check ★](#post-ordersidcard-check-)
  - [GET /card-checks ★](#get-card-checks-)
  - [GET /linked-cancels ★](#get-linked-cancels-)
  - [GET /high-value, /high-value/reviews, /high-value/leaderboard and POST /orders/{id}/review (1.32)](#get-high-value-high-valuereviews-high-valueleaderboard-and-post-ordersidreview-132)
  - [GET /blocklist](#get-blocklist)
  - [POST /blocklist](#post-blocklist)
  - [DELETE /blocklist ★ (bulk)](#delete-blocklist--bulk)
  - [DELETE /blocklist/{id}](#delete-blocklistid)
  - [PATCH /blocklist/{id} and PATCH /blocklist/actions/{id} ★](#patch-blocklistid-and-patch-blocklistactionsid-)
  - [GET /blocklist/actions ★](#get-blocklistactions-)
  - [DELETE /blocklist/actions/{id} ★](#delete-blocklistactionsid-)
  - [POST /blocklist/actions/{id}/resync ★](#post-blocklistactionsidresync-)
  - [GET /verified](#get-verified)
  - [POST /verified](#post-verified)
  - [DELETE /verified](#delete-verified)
  - [GET, POST and DELETE /allowlist ★](#get-post-and-delete-allowlist-)
  - [GET /idv/sessions](#get-idvsessions)
  - [POST /idv/sessions/{id}/accept ★](#post-idvsessionsidaccept-)
  - [GET /idv/requests ★](#get-idvrequests-)
  - [POST /idv/require](#post-idvrequire)
  - [DELETE /idv/require ★](#delete-idvrequire-)
  - [DELETE /idv/verified ★](#delete-idvverified-)
  - [GET /insights/prevented ★](#get-insightsprevented-)
  - [GET /insights/financial ★](#get-insightsfinancial-)
  - [GET /network ★](#get-network-)
  - [GET /network/{ident} ★](#get-networkident-)
  - [GET /geography/{level} ★](#get-geographylevel-)
  - [GET /geography/{level}/customers ★](#get-geographylevelcustomers-)
  - [GET /events](#get-events)
  - [GET /webhooks](#get-webhooks)
  - [POST /webhooks](#post-webhooks)
  - [DELETE /webhooks/{id}](#delete-webhooksid)
  - [POST /webhooks/{id}/test](#post-webhooksidtest)
- [Part 4 — Webhooks](#part-4--webhooks)
  - [Topics](#topics)
  - [Delivery format](#delivery-format)
  - [Verifying a delivery](#verifying-a-delivery)
  - [Payload for every topic](#payload-for-every-topic)
  - [Hashed identifiers](#hashed-identifiers)
  - [Retries and failure](#retries-and-failure)
  - [Polling instead of receiving](#polling-instead-of-receiving)
- [Part 5 — Integration recipes and rules](#part-5--integration-recipes-and-rules)
- [Value glossary](#value-glossary)
- [Security model](#security-model)

---

## What's new in 1.30

The API shipped in 1.24 with 20 routes and 13 topics. Fraud Shield 1.25–1.29 then added street locks, the door-key address hash, linked-identity auto-cancel, the Fraud-prevented dashboard, the Financial Protection report, the Customer Network, blocklist search, bulk removal and provenance, and checkout-refusal evidence — most of it reachable only in wp-admin. 1.30 closes that gap: **65 routes and 23 topics**, so an external platform can read and act on everything the admin screens show. Every admin screen has a route; every staff action on a customer, order or credential has a route; every state change has a topic. What remains wp-admin-only is operational: starting scans and imports, editing settings, syncing from Didit, and minting API keys.

| Area | Added |
|---|---|
| Diagnostics | `GET /settings`; `GET /scan` (the Dashboard's scan panel, read-only); `features.controls` and `features.scopes` on `/ping`; block cards, verified credentials, allowlist, Auto-Cancelled totals, Fraud-prevented headline and card checks on `/stats`; `state.blocklist_evidence` on `/risk/evaluate` |
| Customers | `GET /customers` (the Profiles list); a full dossier on `GET /customers/{ident}` including an `analysis` block (cadence, time-of-day, geo checks, consistency); the Addresses and IPs registries (`GET /addresses`, `GET /ips`) and their detail pages (`/addresses/{hash}`, `/ips/{ip}`); the Repeat and New Customers reports (`/reports/repeat-customers`, `/reports/new-customers`); `GET /restrictions`, `GET /holds`; the Checkout Locks ledger (`/checkout-locks`); `POST`/`DELETE /customers/{ident}/restriction`, `DELETE /customers/{ident}/hold` |
| Orders | Eleven more filters and nine more fields on `GET /orders`; linked-cancel, card-check, ID-session and blocklist provenance on `GET /orders/{id}`; `POST`/`DELETE /orders/{id}/chargeback`, `/recovery`; `POST /orders/{id}/card-check`; `GET /card-checks`; `GET /linked-cancels` |
| Blocklist | `type` and `search` on `GET /blocklist`, with message, author and owning cards on every entry; `cascade: true` on `POST /blocklist`; bulk `DELETE /blocklist` (ids, IPs, every IP, the legacy ungrouped rows); `PATCH` the checkout message on a credential or a card; the block cards (`/blocklist/actions`, reverse, re-sync) |
| Trust and ID | `POST /verified` for an account now mirrors the admin Verify button exactly; `/allowlist`; `GET /idv/requests`; `DELETE /idv/require`; `POST /idv/sessions/{id}/accept`; `DELETE /idv/verified`; every stored session column on `GET /idv/sessions` |
| Insights | `GET /insights/prevented`, `GET /insights/financial`, `GET /network`, `GET /network/{ident}`, `GET /geography/{level}`, `GET /geography/{level}/customers` |
| Scopes | `write:orders`, `write:customers`, `read:insights` |
| Webhooks | `order.cancelled`, `order.chargeback`, `order.recovery`, `customer.unblocked`, `blocklist.extended`, `blocklist.removed`, `hold.applied`, `hold.released`, `idv.requested`, `idv.request_cleared`; `scope: "allowlist"` on `customer.verified` / `customer.unverified`; `block_card` and `credential` are hashed on digest subscriptions |

Nothing existing changed shape: every 1.24 field is still there with the same name and type. Existing keys keep the scopes they were minted with; to use a new scope, mint a new key (or pair again) with it ticked.

---

## Part 1 — What Fraud Shield is

Fraud Shield is a **WordPress/WooCommerce plugin** (PHP, GPL-2.0-or-later). It runs inside the merchant's own WordPress install; there is no central service. Every store has its own database, settings, API keys, webhook subscriptions and `store_id`. An integration connects to **one store at a time** and should key its records on that store's `store_id`.

The plugin does three things:

1. **Indexes and scores every order** — the whole back history plus every new order the moment it is placed — against *cross-order* patterns that single-order review cannot see: shared drop addresses, recycled emails and phones, one IP running many accounts, brand-new accounts ordering in bursts, and anything linked to a prior chargeback. Every score carries plain-English reasons.
2. **Enforces at checkout** through independent, opt-in, reversible controls: a blocklist, automatic cancellation of orders *linked* to the blocklist, geographic destination locks, a velocity lock, a new-account high-value hold, payment-gateway restrictions, step-up government-ID verification (via Didit), and a post-payment cardholder check (via NMI AVS/CVV).
3. **Exposes all of it over a REST API and signed webhooks**, so an external platform can ask "what do you know about this identity and what would checkout do with it right now?", act on the answer, and be told when something changes.

### The mental model

**Identity normalization.** Before anything is stored or matched, credentials are normalized so cosmetic variation collapses to one identity:

| Credential | Normalization |
|---|---|
| Email | Lower-cased; Gmail dots and `+tags` collapsed (`John.Doe+shop@gmail.com` → `johndoe@gmail.com`). API fields named `email_norm` are this form |
| Phone | Digits only; a leading US `1` is dropped. API fields named `phone` carry this form |
| Address | Three hashes: **exact** (both lines, normalized, hashed — `address_id`), **loose** (first line + postcode, so different unit numbers still link — `address_loose_id`), and the **door key** (house number + street name + country + state for US/CA/AU, the postcode elsewhere — blind to the unit, the ZIP and the city, so rewriting "Apt 4" as "#4", typing another ZIP or another acceptable city name cannot escape a block — `door_id`; **1.31 changed its shape**, so `door_id` values read before 1.31 no longer correspond to the ones served now). Raw addresses are never returned by the API |
| Device | The browser user-agent, SHA-256 hashed (`device_id`); one signature across many accounts is one operator |
| Place (destination lock) | `state:US\|FL`, `city:US\|FL\|miami`, `zip:US\|331` (prefix), `street:US\|FL\|miami\|n main st` (a street within one city name), and ★ 1.31: `streetzip:US\|33166\|n main st` (within one ZIP), `streetcounty:US\|FL\|miami dade\|n main st` (within one county — reached through address validation), `building:US\|FL\|8020\|n main st` (one house number on one street anywhere in the state — every unit), `geo:25.83300\|-80.32800\|805` (an area ban: a point and a radius in metres — every address the geocoder resolves to a point inside it) |

Anything sent to `POST /risk/evaluate` goes through the same normalizer as a real order, so an ad-hoc lookup lands on the same history a checkout would.

**Two layers: the heuristic score and the deliberate controls.**

- The **risk score** is additive: each rule that fires adds points and a reason (email on a prior chargeback = 80, exact address on a prior chargeback = 70, phone on a prior chargeback = 60, address shared by 3+ emails = 25 and up, 3+ orders in 7 days = 20, account under 7 days old = 15, and so on). The total maps to a level: `none`/`low` (0–39), `medium` (40–69), `high` (70–109), `block` (110+). Every weight and cut-off is merchant-configurable — `GET /settings` returns this store's — so **compare levels, not raw scores, across stores**. The full rule table is in `FRAUD-RISK-CRITERIA.md`.
- The **deliberate controls** are explicit merchant decisions — blocklist, linked auto-cancel, destination locks, velocity lock, new-account hold, gateway restrictions, ID-check demand, high-value ID rule. They apply **in every mode**. `features.controls` on `/ping` says which are switched on.

**Observe vs. Enforce.** `features.mode` from `GET /ping` is `observe` or `enforce`. In observe mode, scores annotate orders but never block; in enforce mode, `high` and `block` scores can refuse a checkout. The blocklist and the other deliberate controls block regardless of mode.

### Trust states

| State | How it is earned | What it exempts from |
|---|---|---|
| **Blocklisted** | A staff, API, cascade or import block on an email, phone, IP, address, account or place | Nothing — it *is* the block |
| **Verified account** (staff flag) | Staff marks the WordPress account verified (or `POST /verified {user_id}`) | Everything, including the blocklist |
| **Verified credential** (email / address / IP) | Staff, the API, or earned by an approved ID check (`source: "idv"`) | Velocity lock, hold, gateway restrictions, linked auto-cancel — **never** the blocklist |
| **Allowlisted email** | Staff or `POST /allowlist` | Velocity lock and hold only — never the blocklist |
| **Auto-verified** | 180+ days of history, at least one paid order, no chargebacks or refunds (configurable) | Same as a verified credential; never the blocklist |
| **ID-verified** | Completed Didit check with a matching name | Same as a verified credential; never the blocklist |
| **ID requested** | Staff or the API demands an ID check from an account | Blocks checkout until the customer verifies; self-releasing |
| **Restricted checkout** | Staff or `POST /customers/{ident}/restriction` | Allowed to check out, on the listed gateways only; counts as a release from the velocity lock, hold and ID demand; lapses after N months or on an approved ID check |

`state.trust_tier` on a customer summarises the strongest standing: `staff` > `idv` > `auto` > `none`.

Rule of thumb: **an explicit block always wins over every kind of verification except the staff "Verified account" flag.**

Every block — including one created through `POST /blocklist` — is owned by a reversible *block action* (a "block card") that groups the credentials it cascaded onto, so staff can undo an API block in one click and `DELETE /blocklist/actions/{id}` can do the same. Blocking an address or a customer profile cascades onto every associated credential; blocking a *place* never cascades onto people.

---

## Part 2 — Setting it up in Fraud Shield

### Prerequisites

| Requirement | Why |
|---|---|
| WooCommerce 6.0+ active, Fraud Shield 1.24.0 or newer installed and activated (1.30.0 for the routes marked ★) | The API shipped in 1.24.0 |
| A user with the **Shop manager** or **Administrator** role (`manage_woocommerce`) | That capability gates the API & Webhooks screen |
| **HTTPS** on the store | The API refuses plain-HTTP requests by default (`403 fs_api_insecure`) |
| Pretty permalinks (*Settings → Permalinks* set to anything but "Plain") | Gives the `https://<store>/wp-json/fraud-shield/v1` base URL. With plain permalinks use `https://<store>/?rest_route=/fraud-shield/v1/...` instead — both forms work identically |
| WP-Cron running | Webhook deliveries are sent by cron, never inside the request that produced them. On a low-traffic store, or one with `DISABLE_WP_CRON` set, configure a real system cron that requests `wp-cron.php` every minute or deliveries will lag |
| *(for `idv.*` topics, `POST /idv/require` and the ID fields)* Didit configured under *Fraud Shield → Settings → ID verification (Didit)* | Without it, no ID-verification events occur and `POST /idv/require` answers `409 fs_api_idv_unavailable` |
| *(for `card.checked`, `POST /orders/{id}/card-check` and `GET /card-checks`)* NMI configured under *Fraud Shield → Settings → Cardholder check (NMI)* | Without it, no cardholder events occur and `POST /orders/{id}/card-check` answers `409 fs_api_card_check_unavailable` |
| *(for `order.cancelled`, `GET /linked-cancels` and `linked_blocklist` decisions)* *Settings → Cancel orders linked to the blocklist* switched on | The linked rule is off by default |
| *(for `analysis.geo_checks` on a customer)* WooCommerce's MaxMind geolocation integration with a licence key (*WooCommerce → Settings → Integration*) | Without it the IP-versus-country checks report `available: false` |

### The API & Webhooks screen

Open **WordPress admin → Fraud Shield → API & Webhooks**. The screen has these panels, top to bottom:

1. **Endpoint** — the store's **Base URL** (e.g. `https://store.example.com/wp-json/fraud-shield/v1`), its **Store ID** (a 32-hex identifier that appears in every response and webhook; the integrator keys records on it), and the current **Status**: Enabled/Disabled plus the count of active keys, subscriptions and queued deliveries.
2. **Settings** — the API's own configuration (next section).
3. **Pair an external platform** — the single-use pairing code flow.
4. **API keys** — the list of credentials and the create form.
5. **Webhooks** — the list of subscriptions and the create form.
6. **Recent deliveries** — the last 25 delivery attempts (appears once there are any).
7. **Security log** — authentication failures and credential changes (appears once there are any).
8. **Quick reference** — copy-paste `curl` examples for `/ping` and `/risk/evaluate` against this store's base URL.

The API is **enabled by default but refuses every request until at least one key exists** (or a pairing window has been redeemed). Nothing is exposed by merely installing the plugin.

### Settings panel

| Setting | Default | What it does and when to change it |
|---|---|---|
| **API — Answer API requests** | On | Master switch. Turning it off refuses every request, including from existing keys, and stops webhook delivery. Routes answer `503 fs_api_disabled` |
| **Transport — Require HTTPS** | On | Refuses plain-HTTP API requests with `403 fs_api_insecure`. Leave on; a bearer token sent over HTTP is given to every device on the path. Turn off only on a local development site |
| **Request signing — Require signed requests from every key** | Off | Forces the HMAC signing scheme for every key, not just the ones flagged for it. Strongest setting: a captured request cannot be replayed and a token alone is not enough. Individual keys can require signing on their own |
| **Rate limit** | 120 requests/minute/key | Store-wide default. A key can be given its own limit at creation (1–10,000) |
| **Caller address — Read the caller IP from proxy headers** | Off | By default the caller's address is read from the transport connection, **not** `X-Forwarded-For`. Tick this **only** behind a proxy or CDN that overwrites the forwarded-for header (Cloudflare, a load balancer). Otherwise any caller can claim any address and per-key IP allow-lists become decorative |
| **Event retention** | 30 days (1–365) | Events and delivery history older than this are pruned. An event with a delivery still pending is kept past the window. Anything a poller has not collected by then is gone |
| **Delivery — timeout** | 10 seconds (3–30) | How long a webhook POST waits for the subscriber's response |
| **Delivery — attempts per event** | 6 (1–12) | Retries back off exponentially (1, 2, 4, 8, 16 minutes, capped at 6 hours). A subscription that fails 20 times in a row is switched off |
| **Local delivery — Allow webhook URLs on private or loopback addresses** | Off | **Development only.** A webhook URL is an address the store connects to on request; allowing internal addresses lets anyone who can add a webhook reach services behind the firewall |

Click **Save settings**. Settings take effect immediately.

### Option A — Pair an external platform (recommended)

Pairing lets a platform obtain its own scoped key and webhook secret **without anyone copying a permanent secret by hand**. The admin produces a single-use code; the platform exchanges it once at `POST /handshake`; the code is worthless afterwards.

**On the store (admin):**

1. In **Pair an external platform**, fill in:

   | Field | Default | Notes |
   |---|---|---|
   | **Platform name** | `bonesfraud.com` | Labels the resulting key and webhook in the lists. Use the platform's name |
   | **Scopes** | `read:risk`, `read:customers`, `read:orders`, `read:events` | The scopes the paired key will hold — all fourteen are offered (see [Scopes](#scopes)). Grant the narrowest set that works. Tick `manage:webhooks` only if the platform must manage subscriptions itself; tick the `write:*` scopes only if it will act on the store, not just read it |
   | **Valid for** | 15 minutes | How long the window stays open: 15 minutes, 1 hour or 24 hours |
   | **The paired key must sign every request** | Ticked | Recommended. The platform must use the HMAC scheme; a bearer token will be refused with `401 fs_api_signature_required` |
   | **Also create a webhook subscription from the callback URL the platform supplies** | Ticked | If the platform sends `callback_url` in the handshake, a subscription is created in the same step and its secret returned in the same response |

2. Click **Generate pairing code**. A green notice shows a code like `fspc_1b232ce3f08bf1ef7dd9d6da673b6a964ff72b6e` **exactly once**. Copy it and give it to the platform through whatever channel you trust; it is useless on its own after it is redeemed or expires.
3. While the window is open the panel shows an amber **Pairing open** pill with the minutes remaining and the scopes. **Close pairing window** cancels it early.

The window closes automatically on the first successful redemption, on expiry, or after **five wrong codes**. Handshake attempts are rate-limited to 10 per hour per IP.

**On the platform:** `POST <base>/handshake` with the code (no credential needed). The `201` response carries the token, the key id, the scopes, the signing requirements and — if a callback URL was supplied and allowed — the webhook id and secret. Both secrets are shown only in that response. Full details under [POST /handshake](#post-handshake).

**Afterwards:** the key appears in **API keys** with a `paired` pill (and `signed` if signing was required); the subscription appears under **Webhooks** labelled with the platform name. Both are managed exactly like hand-made ones.

### Option B — Create an API key by hand

For a reporting dashboard, an internal script, or any case where you will paste the token into the client yourself.

1. In **API keys → Create a key**, fill in:

   | Field | Default | Notes |
   |---|---|---|
   | **Label** | *(empty)* | e.g. `Reporting dashboard`. Shown in the list and used as the default label for webhooks this key creates |
   | **Scopes** | `read:risk` | At least one is required. There is no wildcard. The checklist offers all fourteen scopes with a one-line description of each |
   | **Require signed requests from this key** | Off | Tick for anything long-lived or handed to a third party |
   | **Allowed addresses** | *(any)* | One IP or CIDR per line, e.g. `203.0.113.7` or `198.51.100.0/24`. Fails closed: a request from any other address is refused with the uniform `401 fs_api_unauthorized`. Only meaningful if the caller IP is read correctly — see *Caller address* above |
   | **Rate limit** | `0` (= store default) | Requests per minute for this key alone |
   | **Expires** | never | never / in 30 days / in 90 days / in a year |

2. Click **Create key**. The token — `fsk_<16 hex key id>_<48 hex secret>` — is shown **exactly once** in a green notice with the hint *Send it as: Authorization: Bearer <token>*. Copy it now; the store keeps only `sha256(secret)` and cannot show it again.

The list shows each key's label, pills (`paired`, `signed`, `IP pinned`), public key id, scopes, status (`active` / `revoked` / `expired`), last-used time and call count, with **Revoke** and **Delete** buttons. Scopes cannot be edited on an existing key: mint a new one and revoke the old.

### Adding a webhook by hand

1. In **Webhooks → Add a webhook**, fill in:

   | Field | Default | Notes |
   |---|---|---|
   | **Label** | *(empty)* | For the list |
   | **Delivery URL** | — | Must be **HTTPS** and must resolve to a **public** address (every A/AAAA result is checked), unless *Local delivery* is allowed in Settings. Must not carry credentials. Point it at the final URL — redirects are not followed |
   | **Topics** | `order.flagged`, `checkout.blocked`, `idv.approved` | Tick any of the twenty-one business topics. `test.ping` is not offered; it is sent only by **Send test** |
   | **Privacy — Send hashed identifiers** | Off | Replaces emails, names, IPs and similar fields with stable SHA-256 digests (see [Hashed identifiers](#hashed-identifiers)) |

2. Click **Add webhook**. The signing secret — `fswh_<48 hex>` — is shown **exactly once**, with the hint *Verify deliveries with: HMAC-SHA256 of "<timestamp>.<body>" against the X-FS-Signature header.* Copy it now.

   If the URL is refused, the notice says why, verbatim: *Webhook URLs must use HTTPS*, *That URL resolves to a private or reserved address…*, or *could not be resolved*.

3. Click **Send test** on the new row. The store delivers a `test.ping` **synchronously** and reports *Test event delivered successfully* or *The test event was not accepted*, with the subscriber's HTTP status or transport error shown in the row's **Last result** column. Test deliveries always carry event id `evt_0` and `delivery.id: 0`; they are not stored in the event log.

Each row shows the label and URL, a `hashed identifiers` pill if enabled, the topic list (or *all topics*), status (`active` / `disabled`), a red **N failing** pill when consecutive failures are accumulating, and the last result (*delivered 5 mins ago*, *nothing sent yet*, or the last error). Buttons: **Send test**, **Disable**/**Enable**, **Delete**.

Re-enabling a subscription that was switched off after 20 consecutive failures clears its strike count.

### Monitoring: Recent deliveries and the Security log

**Recent deliveries** lists the last 25 attempts across all subscriptions: when, topic, endpoint host, result (`delivered` green / `pending` amber / `failed` red, with the HTTP code and any error such as *Endpoint returned HTTP 500* or a transport error), and the attempt count. A `pending` row with attempts > 1 is a retry in backoff.

**Security log** lists the last 40 entries: authentication failures (`auth.*`, red), rate limiting, scope denials, key lifecycle (`key.created`, `key.revoked`…) and pairing activity (`pairing.opened`, `pairing.completed`, `pairing.failed`), each with the **public** key id, the caller address and a detail string. No secret, token or pairing code is ever written here. Caller-triggered entries are coalesced to one per minute per event and address, so a stranger cannot flood the log. Repeated failures from one address are worth a look; a burst is worth revoking a key over.

The Status line in the **Endpoint** panel also shows how many deliveries are queued; a number that keeps growing means the subscriber is down or WP-Cron is not running.

### Key and secret lifecycle

- **Revoke** a key: it stops working immediately and stays in the list as `revoked` for the audit trail. **Delete** removes the row entirely. Revoke first; delete when you no longer need the history.
- **Rotate a key**: create a new one with the same scopes, switch the client over, then revoke the old one. There is no in-place rotation.
- **Rotate a webhook secret**: delete the subscription and create a new one (new secret, new id). The platform must update its stored secret. There is no in-place rotation.
- **Expired keys** show as `expired` and are refused with the uniform `401 fs_api_unauthorized`.
- A key created through pairing is an ordinary key: revoke, delete and audit all work the same way.
- **Upgrading to 1.30**: existing keys keep exactly the scopes they had. The new scopes (`write:orders`, `write:customers`, `read:insights`) appear on the checklists automatically; mint a new key or pair again to use them.

### Local development

For a development store on `localhost` or a private network:

1. Untick **Require HTTPS** (otherwise every request is `403 fs_api_insecure`).
2. Tick **Allow webhook URLs on private or loopback addresses** to subscribe something like `http://127.0.0.1:8787/hooks` or a LAN receiver. The plugin's own `tools/hook-receiver.php` is a minimal verifying receiver you can run with `php -S`.
3. Never ship a store with either setting changed.

### Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Every request answers `401 fs_api_no_credentials` | No `Authorization` or `X-FS-Key` header reached PHP | Some hosts strip `Authorization`. Add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to `.htaccess`, or use the signed scheme (`X-FS-Key` header) |
| `401 fs_api_unauthorized` with a token you just created | Wrong token, key revoked/expired, or caller IP not on the key's allow-list | Check the **Security log** — it records the real reason by key id. If the key is IP-pinned behind a proxy, check *Caller address* |
| `401 fs_api_signature_required` | The key (or the store) requires signing and you sent a bearer token | Implement the signed scheme |
| `401 fs_api_bad_signature` | Canonical string mismatch | Check: path includes `/fraud-shield/v1`, no trailing slash, query sorted and `rawurlencode`d, body hash over the exact bytes sent |
| `401 fs_api_stale_request` | Clock skew over 300 s | Sync the client clock |
| `409 fs_api_replay` | Nonce reused within 600 s | Generate a fresh nonce per request |
| `403 fs_api_insecure` | Plain HTTP with *Require HTTPS* on | Use HTTPS |
| `403 fs_api_forbidden` | Key lacks the scope (common after upgrading: a 1.24 key has no `write:orders`, `write:customers` or `read:insights`) | Mint a key with the right scope; scopes cannot be edited in place |
| `404` for a route marked ★ | Store is on a plugin version before 1.30.0 | Check `store.version` on `/ping`; upgrade the plugin |
| `409 fs_api_idv_unavailable` / `fs_api_card_check_unavailable` | Didit / NMI not configured on the store | Configure it under *Fraud Shield → Settings* |
| `503 fs_api_disabled` | The API master switch is off | Tick *Answer API requests* |
| `404` HTML page from WordPress for `/wp-json/...` | Plain permalinks | Use `/?rest_route=/fraud-shield/v1/...` or enable pretty permalinks |
| Webhook creation fails with `fs_hook_private` | URL resolves to a private/loopback address | Use a public HTTPS endpoint, or enable *Local delivery* on a dev store |
| Deliveries sit as `pending` with no attempts | WP-Cron not running | Set up a system cron for `wp-cron.php`; the queue drains every 5 minutes plus an immediate run a few seconds after each event |
| Subscription shows `disabled` and `20 failing` | 20 consecutive failures | Fix the receiver, then click **Enable** to clear the strikes |
| `GET /events` returns the same page forever | Passing `"evt_3"` as the cursor | Pass the integer `next_cursor` as `after` |

---

## Part 3 — API reference

### Basics

| Fact | Value |
|---|---|
| Base URL | `https://<store>/wp-json/fraud-shield/v1` |
| Alternate URL form | `https://<store>/?rest_route=/fraud-shield/v1/...` |
| Auth | `Authorization: Bearer <token>`, or HMAC-SHA256 signed headers |
| Content type | `application/json` (`multipart/form-data` is refused with `415`) |
| Max request body | 256 KB |
| Default rate limit | 120 requests/minute/key |
| List page size | default 50 (100 on `/blocklist` and `/allowlist`), maximum 100 |
| Timestamps | ISO 8601. Order/customer/session/ledger dates carry the **store's** offset; webhook and event timestamps, restriction and card-check times are **UTC** |
| Money | Plain decimals in the store's currency; where a figure may be unknown (an unconfigured fee, a rate with no denominator) it is `null`, never `0` |
| Error shape | WordPress REST standard: `{ "code": ..., "message": ..., "data": { "status": ... } }` |
| Webhook signature | `X-FS-Signature: t=<unix>,v1=<hex>` over `"<t>.<raw body>"` |

### Authentication

Every endpoint except `POST /handshake` requires a credential. A token (captured verbatim):

```
fsk_3ec576b720cd1043_fea9d20bcebadb2dc9de6e875903d0a5711046a96012b1fe
    └── key id (16 hex) ┘ └────────── secret (48 hex) ──────────┘
```

The key id is public and appears in the admin UI and audit log. The secret is displayed once at creation; the store keeps only `sha256(secret)`.

#### Bearer tokens

```bash
curl -s 'https://store.example.com/wp-json/fraud-shield/v1/ping' \
  -H 'Authorization: Bearer fsk_3ec576b720cd1043_fea9d20bcebadb2dc9de6e875903d0a5711046a96012b1fe'
```

A bearer token is a password: whatever can read it can use it. Prefer signed requests for anything long-lived, and for any credential given to a third party.

#### Signed requests

A key can be marked **require signature** individually, or all keys can be forced to sign store-wide. A signed request cannot be replayed and cannot be altered in transit. Send four headers:

| Header | Value |
|---|---|
| `X-FS-Key` | The full token |
| `X-FS-Timestamp` | Unix seconds. Must be within **±300 s** of store time |
| `X-FS-Nonce` | Unique per request. 8–128 chars from `A–Z a–z 0–9 . _ : -`. Remembered for 600 s |
| `X-FS-Signature` | `<hex>` or `v1=<hex>` |

The signature is `HMAC-SHA256(canonical_string, secret)`, hex-encoded, where `secret` is the 48-hex part of the token after the last `_`. The canonical string is **exactly seven lines joined by `\n`**:

```
v1
<HTTP METHOD, uppercase>
<REST path, namespace-included, no trailing slash>
<canonical query string>
<sha256 hex of the raw request body>
<timestamp>
<nonce>
```

1. `v1` — literal scheme version.
2. Method, uppercase: `GET`, `POST`, `DELETE`.
3. The path **as sent**, including the namespace and any path parameters: `/fraud-shield/v1/orders/51802`. Identical for both URL forms. No trailing slash.
4. Query parameters, excluding `rest_route` and `_locale`: sort keys ascending, `rawurlencode` each key and value, join as `k=v` with `&`. Empty string when there are none.
5. `sha256` of the raw body bytes. For an empty body: `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
6. The same value sent in `X-FS-Timestamp`.
7. The same value sent in `X-FS-Nonce`.

Worked example — `GET /fraud-shield/v1/orders?level=high&limit=3`, no body, timestamp `1787167380`, nonce `a1b2c3d4e5f60718`:

```
v1
GET
/fraud-shield/v1/orders
level=high&limit=3
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
1787167380
a1b2c3d4e5f60718
```

Rules the store enforces:

- A key marked *require signature* **refuses a bearer token**: `401 fs_api_signature_required`.
- Wrong secret: `401 fs_api_bad_signature`. Reused nonce: `409 fs_api_replay`. Timestamp outside ±300 s: `401 fs_api_stale_request`.
- If a key does not require signing but signature headers are present, they are still verified.

#### Client implementations

**PHP** (plain PHP streams, no extension required; inside WordPress you can swap the transport for `wp_remote_request()` and keep the same headers)

```php
function fs_signed_request( string $base, string $token, string $method, string $path, array $query = array(), $payload = null ) {
    $secret = substr( $token, strrpos( $token, '_' ) + 1 );
    $body   = ( null === $payload ) ? '' : json_encode( $payload, JSON_UNESCAPED_SLASHES );

    ksort( $query );
    $pairs = array();
    foreach ( $query as $k => $v ) {
        $pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
    }

    $timestamp = (string) time();
    $nonce     = bin2hex( random_bytes( 8 ) );
    $canonical = implode( "\n", array(
        'v1',
        strtoupper( $method ),
        $path,
        implode( '&', $pairs ),
        hash( 'sha256', $body ),
        $timestamp,
        $nonce,
    ) );

    $headers = array(
        'Content-Type: application/json',
        'X-FS-Key: ' . $token,
        'X-FS-Timestamp: ' . $timestamp,
        'X-FS-Nonce: ' . $nonce,
        'X-FS-Signature: ' . hash_hmac( 'sha256', $canonical, $secret ),
    );

    $url     = rtrim( $base, '/' ) . $path . ( $pairs ? '?' . implode( '&', $pairs ) : '' );
    $context = stream_context_create( array(
        'http' => array(
            'method'        => strtoupper( $method ),
            'header'        => implode( "\r\n", $headers ),
            'content'       => $body,
            'ignore_errors' => true, // return the JSON error body on 4xx/5xx instead of a warning
        ),
    ) );
    $response = file_get_contents( $url, false, $context );
    return json_decode( (string) $response, true );
}
```

**Node (18+)**

```js
import { createHmac, createHash, randomBytes } from 'node:crypto';

export async function fsSignedRequest(base, token, method, path, query = {}, payload = null) {
  const secret = token.slice(token.lastIndexOf('_') + 1);
  const body = payload === null ? '' : JSON.stringify(payload);

  const pairs = Object.keys(query)
    .sort()
    .map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(query[k])}`);

  const timestamp = Math.floor(Date.now() / 1000).toString();
  const nonce = randomBytes(8).toString('hex');
  const canonical = [
    'v1',
    method.toUpperCase(),
    path,
    pairs.join('&'),
    createHash('sha256').update(body).digest('hex'),
    timestamp,
    nonce,
  ].join('\n');

  const url = base.replace(/\/$/, '') + path + (pairs.length ? `?${pairs.join('&')}` : '');
  const res = await fetch(url, {
    method: method.toUpperCase(),
    headers: {
      'Content-Type': 'application/json',
      'X-FS-Key': token,
      'X-FS-Timestamp': timestamp,
      'X-FS-Nonce': nonce,
      'X-FS-Signature': createHmac('sha256', secret).update(canonical).digest('hex'),
    },
    body: body === '' ? undefined : body,
  });
  return res.json();
}
```

**Python**

```python
import hashlib, hmac, json, secrets, time
from urllib.parse import quote
import requests

def fs_signed_request(base, token, method, path, query=None, payload=None):
    secret = token.rsplit("_", 1)[1]
    body = "" if payload is None else json.dumps(payload, separators=(",", ":"))

    query = query or {}
    pairs = [f"{quote(str(k), safe='')}={quote(str(query[k]), safe='')}" for k in sorted(query)]

    timestamp = str(int(time.time()))
    nonce = secrets.token_hex(8)
    canonical = "\n".join([
        "v1",
        method.upper(),
        path,
        "&".join(pairs),
        hashlib.sha256(body.encode()).hexdigest(),
        timestamp,
        nonce,
    ])

    url = base.rstrip("/") + path + (("?" + "&".join(pairs)) if pairs else "")
    return requests.request(
        method.upper(),
        url,
        data=body or None,
        headers={
            "Content-Type": "application/json",
            "X-FS-Key": token,
            "X-FS-Timestamp": timestamp,
            "X-FS-Nonce": nonce,
            "X-FS-Signature": hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest(),
        },
    ).json()
```

### Scopes

Fourteen scopes. A write scope grants its matching reads. There is no wildcard. `GET /ping` works with any valid key.

| Scope | Grants |
|---|---|
| `read:risk` | `GET /ping`, `GET /stats`, `GET /settings` ★, `GET /scan` ★, `POST /risk/evaluate` |
| `read:customers` | `GET /customers` ★, `GET /customers/{ident}`, `GET /addresses` ★, `GET /addresses/{hash}` ★, `GET /ips` ★, `GET /ips/{ip}` ★, `GET /reports/repeat-customers` ★, `GET /reports/new-customers` ★, `GET /restrictions` ★, `GET /holds` ★, `GET /checkout-locks` ★, `GET /checkout-locks/{id}` ★ |
| `write:customers` ★ | `POST`/`DELETE /customers/{ident}/restriction`, `DELETE /customers/{ident}/hold`, `DELETE /checkout-locks/{id}` — plus every `read:customers` route |
| `read:orders` | `GET /orders`, `GET /orders/{id}`, `GET /card-checks` ★, `GET /linked-cancels` ★, `GET /linked-cancels/{id}` ★, `GET /high-value`, `GET /high-value/reviews`, `GET /high-value/reviews/{id}`, `GET /high-value/leaderboard` (1.32) |
| `write:orders` ★ | `POST`/`DELETE /orders/{id}/chargeback`, `POST`/`DELETE /orders/{id}/recovery`, `POST /orders/{id}/card-check`, `POST /orders/{id}/review` (1.32) — plus every `read:orders` route |
| `read:events` | `GET /events` |
| `read:blocklist` | `GET /blocklist`, `GET /blocklist/actions` ★, `GET /blocklist/actions/{id}` ★ |
| `write:blocklist` | `POST /blocklist`, `DELETE /blocklist` ★, `DELETE /blocklist/{id}`, `PATCH /blocklist/{id}` ★, `DELETE /blocklist/actions/{id}` ★, `PATCH /blocklist/actions/{id}` ★, `POST /blocklist/actions/{id}/resync` ★ — plus the reads |
| `read:verified` | `GET /verified`, `GET /allowlist` ★ |
| `write:verified` | `POST`/`DELETE /verified`, `POST`/`DELETE /allowlist` ★ — plus the reads |
| `read:idv` | `GET /idv/sessions`, `GET /idv/requests` ★ |
| `write:idv` | `POST /idv/require`, `DELETE /idv/require` ★, `POST /idv/sessions/{id}/accept` ★, `DELETE /idv/verified` ★ — plus the reads |
| `read:insights` ★ | `GET /insights/prevented`, `GET /insights/financial`, `GET /network`, `GET /network/{ident}`, `GET /geography/{level}`, `GET /geography/{level}/customers` |
| `manage:webhooks` | all `/webhooks` routes |

`read:events` returns the same payloads a webhook subscriber receives, including emails, names and IPs where the topic carries them. Treat it as equivalent in sensitivity to a subscription. `read:customers`, `read:orders` and `read:insights` likewise return customer emails, names, phones and IPs — they are investigation scopes.

### Rate limits and response headers

Fixed one-minute window per key. Every response carries:

```
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 119
X-RateLimit-Reset: 1787167380
```

Over the limit, captured verbatim from a key limited to 3/minute:

```
HTTP/1.1 429
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1787167200
Retry-After: 9
```

```json
{ "code": "fs_api_rate_limited", "message": "Rate limit exceeded. Slow down and try again shortly.", "data": { "status": 429, "retry_after": 5 } }
```

Risk answers are per-customer and must never be cached by an intermediary — that is what `no-store` is for.

### Errors

| Status | Codes | Meaning |
|---|---|---|
| 400 | `fs_api_bad_type`, `fs_api_bad_value`, `fs_api_no_signals`, `fs_api_empty_payload`, `fs_api_bad_identifier`, `fs_hook_bad_url`, `fs_hook_insecure`, `fs_hook_private`, `fs_hook_unresolved`, `fs_hook_no_topics` | Malformed request |
| 401 | `fs_api_no_credentials`, `fs_api_unauthorized`, `fs_api_signature_required`, `fs_api_bad_signature`, `fs_api_stale_request`, `fs_api_bad_nonce` | Authentication failed |
| 403 | `fs_api_forbidden`, `fs_api_insecure`, `fs_api_bad_pairing`, `fs_api_no_pairing` | Authenticated but not permitted |
| 404 | `fs_api_not_found` | No such customer, order, address, IP, lock, block, session or subscription |
| 409 | `fs_api_replay`, `fs_api_idv_unavailable`, `fs_api_card_check_unavailable` ★ | Conflicts with current state |
| 413 | `fs_api_body_too_large` | Body over 256 KB |
| 415 | `fs_api_unsupported_media` | Send JSON; multipart is refused |
| 429 | `fs_api_rate_limited` | Slow down |
| 503 | `fs_api_disabled` | The API is switched off on this store |

```json
{ "code": "fs_api_no_credentials", "message": "Authentication required.", "data": { "status": 401 } }
{ "code": "fs_api_unauthorized", "message": "Authentication failed.", "data": { "status": 401 } }
{ "code": "fs_api_forbidden", "message": "This key does not carry the read:orders scope.", "data": { "status": 403 } }
{ "code": "fs_api_signature_required", "message": "This key requires signed requests.", "data": { "status": 401 } }
```

**Authentication failures are deliberately uniform.** Unknown key id, wrong secret, revoked, expired, or caller address not allow-listed all answer the same `401 fs_api_unauthorized`. The real reason is recorded in the store's Security log.

### Route index

| Method and path | Scope | Purpose |
|---|---|---|
| `POST /handshake` | none | Redeem a pairing code |
| `GET /ping` | any | Confirm a key; store identity, features, controls, topics, scopes |
| `GET /stats` | `read:risk` | Dashboard counters |
| `GET /settings` ★ | `read:risk` | Mode, scoring rules, control thresholds, classification |
| `GET /scan` ★ | `read:risk` | Scan / re-score progress and index coverage, read-only |
| `POST /risk/evaluate` | `read:risk` | Score an identity and get the checkout decision |
| `GET /customers` ★ | `read:customers` | Every identity, one row per email |
| `GET /customers/{ident}` | `read:customers` | The full customer dossier |
| `POST /customers/{ident}/restriction` ★ | `write:customers` | Put an account on restricted checkout |
| `DELETE /customers/{ident}/restriction` ★ | `write:customers` | Lift a restriction |
| `DELETE /customers/{ident}/hold` ★ | `write:customers` | Lift a new-account hold |
| `GET /addresses` ★ | `read:customers` | The Addresses registry: every shipping address with aggregates |
| `GET /addresses/{hash}` ★ | `read:customers` | Everything behind one shipping address |
| `GET /ips` ★ | `read:customers` | The IPs registry |
| `GET /ips/{ip}` ★ | `read:customers` | Everything behind one IP |
| `GET /reports/repeat-customers` ★ | `read:customers` | Accounts with N+ orders in a window |
| `GET /reports/new-customers` ★ | `read:customers` | Recently registered accounts ranked by order velocity |
| `GET /restrictions` ★ | `read:customers` | Accounts on staff-applied restricted checkout |
| `GET /holds` ★ | `read:customers` | Accounts under a verification hold |
| `GET /checkout-locks` ★ | `read:customers` | The Checkout Locks ledger with evidence |
| `GET /checkout-locks/{id}` ★ | `read:customers` | One ledger row |
| `DELETE /checkout-locks/{id}` ★ | `write:customers` | Dismiss a row |
| `GET /orders` | `read:orders` | Indexed orders, filtered |
| `GET /orders/{id}` | `read:orders` | One order with its ledgers and provenance |
| `POST /orders/{id}/chargeback` ★ | `write:orders` | Mark charged back |
| `DELETE /orders/{id}/chargeback` ★ | `write:orders` | Clear the manual mark |
| `POST /orders/{id}/recovery` ★ | `write:orders` | Record a recovery |
| `DELETE /orders/{id}/recovery` ★ | `write:orders` | Clear a recovery |
| `POST /orders/{id}/card-check` ★ | `write:orders` | Run the NMI cardholder check now |
| `GET /card-checks` ★ | `read:orders` | The Card Checks page |
| `GET /linked-cancels` ★ | `read:orders` | The Auto-Cancelled ledger |
| `GET /linked-cancels/{id}` ★ | `read:orders` | One auto-cancel record |
| `GET /high-value` (1.32) | `read:orders` | The High-Value Review queue for a window, with its headline numbers |
| `GET /high-value/reviews` (1.32) | `read:orders` | The review log: who decided what, on which order |
| `GET /high-value/reviews/{id}` (1.32) | `read:orders` | One verdict |
| `GET /high-value/leaderboard` (1.32) | `read:orders` | Reviewers ranked by verdicts recorded |
| `POST /orders/{id}/review` (1.32) | `write:orders` | Record a verdict: looks good, hold the package, or cancel now |
| `GET /blocklist` | `read:blocklist` | Credentials, filterable and searchable |
| `POST /blocklist` | `write:blocklist` | Block a credential (optionally cascading) |
| `DELETE /blocklist` ★ | `write:blocklist` | Remove many credentials |
| `DELETE /blocklist/{id}` | `write:blocklist` | Remove one credential |
| `PATCH /blocklist/{id}` ★ | `write:blocklist` | Set or clear a credential's checkout message |
| `GET /blocklist/actions` ★ | `read:blocklist` | Block cards with their credentials |
| `GET /blocklist/actions/{id}` ★ | `read:blocklist` | One block card |
| `DELETE /blocklist/actions/{id}` ★ | `write:blocklist` | Reverse a block |
| `PATCH /blocklist/actions/{id}` ★ | `write:blocklist` | Set or clear a card's checkout message |
| `POST /blocklist/actions/{id}/resync` ★ | `write:blocklist` | Re-run a block's cascade |
| `GET /verified` | `read:verified` | Verified credentials |
| `POST /verified` | `write:verified` | Verify an account or credential |
| `DELETE /verified` | `write:verified` | Withdraw a verification |
| `GET /allowlist` ★ | `read:verified` | Allowlisted emails |
| `POST /allowlist` ★ | `write:verified` | Allowlist an email |
| `DELETE /allowlist` ★ | `write:verified` | Remove an email |
| `GET /idv/sessions` | `read:idv` | ID-verification sessions |
| `POST /idv/sessions/{id}/accept` ★ | `write:idv` | Accept a name mismatch |
| `GET /idv/requests` ★ | `read:idv` | Accounts awaiting a demanded ID |
| `POST /idv/require` | `write:idv` | Demand an ID check |
| `DELETE /idv/require` ★ | `write:idv` | Withdraw a demand |
| `DELETE /idv/verified` ★ | `write:idv` | Revoke a verification |
| `GET /insights/prevented` ★ | `read:insights` | The Fraud-prevented panel |
| `GET /insights/financial` ★ | `read:insights` | The Financial Protection report |
| `GET /network` ★ | `read:insights` | The Customer Network list |
| `GET /network/{ident}` ★ | `read:insights` | One customer's connections |
| `GET /geography/{level}` ★ | `read:insights` | The Geography rollup |
| `GET /geography/{level}/customers` ★ | `read:insights` | Everyone shipping to one place |
| `GET /events` | `read:events` | The event log, as a cursor |
| `GET /webhooks` | `manage:webhooks` | Topics and subscriptions |
| `POST /webhooks` | `manage:webhooks` | Subscribe |
| `DELETE /webhooks/{id}` | `manage:webhooks` | Unsubscribe |
| `POST /webhooks/{id}/test` | `manage:webhooks` | Send a test event |

`{ident}` is a URL-encoded email address, or `id:<number>` for a WordPress account. `{hash}` is a 64-hex `address_id`. `{level}` is `state`, `city`, `zip` or `street`.

### Shared objects

Several routes return the same objects. They are defined once here (shapes; field types exact).

**Order row** — returned by `GET /orders`, `GET /orders/{id}`, and inside `GET /network/{ident}`:

```json
{
  "order_id": 51802,
  "date": "2026-08-10T19:17:14-04:00",
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "phone": "3055557712",
  "total": 705.25,
  "status": "completed",
  "gateway": "Credit Card",
  "shipping_method": "Flat rate",
  "coupons": ["SAVE10"],
  "ip": "198.51.100.66",
  "device_id": "<sha256 of the user agent, or empty>",
  "address_id": "4367737119a2aa85db47887b1bdff61d7d281912fd2716c97616abe402ce76b6",
  "address_loose_id": "<sha256>",
  "door_id": "<sha256>",
  "billing_address_id": "<sha256>",
  "billing_country": "US",
  "geo": { "country": "US", "state": "FL", "city": "doral", "postcode": "33122", "street": "nw 82nd ave" },
  "chargeback": false,
  "recovered": 0,
  "risk": { "score": 165, "level": "block", "reasons": ["Shipping address was used on a prior charged-back order"] },
  "indexed_at": "2026-08-19T15:00:00-04:00"
}
```

Fields through `chargeback` and `risk` are unchanged from 1.24; `phone`, `shipping_method`, `coupons`, `device_id`, `address_loose_id`, `door_id`, `billing_address_id`, `billing_country`, `geo.street`, `recovered` and `indexed_at` are ★ 1.30.

**Link** — a credential shared with a blocked identity, from `FS_Blocklist::linked()`; returned under `state.blocklist_links` on `/risk/evaluate`, `links` on auto-cancel records, and `evidence.links` on a linked Checkout Locks row:

```json
{
  "via": "address",
  "value": "<address hash, phone digits, IP, email or account id>",
  "on_order": true,
  "degree": 1,
  "blocked_type": "address",
  "blocked_value": "<the blocklist entry reached>",
  "block_card": "Email: fraud.ring@example.com",
  "identities": [ { "email": "fraud.ring@example.com", "user_id": 0, "name": "R Castillo", "blocked": true } ]
}
```

`via` ∈ `email` `phone` `ip` `address` `user`. `degree` 1 = the credential itself is blocked; 2 = another identity using it is blocked. `identities` lists up to three accounts/profiles behind the credential, blocked first, never the identity being evaluated.

**Blocklist evidence** — what matched, as the Checkout Locks "Blocked by" column shows it; returned under `state.blocklist_evidence` on `/risk/evaluate`, `blocklist` on `GET /orders/{id}` (items only), and `evidence` on a blocklist-kind Checkout Locks row:

```json
{
  "kind": "blocklist",
  "at": "2026-08-19T15:17:55-04:00",
  "items": [
    {
      "type": "ip",
      "value": "198.51.100.66",
      "label": "IP 198.51.100.66",
      "reason": "Shared drop-address IP",
      "blocked_at": "2026-08-19T15:17:55-04:00",
      "blocked_by": "Fraud Shield (automatic)",
      "cards": [ { "id": 2, "kind": "ip", "title": "198.51.100.66", "created_by": "Admin" } ],
      "identities": [ { "email": "fraud.ring@example.com", "user_id": 0, "name": "R Castillo", "blocked": true } ],
      "more": 0
    }
  ]
}
```

For an `address` match, `label` is the address as the customer typed it with how it matched (exact, same address line and ZIP, or same door). `blocked_by` is a staff name or *Fraud Shield (automatic)* for a cascade or auto-extension. A `place` match has no identities.

**Checkout Locks row** — returned by `/checkout-locks` and under `refusals` on a customer:

```json
{
  "id": 7,
  "kind": "blocklist",
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "detail": "ip, address",
  "attempts": 3,
  "first_at": "2026-08-19T15:17:55-04:00",
  "last_at": "2026-08-19T16:00:00-04:00",
  "resolved": false,
  "evidence": { "kind": "blocklist", "at": "...", "items": [ "...see Blocklist evidence..." ] }
}
```

`kind` ∈ `blocklist` `velocity` `idv` `linked`. `evidence` is a *Blocklist evidence* object for `blocklist`, `{ "kind": "linked", "at": ..., "links": [ ...Link... ] }` for `linked`, and `null` for `velocity` and `idv` (which record no evidence) or for rows that predate 1.29.

**Auto-cancel record** — returned by `/linked-cancels`, under `linked_cancel` on `GET /orders/{id}` and on a customer:

```json
{
  "id": 1,
  "order_id": 51802,
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "total": 705.25,
  "currency": "USD",
  "summary": "shares shipping address with blocked R Castillo (fraud.ring@example.com)",
  "shares": ["address"],
  "links": [ "...Link..." ],
  "attempts": 2,
  "cancelled_at": "2026-08-19T15:17:55-04:00",
  "last_attempt_at": "2026-08-19T16:00:00-04:00"
}
```

`attempts` counts the cancelled order plus every later checkout refused at the gate while the link stood.

**Card check** — returned by `/card-checks`, under `card_check` on `GET /orders/{id}` and on a customer:

```json
{
  "order_id": 51802,
  "email_norm": "jokafordeals@gmail.com",
  "gateway": "stripe_cc",
  "outcome": "fail",
  "cardholder": "mismatch",
  "id_verified": true,
  "avs": "N",
  "cvv": "M",
  "brand": "visa",
  "last4": "4242",
  "three_ds": "",
  "key_label": "MID 1",
  "checked_at": "2026-08-19T19:22:37+00:00"
}
```

The order-detail form (read from the order itself) adds `bin`, `eci`, `cavv_result`, `condition` and, for a check that could not complete, `reason`.

**Restriction** — a per-account gateway restriction:

```json
{
  "user_id": 5109,
  "email_norm": "dana.reeve@example.com",
  "gateways": ["bacs", "cod"],
  "low_value_amount": 25,
  "low_value_gateways": ["stripe_cc"],
  "applied_at": "2026-08-19T19:22:37+00:00",
  "expires_at": "2027-02-15T19:22:37+00:00",
  "void_reason": ""
}
```

`expires_at` is `null` when the store's window is 0 (never) or the record predates stamping. `void_reason` is `""` while in force, or `expired` / `idv` / `verified` when the stored record no longer applies and will be swept.

**Hold** — a new-account verification hold:

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com", "order_id": 51901, "amount": 415, "age_days": 4, "since": "2026-08-17T19:17:14-04:00" }
```

**Blocklist entry** — returned by `/blocklist` and inside block cards:

```json
{
  "id": 2,
  "type": "ip",
  "value": "198.51.100.66",
  "label": "IP 198.51.100.66",
  "reason": "Shared drop-address IP",
  "message": "",
  "added_by": 0,
  "added_by_name": "Fraud Shield (automatic)",
  "created_at": "2026-08-19T15:17:55-04:00",
  "cards": [ { "id": 1, "kind": "manual", "title": "Email: fraud.ring@example.com" } ]
}
```

`id`, `type`, `value`, `reason` and `created_at` are unchanged from 1.24; the rest is ★ 1.30. `message` is the per-credential checkout message; `cards` are the block cards owning the entry (empty for a legacy "ungrouped" row).

**Block card** (block action):

```json
{
  "id": 1,
  "kind": "manual",
  "target": "email:fraud.ring@example.com",
  "title": "Email: fraud.ring@example.com",
  "message": "",
  "created_by": 1,
  "created_by_name": "Admin",
  "created_at": "2026-08-19T15:17:55-04:00",
  "entries": [ "...Blocklist entry..." ]
}
```

`kind` ∈ `manual` `profile` `address` `ip` `place` `import`.

**Customer row** — returned by `GET /customers` and `GET /network`:

```json
{
  "email_norm": "cole.smith@example.com",
  "name": "Cole Smith",
  "user_id": 4182,
  "registered": "2025-07-15T19:19:50-04:00",
  "orders": 2,
  "paid_orders": 2,
  "spend": 531.5,
  "average_order_value": 265.75,
  "chargebacks": 0,
  "distinct_ips": 1,
  "distinct_addresses": 1,
  "max_risk_score": 0,
  "first_order": "2025-10-23T19:19:50-04:00",
  "last_order": "2026-04-21T19:19:50-04:00"
}
```

`GET /network` rows add `blocked`, `chargeback_amount` and `distinct_phones` and omit `registered`.

### POST /handshake

No credential required. Rate limited to 10 attempts per hour per IP. Five wrong guesses burn the window. See [Option A](#option-a--pair-an-external-platform-recommended) for the admin side.

**Request**

```json
{
  "code": "fspc_1b232ce3f08bf1ef7dd9d6da673b6a964ff72b6e",
  "platform": "bonesfraud.com",
  "callback_url": "https://bonesfraud.com/hooks/atomiklabz",
  "topics": ["order.flagged", "checkout.blocked", "idv.approved"]
}
```

| Field | Required | Notes |
|---|---|---|
| `code` | yes | Single use. Dies on first success, on expiry, or after 5 failures |
| `platform` | no | Labels the resulting key in the admin UI |
| `callback_url` | no | If present and the window allows it, a webhook subscription is created |
| `topics` | no | Defaults to every topic except `test.ping` |

**Response — `201`, captured verbatim** (1.24 run; a 1.30 store reports `"version": "1.30.0"`, `"db_version": "19"`)

```json
{
  "ok": true,
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "name": "Atomik Labz",
    "url": "https://store.example.com/",
    "plugin": "fraud-shield",
    "version": "1.24.0",
    "db_version": "12",
    "api_version": "v1"
  },
  "api": {
    "base": "https://store.example.com/wp-json/fraud-shield/v1",
    "namespace": "fraud-shield/v1",
    "signing": {
      "required": true,
      "algorithm": "HMAC-SHA256",
      "window": 300,
      "canonical": "v1\\nMETHOD\\nroute\\nsorted_query\\nsha256(body)\\ntimestamp\\nnonce",
      "headers": ["X-FS-Key", "X-FS-Timestamp", "X-FS-Nonce", "X-FS-Signature"]
    }
  },
  "credential": {
    "token": "fsk_1bd8dcd2d870cf80_b18527bb574177fd888eaa7ab7ed21b48818f619141be968",
    "key_id": "1bd8dcd2d870cf80",
    "scopes": ["read:risk", "read:customers", "read:orders", "read:events"],
    "notice": "Store this token now. It cannot be retrieved again."
  },
  "webhook": {
    "id": 1,
    "secret": "fswh_3d81aa106e50ac1b5f503028ac8cad9ae8e39c1775e45b50",
    "topics": ["order.flagged", "checkout.blocked", "idv.approved"],
    "signature": "X-FS-Signature: t=<unix>,v1=<hex hmac-sha256 of \"t.body\">"
  }
}
```

Store `credential.token` and `webhook.secret` immediately — neither is retrievable afterwards. Key your records on `store_id`. Honour `api.signing.required`.

Wrong code — `403 { "code": "fs_api_bad_pairing", "message": "That pairing code is not valid." }`. Replaying a used code — `403 { "code": "fs_api_no_pairing", "message": "No pairing is open on this store." }`.

If the callback URL is rejected (not HTTPS, resolves privately, does not resolve), the credential is still issued and `webhook` carries an `error` field instead — a bad URL must not cost the platform its key.

### GET /ping

**Scope:** any valid key. Confirms a credential and reports what the store can do.

**Response — `200` (shape; `store`, `time`, `key`, `features.mode`, `features.id_verification`, `features.cardholder_check` and `features.webhook_topics` are as captured in 1.24; `controls` and `scopes` are ★ 1.30)**

```json
{
  "ok": true,
  "store": { "store_id": "357a7b151fbabfa135907704094e267c", "name": "Atomik Labz", "url": "https://store.example.com/", "plugin": "fraud-shield", "version": "1.30.0", "db_version": "19", "api_version": "v1" },
  "time": "2026-10-02T19:22:37+00:00",
  "key": { "key_id": "3ec576b720cd1043", "label": "bonesfraud.com (full)", "scopes": ["read:risk", "..."], "signed": false },
  "features": {
    "mode": "enforce",
    "id_verification": true,
    "cardholder_check": true,
    "webhook_topics": ["order.scored", "order.flagged", "checkout.blocked", "customer.verified", "customer.unverified", "customer.blocked", "idv.approved", "idv.declined", "idv.name_mismatch", "card.checked", "restriction.applied", "restriction.released", "order.cancelled", "order.chargeback", "order.recovery", "customer.unblocked", "blocklist.removed", "hold.applied", "hold.released", "idv.requested", "idv.request_cleared", "order.reviewed", "test.ping"],
    "controls": {
      "action_high": "hold",
      "linked_cancel": true,
      "linked_cancel_types": ["email", "phone", "ip", "address", "user"],
      "auto_extend_blocks": true,
      "velocity_lock": true,
      "velocity_lock_action": "block",
      "new_account_hold": true,
      "gateway_restriction": true,
      "high_value_rules": false,
      "auto_verify": true,
      "amount_basis": "subtotal"
    },
    "scopes": ["read:risk", "read:customers", "read:orders", "read:events", "read:blocklist", "read:verified", "read:idv", "read:insights", "write:blocklist", "write:verified", "write:idv", "write:orders", "write:customers", "manage:webhooks"]
  }
}
```

`features.mode` is `observe` or `enforce`. In `observe`, heuristic scores are recorded but never block; the blocklist and the explicit locks still apply. `controls` tells you which deliberate controls can produce a `decision` other than `allow`/`review` and which events to expect (no `order.cancelled` unless `linked_cancel`; no `hold.*` unless `new_account_hold`). `action_high` is what a `high` score does under Enforce: `hold`, `block` or `flag`.

### GET /stats

**Scope:** `read:risk`.

**Response — `200` (shape; the first seven keys and `webhooks` are as captured in 1.24)**

```json
{
  "orders_indexed": 7,
  "chargebacks": 1,
  "high_risk": 1,
  "medium_risk": 0,
  "blocklist": 2,
  "block_actions": 2,
  "checkout_blocks": 0,
  "verified_credentials": 1,
  "allowlist": 0,
  "id_verification": { "total": 2, "approved": 1, "name_mismatch": 1, "declined": 0, "expired": 0, "pending": 0, "people": 2 },
  "linked_cancels": { "total": 1, "last_30d": 1, "value": 705.25, "retries": 1, "currency": "USD" },
  "prevented": {
    "currency": "USD",
    "all_time": { "fraud_amount": 705.25, "verify_amount": 415, "fraud_stopped": 1, "cancelled": 1, "refused": 1, "attempts": 3 },
    "last_30d": { "fraud_amount": 705.25, "verify_amount": 415, "fraud_stopped": 1, "cancelled": 1, "refused": 1, "attempts": 3 }
  },
  "card_checks": 1,
  "webhooks": { "subscriptions": 0, "events": 0, "pending": 0, "delivered": 0, "failed": 0 }
}
```

`high_risk` counts orders at level `high` **or** `block`. `checkout_blocks` is the number of unresolved Checkout Locks rows. `prevented.*.fraud_amount` is the Dashboard headline — money stopped by a verdict; `verify_amount` is money merely held back pending verification and is never added in.

### GET /settings ★

**Scope:** `read:risk`. The merchant-visible configuration that shapes every answer this API gives. Read-only; never a secret (Didit / NMI keys, webhook secrets) and never the customer-facing messages. A platform interpreting `risk.score` across stores needs this to know what a 70 means on *this* one.

**Response — `200` (shape)**

```json
{
  "mode": "enforce",
  "action_high": "hold",
  "count_refunded_as_chargeback": false,
  "amount_basis": "subtotal",
  "scoring": {
    "levels": { "medium": 40, "high": 70, "block": 110 },
    "weights": { "cb_email": 80, "cb_ship": 70, "cb_phone": 60, "cb_ship_loose": 40, "cb_ip": 35, "addr_multi_email": 25, "...": "..." },
    "thresholds": { "addr_multi_email": 3, "addr_multi_name": 3, "email_multi_addr": 4, "...": "..." },
    "disposable_domains": 412
  },
  "controls": {
    "auto_extend_blocks": true,
    "linked_cancel": { "enabled": true, "types": ["email", "phone", "ip", "address", "user"] },
    "velocity_lock": { "enabled": true, "threshold": 2, "action": "block", "gateways": [], "new_only": true, "new_months": 3 },
    "new_account_hold": { "enabled": true, "days": 90, "amount": 500, "hold_order": true },
    "gateway_restriction": { "enabled": true, "days": 90, "allowed": ["bacs"], "low_value_amount": 0, "low_value_gateways": [], "expire_months": 6, "release_on_idv": true },
    "auto_verify": { "enabled": true, "min_days": 180, "min_orders": 1, "require_clean": true },
    "high_value": { "enabled": false, "amount_none": 1000, "amount_auto": 3000, "amount_idv": 10000, "hard_amount": 0, "fresh_hours": 24, "gateways": [] },
    "id_verification": { "configured": true, "name_match": "lenient", "mismatch_action": "verify", "valid_days": 365, "max_attempts": 3 },
    "cardholder_check": { "enabled": true, "gateways": [], "accept_partial": false, "action": "flag", "scope": "all" }
  },
  "financial": { "chargeback_fee": 15, "loss_ratio": 100, "min_window_days": 90 },
  "classification": { "payment_methods": { "Credit Card": "card", "Bank transfer": "other" }, "shipping_methods": { "Overnight": "expedited" } }
}
```

The rule keys under `weights` and `thresholds` are the ones in `FRAUD-RISK-CRITERIA.md`. `classification` is the merchant's labelling of payment methods (`card` / `other`) and shipping methods (`expedited` / `standard`) that the `card_only` and `fast_shipping` rules depend on.

### GET /scan ★

**Scope:** `read:risk`. The Dashboard's scan panel, read-only: whether a back-history scan or a re-score is running, how far it has got, and how much of the index is still unscored. Starting, pausing and cancelling a scan remain wp-admin actions.

**Response — `200` (shape)**

```json
{
  "status": "running",
  "kind": "full",
  "phase": "score",
  "range": "12m",
  "date_after": "2025-10-05T00:00:00+00:00",
  "skip_indexed": true,
  "score_mode": "new",
  "progress": { "done": 1400, "total": 4120, "percent": 34.0 },
  "index": { "orders": 4120, "unscored": 2720 },
  "started_at": "2026-10-05T12:01:00-04:00",
  "updated_at": "2026-10-05T12:06:30-04:00"
}
```

`status` ∈ `idle` `running` `paused` `complete`; `kind` ∈ `full` (index then score) `rescore` (re-apply the rules to the whole index); `phase` ∈ `index` `score`; `range` ∈ `all` `1m` `3m` `6m` `12m` `new`. `progress.percent` is `null` when the phase's total is not yet known. A store whose `index.unscored` is non-zero outside a running scan has orders waiting for the next scan; their `risk.level` reads `""` until then.

### POST /risk/evaluate

**Scope:** `read:risk`. The most useful endpoint: score an identity that has no order yet and learn what this store's checkout would do with it right now.

**Request fields** — at least one of `email`, `phone`, `ip`, `address`, `user_id` is required.

| Field | Type | Notes |
|---|---|---|
| `email` | string | Normalized exactly as an order's would be |
| `phone` | string | Reduced to digits; a leading US `1` is dropped |
| `ip` | string | Discarded if not a valid IP |
| `name` | string | Used for name-cluster signals |
| `user_id` | int | WordPress account id, if known |
| `amount` | number | Drives the high-value rules and the gateway answer |
| `gateway` | string | Adds a per-gateway allow/deny answer |
| `shipping_method` | string | Feeds the expedited-shipping signal |
| `address` | object | `address_1`, `address_2`, `city`, `state`, `postcode`, `country` |
| `exclude_order_id` | int | Exclude one order from the history lookup (when re-scoring an indexed order) |

**Response shape**

```json
{
  "decision": "allow | review | restrict | verify | block",
  "reason": "",
  "risk": { "score": 0, "level": "low", "reasons": [] },
  "identity": { "email_norm": "...", "user_id": 0, "address_id": "<64 hex or empty>" },
  "state": {
    "verified": false,
    "verified_account": false,
    "id_verified": false,
    "id_check_required": false,
    "velocity_locked": false,
    "verification_hold": false,
    "blocklist_matches": [],
    "blocklist_evidence": [],
    "blocklist_links": [],
    "linked_sufficient": false,
    "high_value_rule": null
  },
  "gateway": null,
  "profile": { "...raw signal counts the rules fired on..." }
}
```

**`decision` is what to act on — never `risk.level` alone.** The two can disagree (a velocity-locked customer scores `low` and is still refused). The decision is computed in exactly the precedence checkout uses:

| Order | Condition | `decision` | `reason` |
|---|---|---|---|
| 1 | Blocklist match and not a staff-verified account | `block` | `blocklist` |
| 2 | Shares a credential with a blocked identity and the links suffice (rule enabled) | `block` | `linked_blocklist` |
| 3 | Staff or the API demanded an ID check | `verify` | `id_verification_requested` |
| 4 | Velocity rule tripped (N orders in the store's window) | the store's velocity action: `hold` ★ 1.31 (the order is accepted and placed On-Hold for review — proceed, but expect it to wait), `block`, or `restrict` | `velocity` |
| 5 | New-account verification hold | `block` | `verification_hold` |
| 6 | High-value **hard** rule with no fresh ID check | `verify` | `high_value` |
| 7 | Enforce mode and score is `block` (or `high` with the store's high action set to block) | `block` | `risk_score` |
| 8 | Score is `medium` or `high` | `review` | `risk_score` |
| — | Otherwise | `allow` | `""` |

**`state.blocklist_evidence` ★ 1.30** — when `blocklist_matches` is non-empty, the full *Blocklist evidence* `items` (see [Shared objects](#shared-objects)): each matched credential as submitted, since when and by whom it was blocked, the owning card, and the blocked identities behind it. Empty when nothing matched.

#### Example 1 — an unknown, clean identity (captured verbatim, 1.24; `blocklist_evidence` added)

```json
{
  "email": "new.shopper@example.com",
  "ip": "203.0.113.90",
  "amount": 120,
  "name": "Alex Turner",
  "address": { "address_1": "18 Cranbrook Road", "city": "Austin", "state": "TX", "postcode": "78701", "country": "US" }
}
```

```json
{
  "decision": "allow",
  "reason": "",
  "risk": { "score": 0, "level": "low", "reasons": [] },
  "identity": {
    "email_norm": "new.shopper@example.com",
    "user_id": 0,
    "address_id": "bc63f34ce181dec5d1864bb92870dcd1268280408d16fbd6b049d8239ac2198b"
  },
  "state": {
    "verified": false, "verified_account": false, "id_verified": false, "id_check_required": false,
    "velocity_locked": false, "verification_hold": false,
    "blocklist_matches": [], "blocklist_evidence": [], "blocklist_links": [], "linked_sufficient": false, "high_value_rule": null
  },
  "gateway": null,
  "profile": {
    "chargeback_email": false, "chargeback_phone": false, "chargeback_ship": false, "chargeback_ship_loose": false, "chargeback_ip": false,
    "emails_at_address": 1, "names_at_address": 1, "addresses_for_email": 1, "accounts_at_ip": 0,
    "orders_7d": 0, "orders_30d": 0, "orders_burst": 0, "account_age_days": null,
    "disposable_email": false, "country_mismatch": false, "bill_ship_mismatch": 0,
    "pay_orders": 0, "pay_card_orders": 0, "pay_card_only": false, "fast_shipping": false
  }
}
```

#### Example 2 — a drop address with a chargeback history and a blocklisted IP (captured verbatim, 1.24; `blocklist_evidence` added as shape)

```json
{
  "email": "new.buyer2201@gmail.com",
  "phone": "+1 305 555 7712",
  "ip": "198.51.100.66",
  "amount": 680,
  "name": "R Castillo",
  "gateway": "stripe_cc",
  "address": { "address_1": "2201 NW 82nd Ave", "city": "Doral", "state": "FL", "postcode": "33122", "country": "US" }
}
```

```json
{
  "decision": "block",
  "reason": "blocklist",
  "risk": {
    "score": 205,
    "level": "block",
    "reasons": [
      "Shipping address was used on a prior charged-back order",
      "Phone number was used on a prior charged-back order",
      "Order IP was used on a prior charged-back order",
      "Shipping address is shared by 3 different customer emails",
      "Shipping address is shared by 3 different customer names"
    ]
  },
  "identity": {
    "email_norm": "newbuyer2201@gmail.com",
    "user_id": 0,
    "address_id": "4367737119a2aa85db47887b1bdff61d7d281912fd2716c97616abe402ce76b6"
  },
  "state": {
    "verified": false, "verified_account": false, "id_verified": false, "id_check_required": false,
    "velocity_locked": false, "verification_hold": false,
    "blocklist_matches": ["ip"],
    "blocklist_evidence": [
      { "type": "ip", "value": "198.51.100.66", "label": "IP 198.51.100.66", "reason": "Shared drop-address IP",
        "blocked_at": "2026-08-19T15:17:55-04:00", "blocked_by": "Admin",
        "cards": [ { "id": 2, "kind": "manual", "title": "IP: 198.51.100.66", "created_by": "Admin" } ],
        "identities": [ { "email": "jokafordeals@gmail.com", "user_id": 0, "name": "J Okafor", "blocked": false } ], "more": 0 }
    ],
    "blocklist_links": [], "linked_sufficient": false, "high_value_rule": null
  },
  "gateway": { "id": "stripe_cc", "allowed": false },
  "profile": {
    "chargeback_email": false, "chargeback_phone": true, "chargeback_ship": true, "chargeback_ship_loose": true, "chargeback_ip": true,
    "emails_at_address": 3, "names_at_address": 3, "addresses_for_email": 1, "accounts_at_ip": 2,
    "orders_7d": 0, "orders_30d": 0, "orders_burst": 0, "account_age_days": null,
    "disposable_email": false, "country_mismatch": false, "bill_ship_mismatch": 0,
    "pay_orders": 0, "pay_card_orders": 0, "pay_card_only": false, "fast_shipping": false
  }
}
```

Note `newbuyer2201@gmail.com`: gmail dots are collapsed, so an ad-hoc lookup lands on the same identity a real order would.

#### Example 3 — a customer over the velocity threshold (decision and level disagree; captured verbatim)

```json
{ "email": "dana.reeve@example.com", "user_id": 5109, "amount": 415 }
```

```json
{
  "decision": "block",
  "reason": "velocity",
  "risk": { "score": 35, "level": "low", "reasons": ["Customer first seen 5 days before this order", "3 prior orders in the last 7 days"] },
  "state": { "verified": false, "velocity_locked": true, "blocklist_matches": [], "blocklist_evidence": [], "blocklist_links": [], "linked_sufficient": false, "high_value_rule": null },
  "profile": { "orders_7d": 3, "orders_30d": 3, "account_age_days": 5, "pay_orders": 3 }
}
```

#### Example 4 — a trusted customer above the hard high-value limit (captured verbatim)

```json
{ "email": "cole.smith@example.com", "user_id": 4182, "amount": 6200 }
```

```json
{
  "decision": "verify",
  "reason": "high_value",
  "risk": { "score": 0, "level": "low", "reasons": [] },
  "state": {
    "verified": true, "verified_account": false, "id_verified": false,
    "high_value_rule": { "mode": "hard", "tier": "staff", "threshold": 5000, "amount": 6200 }
  },
  "profile": { "account_age_days": 399, "pay_orders": 2, "idv_name_mismatch": false, "idv_vpn": false }
}
```

Only the staff *Verified account* flag is exempt from the hard high-value rule.

#### Linked identities (`state.blocklist_links`)

When the store's *Cancel orders linked to the blocklist* rule is on and the identity does not match the blocklist outright and is not verified, `blocklist_links` lists every credential it shares with a blocked identity as *Link* objects (see [Shared objects](#shared-objects)). `state.linked_sufficient` says whether the links would actually cancel an order. **An IP-only link never suffices** — an IP counts only alongside another shared credential — so a response can carry an `ip` link with `linked_sufficient: false` and `decision: allow`.

#### Empty payload — `400`

```json
{ "code": "fs_api_empty_payload", "message": "Provide at least one identity signal.", "data": { "status": 400 } }
```

### GET /customers ★

**Scope:** `read:customers`. Every identity the index knows, one row per normalized email — the Profiles page.

| Parameter | Notes |
|---|---|
| `search` | Free text across email, name, phone, IP and shipping address text |
| `orderby` | `email` `name` `registered` `orders` `paid` `spend` `aov` `chargebacks` `ips` `addresses` `risk` `first` `last` (default `last`) |
| `dir` | `asc` or `desc` (default) |
| `from`, `to` | Only orders inside the window are aggregated — counts, spend and risk describe the period, identities with no orders in it drop out. Same formats as `GET /orders` |
| `limit`, `offset` | 1–100, default 50 |

**Response — `200` (shape)**

```json
{ "total": 412, "limit": 50, "offset": 0, "customers": [ "...Customer row..." ] }
```

### GET /customers/{ident}

**Scope:** `read:customers`. `{ident}` is a URL-encoded email address, or `id:<number>` for a WordPress account. A guest identity with orders but no account resolves too, with `user_id: 0` and `registered: null`. Not found: `404 { "code": "fs_api_not_found", "message": "No such customer." }`.

```bash
curl -s 'https://store.example.com/wp-json/fraud-shield/v1/customers/id:4182' -H 'Authorization: Bearer fsk_...'
```

**Response — `200` (shape; `identity`, `history` and the first eight `state` keys are as captured in 1.24; the rest is ★ 1.30)**

```json
{
  "identity": { "email_norm": "cole.smith@example.com", "user_id": 4182, "name": "Cole Smith", "registered": "2025-07-15T19:19:50-04:00" },
  "history": {
    "orders": 2, "paid_orders": 2, "spend": 531.5, "average_order_value": 265.75, "chargebacks": 0,
    "distinct_ips": 1, "distinct_addresses": 1, "distinct_names": 1, "distinct_phones": 1, "distinct_gateways": 1, "distinct_countries": 1,
    "max_risk_score": 0,
    "first_order": "2025-10-23T19:19:50-04:00", "last_order": "2026-04-21T19:19:50-04:00"
  },
  "state": {
    "verified_account": false, "auto_verified": true, "email_verified": true, "email_allowlisted": false,
    "id_verified": false, "id_check_required": false, "velocity_locked": false, "verification_hold": false,
    "gateway_restriction": null,
    "trust_tier": "auto",
    "blocked": false
  },
  "trust": {
    "auto_verify": { "qualifies": true, "enabled": true, "reason": "", "age_days": 444, "min_days": 180, "paid_orders": 2, "min_orders": 1, "chargebacks": 0, "refunds": 0 },
    "id_verification": { "verified": false, "session_id": "", "full_name": "", "name_match": "", "method": "", "verified_at": null, "expires_at": null, "requested": null },
    "restriction": null,
    "hold": null
  },
  "credentials": {
    "phones":    [ { "value": "3055550100", "orders": 2 } ],
    "ips":       [ { "value": "198.51.100.12", "orders": 2 } ],
    "addresses": [ { "address_id": "<sha256>", "orders": 2 } ],
    "names":     [ { "value": "Cole Smith", "orders": 2 } ],
    "gateways":  [ { "value": "Credit Card", "orders": 2 } ],
    "devices":   [ { "device_id": "<sha256>", "orders": 2 } ],
    "coupons":   [ { "code": "WELCOME10", "orders": 1 } ]
  },
  "blocklist": [],
  "links": [ { "email_norm": "other@example.com", "name": "Other Person", "user_id": 0, "orders": 3, "shared_ips": 1, "shared_addresses": 0, "chargebacks": 0 } ],
  "refusals": [ "...Checkout Locks row..." ],
  "linked_cancel": null,
  "card_check": null,
  "analysis": {
    "patterns": {
      "orders_dated": 2,
      "average_gap_days": 180.0,
      "shortest_gap_days": 180.0,
      "busiest_window": { "start_hour": 19, "end_hour": 22, "orders": 2 },
      "hour_histogram": [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0, 0, 0, 0]
    },
    "geo_checks": {
      "available": true,
      "known": 1,
      "matched": 1,
      "ips": [ { "ip": "198.51.100.12", "orders": 2, "ip_country": "US", "ip_state": "TX", "billing_country": "US", "shipping_country": "US", "match": true } ]
    },
    "consistency": {
      "billing_shipping": { "known": 2, "matched": 2 },
      "account": {
        "account_email": "cole.smith@example.com",
        "account_name": "Cole Smith",
        "emails_used": ["cole.smith@example.com"],
        "names_used": ["Cole Smith"],
        "phones_used": ["3055550100"],
        "other_emails": 0,
        "name_matches_account": true
      }
    }
  }
}
```

| Key | Meaning |
|---|---|
| `state.trust_tier` | `staff` / `idv` / `auto` / `none` — the strongest standing in force |
| `state.blocked` | Whether any of the identity's credentials (email, account, phones, IPs, addresses) is on the blocklist right now |
| `state.gateway_restriction` | The *Restriction* in force, or `null` when there is none or it has lapsed |
| `trust.auto_verify` | The automatic-verification verdict and the numbers behind it. `reason` is `""` when it qualifies, else `unknown` / `too_new` / `too_few_orders` / `not_clean` / `disabled` |
| `trust.id_verification` | The verification record in force (account first, then the email map) and any open staff demand under `requested` |
| `trust.restriction` | The stored restriction even if lapsed (`void_reason` says why); `state.gateway_restriction` is the one actually enforced |
| `trust.hold` | The *Hold* record, or `null` |
| `credentials` | Every credential the identity has used, each with how many orders (capped at 50 per list) |
| `blocklist` | The blocklist rows holding any of those credentials, each with `blocked_at`, `blocked_by` and the owning `cards` — the order screen's provenance box |
| `links` | Other identities sharing an IP or shipping address, as the Profiles page lists them (up to 100) |
| `refusals` | Every time this identity was refused at checkout (resolved or not, newest first, up to 20), with evidence |
| `linked_cancel` | The most recent *Auto-cancel record*, or `null` |
| `card_check` | The most recent *Card check*, or `null` |
| `analysis.patterns` | Ordering cadence over the identity's dated orders (up to 500): the average and shortest gap between orders in days (`null` with fewer than two), the densest three-hour window in store time, and a 24-bucket hour-of-day histogram |
| `analysis.geo_checks` | For each IP the identity ordered from: where it geolocates (WooCommerce's local MaxMind database — `available: false` and empty countries when the integration is not configured), the billing and shipping countries entered on those orders, and whether they agree (`match` is `null` when the IP could not be located). `known`/`matched` summarise |
| `analysis.consistency.billing_shipping` | How many orders had a billing address on record, and how many of those matched the shipping address |
| `analysis.consistency.account` | Accounts only: the emails, names and phones this account has ordered under, against the account's own email and name — `other_emails` is how many order emails differ from the account's; `name_matches_account` is `null` when the account has no name |

### POST /customers/{ident}/restriction ★

**Scope:** `write:customers`. Put an account on restricted checkout — the admin's "Restrict Checkout & Unblock". The customer may pay only with the listed gateways (plus, under the low-value threshold, the low-value set), and is released from the velocity lock, the hold and any open ID demand: the restriction itself is the release. It does **not** mark the account Verified. Stamps `applied_at`, so the restriction lapses after the store's window; re-saving restarts the clock. Emits `restriction.applied` (and `hold.released` / `idv.request_cleared` when those applied).

```json
{ "gateways": ["bacs", "cod"], "low_value_amount": 25, "low_value_gateways": ["stripe_cc"] }
```

| Field | Required | Notes |
|---|---|---|
| `gateways` | yes | At least one gateway id. An empty list is refused with `400` — a restriction with no allowed gateway would fail open |
| `low_value_amount` | no | Threshold under which the low-value set is also allowed (0 = off) |
| `low_value_gateways` | no | Gateways allowed only under the threshold |

**Response — `201` (shape):** `{ "ok": true, "user_id": 5109, "restriction": { "...Restriction..." } }`. A guest identity answers `400 fs_api_bad_value` — a restriction applies to a registered account.

### DELETE /customers/{ident}/restriction ★

**Scope:** `write:customers`. Take the account off restricted checkout; normal rules apply again. Emits `restriction.released`.

```json
{ "ok": true, "user_id": 5109, "released": true }
```

`released` is `false` when there was nothing to lift.

### DELETE /customers/{ident}/hold ★

**Scope:** `write:customers`. Lift a new-account verification hold without verifying the account. The held triggering order is advanced to processing **only if it was actually paid**; an unpaid held order stays on-hold for the merchant. Emits `hold.released`.

```json
{ "ok": true, "user_id": 5109, "released": true }
```

### GET /addresses and GET /ips ★

**Scope:** `read:customers`. The Addresses and IPs registries: *every* shipping address or IP in the index (not just the risky ones), one row each with its headline aggregates — browse, search, sort, page. `orderby=risk` is the Dashboard's "top risky addresses / IPs" table.

| Parameter | Notes |
|---|---|
| `search` | Free text across email, name, phone, IP and shipping address text |
| `orderby` | `emails` (default) `names` `orders` `paid` `spend` `chargebacks` `other` (the cross dimension: IPs per address, addresses per IP) `risk` `first` `last`; also `address` on `/addresses` |
| `dir` | `asc` / `desc` (default) |
| `limit`, `offset` | 1–100, default 50 |

**Response — `200` (shape, `/addresses`)**

```json
{
  "total": 1830, "limit": 50, "offset": 0,
  "addresses": [
    {
      "address_id": "4367737119a2aa85db47887b1bdff61d7d281912fd2716c97616abe402ce76b6",
      "address": "2201 NW 82nd Ave, Doral FL 33122 US",
      "orders": 4, "paid_orders": 3, "spend": 1850.5,
      "distinct_emails": 3, "distinct_names": 3, "chargebacks": 1,
      "max_risk_score": 165,
      "first_order": "2026-03-02T10:00:00-04:00", "last_order": "2026-08-10T19:17:14-04:00",
      "blocked": true, "verified": false,
      "distinct_ips": 2
    }
  ]
}
```

`/ips` returns `ips[]` with `ip` in place of `address_id`/`address` and `distinct_addresses` in place of `distinct_ips`. `address` is the shipping address as it appears on the most recent order — this registry is the one place the API returns address text, because browsing addresses is what the page is for; order rows carry only the hash. `blocked` is whether the exact value is on the blocklist (a loose or door-key match of the address is not consulted here); `verified` whether it is a verified credential.

### GET /addresses/{hash} and GET /ips/{ip} ★

**Scope:** `read:customers`. Everything behind one shipping address (`{hash}` = a 64-hex `address_id`) or one IP — the registries' detail pages: the cluster summary, whether it is blocked or verified, the block card if any, every identity that used it, the payment methods, device signatures and coupon codes seen there, and its most recent orders.

**Response — `200` (shape, address form)**

```json
{
  "type": "address",
  "id": "4367737119a2aa85db47887b1bdff61d7d281912fd2716c97616abe402ce76b6",
  "address": "2201 NW 82nd Ave, Doral FL 33122 US",
  "summary": {
    "orders": 4, "paid_orders": 3, "distinct_emails": 3, "distinct_names": 3, "distinct_countries": 1,
    "chargebacks": 1, "paid_value": 1850.5,
    "first_order": "2026-03-02T10:00:00-04:00", "last_order": "2026-08-10T19:17:14-04:00",
    "distinct_ips": 2
  },
  "geo": { "country": "US", "state": "FL", "city": "doral", "street": "nw 82nd ave" },
  "blocked": true,
  "block_action": { "...Block card without entries..." },
  "verified": false,
  "identities": [
    { "email_norm": "jokafordeals@gmail.com", "name": "J Okafor", "user_id": 0, "phone": "3055557712",
      "orders": 1, "paid_orders": 1, "spend": 705.25, "chargebacks": 0,
      "first_order": "2026-08-10T19:17:14-04:00", "last_order": "2026-08-10T19:17:14-04:00", "latest_gateway": "Credit Card" }
  ],
  "gateways": [ { "value": "Credit Card", "orders": 4 } ],
  "devices":  [ { "device_id": "<sha256>", "orders": 3 } ],
  "coupons":  [ { "code": "SAVE10", "orders": 1 } ],
  "orders":   [ "...Order row..." ],
  "orders_capped": false
}
```

The IP form has `"type": "ip"`, reports `distinct_addresses` instead of `distinct_ips`, and has no `address` or `geo`. `orders` is the location's most recent 25 (`orders_capped` says whether there are more — page them with `GET /orders?address_id=` or `?ip=`). `404 fs_api_not_found` when no order uses it; `400 fs_api_bad_identifier` for an invalid IP.

### GET /reports/repeat-customers ★

**Scope:** `read:customers`. The Repeat Customers report: registered accounts with at least `min_orders` counted orders (paid or charged back) whose dates fall in the window.

| Parameter | Notes |
|---|---|
| `from`, `to` | Window in the store's timezone (same formats as `GET /orders`); defaults to all time |
| `min_orders` | Default 2 |
| `orderby` | `last_order` (default) `name` `email` `registered` `orders` `spend` `chargebacks` `payment` `ip` |
| `dir`, `search`, `limit`, `offset` | As elsewhere |

```json
{
  "total": 14, "limit": 50, "offset": 0, "from": "1970-01-01T00:00:00+00:00", "to": "2026-10-05T12:00:00-04:00", "min_orders": 2,
  "customers": [
    { "user_id": 4182, "email": "cole.smith@example.com", "name": "Cole Smith", "registered": "2025-07-15T19:19:50-04:00",
      "orders": 2, "spend": 531.5, "chargebacks": 0, "distinct_ips": 1, "distinct_addresses": 1,
      "first_order": "2025-10-23T19:19:50-04:00", "last_order": "2026-04-21T19:19:50-04:00",
      "latest_ip": "198.51.100.12", "latest_gateway": "Credit Card", "latest_country": "US" }
  ]
}
```

### GET /reports/new-customers ★

**Scope:** `read:customers`. The New Customers report: accounts registered in the last `months` months with at least `min_orders` counted orders, ranked by order velocity — the burst-then-chargeback pattern.

| Parameter | Notes |
|---|---|
| `months` | Registration window, default 3 (1–120) |
| `min_orders` | Default 2 |
| `orderby` | `frequency` (orders per month, default) `spend` `orders` `chargebacks` `risk` `recent` `registered` `age` `name` `email` |
| `dir`, `search`, `limit`, `offset` | As elsewhere |

```json
{
  "total": 6, "limit": 50, "offset": 0, "months": 3, "min_orders": 2,
  "customers": [
    { "user_id": 5109, "email": "dana.reeve@example.com", "name": "Dana Reeve", "registered": "2026-08-12T19:19:50-04:00",
      "age_days": 54, "orders": 3, "orders_per_month": 1.67, "spend": 1245, "chargebacks": 0,
      "distinct_ips": 1, "distinct_addresses": 1, "distinct_emails": 1, "max_risk_score": 35,
      "first_order": "2026-08-15T19:17:14-04:00", "last_order": "2026-08-17T19:17:14-04:00" }
  ]
}
```

Both reports count registered accounts only (a guest has no registration date); `GET /customers` covers every identity, guests included.

### GET /restrictions ★

**Scope:** `read:customers`. Accounts on **staff-applied** restricted checkout — stored records only. A velocity-derived restriction is a live computation, visible per customer as `state.velocity_locked`. `limit`, `offset`.

```json
{ "total": 3, "limit": 50, "offset": 0, "restrictions": [ "...Restriction..." ] }
```

Rows whose `void_reason` is non-empty are stale records the store will sweep; they are not enforced.

### GET /holds ★

**Scope:** `read:customers`. Accounts under a new-account verification hold — the Verification queue on Checkout Locks. `limit`, `offset`.

```json
{ "total": 1, "limit": 50, "offset": 0, "holds": [ "...Hold..." ] }
```

### GET /checkout-locks ★

**Scope:** `read:customers`. The Checkout Locks ledger: every identity a control refused at checkout, with the evidence of the latest refusal.

| Parameter | Notes |
|---|---|
| `kind` | `blocklist` `velocity` `idv` `linked` |
| `email` | Normalized before matching |
| `user_id` | Account id |
| `resolved` | `open` (default: the live queue), `resolved`, or `all` |
| `limit`, `offset` | 1–100, default 50 |

```json
{ "total": 12, "limit": 50, "offset": 0, "locks": [ "...Checkout Locks row..." ] }
```

Repeat attempts bump `attempts` on one row rather than adding rows; a retry that brings evidence replaces what is stored, one that brings none leaves it. **`GET /checkout-locks/{id}`** returns one row; **`DELETE /checkout-locks/{id}`** (`write:customers`) dismisses it — the queue entry is resolved, the underlying control still applies, and the evidence stays on the customer's `refusals`. Both answer `404 fs_api_not_found` for an unknown id.

### GET /orders

**Scope:** `read:orders`.

| Parameter | Notes |
|---|---|
| `level` | `none` `low` `medium` `high` `block` |
| `email` | Normalized before matching |
| `chargeback` | `1` or `0` |
| `user_id` ★ | Account id |
| `ip` ★ | Exact IP |
| `address_id` ★ | 64-hex exact address hash |
| `phone` ★ | Normalized before matching |
| `status` ★ | WooCommerce status slug as indexed (`processing`, `completed`, `cancelled`, `charged-back`…) |
| `gateway` ★ | The indexed payment method (title when the gateway had one) |
| `country`, `state`, `city`, `postcode` ★ | Ship-to geography, normalized before matching |
| `min_score` ★ | Risk score at or above |
| `linked_cancelled` ★ | `1` — only orders the linked rule cancelled |
| `from`, `to` | `2026-08-01`, `2026-08-01 13:45:00` (store's timezone), or a zoned ISO instant like `2026-08-01T00:00:00-04:00` — the `date` this endpoint returns can be fed straight back in |
| `limit` | 1–100, default 50 |
| `offset` | default 0 |

**Response — `200` (shape)**

```json
{ "total": 7, "limit": 2, "offset": 0, "orders": [ "...Order row..." ] }
```

An empty result is a normal `200` with `"orders": []`. `address_id` is the store's internal exact-address hash; two orders sharing an address share this value. Every filter lands on an indexed column.

### GET /orders/{id}

**Scope:** `read:orders`. One *Order row* plus, ★ 1.30, everything the order screen's Fraud Shield box knows:

```json
{
  "...Order row...": "...",
  "status": "cancelled",
  "linked_cancel": { "...Auto-cancel record..." },
  "card_check": { "...Card check (order-detail form)..." },
  "id_verification": { "session_id": "f47ac10b58cc4372a5670e02b2c3d479" },
  "blocklist": [ "...Blocklist evidence items..." ]
}
```

| Key | Meaning |
|---|---|
| `status` | Read live from the order when it still exists (the index may lag a status change by a moment) |
| `linked_cancel` | The *Auto-cancel record* if the linked rule cancelled this order, else `null` |
| `card_check` | The stored cardholder verdict, else `null` |
| `id_verification` | The Didit session the order was placed under (stamped for chargeback evidence), else `null` |
| `blocklist` | Which of the order's credentials are on the blocklist **right now**, each with since when, by whom, the owning card and the identities behind it — the 1.27 provenance box. Empty when none |

Not indexed: `404 { "code": "fs_api_not_found", "message": "That order is not in the index." }`.

### POST and DELETE /orders/{id}/chargeback ★

**Scope:** `write:orders`. Mark an order as charged back, or clear the manual mark. The same path as the order screen's button: an order note, a re-index, and an `order.chargeback` event (one per flip). `POST` after the order already counts as charged back is harmless and emits nothing.

```json
POST   → { "ok": true, "order_id": 51802, "chargeback": true }
DELETE → { "ok": true, "order_id": 51802, "chargeback": false }
```

`chargeback` is whether the order **now** counts as charged back — after a `DELETE` it may still be `true` through the *Charged back* order status or a counted refund. After marking, run *Recompute scores* in wp-admin (or wait for the next scan) to propagate the chargeback-contagion signal to linked orders. `404 fs_api_not_found` when the WooCommerce order does not exist.

### POST and DELETE /orders/{id}/recovery ★

**Scope:** `write:orders`. Record the money won back on a charged-back order (a dispute won, a partial reversal), or clear it. Capped at the order total; the Financial Protection report nets it against the chargeback. Emits `order.recovery`.

```json
POST { "amount": 300 } → 201 { "ok": true, "order_id": 51802, "recovered": 300, "total": 705.25 }
DELETE                 → 200 { "ok": true, "order_id": 51802, "recovered": 0 }
```

`amount` must be more than zero (`400 fs_api_bad_value`); an amount above the total is stored as the total.

### POST /orders/{id}/card-check ★

**Scope:** `write:orders`. Run the NMI cardholder check on an order now — the order screen's "Run card check". Synchronous: the issuer's verdict is in the response, and `card.checked` is emitted as usual. Works on any past NMI order with a transaction id, ignoring the gateway scope setting (a human asked).

```json
{ "ok": true, "result": "ok", "order_id": 51802, "card_check": { "...Card check (order-detail form)..." } }
```

`result` ∈ `ok` (checked), `done` (already had a final result), `no_txn` (no gateway transaction id), `notfound` (not on any configured NMI account), `network` (NMI unreachable), `disabled`. `409 fs_api_card_check_unavailable` when the cardholder check is not configured.

### GET /card-checks ★

**Scope:** `read:orders`. The Card Checks page: the issuer's verdict on every checked payment, newest first. The log is capped (one row per order, a re-check replaces the row), so filtering and paging happen in memory.

| Parameter | Notes |
|---|---|
| `outcome` | `pass` `partial` `fail` `unavailable` `notfound` `error` |
| `cardholder` | `confirmed` `mismatch` |
| `email` | Normalized before matching |
| `limit`, `offset` | 1–100, default 50 |

```json
{ "total": 38, "limit": 50, "offset": 0, "checks": [ "...Card check..." ] }
```

`cardholder=mismatch` is the filter that matters: ID-verified customers paying with a card the issuer says is not theirs.

### GET /linked-cancels ★

**Scope:** `read:orders`. The Auto-Cancelled ledger: every order the linked-identity rule cancelled, with the shared credentials, the block cards they lead to and the identities behind them.

| Parameter | Notes |
|---|---|
| `search` | Email, name, summary text, or an order number (with or without `#`) |
| `limit`, `offset` | 1–100, default 50 |

```json
{
  "total": 4, "limit": 50, "offset": 0,
  "stats": { "total": 4, "last_30d": 2, "value": 2210.75, "retries": 3, "currency": "USD" },
  "cancels": [ "...Auto-cancel record..." ]
}
```

**`GET /linked-cancels/{id}`** returns one record; `404 fs_api_not_found` otherwise.

### GET /high-value, /high-value/reviews, /high-value/leaderboard and POST /orders/{id}/review (1.32)

The High-Value Review page over the API: every order at or above the store's review amount (*Settings → High-value order review*), what a reviewer decided about each, who is doing the reviewing, and a way to record a verdict from outside wp-admin. The queue is a view over the order index — nothing is written when an order arrives, so a change of amount applies at once and orders placed before 1.32 appear on the same footing as new ones. Only verdicts are stored, append-only: a second look at the same order is a second row, and the latest row is the order's current verdict.

Every window parameter takes `today` (default), `yesterday`, `week` (from the day WordPress' *Week Starts On* names), `7d`, `30d` or `90d`, in the store's own time.

**`GET /high-value`** — **Scope:** `read:orders`.

| Parameter | Notes |
|---|---|
| `window` | Which orders, by the date they were placed |
| `show` | `unreviewed`, `reviewed`, or `all` (default) |
| `include_dead` | `1` to include unreviewed orders that are cancelled, failed, refunded or charged back — hidden by default because nothing will ship. Reviewed orders always show, whatever their status |
| `search` | Name, email, phone, IP, address text, or an order number (with or without `#`) |
| `orderby`, `dir` | `date` (default), `total`, `risk`, `reviewed`; `desc` (default) or `asc` |
| `limit`, `offset` | 1–100, default 50 |

```json
{
  "total": 3, "limit": 50, "offset": 0, "amount": 500,
  "window": { "key": "today", "label": "Today", "from": "2026-10-15T00:00:00+00:00", "to": "2026-10-16T00:00:00+00:00" },
  "stats": { "total": 3, "unreviewed": 1, "reviewed": 2, "cleared": 1, "held": 1, "cancelled": 0, "value": 3350.5, "unreviewed_value": 1200, "backlog": 2, "oldest_unreviewed": "2026-10-05T12:00:00+00:00", "currency": "USD" },
  "orders": [
    { "...Order...": "every field of GET /orders",
      "review": { "id": 14, "verdict": "held", "note": "Ships to a reshipper", "reviewer_id": 9, "reviewer_name": "Bob Checker", "reviewed_at": "2026-10-15T14:02:11+00:00", "source": "admin", "count": 1 },
      "customer_history": { "orders": 4, "chargebacks": 1, "spend": 1900, "first_order": "2026-07-03T09:12:00+00:00" } }
  ]
}
```

`review` is `null` while the order awaits a look; `count` is how many verdicts it has had. `stats.backlog` is the number of live, unreviewed high-value orders placed *before* the window (within the previous 90 days) and `oldest_unreviewed` the oldest of them — the queue's drift. `409 fs_api_hv_disabled` when the review amount is 0.

**`GET /high-value/reviews`** — **Scope:** `read:orders`. The review log, newest first. `window` filters on the time of the verdict; `all_time=1` ignores it. Also `reviewer_id` (a WordPress user id; `0` is every verdict recorded over the API), `verdict`, `search` (customer, reviewer, note text or order number), `limit`, `offset`.

```json
{ "total": 6, "limit": 50, "offset": 0, "window": { "key": "week", "...": "..." }, "reviews": [ "...Review..." ] }
```

**Review** — returned here, under `review` on a queue row, as `high_value_review` on `GET /orders/{id}`, and as the `order.reviewed` payload:

```json
{ "id": 14, "order_id": 51920, "user_id": 0, "email_norm": "buyer@example.com", "name": "Jo Buyer", "total": 2500, "currency": "USD",
  "verdict": "cancelled", "note": "Stolen card pattern", "status_before": "processing", "status_after": "cancelled",
  "reviewer": { "id": 9, "name": "Bob Checker" }, "source": "admin", "reviewed_at": "2026-10-15T14:05:40+00:00" }
```

`verdict` ∈ `cleared` (looks good — the order is left as it is), `held` (something off — the order is set On-Hold so nothing ships), `cancelled` (cancel now — the order is cancelled; the payment is **not** refunded by this). `status_before` / `status_after` say what the verdict did; an order already held, completed, cancelled or refunded keeps its status and is only noted. `source` ∈ `admin` (the review page), `order` (the order screen's Fraud Shield box), `api`. `reviewer.id` is `0` for an API caller, whose `name` is `API: <key label>`.

**`GET /high-value/reviews/{id}`** returns one Review; `404 fs_api_not_found` otherwise.

**`GET /high-value/leaderboard`** — **Scope:** `read:orders`. Reviewers ranked by verdicts recorded in the `window` (or `all_time=1`). Every verdict counts one, whatever it was.

```json
{ "window": { "key": "week", "...": "..." }, "reviews": 11,
  "leaderboard": [ { "rank": 1, "reviewer_id": 9, "reviewer_name": "Bob Checker", "reviews": 7, "cleared": 4, "held": 2, "cancelled": 1, "value": 9120.5, "share": 0.6364, "last_at": "2026-10-15T14:05:40+00:00", "all_time": 131 } ] }
```

**`POST /orders/{id}/review`** — **Scope:** `write:orders`. Record a verdict — the review page's three buttons, through the same path: the same order note (naming the reviewer), the same status change, the same `order.reviewed` event. Any order can be reviewed, whatever its total.

```json
{ "verdict": "held", "note": "Carrier says the address is a forwarder" }
```

Returns `201` with `{ ok, order_id, verdict, status_before, status_after, changed, message, review }`. `400 fs_api_invalid_verdict` for anything but the three verdicts; `404 fs_api_not_found` for an unknown order.

### GET /blocklist

**Scope:** `read:blocklist` or `write:blocklist`.

| Parameter | Notes |
|---|---|
| `type` ★ | `email` `phone` `address` `ip` `user` `place` — only credentials of that type |
| `search` ★ | The Blocklist page's search: a value, an IP prefix, an account id, a reason, or a block card's name. Emails and phones are matched in normalized form as well as as typed. Results are flattened to credentials: a card that matched by its own name contributes all its entries, one that matched through a credential contributes only the matching ones, plus matching legacy ungrouped rows |
| `limit`, `offset` | 1–100, default 100 |

**Response — `200` (shape; `total`, `limit`, `offset` and the entries' `id`/`type`/`value`/`reason`/`created_at` as captured in 1.24)**

```json
{ "total": 2, "limit": 100, "offset": 0, "type": "", "search": "", "entries": [ "...Blocklist entry..." ] }
```

### POST /blocklist

**Scope:** `write:blocklist`.

| Field | Required | Notes |
|---|---|---|
| `type` | yes | `email` `phone` `address` `ip` `user` `place` |
| `value` | yes | Normalized before storage |
| `reason` | no | Defaults to a note naming the API key |
| `message` | no | Shown to the customer at checkout |
| `cascade` ★ | no | `true` with `type` `address`, `ip` or `email`: block the target **and every credential ever associated with it** in the order index — the admin's "Block this address / IP + all credentials" and "Block profile" buttons. An address cascade also blocks the loose and door-key variants of every address swept in; an email cascade blocks the identity's phones, IPs, addresses and account. One reversible card owns the lot |

`address` accepts a raw address string or a precomputed 64-char hash. `place` is a destination lock: `city:US|FL|miami`, `state:US|FL`, `zip:US|331`, `street:US|FL|miami|n main st` (the last segment is the street **name** as the store normalizes it — house number and unit stripped, abbreviations applied, directionals and street types kept), or ★ 1.31 `streetzip:US|33166|n main st`, `streetcounty:US|FL|miami dade|n main st`, `building:US|FL|8020|n main st` and `geo:25.83300|-80.32800|805` (latitude, longitude to five decimals, radius in metres from 50 to 160934). A street ban with an explicit boundary is one card holding one `street:` key per city name, one `streetzip:` key per ZIP and one `streetcounty:` key per county the road runs through; place it from wp-admin, where the boundary is pre-filled from the store's history (over the API each place key posted creates its own card, so a boundary posted key by key is several cards that match identically and release separately). A place lock never cascades.

Entries are owned by a **reversible block action**, exactly like one created in wp-admin, and `customer.blocked` is emitted.

```json
{ "type": "email", "value": "Carder.Test+alias@Gmail.com", "reason": "Card testing, 14 declines in 3 minutes" }
```

```json
{ "ok": true, "type": "email", "value": "cardertest@gmail.com", "action_id": 1, "cascade": false }
```

A cascade (shape):

```json
{ "type": "ip", "value": "198.51.100.66", "cascade": true, "reason": "Drop-address IP" }
```

```json
{ "ok": true, "type": "ip", "value": "198.51.100.66", "action_id": 6, "cascade": true, "added": 9 }
```

`added` is how many credentials the cascade placed on the list (the target included; already-listed ones are linked, not counted). A destination lock:

```json
{ "type": "place", "value": "zip:US|331", "reason": "Destination lock: repeated reship fraud" }
```

```json
{ "ok": true, "type": "place", "value": "zip:US|331", "action_id": 4, "cascade": false }
```

Invalid type — `400 { "code": "fs_api_bad_type", "message": "type must be one of: email, phone, address, ip, user, place." }`.

### DELETE /blocklist ★ (bulk)

**Scope:** `write:blocklist`. The Blocklist page's bulk tools, by body shape. One `blocklist.removed` event per call. Only the named credentials are removed; a block card left with nothing that existed only for an IP goes with it, while profile, address and destination cards keep their other credentials.

```json
{ "ids": [12, 13, 14] }                                      → { "ok": true, "removed": 3, "by_type": { "email": 1, "ip": 2 } }
{ "type": "ip", "values": ["198.51.100.66", "203.0.113.9"] } → { "ok": true, "removed": 1, "missing": 1, "by_type": { "ip": 1 } }
{ "type": "ip", "all": true }                                → { "ok": true, "removed": 212, "by_type": { "ip": 212 } }
{ "ungrouped": true }                                        → { "ok": true, "removed": 9, "by_type": {} }
```

`values` may also be one string with IPs separated by newlines, commas or spaces. `ungrouped: true` empties the legacy "Ungrouped" card — pre-1.6 credentials no block card owns. Anything else answers `400 fs_api_bad_value`.

### DELETE /blocklist/{id}

**Scope:** `write:blocklist`. `{id}` from `GET /blocklist`. Returns `{ "ok": true }` and emits `blocklist.removed` with `count: 1`.

### PATCH /blocklist/{id} and PATCH /blocklist/actions/{id} ★

**Scope:** `write:blocklist`. Set or clear the checkout message a refused customer reads. Precedence at checkout is **per-credential message → block card message → store default**, so a credential's message outranks its card's.

```json
PATCH /blocklist/7          { "message": "Please contact support quoting reference FS-7." } → { "ok": true, "id": 7, "message": "Please contact support quoting reference FS-7." }
PATCH /blocklist/actions/2  { "message": "" }                                                → { "ok": true, "id": 2, "message": "" }
```

`message` is required (an empty string clears it and falls back down the chain); `400 fs_api_bad_value` without it, `404 fs_api_not_found` for an unknown id. Messages are HTML-filtered the way WordPress filters post content. The velocity lock keeps its own message (a setting), and a street lock created without a message carries the store's default street-lock message.

### GET /blocklist/actions ★

**Scope:** `read:blocklist`. The block cards — one per block decision, each with the credentials it currently owns — the Blocklist page as an endpoint. `limit`, `offset` (cards per page, default 50).

```json
{ "total": 9, "limit": 50, "offset": 0, "ungrouped": 0, "actions": [ "...Block card with entries..." ] }
```

`ungrouped` is how many legacy (pre-1.6) credentials belong to no card; they appear in `GET /blocklist` with empty `cards`. **`GET /blocklist/actions/{id}`** returns one card with all its entries; `404 fs_api_not_found` otherwise.

### DELETE /blocklist/actions/{id} ★

**Scope:** `write:blocklist`. Reverse a block — the card's "Remove all (unblock)". Every credential the card owns leaves the list, except ones another standing card also owns. Pass `?release_shared=1` for the profile toggle's "release the person" semantics: the credentials go even where another card links them, unless they are the explicit target of that other card (a blocked address's own hash, a manually blocked value, an imported entry, a destination lock). Emits `customer.unblocked`.

```json
{ "ok": true, "id": 1, "released": 7 }
```

### POST /blocklist/actions/{id}/resync ★

**Scope:** `write:blocklist`. Re-run the card's cascade to sweep in credentials that appeared after the original block — the card's "Re-sync". For an address or IP card this re-reads the index; for a profile card, the identity's newer phones, IPs and addresses (door keys included).

```json
{ "ok": true, "id": 1, "added": 2 }
```

### GET /verified

**Scope:** `read:verified` or `write:verified`. Verified credentials bypass the velocity lock, the new-account hold and gateway restrictions — but **never** the blocklist.

**Response — `200`, captured verbatim**

```json
{
  "total": 1,
  "limit": 5,
  "offset": 0,
  "credentials": [
    { "type": "email", "value": "cole.smith@example.com", "label": "Long-standing customer, verified by support", "source": "", "added_at": "2026-08-19T15:17:55-04:00" }
  ]
}
```

`source` is `""` for a staff decision and `"idv"` when earned by a completed ID check.

### POST /verified

**Scope:** `write:verified`. Either flag an account or verify a single credential. Both answer `201`.

**Account** — `{ "user_id": 5109 }` → `{ "ok": true, "user_id": 5109, "scope": "account" }`. ★ 1.30: this now does exactly what the admin Verify button does, through one shared path: sets the Verified flag (exempt from *every* layer, blocklist included), mirrors the email onto the verified-credentials list, resolves the account's open Checkout Locks rows, and lifts any verification hold — advancing the held order to processing if it was paid. Emits `customer.verified` (scope `account` and scope `credential`) and `hold.released` where applicable. `404 fs_api_not_found` for an unknown account.

**Credential** — `{ "type": "email", "value": "trusted.buyer@example.com", "label": "Verified by support ticket 8812" }` → `{ "ok": true, "type": "email", "added": true }`. `type` is `email`, `address` or `ip`. `added` is `false` when the credential was already on the list.

### DELETE /verified

**Scope:** `write:verified`. Same body shape as `POST`; withdraws the verification. For an account, ★ 1.30 also removes the mirrored email credential, as the admin revoke does. Returns `{ "ok": true }`.

### GET, POST and DELETE /allowlist ★

**Scope:** `read:verified` for `GET`; `write:verified` for `POST` and `DELETE`. The email allowlist: the guest-friendly release from the velocity lock and the new-account hold. It **never** overrides the blocklist.

```json
GET                                            → { "total": 2, "limit": 100, "offset": 0, "emails": ["guest.buyer@example.com", "..."] }
POST   { "email": "Guest.Buyer@Example.com" }  → 201 { "ok": true, "email_norm": "guest.buyer@example.com", "added": true }
DELETE { "email": "guest.buyer@example.com" }  → 200 { "ok": true, "removed": true }
```

`POST` also resolves the email's open velocity Checkout Locks rows, as the Allow button does, and emits `customer.verified` with `scope: "allowlist"`; `DELETE` emits `customer.unverified` with the same scope.

### GET /idv/sessions

**Scope:** `read:idv` or `write:idv`. Parameters: `result` (`approved` `name_mismatch` `declined` `expired` `pending`), `search`, `limit`, `offset`.

**Response — `200` (captured verbatim in 1.24; `id`, `full_name`, `expected_name`, `doc_state`, `verify_country`, `verify_state` and `warnings` are ★ 1.30)**

```json
{
  "total": 2,
  "limit": 5,
  "offset": 0,
  "sessions": [
    {
      "id": 11,
      "session_id": "9b1deb4d3b7d4bad9bdd2b0d7b3dcb6d",
      "user_id": 5109,
      "email_norm": "dana.reeve@example.com",
      "status": "Approved",
      "result": "name_mismatch",
      "name_match": "no",
      "full_name": "DANIELLE R VOSS",
      "expected_name": "Dana Reeve",
      "doc_type": "Passport",
      "doc_country": "US",
      "doc_state": "NY",
      "verify_country": "RO",
      "verify_state": "",
      "vpn": true,
      "warnings": ["IP location does not match document country", "VPN or proxy detected"],
      "order_id": 51962,
      "created_at": "2026-08-19T15:17:55-04:00",
      "updated_at": "2026-08-19T15:17:55-04:00"
    }
  ]
}
```

`status` is the vendor's session status; `result` is Fraud Shield's verdict — a document can be `Approved` by the vendor and still land on `name_mismatch`. `id` is the row id `POST /idv/sessions/{id}/accept` takes. `full_name` is the name on the document, `expected_name` the name the customer typed at checkout; `doc_state` is where the document was issued, `verify_country`/`verify_state` where the customer physically was while verifying; `warnings` are the vendor's short risk descriptions (empty when none). Document images, document numbers and dates of birth never reach the store and are never returned.

### POST /idv/sessions/{id}/accept ★

**Scope:** `write:idv`. Accept a name mismatch: the document was genuine, the name differed, and a human has decided it is the same person — the "Accept & mark verified" button. Completes the verification as a staff decision (`name_match` becomes `manual`, `result` becomes `approved`); the customer gains the verified credential and every release it brings. Emits `customer.verified` with `origin: "idv"`.

```json
{ "ok": true, "id": 11 }
```

`404 fs_api_not_found` when `{id}` is not a `name_mismatch` session.

### GET /idv/requests ★

**Scope:** `read:idv`. Accounts currently blocked awaiting a demanded ID check — the "Awaiting a requested ID" queue. Only accounts that are *actually* still blocked are listed (one that has since verified, or that staff marked Verified, drops out). `limit`, `offset`.

```json
{
  "total": 1, "limit": 50, "offset": 0,
  "requests": [ { "user_id": 5109, "email_norm": "dana.reeve@example.com", "name": "Dana Reeve", "requested_at": "2026-08-18T10:00:00-04:00", "requested_by": 1, "attempts": 2 } ]
}
```

`attempts` is how many verification sessions the account has started.

### POST /idv/require

**Scope:** `write:idv`. Demands an ID check before the account may check out again. The customer sees the request at checkout with a button that clears it. Emits `idv.requested`.

```json
{ "user_id": 5109 }   →   { "ok": true, "user_id": 5109 }
```

`email` is accepted instead, provided it belongs to a registered account. A guest cannot carry an account-scoped demand (`400 fs_api_bad_value`). `409 fs_api_idv_unavailable` when ID verification is not configured on the store.

### DELETE /idv/require ★

**Scope:** `write:idv`. Withdraw a demanded ID check — "Cancel request". Also closes the Checkout Locks rows the demand opened. Emits `idv.request_cleared`.

```json
{ "user_id": 5109 }   →   { "ok": true, "user_id": 5109, "cleared": true }
```

`cleared` is `false` when no demand was open. `email` of a registered account is accepted instead of `user_id`.

### DELETE /idv/verified ★

**Scope:** `write:idv`. Revoke an ID verification — the admin "Revoke" action. Withdraws only the credential the check itself earned; a credential staff added by hand survives. Emits `customer.unverified` when a credential was withdrawn.

```json
{ "user_id": 5109 }   →   { "ok": true, "user_id": 5109, "email_norm": "dana.reeve@example.com", "revoked": true }
```

Provide `user_id`, `email`, or both (a guest verification lives on the email). `revoked` is whether a verification was in force.

### GET /insights/prevented ★

**Scope:** `read:insights`. The Dashboard's "Fraud prevented" panel: the money that did not change hands because a control stopped the checkout. Verdicts (blocklist, linked rule, block-level score) are the headline; money merely held back pending verification is shown beside it and never added in. Each cancelled order counts once; a customer retrying a refused checkout counts once per control per day with the largest cart seen.

| Parameter | Notes |
|---|---|
| `days` | Window, 0 = all time (default) |
| `months` | Months in the series, default 12 (1–36) |
| `limit` | Recent rows, default 10 |

**Response — `200` (shape)**

```json
{
  "period": { "days": 30, "label": "last_30_days" },
  "currency": "USD",
  "totals": { "fraud_amount": 4120.5, "verify_amount": 915, "fraud_stopped": 6, "cancelled": 2, "refused": 7, "attempts": 14, "staff_stopped": 1, "staff_amount": 571.93 },
  "by_kind": [
    { "kind": "blocklist",  "label": "Blocklist",                      "group": "fraud",  "amount": 2415.25, "stopped": 4, "attempts": 9 },
    { "kind": "linked",     "label": "Linked to the blocklist",        "group": "fraud",  "amount": 1705.25, "stopped": 2, "attempts": 3 },
    { "kind": "score",      "label": "Risk score at BLOCK (Enforce)",  "group": "fraud",  "amount": 0,       "stopped": 0, "attempts": 0 },
    { "kind": "velocity",   "label": "Velocity lock",                  "group": "verify", "amount": 415,     "stopped": 1, "attempts": 1 },
    { "kind": "hold",       "label": "New-account hold",               "group": "verify", "amount": 500,     "stopped": 1, "attempts": 1 },
    { "kind": "idv",        "label": "ID verification requested",      "group": "verify", "amount": 0,       "stopped": 0, "attempts": 0 },
    { "kind": "high_value", "label": "High-value ID rule",             "group": "verify", "amount": 0,       "stopped": 0, "attempts": 0 }
  ],
  "monthly": [ { "month": "2025-11", "fraud": 0, "verify": 0, "stopped": 0 }, "...", { "month": "2026-10", "fraud": 4120.5, "verify": 915, "stopped": 9 } ],
  "recent": [
    { "kind": "linked", "group": "fraud", "stage": "cancelled", "order_id": 51802, "user_id": 0, "email_norm": "jokafordeals@gmail.com", "name": "J Okafor",
      "amount": 705.25, "currency": "USD", "attempts": 2, "stopped_by": "rule",
      "linked_to": [ { "email_norm": "m.trinis@example.com", "user_id": 0, "name": "Michael Trinis" } ],
      "first_at": "2026-08-19T15:17:55-04:00", "last_at": "2026-08-19T16:00:00-04:00" }
  ]
}
```

`by_kind` always lists all seven controls. `stage` is `cancelled` (an order existed and was cancelled) or `refused` (no order was created). Payment-method refusals are not recorded at all — the customer pays another way.

`stopped_by` is `rule` when a control stopped the checkout and `staff` when a person cancelled or refunded an order that was on the blocklist or linked to it (counted in `fraud_amount`; `staff_stopped` / `staff_amount` say how much of the headline that is). `linked_to` names the blocked identities the stop was tied to — the person behind the shared address, phone or account, or behind the matched blocklist entry — and is empty for stops with nobody behind them (a destination lock, a block-level score, a verification hold).

### GET /insights/financial ★

**Scope:** `read:insights`. The whole Financial Protection report: four figures kept apart on purpose, each labelled observed / estimate / forecast on the page, with the assumptions and calibration behind them. Anything the store has not recorded is `null`, never `0`.

| Parameter | Notes |
|---|---|
| `days` | Window for the observed figures, 0 = all time (default). The projection is always all-time |
| `limit` | Rows in each `by_customer` table, default 25 |

**Response — `200` (shape, abridged)**

```json
{
  "period": { "days": 0, "from": null, "label": "all_time" },
  "currency": "USD",
  "assumptions": { "chargeback_fee": 15, "loss_ratio": 1.0, "min_window_days": 90 },
  "coverage": { "orders": 4120, "first_order": "2024-06-01 09:12:00", "last_order": "2026-10-02 14:03:11", "chargebacks": 23 },
  "losses": {
    "chargebacks": 23, "identities": 17, "gross": 18240.5, "recovered": 1200, "recovered_orders": 2,
    "fees": 345, "net": 17385.5, "net_includes_fees": true,
    "paid_orders": 4001, "paid_amount": 812400.25, "rate_by_orders": 0.0057, "rate_by_amount": 0.022,
    "refunded_counted_as_chargebacks": 0, "counts_refunds": false,
    "by_customer": [ { "email_norm": "...", "name": "...", "user_id": 0, "chargebacks": 2, "gross": 10000, "recovered": 0, "first": "...", "last": "..." } ]
  },
  "blocked_value": {
    "value": 9140.75, "events": 11, "attempts": 19, "identities": 8, "cancelled": 3, "refused": 8,
    "later_approved": 1, "later_approved_amount": 240,
    "stopped_by_staff": 1, "stopped_by_staff_amount": 571.93,
    "by_kind": { "blocklist": { "amount": 6000, "events": 7 }, "linked": { "amount": 3140.75, "events": 4 } },
    "held_pending_verification": { "amount": 915, "events": 2 },
    "by_customer": [ { "email_norm": "...", "name": "...", "user_id": 0, "value": 2410, "events": 3, "attempts": 5, "cancelled": 1, "refused": 2, "later_approved": 0, "later_approved_amount": 0, "stopped_by_staff": 0, "linked_to": [ { "email_norm": "...", "user_id": 0, "name": "..." } ], "kinds": ["blocklist"], "last_at": "..." } ]
  },
  "estimate": {
    "blocked_value": 9140.75, "conservative": 2410, "expected": 3870.2, "fees_expected": 61.5,
    "uncalibrated": 0, "covered_value": 2410, "confidence": "medium",
    "prior": { "class": "blocked", "rate": 0.41, "settled": 61, "chargebacks": 25 },
    "calibration": { "blocked": { "settled": 61, "chargebacks": 25, "rate": 0.41 }, "risky": { "...": "..." }, "store": { "...": "..." } },
    "loss_ratio": 1.0, "shrink_orders": 3,
    "by_customer": [ { "email_norm": "...", "name": "...", "user_id": 0, "kinds": ["linked"], "value": 571.93, "events": 1, "attempts": 1, "stopped_by_staff": 0,
                       "own_settled": 0, "own_chargebacks": 0,
                       "linked_to": [ { "email_norm": "m.trinis@example.com", "user_id": 0, "name": "Michael Trinis" } ], "tied_settled": 2, "tied_chargebacks": 1,
                       "basis_settled": 2, "basis_chargebacks": 1, "own_rate": 0.5, "probability": 0.229, "expected": 130.73, "conservative": 285.97, "last_at": "..." } ]
  },
  "projection": {
    "annual": 40000, "fees_annual": 120, "trailing_12m": 10000,
    "by_confidence": { "high": 0, "medium": 40000, "low": 0 },
    "identities": 1, "blocked_without_chargebacks": 6, "blocked_named": 7, "min_months": 3, "loss_ratio": 1.0,
    "by_customer": [ { "email_norm": "...", "name": "...", "user_id": 0, "orders": 2, "chargebacks": 2, "gross": 10000, "recovered": 0, "net": 10000, "first_order": "...", "last_order": "...", "span_months": 3.0, "window_months": 3.0, "factor": 4.0, "annual": 40000, "fees_annual": 120, "confidence": "medium" } ]
  }
}
```

| Section | What it is |
|---|---|
| `losses` (observed) | Recorded chargebacks at the reversed amount, net of recorded recoveries, plus the processor fee once configured (`fees` and `net_includes_fees` tell you whether it is in). `rate_by_orders` / `rate_by_amount` are against *settled* orders (paid or charged back, each counted once) |
| `blocked_value` (observed) | The value of the purchase attempts a **verdict** stopped — explicitly not money saved. Retries count once per control per day, cancelled orders staff later approved are excluded and reported under `later_approved`, verification holds are set aside under `held_pending_verification`. Orders staff cancelled or refunded while on the blocklist or linked to it are included and reported under `stopped_by_staff`. Each customer row names whom their stops were tied to under `linked_to` |
| `estimate` (estimate) | That value weighted by the chargeback rate of the history **behind** each stop — the stopped customer's own settled orders (`own_*`) plus, when the stop was tied to a blocked identity, that identity's (`tied_*`; `linked_to` names them; `basis_*` is the sum the probability rests on) — blended with the closest class the store has outcomes for (`prior.class`: `blocked` → `risky` → `store`) as if the class rate had been seen on `shrink_orders` extra orders. `conservative` counts only stops with a recorded chargeback behind them, at that history's own rate; `expected` counts everyone. `uncalibrated` is money nothing could be calibrated against. `confidence` ∈ `none` `low` `medium` `high` |
| `projection` (forecast) | What the customers now on the blocklist were costing per year while active: net loss × 12 ÷ months active, floored at `min_months`. A customer who charged back $10,000 across two orders three months apart projects to $40,000. `confidence` per row: `high` (12+ months, 3+ chargebacks), `medium`, `low` |

### GET /network ★

**Scope:** `read:insights`. The Customer Network list: every identity ranked by risk — blocked first, then recorded chargebacks, then the highest score reached.

| Parameter | Notes |
|---|---|
| `search` | Free text across email, name, phone, IP and address |
| `orderby` | `risk` (default) `chargebacks` `spend` `orders` `last` `name` |
| `dir` | `asc` / `desc` (default) |
| `limit`, `offset` | 1–100, default 50 |

```json
{ "total": 412, "limit": 50, "offset": 0, "customers": [ "...Customer row + blocked, chargeback_amount, distinct_phones..." ] }
```

### GET /network/{ident} ★

**Scope:** `read:insights`. One customer's network: their credentials on record, every connected customer with **what matches**, the **evidence** (orders on each side, with dates), its **recency**, that customer's **standing**, a **contribution to risk** that scales with the identifier, and a second hop to any blocked customer a connection leads to. The explore view. `404 fs_api_not_found` for an identity with no orders.

**Response — `200` (shape, abridged)**

```json
{
  "identity": { "email_norm": "dana.reeve@example.com", "user_id": 5109, "name": "Dana Reeve" },
  "history": { "...as on /customers/{ident}..." },
  "standing": {
    "blocked": false, "verified": false, "id_verified": true,
    "block_cards": [ { "entry_id": 3, "type": "address", "value": "<sha256>", "display": "1 Main St, New York NY 10012", "reason": "Drop house", "blocked_at": "...", "action_id": 2, "action_kind": "address", "action_title": "1 Main St, New York NY 10012" } ]
  },
  "credentials": [ { "via": "phone", "value": "2125550100", "label": "212 555 0100", "orders": 3, "first": "...", "last": "..." }, "..." ],
  "connections": [
    {
      "email_norm": "other@example.com", "name": "Other Person", "user_id": 0,
      "blocked": true, "verified": false,
      "orders": 4, "spend": 900, "chargebacks": 1, "chargeback_amount": 300, "max_risk_score": 95,
      "first_order": "...", "last_order": "...",
      "strongest": "strong",
      "connections": [
        { "via": "phone", "label": "Phone number", "strength": "strong", "value": "2125550100", "display": "212 555 0100",
          "mine_orders": 3, "mine_last": "...", "their_orders": 2, "their_first": "...", "their_last": "...", "their_order_ids": [51777, 51790] }
      ],
      "paths_to_blocked": [],
      "risk": { "score": 0.8, "level": "high", "reason": "Connected through a strong identifier to a blocked customer." }
    }
  ],
  "chargebacks": [ "...Order row..." ],
  "orders": [ "...Order row..." ],
  "orders_capped": false
}
```

`via` on a connection ∈ `account` `phone` `address` `door` `building` `street` `ip` `device`; `strength` ∈ `identity` (same account) `strong` (phone, same door) `medium` (same building or address line) `weak` (street, IP, browser — never grounds to block on its own, and `risk.reason` says so). `paths_to_blocked` lists, for an unblocked connection, the blocked customers it leads on to over a strong identifier: `{ blocked_email, blocked_name, via, label, strength, display, last }`. `orders` is the customer's most recent 25; `orders_capped` says whether the credential set was built from a capped history.

### GET /geography/{level} ★

**Scope:** `read:insights`. Fraud rolled up by ship-to geography — the Geography report — at `state`, `city`, `zip` or `street`, each row saying whether the place is currently locked.

| Parameter | Notes |
|---|---|
| `search` | Matches the place names |
| `months` | Only orders in the last N months (0 = all, default) |
| `min_orders` | Floor on orders per place, default 5 (an average over 2 orders is noise) |
| `orderby` | `avg_score` (default) `orders` `customers` `high` `chargebacks` `cb_rate` `last_order` `country` `state` `city` `zip` `street` |
| `order` | `desc` (default) / `asc` |
| `limit`, `offset` | 1–100, default 50 |

**Response — `200` (shape, `level=city`)**

```json
{
  "level": "city", "total": 38, "limit": 50, "offset": 0,
  "places": [
    {
      "place": { "country": "US", "state": "FL", "city": "doral" },
      "place_key": "city:US|FL|doral",
      "locked": true,
      "orders": 14, "customers": 9, "average_score": 61.4, "high_or_block": 6, "chargebacks": 3, "chargeback_rate": 21.4,
      "last_order": "2026-08-10T19:17:14-04:00"
    }
  ]
}
```

`place` carries `postcode` at `zip` level and `street` (plus `city`) at `street` level. `place_key` is exactly the `value` to send to `POST /blocklist` with `type: "place"` to lock the place; `locked` is whether that exact key is on the blocklist (a ZIP covered only by a shorter prefix lock reads `false`). `chargeback_rate` is a percentage.

### GET /geography/{level}/customers ★

**Scope:** `read:insights`. Everyone shipping to one place — the Geography page's drill-down. Identify the place with the values a `/geography` row carries under `place`: `country` and `state` always, plus `city` (city level), `postcode` (zip level), or `city` and `street` (street level). `400 fs_api_bad_value` when the place is incomplete.

| Parameter | Notes |
|---|---|
| `country`, `state`, `city`, `postcode`, `street` | The place (normalized before matching) |
| `search` | Free text across email, name, phone, IP and address |
| `orderby` | `last` (default) `first` `email` `name` `orders` `paid` `spend` `chargebacks` `ips` `addresses` `risk` |
| `dir`, `limit`, `offset` | As elsewhere |

```json
{
  "level": "city",
  "place": { "country": "US", "state": "FL", "city": "doral" },
  "total": 9, "limit": 50, "offset": 0,
  "customers": [ "...Customer row (without registered)..." ]
}
```

### GET /events

**Scope:** `read:events`. Forward-only cursor over the event log — the supported alternative to receiving webhooks.

| Parameter | Notes |
|---|---|
| `after` | Cursor. Start at `0`, then pass the previous `next_cursor` |
| `topic` | Optional single topic filter |
| `limit` | 1–100, default 50 |

```json
{
  "events": [
    {
      "id": "evt_1",
      "topic": "customer.blocked",
      "occurred_at": "2026-08-19T19:19:51+00:00",
      "store": { "store_id": "357a7b151fbabfa135907704094e267c", "url": "https://store.example.com/", "version": "1.24.0" },
      "data": { "action_id": 3, "kind": "manual", "target": "email:cardertest@gmail.com", "title": "Email: cardertest@gmail.com" }
    }
  ],
  "next_cursor": 3,
  "has_more": true
}
```

`next_cursor` is the numeric row id of the last event returned — an integer, **not** `"evt_3"`. Pass it as `after` on the next call; continue while `has_more` is true. Events are recorded whenever at least one key **or** one subscription exists, so polling works with no subscriptions at all.

### GET /webhooks

**Scope:** `manage:webhooks`. Returns every topic with its description plus the current subscriptions. **Secrets are never included.**

```json
{
  "topics": { "order.scored": "An order was risk-scored (every order).", "...": "...", "test.ping": "Test event, sent only on request." },
  "webhooks": [
    {
      "id": 1,
      "url": "https://bonesfraud.com/hooks/atomiklabz",
      "label": "bonesfraud.com (paired)",
      "topics": ["order.flagged", "checkout.blocked", "idv.approved"],
      "status": "active",
      "hash_pii": false,
      "failures": 0,
      "last_status": 0,
      "last_error": "",
      "last_delivered": null,
      "created_at": "2026-08-19T19:17:55+00:00"
    }
  ]
}
```

### POST /webhooks

**Scope:** `manage:webhooks`.

| Field | Required | Notes |
|---|---|---|
| `url` | yes | Must be HTTPS and must resolve outside private address space |
| `topics` | no | Array, or `"*"` for every topic except `test.ping` |
| `label` | no | Defaults to the API key's label |
| `hash_pii` | no | Send digests instead of identifiers |

```json
{ "ok": true, "id": 3, "secret": "fswh_04a601a1d23f8f96f4c2fec22e964dc052361e6efe6ef2f0", "topics": ["order.flagged", "checkout.blocked"], "notice": "Store this secret now. It cannot be retrieved again." }
```

Refusals (the SSRF guard): `400 fs_hook_insecure` ("Webhook URLs must use HTTPS."), `400 fs_hook_private` ("That URL resolves to a private or reserved address. Enable local delivery in the API settings if this is a development endpoint."), `400 fs_hook_unresolved`, `400 fs_hook_bad_url`, `400 fs_hook_no_topics`.

### DELETE /webhooks/{id}

**Scope:** `manage:webhooks`. Deletes the subscription and its delivery history. Returns `{ "ok": true }`.

### POST /webhooks/{id}/test

**Scope:** `manage:webhooks`. Delivers a `test.ping` synchronously: `{ "ok": true, "status": 200 }`, or on transport failure `{ "ok": false, "error": "cURL error 7: Failed to connect to ..." }`.

---

## Part 4 — Webhooks

### Topics

Twenty-four topics. Events are raised at the domain choke points, so a change made in wp-admin, at checkout, or over the API produces the same event.

| Topic | Fires when |
|---|---|
| `order.scored` | An order was risk-scored (every order) |
| `order.flagged` | An order scored `medium`, `high` or `block` (same payload as `order.scored`) |
| `order.cancelled` ★ | The linked-identity rule cancelled an order at creation |
| `order.chargeback` ★ | An order was marked charged back, or the mark was cleared — from the button, the API, the *Charged back* status or a counted refund |
| `order.recovery` ★ | A chargeback recovery was recorded or cleared |
| `order.reviewed` (1.32) | A reviewer recorded a high-value verdict — looks good, held, or cancelled — from the review page, the order screen or the API |
| `checkout.blocked` | A checkout attempt was refused or locked |
| `customer.verified` | A customer, credential or allowlisted email was verified |
| `customer.unverified` | A verification was withdrawn |
| `customer.blocked` | A block card was created (a customer, address, IP or destination blocked) |
| `customer.unblocked` ★ | A block card was reversed and its credentials released |
| `blocklist.extended` ★ | An existing block card grew: a re-sync, or a matching new order had its fresh credentials linked in (auto-extend) |
| `blocklist.removed` ★ | One or more credentials were removed from the list |
| `hold.applied` ★ | A new account was placed under a verification hold |
| `hold.released` ★ | A verification hold was lifted |
| `idv.requested` ★ | An ID check was demanded from an account |
| `idv.request_cleared` ★ | A demanded ID check was satisfied or withdrawn |
| `idv.approved` | An ID verification was approved |
| `idv.declined` | An ID verification was declined, expired or abandoned |
| `idv.name_mismatch` | An ID was genuine but the name did not match |
| `card.checked` | A cardholder check returned an issuer verdict |
| `restriction.applied` | A customer was put on restricted checkout |
| `restriction.released` | A restricted checkout was lifted |
| `test.ping` | Test event, sent only on request; never included in `"*"` |

Subscribe to `order.scored` **or** `order.flagged`, not both, unless you want two deliveries per flagged order. A platform mirroring the blocklist wants `customer.blocked`, `customer.unblocked`, `blocklist.extended` and `blocklist.removed` together (a block's credentials change through all four); one mirroring chargeback outcomes wants `order.chargeback` and `order.recovery`.

### Delivery format

`POST` with `Content-Type: application/json; charset=utf-8`. Real headers from a live delivery (captured verbatim):

```
POST /hooks/fraud-shield HTTP/1.1
user-agent: FraudShield/1.24.0; https://store.example.com/
content-type: application/json; charset=utf-8
accept: application/json
x-fs-topic: order.flagged
x-fs-event: evt_5
x-fs-delivery: 2
x-fs-attempt: 1
x-fs-store: 357a7b151fbabfa135907704094e267c
x-fs-signature: t=1787167075,v1=23b57d211d2c58f161a7a5a48f3377a0879b82e5bf9d0511a50f8dea41de64a3
```

| Header | Meaning |
|---|---|
| `X-FS-Signature` | `t=<unix>,v1=<hex>` — see below |
| `X-FS-Topic` | The topic |
| `X-FS-Event` | `evt_<id>` — **stable across retries; use it for idempotency** |
| `X-FS-Delivery` | Delivery attempt row id (differs per subscription) |
| `X-FS-Attempt` | 1, 2, 3… |
| `X-FS-Store` | The store id |

Every body has the same envelope:

```json
{
  "id": "evt_5",
  "topic": "order.flagged",
  "occurred_at": "2026-08-19T19:17:55+00:00",
  "store": { "store_id": "357a7b151fbabfa135907704094e267c", "url": "https://store.example.com/", "version": "1.30.0" },
  "data": { "...topic-specific..." },
  "delivery": { "id": 2, "attempt": 1 }
}
```

Answer `2xx` as soon as you have stored the event; process afterwards. The store's timeout is 10 seconds by default. Redirects are not followed.

### Verifying a delivery

Compute `HMAC-SHA256("<t>.<raw body>", secret)` and compare against `v1` in constant time. Reject anything whose `t` is more than a few minutes old. Use the **raw** body bytes — re-serialising parsed JSON changes the signature. Each subscription has its own secret.

**PHP**

```php
$secret = 'fswh_04a601a1d23f8f96f4c2fec22e964dc052361e6efe6ef2f0';
$raw    = file_get_contents( 'php://input' );
$header = $_SERVER['HTTP_X_FS_SIGNATURE'] ?? '';

$parts = array();
foreach ( explode( ',', $header ) as $piece ) {
    $bits = explode( '=', trim( $piece ), 2 );
    if ( 2 === count( $bits ) ) {
        $parts[ $bits[0] ] = $bits[1];
    }
}

$timestamp = (int) ( $parts['t'] ?? 0 );
$given     = (string) ( $parts['v1'] ?? '' );
$expected  = hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );

if ( abs( time() - $timestamp ) > 300 ) {
    http_response_code( 400 );  // stale
    exit;
}
if ( ! hash_equals( $expected, $given ) ) {
    http_response_code( 401 );  // forged
    exit;
}

http_response_code( 200 );
// ...queue the event and return; process it after responding.
```

**Node**

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

export function verifyDelivery(rawBody, signatureHeader, secret) {
  const parts = Object.fromEntries(
    signatureHeader.split(',').map((p) => p.trim().split('='))
  );
  const t = Number(parts.t);
  if (!Number.isFinite(t) || Math.abs(Date.now() / 1000 - t) > 300) return false;

  const expected = createHmac('sha256', secret).update(`${t}.${rawBody}`).digest();
  const given = Buffer.from(parts.v1 ?? '', 'hex');
  return expected.length === given.length && timingSafeEqual(expected, given);
}
```

**Python (Flask)**

```python
import hashlib, hmac, time
from flask import Flask, request

app = Flask(__name__)
SECRET = b"fswh_04a601a1d23f8f96f4c2fec22e964dc052361e6efe6ef2f0"

@app.post("/hooks/fraud-shield")
def hook():
    raw = request.get_data()  # raw bytes, not request.json
    parts = dict(p.strip().split("=", 1) for p in request.headers.get("X-FS-Signature", "").split(","))
    t = int(parts.get("t", 0))

    if abs(time.time() - t) > 300:
        return "", 400

    expected = hmac.new(SECRET, f"{t}.".encode() + raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, parts.get("v1", "")):
        return "", 401

    return "", 200
```

### Payload for every topic

The `data` object for each topic. The envelope around each is as shown above. The 1.24 topics are captured verbatim; the ★ 1.30 topics are shapes.

#### `order.scored` — every order, as soon as it is scored

```json
{
  "order_id": 51901,
  "user_id": 5109,
  "email_norm": "dana.reeve@example.com",
  "name": "Dana Reeve",
  "total": 415,
  "currency": "USD",
  "gateway": "bacs",
  "status": "processing",
  "ip": "198.51.100.201",
  "risk": { "score": 15, "level": "low", "reasons": ["Customer first seen 4 days before this order"] }
}
```

#### `order.flagged` — same payload, only for `medium` / `high` / `block`

```json
{
  "order_id": 51802,
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "total": 705.25,
  "currency": "USD",
  "gateway": "stripe_cc",
  "status": "processing",
  "ip": "198.51.100.66",
  "risk": { "score": 62, "level": "high", "reasons": ["3 identities at this shipping address", "Address linked to a prior chargeback"] }
}
```

#### `order.cancelled` ★ — the linked rule cancelled an order at creation

```json
{
  "order_id": 51802,
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "total": 705.25,
  "currency": "USD",
  "gateway": "stripe_cc",
  "status": "cancelled",
  "reason": "linked_blocklist",
  "shares": ["address", "phone"],
  "links": [
    { "via": "address", "degree": 1, "on_order": true, "block_card": "Email: fraud.ring@example.com" },
    { "via": "phone", "degree": 2, "on_order": true, "block_card": "R Castillo (fraud.ring@example.com)" }
  ]
}
```

The order-level counterpart of `checkout.blocked` (`kind: linked`): which order, how much, and the *shape* of the links — the credential types, degrees and the block cards they lead to. Deliberately **not** the shared values or the blocked party's identities; those stay on the store (`GET /linked-cancels/{id}`). `block_card` is redacted on hashed subscriptions. Fires once per order; the customer's later refused retries bump `attempts` on the record and raise `checkout.blocked`, not another `order.cancelled`.

#### `order.chargeback` ★ — the charged-back state flipped

```json
{
  "order_id": 51802,
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "total": 705.25,
  "currency": "USD",
  "gateway": "stripe_cc",
  "status": "completed",
  "charged_back": true,
  "recovered": 0,
  "source": "api"
}
```

`charged_back` is the new state. `source` ∈ `manual` (the order screen's button), `api` (`POST`/`DELETE /orders/{id}/chargeback`), `status` (the *Charged back* order status was set or left), `refund` (a refund on a store that counts refunds as chargebacks). Exactly one event per flip, whatever caused it; clearing a mark on an order that still counts as charged back through its status emits nothing.

#### `order.recovery` ★ — a recovery was recorded or cleared

```json
{ "order_id": 51802, "user_id": 0, "email_norm": "jokafordeals@gmail.com", "total": 705.25, "currency": "USD", "recovered": 300, "cleared": false, "source": "api" }
```

`recovered` is the amount now on record (`0` with `cleared: true` when cleared). `source` ∈ `manual` / `api`.

#### `order.reviewed` (1.32) — a high-value verdict was recorded

```json
{ "order_id": 51920, "user_id": 0, "email_norm": "buyer@example.com", "name": "Jo Buyer", "total": 2500, "currency": "USD", "verdict": "held", "note": "Carrier says the address is a forwarder", "status_before": "processing", "status_after": "on-hold", "reviewer": { "id": 9, "name": "Bob Checker" }, "source": "admin", "review_id": 14, "reviewed_at": "2026-10-15 14:02:11" }
```

The same fields as a Review (see `GET /high-value/reviews`). `verdict` ∈ `cleared` / `held` / `cancelled`; `status_before` and `status_after` say what it did to the order (equal when the order was already in a state the verdict could not change). `reviewer.id` is `0` and `source` is `api` for a verdict recorded over the API. `reviewed_at` is the store's local time. The note is the reviewer's own text and is sent as written.

#### `checkout.blocked` — a refused or locked checkout attempt

```json
{ "kind": "velocity", "user_id": 5109, "email_norm": "dana.reeve@example.com", "name": "Dana Reeve", "detail": "3 orders in 30 days" }
```

`kind` is `blocklist`, `velocity`, `idv` or `linked`. For `linked`, `detail` names only the shared credential *types* (e.g. `shares ip, address with a blocked identity`), never the blocked party's identifiers. Coalesced to one event per identity and kind per 5 minutes, so a bot retrying a blocked address does not become a flood. The per-credential evidence staff see on Checkout Locks is never sent off-site; it is readable, with the scope, at `GET /checkout-locks`.

#### `customer.verified`

```json
{ "scope": "credential", "type": "email", "email_norm": "second.trusted@example.com", "key": "email:second.trusted@example.com", "origin": "" }
```

`scope` is `credential`, `account`, or ★ `allowlist`. For `account`, `data` carries `user_id` and `email_norm` instead of `type`/`key`. For `allowlist`, `type` is `email` and `key` is `allow:<email>`. `origin` is `""` for a staff or API decision, `"idv"` when earned by an ID check (including an accepted name mismatch).

#### `customer.unverified`

```json
{ "scope": "credential", "type": "email", "email_norm": "second.trusted@example.com", "key": "email:second.trusted@example.com" }
```

Same `scope` values as `customer.verified`.

#### `customer.blocked` — one event per merchant decision, not per cascaded credential

```json
{ "action_id": 5, "kind": "manual", "target": "email:live.run.block@example.com", "title": "Email: live.run.block@example.com" }
```

`kind` is `manual`, `profile`, `address`, `ip`, `place` or `import`.

#### `customer.unblocked` ★ — a block card was reversed

```json
{ "action_id": 5, "kind": "manual", "target": "email:live.run.block@example.com", "title": "Email: live.run.block@example.com", "entries_removed": 7, "release_shared": false }
```

The counterpart of `customer.blocked`, whichever screen or API call reversed it. `entries_removed` is how many credentials left the list; `release_shared` is whether the reversal used the "release the person" semantics. A credential the card owned but another card still holds stays blocked and is not counted.

#### `blocklist.extended` ★ — an existing block card grew

```json
{ "action_id": 6, "kind": "ip", "target": "198.51.100.66", "title": "198.51.100.66", "added": 3, "source": "auto_extend", "order_id": 51977, "types": ["email", "phone", "address"] }
```

`customer.blocked` fires once, when a card is created; this is how the card's credentials grow afterwards. `source` is `auto_extend` — a new order matched the card and, with *Auto-extend blocks* on, its own fresh email, phone, IP, account and address were linked in (`order_id` names the order, `types` the credential types that were new) — or `resync` — staff or `POST /blocklist/actions/{id}/resync` re-ran the card's cascade (`order_id` is `0`, `types` empty). `added` counts credentials newly placed on the list; a card that re-synced and found nothing new emits nothing. Destination locks never extend.

#### `blocklist.removed` ★ — credentials were removed

```json
{
  "count": 212,
  "by_type": { "ip": 210, "email": 2 },
  "sample": [ { "type": "ip", "credential": "198.51.100.66" }, { "type": "ip", "credential": "203.0.113.9" } ]
}
```

One event per removal call, not per credential — a 500-IP bulk removal is one decision. `sample` carries up to 25 of the removed credentials; `credential` is redacted on hashed subscriptions. Raised by `DELETE /blocklist/{id}`, `DELETE /blocklist` (including `ungrouped: true`), and the Blocklist page's Remove buttons and bulk tools. A card reversal raises `customer.unblocked` instead.

#### `hold.applied` ★ / `hold.released` ★

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com", "order_id": 51901, "amount": 415, "age_days": 4 }
```

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com" }
```

A new-account verification hold was placed (the triggering order, its amount, the account's age in days) or lifted — by staff verifying the account, a restriction being applied, `DELETE /customers/{ident}/hold`, or `POST /verified`. Observed on the account flag itself, so every path raises it.

#### `idv.requested` ★ / `idv.request_cleared` ★

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com", "requested_by": 1 }
```

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com" }
```

An ID check was demanded from an account (`requested_by` is the WordPress user id of the staff member, `0` over the API), or the demand ended — because the customer verified, staff cancelled it, a restriction was applied, or `DELETE /idv/require`.

#### `idv.approved`

```json
{
  "session_id": "f47ac10b58cc4372a5670e02b2c3d479",
  "user_id": 4182,
  "email_norm": "cole.smith@example.com",
  "result": "approved",
  "status": "Approved",
  "name_match": "yes",
  "full_name": "COLE ANDREW SMITH",
  "expected_name": "Cole Smith",
  "doc_type": "Driving Licence",
  "doc_country": "US",
  "vpn": false,
  "order_id": 50984
}
```

#### `idv.name_mismatch` — a genuine document in the wrong name

```json
{
  "session_id": "9b1deb4d3b7d4bad9bdd2b0d7b3dcb6d",
  "user_id": 5109,
  "email_norm": "dana.reeve@example.com",
  "result": "name_mismatch",
  "status": "Approved",
  "name_match": "no",
  "full_name": "DANIELLE R VOSS",
  "expected_name": "Dana Reeve",
  "doc_type": "Passport",
  "doc_country": "US",
  "vpn": true,
  "order_id": 51962
}
```

The highest-signal event in the set: the vendor's `status` is `Approved` — the document is real — but the name on it is not the customer's, and the session came through a VPN. That is the stolen-ID pattern. It is also what an innocent married name or transliteration looks like, so treat it as a review trigger, not a verdict — and `POST /idv/sessions/{id}/accept` is how a human closes it.

#### `idv.declined` — declined, expired or abandoned

```json
{
  "session_id": "c9bf9e57e2c34b1f9b0a5e1d8c7a6f42",
  "user_id": 0,
  "email_norm": "walk.in.guest@example.com",
  "result": "declined",
  "status": "Declined",
  "name_match": "",
  "full_name": "",
  "expected_name": "Robin Vance",
  "doc_type": "",
  "doc_country": "",
  "vpn": false,
  "order_id": 0
}
```

A declined session never produced a document name, so the document fields are blank. `order_id: 0` means the session was not tied to an order.

#### `card.checked` — the card issuer's verdict, after payment

```json
{
  "order_id": 51802,
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "gateway": "stripe_cc",
  "outcome": "fail",
  "cardholder": "mismatch",
  "id_verified": true,
  "avs": "N",
  "cvv": "M",
  "brand": "visa",
  "last4": "4242",
  "three_ds": ""
}
```

`cardholder: "mismatch"` with `id_verified: true` is the stolen-card-with-real-ID case. Arrives on cron after payment, never during checkout — or synchronously when `POST /orders/{id}/card-check` runs it.

#### `restriction.applied`

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com", "gateways": ["bacs", "cod"] }
```

`gateways` is the set the customer may still use.

#### `restriction.released`

```json
{ "user_id": 5109, "email_norm": "dana.reeve@example.com" }
```

#### `test.ping` — only when someone presses **Send test**

```json
{ "message": "Fraud Shield webhook test.", "sent_by": 1 }
```

Test deliveries carry `"id": "evt_0"` and `"delivery": { "id": 0, "attempt": 1 }`; they are not stored in the event log. `sent_by` is the WordPress user id of whoever pressed the button.

### Hashed identifiers

With **Send hashed identifiers** on a subscription, these fields are replaced by `<field>_sha256` — lower-cased then SHA-256, recursively at every depth:

`email`, `email_norm`, `name`, `phone`, `ip`, `expected_name`, `full_name`, `target`, `title`, `key`, `last4`, and ★ 1.30 `block_card`, `credential`

The last six are easy to overlook: a blocklist event's `target` and `title` embed whatever was blocked, a verified-credential event's `key` embeds the raw email or IP, `card.checked` carries the card's last four digits, an `order.cancelled` link's `block_card` names the person or address the card is about, and a `blocklist.removed` sample's `credential` *is* the identifier.

The same `order.flagged` event delivered hashed (captured verbatim):

```json
{
  "order_id": 51802,
  "user_id": 0,
  "email_norm_sha256": "a3c9e943ca95f5d9c7e947e75b1b311b4ad495969c1292be4582b19d429df0dd",
  "name_sha256": "5e07127dbf3093e7c0e41c68f2eeca0792ad1faea48aa34479c26f69641b27d9",
  "total": 705.25,
  "currency": "USD",
  "gateway": "stripe_cc",
  "status": "processing",
  "ip_sha256": "83e61ecb3b828367bb73e4da8dec1ad9382020caf853292b6ea0bb9a4e9edb72",
  "risk": { "score": 62, "level": "high", "reasons": ["3 identities at this shipping address", "Address linked to a prior chargeback"] }
}
```

The digest is stable, so a receiver can group events by customer without holding the identifier. It is **not** a secret: an unsalted SHA-256 of an email can be confirmed by anyone who already has a candidate address. Use it for correlation, not as an anonymisation guarantee.

### Retries and failure

Delivery happens on cron, never inside the request that produced the event, so a slow subscriber can never slow down a customer's checkout. The queue drains on a recurring five-minute schedule, plus an immediate single run about five seconds after an event is recorded (throttled to one nudge per 30 seconds), so the common case feels near-instant when WP-Cron is healthy.

- Up to **6 attempts** by default (configurable 1–12).
- Exponential backoff: 1, 2, 4, 8, 16 minutes, capped at 6 hours.
- A subscription that fails **20 times consecutively** is switched off and shown as failing in the admin. Re-enabling clears the strike count.
- A subscription whose hostname stops resolving publicly is switched off at delivery time rather than retried.
- Deliveries are **at-least-once**. Deduplicate on `X-FS-Event`.

Captured retry state after a subscriber answered `HTTP 500`:

```
delivery:      status=pending  attempts=1  response_code=500
               next_attempt_at=2026-08-19 19:20:51 (UTC, one minute later)
               error=Endpoint returned HTTP 500.
subscription:  failures=1  last_status=500  last_error=Endpoint returned HTTP 500.
```

An event is retained past the retention window while a delivery is still pending. Events and delivery history are otherwise pruned after 30 days by default.

### Polling instead of receiving

If you cannot expose an endpoint, poll `GET /events` with a key holding `read:events`:

1. Start with `?after=0&limit=100`.
2. Process `events[]`; each is the same envelope a webhook delivers.
3. Store `next_cursor` (an integer) and pass it as `after` on the next call.
4. Continue while `has_more` is true; otherwise sleep and poll again from the stored cursor.

Events are recorded whenever at least one key or one subscription exists. Collect within the retention window (30 days by default).

---

## Part 5 — Integration recipes and rules

### Recipes

**Pre-checkout risk check on your own platform.** `POST /risk/evaluate` with everything you know (email, phone, IP, name, address, amount, gateway). Branch on `decision`; surface `risk.reasons` to humans; use `state.blocklist_evidence` to say *which* credential is blocked and since when, and `state.blocklist_links[].identities` to explain *who* a linked block leads to. Pass `exclude_order_id` when re-scoring an order that is already indexed. Read `GET /settings` once per store to interpret scores.

**Mirror a store's risk feed.** Pair via `/handshake` with `topics: ["order.flagged", "order.cancelled", "checkout.blocked", "idv.name_mismatch", "card.checked", "order.chargeback"]`. Store the key and webhook secret against `store_id`. Verify every delivery; deduplicate on `X-FS-Event`; acknowledge with `2xx` fast and process asynchronously.

**Mirror a store's blocklist.** Subscribe to `customer.blocked`, `customer.unblocked`, `blocklist.extended` and `blocklist.removed`; seed from `GET /blocklist/actions` (cards) or `GET /blocklist` (flat credentials, paged). The card id (`action_id`) is the join key between the four topics; after a `blocklist.extended`, re-read `GET /blocklist/actions/{id}` for the card's new credentials.

**Mirror chargeback outcomes.** Subscribe to `order.chargeback` and `order.recovery`; backfill with `GET /orders?chargeback=1`. `recovered` on the order row is the net the Financial Protection report uses.

**Push your own intelligence into a store.** `POST /blocklist` (`write:blocklist`) with a `reason` — it becomes a reversible block card staff can see and undo; add `cascade: true` to block an address or IP with everyone who used it. `POST /verified` or `POST /allowlist` to whitelist a trusted customer. `POST /idv/require` to force an ID check on a registered account. `POST /orders/{id}/chargeback` when your processor tells you about a dispute before the store knows.

**Work the queues from your own console.** `GET /checkout-locks` (the refusals, with evidence), `GET /holds` (the verification queue), `GET /idv/requests` (awaiting an ID), `GET /restrictions` (on probation) — and the matching writes to verify (`POST /verified`), release onto restricted gateways (`POST /customers/{ident}/restriction`), lift a hold or withdraw a demand.

**Backfill history.** Walk `GET /orders?from=...&to=...&limit=100&offset=N`; the `date` field can be fed straight back into `from`/`to`. Use `GET /customers` for the identity list and `GET /customers/{ident}` for the dossier.

**Report upward.** `GET /insights/prevented` and `GET /insights/financial` are the Dashboard and Financial Protection pages as JSON; `GET /stats` is the one-call summary. Keep the four financial figures apart as the page does — the response labels which are observed, which estimated, which forecast.

**Multi-store platform.** One pairing per store. Record `store_id`, token, scopes, `api.signing.required`, webhook id and secret per store. Route inbound deliveries by `X-FS-Store`.

### Rules to never break

1. Act on `decision`, not `risk.level`.
2. Store `credential.token` and `webhook.secret` the moment you receive them — they are never retrievable again.
3. Sign over the **raw** body bytes you actually send; verify webhooks over the **raw** body you actually received.
4. The canonical path includes the namespace (`/fraud-shield/v1/...`) and has no trailing slash; query keys are sorted and `rawurlencode`d.
5. Deduplicate webhooks on `X-FS-Event`; expect retries.
6. Request the narrowest scopes; there is no wildcard. A reporting integration needs no `write:*` scope.
7. Do not cache risk answers (responses are `Cache-Control: no-store` for a reason).
8. Raw addresses, card numbers, ID images, document numbers and dates of birth are never available through any scope — do not design for them.
9. `read:events`, webhook payloads and the investigation scopes carry PII (emails, names, phones, IPs) unless `hash_pii` is on; handle accordingly.
10. Pass the integer `next_cursor` to `GET /events`, not the `evt_` string.
11. Write routes do what the admin buttons do, including their side effects (a restriction lifts a hold and withdraws an ID demand; verifying an account releases its queue rows). Read the route's description before wiring a button to it.
12. Check `store.version` on `/ping` before relying on a route marked ★.

---

## Value glossary

**`decision`** (from `POST /risk/evaluate`):

| Value | Meaning |
|---|---|
| `allow` | Nothing outstanding |
| `review` | Allowed, but scored `medium` or `high` — worth a human look |
| `restrict` | Allowed only on a limited set of payment methods |
| `verify` | Allowed only after an ID check |
| `block` | Refused |

**`reason`** — `""`, `blocklist`, `linked_blocklist`, `id_verification_requested`, `velocity`, `verification_hold`, `high_value`, `risk_score`.

**`risk.level`** — `none`, `low`, `medium`, `high`, `block`. Thresholds are merchant-configurable (`GET /settings` → `scoring.levels`).

**`high_value_rule`** — `null`, or `{ mode, tier, threshold, amount }`. `mode` is `soft` (invite to verify) or `hard` (refuse until verified). `tier` names the trust band that set the threshold: `none`, `auto`, `idv` or `staff`.

**`trust_tier`** ★ — `staff`, `idv`, `auto`, `none`: the strongest standing in force.

**`auto_verify.reason`** ★ — `""` (qualifies), `unknown`, `too_new`, `too_few_orders`, `not_clean`, `disabled`.

**IDV `result`** — `approved`, `name_mismatch`, `declined`, `expired`, or `""` while still in flight.

**IDV `name_match`** — `yes`, `no`, `n/a` (name matching switched off, or no name on file), `manual` ★ (a human accepted a mismatch), or `""` when the session never reached a name comparison.

**IDV `status`** — the vendor's own session status: `Not Started`, `In Progress`, `Approved`, `Declined`, `In Review`, `Expired`, `Abandoned`, `Kyc Expired`.

**IDV `method`** ★ — how the verification in force was earned: `didit` (completed check) or `staff` (accepted mismatch).

**`card.checked` `outcome`** — `pass`, `partial`, `fail`, `unavailable`, `notfound`, `error`.

**`card.checked` `cardholder`** — `confirmed`, `mismatch`, or `""` when the customer is not ID-verified so the two cannot be correlated.

**`avs`** — `Y`/`X`/`D`/`M` full match; `A`/`Z`/`W`/`B`/`P` partial; `N`/`C` no match; anything else means the check did not run.

**`cvv`** — `M` matched, `N` did not match, `P` not processed, `S`/`U` unavailable.

**Card-check `result`** ★ (`POST /orders/{id}/card-check`) — `ok`, `done`, `no_txn`, `notfound`, `network`, `disabled`.

**Blocklist `type`** — `email`, `phone`, `address` (64-char hash), `ip`, `user` (account id), `place` (destination lock).

**Block card `kind`** — `manual`, `profile`, `address`, `ip`, `place`, `import`.

**`checkout.blocked` `kind`** / Checkout Locks `kind` — `blocklist`, `velocity`, `idv`, `linked`.

**Link `via`** — `email`, `phone`, `ip`, `address`, `user`. **Link `degree`** — `1` the credential itself is blocked, `2` another identity using it is blocked.

**Network connection `via`** ★ — `account`, `phone`, `address`, `door`, `building`, `street`, `ip`, `device`. **`strength`** — `identity`, `strong`, `medium`, `weak`.

**Prevented `kind`** ★ — `blocklist`, `linked`, `score` (group `fraud`); `velocity`, `hold`, `idv`, `high_value` (group `verify`). **`stage`** — `cancelled`, `refused`.

**`order.chargeback` `source`** ★ — `manual`, `api`, `status`, `refund`. **`order.recovery` `source`** — `manual`, `api`. **`blocklist.extended` `source`** — `auto_extend`, `resync`.

**Scan `status`** ★ — `idle`, `running`, `paused`, `complete`. **`kind`** — `full`, `rescore`. **`phase`** — `index`, `score`. **`range`** — `all`, `1m`, `3m`, `6m`, `12m`, `new`.

**`customer.verified` `scope`** — `account`, `credential`, `allowlist` ★. **`origin`** — `""` or `idv`.

**Restriction `void_reason`** ★ — `""` (in force), `expired`, `idv`, `verified`.

**Financial `confidence`** ★ — `none`, `low`, `medium`, `high`. **Calibration `class`** — `blocked`, `risky`, `store`.

**Geography `level`** ★ — `state`, `city`, `zip`, `street`.

---

## Security model

**Credentials.** Secrets are 192 bits of CSPRNG randomness, stored only as SHA-256 digests and displayed exactly once. Verification is one indexed lookup plus one constant-time compare. A stolen database yields no working keys.

**Transport.** HTTPS required by default (`403 fs_api_insecure`). Responses are `no-store`, `nosniff`, `Referrer-Policy: no-referrer`.

**Least privilege.** Fourteen per-key scopes with no wildcard, an optional IP/CIDR allow-list that fails closed, an optional expiry, and a per-key rate limit. Reads and writes are separate scopes, so a reporting integration cannot mutate the store; the investigation scopes (`read:customers`, `read:orders`, `read:insights`, `read:events`) return PII — emails, names, phones, IPs, and on the Addresses registry the shipping address text the page shows — and should be granted as deliberately as the writes. Order rows carry only address hashes.

**What stays wp-admin.** Starting, pausing or cancelling a scan or import, editing settings, syncing from Didit, and minting or revoking API keys have no route by design: they are operations on the store rather than facts about it, and a key that could mint keys or switch Observe to Enforce would be a key worth stealing. The API reads their state (`GET /scan`, `GET /settings`, `GET /ping`) and leaves the levers to a logged-in administrator.

**Replay.** Signed requests bind method, path, query, body digest, timestamp and nonce. The ±300 s window plus a 600 s nonce cache means a captured request is either a duplicate nonce or an expired timestamp. `multipart/form-data` is refused because PHP consumes such a body before it can be read as raw bytes.

**Caller address.** Taken from the transport connection, **not** `X-Forwarded-For`, unless the store explicitly opts in behind a proxy that overwrites the header.

**SSRF.** Webhook URLs must be HTTPS, must not carry credentials, and must not resolve to private, loopback, link-local or reserved addresses — checked across every resolved address (IPv4 and IPv6), and re-checked immediately before every delivery. Adding webhooks is gated on `manage_woocommerce` or the `manage:webhooks` scope.

**Audit.** Authentication failures, rate limiting, scope denials, credential lifecycle and pairing activity are logged on the API screen, keyed by the *public* key id. No secret, token or pairing code is ever written there. Caller-triggered entries are coalesced to one per minute per event and address.

**Blast radius.** No scope grants access to card numbers, ID images, document numbers or dates of birth — the store never holds them. Every write route goes through the same code path as the corresponding admin button: blocklist writes through reversible block cards (one click, or one `DELETE /blocklist/actions/{id}`, to undo), chargeback marks and recoveries through the shared order functions, account verification and restrictions through the shared account functions — so an API mistake is undone the way a staff mistake is, and every surface raises the same events.

**Off-site data.** Webhook payloads never carry the per-credential evidence of a refusal or a third party's identifiers behind a linked cancel; those are readable only through authenticated, scoped reads (`GET /checkout-locks`, `GET /linked-cancels`). `order.cancelled` carries credential types and card titles, and the titles are hashed on digest subscriptions.

**Deliberate omission.** Webhook signing secrets are stored in plaintext, because signing a payload requires the secret itself and WordPress offers no key management that would protect it. Treat database access as equivalent to holding those secrets; rotate by deleting and recreating a subscription.

**Performance.** Routes register on `rest_api_init`, which never fires on a storefront page load. Event emission short-circuits on one autoloaded array read when no key and no subscription exists. Delivery is cron work with a 20-second budget per run. Every list endpoint is hard-capped at 100 rows and every filter lands on an indexed column; the detail routes (`/customers/{ident}`, `/orders/{id}`, `/network/{ident}`, `/insights/financial`) run the same bounded queries as the admin page they mirror. Bodies over 256 KB are refused before being hashed.

---

*Companion documents in the plugin repository: `API.md` (the 1.24 live-run capture this guide quotes from), `FRAUD-RISK-CRITERIA.md` (every scoring rule, weight and threshold), `SECURITY.md` (the plugin-wide threat model), `README.md` (feature guide and changelog).*
