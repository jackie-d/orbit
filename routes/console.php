<?php

use Illuminate\Support\Facades\Schedule;

// Runs in the "scheduler" deployment (php artisan schedule:work).
Schedule::command('orbit:prune-deleted')->dailyAt('03:30')->onOneServer()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
