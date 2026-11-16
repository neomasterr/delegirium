<?php

declare(strict_types=1);

namespace Delegirium\Http;

/**
 * Результат HTTP-запроса: либо тело с кодом ответа, либо ошибка транспорта.
 * Ошибка не превращается в исключение: провайдер сам решает, как её показать.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $error = null,
        public readonly string $transport = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
