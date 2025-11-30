<?php

namespace BrainletAli\Locksmith\Contracts;

/** Contract for recipes that can discard old secrets. */
interface DiscardableRecipe
{
    /** Discard an old secret value from the provider (e.g., delete old AWS key). */
    public function discard(string $value): void;
}
