<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CustomCommandTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function configuredCommandReceivesRawArgumentsContextAndOutputChannels(): void
    {
        $project = $this->project('custom-command', <<<'PHP'
            final class CompanyCommandProvider implements CommandProvider
            {
                public function commands(): array
                {
                    return [new CommandDefinition(
                        'company:hello',
                        'Print a company greeting',
                        static function (CommandInvocation $invocation): ExitCode {
                            $invocation->write("cwd=" . $invocation->workingDirectory . "\n");
                            $invocation->write("args=" . implode('|', $invocation->arguments) . "\n");
                            $invocation->writeError("company diagnostic\n");

                            return ExitCode::fromInt(7);
                        },
                    )];
                }
            }

            return GreenlightConfig::create()->plugins(
                static fn(): CompanyCommandProvider => new CompanyCommandProvider(),
            );
            PHP);

        $result = GreenlightCli::run($project->directory, ['company:hello', 'Ben', '--mode=brief']);

        Expect::that($result->exitCode)->toBe(7);
        Expect::that($result->stdout)
            ->toContain('cwd=' . $project->directory)
            ->toContain('args=Ben|--mode=brief');
        Expect::that($result->stderr)->toBe('company diagnostic');
    }

    #[Test]
    public function customAndBundledCommandsShareOneNameRegistry(): void
    {
        $project = $this->project('duplicate-command', <<<'PHP'
            final class DuplicateCommandProvider implements CommandProvider
            {
                public function commands(): array
                {
                    return [
                        new CommandDefinition('run', 'Replace the run command', static fn(CommandInvocation $invocation): ExitCode => ExitCode::success()),
                        new CommandDefinition('company:probe', 'Load this provider', static fn(CommandInvocation $invocation): ExitCode => ExitCode::success()),
                    ];
                }
            }

            return GreenlightConfig::create()->plugins(
                static fn(): DuplicateCommandProvider => new DuplicateCommandProvider(),
            );
            PHP);

        $result = GreenlightCli::run($project->directory, ['company:probe', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stdout)->toBe('');
        Expect::that($result->stderr)->toBe('greenlight: Command name "run" is registered more than once.');
    }

    #[Test]
    public function commandFailuresAreContainedAtThePluginBoundary(): void
    {
        $project = $this->project('failed-command', <<<'PHP'
            final class FailedCommandProvider implements CommandProvider
            {
                public function commands(): array
                {
                    return [
                        new CommandDefinition(
                            'company:throws',
                            'Throw from a command',
                            static fn(CommandInvocation $invocation): ExitCode => throw new RuntimeException('Command exploded'),
                        ),
                        new CommandDefinition(
                            'company:invalid-exit',
                            'Return an invalid exit code',
                            static fn(CommandInvocation $invocation): ExitCode => ExitCode::fromInt(300),
                        ),
                    ];
                }
            }

            return GreenlightConfig::create()->plugins(
                static fn(): FailedCommandProvider => new FailedCommandProvider(),
            );
            PHP);

        $threw = GreenlightCli::run($project->directory, ['company:throws', '--no-ansi']);
        Expect::that($threw->exitCode)->toBe(1);
        Expect::that($threw->stderr)
            ->toBe('greenlight: Command "company:throws" caused an error: Command exploded');

        $invalid = GreenlightCli::run($project->directory, ['company:invalid-exit', '--no-ansi']);
        Expect::that($invalid->exitCode)->toBe(1);
        Expect::that($invalid->stderr)
            ->toBe('greenlight: Command "company:invalid-exit" caused an error: Exit code MUST be from 0 through 255.');
    }

    private function project(string $name, string $body): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Command\ExitCode;
            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\CommandDefinition;
            use Greenlight\Plugin\CommandInvocation;
            use Greenlight\Plugin\CommandProvider;

            %s

            PHP,
            $body,
        ));

        return $project;
    }
}
