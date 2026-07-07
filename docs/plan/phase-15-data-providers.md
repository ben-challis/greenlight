# Phase 15: inline data rows

| | |
|---|---|
| **Track** | post-GA, track B follow-on (serial: touches the discovery/execution seam) |
| **Unblocked by** | Phase 14 (filter semantics for data-set labels must be settled first) |
| **PRD sections** | 5.1 (shape of a test), 5.3 (flow-control attributes) |
| **Writes to** | `src/Attribute/`, `src/Discovery/`, `src/Runner/Worker/`, `tests/` |

## Reality check

This phase was originally planned as "provider-method data sets" on the belief that `#[DataSet]` carried literal values. The codebase says otherwise: `#[DataSet]` has been the provider form since Phase 3, with plan-time expansion under a time budget, stable derived keys, worker-side re-resolution through the same expander, per-class caching, and a loud error when a changed provider no longer yields a planned key. Object-valued rows already work because values never cross the wire; only keys do. The genuinely missing piece was the inverse: an inline literal form for trivial rows that should not force a provider method.

## Goals

`#[DataRow([args], label: ...)]`: repeatable inline data sets for the cases attributes can express (scalars, arrays, constants), sharing one key space with a `#[DataSet]` provider on the same method.

## Key tasks

- The `DataRow` attribute: `array $arguments` in parameter order, optional non-empty label; unlabelled rows key as `#<position>` among the rows, matching the provider key derivation style.
- One resolution path for both sides: the expander gains `rowsFor(class, method, ?provider)` merging inline rows (declaration order first) with provider yields, refusing duplicate keys as a discovery error. The discoverer and the worker's class context both resolve through it, so keys cannot drift between plan and execution; the worker caches per test method.
- No wire changes: inline rows live in the attribute, which the worker reflects locally, exactly as providers are re-invoked locally.
- Filtering by label works unchanged because Phase 14's id filter applies after expansion.

## Deliverables

The attribute, expansion, parallel execution, and label filtering proven by fixtures; attribute reference and PHPUnit migration guide (`#[TestWith]` mapping) updated.

## Design decisions

- Inline rows are deliberately limited to attribute-expressible values. Objects and computed cases belong to providers, which already handle them; a second object-capable channel would duplicate the provider contract for no gain.
- Positional keys count across rows, not attributes, so inserting a labelled row does not renumber the unlabelled ones after it any more than inserting an unlabelled one does. Labels are still the recommendation for anything long-lived.

## Validation

- Unit: expansion order and keys (labelled, positional, provider-merged), duplicate-key refusal, discoverer producing labelled plan entries.
- Acceptance: the fixture running under workers with all four rows reported, and `--filter` selecting a single row by label.
