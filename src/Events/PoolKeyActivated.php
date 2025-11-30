<?php

namespace BrainletAli\Locksmith\Events;

use BrainletAli\Locksmith\Models\PoolKey;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Event fired when a pool key is activated. */
class PoolKeyActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $secretKey,
        public PoolKey $poolKey
    ) {}
}
