# Code conventions

These conventions apply to new and materially changed Greenlight modules. Some
code predates a rule. Do not change unrelated code only to make it conform.

In this document, uppercase **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**,
and **MAY** specify normative requirements.

## Technical prose

Repository-owned technical prose **MUST** comply with
[the technical writing standard](technical-writing.md). The standard applies
ASD-STE100 Issue 9 to documentation, PHPDoc, comments, contributor material,
accessibility copy, diagnostics, CLI help, and human-readable output.

Authors **MUST** manually review the meaning and the controlled vocabulary.
They **MUST** also review active voice, verbal `-ing` forms, and instruction
structure. Automated checks cannot certify these requirements.

Marketing copy **MUST** use STE clarity principles. It **MAY** use words that
are not in the controlled vocabulary.

Uppercase normative tokens are an approved exception to the controlled
vocabulary.

Code identifiers **MUST** use American English spelling.

## Exceptions

Modules **SHOULD** expose one exception class at each caller seam.

Each exception class **MUST** use one of these name forms:

* `<Component>Error`
* A domain-specific name that ends in `Error` or `Failed`

Named constructors **SHOULD** represent repeated failure modes.
`DiscoveryError` and `ConfigFileError` are the reference examples.

Small validation guards inside value objects **MAY** throw inline. This option
avoids a named constructor.

Exception base classes **MUST** match their meaning:

* Code **MUST** use `\InvalidArgumentException` for malformed input found during
  construction or configuration.
* Code **MUST** use `\RuntimeException` for failures that depend on runtime
  state. Examples include files, processes, and wire payloads.
* Code **MUST** use `\LogicException` for internal framework misuse that
  indicates a Greenlight defect.

`ExpectationFailed` and `SkipTest` are deliberate control-signal exceptions.
They are public interfaces and extend `\Exception`. The runner interprets them.
Internal exception types **MUST NOT** use them as templates.

Every exception class docblock **MUST** contain at least one prose sentence.
This sentence **MUST** identify the condition that causes the exception.

Exception class docblocks **MUST** include `@internal` unless the exception is
public API.

## Error messages

Error messages **MUST** use sentence case.

Greenlight error messages **MUST** end with a period. If a message contains text
from another throwable, preserve that text and its punctuation.

An error message **MUST** enclose an interpolated identifier in double quotes:

```php
'Configuration file "%s" does not exist.'
```

The PHP string literal itself **SHOULD** stay single-quoted.

If a short corrective action exists, the message **SHOULD** include it. Name the
applicable fix, flag, or method.

## Value objects

Value objects **SHOULD** use `final readonly` classes and promoted constructor
properties.

Code **SHOULD NOT** demote a property unless it needs runtime validation to
protect a narrow PHPDoc type.

Constructor validation **MUST** throw `\InvalidArgumentException`.

Wire deserialization **MUST** throw `InvalidWirePayload` through the `Wire`
readers.

Types that cross the wire **MUST** implement `WireSerializable`.

Wire payloads **MUST** use explicit key names.

Wire payloads **MUST** survive a JSON round trip.

## Docblocks

Class docblocks **SHOULD** be one to three prose sentences.

Class docblocks **MUST** state the class purpose. They **MUST** also state each
constraint that types cannot express.

Unless a class is public, its docblock **MUST** contain `@internal` after a
blank line.

Code comments and docblocks **MUST NOT** refer to design documents, plan files,
or phase numbers. They **MUST** state the applicable constraint directly.

## Tests

Test method names **MUST** use sentence-style camelCase. Each name **MUST**
describe the behavior:

```php
bailStopsTheRunAfterTheThreshold
```

Assertions **SHOULD** use `Greenlight\Expect`.

Tests **MUST NOT** create an array only to group independent expectation
subjects. Tests **MUST** give each subject to `Expect::that()` directly.

Tests **MAY** compare an array when the behavior produces the array. Examples
include wire payloads and ordered sequences.

Tests **MUST** use `Greenlight\Tests\Support\Check` only when `Expect` cannot
test itself.

Fixture directories under `tests/Fixture/` **SHOULD** cover one behavior each.

When another suite depends on a fixture directory, contributors **MUST** treat
that directory as append-only.
