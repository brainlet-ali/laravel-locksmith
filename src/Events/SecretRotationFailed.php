<?php

namespace BrainletAli\Locksmith\Events;

use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Event fired when rotation fails. */
class SecretRotationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Secret $secret,
        public string $reason,
        public ?RotationLog $log = null
    ) {}
}
