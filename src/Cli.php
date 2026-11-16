<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Небольшая консоль для наброска: компиляция, проверка, демо, кеш.
 */
final class Cli
{
    private const ENV_KEYS = [
        'DELEGIRIUM_PROVIDER',
        'DELEGIRIUM_API_KEY',
        'DELEGIRIUM_API_URL',
        'DELEGIRIUM_MODEL',
        'DELEGIRIUM_OPENROUTER_KEY',
        'DELEGIRIUM_OPENROUTER_URL',
        'DELEGIRIUM_OPENROUTER_MODEL',
        'DELEGIRIUM_OPENROUTER_REFERER',
        'DELEGIRIUM_CACHE_DIR',
        'DELEGIRIUM_ENV_FILE',
        'DELEGIRIUM_RECORD',
    ];

    /** @var resource */
    private $stdout;

    /**
     * @param resource $stdout
     */
    public function __construct($stdout = STDOUT)
    {
        $this->stdout = $stdout;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        [$positional, $flags] = self::parseArguments(array_slice($argv, 2));

        try {
            return match ($command) {
                'compile', 'build' => $this->compile($positional, $flags),
                'check', 'lint' => $this->check($positional, $flags),
                'cache:clear' => $this->cacheClear($flags),
                'env' => $this->env(),
                'demo' => $this->demo($flags),
                'help', '--help', '-h' => $this->help(),
                default => $this->fail('Неизвестная команда: ' . $command),
            };
        } catch (DelegiriumError $error) {
            return $this->fail($error->getMessage());
        }
    }

    /**
     * @param list<string>          $positional
     * @param array<string, mixed>  $flags
     */
    private function compile(array $positional, array $flags): int
    {
        $file = $positional[0] ?? null;

        if ($file === null) {
            return $this->fail('Укажите файл: delegirium compile examples/math.dlr');
        }

        if (($flags['dry'] ?? false) === true) {
            return $this->dryRun($file, $flags);
        }

        $started = microtime(true);
        $unit = Delegirium::compileFile($file, $this->options($flags));

        foreach ($unit->warnings() as $warning) {
            $this->line('  ! ' . $warning);
        }

        $out = $flags['out'] ?? $flags['o'] ?? null;

        if (is_string($out) && $out !== '') {
            $unit->save($out);
            $this->line(sprintf('Записан %s', $out));
        } elseif (($flags['json'] ?? false) === true) {
            $this->line((string) json_encode([
                'file' => $unit->label(),
                'declarations' => $unit->declarations(),
                'warnings' => $unit->warnings(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($unit->php());
        }

        $this->summary($unit, microtime(true) - $started);

        return 0;
    }

    /**
     * @param list<string>         $positional
     * @param array<string, mixed> $flags
     */
    private function check(array $positional, array $flags): int
    {
        $file = $positional[0] ?? null;

        if ($file === null) {
            return $this->fail('Укажите файл: delegirium check examples/math.dlr');
        }

        $unit = Delegirium::compileFile($file, $this->options($flags));

        $this->line(sprintf('%s: OK, %d AI-функц.', $file, count($unit->declarations())));
        $this->summary($unit, 0.0);

        return 0;
    }

    /**
     * Показывает, какие .env-файлы подхвачены и какие переменные видит компилятор.
     * Секреты маскируются.
     */
    private function env(): int
    {
        $loaded = Delegirium::boot();
        $candidates = DotEnv::candidates();

        $this->line('Файлы .env:');

        foreach ($candidates as $file) {
            $status = in_array($file, $loaded, true)
                ? 'загружен'
                : (is_file($file) ? 'не загружен' : 'нет файла');

            $this->line(sprintf('  %-48s %s', $file, $status));
        }

        $this->line('');
        $this->line('Переменные:');

        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            $printed = $value === false
                ? '(не задана)'
                : self::maskValue($key, $value);

            $this->line(sprintf('  %-30s = %s', $key, $printed));
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function cacheClear(array $flags): int
    {
        $cache = new Cache(Delegirium::cacheDir($this->options($flags)));
        $removed = $cache->clear();

        $this->line(sprintf('Удалено записей кеша: %d (%s)', $removed, $cache->directory()));

        return 0;
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function demo(array $flags): int
    {
        $options = $this->options($flags);
        $provider = Delegirium::provider($options);

        $this->line(sprintf('Delegirium %s — провайдер: %s (%s)', Delegirium::VERSION, $provider->name(), $provider->model()));
        $this->line('');

        $root = dirname(__DIR__);
        $math = Delegirium::compileFile($root . '/examples/math.dlr', $options);
        $text = Delegirium::compileFile($root . '/examples/text.dlr', $options);

        $calculator = $math->instance('Delegirium\\Examples\\Math');

        $this->line('  examples/math.dlr');
        $this->row('add(2, 3)', $calculator->add(2, 3));
        $this->row('add(-10, 4)', $calculator->add(-10, 4));
        $this->row('fib(10)', $calculator->fib(10));
        $this->row('divide(7, 2)', $calculator->divide(7, 2));

        $this->line('');
        $this->line('  examples/text.dlr');
        $this->row('reverse("Delegirium")', $text->invoke('Delegirium\\Examples\\Text\\reverse', ['Delegirium']));
        $this->row('reverse("привет")', $text->invoke('Delegirium\\Examples\\Text\\reverse', ['привет']));
        $this->row('slugify("Hello, Delegirium!")', $text->invoke('Delegirium\\Examples\\Text\\slugify', ['Hello, Delegirium!']));

        $this->line('');
        $this->summary($math, 0.0);
        $this->summary($text, 0.0);

        return 0;
    }

    /**
     * Печатает точные промпты, которые уйдут провайдеру. Полезно без ключа API.
     *
     * @param array<string, mixed> $flags
     */
    private function dryRun(string $file, array $flags): int
    {
        $source = (string) @file_get_contents($file);
        $declarations = Parser::parse($source);

        if ($declarations === []) {
            return $this->fail($file . ': AI-объявлений не найдено');
        }

        foreach ($declarations as $declaration) {
            $request = $declaration->request();
            $this->line('=== ' . $request->fqName . ' (строка ' . $declaration->line . ') ===');
            $this->line('--- system ---');
            $this->line($request->systemPrompt());
            $this->line('--- user ---');
            $this->line($request->userPrompt());
            $this->line('');
        }

        return 0;
    }

    private function help(): int
    {
        $this->line(<<<'TXT'
Delegirium — PHP, в котором тело функции генерирует ИИ.

Использование:
  delegirium compile <file.dlr> [--out=build/x.php] [--json] [--regen] [--dry]
  delegirium check   <file.dlr>
  delegirium env       показать .env-файлы и переменные
  delegirium demo
  delegirium cache:clear

Флаги:
  --out, -o <path>     записать сгенерированный PHP в файл
  --json               вывести метаданные в JSON
  --regen              игнорировать кеш и перегенерировать тела
  --dry                показать промпты, не вызывая модель
  --provider=<name>    auto | openai | openrouter | recorded (по умолчанию auto)
  --cache-dir=<path>   каталог кеша (по умолчанию .delegirium-cache)
  --record             записать ответы модели в fixtures/recorded.php

Окружение:
  DELEGIRIUM_PROVIDER           auto | openai | openrouter | recorded
  DELEGIRIUM_API_KEY            ключ OpenAI
  DELEGIRIUM_OPENROUTER_KEY     ключ OpenRouter
  DELEGIRIUM_OPENROUTER_URL     база OpenRouter (https://api.ru-openrouter.ru/v1)
  DELEGIRIUM_OPENROUTER_MODEL   модель OpenRouter (openai/gpt-4o-mini)
  DELEGIRIUM_MODEL              имя модели
  DELEGIRIUM_API_URL            OpenAI-совместимый endpoint
  DELEGIRIUM_ENV_FILE           явный путь к .env

.env:
  Переменные можно не экспортировать вручную — положите их в .env в корне
  проекта (или укажите DELEGIRIUM_ENV_FILE). Уже заданные в окружении
  переменные имеют приоритет. Шаблон — .env.example.
TXT);

        return 0;
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>
     */
    private function options(array $flags): array
    {
        $options = [
            'regenerate' => ($flags['regen'] ?? false) === true,
            'record_file' => ($flags['record'] ?? false) === true ? Delegirium::fixturesFile() : null,
        ];

        if (isset($flags['cache-dir']) && is_string($flags['cache-dir'])) {
            $options['cache_dir'] = $flags['cache-dir'];
        }

        $provider = $flags['provider'] ?? null;

        if (is_string($provider) && $provider !== '' && $provider !== 'auto') {
            $options['provider'] = $provider;
        }

        return $options;
    }

    private function summary(CompiledUnit $unit, float $seconds): void
    {
        if ($unit->isEmpty()) {
            return;
        }

        $cached = count(array_filter($unit->declarations(), static fn (array $d): bool => (bool) ($d['cached'] ?? false)));

        $this->line('');
        $this->line(sprintf(
            '  %d функц., из них из кеша: %d%s',
            count($unit->declarations()),
            $cached,
            $seconds > 0 ? sprintf(', %.3f с', $seconds) : '',
        ));
    }

    private function row(string $label, mixed $value): void
    {
        $printed = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            $value === null => 'null',
            default => (string) $value,
        };

        $this->line(sprintf('    %s = %s', $label . str_repeat(' ', max(1, 30 - self::textWidth($label))), $printed));
    }

    /**
     * Ширина в символах, а не в байтах: иначе кириллица ломает выравнивание.
     */
    private static function textWidth(string $text): int
    {
        return preg_match_all('/./us', $text, $matches) === false ? strlen($text) : count($matches[0]);
    }

    private function line(string $text): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    private static function maskValue(string $key, string $value): string
    {
        if ($value === '') {
            return '(пусто)';
        }

        if (preg_match('/KEY|SECRET|TOKEN|PASSWORD/i', $key) !== 1) {
            return $value;
        }

        return substr($value, 0, 4) . str_repeat('*', 4) . ' (скрыто)';
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, 'delegirium: ' . $message . PHP_EOL);

        return 1;
    }

    /**
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private static function parseArguments(array $args): array
    {
        $positional = [];
        $flags = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                $positional[] = $arg;
                continue;
            }

            $name = ltrim($arg, '-');
            $value = true;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            $flags[$name] = $value;
        }

        return [$positional, $flags];
    }
}
