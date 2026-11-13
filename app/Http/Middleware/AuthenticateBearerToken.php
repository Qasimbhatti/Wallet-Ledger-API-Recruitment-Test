<?php

namespace App\Http\Middleware;

use App\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static bearer-token authentication.
 *
 * - The expected token comes from the API_TOKEN environment variable (via
 *   config/wallet.php); it is never in source, never logged, never echoed.
 * - An empty/unset API_TOKEN fails closed: every request is rejected.
 * - Both sides are hashed to fixed-length SHA-256 digests and compared with
 *   hash_equals(), so the comparison takes the same time whatever the input
 *   (including its length).
 * - Runs as global middleware, before routing and before any DB access.
 */
final class AuthenticateBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('wallet.api_token');
        $header = $request->headers->get('Authorization');

        // Auth scheme names are case-insensitive (RFC 9110 §11.1); the token
        // itself is compared exactly.
        if ($expected === ''
            || $header === null
            || preg_match('/^Bearer ([^\s]+)$/iD', $header, $matches) !== 1
            || ! hash_equals(hash('sha256', $expected), hash('sha256', $matches[1]))
        ) {
            throw ApiException::unauthorized();
        }

        return $next($request);
    }
}
