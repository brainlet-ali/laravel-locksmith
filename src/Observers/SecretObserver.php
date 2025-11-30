<?php

namespace BrainletAli\Locksmith\Observers;

use BrainletAli\Locksmith\Facades\Locksmith;
use BrainletAli\Locksmith\Models\Secret;

/** Observer to handle cache invalidation when secrets change. */
class SecretObserver
{
    /** Handle the Secret "saved" event (covers create and update). */
    public function saved(Secret $secret): void
    {
        Locksmith::forgetCache($secret->key);
    }

    /** Handle the Secret "deleted" event. */
    public function deleted(Secret $secret): void
    {
        Locksmith::forgetCache($secret->key);
    }
}
