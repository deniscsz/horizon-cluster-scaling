# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-04-16

### Added
- `MasterCountResolver::resolveNames()` method returns sorted list of active master names
- Rank-based `minProcesses` distribution in `ClusterAwareAutoScaler` for exact cluster-wide totals
- Fallback to ceiling division when current master is not in cached names list (race condition safety)

### Changed
- `minProcesses` now uses remainder-aware distribution instead of ceiling division, ensuring exact cluster-wide total
- `MasterCountResolver::resolve()` now derives count from cached names list (single cache source)
- Cache key changed from `horizon-cluster-scaling:master-count` to `horizon-cluster-scaling:master-names` (stores sorted names array)

### Fixed
- `minProcesses=1` with multiple masters no longer inflates to 1-per-host (was: 3 total with 3 masters; now: 1 total)

## [1.0.0] - 2026-04-14

### Added
- Initial release
- `ClusterAwareAutoScaler` decorator that adjusts `maxProcesses` by dividing by active master count
- `MasterCountResolver` with configurable cache TTL
- Configurable enable/disable, cache TTL, and minimum effective max processes
- Auto-discovery service provider
