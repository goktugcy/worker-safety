# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Allow `symfony/console` and `symfony/yaml` 8. `1.0.0` required `^6.4 || ^7.0`,
  so `composer require` failed outright in any project that had already resolved
  Symfony 8 — which includes every Laravel 13 application, because Laravel 13
  requires `symfony/console` ^7.4 or ^8.0. The constraint is now
  `^6.4 || ^7.0 || ^8.0`.

  Nothing changes for projects on Symfony 6.4 or 7: Symfony 8 requires PHP 8.4,
  so Composer will not select it on PHP 8.2 or 8.3, and the committed lock file
  still resolves to Symfony 7.

### Added

- A CI job that installs Symfony 8 on PHP 8.4 and runs the suite against it, so
  the compatibility claim is checked on every push rather than assumed. The
  job has to drop `config.platform.php` first — that pin targets the lowest
  supported PHP and would otherwise make the job silently re-test Symfony 7.
- Laravel 13 to the verified support matrix: package discovery, all documented
  options, JSON and SARIF output, the baseline round-trip and every exit code,
  checked on PHP 8.5 with `symfony/console` 8.

## [1.0.0] - 2026-09-11

First stable release, and the first release published at all: `0.1.0` below was
a development version that was never tagged or distributed.

Worker Safety statically analyzes PHP source for state that survives a request
when the application runs on a persistent worker — FrankenPHP worker mode,
Laravel Octane, RoadRunner or Swoole — and reports it before it reaches
production.

### What is in it

- **Ten rules** (`WS001`–`WS010`) covering static properties, globals,
  environment mutation, singletons, Laravel container bindings, request-scoped
  static context, unbounded static collections, listener registration on request
  paths, and code that assumes the process dies with the request.
- **Four commands**: `scan`, `rules`, `init` and `baseline`.
- **Three output formats**: a human-readable console report, a stable JSON
  schema (`version: "1"`) and SARIF 2.1.0 for GitHub code scanning.
- **Configuration** through `worker-safety.yaml`, with per-rule enable/severity
  overrides, path-scoped suppression, inline `// worker-safety-ignore` comments
  and a `#[WorkerSafetyIgnore]` attribute.
- **A baseline** so an existing codebase can adopt the tool without fixing
  everything first.
- **Framework awareness**: Laravel detection with container-binding analysis,
  and a Laravel integration that registers `php artisan worker-safety:scan`
  through package discovery.
- **Documented CI exit codes**: `0` pass, `1` findings at or above the
  threshold *or* a file that could not be analyzed, `2` invalid configuration,
  `3` internal error.

### Requirements

- PHP 8.2, 8.3 or 8.4, with `ext-json` and `ext-mbstring`.
- `nikic/php-parser` ^5.3, `symfony/console` and `symfony/yaml` ^6.4 or ^7.0.
- Laravel 11 or 12 for the optional Artisan command. Laravel is never a runtime
  dependency; without it the CLI behaves identically.

### Upgrading a baseline

**Any baseline generated before this release must be regenerated.** Finding
identity changed during pre-release review: it is now derived from the whole
reported construct, taken from the token stream with no length limit, instead of
a single truncated display line. Old fingerprints will not match, so a stale
baseline silently stops suppressing anything:

```bash
vendor/bin/worker-safety baseline
```

Identity is still independent of line numbers — moving code does not resurrect a
baselined finding — and is now also insensitive to re-indentation and to comments
inside the construct, while remaining sensitive to whitespace *inside* string
literals and heredocs, which is content rather than formatting.

### Known limitations

This is a risk analyzer, not a proof of safety. A clean scan means the known
cross-request patterns are absent from the analyzed paths. Out of scope:

- Dynamic property and variable access, reflection, and runtime-generated code.
- Third-party packages: `vendor/` is never scanned, so state retained inside a
  dependency is invisible.
- Complex data flow, and inherited or trait-provided members, which are analyzed
  where they are declared rather than once per using class.
- `WS008` proves a bound for exactly one shape — a statement that
  unconditionally resets the whole collection in the function that grows it.
  Every other release path, including the `if (count($x) > N) array_shift($x)`
  eviction idiom, is reported at `MEDIUM` with a message saying the bound could
  not be proven. Review it and record the decision with an inline ignore if the
  trade-off is deliberate.
- A namespaced user function that shadows a built-in cannot be told apart from
  the global one.

The README carries the full list.

### Added

- **Laravel integration.** Installing the package in a Laravel application now
  registers `php artisan worker-safety:scan` through package discovery.

  The Artisan command subclasses the existing `ScanCommand`, so the arguments,
  options, output formats, baseline handling and exit codes are literally the
  same implementation rather than a second copy that could drift. The only
  difference is the default project root: Artisan can be run from anywhere, so
  the scan defaults to the application's `base_path()` instead of the current
  working directory, with `--project-dir` still taking precedence and a relative
  value resolved against the application root.

  The command is registered only for console runs: outside the console the
  provider is still loaded and still adds its container binding, but no command
  is registered and nothing is scanned. Laravel remains a development-only
  suggestion rather than a dependency — in a project without it,
  `vendor/bin/worker-safety` is unchanged and nothing under
  `WorkerSafety\Integration\Laravel` is autoloaded.

  Verified against Laravel 11 and 12. Laravel 10 is not excluded by the
  package's constraints but has not been tested, so it is not claimed as
  supported. See the README for the version table and for manual provider
  registration when package discovery is disabled.


### Changed — eighth release review

`WS008` now draws the line for a "provable reset" at the statement boundary
instead of trying to enumerate the ways a sub-expression can be skipped.

A nullsafe chain that continues into a static call —
`$sink?->next()::consume(self::$items = [])` — put the receiver in the class
position rather than in `->var`, so the chain walker added in the previous
round stopped early and the reset counted as unconditional again. That was the
fourth shape of the same question in as many rounds, so the question itself is
gone:

- A reset is accepted as proof only when the assignment **is a statement**, not
  when it sits inside another expression. Whether a sub-expression is evaluated
  depends on its surroundings — a nullsafe link anywhere in the chain, a
  short-circuit operator, arguments of a call that never happens — and that set
  has no closed enumeration.
- The nullsafe chain walker is therefore deleted, along with its node-type
  special cases. Less code, and nothing left to extend shape by shape.

The cost is one deliberate over-report: a reset nested in another expression is
now reported even when it does run. The existing scope, loop, early-exit and
closure checks are unchanged and still required.

### Fixed — seventh release review

- `?->` short-circuits the whole chain, not just its own link. A reset written
  further along one — `$sink?->next()->consume(self::$items = [])`, and the
  property and array-dimension forms of the same shape — was still counted as
  unconditional, because the conditional context closed when the nullsafe node
  was left and the outer call is an ordinary `MethodCall`. The short-circuit is
  now modelled along the receiver chain, so anything reachable only past a
  nullsafe link is conditional. A chain with no nullsafe in it is unaffected and
  still counts as an unconditional reset.

### Fixed — sixth release review

Two more ways a `WS008` finding could vanish entirely:

- Sub-expressions that may never be evaluated are now treated as conditional:
  the arguments of a nullsafe call (`$sink?->consume(self::$items = [])` skips
  them when the receiver is null) and the right-hand side of `??=`. A reset
  written in either position was being taken as an unconditional full reset.
- `array_push(self::$items['bucket'], $v)` is growth again. The dimension path
  was overwriting the function's effect with a keyed write, and the fixed outer
  key then made it look bounded — but a constant key limits how many keys the
  property has, not how large the array under one of them grows. The function
  now decides the effect and the dimension only says which collection it lands
  on.

### Fixed — fifth release review

Four holes in the one remaining `WS008` exception, the unconditional full reset:

- A write through an array dimension is never a reset of the collection.
  `self::$items['last'] = []` (and the `null` variant) assigns into a key and
  can even add one; it was being classified as a full clear, which silenced the
  finding entirely. Both are now `HIGH`.
- A reset written inside a closure no longer counts for the function that
  declares it. `$reset = function () { self::$items = []; };` is never executed
  on its own — declaration scope is not execution scope, so nothing lexically
  inside a closure is treated as guaranteed.
- `goto` joins return/throw/exit as an early exit, so a reset that is jumped
  over is no longer taken as unconditional.
- Severity no longer depends on method declaration order. Every growing
  function is graded and the worst one decides, so moving a method within its
  class can no longer flip a finding between `MEDIUM` and `HIGH` — or the exit
  code between 0 and 1.

### Changed — fourth release review

`WS008` no longer claims bounds it cannot prove. Two suppression paths are gone
rather than tightened again, because each needed information that the shape of
the code does not carry:

- The **size-guarded eviction** path is removed. Verifying the guard's target,
  direction and limit still left it blind to an eviction behind a second
  condition, to a removal that takes out a key that was never added, to one
  write site that adds two elements, and to `INF` as a "finite" limit.
- The **same-key add/remove** path is removed. Matching the key text cannot see
  that the removal runs *before* the addition, or that the variable holding the
  key was reassigned in between.

What remains is the one provable shape: an unconditional reset of the whole
collection in the function that grows it. Everything else is reported —
`HIGH` when there is no removal at all, `MEDIUM` when removals exist but bound
nothing verifiable, with a message that says so and points at the inline ignore
for a trade-off you have reviewed. The `examples/leaky-app` LRU now carries such
an ignore, which is the workflow this is meant to have.

This makes the rule noisier on correct bounded caches and no longer silent on
six broken ones. The guard-tracking machinery (`sizeGuardedKeys`,
`keyExpression`, the guard stack and the finite-limit check) is deleted, so
there is less code and nothing left to mis-verify.

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

Development version. Never tagged or published; its contents ship as part of
1.0.0.

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

[Unreleased]: https://github.com/goktugcy/worker-safety/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/goktugcy/worker-safety/releases/tag/v1.0.0
