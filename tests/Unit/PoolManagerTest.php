<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Enums\PoolKeyStatus;
use BrainletAli\Locksmith\Events\PoolKeyActivated;
use BrainletAli\Locksmith\Events\PoolLow;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\PoolKey;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Services\RecipeResolver;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;

class PoolManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_can_add_keys_to_pool(): void
    {
        $pool = Locksmith::pool('stripe.secret');

        $added = $pool->add([
            'rk_live_key1',
            'rk_live_key2',
            'rk_live_key3',
        ]);

        $this->assertEquals(3, $added);
        $this->assertEquals(3, $pool->count());
    }

    public function test_first_key_is_activated_when_adding_to_empty_pool(): void
    {
        $pool = Locksmith::pool('stripe.secret');

        $pool->add(['rk_live_key1', 'rk_live_key2']);

        $activeKey = $pool->getActiveKey();
        $this->assertNotNull($activeKey);
        $this->assertEquals('rk_live_key1', $activeKey->value);
        $this->assertEquals(PoolKeyStatus::Active, $activeKey->status);
    }

    public function test_remaining_returns_queued_key_count(): void
    {
        $pool = Locksmith::pool('stripe.secret');

        $pool->add(['rk_live_key1', 'rk_live_key2', 'rk_live_key3']);

        // First key is active, 2 remaining queued
        $this->assertEquals(2, $pool->remaining());
    }

    public function test_can_rotate_to_next_key(): void
    {
        Event::fake([PoolKeyActivated::class, PoolLow::class]);

        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['rk_live_key1', 'rk_live_key2', 'rk_live_key3']);

        $newKey = $pool->rotateNext();

        $this->assertNotNull($newKey);
        $this->assertEquals('rk_live_key2', $newKey->value);
        $this->assertEquals(PoolKeyStatus::Active, $newKey->status);

        // Old key should be marked as used
        $oldKey = PoolKey::where('secret_key', 'stripe.secret')
            ->where('position', 0)
            ->first();
        $this->assertEquals(PoolKeyStatus::Used, $oldKey->status);

        Event::assertDispatched(PoolKeyActivated::class);
    }

    public function test_rotate_syncs_to_secrets_table(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['rk_live_key1', 'rk_live_key2']);

        // Initial secret should be set
        $this->assertEquals('rk_live_key1', Locksmith::get('stripe.secret'));

        $pool->rotateNext(60);

        // New secret should be active
        $this->assertEquals('rk_live_key2', Locksmith::get('stripe.secret'));

        // Grace period should be active
        $this->assertTrue(Locksmith::isInGracePeriod('stripe.secret'));
        $this->assertEquals('rk_live_key1', Locksmith::getPreviousValue('stripe.secret'));
    }

    public function test_rotate_returns_null_when_pool_empty(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['rk_live_key1']); // Only one key, becomes active

        $result = $pool->rotateNext();

        $this->assertNull($result);
    }

    public function test_rotate_fires_pool_low_event_when_below_threshold(): void
    {
        Event::fake([PoolLow::class, PoolKeyActivated::class]);
        config(['locksmith.pools.notify_below' => 2]);

        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key1', 'key2', 'key3']); // 1 active, 2 queued

        $pool->rotateNext(); // Now 1 queued (below threshold)

        Event::assertDispatched(PoolLow::class, function ($event) {
            return $event->secretKey === 'stripe.secret'
                && $event->remaining === 1;
        });
    }

    public function test_can_get_pool_status(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key1', 'key2', 'key3']);

        $status = $pool->status();

        $this->assertEquals('stripe.secret', $status['secret_key']);
        $this->assertEquals(3, $status['total']);
        $this->assertEquals(2, $status['queued']);
        $this->assertEquals(1, $status['active']);
        $this->assertEquals(0, $status['used']);
        $this->assertEquals(0, $status['expired']);
    }

    public function test_can_clear_pool(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key1', 'key2', 'key3']);

        $deleted = $pool->clear();

        $this->assertEquals(3, $deleted);
        $this->assertEquals(0, $pool->count());
    }

    public function test_can_prune_used_and_expired_keys(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key1', 'key2', 'key3', 'key4']);

        // Rotate twice to create used keys
        $pool->rotateNext();
        $pool->rotateNext();

        $status = $pool->status();
        $this->assertEquals(2, $status['used']);

        $pruned = $pool->prune();

        $this->assertEquals(2, $pruned);
        $this->assertEquals(2, $pool->count());
    }

    public function test_keys_are_encrypted_in_database(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['rk_live_secret_key']);

        $rawValue = PoolKey::where('secret_key', 'stripe.secret')
            ->first()
            ->getRawOriginal('value');

        $this->assertNotEquals('rk_live_secret_key', $rawValue);
        $this->assertStringStartsWith('eyJ', $rawValue); // Base64 encrypted
    }

    public function test_validator_skips_invalid_keys(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        // Add a valid key first (becomes active), then invalid, then valid
        $pool->add(['rk_live_first', 'invalid_key', 'rk_live_second']);

        // Set validator that rejects keys not starting with rk_live
        $pool->withValidator(fn ($value) => str_starts_with($value, 'rk_live'));

        // Rotate - should skip invalid_key and activate rk_live_second
        $newKey = $pool->rotateNext();

        $this->assertNotNull($newKey);
        $this->assertEquals('rk_live_second', $newKey->value);

        // Invalid key (position 1) should be marked as expired
        $invalidKey = PoolKey::where('secret_key', 'stripe.secret')
            ->where('position', 1)
            ->first();
        $this->assertEquals(PoolKeyStatus::Expired, $invalidKey->status);
    }

    public function test_positions_are_assigned_incrementally(): void
    {
        $pool = Locksmith::pool('stripe.secret');

        $pool->add(['key1', 'key2']);
        $pool->add(['key3', 'key4']);

        $positions = PoolKey::where('secret_key', 'stripe.secret')
            ->orderBy('position')
            ->pluck('position')
            ->toArray();

        $this->assertEquals([0, 1, 2, 3], $positions);
    }

    public function test_get_value_returns_active_key_value(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['rk_live_key1', 'rk_live_key2']);

        $this->assertEquals('rk_live_key1', $pool->getValue());

        $pool->rotateNext();

        $this->assertEquals('rk_live_key2', $pool->getValue());
    }

    public function test_get_value_returns_null_when_no_active_key(): void
    {
        $pool = Locksmith::pool('stripe.secret');

        $this->assertNull($pool->getValue());
    }

    public function test_has_active_key_returns_correct_boolean(): void
    {
        $pool = Locksmith::pool('stripe.secret');

        $this->assertFalse($pool->hasActiveKey());

        $pool->add(['key1']);

        $this->assertTrue($pool->hasActiveKey());
    }

    public function test_get_queued_returns_ordered_collection(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key1', 'key2', 'key3']);

        $queued = $pool->getQueued();

        $this->assertCount(2, $queued); // First is active
        $this->assertEquals('key2', $queued->first()->value);
        $this->assertEquals('key3', $queued->last()->value);
    }

    public function test_rotate_creates_secret_when_not_exists(): void
    {
        // Manually create pool keys without using pool->add() to avoid auto-creating secret
        PoolKey::create([
            'secret_key' => 'test.pool',
            'value' => 'key1',
            'position' => 0,
            'status' => PoolKeyStatus::Active,
        ]);
        PoolKey::create([
            'secret_key' => 'test.pool',
            'value' => 'key2',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        // Ensure no secret exists yet
        $this->assertNull(Locksmith::find('test.pool'));

        $pool = Locksmith::pool('test.pool');
        $pool->rotateNext();

        // Secret should now be created
        $this->assertNotNull(Locksmith::find('test.pool'));
        $this->assertEquals('key2', Locksmith::get('test.pool'));
    }

    public function test_rotate_discards_previous_value_when_recipe_is_discardable(): void
    {
        // Create a mock discardable recipe
        $mockRecipe = Mockery::mock(DiscardableRecipe::class, Recipe::class);
        $mockRecipe->shouldReceive('discard')
            ->once()
            ->with('old_value');

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('aws.credentials')
            ->andReturn($mockRecipe);

        $this->app->instance(RecipeResolver::class, $mockResolver);

        // Create a secret with a previous_value
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        // Create pool with keys
        $pool = Locksmith::pool('aws.credentials');
        $pool->add(['key1', 'key2']);

        // Rotate - this should discard the old previous_value before setting new one
        $pool->rotateNext();

        // Verify previous_value was cleared (discarded)
        $secret = Locksmith::find('aws.credentials');
        $this->assertNotNull($secret->previous_value);
        $this->assertEquals('key1', $secret->previous_value);
    }

    public function test_rotate_skips_discard_when_previous_value_is_null(): void
    {
        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldNotReceive('resolveForKey');

        $this->app->instance(RecipeResolver::class, $mockResolver);

        // Create a secret without previous_value
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => null,
            'previous_value_expires_at' => null,
        ]);

        $pool = Locksmith::pool('aws.credentials');
        $pool->add(['key1', 'key2']);

        // Rotate - should not attempt to discard since previous_value is null
        $pool->rotateNext();

        $secret = Locksmith::find('aws.credentials');
        $this->assertEquals('key2', $secret->value);
    }

    public function test_rotate_handles_discard_exception_gracefully(): void
    {
        // Create a mock discardable recipe that throws exception
        $mockRecipe = Mockery::mock(DiscardableRecipe::class, Recipe::class);
        $mockRecipe->shouldReceive('discard')
            ->once()
            ->with('old_value')
            ->andThrow(new Exception('Discard failed'));

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('aws.credentials')
            ->andReturn($mockRecipe);

        $this->app->instance(RecipeResolver::class, $mockResolver);

        // Create a secret with a previous_value
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $pool = Locksmith::pool('aws.credentials');
        $pool->add(['key1', 'key2']);

        // Rotate - should catch exception and continue
        $newKey = $pool->rotateNext();

        // Rotation should still succeed
        $this->assertNotNull($newKey);
        $this->assertEquals('key2', $newKey->value);
    }

    public function test_rotate_skips_discard_when_recipe_is_null(): void
    {
        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('stripe.secret')
            ->andReturn(null);

        $this->app->instance(RecipeResolver::class, $mockResolver);

        // Create a secret with a previous_value
        Secret::create([
            'key' => 'stripe.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key1', 'key2']);

        // Rotate - should not discard since recipe is null
        $pool->rotateNext();

        // Old previous_value should be cleared and replaced with new one
        $secret = Locksmith::find('stripe.secret');
        $this->assertEquals('key1', $secret->previous_value);
    }

    public function test_rotate_skips_discard_when_recipe_is_not_discardable(): void
    {
        // Create a mock recipe that is NOT discardable
        $mockRecipe = Mockery::mock(Recipe::class);

        $mockResolver = Mockery::mock(RecipeResolver::class);
        $mockResolver->shouldReceive('resolveForKey')
            ->with('custom.secret')
            ->andReturn($mockRecipe);

        $this->app->instance(RecipeResolver::class, $mockResolver);

        // Create a secret with a previous_value
        Secret::create([
            'key' => 'custom.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $pool = Locksmith::pool('custom.secret');
        $pool->add(['key1', 'key2']);

        // Rotate - should not attempt to discard since recipe is not DiscardableRecipe
        $pool->rotateNext();

        // Old previous_value should be cleared and replaced with new one
        $secret = Locksmith::find('custom.secret');
        $this->assertEquals('key1', $secret->previous_value);
    }

    public function test_activate_next_syncs_to_locksmith(): void
    {
        // Manually create queued keys
        PoolKey::create([
            'secret_key' => 'test.pool',
            'value' => 'key1',
            'position' => 0,
            'status' => PoolKeyStatus::Queued,
        ]);

        $pool = Locksmith::pool('test.pool');
        $activatedKey = $pool->activateNext();

        $this->assertNotNull($activatedKey);
        $this->assertEquals('key1', $activatedKey->value);
        $this->assertEquals(PoolKeyStatus::Active, $activatedKey->status);

        // Should be synced to Locksmith
        $this->assertEquals('key1', Locksmith::get('test.pool'));
    }

    public function test_activate_next_returns_null_when_no_queued_keys(): void
    {
        $pool = Locksmith::pool('test.pool');

        $result = $pool->activateNext();

        $this->assertNull($result);
    }
}
