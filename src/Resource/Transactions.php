<?php

namespace G2Bulk\Resource;

use G2Bulk\HttpClient;

class Transactions
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function list(array $params = []): array
    {
        $data = $this->http->request('GET', '/v1/transactions', ['query' => $params])['data'];
        return Query::page($data['data'] ?? [], $data);
    }
}
