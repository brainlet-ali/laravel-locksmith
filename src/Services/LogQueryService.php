<?php

namespace BrainletAli\Locksmith\Services;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Service for querying rotation logs. */
class LogQueryService
{
    /** Get all logs matching a specific status. */
    public function getByStatus(RotationStatus $status): Collection
    {
        return RotationLog::where('status', $status)->latest('rotated_at')->get();
    }

    /** Get failed logs within the specified hours. */
    public function getRecentFailures(int $hours = 24): Collection
    {
        return RotationLog::where('status', RotationStatus::Failed)
            ->where('rotated_at', '>=', now()->subHours($hours))
            ->latest('rotated_at')
            ->get();
    }

    /** Get logs between two dates. */
    public function getBetween(Carbon $start, Carbon $end): Collection
    {
        return RotationLog::whereBetween('rotated_at', [$start, $end])
            ->latest('rotated_at')
            ->get();
    }

    /** Get aggregated log statistics. */
    public function getStats(?string $key = null): array
    {
        $query = RotationLog::query();

        if ($key) {
            $secret = Secret::where('key', $key)->first();
            if (! $secret) {
                return ['total' => 0, 'by_status' => []];
            }
            $query->where('secret_id', $secret->id);
        }

        $logs = $query->get();

        $byStatus = $logs->groupBy(fn ($log) => $log->status->value)
            ->map(fn ($group) => $group->count())
            ->toArray();

        return [
            'total' => $logs->count(),
            'by_status' => $byStatus,
        ];
    }
}
