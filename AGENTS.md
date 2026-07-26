# Repository guidance

- Greenlight requires PHP 8.4+ and must retain zero runtime dependencies.
- Follow `docs/architecture/conventions.md` and the dependency boundaries in `deptrac.yaml`.
- Add focused unit or acceptance coverage, use `Greenlight\Expect`, and treat shared fixture directories as append-only.
- Run focused tests with `php bin/greenlight run --filter=<test-id>`.
- Before finishing PHP changes, run `composer static-analysis && composer tests`.
- For website changes, run `make docs-check`.
- Use Conventional Commits for commit messages.
