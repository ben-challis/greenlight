<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\ExitCode;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Internal\Filesystem\AtomicFile;
use Greenlight\Internal\Filesystem\AtomicFileError;
use Greenlight\PhpStan\IdeHelper;
use Greenlight\PhpStan\MatcherMap;
use Greenlight\PhpStan\MatcherMapError;

/**
 * Writes the IDE helper for configured extension matchers.
 *
 * @internal
 */
final readonly class IdeHelperCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): ExitCode
    {
        try {
            $configFile = $arguments->value('config') ?? \rtrim($workingDirectory, '/') . '/' . ConfigLoader::FILE_NAME;
            $map = MatcherMap::fromConfigFiles([ConfigurationLoader::absolutePath($configFile, $workingDirectory)]);
        } catch (ConfigFileError|InvalidConfiguration|MatcherMapError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return ExitCode::failure();
        }
        if ($map->names() === []) {
            $this->console->out("The configuration has no extension matchers. There is no helper to generate.\n");
            return ExitCode::success();
        }
        $output = $arguments->value('output') ?? '_greenlight_ide_helper.php';
        $path = ConfigurationLoader::absolutePath($output, $workingDirectory);
        try {
            AtomicFile::write($path, IdeHelper::render($map));
        } catch (AtomicFileError $error) {
            $this->console->err(\sprintf("Greenlight could not write \"%s\": %s\n", $path, $error->getMessage()));
            return ExitCode::failure();
        }
        $this->console->out(
            \sprintf("Wrote %s with %d matchers. Add it to .gitignore. Generate it again after matcher changes.\n", $path, \count($map->names())),
        );

        return ExitCode::success();
    }
}
