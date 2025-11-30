<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Models\RotationLog;
use Illuminate\Console\Command;

/** Artisan command to prune old rotation logs. */
class PruneLogsCommand extends Command
{
    protected $signature = 'locksmith:prune-logs
                            {--days= : Number of days to retain logs}
                            {--dry-run : Show how many logs would be pruned without deleting}';

    protected $description = 'Prune old rotation logs';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('locksmith.log_retention_days', 90);
        $dryRun = $this->option('dry-run');

        $cutoffDate = now()->subDays((int) $days);

        $query = RotationLog::where('rotated_at', '<', $cutoffDate);
        $count = $query->count();

        if ($count === 0) {
            $this->info('No logs to prune.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would prune {$count} rotation log(s).");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("Pruned {$count} rotation log(s).");

        return self::SUCCESS;
    }
}
