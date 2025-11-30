<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Facades\Locksmith;
use Illuminate\Console\Command;

/** Artisan command to rotate all configured pools. */
class PoolRotateCommand extends Command
{
    protected $signature = 'locksmith:pool-rotate';

    protected $description = 'Rotate all configured pool secrets on schedule';

    public function handle(): int
    {
        $pools = config('locksmith.pools', []);

        if (empty($pools)) {
            $this->info('No pools configured for scheduled rotation.');

            return self::SUCCESS;
        }

        $rotated = 0;
        $gracePeriod = (int) config('locksmith.grace_period_minutes', 60);

        foreach ($pools as $key => $config) {
            // Skip non-pool config entries (e.g., 'notify_below')
            if (! is_array($config)) {
                continue;
            }

            $pool = Locksmith::pool($key);

            if ($pool->remaining() === 0) {
                $this->warn("Pool '{$key}' has no queued keys. Skipping.");

                continue;
            }

            $customGrace = $config['grace'] ?? $gracePeriod;
            $newKey = $pool->rotateNext($customGrace);

            if ($newKey) {
                $this->info("Rotated '{$key}' to next pool key.");
                $rotated++;
            }
        }

        $this->info("Rotated {$rotated} pool secrets.");

        return self::SUCCESS;
    }
}
