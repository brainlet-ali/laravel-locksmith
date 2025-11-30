<?php

namespace BrainletAli\Locksmith\Contracts;

/** Contract for secret rotation operations. */
interface SecretRotator
{
    public function rotate(): void;

    public function rollback(): void;
}
