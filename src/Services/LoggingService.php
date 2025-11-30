<?php

namespace BrainletAli\Locksmith\Services;

use BrainletAli\Locksmith\Models\RotationLog;
use Illuminate\Support\Facades\Log;

/** Service for logging rotation events. */
class LoggingService
{
    /** Log a successful rotation to the system log. */
    public function logSuccess(string $key, RotationLog $log): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $context = $this->buildContext($key, $log);

        $this->log('info', "Secret [{$key}] rotated successfully", $context);
    }

    /** Log a failed rotation to the system log. */
    public function logFailure(string $key, RotationLog $log, string $error): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $context = $this->buildContext($key, $log);
        $context['error'] = $error;

        $this->log('error', "Secret [{$key}] rotation failed", $context);
    }

    /** Check if system logging is enabled. */
    protected function isEnabled(): bool
    {
        return config('locksmith.logging.enabled', false);
    }

    /** Get the configured log channel. */
    protected function getChannel(): ?string
    {
        return config('locksmith.logging.channel');
    }

    /** Build log context from rotation log. */
    protected function buildContext(string $key, RotationLog $log): array
    {
        return [
            'key' => $key,
            'status' => $log->status->label(),
            'correlation_id' => $log->metadata['correlation_id'] ?? null,
            'duration_ms' => $log->metadata['duration_ms'] ?? null,
            'source' => $log->metadata['source'] ?? null,
        ];
    }

    /** Write to log with optional channel. */
    protected function log(string $level, string $message, array $context): void
    {
        $channel = $this->getChannel();

        if ($channel) {
            Log::channel($channel)->{$level}($message, $context);
        } else {
            Log::{$level}($message, $context);
        }
    }
}
