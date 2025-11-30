<?php

namespace BrainletAli\Locksmith\Tests\Unit\Enums;

use BrainletAli\Locksmith\Enums\PoolKeyStatus;
use BrainletAli\Locksmith\Tests\TestCase;

class PoolKeyStatusTest extends TestCase
{
    public function test_queued_status_has_correct_value(): void
    {
        $this->assertEquals(0, PoolKeyStatus::Queued->value);
    }

    public function test_active_status_has_correct_value(): void
    {
        $this->assertEquals(1, PoolKeyStatus::Active->value);
    }

    public function test_used_status_has_correct_value(): void
    {
        $this->assertEquals(2, PoolKeyStatus::Used->value);
    }

    public function test_expired_status_has_correct_value(): void
    {
        $this->assertEquals(3, PoolKeyStatus::Expired->value);
    }

    public function test_queued_label(): void
    {
        $this->assertEquals('Queued', PoolKeyStatus::Queued->label());
    }

    public function test_active_label(): void
    {
        $this->assertEquals('Active', PoolKeyStatus::Active->label());
    }

    public function test_used_label(): void
    {
        $this->assertEquals('Used', PoolKeyStatus::Used->label());
    }

    public function test_expired_label(): void
    {
        $this->assertEquals('Expired', PoolKeyStatus::Expired->label());
    }

    public function test_queued_is_available(): void
    {
        $this->assertTrue(PoolKeyStatus::Queued->isAvailable());
    }

    public function test_active_is_not_available(): void
    {
        $this->assertFalse(PoolKeyStatus::Active->isAvailable());
    }

    public function test_used_is_not_available(): void
    {
        $this->assertFalse(PoolKeyStatus::Used->isAvailable());
    }

    public function test_expired_is_not_available(): void
    {
        $this->assertFalse(PoolKeyStatus::Expired->isAvailable());
    }
}
