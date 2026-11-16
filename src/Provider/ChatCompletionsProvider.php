<?php

declare(strict_types=1);

namespace Delegirium\Provider;

use Delegirium\DelegiriumError;
use Delegirium\GenerationRequest;
use Delegirium\Http\HttpClient;

/**
 * Общая реализация провайдера поверх OpenAI-совместимого
 * `POST {endpoint}` с `{ model, messages, temperature }`.
 *
 * Наследники задают только имя провайдера, endpoint и дополнительные заголовки
 * (например, OpenRouter требует HTTP-Referer/X-Title для атрибуции).
 *
 * Сеть — через HttpClient: curl или потоки, с прокси и CA-файлом из окружения.
 * При сбое в сообщение попадает дословная причина от транспорта, а не общая
 * фраза «запрос не удался».
 */
abstract class ChatCompletionsProvider implements ProviderInterface
{
    /**
     * @param list<string> $extraHeaders
     */
    public function __construct(
        private readonly string $providerName,
        protected readonly string $apiKey,
        protected readonly string $model,
        protected readonly string $endpoint,
        protected readonly ?string $recordFile = null,
        protected readonly float $temperature = 0.0,
        protected readonly int $timeout = 60,
        protected readonly array $extraHeaders = [],
        private readonly ?HttpClient $http = null,
    ) {
        if ($apiKey === '') {
            throw new DelegiriumError(sprintf('%s: пустой API-ключ.', $providerName));
        }
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function transport(): string
    {
        return ($this->http ?? HttpClient::fromEnv())->transportName();
    }

    public function generate(GenerationRequest $request): string
    {
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt()],
                ['role' => 'user', 'content' => $request->userPrompt()],
            ],
        ];

        $http = $this->http ?? HttpClient::fromEnv();
        $response = $http->post(
            $this->endpoint,
            $this->headers(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $this->timeout,
        );

        if ($response->failed()) {
            throw new DelegiriumError(sprintf(
                '%s: запрос к %s не удался (%s): %s%s',
                $this->providerName,
                $this->endpoint,
                $response->transport !== '' ? $response->transport : $http->transportName(),
                $response->error,
                self::hints((string) $response->error),
            ));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true) ?? [];

        if ($response->status >= 400 || isset($data['error'])) {
            throw new DelegiriumError(sprintf(
                '%s: HTTP %d%s',
                $this->providerName,
                $response->status,
                self::errorDetail($data, $response->body),
            ));
        }

        if ($data === []) {
            throw new DelegiriumError(sprintf(
                '%s: некорректный ответ сервера (не JSON): %s',
                $this->providerName,
                self::snippet($response->body),
            ));
        }

        $content = self::responseText($data);

        if (trim($content) === '') {
            throw new DelegiriumError(sprintf('%s: модель вернула пустой ответ.', $this->providerName));
        }

        $body = self::extractBody($content);
        $this->record($request, $body);

        return $body;
    }

    /**
     * Достаёт тело из ответа модели: снимает markdown-ограждение, `<?php`
     * и внешние фигурные скобки, если модель их всё-таки добавила.
     */
    public static function extractBody(string $content): string
    {
        $content = trim($content);

        if (preg_match('/```(?:php)?\s*(.*?)```/is', $content, $matches) === 1) {
            $content = trim($matches[1]);
        }

        $content = preg_replace('/^<\?php\s*/i', '', $content) ?? $content;
        $content = str_replace('?>', '', $content);
        $content = trim($content);

        if (str_starts_with($content, '{') && str_ends_with($content, '}')) {
            $inner = substr($content, 1, -1);
            if (self::bracesBalanced($inner)) {
                $content = trim($inner);
            }
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            ...$this->extraHeaders,
        ];
    }

    private static function hints(string $error): string
    {
        $hints = HttpClient::troubleshooting($error);

        if ($hints === []) {
            return "\nПодробная диагностика: php bin/delegirium doctor --live";
        }

        return "\nПодсказки:\n- " . implode("\n- ", $hints)
            . "\nПодробная диагностика: php bin/delegirium doctor --live";
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function responseText(array $data): string
    {
        /** @var mixed $content */
        $content = $data['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        // Некоторые провайдеры отдают content частями.
        if (is_array($content)) {
            $parts = [];

            foreach ($content as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $parts[] = $part['text'];
                } elseif (is_string($part)) {
                    $parts[] = $part;
                }
            }

            return implode('', $parts);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function errorDetail(array $data, string $raw): string
    {
        $error = $data['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : $error;

        if (is_string($message) && $message !== '') {
            return ': ' . $message;
        }

        return $raw !== '' ? ': ' . self::snippet($raw) : '';
    }

    private static function snippet(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        // Обрезаем посимвольно (в терминах UTF-8), не разрывая символ.
        if (preg_match('/^.{0,300}/us', $text, $matches) === 1) {
            return $matches[0] === $text ? $text : $matches[0] . '…';
        }

        return $text;
    }

    private static function bracesBalanced(string $code): bool
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

    private function record(GenerationRequest $request, string $body): void
    {
        if ($this->recordFile === null) {
            return;
        }

        /** @var array<string, string> $recorded */
        $recorded = is_file($this->recordFile) ? (require $this->recordFile) : [];
        $recorded[$request->fqName] = $body;

        ksort($recorded);

        $export = "<?php\n\n// Автоматически записанные ответы модели. Не редактируйте вручную.\n\nreturn "
            . var_export($recorded, true) . ";\n";

        if (@file_put_contents($this->recordFile, $export) === false) {
            throw new DelegiriumError('Не удалось записать ' . $this->recordFile);
        }
    }
}
