<?php

namespace BrainletAli\Locksmith\Tests\Unit;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

class RecipeTest extends TestCase
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

    public function test_recipe_contract_has_required_methods(): void
    {
        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->andReturn('new_secret');
        $recipe->shouldReceive('validate')->with('new_secret')->andReturn(true);

        $this->assertEquals('new_secret', $recipe->generate());
        $this->assertTrue($recipe->validate('new_secret'));
    }

    public function test_locksmith_rotate_accepts_recipe(): void
    {
        Secret::create([
            'key' => 'api.key',
            'value' => 'old_value',
        ]);

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('new_value');
        $recipe->shouldReceive('validate')->once()->with('new_value')->andReturn(true);

        $log = Locksmith::rotate('api.key', $recipe);

        $secret = Secret::where('key', 'api.key')->first();
        $this->assertEquals('new_value', $secret->value);
        $this->assertNotNull($log);
    }

    public function test_locksmith_rotate_with_recipe_logs_failure_on_validation_failure(): void
    {
        Secret::create([
            'key' => 'api.key',
            'value' => 'old_value',
        ]);

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('invalid_value');
        $recipe->shouldReceive('validate')->once()->with('invalid_value')->andReturn(false);

        $log = Locksmith::rotate('api.key', $recipe);

        $secret = Secret::where('key', 'api.key')->first();
        $this->assertEquals('old_value', $secret->value);
        $this->assertNotNull($log);
        $this->assertEquals(RotationStatus::Failed, $log->status);
        $this->assertEquals('Validation failed for generated value', $log->error_message);
    }

    public function test_locksmith_rotate_with_recipe_and_grace_period(): void
    {
        Secret::create([
            'key' => 'api.key',
            'value' => 'old_value',
        ]);

        $recipe = Mockery::mock(Recipe::class);
        $recipe->shouldReceive('generate')->once()->andReturn('new_value');
        $recipe->shouldReceive('validate')->once()->andReturn(true);

        Locksmith::rotate('api.key', $recipe, gracePeriodMinutes: 120);

        $secret = Secret::where('key', 'api.key')->first();
        $this->assertTrue($secret->hasActiveGracePeriod());
        $this->assertEquals('old_value', $secret->previous_value);
    }
}
