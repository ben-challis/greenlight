# Phase 2: fluent configuration system and CLI

| | |
|---|---|
| **Track** | track A (parallel with Phases 3 and 4) |
| **Unblocked by** | RFC-001 (may start once the RFC is drafted) |
| **PRD sections** | 6 (configuration), 7.2 (flow control flags) |
| **Writes to** | `src/Config/`, `src/Cli/`, `bin/greenlight`, `tests/` |

## Goals

`greenlight.php` loading, the typed builder, CLI flag parsing, and the documented precedence chain (defaults < config < CLI).

## Key tasks

- `GreenlightConfig` builder with the PRD section 6 surface: paths, suites, workers, plugins list, coverage, failFast, randomizeOrder.
- An immutable resolved `Configuration` value object.
- Config file locator and loader with clear errors for missing or mistyped files.
- An owned minimal CLI parser (long/short options, zero dependencies) and the `greenlight` command skeleton: `run` as default command, `--help`, `--version`, `list-tests` placeholder.

## Deliverables

`src/Config/`, `src/Cli/`; `bin/greenlight` executes against a real config file and prints the resolved plan-to-be.

## Design decisions

- Builder mutability: the fluent builder is mutable, `build()` produces the immutable object and the loader calls it, so user files stay terse.
- How suites compose filters.
- Config cannot disable attributes globally (YAGNI).

## Dependencies

Phase 1 (config references core types like group names and plugin interfaces only loosely).

## Risks

The builder API is public DX and hard to change later. Mitigation: snapshot-test the builder's public method list; any change to it fails a test and forces a deliberate decision.

## Validation

- Acceptance tests loading fixture config files, asserting resolved `Configuration`.
- Precedence matrix test: default vs config vs CLI for every overridable setting.
