<?php

namespace G2Bulk;

use G2Bulk\Exception\WebhookVerificationException;

final class Webhook
{
    public static function verify(string $secret, array $headers, string $body, int $tolerance = 300, ?int $now = null): array
    {
        $id = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signature = self::header($headers, 'webhook-signature');
        if ($id === null || $timestamp === null || $signature === null) {
            throw new WebhookVerificationException('Missing webhook-id, webhook-timestamp or webhook-signature header');
        }
        if (!preg_match('/^-?\d+$/', $timestamp)) {
            throw new WebhookVerificationException('Invalid webhook-timestamp header');
        }
        $now = $now ?? time();
        if (abs($now - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('Webhook timestamp is outside the allowed tolerance');
        }

        $encoded = strpos($secret, 'whsec_') === 0 ? substr($secret, 6) : $secret;
        $key = base64_decode($encoded, true);
        if ($key === false) {
            throw new WebhookVerificationException('Webhook secret is not valid base64');
        }
        $expected = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true);

        $matched = false;
        foreach (explode(' ', $signature) as $part) {
            $pieces = explode(',', $part, 2);
            if (count($pieces) !== 2 || $pieces[0] !== 'v1' || $pieces[1] === '') {
                continue;
            }
            $given = base64_decode($pieces[1], true);
            if ($given !== false && hash_equals($expected, $given)) {
                $matched = true;
            }
        }
        if (!$matched) {
            throw new WebhookVerificationException('Webhook signature does not match');
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            throw new WebhookVerificationException('Webhook body is not valid JSON');
        }
        return $event;
    }

    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            $normalized = str_replace('_', '-', strtolower((string) $key));
            if (strpos($normalized, 'http-') === 0) {
                $normalized = substr($normalized, 5);
            }
            if ($normalized !== $name) {
                continue;
            }
            if (is_array($value)) {
                $value = reset($value);
            }
            if ($value === null || $value === false || $value === '') {
                return null;
            }
            return (string) $value;
        }
        return null;
    }
}
