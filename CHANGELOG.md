# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-09-10

First release.

### Added

- `worker-safety scan` — static analysis of PHP source for state that survives a
  request under a persistent worker (FrankenPHP, Laravel Octane, RoadRunner, Swoole).
- Ten built-in rules:
  - `WS001` mutable static property
  - `WS002` mutable global variable
  - `WS003` runtime environment mutation
  - `WS004` mutable singleton
  - `WS005` Laravel container singleton with mutable state
  - `WS006` Laravel scoped binding candidate
  - `WS007` static request/user context
  - `WS008` static collection growth
  - `WS009` persistent event/listener registration
  - `WS010` shutdown/runtime lifecycle assumption
- `worker-safety rules` — list the rule set, or show one rule in detail.
- `worker-safety init` — write a commented configuration file.
- `worker-safety baseline` — record current findings so only new ones fail the build.
- Console, JSON and SARIF 2.1.0 output formats.
- Configuration via `worker-safety.yaml`, with per-rule enable/severity overrides
  and path-scoped rule suppression.
- Inline suppression through `// worker-safety-ignore WS001` comments and the
  `#[WorkerSafetyIgnore]` attribute.
- Framework detection from Composer metadata, with a Laravel adapter that
  contributes container-binding analysis.
- Runtime targeting through `--runtime`, including runtime-specific guidance.
- Documented CI exit codes: `0` pass, `1` findings above threshold,
  `2` invalid configuration, `3` internal error.

[Unreleased]: https://github.com/goktugcy/worker-safety/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/goktugcy/worker-safety/releases/tag/v0.1.0
