<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Разбор исходника Delegirium.
 *
 * Delegirium — это PHP, у которого тело функции может быть заменено маркером
 * `ai;`:
 *
 *     public function add($x, $y): int ai;
 *
 * Лексер PHP терпим к такому коду (синтаксически он невалиден, но токенизируется),
 * поэтому парсер работает поверх token_get_all() и не требует своего лексера.
 * Всё, что не является AI-объявлением, остаётся нетронутым: компилятор только
 * вырезает маркер и вклеивает сгенерированное тело, сохраняя отступы и
 * окружающий код (классы, пространства имён, обычные функции).
 */
final class Parser
{
    private const SKIP_FOR_LOOKAHEAD = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * @return list<FunctionDecl>
     */
    public static function parse(string $source): array
    {
        if (!str_starts_with(ltrim($source), '<?php')) {
            $source = "<?php\n" . $source;
        }

        $tokens = self::tokenize($source);
        $count = count($tokens);

        /** @var list<FunctionDecl> $decls */
        $decls = [];
        /** @var list<array{name: string, depth: int}> $classes */
        $classes = [];
        $pendingClass = null;
        $namespace = '';
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token['id'];
            $text = $token['text'];

            if ($id === T_NAMESPACE) {
                $j = self::next($tokens, $i);
                $name = '';

                while ($j < $count && !self::isChar($tokens[$j], ';') && !self::isChar($tokens[$j], '{')) {
                    $name .= $tokens[$j]['text'];
                    $j = self::next($tokens, $j);
                }

                $namespace = trim($name);
                $i = $j - 1;
                continue;
            }

            if (self::isClassLike($id)) {
                $previous = self::prev($tokens, $i);
                if ($previous >= 0 && $tokens[$previous]['id'] === T_DOUBLE_COLON) {
                    // `Foo::class`
                    continue;
                }

                $j = self::next($tokens, $i);
                if ($j < $count && $tokens[$j]['id'] === T_STRING) {
                    $pendingClass = $tokens[$j]['text'];
                    $i = $j;
                }
                continue;
            }

            if (self::isChar($token, '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($pendingClass !== null) {
                    $classes[] = ['name' => $pendingClass, 'depth' => $depth];
                    $pendingClass = null;
                }
                continue;
            }

            if (self::isChar($token, '}')) {
                $depth = max(0, $depth - 1);
                while ($classes !== [] && end($classes)['depth'] > $depth) {
                    array_pop($classes);
                }
                continue;
            }

            if ($id !== T_FUNCTION) {
                continue;
            }

            $decl = self::tryParseFunction($tokens, $i, $classes, $namespace);
            if ($decl === null) {
                continue;
            }

            $decls[] = $decl;
            $i = self::indexAtOffset($tokens, $decl->endOffset) - 1;
        }

        return $decls;
    }

    /**
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     * @param list<array{name: string, depth: int}>                      $classes
     */
    private static function tryParseFunction(array $tokens, int $functionIndex, array $classes, string $namespace): ?FunctionDecl
    {
        $count = count($tokens);

        $nameIndex = self::next($tokens, $functionIndex);
        if ($nameIndex >= $count) {
            return null;
        }

        if (self::isChar($tokens[$nameIndex], '&')) {
            $nameIndex = self::next($tokens, $nameIndex);
        }

        if ($nameIndex >= $count || $tokens[$nameIndex]['id'] !== T_STRING) {
            return null; // closure или что-то нестандартное
        }

        $name = $tokens[$nameIndex]['text'];

        $open = self::next($tokens, $nameIndex);
        if ($open >= $count || !self::isChar($tokens[$open], '(')) {
            return null;
        }

        $close = self::matchingParen($tokens, $open);
        if ($close === null) {
            return null;
        }

        $cursor = self::next($tokens, $close);
        $returnType = '';

        if ($cursor < $count && self::isChar($tokens[$cursor], ':')) {
            $type = '';
            $cursor = self::next($tokens, $cursor);

            while ($cursor < $count) {
                $token = $tokens[$cursor];

                if (self::isChar($token, '{') || self::isChar($token, ';')) {
                    return null; // обычная функция или интерфейсный метод
                }

                if ($token['id'] === T_STRING && strcasecmp($token['text'], 'ai') === 0) {
                    break;
                }

                $type .= $token['text'];
                $cursor = self::next($tokens, $cursor);
            }

            $returnType = trim($type);
        }

        if ($cursor >= $count) {
            return null;
        }

        if ($tokens[$cursor]['id'] !== T_STRING || strcasecmp($tokens[$cursor]['text'], 'ai') !== 0) {
            return null;
        }

        $marker = $cursor;
        $semicolon = self::next($tokens, $marker);

        if ($semicolon >= $count || !self::isChar($tokens[$semicolon], ';')) {
            return null;
        }

        $signatureStart = self::signatureStart($tokens, $functionIndex);
        $signature = '';

        for ($i = $signatureStart; $i <= $close; $i++) {
            $signature .= $tokens[$i]['text'];
        }

        $signature = trim((string) preg_replace('/\s+/', ' ', $signature));

        $className = $classes === [] ? null : end($classes)['name'];
        $doc = self::collectDoc($tokens, $signatureStart);

        // Начинаем замену с пробела перед маркером: так фигурная скобка
        // встаёт на свою строку и не остаётся висящего пробела.
        $markerStart = $tokens[$marker]['start'];
        if ($marker > 0 && $tokens[$marker - 1]['id'] === T_WHITESPACE) {
            $markerStart = $tokens[$marker - 1]['start'];
        }

        return new FunctionDecl(
            name: $name,
            signature: $signature,
            returnType: $returnType,
            doc: $doc,
            tags: self::collectTags($doc),
            className: $className,
            namespace: $namespace,
            indent: self::indentBefore($tokens, $signatureStart),
            startOffset: $markerStart,
            endOffset: $tokens[$semicolon]['end'],
            line: $tokens[$functionIndex]['line'],
        );
    }

    /**
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function matchingParen(array $tokens, int $open): ?int
    {
        $count = count($tokens);
        $depth = 0;

        for ($i = $open; $i < $count; $i++) {
            if (self::isChar($tokens[$i], '(')) {
                $depth++;
            } elseif (self::isChar($tokens[$i], ')')) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Начало объявления: модификаторы (`public`, `static`, ...), атрибуты
     * и `function`. Нужно, чтобы сигнатура и docblock захватывались целиком.
     *
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function signatureStart(array $tokens, int $functionIndex): int
    {
        $start = $functionIndex;
        $i = $functionIndex - 1;

        while ($i >= 0) {
            $id = $tokens[$i]['id'];

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                $i--;
                continue;
            }

            if (self::isModifier($id) || $id === T_ATTRIBUTE) {
                $start = $i;
                $i--;
                continue;
            }

            break;
        }

        return $start;
    }

    /**
     * Ближайший блок комментариев перед объявлением — это и есть контракт.
     *
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function collectDoc(array $tokens, int $signatureStart): string
    {
        $chunks = [];
        $i = $signatureStart - 1;

        while ($i >= 0) {
            $id = $tokens[$i]['id'];

            if ($id === T_WHITESPACE) {
                $i--;
                continue;
            }

            if ($id === T_DOC_COMMENT || $id === T_COMMENT) {
                $chunks[] = $tokens[$i]['text'];
                $i--;
                continue;
            }

            break;
        }

        return implode('', array_reverse($chunks));
    }

    /**
     * @return list<string>
     */
    private static function collectTags(string $doc): array
    {
        if (!preg_match_all('/@([a-zA-Z][a-zA-Z0-9_-]*)/', $doc, $matches) || $matches[1] === []) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Отступ строки, на которой начинается объявление: он же нужен для
     * форматирования вставленного тела.
     *
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function indentBefore(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $position = strrpos($tokens[$i]['text'], "\n");

            if ($position !== false) {
                return substr($tokens[$i]['text'], $position + 1);
            }

            $id = $tokens[$i]['id'];
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                break;
            }
        }

        return '';
    }

    /**
     * @return list<array{id: ?int, text: string, start: int, end: int, line?: int}>
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;
            } else {
                $id = null;
                $text = $token;
                $line = 0;
            }

            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'start' => $offset,
                'end' => $offset + strlen($text),
                'line' => $line,
            ];

            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function next(array $tokens, int $index): int
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            if (!in_array($tokens[$i]['id'], self::SKIP_FOR_LOOKAHEAD, true)) {
                return $i;
            }
        }

        return $count;
    }

    /**
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function prev(array $tokens, int $index): int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (!in_array($tokens[$i]['id'], self::SKIP_FOR_LOOKAHEAD, true)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param list<array{id: ?int, text: string, start: int, end: int}> $tokens
     */
    private static function indexAtOffset(array $tokens, int $offset): int
    {
        foreach ($tokens as $index => $token) {
            if ($token['start'] >= $offset) {
                return $index;
            }
        }

        return count($tokens) - 1;
    }

    /**
     * @param array{id: ?int, text: string} $token
     */
    private static function isChar(array $token, string $char): bool
    {
        return $token['id'] === null && $token['text'] === $char;
    }

    private static function isClassLike(?int $id): bool
    {
        $ids = [T_CLASS, T_TRAIT, T_INTERFACE];

        if (defined('T_ENUM')) {
            $ids[] = T_ENUM;
        }

        return in_array($id, $ids, true);
    }

    private static function isModifier(?int $id): bool
    {
        $ids = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_VAR];

        if (defined('T_READONLY')) {
            $ids[] = T_READONLY;
        }

        return in_array($id, $ids, true);
    }
}
