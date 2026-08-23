<?php

declare(strict_types=1);

namespace Greenlight\Cli\Input;

/**
 * Defines CLI commands, options, help text, and completion metadata.
 *
 * @internal
 */
final readonly class Definition
{
    public const array COMPLETION_SHELLS = ['bash', 'zsh', 'fish'];

    /** Built-in reporter names that completions suggest. Configured names remain valid. */
    public const array BUILT_IN_REPORTERS = ['tty', 'plain', 'junit', 'jsonl', 'github', 'teamcity'];

    /** @var array<non-empty-string, non-empty-string> */
    public const array COMMAND_DESCRIPTIONS = [
        'run' => 'Find and run tests (default)',
        'list-tests' => 'List each found test ID, one per line',
        'coverage:diff' => 'Compare two coverage JSON exports',
        'profile:report' => 'Create a run profile from a saved JSONL stream',
        'ide-helper' => 'Write the IDE autocomplete helper for extension matchers',
        'completion' => 'Print a shell completion script to standard output',
    ];

    /** @var array<non-empty-string, list<non-empty-string>> */
    public const array COMPLETION_VALUES = [
        'reporter' => self::BUILT_IN_REPORTERS,
        'workers' => ['auto'],
    ];

    public const string HELP = <<<'HELP'
        Greenlight

        Usage:
          greenlight [command] [options]

        Commands:
          run            Find and run tests (default)
          list-tests     List each found test ID, one per line
          coverage:diff  Compare two coverage JSON exports. Fail if total coverage
                         decreases or a line becomes newly uncovered.
          profile:report Create a run profile from a saved JSONL stream (--input)
          ide-helper     Write the IDE autocomplete helper for extension matchers
                         (--output, default _greenlight_ide_helper.php)
          completion     Print a shell completion script for bash, zsh, or fish
                         to standard output, for example:
                         source <(greenlight completion bash)

        Options:
          --config=<path>    Use this configuration file instead of ./greenlight.php
          --workers=<n|auto> Set the worker process count
          --resource-limit=<name>=<n>
                             Set a named resource limit. You can repeat this option.
          --bail[=<n>]       Stop after <n> failures (default 1)
          --group=<name>     Run only this group. You can repeat this option.
          --filter=<pattern> Run only tests with a matching test ID. Use a
                             substring or a full match with * wildcards.
                             You can repeat this option.
          --test-id=<id>     Run only this exact test ID. You can repeat this option.
          --test-id-file=<path>
                             Read exact test IDs from a newline-delimited file.
                             You can repeat this option.
          --exclude-group=<name>     Skip tests in this group. You can repeat this option.
          --exclude-class=<pattern>  Skip classes that match this pattern.
                             Matching is case-sensitive. Use a substring or * wildcards.
                             You can repeat this option.
          --exclude-method=<pattern> Skip methods that match this pattern.
                             Matching is case-sensitive. Use a substring or * wildcards.
                             You can repeat this option.
          --exclude-path=<prefix>    Skip test files under this path prefix.
                             Greenlight resolves relative prefixes against the
                             working directory. You can repeat this option.
          --failed           Run only tests that failed or had an error in the previous run
          --list-tests       Print the selected test IDs. Do not run the tests.
          --list-groups      Print each selected group and its test count
          --list-suites      Print the configured suites
          --repeat=<n>       Run the selected tests n times in separate runs.
                             A failed iteration fails the command.
          --repeat-until-failure  Repeat until an iteration fails, up to
                             --repeat times (default at most 100)
                             Do not use repeat modes with JUnit output or coverage.
          --shard=<n>/<m>    Run shard n of m. Shards are disjoint and contain
                             whole classes. They are stable across machines and
                             need no coordination.
          --seed=<n>         Randomize class order with this seed
          --reporter=<name>[=<path>]
                             Select a built-in or configured reporter name.
                             Write to the file when one is specified.
                             Built-ins: tty, plain, junit, jsonl, github, teamcity.
                             You can repeat this option.
          --artifacts-dir=<path> Persistent directory for retained test attachments
          --coverage-map=<path>
                             Write versioned per-test coverage JSONL.
          --coverage-include=<path>
                             Add a source root for command-line coverage.
                             You can repeat this option.
          --no-coverage      Disable configured coverage for this run.
          --watch            Run selected tests at startup and after file changes.
                             Enter reruns them. q quits with exit code 0.
          --detect-leaks     Verify collection of each test instance. Leaks fail the run.
          --verbose          Print a permanent line per completed class in
                             interactive output
          --ansi             Enable colors in append-only reporter output.
          --no-ansi          Disable colors and the live progress window.
                             Use plain append-only output.
          --fail-on-deprecation  Fail passed tests that captured a deprecation
          --fail-on-notice   Fail passed tests that captured a notice
          --fail-on-risky    Fail passed tests that verified no expectations
          --profile          Add a run profile after the summary. It contains worker
                             utilization, boot latency, makespan spread, and slow classes.
                             It also extends the slow-test list.
          --dry-run          Print a run-settings summary without test discovery
                             or execution.
          -h, --help         Show this help
          -V, --version      Show the version

        HELP;

    public function parser(): ArgumentParser
    {
        return new ArgumentParser($this->options());
    }

    /** @return list<OptionSpec> */
    public function options(): array
    {
        return [
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('resource-limit', OptionValue::Required, repeatable: true),
            new OptionSpec('bail', OptionValue::Optional),
            new OptionSpec('group', OptionValue::Required, repeatable: true),
            new OptionSpec('filter', OptionValue::Required, repeatable: true),
            new OptionSpec('test-id', OptionValue::Required, repeatable: true),
            new OptionSpec('test-id-file', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-group', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-class', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-method', OptionValue::Required, repeatable: true),
            new OptionSpec('exclude-path', OptionValue::Required, repeatable: true),
            new OptionSpec('list-tests'), new OptionSpec('list-groups'), new OptionSpec('list-suites'),
            new OptionSpec('repeat', OptionValue::Required), new OptionSpec('repeat-until-failure'),
            new OptionSpec('failed'), new OptionSpec('shard', OptionValue::Required),
            new OptionSpec('fail-on-deprecation'), new OptionSpec('fail-on-notice'), new OptionSpec('fail-on-risky'),
            new OptionSpec('seed', OptionValue::Required),
            new OptionSpec('reporter', OptionValue::Required, repeatable: true),
            new OptionSpec('artifacts-dir', OptionValue::Required),
            new OptionSpec('coverage-map', OptionValue::Required),
            new OptionSpec('coverage-include', OptionValue::Required, repeatable: true),
            new OptionSpec('no-coverage'),
            new OptionSpec('baseline', OptionValue::Required), new OptionSpec('current', OptionValue::Required),
            new OptionSpec('watch'), new OptionSpec('detect-leaks'), new OptionSpec('dry-run'),
            new OptionSpec('ansi'), new OptionSpec('no-ansi'), new OptionSpec('verbose'), new OptionSpec('profile'),
            new OptionSpec('input', OptionValue::Required), new OptionSpec('output', OptionValue::Required),
            new OptionSpec('help', short: 'h'), new OptionSpec('version', short: 'V'),
        ];
    }

    /** @return list<non-empty-string> */
    public function commands(): array
    {
        return \array_keys(self::COMMAND_DESCRIPTIONS);
    }
}
