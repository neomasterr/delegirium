<?php

declare(strict_types=1);

namespace Delegirium\Provider;

/**
 * OpenAI (и любой OpenAI-совместимый endpoint).
 *
 *   DELEGIRIUM_API_KEY   — обязателен
 *   DELEGIRIUM_MODEL     — по умолчанию gpt-4.1-mini
 *   DELEGIRIUM_API_URL   — по умолчанию https://api.openai.com/v1/chat/completions
 */
final class OpenAiProvider extends ChatCompletionsProvider
{
    public function __construct(
        string $apiKey,
        string $model = 'gpt-4.1-mini',
        string $endpoint = 'https://api.openai.com/v1/chat/completions',
        ?string $recordFile = null,
        float $temperature = 0.0,
        int $timeout = 60,
    ) {
        parent::__construct(
            providerName: 'openai',
            apiKey: $apiKey,
            model: $model,
            endpoint: $endpoint,
            recordFile: $recordFile,
            temperature: $temperature,
            timeout: $timeout,
        );
    }
}
