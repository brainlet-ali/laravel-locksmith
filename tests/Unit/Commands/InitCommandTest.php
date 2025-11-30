<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\Console\Exception\RuntimeException;

class InitCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_init_command_requires_key_argument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        $this->artisan('locksmith:init');
    }

    public function test_init_command_succeeds_when_recipe_init_returns_value(): void
    {
        $credentials = json_encode([
            'username' => 'test-user',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('init')->once()->andReturn($credentials);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:init', ['key' => 'aws.credentials'])
            ->expectsOutput('Secret [aws.credentials] initialized successfully.')
            ->assertSuccessful();

        $this->assertTrue(Secret::where('key', 'aws.credentials')->exists());
    }

    public function test_init_command_fails_when_recipe_init_returns_null(): void
    {
        // When validation fails in InitCredentials, it returns null
        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('init')->once()->andReturn(null);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:init', ['key' => 'aws.credentials'])
            ->expectsOutput('No value provided.')
            ->assertFailed();

        $this->assertFalse(Secret::where('key', 'aws.credentials')->exists());
    }

    public function test_init_command_stores_credentials_correctly(): void
    {
        $credentials = json_encode([
            'username' => 'my-iam-user',
            'access_key_id' => 'AKIAI44QH8DHBEXAMPLE',
            'secret_access_key' => 'je7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY',
        ]);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('init')->once()->andReturn($credentials);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:init', ['key' => 'aws.credentials'])
            ->assertSuccessful();

        $secret = Secret::where('key', 'aws.credentials')->first();
        $this->assertNotNull($secret);

        $decoded = json_decode($secret->value, true);
        $this->assertEquals('my-iam-user', $decoded['username']);
        $this->assertEquals('AKIAI44QH8DHBEXAMPLE', $decoded['access_key_id']);
        $this->assertEquals('je7MtGbClwBF/2Zp9Utk/h3yCo8nvbEXAMPLEKEY', $decoded['secret_access_key']);
    }

    public function test_init_command_confirms_overwrite_for_existing_secret(): void
    {
        Secret::create(['key' => 'aws.credentials', 'value' => 'old_value']);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('init')->never();
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:init', ['key' => 'aws.credentials'])
            ->expectsConfirmation('Secret [aws.credentials] already exists. Overwrite?', 'no')
            ->expectsOutput('Cancelled.')
            ->assertSuccessful();
    }

    public function test_init_command_overwrites_when_confirmed(): void
    {
        Secret::create(['key' => 'aws.credentials', 'value' => 'old_value']);

        $newCredentials = json_encode([
            'username' => 'new-user',
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        ]);

        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('init')->once()->andReturn($newCredentials);
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:init', ['key' => 'aws.credentials'])
            ->expectsConfirmation('Secret [aws.credentials] already exists. Overwrite?', 'yes')
            ->expectsOutput('Secret [aws.credentials] initialized successfully.')
            ->assertSuccessful();

        $secret = Secret::where('key', 'aws.credentials')->first();
        $decoded = json_decode($secret->value, true);
        $this->assertEquals('new-user', $decoded['username']);
    }

    public function test_init_command_uses_password_prompt_for_non_initializable_recipe(): void
    {
        // For non-aws keys, the command should use a simple password prompt
        // Since we can't easily mock Laravel Prompts, we'll just verify the flow
        // by checking that the command fails when no input is provided

        $this->artisan('locksmith:init', ['key' => 'stripe.secret'])
            ->expectsQuestion('Secret value', '')
            ->expectsOutput('No value provided.')
            ->assertFailed();
    }

    public function test_init_command_succeeds_with_simple_password_for_non_aws_key(): void
    {
        $this->artisan('locksmith:init', ['key' => 'stripe.secret'])
            ->expectsQuestion('Secret value', 'sk_test_12345')
            ->expectsOutput('Secret [stripe.secret] initialized successfully.')
            ->assertSuccessful();

        $secret = Secret::where('key', 'stripe.secret')->first();
        $this->assertEquals('sk_test_12345', $secret->value);
    }

    public function test_init_command_handles_exception_from_recipe(): void
    {
        $mock = Mockery::mock(AwsRecipe::class);
        $mock->shouldReceive('init')
            ->once()
            ->andThrow(new \RuntimeException('AWS API connection failed'));
        $this->app->instance(AwsRecipe::class, $mock);

        $this->artisan('locksmith:init', ['key' => 'aws.credentials'])
            ->expectsOutputToContain('Initializing secret [aws.credentials]')
            ->expectsOutput('Failed to initialize secret [aws.credentials].')
            ->expectsOutput('Reason: AWS API connection failed')
            ->assertFailed();

        $this->assertFalse(Secret::where('key', 'aws.credentials')->exists());
    }
}
