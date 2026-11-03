<?php

namespace G2Bulk;

interface Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array;
}
