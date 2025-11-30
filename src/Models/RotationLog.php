<?php

namespace BrainletAli\Locksmith\Models;

use BrainletAli\Locksmith\Enums\RotationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eloquent model for rotation audit logs. */
class RotationLog extends Model
{
    protected $table = 'locksmith_rotation_logs';

    protected $guarded = [];

    protected $casts = [
        'status' => RotationStatus::class,
        'rotated_at' => 'datetime',
        'verified_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** Get the secret this rotation log belongs to. */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(Secret::class);
    }

    /** Mark this rotation as verified after successful validation. */
    public function markAsVerified(): void
    {
        $this->update([
            'status' => RotationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    /** Mark this rotation as rolled back with optional reason. */
    public function markAsRolledBack(?string $reason = null): void
    {
        $this->update([
            'status' => RotationStatus::RolledBack,
            'rolled_back_at' => now(),
            'error_message' => $reason,
        ]);
    }

    /** Mark this rotation as failed with an error message. */
    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => RotationStatus::Failed,
            'error_message' => $reason,
        ]);
    }
}
