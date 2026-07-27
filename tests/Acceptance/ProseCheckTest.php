<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

final readonly class ProseCheckTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataRow(['semicolon', 'The worker stops; the orchestrator continues.', 'The worker stops. The orchestrator continues.'], 'semicolon')]
    #[DataRow(['contraction', "The worker doesn't stop.", 'The worker does not stop.'], 'contraction')]
    #[DataRow(['contraction', 'The worker doesn’t stop.', 'The worker does not stop.'], 'Unicode contraction')]
    #[DataRow(['contraction', "Let's start the worker.", 'Start the worker.'], 'additional contraction')]
    #[DataRow(['british-spelling', 'The reporter uses a different colour.', 'The reporter uses a different color.'], 'colour')]
    #[DataRow(['british-spelling', 'The runner favours one worker.', 'The runner favors one worker.'], 'favour')]
    #[DataRow(['british-spelling', 'The runner honours a labelled test.', 'The runner honors a labeled test.'], 'honour and labelled')]
    #[DataRow(['british-spelling', 'The driver normalises the data.', 'The driver normalizes the data.'], 'normalise')]
    #[DataRow(['british-spelling', 'The runner parameterises tests.', 'The runner parameterizes tests.'], 'parameterise')]
    #[DataRow(['british-spelling', 'The reporter deserialises the event.', 'The reporter deserializes the event.'], 'deserialise')]
    #[DataRow(['british-spelling', 'The worker fulfils the request.', 'The worker fulfills the request.'], 'fulfil')]
    #[DataRow([
        'british-spelling',
        'Organise the authorised customisation.',
        'Organize the authorized customization.',
    ], 'other spellings')]
    #[DataRow([
        'sentence-length',
        'The orchestrator collects every selected test class from the configured directories and sends one complete assignment to each available worker before the test run starts in parallel.',
        'The orchestrator collects every selected test class from the configured directories and sends one complete assignment to each available worker before the test run starts.',
    ], 'sentence length')]
    #[DataRow([
        'paragraph-length',
        'A worker starts. It reads the configuration. It runs tests. It records results. It sends events. It releases resources. It stops.',
        'A worker starts. It reads the configuration. It runs tests. It records results. It sends events. It stops.',
    ], 'paragraph length')]
    public function blockingRulesRejectInvalidProseAndAcceptTheValidCounterpart(
        string $rule,
        string $invalid,
        string $valid,
    ): void {
        $root = $this->workspace('blocking-' . $rule);
        $this->write($root, 'sample.md', "# Sample\n\n" . $invalid . "\n");

        $invalidResult = $this->run('check', $root);
        Expect::that($invalidResult->exitCode)->because('blocking rules reject invalid prose and accept the valid counterpart')->toBe(1)
            ->and($invalidResult->output())->toContain($rule);

        $this->write($root, 'sample.md', "# Sample\n\n" . $valid . "\n");

        $validResult = $this->run('check', $root);
        Expect::that($validResult->exitCode)->because('blocking rules reject invalid prose and accept the valid counterpart')->toBe(0)
            ->and($validResult->output())->not()->toContain($rule);
    }

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
    public function checksMarkdownLinkLabelsButExcludesDestinations(): void
    {
        $root = $this->workspace('markdown-link-label');
        $this->write(
            $root,
            'sample.md',
            "[The guide doesn't use colour;](https://example.com/colour)\n",
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks visible Markdown link labels but excludes destinations')->toBe(1)
            ->and($result->output())->toContain('sample.md:1: british-spelling:')
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
        Expect::that($result->exitCode)->because('checks Markdown image alt text')->toBe(1)
            ->and($result->output())->toContain('sample.md:1: british-spelling:')
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
        Expect::that($result->exitCode)->because('checks markdown headings and tables')->toBe(1)
            ->and($result->output())->toContain('sample.md:1: british-spelling:')
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
        Expect::that($result->exitCode)->because('checks website copy and excludes code')->toBe(1)
            ->and($result->output())->toContain('website/src/pages/index.astro:')
            ->toContain('british-spelling')
            ->toContain('contraction')
            ->not()->toContain('codeSample');
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
        Expect::that($result->exitCode)->because('checks structured descriptions and owned comments')->toBe(1)
            ->and($result->output())->toContain('composer.json:2: british-spelling:')
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
        Expect::that($result->exitCode)->because('checks multiline structured and script prose')->toBe(1)
            ->and($result->output())->toContain('composer.json:2: contraction:')
            ->toContain('composer.json:2: british-spelling:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:1: contraction:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:1: british-spelling:')
            ->toContain('.github/ISSUE_TEMPLATE/feature.yml:1: semicolon:')
            ->toContain('website/scripts/status.mjs:1: british-spelling:')
            ->toContain('website/scripts/status.mjs:2: contraction:')
            ->toContain('website/scripts/status.mjs:3: british-spelling:');
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
        Expect::that($result->exitCode)->because('excludes multiline Astro expressions')->toBe(0)
            ->and($result->output())->toBe('');
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
        Expect::that($result->exitCode)->because('excludes registered literals')->toBe(0)
            ->and($result->output())->toBe('');
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
        Expect::that($includedResult->exitCode)->because('excludes PHPDoc tags and machine directives but checks narrative comments')->toBe(1)
            ->and($includedResult->output())->toContain('src/Narrative.php:3:')
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
                 */
                public function report(string $value): string
                {
                    return 'The worker does not organise the data.';
                }
            }

            PHP,
        );

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('checks PHPDoc tag descriptions and human-readable strings')->toBe(1)
            ->and($result->output())->toContain('src/Message.php:6: semicolon:')
            ->toContain('src/Message.php:6: contraction:')
            ->toContain('src/Message.php:6: british-spelling:')
            ->toContain('src/Message.php:10: british-spelling:');
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
        Expect::that($result->exitCode)->because('checks multiline PHPDoc and interpolated PHP strings')->toBe(1)
            ->and($result->output())->toContain('src/Message.php:6: semicolon:')
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
        Expect::that($result->exitCode)->because('joins wrapped Markdown list items')->toBe(1)
            ->and($result->output())->toContain('sample.md:1: sentence-length:')
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
        Expect::that($result->exitCode)->because('joins consecutive line comments and does not create delimiter text')->toBe(1)
            ->and($result->output())->toContain('paragraph-length')
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
        Expect::that($result->exitCode)->because('review reports advisories without failure')->toBe(0)
            ->and($result->output())->toContain('procedural-sentence-length')
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

        Expect::that($checked->exitCode)->because('reports long instructions without blocking them')->toBe(0)
            ->and($reviewed->exitCode)->toBe(0)
            ->and($reviewed->output())->toContain('procedural-sentence-length');
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
        Expect::that($result->exitCode)->because('does not report approved normative tokens as discouraged words')->toBe(0)
            ->and($result->output())->not()->toContain('discouraged-word');
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

        Expect::that($first->exitCode)->because('output is deterministic and sorted by path')->toBe(1)
            ->and($second->exitCode)->toBe(1)
            ->and($second->output())->toBe($first->output())
            ->and($first->output())->toMatch('/^a-first\.md:\d+: contraction:/m')
            ->and($firstPosition)->not()->toBeFalse()
            ->and($lastPosition)->not()->toBeFalse()
            ->and((int) $firstPosition)->toBeLessThan((int) $lastPosition);
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
    public function excludesDependenciesAtAnyDirectoryDepth(): void
    {
        $root = $this->workspace('dependency-exclusion');
        $invalid = "The worker doesn't use the configured colour; it stops.\n";
        $this->write($root, 'vendor/package/README.md', $invalid);
        $this->write($root, 'website/node_modules/package/README.md', $invalid);
        $this->write($root, 'packages/example/vendor/package/README.md', $invalid);
        $this->write($root, 'packages/example/node_modules/package/README.md', $invalid);

        $result = $this->run('check', $root);
        Expect::that($result->exitCode)->because('excludes dependencies at any directory depth')->toBe(0);
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

        Expect::that($result->exitCode)->because('rejects removed baseline options')->toBe(1)
            ->and($result->stderr)->toContain('Unknown prose-check option "--baseline-dir=');
    }

    private function workspace(string $name): string
    {
        $directory = $this->tempDirectory->subdirectory($name);
        $root = $directory . '/project';

        \mkdir($root);

        return $root;
    }

    private function write(string $root, string $relativePath, string $contents): void
    {
        $path = $root . '/' . $relativePath;
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0o777, true);
        }

        \file_put_contents($path, $contents);
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
