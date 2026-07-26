# Repository guidance

- Use PHP 8.4 or a later version. Greenlight MUST have zero runtime dependencies.
- Obey `docs/architecture/conventions.md` and the dependency boundaries in `deptrac.yaml`.
- Use `docs/architecture/technical-writing.md` for all repository-owned technical prose.
- Technical prose includes documentation, PHPDoc, comments, contributor material, accessibility text, diagnostics, CLI help, and human-readable output.
- Use ASD-STE100 Issue 9 for technical prose.
- Use uppercase `MUST`, `MUST NOT`, `SHOULD`, `SHOULD NOT`, and `MAY` as normative tokens.
- Use STE clarity principles for promotional copy. The controlled vocabulary is optional for promotional copy.
- Add focused unit or acceptance tests. Use `Greenlight\Expect`. Treat shared fixture directories as append-only.
- Run focused tests with `php bin/greenlight run --filter=<test-id>`.
- Before you complete PHP changes, run `composer static-analysis && composer tests`.
- For website changes, run `make docs-check`.
- Use Conventional Commits for commit messages and pull request titles.
