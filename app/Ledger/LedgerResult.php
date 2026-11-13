<?php

namespace App\Ledger;

/**
 * Outcome of a credit/debit. Business failures (404/409) are values rather
 * than exceptions so they can be stored against an Idempotency-Key and
 * replayed exactly.
 */
final class LedgerResult
{
    public const OK = 200;

    public const USER_NOT_FOUND = 404;

    public const INSUFFICIENT_FUNDS = 409;

    public const IDEMPOTENCY_KEY_REUSED = 422;

    public function __construct(
        public readonly int $status,
        public readonly ?int $balance = null,
        public readonly bool $replayed = false,
    ) {}

    public static function ok(int $balance): self
    {
        return new self(self::OK, $balance);
    }

    public function asReplay(): self
    {
        return new self($this->status, $this->balance, true);
    }
}
