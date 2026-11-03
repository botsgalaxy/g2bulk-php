<?php

namespace G2Bulk\Resource;

use G2Bulk\Exception\WaitTimeoutException;
use G2Bulk\HttpClient;

class Purchases
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function create(int $productId, array $params = []): array
    {
        $response = $this->http->request('POST', '/v1/products/' . $productId . '/purchase', [
            'body' => [
                'quantity' => (int) ($params['quantity'] ?? 1),
                'description' => $params['description'] ?? null,
                'callback_url' => $params['callback_url'] ?? null,
            ],
            'idempotency_key' => $params['idempotency_key'] ?? HttpClient::idempotencyKey(),
        ]);
        return $response['data'];
    }

    public function list(array $params = []): array
    {
        $data = $this->http->request('GET', '/v1/orders', ['query' => Query::orders($params)])['data'];
        return Query::page($data['orders'] ?? [], $data);
    }

    public function get(int $orderId): array
    {
        $data = $this->http->request('GET', '/v1/orders/' . $orderId)['data'];
        return $data['order'] ?? [];
    }

    public function delivery(int $orderId): array
    {
        $response = $this->http->request('GET', '/v1/orders/' . $orderId . '/delivery', ['expect' => [202, 410]]);
        $status = $response['status'];
        $data = is_array($response['data']) ? $response['data'] : [];
        $data['state'] = $status === 202 ? 'processing' : ($status === 410 ? 'failed' : 'ready');
        return $data;
    }

    public function waitForDelivery(int $orderId, float $intervalSeconds = 3, float $timeoutSeconds = 120): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $delivery = $this->delivery($orderId);
            if ($delivery['state'] !== 'processing') {
                return $delivery;
            }
            if (microtime(true) + $intervalSeconds > $deadline) {
                throw new WaitTimeoutException('Order ' . $orderId . ' is still processing');
            }
            HttpClient::sleep($intervalSeconds);
        }
    }

    public function exportCsv(array $params = []): string
    {
        return $this->http->request('GET', '/v1/orders/export.csv', ['query' => $params, 'raw' => true])['data'];
    }
}
