<?php

declare(strict_types=1);

namespace Delegirium;

use Delegirium\Provider\ProviderInterface;

/**
 * Компилятор Delegirium: исходник с маркерами `ai;` -> обычный PHP.
 *
 * Для каждого AI-объявления:
 *   1. строится запрос к провайдеру (сигнатура + докблок как контракт);
 *   2. тело ищется в кеше по отпечатку промпта;
 *   3. при промахе вызывается провайдер и тело проходит валидацию;
 *   4. при провале валидации модель получает текст ошибки и пробует снова.
 *
 * Остальной код файла не меняется — на выходе остаётся тот же класс,
 * namespace и отступы.
 */
final class Compiler
{
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly ?Cache $cache = null,
        private readonly Validator $validator = new Validator(),
        private readonly bool $regenerate = false,
        private readonly int $maxAttempts = 2,
    ) {
        if ($this->maxAttempts < 1) {
            throw new DelegiriumError('Compiler: maxAttempts должен быть >= 1');
        }
    }

    public function provider(): ProviderInterface
    {
        return $this->provider;
    }

    public function compile(string $source, string $label = '<source>'): CompiledUnit
    {
        $normalized = str_starts_with(ltrim($source), '<?php') ? $source : "<?php\n" . $source;
        $declarations = Parser::parse($normalized);

        $replacements = [];
        $meta = [];
        $warnings = [];

        foreach ($declarations as $decl) {
            $request = $decl->request();
            $key = $request->fingerprint($this->provider->name(), $this->provider->model());

            $warnings = [...$warnings, ...self::contractWarnings($decl)];

            $body = null;
            $cached = false;

            if ($this->cache !== null && !$this->regenerate) {
                $hit = $this->cache->get($key);

                if ($hit !== null) {
                    $body = self::normalizeBody($hit['body']);
                    $cached = true;
                }
            }

            if ($body === null) {
                $body = $this->generateWithRetries($request);
                $this->cache?->put($key, $body, [
                    'fq_name' => $request->fqName,
                    'signature' => $request->signature,
                    'provider' => $this->provider->name(),
                    'model' => $this->provider->model(),
                ]);
            }

            $warnings = [...$warnings, ...$this->validator->validateBody($body, $request)];

            $replacements[] = [
                'start' => $decl->startOffset,
                'end' => $decl->endOffset,
                'text' => "\n" . $decl->indent . "{\n"
                    . self::indent($body, $decl->indent . '    ')
                    . "\n" . $decl->indent . '}',
            ];

            $meta[] = [
                'name' => $decl->name,
                'fq_name' => $decl->fqName(),
                'signature' => $decl->signature,
                'return_type' => $decl->returnType,
                'pure' => $decl->isPure(),
                'cached' => $cached,
                'body' => $body,
                'line' => $decl->line,
            ];
        }

        usort($replacements, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

        $php = $normalized;

        foreach ($replacements as $replacement) {
            $php = substr($php, 0, $replacement['start'])
                . $replacement['text']
                . substr($php, $replacement['end']);
        }

        self::lint($php, $label);

        return new CompiledUnit($php, $label, $meta, $warnings);
    }

    /**
     * @throws DelegiriumError
     */
    public function generateWithRetries(GenerationRequest $request): string
    {
        $feedback = null;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $raw = $this->provider->generate($feedback === null ? $request : $request->withFeedback($feedback));
                $body = self::normalizeBody($raw);
                $this->validator->validateBody($body, $request);

                return $body;
            } catch (DelegiriumError $error) {
                if ($attempt >= $this->maxAttempts) {
                    throw new DelegiriumError(sprintf(
                        '%s: генерация не удалась после %d попыт.%s: %s',
                        $request->fqName,
                        $attempt,
                        $attempt === 1 ? 'ки' : 'ок',
                        $error->getMessage(),
                    ), 0, $error);
                }

                $feedback = $error->getMessage();
            }
        }
    }

    /**
     * Приводит ответ провайдера к «чистым операторам»: снимает теги, внешние
     * скобки, markdown-ограждение и нормализует переводы строк.
     */
    public static function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/^<\?php\s*/i', '', trim($body));
        $body = trim(str_replace('?>', '', $body));

        if (str_starts_with($body, '{') && str_ends_with($body, '}') && self::balanced(substr($body, 1, -1))) {
            $body = trim(substr($body, 1, -1));
        }

        return $body;
    }

    public static function indent(string $code, string $indent): string
    {
        if ($indent === '') {
            return $code;
        }

        $lines = explode("\n", $code);

        return implode("\n", array_map(static fn (string $line): string => $line === '' ? '' : $indent . $line, $lines));
    }

    private static function balanced(string $code): bool
    {
        $depth = 0;

        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_array($token)) {
                continue;
            }

            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;

                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * Дешёвая проверка контракта: `@return` в докблоке не должен противоречить
     * объявленному типу. При конфликте генератор верит типу, поэтому расхождение
     * почти всегда означает ошибку в объявлении (например, копипасту из соседней
     * функции с тем же телом, но другим результатом).
     *
     * @return list<string>
     */
    private static function contractWarnings(FunctionDecl $decl): array
    {
        $declared = self::normalizeType($decl->returnType);

        if ($declared === '') {
            return [];
        }

        if (preg_match('/@return\s+([^\s*]+)/', $decl->doc, $match) !== 1) {
            return [];
        }

        $raw = $match[1];
        $documented = self::normalizeType($raw);

        if ($documented === '' || $documented === $declared) {
            return [];
        }

        // Сложные формы (array<int, Foo>, A|B, ?A) построчно сравнивать бессмысленно.
        if (str_contains($raw, '<') || str_contains($raw, '|') || str_contains($raw, '&') || str_contains($raw, '?')) {
            return [];
        }

        return [sprintf(
            '%s: @return %s в докблоке не совпадает с объявленным типом : %s — генератор опирается на тип.',
            $decl->fqName(),
            $documented,
            $declared,
        )];
    }

    private static function normalizeType(string $type): string
    {
        $type = strtolower(ltrim(trim($type), '\\'));

        return match ($type) {
            'integer' => 'int',
            'boolean' => 'bool',
            'double', 'real' => 'float',
            default => $type,
        };
    }

    /**
     * Финальная проверка: вклеенный результат должен быть валидным PHP.
     * TOKEN_PARSE разбирает файл тем же парсером, что и сам интерпретатор,
     * поэтому `php -l` в отдельном процессе не нужен.
     */
    private static function lint(string $php, string $label): void
    {
        try {
            token_get_all($php, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new DelegiriumError(sprintf(
                '%s: сгенерированный код не компилируется: %s в строке %d',
                $label,
                $error->getMessage(),
                $error->getLine(),
            ), 0, $error);
        }
    }
}
