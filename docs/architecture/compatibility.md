# Compatibility and public interfaces

The `Greenlight\` Composer namespace makes implementation classes available to
the PHP autoload mechanism. This availability is not a compatibility promise.
Only documented interfaces and versioned formats have compatibility promises.

## Contract levels

| Surface | Compatibility status |
| --- | --- |
| Documented attributes, configuration builders, expectations, fixtures, doubles, attachments, conditions, and plugin interfaces | Public PHP interface |
| Documented CLI commands, options, exit meanings, and configuration-file shape | Public command interface |
| JSONL reporter | Public, versioned data interface |
| Coverage JSON export | Public, versioned data interface |
| JUnit, TeamCity, and GitHub output | External integration interfaces |
| Classes or members marked `@internal` | Internal implementation |
| Worker protocol, hidden `__worker` command, staging files, caches, and run-state files | Internal implementation |

An `@internal` marker explicitly excludes a symbol from compatibility. An
undocumented implementation class does not become public when this marker is
absent. When Greenlight intends a symbol for users or plugin authors, document
it in the user guide. Omit `@internal` from that symbol.

## PHP and CLI changes

Make a compatibility decision before you change a public PHP interface. This
rule applies when a change removes or renames a symbol. It also applies to
narrower inputs, changed return types, changed thrown types, changed lifecycle
order, or changed documented semantics.

Apply the same rule to the CLI. A new optional flag is an additive change. A
renamed flag or a changed default is not additive. Changed match rules or exit
meanings are also not additive. A combination becomes incompatible when the
change makes a valid combination invalid.

Error text gives users an action when possible. It is not a machine protocol
unless the documentation identifies a specific message as a protocol.
Integrations **SHOULD** use exit codes or machine-readable reporters. They
**SHOULD NOT** parse prose.

## JSONL changes

The envelope version selects the complete line schema. Within one JSONL
version:

- Existing event tags, required keys, value types, enum values, and meanings
  stay fixed.
- Greenlight **MAY** add optional payload keys. Consumers **MUST** ignore
  unknown keys.
- Field order is not significant.

These changes require a new envelope version and schema file:

- Remove or rename a key.
- Make a new key required.
- Change a type or meaning.
- Add an event tag.
- Extend a closed enum.

Reserved event tags are an exception. They belong to the current schema before
Greenlight emits them.

For a run that completes normally, `run-started` is the first event.
`run-finished` is the last event. Events from one worker keep their send order.
Events from different workers can interleave by arrival time. Consumers
**MUST NOT** infer a total test order from the stream.

`run-started.plannedTests` is the selected plan size. It does not promise that
every test will finish. Bail, interruption, or a run-level protocol failure can
stop execution early.

An external process stop can leave an incomplete final line and no
`run-finished`. Line-oriented consumers **SHOULD** commit only complete objects
that end with a newline.

## Coverage JSON changes

Coverage JSON version 1 **MAY** receive optional top-level or per-file fields.
Readers **MUST** ignore unknown keys. They **MUST** calculate derived totals
from `covered` and `uncovered`.

A change to path semantics or line-status meaning requires a new version. A
change to required fields or existing field types also requires a new version.
For example, v1 cannot change from absolute paths to project-relative paths.

## Paths and portability

These public formats intentionally report absolute paths:

- Coverage JSON keys
- Failure and diagnostic source locations in JSONL
- Published attachment paths and the run artifact directory

Absolute paths help users find files on the source machine. These paths also
contain machine-local data. Coverage baselines compare a file only when
its absolute keys match.

Different checkout roots, containers, or worktrees need the same mounted path.
As an alternative, normalize both reports before you compare them.

Paths can disclose the workspace layout. Give machine-readable reports the same
access controls as logs and test evidence.

## Internal protocol changes

The protocol version detects peer-version mismatches. Frame and payload
decoders reject malformed protocol data. The protocol does not support
independent upgrades. The orchestrator and worker always come from the same
Greenlight installation.

Protocol payloads can change without a public compatibility cycle. For each
change, update both sides and their protocol tests together.

Private staging metadata and cache files support recovery within one run or
installation. External tools **MUST NOT** consume these files. Put required
information in a documented and versioned format.
