<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Uses the tokenizer to get class-like declarations from a PHP file.
 * Discovery can then give a fully qualified name to the autoloader.
 * It does not execute an unknown file directly.
 *
 * @internal
 */
final class ClassFileParser
{
    #[CoverageIgnore]
    private function __construct() {}

    /**
     * @return list<ClassDeclaration>
     *
     * @throws DiscoveryError when the file cannot be read
     */
    public static function declarationsIn(string $file): array
    {
        $code = ErrorTrap::run(
            static fn() => \file_get_contents($file),
            $warning,
            wrap: static fn(\Throwable $error): DiscoveryError =>
                DiscoveryError::unreadableFile($file, $error->getMessage(), $error),
        );

        if ($code === false) {
            throw DiscoveryError::unreadableFile($file, $warning);
        }

        $tokens = \array_values(\PhpToken::tokenize($code));
        $count = \count($tokens);
        $namespace = '';
        $declarations = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token->is(\T_NAMESPACE)) {
                $name = self::nextSignificant($tokens, $i);
                $namespace = $name instanceof \PhpToken && $name->is([\T_NAME_QUALIFIED, \T_STRING])
                    ? $name->text
                    : '';

                continue;
            }

            $kind = match (true) {
                $token->is(\T_CLASS) => 'class',
                $token->is(\T_INTERFACE) => 'interface',
                $token->is(\T_TRAIT) => 'trait',
                $token->is(\T_ENUM) => 'enum',
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $name = self::nextSignificant($tokens, $i);

            if (!$name instanceof \PhpToken || !$name->is(\T_STRING)) {
                continue;
            }

            $declarations[] = new ClassDeclaration($namespace, $name->text, $kind);
        }

        return $declarations;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?\PhpToken
    {
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; ++$i) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
