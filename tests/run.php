<?php

declare(strict_types=1);

/**
 * Мини-тесты без зависимостей: php tests/run.php
 */

require dirname(__DIR__) . '/src/autoload.php';

use Delegirium\Cache;
use Delegirium\CompiledUnit;
use Delegirium\Compiler;
use Delegirium\Delegirium;
use Delegirium\DelegiriumError;
use Delegirium\DotEnv;
use Delegirium\GenerationRequest;
use Delegirium\Http\HttpClient;
use Delegirium\Http\HttpResponse;
use Delegirium\Parser;
use Delegirium\Provider\ChatCompletionsProvider;
use Delegirium\Provider\OpenAiProvider;
use Delegirium\Provider\OpenRouterProvider;
use Delegirium\Provider\ProviderInterface;
use Delegirium\Provider\RecordedProvider;
use Delegirium\Validator;

final class FakeProvider implements ProviderInterface
{
    /** @var list<GenerationRequest> */
    public array $requests = [];

    /** @var array<string, int> */
    private array $calls = [];

    /**
     * @param array<string, string|list<string>> $bodies FQN -> тело или список попыток
     */
    public function __construct(private array $bodies, private string $modelName = 'fake-1')
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function generate(GenerationRequest $request): string
    {
        $this->requests[] = $request;
        $value = $this->bodies[$request->fqName] ?? null;

        if ($value === null) {
            throw new DelegiriumError('нет тела для ' . $request->fqName);
        }

        if (is_array($value)) {
            $index = $this->calls[$request->fqName] ?? 0;
            $this->calls[$request->fqName] = $index + 1;

            return $value[min($index, count($value) - 1)];
        }

        return $value;
    }
}

$passed = 0;
$failed = 0;
$tempDirs = [];

function test(string $name, callable $test): void
{
    global $passed, $failed;

    try {
        $test();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}\n       {$error->getMessage()}\n";
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '') .
            'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true),
        );
    }
}

function expectError(callable $fn, string $needle): void
{
    try {
        $fn();
    } catch (DelegiriumError $error) {
        check(
            str_contains(strtolower($error->getMessage()), strtolower($needle)),
            'в ошибке нет «' . $needle . '»: ' . $error->getMessage(),
        );

        return;
    }

    throw new RuntimeException('ожидалась DelegiriumError, но исключения не было');
}

function tempDir(): string
{
    global $tempDirs;

    $dir = sys_get_temp_dir() . '/delegirium-test-' . bin2hex(random_bytes(4));

    if (!mkdir($dir, 0777, true)) {
        throw new RuntimeException('не удалось создать ' . $dir);
    }

    $tempDirs[] = $dir;

    return $dir;
}

/**
 * @param list<string> $names
 * @return array<string, string|false>
 */
function saveEnv(array $names): array
{
    $values = [];

    foreach ($names as $name) {
        $values[$name] = getenv($name);
    }

    return $values;
}

/**
 * @param array<string, string|false> $values
 */
function restoreEnv(array $values): void
{
    foreach ($values as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}

/**
 * @param list<string> $allowed
 */
function request(
    string $name = 'add',
    string $returnType = 'int',
    bool $pure = true,
    string $doc = "@pure\n",
    string $signature = 'public function add($x, $y): int',
    ?string $className = 'Calc',
    string $namespace = 'Tests\\Unit',
): GenerationRequest {
    return new GenerationRequest(
        name: $name,
        fqName: ($namespace !== '' ? $namespace . '\\' : '') . ($className !== null ? $className . '::' : '') . $name,
        signature: $signature,
        returnType: $returnType,
        doc: $doc,
        className: $className,
        namespace: $namespace,
        pure: $pure,
    );
}

echo "Delegirium tests\n\n";

// --- Парсер ---------------------------------------------------------------

test('парсер находит AI-функции и игнорирует обычные', function (): void {
    $source = <<<'PHP'
<?php
namespace Demo;

final class Calc
{
    /** @pure */
    public function add($x, $y): int ai;

    public static function sub($x, $y): int
    {
        return $x - $y;
    }
}

/** @pure */
function triple(int $n): int ai;
PHP;

    $declarations = Parser::parse($source);

    same(2, count($declarations), 'количество объявлений');
    same('Demo\Calc::add', $declarations[0]->fqName());
    same('int', $declarations[0]->returnType);
    same(true, $declarations[0]->isPure());
    same('    ', $declarations[0]->indent, 'отступ метода');
    same('Demo\triple', $declarations[1]->fqName());
    same('', $declarations[1]->indent, 'отступ функции верхнего уровня');
});

test('парсер не путает ai-параметр с маркером', function (): void {
    $source = '<?php function f($ai) { return $ai; }';
    same(0, count(Parser::parse($source)));
});

// --- Компиляция -----------------------------------------------------------

test('компилятор вставляет тело и результат исполняется', function (): void {
    $source = <<<'PHP'
<?php
namespace Tests\Compile;

final class Calc
{
    /** @pure */
    public function add($x, $y): int ai;
}
PHP;

    $provider = new FakeProvider(['Tests\Compile\Calc::add' => 'return $x + $y;']);
    $unit = (new Compiler($provider))->compile($source, 'compile.dlr');

    check(str_contains($unit->php(), 'return $x + $y;'), 'тело не вставлено');
    check(!str_contains($unit->php(), ' ai;'), 'маркер остался в выводе');
    check(
        str_contains($unit->php(), "): int\n    {\n        return \$x + \$y;\n    }"),
        'сгенерированное тело вставлено не по месту или сбит отступ',
    );

    $calculator = $unit->instance('Tests\Compile\Calc');
    same(5, $calculator->add(2, 3));
    same(1, count($unit->declarations()));
    same(false, $unit->declarations()[0]['cached']);
});

test('кеш возвращает тело без вызова провайдера', function (): void {
    $source = "<?php\nnamespace Tests\\Cache;\n\nfinal class Calc\n{\n    /** @pure */\n    public function add(\$x, \$y): int ai;\n}\n";
    $cache = new Cache(tempDir());

    $first = new FakeProvider(['Tests\Cache\Calc::add' => 'return $x + $y;']);
    (new Compiler($first, $cache))->compile($source);

    $second = new FakeProvider(['Tests\Cache\Calc::add' => 'return 0;']);
    $unit = (new Compiler($second, $cache))->compile($source);

    same([], $second->requests, 'провайдер не должен вызываться');
    check(str_contains($unit->php(), 'return $x + $y;'), 'взято не кешированное тело');
    same(true, $unit->declarations()[0]['cached']);
});

test('--regen игнорирует кеш', function (): void {
    $source = "<?php\nnamespace Tests\\Regen;\n\nfinal class Calc\n{\n    /** @pure */\n    public function add(\$x, \$y): int ai;\n}\n";
    $cache = new Cache(tempDir());

    (new Compiler(new FakeProvider(['Tests\Regen\Calc::add' => 'return $x + $y;']), $cache))->compile($source);

    $provider = new FakeProvider(['Tests\Regen\Calc::add' => 'return $y + $x;']);
    $unit = (new Compiler($provider, $cache, regenerate: true))->compile($source);

    same(1, count($provider->requests));
    check(str_contains($unit->php(), 'return $y + $x;'), 'тело не перегенерировано');
});

test('ретрай передаёт модели текст ошибки валидатора', function (): void {
    $source = "<?php\nnamespace Tests\\Retry;\n\nfinal class Calc\n{\n    /** @pure */\n    public function add(\$x, \$y): int ai;\n}\n";
    $provider = new FakeProvider([
        'Tests\Retry\Calc::add' => ['exec("rm -rf /");', 'return $x + $y;'],
    ]);

    $unit = (new Compiler($provider, maxAttempts: 2))->compile($source);

    same(2, count($provider->requests), 'должно быть две попытки');
    same(null, $provider->requests[0]->feedback);
    check(
        $provider->requests[1]->feedback !== null
            && str_contains($provider->requests[1]->feedback, 'exec'),
        'во второй попытке нет обратной связи',
    );
    check(str_contains($unit->php(), 'return $x + $y;'), 'тело из второй попытки не вставлено');
});

test('невалидный PHP после подстановки не проходит финальный парс', function (): void {
    $source = "<?php\nnamespace Tests\\Lint;\n\nfinal class Calc\n{\n    /** @pure */\n    public function add(\$x, \$y): int ai;\n}\n";

    expectError(
        fn () => (new Compiler(new FakeProvider(['Tests\Lint\Calc::add' => 'return $x + ;']), maxAttempts: 1))->compile($source),
        'не компилируется',
    );
});

// --- Валидатор ------------------------------------------------------------

test('валидатор запрещает опасные вызовы', function (): void {
    $validator = new Validator();

    expectError(fn () => $validator->validateBody('exec("rm -rf /");', request()), 'exec');
    expectError(fn () => $validator->validateBody('$f = `ls`;', request()), 'кавычки');
    expectError(fn () => $validator->validateBody('$x = file_get_contents("/etc/passwd"); return 1;', request()), 'file_get_contents');
});

test('@pure запрещает недетерминизм и вывод', function (): void {
    $validator = new Validator();

    expectError(fn () => $validator->validateBody('return time();', request()), 'time');
    expectError(fn () => $validator->validateBody('echo $x; return 1;', request()), 'pure');
    expectError(fn () => $validator->validateBody('static $c = 0; return ++$c;', request()), 'static');
});

test('валидатор требует return при объявленном типе', function (): void {
    $validator = new Validator();

    expectError(fn () => $validator->validateBody('$x = 1;', request()), 'return');
});

test('валидатор ловит обрезанное тело', function (): void {
    expectError(
        fn () => (new Validator())->validateBody('if ($x) { return 1;', request()),
        'скобк',
    );
});

test('валидатор не пускает объявления внутри тела', function (): void {
    expectError(
        fn () => (new Validator())->validateBody('function inner() { return 1; } return 2;', request()),
        'объявлять',
    );
});

test('валидатор разрешает замыкания и Foo::class', function (): void {
    $body = '$twice = function (int $n): int { return $n * 2; }; return $twice($x) + strlen(\DateTime::class);';

    same([], (new Validator())->validateBody($body, request()));
});

// --- Промпты и кеш-ключи --------------------------------------------------

test('отпечаток зависит от докблока и модели', function (): void {
    $base = request();
    $changedDoc = request(doc: "@pure\n@throws \\RuntimeException\n");
    $otherModel = request();

    check(
        $base->fingerprint('fake', 'fake-1') !== $changedDoc->fingerprint('fake', 'fake-1'),
        'изменение докблока должно менять отпечаток',
    );
    check($base->fingerprint('fake', 'm1') !== $otherModel->fingerprint('fake', 'm2'), 'модель должна влиять на отпечаток');
    check($base->withFeedback('неверный return')->fingerprint('fake', 'fake-1') === $base->fingerprint('fake', 'fake-1'), 'feedback не входит в отпечаток');
});

test('промпт содержит сигнатуру и ограничения', function (): void {
    $prompt = request()->systemPrompt() . "\n" . request()->userPrompt();

    check(str_contains($prompt, 'public function add($x, $y): int'), 'нет сигнатуры');
    check(str_contains($prompt, '@pure'), 'нет упоминания @pure');
    check(str_contains($prompt, 'exec'), 'нет запрета exec');
    check(str_contains($prompt, 'Tests\Unit\Calc::add'), 'нет полного имени');
});

// --- Ответ модели ---------------------------------------------------------

test('extractBody снимает markdown и внешние скобки', function (): void {
    $raw = "```php\n{\n    return \$x + \$y;\n}\n```";

    same("return \$x + \$y;", OpenAiProvider::extractBody($raw));
    same('return 1;', OpenAiProvider::extractBody("<?php\nreturn 1;\n?>"));
});

test('normalizeBody идемпотентен', function (): void {
    $body = "return \$x + \$y;";

    same($body, Compiler::normalizeBody($body));
    same($body, Compiler::normalizeBody(Compiler::normalizeBody($body)));
});

// --- Фасад ----------------------------------------------------------------

test('Delegirium::compileSource работает с провайдером из опций', function (): void {
    $source = "<?php\nnamespace Tests\\Facade;\n\n/** @pure */\nfunction double(int \$n): int ai;\n";
    $provider = new FakeProvider(['Tests\Facade\double' => 'return $n * 2;']);

    $unit = Delegirium::compileSource($source, 'facade', ['provider' => $provider, 'cache' => false]);

    same(42, $unit->invoke('Tests\Facade\double', [21]));
    same(CompiledUnit::class, $unit::class);
});

test('оффлайн-провайдер воспроизводит записанные ответы', function (): void {
    $unit = Delegirium::compileFile(dirname(__DIR__) . '/examples/math.dlr', [
        'provider' => new RecordedProvider(null, Delegirium::fixturesFile()),
        'cache' => false,
    ]);

    $calculator = $unit->instance('Delegirium\Examples\Math');

    same(30, $calculator->add(10, 20));
    same(55, $calculator->fib(10));
    same(1.5, $calculator->divide(3.0, 2.0));
});

// --- OpenRouter -----------------------------------------------------------

test('OpenRouter: базовый URL превращается в chat/completions', function (): void {
    same(
        'https://api.ru-openrouter.ru/v1/chat/completions',
        OpenRouterProvider::chatEndpoint('https://api.ru-openrouter.ru/v1'),
    );
    same(
        'https://api.ru-openrouter.ru/v1/chat/completions',
        OpenRouterProvider::chatEndpoint('  https://api.ru-openrouter.ru/v1/  '),
    );
    same(
        'https://api.ru-openrouter.ru/v1/chat/completions',
        OpenRouterProvider::chatEndpoint('https://api.ru-openrouter.ru/v1/chat/completions'),
    );
});

test('OpenRouter: явный провайдер берёт ключ, базу и модель', function (): void {
    $env = saveEnv([
        'DELEGIRIUM_PROVIDER', 'DELEGIRIUM_API_KEY', 'DELEGIRIUM_MODEL',
        'DELEGIRIUM_OPENROUTER_KEY', 'DELEGIRIUM_OPENROUTER_URL', 'DELEGIRIUM_OPENROUTER_MODEL',
    ]);

    putenv('DELEGIRIUM_PROVIDER');
    putenv('DELEGIRIUM_API_KEY');
    putenv('DELEGIRIUM_OPENROUTER_KEY=test-key');
    putenv('DELEGIRIUM_OPENROUTER_URL=https://api.ru-openrouter.ru/v1');
    putenv('DELEGIRIUM_OPENROUTER_MODEL=anthropic/claude-3.5-sonnet');

    try {
        $provider = Delegirium::provider(['provider' => 'openrouter']);

        check($provider instanceof OpenRouterProvider, 'должен вернуться OpenRouterProvider');
        same('openrouter', $provider->name());
        same('anthropic/claude-3.5-sonnet', $provider->model());
        same('https://api.ru-openrouter.ru/v1/chat/completions', $provider->endpoint());
    } finally {
        restoreEnv($env);
    }
});

test('OpenRouter: auto включается по ключу, значения по умолчанию', function (): void {
    $env = saveEnv([
        'DELEGIRIUM_PROVIDER', 'DELEGIRIUM_API_KEY', 'DELEGIRIUM_MODEL',
        'DELEGIRIUM_OPENROUTER_KEY', 'DELEGIRIUM_OPENROUTER_URL', 'DELEGIRIUM_OPENROUTER_MODEL',
    ]);

    putenv('DELEGIRIUM_PROVIDER');
    putenv('DELEGIRIUM_API_KEY');
    putenv('DELEGIRIUM_MODEL');
    putenv('DELEGIRIUM_OPENROUTER_KEY=test-key');
    putenv('DELEGIRIUM_OPENROUTER_URL');
    putenv('DELEGIRIUM_OPENROUTER_MODEL');

    try {
        $provider = Delegirium::provider([]);

        same('openrouter', $provider->name());
        same(OpenRouterProvider::DEFAULT_MODEL, $provider->model());
        same(
            'https://api.ru-openrouter.ru/v1/chat/completions',
            $provider->endpoint(),
            'должен использоваться адрес ru-openrouter по умолчанию',
        );
    } finally {
        restoreEnv($env);
    }
});

test('OpenRouter: без ключа — понятная ошибка, отпечаток учитывает провайдера', function (): void {
    $env = saveEnv([
        'DELEGIRIUM_PROVIDER', 'DELEGIRIUM_API_KEY',
        'DELEGIRIUM_OPENROUTER_KEY', 'DELEGIRIUM_OPENROUTER_URL', 'DELEGIRIUM_OPENROUTER_MODEL',
    ]);

    putenv('DELEGIRIUM_PROVIDER');
    putenv('DELEGIRIUM_API_KEY');
    putenv('DELEGIRIUM_OPENROUTER_KEY');

    try {
        expectError(fn () => Delegirium::provider(['provider' => 'openrouter']), 'DELEGIRIUM_OPENROUTER_KEY');
        expectError(fn () => Delegirium::provider(['provider' => 'nope']), 'Неизвестный провайдер');
    } finally {
        restoreEnv($env);
    }

    check(
        request()->fingerprint('openai', 'm') !== request()->fingerprint('openrouter', 'm'),
        'провайдер должен влиять на отпечаток',
    );
});

test('OpenRouter: заголовки содержат ключ, X-Title и Referer', function (): void {
    $provider = new OpenRouterProvider('test-key', referer: 'https://example.test');
    $headers = (new ReflectionMethod($provider, 'headers'))->invoke($provider);

    check(in_array('Authorization: Bearer test-key', $headers, true), 'нет Bearer-ключа');
    check(in_array('X-Title: Delegirium', $headers, true), 'нет X-Title');
    check(in_array('HTTP-Referer: https://example.test', $headers, true), 'нет Referer');
    check(in_array('Content-Type: application/json', $headers, true), 'нет Content-Type');
});

// --- .env -----------------------------------------------------------------

test('.env: парсит комментарии, кавычки, export и inline-комментарии', function (): void {
    $parsed = DotEnv::parse(<<<'ENV'
# комментарий
SIMPLE=value
export EXPORTED=yes
QUOTED="a b  c"
SINGLE='x # не комментарий'
ESCAPED="line1\nline2"
INLINE=abc # комментарий
EMPTY=
URL=https://api.example.test/v1/path?x=1
ENV);

    same('value', $parsed['SIMPLE']);
    same('yes', $parsed['EXPORTED']);
    same('a b  c', $parsed['QUOTED']);
    same('x # не комментарий', $parsed['SINGLE']);
    same("line1\nline2", $parsed['ESCAPED']);
    same('abc', $parsed['INLINE']);
    same('', $parsed['EMPTY']);
    same('https://api.example.test/v1/path?x=1', $parsed['URL']);
});

test('.env: не перезатирает окружение без overwrite', function (): void {
    $file = tempDir() . '/.env';
    file_put_contents($file, "DELEGIRIUM_TEST_KEEP=from-file\nDELEGIRIUM_TEST_NEW=from-file\n");

    $env = saveEnv(['DELEGIRIUM_TEST_KEEP', 'DELEGIRIUM_TEST_NEW']);
    putenv('DELEGIRIUM_TEST_KEEP=from-shell');
    putenv('DELEGIRIUM_TEST_NEW');

    try {
        $applied = DotEnv::loadFile($file);

        same('from-shell', getenv('DELEGIRIUM_TEST_KEEP'), 'shell имеет приоритет');
        same('from-file', getenv('DELEGIRIUM_TEST_NEW'));
        same(['DELEGIRIUM_TEST_NEW' => 'from-file'], $applied);

        DotEnv::loadFile($file, overwrite: true);
        same('from-file', getenv('DELEGIRIUM_TEST_KEEP'), 'overwrite должен перезаписать');
    } finally {
        restoreEnv($env);
    }
});

test('.env: провайдер видит значения из файла', function (): void {
    $env = saveEnv([
        'DELEGIRIUM_PROVIDER', 'DELEGIRIUM_API_KEY',
        'DELEGIRIUM_OPENROUTER_KEY', 'DELEGIRIUM_OPENROUTER_URL', 'DELEGIRIUM_OPENROUTER_MODEL',
    ]);

    putenv('DELEGIRIUM_PROVIDER');
    putenv('DELEGIRIUM_API_KEY');
    putenv('DELEGIRIUM_OPENROUTER_KEY');
    putenv('DELEGIRIUM_OPENROUTER_URL');
    putenv('DELEGIRIUM_OPENROUTER_MODEL');

    $file = tempDir() . '/.env';
    file_put_contents($file, "DELEGIRIUM_OPENROUTER_KEY=from-dotenv\nDELEGIRIUM_OPENROUTER_MODEL=deepseek/deepseek-chat\n");

    try {
        DotEnv::loadFile($file);
        $provider = Delegirium::provider([]);

        same('openrouter', $provider->name());
        same('deepseek/deepseek-chat', $provider->model());
    } finally {
        restoreEnv($env);
    }
});

// --- HTTP-транспорт --------------------------------------------------------

test('HttpResponse: ok() только для 2xx без ошибки', function (): void {
    check((new HttpResponse(200, 'x'))->ok(), '200 должен быть ok');
    check(!(new HttpResponse(500, 'x'))->ok(), '500 не ok');
    check(!(new HttpResponse(0, '', 'boom'))->ok(), 'ошибка не ok');
    check(!(new HttpResponse(200, 'x'))->failed(), 'нет ошибки транспорта');
    check((new HttpResponse(0, '', 'boom'))->failed(), 'failed() должен быть true');
});

test('HttpClient: транспорт выбирается из env', function (): void {
    $env = saveEnv(['DELEGIRIUM_HTTP']);

    try {
        putenv('DELEGIRIUM_HTTP=stream');
        same('stream', HttpClient::fromEnv()->transportName());

        putenv('DELEGIRIUM_HTTP=curl');
        same('curl', HttpClient::fromEnv()->transportName());

        putenv('DELEGIRIUM_HTTP=auto');
        same(
            function_exists('curl_init') ? 'curl' : 'stream',
            HttpClient::fromEnv()->transportName(),
            'auto должен выбирать curl, когда он есть',
        );
    } finally {
        restoreEnv($env);
    }
});

test('HttpClient: прокси и insecure из env', function (): void {
    $env = saveEnv(['DELEGIRIUM_PROXY', 'DELEGIRIUM_INSECURE', 'DELEGIRIUM_CAFILE']);

    try {
        putenv('DELEGIRIUM_PROXY=127.0.0.1:8080');
        putenv('DELEGIRIUM_INSECURE=1');
        putenv('DELEGIRIUM_CAFILE=/tmp/ca.pem');

        $client = HttpClient::fromEnv();
        same('127.0.0.1:8080', $client->proxy());
        same(true, $client->insecure());
        same('/tmp/ca.pem', $client->caFile());
    } finally {
        restoreEnv($env);
    }
});

test('HttpClient: подсказки к ошибкам транспорта', function (): void {
    check(
        count(HttpClient::troubleshooting('SSL operation failed: certificate verify failed')) >= 2,
        'ожидались подсказки про TLS',
    );

    $dns = HttpClient::troubleshooting('php_network_getaddresses: getaddrinfo failed');
    check(
        in_array(true, array_map(static fn (string $h): bool => str_contains($h, 'DNS'), $dns), true),
        'ожидалась подсказка про DNS',
    );

    same([], HttpClient::troubleshooting(''));
});

test('HttpClient: сбой соединения возвращается как ошибка', function (): void {
    $client = new HttpClient(transport: 'stream');
    $response = $client->post('http://127.0.0.1:1/', ['Content-Type: application/json'], '{}', 2);

    check($response->failed(), 'должна быть ошибка транспорта');
    check(trim((string) $response->error) !== '', 'текст ошибки обязан быть непустым');
    same('stream', $response->transport);
});

test('DELEGIRIUM_RECORD=1 включает запись в fixtures', function (): void {
    $env = saveEnv(['DELEGIRIUM_PROVIDER', 'DELEGIRIUM_API_KEY', 'DELEGIRIUM_RECORD']);

    putenv('DELEGIRIUM_PROVIDER=openai');
    putenv('DELEGIRIUM_API_KEY=test-key');
    putenv('DELEGIRIUM_RECORD=1');

    try {
        $provider = Delegirium::provider([]);
        $recordFile = (new ReflectionProperty(ChatCompletionsProvider::class, 'recordFile'))->getValue($provider);

        same(Delegirium::fixturesFile(), $recordFile);
    } finally {
        restoreEnv($env);
    }
});

test('HttpClient: CA-бандл подхватывается автоматически, если найден', function (): void {
    $env = saveEnv(['DELEGIRIUM_CAFILE']);
    putenv('DELEGIRIUM_CAFILE');

    try {
        $caFile = HttpClient::fromEnv()->caFile();
        check($caFile === null || is_file($caFile), 'caFile должен быть существующим файлом или null');
    } finally {
        restoreEnv($env);
    }
});

test('компилятор предупреждает о расхождении @return и объявленного типа', function (): void {
    $source = "<?php\nnamespace Tests\\Contract;\n\n/**\n * @return string\n * @pure\n */\nfunction broken(string \$s): int ai;\n";
    $unit = (new Compiler(new FakeProvider(['Tests\\Contract\\broken' => 'return 42;'])))->compile($source);

    same(1, count($unit->warnings()));
    check(str_contains($unit->warnings()[0], '@return string'), 'нет типа из докблока');
    check(str_contains($unit->warnings()[0], 'int'), 'нет объявленного типа');
});

test('согласованный @return не даёт предупреждений', function (): void {
    $source = "<?php\nnamespace Tests\\ContractOk;\n\n/**\n * @return int\n * @pure\n */\nfunction ok(int \$n): int ai;\n";
    $unit = (new Compiler(new FakeProvider(['Tests\\ContractOk\\ok' => 'return $n;'])))->compile($source);

    same([], $unit->warnings());
});

test('add_emoji_after_every_word из fixtures вставляет эмодзи', function (): void {
    $unit = Delegirium::compileFile(dirname(__DIR__) . '/examples/text.dlr', [
        'provider' => new RecordedProvider(null, Delegirium::fixturesFile()),
        'cache' => false,
    ]);

    $call = static fn (string $input): mixed => $unit->invoke('Delegirium\\Examples\\Text\\add_emoji_after_every_word', [$input]);

    same('привет😀, мир😀', $call('привет, мир'));
    same('hello😀 world😀', $call('hello world'));
    same('', $call(''));
});

// --- Итог -----------------------------------------------------------------

foreach ($tempDirs as $dir) {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);