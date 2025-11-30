<?php

namespace BrainletAli\Locksmith;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Services\LogQueryService;
use BrainletAli\Locksmith\Services\PoolManager;
use BrainletAli\Locksmith\Services\RotationManager;
use Carbon\Carbon;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Main Locksmith service for secrets management. */
class Locksmith
{
    /** Get a secret value by key. */
    public function get(string $key): ?string
    {
        return $this->find($key)?->value;
    }

    /** Set a secret value by key, creating or updating as needed. */
    public function set(string $key, string $value): Secret
    {
        $secret = Secret::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        $this->forgetCache($key);

        return $secret;
    }

    /** Check if a secret exists. */
    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /** Delete a secret by key. */
    public function delete(string $key): bool
    {
        $this->forgetCache($key);

        return Secret::where('key', $key)->delete() > 0;
    }

    /** Get all secret keys. */
    public function all(): array
    {
        return Secret::pluck('key')->toArray();
    }

    /** Find and return the Secret model by key. */
    public function find(string $key): ?Secret
    {
        if (! $this->isCacheEnabled()) {
            return Secret::where('key', $key)->first();
        }

        return $this->cache()->remember(
            $this->cacheKey($key),
            $this->cacheTtl(),
            fn () => Secret::where('key', $key)->first()
        );
    }

    /** Rotate a secret using a Recipe instance. */
    public function rotate(
        string $key,
        Recipe $recipe,
        int $gracePeriodMinutes = 60,
        bool $providerCleanup = true
    ): ?RotationLog {
        $log = app(RotationManager::class)->rotateUsingRecipe(
            $key,
            $recipe,
            $gracePeriodMinutes,
            $providerCleanup
        );

        $this->forgetCache($key);

        return $log;
    }

    /** Get all valid values for a secret (current + previous if in grace period). */
    public function getValidValues(string $key): array
    {
        $secret = $this->find($key);

        if (! $secret) {
            return [];
        }

        return $secret->getAllValidValues();
    }

    /** Check if a secret is currently in its grace period. */
    public function isInGracePeriod(string $key): bool
    {
        return $this->find($key)?->hasActiveGracePeriod() ?? false;
    }

    /** Get when the grace period expires for a secret. */
    public function gracePeriodExpiresAt(string $key): ?Carbon
    {
        return $this->find($key)?->previous_value_expires_at;
    }

    /** Get the most recent rotation log for a secret. */
    public function getLastLog(string $key): ?RotationLog
    {
        return $this->find($key)?->rotationLogs()->latest('rotated_at')->first();
    }

    /** Get all rotation logs for a secret. */
    public function getLogs(string $key): Collection
    {
        $secret = $this->find($key);

        if (! $secret) {
            return collect();
        }

        return $secret->rotationLogs()->latest('rotated_at')->get();
    }

    /** Get the previous value of a secret (if in grace period). */
    public function getPreviousValue(string $key): ?string
    {
        $secret = $this->find($key);

        if (! $secret || ! $secret->hasActiveGracePeriod()) {
            return null;
        }

        return $secret->previous_value;
    }

    /** Get all logs matching a specific status. */
    public function getLogsByStatus(RotationStatus $status): Collection
    {
        return app(LogQueryService::class)->getByStatus($status);
    }

    /** Get failed logs within the specified hours. */
    public function getRecentFailures(int $hours = 24): Collection
    {
        return app(LogQueryService::class)->getRecentFailures($hours);
    }

    /** Get logs between two dates. */
    public function getLogsBetween(Carbon $start, Carbon $end): Collection
    {
        return app(LogQueryService::class)->getBetween($start, $end);
    }

    /** Get aggregated log statistics. */
    public function getLogStats(?string $key = null): array
    {
        return app(LogQueryService::class)->getStats($key);
    }

    /** Get a pool manager for the given secret key. */
    public function pool(string $key): PoolManager
    {
        return new PoolManager($key);
    }

    /** Clear cache for a specific secret key. */
    public function forgetCache(string $key): bool
    {
        if (! $this->isCacheEnabled()) {
            return false;
        }

        return $this->cache()->forget($this->cacheKey($key));
    }

    /** Clear all cached secrets. */
    public function flushCache(): bool
    {
        if (! $this->isCacheEnabled()) {
            return false;
        }

        // Get all secret keys and forget each one
        $keys = Secret::pluck('key')->toArray();

        foreach ($keys as $key) {
            $this->cache()->forget($this->cacheKey($key));
        }

        return true;
    }

    /** Check if caching is enabled. */
    protected function isCacheEnabled(): bool
    {
        return config('locksmith.cache.enabled', true);
    }

    /** Get the cache store instance. */
    protected function cache(): CacheRepository
    {
        $store = config('locksmith.cache.store');

        return $store ? Cache::store($store) : Cache::store();
    }

    /** Generate cache key for a secret. */
    protected function cacheKey(string $key): string
    {
        $prefix = config('locksmith.cache.prefix', 'locksmith:');

        return $prefix.$key;
    }

    /** Get cache TTL in seconds. */
    protected function cacheTtl(): ?int
    {
        return config('locksmith.cache.ttl', 300);
    }
}
