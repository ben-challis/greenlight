# Compatibility and public interfaces

The `Greenlight\` Composer namespace exposes implementation classes to the PHP
autoloading mechanism, but autoloadability is not a compatibility promise.
Compatibility follows documented interfaces and versioned formats.

## Contract levels

| Surface | Compatibility status |
| --- | --- |
| Documented attributes, configuration builders, expectations, fixtures, doubles, attachments, conditions, and plugin interfaces | Public PHP interface |
| Documented CLI commands, options, exit meanings, and config-file shape | Public command interface |
| JSONL reporter | Public, versioned data interface |
| Coverage JSON export | Public, versioned data interface |
| JUnit, TeamCity, and GitHub output | External integration interfaces |
| Classes or members marked `@internal` | Internal implementation |
| Worker protocol, hidden `__worker` command, staging files, caches, and run-state files | Internal implementation |

An `@internal` marker explicitly excludes a symbol from compatibility. Its
absence alone does not make an otherwise undocumented implementation class
public. When a symbol is intended for users or plugin authors, document it in
the user guide and omit `@internal`.

## PHP and CLI changes

Changes to a public PHP interface require a compatibility decision when they
remove or rename a symbol, narrow accepted input, change a return or thrown
type, alter lifecycle ordering, or change documented semantics.

The same applies to the CLI. Adding an optional flag is additive. Renaming a
flag, changing its default or matching rules, changing a command's exit
meaning, or making formerly valid combinations invalid is not additive.

Error text is designed to be actionable but is not a machine protocol unless a
specific message is documented as such. Integrations should use exit codes or
machine-readable reporters instead of parsing prose.

## JSONL changes

The envelope version selects the whole line schema. Within one JSONL version:

- existing event tags, required keys, value types, enum values, and meanings
  stay fixed;
- optional payload keys may be added, and consumers must ignore unknown keys;
- field order is not significant.

Removing or renaming a key, making a new key required, changing a type or
meaning, adding an event tag, or extending a closed enum requires a new
envelope version and a new schema file. Reserved event tags are the exception:
they already belong to the current schema even when Greenlight does not emit
them yet.

On a normally completed run, `run-started` is the first event and
`run-finished` is the last. Events from one worker retain their send order, but
events from different workers interleave by arrival time. Consumers must not
infer a total test order from the stream.

`run-started.plannedTests` is the selected plan size, not a promise that every
test will finish. Bail, interruption, or a run-level protocol failure can stop
execution early. A process killed outside Greenlight may leave a truncated
final line and no `run-finished`; line-oriented consumers should commit only
complete newline-terminated objects.

## Coverage JSON changes

Coverage JSON version 1 may gain optional top-level or per-file fields. Readers
must ignore unknown keys and recompute derived totals from `covered` and
`uncovered`.

Changing path semantics, line-status meaning, required fields, or existing
field types requires a new version. In particular, changing v1 from absolute
paths to project-relative paths cannot be done in place.

## Paths and portability

Several public formats intentionally report absolute paths:

- coverage JSON keys;
- failure and diagnostic source locations in JSONL;
- published attachment paths and the run artifact directory.

Absolute paths make reports immediately actionable on the producing machine,
but they are machine-local data. Coverage baselines only compare the same file
when their absolute keys match, so different checkout roots, containers, or
worktrees need the same mounted path or an explicit normalization step.

Paths can also disclose workspace layout. Treat machine-readable reports as
build artifacts with the same access controls as logs and test evidence.

## Internal protocol changes

The worker protocol is versioned to detect mixed or malformed peers, not to
support independent upgrades. The orchestrator and worker always come from the
same Greenlight installation. Protocol payloads may change without a public
compatibility cycle, provided both sides and their protocol tests change
together.

Likewise, private staging metadata and cache files exist for recovery within one
run or installation. External tools must not consume them. Promote information
to a documented, versioned format instead of depending on those files.
