<?php

declare(strict_types=1);

namespace Deniscsz\HorizonClusterScaling;

use Illuminate\Support\Str;
use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Supervisor;

/**
 * Decorator around Horizon's AutoScaler that makes scaling cluster-aware.
 *
 * Before delegating to the real AutoScaler, this class temporarily adjusts
 * the supervisor's maxProcesses and minProcesses based on the number of active
 * master supervisors. After scaling completes, the original values are restored
 * so that Redis persistence and the Horizon dashboard see the configured values.
 *
 * maxProcesses uses ceiling division (slight overshoot is safe).
 * minProcesses uses remainder-aware distribution (exact cluster total).
 */
class ClusterAwareAutoScaler extends AutoScaler
{
    private AutoScaler $inner;

    private MasterCountResolver $masterCountResolver;

    public function __construct(AutoScaler $inner, MasterCountResolver $masterCountResolver)
    {
        // Intentionally NOT calling parent::__construct().
        // All real work is delegated to $inner which has its own dependencies.
        $this->inner = $inner;
        $this->masterCountResolver = $masterCountResolver;
    }

    public function scale(Supervisor $supervisor): void
    {
        if (! config('horizon-cluster-scaling.enabled', true)) {
            $this->inner->scale($supervisor);

            return;
        }

        $masterNames = $this->masterCountResolver->resolveNames();
        $masterCount = count($masterNames);

        if ($masterCount <= 1) {
            $this->inner->scale($supervisor);

            return;
        }

        $originalMax = $supervisor->options->maxProcesses;
        $originalMin = $supervisor->options->minProcesses;

        // maxProcesses: ceiling division (unchanged — slight overshoot is safe)
        $effectiveMax = (int) ceil($originalMax / $masterCount);

        // minProcesses: remainder-aware distribution (exact cluster total)
        $currentMaster = $this->extractMasterName($supervisor);
        $effectiveMin = $this->computeEffectiveMin($originalMin, $masterNames, $currentMaster);

        // Apply optional absolute floor from config
        $configFloor = config('horizon-cluster-scaling.min_effective_max');
        if ($configFloor !== null) {
            $effectiveMax = max($effectiveMax, (int) $configFloor);
        }

        // Effective max must never go below effective min
        $effectiveMax = max($effectiveMax, $effectiveMin);

        $supervisor->options->maxProcesses = $effectiveMax;
        $supervisor->options->minProcesses = $effectiveMin;

        try {
            $this->inner->scale($supervisor);
        } finally {
            // Restore originals so persist() writes the configured values to Redis
            $supervisor->options->maxProcesses = $originalMax;
            $supervisor->options->minProcesses = $originalMin;
        }
    }

    /**
     * Extract the master supervisor name from the supervisor's full name.
     *
     * Horizon sets Supervisor::$options->name as "{masterName}:{supervisorName}".
     * The master name portion uses Str::slug(gethostname()) + random suffix,
     * which never contains colons, making the colon a safe delimiter.
     */
    private function extractMasterName(Supervisor $supervisor): string
    {
        return Str::before($supervisor->options->name, ':');
    }

    /**
     * Compute the effective minProcesses for this host using remainder-aware distribution.
     *
     * Instead of ceiling division (which over-provisions), this distributes minProcesses
     * exactly across the cluster. Each host computes its rank in the sorted master list,
     * then the first R hosts (where R = originalMin % masterCount) get floor+1, the rest
     * get floor. This ensures the cluster total equals the configured minProcesses exactly.
     *
     * Example: minProcesses=4, 3 masters → base=1, remainder=1
     *   - Rank 0: 2 processes, Rank 1: 1 process, Rank 2: 1 process → total=4
     *
     * Falls back to ceiling division if the current master is not in the cached names
     * list (brief race condition during topology changes).
     */
    private function computeEffectiveMin(int $originalMin, array $masterNames, string $currentMaster): int
    {
        $masterCount = count($masterNames);

        $rank = array_search($currentMaster, $masterNames, true);

        // If current master is not in the cached list (race condition during
        // topology changes), fall back to ceiling division to avoid under-provisioning
        if ($rank === false) {
            return max(1, (int) ceil($originalMin / $masterCount));
        }

        $base = intdiv($originalMin, $masterCount);
        $remainder = $originalMin % $masterCount;

        return $rank < $remainder ? $base + 1 : $base;
    }
}
