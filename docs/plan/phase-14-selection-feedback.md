# Phase 14: test selection and run feedback

| | |
|---|---|
| **Track** | post-GA, track H (parallelisable: filter, rerun cache, and slow report are independent once the flag surface is agreed) |
| **Unblocked by** | Phase 13 |
| **PRD sections** | 5.3 (flow-control attributes), 7.2 (flow control), 10 (output and reporting) |
| **Writes to** | `src/Cli/`, `src/Discovery/` (filter engine), `src/Reporting/`, `tests/` |

## Goals

The three everyday selection and feedback tools a developer reaches for within the first hour: run one test by name, re-run what failed last time, and see where the time went. All three are additive; no existing behaviour changes.

## Key tasks

- `--filter=<pattern>` selects tests by id. The pattern matches against the fully qualified `Class::method` id (and the data-set label when present), case insensitively, with `*` as the only wildcard. No PCRE surface: a wildcard-only grammar stays explainable in one help line and avoids delimiter escaping pain. Repeatable; multiple patterns union. Implemented in the existing `Discovery` filter engine next to group filtering, so plan-time expansion and the zero-tests exit-code rule apply unchanged.
- `--failed` re-runs only the tests that did not pass in the previous run. Each run writes its failure set (test ids, wire format) to a state file under the system temp dir keyed by the working-directory hash, the same convention the proxy cache uses. `--failed` with no state file is a usage error with a clear message rather than a silent full run. The state file is written on every run, including watch iterations, so watch and CLI stay consistent.
- Failed-first ordering for free: when `--failed` is not passed but state exists, plan ordering puts previously failed classes first, reusing `PlanPriority` from watch mode. This is ordering only; selection is unchanged.
- A slow-test report in the summary: the ten slowest tests with durations, printed by the tty and plain reporters after the summary block when the run had at least one test slower than a threshold (default 200 ms, so fast suites stay noise-free). Data comes from the existing event stream; no new events.

## Deliverables

`--filter`, `--failed`, and the slow-test block working against the self-hosted suite; help text, configuration docs, and changelog updated.

## Design decisions

- Wildcard grammar, not PCRE. PHPUnit's `--filter` accepts regex and the escaping confuses more than it helps. `*` covers the real use cases (method prefix, class suffix, data-set label).
- The rerun state lives in the temp dir, not the project tree. The project stays untouched, matching the proxy cache decision, and a lost state file costs one full run.
- Failure state is written unconditionally rather than behind a flag, so `--failed` always has fresh data and watch failed-first keeps working when a session alternates between watch and plain runs.
- The slow report is reporter output, not a new reporter. It belongs to the human-facing formats; machine formats (junit, jsonl) already carry per-test durations.

## Dependencies

Phase 13 shipped the surfaces this extends. No new components.

## Risks

Filter semantics with data sets: a pattern matching a method must select all its data rows, and a pattern matching one row label must select exactly that row. The filter engine tests must pin both.

## Validation

- Unit tests for the pattern matcher (table-driven: pattern, id, match) including data-set labels and case insensitivity.
- Acceptance: a fixture with a known failure, run once, then `--failed` runs exactly the failed test; `--filter` selects by method, by class, and by wildcard; the slow report appears for a deliberately slow fixture and stays absent for fast ones.
