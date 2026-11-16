<?php

declare(strict_types=1);

namespace Delegirium;

use Delegirium\Provider\OpenAiProvider;
use Delegirium\Provider\OpenRouterProvider;
use Delegirium\Provider\ProviderInterface;
use Delegirium\Provider\RecordedProvider;

/**
 * Точка входа для тех, кто не хочет собирать компилятор руками.
 *
 * При первом обращении подхватывает `.env` (`DELEGIRIUM_ENV_FILE` или `.env`
 * в корне проекта/текущем каталоге), чтобы не писать export вручную.
 * Уже заданные в окружении переменные имеют приоритет.
 */
final class Delegirium
{
    public const VERSION = '0.1.0';

    /** @var list<string> */
    private static array $envFiles = [];

    private static bool $booted = false;

    /**
     * Загружает `.env` один раз. Вызывать не обязательно: compile*() и provider()
     * делают это сами.
     *
     * @return list<string> прочитанные файлы
     */
    public static function boot(): array
    {
        if (self::$booted) {
            return self::$envFiles;
        }

        self::$booted = true;
        self::$envFiles = DotEnv::loadDefault();

        return self::$envFiles;
    }

    /**
     * Явная загрузка конкретного файла (например, `.env.local`).
     *
     * @return list<string> уже загруженные к этому моменту файлы
     */
    public static function loadEnvFile(string $file, bool $overwrite = false): array
    {
        self::$booted = true;
        self::$envFiles[] = $file;
        DotEnv::loadFile($file, $overwrite);

        return self::$envFiles;
    }

    /**
     * @return list<string>
     */
    public static function loadedEnvFiles(): array
    {
        return self::$envFiles;
    }

    /**
     * @param array{
     *     provider?: ProviderInterface,
     *     cache?: Cache|false,
     *     cache_dir?: string,
     *     regenerate?: bool,
     *     max_attempts?: int,
     *     record_file?: string|null
     * } $options
     */
    public static function compileFile(string $path, array $options = []): CompiledUnit
    {
        if (!is_file($path)) {
            throw new DelegiriumError('Delegirium: файл не найден: ' . $path);
        }

        $source = @file_get_contents($path);

        if ($source === false) {
            throw new DelegiriumError('Delegirium: не удалось прочитать ' . $path);
        }

        return self::compiler($options)->compile($source, $path);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function compileSource(string $source, string $label = '<string>', array $options = []): CompiledUnit
    {
        self::boot();

        return self::compiler($options)->compile($source, $label);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function compiler(array $options = []): Compiler
    {
        self::boot();

        $cache = $options['cache'] ?? null;

        if (!array_key_exists('cache', $options)) {
            $cache = new Cache(self::cacheDir($options));
        }

        return new Compiler(
            provider: self::provider($options),
            cache: $cache instanceof Cache ? $cache : null,
            regenerate: (bool) ($options['regenerate'] ?? false),
            maxAttempts: (int) ($options['max_attempts'] ?? 2),
        );
    }

    public static function cacheDir(array $options = []): string
    {
        self::boot();

        $configured = $options['cache_dir'] ?? getenv('DELEGIRIUM_CACHE_DIR');

        return $configured !== false && $configured !== null && $configured !== ''
            ? (string) $configured
            : dirname(__DIR__) . '/.delegirium-cache';
    }

    public static function fixturesFile(): string
    {
        return dirname(__DIR__) . '/fixtures/recorded.php';
    }

    /**
     * Выбирает провайдера:
     *   - готовый объект в `provider` — как есть;
     *   - строка в `provider` (openai | openrouter | recorded) — явно;
     *   - иначе `DELEGIRIUM_PROVIDER`;
     *   - иначе auto: ключ OpenRouter -> openrouter, ключ OpenAI -> openai,
     *     без ключей -> записанные ответы из fixtures/.
     *
     * @param array<string, mixed> $options
     */
    public static function provider(array $options = []): ProviderInterface
    {
        self::boot();

        $configured = $options['provider'] ?? null;

        if ($configured instanceof ProviderInterface) {
            return $configured;
        }

        $preferred = is_string($configured) && $configured !== ''
            ? strtolower($configured)
            : strtolower((string) (getenv('DELEGIRIUM_PROVIDER') ?: 'auto'));

        $openAiKey = (string) (getenv('DELEGIRIUM_API_KEY') ?: '');
        $openRouterKey = (string) (getenv('DELEGIRIUM_OPENROUTER_KEY') ?: '');
        $recordFile = $options['record_file'] ?? null;

        if ($recordFile === null) {
            $recordEnv = getenv('DELEGIRIUM_RECORD');

            if (is_string($recordEnv) && trim($recordEnv) !== '') {
                // DELEGIRIUM_RECORD=1 — включаем запись в стандартный fixtures.
                $recordFile = in_array(strtolower(trim($recordEnv)), ['1', 'true', 'yes', 'on'], true)
                    ? self::fixturesFile()
                    : trim($recordEnv);
            }
        }

        if ($preferred === 'recorded') {
            return new RecordedProvider(fixtureFile: self::fixturesFile());
        }

        $useOpenRouter = $preferred === 'openrouter'
            || ($preferred === 'auto' && $openRouterKey !== '' && $openAiKey === '');

        if ($useOpenRouter) {
            $key = $openRouterKey !== '' ? $openRouterKey : $openAiKey;

            if ($key === '') {
                throw new DelegiriumError(
                    'Провайдер openrouter требует DELEGIRIUM_OPENROUTER_KEY (или DELEGIRIUM_API_KEY).',
                );
            }

            return new OpenRouterProvider(
                apiKey: $key,
                model: (string) (getenv('DELEGIRIUM_OPENROUTER_MODEL') ?: getenv('DELEGIRIUM_MODEL') ?: OpenRouterProvider::DEFAULT_MODEL),
                baseUrl: (string) (getenv('DELEGIRIUM_OPENROUTER_URL') ?: getenv('DELEGIRIUM_API_URL') ?: OpenRouterProvider::DEFAULT_BASE_URL),
                recordFile: $recordFile,
                referer: (string) (getenv('DELEGIRIUM_OPENROUTER_REFERER') ?: '') ?: null,
            );
        }

        if ($preferred === 'openai' || ($preferred === 'auto' && $openAiKey !== '')) {
            if ($openAiKey === '') {
                throw new DelegiriumError('Провайдер openai требует DELEGIRIUM_API_KEY.');
            }

            return new OpenAiProvider(
                apiKey: $openAiKey,
                model: (string) (getenv('DELEGIRIUM_MODEL') ?: 'gpt-4.1-mini'),
                endpoint: (string) (getenv('DELEGIRIUM_API_URL') ?: 'https://api.openai.com/v1/chat/completions'),
                recordFile: $recordFile,
            );
        }

        if ($preferred !== 'auto') {
            throw new DelegiriumError('Неизвестный провайдер: ' . $preferred . ' (ожидалось openai, openrouter или recorded).');
        }

        return new RecordedProvider(fixtureFile: self::fixturesFile());
    }
}
