<?php

declare(strict_types=1);

namespace Greenlight\Cli\Output;

use Greenlight\Reporting\Style;

/**
 * Styles help text without changes to its content or spacing.
 * Headings have no indentation. Entry labels start after two spaces.
 *
 * @internal
 */
final readonly class HelpFormatter
{
    public function __construct(private Style $style) {}

    public function format(string $text): string
    {
        return \implode("\n", \array_map($this->formatLine(...), \explode("\n", $text)));
    }

    private function formatLine(string $line): string
    {
        if ($line === 'Greenlight') {
            return $this->style->ok($line);
        }

        if ($line !== '' && $line[0] !== ' ' && \str_ends_with($line, ':')) {
            return $this->style->heading($line);
        }

        if (\preg_match('/^  ([^\s,]+(?:, --\S+)?)/', $line, $matches) === 1) {
            return '  ' . $this->style->label($matches[1]) . \substr($line, \strlen($matches[0]));
        }

        return $line;
    }
}
