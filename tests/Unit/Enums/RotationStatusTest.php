<?php

namespace BrainletAli\Locksmith\Tests\Unit\Enums;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Tests\TestCase;

class RotationStatusTest extends TestCase
{
    public function test_label_returns_pending(): void
    {
        $this->assertEquals('Pending', RotationStatus::Pending->label());
    }

    public function test_label_returns_success(): void
    {
        $this->assertEquals('Success', RotationStatus::Success->label());
    }

    public function test_label_returns_failed(): void
    {
        $this->assertEquals('Failed', RotationStatus::Failed->label());
    }

    public function test_label_returns_verified(): void
    {
        $this->assertEquals('Verified', RotationStatus::Verified->label());
    }

    public function test_label_returns_rolled_back(): void
    {
        $this->assertEquals('Rolled Back', RotationStatus::RolledBack->label());
    }

    public function test_label_returns_discard_success(): void
    {
        $this->assertEquals('Discard Success', RotationStatus::DiscardSuccess->label());
    }

    public function test_label_returns_discard_failed(): void
    {
        $this->assertEquals('Discard Failed', RotationStatus::DiscardFailed->label());
    }

    public function test_is_success_returns_true_for_success(): void
    {
        $this->assertTrue(RotationStatus::Success->isSuccess());
    }

    public function test_is_success_returns_true_for_verified(): void
    {
        $this->assertTrue(RotationStatus::Verified->isSuccess());
    }

    public function test_is_success_returns_false_for_failed(): void
    {
        $this->assertFalse(RotationStatus::Failed->isSuccess());
    }

    public function test_is_success_returns_false_for_pending(): void
    {
        $this->assertFalse(RotationStatus::Pending->isSuccess());
    }

    public function test_is_success_returns_false_for_rolled_back(): void
    {
        $this->assertFalse(RotationStatus::RolledBack->isSuccess());
    }
}
