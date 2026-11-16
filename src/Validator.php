<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Страж границы между «текстом модели» и исполняемым кодом.
 *
 * Сгенерированное тело — недоверенный ввод, даже если модель дружелюбна.
 * Валидатор ловит то, что можно проверить без исполнения: синтаксис, запрещённые
 * конструкции, нарушение чистоты (@pure), отсутствие return. Ошибка возвращается
 * модели как обратная связь для следующей попытки.
 *
 * Это не песочница: окончательная изоляция — отдельная задача языка (см. SPEC.md).
 */
final class Validator
{
    /** Функции, которые не должны появляться в сгенерированном теле вообще. */
    private const FORBIDDEN_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
        'eval', 'assert', 'create_function', 'extract', 'parse_str', 'putenv',
        'file_put_contents', 'fwrite', 'fopen', 'unlink', 'rename', 'copy', 'mkdir',
        'rmdir', 'chmod', 'chown', 'touch', 'tempnam', 'tmpfile', 'mail',
        'curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client',
        'socket_create', 'mysqli_query', 'pg_query', 'shell_exec',
    ];

    /** Источники недетерминизма и внешнего состояния: запрещены в @pure. */
    private const IMPURE_FUNCTIONS = [
        'time', 'microtime', 'hrtime', 'rand', 'mt_rand', 'random_int', 'random_bytes',
        'uniqid', 'getenv', 'putenv', 'file_get_contents', 'file_exists', 'is_file',
        'date', 'hash_random', 'session_start', 'setcookie', 'header',
    ];

    private const FORBIDDEN_TOKEN_IDS = [
        T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_GLOBAL,
    ];

    private const MAX_BODY_LENGTH = 4000;

    /**
     * @return list<string> предупреждения, не являющиеся ошибкой
     *
     * @throws DelegiriumError
     */
    public function validateBody(string $body, GenerationRequest $request): array
    {
        $body = trim($body);

        if ($body === '') {
            throw new DelegiriumError('тело пустое');
        }

        if (strlen($body) > self::MAX_BODY_LENGTH) {
            throw new DelegiriumError('тело слишком длинное: ' . strlen($body) . ' байт');
        }

        if (str_contains($body, '<?php') || str_contains($body, '?>')) {
            throw new DelegiriumError('тело не должно содержать PHP-теги');
        }

        $tokens = token_get_all('<?php ' . $body);

        $this->assertBalanced($tokens);
        $this->assertNoForbiddenConstructs($tokens);
        $warnings = [];

        if ($request->pure) {
            $this->assertPure($tokens);
        }

        $this->assertReturns($tokens, $request, $warnings);
        $this->assertNoTopLevelJunk($tokens);

        return $warnings;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function assertBalanced(array $tokens): void
    {
        $depth = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                continue;
            }

            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;

                if ($depth < 0) {
                    throw new DelegiriumError('лишняя закрывающая скобка }');
                }
            }
        }

        if ($depth !== 0) {
            throw new DelegiriumError('несбалансированные фигурные скобки: тело выглядит обрезанным');
        }
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function assertNoForbiddenConstructs(array $tokens): void
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '`') {
                    throw new DelegiriumError('обратные кавычки (shell exec) запрещены');
                }

                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, self::FORBIDDEN_TOKEN_IDS, true)) {
                throw new DelegiriumError('запрещённая конструкция: ' . trim($text));
            }

            if ($text === '$GLOBALS') {
                throw new DelegiriumError('обращение к $GLOBALS запрещено');
            }

            if ($id !== T_STRING) {
                continue;
            }

            $name = strtolower($text);

            if (!in_array($name, self::FORBIDDEN_FUNCTIONS, true)) {
                continue;
            }

            if ($this->isCall($tokens, $i)) {
                throw new DelegiriumError(sprintf('запрещённый вызов %s()', $name));
            }
        }
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function assertPure(array $tokens): void
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_ECHO || $id === T_PRINT || $id === T_GLOBAL) {
                throw new DelegiriumError('@pure функция не может писать в вывод или читать глобальное состояние');
            }

            if ($id === T_STATIC) {
                throw new DelegiriumError('@pure функция не может использовать static-переменные');
            }

            if ($id === T_VARIABLE && $text === '$GLOBALS') {
                throw new DelegiriumError('@pure функция не может обращаться к $GLOBALS');
            }

            if ($id === T_STRING && in_array(strtolower($text), self::IMPURE_FUNCTIONS, true) && $this->isCall($tokens, $i)) {
                throw new DelegiriumError(sprintf('@pure функция не может вызывать %s()', strtolower($text)));
            }
        }
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                    $warnings
     */
    private function assertReturns(array $tokens, GenerationRequest $request, array &$warnings): void
    {
        $returnType = $request->returnType;
        $hasReturn = false;
        $hasThrow = false;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_RETURN) {
                $hasReturn = true;
            } elseif ($token[0] === T_THROW) {
                $hasThrow = true;
            }
        }

        $voidLike = in_array(strtolower($returnType), ['', 'void', 'never'], true);

        if ($voidLike) {
            if ($hasReturn) {
                $warnings[] = sprintf('%s объявлена как %s, но содержит return', $request->fqName, $returnType !== '' ? $returnType : 'mixed');
            }

            return;
        }

        if (!$hasReturn && !$hasThrow) {
            throw new DelegiriumError(sprintf('нет return, хотя объявлен тип возврата %s', $returnType));
        }
    }

    /**
     * Модель иногда добавляет `function foo() { ... }`, класс или namespace
     * внутри тела — такое тело нельзя вклеивать. Замыкания и `Foo::class`
     * при этом разрешены: это обычные выражения.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function assertNoTopLevelJunk(array $tokens): void
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                throw new DelegiriumError('тело не должно объявлять пространства имён');
            }

            if ($token[0] === T_FUNCTION) {
                $next = $this->nextSignificant($tokens, $index);

                // `function () {}` и `function &() {}` — это замыкания.
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    throw new DelegiriumError('тело не должно объявлять функции');
                }
            }
        }
    }

    /**
     * Индекс следующего значимого токена (без пробелов и комментариев).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextSignificant(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Токен является вызовом, если следующий значимый токен — `(`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isCall(array $tokens, int $index): bool
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_string($token) && $token === '(';
        }

        return false;
    }
}
