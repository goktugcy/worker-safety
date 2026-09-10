# Contributing

Thanks for helping out. This project has one job: tell a developer, before they
switch to a persistent worker, which parts of their code will carry state from
one request into the next — and be right often enough to be trusted.

## Getting set up

```bash
git clone https://github.com/goktugcy/worker-safety.git
cd worker-safety
composer install
composer ci        # lint + static analysis + tests
```

Individual steps:

```bash
composer test      # PHPUnit
composer analyse   # PHPStan at max level
composer lint      # PHP-CS-Fixer, no changes written
composer lint:fix  # PHP-CS-Fixer, writes changes
```

All three must be green before a pull request can be merged. PHPStan runs at
`max` with no baseline, and there is no ignore list — if a change needs one,
that is worth discussing in the pull request first.

## The false-positive rule

A scanner that cries wolf gets uninstalled. Concretely:

- **"I saw a static, therefore it is a bug" is not acceptable.** A finding must
  be able to explain, in the `details` text, why *this* code can leak state.
- **Prefer a lower severity over a missing distinction.** If a rule cannot tell
  a dangerous case from a benign one, report the uncertain case at a lower
  severity and say why in the message.
- **Every rule needs a `safe.php` fixture** that must produce zero findings, not
  just a `positive.php` that produces some.

If you are unsure whether something is a real risk, open an issue with the code
sample before writing the rule.

## Adding a rule

1. Pick the next free `WSxxx` id and add it to `WorkerSafety\Rule\RuleId`.
2. Add the rule class:
   - framework-independent → `src/Rule/BuiltIn/`
   - framework-specific → `src/Framework/<Framework>/Rule/`, with
     `requiresFramework` set in its `RuleDefinition`.
3. Extend `AbstractRule` and implement `definition()` plus whichever lifecycle
   hooks you need:
   - `nodeTypes()` + `enterNode()` — per-node checks.
   - `beginFile()` / `finishFile()` — per-file aggregation, e.g. reporting one
     finding per global variable instead of one per write.
   - `finishProject()` — anything that needs the whole project, reading the
     semantic model from `ProjectContext::index()`. This is how a rule can
     correlate a container binding in one file with a class declared in another
     while the project is still parsed only once.
4. Register it in `WorkerSafety\Rule\RuleRegistryFactory` (or in the framework
   adapter's `rules()`).
5. Add `tests/Fixtures/WSxxx/{positive,safe,edge}.php` and a test that asserts
   findings *and* severities through `AnalyzerHarness`.
6. Document the rule in the README table.

Rules must not need their own AST pass. If you find yourself wanting one, the
information probably belongs in the semantic index
(`src/Ast/Index/`, populated by `IndexCollectingVisitor`) so every rule can use it.

## Hard constraints

- **Never execute, include, autoload or evaluate analyzed code.** The scanner
  reads source into an AST and nothing else. This is the guarantee that makes it
  safe to run against untrusted repositories, and it is covered by a test.
- **No framework dependency in the core.** `composer.json` requires
  `nikic/php-parser` and two small Symfony components. Laravel and Symfony
  support lives behind `FrameworkAdapter`.
- **The `Finding` model stays free of AST types.** A future runtime analyzer
  will emit the same objects into the same reporters.
- Target PHP 8.2: no typed class constants, no 8.3+ syntax.

## Commit and pull request hygiene

- One logical change per pull request.
- Update `CHANGELOG.md` under `## [Unreleased]`.
- If you change the configuration template, regenerate the example file:

  ```bash
  php -r 'require "vendor/autoload.php"; file_put_contents("worker-safety.example.yaml", WorkerSafety\Config\ConfigurationTemplate::render());'
  ```

  A test fails if the two drift apart.

## Reporting a false positive

Open an issue with:

1. The smallest code sample that triggers it.
2. The finding as printed by `vendor/bin/worker-safety scan --no-ansi`.
3. Why the code is actually safe under a worker.

False-positive reports are the most valuable issues this project can receive.
