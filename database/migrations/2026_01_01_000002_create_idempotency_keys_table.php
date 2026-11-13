<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optional Idempotency-Key support for POST /credit and POST /debit.
 * The stored outcome lets a retried request get the original response
 * instead of moving money twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE idempotency_keys (
                idempotency_key varchar(255) COLLATE "C" NOT NULL,
                user_id         varchar(64)  COLLATE "C" NOT NULL,
                operation       ledger_entry_type        NOT NULL,
                amount          bigint                   NOT NULL,
                -- Outcome, written in the same transaction as the money movement.
                status_code     smallint,
                balance         bigint,
                created_at      timestamptz              NOT NULL DEFAULT now(),
                CONSTRAINT idempotency_keys_pkey PRIMARY KEY (idempotency_key)
            );

            -- For a retention job (e.g. delete keys older than 24h).
            CREATE INDEX idempotency_keys_created_at_idx ON idempotency_keys (created_at);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS idempotency_keys');
    }
};
