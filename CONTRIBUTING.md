# Contributing to Greenlight

Thank you for your interest in contributing. These rules apply to every change.

## Requirements

Greenlight requires PHP 8.4 or later.

## Before you push

Run:

```bash
composer static-analysis && composer tests
```

CI runs the same scripts, so both must pass locally.

## Commits and pull requests

Submit changes through pull requests targeting `main`.

Pull requests are squash merged, so the pull request title becomes the commit message and must follow the [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) format:

```text
type(scope): short description
```

Examples:

```text
feat(expect): add toContain matcher for iterables
fix(runner): close worker sockets on orchestrator shutdown
docs: clarify the channel contract in the README
refactor(doubles): extract proxy generation from double factory
test(discovery): cover nested fixture directories
chore: bump php-cs-fixer to 3.76
```

Common types include `feat`, `fix`, `docs`, `refactor`, `test`, and `chore`. Add a scope when it provides useful context.

## IDE completion for the PHPStan API

The `phpstan/phpstan` development dependency is distributed as a PHAR, so editors cannot index the PHPStan classes implemented by `src/PhpStan/`.

`composer install` and `composer update` extract the API sources into `.phpstan-api-stubs/` for IDE indexing. This directory is ignored by Git and is never executed.

If completion for `PHPStan\` symbols is missing, run:

```bash
composer phpstan:stubs
```

## Zero runtime dependencies

Greenlight is installed as a development dependency, so runtime dependencies could conflict with the project under test. If a capability is needed, implement it within Greenlight.

Development dependencies for tooling are allowed. Runtime dependencies are not.

## Questions

Open an issue. For feature proposals, use the feature issue template and include a proposed API sketch.
