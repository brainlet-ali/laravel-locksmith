<?php

namespace BrainletAli\Locksmith\Tests\Unit\Logging;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LogQueryMethodsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_logs_by_status_returns_matching_logs(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Failed,
            'rotated_at' => now(),
        ]);

        $logs = Locksmith::getLogsByStatus(RotationStatus::Success);

        $this->assertCount(1, $logs);
        $this->assertEquals(RotationStatus::Success, $logs->first()->status);
    }

    public function test_get_logs_by_status_returns_empty_when_no_match(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $logs = Locksmith::getLogsByStatus(RotationStatus::Failed);

        $this->assertCount(0, $logs);
    }

    public function test_get_recent_failures_returns_failures_within_hours(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        // Recent failure
        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Failed,
            'rotated_at' => now()->subHours(12),
        ]);

        // Old failure
        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Failed,
            'rotated_at' => now()->subHours(48),
        ]);

        $logs = Locksmith::getRecentFailures(24);

        $this->assertCount(1, $logs);
    }

    public function test_get_recent_failures_defaults_to_24_hours(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Failed,
            'rotated_at' => now()->subHours(12),
        ]);

        $logs = Locksmith::getRecentFailures();

        $this->assertCount(1, $logs);
    }

    public function test_get_logs_between_returns_logs_in_date_range(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDays(5),
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDays(15),
        ]);

        $logs = Locksmith::getLogsBetween(
            now()->subDays(10),
            now()
        );

        $this->assertCount(1, $logs);
    }

    public function test_get_log_stats_returns_aggregated_counts(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Failed,
            'rotated_at' => now(),
        ]);

        $stats = Locksmith::getLogStats();

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('by_status', $stats);
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['by_status'][RotationStatus::Success->value]);
        $this->assertEquals(1, $stats['by_status'][RotationStatus::Failed->value]);
    }

    public function test_get_log_stats_for_specific_key(): void
    {
        $secret1 = Secret::create(['key' => 'api.key1', 'value' => 'value']);
        $secret2 = Secret::create(['key' => 'api.key2', 'value' => 'value']);

        RotationLog::create([
            'secret_id' => $secret1->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        RotationLog::create([
            'secret_id' => $secret2->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $stats = Locksmith::getLogStats('api.key1');

        $this->assertEquals(1, $stats['total']);
    }

    public function test_get_log_stats_returns_zeros_when_no_logs(): void
    {
        $stats = Locksmith::getLogStats();

        $this->assertEquals(0, $stats['total']);
        $this->assertEmpty($stats['by_status']);
    }

    public function test_get_log_stats_returns_zeros_for_nonexistent_key(): void
    {
        $stats = Locksmith::getLogStats('nonexistent.key');

        $this->assertEquals(0, $stats['total']);
        $this->assertEmpty($stats['by_status']);
    }
}
