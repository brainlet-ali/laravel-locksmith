<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Services\RotationManager;
use Illuminate\Console\Command;

/** Artisan command to clear expired grace periods. */
class ClearExpiredCommand extends Command
{
    protected $signature = 'locksmith:clear-expired
                            {key? : Optional secret key to clear (clears all if not specified)}';

    protected $description = 'Clear expired grace periods for secrets';

    public function handle(RotationManager $manager): int
    {
        $key = $this->argument('key');

        if ($key) {
            $count = $manager->clearExpiredGracePeriod($key);
            $this->info("Cleared expired grace period for [{$key}]: {$count}");
        } else {
            $count = $manager->clearExpiredGracePeriods();
            $this->info("Cleared {$count} expired grace periods.");
        }

        return self::SUCCESS;
    }
}
