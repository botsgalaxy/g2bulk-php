<?php

namespace G2Bulk;

use G2Bulk\Exception\ConnectionException;

class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeout, 10) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (stripos($line, 'HTTP/') === 0) {
                    $responseHeaders = [];
                }
                return strlen($line);
            },
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ConnectionException($error !== '' ? $error : 'Request failed');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result];
    }
}
