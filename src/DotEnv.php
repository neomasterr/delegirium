<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Мини-загрузчик `.env` без зависимостей.
 *
 * Поддерживает то, что реально используется в проекте:
 *   - комментарии `#` в начале строки и после значения (`KEY=v # note`);
 *   - префикс `export KEY=v`;
 *   - значения в кавычках (`"..."` с \n, \t, \", \\; `'...'` без раскрытия);
 *   - пустые значения и `=` внутри значения.
 *
 * Переменные, уже заданные в окружении, по умолчанию **не перезатираются**:
 * приоритет у shell/CI, `.env` только заполняет пробелы.
 */
final class DotEnv
{
    /**
     * Файлы по умолчанию: явный DELEGIRIUM_ENV_FILE, иначе `.env` в корне
     * проекта и `.env` в текущем каталоге.
     *
     * @return list<string>
     */
    public static function candidates(): array
    {
        $explicit = getenv('DELEGIRIUM_ENV_FILE');

        if (is_string($explicit) && $explicit !== '') {
            return [$explicit];
        }

        $candidates = [dirname(__DIR__) . '/.env'];

        $cwd = getcwd();

        if (is_string($cwd) && $cwd !== dirname(__DIR__)) {
            $candidates[] = $cwd . '/.env';
        }

        return $candidates;
    }

    /**
     * Загружает все существующие файлы-кандидаты.
     *
     * @return list<string> реально прочитанные файлы
     */
    public static function loadDefault(bool $overwrite = false): array
    {
        $loaded = [];

        foreach (self::candidates() as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            self::loadFile($file, $overwrite);
            $loaded[] = $file;
        }

        return $loaded;
    }

    /**
     * @return array<string, string> применённые пары (без уже существующих)
     */
    public static function loadFile(string $file, bool $overwrite = false): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new DelegiriumError('DotEnv: файл не найден или недоступен: ' . $file);
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new DelegiriumError('DotEnv: не удалось прочитать ' . $file);
        }

        $applied = [];

        foreach (self::parse($contents) as $key => $value) {
            if (!$overwrite && getenv($key) !== false) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $applied[$key] = $value;
        }

        return $applied;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) !== 1) {
                continue;
            }

            $values[$key] = self::parseValue(substr($line, $separator + 1));
        }

        return $values;
    }

    private static function parseValue(string $raw): string
    {
        $raw = ltrim($raw);

        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $quote = $raw[0];
            $closing = strrpos($raw, $quote);

            if ($closing !== false && $closing > 0) {
                $inner = substr($raw, 1, $closing - 1);

                return $quote === '"' ? self::unescape($inner) : $inner;
            }

            return trim($raw, $quote);
        }

        // Комментарий — только через пробел: `URL=a#b` остаётся значением.
        $comment = strpos($raw, ' #');

        if ($comment !== false) {
            $raw = substr($raw, 0, $comment);
        }

        return trim($raw);
    }

    private static function unescape(string $value): string
    {
        return strtr($value, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\',
        ]);
    }
}
