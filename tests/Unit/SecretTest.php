<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_secret(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123456',
        ]);

        $this->assertDatabaseHas('locksmith_secrets', [
            'key' => 'api.test.secret',
        ]);
    }

    public function test_value_is_encrypted(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123456',
        ]);

        $rawValue = $secret->getRawOriginal('value');
        $this->assertNotEquals('sk_test_123456', $rawValue);
    }

    public function test_value_is_decrypted_on_access(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_123456',
        ]);

        $this->assertEquals('sk_test_123456', $secret->value);
    }

    public function test_previous_value_is_encrypted(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
        ]);

        $rawValue = $secret->getRawOriginal('previous_value');
        $this->assertNotEquals('sk_test_old', $rawValue);
        $this->assertEquals('sk_test_old', $secret->previous_value);
    }

    public function test_can_set_grace_period(): void
    {
        $expiresAt = now()->addHours(2);

        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => $expiresAt,
        ]);

        $this->assertTrue($secret->hasActiveGracePeriod());
    }

    public function test_grace_period_expired(): void
    {
        $expiresAt = now()->subHour();

        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => $expiresAt,
        ]);

        $this->assertFalse($secret->hasActiveGracePeriod());
    }

    public function test_get_current_value_returns_value(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_current',
        ]);

        $this->assertEquals('sk_test_current', $secret->getCurrentValue());
    }

    public function test_get_all_valid_values_during_grace_period(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $values = $secret->getAllValidValues();

        $this->assertCount(2, $values);
        $this->assertContains('sk_test_new', $values);
        $this->assertContains('sk_test_old', $values);
    }

    public function test_get_all_valid_values_after_grace_period(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        $values = $secret->getAllValidValues();

        $this->assertCount(1, $values);
        $this->assertContains('sk_test_new', $values);
    }
}
