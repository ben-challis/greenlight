# Repository guidance

- Greenlight requires PHP 8.4+ and must retain zero runtime dependencies.
- Follow `docs/architecture/conventions.md` and the dependency boundaries in `deptrac.yaml`.
- Follow `docs/architecture/technical-writing.md` for all repository-owned technical prose.
- Technical prose includes documentation, PHPDoc, comments, contributor material, accessibility copy, diagnostics, CLI help, and human-readable output.
- Use ASD-STE100 Issue 9 for technical prose. Uppercase `MUST`, `MUST NOT`, `SHOULD`, `SHOULD NOT`, and `MAY` are approved normative tokens.
- Use STE clarity principles for marketing copy. The controlled vocabulary is optional in marketing copy.
- Add focused unit or acceptance coverage, use `Greenlight\Expect`, and treat shared fixture directories as append-only.
- Run focused tests with `php bin/greenlight run --filter=<test-id>`.
- Before finishing PHP changes, run `composer static-analysis && composer tests`.
- For website changes, run `make docs-check`.
- Use Conventional Commits for commit messages and pull request titles.
