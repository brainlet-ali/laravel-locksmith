<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Contracts\InitializableRecipe;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Services\RecipeResolver;
use Illuminate\Console\Command;
use Throwable;

/** Artisan command to initialize secret values. */
class InitCommand extends Command
{
    protected $signature = 'locksmith:init
                            {key : The secret key to initialize (e.g., aws.credentials)}';

    protected $description = 'Initialize a secret value in Locksmith';

    public function handle(RecipeResolver $resolver): int
    {
        $key = $this->argument('key');

        if (Locksmith::has($key)) {
            if (! $this->confirm("Secret [{$key}] already exists. Overwrite?", false)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $recipe = $resolver->resolveForKey($key);

        try {
            if ($recipe instanceof InitializableRecipe) {
                $this->info("Initializing secret [{$key}]...");
                $value = $recipe->init();
            } else {
                $value = $this->secret('Secret value');
            }
        } catch (Throwable $e) {
            $this->error("Failed to initialize secret [{$key}].");
            $this->warn("Reason: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (empty($value)) {
            $this->error('No value provided.');

            return self::FAILURE;
        }

        Locksmith::set($key, $value);

        $this->info("Secret [{$key}] initialized successfully.");

        return self::SUCCESS;
    }
}
