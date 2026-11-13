<?php

namespace App\Http\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * GET /health: process is up and the database answers. Authenticated like
 * every other endpoint.
 */
final class HealthController
{
    public function __invoke(DatabaseManager $db): JsonResponse
    {
        try {
            $db->connection()->select('SELECT 1');
        } catch (Throwable) {
            return new JsonResponse(['status' => 'unavailable', 'database' => 'down'], 503);
        }

        return new JsonResponse(['status' => 'ok', 'database' => 'up']);
    }
}
