<?php

namespace BrainletAli\Locksmith\Enums;

/** Status states for pool keys. */
enum PoolKeyStatus: int
{
    case Queued = 0;
    case Active = 1;
    case Used = 2;
    case Expired = 3;

    /** Get human-readable label for the status. */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Active => 'Active',
            self::Used => 'Used',
            self::Expired => 'Expired',
        };
    }

    /** Check if key is available for use. */
    public function isAvailable(): bool
    {
        return $this === self::Queued;
    }
}
