<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_command_shows_no_secrets_message(): void
    {
        $this->artisan('locksmith:status')
            ->expectsOutput('No secrets found.')
            ->assertSuccessful();
    }

    public function test_status_command_lists_all_secrets(): void
    {
        Secret::create(['key' => 'test.secret', 'value' => 'sk_test_123']);
        Secret::create(['key' => 'aws.key', 'value' => 'AKIA123']);

        $this->artisan('locksmith:status')
            ->expectsOutputToContain('test.secret')
            ->expectsOutputToContain('aws.key')
            ->assertSuccessful();
    }

    public function test_status_command_shows_grace_period_status(): void
    {
        Secret::create([
            'key' => 'test.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $this->artisan('locksmith:status')
            ->expectsTable(
                ['Key', 'Status', 'Last Rotation', 'Rotated'],
                [['test.key', 'Grace Period', 'Never', '-']]
            )
            ->assertSuccessful();
    }

    public function test_status_command_shows_last_rotation(): void
    {
        $secret = Secret::create(['key' => 'db.password', 'value' => 'secret123']);
        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now()->subDay(),
        ]);

        $this->artisan('locksmith:status')
            ->expectsOutputToContain('db.password')
            ->assertSuccessful();
    }
}
