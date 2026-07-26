# Contribute to Greenlight

Thank you for your interest in Greenlight. These rules apply to every change.

## Requirements

Greenlight requires PHP 8.4 or later.

## Technical prose

Use the [technical writing standard](docs/architecture/technical-writing.md)
for all repository-owned technical prose. The standard applies ASD-STE100 Issue
9 to documentation, PHPDoc, comments, contributor material, accessibility text,
diagnostics, CLI help, and human-readable output.

Use uppercase `MUST`, `MUST NOT`, `SHOULD`, `SHOULD NOT`, and `MAY` as
normative tokens. Use STE clarity principles for promotional copy. The
controlled vocabulary is optional for promotional copy.

Before you push prose changes, review the prose:

- Check each word for its approved definition.
- Check technical prose for the controlled vocabulary.
- Use active voice when you know the agent.
- Review each `-ing` form. Correct each verbal use.
- Put only one instruction in each sentence.

## Before you push

Run:

```bash
composer static-analysis
composer tests
make docs-check
```

CI runs the same checks. All three MUST pass locally.

## Commits and pull requests

Submit changes in pull requests to `main`.

Greenlight uses squash merges. The pull request title becomes the commit
message. It MUST use the
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
Add a scope if it gives useful context.

## IDE completion for the PHPStan API

The `phpstan/phpstan` development dependency is a PHAR. Editors cannot index
the PHPStan classes in `src/PhpStan/` directly.

`composer install` and `composer update` extract the API sources into
`.phpstan-api-stubs/`. Editors use these sources for IDE completion. Git ignores
this directory. Greenlight does not execute its contents.

If completion for `PHPStan\` symbols is absent, run:

```bash
composer phpstan:stubs
```

## Zero runtime dependencies

Greenlight is a development dependency. A runtime dependency can conflict with
the project under test. Implement each necessary capability in Greenlight.

You MAY add development dependencies for tools. You MUST NOT add runtime
dependencies.

## Questions

Open an issue. For a feature proposal, use the feature issue template. Include
a proposed API sketch.
