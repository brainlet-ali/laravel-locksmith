<?php

namespace BrainletAli\Locksmith\Tests\Unit\Commands;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PoolRotateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pool_rotate_command_with_no_configured_pools(): void
    {
        config(['locksmith.pools' => []]);

        $this->artisan('locksmith:pool-rotate')
            ->expectsOutput('No pools configured for scheduled rotation.')
            ->assertSuccessful();
    }

    public function test_pool_rotate_command_skips_non_array_config_entries(): void
    {
        config(['locksmith.pools' => [
            'notify_below' => 2,
        ]]);

        $this->artisan('locksmith:pool-rotate')
            ->expectsOutput('Rotated 0 pool secrets.')
            ->assertSuccessful();
    }

    public function test_pool_rotate_command_rotates_configured_pools(): void
    {
        $pool = Locksmith::pool('stripe.secret');
        $pool->add(['key_001', 'key_002', 'key_003']);

        config(['locksmith.pools' => [
            'stripe.secret' => [
                'grace' => 30,
            ],
            'notify_below' => 2,
        ]]);

        $this->artisan('locksmith:pool-rotate')
            ->expectsOutput("Rotated 'stripe.secret' to next pool key.")
            ->expectsOutput('Rotated 1 pool secrets.')
            ->assertSuccessful();

        $this->assertEquals('key_002', Locksmith::get('stripe.secret'));
    }

    public function test_pool_rotate_command_skips_empty_pools(): void
    {
        $pool = Locksmith::pool('empty.pool');
        $pool->add(['only_key']); // Becomes active, no queued

        config(['locksmith.pools' => [
            'empty.pool' => [
                'grace' => 60,
            ],
        ]]);

        $this->artisan('locksmith:pool-rotate')
            ->expectsOutput("Pool 'empty.pool' has no queued keys. Skipping.")
            ->expectsOutput('Rotated 0 pool secrets.')
            ->assertSuccessful();
    }

    public function test_pool_rotate_command_rotates_multiple_pools(): void
    {
        Locksmith::pool('pool.one')->add(['one_1', 'one_2']);
        Locksmith::pool('pool.two')->add(['two_1', 'two_2']);

        config(['locksmith.pools' => [
            'pool.one' => ['grace' => 60],
            'pool.two' => ['grace' => 60],
            'notify_below' => 2,
        ]]);

        $this->artisan('locksmith:pool-rotate')
            ->expectsOutput("Rotated 'pool.one' to next pool key.")
            ->expectsOutput("Rotated 'pool.two' to next pool key.")
            ->expectsOutput('Rotated 2 pool secrets.')
            ->assertSuccessful();

        $this->assertEquals('one_2', Locksmith::get('pool.one'));
        $this->assertEquals('two_2', Locksmith::get('pool.two'));
    }
}
