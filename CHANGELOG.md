# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed — third release review

- `WS008`: a size guard is now verified rather than detected. The condition has
  to measure *this* collection with `count()`/`sizeof()`, compare it against a
  finite limit, and put the eviction in the branch taken when the limit is
  exceeded. `count([]) > 10` and `count(self::$x) < 0` no longer silence the
  rule, and an `else` branch no longer inherits its own `if`'s guard.
- `WS008`: removals are only counted as undoing an addition when they target
  the *same* array key, so `unset(self::$items['never'])` after an append no
  longer passes as a bound. Write sites are never treated as element counts:
  `array_push($x, $a, $b)` paired with one `array_pop()` is reported.
- The right operand of `&&`, `||`, `and`, `or` and `??` counts as conditional,
  so `$evict && array_pop(...)` no longer reads as guaranteed to run.
- `WS005`/`WS006`: alias-based suppression removed. `forgetScopedInstances()`
  unsets `$instances[$key]` with no alias resolution and `bind()` deletes
  `$aliases[$abstract]` for the key it registers, so scoping an alias never
  flushes the binding it aliased. Only an exact key match suppresses now, and
  the round-two test that asserted the opposite has been corrected.

### Fixed — second release review

- `WS008`: a fixed outer key no longer bounds a nested collection.
  `self::$items['bucket'][] = $x` grows without bound, and the whole dimension
  chain is now checked — a key counts as fixed only when *every* dimension is a
  compile-time constant. Applies to function-scoped statics too, where the case
  previously produced no finding at all.
- `WS008`: a bound must now be *proven*, not inferred from one removal being
  present. Three shapes silence the rule — an unconditional full reset, a set of
  additions and removals that are all guaranteed to run with at least as many
  removals as additions, and a single addition paired with a size-guarded
  eviction. Growth inside a loop, behind a condition, or after an early return
  proves nothing and keeps the warning, so the +2/−1 net-growth case is reported.
- `unset($static[$key])` is recorded as removing one entry, `unset($static)` as
  removing all of them; the two are no longer conflated.
- Finding identity has no length limit. It was capped at the first 20 lines, so
  two calls differing only past that line shared a fingerprint and a baseline
  accepted the changed one. The identity is now derived from the token stream:
  whitespace *between* tokens collapses and comments are dropped (re-indenting
  or annotating code keeps a baseline valid), while the text of string literals
  and heredoc bodies is preserved byte for byte.
- Laravel container keys are compared the way the container compares them —
  byte for byte, after following `alias()` chains. A scoped `'shared'` no longer
  suppresses a singleton `'Shared'`, and a scoped alias correctly suppresses the
  binding it aliases. This key identity is deliberately separate from PHP
  class-name identity, which stays case-insensitive.
- `WS008`'s description no longer claims the PHP-FPM process exits; the
  earlier lifecycle correction had not reached that string.

Because the fingerprint algorithm changed, baselines generated before this
change no longer match. Regenerate with `worker-safety baseline`.

### Fixed — release review

Scan integrity:

- An unreadable or vanished file is reported as an analysis failure instead of
  being treated as a successfully analyzed empty file.
- A scan that could not analyze a file now fails (exit `1`) rather than
  reporting a clean result, and SARIF no longer sets `executionSuccessful: true`
  for it. Opt out with `--allow-parse-errors` or `fail_on_parse_error: false`.
  A syntax error php-parser recovers from does not count.
- `baseline` refuses to record findings from an incomplete scan.
- `baseline: false` in the configuration is honoured; it previously fell back to
  loading the default baseline file.
- Finding identity is derived from the whole reported construct, so changing a
  multi-line argument no longer keeps a baselined fingerprint. Line movement
  still does not change it.

Rule accuracy:

- `WS005`/`WS006`: a `scoped()` binding under a *different* key no longer hides
  a real singleton — the container flushes scoped instances by abstract key.
- `WS005`/`WS006`: container factories resolve to the type they *return*, not to
  the first object they allocate; ambiguous factories are skipped.
- `WS005`/`WS006`: free-form service ids (`'auth.context'`) and already-built
  instances (`instance('ctx', new Ctx())`) are recognised as bindings.
- `WS005`/`WS006`: a fully qualified class name that is not declared in the
  analyzed paths no longer falls back to an unrelated local short-name match.
- `readonly class` marks every property, promoted or not, as immutable.
- `WS008`: `array_slice()` is no longer treated as a release path (it copies);
  a bound in one method no longer covers growth in another; release paths are
  matched by class *and* method; a literal array key bounds the collection.
- `WS008`: `unset($static[$key])` inside a function-scoped static stays a
  clearing write.
- `WS003`: aliased imports (`use function putenv as changeEnv`) are resolved;
  `setlocale($category, 0)` is recognised as a query, not a mutation.
- `WS009`: only static registries are reported; an instance property on an
  object created per request is not assumed to outlive the request.

Accuracy of what the tool claims:

- Remediation no longer suggests `octane.flush` for static state: it calls
  `forgetInstance()` on container bindings and never touches a static property.
- `WS002`/`WS003` no longer claim cross-request persistence for superglobals the
  runtimes rebuild. FrankenPHP documents `$_GET`, `$_POST`, `$_COOKIE`,
  `$_FILES`, `$_SERVER` and `$_REQUEST` as reset and `$_ENV` as the exception,
  so `$_SERVER` writes drop to `LOW`, request-superglobal writes drop to `LOW`,
  and `$_ENV` stays `HIGH`.
- The PHP-FPM lifecycle is described accurately: the engine ends the request
  context, the OS worker process is reused.

Repository:

- SARIF artifact and base URIs are percent-encoded, so a path containing a
  space or `#` is a valid URI.
- The committed lock resolves against the minimum supported PHP (8.2) via
  `config.platform`, so the CI jobs pinned to 8.2/8.3 can install from it.
- The consumer workflow templates moved to `examples/workflows/`; as active
  workflows in this repository they invoked a `vendor/bin/worker-safety` that
  does not exist here.
- PHP-CS-Fixer runs sequentially, so `composer lint` does not depend on being
  able to bind a local TCP socket.


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
