<?php

declare(strict_types=1);

use Deniscsz\HorizonClusterScaling\MasterCountResolver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

it('returns count of active masters', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn(['master-abc', 'master-def', 'master-ghi']);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('remember')
        ->once()
        ->withArgs(function ($key, $ttl, $callback) {
            return $key === 'horizon-cluster-scaling:master-names';
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

it('returns sorted master names', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn(['gamma-ef56', 'alpha-ab12', 'beta-cd34']);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('remember')
        ->once()
        ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $resolver = new MasterCountResolver($masterRepo, $cache);

    expect($resolver->resolveNames())->toBe(['alpha-ab12', 'beta-cd34', 'gamma-ef56']);
});

it('returns empty array when no masters found via resolveNames', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn([]);

    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('remember')
        ->once()
        ->andReturnUsing(fn ($key, $ttl, $callback) => $callback());

    $resolver = new MasterCountResolver($masterRepo, $cache);

    expect($resolver->resolveNames())->toBe([]);
});

it('caches names and derives count from same source', function () {
    $masterRepo = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepo->shouldReceive('names')
        ->once()
        ->andReturn(['master-b', 'master-a']);

    $cache = Mockery::mock(CacheRepository::class);
    $cachedValue = null;

    $cache->shouldReceive('remember')
        ->andReturnUsing(function ($key, $ttl, $callback) use (&$cachedValue) {
            if ($cachedValue === null) {
                $cachedValue = $callback();
            }

            return $cachedValue;
        });

    $resolver = new MasterCountResolver($masterRepo, $cache);

    // Both methods share the same cache — names() called only once
    expect($resolver->resolveNames())->toBe(['master-a', 'master-b']);
    expect($resolver->resolve())->toBe(2);
});
