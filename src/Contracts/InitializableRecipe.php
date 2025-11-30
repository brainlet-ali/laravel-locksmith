<?php

namespace BrainletAli\Locksmith\Contracts;

/** Contract for recipes that can prompt for initial credentials. */
interface InitializableRecipe
{
    /** Prompt user for initial credentials and return the value to store. */
    public function init(): ?string;
}
