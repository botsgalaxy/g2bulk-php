<?php

use G2Bulk\Client;
use G2Bulk\Exception\AuthenticationException;
use G2Bulk\Exception\BadRequestException;
use G2Bulk\Exception\NotFoundException;
use G2Bulk\Exception\WaitTimeoutException;
use G2Bulk\Exception\WebhookVerificationException;
use G2Bulk\HttpClient;
use G2Bulk\Webhook;

$e2eDir = getenv('G2BULK_E2E_DIR') ?: dirname(__DIR__, 2) . '/e2e';
$envFile = $e2eDir . '/.env.php';

function e2e_env(string $file): array
{
    $env = [];
    foreach (explode("\n", (string) file_get_contents($file)) as $line) {
        $i = strpos($line, '=');
        if ($i !== false && $i > 0) {
            $env[substr($line, 0, $i)] = rtrim(substr($line, $i + 1), "\r");
        }
    }
    return $env;
}

function finish_game_order(string $dir, int $id, string $status, float $delay = 0): void
{
    $cmd = escapeshellarg($dir . '/finish-game-order.sh') . ' ' . $id . ' ' . escapeshellarg($status);
    if ($delay > 0) {
        exec('(sleep ' . $delay . '; ' . $cmd . ') > /dev/null 2>&1 &');
        return;
    }
    exec($cmd . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fail('finish-game-order.sh exited with ' . $code);
    }
}

if (!is_file($envFile)) {
    test('e2e', function () use ($envFile) {
        skip('no ' . $envFile);
    });
    return;
}

$env = e2e_env($envFile);
$vector = json_decode((string) file_get_contents(dirname(__DIR__) . '/testdata/webhook-vector.json'), true);
$g2 = new Client($env['G2BULK_API_KEY'], ['base_url' => $env['G2BULK_BASE_URL']]);
$yesterday = gmdate('Y-m-d', time() - 86400);
$tomorrow = gmdate('Y-m-d', time() + 86400);

test('account', function () use ($g2, $env) {
    $me = $g2->account->me();
    assert_same($env['G2BULK_USER_ID'], (string) $me['user_id']);
    assert_true(is_float($me['balance']), 'balance is a float');
    $bad = new Client('invalid', ['base_url' => $env['G2BULK_BASE_URL']]);
    assert_throws(AuthenticationException::class, function () use ($bad) {
        $bad->account->me();
    });
});

test('catalog', function () use ($g2) {
    $categories = $g2->catalog->categories();
    assert_true(count($categories) > 0, 'has categories');
    $withProducts = null;
    foreach ($categories as $c) {
        if (($c['product_count'] ?? 0) > 0) {
            $withProducts = $c;
            break;
        }
    }
    assert_true($withProducts !== null, 'a category has products');
    assert_true(count($g2->catalog->categoryProducts($withProducts['id'])) > 0, 'category products listed');
    $ids = array_column($g2->catalog->products(), 'id');
    assert_true(in_array(4, $ids, true), 'product 4 listed');
    $product = $g2->catalog->product(4);
    assert_same(4, $product['id']);
    assert_true(is_float($product['unit_price']), 'unit_price is a float');
    assert_true($product['face_value'] === null || is_float($product['face_value']), 'face_value is a float or null');
    assert_throws(NotFoundException::class, function () use ($g2) {
        $g2->catalog->product(999999);
    });
});

test('inventory purchase, delivery and idempotency', function () use ($g2) {
    $price = $g2->catalog->product(4)['unit_price'];
    $before = $g2->account->me()['balance'];
    $key = HttpClient::idempotencyKey();
    $first = $g2->purchases->create(4, ['quantity' => 1, 'description' => 'sdk e2e', 'idempotency_key' => $key]);
    $again = $g2->purchases->create(4, ['quantity' => 1, 'description' => 'sdk e2e', 'idempotency_key' => $key]);
    assert_same('COMPLETED', $first['status']);
    assert_same(1, count($first['delivery_items']));
    assert_same($first['order_id'], $again['order_id']);
    $after = $g2->account->me()['balance'];
    assert_true(abs($before - $after - $price) < 0.001, "charged once: $before -> $after");
    $delivery = $g2->purchases->delivery($first['order_id']);
    assert_same('ready', $delivery['state']);
    assert_same($first['delivery_items'], $delivery['delivery_items']);
    $order = $g2->purchases->get($first['order_id']);
    assert_same('COMPLETED', $order['status']);
    assert_same(4, $order['product_id']);
});

test('provider purchase waits for delivery', function () use ($g2) {
    $result = $g2->purchases->create(1, ['quantity' => 1]);
    assert_true(in_array($result['status'], ['PENDING', 'COMPLETED'], true), 'pending or completed');
    $delivery = $g2->purchases->waitForDelivery($result['order_id'], 1, 30);
    assert_same('ready', $delivery['state']);
    assert_same(1, count($delivery['delivery_items']));
});

test('purchase errors', function () use ($g2) {
    $code = function (string $expected) {
        return function (BadRequestException $e) use ($expected) {
            return $e->getErrorCode() === $expected;
        };
    };
    assert_throws(BadRequestException::class, function () use ($g2) {
        $g2->purchases->create(7, ['quantity' => 1]);
    }, $code('out_of_stock'));
    assert_throws(BadRequestException::class, function () use ($g2) {
        $g2->purchases->create(3, ['quantity' => 50]);
    }, $code('insufficient_balance'));
    $e = assert_throws(BadRequestException::class, function () use ($g2) {
        $g2->purchases->create(4, ['quantity' => 1, 'idempotency_key' => 'short']);
    }, $code('invalid_idempotency_key'));
    assert_same('invalid idempotency key: invalid length: 5 != 36', $e->getMessage());
    assert_throws(NotFoundException::class, function () use ($g2) {
        $g2->purchases->get(999999999);
    });
});

test('order list, export and transactions', function () use ($g2, $yesterday, $tomorrow) {
    $page = $g2->purchases->list(['limit' => 2, 'summary' => true]);
    assert_true(count($page['items']) > 0 && count($page['items']) <= 2, '1-2 items');
    assert_true($page['summary']['orders'] >= 2, 'summary counts orders');
    assert_same(2, $page['pagination']['limit']);
    assert_true(is_float($page['summary']['spend']) && is_float($page['summary']['refunded']), 'summary money is float');
    assert_true(is_string($page['items'][0]['total_price']), 'total_price stays a string');
    $filtered = $g2->purchases->list(['product_id' => 4, 'status' => 'completed']);
    assert_true(count($filtered['items']) > 0, 'filtered list not empty');
    foreach ($filtered['items'] as $o) {
        assert_same(4, $o['product_id']);
    }
    $csv = $g2->purchases->exportCsv(['from' => $yesterday, 'to' => $tomorrow]);
    assert_same(0, strpos($csv, 'order_id,'));
    assert_true(strpos($csv, (string) $page['items'][0]['id']) !== false, 'csv contains latest order');
    $txns = $g2->transactions->list(['limit' => 5]);
    assert_true(in_array('charge_balance', array_column($txns['items'], 'transaction_type'), true), 'has charge_balance');
    assert_true(is_string($txns['items'][0]['amount']) && is_string($txns['items'][0]['balance_after']), 'transaction money stays string');
    assert_throws(BadRequestException::class, function () use ($g2) {
        $g2->purchases->exportCsv(['from' => '', 'to' => '']);
    });
});

test('game lookups', function () use ($g2) {
    $codes = array_column($g2->games->list(), 'code');
    assert_true(in_array('demogame', $codes, true), 'demogame listed');
    $fields = $g2->games->fields('demogame');
    assert_true(in_array('userid', $fields['fields'], true), 'userid field');
    assert_same(null, $g2->games->servers('demogame'));
    $check = $g2->games->validatePlayer('demogame', '123456');
    assert_true(is_bool($check['valid']), 'valid is bool');
    assert_true(is_string($check['name']), 'name is string');
    $catalogue = $g2->games->catalogue('demogame');
    assert_same('demogame', $catalogue['game']['code']);
    assert_true(in_array('100 Gems', array_column($catalogue['catalogues'], 'name'), true), '100 Gems listed');
    foreach ($catalogue['catalogues'] as $item) {
        assert_true(is_float($item['amount']), 'catalogue amount is a float');
    }
    $eta = $g2->games->eta('demogame', '100 Gems');
    assert_true(is_string($eta['label']), 'eta label');
    assert_throws(NotFoundException::class, function () use ($g2) {
        $g2->games->catalogue('no_such_game');
    });
});

test('game order lifecycle', function () use ($g2, $e2eDir, $yesterday, $tomorrow) {
    $params = [
        'catalogue_name' => '100 Gems',
        'player_id' => '123456',
        'server_id' => '2001',
        'remark' => 'sdk-e2e',
        'idempotency_key' => HttpClient::idempotencyKey(),
    ];
    $order = $g2->games->order('demogame', $params);
    $again = $g2->games->order('demogame', $params);
    assert_same('PENDING', $order['status']);
    assert_same($order['order_id'], $again['order_id']);
    assert_true(is_float($order['price']), 'order price is a float');
    $status = $g2->games->status($order['order_id'], 'demogame');
    assert_same($order['order_id'], $status['order_id']);
    assert_same('sdk-e2e', $status['remark']);
    $full = $g2->games->getOrder($order['order_id']);
    assert_same('demogame', $full['game_code']);
    assert_true(is_float($full['price']) && is_float($status['price']), 'game order price is a float');
    $listed = $g2->games->listOrders(['game' => 'demogame', 'limit' => 5, 'summary' => true]);
    assert_true(in_array($order['order_id'], array_column($listed['items'], 'order_id'), true), 'order listed');
    assert_true(is_float($listed['items'][0]['price']), 'listed price is a float');
    assert_true(is_float($listed['summary']['spend']) && is_float($listed['summary']['refunded']), 'game summary money is float');
    $median = $listed['summary']['median_seconds'] ?? null;
    assert_true($median === null || is_int($median) || is_float($median), 'median_seconds as returned');
    finish_game_order($e2eDir, $order['order_id'], 'COMPLETED', 1.5);
    $done = $g2->games->waitForOrder($order['order_id'], 0.5, 30);
    assert_same('COMPLETED', $done['status']);
    $csv = $g2->games->exportCsv(['from' => $yesterday, 'to' => $tomorrow, 'game' => 'demogame']);
    assert_true(strpos($csv, (string) $order['order_id']) !== false, 'csv contains order');
});

test('game order errors and wait timeout', function () use ($g2, $e2eDir) {
    assert_throws(NotFoundException::class, function () use ($g2) {
        $g2->games->order('demogame', ['catalogue_name' => 'No Such Pack', 'player_id' => '123456', 'server_id' => '2001']);
    });
    assert_throws(NotFoundException::class, function () use ($g2) {
        $g2->games->status(999999999, 'demogame');
    });
    $order = $g2->games->order('demogame', ['catalogue_name' => '100 Gems', 'player_id' => '123456', 'server_id' => '2001']);
    assert_throws(WaitTimeoutException::class, function () use ($g2, $order) {
        $g2->games->waitForOrder($order['order_id'], 0.5, 1.5);
    });
    finish_game_order($e2eDir, $order['order_id'], 'FAILED');
    $failed = $g2->games->waitForOrder($order['order_id'], 0.5, 5);
    assert_same('FAILED', $failed['status']);
});

test('webhook management', function () use ($g2) {
    $secret = $g2->webhooks->rotateSecret();
    assert_same(0, strpos($secret, 'whsec_'));
    assert_same(true, $g2->webhooks->secret()['has_secret']);
    $eventId = $g2->webhooks->sendTest('https://example.com/g2bulk-sdk-test')['event_id'];
    assert_same(0, strpos($eventId, 'evt_test_'));
    $deliveries = $g2->webhooks->deliveries(['limit' => 10]);
    assert_same(10, $deliveries['pagination']['limit']);
    $delivery = null;
    foreach ($deliveries['items'] as $d) {
        if ($d['event_id'] === $eventId) {
            $delivery = $d;
        }
    }
    assert_true($delivery !== null, 'test delivery is listed');
    $g2->webhooks->resend($delivery['id']);
    assert_throws(NotFoundException::class, function () use ($g2) {
        $g2->webhooks->resend(999999999);
    });
    assert_throws(BadRequestException::class, function () use ($g2) {
        $g2->webhooks->sendTest('http://localhost/hook');
    });
});

test('webhook verification', function () use ($g2, $vector) {
    $now = (int) $vector['timestamp'];
    $headers = ['webhook-id' => $vector['id'], 'webhook-timestamp' => $vector['timestamp'], 'webhook-signature' => $vector['signature']];
    $event = Webhook::verify($vector['secret'], $headers, $vector['body'], 300, $now);
    assert_same(2432232314, $event['test']);
    $multi = array_merge($headers, ['webhook-signature' => 'v1,AAAA ' . $vector['signature']]);
    assert_same($event, $g2->webhooks->verify($vector['secret'], $multi, $vector['body'], 300, $now));
    $server = ['HTTP_WEBHOOK_ID' => $vector['id'], 'HTTP_WEBHOOK_TIMESTAMP' => $vector['timestamp'], 'HTTP_WEBHOOK_SIGNATURE' => $vector['signature']];
    assert_same($event, Webhook::verify($vector['secret'], $server, $vector['body'], 300, $now));
    $symfony = ['Webhook-Id' => [$vector['id']], 'WEBHOOK-TIMESTAMP' => [$vector['timestamp']], 'webhook-signature' => [$vector['signature']]];
    assert_same($event, Webhook::verify($vector['secret'], $symfony, $vector['body'], 300, $now));
    assert_throws(WebhookVerificationException::class, function () use ($vector, $headers, $now) {
        Webhook::verify($vector['secret'], $headers, $vector['body'] . ' ', 300, $now);
    });
    assert_throws(WebhookVerificationException::class, function () use ($vector, $headers) {
        Webhook::verify($vector['secret'], $headers, $vector['body']);
    });
    assert_throws(WebhookVerificationException::class, function () use ($vector) {
        Webhook::verify($vector['secret'], ['webhook-id' => $vector['id']], $vector['body']);
    });
    assert_throws(WebhookVerificationException::class, function () use ($vector, $headers, $now) {
        Webhook::verify('whsec_' . base64_encode('wrong-secret'), $headers, $vector['body'], 300, $now);
    });
});
