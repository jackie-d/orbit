<?php

use Illuminate\Support\Facades\Route;

// Orbit API v1 — routes are prefixed with /api/v1 (see bootstrap/app.php).
Route::get('/', fn () => ['name' => config('app.name'), 'version' => 'v1']);
