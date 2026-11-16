<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Файловый кеш сгенерированных тел.
 *
 * Кеш — не оптимизация, а часть семантики языка: тело привязано к отпечатку
 * промпта. Изменение контракта (сигнатуры, докблока, @pure) или модели даёт
 * новый ключ, и тело генерируется заново.
 */
final class Cache
{
    public function __construct(private readonly string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @return array{body: string, meta: array<string, mixed>}|null
     */
    public function get(string $key): ?array
    {
        $file = $this->file($key);

        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !is_string($data['body'] ?? null)) {
            return null;
        }

        return ['body' => $data['body'], 'meta' => is_array($data['meta'] ?? null) ? $data['meta'] : []];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function put(string $key, string $body, array $meta = []): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new DelegiriumError('Cache: не удалось создать каталог ' . $this->directory);
        }

        $payload = json_encode(
            ['body' => $body, 'meta' => $meta + ['created' => date('c')]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($payload === false || @file_put_contents($this->file($key), $payload . "\n") === false) {
            throw new DelegiriumError('Cache: не удалось записать ' . $this->file($key));
        }
    }

    public function clear(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;

        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array{entries: int, bytes: int}
     */
    public function stats(): array
    {
        $entries = 0;
        $bytes = 0;

        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $entries++;
            $bytes += (int) @filesize($file);
        }

        return ['entries' => $entries, 'bytes' => $bytes];
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . $key . '.json';
    }
}
