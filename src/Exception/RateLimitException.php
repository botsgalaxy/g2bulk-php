<?php

namespace G2Bulk\Exception;

class RateLimitException extends G2BulkException
{
    public function getRetryAfter(): ?float
    {
        $headers = $this->getHeaders();
        $value = $headers['retry-after'] ?? null;
        if ($value === null || !is_numeric(trim((string) $value))) {
            return null;
        }
        return (float) $value;
    }
}
