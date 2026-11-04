# OSAM Bookings ↔ WHMCS: subscription status

From version 2.8.0, OSAM Bookings can switch its own booking system off when OSAM's billing says a site's subscription is suspended, and switch it back on once it's paid. The plugin side is finished. This document covers what the billing side (WHMCS) needs to provide.

The plugin never connects to WHMCS directly and never holds WHMCS API credentials. It only knows:

1. **A status URL it asks (pull).** It checks hourly, and every 10 minutes while the site is overdue or suspended.
2. **A webhook on the site that billing can call (push).** This applies a change straight away.

Billing can provide either one, or both. Pull alone works, but changes can take up to an hour to apply. Push alone works too, but if a call is missed, nothing corrects it later. Both together are recommended.

---

## 1. Per-site set-up

Each client site needs:

| Item | Where it lives in WHMCS (suggested) | Where it goes on the site |
|---|---|---|
| **Subscription key**: a random secret of at least 32 characters, unique to the site | Product custom field, e.g. `Booking key` (admin-only) | `define( 'OSB_LICENCE_KEY', '…' );` in wp-config.php (recommended), or Bookings → Settings → Subscription |
| **Site URL**, e.g. `https://client.co.uk/` | The service's **Domain** field | Detected automatically (`home_url('/')`) |
| **Service ID** (optional) | The WHMCS service ID | `define( 'OSB_LICENCE_SERVICE', '1042' );` or the settings field |
| **Status URL** (pull) | Wherever the endpoint below is hosted | `define( 'OSB_LICENCE_URL', 'https://…' );` or the settings field. If left empty, the default is `https://connect.osamweb.com/wp-json/osam-connect/v1/licence` |

Generate a key with, for example, `bin2hex(random_bytes(24))`.

Setting the key in wp-config.php locks the Subscription settings, so they can't be changed from the WordPress dashboard. Without a key the site is "not linked", and bookings always stay on.

---

## 2. Signing (both directions)

Every message is signed with the site's key:

```
signature = hex( HMAC-SHA256( key, timestamp + "." + payload ) )
```

- `timestamp` is Unix seconds, sent in the `X-OSB-Timestamp` header.
- The signature is sent in the `X-OSB-Signature` header.
- A message more than **5 minutes** old is rejected, so both servers need accurate clocks (NTP).

---

## 3. Statuses

| `status` | What the site does |
|---|---|
| `active` | Normal. |
| `overdue` | Normal, but shows a "payment overdue" warning in the client's dashboard. Optional `grace_until` gives the date it will be switched off. |
| `suspended` | Switches off according to `mode` (default `bookings`). |
| `terminated` | Switches off according to `mode` (default `full`). |

| `mode` | Effect |
|---|---|
| `bookings` | No new bookings: booking forms show a message, and staff can't add bookings. Staff can still see and manage existing bookings. Customers can still view or cancel their own bookings from their email links. Reminders still go out. |
| `full` | The same as `bookings`, plus every booking admin screen shows a "suspended" notice and scheduled jobs (reminders, follow-ups, custom emails, calendar sync) pause. |

Nothing is ever deleted. When billing reports `active` again, everything carries on as before.

Optional fields, used in both directions:

- `message` (shown to the client in their dashboard, max 500 characters)
- `due_date` (YYYY-MM-DD)
- `grace_until` (YYYY-MM-DD)
- `invoice_url` (link to the WHMCS invoice)

**Fail-safe:** if the site can't reach the status URL, gets an error, or gets an answer it can't verify, it keeps the last known status. A billing outage never switches a site off.

**Suggested mapping from WHMCS:**

| WHMCS | Send |
|---|---|
| Service Active, nothing overdue | `active` |
| Invoice overdue, not yet suspended | `overdue` + `grace_until` = the date your suspension cron will act |
| Service Suspended | `suspended` (mode `bookings`, or `full` if OSAM prefers) |
| Service Terminated / Cancelled | `terminated` |

---

## 4. Pull: the status endpoint billing provides

The site sends:

```
GET {status URL}?site=https%3A%2F%2Fclient.co.uk%2F&service=1042&plugin=osam-bookings&version=2.8.0
X-OSB-Site:      https://client.co.uk/
X-OSB-Timestamp: 1798451200
X-OSB-Signature: hex(HMAC-SHA256(key, "1798451200.https://client.co.uk/"))
```

Billing should:

1. Find the service (by `service`, or by the Domain field matching `site`) and read its key.
2. Verify the signature and the timestamp. If either fails, answer `401`.
3. Answer `200` with a JSON body, **signed over the exact body bytes**:

```
HTTP/1.1 200 OK
Content-Type: application/json
X-OSB-Timestamp: 1798451201
X-OSB-Signature: hex(HMAC-SHA256(key, "1798451201." + body))

{"site":"https://client.co.uk/","status":"overdue","grace_until":"2026-11-10","invoice_url":"https://billing.osamweb.com/viewinvoice.php?id=9"}
```

If `site` is included, it must match the site exactly (a trailing slash doesn't matter), or the site ignores the answer. Unsigned answers are always ignored.

The endpoint can live on the OSAM Connector (connect.osamweb.com, which then asks WHMCS using `localAPI` / the API) or as a small script on the WHMCS server. **The default URL path on the Connector, `/wp-json/osam-connect/v1/licence`, doesn't exist until someone builds it.** Until then, either build it there or set `OSB_LICENCE_URL` on each site to wherever the endpoint is.

Example core of the endpoint (PHP; adapt it):

```php
$ts   = $_SERVER['HTTP_X_OSB_TIMESTAMP'] ?? '';
$site = $_SERVER['HTTP_X_OSB_SITE'] ?? '';
$key  = lookup_key_for_site_or_service( $site, $_GET['service'] ?? '' ); // from the custom field
if ( ! $key || abs( time() - (int) $ts ) > 300
	|| ! hash_equals( hash_hmac( 'sha256', $ts . '.' . $site, $key ), $_SERVER['HTTP_X_OSB_SIGNATURE'] ?? '' ) ) {
	http_response_code( 401 ); exit;
}
$body = json_encode( array( 'site' => $site, 'status' => status_from_whmcs( $site ) ) );
$now  = (string) time();
header( 'Content-Type: application/json' );
header( 'X-OSB-Timestamp: ' . $now );
header( 'X-OSB-Signature: ' . hash_hmac( 'sha256', $now . '.' . $body, $key ) );
echo $body;
```

---

## 5. Push: the webhook on each site

The webhook address is `https://client.co.uk/wp-json/osb/v1/licence/notify`, also shown under Bookings → Settings → Subscription.

```
POST /wp-json/osb/v1/licence/notify
Content-Type:    application/json
X-OSB-Timestamp: 1798451200
X-OSB-Signature: hex(HMAC-SHA256(key, "1798451200." + body))

{"status":"suspended","mode":"bookings","message":"Invoice #9 is unpaid","invoice_url":"https://…"}
```

Instead of a status, billing can send `{"action":"check"}`. This tells the site to ask the status URL straight away.

| Response | Meaning |
|---|---|
| `200 {"ok":true,"site":"…","status":"suspended","mode":"bookings","version":"2.8.0"}` | Applied |
| `401` | Bad or expired signature |
| `400` | Bad JSON or unknown status |
| `404` | Site has no key set (not linked) |

Suggested WHMCS hooks (in `includes/hooks/`; this is a sketch):

```php
function osb_push( $serviceId, array $data ) {
	$svc  = localAPI( 'GetClientsProducts', array( 'serviceid' => $serviceId ) )['products']['product'][0] ?? null;
	$key  = /* read the "Booking key" custom field from $svc */ '';
	if ( ! $svc || ! $key || empty( $svc['domain'] ) ) { return; }
	$url  = 'https://' . preg_replace( '#^https?://#', '', rtrim( $svc['domain'], '/' ) ) . '/wp-json/osb/v1/licence/notify';
	$body = json_encode( $data );
	$ts   = (string) time();
	$ch   = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
		CURLOPT_HTTPHEADER => array( 'Content-Type: application/json', 'X-OSB-Timestamp: ' . $ts, 'X-OSB-Signature: ' . hash_hmac( 'sha256', $ts . '.' . $body, $key ) ) ) );
	curl_exec( $ch ); curl_close( $ch );
}
add_hook( 'AfterModuleSuspend',   1, function ( $v ) { osb_push( $v['params']['serviceid'], array( 'status' => 'suspended', 'mode' => 'bookings' ) ); } );
add_hook( 'AfterModuleUnsuspend', 1, function ( $v ) { osb_push( $v['params']['serviceid'], array( 'status' => 'active' ) ); } );
add_hook( 'AfterModuleTerminate', 1, function ( $v ) { osb_push( $v['params']['serviceid'], array( 'status' => 'terminated' ) ); } );
// Optional: InvoicePaid → push {"action":"check"} or "active" for the services on that invoice;
// a daily cron hook → push "overdue" with grace_until for services with overdue invoices.
```

Note: `AfterModule*` hooks only fire for products that use a server/provisioning module. For products without a module, use `InvoicePaid` together with a daily check, or rely on pull.

---

## 6. Testing

1. On a test site, set a key and point `OSB_LICENCE_URL` at the endpoint.
2. Under Bookings → Settings → Subscription, click **Check now**. Status, last checked and any error are shown there.
3. Push `{"status":"suspended"}`. The booking page should show the "unavailable" message.
4. Push `{"status":"active"}`. The booking form should come back.
