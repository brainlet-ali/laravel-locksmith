<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Services\RecipeResolver;
use Illuminate\Console\Command;

/** Artisan command to rotate a secret using a recipe. */
class RotateCommand extends Command
{
    protected $signature = 'locksmith:rotate
                            {key : The secret key to rotate}
                            {--recipe= : The recipe to use (aws)}
                            {--grace=60 : Grace period in minutes}
                            {--no-provider-cleanup : Skip calling provider API to delete old keys}';

    protected $description = 'Rotate a secret using a configured recipe';

    public function handle(RecipeResolver $resolver): int
    {
        $key = $this->argument('key');
        $recipeName = $this->option('recipe');
        $gracePeriod = (int) $this->option('grace');

        if (! Locksmith::has($key)) {
            $this->error("Secret [{$key}] not found.");

            return self::FAILURE;
        }

        if (! $recipeName) {
            $this->error('No recipe specified. Use --recipe option (e.g., --recipe=aws).');

            return self::FAILURE;
        }

        $recipe = $resolver->resolve($recipeName);

        if (! $recipe) {
            $this->error("Unknown recipe [{$recipeName}]. Available: ".implode(', ', $resolver->all()));

            return self::FAILURE;
        }

        // Command flag overrides config, otherwise use config value
        $providerCleanup = $this->option('no-provider-cleanup')
            ? false
            : $resolver->getProviderCleanup($recipeName);

        // Check if there's a previous value that will be cleaned up from provider
        if ($providerCleanup && Locksmith::getPreviousValue($key)) {
            $this->warn("Discarding previous key for [{$key}]...");
        }

        $this->info("Rotating secret [{$key}]...");

        $log = Locksmith::rotate($key, $recipe, $gracePeriod, $providerCleanup);

        if ($log && $log->status->isSuccess()) {
            $this->info("Secret [{$key}] rotated successfully.");

            return self::SUCCESS;
        }

        $this->error("Failed to rotate secret [{$key}].");

        if ($log?->error_message) {
            $this->warn("Reason: {$log->error_message}");
        }

        return self::FAILURE;
    }
}
