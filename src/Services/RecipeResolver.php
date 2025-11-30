<?php

namespace BrainletAli\Locksmith\Services;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;
use Illuminate\Support\Str;

/** Service for resolving recipe instances by name. */
class RecipeResolver
{
    /** @var array<string, array{class: class-string<Recipe>, provider_cleanup: bool}> */
    protected array $builtIn = [
        'aws' => [
            'class' => AwsRecipe::class,
            'provider_cleanup' => true,
        ],
    ];

    /** Resolve a recipe instance by name. */
    public function resolve(string $name): ?Recipe
    {
        $config = $this->getConfig($name);

        if (! $config) {
            return null;
        }

        return app($config['class']);
    }

    /** Get recipe config (class and options) by name. */
    public function getConfig(string $name): ?array
    {
        $recipes = $this->recipes();

        if (! isset($recipes[$name])) {
            return null;
        }

        return $this->normalizeConfig($recipes[$name]);
    }

    /** Get provider_cleanup setting for a recipe. */
    public function getProviderCleanup(string $name): bool
    {
        $config = $this->getConfig($name);

        return $config['provider_cleanup'] ?? true;
    }

    /** Resolve a recipe for a secret key based on key prefix. */
    public function resolveForKey(string $key): ?Recipe
    {
        $prefix = Str::before($key, '.');

        if ($prefix === $key) {
            return null;
        }

        return $this->resolve($prefix);
    }

    /** Check if a recipe exists. */
    public function has(string $name): bool
    {
        return isset($this->recipes()[$name]);
    }

    /** Get all recipe names. */
    public function all(): array
    {
        return array_keys($this->recipes());
    }

    /** Normalize config to full format. */
    protected function normalizeConfig(array|string $config): array
    {
        // Simple format: 'twilio' => TwilioRecipe::class
        if (is_string($config)) {
            return [
                'class' => $config,
                'provider_cleanup' => true,
            ];
        }

        // Full format: 'twilio' => ['class' => ..., 'provider_cleanup' => ...]
        return [
            'class' => $config['class'],
            'provider_cleanup' => $config['provider_cleanup'] ?? true,
        ];
    }

    /** Get all recipes (built-in + custom from config). */
    protected function recipes(): array
    {
        $custom = config('locksmith.recipes', []);

        // Normalize built-in recipes
        $builtIn = [];
        foreach ($this->builtIn as $name => $config) {
            $builtIn[$name] = $config;
        }

        // Custom recipes override built-in
        return array_merge($builtIn, $custom);
    }
}
