# Examples

## `leaky-app`

A tiny Laravel-shaped project with the same feature written twice: once the way
it is usually written under PHP-FPM (`before/`), and once so that nothing
survives a request (`after/`).

Run it from the repository root:

```bash
# The FPM-style version
bin/worker-safety scan before --project-dir=examples/leaky-app --fail-on=never

# The worker-safe version
bin/worker-safety scan after --project-dir=examples/leaky-app --fail-on=never
```

### `before/` — 9 findings across 8 rules

```text
CRITICAL WS007  Static request/user context
HIGH     WS001  Mutable static property
HIGH     WS003  Runtime environment mutation
HIGH     WS005  Laravel container singleton with mutable state
HIGH     WS008  Static collection growth
MEDIUM   WS001  Mutable static property
MEDIUM   WS006  Laravel scoped binding candidate
MEDIUM   WS009  Persistent event/listener registration
MEDIUM   WS010  Shutdown/runtime lifecycle assumption

  Critical  1
  High      4
  Medium    4
```

What each one is pointing at:

| Rule | Code | Why it matters under a worker |
| --- | --- | --- |
| `WS007` | `public static ?object $currentUser` | The next request reads the previous request's user. |
| `WS001` | the same property, plus `$permissionCache` | Both are process-lifetime slots. |
| `WS008` | `self::$permissionCache[$token] = …` | Nothing removes entries; the array grows until the worker is recycled. |
| `WS003` | `putenv('CURRENT_TENANT=…')` | The environment is never restored, so later requests inherit the tenant. |
| `WS005` | `$this->app->singleton(RequestState::class)` | One `RequestState` for the whole worker; every request writes into it. |
| `WS006` | the same binding | `scoped()` is the lifetime this class actually wants. |
| `WS009` | `Event::listen(...)` in a controller | A listener is added per request and the old ones keep firing. |
| `WS010` | `exit(1)` in a controller | Tears down the worker process mid-loop. |

### `after/` — one LOW note

```text
LOW WS001  Mutable static property

after/PermissionCache.php:18  Example\After\PermissionCache::$entries

  Critical  0
  High      0
  Medium    0
```

The fixes:

- `UserContext` became a normal object with instance state, registered with
  `scoped()` so the container builds one per request.
- `Config` stays a `singleton()`, and is not reported, because it is immutable —
  sharing it is the right thing to do.
- `Event::listen()` moved into the provider's `boot()`, which runs once per
  worker boot.
- `exit(1)` became a returned response.
- `putenv()` is gone; the value is passed as an argument.

The remaining `LOW` is deliberate, and it is worth reading:

```text
PermissionCache::$entries accumulates entries at runtime. The array is shared by
every request the worker serves, so a value written for one request can be read
back by the next one. The class does expose a reset path, so the remaining risk
is that the reset is not wired into the worker request lifecycle.
```

`PermissionCache` is still a static cache. It is bounded — growth and eviction
happen in the same method, so `WS008` stays quiet — but it is still shared across
requests, so `WS001` reports it at `LOW` rather than pretending it is invisible.
That is the intended behaviour: a cache like this is usually a deliberate
trade-off, and `LOW` says "we see it, you decide" instead of failing the build.
