<?php

declare(strict_types=1);

namespace Delegirium;

use Delegirium\Http\HttpClient;
use Delegirium\Provider\ChatCompletionsProvider;
use Delegirium\Provider\RecordedProvider;

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
        'DELEGIRIUM_HTTP',
        'DELEGIRIUM_PROXY',
        'DELEGIRIUM_CAFILE',
        'DELEGIRIUM_INSECURE',
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
                'doctor', 'ping' => $this->doctor($flags),
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

        foreach ($unit->warnings() as $warning) {
            $this->line('  ! ' . $warning);
        }

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
     * Проверяет окружение, транспорт, DNS/TLS и (по желанию) реальный запрос
     * к модели. Печатает дословную причину сбоя вместо «не удалось».
     *
     * @param array<string, mixed> $flags
     */
    private function doctor(array $flags): int
    {
        $live = ($flags['live'] ?? false) === true;
        $http = HttpClient::fromEnv();

        $this->line('Delegirium ' . Delegirium::VERSION . ' — диагностика');
        $this->line('');
        $this->line('Среда:');
        $this->stat('PHP', PHP_VERSION . ' (' . PHP_OS . ')', version_compare(PHP_VERSION, '8.1', '>='));
        $this->stat('json', extension_loaded('json') ? 'да' : 'нет', extension_loaded('json'));
        $this->stat('openssl', extension_loaded('openssl') ? 'да' : 'нет', extension_loaded('openssl'));
        $this->stat('curl', extension_loaded('curl') ? 'да' : 'нет (будет stream)', true);
        $this->stat('allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off', (bool) ini_get('allow_url_fopen'));

        $this->line('');
        $this->line('Транспорт:');
        $this->line('  ' . $http->transportName() . ($http->transportName() === 'curl' ? ' (расширение curl)' : ' (потоки PHP)'));
        $this->line('  proxy:  ' . ($http->proxy() ?? '—'));
        $this->line('  cafile: ' . ($http->caFile() ?? '—'));

        if ($http->insecure()) {
            $this->line('  ! DELEGIRIUM_INSECURE=1: TLS не проверяется. Так можно только диагностировать.');
        }

        $envFiles = Delegirium::loadedEnvFiles();
        $this->line('');
        $this->line('.env: ' . ($envFiles === [] ? 'не найден' : implode(', ', $envFiles)));

        $this->line('');
        $this->line('Провайдер:');

        try {
            $provider = Delegirium::provider($this->options($flags));
        } catch (DelegiriumError $error) {
            $this->stat('конфигурация', $error->getMessage(), false);
            $this->line('');
            $this->line('Подсказка: заполните .env по образцу .env.example или экспортируйте нужные переменные.');

            return 1;
        }

        $this->line('  ' . $provider->name() . ' / ' . $provider->model());

        if ($provider instanceof RecordedProvider) {
            $this->line('  оффлайн-провайдер: сеть не нужна');

            return 0;
        }

        $endpoint = $provider instanceof ChatCompletionsProvider ? $provider->endpoint() : null;
        $this->line('  endpoint: ' . ($endpoint ?? '—'));

        if ($endpoint === null) {
            return 0;
        }

        $this->line('');
        $this->line('Сеть:');
        $reachable = $this->probeEndpoint($endpoint, $http);

        $this->line('');

        if (!$live) {
            $this->line('Модель не вызывалась. Реальный запрос: php bin/delegirium doctor --live');

            return $reachable ? 0 : 1;
        }

        $this->line('Запрос к модели:');

        try {
            $body = $provider->generate($this->smokeRequest());
            $this->stat('ответ', 'получено тело: ' . $body, true);

            return 0;
        } catch (DelegiriumError $error) {
            $this->stat('ответ', $error->getMessage(), false);

            return 1;
        }
    }

    private function probeEndpoint(string $endpoint, HttpClient $http): bool
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts) || !isset($parts['host'])) {
            $this->stat('endpoint', 'не удалось разобрать ' . $endpoint, false);

            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $proxy = $http->proxy();

        if ($proxy !== null) {
            $this->line('  DNS пропущен: используется прокси ' . $proxy);
        } else {
            $ip = gethostbyname($host);

            if ($ip !== $host) {
                $this->stat('DNS', $host . ' -> ' . $ip, true);
            } else {
                $this->stat('DNS', $host . ' не разрешается', false);
            }
        }

        $remote = $proxy !== null
            ? 'tcp://' . $proxy
            : ($scheme === 'https' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $context = [];

        if ($proxy === null && $scheme === 'https') {
            if ($http->caFile() !== null) {
                $context['ssl']['cafile'] = $http->caFile();
            }

            if ($http->insecure()) {
                $context['ssl']['verify_peer'] = false;
                $context['ssl']['verify_peer_name'] = false;
            }
        }

        $warning = null;
        $errno = 0;
        $errstr = '';

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $socket = stream_socket_client(
                $remote,
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT,
                stream_context_create($context),
            );
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            $reason = trim($errstr) !== '' ? $errstr : trim((string) $warning);
            $this->stat('TLS/TCP', $remote . ': ' . ($reason !== '' ? $reason : 'не удалось'), false);

            foreach (HttpClient::troubleshooting($reason) as $hint) {
                $this->line('       - ' . $hint);
            }

            return false;
        }

        fclose($socket);
        $this->stat('TLS/TCP', $remote . ' доступен', true);

        return true;
    }

    private function smokeRequest(): GenerationRequest
    {
        return new GenerationRequest(
            name: 'smoke',
            fqName: 'Delegirium\\Doctor\\smoke',
            signature: 'function smoke(): int',
            returnType: 'int',
            doc: "/** @pure Возвращает число 42. */",
            className: null,
            namespace: 'Delegirium\\Doctor',
            pure: true,
        );
    }

    private function stat(string $label, string $value, bool $ok): void
    {
        $this->line(sprintf('  [%s] %-18s %s', $ok ? 'ok' : '!!', $label, $value));
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
        $this->row('add_emoji("привет, мир")', $text->invoke('Delegirium\\Examples\\Text\\add_emoji_after_every_word', ['привет, мир']));

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
  delegirium doctor    диагностика сети/ключа (--live — реальный запрос к модели)
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
  DELEGIRIUM_HTTP              auto | curl | stream (транспорт)
  DELEGIRIUM_PROXY             host:port (иначе HTTPS_PROXY/HTTP_PROXY)
  DELEGIRIUM_CAFILE            путь к PEM-бандлу CA
  DELEGIRIUM_INSECURE          1 — отключить проверку TLS (только диагностика)

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
        // `| head` закрывает pipe — не сыпем Notice'ами в таком случае.
        @fwrite($this->stdout, $text . PHP_EOL);
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
        @fwrite(STDERR, 'delegirium: ' . $message . PHP_EOL);

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
