<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable caching for these tests
        config(['locksmith.cache.enabled' => true]);
        config(['locksmith.cache.ttl' => 300]);
        config(['locksmith.cache.prefix' => 'locksmith:']);
    }

    public function test_get_caches_secret_value(): void
    {
        Secret::create(['key' => 'test.secret', 'value' => 'cached_value']);

        // First call - should hit database
        $value1 = Locksmith::get('test.secret');

        // Second call - should hit cache
        $value2 = Locksmith::get('test.secret');

        $this->assertEquals('cached_value', $value1);
        $this->assertEquals('cached_value', $value2);

        // Verify cache was used
        $this->assertTrue(Cache::has('locksmith:test.secret'));
    }

    public function test_set_invalidates_cache(): void
    {
        Secret::create(['key' => 'test.secret', 'value' => 'old_value']);

        // Populate cache
        Locksmith::get('test.secret');
        $this->assertTrue(Cache::has('locksmith:test.secret'));

        // Set new value
        Locksmith::set('test.secret', 'new_value');

        // Cache should be invalidated
        $this->assertFalse(Cache::has('locksmith:test.secret'));

        // Next get should return new value
        $this->assertEquals('new_value', Locksmith::get('test.secret'));
    }

    public function test_delete_invalidates_cache(): void
    {
        Secret::create(['key' => 'test.secret', 'value' => 'some_value']);

        // Populate cache
        Locksmith::get('test.secret');
        $this->assertTrue(Cache::has('locksmith:test.secret'));

        // Delete secret
        Locksmith::delete('test.secret');

        // Cache should be invalidated
        $this->assertFalse(Cache::has('locksmith:test.secret'));
    }

    public function test_cache_disabled_does_not_cache(): void
    {
        config(['locksmith.cache.enabled' => false]);

        Secret::create(['key' => 'test.secret', 'value' => 'value']);

        Locksmith::get('test.secret');

        // Cache should not be populated
        $this->assertFalse(Cache::has('locksmith:test.secret'));
    }

    public function test_forget_cache_clears_specific_key(): void
    {
        Secret::create(['key' => 'test.secret1', 'value' => 'value1']);
        Secret::create(['key' => 'test.secret2', 'value' => 'value2']);

        // Populate cache for both
        Locksmith::get('test.secret1');
        Locksmith::get('test.secret2');

        $this->assertTrue(Cache::has('locksmith:test.secret1'));
        $this->assertTrue(Cache::has('locksmith:test.secret2'));

        // Forget only one
        Locksmith::forgetCache('test.secret1');

        $this->assertFalse(Cache::has('locksmith:test.secret1'));
        $this->assertTrue(Cache::has('locksmith:test.secret2'));
    }

    public function test_flush_cache_clears_all_secrets(): void
    {
        Secret::create(['key' => 'test.secret1', 'value' => 'value1']);
        Secret::create(['key' => 'test.secret2', 'value' => 'value2']);

        // Populate cache
        Locksmith::get('test.secret1');
        Locksmith::get('test.secret2');

        $this->assertTrue(Cache::has('locksmith:test.secret1'));
        $this->assertTrue(Cache::has('locksmith:test.secret2'));

        // Flush all
        Locksmith::flushCache();

        $this->assertFalse(Cache::has('locksmith:test.secret1'));
        $this->assertFalse(Cache::has('locksmith:test.secret2'));
    }

    public function test_model_update_invalidates_cache(): void
    {
        $secret = Secret::create(['key' => 'test.secret', 'value' => 'old']);

        // Populate cache
        Locksmith::get('test.secret');
        $this->assertTrue(Cache::has('locksmith:test.secret'));

        // Update directly via model (not through Locksmith)
        $secret->update(['value' => 'new']);

        // Cache should be invalidated by observer
        $this->assertFalse(Cache::has('locksmith:test.secret'));
    }

    public function test_model_delete_invalidates_cache(): void
    {
        $secret = Secret::create(['key' => 'test.secret', 'value' => 'value']);

        // Populate cache
        Locksmith::get('test.secret');
        $this->assertTrue(Cache::has('locksmith:test.secret'));

        // Delete via model
        $secret->delete();

        // Cache should be invalidated by observer
        $this->assertFalse(Cache::has('locksmith:test.secret'));
    }

    public function test_cache_reduces_database_queries(): void
    {
        Secret::create(['key' => 'test.secret', 'value' => 'value']);

        // Enable query log
        DB::enableQueryLog();

        // First call - hits database
        Locksmith::get('test.secret');
        $queriesAfterFirst = count(DB::getQueryLog());

        // Second call - should hit cache, no new queries
        Locksmith::get('test.secret');
        $queriesAfterSecond = count(DB::getQueryLog());

        // Third call - should hit cache, no new queries
        Locksmith::get('test.secret');
        $queriesAfterThird = count(DB::getQueryLog());

        DB::disableQueryLog();

        // Only one query should have been made
        $this->assertEquals($queriesAfterFirst, $queriesAfterSecond);
        $this->assertEquals($queriesAfterSecond, $queriesAfterThird);
    }

    public function test_cache_uses_configured_prefix(): void
    {
        config(['locksmith.cache.prefix' => 'custom_prefix:']);

        Secret::create(['key' => 'test.secret', 'value' => 'value']);

        Locksmith::get('test.secret');

        $this->assertTrue(Cache::has('custom_prefix:test.secret'));
        $this->assertFalse(Cache::has('locksmith:test.secret'));
    }

    public function test_has_uses_cached_find(): void
    {
        Secret::create(['key' => 'test.secret', 'value' => 'value']);

        // Populate cache
        Locksmith::get('test.secret');

        DB::enableQueryLog();

        // has() should use cached find
        $exists = Locksmith::has('test.secret');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($exists);
        $this->assertCount(0, $queries);
    }

    public function test_forget_cache_returns_false_when_disabled(): void
    {
        config(['locksmith.cache.enabled' => false]);

        $result = Locksmith::forgetCache('test.secret');

        $this->assertFalse($result);
    }

    public function test_flush_cache_returns_false_when_disabled(): void
    {
        config(['locksmith.cache.enabled' => false]);

        $result = Locksmith::flushCache();

        $this->assertFalse($result);
    }

    public function test_cache_uses_configured_store(): void
    {
        config(['locksmith.cache.store' => 'array']);

        Secret::create(['key' => 'test.secret', 'value' => 'value']);

        Locksmith::get('test.secret');

        // Should use the configured store
        $this->assertTrue(Cache::store('array')->has('locksmith:test.secret'));
    }
}
