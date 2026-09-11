# Worker Safety

Static analysis for PHP applications running on persistent workers.

**Detect cross-request state risks before they reach production.**

[![CI](https://github.com/goktugcy/worker-safety/actions/workflows/ci.yml/badge.svg)](https://github.com/goktugcy/worker-safety/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/goktugcy/worker-safety.svg)](https://packagist.org/packages/goktugcy/worker-safety)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-777bb4.svg)](composer.json)

---

## The problem

Under PHP-FPM, every request gets a fresh PHP context. The OS worker process is
reused, but the engine tears the request down afterwards, so anything you left in
a static property, a global or a singleton is gone by the next request. This is
therefore harmless:

```php
final class UserContext
{
    public static ?User $user = null;
}
```

Under FrankenPHP worker mode, Laravel Octane, RoadRunner or Swoole the process
stays alive and serves request after request. The same code now means: *the user
from the previous request is still there.* Depending on what reads it, that is a
stale cache, a wrong audit log, or one customer seeing another customer's data.

These bugs are hard to find by testing, because they only appear on the *second*
request, and only when two requests differ in the right way.

Worker Safety reads your source code and points at the places where this can
happen — before you flip the switch.

## Installation

```bash
composer require --dev goktugcy/worker-safety
```

In a Laravel application that is all you need: the package registers an Artisan
command through package discovery. See [Laravel](#laravel) below.

## Quick start

```bash
# Scan the configured paths, or the framework defaults
vendor/bin/worker-safety scan

# Scan specific directories
vendor/bin/worker-safety scan app src

# Fail a CI build on HIGH findings
vendor/bin/worker-safety scan --fail-on=high

# Machine-readable output
vendor/bin/worker-safety scan --format=json
vendor/bin/worker-safety scan --format=sarif > worker-safety.sarif
```

Optionally write a configuration file first:

```bash
vendor/bin/worker-safety init
```

### What a finding looks like

```text
Worker Safety 1.0.1

Project
  /Users/dev/payment-api

Environment
  PHP        8.4.2
  Framework  Laravel 12
  Runtime    FrankenPHP, Octane
  Config     worker-safety.yaml

284 PHP files analyzed in 1.42s.

Findings
──────────────────────────────────────────────────────────────────────

HIGH WS005  Laravel container singleton with mutable state

app/Providers/AppServiceProvider.php:18  App\Support\UserContext::$user

    $this->app->singleton(UserContext::class);

Laravel singleton UserContext contains potentially request-specific mutable
state ($user).

UserContext is registered with singleton(), so the container builds it once
and hands the same instance to every request. UserContext declares 1 mutable
instance property ($user) at app/Support/UserContext.php:12, which means
values written while serving one request are still set for the next one. The
property names read as request-specific state (user), so this is a concrete
cross-request leak rather than a theoretical one: consider a scoped binding.
$user is public, so any caller holding the shared instance can change it.

Affected runtimes:
  FrankenPHP, Octane

Recommendation:
  - Use `$this->app->scoped()` instead of `singleton()`: Octane discards
    scoped instances between requests.
  - Or make the service immutable and pass the per-request values in as
    method arguments.
  - If the binding must stay a singleton, reset its state in a
    RequestReceived listener.

──────────────────────────────────────────────────────────────────────

Summary

  Critical  0
  High      2
  Medium    3
  Low       0
  Info      0

Result: FAILED

2 finding(s) at or above HIGH.
```

Every finding says **what** was found, **why it is a risk under a worker**, and
**what to do about it**. That last part is the whole point: a list of line
numbers is not actionable.

## Rules

| ID | Severity | Category | Framework | What it finds |
| --- | --- | --- | --- | --- |
| `WS001` | High | Static state | any | A static property that is written at runtime. |
| `WS002` | High | Global state | any | `global $x`, writes to `$GLOBALS`, writes to request superglobals. |
| `WS003` | Medium | Environment mutation | any | `putenv()`, `ini_set()`, writes to `$_ENV` / `$_SERVER`. |
| `WS004` | Medium | Singleton | any | The self-instantiating singleton pattern, graded by whether the instance is mutable. |
| `WS005` | High | Container binding | Laravel | A class bound with `singleton()` that carries mutable state. |
| `WS006` | Medium | Container binding | Laravel | A `singleton()` binding that should be `scoped()`. |
| `WS007` | High | Static state | any | Static state whose name or type reads as request-specific (`$currentUser`, `static ?User $u`). |
| `WS008` | Medium | Memory retention | any | A static collection that only ever grows. |
| `WS009` | Medium | Event registration | any | Listener, macro or handler registration on a request path. |
| `WS010` | Low | Process lifecycle | any | Code that assumes the process dies at the end of the request. |

The **Severity** column is each rule's default. Most rules compute a severity per
finding — a static property with an explicit reset path is reported lower than one
without, and an immutable singleton much lower than a mutable one — and the
configuration can override the result.

Inspect the rule set from the CLI:

```bash
vendor/bin/worker-safety rules            # the table above
vendor/bin/worker-safety rules WS001      # one rule in detail
vendor/bin/worker-safety rules --format=json
```

### How the rules avoid crying wolf

"I saw a `static`, therefore it is a bug" would make this tool useless. So:

- **A property that is never written is not mutable state.** Writes are collected
  across the whole project, so `Registry::$items[] = …` in another file counts,
  and a scalar `private static string $version = '1.0';` produces nothing.
- **Naming is checked in both directions.** `$currentUser` and `static ?User $u`
  match; `$userTable`, `$defaultLocale` and `UserRepository` do not, because
  configuration and infrastructure vocabulary neutralises the match.
- **A release path lowers the severity instead of hiding the finding.** A static
  cache cleared only by a `flush()` method is `MEDIUM`, and one with no release
  path at all is `HIGH`.
- **Silence requires a proof, and only one shape qualifies.** WS008 goes quiet
  for exactly one pattern: a statement that unconditionally resets the whole
  collection, in the function that grows it, because then nothing can carry over
  to the next call. It has to be a statement — an assignment nested inside
  another expression is not accepted, because whether a sub-expression runs
  depends on everything around it.
  Everything else — including the `if (count($x) > N) array_shift($x)` eviction
  idiom — is reported, at `MEDIUM` rather than `HIGH`, with a message saying the
  removal is there but unproven. Certifying an eviction would mean knowing that
  it runs on every path through its guard, that it removes at least as much as
  was added, and that the limit is finite; matching a removal to an addition
  would mean knowing their order and the runtime value of the key. None of that
  follows from the shape of the code, so the tool does not claim it. Review the
  finding and, if the bound is real, record the decision with an inline
  `// worker-safety-ignore WS008`.
- **The more specific rule wins.** A singleton's instance holder is reported by
  `WS004` alone, so one line never carries two contradictory severities.
- **Registration in a service provider is not reported.** That is where it
  belongs; `WS009` targets controllers, middleware, jobs and helpers.
- **Top-level code is downgraded.** Statements outside any function normally run
  once per worker boot, which is exactly where `putenv()` is acceptable.

If a rule is wrong about your code, that is a bug worth reporting — see
[CONTRIBUTING.md](CONTRIBUTING.md).

## CLI reference

### `scan`

```text
worker-safety scan [paths...] [options]

  paths                   Files or directories to scan. Defaults to the
                          configured paths, then the framework defaults.

  -c, --config=FILE       Path to a configuration file.
  -f, --format=FORMAT     console (default), json, sarif.
  -r, --runtime=RUNTIME   frankenphp, octane, roadrunner, swoole or all.
                          Repeatable, and accepts a comma separated list.
      --fail-on=SEVERITY  critical, high, medium, low, info or never.
      --project-dir=DIR   Project root. Defaults to the working directory.
      --baseline=FILE     Path to the baseline file.
      --no-baseline       Ignore an existing baseline file.
      --generate-baseline Write current findings to the baseline and exit 0.
      --no-progress       Do not render a progress bar.
      --allow-parse-errors
                          Report unanalyzable files as warnings instead of
                          failing the scan.
      --no-ansi           Disable colour.
  -q, --quiet             Suppress output; rely on the exit code.
  -v                      Also print stack traces for internal errors.
```

### `rules`

Lists the rule set, or one rule in detail. Supports `--format=table|json`.

### `init`

Writes a commented `worker-safety.yaml` with defaults matching the detected
framework. Refuses to overwrite an existing file unless `--force` is given, and
never prompts — safe to run in CI.

### `baseline`

Records every current finding so that only new ones fail the build. See
[Baseline](#baseline-1).

## Laravel

Installing the package in a Laravel application registers one Artisan command
through package discovery:

```bash
php artisan worker-safety:scan
php artisan worker-safety:scan app --runtime=octane --fail-on=medium
php artisan worker-safety:scan --format=sarif --no-progress
```

It is the same command as `vendor/bin/worker-safety scan`, not a reimplementation
of it: identical arguments, options, output formats, baseline handling and
[exit codes](#exit-codes). Anything documented for `scan` works here unchanged.

The one difference is the default project root. Artisan can be invoked from any
directory, so the scan defaults to the application's `base_path()` rather than
the current working directory. `--project-dir` still overrides it, and a
relative value is resolved against the application root:

```bash
# Scans the application, wherever you run it from
php artisan worker-safety:scan

# Scans a package inside the repository instead
php artisan worker-safety:scan --project-dir=packages/billing
```

No scanning happens during an HTTP request, and the Artisan command is not
registered there either — the provider's `boot()` returns early outside the
console. Be precise about what that does and does not mean: in an install that
has dev dependencies, a discovered provider *is* loaded on every request, and
this one's `register()` adds a single container binding. Nothing else runs. In a
production install built with `--no-dev` the package is not present at all, so
there is no provider to discover.

### Supported Laravel versions

| Version | Status |
| --- | --- |
| Laravel 13 | Verified: same checks, on PHP 8.5 with `symfony/console` 8. Laravel 13 requires `symfony/console` ^7.4 or ^8.0, which is why the package accepts Symfony 8. |
| Laravel 12 | Verified: package discovery, all documented options, JSON and SARIF output, baseline round-trip and every exit code. |
| Laravel 11 | Verified: same checks. On PHP 8.5 the framework itself emits deprecation notices during bootstrap that land on stdout, which will corrupt `--format=json` or `--format=sarif` output — that is Laravel 11 on PHP 8.5, not this package, and `php artisan list` does it too. PHP 8.2–8.4 is unaffected. |
| Laravel 10 | Not verified. The package's constraints (PHP ^8.2, `symfony/console` ^6.4, ^7.0 or ^8.0) do not exclude it, but it has not been tested, so no support is claimed. |

### If package discovery is disabled

Projects that opt out of discovery — either globally, or by listing this package
under `extra.laravel.dont-discover` — should register the provider by hand.

In Laravel 11 and 12, add it to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    WorkerSafety\Integration\Laravel\WorkerSafetyServiceProvider::class,
];
```

In Laravel 10, add it to the `providers` array in `config/app.php`:

```php
'providers' => [
    // …
    WorkerSafety\Integration\Laravel\WorkerSafetyServiceProvider::class,
],
```

Because the package is a dev dependency, guard the registration if the same file
is used for a production build without dev dependencies:

```php
if (class_exists(WorkerSafety\Integration\Laravel\WorkerSafetyServiceProvider::class)) {
    // register it
}
```

Laravel is never a runtime dependency of this package. In a project without it,
`vendor/bin/worker-safety` behaves exactly as documented everywhere else in this
README, and nothing under `WorkerSafety\Integration\Laravel` is ever autoloaded.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Scan completed; nothing reached the failure threshold. |
| `1` | Findings reached the `--fail-on` threshold, **or** a file could not be analyzed. |
| `2` | Invalid configuration or CLI option. Nothing was analyzed. |
| `3` | Internal error, including a path that does not exist. |

`--fail-on=never` reports findings but always exits `0`.

A file that could not be parsed or read at all is not a finding — it is a gap in
the evidence, so it fails the scan on its own. The console output says which
files and why. Pass `--allow-parse-errors` (or set `fail_on_parse_error: false`)
to downgrade that to a warning.

Be precise about what this means: a scan is *complete* when *no file was skipped
entirely*, not when every file parsed without a single error. A syntax error
php-parser recovers from leaves the scan complete, because the file was still
analyzed — it is reported as a parse warning instead. Both counts are in the
JSON report (`summary.parse_errors` and `summary.files_not_analyzed`), and
`summary.incomplete` is the one that gates the build.

## Configuration

Worker Safety runs without any configuration. To customise it, put a
`worker-safety.yaml` in the project root (`worker-safety.yml`,
`worker-safety.dist.yaml` and `.worker-safety.yaml` are also picked up), or pass
`--config=path/to/file.yaml`.

```yaml
paths:
  - app
  - src

exclude:
  - vendor
  - storage
  - tests

runtime:
  - frankenphp
  - octane

rules:
  WS001:
    enabled: true
    severity: high

  # Shorthand for `enabled: false`
  WS009: false

# Suppress a rule for specific paths
ignore:
  WS008:
    - app/Legacy

fail_on: high

# Fail the scan when a file cannot be analyzed at all (default: true).
fail_on_parse_error: true

baseline: worker-safety-baseline.json
```

See [worker-safety.example.yaml](worker-safety.example.yaml) for the annotated
version — it is the exact file `init` writes.

Notes:

- **Unknown keys, unknown rule ids and invalid severities are hard errors**
  (exit code `2`), not silently ignored typos.
- `exclude` patterns: a bare name (`vendor`) matches any path segment, a value
  containing `/` (`bootstrap/cache`) matches a path prefix, and globs
  (`*.blade.php`) match the path and the basename.
- `vendor`, `node_modules` and `.git` are **always** excluded, even if you
  replace the `exclude` list, unless you explicitly point a scan path inside them.
- A configured `severity` always wins over the severity a rule computed.

### Default scan paths

With no `paths` configured and no path argument, Worker Safety uses the framework
defaults — Laravel: `app`, `bootstrap`, `config`, `database`, `routes`;
Symfony: `src`, `config`; otherwise `src`, `lib`, `app` — skipping the ones that
do not exist, and falling back to the project root.

## Suppressing a finding

Inline, by comment:

```php
// worker-safety-ignore WS001
private static array $cache = [];

private static array $accepted = []; // worker-safety-ignore WS001,WS008
```

Available forms — the rule list is optional, and omitting it suppresses every rule:

| Directive | Scope |
| --- | --- |
| `worker-safety-ignore WS001` | The comment's own line and the following line. |
| `worker-safety-ignore-line WS001` | The comment's own line only. |
| `worker-safety-ignore-next-line WS001` | The following line only. |
| `worker-safety-ignore-file WS001` | The whole file. |

Directives are read from the token stream, so the marker inside a string literal
is never mistaken for one.

By attribute, which covers the annotated declaration's whole line range:

```php
use WorkerSafety\Attribute\WorkerSafetyIgnore;

#[WorkerSafetyIgnore('WS001', 'WS008')]
private static array $cache = [];
```

Or by path, in the configuration file, using the `ignore` key shown above.

Suppressed findings are counted in the summary, so they never disappear silently.

## Baseline

To adopt the tool on an existing codebase without fixing everything first:

```bash
vendor/bin/worker-safety baseline
```

This writes `worker-safety-baseline.json`, which you commit. Recorded findings
stop failing the build; new ones still do.

```bash
vendor/bin/worker-safety scan                # baseline applied automatically
vendor/bin/worker-safety scan --no-baseline  # report everything again
vendor/bin/worker-safety scan --generate-baseline   # same as `baseline`
```

Baseline entries are fingerprinted from the rule, the file, the symbol and the
whole reported construct — **not** the line number, and with no length limit.
The code component is taken from the token stream, so:

- inserting lines above a baselined finding does not resurrect it, and neither
  does re-indenting the file or adding a comment inside the construct;
- changing the code does resurrect it, including a change on a continuation
  line of a long multi-line statement, and including whitespace *inside* a
  string literal or a heredoc, which is content rather than formatting.

A baseline is never written from an incomplete scan: if a file could not be
analyzed, `baseline` refuses rather than recording a gap as accepted.

## GitHub Actions

Fail the build on high-severity findings:

```yaml
name: Worker Safety

on:
  pull_request:
  push:
    branches: [main]

jobs:
  worker-safety:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - run: composer install --no-interaction --no-progress

      - run: vendor/bin/worker-safety scan --fail-on=high --no-ansi
```

### SARIF and code scanning

Upload results to GitHub code scanning to get findings as annotations on the pull
request diff:

```yaml
name: Worker Safety (code scanning)

on:
  pull_request:
  push:
    branches: [main]

permissions:
  contents: read
  security-events: write

jobs:
  worker-safety:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - run: composer install --no-interaction --no-progress

      # `--fail-on=never` so the SARIF always uploads; code scanning decides
      # what blocks the merge.
      - run: vendor/bin/worker-safety scan --format=sarif --fail-on=never > worker-safety.sarif

      - uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: worker-safety.sarif
          category: worker-safety
```

Both are reproduced in full above, and the repository keeps copies under
`examples/workflows/`. They live there rather than in its own
`.github/workflows/` because they run `vendor/bin/worker-safety`, which exists
only once the package is installed as a dependency.

## JSON output

`--format=json` writes a stable schema to stdout and nothing else — no colour, no
progress, no banner:

```json
{
  "version": "1",
  "tool": { "name": "Worker Safety", "package": "goktugcy/worker-safety", "version": "1.0.1" },
  "project": {
    "root": "/app",
    "paths": ["app"],
    "framework": { "name": "laravel", "version": "12" },
    "runtimes": ["frankenphp", "octane"],
    "php": "8.4.2",
    "config": "worker-safety.yaml",
    "baseline": null
  },
  "summary": {
    "files": 284,
    "duration_seconds": 1.42,
    "total": 5,
    "critical": 0,
    "high": 2,
    "medium": 3,
    "low": 0,
    "info": 0,
    "suppressed": 0,
    "baseline_filtered": 0,
    "parse_errors": 0,
    "fail_on": "high",
    "failed": true,
    "by_rule": { "WS001": 1, "WS005": 1, "WS006": 1, "WS008": 1, "WS009": 1 }
  },
  "findings": [
    {
      "rule": "WS005",
      "title": "Laravel container singleton with mutable state",
      "severity": "high",
      "category": "container-binding",
      "file": "app/Providers/AppServiceProvider.php",
      "line": 18,
      "column": 9,
      "end_line": 18,
      "message": "Laravel singleton UserContext contains potentially request-specific mutable state ($user).",
      "details": "…",
      "remediation": ["…"],
      "snippet": "$this->app->singleton(UserContext::class);",
      "symbol": { "class": "App\\Support\\UserContext", "method": "register", "property": "user" },
      "runtimes": ["frankenphp", "octane"],
      "framework": "Laravel",
      "fingerprint": "a907350778c516d031547604abb544df"
    }
  ],
  "parse_errors": []
}
```

`version` is the schema version and only changes on a breaking change to the
shape, independently of the tool version.

## Supported runtimes

| Runtime | `--runtime` value |
| --- | --- |
| FrankenPHP worker mode | `frankenphp` |
| Laravel Octane | `octane` |
| RoadRunner | `roadrunner` |
| Swoole / OpenSwoole | `swoole` |

All four are analyzed by default. Selecting a subset narrows which rules run and
which runtimes each finding lists, and when exactly one runtime is selected the
console report adds that runtime's specific caveat — for example, that Octane
resets container state between requests but never your own statics. Note that
`octane.flush` does **not** help there: it calls `forgetInstance()` on the
container bindings you list and never touches a static property, so a static
needs an explicit reset from a `RequestReceived` or `RequestTerminated`
listener.

Superglobals differ per runtime too. FrankenPHP worker mode resets `$_GET`,
`$_POST`, `$_COOKIE`, `$_FILES`, `$_SERVER` and `$_REQUEST` between requests and
documents `$_ENV` as the exception, which is why WS003 rates a `$_ENV` write
above a `$_SERVER` one.

## Supported frameworks

| Framework | Detection | Support |
| --- | --- | --- |
| Laravel | `laravel/framework`, `laravel/lumen-framework`, `illuminate/support` | Container-binding analysis (`WS005`, `WS006`), Laravel-aware event detection in `WS009`, framework-specific scan defaults. |
| Symfony | `symfony/framework-bundle`, `symfony/symfony` | Scan defaults. The adapter is a ready extension point; v1 ships no Symfony-specific rules rather than guessing. |
| Plain PHP | fallback | All eight framework-independent rules. |

Detection is a read of `composer.json` and `composer.lock`. The framework itself
is never installed, loaded or booted, and Worker Safety does not depend on it.

A `scoped()` registration only silences `WS005`/`WS006` when it uses the **exact
same container key**. Keys are plain array keys, so `'Shared'` is not `'shared'`,
and aliases are deliberately not followed: `forgetScopedInstances()` unsets
`$instances[$key]` without resolving aliases, and `bind()` deletes the alias for
any key it re-registers — so `alias(Ctx::class, 'a')` plus `scoped('a')` leaves
the `Ctx::class` singleton alive. This key identity is separate from PHP
class-name identity, which stays case-insensitive when resolving a bound class
to its declaration.

## Security

The scanner **never executes, includes, autoloads or evaluates the code it
analyzes**. Source is turned into an AST and nothing more; `scan` does not
bootstrap the analyzed application, and YAML is parsed without object support.
See [SECURITY.md](SECURITY.md) for the full threat model.

## Performance

- Every file is parsed exactly once. A single AST walk drives semantic indexing,
  suppression collection and rule dispatch, so adding a rule costs no extra pass.
- Excluded directories are pruned during traversal rather than filtered
  afterwards, which is what keeps `vendor/` from ever being walked.
- Files are read and released one at a time, so peak memory is bounded by the
  largest single file plus the semantic index, not by the size of the project.
- Rules that need cross-file knowledge emit their findings after the walk, from
  the shared index — never by re-parsing.

Parallel scanning is not implemented in v1. The architecture allows it: file
analysis is independent apart from the shared index.

## Limitations

Static analysis cannot find every runtime leak, and this tool does not pretend
otherwise. Out of scope for v1:

- **Dynamic property and variable access** — `$obj->{$name}`, `$$var`,
  `Foo::${$prop}`.
- **Reflection** and anything that writes state through it.
- **Runtime-generated code** — `eval()`, generated classes, `create_function`.
- **Third-party packages.** `vendor/` is not scanned, so state retained inside a
  dependency is invisible. If a dependency leaks, this tool will not tell you.
- **C extension memory leaks** and leaks inside the runtime itself.
- **Complex data flow.** A value that reaches a static property through several
  function calls, an array, or a closure captured elsewhere may be missed.
- **Inherited and trait-provided members.** A static property declared in a
  parent class or a trait is analyzed where it is declared, not once per
  using class, so inherited instance state can be missed by the container rules.
- **A user function that shadows a built-in.** A namespaced `function putenv()`
  called unqualified cannot be told apart from the global one, so WS003 may
  report it. Aliased imports (`use function putenv as changeEnv`) *are*
  resolved correctly.
- **Container bindings whose class is not in the scanned paths** are skipped
  rather than guessed at.
- **Bindings and listeners registered dynamically**, from a config array or a
  loop, are not correlated.

Position this tool accordingly. It is a

> worker-safety **risk analyzer**

not a

> **proof of safety**.

A clean scan means the known cross-request patterns are absent from the analyzed
paths. It does not mean the application is safe to run on a worker. Stage the
rollout, watch memory, and recycle workers.

## Roadmap

- `worker-safety test` — drive a real FrankenPHP/Octane worker, replay requests
  and diff observed state between them. The `Finding` model and every reporter
  are already independent of the AST so that a runtime analyzer can emit into
  them unchanged; `Analyzer` is the interface it will implement.
- Symfony-specific rules for container and kernel state.
- Inherited and trait-provided static property analysis.
- Parallel file analysis.
- More `--fix`-style guidance, but **not** automatic code modification.

## Contributing

Bug reports, and especially false-positive reports, are welcome. See
[CONTRIBUTING.md](CONTRIBUTING.md) for the development setup, the false-positive
policy and how to add a rule.

```bash
composer install
composer ci   # lint + PHPStan (max) + PHPUnit
```

## License

MIT. See [LICENSE](LICENSE).
