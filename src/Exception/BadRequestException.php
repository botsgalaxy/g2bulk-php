<?php

namespace G2Bulk\Exception;

class BadRequestException extends G2BulkException
{
    public function getErrorCode(): ?string
    {
        return self::codeFromMessage($this->getMessage());
    }

    public static function codeFromMessage(string $message): ?string
    {
        $m = strtolower($message);
        if (strpos($m, 'enough balance') !== false) {
            return 'insufficient_balance';
        }
        if (strpos($m, 'enough stock') !== false || strpos($m, 'out of stock') !== false) {
            return 'out_of_stock';
        }
        if (strpos($m, 'invalid player') !== false) {
            return 'invalid_player';
        }
        if (strpos($m, 'idempotency key') !== false) {
            return 'invalid_idempotency_key';
        }
        return null;
    }
}
