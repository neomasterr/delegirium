<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Единственный тип исключения, которым пользуется компилятор.
 * Сообщение адресовано и человеку, и модели (при ретрае генерации).
 */
final class DelegiriumError extends \RuntimeException
{
    public static function at(string $label, string $message): self
    {
        return new self($label . ': ' . $message);
    }
}
