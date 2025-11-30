<?php

namespace BrainletAli\Locksmith\Tests\Unit\Logging;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\Support\TestRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class SystemLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_success_logs_to_configured_channel(): void
    {
        config(['locksmith.logging.enabled' => true]);
        config(['locksmith.logging.channel' => 'stack']);

        Log::shouldReceive('channel')
            ->with('stack')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'rotated successfully')
                    && $context['key'] === 'api.key'
                    && isset($context['correlation_id']);
            });

        Secret::create(['key' => 'api.key', 'value' => 'old']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));
    }

    public function test_rotation_failure_logs_to_configured_channel(): void
    {
        config(['locksmith.logging.enabled' => true]);
        config(['locksmith.logging.channel' => 'stack']);

        Log::shouldReceive('channel')
            ->with('stack')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'rotation failed')
                    && $context['key'] === 'api.key'
                    && isset($context['error']);
            });

        Secret::create(['key' => 'api.key', 'value' => 'old']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => throw new Exception('API Error')));
    }

    public function test_logging_disabled_by_default(): void
    {
        config(['locksmith.logging.enabled' => false]);

        Log::shouldReceive('channel')->never();
        Log::shouldReceive('info')->never();
        Log::shouldReceive('error')->never();

        Secret::create(['key' => 'api.key', 'value' => 'old']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));
    }

    public function test_logging_uses_default_channel_when_not_specified(): void
    {
        config(['locksmith.logging.enabled' => true]);
        config(['locksmith.logging.channel' => null]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'rotated successfully');
            });

        Secret::create(['key' => 'api.key', 'value' => 'old']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));
    }

    public function test_log_includes_duration_metadata(): void
    {
        config(['locksmith.logging.enabled' => true]);
        config(['locksmith.logging.channel' => null]);

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return isset($context['duration_ms']) && is_numeric($context['duration_ms']);
            });

        Secret::create(['key' => 'api.key', 'value' => 'old']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));
    }
}
