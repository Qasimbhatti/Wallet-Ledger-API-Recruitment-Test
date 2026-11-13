<?php

namespace App\Http\Controllers;

use App\Http\ApiException;
use App\Http\LedgerRequest;
use App\Ledger\LedgerResult;
use App\Ledger\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dependencies are method-injected (not constructor-injected) because under
 * Octane the router caches controller instances across requests.
 */
final class LedgerController
{
    public function credit(Request $request, LedgerService $ledger): JsonResponse
    {
        [$userId, $amount] = LedgerRequest::mutation($request);
        $key = LedgerRequest::idempotencyKey($request);

        return $this->respond($userId, $ledger->credit($userId, $amount, $key));
    }

    public function debit(Request $request, LedgerService $ledger): JsonResponse
    {
        [$userId, $amount] = LedgerRequest::mutation($request);
        $key = LedgerRequest::idempotencyKey($request);

        return $this->respond($userId, $ledger->debit($userId, $amount, $key));
    }

    public function balance(LedgerService $ledger, ?string $userId = null): JsonResponse
    {
        $userId = LedgerRequest::userId($userId);
        $balance = $ledger->balance($userId);

        if ($balance === null) {
            throw ApiException::userNotFound($userId);
        }

        return new JsonResponse(['user_id' => $userId, 'balance' => $balance]);
    }

    private function respond(string $userId, LedgerResult $result): JsonResponse
    {
        $response = match ($result->status) {
            LedgerResult::OK => new JsonResponse(['user_id' => $userId, 'balance' => $result->balance]),
            LedgerResult::USER_NOT_FOUND => ApiException::userNotFound($userId)->toResponse(),
            LedgerResult::INSUFFICIENT_FUNDS => ApiException::insufficientFunds()->toResponse(),
            LedgerResult::IDEMPOTENCY_KEY_REUSED => ApiException::idempotencyKeyReused()->toResponse(),
        };

        if ($result->replayed) {
            $response->headers->set('Idempotent-Replayed', 'true');
        }

        return $response;
    }
}
