<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContactPhotoController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InteractionController;
use App\Http\Controllers\Api\V1\InteractionLinkExportController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Support\TokenAbility;
use Illuminate\Support\Facades\Route;

// Orbit API v1 — routes are prefixed with /api/v1 (see bootstrap/app.php).

Route::get('/', fn () => ['name' => config('app.name'), 'version' => 'v1']);
Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Third-party read-only exports: any token with "links:read" (full-access tokens included).
    Route::prefix('exports/interaction-links')
        ->middleware(['ability:'.TokenAbility::LINKS_READ, 'throttle:exports'])
        ->group(function () {
            Route::get('/', [InteractionLinkExportController::class, 'index'])->name('exports.links.index');
            Route::get('recurrence', [InteractionLinkExportController::class, 'recurrence'])->name('exports.links.recurrence');
        });

    // Everything else requires a full-access token (the ones issued at login).
    Route::middleware('abilities:'.TokenAbility::FULL)->group(function () {
        Route::get('auth/tokens', [TokenController::class, 'index'])->name('tokens.index');
        Route::post('auth/tokens', [TokenController::class, 'store'])->name('tokens.store');
        Route::delete('auth/tokens/{token}', [TokenController::class, 'destroy'])->whereNumber('token')->name('tokens.destroy');

        Route::apiResource('contacts', ContactController::class);
        Route::post('contacts/{contact}/photo', [ContactPhotoController::class, 'store'])->name('contacts.photo.store');
        Route::delete('contacts/{contact}/photo', [ContactPhotoController::class, 'destroy'])->name('contacts.photo.destroy');
        Route::get('contacts/{contact}/interactions', [InteractionController::class, 'forContact'])->name('contacts.interactions');

        Route::apiResource('interactions', InteractionController::class);

        Route::get('stats/overview', [StatsController::class, 'overview'])->name('stats.overview');
    });
});
