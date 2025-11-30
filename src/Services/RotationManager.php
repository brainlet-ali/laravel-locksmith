<?php

namespace BrainletAli\Locksmith\Services;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Contracts\SecretRotator;
use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Events\SecretRotated;
use BrainletAli\Locksmith\Events\SecretRotating;
use BrainletAli\Locksmith\Events\SecretRotationFailed;
use BrainletAli\Locksmith\Jobs\GracePeriodCleanupJob;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use Illuminate\Support\Str;
use Throwable;

/** Service for managing secret rotation operations. */
class RotationManager
{
    /** Rotate a secret to a new value with grace period for dual-key validity. */
    public function rotate(
        Secret $secret,
        SecretRotator $rotator,
        string $newValue,
        int $gracePeriodMinutes = 60
    ): RotationLog {
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Pending,
            'rotated_at' => now(),
        ]);

        try {
            $rotator->rotate();
            $this->updateSecretValue($secret, $newValue, $gracePeriodMinutes);
            $log->update(['status' => RotationStatus::Success]);
        } catch (Throwable $e) {
            $log->markAsFailed($e->getMessage());
        }

        return $log;
    }

    /** Rotate a secret using a Recipe (generate + validate). */
    public function rotateUsingRecipe(
        string $key,
        Recipe $recipe,
        int $gracePeriodMinutes = 60,
        bool $providerCleanup = true
    ): ?RotationLog {
        $correlationId = Str::uuid()->toString();
        $startTime = microtime(true);
        $recipeName = class_basename($recipe);

        $secret = Secret::firstOrCreate(
            ['key' => $key],
            ['value' => '']
        );

        SecretRotating::dispatch($secret);

        // Cleanup previous value from provider BEFORE generating new one (AWS has 2 key limit)
        $this->cleanupPreviousValueIfExists($secret, $providerCleanup);

        try {
            $newValue = $recipe->generate();

            if (! $recipe->validate($newValue)) {
                $log = $this->createLog($secret, RotationStatus::Failed, $correlationId, $startTime, $recipeName, 'Validation failed for generated value');
                app(LoggingService::class)->logFailure($key, $log, 'Validation failed');
                SecretRotationFailed::dispatch($secret, 'Validation failed', $log);

                return $log;
            }

            $this->updateSecretValue($secret, $newValue, $gracePeriodMinutes, $providerCleanup);

            $log = $this->createLog($secret, RotationStatus::Success, $correlationId, $startTime, $recipeName);
            app(LoggingService::class)->logSuccess($key, $log);
            SecretRotated::dispatch($secret, $log);

            return $log;
        } catch (Throwable $e) {
            $log = $this->createLog($secret, RotationStatus::Failed, $correlationId, $startTime, $recipeName, $e->getMessage());
            app(LoggingService::class)->logFailure($key, $log, $e->getMessage());
            SecretRotationFailed::dispatch($secret, $e->getMessage(), $log);

            return $log;
        }
    }

    /** Rollback to the previous secret value with external service rollback. */
    public function rollback(Secret $secret, SecretRotator $rotator): bool
    {
        if ($secret->previous_value === null) {
            return false;
        }

        $latestLog = $secret->rotationLogs()->latest()->first();

        try {
            $rotator->rollback();

            $this->rollbackValue($secret);

            if ($latestLog) {
                $latestLog->markAsRolledBack();
            }

            return true;
        } catch (Throwable $e) {
            if ($latestLog) {
                $latestLog->markAsFailed('Rollback failed: '.$e->getMessage());
            }

            return false;
        }
    }

    /** Simple rollback of the secret value without external service calls. */
    public function rollbackValue(Secret $secret): RotationLog|false
    {
        if ($secret->previous_value === null) {
            return false;
        }

        $secret->update([
            'value' => $secret->previous_value,
            'previous_value' => null,
            'previous_value_expires_at' => null,
        ]);

        return RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::RolledBack,
            'rotated_at' => now(),
        ]);
    }

    /** Verify the new secret works and optionally rollback on failure. */
    public function verify(Secret $secret, callable $verifier, ?SecretRotator $rotator = null): bool
    {
        $isValid = $verifier($secret->value);

        $latestLog = $secret->rotationLogs()
            ->where('status', RotationStatus::Success)
            ->latest()
            ->first();

        if ($isValid) {
            if ($latestLog) {
                $latestLog->markAsVerified();
            }

            return true;
        }

        if ($rotator !== null && $secret->previous_value !== null) {
            $this->rollback($secret, $rotator);
        }

        return false;
    }

    /** Clear expired grace periods and remove stale previous values. */
    public function clearExpiredGracePeriods(): int
    {
        $expired = Secret::whereNotNull('previous_value')
            ->whereNotNull('previous_value_expires_at')
            ->where('previous_value_expires_at', '<', now())
            ->get();

        $resolver = app(RecipeResolver::class);

        foreach ($expired as $secret) {
            $this->cleanupFromProvider($secret, $resolver);
            $secret->clearGracePeriod();
        }

        return $expired->count();
    }

    /** Clear expired grace period for a specific secret key. */
    public function clearExpiredGracePeriod(string $key): int
    {
        $secret = Secret::where('key', $key)
            ->whereNotNull('previous_value')
            ->whereNotNull('previous_value_expires_at')
            ->where('previous_value_expires_at', '<', now())
            ->first();

        if (! $secret) {
            return 0;
        }

        $resolver = app(RecipeResolver::class);
        $this->cleanupFromProvider($secret, $resolver);
        $secret->clearGracePeriod();

        return 1;
    }

    /** Core secret update with grace period and cleanup job scheduling. */
    public function updateSecretValue(
        Secret $secret,
        string $newValue,
        int $gracePeriodMinutes = 60,
        bool $providerCleanup = true
    ): void {
        // Cleanup any existing previous_value from provider before rotating (prevents orphaned keys)
        $this->cleanupPreviousValueIfExists($secret, $providerCleanup);

        $oldValue = $secret->value ?: null;

        $secret->update([
            'value' => $newValue,
            'previous_value' => $oldValue,
            'previous_value_expires_at' => $oldValue ? now()->addMinutes($gracePeriodMinutes) : null,
        ]);

        // Schedule cleanup job after grace period expires
        if ($oldValue) {
            GracePeriodCleanupJob::dispatch($secret->key, $oldValue, $providerCleanup)
                ->delay(now()->addMinutes($gracePeriodMinutes));
        }
    }

    /** Cleanup previous value from provider if it exists (for on-demand rotation). */
    protected function cleanupPreviousValueIfExists(Secret $secret, bool $providerCleanup = true): void
    {
        if ($secret->previous_value === null) {
            return;
        }

        // Only call provider API if providerCleanup is true
        if ($providerCleanup) {
            $resolver = app(RecipeResolver::class);
            $this->cleanupFromProvider($secret, $resolver);
        }

        $secret->clearGracePeriod();
    }

    /** Delete the old key from the provider if recipe supports it. */
    protected function cleanupFromProvider(Secret $secret, RecipeResolver $resolver): void
    {
        if ($secret->previous_value === null) {
            return;
        }

        $recipe = $resolver->resolveForKey($secret->key);

        if ($recipe instanceof DiscardableRecipe) {
            try {
                $recipe->discard($secret->previous_value);
            } catch (Throwable $e) {
                // Log but don't fail - the grace period should still be cleared
                report($e);
            }
        }
    }

    /** Create a rotation log with metadata. */
    protected function createLog(
        Secret $secret,
        RotationStatus $status,
        string $correlationId,
        float $startTime,
        ?string $recipeName = null,
        ?string $errorMessage = null
    ): RotationLog {
        return RotationLog::create([
            'secret_id' => $secret->id,
            'status' => $status,
            'rotated_at' => now(),
            'error_message' => $errorMessage,
            'metadata' => [
                'correlation_id' => $correlationId,
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'source' => $this->detectSource(),
                'triggered_by' => $this->detectTriggeredBy(),
                'recipe' => $recipeName,
            ],
        ]);
    }

    /** Detect the source of the rotation. */
    protected function detectSource(): string
    {
        if (app()->runningInConsole()) {
            return 'artisan';
        }

        if (app()->runningUnitTests()) {
            return 'testing';
        }

        return 'api';
    }

    /** Detect what triggered the rotation. */
    protected function detectTriggeredBy(): string
    {
        if (app()->runningUnitTests()) {
            return 'testing';
        }

        if (app()->runningInConsole()) {
            return 'artisan';
        }

        if (app()->bound('queue.worker')) {
            return 'queue';
        }

        return 'api';
    }
}
