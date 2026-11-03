<?php

namespace G2Bulk\Resource;

use G2Bulk\Exception\BadRequestException;
use G2Bulk\Exception\PermissionDeniedException;
use G2Bulk\Exception\WaitTimeoutException;
use G2Bulk\HttpClient;

class Games
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function list(): array
    {
        $data = $this->http->request('GET', '/v1/games', ['auth' => false])['data'];
        return $data['games'] ?? [];
    }

    public function fields(string $game): array
    {
        $data = $this->http->request('POST', '/v1/games/fields', [
            'body' => ['game' => $game],
            'auth' => false,
            'retry' => true,
        ])['data'];
        return ['fields' => $data['info']['fields'] ?? [], 'notes' => $data['info']['notes'] ?? ''];
    }

    public function servers(string $game): ?array
    {
        try {
            $data = $this->http->request('POST', '/v1/games/servers', [
                'body' => ['game' => $game],
                'auth' => false,
                'retry' => true,
            ])['data'];
        } catch (PermissionDeniedException $e) {
            return null;
        }
        return $data['servers'] ?? [];
    }

    public function validatePlayer(string $game, string $userId, ?string $serverId = null, ?string $charname = null): array
    {
        $response = $this->http->request('POST', '/v1/games/checkPlayerId', [
            'body' => ['game' => $game, 'user_id' => $userId, 'server_id' => $serverId, 'charname' => $charname],
            'auth' => false,
            'retry' => true,
            'expect' => [400],
        ]);
        $data = $response['data'];
        if (!is_array($data) || !isset($data['valid']) || !is_string($data['valid'])) {
            $message = $response['status'] === 400 ? HttpClient::messageOf($data, is_string($data) ? $data : '', 400) : 'Player check failed';
            throw new BadRequestException($message, $response['status'], $data, $response['headers']);
        }
        $result = ['valid' => $data['valid'] === 'valid', 'name' => (string) ($data['name'] ?? '')];
        if (!empty($data['openid'])) {
            $result['openid'] = $data['openid'];
        }
        return $result;
    }

    public function catalogue(string $game): array
    {
        $data = $this->http->request('GET', '/v1/games/' . rawurlencode($game) . '/catalogue', ['auth' => false])['data'];
        return ['game' => $data['game'] ?? null, 'catalogues' => Money::each($data['catalogues'] ?? [], ['amount'])];
    }

    public function eta(string $game, string $catalogueName): array
    {
        $data = $this->http->request('POST', '/v1/games/eta', [
            'body' => ['game_code' => $game, 'denom_id' => $catalogueName],
            'auth' => false,
            'retry' => true,
        ])['data'];
        return $data['estimated_time'] ?? [];
    }

    public function order(string $game, array $params): array
    {
        $data = $this->http->request('POST', '/v1/games/' . rawurlencode($game) . '/order', [
            'body' => [
                'catalogue_name' => $params['catalogue_name'] ?? null,
                'player_id' => isset($params['player_id']) ? (string) $params['player_id'] : null,
                'server_id' => isset($params['server_id']) ? (string) $params['server_id'] : null,
                'charname' => $params['charname'] ?? null,
                'remark' => $params['remark'] ?? null,
                'callback_url' => $params['callback_url'] ?? null,
            ],
            'idempotency_key' => $params['idempotency_key'] ?? HttpClient::idempotencyKey(),
        ])['data'];
        return Money::gameOrder($data['order'] ?? []);
    }

    public function status(int $orderId, string $game): array
    {
        $data = $this->http->request('POST', '/v1/games/order/status', [
            'body' => ['order_id' => $orderId, 'game' => $game],
            'retry' => true,
        ])['data'];
        return Money::gameOrder($data['order'] ?? []);
    }

    public function listOrders(array $params = []): array
    {
        $data = $this->http->request('GET', '/v1/games/orders', ['query' => Query::orders($params)])['data'];
        return Query::page(Money::each($data['orders'] ?? [], ['price']), $data);
    }

    public function getOrder(int $orderId): array
    {
        $data = $this->http->request('GET', '/v1/games/orders/' . $orderId)['data'];
        return Money::gameOrder($data['order'] ?? []);
    }

    public function waitForOrder(int $orderId, float $intervalSeconds = 5, float $timeoutSeconds = 600): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $order = $this->getOrder($orderId);
            $status = $order['status'] ?? null;
            if ($status === 'COMPLETED' || $status === 'FAILED') {
                return $order;
            }
            if (microtime(true) + $intervalSeconds > $deadline) {
                throw new WaitTimeoutException('Order ' . $orderId . ' is still ' . $status);
            }
            HttpClient::sleep($intervalSeconds);
        }
    }

    public function exportCsv(array $params = []): string
    {
        return $this->http->request('GET', '/v1/games/orders/export.csv', ['query' => $params, 'raw' => true])['data'];
    }
}
