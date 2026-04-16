# Horizon Cluster Scaling

[![run-tests](https://github.com/deniscsz/horizon-cluster-scaling/actions/workflows/run-tests.yml/badge.svg?branch=main&event=push)](https://github.com/deniscsz/horizon-cluster-scaling/actions/workflows/run-tests.yml)

Makes Laravel Horizon **cluster-aware** by dynamically adjusting `maxProcesses` and `minProcesses` based on the number of active master supervisors across your server cluster.

## The Problem

Laravel Horizon's `maxProcesses` and `minProcesses` settings are **per server**. When you run Horizon on multiple servers, each one independently uses the configured values — there is no coordination between them.

```
Config: maxProcesses = 10, minProcesses = 1

Server A: up to 10 workers, at least 1 idle
Server B: up to 10 workers, at least 1 idle
Server C: up to 10 workers, at least 1 idle
─────────────────────────────────────────────
Cluster total: up to 30 workers, at least 3 idle
← You probably wanted 10 max and 1 min total
```

This leads to over-provisioning that wastes resources and can overwhelm your database or external APIs.

## The Solution

This package detects how many Horizon master supervisors are running (using the same Redis data that powers the Horizon dashboard) and adjusts both `maxProcesses` and `minProcesses` per host.

### maxProcesses — Ceiling Division

`maxProcesses` is divided using **ceiling division** to ensure the cluster never underprovisions capacity:

```
Config: maxProcesses = 10, 3 servers running

Effective per server: ceil(10 / 3) = 4
Server A: up to 4 workers
Server B: up to 4 workers
Server C: up to 4 workers
─────────────────────────────────────────────
Cluster total: up to 12 workers  ← Close to 10, safe overshoot
```

| Configured `maxProcesses` | Masters | Effective per server | Cluster total |
|:---:|:---:|:---:|:---:|
| 10 | 1 | 10 | 10 |
| 10 | 2 | 5 | 10 |
| 10 | 3 | 4 | 12 |
| 10 | 4 | 3 | 12 |
| 10 | 5 | 2 | 10 |
| 5 | 3 | 2 | 6 |
| 8 | 3 | 3 | 9 |

> Ceiling division may produce a slight overshoot (e.g., 12 instead of 10). This is intentional — floor division would underprovision (e.g., 9 instead of 10), which risks leaving jobs unprocessed.

### minProcesses — Remainder-Aware Distribution

`minProcesses` uses **remainder-aware distribution** to achieve the **exact** configured total across the cluster. Each host deterministically computes its rank from a sorted list of master names, then:

```
base = floor(minProcesses / masterCount)
remainder = minProcesses % masterCount
Hosts with rank < remainder get: base + 1
Hosts with rank >= remainder get: base
```

```
Config: minProcesses = 1, 3 servers running

Sorted masters: [server-a, server-b, server-c]
base = floor(1 / 3) = 0, remainder = 1 % 3 = 1

Server A (rank 0): 1 idle worker  ← gets the remainder
Server B (rank 1): 0 idle workers
Server C (rank 2): 0 idle workers
─────────────────────────────────────────────
Cluster total: exactly 1 idle worker  ← Exact match
```

| Configured `minProcesses` | Masters | Distribution per server | Cluster total |
|:---:|:---:|:---:|:---:|
| 1 | 1 | 1 | 1 |
| 1 | 2 | 1, 0 | 1 |
| 1 | 3 | 1, 0, 0 | 1 |
| 2 | 3 | 1, 1, 0 | 2 |
| 3 | 3 | 1, 1, 1 | 3 |
| 4 | 3 | 2, 1, 1 | 4 |
| 5 | 3 | 2, 2, 1 | 5 |
| 6 | 3 | 2, 2, 2 | 6 |

> Unlike ceiling division, remainder-aware distribution produces **zero overshoot** — the cluster total always equals the configured value exactly.

## How It Works Under the Hood

### Architecture: Decorator Pattern

The package uses the **Decorator pattern** around Horizon's `AutoScaler` class — zero vendor file modifications.

```
┌─────────────────────────────────────────────────────────┐
│                   Supervisor::autoScale()                │
│                           │                              │
│              app(AutoScaler::class)->scale($this)        │
│                           │                              │
│              ┌────────────▼─────────────┐                │
│              │ ClusterAwareAutoScaler   │  ◄── Our code  │
│              │                          │                │
│              │  1. Query master names   │                │
│              │  2. Adjust maxProcesses  │                │
│              │  3. Distribute minProc.  │                │
│              │  4. Delegate to inner    │                │
│              │  5. Restore originals    │                │
│              └────────────┬─────────────┘                │
│                           │                              │
│              ┌────────────▼─────────────┐                │
│              │   Original AutoScaler    │  ◄── Horizon   │
│              │                          │                │
│              │  Calculate workers       │                │
│              │  Scale process pools     │                │
│              └──────────────────────────┘                │
└─────────────────────────────────────────────────────────┘
```

### Scaling Flow (detailed)

1. Every `balanceCooldown` seconds, each `Supervisor` calls `autoScale()`
2. `autoScale()` resolves `AutoScaler::class` from the Laravel container
3. Thanks to our `ServiceProvider::extend()`, it gets `ClusterAwareAutoScaler` instead
4. `ClusterAwareAutoScaler::scale()`:
   - Queries `MasterSupervisorRepository::names()` for active master names (cached, sorted)
   - If ≤ 1 master, delegates directly to the real `AutoScaler` (zero overhead)
   - **maxProcesses:** computes `effectiveMax = ceil(configuredMax / masterCount)`
   - **minProcesses:** extracts current master name from supervisor, computes rank in sorted master list, applies floor + remainder distribution for exact cluster total
   - Temporarily overrides `$supervisor->options->maxProcesses` and `minProcesses`
   - Delegates to the real `AutoScaler::scale()`
   - Restores original values in a `finally` block
5. The real `AutoScaler` performs its normal scaling logic with the adjusted limits
6. `Supervisor::persist()` writes the original (configured) values to Redis — the dashboard sees your intended config, not the adjusted values

### How master detection works

Horizon stores master supervisor data in Redis:

```
Redis sorted set "masters":
  - "server-a-abc7" (score: 1713100000)
  - "server-b-def4" (score: 1713100001)
  - "server-c-ghi9" (score: 1713100002)

Redis hash "master:server-a-abc7":
  name: "server-a-abc7"
  status: "running"
  supervisors: ["server-a-abc7:supervisor-default", ...]
  TTL: 15 seconds (refreshed every ~1s loop)
```

`MasterSupervisorRepository::names()` returns only masters scored within a **14-second window** — stale masters are automatically excluded. When a server goes down, its Redis key expires in 15 seconds.

### Rank assignment for minProcesses

Each host determines its rank by sorting the list of active master names lexicographically. Since all hosts query the same Redis data (within the cache TTL window), they all produce the same sorted list and the same rank assignments.

```
Active masters from Redis: ["server-c-ghi9", "server-a-abc7", "server-b-def4"]
Sorted:                    ["server-a-abc7", "server-b-def4", "server-c-ghi9"]
                            ↑ rank 0         ↑ rank 1         ↑ rank 2
```

The rank is stable as long as the set of active masters doesn't change. When topology changes occur (server up/down), ranks may shift — this is handled gracefully within one cache TTL cycle.

### Caching strategy

To avoid querying Redis on every auto-scale cycle (which runs every `balanceCooldown` seconds, typically 1-3s), the master names list is cached for `cache_ttl` seconds (default: 5). This means:

- **Topology changes** (server up/down) take at most `cache_ttl` seconds to reflect
- A slight overshoot during the transition is acceptable (jobs keep processing)
- The cache key is `horizon-cluster-scaling:master-names`
- Both `maxProcesses` and `minProcesses` computations share the same cached data (single cache source)

## Requirements

- PHP 8.1+
- Laravel 11.x, 12.x or 13.x
- Laravel Horizon 5.x

## Installation

```bash
composer require deniscsz/horizon-cluster-scaling
```

The package auto-discovers its service provider. No additional setup required.

### Publish configuration (optional)

```bash
php artisan vendor:publish --tag=horizon-cluster-scaling-config
```

## Configuration

| Variable | Default | Description |
|---|---|---|
| `HORIZON_CLUSTER_SCALING_ENABLED` | `true` | Enable/disable cluster-aware scaling |
| `HORIZON_CLUSTER_SCALING_CACHE_TTL` | `5` | Seconds to cache master names list |
| `HORIZON_CLUSTER_SCALING_MIN_EFFECTIVE_MAX` | `null` | Absolute floor for effective maxProcesses |

### `enabled`

Set to `false` to completely disable the package. The original `AutoScaler` will be called directly with zero overhead.

### `cache_ttl`

How long (in seconds) to cache the active master names list. Lower values = faster reaction to topology changes but more Redis queries. The default of 5 seconds is a good balance.

### `min_effective_max`

An absolute minimum for the computed effective `maxProcesses`. When set to `null` (default), each supervisor's own `minProcesses` serves as the floor.

Example: if you never want any supervisor to run fewer than 3 workers per server, set this to `3`.

## Edge Cases

### Server goes offline

When a Horizon master stops (crash, deploy, scale-down), its Redis key expires within 15 seconds. On the next cache refresh (≤ `cache_ttl` seconds), remaining servers detect fewer masters and automatically increase their effective `maxProcesses`. The `minProcesses` remainder is also redistributed among the remaining hosts.

### New server starts

A brief window occurs until the cache refreshes on existing servers. During this window, the new server may not be in other hosts' cached master lists. The new server itself falls back to ceiling division for `minProcesses` if it doesn't find itself in the cached list. This transient state self-corrects within one `cache_ttl` cycle.

### Hosts with minProcesses = 0

When a host receives `effectiveMin = 0` through remainder distribution, it means that host has no guaranteed idle workers for that supervisor. With `balance: auto`, the host will still scale up workers when jobs arrive in the queue — `maxProcesses` remains > 0. The `minProcesses = 0` simply means the host can scale down to zero workers when the queue is empty, avoiding unnecessary resource usage.

### minProcesses floor

The effective `maxProcesses` is always clamped to at least the effective `minProcesses`. The `minProcesses` remainder distribution ensures the exact cluster total matches the configured value. The effective `minProcesses` can be `0` for hosts that don't receive the remainder.

### Manual scaling

Horizon's manual scale command (`Supervisor::scale()`) writes directly to `$options->maxProcesses` and is not intercepted by the decorator. Manual scaling overrides work as expected.

### Single server (no-op)

When only 1 master is detected (or none), the package short-circuits to the original `AutoScaler` with zero overhead. No rank computation or division is performed.

### Race condition: master not in cached list

If a newly started master is not yet in the cached names list (because the cache was written before it registered in Redis), it falls back to ceiling division for `minProcesses`. This is the safe default — it errs on the side of slight over-provisioning rather than under-provisioning, and self-corrects on the next cache refresh.

## Package Structure

```
├── config/
│   └── horizon-cluster-scaling.php    # Package configuration
├── src/
│   ├── ClusterAwareAutoScaler.php     # Decorator around Horizon's AutoScaler
│   ├── MasterCountResolver.php        # Redis query + cache for master names
│   └── HorizonClusterScalingServiceProvider.php
└── tests/
    └── Unit/
        ├── ClusterAwareAutoScalerTest.php
        └── MasterCountResolverTest.php
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md) for details.
