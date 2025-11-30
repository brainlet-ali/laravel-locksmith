<?php

namespace BrainletAli\Locksmith\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Event fired when pool keys are running low. */
class PoolLow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $secretKey,
        public int $remaining,
        public int $threshold
    ) {}
}
