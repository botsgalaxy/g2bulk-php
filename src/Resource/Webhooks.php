<?php

namespace G2Bulk\Resource;

use G2Bulk\HttpClient;
use G2Bulk\Webhook;

class Webhooks
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function deliveries(array $params = []): array
    {
        $data = $this->http->request('GET', '/v1/webhooks/deliveries', [
            'query' => [
                'page' => $params['page'] ?? null,
                'limit' => $params['limit'] ?? null,
                'status' => $params['status'] ?? null,
                'order_id' => $params['order_id'] ?? null,
            ],
        ])['data'];
        $limit = (int) (($data['limit'] ?? 0) ?: ($params['limit'] ?? 20));
        $total = (int) ($data['total'] ?? 0);
        return [
            'items' => $data['deliveries'] ?? [],
            'pagination' => [
                'page' => $data['page'] ?? null,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $limit)),
            ],
        ];
    }

    public function resend(int $deliveryId): void
    {
        $this->http->request('POST', '/v1/webhooks/deliveries/' . $deliveryId . '/resend');
    }

    public function sendTest(string $url): array
    {
        $data = $this->http->request('POST', '/v1/webhooks/test', ['body' => ['url' => $url]])['data'];
        return ['event_id' => $data['event_id'] ?? null];
    }

    public function secret(): array
    {
        $data = $this->http->request('GET', '/v1/webhooks/secret')['data'];
        return ['has_secret' => (bool) ($data['has_secret'] ?? false), 'created_at' => $data['created_at'] ?? null];
    }

    public function rotateSecret(): string
    {
        $data = $this->http->request('POST', '/v1/webhooks/secret')['data'];
        return (string) ($data['secret'] ?? '');
    }

    public function verify(string $secret, array $headers, string $body, int $tolerance = 300, ?int $now = null): array
    {
        return Webhook::verify($secret, $headers, $body, $tolerance, $now);
    }
}
