<?php

use G2Bulk\Client;
use G2Bulk\Exception\BadRequestException;
use G2Bulk\Exception\ConnectionException;
use G2Bulk\Exception\RateLimitException;
use G2Bulk\Exception\ServerException;
use G2Bulk\Transport;

function fake_transport(array $responses, array &$calls): callable
{
    return function (string $method, string $url, array $headers, ?string $body, float $timeout) use (&$responses, &$calls): array {
        $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return [
            'status' => $next['status'],
            'headers' => $next['headers'] ?? ['content-type' => 'application/json'],
            'body' => $next['body'] ?? '',
        ];
    };
}

test('retries GET on 503 then succeeds', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 503, 'body' => '{}'],
        ['status' => 200, 'body' => '{"success":true,"games":[]}'],
    ], $calls)]);
    assert_same([], $g2->games->list());
    assert_same(2, count($calls));
});

test('gives up after max_retries and raises the matching exception', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'max_retries' => 1, 'transport' => fake_transport([
        ['status' => 500, 'body' => '{"message":"boom"}'],
        ['status' => 500, 'body' => '{"message":"boom"}'],
    ], $calls)]);
    assert_throws(ServerException::class, function () use ($g2) {
        $g2->account->me();
    }, function (ServerException $e) {
        return $e->getMessage() === 'boom' && $e->getStatus() === 500 && $e->getBody() === ['message' => 'boom'];
    });
    assert_same(2, count($calls));
});

test('honours Retry-After and exposes it', function () {
    $calls = [];
    $limited = ['status' => 429, 'body' => '{"message":"slow down"}', 'headers' => ['Retry-After' => '0']];
    $g2 = new Client('k', ['base_url' => 'http://x', 'max_retries' => 1, 'transport' => fake_transport([$limited, $limited], $calls)]);
    $started = microtime(true);
    assert_throws(RateLimitException::class, function () use ($g2) {
        $g2->account->me();
    }, function (RateLimitException $e) {
        return $e->getRetryAfter() === 0.0 && $e->getHeaders()['retry-after'] === '0';
    });
    assert_same(2, count($calls));
    assert_true(microtime(true) - $started < 0.3, 'Retry-After 0 means no backoff');
});

test('orders always send an idempotency key and are retried with the same key', function () {
    $calls = [];
    $ok = '{"success":true,"order":{"order_id":1,"status":"PENDING"}}';
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 502, 'body' => ''],
        ['status' => 200, 'body' => $ok],
    ], $calls)]);
    $order = $g2->games->order('pubgm', ['catalogue_name' => '60 UC', 'player_id' => '1']);
    assert_same(1, $order['order_id']);
    assert_same(2, count($calls));
    $first = $calls[0]['headers']['X-Idempotency-Key'];
    assert_same(36, strlen($first));
    assert_true((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first), 'uuid v4');
    assert_same($first, $calls[1]['headers']['X-Idempotency-Key']);
    assert_same($calls[0]['body'], $calls[1]['body']);
    $body = json_decode($calls[0]['body'], true);
    assert_same(['catalogue_name' => '60 UC', 'player_id' => '1'], $body);
    assert_same('application/json', $calls[0]['headers']['Content-Type']);
    assert_same('http://x/v1/games/pubgm/order', $calls[0]['url']);
});

test('unkeyed POSTs are not retried', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 503, 'body' => '{"message":"down"}'],
    ], $calls)]);
    assert_throws(ServerException::class, function () use ($g2) {
        $g2->webhooks->rotateSecret();
    });
    assert_same(1, count($calls));
});

test('transport errors become ConnectionException', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'max_retries' => 0, 'transport' => fake_transport([new \RuntimeException('connection refused')], $calls)]);
    assert_throws(ConnectionException::class, function () use ($g2) {
        $g2->account->me();
    }, function (ConnectionException $e) {
        return strpos($e->getMessage(), 'connection refused') !== false;
    });
});

test('transport errors are retried for GET', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'max_retries' => 1, 'transport' => fake_transport([
        new \RuntimeException('reset'),
        ['status' => 200, 'body' => '{"success":true,"user_id":1,"balance":5.5}'],
    ], $calls)]);
    assert_same(5.5, $g2->account->me()['balance']);
    assert_same(2, count($calls));
});

test('plain-text error bodies keep their message', function () {
    $calls = [];
    $text = 'invalid idempotency key: invalid length: 5 != 36';
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 400, 'body' => $text, 'headers' => ['content-type' => 'text/plain']],
    ], $calls)]);
    assert_throws(BadRequestException::class, function () use ($g2) {
        $g2->purchases->create(1, ['quantity' => 1, 'idempotency_key' => 'short']);
    }, function (BadRequestException $e) use ($text) {
        return $e->getMessage() === $text && $e->getErrorCode() === 'invalid_idempotency_key' && $e->getStatus() === 400;
    });
    assert_same('short', $calls[0]['headers']['X-Idempotency-Key']);
});

test('error message falls back through error and detail.message', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 404, 'body' => '{"error":"nope"}'],
        ['status' => 401, 'body' => '{"detail":{"message":"bad key"}}'],
    ], $calls)]);
    assert_throws(\G2Bulk\Exception\NotFoundException::class, function () use ($g2) {
        $g2->purchases->get(1);
    }, function ($e) {
        return $e->getMessage() === 'nope';
    });
    assert_throws(\G2Bulk\Exception\AuthenticationException::class, function () use ($g2) {
        $g2->account->me();
    }, function ($e) {
        return $e->getMessage() === 'bad key';
    });
});

test('public endpoints send no API key', function () {
    $calls = [];
    $g2 = new Client('secret', ['base_url' => 'http://x/', 'transport' => fake_transport([
        ['status' => 200, 'body' => '{"success":true,"products":[]}'],
    ], $calls)]);
    $g2->catalog->products();
    assert_true(!isset($calls[0]['headers']['X-API-Key']), 'no X-API-Key header');
    assert_same(0, strpos($calls[0]['headers']['User-Agent'], 'g2bulk-php/'));
    assert_same('g2bulk-php/' . Client::VERSION, $calls[0]['headers']['User-Agent']);
    assert_same('http://x/v1/products', $calls[0]['url']);
});

test('query strings drop empty values and encode summary', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 200, 'body' => '{"success":true,"orders":[],"pagination":{"page":1,"limit":2,"total":0,"total_pages":0},"summary":{"orders":0}}'],
    ], $calls)]);
    $page = $g2->purchases->list(['limit' => 2, 'summary' => true, 'status' => null, 'search' => '', 'product_id' => 4]);
    assert_same('http://x/v1/orders?limit=2&summary=1&product_id=4', $calls[0]['url']);
    assert_same(['orders' => 0], $page['summary']);
    assert_same([], $page['items']);
});

test('delivery states map from status codes', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 202, 'body' => '{"success":true,"status":"PROCESSING"}'],
        ['status' => 410, 'body' => '{"success":false,"status":"FAILED"}'],
        ['status' => 200, 'body' => '{"success":true,"delivery_items":["A"]}'],
    ], $calls)]);
    assert_same('processing', $g2->purchases->delivery(1)['state']);
    assert_same('failed', $g2->purchases->delivery(1)['state']);
    $ready = $g2->purchases->delivery(1);
    assert_same('ready', $ready['state']);
    assert_same(['A'], $ready['delivery_items']);
});

test('object transports are accepted', function () {
    $transport = new class implements Transport {
        public array $calls = [];

        public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
        {
            $this->calls[] = [$method, $url, $timeout];
            return ['status' => 200, 'headers' => [], 'body' => '{"success":true,"categories":[{"id":1}]}'];
        }
    };
    $g2 = new Client('k', ['base_url' => 'http://x', 'timeout' => 5, 'transport' => $transport]);
    assert_same([['id' => 1]], $g2->catalog->categories());
    assert_same([['GET', 'http://x/v1/category', 5.0]], $transport->calls);
});

test('missing API key is reported before any request', function () {
    $calls = [];
    $saved = getenv('G2BULK_API_KEY');
    putenv('G2BULK_API_KEY');
    unset($_ENV['G2BULK_API_KEY'], $_SERVER['G2BULK_API_KEY']);
    try {
        $g2 = new Client(null, ['base_url' => 'http://x', 'transport' => fake_transport([], $calls)]);
        assert_throws(\G2Bulk\Exception\G2BulkException::class, function () use ($g2) {
            $g2->account->me();
        });
        assert_same(0, count($calls));
    } finally {
        if ($saved !== false) {
            putenv('G2BULK_API_KEY=' . $saved);
        }
    }
});

test('money fields always come back as floats', function () {
    $calls = [];
    $g2 = new Client('k', ['base_url' => 'http://x', 'transport' => fake_transport([
        ['status' => 200, 'body' => '{"success":true,"user_id":1,"balance":500}'],
        ['status' => 200, 'body' => '{"success":true,"product":{"id":4,"unit_price":2,"face_value":null}}'],
        ['status' => 200, 'body' => '{"success":true,"products":[{"id":4,"unit_price":2,"face_value":10}]}'],
        ['status' => 200, 'body' => '{"success":true,"game":{"code":"g"},"catalogues":[{"id":1,"name":"100 Gems","amount":3}]}'],
        ['status' => 200, 'body' => '{"success":true,"order":{"order_id":1,"price":3,"status":"PENDING"}}'],
        ['status' => 200, 'body' => '{"success":true,"orders":[{"order_id":1,"price":3}],"pagination":{"page":1,"limit":20,"total":1,"total_pages":1},"summary":{"orders":1,"spend":3,"refunded":0,"median_seconds":42}}'],
        ['status' => 200, 'body' => '{"success":true,"orders":[{"id":1,"total_price":"2.00"}],"pagination":{"page":1,"limit":20,"total":1,"total_pages":1},"summary":{"orders":1,"spend":2,"refunded":0,"median_seconds":null}}'],
        ['status' => 200, 'body' => '{"success":true,"data":[{"id":1,"amount":"2.00","balance_before":"500.00","balance_after":"498.00"}],"pagination":{"page":1,"limit":20,"total":1,"total_pages":1}}'],
    ], $calls)]);
    assert_same(500.0, $g2->account->me()['balance']);
    $product = $g2->catalog->product(4);
    assert_same(2.0, $product['unit_price']);
    assert_same(null, $product['face_value']);
    $products = $g2->catalog->products();
    assert_same(2.0, $products[0]['unit_price']);
    assert_same(10.0, $products[0]['face_value']);
    assert_same(3.0, $g2->games->catalogue('g')['catalogues'][0]['amount']);
    assert_same(3.0, $g2->games->getOrder(1)['price']);
    $games = $g2->games->listOrders(['summary' => true]);
    assert_same(3.0, $games['items'][0]['price']);
    assert_same(['orders' => 1, 'spend' => 3.0, 'refunded' => 0.0, 'median_seconds' => 42], $games['summary']);
    $orders = $g2->purchases->list(['summary' => true]);
    assert_same('2.00', $orders['items'][0]['total_price']);
    assert_same(null, $orders['summary']['median_seconds']);
    assert_same(2.0, $orders['summary']['spend']);
    $txn = $g2->transactions->list()['items'][0];
    assert_same(['id' => 1, 'amount' => '2.00', 'balance_before' => '500.00', 'balance_after' => '498.00'], $txn);
});
