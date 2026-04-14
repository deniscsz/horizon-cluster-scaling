# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-14

### Added
- Initial release
- `ClusterAwareAutoScaler` decorator that adjusts `maxProcesses` by dividing by active master count
- `MasterCountResolver` with configurable cache TTL
- Configurable enable/disable, cache TTL, and minimum effective max processes
- Auto-discovery service provider
