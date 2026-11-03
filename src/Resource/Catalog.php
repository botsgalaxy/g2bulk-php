<?php

namespace G2Bulk\Resource;

use G2Bulk\HttpClient;

class Catalog
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    public function categories(): array
    {
        $data = $this->http->request('GET', '/v1/category', ['auth' => false])['data'];
        return $data['categories'] ?? [];
    }

    public function categoryProducts(int $categoryId): array
    {
        $data = $this->http->request('GET', '/v1/category/' . $categoryId, ['auth' => false])['data'];
        return Money::products($data['products'] ?? []);
    }

    public function products(): array
    {
        $data = $this->http->request('GET', '/v1/products', ['auth' => false])['data'];
        return Money::products($data['products'] ?? []);
    }

    public function product(int $productId): array
    {
        $data = $this->http->request('GET', '/v1/products/' . $productId, ['auth' => false])['data'];
        return Money::product($data['product'] ?? []);
    }
}
