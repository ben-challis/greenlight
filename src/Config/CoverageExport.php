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
            throw InvalidConfiguration::emptyCoverageExport();
        }

        if (!\in_array($format, self::FORMATS, true)) {
            throw InvalidConfiguration::unknownCoverageFormat($format);
        }

        if (\str_contains($target, "\0")) {
            throw InvalidConfiguration::coverageTargetContainsNullByte();
        }

        $this->format = $format;
        $this->target = $target;
    }
}
