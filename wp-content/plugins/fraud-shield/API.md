# Fraud Shield — API & Webhook Reference

Plugin version **1.24.1** · API version **v1** · Database version 12

This document is self-contained and safe to paste into an LLM context whole. It is the complete reference for the Fraud Shield REST API and its outbound webhooks.

> **Every request, response, header, signature and webhook body in this document was captured verbatim from a live execution of the plugin** against a real MariaDB database — not written by hand. The fixture store is called *Atomik Labz*, has 7 indexed orders scored by the real rules engine, 2 blocklist entries, 2 ID verifications and 1 verified credential. Values such as `store_id`, tokens, secrets, signatures and event ids are therefore internally consistent throughout: they all come from the same run.

---

## Contents

- [Orientation](#orientation)
- [Authentication](#authentication)
  - [Bearer tokens](#bearer-tokens)
  - [Signed requests](#signed-requests)
  - [Client implementations](#client-implementations)
  - [Scopes](#scopes)
  - [Rate limits](#rate-limits)
  - [Response headers](#response-headers)
- [Errors](#errors)
- [Pairing handshake](#pairing-handshake)
- [Endpoint reference](#endpoint-reference)
  - [GET /ping](#get-ping)
  - [GET /stats](#get-stats)
  - [POST /risk/evaluate](#post-riskevaluate)
  - [GET /customers/{ident}](#get-customersident)
  - [GET /orders](#get-orders)
  - [GET /orders/{id}](#get-ordersid)
  - [GET /blocklist](#get-blocklist)
  - [POST /blocklist](#post-blocklist)
  - [DELETE /blocklist/{id}](#delete-blocklistid)
  - [GET /verified](#get-verified)
  - [POST /verified](#post-verified)
  - [DELETE /verified](#delete-verified)
  - [GET /idv/sessions](#get-idvsessions)
  - [POST /idv/require](#post-idvrequire)
  - [GET /events](#get-events)
  - [GET /webhooks](#get-webhooks)
  - [POST /webhooks](#post-webhooks)
  - [DELETE /webhooks/{id}](#delete-webhooksid)
  - [POST /webhooks/{id}/test](#post-webhooksidtest)
- [Webhooks](#webhooks)
  - [Delivery format](#delivery-format)
  - [Verifying a delivery](#verifying-a-delivery)
  - [Real payload for every topic](#real-payload-for-every-topic)
  - [Hashed identifiers](#hashed-identifiers)
  - [Retries and failure](#retries-and-failure)
- [Value glossary](#value-glossary)
- [Security model](#security-model)
- [Performance characteristics](#performance-characteristics)

---

## Orientation

| Fact | Value |
|---|---|
| Base URL | `https://<store>/wp-json/fraud-shield/v1` |
| Alternate URL form | `https://<store>/?rest_route=/fraud-shield/v1/...` |
| Auth | `Authorization: Bearer <token>`, or HMAC-SHA256 signed headers |
| Content type | `application/json` (`multipart/form-data` is refused with `415`) |
| Max request body | 256 KB |
| Default rate limit | 120 requests/minute/key |
| List page size | default 50, maximum 100 |
| Timestamps | ISO 8601. Order/customer/session dates carry the **store's** offset; webhook and event timestamps are **UTC** |
| Error shape | WordPress REST standard: `{ "code": ..., "message": ..., "data": { "status": ... } }` |
| Webhook signature | `X-FS-Signature: t=<unix>,v1=<hex>` over `"<t>.<raw body>"` |

The API is enabled by default but **refuses every request until an administrator mints a key** under *WordPress admin → Fraud Shield → API & Webhooks*.

---

## Authentication

Every endpoint except `POST /handshake` requires a credential. A token looks like this (a real one, from the captured run):

```
fsk_3ec576b720cd1043_fea9d20bcebadb2dc9de6e875903d0a5711046a96012b1fe
    └── key id (16 hex) ┘ └────────── secret (48 hex) ──────────┘
```

The key id is public and appears in the admin UI and audit log. The secret is displayed once at creation; the store keeps only `sha256(secret)`.

### Bearer tokens

```bash
curl -s 'https://store.example.com/wp-json/fraud-shield/v1/ping' \
  -H 'Authorization: Bearer fsk_3ec576b720cd1043_fea9d20bcebadb2dc9de6e875903d0a5711046a96012b1fe'
```

A bearer token is a password: whatever can read it can use it. Prefer signed requests for anything long-lived, and for any credential given to a third party.

### Signed requests

A key can be marked **require signature** individually, or all keys can be forced to sign store-wide. A signed request cannot be replayed and cannot be altered in transit.

Send four headers:

| Header | Value |
|---|---|
| `X-FS-Key` | The full token |
| `X-FS-Timestamp` | Unix seconds. Must be within **±300 s** of store time |
| `X-FS-Nonce` | Unique per request. 8–128 chars from `A–Z a–z 0–9 . _ : -`. Remembered for 600 s |
| `X-FS-Signature` | `<hex>` or `v1=<hex>` |

The signature is `HMAC-SHA256(canonical_string, secret)`, hex-encoded. The canonical string is **exactly seven lines joined by `\n`**:

```
v1
<HTTP METHOD, uppercase>
<REST path, namespace-included, no trailing slash>
<canonical query string>
<sha256 hex of the raw request body>
<timestamp>
<nonce>
```

Line by line:

1. `v1` — literal scheme version.
2. Method, uppercase: `GET`, `POST`, `DELETE`.
3. The path **as sent**, including the namespace and any path parameters: `/fraud-shield/v1/orders/51802`. Identical for both URL forms, because WordPress resolves the route before signing is checked. Do **not** include a trailing slash — WordPress strips it.
4. Query parameters, excluding `rest_route` and `_locale`: sort keys ascending, `rawurlencode` each key and value, join as `k=v` with `&`. Empty string when there are none.
5. `sha256` of the raw body bytes. For an empty body that is the well-known `e3b0c442...b855` digest of `""`.
6. The same value sent in `X-FS-Timestamp`.
7. The same value sent in `X-FS-Nonce`.

A worked example. For `GET /fraud-shield/v1/orders?level=high&limit=3` with no body, timestamp `1787167380` and nonce `a1b2c3d4e5f60718`, the canonical string is:

```
v1
GET
/fraud-shield/v1/orders
level=high&limit=3
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
1787167380
a1b2c3d4e5f60718
```

### Client implementations

**PHP**

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

    $url = rtrim( $base, '/' ) . $path . ( $pairs ? '?' . implode( '&', $pairs ) : '' );
    $ch  = curl_init( $url );
    curl_setopt_array( $ch, array(
        CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'X-FS-Key: ' . $token,
            'X-FS-Timestamp: ' . $timestamp,
            'X-FS-Nonce: ' . $nonce,
            'X-FS-Signature: ' . hash_hmac( 'sha256', $canonical, $secret ),
        ),
    ) );
    $response = curl_exec( $ch );
    curl_close( $ch );
    return json_decode( $response, true );
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

Rules the store enforces, all verified in the captured run:

- A key marked *require signature* **refuses a bearer token**, answering `401 fs_api_signature_required`.
- A signature computed with the wrong secret answers `401 fs_api_bad_signature`.
- A reused nonce answers `409 fs_api_replay`.
- A timestamp outside ±300 s answers `401 fs_api_stale_request`.
- If a key does not require signing but signature headers are present, they are still verified.

### Scopes

| Scope | Grants |
|---|---|
| `read:risk` | `GET /ping`, `GET /stats`, `POST /risk/evaluate` |
| `read:customers` | `GET /customers/{ident}` |
| `read:orders` | `GET /orders`, `GET /orders/{id}` |
| `read:events` | `GET /events` |
| `read:blocklist` | `GET /blocklist` |
| `read:verified` | `GET /verified` |
| `read:idv` | `GET /idv/sessions` |
| `write:blocklist` | `POST /blocklist`, `DELETE /blocklist/{id}`, and `GET /blocklist` |
| `write:verified` | `POST /verified`, `DELETE /verified`, and `GET /verified` |
| `write:idv` | `POST /idv/require`, and `GET /idv/sessions` |
| `manage:webhooks` | all `/webhooks` routes |

A write scope implies its matching read. There is no wildcard scope. Grant the narrowest set that works.

`read:events` returns the same payloads a webhook subscriber receives, including emails, names and IPs where the topic carries them. It is not a reduced view — treat it as equivalent in sensitivity to a subscription.

### Rate limits

Fixed one-minute window per key. Real headers, captured from three consecutive requests on a key limited to 3/minute:

```
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 2
X-RateLimit-Reset: 1787167200
```
```
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 1
X-RateLimit-Reset: 1787167200
```
```
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1787167200
```

The fourth request, captured verbatim:

```
HTTP/1.1 429
Cache-Control: no-store, no-cache, must-revalidate, max-age=0
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
X-RateLimit-Limit: 3
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1787167200
Retry-After: 9
```

```json
{
  "code": "fs_api_rate_limited",
  "message": "Rate limit exceeded. Slow down and try again shortly.",
  "data": {
    "status": 429,
    "retry_after": 5
  }
}
```

### Response headers

Every response in this namespace, success or failure, carries:

```json
{
  "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
  "X-Content-Type-Options": "nosniff",
  "Referrer-Policy": "no-referrer",
  "X-RateLimit-Limit": "120",
  "X-RateLimit-Remaining": "119",
  "X-RateLimit-Reset": "1787167380"
}
```

Risk answers are per-customer and must never be cached by an intermediary, which is what `no-store` is there for.

---

## Errors

| Status | Codes | Meaning |
|---|---|---|
| 400 | `fs_api_bad_type`, `fs_api_bad_value`, `fs_api_no_signals`, `fs_api_empty_payload`, `fs_api_bad_identifier`, `fs_hook_bad_url`, `fs_hook_insecure`, `fs_hook_private`, `fs_hook_unresolved`, `fs_hook_no_topics` | Malformed request |
| 401 | `fs_api_no_credentials`, `fs_api_unauthorized`, `fs_api_signature_required`, `fs_api_bad_signature`, `fs_api_stale_request`, `fs_api_bad_nonce` | Authentication failed |
| 403 | `fs_api_forbidden`, `fs_api_insecure`, `fs_api_bad_pairing`, `fs_api_no_pairing` | Authenticated but not permitted |
| 404 | `fs_api_not_found` | No such customer, order or subscription |
| 409 | `fs_api_replay`, `fs_api_idv_unavailable` | Conflicts with current state |
| 413 | `fs_api_body_too_large` | Body over 256 KB |
| 415 | `fs_api_unsupported_media` | Send JSON; multipart is refused |
| 429 | `fs_api_rate_limited` | Slow down |
| 503 | `fs_api_disabled` | The API is switched off on this store |

Real error bodies, captured:

```json
{ "code": "fs_api_no_credentials", "message": "Authentication required.", "data": { "status": 401 } }
```
```json
{ "code": "fs_api_unauthorized", "message": "Authentication failed.", "data": { "status": 401 } }
```
```json
{ "code": "fs_api_forbidden", "message": "This key does not carry the read:orders scope.", "data": { "status": 403 } }
```
```json
{ "code": "fs_api_signature_required", "message": "This key requires signed requests.", "data": { "status": 401 } }
```

**Authentication failures are deliberately uniform.** Whether the key id is unknown, the secret is wrong, the key was revoked or expired, or the caller's address is not allow-listed, the answer is the same `401 fs_api_unauthorized`. The distinction is recorded in the store's own security log, not returned to the caller.

---

## Pairing handshake

Use this to connect an external platform without a human copying a permanent secret between two systems.

An administrator opens a pairing window in *Fraud Shield → API & Webhooks → Pair an external platform*, choosing the scopes the platform may hold and how long the window stays open. That produces a single-use code — a real one from the run:

```
fspc_1b232ce3f08bf1ef7dd9d6da673b6a964ff72b6e
```

### `POST /handshake`

No credential required. Rate limited to 10 attempts per hour per IP. Five wrong guesses burn the window.

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

**Response — `201`, captured verbatim**

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

Store `credential.token` and `webhook.secret` immediately — neither is retrievable afterwards. Key your records on `store_id`.

**Wrong code — `403`**

```json
{ "code": "fs_api_bad_pairing", "message": "That pairing code is not valid.", "data": { "status": 403 } }
```

**Replaying a used code — `403`** (the window closed on first success)

```json
{ "code": "fs_api_no_pairing", "message": "No pairing is open on this store.", "data": { "status": 403 } }
```

Verified in the captured run: the paired credential works when it signs, and is refused `403` when it attempts a write outside its scopes.

If the callback URL is rejected (not HTTPS, resolves privately, does not resolve), the credential is still issued and `webhook` carries an `error` field instead — a bad URL must not cost the platform its key.

---

## Endpoint reference

### GET /ping

**Scope:** any valid key. Confirms a credential and reports what the store can do.

**Response — `200`, captured verbatim**

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
  "time": "2026-08-19T19:22:37+00:00",
  "key": {
    "key_id": "3ec576b720cd1043",
    "label": "bonesfraud.com (full)",
    "scopes": [
      "read:risk", "read:customers", "read:orders", "read:events",
      "read:blocklist", "read:verified", "read:idv",
      "write:blocklist", "write:verified", "write:idv", "manage:webhooks"
    ],
    "signed": false
  },
  "features": {
    "mode": "enforce",
    "id_verification": true,
    "cardholder_check": true,
    "webhook_topics": [
      "order.scored", "order.flagged", "checkout.blocked",
      "customer.verified", "customer.unverified", "customer.blocked",
      "idv.approved", "idv.declined", "idv.name_mismatch",
      "card.checked", "restriction.applied", "restriction.released", "test.ping"
    ]
  }
}
```

`features.mode` is `observe` or `enforce`. In `observe`, heuristic scores are recorded but never block; the blocklist and the explicit locks still apply.

---

### GET /stats

**Scope:** `read:risk`. The numbers a dashboard opens with.

**Response — `200`, captured verbatim**

```json
{
  "orders_indexed": 7,
  "chargebacks": 1,
  "high_risk": 1,
  "medium_risk": 0,
  "blocklist": 2,
  "checkout_blocks": 0,
  "id_verification": {
    "total": 2,
    "approved": 1,
    "name_mismatch": 1,
    "declined": 0,
    "expired": 0,
    "pending": 0,
    "people": 2
  },
  "webhooks": {
    "subscriptions": 0,
    "events": 0,
    "pending": 0,
    "delivered": 0,
    "failed": 0
  }
}
```

`high_risk` counts orders at level `high` **or** `block`. `checkout_blocks` is the number of unresolved entries on the Checkout Locks screen.

---

### POST /risk/evaluate

**Scope:** `read:risk`. The most useful endpoint here: score an identity that has no order yet, and learn what this store's checkout would do with it right now.

**Request fields** — at least one of `email`, `phone`, `ip`, `address`, `user_id` is required.

| Field | Type | Notes |
|---|---|---|
| `email` | string | Normalized exactly as an order's would be (gmail dots and `+tags` collapsed) |
| `phone` | string | Reduced to digits; a leading US `1` is dropped |
| `ip` | string | Discarded if not a valid IP |
| `name` | string | Used for name-cluster signals |
| `user_id` | int | WordPress account id, if known |
| `amount` | number | Drives the high-value rules |
| `gateway` | string | Adds a per-gateway allow/deny answer |
| `shipping_method` | string | Feeds the expedited-shipping signal |
| `address` | object | `address_1`, `address_2`, `city`, `state`, `postcode`, `country` |
| `exclude_order_id` | int | Exclude one order from the history lookup |

#### Example 1 — an unknown, clean identity

**Request**

```json
{
  "email": "new.shopper@example.com",
  "ip": "203.0.113.90",
  "amount": 120,
  "name": "Alex Turner",
  "address": {
    "address_1": "18 Cranbrook Road",
    "city": "Austin",
    "state": "TX",
    "postcode": "78701",
    "country": "US"
  }
}
```

**Response — `200`, captured verbatim**

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
    "verified": false,
    "verified_account": false,
    "id_verified": false,
    "id_check_required": false,
    "velocity_locked": false,
    "verification_hold": false,
    "blocklist_matches": [],
    "blocklist_links": [],
    "linked_sufficient": false,
    "high_value_rule": null
  },
  "gateway": null,
  "profile": {
    "chargeback_email": false,
    "chargeback_phone": false,
    "chargeback_ship": false,
    "chargeback_ship_loose": false,
    "chargeback_ip": false,
    "emails_at_address": 1,
    "names_at_address": 1,
    "addresses_for_email": 1,
    "accounts_at_ip": 0,
    "orders_7d": 0,
    "orders_30d": 0,
    "orders_burst": 0,
    "account_age_days": null,
    "disposable_email": false,
    "country_mismatch": false,
    "bill_ship_mismatch": 0,
    "pay_orders": 0,
    "pay_card_orders": 0,
    "pay_card_only": false,
    "fast_shipping": false
  }
}
```

#### Example 2 — a drop address with a chargeback history and a blocklisted IP

**Request**

```json
{
  "email": "new.buyer2201@gmail.com",
  "phone": "+1 305 555 7712",
  "ip": "198.51.100.66",
  "amount": 680,
  "name": "R Castillo",
  "gateway": "stripe_cc",
  "address": {
    "address_1": "2201 NW 82nd Ave",
    "city": "Doral",
    "state": "FL",
    "postcode": "33122",
    "country": "US"
  }
}
```

**Response — `200`, captured verbatim**

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
    "verified": false,
    "verified_account": false,
    "id_verified": false,
    "id_check_required": false,
    "velocity_locked": false,
    "verification_hold": false,
    "blocklist_matches": ["ip"],
    "blocklist_links": [],
    "linked_sufficient": false,
    "high_value_rule": null
  },
  "gateway": { "id": "stripe_cc", "allowed": false },
  "profile": {
    "chargeback_email": false,
    "chargeback_phone": true,
    "chargeback_ship": true,
    "chargeback_ship_loose": true,
    "chargeback_ip": true,
    "emails_at_address": 3,
    "names_at_address": 3,
    "addresses_for_email": 1,
    "accounts_at_ip": 2,
    "orders_7d": 0,
    "orders_30d": 0,
    "orders_burst": 0,
    "account_age_days": null,
    "disposable_email": false,
    "country_mismatch": false,
    "bill_ship_mismatch": 0,
    "pay_orders": 0,
    "pay_card_orders": 0,
    "pay_card_only": false,
    "fast_shipping": false
  }
}
```

Note `newbuyer2201@gmail.com` in the response: gmail dots are collapsed, so an ad-hoc lookup lands on the same identity a real order would.

#### Example 3 — a customer over the velocity threshold

**Request**

```json
{ "email": "dana.reeve@example.com", "user_id": 5109, "amount": 415 }
```

**Response — `200`, captured verbatim** (abridged to the fields that changed)

```json
{
  "decision": "block",
  "reason": "velocity",
  "risk": {
    "score": 35,
    "level": "low",
    "reasons": [
      "Customer first seen 5 days before this order",
      "3 prior orders in the last 7 days"
    ]
  },
  "state": {
    "verified": false,
    "velocity_locked": true,
    "blocklist_matches": [],
    "blocklist_links": [],
    "linked_sufficient": false,
    "high_value_rule": null
  },
  "profile": {
    "orders_7d": 3,
    "orders_30d": 3,
    "account_age_days": 5,
    "pay_orders": 3
  }
}
```

This is the case where `decision` and `risk.level` deliberately disagree: the heuristic score is only 35 (`low`), but the velocity lock is an independent control and it refuses the checkout. **Always act on `decision`, not on `risk.level`.**

#### Example 4 — a trusted customer above the hard high-value limit

**Request**

```json
{ "email": "cole.smith@example.com", "user_id": 4182, "amount": 6200 }
```

**Response — `200`, captured verbatim** (abridged)

```json
{
  "decision": "verify",
  "reason": "high_value",
  "risk": { "score": 0, "level": "low", "reasons": [] },
  "state": {
    "verified": true,
    "verified_account": false,
    "id_verified": false,
    "high_value_rule": {
      "mode": "hard",
      "tier": "staff",
      "threshold": 5000,
      "amount": 6200
    }
  },
  "profile": {
    "account_age_days": 399,
    "pay_orders": 2,
    "idv_name_mismatch": false,
    "idv_vpn": false
  }
}
```

An otherwise-trusted customer is still asked to verify above the hard threshold. Only the staff *Verified account* flag is exempt from that rule.

#### Empty payload — `400`

```json
{ "code": "fs_api_empty_payload", "message": "Provide at least one identity signal.", "data": { "status": 400 } }
```

---

### GET /customers/{ident}

**Scope:** `read:customers`. `{ident}` is a URL-encoded email address, or `id:<number>` for a WordPress account.

```bash
curl -s 'https://store.example.com/wp-json/fraud-shield/v1/customers/id:4182' \
  -H 'Authorization: Bearer fsk_...'
```

**Response — `200`, captured verbatim**

```json
{
  "identity": {
    "email_norm": "cole.smith@example.com",
    "user_id": 4182,
    "name": "Cole Smith",
    "registered": "2025-07-15T19:19:50-04:00"
  },
  "history": {
    "orders": 2,
    "paid_orders": 2,
    "spend": 531.5,
    "average_order_value": 265.75,
    "chargebacks": 0,
    "distinct_ips": 1,
    "distinct_addresses": 1,
    "distinct_names": 1,
    "distinct_phones": 1,
    "distinct_gateways": 1,
    "distinct_countries": 1,
    "max_risk_score": 0,
    "first_order": "2025-10-23T19:19:50-04:00",
    "last_order": "2026-04-21T19:19:50-04:00"
  },
  "state": {
    "verified_account": false,
    "auto_verified": true,
    "email_verified": true,
    "id_verified": false,
    "id_check_required": false,
    "velocity_locked": false,
    "verification_hold": false,
    "gateway_restriction": null
  }
}
```

A guest identity with orders but no account resolves too, with `user_id: 0` and `registered: null`:

```json
{
  "identity": {
    "email_norm": "jokafordeals@gmail.com",
    "user_id": 0,
    "name": "J Okafor",
    "registered": null
  },
  "history": {
    "orders": 1,
    "paid_orders": 1,
    "spend": 705.25,
    "average_order_value": 705.25,
    "chargebacks": 0,
    "distinct_ips": 1,
    "distinct_addresses": 1,
    "distinct_names": 1,
    "distinct_phones": 1,
    "distinct_gateways": 1,
    "distinct_countries": 1,
    "max_risk_score": 165,
    "first_order": "2026-08-10T19:19:50-04:00",
    "last_order": "2026-08-10T19:19:50-04:00"
  },
  "state": {
    "verified_account": false,
    "auto_verified": false,
    "email_verified": false,
    "id_verified": false,
    "id_check_required": false,
    "velocity_locked": false,
    "verification_hold": false,
    "gateway_restriction": null
  }
}
```

**Not found — `404`**

```json
{ "code": "fs_api_not_found", "message": "No such customer.", "data": { "status": 404 } }
```

`auto_verified` means the customer earned trust from history (old enough, enough paid orders, no refunds or chargebacks) rather than from a staff decision.

---

### GET /orders

**Scope:** `read:orders`.

| Parameter | Notes |
|---|---|
| `level` | `none` `low` `medium` `high` `block` |
| `email` | Normalized before matching |
| `chargeback` | `1` or `0` |
| `from`, `to` | `2026-08-01`, `2026-08-01 13:45:00` (store's timezone), or a zoned ISO instant like `2026-08-01T00:00:00-04:00`, which is converted into the store's timezone — so the `date` this endpoint returns can be fed straight back in |
| `limit` | 1–100, default 50 |
| `offset` | default 0 |

**Response — `200`, captured verbatim for `?limit=2`**

```json
{
  "total": 7,
  "limit": 2,
  "offset": 0,
  "orders": [
    {
      "order_id": 51901,
      "date": "2026-08-17T19:17:14-04:00",
      "user_id": 5109,
      "email_norm": "dana.reeve@example.com",
      "name": "Dana Reeve",
      "total": 415,
      "status": "processing",
      "gateway": "Credit Card",
      "ip": "198.51.100.201",
      "address_id": "5b4d5b8f1a1ee22cb0cb370cc06422313e15aaa14ad5f9ea528311a475998426",
      "geo": { "country": "US", "state": "NY", "city": "new york", "postcode": "10012" },
      "chargeback": false,
      "risk": {
        "score": 15,
        "level": "low",
        "reasons": ["Customer first seen 4 days before this order"]
      }
    },
    {
      "order_id": 51930,
      "date": "2026-08-15T19:17:14-04:00",
      "user_id": 5109,
      "email_norm": "dana.reeve@example.com",
      "name": "Dana Reeve",
      "total": 415,
      "status": "processing",
      "gateway": "Credit Card",
      "ip": "198.51.100.201",
      "address_id": "5b4d5b8f1a1ee22cb0cb370cc06422313e15aaa14ad5f9ea528311a475998426",
      "geo": { "country": "US", "state": "NY", "city": "new york", "postcode": "10012" },
      "chargeback": false,
      "risk": {
        "score": 15,
        "level": "low",
        "reasons": ["Customer first seen 2 days before this order"]
      }
    }
  ]
}
```

An empty result is a normal `200`:

```json
{ "total": 0, "limit": 3, "offset": 0, "orders": [] }
```

`address_id` is the store's internal address hash. Two orders sharing an address share this value, which makes it useful for correlation without handling the address itself.

---

### GET /orders/{id}

**Scope:** `read:orders`.

**Response — `200`, captured verbatim**

```json
{
  "order_id": 51802,
  "date": "2026-08-10T19:17:14-04:00",
  "user_id": 0,
  "email_norm": "jokafordeals@gmail.com",
  "name": "J Okafor",
  "total": 705.25,
  "status": "completed",
  "gateway": "Credit Card",
  "ip": "198.51.100.66",
  "address_id": "4367737119a2aa85db47887b1bdff61d7d281912fd2716c97616abe402ce76b6",
  "geo": { "country": "US", "state": "FL", "city": "doral", "postcode": "33122" },
  "chargeback": false,
  "risk": {
    "score": 165,
    "level": "block",
    "reasons": [
      "Shipping address was used on a prior charged-back order",
      "Phone number was used on a prior charged-back order",
      "Order IP was used on a prior charged-back order"
    ]
  }
}
```

**Not indexed — `404`**

```json
{ "code": "fs_api_not_found", "message": "That order is not in the index.", "data": { "status": 404 } }
```

---

### GET /blocklist

**Scope:** `read:blocklist` or `write:blocklist`. Parameters: `limit` (1–100), `offset`.

**Response — `200`, captured verbatim**

```json
{
  "total": 2,
  "limit": 5,
  "offset": 0,
  "entries": [
    {
      "id": 1,
      "type": "email",
      "value": "fraud.ring@example.com",
      "reason": "Chargeback ring, order 51774",
      "created_at": "2026-08-19T15:17:55-04:00"
    },
    {
      "id": 2,
      "type": "ip",
      "value": "198.51.100.66",
      "reason": "Shared drop-address IP",
      "created_at": "2026-08-19T15:17:55-04:00"
    }
  ]
}
```

---

### POST /blocklist

**Scope:** `write:blocklist`.

| Field | Required | Notes |
|---|---|---|
| `type` | yes | `email` `phone` `address` `ip` `user` `place` |
| `value` | yes | Normalized before storage |
| `reason` | no | Defaults to a note naming the API key |
| `message` | no | Shown to the customer at checkout |

`address` accepts a raw address string or a precomputed 64-char hash. `place` is a destination lock formatted `city:US|FL|miami`, `state:US|FL`, `zip:US|331` or, since 1.25, `street:US|FL|miami|n main st` — the last segment is the street **name** as the store normalizes it (house number and unit stripped, abbreviations applied, directionals kept), so `n main st` is what `123 North Main Street, Apt 4` becomes. A street lock created with no `message` carries the store's default street-lock message.

Entries are owned by a **reversible block action**, exactly like one created in wp-admin, so staff can undo them in one click.

**Request**

```json
{
  "type": "email",
  "value": "Carder.Test+alias@Gmail.com",
  "reason": "Card testing, 14 declines in 3 minutes"
}
```

**Response — `201`, captured verbatim**

```json
{
  "ok": true,
  "type": "email",
  "value": "cardertest@gmail.com",
  "action_id": 1
}
```

Note the normalization: `Carder.Test+alias@Gmail.com` is stored as `cardertest@gmail.com`, so alias variants cannot walk around the block.

**A destination lock**

```json
{ "type": "place", "value": "zip:US|331", "reason": "Destination lock: repeated reship fraud" }
```

```json
{ "ok": true, "type": "place", "value": "zip:US|331", "action_id": 4 }
```

This creates a genuine `place` action, so it appears on — and is releasable from — the Geography screen. A place lock never cascades onto anyone's credentials.

**Invalid type — `400`**

```json
{
  "code": "fs_api_bad_type",
  "message": "type must be one of: email, phone, address, ip, user, place.",
  "data": { "status": 400 }
}
```

---

### DELETE /blocklist/{id}

**Scope:** `write:blocklist`. `{id}` is the blocklist entry id from `GET /blocklist`.

```json
{ "ok": true }
```

---

### GET /verified

**Scope:** `read:verified` or `write:verified`.

Verified credentials are the inverse of the blocklist: a known-good list that bypasses the velocity lock, the new-account hold and gateway restrictions — but **never** the blocklist. An explicit block always wins.

**Response — `200`, captured verbatim**

```json
{
  "total": 1,
  "limit": 5,
  "offset": 0,
  "credentials": [
    {
      "type": "email",
      "value": "cole.smith@example.com",
      "label": "Long-standing customer, verified by support",
      "source": "",
      "added_at": "2026-08-19T15:17:55-04:00"
    }
  ]
}
```

`source` is `""` for a staff decision and `"idv"` when earned by a completed ID check.

---

### POST /verified

**Scope:** `write:verified`. Either flag an account, or verify a single credential.

**Account**

```json
{ "user_id": 5109 }
```

```json
{ "ok": true, "user_id": 5109, "scope": "account" }
```

**Credential** — `type` is `email`, `address` or `ip`.

```json
{ "type": "email", "value": "trusted.buyer@example.com", "label": "Verified by support ticket 8812" }
```

```json
{ "ok": true, "type": "email", "added": true }
```

`added` is `false` when the credential was already on the list. Both responses are `201`.

---

### DELETE /verified

**Scope:** `write:verified`. Same body shape as `POST`; withdraws the verification.

```json
{ "ok": true }
```

---

### GET /idv/sessions

**Scope:** `read:idv` or `write:idv`. Parameters: `result` (`approved` `name_mismatch` `declined` `expired` `pending`), `search`, `limit`, `offset`.

**Response — `200`, captured verbatim**

```json
{
  "total": 2,
  "limit": 5,
  "offset": 0,
  "sessions": [
    {
      "session_id": "9b1deb4d3b7d4bad9bdd2b0d7b3dcb6d",
      "user_id": 5109,
      "email_norm": "dana.reeve@example.com",
      "status": "Approved",
      "result": "name_mismatch",
      "name_match": "no",
      "doc_type": "Passport",
      "doc_country": "US",
      "vpn": true,
      "order_id": 51962,
      "created_at": "2026-08-19T15:17:55-04:00",
      "updated_at": "2026-08-19T15:17:55-04:00"
    },
    {
      "session_id": "f47ac10b58cc4372a5670e02b2c3d479",
      "user_id": 4182,
      "email_norm": "cole.smith@example.com",
      "status": "Approved",
      "result": "approved",
      "name_match": "yes",
      "doc_type": "Driving Licence",
      "doc_country": "US",
      "vpn": false,
      "order_id": 50984,
      "created_at": "2026-08-19T15:17:55-04:00",
      "updated_at": "2026-08-19T15:17:55-04:00"
    }
  ]
}
```

Note `status` is the vendor's own session status while `result` is Fraud Shield's verdict: a document can be `Approved` by the vendor and still land on `name_mismatch` here. Document images, document numbers and dates of birth never reach this store and are never returned.

---

### POST /idv/require

**Scope:** `write:idv`. Demands an ID check before the account may check out again. The customer sees the request at checkout with a button that clears it.

```json
{ "user_id": 5109 }
```

```json
{ "ok": true, "user_id": 5109 }
```

`email` is accepted instead, provided it belongs to a registered account. A guest cannot carry an account-scoped demand:

```json
{
  "code": "fs_api_bad_value",
  "message": "An ID check can only be demanded from a registered account. Provide user_id or a registered email.",
  "data": { "status": 400 }
}
```

`409 fs_api_idv_unavailable` when ID verification is not configured on the store.

---

### GET /events

**Scope:** `read:events`. Forward-only cursor over the event log — the supported alternative to receiving webhooks.

| Parameter | Notes |
|---|---|
| `after` | Cursor. Start at `0`, then pass the previous `next_cursor` |
| `topic` | Optional single topic filter |
| `limit` | 1–100, default 50 |

**Response — `200`, captured verbatim for `?after=0&limit=3`**

```json
{
  "events": [
    {
      "id": "evt_1",
      "topic": "customer.blocked",
      "occurred_at": "2026-08-19T19:19:51+00:00",
      "store": {
        "store_id": "357a7b151fbabfa135907704094e267c",
        "url": "https://store.example.com/",
        "version": "1.24.0"
      },
      "data": {
        "action_id": 3,
        "kind": "manual",
        "target": "email:cardertest@gmail.com",
        "title": "Email: cardertest@gmail.com"
      }
    },
    {
      "id": "evt_2",
      "topic": "customer.blocked",
      "occurred_at": "2026-08-19T19:19:51+00:00",
      "store": {
        "store_id": "357a7b151fbabfa135907704094e267c",
        "url": "https://store.example.com/",
        "version": "1.24.0"
      },
      "data": {
        "action_id": 4,
        "kind": "place",
        "target": "zip:US|331",
        "title": "ZIP 331* (US) — prefix lock"
      }
    },
    {
      "id": "evt_3",
      "topic": "customer.verified",
      "occurred_at": "2026-08-19T19:19:51+00:00",
      "store": {
        "store_id": "357a7b151fbabfa135907704094e267c",
        "url": "https://store.example.com/",
        "version": "1.24.0"
      },
      "data": {
        "scope": "credential",
        "type": "email",
        "email_norm": "trusted.buyer@example.com",
        "key": "email:trusted.buyer@example.com",
        "origin": ""
      }
    }
  ],
  "next_cursor": 3,
  "has_more": true
}
```

**Paging.** `next_cursor` is the numeric row id of the last event returned — note it is `3` here, not `"evt_3"`. Pass it as `after` on the next call. Keep going while `has_more` is true. A captured walk of the full log with `limit=3` completed in 3 pages (`next_cursor` moving `3 → 6 → 8`), returning all 8 events exactly once with no repeats.

Events are recorded whenever at least one key **or** one subscription exists, so polling works with no subscriptions at all.

---

### GET /webhooks

**Scope:** `manage:webhooks`. Returns every topic the store publishes plus the current subscriptions. **Secrets are never included.**

**Response — `200`, captured verbatim (abridged to two subscriptions)**

```json
{
  "topics": {
    "order.scored": "An order was risk-scored (every order).",
    "order.flagged": "An order scored medium risk or worse.",
    "checkout.blocked": "A checkout attempt was refused or locked.",
    "customer.verified": "A customer or credential was verified.",
    "customer.unverified": "A verification was withdrawn.",
    "customer.blocked": "A customer, address, IP or destination was blocked.",
    "idv.approved": "An ID verification was approved.",
    "idv.declined": "An ID verification was declined or abandoned.",
    "idv.name_mismatch": "An ID was genuine but the name did not match.",
    "card.checked": "A cardholder check returned an issuer verdict.",
    "restriction.applied": "A customer was put on restricted checkout.",
    "restriction.released": "A restricted checkout was lifted.",
    "test.ping": "Test event, sent only on request."
  },
  "webhooks": [
    {
      "id": 3,
      "url": "http://127.0.0.1:8787/hooks/hashed",
      "label": "Hashed identifiers receiver",
      "topics": ["order.flagged"],
      "status": "active",
      "hash_pii": true,
      "failures": 0,
      "last_status": 0,
      "last_error": "",
      "last_delivered": null,
      "created_at": "2026-08-19T19:17:55+00:00"
    },
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

---

### POST /webhooks

**Scope:** `manage:webhooks`.

| Field | Required | Notes |
|---|---|---|
| `url` | yes | Must be HTTPS and must resolve outside private address space |
| `topics` | no | Array, or `"*"` for every topic except `test.ping` |
| `label` | no | Defaults to the API key's label |
| `hash_pii` | no | Send digests instead of identifiers |

**Response — `201`**

```json
{
  "ok": true,
  "id": 3,
  "secret": "fswh_04a601a1d23f8f96f4c2fec22e964dc052361e6efe6ef2f0",
  "topics": ["order.flagged", "checkout.blocked"],
  "notice": "Store this secret now. It cannot be retrieved again."
}
```

**Refusals, captured verbatim.** These are the SSRF guard, and they are the reason "add a webhook" is not a probe against your internal network:

```json
{ "code": "fs_hook_insecure", "message": "Webhook URLs must use HTTPS.", "data": { "status": 400 } }
```

```json
{
  "code": "fs_hook_private",
  "message": "That URL resolves to a private or reserved address. Enable local delivery in the API settings if this is a development endpoint.",
  "data": { "status": 400 }
}
```

The first was `http://169.254.169.254/latest/meta-data/` (the cloud metadata service); the second `https://127.0.0.1/hooks`. Every address a hostname resolves to is checked, IPv4 and IPv6, and the check runs again immediately before each delivery.

---

### DELETE /webhooks/{id}

**Scope:** `manage:webhooks`. Deletes the subscription and its delivery history.

```json
{ "ok": true }
```

---

### POST /webhooks/{id}/test

**Scope:** `manage:webhooks`. Delivers a `test.ping` synchronously and reports what the subscriber said.

**Response — `200`, captured verbatim**

```json
{ "ok": true, "status": 200 }
```

On a transport failure:

```json
{ "ok": false, "error": "cURL error 7: Failed to connect to ..." }
```

---

## Webhooks

### Delivery format

`POST` with `Content-Type: application/json; charset=utf-8`. Real headers, captured from a live delivery:

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
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": { "...topic-specific..." },
  "delivery": { "id": 2, "attempt": 1 }
}
```

Answer `2xx` as soon as you have stored the event; do your processing afterwards. The store's timeout is 10 seconds by default. Redirects are not followed — point the subscription at the final URL.

### Verifying a delivery

Compute `HMAC-SHA256("<t>.<raw body>", secret)` and compare against `v1` in constant time. Reject anything whose `t` is more than a few minutes old. Use the **raw** body bytes — re-serialising parsed JSON will change the signature.

The code below is exactly what the live harness used to verify all 15 deliveries in the captured run; every one returned *signature valid*.

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

### Real payload for every topic

All 13 bodies below were captured from live deliveries; the receiver independently verified every signature.

#### `order.scored` — every order, as soon as it is scored

```json
{
  "id": "evt_9",
  "topic": "order.scored",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "order_id": 51901,
    "user_id": 5109,
    "email_norm": "dana.reeve@example.com",
    "name": "Dana Reeve",
    "total": 415,
    "currency": "USD",
    "gateway": "bacs",
    "status": "processing",
    "ip": "198.51.100.201",
    "risk": {
      "score": 15,
      "level": "low",
      "reasons": ["Customer first seen 4 days before this order"]
    }
  },
  "delivery": { "id": 7, "attempt": 1 }
}
```

#### `order.flagged` — same payload, only for `medium` / `high` / `block`

```json
{
  "id": "evt_5",
  "topic": "order.flagged",
  "occurred_at": "2026-08-19T19:17:55+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "order_id": 51802,
    "user_id": 0,
    "email_norm": "jokafordeals@gmail.com",
    "name": "J Okafor",
    "total": 705.25,
    "currency": "USD",
    "gateway": "stripe_cc",
    "status": "processing",
    "ip": "198.51.100.66",
    "risk": {
      "score": 62,
      "level": "high",
      "reasons": [
        "3 identities at this shipping address",
        "Address linked to a prior chargeback"
      ]
    }
  },
  "delivery": { "id": 2, "attempt": 1 }
}
```

Subscribe to `order.scored` for a complete feed, or `order.flagged` for only the orders worth a human's attention. Subscribing to both means two deliveries per flagged order.

#### `checkout.blocked` — a refused or locked checkout attempt

```json
{
  "id": "evt_6",
  "topic": "checkout.blocked",
  "occurred_at": "2026-08-19T19:17:55+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "kind": "velocity",
    "user_id": 5109,
    "email_norm": "dana.reeve@example.com",
    "name": "Dana Reeve",
    "detail": "3 orders in 30 days"
  },
  "delivery": { "id": 5, "attempt": 1 }
}
```

`kind` is `blocklist`, `velocity`, `idv` or `linked` (the customer shares a credential with a blocked identity and the linked auto-cancel rule cancelled their order, or refused a repeat attempt; `detail` names only the shared credential *types*, e.g. `shares ip, address with a blocked identity` — never the blocked party's identifiers). Coalesced to one event per identity and rule per 5 minutes, matching the ledger behind the Checkout Locks screen — a bot retrying a blocked address does not become a flood.

#### `customer.verified`

```json
{
  "id": "evt_7",
  "topic": "customer.verified",
  "occurred_at": "2026-08-19T19:19:51+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "scope": "credential",
    "type": "email",
    "email_norm": "second.trusted@example.com",
    "key": "email:second.trusted@example.com",
    "origin": ""
  },
  "delivery": { "id": 4, "attempt": 1 }
}
```

`scope` is `credential` or `account`. For `account`, `data` carries `user_id` and `email_norm` instead of `type`/`key`. `origin` is `""` for a staff decision, `"idv"` when earned by an ID check.

#### `customer.unverified`

```json
{
  "id": "evt_10",
  "topic": "customer.unverified",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "scope": "credential",
    "type": "email",
    "email_norm": "second.trusted@example.com",
    "key": "email:second.trusted@example.com"
  },
  "delivery": { "id": 8, "attempt": 1 }
}
```

#### `customer.blocked` — one event per merchant decision, not per cascaded credential

```json
{
  "id": "evt_8",
  "topic": "customer.blocked",
  "occurred_at": "2026-08-19T19:19:51+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "action_id": 5,
    "kind": "manual",
    "target": "email:live.run.block@example.com",
    "title": "Email: live.run.block@example.com"
  },
  "delivery": { "id": 5, "attempt": 1 }
}
```

`kind` is `manual`, `profile`, `address`, `ip`, `place` or `import`. A single block can cascade onto many credentials; you receive one event.

#### `idv.approved`

```json
{
  "id": "evt_13",
  "topic": "idv.approved",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
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
  },
  "delivery": { "id": 12, "attempt": 1 }
}
```

#### `idv.name_mismatch` — a genuine document in the wrong name

```json
{
  "id": "evt_14",
  "topic": "idv.name_mismatch",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
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
  },
  "delivery": { "id": 13, "attempt": 1 }
}
```

This is the highest-signal event in the set: the vendor's `status` is `Approved` — the document is real — but the name on it is not the customer's, and the session came through a VPN. That is the stolen-ID pattern the check exists to catch. It is also what an innocent married name or transliteration looks like, so treat it as a review trigger, not a verdict.

#### `idv.declined` — declined, expired or abandoned

```json
{
  "id": "evt_15",
  "topic": "idv.declined",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
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
  },
  "delivery": { "id": 14, "attempt": 1 }
}
```

Note the empty strings: a declined session never produced a document name, so `full_name`, `doc_type`, `doc_country` and `name_match` are all blank. `order_id: 0` means the session was not tied to an order.

#### `card.checked` — the card issuer's verdict, after payment

```json
{
  "id": "evt_16",
  "topic": "card.checked",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
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
  },
  "delivery": { "id": 15, "attempt": 1 }
}
```

`cardholder: "mismatch"` with `id_verified: true` is the stolen-card-with-real-ID case: the person proved who they are, and the issuer says the card is not theirs. Arrives on cron after payment, never during checkout.

#### `restriction.applied`

```json
{
  "id": "evt_11",
  "topic": "restriction.applied",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "user_id": 5109,
    "email_norm": "dana.reeve@example.com",
    "gateways": ["bacs", "cod"]
  },
  "delivery": { "id": 9, "attempt": 1 }
}
```

`gateways` is the set the customer may still use.

#### `restriction.released`

```json
{
  "id": "evt_12",
  "topic": "restriction.released",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "user_id": 5109,
    "email_norm": "dana.reeve@example.com"
  },
  "delivery": { "id": 10, "attempt": 1 }
}
```

#### `test.ping` — only when someone presses **Send test**

```json
{
  "id": "evt_0",
  "topic": "test.ping",
  "occurred_at": "2026-08-19T19:22:37+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "message": "Fraud Shield webhook test.",
    "sent_by": 1
  },
  "delivery": { "id": 0, "attempt": 1 }
}
```

Test deliveries always carry `evt_0` and `delivery.id: 0` — they are not stored in the event log. `sent_by` is the WordPress user id of whoever pressed the button. Subscribing to `"*"` does **not** include this topic.

### Hashed identifiers

Tick **Send hashed identifiers** on a subscription and these fields are replaced by `<field>_sha256` — lower-cased then SHA-256, recursively, at every depth:

`email`, `email_norm`, `name`, `phone`, `ip`, `expected_name`, `full_name`, `target`, `title`, `key`, `last4`

The last four are easy to overlook: a blocklist event's `target` and `title` embed whatever was blocked, a verified-credential event's `key` embeds the raw email or IP, and `card.checked` carries the card's last four digits.

Here is the **same event** delivered to two subscriptions in the same dispatch run — one plain, one hashed. Both captured verbatim:

```json
{
  "id": "evt_5",
  "topic": "order.flagged",
  "occurred_at": "2026-08-19T19:17:55+00:00",
  "store": {
    "store_id": "357a7b151fbabfa135907704094e267c",
    "url": "https://store.example.com/",
    "version": "1.24.0"
  },
  "data": {
    "order_id": 51802,
    "user_id": 0,
    "email_norm_sha256": "a3c9e943ca95f5d9c7e947e75b1b311b4ad495969c1292be4582b19d429df0dd",
    "name_sha256": "5e07127dbf3093e7c0e41c68f2eeca0792ad1faea48aa34479c26f69641b27d9",
    "total": 705.25,
    "currency": "USD",
    "gateway": "stripe_cc",
    "status": "processing",
    "ip_sha256": "83e61ecb3b828367bb73e4da8dec1ad9382020caf853292b6ea0bb9a4e9edb72",
    "risk": {
      "score": 62,
      "level": "high",
      "reasons": [
        "3 identities at this shipping address",
        "Address linked to a prior chargeback"
      ]
    }
  },
  "delivery": { "id": 3, "attempt": 1 }
}
```

The digest is stable, so a receiver can group events by customer without holding the identifier. It is **not** a secret: an unsalted SHA-256 of an email address can be confirmed by anyone who already has a candidate address to test. Use it for correlation, not as an anonymisation guarantee.

Note the signature differs between the two deliveries (`23b57d21…` plain vs `25101ddee…` hashed) because the bodies differ. Each subscription has its own secret.

### Retries and failure

Delivery happens on cron, never inside the request that produced the event, so a slow subscriber can never slow down a customer's checkout.

- Up to **6 attempts** by default (configurable 1–12).
- Exponential backoff: 1, 2, 4, 8, 16 minutes, capped at 6 hours.
- A subscription that fails **20 times consecutively** is switched off and shown as failing in the admin. Re-enabling clears the strike count.
- A subscription whose hostname stops resolving publicly is switched off at delivery time rather than retried.
- Deliveries are **at-least-once**. Deduplicate on `X-FS-Event`.

Real retry state, captured after a subscriber deliberately answered `HTTP 500`:

```
delivery:      status=pending  attempts=1  response_code=500
               next_attempt_at=2026-08-19 19:20:51 (UTC, one minute later)
               error=Endpoint returned HTTP 500.
subscription:  failures=1  last_status=500  last_error=Endpoint returned HTTP 500.
```

The event itself is retained even past the retention window while a delivery is still pending, so a slow-to-recover subscriber does not lose payloads mid-backoff. Events and delivery history are otherwise pruned after 30 days by default.

---

## Value glossary

**`decision`** (from `POST /risk/evaluate`) — act on this, not on `risk.level`:

| Value | Meaning |
|---|---|
| `allow` | Nothing outstanding |
| `review` | Allowed, but scored `medium` or `high` — worth a human look |
| `restrict` | Allowed only on a limited set of payment methods |
| `verify` | Allowed only after an ID check |
| `block` | Refused |

**`reason`** — `""`, `blocklist`, `linked_blocklist`, `id_verification_requested`, `velocity`, `verification_hold`, `high_value`, `risk_score`.

`linked_blocklist` (since 1.26.0) means the store's linked auto-cancel rule is on and this identity shares a credential with a blocked identity without matching the blocklist itself, and the links are enough to act on; a real checkout would create the order, cancel it before payment, and refuse repeat attempts. The links are listed under `state.blocklist_links`, each with `via` (`email` / `phone` / `ip` / `address` / `user`), `value` (addresses as hashes), `on_order` (on the submitted signals, or from an earlier order), `degree` (`1` = the credential is itself blocked, `2` = another identity using it is blocked), `blocked_type` / `blocked_value`, `block_card`, and `identities` — the accounts and profiles behind the blocked credential, each `{ email, user_id, name, blocked }` (up to three, blocked first; never the identity being evaluated). `state.linked_sufficient` says whether those links would actually cancel: an IP-only link never does (an IP counts only alongside another shared credential), so a response can carry an `ip` link with `linked_sufficient: false` and `decision: allow`. The array is empty whenever the rule is off, the identity matches the blocklist outright, or the identity is verified.

**`risk.level`** — `none`, `low`, `medium`, `high`, `block`. Thresholds are merchant-configurable, so compare levels rather than raw scores across stores.

**`high_value_rule`** — `null`, or `{ mode, tier, threshold, amount }`. `mode` is `soft` (invite to verify) or `hard` (refuse until verified). `tier` names the trust band that set the threshold: `none`, `auto`, `idv` or `staff`.

**IDV `result`** — `approved`, `name_mismatch`, `declined`, `expired`, or `""` while still in flight.

**IDV `name_match`** — `yes`, `no`, `n/a` (name matching switched off, or no name on file), or `""` when the session never reached a name comparison.

**IDV `status`** — the vendor's own session status: `Not Started`, `In Progress`, `Approved`, `Declined`, `In Review`, `Expired`, `Abandoned`, `Kyc Expired`.

**`card.checked` `outcome`** — `pass`, `partial`, `fail`, `unavailable`, `notfound`, `error`.

**`card.checked` `cardholder`** — `confirmed`, `mismatch`, or `""` when the customer is not ID-verified so the two cannot be correlated.

**`avs`** — the issuer's address verdict. `Y`/`X`/`D`/`M` are full matches; `A`/`Z`/`W`/`B`/`P` partial; `N`/`C` no match; anything else means the check did not run.

**`cvv`** — `M` matched, `N` did not match, `P` not processed, `S`/`U` unavailable.

**Blocklist `type`** — `email`, `phone`, `address` (64-char hash), `ip`, `user` (account id), `place` (destination lock).

---

## Security model

**Credentials.** Secrets are 192 bits of CSPRNG randomness, stored only as SHA-256 digests and displayed exactly once. Verification is one indexed lookup plus one `hash_equals` compare. A stolen database yields no working keys.

**Transport.** HTTPS required by default, refusals explicit (`403 fs_api_insecure`). Responses are `no-store` and `nosniff` with `Referrer-Policy: no-referrer`.

**Least privilege.** Per-key scopes with no wildcard, an optional IP/CIDR allow-list that fails closed, an optional expiry, and a per-key rate limit.

**Replay.** Signed requests bind method, path, query, body digest, timestamp and nonce. The ±300 s window plus a 600 s nonce cache means a captured request is either a duplicate nonce or an expired timestamp. `multipart/form-data` is refused, because PHP consumes such a body before it can be read as raw bytes — the signature would commit to nothing.

**Caller address.** Taken from the transport connection, **not** `X-Forwarded-For`. Reading a caller-controlled header would make IP allow-lists decorative. Trusting proxy headers is an explicit opt-in for stores behind a proxy that overwrites them.

**SSRF.** Webhook URLs must be HTTPS, must not carry credentials, and must not resolve to private, loopback, link-local or reserved addresses — checked across every resolved address (IPv4 and IPv6), and re-checked immediately before every delivery. Residual risk: the gap between our lookup and the HTTP client's own cannot be closed without pinning the connection, so treat the ability to add webhooks as privileged. It is gated on `manage_woocommerce` or the `manage:webhooks` scope.

**Audit.** Authentication failures, rate limiting, scope denials, credential lifecycle and pairing activity are logged on the API screen, keyed by the *public* key id. No secret, token or pairing code is ever written there. Caller-triggered entries are coalesced to one per minute per event and address, so a stranger cannot rewrite the log on every request nor flush real evidence out of it with a burst.

**Blast radius.** No scope grants access to card numbers, ID images, document numbers or dates of birth — the store never holds them. Blocklist writes go through the reversible block-action path, so an API mistake is one click to undo.

**Deliberate omission.** Webhook signing secrets are stored in plaintext, because signing a payload requires the secret itself and WordPress offers no key management that would meaningfully protect it. Treat database access as equivalent to holding those secrets; rotate by deleting and recreating a subscription.

---

## Performance characteristics

The plugin's hot path is WooCommerce checkout, and this subsystem is built to stay off it.

- Routes register on `rest_api_init`, which never fires on a storefront page load.
- Authentication is one indexed lookup on a unique key plus one hash compare.
- "Last used" accounting is throttled to one write per key per minute.
- Rate limiting uses the object cache atomically when one exists, transients otherwise — never the database.
- Event emission short-circuits on a single autoloaded array read when no key and no subscription exists, so a store that never enabled the API pays essentially nothing.
- Recording an event is two indexed inserts. Delivery is cron work with a 20-second budget per run.
- Every list endpoint is hard-capped at 100 rows and every filter lands on an indexed column. The dispatcher's queue query uses a composite `(status, next_attempt_at)` index.
- Bodies over 256 KB are refused before being hashed.
