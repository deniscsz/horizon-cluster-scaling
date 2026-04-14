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
     * Uses Horizon's own RedisMasterSupervisorRepository which filters
     * masters by a 14-second staleness window. Results are cached for
     * the configured TTL to avoid excessive Redis queries.
     *
     * @return int Always >= 1 (guards against division by zero)
     */
    public function resolve(): int
    {
        $ttl = config('horizon-cluster-scaling.cache_ttl', 5);

        $count = $this->cache->remember(
            'horizon-cluster-scaling:master-count',
            $ttl,
            fn () => count($this->masterRepository->names())
        );

        return max(1, (int) $count);
    }
}
