<?php

declare(strict_types=1);

namespace Deniscsz\HorizonClusterScaling;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class MasterCountResolver
{
    public function __construct(
        private MasterSupervisorRepository $masterRepository,
        private CacheRepository $cache,
    ) {}

    /**
     * Get the number of active master supervisors in the cluster.
     *
     * Derives count from resolveNames() so there is a single cache source.
     *
     * @return int Always >= 1 (guards against division by zero)
     */
    public function resolve(): int
    {
        return max(1, count($this->resolveNames()));
    }

    /**
     * Get the sorted list of active master supervisor names in the cluster.
     *
     * Uses Horizon's own RedisMasterSupervisorRepository which filters
     * masters by a 14-second staleness window. Results are cached for
     * the configured TTL to avoid excessive Redis queries.
     *
     * The names are sorted to ensure deterministic ordering across all
     * hosts in the cluster (used for rank-based minProcesses distribution).
     *
     * @return array<int, string> Sorted master names
     */
    public function resolveNames(): array
    {
        $ttl = config('horizon-cluster-scaling.cache_ttl', 5);

        $names = $this->cache->remember(
            'horizon-cluster-scaling:master-names',
            $ttl,
            function () {
                $names = $this->masterRepository->names();
                sort($names);

                return $names;
            }
        );

        return is_array($names) ? $names : [];
    }
}
