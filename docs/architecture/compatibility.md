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

## Release impact

A change has release impact when it changes a public interface in this
document. Assess the public behavior. Do not assess only the source file or
namespace.

An internal change is not a breaking change when all public interfaces stay
compatible. This rule includes symbols marked `@internal`, the worker protocol,
and private files. If an internal change alters public behavior, assess that
behavior.

### Feature

A feature adds a documented user capability. It keeps all valid documented
uses valid.

Examples include a new matcher, attribute, optional configuration value,
reporter, CLI option, or plugin capability. A new internal abstraction is not a
feature by itself.

Use `feat(scope): description` for a feature.

### Fix

A fix makes Greenlight agree with a current public contract or intended result.
The defect must affect public behavior.

A fix can correct behavior that the public interfaces do not promise. Such a
correction is compatible, even when a user has observed the incorrect behavior.

An internal code change can be a fix when it corrects public behavior. A test
change that only improves coverage is not a fix.

Use `fix(scope): description` for a fix.

### Performance change

A performance change reduces time, memory, or resource use. It does not change
a documented result or make a valid use invalid.

Use `perf(scope): description` for this change.

### Breaking change

A breaking change makes at least one valid documented use invalid. The changed
code does not have to be in a public class.

These changes are breaking:

- Remove or rename a public symbol, command, option, key, tag, or event.
- Add a required argument, method, configuration value, or data field.
- Make an accepted input invalid.
- Change a documented default, return meaning, thrown type, lifecycle order,
  match rule, or exit meaning incompatibly.
- Change a versioned format without its required version change.
- Increase the minimum supported PHP version.

A large internal rewrite is not breaking when all public interfaces stay
compatible. Test size, code size, and implementation difficulty do not
determine release impact.

Put `!` before the colon in the pull request title. In the pull request body,
identify the old valid use and its replacement.

For example:

```text
feat(expect)!: require a timeout for temporal expectations
```

### Version effect

Greenlight uses pre-major version rules before version `1.0.0`.

| Change | Before `1.0.0` | From `1.0.0` |
| --- | --- | --- |
| Fix or performance change | Patch | Patch |
| Feature | Patch | Minor |
| Breaking change | Minor | Major |

The `docs`, `refactor`, `test`, `ci`, `build`, `style`, and `chore` types do not
state release impact. Use them only when the change has no release impact.

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

- The schema keeps event tags, required keys, value types, enum values, and
  meanings fixed.
- Greenlight **MAY** add optional payload keys. Consumers **MUST** ignore
  unknown keys.
- Field order is not significant.

These changes require a new envelope version and schema file:

- Remove or rename a key.
- Make a new key required.
- Change a type or meaning.
- Add an event tag.
- Extend a closed enum.

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

A change to path semantics or a line-status definition requires a new version.
A change to a required field or a field type also requires a new version. For
example, v1 cannot change from absolute paths to project-relative paths.

## Paths and portability

These public formats intentionally report absolute paths:

- Coverage JSON keys
- Failure and diagnostic source locations in JSONL
- Published attachment paths and the run artifact directory

Absolute paths help users find files on the source machine. These paths also
contain machine-local data. Coverage baselines compare a file only when
its absolute keys match.

Different checkout roots, containers, or worktrees need the same mounted path.
As an alternative, use `coverage:diff` with `--baseline-root` and
`--current-root`. This command option normalizes paths for one comparison. It
does not change the coverage JSON version 1 contract.

For coverage from multiple roots, use `coverage:merge` with one `--input-root`
for each input and one `--project-root`. This operation writes version 1
absolute paths below the selected output root.

Paths can disclose the workspace layout. Give machine-readable reports the same
access controls as logs and test evidence.

## Internal protocol changes

The protocol version detects peer-version mismatches. Frame and payload
decoders reject malformed protocol data. The protocol does not support
independent upgrades. The orchestrator and worker always use the same Greenlight
installation.

Protocol payloads can change without a public compatibility cycle. For each
change, update both sides and their protocol tests together.

Private staging metadata and cache files support recovery within one run or
installation. External tools **MUST NOT** consume these files. Put required
information in a documented and versioned format.
