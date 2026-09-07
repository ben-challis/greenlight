# Code conventions

These conventions apply to new and materially changed Greenlight modules. Some
code predates a rule. Do not change unrelated code only to make it conform.

Direct instructions define requirements. Recommendations identify preferred
approaches. Statements with `can` describe options.

## Technical prose

Follow [the technical writing policy](technical-writing.md) for repository-owned
technical prose. It applies to documentation, PHPDoc, comments, contributor
material, accessibility text, diagnostics, CLI help, and human-readable output.
Do not claim formal ASD-STE100 compliance without a complete review.

Review the meaning and the controlled vocabulary manually.
Also review active voice, verbal `-ing` forms, and instruction structure.
Automated checks cannot certify these requirements.

Use STE clarity principles for marketing copy. This copy can use words outside
the controlled vocabulary.

Preserve normative terms in formal specifications and protocol requirements.

Use American English spelling in code identifiers.

## Exceptions

We recommend one exception class at each caller seam.

Use one of these name forms for each exception class:

* `<Component>Error`
* A domain-specific name that ends in `Error` or `Failed`

We recommend named constructors for repeated failure modes.
`DiscoveryError` and `ConfigFileError` are the reference examples.

Small validation guards inside value objects can throw inline. This option
avoids a named constructor.

Select the exception base class that matches its meaning:

* Use `\InvalidArgumentException` for malformed input found during
  construction or configuration.
* Use `\RuntimeException` for failures that depend on runtime
  state. Examples include files, processes, and wire payloads.
* Use `\LogicException` for internal framework misuse that
  indicates a Greenlight defect.

`ExpectationFailed` and `SkipTest` are deliberate control-signal exceptions.
They are public interfaces and extend `\Exception`. The runner interprets them.
Do not use them as templates for internal exception types.

Write at least one prose sentence in every exception class docblock.
Identify the condition that causes the exception.

Unless the exception is public API, include `@internal` in its class docblock.

## Error messages

Use sentence case in error messages.

End Greenlight error messages with a period. If a message contains text
from another throwable, preserve that text and its punctuation.

In an error message, enclose an interpolated identifier in double quotes:

<!-- php-example {"mode":"display","reason":"Shows an error-message template rather than an executable statement."} -->
```php
'Configuration file "%s" does not exist.'
```

We recommend single quotes around the PHP string literal itself.

If a short corrective action exists, we recommend that the message include it.
Name the applicable fix, flag, or method.

## Value objects

We recommend `final readonly` classes and promoted constructor properties for
value objects.

We recommend that properties stay promoted unless runtime validation must
protect a narrow PHPDoc type.

Throw `\InvalidArgumentException` for constructor validation failures.

Use the internal wire readers to throw `WireCommunicationFailed` for wire
deserialization failures.

Define `toWire()` and `fromWire()` methods on types that cross the wire.
On public types, mark these methods `@internal`.

Use explicit key names in wire payloads.

Keep wire payloads valid through a JSON round trip.

## Docblocks

We recommend one to three prose sentences in class docblocks.

State the class purpose in its docblock. Also state each constraint that types
cannot express.

Unless a class is public, include `@internal` after a blank line in its docblock.

Do not refer to design documents, plan files, or phase numbers in code comments
and docblocks. State the applicable constraint directly.

## Tests

Use sentence-style camelCase for test method names. Describe the behavior in
each name:

<!-- php-example {"mode":"display","reason":"Shows a test ID rather than an executable statement."} -->
```php
bailStopsTheRunAfterTheThreshold
```

We recommend `Greenlight\Expect\Expect` for assertions.

Do not create an array only to group independent expectation subjects.
Give each subject to `Expect::that()` directly.

Tests can compare an array when the behavior produces the array. Examples
include wire payloads and ordered sequences.

For expectation failure details, use `FailureProbe` in `tests/Unit/Expect/`.
This helper captures `ExpectationFailed::detail()` so assertions can inspect it.

We recommend one behavior for each fixture directory under `tests/Fixture/`.

When another suite depends on a fixture directory, treat that directory as
append-only.
