<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Application liveness probe.
     * Returns whether the application service is running.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'version' => '0.16.0',
            'environment' => config('app.env'),
        ]);
    }

    /**
     * Application readiness probe.
     * Verifies that the primary application database and cache are operational.
     * Invariance: Customer databases are strictly excluded to isolate tenant states.
     */
    public function ready(): JsonResponse
    {
        $dbStatus = 'ok';
        $cacheStatus = 'ok';

        // 1. Check primary application database
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $dbStatus = 'unreachable';
        }

        // 2. Check default cache
        try {
            $probeKey = 'tts_ready_probe_' . bin2hex(random_bytes(4));
            Cache::put($probeKey, '1', 5);
            $retrieved = Cache::get($probeKey);
            Cache::forget($probeKey);

            if ($retrieved !== '1') {
                $cacheStatus = 'degraded';
            }
        } catch (Throwable) {
            $cacheStatus = 'unreachable';
        }

        $isReady = ($dbStatus === 'ok');

        return response()->json([
            'status' => $isReady ? 'ready' : 'unhealthy',
            'checks' => [
                'app' => 'ok',
                'database' => $dbStatus,
                'cache' => $cacheStatus,
            ],
            'timestamp' => now()->toIso8601String(),
        ], $isReady ? 200 : 503);
    }
}
