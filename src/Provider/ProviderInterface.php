<?php

declare(strict_types=1);

namespace Delegirium\Provider;

use Delegirium\DelegiriumError;
use Delegirium\GenerationRequest;

/**
 * Источник тел функций. Всё, что знает компилятор о «мозге» языка, — этот
 * интерфейс: сгенерируй тело по контракту. Реальный LLM, записанный ответ,
 * детерминированная заглушка или кеш-провайдер взаимозаменяемы.
 */
interface ProviderInterface
{
    /** Человекочитаемое имя провайдера (попадает в отпечаток кеша). */
    public function name(): string;

    /** Идентификатор модели (тоже часть отпечатка кеша). */
    public function model(): string;

    /**
     * Возвращает операторы тела функции — без `<?php`, без внешних `{ }`.
     *
     * @throws DelegiriumError если тело получить не удалось
     */
    public function generate(GenerationRequest $request): string;
}
