# Technical writing

Greenlight technical prose follows ASD-STE100 Simplified Technical English,
Issue 9, dated January 15, 2025.

This policy applies to repository-owned technical prose:

* Markdown documentation
* PHPDoc and code comments
* Contributor and community guidance
* Website technical content and accessibility text
* Command help, diagnostics, error messages, and reports
* Package, schema, and configuration descriptions

Promotional website text follows the clarity principles in this policy. It does
not have to use the controlled vocabulary.

The prose checker finds mechanical errors and possible language problems. It
does not certify compliance with ASD-STE100. A writer must also review the
meaning, vocabulary, grammar, and technical accuracy.

## Writing rules

Use the official ASD-STE100 Issue 9 standard as the primary reference. Apply
these rules to all new or materially changed technical prose:

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

Architecture rules use the uppercase control terms **MUST**, **MUST NOT**,
**SHOULD**, **SHOULD NOT**, and **MAY**. These terms preserve distinct
requirement levels and are explicit project exceptions.

Use these terms only in normative rules. Do not replace one control term with
another during a language rewrite. Such a replacement can change a
requirement.

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

Do not rename a public identifier to change its spelling. For example, preserve
an existing identifier that contains `colour`. Use `color` in the related
prose.

## Technical-term register

The register approves Greenlight-specific words for the listed part of speech
and meaning. Use the singular form unless the context requires a plural.

| Term | Type | Approved meaning |
| --- | --- | --- |
| artifact | Technical noun | A file or data item that a run produces |
| assignment | Technical noun | A group of test classes that an orchestrator sends to a worker |
| attachment | Technical noun | Evidence that belongs to one test result |
| channel | Technical noun | A stable resource slot that belongs to one worker |
| configuration | Technical noun | The settings that control a Greenlight run |
| data set | Technical noun | One named set of arguments for a test method |
| data-set | Noun modifier | Related to a data set |
| double | Technical noun | A test object that replaces a collaborator |
| expectation | Technical noun | A required condition for a test value |
| fixture | Technical noun | Test support code or data with a controlled purpose |
| matcher | Technical noun | An operation that checks an expectation |
| mock | Technical noun | A strict double with planned interactions |
| orchestrator | Technical noun | The process that plans and controls a run |
| plugin | Technical noun | An extension that adds a Greenlight capability |
| reporter | Technical noun | A component that converts run events to output |
| resource limit | Technical noun | A limit on concurrent access to a named resource |
| run | Technical noun | One execution of a selected test suite |
| run | Technical verb | To execute a command, test, or test suite |
| run time | Technical noun | The time when a program executes |
| runtime | Noun modifier | Related to program execution |
| spy | Technical noun | A double that records permitted calls |
| stub | Technical noun | An inert double that supplies a dependency |
| suite | Technical noun | A selected group of test classes |
| test | Technical noun | One test method with one optional data set |
| test class | Technical noun | A class that contains one or more test methods |
| test ID | Technical noun | The stable class, method, and optional data-set label for a test |
| worker | Technical noun | A process that executes assigned test classes |

Use `configuration`, not `config`, in prose. Preserve `config` when it is part
of code, a path, a command, or exact output.

Use `test ID`, not `test identifier`. Use `data set` as a noun and `data-set`
as a modifier.

Use `run time` as a noun. Use `runtime` only as a modifier.

## Review procedure

Review technical prose as follows:

1. Confirm that the text preserves its technical meaning.
2. Confirm that each general word is approved for its use.
3. Confirm that each project term is in the register.
4. Run `composer prose:check`.
5. Run `composer prose:review`.
6. Review each advisory message and correct each applicable problem.

The checker baseline records existing mechanical findings during the rollout.
Do not add a new finding to the baseline.

Use `php tools/prose-check.php baseline --prune` after you remove findings. The
command must not accept new findings.
