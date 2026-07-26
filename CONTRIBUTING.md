# Contributing to Greenlight

Thank you for your interest in contributing. These rules apply to every change.

## Requirements

Greenlight requires PHP 8.4 or later.

## Technical writing

Follow the [technical writing standard](docs/architecture/technical-writing.md)
for all repository-owned technical prose. The standard applies ASD-STE100
Issue 9 to documentation, PHPDoc, comments, contributor material,
accessibility copy, diagnostics, CLI help, and human-readable output.

Uppercase `MUST`, `MUST NOT`, `SHOULD`, `SHOULD NOT`, and `MAY` are approved
normative tokens. Marketing copy must use STE clarity principles, but it does
not require the controlled vocabulary.

Before you push prose changes, do a manual review:

- Confirm that each word has the approved meaning.
- Confirm that technical prose uses the controlled vocabulary.
- Use active voice when you know the agent.
- Check each `-ing` form and correct verbal uses.
- Put only one instruction in each sentence.

## Before you push

Run:

```bash
composer static-analysis
composer tests
make docs-check
```

CI runs the same checks, so all three must pass locally.

## Commits and pull requests

Submit changes through pull requests targeting `main`.

Greenlight uses squash merges. The pull request title becomes the commit
message. It must follow the
[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/)
format:

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

Common types include `feat`, `fix`, `docs`, `refactor`, `test`, and `chore`.
Add a scope when it provides useful context.

## IDE completion for the PHPStan API

The `phpstan/phpstan` development dependency is a PHAR. Editors cannot index
the PHPStan classes in `src/PhpStan/`.

`composer install` and `composer update` extract the API sources into
`.phpstan-api-stubs/` for IDE indexing. Git ignores this directory. Greenlight
does not execute its contents.

If completion for `PHPStan\` symbols is missing, run:

```bash
composer phpstan:stubs
```

## Zero runtime dependencies

Greenlight is a development dependency. A runtime dependency can conflict
with the project under test. Implement each necessary capability in
Greenlight.

Development dependencies for tooling are allowed. Runtime dependencies are not.

## Questions

Open an issue. For a feature proposal, use the feature issue template. Include
a proposed API sketch.
