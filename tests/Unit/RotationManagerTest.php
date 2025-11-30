<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Contracts\SecretRotator;
use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Events\SecretRotated;
use BrainletAli\Locksmith\Events\SecretRotating;
use BrainletAli\Locksmith\Events\SecretRotationFailed;
use BrainletAli\Locksmith\Jobs\GracePeriodCleanupJob;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Services\LoggingService;
use BrainletAli\Locksmith\Services\RecipeResolver;
use BrainletAli\Locksmith\Services\RotationManager;
use BrainletAli\Locksmith\Tests\TestCase;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionClass;

class RotationManagerTest extends TestCase
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

    public function test_can_rotate_secret(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_old',
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rotate')->once();

        $manager = new RotationManager();
        $log = $manager->rotate($secret, $rotator, 'sk_test_new');

        $secret->refresh();
        $this->assertEquals('sk_test_new', $secret->value);
        $this->assertEquals('sk_test_old', $secret->previous_value);
        $this->assertInstanceOf(RotationLog::class, $log);
        $this->assertEquals(RotationStatus::Success, $log->status);
    }

    public function test_rotate_sets_grace_period(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_old',
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rotate')->once();

        $manager = new RotationManager();
        $manager->rotate($secret, $rotator, 'sk_test_new', gracePeriodMinutes: 120);

        $secret->refresh();
        $this->assertTrue($secret->hasActiveGracePeriod());
        $this->assertNotNull($secret->previous_value_expires_at);
    }

    public function test_rotate_fails_gracefully(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_old',
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rotate')->once()->andThrow(new Exception('API Error'));

        $manager = new RotationManager();
        $log = $manager->rotate($secret, $rotator, 'sk_test_new');

        $secret->refresh();
        $this->assertEquals('sk_test_old', $secret->value);
        $this->assertNull($secret->previous_value);
        $this->assertEquals(RotationStatus::Failed, $log->status);
        $this->assertEquals('API Error', $log->error_message);
    }

    public function test_can_rollback_secret(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rollback')->once();

        $manager = new RotationManager();
        $result = $manager->rollback($secret, $rotator);

        $secret->refresh();
        $this->assertTrue($result);
        $this->assertEquals('sk_test_old', $secret->value);
        $this->assertNull($secret->previous_value);
        $this->assertNull($secret->previous_value_expires_at);
    }

    public function test_rollback_fails_without_previous_value(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_current',
        ]);

        $rotator = Mockery::mock(SecretRotator::class);

        $manager = new RotationManager();
        $result = $manager->rollback($secret, $rotator);

        $this->assertFalse($result);
    }

    public function test_can_verify_rotation(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $manager = new RotationManager();
        $result = $manager->verify($secret, fn ($value) => $value === 'sk_test_new');

        $log->refresh();
        $this->assertTrue($result);
        $this->assertEquals(RotationStatus::Verified, $log->status);
    }

    public function test_verify_fails_and_triggers_rollback(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rollback')->once();

        $manager = new RotationManager();
        $result = $manager->verify(
            $secret,
            fn ($value) => false,
            $rotator
        );

        $secret->refresh();
        $this->assertFalse($result);
        $this->assertEquals('sk_test_old', $secret->value);
    }

    public function test_clear_expired_grace_periods(): void
    {
        Secret::create([
            'key' => 'api.expired',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        Secret::create([
            'key' => 'api.active',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $manager = new RotationManager();
        $cleared = $manager->clearExpiredGracePeriods();

        $this->assertEquals(1, $cleared);

        $expired = Secret::where('key', 'api.expired')->first();
        $this->assertNull($expired->previous_value);
        $this->assertNull($expired->previous_value_expires_at);

        $active = Secret::where('key', 'api.active')->first();
        $this->assertNotNull($active->previous_value);
    }

    public function test_rollback_fails_when_rotator_throws_exception(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rollback')->once()->andThrow(new Exception('Rollback API Error'));

        $manager = new RotationManager();
        $result = $manager->rollback($secret, $rotator);

        $log->refresh();
        $this->assertFalse($result);
        $this->assertEquals(RotationStatus::Failed, $log->status);
        $this->assertStringContainsString('Rollback failed:', $log->error_message);
    }

    public function test_rollback_without_log_marks_nothing(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rollback')->once();

        $manager = new RotationManager();
        $result = $manager->rollback($secret, $rotator);

        $this->assertTrue($result);
    }

    public function test_verify_without_log_does_not_fail(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
        ]);

        $manager = new RotationManager();
        $result = $manager->verify($secret, fn ($value) => $value === 'sk_test_new');

        $this->assertTrue($result);
    }

    public function test_rotate_dispatches_cleanup_job(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_old',
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rotate')->once();

        $manager = new RotationManager();
        $manager->rotate($secret, $rotator, 'sk_test_new', gracePeriodMinutes: 60);

        Queue::assertPushed(GracePeriodCleanupJob::class, function ($job) {
            return $job->secretKey === 'api.test.secret'
                && $job->valueToCleanup === 'sk_test_old'
                && $job->providerCleanup === true;
        });
    }

    public function test_rotate_using_recipe_creates_new_secret(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('new_secret_value');
        $recipe->shouldReceive('validate')->once()->with('new_secret_value')->andReturn(true);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logSuccess')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.secret', $recipe, gracePeriodMinutes: 60);

        $this->assertInstanceOf(RotationLog::class, $log);
        $this->assertEquals(RotationStatus::Success, $log->status);

        $secret = Secret::where('key', 'test.secret')->first();
        $this->assertNotNull($secret);
        $this->assertEquals('new_secret_value', $secret->value);

        Event::assertDispatched(SecretRotating::class);
        Event::assertDispatched(SecretRotated::class);
    }

    public function test_rotate_using_recipe_updates_existing_secret(): void
    {
        Event::fake();

        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'old_value',
        ]);

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('new_secret_value');
        $recipe->shouldReceive('validate')->once()->with('new_secret_value')->andReturn(true);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logSuccess')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.secret', $recipe);

        $secret->refresh();
        $this->assertEquals('new_secret_value', $secret->value);
        $this->assertEquals('old_value', $secret->previous_value);

        Event::assertDispatched(SecretRotating::class);
        Event::assertDispatched(SecretRotated::class);
    }

    public function test_rotate_using_recipe_fails_on_validation_failure(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('invalid_value');
        $recipe->shouldReceive('validate')->once()->with('invalid_value')->andReturn(false);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logFailure')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.secret', $recipe);

        $this->assertEquals(RotationStatus::Failed, $log->status);
        $this->assertEquals('Validation failed for generated value', $log->error_message);

        Event::assertDispatched(SecretRotationFailed::class);
    }

    public function test_rotate_using_recipe_fails_on_exception(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andThrow(new Exception('Generation failed'));

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logFailure')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.secret', $recipe);

        $this->assertEquals(RotationStatus::Failed, $log->status);
        $this->assertEquals('Generation failed', $log->error_message);

        Event::assertDispatched(SecretRotationFailed::class);
    }

    public function test_rotate_using_recipe_cleans_up_previous_value_with_discardable_recipe(): void
    {
        $secret = Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => 'old_value_to_discard',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->once()->with('old_value_to_discard');
        $recipe->shouldReceive('generate')->once()->andReturn('new_value');
        $recipe->shouldReceive('validate')->once()->with('new_value')->andReturn(true);

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->with('aws.credentials')->andReturn($recipe);
        app()->instance(RecipeResolver::class, $recipeResolver);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logSuccess')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $manager->rotateUsingRecipe('aws.credentials', $recipe);

        $secret->refresh();
        $this->assertEquals('new_value', $secret->value);
    }

    public function test_rotate_using_recipe_with_provider_cleanup_disabled(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->never();
        $recipe->shouldReceive('generate')->once()->andReturn('new_value');
        $recipe->shouldReceive('validate')->once()->with('new_value')->andReturn(true);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logSuccess')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $manager->rotateUsingRecipe('test.secret', $recipe, providerCleanup: false);

        $secret->refresh();
        $this->assertEquals('new_value', $secret->value);
        $this->assertEquals('current_value', $secret->previous_value);
    }

    public function test_rollback_value_restores_previous_value(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $manager = new RotationManager();
        $log = $manager->rollbackValue($secret);

        $secret->refresh();
        $this->assertInstanceOf(RotationLog::class, $log);
        $this->assertEquals(RotationStatus::RolledBack, $log->status);
        $this->assertEquals('old_value', $secret->value);
        $this->assertNull($secret->previous_value);
        $this->assertNull($secret->previous_value_expires_at);
    }

    public function test_rollback_value_returns_false_without_previous_value(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
        ]);

        $manager = new RotationManager();
        $result = $manager->rollbackValue($secret);

        $this->assertFalse($result);
    }

    public function test_verify_without_previous_value_and_no_rotator(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
        ]);

        $manager = new RotationManager();
        $result = $manager->verify($secret, fn ($value) => false);

        $this->assertFalse($result);
    }

    public function test_clear_expired_grace_period_for_specific_key(): void
    {
        Secret::create([
            'key' => 'test.expired',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        $manager = new RotationManager();
        $cleared = $manager->clearExpiredGracePeriod('test.expired');

        $this->assertEquals(1, $cleared);

        $secret = Secret::where('key', 'test.expired')->first();
        $this->assertNull($secret->previous_value);
    }

    public function test_clear_expired_grace_period_returns_zero_for_nonexistent_key(): void
    {
        $manager = new RotationManager();
        $cleared = $manager->clearExpiredGracePeriod('nonexistent.key');

        $this->assertEquals(0, $cleared);
    }

    public function test_clear_expired_grace_period_returns_zero_for_active_grace_period(): void
    {
        Secret::create([
            'key' => 'test.active',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $manager = new RotationManager();
        $cleared = $manager->clearExpiredGracePeriod('test.active');

        $this->assertEquals(0, $cleared);
    }

    public function test_clear_expired_grace_period_calls_discard_on_recipe(): void
    {
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'new_value',
            'previous_value' => 'old_value_to_discard',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->once()->with('old_value_to_discard');

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->with('aws.credentials')->andReturn($recipe);
        app()->instance(RecipeResolver::class, $recipeResolver);

        $manager = new RotationManager();
        $cleared = $manager->clearExpiredGracePeriod('aws.credentials');

        $this->assertEquals(1, $cleared);
    }

    public function test_clear_expired_grace_periods_handles_discard_exceptions_gracefully(): void
    {
        Secret::create([
            'key' => 'aws.credentials',
            'value' => 'new_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->subHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->once()->andThrow(new Exception('Provider API error'));

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->with('aws.credentials')->andReturn($recipe);
        app()->instance(RecipeResolver::class, $recipeResolver);

        $manager = new RotationManager();
        $cleared = $manager->clearExpiredGracePeriods();

        $this->assertEquals(1, $cleared);

        $secret = Secret::where('key', 'aws.credentials')->first();
        $this->assertNull($secret->previous_value);
    }

    public function test_update_secret_value_without_old_value(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => '',
        ]);

        $manager = new RotationManager();
        $manager->updateSecretValue($secret, 'new_value', gracePeriodMinutes: 60);

        $secret->refresh();
        $this->assertEquals('new_value', $secret->value);
        $this->assertNull($secret->previous_value);
        $this->assertNull($secret->previous_value_expires_at);

        Queue::assertNotPushed(GracePeriodCleanupJob::class);
    }

    public function test_update_secret_value_with_old_value(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'old_value',
        ]);

        $manager = new RotationManager();
        $manager->updateSecretValue($secret, 'new_value', gracePeriodMinutes: 120);

        $secret->refresh();
        $this->assertEquals('new_value', $secret->value);
        $this->assertEquals('old_value', $secret->previous_value);
        $this->assertNotNull($secret->previous_value_expires_at);

        Queue::assertPushed(GracePeriodCleanupJob::class);
    }

    public function test_update_secret_value_cleans_up_existing_previous_value(): void
    {
        $secret = Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => 'old_value_to_cleanup',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->once()->with('old_value_to_cleanup');

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->with('aws.credentials')->andReturn($recipe);
        app()->instance(RecipeResolver::class, $recipeResolver);

        $manager = new RotationManager();
        $manager->updateSecretValue($secret, 'new_value');

        $secret->refresh();
        $this->assertEquals('new_value', $secret->value);
        $this->assertEquals('current_value', $secret->previous_value);
    }

    public function test_update_secret_value_with_provider_cleanup_disabled(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->never();
        app()->instance(RecipeResolver::class, $recipeResolver);

        $manager = new RotationManager();
        $manager->updateSecretValue($secret, 'new_value', providerCleanup: false);

        $secret->refresh();
        $this->assertEquals('new_value', $secret->value);
        $this->assertEquals('current_value', $secret->previous_value);
    }

    public function test_cleanup_from_provider_does_nothing_without_previous_value(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
        ]);

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->never();

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('cleanupFromProvider');
        $method->setAccessible(true);
        $method->invoke($manager, $secret, $recipeResolver);

        $this->assertTrue(true);
    }

    public function test_cleanup_from_provider_does_nothing_for_non_discardable_recipe(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class);

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->once()->with('test.secret')->andReturn($recipe);

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('cleanupFromProvider');
        $method->setAccessible(true);
        $method->invoke($manager, $secret, $recipeResolver);

        $this->assertTrue(true);
    }

    public function test_cleanup_from_provider_calls_discard_on_discardable_recipe(): void
    {
        $secret = Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => 'old_value_to_discard',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->once()->with('old_value_to_discard');

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->once()->with('aws.credentials')->andReturn($recipe);

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('cleanupFromProvider');
        $method->setAccessible(true);
        $method->invoke($manager, $secret, $recipeResolver);

        $this->assertTrue(true);
    }

    public function test_cleanup_from_provider_handles_exceptions_gracefully(): void
    {
        $secret = Secret::create([
            'key' => 'aws.credentials',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipe = Mockery::mock(Recipe::class, DiscardableRecipe::class);
        $recipe->shouldReceive('discard')->once()->andThrow(new Exception('API error'));

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->once()->with('aws.credentials')->andReturn($recipe);

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('cleanupFromProvider');
        $method->setAccessible(true);
        $method->invoke($manager, $secret, $recipeResolver);

        $this->assertTrue(true);
    }

    public function test_cleanup_from_provider_when_recipe_is_null(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'current_value',
            'previous_value' => 'old_value',
            'previous_value_expires_at' => now()->addHour(),
        ]);

        $recipeResolver = Mockery::mock(RecipeResolver::class);
        $recipeResolver->shouldReceive('resolveForKey')->once()->with('test.secret')->andReturn(null);

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('cleanupFromProvider');
        $method->setAccessible(true);
        $method->invoke($manager, $secret, $recipeResolver);

        $this->assertTrue(true);
    }

    public function test_create_log_includes_all_metadata(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'test_value',
        ]);

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('createLog');
        $method->setAccessible(true);

        $startTime = microtime(true);
        $correlationId = 'test-correlation-id';

        $log = $method->invoke(
            $manager,
            $secret,
            RotationStatus::Success,
            $correlationId,
            $startTime,
            'TestRecipe',
            null
        );

        $this->assertInstanceOf(RotationLog::class, $log);
        $this->assertEquals(RotationStatus::Success, $log->status);
        $this->assertEquals($correlationId, $log->metadata['correlation_id']);
        $this->assertArrayHasKey('duration_ms', $log->metadata);
        $this->assertArrayHasKey('source', $log->metadata);
        $this->assertArrayHasKey('triggered_by', $log->metadata);
        $this->assertEquals('TestRecipe', $log->metadata['recipe']);
    }

    public function test_create_log_with_error_message(): void
    {
        $secret = Secret::create([
            'key' => 'test.secret',
            'value' => 'test_value',
        ]);

        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('createLog');
        $method->setAccessible(true);

        $log = $method->invoke(
            $manager,
            $secret,
            RotationStatus::Failed,
            'correlation-id',
            microtime(true),
            'TestRecipe',
            'Test error message'
        );

        $this->assertEquals('Test error message', $log->error_message);
    }

    public function test_detect_source_returns_artisan_in_console_tests(): void
    {
        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('detectSource');
        $method->setAccessible(true);

        $result = $method->invoke($manager);

        $this->assertEquals('artisan', $result);
    }

    public function test_detect_triggered_by_returns_testing_in_tests(): void
    {
        $manager = new RotationManager();
        $reflection = new ReflectionClass($manager);
        $method = $reflection->getMethod('detectTriggeredBy');
        $method->setAccessible(true);

        $result = $method->invoke($manager);

        $this->assertEquals('testing', $result);
    }

    public function test_rollback_marks_log_as_rolled_back(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rollback')->once();

        $manager = new RotationManager();
        $result = $manager->rollback($secret, $rotator);

        $log->refresh();
        $this->assertTrue($result);
        $this->assertEquals(RotationStatus::RolledBack, $log->status);
    }

    public function test_rollback_when_rotator_throws_exception_without_log(): void
    {
        $secret = Secret::create([
            'key' => 'api.test.secret',
            'value' => 'sk_test_new',
            'previous_value' => 'sk_test_old',
            'previous_value_expires_at' => now()->addHours(2),
        ]);

        $rotator = Mockery::mock(SecretRotator::class);
        $rotator->shouldReceive('rollback')->once()->andThrow(new Exception('Rollback failed'));

        $manager = new RotationManager();
        $result = $manager->rollback($secret, $rotator);

        $this->assertFalse($result);
    }

    public function test_rotate_using_recipe_includes_source_in_metadata(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('new_value');
        $recipe->shouldReceive('validate')->once()->andReturn(true);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logSuccess')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.key', $recipe);

        $this->assertArrayHasKey('source', $log->metadata);
        $this->assertContains($log->metadata['source'], ['artisan', 'testing', 'api']);
    }

    public function test_rotate_using_recipe_includes_triggered_by_in_metadata(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('new_value');
        $recipe->shouldReceive('validate')->once()->andReturn(true);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logSuccess')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.key', $recipe);

        $this->assertArrayHasKey('triggered_by', $log->metadata);
        $this->assertContains($log->metadata['triggered_by'], ['testing', 'artisan', 'queue', 'api']);
    }

    public function test_rotate_using_recipe_fails_includes_source_and_triggered_by(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andThrow(new Exception('Failed'));

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logFailure')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.key', $recipe);

        $this->assertArrayHasKey('source', $log->metadata);
        $this->assertArrayHasKey('triggered_by', $log->metadata);
    }

    public function test_rotate_using_recipe_validation_failure_includes_metadata(): void
    {
        Event::fake();

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('value');
        $recipe->shouldReceive('validate')->once()->andReturn(false);

        $loggingService = Mockery::mock(LoggingService::class);
        $loggingService->shouldReceive('logFailure')->once();
        app()->instance(LoggingService::class, $loggingService);

        $manager = new RotationManager();
        $log = $manager->rotateUsingRecipe('test.key', $recipe);

        $this->assertArrayHasKey('source', $log->metadata);
        $this->assertArrayHasKey('triggered_by', $log->metadata);
        $this->assertContains($log->metadata['source'], ['artisan', 'testing', 'api']);
        $this->assertContains($log->metadata['triggered_by'], ['testing', 'artisan', 'queue', 'api']);
    }
}
