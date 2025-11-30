<?php

namespace BrainletAli\Locksmith\Services;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Enums\PoolKeyStatus;
use BrainletAli\Locksmith\Events\PoolKeyActivated;
use BrainletAli\Locksmith\Events\PoolLow;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Jobs\GracePeriodCleanupJob;
use BrainletAli\Locksmith\Models\PoolKey;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Service for managing key pools. */
class PoolManager
{
    protected string $secretKey;

    protected ?Closure $validator = null;

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    /** Add keys to the pool. */
    public function add(array $keys): int
    {
        $maxPosition = PoolKey::where('secret_key', $this->secretKey)->max('position') ?? -1;
        $added = 0;

        foreach ($keys as $key) {
            $maxPosition++;
            PoolKey::create([
                'secret_key' => $this->secretKey,
                'value' => $key,
                'position' => $maxPosition,
                'status' => PoolKeyStatus::Queued,
            ]);
            $added++;
        }

        // If no active key exists, activate the first one
        if (! $this->hasActiveKey()) {
            $this->activateNext();
        }

        return $added;
    }

    /** Set a validator closure to validate keys before activation. */
    public function withValidator(Closure $validator): self
    {
        $this->validator = $validator;

        return $this;
    }

    /** Get total count of keys in the pool (all statuses). */
    public function count(): int
    {
        return PoolKey::where('secret_key', $this->secretKey)->count();
    }

    /** Get count of remaining queued keys. */
    public function remaining(): int
    {
        return PoolKey::where('secret_key', $this->secretKey)
            ->queued()
            ->count();
    }

    /** Check if there is an active key. */
    public function hasActiveKey(): bool
    {
        return PoolKey::where('secret_key', $this->secretKey)
            ->active()
            ->exists();
    }

    /** Get the currently active key. */
    public function getActiveKey(): ?PoolKey
    {
        return PoolKey::where('secret_key', $this->secretKey)
            ->active()
            ->first();
    }

    /** Get the current active key value. */
    public function getValue(): ?string
    {
        return $this->getActiveKey()?->value;
    }

    /** Get all queued keys. */
    public function getQueued(): Collection
    {
        return PoolKey::where('secret_key', $this->secretKey)
            ->queued()
            ->ordered()
            ->get();
    }

    /** Activate the next queued key (used when adding to empty pool). */
    public function activateNext(): ?PoolKey
    {
        $nextKey = $this->getNextQueued();

        if (! $nextKey) {
            return null;
        }

        $nextKey->activate();

        // Sync to secrets table
        Locksmith::set($this->secretKey, $nextKey->value);

        return $nextKey;
    }

    /** Rotate to the next key in the pool. */
    public function rotateNext(int $gracePeriodMinutes = 60): ?PoolKey
    {
        return DB::transaction(function () use ($gracePeriodMinutes) {
            $currentActive = $this->getActiveKey();
            $nextKey = $this->getNextQueued();

            if (! $nextKey) {
                return null;
            }

            // Validate the next key if validator is set
            if ($this->validator && ! ($this->validator)($nextKey->value)) {
                $nextKey->update(['status' => PoolKeyStatus::Expired]);

                return $this->rotateNext($gracePeriodMinutes);
            }

            // Mark current active as used
            if ($currentActive) {
                $currentActive->markAsUsed();
            }

            // Activate the next key
            $nextKey->activate();

            // Sync with Locksmith secrets table for grace period support
            $this->syncToSecret($nextKey, $currentActive, $gracePeriodMinutes);

            // Fire event
            event(new PoolKeyActivated($this->secretKey, $nextKey));

            // Check if pool is running low
            $this->checkPoolLevel();

            return $nextKey;
        });
    }

    /** Clear all keys from the pool. */
    public function clear(): int
    {
        return PoolKey::where('secret_key', $this->secretKey)->delete();
    }

    /** Remove used and expired keys from the pool. */
    public function prune(): int
    {
        return PoolKey::where('secret_key', $this->secretKey)
            ->whereIn('status', [PoolKeyStatus::Used, PoolKeyStatus::Expired])
            ->delete();
    }

    /** Get pool status summary. */
    public function status(): array
    {
        $keys = PoolKey::where('secret_key', $this->secretKey)->get();

        return [
            'secret_key' => $this->secretKey,
            'total' => $keys->count(),
            'queued' => $keys->where('status', PoolKeyStatus::Queued)->count(),
            'active' => $keys->where('status', PoolKeyStatus::Active)->count(),
            'used' => $keys->where('status', PoolKeyStatus::Used)->count(),
            'expired' => $keys->where('status', PoolKeyStatus::Expired)->count(),
        ];
    }

    /** Get the next queued key. */
    protected function getNextQueued(): ?PoolKey
    {
        return PoolKey::where('secret_key', $this->secretKey)
            ->queued()
            ->ordered()
            ->first();
    }

    /** Sync pool key to the secrets table for unified access. */
    protected function syncToSecret(PoolKey $newKey, ?PoolKey $oldKey, int $gracePeriodMinutes): void
    {
        $secret = Locksmith::find($this->secretKey);

        if ($secret) {
            // Discard any existing previous_value before overwriting (prevents orphaned keys)
            $this->discardPreviousValueIfExists($secret);

            $secret->update([
                'previous_value' => $oldKey?->value,
                'previous_value_expires_at' => $oldKey ? now()->addMinutes($gracePeriodMinutes) : null,
                'value' => $newKey->value,
            ]);

            // Schedule cleanup job after grace period expires (no provider cleanup for pools)
            if ($oldKey) {
                GracePeriodCleanupJob::dispatch($this->secretKey, $oldKey->value, providerCleanup: false)
                    ->delay(now()->addMinutes($gracePeriodMinutes));
            }
        } else {
            Locksmith::set($this->secretKey, $newKey->value);
        }
    }

    /** Discard previous value immediately if it exists (for on-demand rotation). */
    protected function discardPreviousValueIfExists($secret): void
    {
        if ($secret->previous_value === null) {
            return;
        }

        $resolver = app(RecipeResolver::class);
        $recipe = $resolver->resolveForKey($this->secretKey);

        if ($recipe instanceof DiscardableRecipe) {
            try {
                $recipe->discard($secret->previous_value);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $secret->clearGracePeriod();
    }

    /** Check pool level and fire event if low. */
    protected function checkPoolLevel(): void
    {
        $remaining = $this->remaining();
        $threshold = config('locksmith.pools.notify_below', 2);

        if ($remaining <= $threshold) {
            event(new PoolLow($this->secretKey, $remaining, $threshold));
        }
    }
}
