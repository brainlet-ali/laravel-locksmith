<?php

namespace BrainletAli\Locksmith\Tests\Unit\Services;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;
use BrainletAli\Locksmith\Services\RecipeResolver;
use BrainletAli\Locksmith\Tests\TestCase;

class RecipeResolverTest extends TestCase
{
    public function test_resolves_built_in_aws_recipe(): void
    {
        $resolver = new RecipeResolver;

        $recipe = $resolver->resolve('aws');

        $this->assertInstanceOf(AwsRecipe::class, $recipe);
    }

    public function test_returns_null_for_unknown_recipe(): void
    {
        $resolver = new RecipeResolver;

        $recipe = $resolver->resolve('unknown');

        $this->assertNull($recipe);
    }

    public function test_has_returns_true_for_built_in_recipe(): void
    {
        $resolver = new RecipeResolver;

        $this->assertTrue($resolver->has('aws'));
    }

    public function test_has_returns_false_for_unknown_recipe(): void
    {
        $resolver = new RecipeResolver;

        $this->assertFalse($resolver->has('unknown'));
    }

    public function test_all_returns_built_in_recipe_names(): void
    {
        $resolver = new RecipeResolver;

        $all = $resolver->all();

        $this->assertContains('aws', $all);
    }

    public function test_resolve_for_key_detects_recipe_from_prefix(): void
    {
        $resolver = new RecipeResolver;

        $recipe = $resolver->resolveForKey('aws.credentials');

        $this->assertInstanceOf(AwsRecipe::class, $recipe);
    }

    public function test_resolve_for_key_returns_null_for_unknown_prefix(): void
    {
        $resolver = new RecipeResolver;

        $recipe = $resolver->resolveForKey('unknown.credentials');

        $this->assertNull($recipe);
    }

    public function test_resolve_for_key_returns_null_for_key_without_prefix(): void
    {
        $resolver = new RecipeResolver;

        $recipe = $resolver->resolveForKey('credentials');

        $this->assertNull($recipe);
    }

    public function test_resolves_custom_recipe_from_config(): void
    {
        config(['locksmith.recipes.custom' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $recipe = $resolver->resolve('custom');

        $this->assertInstanceOf(FakeCustomRecipe::class, $recipe);
    }

    public function test_resolve_for_key_works_with_custom_recipe(): void
    {
        config(['locksmith.recipes.twilio' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $recipe = $resolver->resolveForKey('twilio.credentials');

        $this->assertInstanceOf(FakeCustomRecipe::class, $recipe);
    }

    public function test_has_returns_true_for_custom_recipe(): void
    {
        config(['locksmith.recipes.twilio' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $this->assertTrue($resolver->has('twilio'));
    }

    public function test_all_includes_custom_recipes(): void
    {
        config(['locksmith.recipes.twilio' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $all = $resolver->all();

        $this->assertContains('aws', $all);
        $this->assertContains('twilio', $all);
    }

    public function test_custom_recipe_overrides_built_in(): void
    {
        config(['locksmith.recipes.aws' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $recipe = $resolver->resolve('aws');

        // Custom should override built-in
        $this->assertInstanceOf(FakeCustomRecipe::class, $recipe);
    }

    public function test_get_provider_cleanup_defaults_to_true(): void
    {
        $resolver = new RecipeResolver;

        $this->assertTrue($resolver->getProviderCleanup('aws'));
    }

    public function test_get_provider_cleanup_from_simple_config(): void
    {
        // Simple format defaults to true
        config(['locksmith.recipes.twilio' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $this->assertTrue($resolver->getProviderCleanup('twilio'));
    }

    public function test_get_provider_cleanup_from_full_config(): void
    {
        config(['locksmith.recipes.twilio' => [
            'class' => FakeCustomRecipe::class,
            'provider_cleanup' => false,
        ]]);

        $resolver = new RecipeResolver;

        $this->assertFalse($resolver->getProviderCleanup('twilio'));
    }

    public function test_get_config_returns_normalized_config(): void
    {
        config(['locksmith.recipes.twilio' => FakeCustomRecipe::class]);

        $resolver = new RecipeResolver;

        $config = $resolver->getConfig('twilio');

        $this->assertEquals(FakeCustomRecipe::class, $config['class']);
        $this->assertTrue($config['provider_cleanup']);
    }

    public function test_get_config_returns_full_config(): void
    {
        config(['locksmith.recipes.twilio' => [
            'class' => FakeCustomRecipe::class,
            'provider_cleanup' => false,
        ]]);

        $resolver = new RecipeResolver;

        $config = $resolver->getConfig('twilio');

        $this->assertEquals(FakeCustomRecipe::class, $config['class']);
        $this->assertFalse($config['provider_cleanup']);
    }

    public function test_get_config_returns_null_for_unknown(): void
    {
        $resolver = new RecipeResolver;

        $this->assertNull($resolver->getConfig('unknown'));
    }

    public function test_full_config_overrides_built_in(): void
    {
        config(['locksmith.recipes.aws' => [
            'class' => FakeCustomRecipe::class,
            'provider_cleanup' => false,
        ]]);

        $resolver = new RecipeResolver;

        $recipe = $resolver->resolve('aws');
        $this->assertInstanceOf(FakeCustomRecipe::class, $recipe);
        $this->assertFalse($resolver->getProviderCleanup('aws'));
    }
}

class FakeCustomRecipe implements Recipe
{
    public function generate(): string
    {
        return 'fake_value';
    }

    public function validate(string $value): bool
    {
        return true;
    }
}
