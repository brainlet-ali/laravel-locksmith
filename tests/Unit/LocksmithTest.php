<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Events\SecretRotated;
use BrainletAli\Locksmith\Events\SecretRotating;
use BrainletAli\Locksmith\Events\SecretRotationFailed;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\Support\TestRecipe;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class LocksmithTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_can_set_secret(): void
    {
        Locksmith::set('api.test.key', 'sk_test_123');

        $this->assertDatabaseHas('locksmith_secrets', [
            'key' => 'api.test.key',
        ]);

        $secret = Secret::where('key', 'api.test.key')->first();
        $this->assertEquals('sk_test_123', $secret->value);
    }

    public function test_can_get_secret(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_123',
        ]);

        $value = Locksmith::get('api.test.key');

        $this->assertEquals('sk_test_123', $value);
    }

    public function test_get_returns_null_for_missing_secret(): void
    {
        $value = Locksmith::get('nonexistent.key');

        $this->assertNull($value);
    }

    public function test_set_updates_existing_secret(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        Locksmith::set('api.test.key', 'sk_test_new');

        $secret = Secret::where('key', 'api.test.key')->first();
        $this->assertEquals('sk_test_new', $secret->value);
        $this->assertCount(1, Secret::all());
    }

    public function test_can_rotate_with_recipe(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        $recipe = TestRecipe::make(
            generate: fn () => 'sk_test_new',
            validate: fn ($value) => str_starts_with($value, 'sk_'),
        );

        $log = Locksmith::rotate('api.test.key', $recipe);

        $secret = Secret::where('key', 'api.test.key')->first();
        $this->assertEquals('sk_test_new', $secret->value);
        $this->assertEquals('sk_test_old', $secret->previous_value);
        $this->assertNotNull($log);
    }

    public function test_rotate_creates_secret_if_not_exists(): void
    {
        $recipe = TestRecipe::make(
            generate: fn () => 'new_value',
            validate: fn ($value) => true,
        );

        $log = Locksmith::rotate('api.new.key', $recipe);

        $this->assertDatabaseHas('locksmith_secrets', [
            'key' => 'api.new.key',
        ]);

        $secret = Secret::where('key', 'api.new.key')->first();
        $this->assertEquals('new_value', $secret->value);
    }

    public function test_rotate_rolls_back_on_validation_failure(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        $recipe = TestRecipe::make(
            generate: fn () => 'invalid_key',
            validate: fn ($value) => str_starts_with($value, 'sk_'),
        );

        $log = Locksmith::rotate('api.test.key', $recipe);

        $secret = Secret::where('key', 'api.test.key')->first();
        $this->assertEquals('sk_test_old', $secret->value);
        $this->assertNull($secret->previous_value);
    }

    public function test_rotate_with_custom_grace_period(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        $recipe = TestRecipe::make(
            generate: fn () => 'sk_test_new',
            validate: fn ($value) => true,
        );

        Locksmith::rotate('api.test.key', $recipe, gracePeriodMinutes: 120);

        $secret = Secret::where('key', 'api.test.key')->first();
        $this->assertTrue($secret->hasActiveGracePeriod());
        $this->assertTrue($secret->previous_value_expires_at->gt(now()->addMinutes(100)));
    }

    public function test_rotate_dispatches_rotating_event(): void
    {
        Event::fake([SecretRotating::class]);

        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        $recipe = TestRecipe::make(
            generate: fn () => 'sk_test_new',
            validate: fn ($value) => true,
        );

        Locksmith::rotate('api.test.key', $recipe);

        Event::assertDispatched(SecretRotating::class, function ($event) {
            return $event->secret->key === 'api.test.key';
        });
    }

    public function test_rotate_dispatches_rotated_event_on_success(): void
    {
        Event::fake([SecretRotated::class]);

        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        $recipe = TestRecipe::make(
            generate: fn () => 'sk_test_new',
            validate: fn ($value) => true,
        );

        Locksmith::rotate('api.test.key', $recipe);

        Event::assertDispatched(SecretRotated::class, function ($event) {
            return $event->secret->key === 'api.test.key'
                && $event->log !== null;
        });
    }

    public function test_rotate_dispatches_failed_event_on_failure(): void
    {
        Event::fake([SecretRotationFailed::class]);

        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_old',
        ]);

        $recipe = TestRecipe::make(
            generate: fn () => throw new Exception('API Error'),
            validate: fn ($value) => true,
        );

        Locksmith::rotate('api.test.key', $recipe);

        Event::assertDispatched(SecretRotationFailed::class, function ($event) {
            return $event->secret->key === 'api.test.key'
                && $event->reason === 'API Error';
        });
    }

    public function test_has_returns_true_for_existing_secret(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_123',
        ]);

        $this->assertTrue(Locksmith::has('api.test.key'));
    }

    public function test_has_returns_false_for_missing_secret(): void
    {
        $this->assertFalse(Locksmith::has('nonexistent.key'));
    }

    public function test_delete_removes_secret(): void
    {
        Secret::create([
            'key' => 'api.test.key',
            'value' => 'sk_test_123',
        ]);

        Locksmith::delete('api.test.key');

        $this->assertDatabaseMissing('locksmith_secrets', [
            'key' => 'api.test.key',
        ]);
    }

    public function test_all_returns_all_secret_keys(): void
    {
        Secret::create(['key' => 'api.test.key', 'value' => 'sk_123']);
        Secret::create(['key' => 'api.twilio.key', 'value' => 'tw_123']);

        $keys = Locksmith::all();

        $this->assertCount(2, $keys);
        $this->assertContains('api.test.key', $keys);
        $this->assertContains('api.twilio.key', $keys);
    }

    public function test_find_returns_secret_model(): void
    {
        Secret::create(['key' => 'api.test.key', 'value' => 'sk_123']);

        $secret = Locksmith::find('api.test.key');

        $this->assertInstanceOf(Secret::class, $secret);
        $this->assertEquals('api.test.key', $secret->key);
    }

    public function test_find_returns_null_for_missing_secret(): void
    {
        $secret = Locksmith::find('nonexistent.key');

        $this->assertNull($secret);
    }

    public function test_get_valid_values_returns_current_value_when_no_grace_period(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'current_value']);

        $values = Locksmith::getValidValues('api.key');

        $this->assertEquals(['current_value'], $values);
    }

    public function test_get_valid_values_returns_both_values_during_grace_period(): void
    {
        Secret::create([
            'key' => 'api.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $values = Locksmith::getValidValues('api.key');

        $this->assertCount(2, $values);
        $this->assertContains('new_value', $values);
        $this->assertContains('old_value', $values);
    }

    public function test_get_valid_values_returns_empty_for_missing_secret(): void
    {
        $values = Locksmith::getValidValues('nonexistent.key');

        $this->assertEquals([], $values);
    }

    public function test_is_in_grace_period_returns_true_during_grace_period(): void
    {
        Secret::create([
            'key' => 'api.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $this->assertTrue(Locksmith::isInGracePeriod('api.key'));
    }

    public function test_is_in_grace_period_returns_false_when_no_grace_period(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'value']);

        $this->assertFalse(Locksmith::isInGracePeriod('api.key'));
    }

    public function test_is_in_grace_period_returns_false_for_missing_secret(): void
    {
        $this->assertFalse(Locksmith::isInGracePeriod('nonexistent.key'));
    }

    public function test_grace_period_expires_at_returns_expiration_time(): void
    {
        $expiresAt = now()->addHour();
        Secret::create([
            'key' => 'api.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => $expiresAt,
        ]);

        $result = Locksmith::gracePeriodExpiresAt('api.key');

        $this->assertNotNull($result);
        $this->assertEquals($expiresAt->format('Y-m-d H:i'), $result->format('Y-m-d H:i'));
    }

    public function test_grace_period_expires_at_returns_null_when_no_grace_period(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'value']);

        $this->assertNull(Locksmith::gracePeriodExpiresAt('api.key'));
    }

    public function test_get_last_log_returns_most_recent_log(): void
    {
        $secret = Secret::create(['key' => 'api.key', 'value' => 'value']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new1'));
        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new2'));

        $log = Locksmith::getLastLog('api.key');

        $this->assertNotNull($log);
        $this->assertEquals($secret->id, $log->secret_id);
    }

    public function test_get_last_log_returns_null_for_missing_secret(): void
    {
        $this->assertNull(Locksmith::getLastLog('nonexistent.key'));
    }

    public function test_get_logs_returns_all_rotation_logs(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'value']);

        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new1'));
        Locksmith::rotate('api.key', TestRecipe::make(fn () => 'new2'));

        $logs = Locksmith::getLogs('api.key');

        $this->assertCount(2, $logs);
    }

    public function test_get_logs_returns_empty_collection_for_missing_secret(): void
    {
        $logs = Locksmith::getLogs('nonexistent.key');

        $this->assertCount(0, $logs);
    }

    public function test_get_previous_value_returns_previous_value_during_grace_period(): void
    {
        Secret::create([
            'key' => 'api.key',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $this->assertEquals('old_value', Locksmith::getPreviousValue('api.key'));
    }

    public function test_get_previous_value_returns_null_when_no_grace_period(): void
    {
        Secret::create(['key' => 'api.key', 'value' => 'value']);

        $this->assertNull(Locksmith::getPreviousValue('api.key'));
    }

    public function test_get_previous_value_returns_null_for_missing_secret(): void
    {
        $this->assertNull(Locksmith::getPreviousValue('nonexistent.key'));
    }
}
