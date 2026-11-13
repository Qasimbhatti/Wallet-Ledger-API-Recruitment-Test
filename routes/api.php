<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

// Authentication is global middleware (bootstrap/app.php), applied before routing.

Route::post('/credit', [LedgerController::class, 'credit']);
Route::post('/debit', [LedgerController::class, 'debit']);

// Optional parameter so GET /balance (no id) is a 400, not a 404.
Route::get('/balance/{userId?}', [LedgerController::class, 'balance']);

Route::get('/health', HealthController::class);
