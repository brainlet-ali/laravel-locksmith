<?php

namespace BrainletAli\Locksmith\Contracts;

/** Contract for secret rotation recipes. */
interface Recipe
{
    /** Generate a new secret value. */
    public function generate(): string;

    /** Validate the new secret value works. */
    public function validate(string $value): bool;
}
