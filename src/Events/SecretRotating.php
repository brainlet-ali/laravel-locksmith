<?php

namespace BrainletAli\Locksmith\Events;

use BrainletAli\Locksmith\Models\Secret;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Event fired before secret rotation. */
class SecretRotating
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Secret $secret
    ) {}
}
