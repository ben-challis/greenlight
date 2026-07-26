# Code conventions

These conventions apply to new and materially changed Greenlight modules.
Existing code may predate a rule; do not churn unrelated code solely to make a
file look conformant.

The key words **MUST**, **MUST NOT**, **SHOULD**, **SHOULD NOT**, and **MAY** are
to be interpreted as normative requirements.

## Exceptions

Modules **SHOULD** expose one exception class at each caller-facing seam.

Exception classes **MUST** be named `<Component>Error`, or use a domain-specific
name ending in `Error` or `Failed`.

Repeated failure modes **SHOULD** be represented by named constructors.
`DiscoveryError` and `ConfigFileError` are the reference examples.

Small validation guards inside value objects **MAY** throw inline instead of
adding a named constructor.

Exception base classes **MUST** be chosen by meaning:

* `\InvalidArgumentException` **MUST** be used for malformed input caught during
  construction or configuration.
* `\RuntimeException` **MUST** be used for failures that depend on runtime state,
  such as files, processes, or wire payloads.
* `\LogicException` **MUST** be used for internal framework misuse that indicates
  a bug in Greenlight.

`ExpectationFailed` and `SkipTest` are deliberate control-signal exceptions.
They are public interface, extend `\Exception`, and are interpreted by the
runner. Internal exception types **MUST NOT** use them as templates.

Every exception class docblock **MUST** include at least one prose sentence
describing when the exception is raised.

Exception class docblocks **MUST** include `@internal` unless the exception is
public API.

## Error messages

Error messages **MUST** use sentence case.

Framework-authored error messages **MUST** end with a period. When a message
embeds text from an underlying throwable, preserve that text rather than
rewriting its punctuation.

Interpolated identifiers **MUST** be wrapped in double quotes:

```php
'Config file "%s" does not exist.'
```

The PHP string literal itself **SHOULD** stay single-quoted.

When a short actionable fix exists, the message **SHOULD** include it. Prefer
naming the fix, flag, or method to call.

## Value objects

Value objects **SHOULD** be `final readonly` classes with promoted constructor
properties.

A property **SHOULD NOT** be demoted unless runtime validation is needed to
protect a narrowed phpdoc type.

Constructor validation **MUST** throw `\InvalidArgumentException`.

Wire deserialization **MUST** throw `InvalidWirePayload` through the `Wire`
readers.

Types that cross the wire **MUST** implement `WireSerializable`.

Wire payloads **MUST** use explicit key names.

Wire payloads **MUST** survive a JSON round trip.

## Docblocks

Class docblocks **SHOULD** be one to three prose sentences.

Class docblocks **MUST** state the class purpose and any constraint that cannot
be expressed in types.

Class docblocks **MUST** then include a blank line followed by `@internal`,
unless the class is part of the public surface.

Code comments and docblocks **MUST NOT** refer to design documents, plan
files, or phase numbers. They **MUST** state the relevant constraint directly.

## Tests

Test method names **MUST** use sentence-style camelCase and describe the
behaviour:

```php
bailStopsTheRunAfterTheThreshold
```

Assertions **SHOULD** use `Greenlight\Expect`.

`Greenlight\Tests\Support\Check` **MUST** be used only where `Expect` cannot test
itself.

Fixture directories under `tests/Fixture/` **SHOULD** cover one behaviour each.

Once another suite depends on a fixture directory, that directory **MUST** be
treated as append-only.
