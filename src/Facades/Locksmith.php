<?php

namespace BrainletAli\Locksmith\Facades;

use BrainletAli\Locksmith\Locksmith as LocksmithService;
use Illuminate\Support\Facades\Facade;

/** Facade for the Locksmith service. */
class Locksmith extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LocksmithService::class;
    }
}
