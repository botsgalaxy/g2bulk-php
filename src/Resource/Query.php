<?php

namespace G2Bulk\Resource;

final class Query
{
    public static function orders(array $params): array
    {
        if (array_key_exists('summary', $params)) {
            $params['summary'] = $params['summary'] ? '1' : null;
        }
        return $params;
    }

    public static function page(array $items, $data): array
    {
        $page = ['items' => $items, 'pagination' => $data['pagination'] ?? null];
        if (isset($data['summary'])) {
            $page['summary'] = Money::fields($data['summary'], ['spend', 'refunded']);
        }
        return $page;
    }
}
