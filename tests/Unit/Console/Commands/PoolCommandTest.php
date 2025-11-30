<?php

namespace BrainletAli\Locksmith\Tests\Unit\Console\Commands;

use BrainletAli\Locksmith\Models\PoolKey;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class PoolCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_status_option_shows_pool_status(): void
    {
        $this->artisan('locksmith:pool', ['key' => 'test.key', '--status' => true])
            ->assertSuccessful()
            ->expectsOutput('Pool Status: test.key');
    }

    public function test_status_shows_empty_pool_warning(): void
    {
        $this->artisan('locksmith:pool', ['key' => 'test.key', '--status' => true])
            ->assertSuccessful()
            ->expectsOutput('Pool is empty! Add keys with --add');
    }

    public function test_status_shows_low_pool_warning(): void
    {
        // Add only 2 keys so pool shows low warning
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key1', 'status' => 0, 'position' => 1]);
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key2', 'status' => 0, 'position' => 2]);

        $this->artisan('locksmith:pool', ['key' => 'test.key', '--status' => true])
            ->assertSuccessful()
            ->expectsOutput('Pool running low! Only 2 keys remaining.');
    }

    public function test_clear_option_clears_pool(): void
    {
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key1', 'status' => 0, 'position' => 1]);
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key2', 'status' => 0, 'position' => 2]);

        $this->artisan('locksmith:pool', ['key' => 'test.key', '--clear' => true])
            ->assertSuccessful()
            ->expectsOutput('Cleared 2 keys from pool.');

        $this->assertEquals(0, PoolKey::where('secret_key', 'test.key')->count());
    }

    public function test_prune_option_removes_used_and_expired_keys(): void
    {
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key1', 'status' => 0, 'position' => 1]); // queued
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key2', 'status' => 2, 'position' => 2]); // used
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key3', 'status' => 3, 'position' => 3]); // expired

        $this->artisan('locksmith:pool', ['key' => 'test.key', '--prune' => true])
            ->assertSuccessful()
            ->expectsOutput('Pruned 2 used/expired keys from pool.');

        $this->assertEquals(1, PoolKey::where('secret_key', 'test.key')->count());
    }

    public function test_rotate_option_rotates_to_next_key(): void
    {
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key1', 'status' => 0, 'position' => 1]);

        $this->artisan('locksmith:pool', ['key' => 'test.key', '--rotate' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Rotated to next key in pool');
    }

    public function test_rotate_option_fails_when_no_keys_available(): void
    {
        $this->artisan('locksmith:pool', ['key' => 'test.key', '--rotate' => true])
            ->assertFailed()
            ->expectsOutput('No queued keys available for rotation!');
    }

    public function test_default_action_shows_status(): void
    {
        $this->artisan('locksmith:pool', ['key' => 'test.key'])
            ->assertSuccessful()
            ->expectsOutput('Pool Status: test.key');
    }

    public function test_status_shows_correct_counts(): void
    {
        // Create keys with different statuses
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key1', 'status' => 0, 'position' => 1]); // queued
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key2', 'status' => 0, 'position' => 2]); // queued
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key3', 'status' => 0, 'position' => 3]); // queued
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key4', 'status' => 1, 'position' => 4]); // active
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key5', 'status' => 2, 'position' => 5]); // used

        $this->artisan('locksmith:pool', ['key' => 'test.key', '--status' => true])
            ->assertSuccessful();

        // Verify counts in database match
        $this->assertEquals(3, PoolKey::where('secret_key', 'test.key')->where('status', 0)->count());
        $this->assertEquals(1, PoolKey::where('secret_key', 'test.key')->where('status', 1)->count());
        $this->assertEquals(1, PoolKey::where('secret_key', 'test.key')->where('status', 2)->count());
    }

    public function test_add_option_adds_keys_to_pool(): void
    {
        $this->artisan('locksmith:pool', ['key' => 'test.key', '--add' => true])
            ->expectsQuestion('Paste your keys (one per line)', "key1\nkey2\nkey3")
            ->assertSuccessful()
            ->expectsOutput('Added 3 keys to pool.');

        $this->assertEquals(3, PoolKey::where('secret_key', 'test.key')->count());
    }

    public function test_add_option_with_empty_input(): void
    {
        $this->artisan('locksmith:pool', ['key' => 'test.key', '--add' => true])
            ->expectsQuestion('Paste your keys (one per line)', '')
            ->assertSuccessful()
            ->expectsOutput('No keys added.');

        $this->assertEquals(0, PoolKey::where('secret_key', 'test.key')->count());
    }

    public function test_rotate_shows_grace_period(): void
    {
        PoolKey::create(['secret_key' => 'test.key', 'value' => 'key1', 'status' => 0, 'position' => 1]);

        $this->artisan('locksmith:pool', ['key' => 'test.key', '--rotate' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Grace period:');
    }
}
