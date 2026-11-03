# G2Bulk PHP SDK

Official PHP client for the [G2Bulk](https://api.g2bulk.com) reseller API: buy digital products, place game top-ups, track orders and verify signed webhooks.

- PHP 7.4 or newer, with `ext-curl` and `ext-json`. No other dependencies.
- Works with Composer, or with a single `require` on shared hosting and WordPress.
- Retries safe requests automatically and makes every order idempotent.

## Installation

### With Composer

```bash
composer require g2bulk/g2bulk-php
```

```php
require __DIR__ . '/vendor/autoload.php';
```

### Without Composer (WordPress, shared hosting)

Download the package, copy the folder into your project (for example `wp-content/plugins/my-shop/lib/g2bulk-php`), and require its autoloader:

```php
require __DIR__ . '/lib/g2bulk-php/autoload.php';
```

## Quickstart

```php
use G2Bulk\Client;

$g2 = new Client('YOUR_API_KEY');

$me = $g2->account->me();
echo "Balance: {$me['balance']}\n";

$order = $g2->purchases->create(4, ['quantity' => 1]);
if ($order['status'] === 'COMPLETED') {
    print_r($order['delivery_items']);
}
```

### Client options

```php
$g2 = new Client('YOUR_API_KEY', [
    'base_url'    => 'https://api.g2bulk.com',
    'timeout'     => 30,
    'max_retries' => 2,
]);
```

| Option        | Default                  | Notes                                                        |
| ------------- | ------------------------ | ------------------------------------------------------------ |
| `base_url`    | `https://api.g2bulk.com` | Falls back to the `G2BULK_BASE_URL` environment variable.    |
| `timeout`     | `30`                     | Seconds per HTTP attempt.                                    |
| `max_retries` | `2`                      | Extra attempts for retryable requests. `0` disables retries. |
| `transport`   | curl                     | A callable or a `G2Bulk\Transport` (see [Testing](#testing-your-integration)). |

If you pass `null` as the API key, the client reads `G2BULK_API_KEY` from the environment (`getenv`, `$_ENV` or `$_SERVER`). Public catalogue and game lookups work without a key; everything else needs one.

Every method returns plain associative arrays with the same snake_case keys as the API.

Numeric money fields are always PHP `float`, even when the API sends a whole number such as `500`: `balance`, product `unit_price` and `face_value` (which may be `null`), catalogue `amount`, game order `price`, and the `spend` and `refunded` totals in list summaries. Money that the API sends as strings stays a string so no precision is lost: `total_price` on product orders, and `amount`, `balance_before` and `balance_after` on transactions. Summary `median_seconds` is returned as is (`int` or `null`).

## Resources

### Account

```php
$me = $g2->account->me();
```

### Catalog (public, no API key needed)

```php
$categories = $g2->catalog->categories();
$products   = $g2->catalog->categoryProducts($categories[0]['id']);
$all        = $g2->catalog->products();
$product    = $g2->catalog->product(4);
echo $product['unit_price'];
```

### Purchases

```php
$order = $g2->purchases->create(4, [
    'quantity'        => 2,
    'description'     => 'invoice #1001',
    'callback_url'    => 'https://example.com/g2bulk/callback',
    'idempotency_key' => $myUuid,
]);
```

Inventory products complete immediately and return `delivery_items`. Provider products return `PENDING`; wait for the codes:

```php
$delivery = $g2->purchases->waitForDelivery($order['order_id'], 3, 120);
if ($delivery['state'] === 'ready') {
    print_r($delivery['delivery_items']);
}
```

`delivery($id)` checks once and adds `state`: `ready` (200), `processing` (202) or `failed` (410).

```php
$page   = $g2->purchases->list(['page' => 1, 'limit' => 20, 'status' => 'completed', 'product_id' => 4, 'summary' => true]);
$items  = $page['items'];
$paging = $page['pagination'];
$totals = $page['summary'];

$single = $g2->purchases->get($order['order_id']);
$csv    = $g2->purchases->exportCsv(['from' => '2026-11-01', 'to' => '2026-11-30']);
```

List filters: `page`, `limit`, `search`, `status` (`completed`, `in_progress`, `failed`, `cancelled`), `from`, `to`, `tz`, `source` (`website`, `api`, `bot`, `miniapp`, `smm`), `summary`. Exports require `from` and `to`.

### Transactions

```php
$page = $g2->transactions->list(['page' => 1, 'limit' => 50, 'search' => 'charge']);
foreach ($page['items'] as $t) {
    echo "{$t['transaction_type']} {$t['amount']}\n";
}
```

### Games

```php
$games  = $g2->games->list();
$fields = $g2->games->fields('pubgm');
$serversOrNull = $g2->games->servers('mlbb');

$check = $g2->games->validatePlayer('mlbb', '123456', '2001');
if (!$check['valid']) {
    exit('Unknown player');
}
echo $check['name'];

$catalogue = $g2->games->catalogue('mlbb');
$eta = $g2->games->eta('mlbb', '100 Diamonds');

$order = $g2->games->order('mlbb', [
    'catalogue_name'  => '100 Diamonds',
    'player_id'       => '123456',
    'server_id'       => '2001',
    'charname'        => null,
    'remark'          => 'customer 42',
    'callback_url'    => 'https://example.com/g2bulk/topup',
    'idempotency_key' => $myUuid,
]);

$status = $g2->games->status($order['order_id'], 'mlbb');
$final  = $g2->games->waitForOrder($order['order_id'], 5, 600);

$page = $g2->games->listOrders(['game' => 'mlbb', 'status' => 'completed', 'limit' => 50, 'summary' => true]);
$one  = $g2->games->getOrder($order['order_id']);
$csv  = $g2->games->exportCsv(['from' => '2026-11-01', 'to' => '2026-11-30', 'game' => 'mlbb']);
```

`servers()` returns `null` for games that have no server list. `validatePlayer()` returns `['valid' => bool, 'name' => string]` (plus `openid` when the game has one); an unknown player is `valid => false`, not an exception. `waitForOrder()` returns once the order is `COMPLETED` or `FAILED`.

### Webhooks

```php
$secret = $g2->webhooks->rotateSecret();
$status = $g2->webhooks->secret();
$test   = $g2->webhooks->sendTest('https://example.com/g2bulk/webhook');

$page = $g2->webhooks->deliveries(['limit' => 20, 'status' => 'failed', 'order_id' => 123]);
$g2->webhooks->resend($page['items'][0]['id']);
```

`rotateSecret()` returns the new `whsec_...` secret once. Store it; the old one stops working.

## Verifying webhooks

G2Bulk signs every webhook with the [Standard Webhooks](https://www.standardwebhooks.com) scheme. Always verify against the raw request body, before decoding it.

```php
$event = \G2Bulk\Webhook::verify($secret, $headers, $rawBody);
```

`verify()` checks the `v1` HMAC-SHA256 signature (any of several space-separated signatures may match), rejects timestamps more than 300 seconds away (pass a different `$tolerance` as the fourth argument), and returns the decoded event array. It throws `G2Bulk\Exception\WebhookVerificationException` on any problem. Header names are matched case-insensitively, and `$_SERVER` style keys (`HTTP_WEBHOOK_ID`) work too. `$g2->webhooks->verify(...)` is the same call.

### Plain PHP

```php
<?php
require __DIR__ . '/lib/g2bulk-php/autoload.php';

use G2Bulk\Exception\WebhookVerificationException;
use G2Bulk\Webhook;

$secret = getenv('G2BULK_WEBHOOK_SECRET');

try {
    $event = Webhook::verify($secret, $_SERVER, file_get_contents('php://input'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    exit;
}

if ($event['event'] === 'topup.completed') {
    markPaid($event['order_id']);
}

http_response_code(200);
```

### Laravel

```php
use G2Bulk\Exception\WebhookVerificationException;
use G2Bulk\Webhook;
use Illuminate\Http\Request;

Route::post('/g2bulk/webhook', function (Request $request) {
    try {
        $event = Webhook::verify(
            config('services.g2bulk.webhook_secret'),
            $request->headers->all(),
            $request->getContent()
        );
    } catch (WebhookVerificationException $e) {
        abort(400);
    }

    ProcessG2BulkEvent::dispatch($event);

    return response()->noContent();
});
```

Exclude the route from CSRF protection (`VerifyCsrfToken::$except`, or `validateCsrfTokens(except: [...])` in Laravel 11+).

Events carry `event_id` (use it to deduplicate), `event` (`topup.completed`, `topup.failed`, order events, or `ping` for tests) and `order_id`.

## Errors

Every error thrown by the SDK extends `G2Bulk\Exception\G2BulkException`.

| Exception                      | When                                                        |
| ------------------------------ | ----------------------------------------------------------- |
| `BadRequestException`          | 400. `getErrorCode()` tells common cases apart.             |
| `AuthenticationException`      | 401, missing or invalid API key.                            |
| `PermissionDeniedException`    | 403, for example an IP that is not on your allowlist.       |
| `NotFoundException`            | 404, unknown product, order, game or catalogue item.        |
| `RateLimitException`           | 429. `getRetryAfter()` returns seconds, or `null`.          |
| `ServerException`              | 5xx after retries.                                          |
| `ConnectionException`          | DNS, TLS, timeout or connection failures after retries.     |
| `WaitTimeoutException`         | `waitForDelivery` / `waitForOrder` ran out of time.         |
| `WebhookVerificationException` | A webhook failed verification.                              |

`getMessage()` is the API's message, `getStatus()` the HTTP status, `getBody()` the decoded response body and `getHeaders()` the lower-cased response headers.

`BadRequestException::getErrorCode()` returns one of `insufficient_balance`, `out_of_stock`, `invalid_player`, `invalid_idempotency_key`, or `null`.

```php
use G2Bulk\Exception\BadRequestException;
use G2Bulk\Exception\G2BulkException;

try {
    $g2->purchases->create(4, ['quantity' => 10]);
} catch (BadRequestException $e) {
    if ($e->getErrorCode() === 'insufficient_balance') {
        notifyAdmin('Top up the G2Bulk balance');
    } else {
        echo $e->getMessage();
    }
} catch (G2BulkException $e) {
    error_log('G2Bulk failed: ' . $e->getMessage());
}
```

## Retries

The SDK retries on connection errors and on HTTP 429, 500, 502, 503 and 504, up to `max_retries` times, with exponential backoff and jitter (0.5 s, 1 s, 2 s, ... capped at 8 s). A `Retry-After` header is honoured, up to 60 seconds.

Only requests that are safe to repeat are retried:

- every `GET`;
- the read-only lookups `games->fields`, `servers`, `validatePlayer`, `eta` and `status`;
- `purchases->create` and `games->order`, because they always carry an idempotency key.

Webhook management calls (`sendTest`, `resend`, `rotateSecret`) are never retried.

## Idempotency

Every `purchases->create` and `games->order` call sends an `X-Idempotency-Key`. If you do not pass `idempotency_key`, the SDK generates a UUID v4 and reuses it on its own retries, so a network hiccup never charges you twice.

To survive your own process crashing or a user double-clicking, generate the key yourself, store it with your order, and send the same key when you retry. Repeating a request with the same key returns the original order instead of placing a new one. The key must be exactly 36 characters.

```php
$key = $myOrder->g2bulk_key ?: \G2Bulk\HttpClient::idempotencyKey();
$result = $g2->games->order('mlbb', [
    'catalogue_name'  => '100 Diamonds',
    'player_id'       => '123456',
    'server_id'       => '2001',
    'idempotency_key' => $key,
]);
```

## Testing your integration

Pass a `transport` to run without the network. It receives the method, full URL, headers, JSON body (or `null`) and timeout, and returns `status`, `headers` and `body`:

```php
$g2 = new Client('test', [
    'transport' => function (string $method, string $url, array $headers, ?string $body, float $timeout): array {
        return ['status' => 200, 'headers' => [], 'body' => '{"success":true,"games":[]}'];
    },
]);
```

You can also implement the `G2Bulk\Transport` interface to plug in your own HTTP stack.

## Development

```bash
php tests/run.php          # unit + end-to-end
php tests/run.php unit     # unit only, no network
```

The end-to-end suite reads `../e2e/.env.php` (`G2BULK_BASE_URL`, `G2BULK_API_KEY`, `G2BULK_USER_ID`) and is skipped when that file is missing.

## License

MIT
