<?php

declare(strict_types=1);

/**
 * Минимальный автозагрузчик PSR-4 для пространства имён Delegirium.
 * Проект принципиально без зависимостей: достаточно PHP >= 8.1.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Delegirium\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
