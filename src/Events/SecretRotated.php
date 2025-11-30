<?php

namespace BrainletAli\Locksmith\Events;

use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Event fired after successful rotation. */
class SecretRotated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Secret $secret,
        public RotationLog $log
    ) {}
}
