# Security policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 0.1.x   | yes       |

While the project is pre-1.0, security fixes land on the latest minor release.

## Reporting a vulnerability

Please report security issues privately through
[GitHub's private vulnerability reporting](https://github.com/goktugcy/worker-safety/security/advisories/new)
rather than in a public issue.

Include the affected version, a description of the impact, and a reproduction if
you have one. You can expect an acknowledgement within a few days.

## The threat model

Worker Safety is a development tool that reads source code, so the interesting
question is what happens when it is pointed at a repository you do not trust.

**By design, the scanner never runs the code it analyzes.** Specifically:

- Source files are turned into an abstract syntax tree with
  `nikic/php-parser`. They are never `include`d, `eval`ed, autoloaded or
  reflected over.
- The `scan` command does not bootstrap the analyzed application: no framework
  kernel, no service container, no `.env` loading.
- Framework detection is a read of `composer.json` and `composer.lock` as JSON.
  No Composer plugin or script from the analyzed project is executed.
- Configuration is parsed with `symfony/yaml` without object support and without
  custom tags, so a configuration file cannot instantiate a class.
- Nothing is sent over the network. There is no telemetry and no update check.

A syntax error, a hostile filename or a file that would throw on load is
therefore a parse warning at worst, never code execution. If you find a way to
make the scanner execute analyzed code, that is a vulnerability — please report
it.

## What the tool does write

- `worker-safety.yaml` (only via `worker-safety init`)
- `worker-safety-baseline.json` (only via `worker-safety baseline` or
  `scan --generate-baseline`)

Reports go to stdout. Nothing else on the filesystem is modified.
