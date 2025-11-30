<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Services\PoolManager;
use Illuminate\Console\Command;

use function Laravel\Prompts\textarea;

/** Artisan command to manage key pools. */
class PoolCommand extends Command
{
    protected $signature = 'locksmith:pool
        {key : The secret key to manage}
        {--add : Add keys to the pool}
        {--status : Show pool status}
        {--rotate : Rotate to next key}
        {--clear : Clear all keys from pool}
        {--prune : Remove used/expired keys}';

    protected $description = 'Manage key pools for secrets';

    public function handle(): int
    {
        $key = $this->argument('key');
        $pool = Locksmith::pool($key);

        if ($this->option('status')) {
            return $this->showStatus($pool->status());
        }

        if ($this->option('add')) {
            return $this->addKeys($pool);
        }

        if ($this->option('rotate')) {
            return $this->rotateNext($pool, $key);
        }

        if ($this->option('clear')) {
            $deleted = $pool->clear();
            $this->info("Cleared {$deleted} keys from pool.");

            return self::SUCCESS;
        }

        if ($this->option('prune')) {
            $deleted = $pool->prune();
            $this->info("Pruned {$deleted} used/expired keys from pool.");

            return self::SUCCESS;
        }

        // Default: show status
        return $this->showStatus($pool->status());
    }

    /** Show pool status in a table. */
    protected function showStatus(array $status): int
    {
        $this->info("Pool Status: {$status['secret_key']}");
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Keys', $status['total']],
                ['Queued', $status['queued']],
                ['Active', $status['active']],
                ['Used', $status['used']],
                ['Expired', $status['expired']],
            ]
        );

        if ($status['queued'] === 0 && $status['active'] === 0) {
            $this->warn('Pool is empty! Add keys with --add');
        } elseif ($status['queued'] <= 2) {
            $this->warn("Pool running low! Only {$status['queued']} keys remaining.");
        }

        return self::SUCCESS;
    }

    /** Add keys to the pool interactively. */
    protected function addKeys(PoolManager $pool): int
    {
        $input = textarea(
            label: 'Paste your keys (one per line)',
            placeholder: "rk_live_key1\nrk_live_key2\nrk_live_key3",
            hint: 'Each key should be on its own line'
        );
        $keys = array_filter(array_map('trim', explode("\n", $input)));

        if (empty($keys)) {
            $this->info('No keys added.');

            return self::SUCCESS;
        }

        $added = $pool->add($keys);
        $this->info("Added {$added} keys to pool.");

        $this->showStatus($pool->status());

        return self::SUCCESS;
    }

    /** Rotate to the next key in the pool. */
    protected function rotateNext(PoolManager $pool, string $key): int
    {
        $gracePeriod = (int) config('locksmith.grace_period_minutes', 60);

        $this->info("Rotating to next key in pool for '{$key}'...");

        $newKey = $pool->rotateNext($gracePeriod);

        if (! $newKey) {
            $this->error('No queued keys available for rotation!');

            return self::FAILURE;
        }

        $this->info("Rotated to next key in pool for '{$key}'");
        $this->info("Grace period: {$gracePeriod} minutes");

        $this->showStatus($pool->status());

        return self::SUCCESS;
    }
}
