# Fraud Shield for WooCommerce

**Detect, investigate, and stop card-testing rings, chargeback fraud, and serial abusers — before they cost you money.**

[![Version](https://img.shields.io/badge/version-1.32.0-blue.svg)](https://github.com/bonesrnd/fraudshield/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-6.x-21759b.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Fraud Shield scores every order in your store — your entire back history *and*
every new order the moment it is placed — against cross-order patterns that
individual-order review can't see: shared drop addresses, recycled emails and
phones, one IP running dozens of accounts, brand-new accounts ordering in
bursts, and identities linked to prior chargebacks.

Every flag is **transparent**: each order lists the exact signals and points
behind its score, giving you an evidence trail you can hand to your payment
processor when disputing a chargeback.

When you're ready to act, Fraud Shield layers **nine independent enforcement
controls** on top of the score — a reversible blocklist, automatic cancellation
of orders from customers *linked* to it, geographic destination locks, a
checkout velocity lock, a new-account high-value hold, payment-gateway
restrictions for brand-new accounts, and step-up **government-ID verification**
(via [Didit](https://didit.me/)) working in both directions: a restricted
customer can unlock the full payment-method set by proving who they are, and you
can **demand an ID from any customer** — blocking their checkout until they
verify, then releasing them automatically when they do. Each is opt-in, each is
reversible, and everyone caught by one is surfaced with a **one-click release**
so a false positive is never a lost customer.

And because an ID proves the *person* while only the card issuer can vouch for
the *card*, Fraud Shield closes the loop after payment: the **cardholder check**
pulls the issuer's AVS/CVV verdict for each card payment from your NMI
account(s) and flags — or holds — the order when an ID-verified customer pays
with a card the issuer says is not theirs.

---

## Table of contents

- [Download & installation](#download--installation)
- [Quick start](#quick-start)
- [Features](#features)
- [Enforcement layers](#enforcement-layers)
- [How scoring works](#how-scoring-works)
- [Feature guide](#feature-guide)
- [API & webhooks](#api--webhooks)
- [Security](#security)
- [Requirements & compatibility](#requirements--compatibility)
- [FAQ](#faq)
- [Changelog](#changelog)
- [License](#license)

---

## Download & installation

1. Go to the [**latest release**](https://github.com/bonesrnd/fraudshield/releases/latest).
2. Under **Assets**, download **`fraud-shield.zip`** (the pre-built plugin —
   not the "Source code" archives).
3. In WordPress admin, go to **Plugins → Add New → Upload Plugin**, choose the
   zip, click **Install Now**, then **Activate**.
4. A **Fraud Shield** menu appears in the admin sidebar.

**Upgrading?** Deactivate and delete the previous version first (your data,
settings, index, and blocklist are preserved — they live in the database, not
the plugin folder), then upload the new zip. Database schema upgrades run
automatically.

---

## Quick start

1. **Index your history** — *Dashboard → Run scan* (or upload an orders CSV via
   **Import CSV** on very large stores). Progress is saved continuously: you
   can pause, close the tab, and resume without starting over.
2. **Review what surfaces** — risky address/IP clusters on the Dashboard, the
   **Repeat Customers** report, and **New Customers** ranked by order velocity.
3. **Mark known chargebacks** — on any order (*Mark as chargeback*) or with the
   built-in **Charged back** order status, then *Recompute scores* to propagate
   that signal to every linked identity.
4. **Block confirmed bad actors** — one click on an address or IP blocks it
   **and every associated credential** (emails, phones, IPs, accounts).
5. **Go live** — switch **Settings → Mode → Enforce** when the flags look
   right. Until then, Fraud Shield observes and annotates but never interferes
   with checkout.
6. **Turn on the controls you want** — the velocity lock, new-account high-value
   hold, and new-customer gateway restriction are all off by default and
   independent of Observe/Enforce. Enable them under **Settings**, then watch who
   they catch on the **Checkout Locks** page — release anyone legitimate in one
   click.

---

## Features

### Detection
- **Cross-order risk engine** with transparent, per-order reasons and fully
  configurable rule weights, thresholds, and risk-level cut-offs.
- **Identity normalization** — Gmail dot/`+alias` collapsing, phone
  canonicalization, and street-level address hashing that catches "same drop
  house, different apartment number."
- **Automatic tracking** — every new order is indexed and scored at creation,
  from any source: classic checkout, block checkout, admin, REST API,
  subscription renewals.
- **Chargeback contagion** — orders sharing an email, phone, address, or IP
  with a prior chargeback inherit that risk.
- **Behavioral signals** — billing≠shipping divergence (count of an identity's
  mismatched orders), back-to-back ordering (a burst within 24 hours), paying
  **exclusively** via card processors (chargebackable), and choosing an
  expedited/overnight shipping method. The last two rely on a one-time
  classification pass (below) where you label which payment and shipping methods
  count.

### Investigation
- **High-Value Review queue** — every order at or above an amount you set,
  for today, yesterday, this week or the last 7/30/90 days, each row carrying
  what a reviewer needs to triage in place (who, where it ships, from which
  IP, the customer's history, the risk score and its reasons, who they are
  connected to) and one-click ways into the order, the profile, the network,
  the address and the IP. Three verdicts — **Looks good**, **Something off
  (hold the package)**, **Cancel now** — each noted on the order with the
  reviewer's name; a log of what was reviewed and by whom; a reviewer
  leaderboard; a Dashboard card and a menu bubble that count what nobody
  has looked at yet.
- **Customer Profiles** — a full intelligence dossier on every identity seen
  in your orders (registered or guest): linked customers sharing an IP or
  address, coupon codes used, payment methods, device signatures, average
  order value, ordering cadence and time-of-day patterns, IP-vs-country geo
  checks, billing/shipping consistency, identity-consistency checks (do their
  order details match their account?), every IP and address used, and the
  complete order history.
- **Address & IP registries** — browse, search, and sort *every* shipping
  address and IP ever seen (not just the risky ones); each opens a full
  profile: every account and order behind it, payment methods, coupon codes,
  and device signatures.
- **Address & IP drill-downs** — every account at a location (name, email,
  phone, totals, registration date, latest payment method) plus every order,
  newest first, each expandable to items, full billing/shipping, payment
  method, and IP geolocation.
- **Repeat Customers report** — everyone with more than one successful order
  in a chosen window (1/2/3 months or custom dates).
- **New Customers report** — recently registered accounts ranked by order
  velocity, the classic burst-then-chargeback pattern.
- **Search & sort everywhere** — free-text search across names, emails,
  phones, IPs, and addresses; every report column sorts by clicking its header.
- **CSV exports (Excel-compatible)** — one click exports the *entire* result
  set of the risky-address and risky-IP tables, Repeat Customers, New
  Customers, and Profiles, honoring your current filters and sort.
- **Payment-aware views** — order rows are green when payment succeeded, red
  when it failed or was reversed; money totals count successful payments only.

### Enforcement
- **Blocklist that blocks in every mode** — a blocklist entry is an explicit
  merchant decision, so it hard-blocks checkout even in Observe mode. Blocked
  visitors don't even see the checkout form: the checkout page is replaced by
  the configured message (returned as a `403` with no-store cache headers, so a
  page cache or CDN can never serve "you're blocked" to everyone).
- **One-click reversible, grouped blocking** — every Block is a *block action*
  that owns the credentials it cascades onto the blocklist. Click **Block**
  once to block an address, IP, or customer (and every email, phone, IP,
  account, and address ever associated with it); click the same button again to
  **unblock** and remove all of those credentials at once. The confirm dialog
  states exactly how many credentials will be removed.
- **Grouped blocklist** — the Blocklist page is organized into one card per
  block action (titled by the address, or the customer's name + email), each
  with **Remove all (unblock)**, **Re-sync**, and its own optional message. A
  credential shared by two blocks survives until *both* are removed. Legacy
  pre-1.6 entries live under an **Ungrouped** card and convert to a removable
  group the moment you re-block the matching address/IP/profile.
- **Cascade blocking + auto-extend** — blocking a cluster blocks every
  credential ever associated with it, and (optionally, on by default) any *new*
  order that matches a block automatically links its own fresh credentials to
  that block — so a ring rotating through new emails/cards stays blocked. An
  order note records each auto-link for auditability.
- **Auto-cancel orders linked to the blocklist** — the blocklist stops a
  customer whose *own* credentials are blocked; this optional rule reaches one
  step further. A customer who is not blocked but **shares a credential with a
  blocked identity** — the same shipping address (exact, the same address line
  and ZIP, or the same door — unit, ZIP or city rewritten — never a street alone),
  phone or account as someone on the blocklist, on this order or on any
  earlier order they placed — has the order **cancelled the moment it is
  created, before payment is taken**. A shared shipping address is enough on
  its own; **a shared IP never is** — an IP is recorded as corroborating
  evidence and only counts alongside another shared credential, because
  carrier and campus networks put strangers behind one address. It catches
  the ring member whose own details were never blocked, the drop address a
  blocked customer reused under a fresh email, and blocks that were imported
  or added by hand and so never cascaded. Every cancelled order names **the
  accounts and profiles the shared credentials lead to** — name, email,
  account id, each linked to its profile — in the order note, the order
  screen, an **"Auto-cancelled" badge in a Fraud Shield column on
  WooCommerce → Orders**, and a row on the **Auto-Cancelled** page (with
  one-click Block or Verify and a CSV export). A customer who tries again
  while the link stands is refused without creating another order, and the
  retry is counted against the first. Which credential types count is
  configurable; verified customers, verified credentials, allowlisted emails
  and completed ID checks are exempt, because the link is an inference rather
  than a verdict. Applies in Observe and Enforce mode alike; off by default.
- **Fraud-prevented dashboard** — the Dashboard opens with the money that did
  not change hands: every checkout a control stopped, at the total the
  customer would have paid, all-time and last-30-days, with a 12-month chart,
  a breakdown by control with a period switch, and the most recent stops. The
  headline counts **verdicts** only — blocklist hits, linked-identity cancels
  and block-level scores under Enforce; money merely held back while a
  customer verifies (velocity lock, new-account hold, ID rules) is shown
  alongside and never added in. Each cancelled order counts once, and a
  customer retrying a refused checkout counts once per rule per day, so the
  number cannot be inflated by a bot.
- **Financial Protection report** — four measurements, kept apart on purpose.
  **Historical losses** (observed): recorded chargebacks at the reversed
  amount, net of the recoveries staff record on the order screen (*Record
  recovery* — a dispute won, a partial reversal) and plus your processor's
  per-dispute fee once you enter it, with chargeback rates and a by-customer
  table. **Blocked transaction value** (observed): the value of the purchase
  attempts a verdict stopped — explicitly *not* money saved, with retries
  counted once, later-approved orders excluded, verification holds set
  aside, and orders staff cancelled or refunded while on the blocklist or
  linked to it included and marked as such. **Estimated losses prevented**
  (estimate): that value weighted by the chargeback rate of the history
  *behind* each stop — the stopped customer's own orders and, when the stop
  was tied to a blocked identity (a shared shipping address, phone or
  account, or a matched blocklist entry), that identity's orders too —
  blended with the closest class the store has outcomes for (blocked
  customers → HIGH/BLOCK-scored orders → the whole store), given as a
  conservative and an expected figure with the calibration table and a
  confidence grade. **Projected annual protection**
  (forecast): what the customers currently on the blocklist were costing per
  year while active — a customer who charged back $10,000 across two orders
  three months apart projects to $40,000 a year — labelled as a run-rate, not
  a saving, with a confidence grade per customer from the length and count of
  the history, an observed trailing-12-month anchor, and a CSV of every input.
  Reporting periods, assumptions and data limitations are always on the page;
  anything the store has not recorded reads *unavailable*, never zero.
- **Customer Network** — every customer identity ranked by risk (blocked
  first, then chargebacks, then risk score), with how many other customers
  share an identifier with them and the strongest link to a blocked one. Open
  a customer for an explorable relationship view: who they are connected to,
  **what matches** (account, phone, exact address, same door, same building or
  address line, street, IP, browser signature), the **evidence** (orders on
  each side, linked, with dates), its **recency**, the connected customer's
  own standing, and a **contribution to risk** that scales with the strength
  of the identifier — a shared street or IP is labelled a weak association and
  is never, by itself, grounds to block. Indirect links are traced one hop
  further over strong identifiers ("leads to blocked X via phone …"). Block,
  Unblock, Verify and Request ID are the same explicit, logged, one-customer
  actions as everywhere else; nothing is ever blocked as a group.
- **Message precedence** — the message a blocked customer sees is chosen as
  **per-block message → per-credential message → global default** (the velocity
  lock keeps its own message). The same precedence applies on the checkout-page
  gate, the classic checkout error, and the Store API rejection.
- **Blocklist export / import** — move your blocklist between sites as a CSV;
  custom messages **and block grouping** carry over via portable natural keys,
  duplicates are skipped. An old-format CSV imports under one reversible
  "Imported …" block.
- **Pre-payment blocking on both checkouts** — classic (shortcode) and blocks
  (Store API) checkout are both rejected *before* payment is taken.
- **Checkout velocity rule** — a plain count, not points: once a customer
  already has N orders inside a window you define (days or hours), their next
  order is acted on. The default action is **hold** — the checkout completes,
  the order is set On-Hold with a note saying how many earlier orders the
  customer had in the window, it stays On-Hold when the payment lands, the
  customer is listed on Checkout Locks, and your team is emailed; nothing ships
  until a person looks, and verifying the customer releases their paid held
  orders. Or **refuse** the checkout outright, or **restrict** high-velocity
  customers to a configured set of gateways (released automatically as their
  window rolls). "The same customer" is the account and the email, plus — each
  behind its own switch — the same billing **phone** (on by default) and the
  same shipping **door** (off by default; unit-blind, so it counts a whole
  building), so a customer who rotates email addresses between orders is still
  one customer. The 7-day/30-day velocity *score* rules are separate.
- **Checkout Locks page** — one place to see *everyone actually blocked from
  checkout* (with a live count) and release them. A durable ledger records each
  real block event as it happens (so the list is accurate and grows in real
  time, not reconstructed): accounts unlock via **Verify**, guest emails via an
  **email allowlist**, or **Dismiss** to clear a row. Tick rows and use
  **Restrict Checkout & Unblock** to bulk-release accounts onto a chosen
  gateway set; the **Restricted checkout** panel lists everyone on limited
  gateways with bulk remove. The new-account **Verification queue** sits
  alongside.
- **Verify from anywhere** — a Verify toggle sits next to every Block button
  (Dashboard, Repeat/New Customers, Profiles, Addresses, IPs, profile pages);
  verified entries show a green ✓ and collect on the Verified Customers page.
- **New-account verification hold** — when an account registered within the
  last N days places an order over a configurable amount, it is locked from
  placing further orders, the triggering order is optionally held for review,
  and your team is emailed to call the customer. Verifying releases the account
  (and its held order). Never touches guests; independent of Observe/Enforce.
- **New-customer payment gateways** — restrict which payment methods a new
  customer may use (e.g. keep brand-new accounts off card processors until they
  have a track record), with an optional **low-value allowance** that re-enables
  a chosen gateway for orders under a set amount (an easy path for small first
  orders; high-value first orders stay restricted). "New" is judged from the
  account **registration date**, falling back to the account owning the checkout
  email and then to the first order ever seen for that email, so guests and
  customers who register during checkout are covered rather than skipped.
  Enforced twice — on the methods displayed, and again on the method actually
  submitted, on both classic and blocks checkout — so a stale or cached checkout
  page can't slip an order onto a withheld processor. A new customer who needs a
  withheld method must pass the **ID check** or be **verified by your team**.
  Runs at the **last possible priority**, so a payment rotator or any other
  plugin that rebuilds the gateway list can't put a withheld method back. Fails
  open if misconfigured, so checkout is never fully locked out — and Settings
  tells you when it is failing open rather than leaving you to find out at
  checkout, via a **preview** of exactly what a new customer would see at any
  order total.
- **Automatic verification for established customers** — a customer who has
  been with you long enough (180 days by default), has bought at least once, and
  has no chargebacks or refunds is treated as verified: full payment methods, no
  velocity lock, no new-account hold. Evaluated live, so one chargeback ends it
  immediately, and it **never** overrides the blocklist, a destination lock, or a
  staff request for an ID. Every condition is configurable.
- **Cardholder check (NMI)** — an ID check proves the person; only the card
  issuer can attest whose card it is. After each card payment, Fraud Shield asks
  your NMI account(s) for the issuer's verdict on that exact transaction — the
  **AVS** address check, the **CVV** security-code check, and any 3-D Secure
  evidence — and records it on the order, in an order note, and on the **Card
  Checks** page. Combined with ID verification it answers the question that
  matters: an ID-verified customer whose card *passes* is **cardholder
  confirmed**; one whose card *fails* is the classic stolen-card-with-real-ID
  pattern, and the failed check can flag the order or put it **On-Hold** before
  it ships. Supports several NMI security keys (one per MID — rotator-friendly,
  with the working key remembered per gateway), stores only result codes and
  masked card data (brand, BIN, last four — never a card number), and staff can
  run it on any past NMI order with one click.
- **Restricted checkout that ends by itself** — a per-customer gateway
  restriction is probation, not a permanent state. It **lapses 6 months after
  it was applied** (configurable; 0 = never) and the customer returns to the
  normal rules, and an **approved ID check ends it immediately**. Both the
  Restricted checkout list and the customer's profile show the date each
  restriction releases, so nobody has to remember to go back and clear it.
- **Block from anywhere** — one-click Block/Unblock toggles now live on the
  Repeat Customers, New Customers, and Geography customer lists (not just the
  profile page).
- **Observe / Enforce modes** — heuristic scores never block in Observe mode,
  so day-one installs can't hurt legitimate checkout; Enforce adds
  hold-for-review or hard-block actions on scores.

### Geography & Destination Locks
- **Geography report** — fraud rolled up by ship-to **state**, **city**,
  **ZIP/postcode**, and **street**: order count, distinct customers, average
  risk score, high/block count, chargebacks and chargeback rate, and last order.
  Sortable, searchable, time-windowed, with a minimum-order floor (default 5,
  because an average over 2 orders is noise). Default sort is average score
  descending, so the worst destination is the first row. CSV-exportable with all
  filters honored. Click any state, city, ZIP, or street to drill into a
  sortable, filterable table of every customer shipping there (each with a
  one-click Block).
- **Destination Locks** — hard-block any checkout *shipping to* a locked place,
  at four scopes: **state** (`state:US|FL`), **city** (`city:US|FL|miami`),
  **ZIP prefix** (`zip:US|331`), and **street** (`street:US|FL|miami|n main st`).
  Lock or unlock in one click from any Geography row, or add one manually (e.g.
  pre-empt a corridor with no history yet). Locks are Block Actions, so they show
  as reversible cards on the Blocklist with their own optional checkout message,
  export/import with grouping, and Test Checkout preview — inheriting all
  enforcement surfaces automatically.
- **Street bans with explicit boundaries** — the drop house's whole street, not
  just the one door. A street lock keys on the street **name**: house numbers,
  unit numbers, recipients and punctuation are stripped and spellings
  standardized to USPS forms, so "123 N. Main Street, Apt 4", "456 North Main
  St" and "789 N MAIN ST #2" are one street, and a fraudster moving two doors
  down is still refused. Directionals and street types are kept (N Main St, S
  Main St and N Main Ave are three streets). Each lock carries the road's
  **boundary** — every city name, ZIP code and county it runs through — as keys
  under one card: "NW 66th St within Miami, Doral; within 33166, 33167, 33172,
  33195; within Miami-Dade". An order typed under any of them meets the lock;
  the identically named road in another county does not. The **Ban this
  street** button on an address's detail page and the Lock button on the
  Geography **Streets** tab pre-fill the boundary from every city and ZIP the
  road has been typed under in your history, and tell you how many orders,
  customers and addresses it covers before you confirm; or type one into the
  destination-lock form. Unlocking from any covered row releases the whole
  street. The refused customer reads *"You have been blocked from checkout,
  please contact customer service."* unless you give the lock its own message.
- **Building bans** — one house number on one street, anywhere in the state:
  every apartment, suite, mailbox, forwarding-account number, business and
  recipient at that building, under any city name or ZIP. One click (**Ban this
  building**) beside the street button; keep it alongside a street ban so the
  building stays banned if you later release the street.
- **Area bans** — a radius around a location. The address page shows where
  the geocoder puts an address (with a map link and the county) and offers
  **Ban this area** — everything within, say, half a mile. At checkout the
  typed address is resolved to a point and tested against every circle, so a
  changed unit, recipient, ZIP or city name has no effect: a building does not
  move. Nested bans report the tightest; nothing a geocoder cannot locate is
  ever matched by an area ban (the street and building bans cover those).
- **Rewrite simulation** — on any address page, **Simulate 240 rewrites**
  generates every way the address could be typed (directional, ordinal, street
  type, unit style, line placement, city, ZIP, state, Unicode — singly, in the
  pairs that matter, and a seeded sample of full combinations), runs each
  through the live blocklist exactly as a checkout would, and lists what would
  get through and why. Run it after placing a ban; run it again after the next
  rewrite shows up.
- **Address resolution (free)** — the typed city and ZIP are the weakest part of
  any geography rule: a reshipper types another ZIP or one of a city's several
  acceptable names and the carrier still delivers. Fraud Shield resolves every
  checkout that could meet a street, building or area lock to its **physical
  destination** first — standardized street, delivery ZIP, city, county and the
  point on the map — using the **US Census Bureau geocoder** by default: free,
  no key, no quota for interactive use, US addresses. Switch to **Google
  Address Validation** (worldwide, USPS delivery-point confirmation, paid beyond
  the monthly credit) or **OpenStreetMap Nominatim** (free, worldwide, one
  request per second by policy — enforced) under *Settings → Address
  resolution*. With validation on, street and building locks match the resolved
  destination too, and an address the geocoder **cannot find**, on a road that
  is locked anywhere in the state, is **stopped for correction** with your
  message rather than approved on text nobody confirmed (switchable). Results
  are cached per address; no request is made unless there is a lock to resolve
  against; an outage falls back to typed-text matching and never blocks a
  checkout. Staff see what an address resolved to on the refusal.
- **Exact-match cities, prefix ZIPs** — city locks match the exact normalized
  city name ("North Miami" is *not* "Miami"; "Miami, OK" is *not* "Miami, FL").
  ZIP prefixes are the precision tool: `zip:US|331` covers a whole metro,
  `zip:US|33172` a single ZIP.
- **Safety by design** — a destination lock **never cascades** to identities and
  **never auto-extends**: blocking a city blocks the destination, not the people
  who ship there. Identity messages outrank geo messages (a blocked person sees
  their message, not the city's). Verified customers are always exempt.
- **Guest limitation** — the checkout-page gate applies to identifiable visitors
  (logged-in, with a saved destination); a guest's destination is unknown until
  typed, so guests shipping to a locked place are rejected at submission instead
  (classic checkout error / Store API rejection), with the same message.

### Operations
- **Email alerts** — notify any addresses the moment an order at or above a
  chosen risk level (or from a blocklisted identity) is placed.
- **Test Checkout** — enter any credentials and preview exactly what that
  customer experiences at checkout, including the message they would see.
- **Resumable, firewall-friendly scanning** — batched with saved progress,
  pause/resume, date-scoped scans (1/3/6/12 months, all time, or new-only),
  and automatic skip of already-indexed orders.
- **CSV import & export** — import an orders export instead of scanning;
  export the index (scores included) as a re-importable backup.
- **Order-screen meta box** — live score, reasons, one-click
  chargeback/block actions on every WooCommerce order, and — when the
  customer's credentials are on the blocklist — exactly which ones, blocked
  since when, by whom, under which block and why, each linking to its place on
  the Blocklist page. The Fraud Shield column on WooCommerce → Orders carries
  the same at a glance ("On blocklist: email, phone · since … · by …").

---

## Enforcement layers

Fraud Shield keeps the **heuristic score** (which you ease into with Observe →
Enforce) separate from the **deliberate controls** you switch on explicitly. The
deliberate controls apply in *every* mode — that is the whole point of them — and
each one is opt-in, reversible, and off by default (except auto-extend, which only
ever grows a block you already made).

| Layer | What it stops | Applies to | Default | Release valve |
|---|---|---|---|---|
| **Risk score** | Orders matching risky cross-order patterns | Every order | Observe (annotate only) | Switch to Enforce when ready |
| **Blocklist** | Confirmed bad actors ever checking out again (email, phone, IP, address, account) | Matching credentials | On the moment you block something | Blocklist card → *Remove all (unblock)* |
| **Linked auto-cancel** | Orders from customers who *share* a credential (shipping address, phone, account; an IP only alongside one of those) with a blocked identity without being blocked themselves — cancelled at creation, before payment, naming the account/profile it leads to | Customers linked to any blocklist entry, on this or an earlier order | Off | Auto-Cancelled page → *Verify* (exempt) or *Block* (make it a verdict); unblocking the linked entry releases everyone linked through it |
| **Destination locks** | Any checkout *shipping to* a locked state / city / ZIP / street (within its boundary of city names, ZIPs and counties) / building (one house number, every unit) / area (a radius around a location) — street and building locks matched against the resolved physical destination when validation is on, area bans always; an unverifiable address on a locked road stopped for correction | Ship-to geography | On when you lock a place | Blocklist card, or the lock toggle on any covered row |
| **Velocity rule** | N or more orders inside a window you define (days or hours), counted, never scored — the next order is placed On-Hold for review (default), refused, or limited to a gateway set | Accounts + guest emails, plus the same phone (on) and the same door (off) when switched on | Off | Checkout Locks → *Verify* / *Allow*; restrict mode releases itself as the 30-day window rolls |
| **New-account hold** | A new account's first high-value order (blocks further orders) | Recently-registered accounts | Off | Checkout Locks → *Verify & unlock* |
| **New-customer gateways** | Brand-new customers reaching your card processors at all — enforced on the methods shown AND on the method submitted | Recently-registered accounts, plus guests with no trading history | Off | Ages out, passes an ID check, or mark *Verified* |
| **Auto-verification** | *(exemption, not a block)* Established customers with a clean history tripping the automatic controls | Customers 180+ days old with paid orders and no chargebacks/refunds | On | Configurable in Settings; never overrides the blocklist |
| **Per-customer gateways** | A released customer using anything beyond their staff-assigned gateway set | The specific account | Off (per-customer) | Lapses on its own after 6 months, ends early on an approved ID check, or Restricted checkout list → bulk remove |
| **ID verification (Didit)** | Stolen-card fraud on your card processors: a gateway-restricted customer is invited to verify a government ID at checkout, and approval unlocks the full gateway set. A billing-name mismatch is flagged for review (or held, if you prefer) | Gateway-restricted customers | Off | Expires after N days (configurable); revoke any time from the order screen, profile, or ID Verifications page |
| **ID requested** | A specific customer you don't trust yet checking out at all: *Block checkout & request ID* on their profile blocks them until they pass a government-ID check | The specific account | Off (per-customer) | The customer clears it themselves by verifying; or *Cancel request* on their profile, Checkout Locks, or ID Verifications |

**Exemptions.** Marking an account **Verified** exempts it from *every* layer,
including the blocklist. The **email allowlist** (for releasing a locked guest
email) exempts from the velocity lock and the new-account hold, but **never** from
the blocklist — an explicit block is always the strongest signal. A **per-customer
gateway restriction** counts as a release: it clears the velocity lock and hold for
that account, but the customer checks out only on the assigned gateways. A
completed **ID verification** counts like a verified credential — and is recorded
as one, so ID-verified customers appear on the **Verified Customers** page: it
clears gateway restrictions, the velocity lock, and the new-account hold, but
never the blocklist, and never a per-customer restriction staff set deliberately.

**Why *ID requested* is not a blocklist entry.** A blocklist entry is a verdict
that holds until you lift it. *ID requested* is a question the customer is meant
to answer, so it lives in its own flag and clears itself the moment they pass.
Keeping the two apart is what lets this block be self-releasing without making an
approved document a way around the blocklist — a blocked customer who verifies is
still blocked.

---

## How scoring works

Each rule that fires adds points; the total maps to a level (**Low → Medium →
High → Block**) with configurable cut-offs. Highlights:

| Signal | Default points |
|---|---|
| Email used on a prior charged-back order | 80 |
| Exact shipping address on a prior chargeback | 70 |
| Phone on a prior chargeback | 60 |
| Same street/ZIP (different unit) on a prior chargeback | 40 |
| Order IP on a prior chargeback | 35 |
| Address shared by 3+ customer emails | 25 (+5/extra) |
| Pays exclusively via card processors (2+ orders) | 25 |
| Many accounts ordering from one IP | 20 |
| Disposable email domain | 20 |
| Order velocity (3+ in 7 days) | 20 |
| Back-to-back orders (3+ within 24 hours) | 20 |
| 2+ orders where billing ≠ shipping address | 20 |
| Account younger than 7 days | 15 |
| Expedited / overnight shipping method | 15 |
| Billing/shipping country mismatch | 10 |

Every weight and threshold is editable under **Settings**, and *Recompute
scores* re-applies changes to your whole history in minutes — no re-scan
needed.

---

## Feature guide

| Screen | What it's for |
|---|---|
| **Dashboard** | Store-wide stats, the **Fraud prevented** savings panel (money stopped, by month and by control), the count of high-value orders awaiting review, scan controls, CSV export, top risky shipping addresses and IPs |
| **High-Value Review** | Every order at or above the review amount for today / yesterday / this week / the last 7, 30 or 90 days — awaiting review, reviewed, or all — each with the customer's standing and history, where it ships and from which IP, the risk reasons, their connections and the strongest link to a blocked customer, in-place order details, and links to the order, profile, network, address and IP. Verdicts (**Looks good** / **Something off — hold package** / **Cancel now**) with an optional note; the window's review log with who decided what and what it did to the order; a reviewer leaderboard; CSV exports of both |
| **Financial Protection** | Historical losses (recorded chargebacks net of recoveries, plus fees when configured), blocked transaction value, estimated losses prevented with its calibration, and projected annual protection with per-customer confidence — each labelled observed / estimate / forecast, with reporting periods, assumptions and data limitations, and a CSV of the projection |
| **Customer Network** | Every customer ranked by risk with their connections; explore any customer to see who shares an account, phone, address, door, building, street, IP or browser signature with them, the evidence and recency behind each link, their standing, and the link's contribution to risk — with the existing one-customer Block / Verify / Request ID actions |
| **Repeat Customers** | Accounts with 2+ successful orders in a period — sortable, searchable |
| **New Customers** | Recently registered accounts ranked by order frequency, with full profiles |
| **Profiles** | Intelligence dossier on every customer identity: links, devices, coupons, cadence, geo checks, and every time they were refused at checkout — which credentials matched, blocked since when and by whom, and the blocked person they were matched to |
| **Addresses** | Registry of every shipping address with per-address profiles and cascade blocking |
| **IPs** | Registry of every IP with geolocation, per-IP profiles, and cascade blocking |
| **Geography** | Fraud rolled up by ship-to state / city / ZIP / street (orders, customers, avg risk score, high/block, chargebacks + rate), plus one-click Destination Locks |
| **Import CSV** | Populate the index from an orders export; also restores Fraud Shield backups |
| **Blocklist** | Search across blocks and credentials (by value, reason or block name — emails and phones matched in normalized form too) with a type filter, a checkbox on every credential with Select all / Select all IPs / Remove selected, reversible block actions grouped into cards (Remove-all / Re-sync / per-block message), per-credential messages, manual additions, bulk removal of IP addresses (a pasted list, everything a search matches, or every IP), and an Ungrouped card for legacy entries |
| **Checkout Locks** | Everyone actually blocked from checkout (a durable, real-time ledger reconciled against the live lock), each blocklist or linked refusal spelling out exactly which credentials matched, when and by whom they were blocked, under which block, and whom they were matched to; bulk "Restrict Checkout & Unblock", the Restricted checkout list (bulk-removable), the new-account verification queue — each with one-click Verify / Allow / Dismiss — and the email allowlist |
| **Auto-Cancelled** | Every order the linked-to-blocklist rule cancelled: when, the order and its current status, the customer, the total, exactly which credential is shared, which block card it leads to and **which accounts and profiles are behind it** (linked), how many retries were refused — with one-click Block / Verify, search, and CSV export |
| **Verified Customers** | The green "known-good" list (verified accounts, emails, addresses, IPs) that bypasses the velocity lock, holds, and gateway restrictions (never the blocklist); verified profiles show green on the Profiles page. ID-verified customers land here automatically. Exports and imports as CSV for backup or moving between sites |
| **ID Verifications** | Everyone who has been through the Didit flow, passed or failed — outcome, name on the document vs the name expected, document type, timestamps and linked order, with filters, search, CSV export, and the queue of customers currently blocked awaiting a requested ID |
| **Card Checks** | The issuer's AVS/CVV verdict on each card payment, pulled from NMI — outcome, card, 3-D Secure use, and the **Cardholder mismatch** filter for ID-verified customers paying with a card the issuer says is not theirs |
| **Test Checkout** | Simulate any customer's checkout and preview the exact outcome and message |
| **API & Webhooks** | Scoped API keys (shown once, stored hashed), the single-use pairing code that onboards an external platform, webhook subscriptions with per-topic selection and optional hashed identifiers, recent delivery attempts, and the authentication security log |
| **Settings** | Checkout banner, mode & actions, block message, auto-extend, linked auto-cancel (which credentials count, the customer's message), Financial Protection assumptions (chargeback fee, loss ratio, minimum window), email alerts, velocity lock, new-account verification hold, new-customer payment gateways (with a live preview of what a new customer sees), restricted-checkout expiry, automatic verification, ID verification (Didit), cardholder check (NMI), payment- & shipping-method classification, rule weights, thresholds, risk cut-offs, disposable domains |

---

## API & webhooks

Since 1.24.0 Fraud Shield answers HTTP requests and pushes signed webhooks, so
another system — a reporting dashboard, an internal tool, or a platform such as
**bonesfraud.com** — can ask this store what it knows about a customer, act on
the answer, and be told when something changes.

The endpoint everything else exists to support is **`POST /risk/evaluate`**: hand
it an email, an address, an IP and an amount, and it returns the risk score, the
reasons behind it, everything the store already knows about that identity, and
the decision checkout would reach right now (`allow`, `review`, `verify`,
`restrict` or `block`). No order needs to exist, and the inputs are normalized
exactly as an order would be, so an ad-hoc lookup sees the same history a real
order would.

Beyond that: read customer profiles and indexed orders, manage the blocklist and
the verified-customer list, read ID verifications and demand one from an account,
manage subscriptions, and poll the event log if you would rather pull than
receive. Since 1.30.0 the API reaches everything the admin screens do: the full
customer dossier (credentials, linked identities, refusals, blocklist provenance,
trust standing), address and IP registries, the Checkout Locks ledger with its
evidence, the Auto-Cancelled ledger, chargeback marks and recoveries, cardholder
checks, block cards and bulk removal, gateway restrictions, holds, the email
allowlist, the Fraud-prevented and Financial Protection figures, the Customer
Network and the Geography rollups, plus a read-only view of the store's scoring
rules and controls.

**Onboarding an external platform** does not involve pasting a permanent secret
anywhere. An admin opens a pairing window under *Fraud Shield → API & Webhooks*,
which produces a single-use code valid for as long as they chose. The platform
posts that code once to `/handshake` and receives its own scoped key plus a
webhook secret — both shown only in that one response. The window closes the
moment the code is used, and burns after five wrong guesses.

Twenty-four webhook topics cover risk verdicts, refused and auto-cancelled
checkouts, chargebacks and recoveries, high-value review verdicts,
verification changes, blocks and their reversals, holds, ID-check demands and
outcomes, cardholder verdicts and restriction changes.
Deliveries are HMAC-SHA256 signed with a timestamp inside the signed material,
retried with exponential backoff, and — crucially — never sent inside the request
that produced them, so a subscriber's outage can never slow down a customer's
checkout.

Security highlights: keys are 192-bit secrets stored only as SHA-256 digests and
shown once; scopes are per-key and there is no wildcard; requests can be required
to be HMAC-signed with replay protection; keys can be pinned to an IP or CIDR
range and given their own expiry and rate limit; webhook URLs are refused if they
are not HTTPS or resolve into private address space; and payloads can be
configured to carry stable digests instead of emails, names and IPs.

Full reference — every endpoint, the exact signing schemes, payload samples, and
verification code in PHP, Node and Python — is in **[`API.md`](API.md)**.

---

## Security

Fraud Shield deliberately collects data authored by fraudsters, so every admin
surface and export is treated as a sink for hostile input: capability +
per-action nonce checks on every handler, prepared statements everywhere,
escaping at output, formula-injection-neutralized CSV exports, a hardened
uploads directory, and a cache-proof 403 block page. Outbound calls go only to
the partners you explicitly configure — Didit for ID checks, your own NMI
account(s) for cardholder checks, and any webhook endpoints you subscribe — over
HTTPS. The API adds hashed-at-rest credentials, per-key scopes, optional
HMAC request signing with replay protection, IP pinning, rate limiting, and an
SSRF guard on webhook targets; see [`API.md`](API.md#security-model) for that
subsystem's model. The full threat model, PII inventory, audit findings, and
merchant hardening checklist live in [`SECURITY.md`](SECURITY.md).

---

## Requirements & compatibility

- WordPress with **WooCommerce 6.0+** (classic and block checkout supported)
- **PHP 7.4+**
- **HPOS compatible** (High-Performance Order Storage) and legacy post storage
- IP geolocation uses WooCommerce's built-in MaxMind integration — add a free
  license key under *WooCommerce → Settings → Integration* to see locations
- Translation-ready (`fraud-shield` text domain)

---

## FAQ

**Will installing this break my checkout?**
No. Fraud Shield ships in Observe mode: heuristic scores only annotate orders
and never block anyone. Every enforcement control (blocklist, destination locks,
velocity lock, new-account hold, new-customer gateway restriction) is opt-in and
off until you turn it on — and the ones that could theoretically lock everyone out
fail safe (e.g. the gateway restriction shows all gateways if you haven't allowed
an active one). The only things that ever block a checkout are the explicit
actions you take, and those apply in every mode, because that's what blocking is
for.

**How do I keep brand-new accounts off my credit-card processor?**
*Settings → New-customer payment gateways*: enable it, set the "registered within
the last N days" window, and tick the gateways new accounts may use (e.g. only
bank transfer). Accounts older than the window and Verified customers keep every
gateway. It's enforced on classic and block checkout and validated server-side, so
a disallowed gateway can't be submitted.

**A customer passed the ID check — how do I know they're the actual cardholder?**
That is exactly what the **cardholder check** answers. The ID check proves the
person; the card issuer is the only party who can vouch for the card, so after
each card payment Fraud Shield pulls the issuer's AVS/CVV verdict from your NMI
account and binds it to the verification: ID-verified + card passes reads
**Cardholder confirmed**, ID-verified + card fails reads **Cardholder MISMATCH**
(a verified person on someone else's card — treat as stolen) and can hold the
order before it ships. Enable it under *Settings → Cardholder check (NMI)* with
one security key per NMI MID. For even stronger, issuer-side authentication,
also ask your processor about enabling **3-D Secure** — when a payment carries
it, Fraud Shield records that evidence too.

**Where do I see — and release — everyone who's locked out of checkout?**
The **Checkout Locks** page: it lists the velocity-locked customers and the
new-account verification queue with a live count, and releases any of them in one
click (*Verify* an account, or *Allow* a guest email). Blocklisted credentials and
destination locks are managed on the **Blocklist** page.

**Does it slow my store down?**
Scoring happens on order creation against an indexed table designed for large
catalogs (hundreds of thousands of orders). The heavy lifting — back-history
scans — runs in resumable batches in the admin.

**A scan keeps failing with HTTP 403.**
Your host firewall or CDN (e.g. Cloudflare Bot Fight Mode) is rate-limiting
`admin-ajax.php`. Fraud Shield batches work to minimize requests and retries
automatically; if it persists, allowlist `admin-ajax.php` for logged-in admins
or pause the CDN during the one-time backfill.

**What data does it store?**
Normalized order signals (hashed addresses, normalized emails/phones, IPs, ship-to
city/state/postcode, totals, statuses) in its own tables, plus your grouped
blocklist, block actions, a ledger of checkout-block events, and a ledger of
the orders the linked-to-blocklist rule cancelled. A little
per-customer state lives in user meta (the *Verified* flag and any active
verification hold) and one option holds the email allowlist. Uninstalling removes
all plugin tables, options, and metadata.

**Is a risk score proof of fraud?**
No. Scores flag patterns; they don't prove intent. Treat them as decision
support — especially before hard-blocking a customer.

---

## Changelog

**1.32.0**
- **New: High-Value Review — a queue for the orders a person should look at before they ship.** The problem it answers: customer service had stopped reviewing the high-value orders, and nothing on the admin side said so. A new **High-Value Review** page (second in the menu, with a bubble counting what is unreviewed this week) lists every order at or above an amount you set (*Settings → High-value order review*, default 500, compared against the order total as indexed; 0 switches the page off) for **today**, **yesterday**, **this week** (from the day *Week Starts On* names), or the **last 7, 30 or 90 days** — awaiting review by default, or reviewed, or all. Each row is built for triage without leaving the page: when it was placed; the order, its status and payment method, with the line items, full billing and shipping addresses and IP geolocation one click away in place; the customer with their phone, account age (a **New account** pill under 30 days) or guest status, **Blocked** / **Verified** standing, and their history on this store (**First order**, or *N orders · spend settled*, and any chargebacks); the total; where it ships and from which IP, with **Billing ≠ shipping** and **IP ≠ ship country** flags and links to **everyone at this address** and **everyone on this IP**; the risk level, score and top reasons; and their connections — how many customers share an identifier with them and, in red, **the strongest link to a blocked customer and through what** — with links to the profile and the Customer Network. Rows are tinted by risk and dark when the customer is on the blocklist. Six cards give the window's shape: awaiting review (and its value), reviewed, looks good, held, cancelled, and **older, still unreviewed** — live high-value orders placed before the window that nobody has looked at, with the oldest date, so a stale queue is visible from any tab.
- **Three verdicts, one path.** After investigating, the reviewer records **Looks good** (the order is left as it is), **Something off — hold package** (the order is set On-Hold so nothing ships until someone releases it; already held, completed or dead orders are only noted) or **Cancel now** (the order is cancelled; the payment is deliberately *not* refunded by this — the button says so, and the refund stays a decision on the order screen), with an optional note. Every verdict writes one order note naming the reviewer, the verdict and the note; stamps the order; appends to a ledger; and raises `order.reviewed`. The ledger is append-only, so a verdict can be changed later (**Review again**) and every look is kept — the latest is the order's current state. The same three buttons sit in the order screen's Fraud Shield box for any high-value order, and the WooCommerce → Orders list's Fraud Shield column shows **High value · awaiting review**, or the verdict and who gave it. A staff **Cancel now** on an order tied to the blocklist is counted as fraud prevented exactly as a manual cancellation is (1.30).
- **Who is checking.** Below the queue, **Reviewed** lists every verdict recorded in the window — when, by whom (and from where: the page, the order screen, the API), the verdict, the order and customer, the total, and what it did to the order's status — filterable by reviewer. The **Leaderboard** ranks reviewers by verdicts recorded in the window, with the looks-good / held / cancelled breakdown, the order value they looked at, their share, their last review and their all-time count; a second look at the same order counts, because the point is that someone looked. The Dashboard gains a card — high-value orders awaiting review today, and this week — that links straight to the queue. Both the queue (as filtered) and the review log export to CSV.
- **API and webhooks.** `GET /high-value` (the queue with its headline numbers, every `GET /orders` field plus the latest verdict and the customer's history on each row), `GET /high-value/reviews` and `/high-value/reviews/{id}` (the log), `GET /high-value/leaderboard`, and `POST /orders/{id}/review` to record a verdict over the API (the reviewer is the key); `high_value_review` on `GET /orders/{id}`; new topic `order.reviewed`. Scopes `read:orders` / `write:orders`. Reference in [`INTEGRATION.md`](INTEGRATION.md).
- Validated on the MariaDB rig with a 109-check harness over the window arithmetic (calendar day, configurable week start, Monday-as-first-day, 7/30/90-day spans), the queue as a view (threshold, window, dead statuses hidden for unreviewed rows and ignored for reviewed ones, chargebacks excluded, search, sort, paging, a threshold change applying at once), every verdict through the shared path (status changes and the cases that leave status alone, the single order note, the meta stamp, the ledger row, the event, the API reviewer, the capped note), the stats and backlog before and after verdicts, the review log filters, the leaderboard (ranking, tie-break, breakdown, share, renamed and deleted accounts), the customer facts and the API shapes; a 64-check render pass over the page in every window and filter, the empty and switched-off states, the admin-post handler, the Dashboard card, the Settings panel, the order meta box and the Orders-list cell with warnings promoted to errors; and a 37-check pass over the four routes. Existing harnesses unchanged and passing.

Database version 23: adds the `fs_hv_reviews` ledger (created automatically on first load). No other schema changes; drop-in update.

**1.31.1**
- **New: rewrite simulation.** Every address page has a **Simulate 240 rewrites** button. It generates every way that address could be typed — "N.W." / "Northwest" / glued to the number / moved after the type; "66th" / "66" / "66 th" / "Sixty-Sixth"; "Street" / "Str" / no type; the unit as Apt / Ste / # / glued / repeated / on the other line / first / bare / another box number; a name or a unit on line 1 with the street on line 2; the destination typed into the line; the city renamed, misspelled or blank; the ZIP rotated, extended, blanked or invented; the state spelled out; Cyrillic lookalikes, full-width digits, zero-width characters — every single change, the pairs that matter, and a seeded sample of full combinations — and runs each through the live blocklist exactly as a checkout would: typed text first, then the geocoder for whatever got past that (capped at forty requests). It reports what was refused and how, and lists **what would get through**, spelled out, with the reason. The same battery ships as a harness (`tools/sim-rewrites.php`).
- **Fix: the parser gaps that simulation found.** Spelled-out ordinals beyond twelfth ("Sixty-Sixth", "sixty sixth", "Twenty-First") now collapse to their number; an ordinal suffix typed as its own word ("66 th", and "71 st St" / "23 rd Ave" before another type) folds in; the house number glued to a two-letter directional ("8020NW 66th St", "8020NW66TH ST") is split, as is a directional glued to a number word ("NWSixty-Sixth" — never "Newark" or "Seward"); a "No." before the house number is a number marker, not a unit; a box number typed first ("Box 152978 8020 NW 66th St") is a unit written first, not a PO Box line; several leading unit pairs are all stripped ("Apt 152978 Apt 152978 8020 …"); the forwarder's and office vocabulary joins the unit designators (MB, locker, level, wing, bay, desk…); a long bare number after the street type ("… 66th St 152978") is a box number; words standing after the last street type are a destination typed into the line ("… 66th St Miami", "Collins Ave Miami Beach") and are cut — never for a name-after-type line ("Rue St-Jacques", "Avenue of the Americas"), never a type in first position, and a lone post-directional stays; and a spelled-out state ("Florida", "Ontario", "New South Wales") is its code in every key. A street typed without its type ("8020 NW 66th") is also tried as each common type against building and street locks, as it already was against the door hash.
- **Geocoder load controls.** Checkout was already one `match()` — six to twelve indexed queries, about a millisecond of database time — and at most one geocoder request per new address, but with an area ban in place that one request was made for every new address in the country. Now: geocoding is scoped to the **region that has something to resolve against** — street and building locks carry their state, and an area ban records the state its centre resolved to when it was drawn — so a Texan's checkout never costs a request on a Florida lock's account (a ban drawn from bare coordinates keeps every state eligible, the safe direction); a **circuit breaker** pauses the provider for two minutes after a failed request, so an outage stalls one checkout for the timeout and no others (typed-text matching continues, fail open); and the request timeout drops from six seconds to four. Measured on the probe rig with the three bans and a 5,000-row index: 1–2 ms and 7–12 queries per checkout, zero geocoder calls for a refused or out-of-state address, one for an in-state new address, cached thirty days; the Simulate button's 240 variants cost about a quarter of a second and up to forty requests, and run only when clicked.
- **Fix: a ZIP-bounded street lock could stop an unverifiable address a thousand miles away for correction.** The "potential match" test for an unconfirmed address now requires the typed ZIP to share its first three digits with a ZIP-bounded lock's; city- and county-bounded locks were already state-scoped.
- Harness: `sim-geoban` grows from 147 to 154 (the breaker open, closed and cleared; region-scoped gating); new `sim-rewrites` (40 checks) — the generator's dimensions and determinism, then the battery against nothing (60 of 60 through), the building ban alone (240 of 240 refused on text, no geocoder), the street ban alone (204 on text, the geocoder catches 36 more, nothing through — and with validation off, the 36 with city and ZIP both outside the boundary get through, which is why validation is on), the area ban alone (212 refused through the geocoder; 28 exotic spellings the geocoder cannot place get through — which is why the building ban stands beside it), all three together (240 of 240, all on text), and the neighbours, Georgia and NW 66th *Avenue* open. `sim-street` 195 (two expectations moved to the cleaner tail cut); every other harness unchanged and passing.

Database version 22: no new columns. The parser changed, so the background job re-derives every index row (`geo_scheme` 3), carries standing address blocks onto their current door key, and realigns street locks — the same three steps as 1.31.0, with nothing to click. Drop-in update.

**1.31.0**
- **Fix: the door key could be moved by changing the ZIP code — and was.** The incident: one reshipper door, 8020 NW 66th St in Miami, placed orders under four ZIP codes (33166-3900, 33167, 33195, 33195-3900) and two unit spellings. Each row on the Addresses page was blocked in turn, and the next one still got through, because the door key was house number + street + **postcode** — and a wrong or rotated ZIP costs a reshipper nothing (carriers correct it in transit), so every rotation minted a key no block had seen. The exact and loose hashes carry the ZIP too, so all three moved together. The door key is now **house number + street + country + state** for US, Canadian and Australian addresses (the postcode anchors everywhere else — a UK postcode's outward code, a Canadian FSA), blind to the unit, the ZIP, the ZIP+4 and the city (a Miami-Dade door is deliverable as Miami, Doral or Medley). The five rows in the incident are one key; so are the rewrites that would have come next.
- **The street parser closes the rest of the rewriting room.** Every numeric ordinal collapses ("66th" and "66" were two streets), word ordinals up to twelfth too; directionals are aligned ("N.W.", "N W", "Northwest" and "NW" are one; "NW66th" is split; a trailing "Main St NW" moves to the front) and street-type spellings are folded to the USPS abbreviation ("Str", "Strt", "Boul", "Terr", "Mountain"→"Mtn" and the rest of the common list); unit values may run to eight digits (a forwarder's "Ste 152978"), "STE152978" is split, and a long box number typed beside the house number with no designator at all is read as the box; the street is read from **line 2** when line 1 carries only the unit or a "c/o"; a destination typed into the line (", Miami, FL 33166", "USA", a different ZIP) is dropped from the end; a leading zero on the number goes; and Unicode lookalikes (Cyrillic "Ѕ" for "S"), full-width digits and zero-width characters are folded before parsing. An address typed *without* its street type ("8020 NW 66th") is additionally checked as "… St", "… Ave", "… Rd" and the other common types — on the incoming side only, so a block on "66th St" never becomes a block on "66th Ave". Street locks share the parser, so "NW 66 St" can no longer walk past a lock on "NW 66th St". Historical exact and loose hashes are untouched.
- **What stays distinct, by construction:** the next house number, the other side of a directional, "66th St" vs "66th Ave" in a grid city, other states and countries; no house number, no street, a PO box or a unit-only line yields no key. The one deliberate cost: the same number on a street of the same name in another city of the same state shares the key — the population is small, the consequence is a refusal that names the match, and the remedy is removing that one door-key row on the Blocklist page.
- **The upgrade carries every standing block across, with nothing to click.** Every address on the blocklist gets its current door key planted beside it under the same block card (read from the orders behind it), every street lock's key is realigned in place, and every index row is re-derived — all of it driven by a self-rescheduling background job (`geo_scheme` is the cursor now; the Geography page's **Backfill now** button still works and simply gets there sooner, and sites that never pressed it after 1.25.1 get their backfill finished too). A **Block** clicked while that job is still running re-derives the address's own rows first, so it can never plant a stale key. API consumers: `door_id` values change on update — re-read them.
- **Velocity: the score now escalates with volume.** A flat 20 points scored six orders in a day exactly like three, so a same-day spree never reached Block on velocity alone. Each order beyond the threshold now adds points, capped — new weights `velocity_7d_each` (10, cap 40) and `order_burst_each` (25, cap 60), both editable: at the thresholds the score is exactly what it was (40); 4 prior orders within 24 hours is HIGH (75); 5 is BLOCK (110) on velocity alone, and sooner alongside the other signals the incident carried. Existing weights are unchanged.
- **Velocity lock: who counts as the same customer.** The lock counted orders by account and email only, so a customer rotating email addresses between orders was a new customer each time. Two new switches under *Settings → Checkout velocity lock*: the same billing **phone** (on by default — a phone is a person) and the same shipping **door** (off by default — a door is a place, and unit-blind it counts a whole building; turn it on when the fraud you see ships to a reshipper under changing names). Both apply on the classic checkout, the blocks/Store API draft stage and backstop, the checkout-page gate, the API's `POST /risk/evaluate` and the Test Checkout preview.
- **New: location-wide shipping bans — the street within its boundary, and the building.** Blocking address *spellings* is a chase; these ban the *place*. A **street ban** keys on the standardized street name (USPS directional and type forms; NW 66th St is never NE 66th St or NW 66th Ave) and ignores house numbers, units and recipients; it carries the road's **explicit boundary** — every city name, ZIP code and county the road runs through — as keys under one card, so an order typed under any of them meets the lock while the identically named road in another county does not. The **Ban this street** button and the Geography Streets tab pre-fill the boundary from every city and ZIP the road has been typed under in your history (for the incident: Miami, Doral; 33166, 33167, 33172, 33195); unlocking from any covered row releases the whole street. A **building ban** is one house number on one street anywhere in the state — every apartment, suite, mailbox, forwarding-account number, business and recipient — one click beside the street button; keep both, and the building stays banned if the street is later released. New place-key scopes `building:CC|ST|number|street`, `streetzip:CC|ZIP|street` and `streetcounty:CC|ST|county|street` join `street:` in the blocklist, the API (`POST /blocklist` with `type: place`) and the destination-lock form.
- **New: address validation for destination locks (Google Address Validation API).** The typed city and ZIP are the weakest part of any geography rule, so with an API key every checkout that could meet a street or building lock is first resolved to its physical destination — standardized street, delivery ZIP, USPS city and county from CASS data — and matched on that as well as the typed text. An address the service cannot confirm, on a road locked anywhere in the state, is stopped for correction with a configurable message (never approved on text nobody confirmed; switchable to allow). Cached per address for thirty days, a failed lookup for ten minutes; no request is ever made on a store with no street or building locks, nor when the typed text already refuses the order; an outage falls back to typed-text matching and cannot block a checkout. Staff see what the address validated to on the refusal and on Checkout Locks. *Settings → Address validation for destination locks.*
- **Changed: the velocity rule is a count in a window, and its default action is a hold.** It was never points — the score's 7-day and 30-day rules are separate and still are — but the window was fixed at thirty days and the only actions were to refuse the checkout or restrict gateways. Now *Settings → Checkout velocity rule* reads as one sentence: act once the customer already has **N** orders in the last **X days or hours**. The default action is **hold**: the checkout completes, the order is set On-Hold with a note saying how many earlier orders the customer had in the window, it stays On-Hold when the payment lands (the gateway's payment-complete would otherwise move it to processing), the customer is listed on Checkout Locks under the velocity rule, and your hold (or alert) recipients are emailed. Nothing ships until a person looks; Verify or Allow releases the customer's *paid* held orders to processing (unpaid ones stay). Refuse and restrict remain as before. Existing installs that chose block or restrict keep their choice; the window stays thirty days until changed. The API's `POST /risk/evaluate` reports `decision: "hold"` with `reason: "velocity"` for the new action, and the settings routes carry the window.
- **New: area bans — a radius around a location.** Every address page now shows where the geocoder puts the address (corrected street, city, ZIP, county, coordinates, a map link) and offers **Ban this area**: every checkout whose address resolves to a point within the radius is refused, whatever unit, recipient, ZIP or city name was typed. Nested bans report the tightest. The destination-lock form takes an address and a radius too. Key `geo:<lat>|<lng>|<metres>` on the blocklist and in `POST /blocklist`. Area bans never cascade and never auto-extend.
- **New: a free geocoder, and a choice of three.** Address resolution now has providers: the **US Census Bureau geocoder** — free, no key, no quota for interactive use, US addresses; returns the corrected street, city, state and ZIP, the county, and a point on the block — is the default; **Google Address Validation** (worldwide, USPS delivery-point confirmation, a rooftop point; paid beyond the monthly credit) and **OpenStreetMap Nominatim** (free, worldwide; its policy's one request per second is enforced, and the contact it asks for travels in the User-Agent) are the alternatives. Street and building locks match the resolved destination when validation is on; area bans resolve the address whether or not it is, because they cannot work any other way; no request is made without a lock to resolve against; an address the Census geocoder cannot find is "unconfirmed", not "unavailable", so the correction stop applies to it.
- Harnesses: `sim-door` rebuilt around the incident, 53 → 138 checks — the five rows from the Addresses page as one key and the thirty rewrites that would have followed, precision (neighbours, "66th Ave", the other directionals, Georgia, Canada), UK and Canadian anchoring, the incident replayed on the schema with the refusal described in words, cluster/profile reversibility, the full upgrade from a v1.25.1 store (old-shape door entries carried onto the new key under their cards, the ungrouped legacy row, the street lock realigned on entry and card, index rows re-derived, the dead order stamped, the cron armed and idle when done), a Block clicked mid-backfill planting the current key and not the stale one, the velocity anchors with each switch on and off, and the escalation table. `sim-street` 195 checks (seven expectations moved to the aligned forms, the new candidate keys); new `sim-geoban`, 147 checks — the keys, the building ban against the five rows and nine more rewrites with the neighbours open, the street ban with a history-filled boundary and the same road in Broward and Gainesville open, the validated destination catching a city and ZIP both outside the typed boundary (and served from cache the second time), a county-only boundary reached through validation alone, the correction stop for an unverifiable address on a locked road (and not on an unlocked one, and not when set to allow, and not with validation off), the outage failing open and remembered, no request without a street-level lock, unlocking from a boundary row, both bans together, the Census geocoder resolving Medley 33178 to Miami / 33166 / Miami-Dade with a point and recording the corrected city and ZIP (and Hialeah to nothing, hence the correction stop), Nominatim with the policy contact in its User-Agent, the area ban refusing the building under a made-up city and ZIP, the neighbour 150 m away and the corner 400 m away while downtown Miami, Fort Lauderdale and an unlocatable address stay open, nested circles reporting the tightest, the geocoder consulted only once an area ban exists and even with validation off, the outage failing open, and the velocity rule's window arithmetic (48 hours, 24 hours, 2 hours), the count-in-window lock, and Verify releasing the paid held order and not the unpaid one; all other harnesses unchanged and passing.

Database version 21: adds an indexed `geo_scheme` column to the order index. Drop-in update; the background job does the rest.

**1.30.0**
- **Fix: the money saved by stopping a customer who shares a credential with a blocked identity was underestimated.** The savings ledger recorded only the customer who was stopped, so the Financial Protection estimate weighed a $571.93 order shipped to a charged-back fraudster's address exactly as it would a first-time stranger's: no history, the store's overall chargeback rate (a few percent), and nothing at all under the conservative figure — even though the shared address was the whole reason the order was stopped. Every ledger row now names **whom the stop was tied to** — the blocked identity behind the shared shipping address, phone or account the linked rule found, or behind the blocklist entry that matched — and the estimate weighs the stop by the settled orders and chargebacks of that identity together with the customer's own. For that order the conservative figure goes from $0 to the full amount at the blocked customer's own rate, and the expected figure rises accordingly; the by-customer table shows "Own" and "Tied to" history side by side, the Dashboard's recent stops say whom each was tied to, and the API carries `linked_to`, `tied_settled`, `tied_chargebacks` and `basis_*` on every row. Rows the rule wrote before this release learn whom they were tied to from the Auto-Cancelled ledger on update.
- **Fix: a stop could miss the ledger altogether.** A post-creation backstop that met an order already cancelled by a sibling hook recorded nothing for it; it now counts the money (once per order, whatever else happens to it). A re-fired checkout hook that finds the auto-cancel stamp but no savings row repairs the row from the stamp. And an order a **person** cancels or refunds in wp-admin (or over the API) while it is on the blocklist — or, with the linked rule on, linked to a blocked identity — is now counted at its total and marked "by staff" on the Dashboard, on Financial Protection (with its own tally) and in the API (`stopped_by`, `staff_stopped`, `stopped_by_staff`), because the control surfaced the link and staff acted on it. Cron, gateway webhooks and customers cancelling their own orders are never "staff"; an order recorded as charged back is a loss, never a stop, and marking a staff-counted order as charged back withdraws its row. A cancelled or refunded order that qualifies but was never counted carries a **Count as fraud prevented** button in its Fraud Shield box, which counts a sufficient link whether or not the linked rule is switched on — for the orders that were stopped before this release.
- Harnesses: `sim-linked` grows from 218 to 266 checks (the ledger names Z behind W's cancel and behind every retry, the repair on a re-fire, a backstop meeting an already-cancelled order, a blocklist refusal naming the person behind the phone, staff stops in every shape — refund, customer self-cancel, charged-back, unrelated, already counted by the rule, rule off with the manual count — and the chargeback withdrawal); `sim-insights` grows from 138 to 170 (the $571.93 order tied to a two-chargeback identity to the cent, a refusal tied to a one-of-two identity, a staff stop tied to a clean one, the totals, the ranking, the decoded recent rows and the idempotent backfill).
- **New: the API and webhooks now cover every feature and datapoint added since the API shipped in 1.24.** Forty-five new routes (65 in all) and ten new webhook topics (23 in all), so an external platform can read and act on everything the admin screens show. Full reference, with every request and response shape, in [`INTEGRATION.md`](INTEGRATION.md).
- **Registries and reports.** `GET /addresses` and `GET /ips` are the Addresses and IPs registries (every address or IP with its aggregates, searchable and sortable; `orderby=risk` is the Dashboard's risky-clusters table, and each row says whether it is blocked or verified); their detail routes now carry the address text, the orders at the location, and the payment methods, device signatures and coupon codes seen there. `GET /reports/repeat-customers` and `GET /reports/new-customers` are the two reports. `GET /geography/{level}/customers` is the Geography drill-down. `GET /scan` is the Dashboard's scan panel, read-only.
- **Orders.** `GET /orders` filters on `user_id`, `ip`, `address_id`, `phone`, `status`, `gateway`, `country`, `state`, `city`, `postcode`, `min_score` and `linked_cancelled`; every order row now carries the normalized phone, shipping method, coupons, a device id (the user-agent hash), the loose and door address hashes, the billing address hash, the street, the recorded recovery and the indexed-at time. `GET /orders/{id}` adds the linked-cancel record, the cardholder verdict, the ID session the order was placed under, and which of its credentials are on the blocklist right now — since when, by whom, under which card, and the identities behind them. New writes: `POST`/`DELETE /orders/{id}/chargeback`, `POST`/`DELETE /orders/{id}/recovery` and `POST /orders/{id}/card-check` (scope `write:orders`), and reads of the Card Checks page (`GET /card-checks`) and the Auto-Cancelled ledger (`GET /linked-cancels`, `/linked-cancels/{id}`).
- **Customers.** `GET /customers` lists every identity (the Profiles page); `GET /customers/{ident}` is now the full dossier — every phone, IP, address, name, gateway, device and coupon with counts, the linked identities, the blocklist rows holding any of them with provenance, the refusals at checkout with their evidence, the latest auto-cancel, the latest card check, a `trust` block with the auto-verification verdict and its numbers, the ID-verification record and any open demand, the restriction with its expiry, and the hold — and an `analysis` block with the profile page's ordering cadence and time-of-day pattern, the IP-geolocation-versus-entered-country checks, billing/shipping consistency and, for an account, whether the order details match the account. New: `GET /addresses/{hash}` and `GET /ips/{ip}` (the registries' detail pages), `GET /restrictions`, `GET /holds`, the Checkout Locks ledger (`GET /checkout-locks`, `/checkout-locks/{id}`, `DELETE` to dismiss), and under the new `write:customers` scope `POST`/`DELETE /customers/{ident}/restriction` and `DELETE /customers/{ident}/hold`.
- **Blocklist.** `GET /blocklist` takes `type` and `search` (the Blocklist page's search, flattened to credentials) and every entry now carries its message, who added it and the block cards that own it. `POST /blocklist` accepts `cascade: true` for an address, IP or email — the admin's cluster and profile blocks. New: bulk removal (`DELETE /blocklist` by row ids, by IP list, every IP, or the legacy ungrouped rows), the per-credential and per-card checkout messages (`PATCH /blocklist/{id}`, `PATCH /blocklist/actions/{id}`), and the block cards themselves — `GET /blocklist/actions`, `GET /blocklist/actions/{id}`, `DELETE` to reverse one (with `release_shared`), `POST .../resync` to re-run its cascade.
- **Trust and ID.** `POST /verified` with a `user_id` now does exactly what the admin Verify button does (flag, credential mirror, released Checkout Locks rows, lifted hold and its paid held order), through one shared path. New: the email allowlist (`GET`/`POST`/`DELETE /allowlist`), the ID-demand queue (`GET /idv/requests`), withdrawing a demand (`DELETE /idv/require`), accepting a name mismatch (`POST /idv/sessions/{id}/accept`) and revoking a verification (`DELETE /idv/verified`). Sessions carry their row `id` and every stored column: the name on the document against the name expected, the document's state, where the customer was while verifying, and the vendor's risk warnings.
- **Insights** (new scope `read:insights`): `GET /insights/prevented` (the Dashboard's Fraud-prevented panel: totals, by control, monthly, recent), `GET /insights/financial` (the whole Financial Protection report with its assumptions and calibration), `GET /network` and `GET /network/{ident}` (the Customer Network, connections with evidence, recency, standing and risk contribution, second-hop paths), `GET /geography/{state|city|zip|street}` (the Geography rollups, each row saying whether the place is locked).
- **Diagnostics.** `GET /ping` reports which controls are on and the scopes the store offers; `GET /stats` adds block cards, verified credentials, the allowlist, the Auto-Cancelled totals, the Fraud-prevented headline (all time and last 30 days) and card checks; new `GET /settings` returns the mode, level cut-offs, rule weights and thresholds, every control's numbers and the payment/shipping classification — never a secret or a message. `POST /risk/evaluate` adds `state.blocklist_evidence`.
- **Webhooks.** New topics: `order.cancelled` (the linked rule cancelled an order — the credential types and block cards, never the blocked party's identifiers), `order.chargeback` (marked or cleared, from the button, the API, the Charged back status or a counted refund — one event per flip), `order.recovery`, `customer.unblocked` (the counterpart of `customer.blocked`), `blocklist.extended` (an existing card grew — an auto-extension from a matching new order, or a re-sync), `blocklist.removed` (one event per removal call, with counts by type and a sample), `hold.applied` / `hold.released`, `idv.requested` / `idv.request_cleared`. `customer.verified` / `customer.unverified` gain `scope: "allowlist"`. `block_card` and `credential` join the fields hashed on digest subscriptions. The admin meta-box chargeback and recovery buttons and the Verify / Restrict actions now share one code path with the API, so every surface raises the same events.
- Validated with a 124-check DB-less harness over every new route and shaper plus the new emit sites, the existing API harness (162), gateway (81), IDV (44) harnesses and the packaged-boot probe.

Database version 20: adds `linked_to` and `stopped_by` to the `fs_prevented` savings ledger (added automatically on first load) and fills `linked_to` for existing auto-cancel rows from the Auto-Cancelled ledger, once. Drop-in update. New scopes appear on the API & Webhooks screen's checklists automatically; existing keys keep their scopes and must be re-minted (or a new key paired) to gain the new ones.

**1.29.0**
- **New: a refused checkout now says exactly what matched — and whom it was matched to.** When the blocklist (or the linked-to-blocklist rule) turns a customer away *before* an order exists, Checkout Locks used to record only the credential types ("Blocklist (email, ip)"), so there was nowhere to see which email, which IP, or which blocked person was involved. Every refusal now carries its evidence: each blocked credential as the customer typed it (the shipping address spelled out, with how it matched — exact, same address line and ZIP, or same door), blocked since when and by whom, the block card it belongs to, the reason recorded at the time, and **the identities behind the credential** — the blocked customer this person was matched to, by name, email and account, each linking to their profile, with a BLOCKED pill when they are themselves on the list; the customer being refused is never listed against their own match. Each line links to the Blocklist search for that credential. The same evidence is recorded for the Store API draft stage, the checkout-page gate (describing the saved address that matched), and the post-creation backstop.
- **Where it shows.** On **Checkout Locks**, under the "Blocked by" column of every blocklist and linked row. On the customer's **Profile** and **Account view**, a new *Refused at checkout* panel lists every refusal for that identity — including rows staff have since dismissed — with the same detail, so the answer is still there after the queue is cleared.
- A retry that brings fresh evidence replaces what is stored (the latest attempt is what staff are looking at); a retry through a control that keeps none leaves the earlier evidence in place. Webhook payloads are unchanged — the evidence is never sent off-site.
- **Fix: the profile page's "Linked customers" list was empty on MariaDB hosts.** Its query ordered by an arithmetic of two aggregate aliases, which MySQL tolerates and MariaDB rejects ("Reference 'shared_ips' not supported"), so the list silently showed nobody since 1.3.0 on those servers. The ordering now happens over a derived table and works on both engines.
- Harness: `sim-linked` grows from 188 to 218 checks — the ledger column, a real classic-checkout refusal on a blocked phone (label, provenance, card, reason, the person behind it with account id and blocked flag, the search term), evidence kept across an evidence-less retry and replaced by a fresh one, an address match described as typed with everyone behind the door (the blocked customer first, the refused customer never), a destination lock naming nobody, and the linked rule's refusals carrying their links.

Database version 19: adds an `evidence` column to `fs_checkout_blocks` (added automatically on first load). No other schema changes; drop-in update.

**1.28.0**
- **New: select credentials on the Blocklist page and remove them together.** Every credential row now carries a checkbox, every block's table a select-all box, and a bar above the cards offers **Select all on this page**, **Select all IPs on this page**, **Clear selection** and **Remove selected**, with a live count and a confirmation. Removing a selection is the same removal as a row's own Remove button, whatever the type: each credential leaves every block that shares it, a block left with nothing that existed only for an IP goes with it, and profile, address and destination blocks keep whatever remains. The notice says how many went and how many of them were IP addresses.
- **New: a Type filter beside the search.** Pick *IP address* (or email, phone, address, account, destination lock) to see only those credentials — with or without a search term — so "IP address → Select all → Remove selected" is exactly "every IP on this page", and the results bar's own button then reads "Remove ALL … IP addresses" across every page. A block appears under a type filter only through a credential of that type; its name alone is not enough.
- Harness: `sim-blocklist` grows to 129 checks — the type filter on its own, combined with a search, and against the wrong type; and the checkbox removal with a mixed, duplicated and junk selection.

No database change. Drop-in update.

**1.27.0**
- **New: search on the Blocklist page.** One box finds blocks by name and credentials by value or reason — an email, a phone, an IP or IP prefix, an account id, an address as it was blocked (the block's name carries it), or the reason typed at the time. Emails and phones are matched in their normalized form as well as as typed, so a gmail alias or a formatted number finds the stored credential. A block that matches by its own name comes back whole; one that matches only through a credential shows just the matching credentials with an "N of M" note, so a search for one IP never unrolls a 200-credential profile. Ungrouped legacy credentials that match get their own card. Results page like the full list.
- **New: bulk-remove IP addresses.** A panel on the Blocklist page takes a pasted list of IPs (one per line, or separated by commas or spaces) and removes the ones that are on the list, reporting how many were removed, how many were not on the list and how many lines were not IP addresses; a second button removes every IP on the list; and on any search, a button removes the IP credentials that search matches. Only IP credentials are ever touched: a block that existed solely for an IP (an IP-address card, or a manual "ip:" entry's card) is dropped with it, while profile, address and destination blocks keep their other credentials — including anyone an IP block swept in, whom "Remove all (unblock)" on that card still releases.
- **New: blocklist provenance on the order screen and the Orders list.** When a customer's credentials are on the blocklist, the order's Fraud Shield box now lists each blocked credential — the email, phone, IP or account itself, the shipping address as typed on the order with how it matched (exact, same address line and ZIP, or same door), or the destination lock — with the date and time it was blocked, who blocked it (a staff member by name, or *Fraud Shield (automatic)* for a cascade or auto-extension), the block it belongs to and its author when different, and the reason recorded at the time; each line links to the Blocklist search for that credential. The Fraud Shield column on WooCommerce → Orders shows an **On blocklist** badge with the credential types, the earliest block date and who blocked them, beside the existing Auto-cancelled badge.
- `FS_Blocklist::match()` now also returns each entry's `added_by` and `created_at`; block-card titles are written without angle brackets when they reach an order note.
- Harness: `sim-blocklist` grows from 59 to 114 checks — needles, search by name / credential / reason / prefix, the "N of M" trimming, all three bulk-removal paths and their card pruning, provenance, actor names and the readable descriptions.

No database change. Drop-in update.

**1.26.2**
- **Projections count months the way people do.** The annual-protection forecast rounded a customer's active window up to whole months (`ceil(days / 30.44)`), so two chargebacks exactly three calendar months apart — June 22 to September 22, 92 days — read as four months and projected ×3 instead of the ×4 the worked example promises, while 90 days read as three. The window is now whole calendar months plus the leftover days as a fraction, with no rounding step: three months apart is ×4 to the dollar whatever the day count, one extra day never moves a projection by a whole month, and the page and CSV show months to a decimal. Confidence grades are measured in days (90 for medium, 365 for high) so "three months" reads the same however the calendar fell. The minimum-window floor is unchanged.
- **The Dashboard no longer counts a page view as money saved.** A blocked customer who merely opened the checkout page was turned away there and their cart added to "Fraud prevented" (once per day), without ever pressing Place Order. The page gate still turns them away and still records them on Checkout Locks, but only a submitted checkout now reaches the savings ledger. The assumptions panel says so.
- **The alert email carries the linked-cancel outcome.** `woocommerce_new_order` fires while a checkout is still creating the order, before the linked-identity rule decides anything; for an order that already scored at the alert level, that first email went out without the "linked to the blocklist — cancelled" line and, being once-per-order, silenced the fuller one. For orders a customer checkout creates, the alert now waits for the checkout handler, which knows the outcome; if that handler never runs (a third-party flow that skips the action), the alert is still sent at the end of the request, so none is lost. Orders created in wp-admin, over REST or by renewals are alerted on the spot as before.
- **Settings copy names the address match precisely.** The linked rule's address link was described as "same street and ZIP"; it is the same first address line and ZIP (the house number included, the unit ignored), or the same door with the unit rewritten — never a street alone. README and Settings now say so.
- **Number inputs read in every locale.** The chargeback-fee setting and the order screen's recovery amount rendered their values with the store's decimal separator, which a browser number field rejects in comma-decimal locales (the field showed empty). Both now render a plain decimal.
- Harnesses: `sim-linked` (188 checks) adds the deferred-alert flow in all three shapes — checkout handler reached, never reached, and non-checkout orders; `sim-insights` (138 checks) adds the exact-three-months example and the calendar-month span function.

No database change. Drop-in update.

**1.26.1**
- **The order note names the linked identity again.** The cancellation note wrote the identity behind a shared credential as `Jane Doe <jane@example.com>`; WooCommerce renders order notes through `wp_kses_post()`, which reads the angle-bracketed email as an HTML tag and strips it, so the note read "blocked Jane Doe ." while the Fraud Shield box beside it showed the full identity. Identities are now written as `Jane Doe (jane@example.com, account #4411)` everywhere — note, ledger, Orders column, alert email, CSV — and survive every renderer.
- **A retry no longer doubles the "Fraud prevented" figure.** After a linked cancel, the same customer pressing Place Order again the same day was recorded as a second, refused checkout with the same cart, so one purchase intent counted twice in the Dashboard headline and on the Financial Protection page (six $120 carts read as $1,200 saved). A same-day retry now bumps the cancelled order's own ledger row — attempts go up, the largest cart is kept, no amount is added. A return on a later day is still a fresh, once-per-day refusal, like every other rule. The update folds any such double rows a store already recorded into the order's row.
- **The chargeback rate counts each order once.** Historical losses computed the rate against paid orders *plus* charged-back orders, but an order flagged with "Mark as chargeback" keeps its paid status and so sat in both, understating the rate and making panel 1 disagree with the calibration table below it (12.00% against 12.50% on the same data). Settled orders are now paid *or* charged back, counted once, matching the calibration.
- Harnesses updated: `sim-linked` (172 checks) covers the kses-safe label, same-day and later-day retries, and the fold-in migration; `sim-insights` (127 checks) covers a paid-status order flagged as charged back and asserts the two panels agree.

Database version 18: no schema change; a one-time, idempotent pass folds duplicated same-day retry rows in `fs_prevented` into their cancelled order's row. Drop-in update.

**1.26.0**
- **New: auto-cancel orders linked to the blocklist.** The blocklist refuses a customer whose *own* email, phone, IP, account or address is blocked. This optional rule (*Settings → Cancel orders linked to the blocklist*, off by default) reaches one step further: a customer who is **not** blocked but **shares a credential with a blocked identity** — the same shipping address (exact, same street and ZIP, or the same door with the unit rewritten), phone or account as someone on the blocklist, on this order or on any earlier order they placed — has the order cancelled the moment it is created, before any payment is taken, and reads a configurable message. Two kinds of link are found: a credential the customer used on an *earlier* order that is itself blocked (they typed something new today, but their history did not), and another identity on a shared address, phone or IP that is blocked by email, account or phone — the case no cascade ever reached: a manually added email, an imported list, a blocked customer's newer credentials. Destination locks (a blocked city, ZIP or street) never form a link; those block places, not people.
- **A shared address is enough; a shared IP never is.** A shared shipping address, phone, account or email cancels on its own. An IP shared with a blocked identity is recorded as evidence but **never cancels an order by itself**: it counts only alongside one of the other shared credentials, because carrier and campus networks put strangers behind one address. Settings says so on the IP checkbox and warns when nothing that can act alone is ticked; Test Checkout and `POST /risk/evaluate` show an IP-only link as evidence that was not acted on.
- **Every cancellation names who it leads to.** Behind each shared credential the rule resolves the **accounts and profiles** that used it — name, email, account id, flagged when they are themselves blocked — from the order index (falling back to the WordPress account and the block card's own target), never listing the customer themselves. They appear in the order note, in the order screen's Fraud Shield box (with links to the profile and the account view), in the Fraud Shield column on WooCommerce → Orders, on the Auto-Cancelled page (as a *Linked to:* line per credential, each name linking to its profile), in the CSV export (`linked_names`, `linked_emails`, `linked_accounts`), and under `identities` on every link the API returns.
- **Where the cancelled orders show.** WooCommerce → Orders gains a **Fraud Shield** column (legacy and HPOS lists alike) with an **Auto-cancelled** badge and the reason on every order this rule cancelled, linking through to the new **Auto-Cancelled** page: every such order with its current status, the customer, the total, exactly which credential is shared, which block card it leads to and who is behind it, how many retries were refused, and one-click **Block** (turn the link into a verdict) or **Verify** (exempt the customer), plus search and a per-link CSV export. The order itself carries a note naming the cause and the same evidence in meta; the alert email (when enabled) reports it; and the customer appears on Checkout Locks under *Linked to the blocklist* with the usual Verify / Allow / Dismiss.
- **One cancelled order per customer, not a pile.** The first attempt becomes the cancelled order on record; while the link stands, every later attempt from the same identity is refused at the gate — classic validation and the blocks/Store API draft stage — with no new order, and counted as an attempt on the first. Switching IPs does not launder the link: the cancelled order is part of the customer's history. Removing the block, or verifying the customer, reopens checkout at once. The classic checkout stops at the hook that fires before payment (an exception WooCommerce renders as the checkout error), and the Store API path aborts the same way a blocklist match does, so a cancelled order is never charged.
- **New: the Fraud prevented dashboard.** The Dashboard opens with the money that did not change hands because a control stopped the checkout — orders cancelled at creation and attempts refused before an order existed, at the grand total the customer would have paid: saved all-time and in the last 30 days, fraudulent checkouts stopped, attempts turned away, a 12-month chart, a breakdown by control with a 30-day / 90-day / 12-month / all-time switch, and the most recent stops with links to the order or profile. The headline counts **verdicts** only — the blocklist, the linked-identity rule, and a block-level risk score under Enforce. Money merely held back while a customer verifies — velocity lock, new-account hold, a requested ID check, the high-value ID rule — is shown as *held pending verification* and never added to the headline; payment-method refusals are not counted at all, since the customer pays another way. Each cancelled order counts once, and a customer retrying a refused checkout counts once per rule per day (the largest cart seen), so a bot cannot inflate the figure. Counting starts with this version.
- **New: the Financial Protection report.** A page that turns what the store has actually recorded into four figures that are never allowed to blur into one another. *Historical losses* (observed): orders recorded as charged back, at the reversed amount, net of **recoveries** — a new **Record recovery** action on any charged-back order's Fraud Shield box stores the amount a dispute won or a partial reversal returned — plus your processor's per-dispute fee once you enter it, with chargeback rates by count and by value and a by-customer table. *Blocked transaction value* (observed): the value of the purchase attempts a verdict stopped, stated plainly as **not** money saved: retries count once per rule per day, cancelled orders staff later approved are excluded and reported as excluded, and money the verification controls held back is shown separately and never counted. *Estimated losses prevented* (estimate): each stopped customer's own chargeback rate blended with the rate of the closest class this store has outcomes for — blocked customers, then HIGH/BLOCK-scored orders, then every settled order — as if the class rate had been seen on three extra orders, so one chargeback on one order is not read as certainty; given as a *conservative* figure (repeat offenders only, at their own rate) and an *expected* figure, with the calibration table, the share of blocked value that could not be calibrated, and a confidence grade from the amount of outcome evidence. *Projected annual protection* (forecast): what the customers now on the blocklist were costing per year while active — a customer who charged back $10,000 across two orders three months apart projects to $40,000 a year — annualized over their active window with a configurable floor (90 days by default, so a burst is multiplied at most ×4), net of recoveries, with a confidence grade per customer (12+ months and 3+ chargebacks: high; 3–11 months and 2+: medium; a single chargeback or under 3 months: low), an observed trailing-12-month anchor beside it, and a CSV carrying every input to the arithmetic. Every section names its reporting period; a panel lists the assumptions and data limitations; missing data — an unset fee, no recorded recoveries, a store with no chargebacks to calibrate against — is shown as *unavailable*, never as zero. Three assumption settings under *Settings → Financial Protection assumptions*: the chargeback fee, the loss ratio (the share of a reversed order counted as lost in estimates and projections; recorded losses are always the full amount) and the minimum annualization window. Cost of goods, shipping and staff time are not tracked and are not estimated.
- **New: the Customer Network.** A risk-ranked view of every customer identity — blocked first, then recorded chargebacks, then the highest risk score reached — with how many other customers share an identifier with them and the strongest identifier they share with someone on the blocklist. Exploring a customer shows their identifiers on record, every blocklist entry naming them or something they used (with the block card and the reason), and each connected customer with **what matches** — the same account, a phone number, an identical shipping address, the same door with the unit rewritten, the same building or first address line, the same street, an IP, a browser signature — the **evidence** (how many orders on each side used it, when, with links to the orders), the connected customer's **standing** (blocked, verified, chargebacks, risk) and a **contribution to risk** that scales with the identifier: a shared street, IP or browser is a weak association, labelled as such, with the note that it does not justify blocking on its own. Connections that are not blocked but lead on to a blocked customer over a strong identifier show the path. Actions are the existing Block / Unblock toggle, Verify and Request ID — explicit, logged, one customer at a time; the page never classifies or blocks a connected group. Both pages export to CSV.
- **Configurable and careful.** Exempt from the linked rule: the staff Verified flag, verified credentials, allowlisted emails, completed ID checks and automatically verified established customers, because the link is an inference, not a human verdict. `POST /risk/evaluate` reports `decision: block`, `reason: linked_blocklist` when the links suffice, with every link (and its identities) under `state.blocklist_links` and `state.linked_sufficient`; `checkout.blocked` webhooks carry `kind: linked`. Orders created outside a customer checkout (admin, REST, renewals) are indexed as before and never cancelled by this rule.
- Validated with a 155-check harness (`tools/sim-linked.php`) on the real schema: both degrees of link over cascaded, manual and imported blocks, the account anchor, same-building addresses, the sufficiency rule (address / phone alone cancel; an IP-only customer is linked but never cancelled or refused), the identities behind each link (name, account id, blocked flag; the customer never listed against their own history), non-links (strangers, a customer's own rows, destination locks), the credential-type filter, summary text, the real FS_Live cancel (note, meta, ledger, Checkout Locks row, idempotence under a re-fired hook, message fallback), the gate's repeat refusals and attempt counting, every exemption, release on unblock, the ledger's search, paging and statistics, and the savings ledger (one row per order, retries coalesced with the largest cart kept, verdicts apart from verification holds, zero amounts ignored, monthly series, idempotent upgrade backfill). A second 119-check harness (`tools/sim-insights.php`) validates the Financial Protection arithmetic and the Customer Network on the real schema: recorded losses net of a recorded recovery with fees unavailable until configured, the later-approved exclusion, the calibration fallback from a too-small blocked class to the store class, the shrinkage estimator to the cent, unavailable-not-zero when there is nothing to calibrate against, the $10,000-over-three-months customer projecting to exactly $40,000 with medium confidence (and $20,000 under a six-month floor), and the network's ranking, strength grading, evidence, recency, second-hop paths, weak-link ceiling, block-card listing and the guarantee that exploring blocks nobody. The existing harnesses pass unchanged (192 + 81 + 59 + 53 + 44 checks plus the packaged-boot probe in all three contexts; the API harness's single failing check — a zero-padded IP allow-list entry, under PHP 8.5 — fails identically before this change and is unrelated).

Database version 17: adds the `fs_linked_cancels` ledger and the `fs_prevented` savings ledger (both created automatically on first load), and a `recovered` column on the order index for recorded chargeback recoveries. Drop-in update.

**1.25.2**
- **Fix: abandoned ID-verification sessions are handled gracefully at every surface they touch.** Three gaps, one theme — a session the customer walked away from was treated as if it would finish any minute, forever:
  - **Resuming no longer hands out a dead session.** Didit expires sessions on its own clock, well inside Fraud Shield's 24-hour resume window. A customer who abandoned the popup and clicked *Resume verification* later was sent straight into the expired session — a Didit error page, with nothing on our side recovering. A resume of a session older than 15 minutes now asks Didit once whether it is still alive (behind the same 10-second per-session lock the status poll uses): still alive → resumed exactly as before, at no API cost for fresh sessions; expired or abandoned → recorded as such and a **new** session opens instead; completed while they were away → no popup, a friendly "already verified" note; Didit unreachable → the old resume behaviour, rather than burning a credit blind.
  - **An expired session no longer polls forever.** The status poll answered `none` for an expired session, which the checkout script read as "keep waiting" — an abandoned-then-returning customer sat with a spinner and a disabled button indefinitely. The poll now answers `expired` with an explanation; the script stops polling, tells the customer their session expired, and re-renders a fresh card whose button starts a clean new attempt.
  - **Staff see "Abandoned", not an eternal "In progress".** Nobody polls a session the customer walked away from, so nothing updated its row and the ID-verifications list showed it as in progress for weeks. In-flight sessions older than the 24-hour resume window now display — on the page and in the CSV export — as **Abandoned** (display-level; webhooks, polls, the resume probe, and the Sync tool still record real statuses when they arrive).
- Validated with 14 new checks in `tools/sim-idv-offer.php` (now 44): outcome labelling by age, the expired poll answer, fresh-resume with zero API calls, dead-session probe → new session (exactly two API calls), API-failure fallback to the old resume, and approved-while-away. All seven harnesses pass (192 + 162 + 81 + 59 + 53 + 44 checks plus the packaged-boot probe).

No database changes; drop-in update.

**1.25.1**
- **New: the door key — the third address hash, and the one a blocked customer cannot rewrite their way past.** The incident behind it: a blocked customer registered a new account and re-entered their blocked shipping address with the street lowercased and the unit written differently. The **exact** hash covers both address lines verbatim, so any unit rewrite moves it; the **loose** hash covers the whole first line + postcode, so a unit moved into or out of that line moves it too. Both missed, and the order went through. The door key is built from the house number + the street name + the postcode and **nothing else** — computed with the same parser street locks use, so case, punctuation, spacing, abbreviations ("Street"/"St"), ordinals ("1st Ave"/"First Ave"), ZIP+4 vs plain ZIP, a unit-letter suffix ("123-B"/"123 B"), and the unit itself — however written ("Apt 4", "#4", "Unit C", "2nd Floor"), on either address line, or omitted — all collapse to one key.
- **Planted automatically, enforced automatically.** Blocking a profile or an address cluster now sweeps the door key in alongside the exact and loose hashes (existing blocks pick it up with one **Re-sync** on their card, or by re-clicking Block), and every checkout — classic, blocks/Store API draft stage and post-creation backstop, `POST /risk/evaluate`, and imports — computes the door key from the typed address and matches it against the blocklist. No setting to enable; precision is guaranteed by construction (no house number, no postcode, or a PO box → no key, and the neighbours at other numbers never match).
- **Fix: "1st Ave" and "First Ave" now key to the same street.** The abbreviation map has always turned first/second/third into 1/2/3, but the suffixed forms 1st/2nd/3rd did not collapse, so the two spellings of one street produced two street keys (and would have produced two door keys). Aligned in the street parser — exactly those three; "5th Ave" keeps its readable form since no word counterpart exists to collide with. Historical exact/loose hashes are untouched.
- Validated with a 53-check harness (`tools/sim-door.php`) on the real schema: the incident replayed verbatim — including proof the old exact+loose pair let the rewrite through and the door key catches it — six rewrite variants, neighbour and cross-ZIP precision, cluster cascade and unblock reversibility, and the backfill giving pre-1.25.1 history its door keys (dead orders stamped out, v1.25.0-era street columns preserved and ordinals re-aligned). The street harness grew to 192 checks; all seven harnesses pass (192 + 162 + 81 + 59 + 53 + 30 checks plus the packaged-boot probe).

Database version 14: adds an indexed `ship_door_hash` column to the order index. The Geography page's **Backfill now** revisits every row (it re-selects on the new column) and fills the door key from each order — run it once after updating, then Re-sync any standing profile/address block cards so history's door keys join them. Street locks and all v1.25.0 features are unchanged. Drop-in update.

**1.25.0**
- **New: street locks — block a whole street, not just one door.** A street lock is a destination lock one level finer than a city: it refuses every checkout whose shipping address is on the locked street in the locked city, at any house number. The address a customer types is reduced to its street **name** before matching — the house number goes (including "123A", Queens-style "37-12", ranges, "123 1/2"), the unit goes ("Apt 4", "#4", "Ste 200-B", "Unit C", "2nd Floor", and a unit written first), and the usual abbreviation normalization applies — so "123 N. Main Street, Apt 4", "456 North Main St" and "789 N MAIN ST #2" all key to `n main st`. Directionals are deliberately kept: N Main St, S Main St and Main St are three different streets with three different address ranges. PO boxes, rural routes and bare numbers have no street and can never form a lock. The parser is built for US/Canada number-first addresses.
- **Three ways to lock a street.** The Geography page gains a **Streets** tab (orders, customers, average risk, chargebacks per street, with drill-down to everyone shipping there and the same one-click Lock as cities and ZIPs); every shipping address's detail page gains a **Block this street** button next to "Block this address + all credentials", whose confirm dialog says how many orders, customers and distinct addresses on the street it will cover; and the manual destination-lock form accepts a Street scope, typed with or without a house number.
- **Its own message.** A street lock tells the refused customer *"You have been blocked from checkout, please contact customer service."* unless you write a message at creation; like every block, the message is editable afterwards on its Blocklist card. When a street lock sits inside a city or ZIP lock, the street lock is the one reported, so its message is the one read — destination matches are now resolved most-specific-first (street, city, ZIP prefixes longest to shortest, state) instead of in whatever order the database returned them.
- **Destination-only, like every geo lock.** A street lock never cascades to the emails, phones, IPs or accounts of the people who ship there, and auto-extend leaves it alone; unlocking it releases exactly that street. Verified customers stay exempt. It applies on the classic checkout, the blocks/Store API checkout (draft stage and post-creation backstop), the checkout-page gate for logged-in customers with a saved address on the street, `POST /risk/evaluate`, and the Test Checkout page.
- **API.** `POST /blocklist` accepts `{"type":"place","value":"street:US|FL|miami|n main st"}` and creates the same reversible lock the admin screens do; imported place keys now tolerate a capitalised scope (`City:` / `Street:`) instead of being silently dropped.
- Validated with a 189-check harness (`tools/sim-street.php`): 85 parser checks covering house-number forms, every USPS unit designator before and after the street, ordinals and numbered highways, street names that contain a designator word (Front St, Key Largo Dr, Pier 39), PO boxes, and the directional rule; the key builders and their precedence order; and, on a real MariaDB with the real schema, indexing through `extract_signals()`, the Streets rollup, drill-down and `street_stats()`, the lock round-trip against unseen house numbers on the classic and Store API signal shapes, the four non-matches (other side of the street, plain Main St, same street in another city or state, PO box), street-inside-city message precedence, layered unlocking, custom messages, and the backfill across pre-1.25 rows, pre-1.8 rows, deleted orders and restored pre-1.25 backups.

Database version 13: adds an indexed `ship_street` column to the order index. Existing orders show a one-time **Backfill now** on the Geography page (the same resumable backfill that filled city/state/ZIP in 1.8) — street locks work at checkout immediately, the backfill is what populates the Streets tab and the address page's street button for older orders. Drop-in update.

**1.24.5**
- **Fix: activation fatal on sites running any Freemius-carrying plugin.** Versions 1.24.0–1.24.4 declared a class named `FS_API` — and the Freemius SDK, bundled inside a great many commercial plugins, declares `FS_Api`. PHP class names are case-insensitive, so on any site where one such plugin loads first (plugins load alphabetically), Fraud Shield died at load with `Cannot declare class FS_API, because the name is already in use` — before a single hook ran. That is why every install method fataled at activation while 1.23.2 (which predates the class) kept working. The class is now `FS_REST_API`; every other Fraud Shield class name was checked against the Freemius SDK's `FS_*` inventory and is collision-free. No functional changes — the REST API, webhooks, admin page, keys, and documentation are byte-for-byte the same behaviour under the new internal name.
- The release-validation boot probe (`tools/probe-boot.php`) now loads the packaged plugin NEXT TO Freemius SDK stand-in classes in frontend, admin, and AJAX contexts — it reproduces the 1.24.4 fatal verbatim against the old package and passes against this one, so a future name collision cannot ship again.
- All harnesses re-run and passing: API (162), gateways (81), IDV offer (30), blocklist (59 on real MariaDB).

No database changes; drop-in update. Upgrading from 1.23.2 applies the API tables (database v12) automatically on first load.

**1.24.4**
- **Fix: the ID-verification button now reliably appears for every unverified customer it applies to.** Four defects each hid it from a different group:
  - **The script only loaded when the page-load state already had something to show.** A fresh guest has no session email at load, so nothing showed — and after they typed the email of a restricted or velocity-locked customer, nothing *could* show: the blocks checkout surfaces the card only through this script's injection, and the classic checkout's fragment re-render brought the card back with a button that did nothing. The script is now always present on the checkout whenever ID verification is configured.
  - **The offer was frozen at page load.** A new `fs_idv_state` endpoint recomputes the card for the identity actually on the form; the script re-asks after checkout updates and email edits, then injects, replaces, or removes the card live (never disturbing an in-flight verification, identity pinned to the account for logged-in customers exactly like the status poll).
  - **Velocity-restricted guests were refused the offer outright** (`velocity_restrict_applies` required an account) even though enforcement velocity-locks guests by their checkout email and approvals are recorded against that same email — restricted payment methods with no exit. Guests are now judged by their checkout email, and the start endpoint's gate agrees with the button it offered.
  - **Customers on the per-account Restricted checkout list were never offered the button**, even though "an approved ID check ends restricted checkout immediately" (on by default) makes verification their documented way off the list. They now get the offer whenever that setting is on; with it off, the old veto stands (verification would not lift the restriction, so the button would be a false promise).
- Card injection on the blocks checkout retries while the page is still painting and falls back to the classic form when a theme's custom payment template dropped the PHP hook.
- Validated with a 30-check harness (`tools/sim-idv-offer.php`): the full offer matrix (anonymous guest, velocity-locked guest and account, hard-block vs restrict, per-account restricted with the release setting on and off, exempt accounts, empty allow-list), the state endpoint (offer/approved/pending/none, session id hand-off, identity pinning against a foreign typed email), and the always-on enqueue. The existing gateway (81), API (162), and blocklist (59) harnesses all still pass.

No database changes; drop-in update.

**1.24.3**
- **Fix: unblocking a profile now actually clears its credentials.** The Block/Unblock toggle removed a credential only when no *other* block card also owned it — a rule meant to protect deliberate staff blocks, but on a mature store address/IP cluster blocks and the auto-extend feature cross-own nearly every credential a profile has, so the unblock click visibly removed nothing and the button looked broken. Unblocking a profile now **releases the person**: their email, phones, IPs, addresses, and account leave the blocklist even where another block card also linked them. The one exception is a credential that is the *explicit target* of another standing block — a blocked address's own hash, a blocked IP's own address, a manually blocked value, an imported entry, a destination lock — which stays enforced in its own right, and the success notice now says how many were kept and why. The per-card **Remove all** button on the Blocklist page keeps its old careful semantics unchanged.
- **New: export all customer profiles, with an optional date range.** The Profiles page's export now sits beside From/To date pickers: leave them empty for the complete list, or set a window to export only profiles with orders inside it — with every column (orders, paid, spend, average order, chargebacks, IPs, addresses, max risk) computed *within* that window.
- **New: export a single customer's complete dossier.** Every profile detail page has an "Export this profile (CSV)" button — one row per order with the full picture (date, status, name, email, phone, IP, shipping address and geo, totals, payment method, shipping method, coupons, risk score/level/reasons, chargeback flag), with the same optional date range.
- Validated with a 53-check harness (`tools/sim-blocklist.php`) running the real schema on a real MariaDB: the exact toggle round-trip (block → unblock → zero rows, twice), auto-extended and legacy ungrouped credentials swept correctly, the cross-owned live-store state (profile released, the address block's own target and the other identities it swept both stay blocked), manual-block anchors surviving, the Blocklist-page default path unchanged, and the export range queries at every boundary.

No database changes; drop-in update.

**1.24.2**
- **Fix: the "seen at checkout" gateway record could permanently starve rare ids.** The record is capped, and once full it simply refused newcomers — so on a store whose payment rotator mints many ids, the table filled with the rotator's regulars and an id only some devices are ever offered (an Apple Pay wallet, which appears only for Safari on Apple hardware) could **never** be recorded, leaving Settings insisting the id does not exist no matter how many customers used it. A full table now evicts the id that has gone longest without being served (never one being served on the current render), tracked with day-granular last-seen clocks so a settled table still costs at most one database write per day. The old option shape migrates in place.
- **Fix: the low-value allowance warning claimed certainty it did not have.** Settings flagged any allowance id that was neither *enabled* in wp-admin nor already seen at checkout as "granting nothing". That verdict is right for an id known nowhere — but it also swept up ids that ARE registered by a payment plugin while reading *inactive* in wp-admin, which is exactly how wallet gateways behave: Apple Pay decides per device whether to appear, and enforcement filters the live payment list, so the allowance grants such an id automatically the moment the storefront actually offers it. The preview now separates the two cases: ids known nowhere keep the hard error, while registered-but-unobserved ids get an accurate warning explaining the wallet behaviour, how to confirm the id with one test order from a capable device, and that the notice clears itself the first time a checkout render offers the id.
- **Fix: the settings preview vouched for gateway ids a payment rotator strips from every live checkout.** The preview treated "enabled in wp-admin" as proof an id can reach a customer — but a rotator replaces the wp-admin gateways with its own entries on every render, and both the allow-list and the low-value allowance can only grant methods the render actually carries. So an allowance ticking the wp-admin ids (`nmi`, `nmi2`…) while customers are served the rotator's entries (`brw_nmi_wallet`…) granted nothing at checkout, while the preview kept promising it would — which reads exactly like "the allowed list overrides the allowance". It does not: both the display filter and the submit-time check take the strict union of the base list and the allowance under the threshold. The preview now detects the signature (ticked ids enabled in wp-admin that have never once been recorded at a live checkout, while other ids have), warns which ids they are with the instruction to tick their "seen at checkout" counterparts, and marks those ids inline in the persona lists rather than stating them as fact. Detection stays silent until the seen-recorder has data, so a fresh install is never accused of running a rotator.
- **Fix: the low-value allowance only ever applied to new customers — velocity-restricted customers and accounts on the Restricted checkout list got no allowance at all.** The setting reads as a store-wide rule about order size, but it was wired into the new-customer policy branch alone: the velocity-restrict policy hardcoded "no allowance", and a per-account restriction only carried an allowance if staff had configured one on that specific account (the bulk Restrict Checkout flow never does). So for exactly the customers most stores restrict, no gateway ever appeared under the threshold — however small the order. The global allowance now applies to **every** restricted checkout — new-customer, velocity-restricted, and per-account — with a per-account allowance still taking precedence on accounts where staff set one. Because the display filter and the submit-time check read the same policy object, both honour the change; the Settings description now states the scope.
- Validated with an 81-check harness (`tools/sim-gwseen.php`). Beyond the recorder mechanics (migration, write throttling, eviction under pressure), the diagnostic buckets, and rotator-substitution detection, it drives the REAL `restrict_gateways()` WooCommerce filter callback end to end for every customer class — anonymous guest, guest identified by session email (fresh and velocity-locked), new account, velocity-restricted, per-account restricted with and without an account-level allowance, and verified — at every boundary: one cent under the threshold grants, exactly at it does not, unknown amounts stand down, both amount bases are honoured, the high-value ID rule's collision band narrows exactly as its settings warning describes and leaves the allowance alone outside it, the submit-time gate agrees with the display in every class, and a configuration matching nothing still fails open rather than locking customers out.

No database changes; drop-in update.

**1.24.1**
- **Fix: `GET /events` paging was stuck on page one.** `next_cursor` came back as `0` on every call, because the cursor was derived by casting the wire-format event id — and `(int) "evt_3"` is `0`. Any client polling the event log received the same first page forever. The cursor is now the numeric row id, returned alongside the envelopes rather than parsed back out of them. Verified by walking a full log: the cursor advances `3 → 6 → 8`, three pages, every event seen exactly once, paging terminates.
- **Fix: `GET /customers/{ident}` returned database strings instead of JSON values.** The history block passed MySQL's output through untouched, so callers got `"orders": "2"` and `"average_order_value": "265.750000"` and had to cast everything themselves — and had to guess what the internal `bill_known` / `bill_match` columns meant. History is now typed (integers, 2-decimal floats, ISO 8601 dates) with the internal columns dropped and the remaining ones named for what they are (`distinct_ips`, `distinct_addresses`, `max_risk_score`).
- **Documentation is now generated from a live run, not written by hand.** [`API.md`](API.md) quotes real captured requests, responses, headers, signatures and webhook bodies — including one real payload for all thirteen topics — from an end-to-end execution against a real database and a real HTTP subscriber. Two documented values were wrong before and are corrected: `name_match` is `yes`/`no`/`n/a`/`""` (never `exact`), and the `/customers` history shape above.
- Adds `tools/live-api.php` and `tools/hook-receiver.php`: an end-to-end harness that installs the real schema, seeds and scores realistic orders with the real rules engine, dispatches every route through the real permission callbacks, and delivers real signed webhooks to a live local receiver that verifies each signature — 91 checks. Development tooling is now marked `export-ignore`, so it is version-controlled but never shipped inside the plugin zip.

No database changes; drop-in update over 1.24.0.

**1.24.0**
- **New: a complete API and webhook system, on a new *API & Webhooks* screen.** Another system can now ask this store what it knows about a customer, act on the answer, and be told when something changes. The centrepiece is `POST /risk/evaluate`: hand it an email, address, IP and amount and it returns the score, the reasons behind it, everything already known about that identity, and the decision checkout would reach right now (`allow` / `review` / `verify` / `restrict` / `block`) — no order required, with inputs normalized exactly as an order's would be so an ad-hoc lookup sees the same history. Also readable: customer profiles, indexed orders, ID verifications, aggregate stats and the event log; also writable: the blocklist (through the reversible block-action path, so staff can still undo it in one click), the verified-customer list, and a demand for an ID check.
- **Secure handshake for external platforms — no permanent secret changes hands.** An admin opens a pairing window and gets a single-use code; the platform (for example bonesfraud.com) posts it once to `/handshake` and receives its own scoped key plus a webhook secret, both shown only in that one response. The window closes the instant the code is used, expires on its own, and burns after five wrong guesses.
- **Twelve webhook topics**, covering risk verdicts, refused checkouts, verification changes, blocks, ID-check outcomes, the issuer's cardholder verdict, and restriction changes. Events are raised at the domain choke points, so a change made in wp-admin, at checkout, or over the API produces the same event. Deliveries are HMAC-SHA256 signed with the timestamp inside the signed material, carry a stable event id for idempotency, and retry with exponential backoff (a subscription failing 20 times consecutively is switched off and shown as failing). A store that would rather pull than expose an endpoint can poll `GET /events` for identical payloads.
- **Security.** Key secrets are 192 bits of CSPRNG randomness, shown exactly once and stored only as SHA-256 digests, so a stolen database yields no working keys. Per-key scopes with no wildcard; optional HMAC request signing that binds method, route, query, body digest, timestamp and nonce, with a ±5-minute window and a nonce cache that refuses replays; optional IP/CIDR pinning that fails closed; per-key expiry and rate limit; HTTPS required by default. Caller addresses come from the transport connection, not `X-Forwarded-For`, because an allow-list that trusts a caller-supplied header is decorative. Webhook URLs are refused unless they are HTTPS and resolve outside private, loopback, link-local and reserved space — a webhook target is an address this store connects to on demand, which is the shape of an SSRF. Authentication failures are uninformative to the caller and recorded in full, by public key id, in a capped on-screen security log. Payloads can carry stable digests instead of emails, names and IPs.
- **Performance: none of this touches checkout.** Routes register on `rest_api_init`, which never fires on a storefront page load. Event emission short-circuits on one autoloaded array read when no key and no subscription exists; recording an event is two indexed inserts; delivery is cron work on a 20-second budget and never happens inside the request that produced the event. Authentication is one indexed lookup plus one constant-time compare, usage accounting is throttled to one write per key per minute, rate limiting uses the object cache (never the database), every list endpoint is capped at 100 rows on indexed filters, and bodies over 256 KB are refused before being hashed.
- Full reference, including every endpoint, both signing schemes and verification code in PHP, Node and Python, is in [`API.md`](API.md).

Database version 12: adds four tables (credentials, event log, subscriptions, delivery attempts), all UTC-stamped so a retry queue cannot shift when a store changes timezone. Validated with a 162-check harness covering token parsing and verification, scope and IP enforcement, the signature and its replay/timestamp defences, rate limiting, pairing redemption, SSRF rejection, redaction, returned-row-id integrity, transport rules, audit throttling, date-filter timezone handling and the cheap-path guarantee. Drop-in update; the API ships switched on but refuses every request until a key exists.

**1.23.2**
- **Hotfix: survive third-party plugins re-firing the checkout action with fewer arguments.** The draft-stage handler introduced in 1.23.0 declared its `$request` parameter as required. WooCommerce core always passes it — but payment/checkout plugins that emulate the Woo pipeline are known to re-fire core actions with only the order, and PHP then dies at argument binding ("Too few arguments"): an HTTP 500 on the checkout request with no Fraud Shield message, before the 1.23.1 guards could even run. The parameter is now optional; an invocation without a request simply falls through the "not provably a place-order POST" guard and is skipped, with the post-creation backstop still enforcing at submission. No behavior change for normal WooCommerce checkouts.

**1.23.1**
- **Hotfix: checkout form updates are no longer blocked mid-fill (v1.23.0 regression).** The draft-stage enforcement added in 1.23.0 hooked a WooCommerce action believed to fire only at Place Order — but on current WooCommerce that same action also fires on the checkout **PUT/PATCH "data update" requests** that run while the customer is still filling the form: selecting a payment method, editing an address, adding order notes. A customer matching any rule (an outstanding ID-verification demand, the high-value threshold, a gateway restriction) was refused during those updates — the form wedged with an error before they ever pressed Place Order, and before they could clear the condition. Symptom: selecting a (hosted) payment method errors out and the checkout dies.
- The draft-stage check now runs **only on the genuine POST place-order submission of a checkout draft**. Form updates (PUT/PATCH), the pay-for-order route (an existing pending order), and any other invocation are skipped entirely and keep their pre-1.23.0 behavior via the post-creation backstop, which was never removed. Enforcement conditions, messages, and priority are unchanged.

Validated with the 1.23.0 simulation extended to 30 checks: every gate still rejects the POST place-order at draft stage, while PUT/PATCH updates, pay-for-order, and non-REST invocations pass through untouched even when a rule matches. No database changes; drop-in update — replaces 1.23.0.

**1.23.0**
- **Fix: the verification popup no longer turns into a second checkout — the source of duplicate orders.** Didit's hosted pages send a Cross-Origin-Opener-Policy header that cuts the popup's link back to the window that opened it. Our return page used that link to tell "popup" from "the checkout tab itself travelled to Didit", so a completed verification took the wrong branch and loaded the **full WooCommerce checkout inside the popup**, leaving the customer with two live checkouts sharing one cart — finish both (or race them) and the store gets two orders. The page that Didit returns to now learns which flow it is from a same-origin browser flag set before leaving for Didit (the severed link can't touch it): a popup announces completion, tries to close itself, and if the browser refuses shows "you can close this window" — it **never** loads checkout; only the genuine popup-blocked same-tab flow travels back to checkout.
- **The original checkout tab now hears about completion even when the popup's link back was severed.** Completion rides a cross-window browser signal (with the old direct message kept as an accelerator), so the "You have been verified" state and the unlocked payment methods appear in the checkout tab immediately instead of waiting for the next poll.
- **Approval no longer reloads the blocks checkout mid-payment.** The refresh that reveals unlocked payment methods now waits until no order submission is in flight (and gives a just-completed payment its redirect to the thank-you page) before reloading — previously an approval landing during payment processing could abandon that redirect, leave the cart intact, and invite a second payment: two paid orders for one purchase.
- **Blocked attempts on the blocks checkout no longer mint a cancelled order per retry.** The blocks checkout creates the order before any validation, so Fraud Shield could only reject by cancelling — and every retry (say, after completing the requested ID verification) produced another cancelled order in the admin list. Enforcement now runs while the order is still the session's reusable checkout draft (invisible in the admin): blocklist, demanded ID verification, velocity lock, new-account hold, gateway allow-list with the low-value allowance, high-value hard rule, and score policy — same checks, same order, same messages as the classic checkout — and a blocked attempt leaves **zero** orders behind no matter how many times it is retried. The old post-creation check stays as a backstop.

Validated with a 24-check simulation of the draft-stage enforcement (every gate rejects while the order is still a draft and the same draft is reused after verification), plus a syntax pass over the reworked checkout script. No database changes; drop-in update.

**1.22.1**
- **Sync from Didit and Rebuild attributions now finish in one click.** Both used to restore at most ~25 approved sessions per press (plus history rows — the "adds 50 or 60 then stops" behavior), leaving the admin to keep clicking until the backlog drained; a rebuild looked even worse, wiping everything and bringing back only the first batch. The batch caps are gone: the sync now runs on a **time budget** (up to four minutes per request) and keeps walking pages and restoring sessions until the whole Didit list is processed. On accounts small enough — which is nearly all of them — one click does everything.
- **Interrupted runs continue instead of starting over.** Progress checkpoints page by page: if a giant backlog or a slow host outlasts the budget (or the server kills the request outright), the notice says how many sessions remain and the next press of **Sync from Didit** picks up exactly where the run stopped — including after a rebuild, which plain syncs continue. A failed decision fetch is retried on the next run rather than lost.
- No database changes; drop-in update.

**1.22.0**
- **Fix: verifications can no longer be claimed by the wrong store — the real cause of "this customer shows as someone else".** The exports told the whole story: **two WordPress sites were sharing one Didit account**, and a Didit session only carried a bare numeric user id (`fs-u83502`). The same number is a *different human* on each site, so every "Sync from Didit" imported the *other* store's sessions and pinned each one on whoever held that number locally — which is exactly how a document reading "Am G Koiudos" (the other store's customer Amy) surfaced under this store's Chris Wright. Reinstalling the plugin made it worse: each reinstall wiped the local session table and re-imported the whole shared account from scratch. Now every new session is stamped with a per-store key in `vendor_data` (and the store URL in metadata), so sessions are provably owned — and Didit stops reusing one store's open session for the other store's customer who happens to share the number.
- **Sync and webhooks now verify ownership before attributing anything.** Sessions stamped for another store are recognized and left alone (the sync notice counts them: "N sessions belong to a different store"). Old unstamped sessions are attributed only when the session's **own contact email** corroborates the account it would land on; when the email says otherwise but belongs to a customer this store knows, the session follows the email (the identity that is true everywhere); when nobody local can be corroborated, the row stays anonymous history — a verification is never granted on the strength of a recycled user id again.
- **New: Rebuild attributions from Didit** (ID verification → tools). One click clears every finished session row, withdraws every verification an ID check earned (staff decisions survive untouched: accepted mismatches, the account Verified flag, hand-added Verified Customers entries), keeps in-flight sessions from the last 24 h, and re-imports from Didit under the new ownership rules with original dates. Run it once on **each** store sharing the account; plain syncs continue where it left off.
- **Fix: the missing first/last names.** Expected names used to go blank (43 guest sessions on one store) or show a username. Now, when the customer typed nothing and the profile is empty, the expected name falls back to the **billing name on their most recent order** — for accounts *and* guests — and the blocked-checkout gate now sends the billing name from the surrounding page too, so far fewer checks start nameless. Restored history rows get real names the same way.
- **No duplicate session rows.** When Didit returns an already-known session id (its own dedupe), the existing row is updated in place instead of a twin being inserted.
- No database changes; drop-in update. After updating, run **Rebuild attributions from Didit** on every store that shares the Didit account — and longer-term, give each store its own Didit API key.

**1.21.1**
- **Fix: two customers verifying at the same time can no longer be confused with each other.** Every ID-verification flow is now bound to the one Didit session it opened. The checkout used to poll by *customer identity* — and half of that identity was the email currently typed in the billing field, trusted even for logged-in customers. With another customer's address in the form (an agent-assisted order, browser autofill, a shared machine, a typo landing on a real customer), the poll matched THAT customer: it looked up "the newest session for this user **or** that email", probed it, and answered "you have been verified" the moment the *other* customer finished *their* check. Now `fs_idv_start` returns the session id, the browser carries it for the life of the flow (surviving the popup-blocked same-tab redirect via sessionStorage) and sends it with every poll, and the server answers about that exact session — after confirming it belongs to the caller. A session id that isn't yours is ignored, not answered, so nobody can watch or drive another customer's verification.
- **Poll identity is pinned like start identity always was.** `fs_idv_start` has always bound a logged-in customer to their ACCOUNT email, ignoring the form (so a mule email can't be verified with your ID); `fs_idv_status` now applies the same rule, so "am I verified?" is answered for the account, never for whatever email sits in the billing field. Guests still identify by their checkout email — that is the only identity a guest has.
- **Fix: the ID is now checked against the name the customer typed at checkout.** The name match used to prefer the *account profile* over the billing fields, and when the profile had no saved billing name it fell back to the WordPress display name — which, for accounts created at checkout, is the **username or the email's local part** ("jsmith77"). Matching a passport against a username fails every time, so nearly every verification landed as a name mismatch. Now the first and last name the customer entered on the checkout form is the expected side of the match; the profile only fills in when the request carried no name at all (the blocked-checkout gate and account pages have no name fields), and a username-shaped display name is never used as a legal name — with nothing to compare, the match records "n/a" instead of manufacturing a false mismatch. Didit's own expected-details screen now shows the typed name too, and if the customer corrects their name mid-flow, resuming the session refreshes the expected name — while a gate-page resume can no longer clobber a good typed name with a stale profile. A genuinely different name on the document still flags `name_mismatch` exactly as before.
- Webhook processing was already safe (signature-verified, keyed by session id, attributed to the session's own customer) and is unchanged. No database changes; drop-in update.

**1.21.0**
- **Verified customers now export and import as CSV (new).** A **Transfer** panel on the Verified Customers page writes everything the page knows into one re-importable file: verified accounts (carried by **email** — the identity that survives a site move, since account ids don't) and every verified email, address, and IP with its note, origin (staff vs ID-verification), and added date. Addresses travel as their stored 64-char hashes — the plain address was never stored — and import back as the same hash.
- **Import merges; it never removes.** Existing entries are kept, duplicates are skipped, and a re-import of the same file changes nothing. An account row is matched to a registered account by email and verified exactly as the Verify button does — flag set, email credential recorded, open checkout-block ledger rows released, any verification hold lifted (held *orders* are left untouched: a bulk import will not advance order statuses). An account email with no registered account on this site becomes a verified **email** instead, so the customer is recognized the moment they use it — and the file's original dates and notes are preserved, not restamped with the import time.
- **Loose files import too.** Besides the plugin's own export, any sheet with an `email` column works (optional `name` and `date` columns become the note and the added date), down to a plain one-email-per-line list. Rows that are not real email addresses are skipped rather than stored as junk credentials, and the exporter's spreadsheet-formula guard is undone on import so labels round-trip exactly.
- No database changes; drop-in update.

**1.20.1**
- **Fix: the low-value allowance can now name the gateways your checkout actually serves.** The allowance works by exact gateway id, but its checklist only offered the gateways registered inside wp-admin — and on a store whose card processors are added by a payment rotator on the storefront only (under generated ids), the id that matters was not even offered. The result was an allowance that looked configured and granted nothing: orders under the threshold saw no extra payment method, and new customers were still pointed at ID verification as the only way to a card processor. Every gateway checklist — the new-customer allow-list, the **low-value allowance**, the velocity restrict set, the high-value fallback set, the per-customer restriction editor, the bulk restrict panel, and the card-check scope — now offers the union of wp-admin gateways and the ids recorded on real checkout renders, with storefront-only entries marked "seen at checkout only". Tick those entries and small orders get their card processor back.
- **Ticked ids survive their gateway going missing.** A ticked id that is no longer registered or seen stays on the checklist (marked as such) instead of vanishing — previously, saving any settings change silently wiped it from the list, which is how a working configuration could degrade without anyone touching that rule.
- **The preview now catches this whole failure class.** *Preview: what a new customer sees* judges reachability against both worlds (wp-admin **and** checkout renders): it says outright when the allowance ids can never reach a customer, when a ticked id is known nowhere, and it now models the high-value ID rule too — including the configuration where the unverified-customer ID threshold sits **below** the low-value threshold, so the most-restrictive-wins composition strips the allowance on orders between the two amounts. That overlap gets a plain-words warning with both ways out (raise the ID threshold, or add the allowance gateways to the fallback set).
- No database changes; drop-in update.

**1.20.0**
- **Cardholder check via NMI (new).** An approved ID proves who the *person* is; it says nothing about whose *card* they are holding — and the failure mode that costs real money is precisely a verified person paying with someone else's card. Only the card issuer can attest that, so Fraud Shield now goes and asks: shortly after each card payment, the transaction is looked up on your NMI account(s) through the Query API and the issuer's own verdict is recorded — the **AVS** result (does the billing address match the cardholder's records at their bank), the **CVV** result (was the code on the physical card right), and any **3-D Secure** evidence the payment carried. The verdict is bound to the ID-verification state: ID-verified + card passes = **cardholder confirmed**, in green on the order and the profile; ID-verified + card fails = **cardholder MISMATCH**, the stolen-card-with-real-ID pattern, called out in red everywhere staff look.
- **A failed check can flag or hold.** Configurable policy: record only, **flag** (order note + the Card Checks list, the default), or **hold** — the paid order is set On-Hold so it is not fulfilled until a human looks. Hold only ever touches orders still in Processing (a completed order is flagged, not pulled back), never voids or refunds the payment, and can be scoped to every failed check or only to ID-verified customers. A failure always means the issuer positively said *no match* — or the CVV was wrong; partial address matches and "issuer does not participate" verdicts are their own middle states and never trigger the action (a setting can count partial matches as passes).
- **Built for rotator setups.** Several NMI security keys can be configured (one per MID, labelled), Fraud Shield tries each until the transaction is found, and it remembers which key served which gateway so most lookups are a single request. The Query endpoint is configurable for white-labelled NMI portals. Lookups run out-of-band a few seconds after payment — never inside the customer's checkout request — with automatic retries, and a **Run card check now** button on every order covers past orders and key testing.
- **New Card Checks page.** Every verdict in one list — order, customer, card (brand + last four), AVS and CVV codes (hover for plain English), 3-D Secure use, outcome, and the **Cardholder mismatches** filter that is the whole point. Results also live permanently on each order: a Card Check box on the order screen and a plain-language order note.
- **What is stored.** Result codes and masked card data only: brand, BIN, last four, and NMI's card fingerprint hash — never a card number. Keys are entered once in Settings and used server-side only. Uninstall (with data removal opted in) clears the log, the key map, and the per-order results.

**1.19.0**
- **Sync from Didit (new).** Didit keeps every verification session a store ever started, and each one was stamped with its customer at creation, so the whole verification state can be rebuilt from nothing. One button on the **ID Verifications** page pulls sessions back through the Didit API, matches each to its customer (by account id, by the hashed guest email against every address the store knows, or by the contact email Didit kept), re-runs the name-match policy, restores the document details and enrichment, and grants the verification **dated when it really happened**, so expiry and the high-value freshness rules keep counting from the original day. Idempotent: known sessions are skipped, repeated runs are safe, and large backlogs continue across runs. A customer with several checks over time ends up wearing their newest one.
- **CSV import (new).** The same page now imports verifications from a file: the page's own CSV export round-trips (export before a migration, import after), and any file with an "email" column works, down to a plain one-email-per-line list. Optional columns carry the user id, session id, outcome, document name, name match, document type, and the verification date. Rows that would duplicate a known session are skipped, so re-importing the same file is harmless.
- **Uninstalling no longer deletes your data.** Deleting the plugin used to drop every table and option as a side effect, taking the order index, the blocklist, and every completed ID verification with it. That is now opt-in: a new "Data on uninstall" setting (off by default) controls it, and with it off, uninstalling removes only the code and a reinstall picks the data straight back up. This exists because losing 40 identity verifications should require more than clicking Delete.

**1.18.0**
- **Fix: the low-value gateway allowance now measures what you think it measures.** It compared against the grand total (shipping and tax included), so a $480 basket failed a $500 allowance the moment $30 of shipping landed on top, and on the order-pay page (where the cart is empty) the allowance never applied at all. Money thresholds now share one configurable basis under **Settings, Order amounts**: item subtotal after discounts (the new default, matching what "a $500 order" means to a human) or the grand total. The order-pay page reads the amount from the order being paid, and the submit-time check uses the same basis as the display filter so the two can never disagree.
- **High-value orders (new).** Above configurable amounts, an order requires an ID check, and which amount applies depends on the customer's standing: one threshold for customers with **no verification**, a higher one for **automatically verified** regulars, and a **re-verification** threshold for ID-verified customers, because the person placing today's $10,500 order is the one who matters, not the one who verified a month ago. A check completed within a configurable freshness window (default 24 hours) satisfies every rule, so verifying during checkout counts immediately and two large orders in one day need only one check. Customers who have not completed the required check can still pay with a configurable **fallback gateway set** (empty set = invite only). At or above the optional **hard amount**, the order is refused at submission until a fresh check exists, no matter who the customer is; only the staff Verified account flag is exempt. The whole feature stands down if Didit is not configured, so nobody is ever stranded behind a check that cannot run. Guests are invited to sign in, since verification lives on the account.
- **Checkout knows the difference.** The verification card speaks per situation: first-time verification, "please confirm your identity again" for re-verification (with its own editable text, because "verify once to unlock everything" is a broken promise to someone who already did), a hard-requirement headline, and a sign-in card for guests. `{amount}` placeholders are replaced with the threshold that fired. The green "you have been verified" card no longer shows while a fresh check is still owed, and the status endpoint no longer reports "approved" to a customer whose old verification does not satisfy the current order, which would have reloaded the page straight back into the same request.
- **Didit now tells you more than pass/fail.** Each session stores where the document is from (state and country), where the customer physically was while verifying (IP country and state), whether they were behind a VPN or Tor, and any risk warnings Didit raised (DB v11). Still no images, no document numbers, no dates of birth. The customer profile gains a "What the check tells us" table that compares the document state against the states the customer actually ships to, and the ID Verifications page and history tables show document origin and verification location.
- **The fraud score reads the ID check.** Four new weighted signals: approved document naming a different person (30), document state that matches none of the customer's shipping states (15), verification performed from a different country than the order (15), and verification over a VPN or Tor (10). They appear in Rule weights, fire in the order meta box and scanner like every other rule, and only ever apply to customers who have an approved check on file.
- **Verification method and history.** Verification records now carry how they happened (completed the Didit check, or staff accepted a mismatch), profiles state the customer's current standing (staff-verified, ID-verified, automatically verified, or not verified), and the history table keeps every attempt over time, with the newest approved check being the one in force.
- **Settings page rewritten.** Panels are grouped into sections (General, Checkout controls, Payment methods, Trust and identity, Risk scoring, Classification), every master toggle now states in plain words what happens when it is ON and when it is OFF, the copy avoids em-dashes and jargon, and the save button sits in a sticky bar. The velocity, gateway and hold panels explain their fail-open behaviours instead of leaving them to be discovered.

**1.17.0**
- **Fix — completing the ID check on a name mismatch now verifies the customer, so their payment methods actually unlock.** A mismatch (Didit approved the document, but the name on it differed from the billing name) recorded the result and stopped there — `mark_verified()` was never called, so the customer stayed restricted with no way forward except a staff action they had no way to ask for. A completed check is now honoured by default: verification completes, the full set of admin-configured gateways returns, **and the mismatch is still recorded and flagged** on the order screen, the customer profile, and the ID Verifications page. A new **"On a name mismatch"** setting keeps the old stricter behaviour ("Hold") for anyone who wants it.
- **Verified customers now get a confirmation at checkout.** Previously the invitation simply disappeared, on the theory that unlocked payment methods spoke for themselves. A customer who has just handed over an ID document deserves better, so they now see a green card with the Fraud Shield mark reading **"You have been verified."** On the classic checkout the invitation turns into the confirmation in place, without a page reload.
- **The ID-verification invitation was redesigned.** It is now a proper card — shield mark, headline, explanatory line, and a clear action — with its own stylesheet instead of borrowing the theme's `woocommerce-info` box and inline styles. Uses `currentColor` and neutral system styling so it sits inside any theme, and the default copy was rewritten.
- **New: "This checkout is protected by Fraud Shield" banner.** A short reassurance line at the top of checkout, on both the classic and blocks checkout. The text is editable and the banner can be switched off. On the blocks checkout it is prepended to the block's own markup server-side rather than injected with JavaScript, so there is no visible pop-in.
- **Verified-on-mismatch is visually distinct in admin.** It carries an amber "VERIFIED — NAME MISMATCH" badge rather than the plain green one, because a verification that skipped the name check is a materially different fact and should not look identical to a clean one.

**1.16.1**
- **Fix — Fraud Shield now has the last word on which payment methods appear.** The gateway filter ran at priority 20, so any plugin that rebuilt the list afterwards silently undid the restriction. A **payment rotator** does exactly that, which is why a withheld processor kept appearing for brand-new accounts. Fraud Shield now filters at `PHP_INT_MAX` and re-asserts itself on `wp_loaded`, so it runs after every other callback — including another plugin also sitting at `PHP_INT_MAX`.
- **Fix — the restriction was skipped entirely on admin-ajax requests.** The filter bailed whenever `is_admin()` was true, which is also true for `admin-ajax.php` — where plenty of storefront features, payment rotators included, refresh the payment methods. Real wp-admin screens are still skipped; frontend AJAX is not.
- **New: "Preview: what a new customer sees" in Settings.** This rule had two failure modes that were invisible from the settings form, both of which look identical to "the setting does nothing". The preview now reports them directly: a ticked gateway id that **no longer exists** (the allow-list matches by exact id and deliberately fails open when it matches nothing, so one stale id turns the whole rule into a no-op), and an order **under the low-value threshold** quietly getting the withheld methods back. Enter any order total to see precisely which methods a restricted new customer would be shown and which are withheld.
- **New: gateway ids are recorded as customers reach checkout.** A plugin that registers its gateways only on the storefront leaves them invisible in wp-admin, so they could never be ticked. Those ids are now captured live and offered in Settings, flagged when they do not appear in wp-admin. Stored as a growing union so a rotator serving a different subset per request costs no repeated database writes.

**1.16.0**
- **Fix — new customers could keep a payment method you withheld from them.** The new-customer gateway restriction only ever applied to a *signed-in* account: `is_gateway_restricted_customer()` returned `false` for any visitor with no user id, so a guest — or anyone creating their account during checkout, which is when the account does not exist yet — was handed every gateway, at both display and submit time. Newly-registered customers were exactly the population it failed on. The rule is now identity-based rather than session-based: the account's registration date where there is one, otherwise the registration date of the account owning the checkout email, otherwise the first order ever indexed for that email so long-standing guests are still recognised as established. **A customer with no history anywhere now counts as new** rather than as exempt.
- **The chosen payment method is re-checked at submit.** The allow-list is applied to the methods on display, but a display-time filter runs before a guest has typed their email, so it cannot know who they are. Both checkout paths now validate the submitted gateway once the billing email is known — the classic checkout refuses it with a configurable message, and the blocks/Store API path cancels the pending order — from the same policy the display filter uses, so the two can never disagree. A stale or cached checkout page can no longer take an order on a withheld processor.
- **Automatic verification for established, clean customers.** A customer who has been with you at least 180 days, has at least 1 paid order, and has no chargebacks or refunds is now treated as verified: full payment methods, no velocity lock, no new-account hold. All four conditions are configurable in Settings, and it is evaluated live, so a customer who takes a chargeback tomorrow stops qualifying immediately. It grants exactly what a verified credential grants and deliberately **never** overrides the blocklist, a destination lock, or a staff request for an ID — no amount of good history undoes a human decision. On by default; the customer's profile explains where they stand and why.
- **Staff verification releases restricted checkout.** Verifying a customer by hand — the account Verified flag, an allowlisted email, or a verified credential — now ends a per-customer gateway restriction, alongside the ID check and the 6-month clock. The gateway editor warns you up front when a customer is already verified, since a restriction applied to them would be void on arrival.
- **Settings now show the effect of your allow-list.** The new-customer gateway list is an *allow* list, and reading it as a block list produces exactly the opposite policy, so Settings now states the outcome in words: which active gateways new customers may use, and which they cannot.
- **Restricted checkout is now probation, not a life sentence.** A per-customer gateway restriction used to sit on an account forever unless someone remembered to go back and clear it. It now **lapses on its own 6 months after it was applied** (configurable, 0 = never), and the customer returns to the normal checkout rules. Restrictions carry the date they were applied, the Restricted checkout list shows when each one releases, and the customer's own profile spells out where their probation stands.
- **An approved ID check ends restricted checkout immediately.** Passing the Didit verification now removes the per-customer gateway limit outright, so the customer regains every payment method *and* drops off the Restricted checkout list rather than lingering there as a row that no longer does anything. This closes a real gap: ID verification has always promised to clear gateway restrictions, but a per-customer restriction was checked before the verified exemption, so an ID-verified customer stayed restricted anyway.
- **Upgrade note.** Restrictions saved before this release have no applied-on date, and an undated restriction can never lapse, so the upgrade stamps them once and starts their window from the upgrade. Enforcement ignores a lapsed restriction on the customer's very next page load — no cron involved — and the Checkout Locks page tidies the stored records whenever staff open it.

**1.15.0**
- **ID-verified customers are now verified customers.** Passing the Didit check records the customer's email on the **Verified Customers** list automatically, so they read as green everywhere staff look instead of living in a parallel list, and their velocity-lock ledger rows are released. This is deliberately a *verified credential*, not the account **Verified** flag: it clears gateway restrictions, the velocity lock and the new-account hold, but an explicit blocklist entry still wins. Revoking a verification withdraws only the credential the check itself earned — a credential a human added survives.
- **Block checkout & request ID (new enforcement layer).** A new action on any customer profile blocks that account from checking out until they complete a government-ID check. The blocked-checkout page carries the verify button, so the customer unlocks themselves the moment they pass — no support ticket, no staff action. It is stored as its own flag rather than a blocklist entry, precisely so it can be self-releasing without weakening the blocklist. Enforced on the checkout page gate, the classic checkout, and the blocks/Store API checkout — including for a customer who signs out and tries again as a guest on the same email, who is told to sign in so they can verify. Marking the account **Verified**, or releasing it onto restricted gateways, withdraws the demand along with everything else. Fails **open** if ID verification is switched off or loses its credentials, so nobody is ever stranded behind a check your store can no longer run, and the action refuses to apply in the first place until Didit is configured.
- **ID verification panel on customer profiles.** Both profile views (Profiles → intelligence profile, and the account view) now show where a customer stands — verified, name mismatch, ID requested, or never attempted — with the document name, name-match outcome, verification date, expiry, Didit session id, and a full history table of every attempt they have made, plus one-click *Revoke* / *Accept & mark verified*.
- **New ID Verifications page.** A dedicated page listing everyone who has been through the flow, passed or failed: headline counts, filters (Verified / Name mismatch / Declined / In progress / Not completed), search across email, document name and session id, sortable columns, a **CSV export**, and an *Awaiting a requested ID* queue showing exactly who is blocked pending a check and how many attempts they have made.
- **Checkout Locks understands the new block.** ID-requested customers appear there labelled as such, with a *Cancel ID request* action, and rows self-heal once the customer verifies — the same drift protection velocity rows already had.
- No schema change (DB stays v9). Uninstall removes the new per-account flag. Upgrades in place.

**1.14.0**
- **ID verification via Didit (new enforcement layer).** Customers whose payment methods are restricted (the new-customer gateway rule, or the velocity lock in restrict mode) now see an optional "Verify my ID" invitation above the payment methods: a Didit-hosted government-ID check (driver's license, passport, 14,000+ document types) opens in a popup, and on approval the full gateway set unlocks — on the spot, no staff involved. Configure under **Settings → ID verification (Didit)**: API key, workflow ID, webhook secret, texts, attempt limit, and validity window.
- **Name matching.** An approved document alone isn't enough: the name Didit extracts from the ID must match the customer's billing name (lenient last-name+initial match by default; strict and off also available). A genuine-but-borrowed ID therefore fails closed as a **Name mismatch**, held for staff review on the order screen and user profile with one-click *Accept & mark verified*.
- **Works with or without webhooks.** Point a Didit webhook at `/wp-json/fraud-shield/v1/didit` (HMAC-verified, ±5-minute timestamp window) for instant results; without one, checkout polling falls back to querying Didit's decision API directly.
- **Privacy by design.** Document images go browser → Didit and never touch your server; the plugin stores only the session id, decision, extracted name, and timestamps (new `fs_idv_sessions` table, DB v9). Orders placed by ID-verified customers are stamped with the session id for chargeback evidence.
- **Scope.** ID verification counts as a verified credential — it clears gateway restrictions, the velocity lock, and the new-account hold, but **never** the blocklist or a staff-set per-customer restriction. Verification expires after a configurable number of days (default 365; 0 = never), attempts are rate-limited (default 3/day), and a `[fraud_shield_verify]` shortcode renders the same flow on any page (e.g. My Account).

**1.13.1**
- **Packaging fix (no code changes).** The 1.13.0 zip was built with backslash path separators in its entries, which Linux hosts treat as part of the filename — the plugin extracted as loose `fraud-shield\…` files instead of a `fraud-shield/` folder, making it impossible to activate ("plugin file does not exist") or delete. Release zips are now built with `git archive` (spec-compliant forward slashes). If you installed the broken 1.13.0 zip, delete the stray `fraud-shield\*` files from `wp-content/plugins/` and install this version.

**1.13.0**
- **Verify buttons everywhere.** Every list that has a Block button now has a Verify toggle beside it — Dashboard (address + IP tables), Repeat Customers, New Customers, Profiles, Addresses, and IPs — plus a "Verify customer" action on the profile detail page. Verified entries render as a green "✓ Verified" button (click again to revoke) and land on the Verified Customers page.
- **Fix — verified accounts now appear on Verified Customers.** Verifying an account (Mark verified / Verify & unlock) previously only set account meta, so the customer never showed on the Verified Customers page. Account verification now also records the account email on the verified-credentials list (and removes it on revoke), and the page gained a "Verified accounts" section listing every account carrying the flag.
- **Velocity rule: block or restrict.** New "When over the threshold" setting on the velocity lock: keep the classic hard block, or push high-velocity customers onto a restricted set of gateways instead — they can still check out, but only on the methods you tick. Restricted-by-velocity customers appear on the new Restricted checkout list and release automatically as their 30-day window rolls.
- **Restricted checkout list + bulk actions on Checkout Locks.** The blocked-at-checkout table now has row checkboxes and a "Restrict Checkout & Unblock selected" bulk action: pick the allowed gateways (plus optional under-$X allowance) and every selected account is unblocked onto restricted checkout in one step. The new Restricted checkout panel lists everyone on limited gateways — staff-applied restrictions are bulk-removable, velocity-derived ones show with a Verify & release shortcut.
- **Fix — per-customer gateway restrictions actually enforce now.** Saving a per-user restriction used to also mark the account Verified, and the verified exemption was checked before the restriction — so the limit never applied. Restrictions are now checked first, and applying one no longer verifies the account; the restriction itself releases the customer from the velocity lock and holds (the blocklist still applies).

**1.12.0**
- **Fix — Checkout Locks now shows everyone actually blocked.** A completed payment was wrongly resolving a customer's velocity ledger row even though the "N+ orders in 30 days" lock still applied, so still-locked customers silently dropped off the page. The page now reconciles the ledger against the live lock on every view: stale rows self-heal, and any currently-locked customer missing a row is re-surfaced.
- **Verified Customers** — a new page and a green "known-good" list of emails, addresses, and IPs. A verified credential bypasses the velocity lock, the new-account hold, and gateway restrictions (but never an explicit blocklist / destination lock — an explicit block always wins). Verified profiles render green on the Profiles page. Verify from the page, a customer's profile, or the Checkout Locks queue.
- **Per-customer gateway restriction** — on any customer's account view you can unblock them but limit which payment gateways they may use, with an optional "allow this gateway when the order is under $X" rule (off unless an amount is set). Saving verifies the account so they can check out on the allowed methods. This is independent of the global new-customer gateway rule and takes priority for that customer.
- **Registered-date column on Profiles**, sortable.
- **Multi-column sort** on New Customers, Repeat Customers, Profiles, and Addresses/IPs: click a column to sort by it; **Ctrl/Cmd+click** a second column to add it as a secondary sort (e.g. newest first, then by highest spend). Server-side, so it sorts the full dataset and works across pages. No schema change (DB stays v8).

**1.11.0**
- **Four new detection signals** (transparent/additive, all weights & thresholds editable): billing≠shipping divergence (count of an identity's mismatched orders, default 2+), back-to-back ordering (3+ within 24 hours, separate from the 7/30-day velocity), **card-processor exclusivity** (a moderate, non-overpowering weight when a customer with 2+ orders paid 100% via gateways you've labeled as card processors), and **expedited/overnight shipping**.
- **Payment- & shipping-method classification** (Settings): after a scan, label each distinct payment method as *card processor* vs *other*, and each shipping method as *expedited* vs *standard*. The card-only and expedited-shipping rules use these labels; unlabeled = not-card / standard.
- **Schema v8**: adds a `ship_method` column to capture the chosen shipping method (auto-upgrades; re-index once to backfill historical orders). No data loss.
- **Security hardening** (internal audit): all CSV exports now neutralize spreadsheet formula injection (CWE-1236) from customer-controlled values, with a faithful strip on re-import; CSV downloads send `X-Content-Type-Options: nosniff`; classification inputs are type-checked. Full security model documented in `SECURITY.md`.

**1.10.0**
- **Fix — accurate checkout-block tracking**: the Checkout Locks page now reads a durable ledger of *real* block events instead of reconstructing a list from the order index. The old reconstruction was capped and skewed (it sampled the highest-order-count accounts — your established regulars — then filtered them out, so genuinely-locked new accounts never appeared and the list looked stuck). Now every velocity/blocklist block is recorded the moment it happens, keyed by identity (repeat attempts bump a counter), so the list is accurate, grows in real time, and CS can Verify/Allow/Dismiss directly. Velocity rows auto-resolve when the customer later completes a paid order. Adds table `fs_checkout_blocks` (schema v7, auto-upgrades).
- **New — low-value gateway allowance**: in the new-customer gateway rules, you can now let otherwise-restricted gateways (e.g. a credit-card processor) become available to a new customer when their order total is **under a set amount** — an easy payment path for small first orders while high-value first orders stay restricted. Uses the live cart total, so it updates as the cart changes; when the total is unknown it conservatively stays restricted.

**1.9.0**
- **New-customer payment gateway restriction**: limit which payment methods accounts registered within the last N days (configurable) may use — e.g. keep new accounts off credit-card processors until they're established. Enforced via WooCommerce's available-gateways filter, so it governs classic checkout, block/Store-API checkout, and server-side order validation together. Verified customers and older accounts are exempt; an optional checkout notice (default off) can explain the reduced options. Fails open if the allowed list matches no active gateway, so it can never lock every new customer out of checkout. No schema change (DB v6).

**1.8.1**
- Pre-release hardening: the Verification queue lookup now uses an explicit `EXISTS` meta query; a held triggering order now reliably **stays** On-Hold after payment completes (previously `payment_complete()` could flip it back to processing); releasing a held customer only advances their triggering order to "processing" if it was actually paid (an unpaid held order stays on-hold for manual review); and the velocity-lock candidate scan on the Checkout Locks page is bounded to keep the page fast on high-volume stores.

**1.8.0**
- **Checkout Locks page**: one surface showing everyone currently blocked from checkout (with a live count) and a one-click release. Two sections — the velocity-lock list (accounts release via Verify; guest emails via a new email allowlist) and the new-account Verification queue.
- **New-account verification hold**: a new deliberate control (all modes). When an account registered within the last N days (default 90) places an order over a configurable amount, it is locked from placing further orders, the triggering order is optionally held for review, and a configurable list of emails is notified so a CS agent can call the customer. Verifying the account releases it and its held order. Account-scoped (never affects guests).
- **Block from the lists**: one-click Block/Unblock toggles added to the Repeat Customers, New Customers, and Geography customer views — no more drilling into each profile.
- **Geography drill-down**: click any state, city, or ZIP in the Geography report to see a sortable, searchable table of every customer shipping there, each with a Block toggle and a link to the full profile.
- **Email allowlist**: releases a velocity-locked guest email (and exempts it from the new hold). Does not override the blocklist. No schema change (DB stays v6).

**1.7.0**
- **Geography report**: fraud rolled up by ship-to state / city / ZIP — orders, distinct customers, average risk score, high/block count, chargebacks and chargeback rate — sortable, searchable, time-windowed, with a minimum-order floor and CSV export. Answers "which city/state has the worst fraud" on row one.
- **Destination Locks**: a new `place` blocklist type that hard-blocks checkouts *shipping to* a locked state, city, or ZIP prefix. One-click lock/unlock from any Geography row (or a manual add form), rendered as reversible cards on the Blocklist. Locks inherit every enforcement surface (page gate, classic checkout, Store API), the verified-customer exemption, message precedence, Test Checkout, and export/import — with **no new tables**.
- **Safety**: destination locks never cascade to identities and never auto-extend (blocking a city must not become a mass identity block); city matching is exact ("North Miami" ≠ "Miami"), ZIP prefixes cover metros. Identity messages outrank geo messages.
- **Geo columns (schema v6)**: the index now stores normalized ship-to city/state/postcode with indexes. Existing rows show a one-click **Backfill** on the Geography page (resumable; a full re-scan with "Re-index" also fills them). CSV-only rows without those columns can't be geo-backfilled (there is no order to read).

**1.6.0**
- **Reversible, grouped blocking**: every Block is now a *block action* that owns the credentials it created. Click Block again to unblock and remove all of them at once — the confirm dialog states the exact credential count. The Blocklist page is reorganized into one card per block (address / IP / customer), each with Remove-all, Re-sync, and its own optional checkout message.
- **Shared-credential safety**: ownership is many-to-many, so unblocking one block never removes a credential another active block still needs.
- **Legacy adoption**: pre-1.6 entries appear under an "Ungrouped" card and convert into a removable group as soon as you re-block the matching address/IP/profile.
- **Auto-extend blocks** (new setting, on by default): a new order that matches an existing block automatically links its own fresh credentials to that block, keeping a rotating ring blocked. Each auto-link is written as an order note.
- **Checkout-gate rewrite**: the blocked-page gate was rebuilt on `template_redirect` with a self-contained page and cache hardening (`nocache_headers()`, `DONOTCACHEPAGE`, `Cache-Control: no-store`, `403`), fixing themes/builders where the previous `the_content` gate never fired — the message now shows reliably on classic, FSE/block, shortcode, and block-checkout pages.
- **Message precedence**: per-block → per-credential → global default, enforced identically on the page gate, classic checkout, and Store API rejection.
- **Import/export** now preserves block grouping via portable natural keys; old-format CSVs import under one reversible "Imported …" block.
- Fixed the plugin header version (was still `1.0.0`). Database schema v5 (adds `fs_block_actions` + `fs_block_action_entries`; auto-upgrades, existing blocklist rows preserved).

**1.5.0**
- **Critical fix**: blocklist entries now hard-block checkout in EVERY mode. Previously they were only enforced in Enforce mode, so stores still in Observe mode could see blocked credentials check out.
- **Checkout page gate**: identifiable blocked (or velocity-locked) visitors no longer see the checkout form at all — the checkout page shows only their configured message. Guests entering blocked details are rejected at submission, before payment, on both classic and blocks checkout.
- **Block profile**: one click on a customer profile blocks their email, phones, IPs, addresses, and account together. Merchant-verified customers bypass the blocklist (the manual-verification unlock path).
- **Blocklist export / import**: move the blocklist between sites as CSV, custom messages included.
- **Dashboard quick search** for addresses (and a shortcut to profile search).

**1.4.0**
- **Address & IP registries**: dedicated Addresses and IPs sections listing *every* shipping address and IP in the index (the dashboard shows only the top risky ones) — searchable, sortable, paginated, exportable to CSV, with View and cascade-Block actions on every row.
- Address/IP profile pages gain payment methods, coupon codes, and device signatures (one device signature across many accounts = one operator).

**1.3.0**
- **Customer Profiles**: an intelligence dossier for every identity (registered or guest) — linked customers via shared IPs/addresses, coupon codes, payment methods, device signatures, average order value, order cadence and time-of-day patterns, IP-vs-country geolocation checks, and billing/shipping consistency.
- **CSV exports (Excel-compatible)** of the full result sets: risky addresses, risky IPs, Repeat Customers, New Customers, and Profiles — honoring current filters and sort.
- Index now captures coupon codes, browser user-agent, and a billing-address hash (schema v4, auto-upgrades; re-index once to backfill history).

**1.2.0**
- Automatic indexing and scoring of every new order from any source.
- Cascade blocking (an address/IP block also blocks all associated credentials) with per-entry checkout messages and a ✓ Blocked state.
- Payment-aware order coloring and paid-only totals; sortable, searchable reports; email alerts for risky orders; Test Checkout simulator.

**1.1.0**
- Resumable, pausable, date-scoped scanning with incremental "new since last scan" mode; CSV import with column mapping and verbatim index restore; index CSV export; address/IP cluster drill-downs; New Customers report; checkout velocity lock with manual verification; IP geolocation.

**1.0.0**
- Initial release: cross-order risk engine, full-history scan, flagged orders, blocklist, order meta box, Observe/Enforce modes, HPOS support.

---

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). Built by Bones R&D.
