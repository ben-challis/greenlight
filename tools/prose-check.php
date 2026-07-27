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
    "aren't",
    "can't",
    "couldn't",
    "didn't",
    "doesn't",
    "don't",
    "hadn't",
    "hasn't",
    "haven't",
    "he'd",
    "he'll",
    "he's",
    "isn't",
    "it'd",
    "it'll",
    "it's",
    "shouldn't",
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
    "won't",
    "wouldn't",
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
    'behaviour',
    'behaviours',
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

        if (!\in_array(\strtolower($file->getExtension()), ['astro', 'md', 'markdown', 'php'], true)) {
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
 * @return list<array{path: string, line: int, text: string}>
 */
function prosePassages(string $root, string $path): array
{
    $relative = \proseRelativePath($root, $path);
    $contents = \file_get_contents($path);

    if (!\is_string($contents)) {
        \proseFail(\sprintf('Cannot read "%s".', $relative));
    }

    if (\str_ends_with(\strtolower($path), '.php')) {
        return \prosePhpPassages($relative, $contents);
    }

    if (\str_ends_with(\strtolower($path), '.astro')) {
        return \proseAstroPassages($relative, $contents);
    }

    return \proseMarkdownPassages($relative, $contents);
}

/**
 * @return list<array{path: string, line: int, text: string}>
 */
function proseMarkdownPassages(string $path, string $contents): array
{
    $passages = [];
    $paragraph = [];
    $paragraphLine = 1;
    $inFence = false;
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

            continue;
        }

        if ($isHeading) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $paragraphLine = $lineNumber;
            $paragraph[] = (string) \preg_replace('/^\s*#{1,6}\s+/', '', $line);
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);

            continue;
        }

        if ($isTableRow) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $paragraphLine = $lineNumber;
            $paragraph[] = \str_replace('|', ' ', \trim($line, " \t|"));
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);

            continue;
        }

        if ($isListItem) {
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);
            $paragraphLine = $lineNumber;
            $paragraph[] = (string) \preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/', '', $line);
            \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, true);

            continue;
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
    $text = (string) \preg_replace('/!\[[^\]]*]\([^)]*\)/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/\[[^\]]+]\([^)]*\)/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/`[^`]*`/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/<https?:\/\/[^>]+>/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/https?:\/\/\S+/', ' LITERAL ', $text);
    $text = (string) \preg_replace('/[*_]{1,3}/', '', $text);
    $text = (string) \preg_replace('/\s+/', ' ', $text);

    return \trim($text);
}

/**
 * @return list<array{path: string, line: int, text: string}>
 */
function proseAstroPassages(string $path, string $contents): array
{
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
    $passages = [];
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
 * @param list<array{path: string, line: int, text: string}> $passages
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
 * @return list<array{path: string, line: int, text: string}>
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

        foreach ($lines as $offset => $commentLine) {
            $clean = (string) \preg_replace('/^\s*(?:\/\*\*?|\/\/|#|\*(?!\/))\s?/', '', $commentLine);
            $clean = (string) \preg_replace('/\s*\*\/\s*$/', '', $clean);
            $trimmed = \trim($clean);

            if ($trimmed === '') {
                \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
                $inTag = false;

                continue;
            }

            if (\str_starts_with($trimmed, '@')) {
                \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
                $inTag = true;

                continue;
            }

            if ($inTag) {
                continue;
            }

            if ($paragraph === []) {
                $paragraphLine = $line + $offset;
            }

            $paragraph[] = $trimmed;
        }

        \proseFlushParagraph($passages, $paragraph, $paragraphLine, $path, false);
    }

    return $passages;
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
 * @param array{path: string, line: int, text: string} $passage
 *
 * @return list<array{path: string, line: int, rule: string, severity: string, message: string, passage: string}>
 */
function proseAnalyze(array $passage): array
{
    $findings = [];
    $text = \proseWithoutRegisteredLiterals($passage['text']);
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
