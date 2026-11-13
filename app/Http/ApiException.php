<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * An error that maps 1:1 onto the API error contract:
 * {"error": "<code>", "message": "<text>"}.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidRequest(string $message): self
    {
        return new self(400, 'invalid_request', $message);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'unauthorized', 'Missing or invalid bearer token.', ['WWW-Authenticate' => 'Bearer']);
    }

    public static function userNotFound(string $userId): self
    {
        return new self(404, 'user_not_found', "User '{$userId}' has never been credited.");
    }

    public static function insufficientFunds(): self
    {
        return new self(409, 'insufficient_funds', 'The balance is too low for this debit; nothing was written.');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(422, 'idempotency_key_reused', 'This Idempotency-Key was already used for a different request.');
    }

    /**
     * Normalise any throwable into the API error shape. Internal errors never
     * leak details (they are reported to the log by the exception handler).
     */
    public static function from(Throwable $e): self
    {
        return match (true) {
            $e instanceof self => $e,
            $e instanceof NotFoundHttpException => new self(404, 'not_found', 'Route not found.'),
            $e instanceof MethodNotAllowedHttpException => new self(405, 'method_not_allowed', 'Method not allowed.', $e->getHeaders()),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => new self($e->getStatusCode(), 'http_error', 'Request rejected.'),
            default => new self(500, 'internal_error', 'Internal server error.'),
        };
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(
            ['error' => $this->errorCode, 'message' => $this->getMessage()],
            $this->status,
            $this->headers,
        );
    }
}
