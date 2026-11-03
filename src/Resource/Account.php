<?php

namespace G2Bulk\Resource;

use G2Bulk\HttpClient;

class Account
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function me(): array
    {
        return Money::fields($this->http->request('GET', '/v1/getMe')['data'], ['balance']);
    }
}
