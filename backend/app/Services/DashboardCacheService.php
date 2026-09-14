<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardCacheService
{
    public const DEFAULT_TTL_SECONDS = 300; // 5 minutes

    /**
     * Generate a strict, tenant-segregated cache key.
     *
     * @param int $companyId
     * @param int|null $connectionId
     * @param int $savedQueryId
     * @param string|null $updatedAt
     * @param array $filterParams
     * @return string
     */
    public function getCacheKey(
        int $companyId,
        ?int $connectionId,
        int $savedQueryId,
        ?string $updatedAt,
        array $filterParams = []
    ): string {
        $filterHash = !empty($filterParams) ? md5(json_encode($filterParams)) : 'unfiltered';
        $version = $updatedAt ? md5($updatedAt) : '0';
        $connKey = $connectionId ? (string) $connectionId : 'demo';

        return "tts_dash:c_{$companyId}:db_{$connKey}:sq_{$savedQueryId}:v_{$version}:f_{$filterHash}";
    }

    /**
     * Retrieve cached widget execution result if available.
     * Fail-safe: returns null if cache driver fails.
     *
     * @param string $cacheKey
     * @return array|null
     */
    public function get(string $cacheKey): ?array
    {
        try {
            return Cache::get($cacheKey);
        } catch (Throwable $e) {
            Log::warning('Dashboard cache read failed gracefully', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Store widget execution result in cache for short TTL.
     * Fail-safe: returns false if cache driver fails.
     *
     * @param string $cacheKey
     * @param array $data
     * @param int $ttlSeconds
     * @return bool
     */
    public function put(string $cacheKey, array $data, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
    {
        try {
            return Cache::put($cacheKey, $data, $ttlSeconds);
        } catch (Throwable $e) {
            Log::warning('Dashboard cache write failed gracefully', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Invalidate cached result for a saved query.
     *
     * @param string $cacheKey
     * @return bool
     */
    public function forget(string $cacheKey): bool
    {
        try {
            return Cache::forget($cacheKey);
        } catch (Throwable $e) {
            return false;
        }
    }
}
