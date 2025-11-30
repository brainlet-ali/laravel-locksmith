<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Services\RotationManager;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Console\Exception\RuntimeException;

class RollbackCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_rollback_command_requires_key_argument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        $this->artisan('locksmith:rollback');
    }

    public function test_rollback_command_fails_for_nonexistent_secret(): void
    {
        $this->artisan('locksmith:rollback', ['key' => 'nonexistent.key'])
            ->expectsOutput('Secret [nonexistent.key] not found.')
            ->assertFailed();
    }

    public function test_rollback_command_fails_without_previous_value(): void
    {
        Secret::create(['key' => 'test.key', 'value' => 'current_value']);

        $this->artisan('locksmith:rollback', ['key' => 'test.key'])
            ->expectsOutput('Secret [test.key] has no previous value to rollback to.')
            ->assertFailed();
    }

    public function test_rollback_command_succeeds_with_previous_value(): void
    {
        Secret::create([
            'key' => 'test.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $this->artisan('locksmith:rollback', ['key' => 'test.key'])
            ->expectsOutput('Rolling back secret [test.key]...')
            ->expectsOutput('Secret [test.key] rolled back successfully.')
            ->assertSuccessful();

        $secret = Secret::where('key', 'test.key')->first();
        $this->assertEquals('old_value', $secret->value);
        $this->assertNull($secret->previous_value);
    }

    public function test_rollback_command_fails_when_rollback_operation_fails(): void
    {
        Secret::create([
            'key' => 'test.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $mockManager = Mockery::mock(RotationManager::class);
        $mockManager->shouldReceive('rollbackValue')
            ->once()
            ->andReturn(false);
        $this->app->instance(RotationManager::class, $mockManager);

        $this->artisan('locksmith:rollback', ['key' => 'test.key'])
            ->expectsOutput('Rolling back secret [test.key]...')
            ->expectsOutput('Failed to rollback secret [test.key].')
            ->assertFailed();
    }
}
