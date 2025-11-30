<?php

namespace BrainletAli\Locksmith\Enums;

/** Status states for secret rotation. */
enum RotationStatus: int
{
    case Pending = 0;
    case Success = 1;
    case Failed = 2;
    case Verified = 3;
    case RolledBack = 4;
    case DiscardSuccess = 5;
    case DiscardFailed = 6;

    /** Get human-readable label for the status. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Verified => 'Verified',
            self::RolledBack => 'Rolled Back',
            self::DiscardSuccess => 'Discard Success',
            self::DiscardFailed => 'Discard Failed',
        };
    }

    /** Check if status represents a successful rotation. */
    public function isSuccess(): bool
    {
        return $this === self::Success || $this === self::Verified;
    }
}
