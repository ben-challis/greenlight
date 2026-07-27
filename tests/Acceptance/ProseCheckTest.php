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
    #[DataRow(['british-spelling', 'The reporter uses a different colour.', 'The reporter uses a different color.'], 'British spelling')]
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
        [$root, $baseline] = $this->workspace('blocking-' . $rule);
        $this->write($root, 'sample.md', "# Sample\n\n" . $invalid . "\n");

        $invalidResult = $this->run('check', $root, $baseline);
        Expect::that($invalidResult->exitCode)->toBe(1)
            ->and($invalidResult->output())->toContain($rule);

        $this->write($root, 'sample.md', "# Sample\n\n" . $valid . "\n");

        $validResult = $this->run('check', $root, $baseline);
        Expect::that($validResult->exitCode)->toBe(0)
            ->and($validResult->output())->not()->toContain($rule);
    }

    #[Test]
    public function excludesMarkdownCodeAndLinks(): void
    {
        [$root, $baseline] = $this->workspace('markdown-exclusions');
        $this->write(
            $root,
            'sample.md',
            <<<'MARKDOWN'
            # Exclusions

            The value is `colour;` in this sample.

            [colour;](https://example.com/colour)

            ```php
            $colour = 'value;';
            ```

            MARKDOWN,
        );

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(0);
    }

    #[Test]
    public function checksMarkdownHeadingsAndTables(): void
    {
        [$root, $baseline] = $this->workspace('markdown-prose');
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

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('sample.md:1: british-spelling:')
            ->toContain('sample.md:5: contraction:');
    }

    #[Test]
    public function checksWebsiteCopyAndUsesTheWebsiteShard(): void
    {
        [$root, $baseline] = $this->workspace('website-copy');
        $this->write(
            $root,
            'website/src/pages/index.astro',
            <<<'ASTRO'
            ---
            const codeSample = "The code doesn't use colour;";
            ---

            <main aria-label="The site uses colour">
              <p>The worker doesn't stop.</p>
              <code>The code doesn't use colour;</code>
            </main>

            ASTRO,
        );

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('website/src/pages/index.astro:')
            ->toContain('british-spelling')
            ->toContain('contraction');

        Expect::that($this->run('baseline', $root, $baseline, '--create')->exitCode)->toBe(0);
        $websiteBaseline = (string) \file_get_contents($baseline . '/website.json');
        Expect::that($websiteBaseline)->toContain('website/src/pages/index.astro')
            ->not()->toContain('codeSample');
    }

    #[Test]
    public function excludesPhpDocTagsAndMachineDirectivesButChecksNarrativeComments(): void
    {
        [$root, $baseline] = $this->workspace('php-comments');
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

        $excludedResult = $this->run('check', $root, $baseline);
        Expect::that($excludedResult->exitCode)->toBe(0);

        $this->write(
            $root,
            'src/Narrative.php',
            <<<'PHP'
            <?php

            // The reporter uses a different colour; the worker continues.
            final class Narrative {}

            PHP,
        );

        $includedResult = $this->run('check', $root, $baseline);
        Expect::that($includedResult->exitCode)->toBe(1)
            ->and($includedResult->output())->toContain('src/Narrative.php:3:')
            ->toContain('british-spelling')
            ->toContain('semicolon');
    }

    #[Test]
    public function joinsConsecutiveLineCommentsAndDoesNotCreateDelimiterText(): void
    {
        [$root, $baseline] = $this->workspace('php-comment-groups');
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

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('paragraph-length')
            ->not()->toContain('valid description. /');
    }

    #[Test]
    public function reviewReportsAdvisoriesWithoutFailure(): void
    {
        [$root, $baseline] = $this->workspace('advisories');
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

        $result = $this->run('review', $root, $baseline);
        Expect::that($result->exitCode)->toBe(0)
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
        [$root, $baseline] = $this->workspace('long-instruction');
        $this->write(
            $root,
            'sample.md',
            'Configure each available worker with every selected test class from all project directories before the orchestrator starts the complete test run with parallel processes and reports.' . "\n",
        );

        $checked = $this->run('check', $root, $baseline);
        $reviewed = $this->run('review', $root, $baseline);

        Expect::that($checked->exitCode)->toBe(0)
            ->and($reviewed->exitCode)->toBe(0)
            ->and($reviewed->output())->toContain('procedural-sentence-length');
    }

    #[Test]
    public function doesNotReportApprovedNormativeTokensAsDiscouragedWords(): void
    {
        [$root, $baseline] = $this->workspace('normative-tokens');
        $this->write(
            $root,
            'sample.md',
            "The worker MUST stop. The reporter SHOULD continue. The plugin MAY report the result.\n",
        );

        $result = $this->run('review', $root, $baseline);
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->not()->toContain('discouraged-word');
    }

    #[Test]
    public function outputIsDeterministicAndSortedByPath(): void
    {
        [$root, $baseline] = $this->workspace('deterministic-output');
        $this->write($root, 'z-last.md', "The worker doesn't stop.\n");
        $this->write($root, 'a-first.md', "The worker doesn't start.\n");

        $first = $this->run('check', $root, $baseline);
        $second = $this->run('check', $root, $baseline);
        $firstPosition = \strpos($first->output(), 'a-first.md:');
        $lastPosition = \strpos($first->output(), 'z-last.md:');

        Expect::that($first->exitCode)->toBe(1)
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
        [$root, $baseline] = $this->workspace('fixture-exclusion');
        $this->write(
            $root,
            'tests/Fixture/Invalid.php',
            "<?php\n\n// The worker doesn't use the configured colour; it stops.\n",
        );

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(0);
    }

    #[Test]
    public function excludesDependenciesAtAnyDirectoryDepth(): void
    {
        [$root, $baseline] = $this->workspace('dependency-exclusion');
        $invalid = "The worker doesn't use the configured colour; it stops.\n";
        $this->write($root, 'vendor/package/README.md', $invalid);
        $this->write($root, 'website/node_modules/package/README.md', $invalid);
        $this->write($root, 'packages/example/vendor/package/README.md', $invalid);
        $this->write($root, 'packages/example/node_modules/package/README.md', $invalid);

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(0);
    }

    #[Test]
    public function createsAnInitialBaselineThatAllowsExistingFindings(): void
    {
        [$root, $baseline] = $this->workspace('baseline-create');
        $this->write($root, 'sample.md', "The worker doesn't stop.\n");

        $created = $this->run('baseline', $root, $baseline, '--create');
        $checked = $this->run('check', $root, $baseline);
        $matchedBaselineFiles = \glob($baseline . '/*');
        $baselineFiles = $matchedBaselineFiles === false ? [] : $matchedBaselineFiles;

        Expect::that($created->exitCode)->toBe(0)
            ->and($baselineFiles)->not()->toBeEmpty()
            ->and($checked->exitCode)->toBe(0);
    }

    #[Test]
    public function refusesToReplaceAnExistingBaseline(): void
    {
        [$root, $baseline] = $this->workspace('baseline-create-twice');
        $this->write($root, 'sample.md', "The worker doesn't stop.\n");

        $created = $this->run('baseline', $root, $baseline, '--create');
        $repeated = $this->run('baseline', $root, $baseline, '--create');

        Expect::that($created->exitCode)->toBe(0)
            ->and($repeated->exitCode)->toBe(1)
            ->and($repeated->output())->toContain('already exists');
    }

    #[Test]
    public function newFindingsFailAgainstAnExistingBaseline(): void
    {
        [$root, $baseline] = $this->workspace('baseline-new');
        $this->write($root, 'sample.md', "The worker doesn't stop.\n");
        Expect::that($this->run('baseline', $root, $baseline, '--create')->exitCode)->toBe(0);

        $this->write(
            $root,
            'sample.md',
            "The worker doesn't stop.\n\nThe reporter uses a different colour.\n",
        );

        $result = $this->run('check', $root, $baseline);
        Expect::that($result->exitCode)->toBe(1)
            ->and(\strtolower($result->output()))->toContain('new')
            ->and($result->output())->toContain('british-spelling');
    }

    #[Test]
    public function staleFindingsFailUntilTheBaselineIsPruned(): void
    {
        [$root, $baseline] = $this->workspace('baseline-stale');
        $this->write($root, 'sample.md', "The worker doesn't stop.\n");
        Expect::that($this->run('baseline', $root, $baseline, '--create')->exitCode)->toBe(0);

        $this->write($root, 'sample.md', "The worker does not stop.\n");

        $stale = $this->run('check', $root, $baseline);
        $pruned = $this->run('baseline', $root, $baseline, '--prune');
        $checked = $this->run('check', $root, $baseline);

        Expect::that($stale->exitCode)->toBe(1)
            ->and(\strtolower($stale->output()))->toContain('stale')
            ->and($pruned->exitCode)->toBe(0)
            ->and($checked->exitCode)->toBe(0);
    }

    #[Test]
    public function baselineFingerprintsIncludeDuplicateFindingCounts(): void
    {
        [$root, $baseline] = $this->workspace('baseline-duplicates');
        $duplicate = "The worker doesn't stop.\n\nThe worker doesn't stop.\n";
        $this->write($root, 'sample.md', $duplicate);
        Expect::that($this->run('baseline', $root, $baseline, '--create')->exitCode)->toBe(0);
        $baselineJson = (string) \file_get_contents($baseline . '/root.json');
        $expectedFingerprint = \hash(
            'sha256',
            "sample.md\0contraction\0the worker doesn't stop.\0" . '2',
        );

        $this->write($root, 'sample.md', "The worker doesn't stop.\n");

        $result = $this->run('check', $root, $baseline);
        Expect::that($baselineJson)->toContain($expectedFingerprint)
            ->toContain('"count": 2')
            ->and($result->exitCode)->toBe(1)
            ->and(\strtolower($result->output()))->toContain('stale');
    }

    #[Test]
    public function pruneRefusesToAddNewFindings(): void
    {
        [$root, $baseline] = $this->workspace('baseline-prune-refusal');
        $this->write($root, 'sample.md', "The worker doesn't stop.\n");
        Expect::that($this->run('baseline', $root, $baseline, '--create')->exitCode)->toBe(0);
        $before = $this->baselineContents($baseline);

        $this->write(
            $root,
            'sample.md',
            "The worker doesn't stop.\n\nThe reporter uses a different colour.\n",
        );

        $pruned = $this->run('baseline', $root, $baseline, '--prune');
        $after = $this->baselineContents($baseline);
        $checked = $this->run('check', $root, $baseline);

        Expect::that($pruned->exitCode)->toBe(1)
            ->and(\strtolower($pruned->output()))->toContain('new')
            ->and($after)->toBe($before)
            ->and($checked->exitCode)->toBe(1);
    }

    /**
     * @return array{string, string}
     */
    private function workspace(string $name): array
    {
        $directory = $this->tempDirectory->subdirectory($name);
        $root = $directory . '/project';

        \mkdir($root);

        return [$root, $directory . '/baseline'];
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

    private function run(
        string $command,
        string $root,
        string $baseline,
        ?string $operation = null,
    ): ProcessResult {
        $arguments = [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/prose-check.php',
            $command,
        ];

        if ($operation !== null) {
            $arguments[] = $operation;
        }

        $arguments[] = '--root=' . $root;
        $arguments[] = '--baseline-dir=' . $baseline;

        return Subprocess::run($root, $arguments);
    }

    private function baselineContents(string $baseline): string
    {
        $matchedFiles = \glob($baseline . '/*');
        $files = $matchedFiles === false ? [] : $matchedFiles;
        \sort($files);
        $contents = '';

        foreach ($files as $file) {
            $contents .= \basename($file) . "\n" . \file_get_contents($file);
        }

        return $contents;
    }
}
