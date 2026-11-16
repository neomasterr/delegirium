<?php

declare(strict_types=1);

namespace Delegirium\Provider;

use Delegirium\DelegiriumError;

/**
 * OpenRouter (в том числе русский прокси https://api.ru-openrouter.ru/v1).
 *
 * Протокол тот же, что у OpenAI (`POST /chat/completions`), но модели задаются
 * в формате `vendor/model`, а для атрибуции трафика рекомендуются заголовки
 * HTTP-Referer и X-Title.
 *
 *   DELEGIRIUM_OPENROUTER_KEY      — обязателен (может быть общим DELEGIRIUM_API_KEY)
 *   DELEGIRIUM_OPENROUTER_URL      — базовый URL, по умолчанию https://api.ru-openrouter.ru/v1
 *   DELEGIRIUM_OPENROUTER_MODEL    — по умолчанию openai/gpt-4o-mini
 *   DELEGIRIUM_OPENROUTER_REFERER  — необязательный HTTP-Referer
 */
final class OpenRouterProvider extends ChatCompletionsProvider
{
    public const DEFAULT_BASE_URL = 'https://api.ru-openrouter.ru/v1';
    public const DEFAULT_MODEL = 'openai/gpt-4o-mini';

    public function __construct(
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $recordFile = null,
        ?string $referer = null,
        string $title = 'Delegirium',
        float $temperature = 0.0,
        int $timeout = 60,
    ) {
        $headers = [];

        if ($referer !== null && trim($referer) !== '') {
            $headers[] = 'HTTP-Referer: ' . trim($referer);
        }

        if (trim($title) !== '') {
            $headers[] = 'X-Title: ' . trim($title);
        }

        parent::__construct(
            providerName: 'openrouter',
            apiKey: $apiKey,
            model: $model,
            endpoint: self::chatEndpoint($baseUrl),
            recordFile: $recordFile,
            temperature: $temperature,
            timeout: $timeout,
            extraHeaders: $headers,
        );
    }

    /**
     * Принимает и базовый URL (`.../v1`), и полный endpoint
     * (`.../v1/chat/completions`).
     */
    public static function chatEndpoint(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '') {
            throw new DelegiriumError('OpenRouterProvider: пустой базовый URL.');
        }

        if (str_ends_with($baseUrl, '/chat/completions')) {
            return $baseUrl;
        }

        return $baseUrl . '/chat/completions';
    }
}
