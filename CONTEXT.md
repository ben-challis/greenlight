# Greenlight

This context defines the test-support terms that have different lifecycle
meanings in Greenlight.

## Language

**Sandbox**:
A test facility that owns temporary state for one test. It restores or removes
the state after the test.
_Avoid_: Built-in fixture

**Test fixture**:
Test support code or data that exercises one controlled behavior.
_Avoid_: Sandbox

**Integration fixture**:
External infrastructure that belongs to one Greenlight run.
_Avoid_: Sandbox, test fixture
