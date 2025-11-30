<?php

namespace BrainletAli\Locksmith\Tests\Support;

use BrainletAli\Locksmith\Contracts\Recipe;
use Closure;

class TestRecipe implements Recipe
{
    protected Closure $generateFn;

    protected Closure $validateFn;

    public function __construct(?Closure $generate = null, ?Closure $validate = null)
    {
        $this->generateFn = $generate ?? fn () => 'new_value';
        $this->validateFn = $validate ?? fn ($v) => true;
    }

    public static function make(?Closure $generate = null, ?Closure $validate = null): self
    {
        return new self($generate, $validate);
    }

    public function generate(): string
    {
        return ($this->generateFn)();
    }

    public function validate(string $value): bool
    {
        return ($this->validateFn)($value);
    }
}
