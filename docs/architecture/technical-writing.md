# Technical writing

Greenlight uses Simplified Technical English principles to make technical prose
clear and consistent. ASD-STE100 Issue 9 is a reference for this policy.
This policy does not certify that project prose complies with the standard.

This policy applies to repository-owned technical prose:

* Markdown documentation
* PHPDoc and code comments
* Contributor and community guidance
* Website technical content and accessibility text
* Command help, diagnostics, error messages, and reports
* Package, schema, and configuration descriptions

Marketing copy follows the clarity principles in this policy. It does not have
to use the controlled vocabulary.

The prose checker finds mechanical errors and possible language problems. It
does not certify compliance with ASD-STE100. A writer must also review the
meaning, vocabulary, grammar, and technical accuracy.

The checker also examines structured text fields, script strings and comments,
and PHP strings that resemble messages. It examines extensionless PHP command
entry points. It checks multiline PHPDoc tag descriptions, visible Markdown
link labels, and website accessibility attributes. It applies only mandatory
rules to PHPDoc tag descriptions and human-readable strings. Review all strings
manually because code literals can resemble prose.

## Writing rules

Use the official ASD-STE100 Issue 9 standard as the primary reference. Apply
these rules to all repository-owned technical prose:

* Use approved words with their approved meaning and part of speech.
* Use the project terms in the technical-term register.
* Use the same term for the same item or action.
* Use American English spelling.
* Use the active voice. Use passive voice only when the agent is unknown.
* Use simple verb forms and tenses.
* Use an `-ing` form only as an approved technical noun or noun modifier.
* Do not use phrasal verbs.
* Do not use contractions.
* Do not use semicolons.
* Write no more than 25 words in a descriptive sentence.
* Write no more than 20 words in an instruction.
* Write one instruction in each sentence.
* Put a necessary condition before its instruction.
* Use the imperative form for instructions.
* Put complex information in a vertical list.
* Give one topic in each paragraph.
* Write no more than six sentences in a paragraph.
* Give information gradually and in a logical order.

Text in parentheses counts as one word. Inline code, quoted text, identifiers,
and proper names also count as one word.

## Normative requirements

Formal specifications and protocol requirements use the uppercase control terms **MUST**, **MUST NOT**,
**SHOULD**, **SHOULD NOT**, and **MAY**. These terms preserve distinct
requirement levels and are explicit project exceptions.

Preserve these terms in formal rules and exact quotations. Do not replace one
control term with another during a language rewrite. This can change a requirement.

In user-facing prose, use direct instructions for requirements. Use explicit
recommendations for preferred actions and `can` for options. Preserve the
requirement level when you rewrite a sentence.

Do not use lowercase modal `should` or `may` in other technical prose. Rewrite
the sentence with an approved construction.

## Literal and machine text

Do not rewrite these items only to comply with this policy:

* Code and API identifiers
* Commands, flags, paths, URLs, and regular expressions
* Schema keys, protocol tags, and machine-readable values
* Exact output samples and test fixture data
* External quotations, publication titles, and proper names
* Legal text in `LICENSE`
* Content in shared append-only fixture directories

Rewrite the technical prose around these items. A human-readable string remains
in scope when its source representation is a PHP string.

The checker excludes code identifiers. Use American English spelling in all
repository-owned identifiers. Update each reference when you correct an
identifier.

## Technical-term register

The register approves Greenlight-specific words for the listed part of speech
and meaning. Use the singular form unless the context requires a plural.

| Term | Type | Approved meaning |
| --- | --- | --- |
| adapter | Technical noun | A component that connects Greenlight to an external tool or execution method |
| artifact | Technical noun | A file or data item that a run produces |
| assignment | Technical noun | A scheduling unit that an orchestrator sends to a worker |
| attachment | Technical noun | Evidence that belongs to one test result |
| attempt | Technical noun | One execution of a test before Greenlight reports a result or starts a retry |
| baseline diff | Technical noun | A comparison of current coverage with a saved coverage map |
| benchmark shape | Technical noun | A generated test-suite structure that one benchmark measures |
| binding | Technical noun | A container rule that maps an ID to a value or construction method |
| boot latency | Technical noun | The time from worker-process creation to the start of its first test class |
| breaking change | Technical noun | A change that makes at least one valid documented use invalid |
| builder | Technical noun | A mutable object that collects configuration for one part of a run |
| capability interface | Technical noun | A plugin interface that adds one defined type of behavior |
| captor | Technical noun | An object that stores argument values from matched mock calls |
| cardinality | Technical noun | The permitted number of calls for one mock interaction |
| channel | Technical noun | A stable resource slot that belongs to one worker |
| condition | Technical noun | A rule that determines if Greenlight skips a test |
| configuration | Technical noun | The settings that control a Greenlight run |
| configurator | Technical noun | A callable that changes a builder |
| container lifetime | Technical noun | The period that one application container belongs to a worker or test attempt |
| consumer | Technical noun | A program or tool that reads Greenlight output |
| coverage driver | Technical noun | A component that collects raw line coverage |
| coverage export | Technical noun | A file or directory that contains coverage results |
| coverage gate | Technical noun | A repository check that enforces a coverage requirement |
| coverage map | Technical noun | Data that identifies executable and covered source lines |
| crash containment | Technical noun | Orchestrator behavior that limits a worker crash to its active test and recovers the remaining assignment |
| data provider | Technical noun | A public static method that supplies named data sets |
| data set | Technical noun | One named set of arguments for a test method |
| data-set | Noun modifier | Related to a data set |
| deep equality | Technical noun | A recursive comparison of values by type, structure, and content |
| diagnostics buffer | Technical noun | Bounded worker output that helps to explain a worker failure |
| discovery | Technical noun | The process that identifies test metadata and makes an execution plan |
| discovery cache | Technical noun | Stored test metadata that Greenlight can reuse for unchanged files |
| double | Technical noun | A test object that replaces a collaborator |
| envelope | Technical noun | A versioned JSONL object that contains one event |
| event | Technical noun | A record of one occurrence in the run lifecycle |
| event tag | Technical noun | A string that selects the event payload shape |
| execution plan | Technical noun | The ordered test metadata that discovery sends for execution |
| expectation | Technical noun | A required condition for a test value |
| extension matcher | Technical noun | A matcher that an expectation extension supplies |
| feature | Technical noun | A documented user capability that keeps all valid documented uses valid |
| fixture | Technical noun | Test support code or data with a controlled purpose |
| fix | Technical noun | A change that corrects a defect in public behavior |
| filter | Technical noun | A set of rules that selects tests or coverage paths |
| flat memory | Technical noun | Memory use that does not increase with the number of completed tests |
| frame | Technical noun | One length-prefixed worker-protocol message |
| harness | Technical noun | The test environment that supplies application services to a worker |
| harness provider | Technical noun | A plugin that supplies services to the Greenlight harness |
| harness service | Technical noun | An object that the harness supplies to a test constructor |
| Hyperf bridge | Technical noun | The component that connects the Greenlight harness to a Hyperf application, container, and coroutine runtime |
| integration fixture | Technical noun | External infrastructure that the orchestrator owns for one run |
| instability | Technical noun | Test behavior that can produce different outcomes without a relevant code change |
| hook | Technical noun | A method or subscriber callback that runs before or after a test |
| interaction | Technical noun | One call from code under test to a double |
| Laravel bridge | Technical noun | The component that connects the Greenlight harness to a Laravel application and container |
| live window | Technical noun | The bounded terminal area that shows active test classes |
| marketing copy | Technical noun | Promotional text that uses STE clarity principles without a controlled-vocabulary requirement |
| matcher | Technical noun | An operation that checks an expectation |
| matcher map | Technical noun | The configured names and signatures of extension matchers |
| memory gate | Technical noun | A repository check that detects memory growth across a long test run |
| mock | Technical noun | A strict double with planned interactions |
| mutant | Technical noun | A source version that contains one deliberate change |
| orchestrator | Technical noun | The process that plans and controls a run |
| output capture | Technical noun | The collection of test output and PHP diagnostics for one result |
| payload | Technical noun | The data that an event envelope contains |
| performance change | Technical noun | A change that reduces resource use without a compatibility change |
| poll | Technical noun | One observation of a probe value |
| poll | Technical verb | To observe a probe until a condition or deadline stops the operation |
| probe | Technical noun | A callable that supplies values to a temporal expectation |
| plugin | Technical noun | An extension that adds a Greenlight capability |
| PSR-11 bridge | Technical noun | The component that connects the Greenlight harness to a PSR-11 container |
| PSR-11 container | Technical noun | A service container that implements the PSR-11 container interface |
| PSR-7 response | Technical noun | An HTTP response that implements the PSR-7 response interface |
| PSR-7 server request | Technical noun | An HTTP server request that implements the PSR-7 server request interface |
| PSR-15 harness | Technical noun | The test environment that sends PSR-7 server requests to a PSR-15 request handler |
| PSR-15 request handler | Technical noun | An object that accepts a PSR-7 server request and returns a PSR-7 response |
| proxy class | Technical noun | A generated class that implements or extends a doubled type |
| proxy object | Technical noun | An instance of a proxy class that acts as a double |
| public behavior | Technical noun | A documented result or effect of a public interface |
| public interface | Technical noun | A documented way that a user or external tool interacts with Greenlight |
| reporter | Technical noun | A component that converts run events to output |
| release impact | Technical noun | The effect of a change on the next version and changelog |
| resource lease | Technical noun | A temporary grant of resource capacity to one scheduling unit |
| resource limit | Technical noun | A limit on concurrent access to a named resource |
| result policy | Technical noun | A rule that can change a test result after execution |
| retention | Technical noun | A rule that determines if Greenlight publishes an attachment or keeps a completed run directory |
| retried pass | Technical noun | A successful terminal result that used more than one test attempt |
| run acceptance policy | Technical noun | A command-side plugin that can reject an otherwise successful run without changing test outcomes |
| retry decider | Technical noun | A plugin that determines if Greenlight starts another test attempt |
| risky test | Technical noun | A passed test that verifies no expectations and has no `#[NoExpectations]` attribute |
| run | Technical noun | One execution of a selected test suite |
| run | Technical verb | To execute a command, test, or test suite |
| run directory | Technical noun | The directory that contains published attachments for one run |
| run policy | Technical noun | A rule that evaluates the final run summary without changing a test result |
| run profile | Technical noun | A report that summarizes worker use, scheduling, and boot latency for one run |
| run state | Technical noun | Saved failure and duration data that can control a later run |
| run subscriber | Technical noun | An orchestrator plugin that observes run events |
| run time | Technical noun | The time when a program executes |
| runtime | Noun modifier | Related to program execution |
| runtime boundary | Technical noun | A plugin-defined execution context that contains one complete test attempt |
| sandbox | Technical noun | A per-test facility that owns temporary state and restores or removes it after the test |
| service provider | Technical noun | A Laravel class that registers services in the Laravel container |
| service resolver | Technical noun | A fallback component that supplies constructor arguments by type |
| service scope | Technical noun | The lifetime and ownership boundary of a harness service |
| service source | Technical noun | A named provider or resolver that supplies a requested harness or container service |
| scheduling unit | Technical noun | One test class or isolated test that the orchestrator can assign to a worker |
| seed | Technical noun | An integer that reproduces randomized test-class order |
| shard | Technical noun | One disjoint class-based part of an execution plan |
| sidecar | Technical noun | A JSON metadata file for one complete staged file |
| spy | Technical noun | A double that records permitted calls |
| staged | Noun modifier | Stored in staging before publication |
| staging | Technical noun | A private area that stores attachment content before publication |
| storage key | Technical noun | An opaque value that identifies staged attachment content |
| stream | Technical noun | An ordered sequence of reporter events or JSONL lines |
| stub | Technical noun | An inert double that supplies a dependency |
| subject | Technical noun | The value that an expectation checks |
| subscriber | Technical noun | A plugin that receives lifecycle callbacks or run events |
| suite | Technical noun | A named group of discovery paths and descriptive tags |
| Symfony bridge | Technical noun | The component that connects the Greenlight harness to a Symfony kernel and container |
| temporal expectation | Technical noun | An expectation that observes probe values over time |
| terminal emulator | Technical noun | Test support that models the terminal state after control sequences |
| terminal result | Technical noun | The final result after all test and plugin changes |
| Tempest bridge | Technical noun | The component that connects the Greenlight harness to a Tempest kernel and container |
| test | Technical noun | One invocation of a test method with one optional data set |
| test class | Technical noun | A class that contains one or more test methods |
| test container | Technical noun | A Symfony container that makes test services available |
| test ID | Technical noun | The stable class, method, and optional data-set label for a test |
| timing cache | Technical noun | Stored class durations that Greenlight uses to order later runs |
| transformation log | Technical noun | The record of plugins that changed a test result |
| wire payload | Technical noun | Data that Greenlight sends through the worker protocol |
| warmup | Technical noun | Initial test execution that lets runtime caches reach stable sizes before measurement |
| worker | Technical noun | A process that executes assigned test classes |
| worker pool | Technical noun | The workers that are available to an orchestrator |
| worker protocol | Technical noun | The internal message contract between the orchestrator and workers |
| worker replacement | Technical noun | The creation of a new worker after a worker reaches a configured limit or exits unexpectedly |

Use `configuration`, not `config`, in prose. Preserve `config` when it is part
of code, a path, a command, or exact output.

Use `test ID`, not `test identifier`. Use `data set` as a noun and `data-set`
as a modifier.

Use `run time` as a noun. Use `runtime` only as a modifier.

## Review procedure

Review technical prose as follows:

1. Confirm that the text preserves its technical meaning.
2. Confirm that the approved vocabulary includes each general word.
3. Confirm that each project term is in the register.
4. Run `composer prose:check`.
5. Run `composer prose:review`.
6. Review each advisory message and correct each applicable problem.

The mandatory check has no baseline. It must report zero errors. The advisory
review can report approved nouns, exact literals, and marketing copy. Record the
reason when you retain an advisory candidate.
