<?php

namespace G2Bulk;

use G2Bulk\Exception\ConnectionException;
use G2Bulk\Exception\G2BulkException;

class HttpClient
{
    private const RETRY_STATUSES = [429, 500, 502, 503, 504];

    private ?string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private $transport;

    public function __construct(?string $apiKey = null, array $options = [])
    {
        $this->apiKey = self::nonEmpty($apiKey) ?? self::env('G2BULK_API_KEY');
        $baseUrl = self::nonEmpty($options['base_url'] ?? null) ?? self::env('G2BULK_BASE_URL') ?? 'https://api.g2bulk.com';
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $transport = $options['transport'] ?? null;
        if ($transport === null) {
            if (!function_exists('curl_init')) {
                throw new G2BulkException('The curl extension is required, or pass a transport option.');
            }
            $transport = new CurlTransport();
        }
        if (!($transport instanceof Transport) && !is_callable($transport)) {
            throw new G2BulkException('The transport option must be a callable or implement G2Bulk\Transport.');
        }
        $this->transport = $transport;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function request(string $method, string $path, array $options = []): array
    {
        $url = $this->baseUrl . $path;
        $query = self::buildQuery($options['query'] ?? []);
        if ($query !== '') {
            $url .= '?' . $query;
        }
        $raw = !empty($options['raw']);
        $headers = [
            'Accept' => $raw ? 'text/csv' : 'application/json',
            'User-Agent' => 'g2bulk-php/' . Client::VERSION,
        ];
        if (($options['auth'] ?? true) !== false) {
            if ($this->apiKey === null) {
                throw new G2BulkException('An API key is required. Pass it to the Client or set G2BULK_API_KEY.');
            }
            $headers['X-API-Key'] = $this->apiKey;
        }
        $payload = null;
        if (array_key_exists('body', $options) && $options['body'] !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode(self::withoutNulls($options['body']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($payload === false) {
                throw new G2BulkException('Could not encode the request body as JSON: ' . json_last_error_msg());
            }
        }
        $idempotencyKey = $options['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }
        $retryable = $options['retry'] ?? ($method === 'GET' || isset($headers['X-Idempotency-Key']));
        $expect = $options['expect'] ?? [];

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->send($method, $url, $headers, $payload);
            } catch (\Exception $e) {
                if ($retryable && $attempt < $this->maxRetries) {
                    self::sleep(self::backoff($attempt));
                    continue;
                }
                throw new ConnectionException('Could not reach ' . $this->baseUrl . ': ' . $e->getMessage(), null, null, [], $e);
            }

            $status = (int) $response['status'];
            $resHeaders = [];
            foreach ($response['headers'] ?? [] as $name => $value) {
                $resHeaders[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
            }
            $text = (string) ($response['body'] ?? '');
            $data = $raw ? $text : self::parseJson($text);

            if (($status >= 200 && $status < 300) || in_array($status, $expect, true)) {
                return ['status' => $status, 'data' => $data, 'headers' => $resHeaders];
            }
            if ($retryable && in_array($status, self::RETRY_STATUSES, true) && $attempt < $this->maxRetries) {
                self::sleep(self::retryDelay($resHeaders['retry-after'] ?? null, $attempt));
                continue;
            }
            throw G2BulkException::fromResponse($status, self::messageOf($data, $text, $status), $data, $resHeaders);
        }
    }

    private function send(string $method, string $url, array $headers, ?string $payload): array
    {
        if ($this->transport instanceof Transport) {
            $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
        } else {
            $response = call_user_func($this->transport, $method, $url, $headers, $payload, $this->timeout);
        }
        if (!is_array($response) || !isset($response['status'])) {
            throw new \UnexpectedValueException('Transport returned an invalid response');
        }
        return $response;
    }

    public static function messageOf($data, string $text, int $status): string
    {
        if (is_array($data)) {
            if (isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
                return $data['message'];
            }
            if (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
                return $data['error'];
            }
            if (isset($data['detail']) && is_array($data['detail']) && isset($data['detail']['message']) && is_string($data['detail']['message'])) {
                return $data['detail']['message'];
            }
        }
        if ($text !== '' && strlen($text) < 500) {
            return trim($text);
        }
        return 'Request failed with status ' . $status;
    }

    public static function idempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    private static function parseJson(string $text)
    {
        if ($text === '') {
            return null;
        }
        $data = json_decode($text, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return $text;
        }
        return $data;
    }

    private static function backoff(int $attempt): float
    {
        $jitter = 0.75 + (mt_rand() / mt_getrandmax()) * 0.5;
        return min(0.5 * (2 ** $attempt), 8.0) * $jitter;
    }

    private static function retryDelay(?string $retryAfter, int $attempt): float
    {
        if ($retryAfter !== null && is_numeric(trim($retryAfter)) && (float) $retryAfter >= 0) {
            return min((float) $retryAfter, 60.0);
        }
        return self::backoff($attempt);
    }

    private static function buildQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $pairs);
    }

    private static function withoutNulls($body)
    {
        if (!is_array($body)) {
            return $body;
        }
        return array_filter($body, function ($value) {
            return $value !== null;
        });
    }

    private static function nonEmpty($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        foreach ([$_ENV, $_SERVER] as $source) {
            if (isset($source[$name]) && is_string($source[$name]) && $source[$name] !== '') {
                return $source[$name];
            }
        }
        return null;
    }
}
