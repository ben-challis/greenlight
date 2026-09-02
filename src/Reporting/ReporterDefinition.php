<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Defines one command-line reporter name and its factory.
 *
 * The name starts with a lowercase ASCII letter. The remaining characters
 * are lowercase ASCII letters, digits, or hyphens.
 */
final readonly class ReporterDefinition
{
    /**
     * @param non-empty-string $name
     * @param \Closure(Output): Reporter $factory Return a new reporter for each
     *   call. Greenlight owns the supplied output.
     *
     * @throws \InvalidArgumentException if the reporter name is invalid
     */
    public function __construct(
        public string $name,
        public \Closure $factory,
    ) {
        if (\preg_match('/\A[a-z][a-z0-9-]*\z/D', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Reporter name "%s" must start with a lowercase ASCII letter and contain only lowercase ASCII letters, digits, or hyphens.',
                $name,
            ));
        }
    }
}
