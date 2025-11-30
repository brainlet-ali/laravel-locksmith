<?php

namespace BrainletAli\Locksmith\Tests\Unit\Jobs;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Jobs\GracePeriodCleanupJob;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Services\RecipeResolver;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class GracePeriodCleanupJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_job_discards_and_clears_grace_period(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockRecipe = Mockery::mock(DiscardableRecipe::class, Recipe::class);
        $mockRecipe->shouldReceive('discard')
            ->once()
            ->with('old_value');

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('test.secret')
            ->andReturn($mockRecipe);

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $secret->refresh();
        $this->assertNull($secret->previous_value);
        $this->assertNull($secret->previous_value_expires_at);
    }

    public function test_job_skips_provider_cleanup_when_disabled(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldNotReceive('resolveForKey');

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: false);
        $job->handle($mockResolver);

        $secret->refresh();
        // Grace period should still be cleared
        $this->assertNull($secret->previous_value);
        $this->assertNull($secret->previous_value_expires_at);
    }

    public function test_job_skips_if_previous_value_changed(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'newest_value',
            'previous_value' => 'new_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldNotReceive('resolveForKey');

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $secret->refresh();
        $this->assertEquals('new_value', $secret->previous_value);
        $this->assertNotNull($secret->previous_value_expires_at);
    }

    public function test_job_skips_if_secret_not_found(): void
    {
        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldNotReceive('resolveForKey');

        $job = new GracePeriodCleanupJob('nonexistent.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $this->assertTrue(true);
    }

    public function test_job_skips_if_previous_value_already_null(): void
    {
        Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
            'previous_value' => null,
            'previous_value_expires_at' => null,
        ]);

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldNotReceive('resolveForKey');

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $this->assertTrue(true);
    }

    public function test_job_clears_grace_period_even_if_recipe_not_discardable(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockRecipe = Mockery::mock(Recipe::class);

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('test.secret')
            ->andReturn($mockRecipe);

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $secret->refresh();
        $this->assertNull($secret->previous_value);
        $this->assertNull($secret->previous_value_expires_at);
    }

    public function test_job_logs_discard_success(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockRecipe = Mockery::mock(DiscardableRecipe::class, Recipe::class);
        $mockRecipe->shouldReceive('discard')->once()->with('old_value');

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('test.secret')
            ->andReturn($mockRecipe);

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $log = RotationLog::where('secret_id', $secret->id)
            ->where('status', RotationStatus::DiscardSuccess)
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->error_message);
        $this->assertEquals('discard', $log->metadata['operation']);
        $this->assertEquals('queue', $log->metadata['source']);
    }

    public function test_job_logs_discard_failure(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockRecipe = Mockery::mock(DiscardableRecipe::class, Recipe::class);
        $mockRecipe->shouldReceive('discard')
            ->once()
            ->with('old_value')
            ->andThrow(new Exception('Provider API error'));

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('test.secret')
            ->andReturn($mockRecipe);

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $log = RotationLog::where('secret_id', $secret->id)
            ->where('status', RotationStatus::DiscardFailed)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('Provider API error', $log->error_message);
        $this->assertEquals('discard', $log->metadata['operation']);
    }

    public function test_discard_log_includes_metadata(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockRecipe = Mockery::mock(DiscardableRecipe::class, Recipe::class);
        $mockRecipe->shouldReceive('discard')->once();

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')->andReturn($mockRecipe);

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: true);
        $job->handle($mockResolver);

        $log = RotationLog::where('secret_id', $secret->id)->latest()->first();

        $this->assertArrayHasKey('correlation_id', $log->metadata);
        $this->assertArrayHasKey('duration_ms', $log->metadata);
        $this->assertArrayHasKey('source', $log->metadata);
        $this->assertArrayHasKey('triggered_by', $log->metadata);
        $this->assertArrayHasKey('recipe', $log->metadata);
        $this->assertArrayHasKey('operation', $log->metadata);
        $this->assertEquals('scheduled', $log->metadata['triggered_by']);
    }

    public function test_no_log_created_when_provider_cleanup_disabled(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subMinute(),
        ]);

        $mockResolver = Mockery::mock(RecipeResolver::class);

        $job = new GracePeriodCleanupJob('test.secret', 'old_value', providerCleanup: false);
        $job->handle($mockResolver);

        $log = RotationLog::where('secret_id', $secret->id)
            ->whereIn('status', [RotationStatus::DiscardSuccess, RotationStatus::DiscardFailed])
            ->first();

        $this->assertNull($log);
    }
}
