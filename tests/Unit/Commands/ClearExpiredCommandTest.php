<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClearExpiredCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_expired_command_with_no_expired_secrets(): void
    {
        $this->artisan('locksmith:clear-expired')
            ->expectsOutput('Cleared 0 expired grace periods.')
            ->assertSuccessful();
    }

    public function test_clear_expired_command_clears_expired_grace_periods(): void
    {
        Secret::create([
            'key' => 'expired.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        Secret::create([
            'key' => 'active.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $this->artisan('locksmith:clear-expired')
            ->expectsOutput('Cleared 1 expired grace periods.')
            ->assertSuccessful();

        $expired = Secret::where('key', 'expired.key')->first();
        $active = Secret::where('key', 'active.key')->first();

        $this->assertNull($expired->previous_value);
        $this->assertNull($expired->previous_value_expires_at);
        $this->assertEquals('old_value', $active->previous_value);
        $this->assertNotNull($active->previous_value_expires_at);
    }

    public function test_clear_expired_command_with_specific_key(): void
    {
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        Secret::create([
            'key' => 'stripe.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        $this->artisan('locksmith:clear-expired', ['key' => 'aws.credentials'])
            ->expectsOutput('Cleared expired grace period for [aws.credentials]: 1')
            ->assertSuccessful();

        $aws = Secret::where('key', 'aws.credentials')->first();
        $stripe = Secret::where('key', 'stripe.secret')->first();

        $this->assertNull($aws->previous_value);
        $this->assertEquals('old_value', $stripe->previous_value);
    }

    public function test_clear_expired_command_with_specific_key_not_expired(): void
    {
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $this->artisan('locksmith:clear-expired', ['key' => 'aws.credentials'])
            ->expectsOutput('Cleared expired grace period for [aws.credentials]: 0')
            ->assertSuccessful();

        $aws = Secret::where('key', 'aws.credentials')->first();
        $this->assertEquals('old_value', $aws->previous_value);
    }
}
