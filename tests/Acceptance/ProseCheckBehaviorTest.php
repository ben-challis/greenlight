<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\ProjectFiles;
use Greenlight\Tests\Support\Subprocess;

final readonly class ProseCheckBehaviorTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function excludesMarkdownCodeAndLinks(): void
    {
        $root = $this->workspace('markdown-exclusions');
        $this->write(
            $root,
            'sample.md',
            <<<'MARKDOWN'
            # Exclusions

            The value is `colour;` in this sample.
            The other value is ``colour; `sample` `` in this sample.

            [reference](https://example.com/colour)

            ```php
            $colour = 'value;';
            ```

            MARKDOWN,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes markdown code and links')->toBe(0);
    }

    #[Test]
    public function closesMarkdownFencesOnlyWithTheOpeningMarker(): void
    {
        $root = $this->workspace('markdown-fence-marker');
        $this->write(
            $root,
            'sample.md',
            <<<'MARKDOWN'
            # Fence marker

            ```php
            $colour = 'value;';
            ~~~
            $colour = 'other;';
            ```

            The reporter doesn't stop;

            MARKDOWN,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('closes Markdown fences only with the opening marker')->toBe(1);
        Expect::that($result->output())->toContain('sample.md:9: contraction:')
            ->toContain('sample.md:9: semicolon:')
            ->not()->toContain('sample.md:6:');
    }

    #[Test]
    public function checksMarkdownLinkLabelsButExcludesDestinations(): void
    {
        $root = $this->workspace('markdown-link-label');
        $this->write(
            $root,
            'sample.md',
            "[The guide doesn't use colour;](https://example.com/colour)\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks visible Markdown link labels but excludes destinations')->toBe(1);
        Expect::that($result->output())->toContain('sample.md:1: british-spelling:')
            ->toContain('sample.md:1: contraction:')
            ->toContain('sample.md:1: semicolon:');
    }

    #[Test]
    public function checksMarkdownImageAltText(): void
    {
        $root = $this->workspace('markdown-image-alt');
        $this->write(
            $root,
            'sample.md',
            "![The diagram doesn't use colour](images/worker.svg)\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks Markdown image alt text')->toBe(1);
        Expect::that($result->output())->toContain('sample.md:1: british-spelling:')
            ->toContain('sample.md:1: contraction:');
    }

    #[Test]
    public function checksMarkdownHeadingsAndTables(): void
    {
        $root = $this->workspace('markdown-prose');
        $this->write(
            $root,
            'sample.md',
            <<<'MARKDOWN'
            # The reporter uses colour

            | State | Description |
            | --- | --- |
            | stopped | The worker doesn't continue. |

            MARKDOWN,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks markdown headings and tables')->toBe(1);
        Expect::that($result->output())->toContain('sample.md:1: british-spelling:')
            ->toContain('sample.md:5: contraction:');
    }

    #[Test]
    public function checksWebsiteCopyAndExcludesCode(): void
    {
        $root = $this->workspace('website-copy');
        $this->write(
            $root,
            'website/src/pages/index.astro',
            <<<'ASTRO'
            ---
            const codeSample = "The code doesn't use colour;";
            const title = "The page doesn't use colour.";
            ---

            <main aria-label="The site uses colour">
              <p>The worker doesn't stop.</p>
              <code>The code doesn't use colour;</code>
            </main>

            <script>
              status.textContent = "The search doesn't use colour.";
            </script>

            ASTRO,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks website copy and excludes code')->toBe(1);
        Expect::that($result->output())->toContain('website/src/pages/index.astro:')
            ->toContain('british-spelling')
            ->toContain('contraction')
            ->not()->toContain('codeSample');
    }

    #[Test]
    public function checksAccessibilityAttributeForms(): void
    {
        $root = $this->workspace('accessibility-attributes');
        $this->write(
            $root,
            'website/src/pages/index.astro',
            <<<'ASTRO'
            <main aria-label='The page does not stop;'>
              <section aria-label={ready ? "The dialog doesn't stop." : 'The dialog stops.'}></section>
            </main>

            ASTRO,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks static and expression accessibility attributes')->toBe(1);
        Expect::that($result->output())->toContain('website/src/pages/index.astro:1: semicolon:')
            ->toContain('website/src/pages/index.astro:2: contraction:');
    }

    #[Test]
    public function checksStructuredDescriptionsAndOwnedComments(): void
    {
        $root = $this->workspace('structured-prose');
        $this->write(
            $root,
            'composer.json',
            <<<'JSON'
            {
              "description": "The package doesn't organise tests."
            }
            JSON,
        );
        $this->write(
            $root,
            '.github/ISSUE_TEMPLATE/feature.yml',
            <<<'YAML'
            name: Feature request
            description: The template uses colour.
            # The author shouldn't add a solution.
            YAML,
        );
        $this->write(
            $root,
            'website/src/lib/docs.ts',
            <<<'TS'
            export const item = {
              title: 'Start',
              description: 'The guide favours one worker.',
            };
            TS,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks structured descriptions and owned comments')->toBe(1);
        Expect::that($result->output())->toContain('composer.json:2: british-spelling:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:2: british-spelling:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:3: contraction:')
            ->toContain('website/src/lib/docs.ts:3: british-spelling:');
    }

    #[Test]
    public function checksMultilineStructuredAndScriptProse(): void
    {
        $root = $this->workspace('multiline-structured-prose');
        $this->write(
            $root,
            'composer.json',
            <<<'JSON'
            {"suggest": {
              "vendor/package": "The package doesn't organise tests."
            }}
            JSON,
        );
        $this->write(
            $root,
            '.github/ISSUE_TEMPLATE/feature.yml',
            <<<'YAML'
            description: >-
              The template doesn't use
              colour;
            YAML,
        );
        $this->write(
            $root,
            'website/scripts/status.mjs',
            <<<'JS'
            const labels = { unavailable: 'The service does not use colour.' };
            throw new Error("The worker doesn't stop.");
            status.textContent = 'The runner does not organise tests.';
            JS,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks multiline structured and script prose')->toBe(1);
        Expect::that($result->output())->toContain('composer.json:2: contraction:')
            ->toContain('composer.json:2: british-spelling:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:1: contraction:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:1: british-spelling:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:1: semicolon:')
            ->toContain('website/scripts/status.mjs:1: british-spelling:')
            ->toContain('website/scripts/status.mjs:2: contraction:')
            ->toContain('website/scripts/status.mjs:3: british-spelling:');
    }

    #[Test]
    public function checksScriptBlockComments(): void
    {
        $root = $this->workspace('script-block-comments');
        $this->write(
            $root,
            'website/scripts/status.mjs',
            <<<'JS'
            const open = '/*';
            const codeSample = "The code doesn't stop;";
            const close = '*/';

            /**
             * The reporter does not stop;
             * the worker continues.
             */
            JS,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks script block comments')->toBe(1);
        Expect::that($result->output())->toContain('website/scripts/status.mjs:6: semicolon:')
            ->not()->toContain('website/scripts/status.mjs:2: contraction:');
    }

    #[Test]
    public function joinsScriptLineCommentParagraphs(): void
    {
        $root = $this->workspace('script-line-comments');
        $this->write(
            $root,
            'website/scripts/status.mjs',
            <<<'JS'
            const template = `
            // The template doesn't stop;
            `;

            // The orchestrator collects every selected test class from all configured directories and
            // sends one complete assignment to every available worker before the test run begins across all active channels.
            //
            // One sentence. Two sentences. Three sentences. Four sentences. Five sentences. Six sentences. Seven sentences.
            JS,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('joins script line-comment paragraphs')->toBe(1);
        Expect::that($result->output())->toContain('website/scripts/status.mjs:5: sentence-length:')
            ->toContain('website/scripts/status.mjs:8: paragraph-length:')
            ->not()->toContain('website/scripts/status.mjs:2: contraction:');
    }

    #[Test]
    public function excludesMultilineAstroExpressions(): void
    {
        $root = $this->workspace('website-expressions');
        $this->write(
            $root,
            'website/src/layouts/DocsLayout.astro',
            <<<'ASTRO'
            <main>
              {
                headings.map((heading) => (
                  <a href={`#${heading.slug}`}>{heading.text}</a>
                ))
              }
              <p>The worker stops.</p>
            </main>

            ASTRO,
        );

        $result = $this->run('review', $root);
        Expect::that($result->exitCode)->because('excludes multiline Astro expressions')->toBe(0);
        Expect::that($result->output())->toBe('');
    }

    #[Test]
    public function excludesRegisteredLiterals(): void
    {
        $root = $this->workspace('registered-literals');
        $this->write(
            $root,
            'website/src/components/Version.astro',
            "<span>dev-main</span>\n",
        );

        $result = $this->run('review', $root);
        Expect::that($result->exitCode)->because('excludes registered literals')->toBe(0);
        Expect::that($result->output())->toBe('');
    }

    #[Test]
    public function excludesGeneratedAstroTypes(): void
    {
        $root = $this->workspace('generated-astro-types');
        $this->write(
            $root,
            'website/.astro/content.d.ts',
            "/** The generated declaration doesn't stop; */\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes generated Astro types')->toBe(0);
    }

    #[Test]
    public function excludesPhpDocTagsAndMachineDirectivesButChecksNarrativeComments(): void
    {
        $root = $this->workspace('php-comments');
        $this->write(
            $root,
            'src/TagOnly.php',
            <<<'PHP'
            <?php

            /**
             * A short description.
             *
             * @param non-empty-string $colour;
             * @phpstan-type Colour = array{colour: string};
             */
            final class TagOnly {} // @phpstan-ignore colour (test fixture: tests that the checker excludes machine directives)

            PHP,
        );

        $excludedResult = $this->run('check', $root);
        Expect::that($excludedResult->exitCode)->because('excludes PHPDoc tags and machine directives but checks narrative comments')->toBe(0);

        $this->write(
            $root,
            'src/Narrative.php',
            <<<'PHP'
            <?php

            // The reporter uses a different colour; the worker continues.
            final class Narrative {}

            PHP,
        );

        $includedResult = $this->run('check', $root);
        Expect::that($includedResult->exitCode)->because('excludes PHPDoc tags and machine directives but checks narrative comments')->toBe(1);
        Expect::that($includedResult->output())->toContain('src/Narrative.php:3:')
            ->toContain('british-spelling')
            ->toContain('semicolon');
    }

    #[Test]
    public function checksPhpDocTagDescriptionsAndHumanReadableStrings(): void
    {
        $root = $this->workspace('php-human-prose');
        $this->write(
            $root,
            'src/Message.php',
            <<<'PHP'
            <?php

            final class Message
            {
                /**
                 * @param string $value The reporter doesn't use colour;
                 * @param int<1, max>|null $limit The worker doesn't use colour;
                 * @return array{value: string, label: string} The method doesn't use colour;
                 */
                public function report(string $value, ?int $limit): string
                {
                    return 'The worker does not organise the data.';
                }
            }

            PHP,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks PHPDoc tag descriptions and human-readable strings')->toBe(1);
        Expect::that($result->output())->toContain('src/Message.php:6: semicolon:')
            ->toContain('src/Message.php:6: contraction:')
            ->toContain('src/Message.php:6: british-spelling:')
            ->toContain('src/Message.php:7: contraction:')
            ->toContain('src/Message.php:7: british-spelling:')
            ->toContain('src/Message.php:8: contraction:')
            ->toContain('src/Message.php:8: british-spelling:')
            ->toContain('src/Message.php:12: british-spelling:');
    }

    #[Test]
    public function checksProseBearingPhpDocTags(): void
    {
        $root = $this->workspace('php-prose-tags');
        $this->write(
            $root,
            'src/Message.php',
            <<<'PHP'
            <?php

            final class Message
            {
                /**
                 * @internal The reporter doesn't stop;
                 */
                public function report(): void {}

                /**
                 * @deprecated The worker starts here and
                 *   doesn't continue;
                 */
                public function oldReport(): void {}
            }

            PHP,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks prose-bearing PHPDoc tags')->toBe(1);
        Expect::that($result->output())->toContain('src/Message.php:6: contraction:')
            ->toContain('src/Message.php:6: semicolon:')
            ->toContain('src/Message.php:11: contraction:')
            ->toContain('src/Message.php:11: semicolon:');
    }

    #[Test]
    public function checksTheExtensionlessPhpEntrypoint(): void
    {
        $root = $this->workspace('extensionless-php');
        $this->write(
            $root,
            'bin/greenlight',
            "<?php\n\n\\fwrite(\\STDERR, \"The runner doesn't stop;\");\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks the extensionless PHP entry point')->toBe(1);
        Expect::that($result->output())->toContain('bin/greenlight:3: contraction:')
            ->toContain('bin/greenlight:3: semicolon:');
    }

    #[Test]
    public function checksMultilinePhpDocAndInterpolatedPhpStrings(): void
    {
        $root = $this->workspace('php-multiline-prose');
        $this->write(
            $root,
            'src/Message.php',
            <<<'PHP'
            <?php

            final class Message
            {
                /**
                 * @param string $value The reporter starts here and
                 *   doesn't use colour;
                 */
                public function report(string $value, string $worker): string
                {
                    return "The $worker doesn't organise tests.";
                }

                public function document(): string
                {
                    return <<<TEXT
                    The worker doesn't use colour;
                    TEXT;
                }
            }

            PHP,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks multiline PHPDoc and interpolated PHP strings')->toBe(1);
        Expect::that($result->output())->toContain('src/Message.php:6: semicolon:')
            ->toContain('src/Message.php:6: contraction:')
            ->toContain('src/Message.php:6: british-spelling:')
            ->toContain('src/Message.php:11: contraction:')
            ->toContain('src/Message.php:11: british-spelling:')
            ->toContain('src/Message.php:16: semicolon:')
            ->toContain('src/Message.php:16: contraction:')
            ->toContain('src/Message.php:16: british-spelling:');
    }

    #[Test]
    public function joinsWrappedMarkdownListItems(): void
    {
        $root = $this->workspace('markdown-list-continuation');
        $this->write(
            $root,
            'sample.md',
            <<<'MARKDOWN'
            - The orchestrator collects every selected test class from all configured directories
              and sends one complete assignment to every available worker before the test run begins across all active channels.

            - One sentence. Two sentences. Three sentences.
              Four sentences. Five sentences. Six sentences. Seven sentences.
            MARKDOWN,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('joins wrapped Markdown list items')->toBe(1);
        Expect::that($result->output())->toContain('sample.md:1: sentence-length:')
            ->toContain('sample.md:4: paragraph-length:');
    }

    #[Test]
    public function excludesCodeTemplatesAndQuotedLiteralsInPhpStrings(): void
    {
        $root = $this->workspace('php-string-exclusions');
        $this->write(
            $root,
            'src/CodeTemplate.php',
            <<<'PHP'
            <?php

            final class CodeTemplate
            {
                public function render(): string
                {
                    return 'private $colour;';
                }

                public function instruction(): string
                {
                    return 'End the file with "return GreenlightConfig::create();".';
                }
            }

            PHP,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes code templates and quoted literals in PHP strings')->toBe(0);
    }

    #[Test]
    public function joinsConsecutiveLineCommentsAndDoesNotCreateDelimiterText(): void
    {
        $root = $this->workspace('php-comment-groups');
        $this->write(
            $root,
            'src/Comments.php',
            <<<'PHP'
            <?php

            // One sentence.
            // Two sentences. Three sentences. Four sentences.
            // Five sentences. Six sentences. Seven sentences.
            final class Comments {}

            /**
             * A valid description.
             */
            final class Documented {}

            PHP,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('joins consecutive line comments and does not create delimiter text')->toBe(1);
        Expect::that($result->output())->toContain('paragraph-length')
            ->not()->toContain('valid description. /');
    }

    #[Test]
    public function reviewReportsAdvisoriesWithoutFailure(): void
    {
        $root = $this->workspace('advisories');
        $this->write(
            $root,
            'sample.md',
            <<<'MARKDOWN'
            # Review

            1. Configure each available worker with the selected test classes from all directories before you start the complete test run in this repository.

            The suite is started by the worker.

            Calling the method starts the worker.

            You should set up the worker.

            Open the file and select the option.

            MARKDOWN,
        );

        $result = $this->run('review', $root);
        Expect::that($result->exitCode)->because('review reports advisories without failure')->toBe(0);
        Expect::that($result->output())->toContain('procedural-sentence-length')
            ->toContain('passive-voice')
            ->toContain('verbal-ing')
            ->toContain('discouraged-word')
            ->toContain('phrasal-verb')
            ->toContain('multiple-instructions');
    }

    #[Test]
    public function reportsLongInstructionsWithoutBlockingThem(): void
    {
        $root = $this->workspace('long-instruction');
        $this->write(
            $root,
            'sample.md',
            'Configure each available worker with every selected test class from all project directories before the orchestrator starts the complete test run with parallel processes and reports.' . "\n",
        );

        $checked = $this->run('check', $root);
        $reviewed = $this->run('review', $root);

        Expect::that($checked->exitCode)->because('reports long instructions without blocking them')->toBe(0);
        Expect::that($reviewed->exitCode)->toBe(0);
        Expect::that($reviewed->output())->toContain('procedural-sentence-length');
    }

    #[Test]
    public function doesNotReportApprovedNormativeTokensAsDiscouragedWords(): void
    {
        $root = $this->workspace('normative-tokens');
        $this->write(
            $root,
            'sample.md',
            "The worker MUST stop. The reporter SHOULD continue. The plugin MAY report the result.\n",
        );

        $result = $this->run('review', $root);
        Expect::that($result->exitCode)->because('does not report approved normative tokens as discouraged words')->toBe(0);
        Expect::that($result->output())->not()->toContain('discouraged-word');
    }

    #[Test]
    public function outputIsDeterministicAndSortedByPath(): void
    {
        $root = $this->workspace('deterministic-output');
        $this->write($root, 'z-last.md', "The worker doesn't stop.\n");
        $this->write($root, 'a-first.md', "The worker doesn't start.\n");

        $first = $this->run('check', $root);
        $second = $this->run('check', $root);
        $firstPosition = \strpos($first->output(), 'a-first.md:');
        $lastPosition = \strpos($first->output(), 'z-last.md:');

        Expect::that($first->exitCode)->because('output is deterministic and sorted by path')->toBe(1);
        Expect::that($second->exitCode)->toBe(1);
        Expect::that($second->output())->toBe($first->output());
        Expect::that($first->output())->toMatch('/^a-first\.md:\d+: contraction:/m');
        Expect::that($firstPosition)->not()->toBeFalse();
        Expect::that($lastPosition)->not()->toBeFalse();
        Expect::that($firstPosition)->toBeLessThan($lastPosition);
    }

    #[Test]
    public function excludesSharedFixtureDirectories(): void
    {
        $root = $this->workspace('fixture-exclusion');
        $this->write(
            $root,
            'tests/Fixture/Invalid.php',
            "<?php\n\n// The worker doesn't use the configured colour; it stops.\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes shared fixture directories')->toBe(0);
    }

    #[Test]
    public function excludesGeneratedAnalysisFiles(): void
    {
        $root = $this->workspace('tool-cache-exclusion');
        $this->write(
            $root,
            'build/cache/phpstan/cache.php',
            "<?php\n\n// The worker doesn't use the configured colour; it stops.\n",
        );
        $this->write(
            $root,
            'build/docs-php/example/snippet.php',
            "<?php\n\n// The worker doesn't use the configured colour; it stops.\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes generated analysis files')->toBe(0);
    }

    #[Test]
    public function excludesDependenciesAtAnyDirectoryDepth(): void
    {
        $root = $this->workspace('dependency-exclusion');
        $invalid = "The worker doesn't use the configured colour; it stops.\n";
        $this->write($root, 'vendor/package/README.md', $invalid);
        $this->write($root, 'website/node_modules/package/README.md', $invalid);
        $this->write($root, 'packages/example/vendor/package/README.md', $invalid);
        $this->write($root, 'packages/example/node_modules/package/README.md', $invalid);

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes dependencies at all directory depths')->toBe(0);
    }

    #[Test]
    public function excludesNestedClaudeWorktrees(): void
    {
        $root = $this->workspace('nested-worktree-exclusion');
        $this->write(
            $root,
            '.claude/worktrees/example/README.md',
            "The worker doesn't use the configured colour; it stops.\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes nested Claude worktrees')->toBe(0);
    }

    #[Test]
    public function rejectsRemovedBaselineOptions(): void
    {
        $root = $this->workspace('removed-baseline');
        $this->write($root, 'sample.md', "The worker stops.\n");
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/prose-check.php',
            'check',
            '--root=' . $root,
            '--baseline-dir=' . $root . '/baseline',
        ]);

        Expect::that($result->exitCode)->because('rejects removed baseline options')->toBe(1);
        Expect::that($result->stderr)->toContain('Unknown prose-check option "--baseline-dir=');
    }

    private function workspace(string $name): string
    {
        return ProjectFiles::create($this->tempDirectory, $name . '/project')->directory;
    }

    private function write(string $root, string $relativePath, string $contents): void
    {
        new ProjectFiles($root)->write($relativePath, $contents);
    }

    private function run(string $command, string $root): ProcessResult
    {
        return Subprocess::run($root, [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/prose-check.php',
            $command,
            '--root=' . $root,
        ]);
    }
}
