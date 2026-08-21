<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

final readonly class WorkflowShellCheckTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function acceptsExplicitBashForMultilineRunSteps(): void
    {
        $root = $this->workspace('explicit-bash');
        $this->writeWorkflow(
            $root,
            <<<'YAML'
            name: Shell contract
            on: push
            jobs:
              check:
                runs-on: ubuntu-latest
                steps:
                  - name: "Run checks"
                    run: |
                      first-command | second-command
                    shell: bash
            YAML,
        );

        $result = $this->run($root);

        Expect::that($result->exitCode)->because('accepts an explicit Bash shell')->toBe(0);
        Expect::that($result->stdout)->toBe('Workflow shell contracts passed.');
    }

    #[Test]
    public function rejectsMultilineRunStepsWithoutExplicitBash(): void
    {
        $root = $this->workspace('implicit-shell');
        $this->writeWorkflow(
            $root,
            <<<'YAML'
            name: Shell contract
            on: push
            jobs:
              check:
                runs-on: ubuntu-latest
                steps:
                  - name: "Run checks"
                    run: |
                      first-command | second-command
            YAML,
        );

        $result = $this->run($root);

        Expect::that($result->exitCode)->because('rejects an implicit shell')->toBe(1);
        Expect::that($result->stderr)->toContain('workflow.yml:8: Multiline run step "Run checks" does not set `shell: bash`.')
            ->toContain('Set `shell: bash` on each multiline `run` step.');
    }

    #[Test]
    public function rejectsFoldedMultilineRunStepsWithoutExplicitBash(): void
    {
        $root = $this->workspace('folded-implicit-shell');
        $this->writeWorkflow(
            $root,
            <<<'YAML'
            name: Shell contract
            on: push
            jobs:
              check:
                runs-on: ubuntu-latest
                steps:
                  - name: "Run folded checks"
                    run: >-
                      first-command |
                      second-command
            YAML,
        );

        $result = $this->run($root);

        Expect::that($result->exitCode)->because('rejects a folded block with an implicit shell')->toBe(1);
        Expect::that($result->stderr)->toContain('Multiline run step "Run folded checks" does not set `shell: bash`.');
    }

    private function workspace(string $name): string
    {
        return $this->tempDirectory->subdirectory($name);
    }

    private function writeWorkflow(string $root, string $contents): void
    {
        \file_put_contents($root . '/workflow.yml', $contents . "\n");
    }

    private function run(string $root): ProcessResult
    {
        return Subprocess::run($root, [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/workflow-shell-check.php',
            'workflow.yml',
        ]);
    }
}
