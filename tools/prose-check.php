<?php

declare(strict_types=1);

const PROSE_EXCLUDED_DIRECTORIES = [
    '.claude/worktrees',
    '.git',
    '.phpstan-api-stubs',
    'tests/Fixture',
    'website/dist',
];

const PROSE_EXCLUDED_DIRECTORY_NAMES = [
    'node_modules',
    'vendor',
];

const PROSE_CONTRACTIONS = [
    "ain't",
    "aren't",
    "can't",
    "couldn't",
    "couldn't've",
    "didn't",
    "doesn't",
    "don't",
    "hadn't",
    "hasn't",
    "haven't",
    "he'd",
    "he'll",
    "he's",
    "how'd",
    "how'll",
    "how's",
    "i'd",
    "i'll",
    "i'm",
    "i've",
    "isn't",
    "it'd",
    "it'll",
    "it's",
    "let's",
    "mightn't",
    "might've",
    "mustn't",
    "must've",
    "needn't",
    "oughtn't",
    "she'd",
    "she'll",
    "she's",
    "shouldn't",
    "shouldn't've",
    "that's",
    "there's",
    "they'd",
    "they'll",
    "they're",
    "they've",
    "wasn't",
    "we'd",
    "we'll",
    "we're",
    "we've",
    "weren't",
    "what'd",
    "what's",
    "when's",
    "where'd",
    "where's",
    "who'd",
    "who'll",
    "who's",
    "why's",
    "won't",
    "would've",
    "wouldn't",
    "wouldn't've",
    "you'd",
    "you'll",
    "you're",
    "you've",
];

const PROSE_BRITISH_SPELLINGS = [
    'analyse',
    'analysed',
    'analyses',
    'analysing',
    'artefact',
    'artefacts',
    'authorisation',
    'authorisations',
    'authorise',
    'authorised',
    'authorises',
    'authorising',
    'behaviour',
    'behaviours',
    'cancelled',
    'cancelling',
    'catalogue',
    'catalogued',
    'catalogues',
    'cataloguing',
    'centre',
    'centred',
    'centres',
    'centring',
    'colour',
    'coloured',
    'colouring',
    'colours',
    'customisation',
    'customisations',
    'customise',
    'customised',
    'customises',
    'customising',
    'defence',
    'defences',
    'deserialisable',
    'deserialisation',
    'deserialised',
    'deserialises',
    'deserialising',
    'favour',
    'favoured',
    'favouring',
    'favourite',
    'favourites',
    'favours',
    'fulfil',
    'fulfilled',
    'fulfilling',
    'fulfils',
    'grey',
    'greys',
    'honour',
    'honoured',
    'honouring',
    'honours',
    'initialise',
    'initialised',
    'initialises',
    'initialising',
    'licence',
    'licenced',
    'licences',
    'licencing',
    'localisation',
    'localisations',
    'localise',
    'localised',
    'localises',
    'localising',
    'labelled',
    'labelling',
    'normalise',
    'normalised',
    'normalises',
    'normalising',
    'optimise',
    'optimised',
    'optimises',
    'optimising',
    'organisation',
    'organisations',
    'organise',
    'organised',
    'organises',
    'organising',
    'parameterise',
    'parameterised',
    'parameterises',
    'parameterising',
    'pluralise',
    'pluralised',
    'pluralises',
    'pluralising',
    'prioritise',
    'prioritised',
    'prioritises',
    'prioritising',
    'recognise',
    'recognised',
    'recognises',
    'recognising',
    'serialise',
    'serialised',
    'serialises',
    'serialising',
    'singularise',
    'singularised',
    'singularises',
    'singularising',
    'summarise',
    'summarised',
    'summarises',
    'summarising',
    'travelling',
    'utilisation',
];

const PROSE_DISCOURAGED_WORDS = [
    'any',
    'ensure',
    'follow',
    'further',
    'however',
    'main',
    'may',
    'perform',
    'shall',
    'should',
    'using',
];

const PROSE_REGISTERED_LITERALS = [
    'dev-main',
];

const PROSE_ALLOWED_ING_WORDS = [
    'anything',
    'during',
    'everything',
    'marketing',
    'meaning',
    'missing',
    'nothing',
    'opening',
    'remaining',
    'running',
    'scheduling',
    'something',
    'spelling',
    'staging',
    'string',
    'substring',
    'testing',
    'timing',
    'warning',
    'writing',
];

const PROSE_IMPERATIVE_VERBS = [
    'add',
    'apply',
    'attach',
    'check',
    'close',
    'configure',
    'connect',
    'create',
    'delete',
    'disconnect',
    'install',
    'open',
    'read',
    'remove',
    'replace',
    'run',
    'save',
    'select',
    'set',
    'start',
    'stop',
    'use',
    'write',
];

function proseFail(string $message): never
{
    \fwrite(\STDERR, $message . "\n");

    exit(1);
}

/**
 * @param list<string> $arguments
 *
 * @return array{command: string, root: string}
 */
function proseArguments(array $arguments): array
{
    \array_shift($arguments);
    $command = 'check';
    $root = \dirname(__DIR__);

    if (isset($arguments[0]) && !\str_starts_with($arguments[0], '--')) {
        $command = \array_shift($arguments);
    }

    foreach ($arguments as $argument) {
        if (\str_starts_with($argument, '--root=')) {
            $root = \substr($argument, \strlen('--root='));

            continue;
        }

        \proseFail(\sprintf('Unknown prose-check option "%s".', $argument));
    }

    if (!\in_array($command, ['check', 'review'], true)) {
        \proseFail(\sprintf('Unknown prose-check command "%s".', $command));
    }

    $resolvedRoot = \realpath($root);

    if ($resolvedRoot === false || !\is_dir($resolvedRoot)) {
        \proseFail(\sprintf('Prose-check root "%s" does not exist.', $root));
    }

    return [
        'command' => $command,
        'root' => $resolvedRoot,
    ];
}

/**
 * @return list<string>
 */
function proseFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        $relative = \proseRelativePath($root, $path);

        if (\proseIsExcluded($relative)) {
            continue;
        }

        if (!\in_array(\strtolower($file->getExtension()), ['astro', 'js', 'json', 'md', 'markdown', 'mjs', 'neon', 'php', 'ts', 'tsx', 'yaml', 'yml'], true)) {
            continue;
        }

        $files[] = $path;
    }

    \sort($files);

    return $files;
}

function proseIsExcluded(string $relativePath): bool
{
    if (\array_any(PROSE_EXCLUDED_DIRECTORIES, fn($directory) => $relativePath === $directory || \str_starts_with($relativePath, $directory . '/'))) {
        return true;
    }

    $segments = \explode('/', $relativePath);

    return \array_any(PROSE_EXCLUDED_DIRECTORY_NAMES, fn(string $directory): bool => \in_array($directory, $segments, true));
}

function proseRelativePath(string $root, string $path): string
{
    return \str_replace('\\', '/', \substr($path, \strlen($root) + 1));
}

/**
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function prosePassages(string $root, string $path): array
{
    $relative = \proseRelativePath($root, $path);
    $contents = \file_get_contents($path);

    if (!\is_string($contents)) {
        \proseFail(\sprintf('Cannot read "%s".', $relative));
    }

    $extension = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));

    if ($extension === 'php') {
        return \prosePhpPassages($relative, $contents);
    }

    if ($extension === 'astro') {
        return \proseAstroPassages($relative, $contents);
    }

    if (\in_array($extension, ['js', 'json', 'mjs', 'neon', 'ts', 'tsx', 'yaml', 'yml'], true)) {
        return \proseStructuredPassages($relative, $contents, $extension);
    }

    return \proseMarkdownPassages($relative, $contents);
}

/**
 * Extracts text fields and owned comments from structured text files.
 *
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function proseStructuredPassages(string $path, string $contents, string $extension): array
{
    if ($extension === 'json') {
        return \proseJsonPassages($path, $contents);
    }

    if (\in_array($extension, ['js', 'mjs', 'ts', 'tsx'], true)) {
        return \proseScriptPassages($path, $contents);
    }

    $passages = [];
    $splitLines = \preg_split('/\R/', $contents);
    $lines = $splitLines === false ? [] : $splitLines;
    $counter = \count($lines);

    for ($offset = 0; $offset < $counter; ++$offset) {
        $line = $lines[$offset];
        $lineNumber = $offset + 1;
        $text = null;

        if (\preg_match('/^(\s*)(?:description|label|name|placeholder|title)\s*:\s*(.+?)\s*$/', $line, $matches) === 1) {
            if (\in_array(\trim($matches[2]), ['|', '>', '|-', '>-'], true)) {
                $indent = \strlen($matches[1]);
                $block = [];

                while (isset($lines[$offset + 1])) {
                    $next = $lines[$offset + 1];
                    $nextIndent = \strlen($next) - \strlen(\ltrim($next));

                    if (\trim($next) !== '' && $nextIndent <= $indent) {
                        break;
                    }

                    ++$offset;
                    $block[] = \trim($next);
                }

                $text = \implode(' ', $block);
            } else {
                $text = \proseStructuredScalar($matches[2]);
            }
        } elseif (\preg_match('/^\s*#\s+(?!@)(.+)$/', $line, $matches) === 1) {
            $text = $matches[1];
        }

        if ($text === null || \preg_match('/[A-Za-z]/', $text) !== 1) {
            continue;
        }

        $passages[] = [
            'path' => $path,
            'line' => $lineNumber,
            'text' => \trim((string) \preg_replace('/\s+/', ' ', $text)),
        ];
    }

    return $passages;
}

/**
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function proseJsonPassages(string $path, string $contents): array
{
    $passages = [];
    $pattern = '/"(description|label|placeholder|title)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s';

    if (\preg_match_all($pattern, $contents, $matches, \PREG_OFFSET_CAPTURE) !== false) {
        foreach ($matches[2] as $match) {
            [$encoded, $offset] = $match;
            $decoded = \json_decode('"' . $encoded . '"', true);
            $text = \is_string($decoded) ? $decoded : $encoded;

            if (\preg_match('/[A-Za-z]/', $text) !== 1) {
                continue;
            }

            $passages[] = [
                'path' => $path,
                'line' => \proseLineAtOffset($contents, $offset),
                'text' => \trim((string) \preg_replace('/\s+/', ' ', $text)),
            ];
        }
    }

    if (\preg_match('/"suggest"\s*:\s*\{(.*?)\}/s', $contents, $suggest, \PREG_OFFSET_CAPTURE) === 1) {
        $body = $suggest[1][0];
        $bodyOffset = $suggest[1][1];

        if (\preg_match_all('/"[^"]+"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s', $body, $values, \PREG_OFFSET_CAPTURE) !== false) {
            foreach ($values[1] as $match) {
                [$encoded, $offset] = $match;
                $decoded = \json_decode('"' . $encoded . '"', true);
                $text = \is_string($decoded) ? $decoded : $encoded;

                if (!\proseLooksLikeHumanString($text)) {
                    continue;
                }

                $passages[] = [
                    'path' => $path,
                    'line' => \proseLineAtOffset($contents, $bodyOffset + $offset),
                    'text' => $text,
                ];
            }
        }
    }

    return $passages;
}

/**
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function proseScriptPassages(string $path, string $contents): array
{
    $passages = [];

    if (\preg_match_all('/^\s*\/\/\s+(?!@)(.+)$/m', $contents, $comments, \PREG_OFFSET_CAPTURE) !== false) {
        foreach ($comments[1] as [$text, $offset]) {
            $passages[] = ['path' => $path, 'line' => \proseLineAtOffset($contents, $offset), 'text' => $text];
        }
    }

    if (\preg_match_all('/([\'"`])((?:\\\\.|(?!\1).)*)\1/s', $contents, $strings, \PREG_OFFSET_CAPTURE) !== false) {
        foreach ($strings[2] as $index => [$encoded, $offset]) {
            $matchOffset = $strings[0][$index][1];
            $lineStart = \strrpos(\substr($contents, 0, $matchOffset), "\n");
            $linePrefix = \substr($contents, $lineStart === false ? 0 : $lineStart + 1, $matchOffset - ($lineStart === false ? 0 : $lineStart + 1));

            if (\preg_match('/\b(?:code|sample)\w*\s*=\s*$/i', $linePrefix) === 1) {
                continue;
            }

            $text = \stripcslashes($encoded);

            if (\preg_match('/\s/', $text) !== 1 || !\proseLooksLikeHumanString($text)) {
                continue;
            }

            $passages[] = [
                'path' => $path,
                'line' => \proseLineAtOffset($contents, $offset),
                'text' => \trim((string) \preg_replace('/\s+/', ' ', $text)),
            ];
        }
    }

    return $passages;
}

function proseLineAtOffset(string $contents, int $offset): int
{
    return \substr_count(\substr($contents, 0, $offset), "\n") + 1;
}

function proseStructuredScalar(string $value): ?string
{
    $value = \trim($value);

    if (\in_array($value, ['|', '>', '|-', '>-'], true)) {
        return null;
    }

    if (
        \strlen($value) >= 2
        && (($value[0] === '"' && \str_ends_with($value, '"')) || ($value[0] === "'" && \str_ends_with($value, "'")))
    ) {
        $quote = $value[0];
        $value = \substr($value, 1, -1);

        return $quote === '"' ? \stripcslashes($value) : \str_replace("''", "'", $value);
    }

    return (string) \preg_replace('/\s+#.*$/', '', $value);
}

/**
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function proseMarkdownPassages(string $path, string $contents): array
{
    $passages = [];
    $paragraph = [];
    $paragraphLine = 1;
    $inFence = false;
    $listItemIndent = null;
    $lines = \preg_split('/\R/', $contents);

    foreach ($lines === false ? [] : $lines as $offset => $line) {
        $lineNumber = $offset + 1;

        if (\preg_match('/^\s*(```|~~~)/', $line) === 1) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $inFence = !$inFence;

            continue;
        }

        if ($inFence) {
            continue;
        }

        $trimmed = \trim($line);
        $isListItem = \preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/', $line) === 1;
        $isHeading = \preg_match('/^\s*#{1,6}\s+/', $line) === 1;
        $isTableRow = \preg_match('/^\s*\|/', $line) === 1;
        $isTableSeparator = \preg_match('/^\s*\|?(?:\s*:?-+:?\s*\|)+\s*$/', $line) === 1;
        $isBoundary = $trimmed === ''
            || \preg_match('/^\s*<!--/', $line) === 1
            || \preg_match('/^\s*[-:| ]{3,}\s*$/', $line) === 1
            || $isTableSeparator;

        if ($isBoundary) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $listItemIndent = null;

            continue;
        }

        if ($isHeading) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $paragraphLine = $lineNumber;
            $paragraph[] = (string) \preg_replace('/^\s*#{1,6}\s+/', '', $line);
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $listItemIndent = null;

            continue;
        }

        if ($isTableRow) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $paragraphLine = $lineNumber;
            $paragraph[] = \str_replace('|', ' ', \trim($line, " \t|"));
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $listItemIndent = null;

            continue;
        }

        if ($isListItem) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $paragraphLine = $lineNumber;
            $paragraph[] = (string) \preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/', '', $line);
            \preg_match('/^(\s*)/', $line, $indentMatch);
            $listItemIndent = \strlen($indentMatch[1] ?? '') + 1;

            continue;
        }

        $indent = \strlen($line) - \strlen(\ltrim($line));

        if ($listItemIndent !== null && $indent >= $listItemIndent) {
            $paragraph[] = $trimmed;

            continue;
        }

        if ($listItemIndent !== null) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $listItemIndent = null;
        }

        if ($paragraph === []) {
            $paragraphLine = $lineNumber;
        }

        $paragraph[] = $trimmed;
    }

    \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);

    return $passages;
}

function proseCleanMarkdown(string $text): string
{
    $text = (string) \preg_replace('/!\[([^\]]*)]\([^)]*\)/', ' $1 ', $text);
    $text = (string) \preg_replace('/\[([^\]]+)]\([^)]*\)/', ' $1 ', $text);
    $text = (string) \preg_replace('/`[^`]*`/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/<https?:\/\/[^>]+>/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/https?:\/\/\S+/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/[*_]{1,3}/', '', $text);
    $text = (string) \preg_replace('/\s+/', ' ', $text);

    return \trim($text);
}

/**
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function proseAstroPassages(string $path, string $contents): array
{
    $passages = [];

    if (\preg_match('/\A---\R(.*?)\R---\R/s', $contents, $frontmatter, \PREG_OFFSET_CAPTURE) === 1) {
        $frontmatterText = $frontmatter[1][0];
        $frontmatterLine = \proseLineAtOffset($contents, $frontmatter[1][1]) - 1;

        foreach (\proseScriptPassages($path, $frontmatterText) as $passage) {
            $passage['line'] += $frontmatterLine;
            $passages[] = $passage;
        }
    }

    if (\preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $contents, $scripts, \PREG_OFFSET_CAPTURE) !== false) {
        foreach ($scripts[1] as [$script, $offset]) {
            $scriptLine = \proseLineAtOffset($contents, $offset) - 1;

            foreach (\proseScriptPassages($path, $script) as $passage) {
                $passage['line'] += $scriptLine;
                $passages[] = $passage;
            }
        }
    }

    $contents = (string) \preg_replace_callback(
        '/\A---\R.*?\R---\R/s',
        static fn(array $matches): string => \str_repeat("\n", \substr_count($matches[0], "\n")),
        $contents,
    );
    $contents = (string) \preg_replace_callback(
        '#<(?:script|style|code|pre)\b[^>]*>.*?</(?:script|style|code|pre)>#is',
        static fn(array $matches): string => ' LITERAL ' . \str_repeat("\n", \substr_count($matches[0], "\n")),
        $contents,
    );
    $contents = \proseMaskAstroExpressions($contents);
    $lines = \preg_split('/\R/', $contents);
    $paragraph = [];
    $paragraphLine = 1;

    foreach ($lines === false ? [] : $lines as $offset => $line) {
        $lineNumber = $offset + 1;

        if (\preg_match_all('/\b(?:alt|aria-label|description|placeholder|title)="([^"]+)"/i', $line, $matches) !== false) {
            foreach ($matches[1] as $attributeText) {
                if (!\str_contains($attributeText, '{')) {
                    $passages[] = [
                        'path' => $path,
                        'line' => $lineNumber,
                        'text' => \html_entity_decode($attributeText, \ENT_QUOTES | \ENT_HTML5),
                    ];
                }
            }
        }

        $visible = (string) \preg_replace('/\{[^{}]*\}/', ' LITERAL ', $line);
        $visible = (string) \preg_replace('/<[^>]+>/', ' ', $visible);
        $visible = \trim((string) \preg_replace('/\s+/', ' ', \html_entity_decode($visible, \ENT_QUOTES | \ENT_HTML5)));

        if ($visible !== '' && \preg_match('/[A-Za-z]/', $visible) === 1) {
            if ($paragraph === []) {
                $paragraphLine = $lineNumber;
            }

            $paragraph[] = $visible;
        }

        if (
            \trim($line) === ''
            || \preg_match('#</(?:a|dd|dt|h[1-6]|li|p|summary)>#i', $line) === 1
        ) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
        }
    }

    \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);

    return $passages;
}

function proseMaskAstroExpressions(string $contents): string
{
    $masked = '';
    $depth = 0;
    $quote = null;
    $escaped = false;
    $length = \strlen($contents);

    for ($index = 0; $index < $length; ++$index) {
        $character = $contents[$index];

        if ($depth === 0) {
            if ($character === '{') {
                $depth = 1;
                $masked .= ' ';

                continue;
            }

            $masked .= $character;

            continue;
        }

        if ($character === "\n" || $character === "\r") {
            $masked .= $character;

            continue;
        }

        $masked .= ' ';

        if ($escaped) {
            $escaped = false;

            continue;
        }

        if ($quote !== null) {
            if ($character === '\\') {
                $escaped = true;
            } elseif ($character === $quote) {
                $quote = null;
            }

            continue;
        }

        if (\in_array($character, ["'", '"', '`'], true)) {
            $quote = $character;

            continue;
        }

        if ($character === '{') {
            ++$depth;
        } elseif ($character === '}') {
            --$depth;
        }
    }

    return $masked;
}

/**
 * @param list<array{path: string, line: int, text: string, advisory?: bool}> $passages
 * @param list<string>                                         $paragraph
 */
function proseFlushParagraph(
    array &$passages,
    array &$paragraph,
    int $paragraphLine,
    string $path,
    bool $markdown,
): void {
    if ($paragraph === []) {
        return;
    }

    $text = \implode(' ', $paragraph);
    $text = $markdown
        ? \proseCleanMarkdown($text)
        : \trim((string) \preg_replace('/\s+/', ' ', $text));

    if ($text !== '') {
        $passages[] = ['path' => $path, 'line' => $paragraphLine, 'text' => $text];
    }

    $paragraph = [];
}

/**
 * @return list<array{path: string, line: int, text: string, advisory?: bool}>
 */
function prosePhpPassages(string $path, string $contents): array
{
    $passages = [];

    foreach (\proseCommentTokens($contents) as $token) {
        [$type, $comment, $line] = $token;

        if ($type === \T_COMMENT && \preg_match('/^\s*(?:\/\/|#)\s*@/', $comment) === 1) {
            continue;
        }

        $splitLines = \preg_split('/\R/', $comment);
        $lines = $splitLines === false ? [] : $splitLines;
        $paragraph = [];
        $paragraphLine = $line;
        $inTag = false;
        $tagDescription = null;
        $tagLine = $line;
        $flushTagDescription = static function (?string $tagDescription, int $tagLine) use (&$passages, $path): void {
            if ($tagDescription === null) {
                return;
            }

            $passages[] = [
                'path' => $path,
                'line' => $tagLine,
                'text' => \trim((string) \preg_replace('/\s+/', ' ', $tagDescription)),
                'advisory' => false,
            ];
        };

        foreach ($lines as $offset => $commentLine) {
            $clean = (string) \preg_replace('/^\s*(?:\/\*\*?|\/\/|#|\*(?!\/))\s?/', '', $commentLine);
            $clean = (string) \preg_replace('/\s*\*\/\s*$/', '', $clean);
            $trimmed = \trim($clean);

            if ($trimmed === '') {
                \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
                $flushTagDescription($tagDescription, $tagLine);
                $tagDescription = null;
                $inTag = false;

                continue;
            }

            if (\str_starts_with($trimmed, '@')) {
                \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
                $flushTagDescription($tagDescription, $tagLine);
                $tagDescription = \prosePhpDocTagDescription($trimmed);
                $tagLine = $line + $offset;

                $inTag = true;

                continue;
            }

            if ($inTag) {
                if ($tagDescription !== null) {
                    $tagDescription .= ' ' . $trimmed;
                }

                continue;
            }

            if ($paragraph === []) {
                $paragraphLine = $line + $offset;
            }

            $paragraph[] = $trimmed;
        }

        \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
        $flushTagDescription($tagDescription, $tagLine);
    }

    if (
        $path !== 'tools/prose-check.php'
        && (\str_starts_with($path, 'src/') || \str_starts_with($path, 'tools/') || \str_starts_with($path, 'bin/'))
    ) {
        $passages = [
            ...$passages,
            ...\prosePhpStringPassages($path, $contents),
        ];
    }

    return $passages;
}

function prosePhpDocTagDescription(string $line): ?string
{
    if (\preg_match('/^@param(?:-out)?\s+\S+\s+\$\S+\s+(.+)$/', $line, $matches) === 1) {
        return $matches[1];
    }

    if (\preg_match('/^@(return|throws|var)\s+\S+\s+(.+)$/', $line, $matches) === 1) {
        return $matches[2];
    }

    return null;
}

/**
 * @return list<array{path: string, line: int, text: string, advisory: false}>
 */
function prosePhpStringPassages(string $path, string $contents): array
{
    $passages = [];
    $tokens = \token_get_all($contents);
    $counter = \count($tokens);

    for ($index = 0; $index < $counter; ++$index) {
        $token = $tokens[$index];

        if (\is_array($token) && $token[0] === \T_CONSTANT_ENCAPSED_STRING) {
            \proseAddPhpStringPassage($passages, $path, $token[2], \proseDecodePhpString($token[1]));

            continue;
        }

        $isHeredoc = \is_array($token) && $token[0] === \T_START_HEREDOC;
        $isInterpolated = $token === '"';

        if (!$isHeredoc && !$isInterpolated) {
            continue;
        }

        $line = $isHeredoc ? $token[2] : \proseTokenLine($tokens, $index);
        $text = '';

        for (++$index; $index < \count($tokens); ++$index) {
            $part = $tokens[$index];

            if ($isHeredoc && \is_array($part) && $part[0] === \T_END_HEREDOC) {
                break;
            }

            if ($isInterpolated && $part === '"') {
                break;
            }

            if (\is_array($part) && $part[0] === \T_ENCAPSED_AND_WHITESPACE) {
                $text .= $part[1];

                continue;
            }

            if (\is_array($part) && \in_array($part[0], [\T_VARIABLE, \T_STRING_VARNAME], true)) {
                $text .= ' LITERAL ';
            }
        }

        \proseAddPhpStringPassage($passages, $path, $line, $text);
    }

    return $passages;
}

/**
 * @param list<array{path: string, line: int, text: string, advisory: false}> $passages
 */
function proseAddPhpStringPassage(array &$passages, string $path, int $line, string $text): void
{
    if (
        \str_contains($text, '<?php')
        || \substr_count($text, ';') > 2
        || \preg_match('/^\s*(?:if\b|public\b|private\b|protected\b|function\b|class\b|declare\b)/', $text) === 1
    ) {
        return;
    }

    $text = \trim((string) \preg_replace('/\s+/', ' ', $text));

    if (!\proseLooksLikeHumanString($text)) {
        return;
    }

    $passages[] = [
        'path' => $path,
        'line' => $line,
        'text' => $text,
        'advisory' => false,
    ];
}

/**
 * @param list<array{int, string, int}|string> $tokens
 */
function proseTokenLine(array $tokens, int $index): int
{
    for (; $index >= 0; --$index) {
        $token = $tokens[$index];

        if (\is_array($token)) {
            return $token[2] + \substr_count($token[1], "\n");
        }
    }

    return 1;
}

function proseDecodePhpString(string $literal): string
{
    if (\strlen($literal) < 2) {
        return $literal;
    }

    $quote = $literal[0];
    $value = \substr($literal, 1, -1);

    if ($quote === "'") {
        return \str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
    }

    return \stripcslashes($value);
}

function proseLooksLikeHumanString(string $text): bool
{
    if (
        \str_contains($text, '://')
        || \str_contains($text, "\n")
        || \str_contains($text, '->')
        || \preg_match('/^\s*[\/^#~]/', $text) === 1
        || \preg_match('/^\s*(?:abstract |class |declare\(|final |for \(|foreach \(|function |if \(|private |protected |public |return |static |throw |while \()/', $text) === 1
        || \preg_match('/<[A-Za-z][^>]*>/', $text) === 1
        || \preg_match('/^\s*[a-z0-9.+-]+\/[a-z0-9.+-]+\s*;/i', $text) === 1
    ) {
        return false;
    }

    $withoutPlaceholders = (string) \preg_replace('/%(?:\d+\$)?[-+0-9.\']*[bcdeEfFgGosuxX]/', ' X ', $text);

    return \preg_match('/\b[A-Za-z]{2,}\b.*\b[A-Za-z]{2,}\b/s', $withoutPlaceholders) === 1;
}

/**
 * @return list<array{int, string, int}>
 */
function proseCommentTokens(string $contents): array
{
    $comments = [];

    foreach (\token_get_all($contents) as $token) {
        if (!\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
            continue;
        }

        [$type, $comment, $line] = $token;
        $lastIndex = \array_key_last($comments);
        $last = $lastIndex === null ? null : $comments[$lastIndex];
        $isLineComment = $type === \T_COMMENT && \preg_match('/^\s*(?:\/\/|#)/', $comment) === 1;
        $continuesLineComment = $last !== null
            && $isLineComment
            && $last[0] === \T_COMMENT
            && \preg_match('/^\s*(?:\/\/|#)/', $last[1]) === 1
            && $line === $last[2] + \substr_count($last[1], "\n") + 1;

        if ($continuesLineComment) {
            $comments[$lastIndex][1] .= "\n" . $comment;

            continue;
        }

        $comments[] = [$type, $comment, $line];
    }

    return $comments;
}

/**
 * @param array{path: string, line: int, text: string, advisory?: bool} $passage
 *
 * @return list<array{path: string, line: int, rule: string, severity: string, message: string, passage: string}>
 */
function proseAnalyze(array $passage): array
{
    $findings = [];
    $text = \str_replace(["\u{2019}", "\u{02BC}"], "'", $passage['text']);
    $text = \proseWithoutQuotedLiterals(\proseWithoutRegisteredLiterals($text));
    $normalized = \proseNormalize($text);

    $add = static function (string $rule, string $severity, string $message) use (&$findings, $passage, $normalized): void {
        $findings[] = [
            'path' => $passage['path'],
            'line' => $passage['line'],
            'rule' => $rule,
            'severity' => $severity,
            'message' => $message,
            'passage' => $normalized,
        ];
    };

    if (\str_contains($text, ';')) {
        $add('semicolon', 'blocking', 'Do not use semicolons in prose.');
    }

    if (\proseContainsWord($text, PROSE_CONTRACTIONS)) {
        $add('contraction', 'blocking', 'Do not use contractions in prose.');
    }

    if (\proseContainsWord($text, PROSE_BRITISH_SPELLINGS)) {
        $add('british-spelling', 'blocking', 'Use American English spelling.');
    }

    $sentences = \proseSentences($text);

    if (\count($sentences) > 6) {
        $add('paragraph-length', 'blocking', 'Write no more than six sentences in one paragraph.');
    }

    $hasLongInstruction = false;
    $hasPassiveVoice = false;
    $hasIngForm = false;
    $hasDiscouragedWord = false;
    $hasPhrasalVerb = false;
    $hasMultipleInstructions = false;

    foreach ($sentences as $sentence) {
        $wordCount = \proseWordCount($sentence);

        $isInstruction = \proseLooksLikeInstruction($sentence);

        if ($wordCount > 25 && !$isInstruction) {
            $add(
                'sentence-length',
                'blocking',
                \sprintf('Write no more than 25 words in a descriptive sentence. Found %d words.', $wordCount),
            );
        }

        if ($wordCount > 20 && $isInstruction) {
            $hasLongInstruction = true;
        }

        if (\preg_match(
            '/\b(?:is|are|was|were|be|been|being)\s+(?:(?:not|also|currently|automatically|deliberately|only|separately)\s+){0,2}(?:[a-z]+ed|built|done|given|known|made|run|set|shown|written)\b/i',
            $sentence,
        ) === 1) {
            $hasPassiveVoice = true;
        }

        if (\proseHasVerbalIngCandidate($sentence)) {
            $hasIngForm = true;
        }

        if (\proseContainsDiscouragedWord($sentence)) {
            $hasDiscouragedWord = true;
        }

        if (\preg_match('/\b(?:fall back|falls back|give up|gives up|go back|goes back|run out|runs out|set up|sets up|show up|shows up|take over|takes over|work out|works out)\b/i', $sentence) === 1) {
            $hasPhrasalVerb = true;
        }

        if (\proseHasMultipleInstructions($sentence)) {
            $hasMultipleInstructions = true;
        }
    }

    if (($passage['advisory'] ?? true) === false) {
        return $findings;
    }

    if ($hasLongInstruction) {
        $add('procedural-sentence-length', 'advisory', 'An instruction can contain more than 20 words.');
    }

    if ($hasPassiveVoice) {
        $add('passive-voice', 'advisory', 'Use active voice when the agent is known.');
    }

    if ($hasIngForm) {
        $add('verbal-ing', 'advisory', 'Review each -ing form and keep only approved noun uses.');
    }

    if ($hasDiscouragedWord) {
        $add('discouraged-word', 'advisory', 'Review possible non-approved vocabulary.');
    }

    if ($hasPhrasalVerb) {
        $add('phrasal-verb', 'advisory', 'Do not use phrasal verbs.');
    }

    if ($hasMultipleInstructions) {
        $add('multiple-instructions', 'advisory', 'Write only one instruction in each sentence.');
    }

    return $findings;
}

function proseWithoutRegisteredLiterals(string $text): string
{
    $literals = \array_map(
        static fn(string $literal): string => \preg_quote($literal, '/'),
        PROSE_REGISTERED_LITERALS,
    );

    return (string) \preg_replace(
        '/(?<![A-Za-z0-9])(?:' . \implode('|', $literals) . ')(?![A-Za-z0-9])/u',
        ' LITERAL ',
        $text,
    );
}

function proseWithoutQuotedLiterals(string $text): string
{
    return (string) \preg_replace('/"[^"]*"|“[^”]*”/', ' LITERAL ', $text);
}

/**
 * @return list<string>
 */
function proseSentences(string $text): array
{
    $splitSentences = \preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/', \trim($text));
    $sentences = $splitSentences === false ? [] : $splitSentences;

    return \array_values(\array_filter(\array_map(trim(...), $sentences), static fn(string $value): bool => $value !== ''));
}

function proseWordCount(string $sentence): int
{
    $sentence = (string) \preg_replace('/\([^)]*\)/', ' LITERAL ', $sentence);
    $sentence = (string) \preg_replace('/"[^"]*"|“[^”]*”/', ' LITERAL ', $sentence);
    \preg_match_all("/[A-Za-z0-9]+(?:[-'][A-Za-z0-9]+)*/", $sentence, $matches);

    return \count($matches[0]);
}

/**
 * @param list<string> $words
 */
function proseContainsWord(string $text, array $words): bool
{
    $quoted = \array_map(static fn(string $word): string => \preg_quote($word, '/'), $words);

    return \preg_match('/\b(?:' . \implode('|', $quoted) . ')\b/iu', $text) === 1;
}

function proseContainsDiscouragedWord(string $sentence): bool
{
    $withoutNormativeTokens = (string) \preg_replace(
        '/\b(?:MUST|MUST NOT|SHOULD|SHOULD NOT|MAY)\b/',
        ' LITERAL ',
        $sentence,
    );

    return \proseContainsWord($withoutNormativeTokens, PROSE_DISCOURAGED_WORDS);
}

function proseHasVerbalIngCandidate(string $sentence): bool
{
    if (\preg_match_all('/\b([a-z]+ing)\b/i', $sentence, $matches) === false) {
        return false;
    }

    return !\array_all(
        $matches[1],
        static fn(string $word): bool => \in_array(\strtolower($word), PROSE_ALLOWED_ING_WORDS, true),
    );
}

function proseLooksLikeInstruction(string $sentence): bool
{
    $verbs = \implode('|', \array_map(static fn(string $verb): string => \preg_quote($verb, '/'), PROSE_IMPERATIVE_VERBS));

    return \preg_match('/^\s*(?:(?:if|when|after|before)\b[^,]*,\s*)?(?:do not\s+)?(?:' . $verbs . ')\b/i', $sentence) === 1;
}

function proseHasMultipleInstructions(string $sentence): bool
{
    $verbs = \implode('|', \array_map(static fn(string $verb): string => \preg_quote($verb, '/'), PROSE_IMPERATIVE_VERBS));

    return \preg_match(
        '/^\s*(?:(?:if|when|after|before)\b[^,]*,\s*)?(?:do not\s+)?(?:' . $verbs . ')\b.*\band\s+(?:do not\s+)?(?:' . $verbs . ')\b/i',
        $sentence,
    ) === 1;
}

function proseNormalize(string $text): string
{
    return \strtolower(\trim((string) \preg_replace('/\s+/', ' ', $text)));
}

/**
 * @return list<array{path: string, line: int, rule: string, severity: string, message: string, passage: string}>
 */
function proseFindings(string $root): array
{
    $findings = [];

    foreach (\proseFiles($root) as $file) {
        foreach (\prosePassages($root, $file) as $passage) {
            \array_push($findings, ...\proseAnalyze($passage));
        }
    }

    \usort(
        $findings,
        static fn(array $left, array $right): int => [
            $left['path'],
            $left['line'],
            $left['rule'],
            $left['passage'],
        ] <=> [
            $right['path'],
            $right['line'],
            $right['rule'],
            $right['passage'],
        ],
    );

    return $findings;
}

/**
 * @param array{path: string, line: int, rule: string, message: string, ...} $finding
 */
function prosePrintFinding(array $finding, string $suffix = ''): void
{
    echo \sprintf(
        '%s:%d: %s: %s%s',
        $finding['path'],
        $finding['line'],
        $finding['rule'],
        $finding['message'],
        $suffix,
    ) . "\n";
}

$options = \proseArguments($argv);
$findings = \proseFindings($options['root']);

if ($options['command'] === 'review') {
    foreach ($findings as $finding) {
        if ($finding['severity'] === 'advisory') {
            \prosePrintFinding($finding);
        }
    }

    exit(0);
}

$hasBlockingFinding = false;

foreach ($findings as $finding) {
    if ($finding['severity'] === 'blocking') {
        \prosePrintFinding($finding);
        $hasBlockingFinding = true;
    }
}

exit($hasBlockingFinding ? 1 : 0);
