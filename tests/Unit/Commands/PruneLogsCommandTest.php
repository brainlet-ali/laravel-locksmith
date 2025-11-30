<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PruneLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_logs_older_than_specified_days(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        // Create old log
        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDays(100),
        ]);

        // Create recent log
        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDays(10),
        ]);

        $this->artisan('locksmith:prune-logs', ['--days' => 90])
            ->expectsOutput('Pruned 1 rotation log(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('locksmith_rotation_logs', 1);
    }

    public function test_uses_config_default_when_days_not_specified(): void
    {
        config(['locksmith.log_retention_days' => 30]);

        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDays(60),
        ]);

        $this->artisan('locksmith:prune-logs')
            ->expectsOutput('Pruned 1 rotation log(s).')
            ->assertSuccessful();
    }

    public function test_outputs_message_when_no_logs_to_prune(): void
    {
        $this->artisan('locksmith:prune-logs', ['--days' => 90])
            ->expectsOutput('No logs to prune.')
            ->assertSuccessful();
    }

    public function test_prunes_multiple_old_logs(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        for ($i = 0; $i < 5; $i++) {
            RotationLog::create([
                'secret_id' => $secret->id,
                'status' => RotationStatus::Success,
                'rotated_at' => now()->subDays(100 + $i),
            ]);
        }

        $this->artisan('locksmith:prune-logs', ['--days' => 90])
            ->expectsOutput('Pruned 5 rotation log(s).')
            ->assertSuccessful();
    }

    public function test_dry_run_option_shows_count_without_deleting(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDays(100),
        ]);

        $this->artisan('locksmith:prune-logs', ['--days' => 90, '--dry-run' => true])
            ->expectsOutput('Would prune 1 rotation log(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('locksmith_rotation_logs', 1);
    }
}
