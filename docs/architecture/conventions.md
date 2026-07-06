# Code conventions

Established at the first convergence review, after the Phase 1 fan-out tracks merged. New components follow these; drift found at a convergence review gets fixed at the review, not debated.

## Exceptions

- One exception class per component boundary, named `<Component>Error` or a domain word plus `Error`/`Failed`, with a named constructor per failure mode (`DiscoveryError`, `ConfigFileError` are the models). Single-use validation guards inside value objects may throw inline instead of adding a factory.
- Base class by semantics: `\InvalidArgumentException` for malformed input caught at construction or configuration time; `\RuntimeException` for failures depending on runtime state (files, processes, wire payloads); `\LogicException` for framework-internal misuse that indicates a bug in Greenlight itself.
- `ExpectationFailed` is the one deliberate exception to all of this: public API, extends `\Exception`, and its shape is frozen. It is not a template for internal types.
- Every exception class docblock carries at least one prose sentence saying when it is raised, then `@internal` (unless it is public API).

## Error messages

- Sentence case, trailing period.
- Interpolated identifiers are double-quoted: `'Config file "%s" does not exist.'` The PHP string literal stays single-quoted.
- Where a short actionable suffix exists, add it: name the fix, the flag, or the method to call.

## Value objects

- `final readonly` with promoted constructor properties; demote a property only when runtime validation must guard a narrowed phpdoc type.
- Constructor validation throws `\InvalidArgumentException`; wire deserialisation throws `InvalidWirePayload` via the `Wire` readers.
- Wire-crossing types implement `WireSerializable` with explicit key names; payloads must survive a JSON round trip.

## Docblocks

- Class docblocks: one to three sentences of prose stating purpose and any constraint the types cannot express, then a blank line, then `@internal` unless the class is on the public surface list in docs/plan/README.md rule 4.
- No references to RFCs, the PRD, plan files, or phase numbers anywhere in code comments; state the constraint itself.

## Tests

- Test method names are sentence-style camelCase describing the behaviour (`bailStopsTheRunAfterTheThreshold`).
- Assertions use `Greenlight\Expect`; `Greenlight\Tests\Support\Check` appears only where Expect cannot test itself.
- Fixture directories under `tests/Fixture/` are one behaviour per directory and append-only once other suites depend on them.
