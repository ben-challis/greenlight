# Phase 0: repository bootstrap

| | |
|---|---|
| **Track** | spine |
| **Unblocked by** | nothing |
| **PRD sections** | 12 (memory principles), 13 (architecture principles) |
| **Writes to** | repo root, `.github/`, `bin/`, `tools/`, `docs/`, `tests/` |

## Decisions locked in this phase

1. Zero runtime dependencies. `composer.json` `require` contains only `php: >=8.4`. A testing framework is installed into every project's dev dependencies; any runtime dependency it carries becomes a version conflict with the code under test. This forces us to own small amounts of code (CLI argument parsing, a process abstraction) and that is the right trade. `ext-pcntl` and `ext-sockets` are `suggest`ed, not required; the runner detects them and degrades to `workers: 1` without them.
2. Package name `greenlight/greenlight`, namespace `Greenlight\`. Component sub-namespaces map to `src/<Component>/`. Read-only package splits are deferred until after v1.0; deptrac enforces the boundaries from day one so the split stays possible.
3. Composer scripts are the task runner. No Makefile, no justfile. CI calls exactly the same scripts, so local and CI behaviour cannot drift.
4. No PHPUnit, ever, including for bootstrapping. Until the engine can run tests (end of Phase 5), the suite runs under `tools/bootstrap-runner.php`: a deliberately dumb single-file runner (target under 200 lines) that instantiates `*Test` classes, calls `#[Test]` methods, and reports via exit code. It has no assertions of its own; tests use `Greenlight\Expect` as soon as it exists (Phase 4), and native `assert()` before that. The bootstrap runner is deleted in Phase 12 and nothing may depend on its behaviour.
5. Coding standard: PER-CS 2.0 via PHP-CS-Fixer with a small strict overlay (`declare(strict_types=1)` enforced, ordered imports, no yoda). PHPStan at level max with `strict-rules` and `bleedingEdge`, no baseline file permitted in the repo.
6. Branching and commits: trunk-based on `main`, conventional commits, squash merges. CHANGELOG generation and release automation are deferred to Phase 13.
7. Licence: MIT. The norm for PHP dev tooling; anything copyleft would gate adoption.

## File tree created in this phase

```
greenlight/
├── .editorconfig
├── .gitattributes              # export-ignore for tests/, docs/, tooling configs
├── .github/
│   ├── workflows/ci.yml
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── ISSUE_TEMPLATE/ (bug.yml, feature.yml)
├── .gitignore
├── .php-cs-fixer.dist.php
├── CONTRIBUTING.md
├── LICENSE
├── README.md                   # already exists; expanded with badges + quick start placeholder
├── SECURITY.md
├── bin/greenlight              # CLI entry point stub
├── composer.json
├── deptrac.yaml
├── docs/
│   ├── PRD.md                  # already exists
│   ├── plan/                   # this plan
│   ├── rfcs/                   # architecture RFCs land here (template included)
│   └── architecture/           # living component docs, one per component as it lands
├── phpstan.dist.neon
├── rector.php
├── src/Core/.gitkeep           # components appear as their phases land
├── tests/
│   ├── Unit/
│   ├── Acceptance/             # end-to-end: run fixture suites, assert on output
│   └── Fixture/                # small self-contained test suites used as inputs
└── tools/bootstrap-runner.php
```

## Key file contents

`composer.json`:

```json
{
    "name": "greenlight/greenlight",
    "description": "An opinionated testing framework for PHP 8.4+: typed attribute-driven tests, parallel-first execution with flat memory, lifecycle-safe test doubles.",
    "license": "MIT",
    "type": "library",
    "keywords": ["testing", "test-framework", "parallel", "mocking", "coverage"],
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "deptrac/deptrac": "^4.6",
        "ergebnis/composer-normalize": "^2.47",
        "php-cs-fixer/shim": "^3.75",
        "php-parallel-lint/php-parallel-lint": "^1.4",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "rector/rector": "^2.0"
    },
    "suggest": {
        "ext-pcntl": "Process control for the parallel runner (POSIX)",
        "ext-sockets": "Orchestrator/worker communication",
        "ext-pcov": "Fast line coverage collection"
    },
    "autoload": {
        "psr-4": { "Greenlight\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Greenlight\\Tests\\": "tests/" }
    },
    "bin": ["bin/greenlight"],
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "ergebnis/composer-normalize": true
        }
    },
    "scripts": {
        "code-style:check": "php-cs-fixer fix --dry-run --diff --ansi",
        "code-style:fix": "php-cs-fixer fix --diff --ansi",
        "deptrac": "deptrac analyse --no-progress",
        "lint": ["parallel-lint src tests tools bin"],
        "phpstan": "phpstan analyse --ansi --no-progress --memory-limit=-1",
        "rector:check": "rector --dry-run --ansi --no-progress-bar",
        "rector:fix": "rector --ansi --no-progress-bar",
        "static-analysis": [
            "@composer validate --strict",
            "@composer normalize --dry-run",
            "@lint",
            "@code-style:check",
            "@phpstan",
            "@rector:check",
            "@deptrac"
        ],
        "tests": "php tools/bootstrap-runner.php tests/Unit tests/Acceptance"
    }
}
```

The `tests` script is redefined in Phase 12 to `bin/greenlight`. Nothing else changes at cutover, which is the point.

`.github/workflows/ci.yml` (shape, not final YAML):

- Triggers: `pull_request`, `merge_group`, `push` to `main`. Concurrency group keyed on head ref with cancel-in-progress.
- Job `ci`, matrix over PHP `8.4` and `8.5` and dependency sets `lowest`/`highest`/`locked` (via `ramsey/composer-install`), `fail-fast: false`.
- Steps: checkout, `shivammathur/setup-php` (with `pcov`, `pcntl`, `sockets`), install deps, then `composer static-analysis` (locked deps only, one PHP version) and `composer tests` (all cells).
- A second job `memory` is added in Phase 11 (the 10,000-test flat-memory gate); a placeholder comment marks where.

`deptrac.yaml` layers (the architecture, encoded): `Core` depends on nothing. `Config`, `Discovery`, `Expect`, `Capture`, `Harness`, `Doubles`, `Coverage` depend only on `Core`. `Plugin` depends on `Core` and `Harness`. `Reporting` depends on `Core`. `Runner` (orchestrator + worker) is the only layer allowed to depend on everything, because it composes the system. `Cli` depends on `Runner` and `Config`. Violations fail CI; there is no baseline.

`bin/greenlight` stub: shebang + strict types, checks PHP version >= 8.4 with a friendly error, locates the autoloader (project-local vs dependency install), prints name/version/help for `--version`/`--help`, exits 64 for unknown commands. Real command dispatch replaces the stub in Phase 2.

`CONTRIBUTING.md` covers: PHP 8.4 requirement, `composer static-analysis && composer tests` before pushing, conventional commit format, the no-baseline PHPStan rule, the zero-runtime-dependency rule, and where RFCs live. `SECURITY.md` gives a private disclosure route.

`.gitignore`: `/vendor/`, `.php-cs-fixer.cache`, `/.greenlight/` (runtime caches: timings, proxies), `.idea/`. The `composer.lock` file is committed; `lowest`/`highest` CI cells cover the library consumer story.

## Validation

- Fresh clone on PHP 8.4: `composer install && composer static-analysis && composer tests` all green (tests trivially green: one placeholder test class proving the bootstrap runner executes and fails on a failing test).
- `bin/greenlight --version` prints a version string; unknown flags exit non-zero.
- CI matrix green on 8.4 and 8.5, all three dependency sets.
- `composer validate --strict` and `composer normalize --dry-run` pass.
