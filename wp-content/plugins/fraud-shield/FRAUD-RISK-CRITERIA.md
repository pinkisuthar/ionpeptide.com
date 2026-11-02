# Fraud Shield — Fraud Risk Criteria

**How Fraud Shield scores the fraud risk of addresses and customer profiles.**

This document describes the exact criteria the plugin uses to turn an order's
signals into a risk score and a risk level. All numbers below are the shipped
**defaults** — every weight, threshold, and cut-off is editable under
**Fraud Shield → Settings**, and *Recompute scores* re-applies any change to your
entire order history.

> Reference version: v1.31.0. A risk score is **decision support, not proof of
> fraud** — it flags patterns, it does not prove intent.

---

## 1. How the model works

- **Transparent and additive.** Each rule that matches adds a fixed number of
  points *and* a plain-English reason. An order's score is the sum of the points
  from every rule that fired; its reasons are the list of those explanations.
- **Cross-order, not single-order.** Every rule is evaluated against your entire
  indexed order history — not just the order in front of you. The order being
  scored is always excluded from its own history so it can't inflate its own
  counts.
- **Anchored in time.** Velocity and account-age rules are measured relative to
  the order's own date, so re-scoring old orders stays accurate.
- **The score maps to a level** using the cut-offs in section 2.

There are two things being evaluated, both fed through the same engine:

| Subject | What it is | Identity key |
|---|---|---|
| **Customer profile** | One customer identity and all of its orders | Normalized email |
| **Address** | One shipping destination and all orders shipped there | Hashed, normalized shipping address (plus a "loose" street + ZIP variant) |

---

## 2. Risk levels

The total score maps to one of four levels using these default cut-offs:

| Level | Score (default) | Typical meaning |
|---|---|---|
| **Low** | 0 – 39 | Nothing notable. |
| **Medium** | 40 – 69 | One moderate signal or a couple of weak ones — worth a glance. |
| **High** | 70 – 109 | A strong signal (e.g. a chargeback link) or several stacking signals. |
| **Block** | 110 + | Multiple strong signals; behaves like a confirmed-bad pattern. |

In **Enforce** mode you decide what High and Block do (flag, hold for review, or
hard-block). In **Observe** mode the score only annotates orders and never
affects checkout.

---

## 3. Chargeback contagion (strongest signals — apply to both)

The heaviest weights come from a **confirmed prior chargeback**. If any credential
on the order also appears on an order Fraud Shield counts as charged back, the
order inherits that risk. "Charged back" means the order's status is *Charged
back*, it carries the manual chargeback flag, or (optionally) it was refunded and
you've enabled "treat refunded as chargeback."

| Rule | Fires when… | Points | Applies to |
|---|---|---:|---|
| `cb_email` | The email was used on a prior charged-back order | **80** | Profile |
| `cb_ship` | The **exact** shipping address was on a prior charged-back order | **70** | Address |
| `cb_phone` | The phone number was on a prior charged-back order | **60** | Profile |
| `cb_ship_loose` | The **same street + ZIP** (different unit/apt) was on a prior charged-back order | **40** | Address |
| `cb_ip` | The order's IP was on a prior charged-back order | **35** | Profile |

Note: `cb_ship` and `cb_ship_loose` are mutually exclusive — an exact-address
match takes precedence over the looser street/ZIP match, so they never
double-count.

---

## 4. Address risk criteria

These rules describe how risky a **shipping destination** is — the classic
"drop house" / reshipper patterns where many unrelated identities funnel through
one address.

| Rule | Fires when… | Default threshold | Points |
|---|---|---|---:|
| `cb_ship` | Exact address used on a prior chargeback | — | **70** |
| `cb_ship_loose` | Same street/ZIP (different unit) used on a prior chargeback | — | **40** |
| `addr_multi_email` | The address is shared by **N or more distinct customer emails** | ≥ 3 emails | **25**, plus **+5 for each extra email** beyond the threshold, capped at **+40** (so 25–65) |
| `addr_multi_name` | The address is shared by **N or more distinct customer names** | ≥ 3 names | **15** |

How the `addr_multi_email` bonus scales (default):

| Distinct emails at the address | Points |
|---|---:|
| 3 | 25 |
| 5 | 35 |
| 8 | 50 |
| 11 or more | 65 (capped) |

---

## 5. Customer profile (identity) risk criteria

These rules describe how risky a **customer identity** is — an email/phone/account
and its behavior across your store.

| Rule | Fires when… | Default threshold | Points |
|---|---|---|---:|
| `cb_email` | Email used on a prior chargeback | — | **80** |
| `cb_phone` | Phone used on a prior chargeback | — | **60** |
| `cb_ip` | Order IP used on a prior chargeback | — | **35** |
| `email_multi_addr` | This email has shipped to **N or more distinct addresses** | ≥ 4 addresses | **10** |
| `ip_multi_account` | **N or more distinct emails** have ordered from this IP | ≥ 4 emails | **20** |
| `new_account` | The account (or first-seen email) is **≤ N days old** at order time | ≤ 7 days | **15** |
| `velocity_7d` | **N or more** orders from this identity within **7 days** | ≥ 3 orders | **20**, plus **+10 for each extra order** beyond the threshold, capped at **+40** (so 20–60) |
| `velocity_30d` | **N or more** orders from this identity within **30 days** | ≥ 6 orders | **12** |
| `order_burst` | **N or more** orders from this identity within a **24-hour** window (back-to-back) | ≥ 3 orders | **20**, plus **+25 for each extra order** beyond the threshold, capped at **+60** (so 20–80) |
| `bill_ship_mismatch` | The identity has **N or more** orders where billing ≠ shipping address | ≥ 2 orders | **20** |
| `card_only` | The identity paid **100% via card processors** (chargebackable) across **N or more** counted orders | ≥ 2 orders | **25** |
| `fast_shipping` | **This order** used a shipping method labeled expedited/overnight | — | **15** |
| `disposable_email` | The email domain is on the disposable/throwaway list | — | **20** |
| `country_mismatch` | Billing country ≠ shipping country | — | **10** |

Notes:
- **Velocity vs. burst.** `velocity_7d`/`velocity_30d` use the tighter window
  first (if 7-day fires, 30-day does not). `order_burst` is **separate** and can
  fire alongside them — it targets rapid same-day bursts. All count orders by the
  same identity (account **or** email) in the window before the order.
- **Velocity escalates with volume (v1.31).** The counts are of *prior* orders,
  so "3 within 24 hours" is the fourth order of the day. With the defaults, the
  fourth order scores 40 on velocity alone (exactly as before), the fifth 75
  (High) and the sixth 110 (Block) — a same-day spree reaches Block on its own,
  and sooner alongside any other signal:

  | Prior orders in 24 h (and in 7 d) | `order_burst` | `velocity_7d` | Velocity total |
  |---|---:|---:|---:|
  | 3 | 20 | 20 | 40 (Medium) |
  | 4 | 45 | 30 | 75 (High) |
  | 5 | 70 | 40 | 110 (Block) |
  | 6 | 80 (capped) | 50 | 130 (Block) |
  | 8 or more | 80 | 60 (capped) | 140 (Block) |
- **New account** age is the account's registration date for logged-in customers,
  or the email's first-seen order date for guests.
- **`card_only`** is a *moderate* weight by design — impactful but not
  overpowering. It flags the population most *capable* of a chargeback (people who
  never use a non-chargebackable method), but never suppresses the stronger
  chargeback/clustering signals. It requires you to classify your gateways (see
  §8); unclassified methods count as "not a card processor," so the rule stays
  dormant until you label.
- **`fast_shipping`** and **`card_only`** both depend on the merchant
  classification step — until you label methods, they contribute nothing.

---

## 6. Full rule reference (defaults)

| Rule key | Signal | Threshold | Points | Subject |
|---|---|---|---:|---|
| `cb_email` | Email on a prior chargeback | — | 80 | Profile |
| `cb_ship` | Exact shipping address on a prior chargeback | — | 70 | Address |
| `cb_phone` | Phone on a prior chargeback | — | 60 | Profile |
| `cb_ship_loose` | Same street/ZIP on a prior chargeback | — | 40 | Address |
| `cb_ip` | Order IP on a prior chargeback | — | 35 | Profile |
| `addr_multi_email` | Distinct emails at the address | ≥ 3 | 25 (+5/extra, cap +40) | Address |
| `addr_multi_name` | Distinct names at the address | ≥ 3 | 15 | Address |
| `email_multi_addr` | Distinct addresses for the email | ≥ 4 | 10 | Profile |
| `ip_multi_account` | Distinct emails at the IP | ≥ 4 | 20 | Profile |
| `new_account` | Account / first-seen age | ≤ 7 days | 15 | Profile |
| `velocity_7d` | Orders in 7 days | ≥ 3 | 20 (+10/extra, cap +40) | Profile |
| `velocity_30d` | Orders in 30 days | ≥ 6 | 12 | Profile |
| `order_burst` | Orders within 24 hours (back-to-back) | ≥ 3 | 20 (+25/extra, cap +60) | Profile |
| `bill_ship_mismatch` | Orders where billing ≠ shipping | ≥ 2 | 20 | Profile |
| `card_only` | Paid 100% via card processors | ≥ 2 orders | 25 | Profile |
| `fast_shipping` | Expedited/overnight shipping (this order) | — | 15 | Order |
| `disposable_email` | Throwaway email domain | — | 20 | Profile |
| `country_mismatch` | Billing ≠ shipping country | — | 10 | Profile |

Level cut-offs: **Medium ≥ 40 · High ≥ 70 · Block ≥ 110**.

---

## 7. Why "the same thing typed differently" still matches

Before any rule runs, credentials are normalized so trivial variations collapse
to one identity:

- **Email** — lowercased; Gmail dot and `+alias` variations collapse
  (`John.Doe+shop@gmail.com` → `johndoe@gmail.com`).
- **Phone** — reduced to digits only; a leading US country code is stripped.
- **Address (exact)** — lowercased, punctuation removed, common abbreviations
  standardized (Street→St, Apartment→Apt, North→N…), then hashed.
- **Address (loose)** — the street line + postcode only, so "same house,
  different apartment number" still links.
- **Address (door key)** — house number + street name + country + state (US,
  Canada, Australia; the postcode elsewhere), with every ordinal collapsed,
  directionals and street-type spellings aligned, the unit stripped from either
  line, the ZIP and the city ignored, and Unicode lookalikes folded. This is the
  hash the blocklist enforces: it is what a reshipper rotating ZIP codes, unit
  spellings and city names cannot move. (Not a scoring input — see §9.)

This is what lets the chargeback-contagion and clustering rules see through
cosmetic differences.

---

## 8. Tuning

Everything above is a default. Under **Fraud Shield → Settings** you can change:

- Every rule's **points** (weights).
- Every **threshold** (how many emails/addresses/orders/days trigger a rule).
- The **level cut-offs** (Medium / High / Block scores).
- The **disposable email domain** list.
- **Payment-method classification** — label each payment method seen in your
  orders as *card processor* (chargebackable) or *other*. Drives `card_only`.
- **Shipping-method classification** — label each shipping method as *expedited /
  overnight* or *standard*. Drives `fast_shipping`.

After changing weights/thresholds, run **Recompute scores** to re-apply them to
your whole indexed history — no re-scan required. Note that `fast_shipping` reads
the shipping method captured on each order; older orders indexed before v1.11 need
a one-time **re-index** (full scan with "Re-index orders already scanned") to
populate it.

---

## 9. What this document does *not* cover

The risk score above is the **heuristic** layer. Fraud Shield also has
**deliberate controls** that act on explicit merchant decisions and are
independent of the score (they apply even in Observe mode):

- **Blocklist** and **destination locks** — hard-block specific credentials or
  ship-to places: a state, a city, a ZIP prefix, a whole street within its
  explicit boundary (city names, ZIPs, counties), one building (every unit), or
  an area (a radius around a location). Addresses are resolved to their
  physical destination by a geocoder (the free US Census geocoder by default);
  with validation on, street and building locks are matched against that
  destination, and an address the geocoder cannot find on a locked road is
  stopped for correction.
- **Velocity rule** — a plain count, not points: once a customer already has
  N orders inside a window you define (days or hours), counted by account and
  email and optionally by the same phone or shipping door, the next order is
  placed On-Hold for review (default), refused, or limited to a gateway set.
- **New-account verification hold** — hold a new account's first high-value order.
- **New-customer payment-gateway restriction** — limit which payment methods
  brand-new accounts may use.

Those are decisions you make, not risk criteria the engine computes, so they are
out of scope here. See the README's *Enforcement layers* section for how they fit
together.
