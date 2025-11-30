<?php

namespace BrainletAli\Locksmith\Models;

use BrainletAli\Locksmith\Enums\PoolKeyStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Eloquent model for key pool entries. */
class PoolKey extends Model
{
    protected $table = 'locksmith_key_pools';

    protected $guarded = [];

    protected $casts = [
        'status' => PoolKeyStatus::class,
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Check if key is queued and available for activation. */
    public function isQueued(): bool
    {
        return $this->status === PoolKeyStatus::Queued;
    }

    /** Check if key is currently active. */
    public function isActive(): bool
    {
        return $this->status === PoolKeyStatus::Active;
    }

    /** Check if key has expired. */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /** Mark this key as active. */
    public function activate(): void
    {
        $this->update([
            'status' => PoolKeyStatus::Active,
            'activated_at' => now(),
        ]);
    }

    /** Mark this key as used (rotated out). */
    public function markAsUsed(): void
    {
        $this->update([
            'status' => PoolKeyStatus::Used,
        ]);
    }

    /** Scope to get queued keys for a secret. */
    public function scopeQueued($query)
    {
        return $query->where('status', PoolKeyStatus::Queued);
    }

    /** Scope to get the active key for a secret. */
    public function scopeActive($query)
    {
        return $query->where('status', PoolKeyStatus::Active);
    }

    /** Scope to order by position. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /** Encrypt and decrypt the key value. */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (string $value) => Crypt::encryptString($value),
        );
    }
}
