<?php

namespace BrainletAli\Locksmith\Tests\Unit\Models;

use BrainletAli\Locksmith\Enums\PoolKeyStatus;
use BrainletAli\Locksmith\Models\PoolKey;
use BrainletAli\Locksmith\Tests\TestCase;
use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PoolKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_queued_returns_true_for_queued_status(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        $this->assertTrue($poolKey->isQueued());
    }

    public function test_is_queued_returns_false_for_non_queued_status(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Active,
        ]);

        $this->assertFalse($poolKey->isQueued());
    }

    public function test_is_active_returns_true_for_active_status(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Active,
        ]);

        $this->assertTrue($poolKey->isActive());
    }

    public function test_is_active_returns_false_for_non_active_status(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        $this->assertFalse($poolKey->isActive());
    }

    public function test_is_expired_returns_false_when_no_expiry(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
            'expires_at' => null,
        ]);

        $this->assertFalse($poolKey->isExpired());
    }

    public function test_is_expired_returns_true_when_past_expiry(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue($poolKey->isExpired());
    }

    public function test_is_expired_returns_false_when_future_expiry(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
            'expires_at' => now()->addDay(),
        ]);

        $this->assertFalse($poolKey->isExpired());
    }

    public function test_activate_sets_status_and_timestamp(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        $this->assertNull($poolKey->activated_at);

        $poolKey->activate();

        $poolKey->refresh();

        $this->assertEquals(PoolKeyStatus::Active, $poolKey->status);
        $this->assertNotNull($poolKey->activated_at);
    }

    public function test_mark_as_used_sets_status(): void
    {
        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'test-value',
            'position' => 1,
            'status' => PoolKeyStatus::Active,
        ]);

        $poolKey->markAsUsed();

        $poolKey->refresh();

        $this->assertEquals(PoolKeyStatus::Used, $poolKey->status);
    }

    public function test_scope_queued_filters_correctly(): void
    {
        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'queued-1',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'active-1',
            'position' => 2,
            'status' => PoolKeyStatus::Active,
        ]);

        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'queued-2',
            'position' => 3,
            'status' => PoolKeyStatus::Queued,
        ]);

        $queued = PoolKey::queued()->get();

        $this->assertCount(2, $queued);
    }

    public function test_scope_active_filters_correctly(): void
    {
        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'queued-1',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'active-1',
            'position' => 2,
            'status' => PoolKeyStatus::Active,
        ]);

        $active = PoolKey::active()->get();

        $this->assertCount(1, $active);
        $this->assertEquals('active-1', $active->first()->value);
    }

    public function test_scope_ordered_sorts_by_position(): void
    {
        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'third',
            'position' => 3,
            'status' => PoolKeyStatus::Queued,
        ]);

        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'first',
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        PoolKey::create([
            'secret_key' => 'test.key',
            'value' => 'second',
            'position' => 2,
            'status' => PoolKeyStatus::Queued,
        ]);

        $ordered = PoolKey::ordered()->get();

        $this->assertEquals('first', $ordered[0]->value);
        $this->assertEquals('second', $ordered[1]->value);
        $this->assertEquals('third', $ordered[2]->value);
    }

    public function test_value_is_encrypted_and_decrypted(): void
    {
        $originalValue = 'my-secret-api-key';

        $poolKey = PoolKey::create([
            'secret_key' => 'test.key',
            'value' => $originalValue,
            'position' => 1,
            'status' => PoolKeyStatus::Queued,
        ]);

        // Fresh fetch from database
        $poolKey->refresh();

        // Value should be decrypted when accessed
        $this->assertEquals($originalValue, $poolKey->value);

        // Raw database value should be different (encrypted)
        $rawValue = DB::table('locksmith_key_pools')
            ->where('id', $poolKey->id)
            ->value('value');

        $this->assertNotEquals($originalValue, $rawValue);
    }
}
