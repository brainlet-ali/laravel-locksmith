<?php

namespace BrainletAli\Locksmith\Jobs;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Services\RecipeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/** Delayed job to cleanup grace period and optionally discard old key from provider. */
class GracePeriodCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $secretKey,
        public string $valueToCleanup,
        public bool $providerCleanup = true
    ) {}

    /** Execute the job. */
    public function handle(RecipeResolver $resolver): void
    {
        $secret = Secret::where('key', $this->secretKey)->first();

        if (! $secret) {
            return;
        }

        // Only cleanup if previous_value matches what we were asked to cleanup
        // If it doesn't match, another rotation already handled it
        if ($secret->previous_value !== $this->valueToCleanup) {
            return;
        }

        // Optionally cleanup from provider (delete old key via API)
        if ($this->providerCleanup) {
            $this->cleanupFromProvider($secret, $resolver);
        }

        // Always clear grace period from database
        $secret->clearGracePeriod();
    }

    /** Delete old key from provider if recipe supports it. */
    protected function cleanupFromProvider(Secret $secret, RecipeResolver $resolver): void
    {
        $recipe = $resolver->resolveForKey($this->secretKey);

        if (! $recipe instanceof DiscardableRecipe) {
            return;
        }

        $startTime = microtime(true);

        try {
            $recipe->discard($this->valueToCleanup);
            $this->logDiscard($secret, RotationStatus::DiscardSuccess, $startTime, $recipe);
        } catch (Throwable $e) {
            $this->logDiscard($secret, RotationStatus::DiscardFailed, $startTime, $recipe, $e->getMessage());
            report($e);
        }
    }

    /** Log discard operation to rotation log. */
    protected function logDiscard(
        Secret $secret,
        RotationStatus $status,
        float $startTime,
        DiscardableRecipe $recipe,
        ?string $errorMessage = null
    ): void {
        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => $status,
            'rotated_at' => now(),
            'error_message' => $errorMessage,
            'metadata' => [
                'correlation_id' => Str::uuid()->toString(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'source' => 'queue',
                'triggered_by' => 'scheduled',
                'recipe' => class_basename($recipe),
                'operation' => 'discard',
            ],
        ]);
    }
}
