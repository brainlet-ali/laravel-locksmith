<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Console\Exception\RuntimeException;

class RotateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_rotate_command_requires_key_argument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        $this->artisan('locksmith:rotate');
    }

    public function test_rotate_command_fails_for_nonexistent_secret(): void
    {
        $this->artisan('locksmith:rotate', ['key' => 'nonexistent.key'])
            ->expectsOutput('Secret [nonexistent.key] not found.')
            ->assertFailed();
    }

    public function test_rotate_command_requires_recipe(): void
    {
        Secret::create(['key' => 'test.key', 'value' => 'old_value']);

        $this->artisan('locksmith:rotate', ['key' => 'test.key'])
            ->expectsOutput('No recipe specified. Use --recipe option (e.g., --recipe=aws).')
            ->assertFailed();
    }

    public function test_rotate_command_with_recipe_option(): void
    {
        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('new_aws_key');
        $mock->shouldReceive('validate')->once()->andReturn(true);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:rotate', ['key' => 'aws.secret', '--recipe' => 'aws'])
            ->expectsOutput('Rotating secret [aws.secret]...')
            ->expectsOutput('Secret [aws.secret] rotated successfully.')
            ->assertSuccessful();

        $this->assertEquals('new_aws_key', Secret::where('key', 'aws.secret')->first()->value);
    }

    public function test_rotate_command_with_grace_period_option(): void
    {
        Secret::create(['key' => 'aws.key', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('new_aws_key');
        $mock->shouldReceive('validate')->once()->andReturn(true);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:rotate', [
            'key' => 'aws.key',
            '--recipe' => 'aws',
            '--grace' => 120,
        ])
            ->assertSuccessful();

        $secret = Secret::where('key', 'aws.key')->first();
        $this->assertEquals('new_aws_key', $secret->value);
        $this->assertNotNull($secret->previous_value_expires_at);
    }

    public function test_rotate_command_fails_with_unknown_recipe(): void
    {
        Secret::create(['key' => 'test.key', 'value' => 'old_value']);

        $this->artisan('locksmith:rotate', ['key' => 'test.key', '--recipe' => 'unknown'])
            ->expectsOutput('Unknown recipe [unknown]. Available: aws')
            ->assertFailed();
    }

    public function test_rotate_command_fails_when_rotation_fails(): void
    {
        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('invalid_key');
        $mock->shouldReceive('validate')->once()->andReturn(false);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:rotate', ['key' => 'aws.secret', '--recipe' => 'aws'])
            ->expectsOutput('Rotating secret [aws.secret]...')
            ->expectsOutput('Failed to rotate secret [aws.secret].')
            ->assertFailed();
    }

    public function test_rotate_command_resolves_custom_recipe_from_config(): void
    {
        Secret::create(['key' => 'custom.key', 'value' => 'old_value']);

        config(['locksmith.recipes.custom' => FakeRecipe::class]);

        $this->artisan('locksmith:rotate', ['key' => 'custom.key', '--recipe' => 'custom'])
            ->expectsOutput('Rotating secret [custom.key]...')
            ->expectsOutput('Secret [custom.key] rotated successfully.')
            ->assertSuccessful();

        $this->assertEquals('fake_generated_value', Secret::where('key', 'custom.key')->first()->value);
    }

    public function test_rotate_command_warns_when_discarding_previous_key(): void
    {
        Secret::create([
            'key' => 'aws.secret',
            'value' => 'current_key',
            'previous_value' => 'old_key_to_discard',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('new_aws_key');
        $mock->shouldReceive('validate')->once()->andReturn(true);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:rotate', ['key' => 'aws.secret', '--recipe' => 'aws'])
            ->expectsOutputToContain('Discarding previous key')
            ->expectsOutput('Rotating secret [aws.secret]...')
            ->expectsOutput('Secret [aws.secret] rotated successfully.')
            ->assertSuccessful();
    }

    public function test_rotate_command_displays_error_reason_when_rotation_fails(): void
    {
        Secret::create(['key' => 'aws.secret', 'value' => 'old_key']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andThrow(new Exception('API rate limit exceeded'));
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:rotate', ['key' => 'aws.secret', '--recipe' => 'aws'])
            ->expectsOutput('Rotating secret [aws.secret]...')
            ->expectsOutput('Failed to rotate secret [aws.secret].')
            ->expectsOutputToContain('API rate limit exceeded')
            ->assertFailed();
    }

    public function test_rotate_command_skips_provider_cleanup_with_flag(): void
    {
        Secret::create([
            'key' => 'aws.secret',
            'value' => 'current_key',
            'previous_value' => 'old_key',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('generate')->once()->andReturn('new_aws_key');
        $mock->shouldReceive('validate')->once()->andReturn(true);
        $this->app->instance(AwsRecipe::class, $mock);

        // With --no-provider-cleanup, should NOT show "Discarding previous key" warning
        $this->artisan('locksmith:rotate', [
            'key' => 'aws.secret',
            '--recipe' => 'aws',
            '--no-provider-cleanup' => true,
        ])
            ->expectsOutput('Rotating secret [aws.secret]...')
            ->expectsOutput('Secret [aws.secret] rotated successfully.')
            ->assertSuccessful();
    }
}

class FakeRecipe implements \BrainletAli\Locksmith\Contracts\Recipe
{
    public function generate(): string
    {
        return 'fake_generated_value';
    }

    public function validate(string $value): bool
    {
        return true;
    }
}
