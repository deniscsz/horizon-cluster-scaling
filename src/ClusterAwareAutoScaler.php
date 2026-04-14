<?php

declare(strict_types=1);

namespace Deniscsz\HorizonClusterScaling;

use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Supervisor;

/**
 * Decorator around Horizon's AutoScaler that makes maxProcesses cluster-aware.
 *
 * Before delegating to the real AutoScaler, this class temporarily adjusts
 * the supervisor's maxProcesses by dividing it by the number of active master
 * supervisors. After scaling completes, the original values are restored so
 * that Redis persistence and the Horizon dashboard see the configured values.
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

        $masterCount = $this->masterCountResolver->resolve();

        if ($masterCount <= 1) {
            $this->inner->scale($supervisor);

            return;
        }

        $originalMax = $supervisor->options->maxProcesses;
        $originalMin = $supervisor->options->minProcesses;

        $effectiveMax = (int) ceil($originalMax / $masterCount);
        $effectiveMin = max(1, (int) ceil($originalMin / $masterCount));

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
}
