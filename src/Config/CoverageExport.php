<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** @internal */
final readonly class CoverageExport
{
    private const array FORMATS = ['json', 'lcov', 'clover', 'cobertura', 'html'];

    /**
     * @var non-empty-string
     */
    public string $format;

    /**
     * @var non-empty-string
     */
    public string $target;

    /**
     * @throws InvalidConfiguration
     */
    public function __construct(string $format, string $target)
    {
        if ($format === '' || $target === '') {
            throw new InvalidConfiguration('Coverage exports need a non-empty format and target.');
        }

        if (!\in_array($format, self::FORMATS, true)) {
            throw new InvalidConfiguration(\sprintf(
                'Unknown coverage export format "%s". Use "json", "lcov", "clover", "cobertura", or "html".',
                $format,
            ));
        }

        if (\str_contains($target, "\0")) {
            throw new InvalidConfiguration('Coverage export target cannot contain a null byte.');
        }

        $this->format = $format;
        $this->target = $target;
    }
}
