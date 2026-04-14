<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Deniscsz\HorizonClusterScaling\MasterCountResolver;

it('returns count of active masters', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn(['master-abc', 'master-def', 'master-ghi']);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('remember')
        ->once()
        ->withArgs(function ($key, $ttl, $callback) {
            return $key === 'horizon-cluster-scaling:master-count';
        })
        ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $resolver = new MasterCountResolver($masterRepo, $cache);

    expect($resolver->resolve())->toBe(3);
});

it('returns 1 when no masters found', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn([]);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('remember')
        ->once()
        ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $resolver = new MasterCountResolver($masterRepo, $cache);

    expect($resolver->resolve())->toBe(1);
});

it('caches the result', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn(['master-abc', 'master-def']);

    $cache = Mockery::mock(CacheRepository::class);
    $cachedValue = null;

    $cache->shouldReceive('remember')
        ->twice()
        ->andReturnUsing(function ($key, $ttl, $callback) use (&$cachedValue) {
            if ($cachedValue === null) {
                $cachedValue = $callback();
            }

            return $cachedValue;
        });

    $resolver = new MasterCountResolver($masterRepo, $cache);

    // Call twice — names() should only be called once
    expect($resolver->resolve())->toBe(2);
    expect($resolver->resolve())->toBe(2);
});
