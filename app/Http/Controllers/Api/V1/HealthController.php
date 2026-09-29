<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness probe: the pod only receives traffic when its backing services
 * are reachable. (Liveness uses Laravel's built-in /up endpoint.)
 */
class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        $checks = ['database' => $this->check(fn () => DB::select('select 1'))];

        if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
            $checks['redis'] = $this->check(fn () => Redis::connection()->ping());
        }

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'unavailable',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{ok: bool, ms: float, error?: string}
     */
    private function check(callable $probe): array
    {
        $start = microtime(true);

        try {
            $probe();

            return ['ok' => true, 'ms' => round((microtime(true) - $start) * 1000, 2)];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'ms' => round((microtime(true) - $start) * 1000, 2), 'error' => class_basename($e)];
        }
    }
}
