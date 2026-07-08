# Contributing to Greenlight

Thanks for your interest in contributing. This document covers the rules that apply to every change.

## Requirements

Greenlight requires PHP 8.4 or later. Development happens on the same version range the framework targets, so make sure your local PHP is at least 8.4.

## Before you push

Run both composer scripts and make sure they are green:

```bash
composer static-analysis && composer tests
```

CI runs exactly the same scripts, so if they pass locally they should pass in CI.

## Commits and branching

Development is trunk-based on `main`. Pull requests are squash merged, so the pull request title becomes the commit message and must follow the conventional commit format:

```
type(scope): short description
```

Examples:

```
feat(expect): add toContain matcher for iterables
fix(runner): close worker sockets on orchestrator shutdown
docs(rfcs): clarify wire protocol framing in RFC-003
refactor(doubles): extract proxy generation from double factory
test(discovery): cover nested fixture directories
chore: bump php-cs-fixer to 3.76
```

Common types are `feat`, `fix`, `docs`, `refactor`, `test`, and `chore`. Use a scope matching the component you touched where it helps.

## IDE completion for the PHPStan API

The `phpstan/phpstan` dev dependency ships as a phar, so editors cannot index the PHPStan classes that `src/PhpStan/` implements. To fix that, `composer install` and `composer update` extract the PHPStan API sources from the phar into `.phpstan-api-stubs/`, which your IDE indexes like any other project directory. The directory is gitignored and never executed; PHPStan loads the real classes from the phar at analysis time. If completion for `PHPStan\` symbols is missing, run `composer phpstan:stubs` to regenerate it.

## No baselines

PHPStan runs at level max with strict rules and deptrac enforces component boundaries. Neither tool may have a baseline file in this repository, ever. If your change introduces a violation, fix the violation rather than suppressing it. Pull requests that add a baseline will be rejected.

## Zero runtime dependencies

The `require` section of `composer.json` contains only `php`. Greenlight is installed into the dev dependencies of every project that uses it, so any runtime dependency it carries becomes a potential version conflict with the code under test. If your change needs a capability, we own the code. Dev dependencies for tooling are fine; runtime dependencies are not.

## RFCs

Architecture RFCs live in `docs/rfcs/`. Any change that adds, removes, or alters a public or cross-component interface needs an RFC before implementation. Small internal changes within a single component do not. If you are unsure whether your change crosses that line, open an issue and ask before writing code.

## Questions

Open an issue. For feature ideas, the feature issue template asks for a proposed API sketch and whether you are willing to write an RFC.
