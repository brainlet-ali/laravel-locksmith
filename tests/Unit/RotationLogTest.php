<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RotationLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_rotation_log(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Pending,
            'rotated_at' => now(),
        ]);

        $this->assertDatabaseHas('locksmith_rotation_logs', [
            'secret_id' => $secret->id,
            'status' => RotationStatus::Pending,
        ]);
    }

    public function test_belongs_to_secret(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $this->assertInstanceOf(Secret::class, $log->secret);
        $this->assertEquals($secret->id, $log->secret->id);
    }

    public function test_can_mark_as_verified(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $log->markAsVerified();

        $this->assertEquals(RotationStatus::Verified, $log->status);
        $this->assertNotNull($log->verified_at);
    }

    public function test_can_mark_as_rolled_back(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $log->markAsRolledBack('API returned 401');

        $this->assertEquals(RotationStatus::RolledBack, $log->status);
        $this->assertNotNull($log->rolled_back_at);
        $this->assertEquals('API returned 401', $log->error_message);
    }

    public function test_can_mark_as_failed(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Pending,
            'rotated_at' => now(),
        ]);

        $log->markAsFailed('Connection timeout');

        $this->assertEquals(RotationStatus::Failed, $log->status);
        $this->assertEquals('Connection timeout', $log->error_message);
    }

    public function test_secret_has_many_rotation_logs(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDay(),
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $this->assertCount(2, $secret->rotationLogs);
    }

    public function test_metadata_is_cast_to_array(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123',
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
            'metadata' => ['trigger' => 'scheduled', 'user_id' => 1],
        ]);

        $this->assertIsArray($log->metadata);
        $this->assertEquals('scheduled', $log->metadata['trigger']);
    }

    public function test_rotation_status_is_success_returns_true_for_success(): void
    {
        $this->assertTrue(RotationStatus::Success->isSuccess());
    }

    public function test_rotation_status_is_success_returns_true_for_verified(): void
    {
        $this->assertTrue(RotationStatus::Verified->isSuccess());
    }

    public function test_rotation_status_is_success_returns_false_for_failed(): void
    {
        $this->assertFalse(RotationStatus::Failed->isSuccess());
    }

    public function test_rotation_status_is_success_returns_false_for_pending(): void
    {
        $this->assertFalse(RotationStatus::Pending->isSuccess());
    }

    public function test_rotation_status_is_success_returns_false_for_rolled_back(): void
    {
        $this->assertFalse(RotationStatus::RolledBack->isSuccess());
    }

    public function test_rotation_status_label_for_all_statuses(): void
    {
        $this->assertEquals('Pending', RotationStatus::Pending->label());
        $this->assertEquals('Success', RotationStatus::Success->label());
        $this->assertEquals('Failed', RotationStatus::Failed->label());
        $this->assertEquals('Verified', RotationStatus::Verified->label());
        $this->assertEquals('Rolled Back', RotationStatus::RolledBack->label());
    }
}
