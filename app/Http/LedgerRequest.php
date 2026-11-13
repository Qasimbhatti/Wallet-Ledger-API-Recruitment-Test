<?php

namespace App\Http;

use Illuminate\Http\Request;
use JsonException;
use stdClass;

/**
 * Strict input parsing. Hand-rolled instead of Laravel's validator because
 * the contract is about JSON *types*: "amount": "100", 100.0 and 1e2 must be
 * rejected, which Laravel's `integer` rule would accept.
 */
final class LedgerRequest
{
    public const MAX_AMOUNT = 1_000_000_000;

    private const MAX_BODY_BYTES = 16 * 1024;

    // `D` stops `$` from matching before a trailing newline.
    private const USER_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    // Printable ASCII, no spaces.
    private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{1,255}$/D';

    /**
     * @return array{0: string, 1: int} [user_id, amount]
     */
    public static function mutation(Request $request): array
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw ApiException::invalidRequest('Request body is too large.');
        }

        try {
            // BIGINT_AS_STRING: integers beyond PHP_INT_MAX become strings
            // (and are rejected below) instead of silently turning into floats.
            $data = json_decode($body, false, 32, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiException::invalidRequest('Request body must be valid JSON.');
        }

        if (! $data instanceof stdClass) {
            throw ApiException::invalidRequest('Request body must be a JSON object.');
        }

        if (! property_exists($data, 'user_id')) {
            throw ApiException::invalidRequest('Field "user_id" is required.');
        }

        if (! property_exists($data, 'amount')) {
            throw ApiException::invalidRequest('Field "amount" is required.');
        }

        $userId = self::userId($data->user_id);
        $amount = $data->amount;

        if (! is_int($amount) || $amount < 1 || $amount > self::MAX_AMOUNT) {
            throw ApiException::invalidRequest('Field "amount" must be a JSON integer between 1 and 1000000000.');
        }

        // Unknown fields are ignored.
        return [$userId, $amount];
    }

    public static function userId(mixed $userId): string
    {
        if (! is_string($userId) || preg_match(self::USER_ID_PATTERN, $userId) !== 1) {
            throw ApiException::invalidRequest('Field "user_id" must be 1-64 characters of A-Z, a-z, 0-9, "_" or "-".');
        }

        return $userId;
    }

    public static function idempotencyKey(Request $request): ?string
    {
        $key = $request->headers->get('Idempotency-Key');

        if ($key === null) {
            return null;
        }

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw ApiException::invalidRequest('Idempotency-Key must be 1-255 printable ASCII characters without spaces.');
        }

        return $key;
    }
}
