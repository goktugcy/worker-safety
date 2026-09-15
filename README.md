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
Worker Safety 1.2.0

Project
  /Users/dev/payment-api

Environment
  PHP        8.4.2
  Framework  Laravel 12
  Config     worker-safety.yaml

Analysis targets
  FrankenPHP, Octane

  Selected for this scan, not detected — Worker Safety does not inspect
  how this application is deployed. Findings below describe what the code
  would do if it ran under one of these runtimes; they are not observations
  of its current behaviour.

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
cross-request leak rather than a theoretical one if the application runs on
a persistent worker. $user is public, so any caller holding the shared
instance can change it.

Affected runtimes:
  FrankenPHP, Octane

Recommendation:
  - Consider `$this->app->scoped()`: Octane discards scoped instances
    between requests. Check first what else resolves this class — anything
    that outlives a request and took it through the constructor keeps the
    instance it already has, so the binding changes while that consumer does
    not.
  - Or make the service immutable and pass the per-request values in as
    method arguments. That removes the question of lifetime instead of
    answering it.
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

Threshold exceeded: 2 finding(s) at or above the configured fail_on level (HIGH).
That is a decision about the findings above against the configured
threshold. Each one still has to be read: it describes a risk under a
persistent worker, not a fault observed in production.
```

Every finding says **what** was found, **why it is a risk under a worker**, and
**what to do about it**. That last part is the whole point: a list of line
numbers is not actionable.

`FAILED` means the configured threshold was crossed — nothing more. It is not a
statement that any of the findings has caused a fault, and a scan can also fail
for the unrelated reason that some files could not be parsed; that is reported
separately as `Incomplete analysis`.

## Rules

| ID | Severity | Category | Framework | What it finds |
| --- | --- | --- | --- | --- |
| `WS001` | High | Static state | any | A static property that is written at runtime. |
| `WS002` | High | Global state | any | `global $x`, writes to `$GLOBALS`, writes to request superglobals. |
| `WS003` | Medium | Environment mutation | any | `putenv()`, `ini_set()`, writes to `$_ENV` / `$_SERVER`. |
| `WS004` | Medium | Singleton | any | The self-instantiating singleton pattern, graded by whether the instance is mutable. |
| `WS005` | High | Container binding | Laravel | A class bound with `singleton()` that carries mutable state. |
| `WS006` | Medium | Container binding | Laravel | A `singleton()` binding that is a candidate for `scoped()` — a lifetime change to review, not a rename. |
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
- **A conditional initializer is named as such, and no further.** `self::$x ??= …`
  writes only while the slot is null or unset, so WS001 says a stored value is
  reused rather than recomputed — a different story from a slot overwritten per
  request. It stops there: `??=` is *not* proof that the initializer runs once,
  because an initializer returning null leaves the slot empty and a reset
  re-opens it, so the finding claims no lifetime for the value. Nor does it
  decide whether the reuse is safe; see [the standard Laravel
  factory](#the-standard-laravel-factory) below.
- **The more specific rule wins.** A singleton's instance holder is reported by
  `WS004` alone, so one line never carries two contradictory severities.
- **Registration in a service provider is not reported.** That is where it
  belongs; `WS009` targets controllers, middleware, jobs and helpers.
- **Top-level code is downgraded.** Statements outside any function normally run
  once per worker boot, which is exactly where `putenv()` is acceptable.

If a rule is wrong about your code, that is a bug worth reporting — see
[CONTRIBUTING.md](CONTRIBUTING.md).

### The standard Laravel factory

Every new Laravel application ships this, and WS001 reports it as `HIGH`:

```php
// database/factories/UserFactory.php
protected static ?string $password;

// …
'password' => static::$password ??= Hash::make('password'),
```

**This is a real report, not a bug — and in this specific case it is almost
certainly not a problem in your code.** The honest reason it is not silenced
automatically is that the analyzer cannot tell this apart from the shapes that
*are* bugs. All three of these are the same syntax to a parser:

```php
static::$password ??= Hash::make('password');  // fixed literal — harmless
static::$user     ??= auth()->user();          // request #1's user, forever
static::$tenant   ??= request('tenant');       // request #1's tenant, forever
```

The last two are genuine cross-request leaks: because `??=` writes only into an
empty slot, whichever request stores a value there hands it to the requests that
follow, until something resets or replaces it. Any rule broad enough to silence
the first line — "the class name ends in `Factory`",
"the file is under `database/factories`", "the assignment uses `??=`" — silences
those two as well. Deciding properly would mean knowing what `Hash::make`,
`auth()` and `request()` return, which is a question about the framework's
behaviour, not about the syntax.

So the tool reports what it can prove — the assignment writes only into an empty
slot, so a stored value gets reused — states which question decides whether that
matters (where the value comes from), and leaves the answer to you. It does not
claim how long the value lasts: that depends on whether the initializer ever
returns null and on resets it cannot see. For the stock factory the answer is that the input is a fixed
string, so record that decision next to the code:

```php
// worker-safety-ignore WS001 memoized hash of a constant test password
protected static ?string $password;
```

Or, if you would rather not touch the file, accept it once with a
[baseline](#baseline-1).

Know what you are buying in either case. Both the directive and the baseline
entry are anchored to the **property declaration**, not to the initializer, so
they keep applying if the initializer is later changed:

```php
// worker-safety-ignore WS001 memoized hash of a constant test password
protected static ?string $password;      // declaration: unchanged

'password' => static::$password ??= request('tenant'),   // now a real leak, still silent
```

That is the normal trade-off of any suppression — it is a decision recorded
about a line — but it is worth knowing here, because the thing that decides the
risk lives somewhere else in the file. If that matters to you, suppress WS001
per file rather than project-wide, and re-read the initializer when the factory
changes.

`database` is one of the [default scan paths](#default-scan-paths) for Laravel,
so this finding appears on a stock application the first time you run a scan.
Handling it once, either way, is part of adopting the tool.

## Runtime replay

Static analysis asks:

> Could this code retain state between requests?

Runtime replay asks:

> Can request B actually observe state left behind by request A?

Those are different questions, and the second one catches things the first
cannot. `worker-safety test` replays an ordered list of HTTP requests against an
application that is **already running**, and reports whether a later request saw
what an earlier one wrote.

### The case that motivates it

This class is in `tests/Fixtures/Replay/static-safe/` and ships with the
package:

```php
final class RequestContext
{
    private ?string $user = null;

    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    public function user(): ?string
    {
        return $this->user;
    }
}
```

There is nothing wrong with it. No static property, no global, no singleton
holder, no container binding — just a mutable instance property, like most
objects in most applications. The scanner says so:

```console
$ worker-safety scan tests/Fixtures/Replay/static-safe --fail-on=high
No worker-safety risks found.
Result: PASSED                                                   # exit 0
```

That is the **correct** answer. Whether this class leaks depends on who
constructs it and how long they keep it, and neither fact is in the file.

Now use it from a worker loop — one object, reused for every request:

```php
$context = new RequestContext();          // once, outside the loop

while ($request = receiveRequest()) {
    handle($request, $context);           // every request shares it
}
```

Replay two requests against it. The first sets a user; the second asks for one
and never mentions a name:

```console
$ worker-safety test --scenario=tests/Fixtures/Replay/leaky-worker/scenario.yaml
Step 2  observe
  ✓ GET /context
  ✓ status = 200
  ✗ json.user

    Expected:
      null
    Observed:
      "alice"

Result: FAILED                                                   # exit 1
```

Request B observed `"alice"`, which only request A ever sent.

The package ships two worker fixtures differing by **one line** — where
`new RequestContext()` sits relative to the accept loop — and runs the same
unmodified scenario against both:

| | Result |
| --- | --- |
| Static scan of `RequestContext` | **PASS** — 0 findings |
| Replay against the leaky worker | **FAIL** — request B observed `"alice"` |
| Replay against the fixed worker | **PASS** — request B observed `null` |

The third row is what makes the second meaningful. Nothing about the scenario
changes between the two replay runs, so the difference is attributable to the
object's lifetime and nothing else; without it, a permanently red assertion
would be indistinguishable from a broken replay engine.

Both are permanent regression tests — `ReplayStaticGapRegressionTest` and
`ReplayFixedWorkerControlTest` — which start real processes and make real HTTP
requests. The tests also assert, via the fixture's own `X-Worker-Pid` header,
that both requests were served by one process.

The fixture is a small persistent PHP process speaking HTTP over a loopback
socket. It is **not** FrankenPHP or Octane. Separately, release checks on
2026-09-15 reproduced the same leaking/fixed results on FrankenPHP 1.12.7
(PHP 8.5.10), both standalone and through Laravel 12.69.2 / Octane 2.19.1.
Each server used one worker, with a worker-local identity checked across requests.
These were local integration checks, not part of the automated fixture suite.

### Run it against a single worker, or the result means nothing

Replay demonstrates cross-request behaviour only when consecutive requests reach
**the same persistent process**. Against a load-balanced deployment the requests
may land on different workers, and a passing run would prove nothing.

This is not a performance setting. With two workers, request B may be served by
a process that never handled request A, so a leak that exists will often still
report as clean. Treat replay as a test-environment tool.

**Laravel Octane:**

```bash
php artisan octane:start --workers=1
```

**FrankenPHP.** `frankenphp run` on its own does *not* give you one worker — the
worker thread count defaults to twice the number of CPUs. The count is part of
the worker configuration, so set it explicitly:

```caddyfile
# Caddyfile
{
    frankenphp {
        worker {
            file ./public/index.php
            num 1          # one PHP thread for this worker; the default is 2x CPUs
        }
    }
}
```

The short form and the environment variable take the count as the second value:

```bash
# in a Caddyfile frankenphp block
worker ./public/index.php 1

# or, with the Docker image
docker run -e FRANKENPHP_CONFIG="worker ./public/index.php 1" ...
```

`num` is what provides the guarantee; the command you use to start the server
does not. These forms are taken from the FrankenPHP worker and configuration
documentation. The explicit `num 1` setup was also exercised during the
local 1.2.0 release checks described above. The automated regression suite
continues to use its portable PHP fixture; other runtime versions and Octane
backends are not covered by that local check.

Worker Safety does not claim, and cannot claim, that a passing replay against a
production deployment proves the absence of retained state.

### Scenario syntax

```yaml
version: 1
name: shared request context leak
base_url: http://127.0.0.1:8080

steps:
  - id: seed
    request:
      method: POST                  # GET | POST | PUT | PATCH | DELETE
      path: /__worker-safety/context
      headers:
        Authorization: Bearer test-token
      json:                         # sends application/json
        action: set
        user: alice
    expect:
      status: 200

  - id: observe
    request:
      method: GET
      path: /__worker-safety/context
    expect:
      status: 200
      json:
        user: null
```

- `version` must be `1`; anything else is rejected rather than guessed at.
- `base_url` may be omitted when `--base-url` is passed.
- `id` is optional and defaults to `step-1`, `step-2`, …; ids must be unique.
- A request sets either `json` **or** `body`, never both.
- Unknown keys are errors at every level, so a typo fails the run instead of
  silently doing nothing.

Scenarios are read as data. The YAML is parsed without object or custom-tag
support, exactly like `worker-safety.yaml`.

### Assertions

| Key | Meaning |
| --- | --- |
| `status: 200` | HTTP status equality. |
| `json: {user: null}` | Value at a dot-path equals the given value. |
| `headers: {X-Tenant: foo}` | Header equality, case-insensitive. |
| `body_contains: [success]` | Raw body contains every string. |
| `body_not_contains: [alice]` | Raw body contains none of them. |

`json` paths are simple dot-paths, not JSONPath: `user`, `tenant.id`,
`items.0.id`. A numeric segment indexes a list.

Comparison uses JSON's type model, not PHP's:

- **Object key order is irrelevant** — `{"a":1,"b":2}` equals `{"b":2,"a":1}`.
- **Array order matters** — `[1,2]` is not `[2,1]`.
- **An object is never an array** — `{}` and `[]` stay distinct, on the response
  side *and* the YAML side.
- **Types are preserved** — `1`, `"1"` and `true` are three different values.
- **`1` equals `1.0`**, applied at every depth.
- **Missing is not null.** A path that is **absent** is reported as "no value at
  this path", never as `null` — the distinction the leak case depends on, since
  `{"user": null}` and `{}` are different answers. Absence is represented by a
  value JSON cannot produce, so a response containing any particular string is
  never mistaken for a missing field.

### `test`

```bash
worker-safety test --scenario=worker-safety.replay.yaml
worker-safety test worker-safety.replay.yaml --format=json
```

| Option | Meaning |
| --- | --- |
| `--scenario`, `-s` | Scenario file. Also accepted as a positional argument. |
| `--base-url`, `-b` | Override the scenario's `base_url`. |
| `--format`, `-f` | `console` (default) or `json`. |
| `--timeout`, `-t` | Per-request **inactivity** timeout in seconds (default 10). |
| `--quiet`, `-q` | Suppress output; the exit code still reports the result. |

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Every expectation held. |
| `1` | At least one expectation did not hold. |
| `2` | The scenario or an option was invalid. |
| `3` | A request could not be completed — connection refused, timeout, incomplete or malformed response. |

`3` is deliberately distinct from `1`: a request that never completed observed
nothing either way, so it is not evidence about state. A response whose body
stops short of its `Content-Length`, has incomplete chunk framing, or stalls
mid-body is a transport
error for exactly this reason — `body_not_contains: [alice]` cannot be satisfied
by a body that was cut off before `alice` could appear.

Chunked responses must include complete chunks, the zero-size final chunk and
the terminating trailer line. Chunk extensions and trailers are accepted; only
decoded body bytes reach assertions. Unsupported transfer encodings are rejected.
Responses delimited only by connection closure cannot establish whether an
application intended to send more data.

Request `json` values preserve YAML mappings as JSON objects and sequences as
arrays, including nested empty `{}` and `[]` values.

On a transport error nothing is written to stdout, including under
`--format=json`, so a failed run can never be mistaken for a successful report.

`--timeout` is an **inactivity** timeout, not a total deadline: it fires when no
data arrives for that long. A response that keeps trickling bytes resets the
window and is not cut off.

### JSON output

```json
{
  "version": 1,
  "scenario": {
    "name": "shared request context leak",
    "target": "http://127.0.0.1:8080"
  },
  "passed": false,
  "steps": [
    {
      "id": "observe",
      "request": "GET /context",
      "status": 200,
      "passed": false,
      "duration_seconds": 0.0012,
      "assertions": [
        {
          "type": "json_equals",
          "path": "user",
          "expected": null,
          "actual": "alice",
          "actual_missing": false,
          "passed": false,
          "detail": null
        }
      ]
    }
  ]
}
```

`version` is the replay schema version, independent of both the tool version and
the scan report's schema. `actual_missing` distinguishes an absent path from an
observed `null`; when it is `true`, `actual` is `null` only because JSON has no
other way to say "nothing was there".

### Laravel

Expose a test-only endpoint that reads and writes whatever request-scoped state
you want to check, then:

```bash
php artisan octane:start --workers=1
worker-safety test --scenario=worker-safety.replay.yaml
```

Worker Safety does **not** generate routes for you: what counts as request state
is yours to decide, and injecting endpoints into an application would break the
rule that this tool never modifies or executes your code.

Protect any such endpoint so it cannot be reached in production — register it
only in a non-production environment, behind middleware, or in a route file that
production never loads. An endpoint that reports internal state is not something
to ship.

### What replay does not do

- **It never loads your application.** No bootstrap, no autoload, no container,
  no instantiation of your classes. `worker-safety test` is an HTTP client, the
  same way `scan` is a parser — neither one runs your code.
- **It proves nothing about paths you did not replay.** A pass covers the
  requests in the scenario against that instance, and nothing else.
- **It does not inspect memory, objects or the heap.** What it sees is what the
  application chose to put in a response.
- **It does not correlate workers.** There is no worker pinning and no
  distributed tracing; that is why the single-worker requirement exists.
- **It does not analyze queue workers.** Replay drives HTTP requests only.

### Secrets

Scenarios can carry tokens and cookies. Neither reporter echoes request headers,
in console or JSON output, and nothing from a scenario is written to a baseline.
Worker Safety sends no telemetry.

## CLI reference

`scan`, `rules`, `init` and `baseline` analyze source code. `test` is the
runtime counterpart and is documented under
[Runtime replay](#runtime-replay).

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

`test` uses the same four codes with the same meanings, reading "expectation"
for "finding": `1` is a failed expectation, and `3` covers a request that could
not be completed at all. See [Runtime replay](#exit-codes-1).

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
  "tool": { "name": "Worker Safety", "package": "goktugcy/worker-safety", "version": "1.2.0" },
  "project": {
    "root": "/app",
    "paths": ["app"],
    "framework": { "name": "laravel", "version": "12" },
    "runtimes": ["frankenphp", "octane"],
    "analysis_targets": ["frankenphp", "octane"],
    "runtime_detected": false,
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
    "failed_on_severity": true,
    "failed_on_incomplete_analysis": false,
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

### Reading the report fields

- `project.analysis_targets` is what `--runtime` selected. `project.runtimes`
  carries the same values under the original name and is kept for compatibility;
  new consumers should read `analysis_targets`.
- `project.runtime_detected` is always `false`. No runtime detection exists —
  the field is there so a consumer never has to infer it from the presence of
  the list.
- `summary.failed` is the exit-code decision. The two reasons behind it are
  reported separately: `failed_on_severity` means findings crossed `fail_on`,
  and `failed_on_incomplete_analysis` means files could not be parsed. They are
  independent and either can be true on its own.

Fields are only ever added within a schema `version`, never renamed or removed,
so reading by key is safe.

## Supported runtimes

| Runtime | `--runtime` value |
| --- | --- |
| FrankenPHP worker mode | `frankenphp` |
| Laravel Octane | `octane` |
| RoadRunner | `roadrunner` |
| Swoole / OpenSwoole | `swoole` |

### These are targets, not detections

Worker Safety **does not detect which runtime your application uses.** There is
no such check anywhere in the tool, and the report labels the list `Analysis
targets` for that reason: it is the set of runtimes the scan reasons about,
chosen by you with `--runtime` (default: all four).

Nor is the absence of a runtime package in `composer.json` treated as evidence
that no persistent worker is involved. It is not reliable evidence: a runtime
can be installed outside Composer — FrankenPHP and RoadRunner are binaries — the
decision usually lives in deployment configuration the source tree never sees,
and a long-running queue worker holds process state across jobs without any of
these packages being present.

That last point is a reason not to trust the inference, **not** a claim of
support: queue workers are not an analysis target, `--runtime` has no value for
them, and no rule models a job lifecycle. The four runtimes in the table above
are the whole list.

The practical consequence is that **nothing is hidden or downgraded because a
runtime looks absent.** That is deliberate: the most useful moment to run this
tool is *before* the migration, when none of those packages are installed yet.
Findings are conditional statements — what this code would do if it ran under a
persistent worker — and reading them is still your job.

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
- **Where a memoized value comes from, and how long it lasts.** WS001 can prove
  that `self::$x ??= …` writes only into an empty slot, but not whether the
  stored value is a constant or request data, and not how long it survives —
  an initializer that returns null re-runs every time, and a reset in unscanned
  code re-opens the slot. See [the standard Laravel
  factory](#the-standard-laravel-factory).
- **No runtime detection.** Which runtime an application actually uses is never
  determined; `--runtime` selects what the analysis reasons about. See [these
  are targets, not detections](#these-are-targets-not-detections).
- **Suppression is anchored to the reported line.** An inline directive or a
  baseline entry for a static property keeps applying when the code that
  assigns it changes, because the fingerprint is built from the declaration.

Position this tool accordingly. It is a

> worker-safety **risk analyzer**

not a

> **proof of safety**.

A clean scan means the known cross-request patterns are absent from the analyzed
paths. It does not mean the application is safe to run on a worker. Stage the
rollout, watch memory, and recycle workers.

## Roadmap

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
