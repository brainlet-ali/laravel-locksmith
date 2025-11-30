<?php

namespace BrainletAli\Locksmith\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** Eloquent model for encrypted secrets. */
class Secret extends Model
{
    protected $table = 'locksmith_secrets';

    protected $guarded = [];

    protected $casts = [
        'previous_value_expires_at' => 'datetime',
    ];

    /** Get all rotation logs for this secret. */
    public function rotationLogs(): HasMany
    {
        return $this->hasMany(RotationLog::class);
    }

    /** Check if the previous value is still valid within the grace period. */
    public function hasActiveGracePeriod(): bool
    {
        return $this->previous_value !== null
            && $this->previous_value_expires_at !== null
            && $this->previous_value_expires_at->isFuture();
    }

    /** Get the current decrypted secret value. */
    public function getCurrentValue(): string
    {
        return $this->value;
    }

    /** Get all valid values including the previous value if within grace period. */
    public function getAllValidValues(): array
    {
        $values = [$this->value];

        if ($this->hasActiveGracePeriod()) {
            $values[] = $this->previous_value;
        }

        return $values;
    }

    /** Clear the previous value and grace period expiration. */
    public function clearGracePeriod(): void
    {
        $this->update([
            'previous_value' => null,
            'previous_value_expires_at' => null,
        ]);
    }

    /** Encrypt and decrypt the secret value. */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (string $value) => Crypt::encryptString($value),
        );
    }

    /** Encrypt and decrypt the previous secret value. */
    protected function previousValue(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Crypt::decryptString($value) : null,
            set: fn (?string $value) => $value ? Crypt::encryptString($value) : null,
        );
    }
}
