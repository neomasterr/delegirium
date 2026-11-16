<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Результат компиляции: готовый PHP-файл плюс метаданные о том, какие тела
 * были сгенерированы и какие взяты из кеша.
 *
 * Unit умеет сохранить файл, загрузить его и вызвать сгенерированную функцию —
 * этого достаточно, чтобы примеры запускались одной командой.
 */
final class CompiledUnit
{
    private ?string $path = null;

    /**
     * @param list<array<string, mixed>> $declarations
     * @param list<string>               $warnings
     */
    public function __construct(
        private readonly string $php,
        private readonly string $label,
        private readonly array $declarations,
        private readonly array $warnings = [],
    ) {
    }

    public function php(): string
    {
        return $this->php;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function isEmpty(): bool
    {
        return $this->declarations === [];
    }

    public function save(string $path): string
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new DelegiriumError('CompiledUnit: не удалось создать каталог ' . $directory);
        }

        if (@file_put_contents($path, $this->php) === false) {
            throw new DelegiriumError('CompiledUnit: не удалось записать ' . $path);
        }

        return $path;
    }

    /**
     * Загружает сгенерированный код в текущий процесс (через временный файл,
     * а не eval — так сохраняются имена классов, стек и opcache).
     */
    public function load(): void
    {
        if ($this->path === null) {
            $this->path = sys_get_temp_dir() . '/delegirium-' . substr(hash('sha256', $this->php), 0, 16) . '.php';

            if (!is_file($this->path)) {
                $this->save($this->path);
            }
        }

        require_once $this->path;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function instance(string $class, array $arguments = []): object
    {
        $this->load();

        if (!class_exists($class)) {
            throw new DelegiriumError(sprintf('CompiledUnit: класс %s не найден в %s', $class, $this->label));
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new DelegiriumError(sprintf('CompiledUnit: класс %s нельзя инстанцировать', $class));
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param list<mixed> $arguments
     */
    public function invoke(string $function, array $arguments = []): mixed
    {
        $this->load();

        if (!function_exists($function)) {
            throw new DelegiriumError(sprintf('CompiledUnit: функция %s не найдена в %s', $function, $this->label));
        }

        /** @var mixed $result */
        $result = $function(...$arguments);

        return $result;
    }
}
