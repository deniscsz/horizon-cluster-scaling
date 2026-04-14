# Horizon Cluster Scaling

[![run-tests](https://github.com/deniscsz/horizon-cluster-scaling/actions/workflows/run-tests.yml/badge.svg?branch=main&event=push)](https://github.com/deniscsz/horizon-cluster-scaling/actions/workflows/run-tests.yml)

Makes Laravel Horizon **cluster-aware** by dynamically adjusting `maxProcesses` based on the number of active master supervisors across your server cluster.

## The Problem

Laravel Horizon's `maxProcesses` setting is **per server**. When you run Horizon on multiple servers, each one independently scales up to the configured maximum — there is no coordination between them.

```
Config: maxProcesses = 10

Server A: up to 10 workers
Server B: up to 10 workers
Server C: up to 10 workers
─────────────────────────────
Cluster total: up to 30 workers  ← You probably wanted 10 total
```

This leads to over-provisioning that wastes resources and can overwhelm your database or external APIs.

## The Solution

This package detects how many Horizon master supervisors are running (using the same Redis data that powers the Horizon dashboard) and divides `maxProcesses` by that count using **ceiling division**.

```
Config: maxProcesses = 10, 3 servers running

Effective per server: ceil(10 / 3) = 4
Server A: up to 4 workers
Server B: up to 4 workers
Server C: up to 4 workers
─────────────────────────────
Cluster total: up to 12 workers  ← Close to 10, safe overshoot
```

### Scaling Examples

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
│              │  1. Query master count   │                │
│              │  2. Adjust maxProcesses  │                │
│              │  3. Delegate to inner    │                │
│              │  4. Restore originals    │                │
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
   - Queries `MasterSupervisorRepository::names()` for active master count (cached)
   - Computes `effectiveMax = ceil(configuredMax / masterCount)`
   - Computes `effectiveMin = max(1, ceil(configuredMin / masterCount))`
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

### Caching strategy

To avoid querying Redis on every auto-scale cycle (which runs every `balanceCooldown` seconds, typically 1-3s), the master count is cached for `cache_ttl` seconds (default: 5). This means:

- **Topology changes** (server up/down) take at most `cache_ttl` seconds to reflect
- A slight overshoot during the transition is acceptable (jobs keep processing)
- The cache key is `horizon-cluster-scaling:master-count`

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
| `HORIZON_CLUSTER_SCALING_CACHE_TTL` | `5` | Seconds to cache master count |
| `HORIZON_CLUSTER_SCALING_MIN_EFFECTIVE_MAX` | `null` | Absolute floor for effective maxProcesses |

### `enabled`

Set to `false` to completely disable the package. The original `AutoScaler` will be called directly with zero overhead.

### `cache_ttl`

How long (in seconds) to cache the active master count. Lower values = faster reaction to topology changes but more Redis queries. The default of 5 seconds is a good balance.

### `min_effective_max`

An absolute minimum for the computed effective `maxProcesses`. When set to `null` (default), each supervisor's own `minProcesses` serves as the floor.

Example: if you never want any supervisor to run fewer than 3 workers per server, set this to `3`.

## Edge Cases

### Server goes offline

When a Horizon master stops (crash, deploy, scale-down), its Redis key expires within 15 seconds. On the next cache refresh (≤ `cache_ttl` seconds), remaining servers detect fewer masters and automatically increase their effective `maxProcesses`.

### New server starts

A brief window of slight overshoot occurs until the cache refreshes on existing servers. The new server also starts with the configured `maxProcesses` until its first auto-scale cycle queries the master count. This transient overshoot is harmless.

### minProcesses floor

The effective `maxProcesses` is always clamped to at least the effective `minProcesses`. Both `maxProcesses` and `minProcesses` are divided proportionally. The effective `minProcesses` never goes below 1.

### Manual scaling

Horizon's manual scale command (`Supervisor::scale()`) writes directly to `$options->maxProcesses` and is not intercepted by the decorator. Manual scaling overrides work as expected.

### Single server (no-op)

When only 1 master is detected, the package short-circuits to the original `AutoScaler` with zero overhead.

## Package Structure

```
├── config/
│   └── horizon-cluster-scaling.php    # Package configuration
├── src/
│   ├── ClusterAwareAutoScaler.php     # Decorator around Horizon's AutoScaler
│   ├── MasterCountResolver.php        # Redis query + cache for master count
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
