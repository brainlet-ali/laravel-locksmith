<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Services\RotationManager;
use Illuminate\Console\Command;

/** Artisan command to rollback a secret rotation. */
class RollbackCommand extends Command
{
    protected $signature = 'locksmith:rollback {key : The secret key to rollback}';

    protected $description = 'Rollback a secret to its previous value';

    public function handle(RotationManager $manager): int
    {
        $key = $this->argument('key');

        $secret = Locksmith::find($key);

        if (! $secret) {
            $this->error("Secret [{$key}] not found.");

            return self::FAILURE;
        }

        if (! Locksmith::getPreviousValue($key)) {
            $this->error("Secret [{$key}] has no previous value to rollback to.");

            return self::FAILURE;
        }

        $this->info("Rolling back secret [{$key}]...");

        $log = $manager->rollbackValue($secret);

        if ($log !== false) {
            $this->info("Secret [{$key}] rolled back successfully.");

            return self::SUCCESS;
        }

        $this->error("Failed to rollback secret [{$key}].");

        return self::FAILURE;
    }
}
