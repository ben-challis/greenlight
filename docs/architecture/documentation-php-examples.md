# Documentation PHP examples

Greenlight checks selected PHP code fences with the PHP parser, PHPStan, and
Rector. The check uses generated files because both tools operate most reliably
on PHP files and projects. It does not change the documentation or extend the
tools.

The extractor reads `README.md` and Markdown files below `docs/`. It does not
read PHP examples in website components, issue templates, or other root files.
Review those examples separately.

The generated workspace is disposable. It is in `build/docs-php`. Do not commit
its contents. `composer docs:php:check` replaces this
directory on each run.

## Select an example

Put one metadata comment immediately before a PHP fence. The comment is JSON so
that invalid and unknown fields cause an error.

```html
<!-- php-example {"example":"getting-started","file":"src/Greeter.php","mode":"file","tools":["phpstan","rector"]} -->
```

`example` identifies a virtual project. Multiple fences can supply different
files to the same project. This method lets one example define a class in one
fence and use it in another fence. Use unique file paths within each project. Select the same tools for all files
in one project.

`file` is a stable path within the virtual project. Use a `.php` extension. Do not use an
absolute path or a parent path. Do not derive this value from the
position of the fence. Stable names keep diagnostics and tool caches useful
when prose moves.

`tools` can contain `phpstan`, `rector`, both tools, or no tools. Every selected
example is checked for PHP syntax before these tools run.

## Choose a mode

Use `file` when the fence represents a PHP file. The extractor adds `<?php` if
the fence omits it.

Use `statements` for statements that need a function body. The extractor puts
the statements in a synthetic static closure. It supplies a synthetic receiver
for a fluent chain that starts with `->`, and it adds a final semicolon when the
fragment omits one.

Use `class-members` for properties or methods that need a class body. The
extractor puts the members in a synthetic final class. Imports can precede the
members. The extractor keeps the imports outside the class.

Use `display` only when analysis would make the example less useful. Give each display
example a nonempty `reason`. Do not use the other fields for that example. Add
metadata to every PHP fence in a manually maintained document within this scope. The command
fails when metadata is absent. Generated reference documents are excluded from
the inventory because their source generator owns their PHP examples.

We recommend definitions for otherwise undefined names in another file in the
same virtual project. You can put a short analysis-only support file in a separate
documentation fence. Use a PHPStan inline ignore only when the
undefined name is the behavior that the documentation must show. Use `display`
for pseudocode, deliberately invalid syntax, or fragments that no small wrapper
can represent honestly.

## Source mapping

The extractor writes `build/docs-php/manifest.json`. Each entry records the
documentation path, fence and body line range, source hash, generated path,
selected tools, line mapping, and synthetic wrapper ranges. Entries and files
have deterministic ordering and names.

For a mapped generated line, the source line is:

```text
sourceStartLine + generatedLine - generatedStartLine
```

An error in an added opening tag or wrapper maps to the opening fence. PHPStan
provides a generated file and line in its JSON output. Rector provides a file
diff. The check uses the first old-file line in its first diff hunk. If Rector
does not provide a usable hunk, the finding maps to the opening fence.

The command prints `path:line` diagnostics locally and GitHub workflow error
annotations in CI. It sorts diagnostics by source location so that parallel
tool behavior cannot change the output order.

## Rector policy

Rector runs in dry-run mode. A finding means that an example does not follow
the repository's current Rector policy. The check does not copy a generated
edit into Markdown. Wrappers and indentation make automatic reverse patches
hard to review, and a change can cross more than one virtual file.

To apply a finding, edit the reported documentation fence and run
`composer docs:php:check` again. You can use the generated diff as a reference.
Do not edit `build/docs-php` as a source directory.

## Configuration and CI

`phpstan.docs.neon` and `rector.docs.php` contain policy for documentation
examples. Their caches are separate from normal source analysis. The Rector
configuration shares only the rule policy with the repository configuration.
The PHPStan configuration does not inherit repository-only checked-exception
rules that do not apply to consumer examples.

The documentation Rector configuration skips transformations that need the
complete class or file. These transformations include strict-type declarations,
closure simplification, readonly inference, unused promoted properties and
variables, and dynamic property completion. Documentation fragments omit
opening boilerplate and can show only the members that explain one feature.
Other repository Rector rules still apply.

`composer static-analysis` runs the documentation check before normal PHPStan,
Rector, and dependency analysis. CI caches the documentation tool caches, but
it does not cache or publish the generated workspace. A clean extraction on
each run removes stale virtual files.
