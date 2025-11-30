<?php

namespace BrainletAli\Locksmith\Console\Commands;

use BrainletAli\Locksmith\Facades\Locksmith;
use Illuminate\Console\Command;

/** Artisan command to display secrets status. */
class StatusCommand extends Command
{
    protected $signature = 'locksmith:status';

    protected $description = 'Show status of all managed secrets';

    public function handle(): int
    {
        $keys = Locksmith::all();

        if (empty($keys)) {
            $this->info('No secrets found.');

            return self::SUCCESS;
        }

        $this->info('Locksmith Secrets Status');
        $this->newLine();

        $rows = collect($keys)->map(function (string $key) {
            $lastLog = Locksmith::getLastLog($key);
            $graceStatus = Locksmith::isInGracePeriod($key) ? 'Grace Period' : 'Active';

            return [
                $key,
                $graceStatus,
                $lastLog?->status->label() ?? 'Never',
                $lastLog?->rotated_at?->diffForHumans() ?? '-',
            ];
        });

        $this->table(
            ['Key', 'Status', 'Last Rotation', 'Rotated'],
            $rows->toArray()
        );

        return self::SUCCESS;
    }
}
