<?php

namespace G2Bulk\Exception;

class G2BulkException extends \RuntimeException
{
    private ?int $status;
    private $body;
    private array $headers;

    public function __construct(string $message, ?int $status = null, $body = null, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function fromResponse(int $status, string $message, $body, array $headers): self
    {
        if ($status === 400) {
            return new BadRequestException($message, $status, $body, $headers);
        }
        if ($status === 401) {
            return new AuthenticationException($message, $status, $body, $headers);
        }
        if ($status === 403) {
            return new PermissionDeniedException($message, $status, $body, $headers);
        }
        if ($status === 404) {
            return new NotFoundException($message, $status, $body, $headers);
        }
        if ($status === 429) {
            return new RateLimitException($message, $status, $body, $headers);
        }
        if ($status >= 500) {
            return new ServerException($message, $status, $body, $headers);
        }
        return new self($message, $status, $body, $headers);
    }
}
