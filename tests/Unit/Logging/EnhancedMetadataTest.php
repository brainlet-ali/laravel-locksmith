<?php

namespace BrainletAli\Locksmith\Tests\Unit\Logging;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\Support\TestRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EnhancedMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotation_log_includes_correlation_id(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old']);

        $log = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));

        $this->assertNotNull($log->metadata);
        $this->assertArrayHasKey('correlation_id', $log->metadata);
        $this->assertNotEmpty($log->metadata['correlation_id']);
    }

    public function test_rotation_log_includes_duration(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old']);

        $log = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));

        $this->assertArrayHasKey('duration_ms', $log->metadata);
        $this->assertIsNumeric($log->metadata['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $log->metadata['duration_ms']);
    }

    public function test_rotation_log_includes_source(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old']);

        $log = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));

        $this->assertArrayHasKey('source', $log->metadata);
        $this->assertContains($log->metadata['source'], ['api', 'artisan', 'testing']);
    }

    public function test_rotation_log_includes_recipe_name_when_using_recipe(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old_key']);

        $log = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new_key'), 60);

        $this->assertArrayHasKey('recipe', $log->metadata);
        $this->assertEquals('TestRecipe', $log->metadata['recipe']);
    }

    public function test_rotation_log_includes_triggered_by(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old']);

        $log = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new'));

        $this->assertArrayHasKey('triggered_by', $log->metadata);
        $this->assertContains($log->metadata['triggered_by'], ['api', 'artisan', 'queue', 'testing']);
    }

    public function test_failed_rotation_includes_metadata(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old']);

        $log = Locksmith::rotate('api.key', TestRecipe::make(fn () => throw new Exception('Error')));

        $this->assertNotNull($log->metadata);
        $this->assertArrayHasKey('correlation_id', $log->metadata);
        $this->assertArrayHasKey('duration_ms', $log->metadata);
    }

    public function test_correlation_id_is_unique_per_rotation(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'old']);

        $log1 = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new1'));
        $log2 = Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new2'));

        $this->assertNotEquals(
            $log1->metadata['correlation_id'],
            $log2->metadata['correlation_id']
        );
    }
}
