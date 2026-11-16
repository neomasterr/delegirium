<?php

declare(strict_types=1);

namespace Delegirium\Provider;

use Delegirium\DelegiriumError;
use Delegirium\GenerationRequest;

/**
 * Оффлайн-провайдер: отдаёт заранее записанные ответы модели.
 *
 * Это не хак для демо, а реальная часть языка: «записанная» генерация даёт
 * воспроизводимые сборки и позволяет держать примеры в репозитории без ключа
 * API и без сети. Живой провайдер пишет сюда новые ответы (`--record`).
 */
final class RecordedProvider implements ProviderInterface
{
    /** @var array<string, string> */
    private array $bodies;

    private string $model;

    /**
     * @param array<string, string>|null $bodies карта «FQN -> тело»; null — читать из файла
     */
    public function __construct(
        ?array $bodies = null,
        private readonly ?string $fixtureFile = null,
        string $model = 'recorded/v1',
    ) {
        $this->bodies = $bodies ?? self::load($fixtureFile);
        $this->model = $model;
    }

    public function name(): string
    {
        return 'recorded';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(GenerationRequest $request): string
    {
        foreach ($this->candidateKeys($request) as $key) {
            if (isset($this->bodies[$key])) {
                return $this->bodies[$key];
            }
        }

        throw new DelegiriumError(sprintf(
            'RecordedProvider: нет записанного тела для %s. '
            . 'Запустите компиляцию с --provider=openai (или openrouter) --record или добавьте ответ в %s.',
            $request->fqName,
            $this->fixtureFile ?? 'fixtures/recorded.php',
        ));
    }

    /**
     * Ищем сначала по полному имени, затем по «Класс::метод» и по короткому имени.
     *
     * @return list<string>
     */
    private function candidateKeys(GenerationRequest $request): array
    {
        $keys = [$request->fqName];

        if ($request->className !== null) {
            $keys[] = $request->className . '::' . $request->name;
        }

        $keys[] = $request->name;

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, string>
     */
    private static function load(?string $file): array
    {
        if ($file === null || !is_file($file)) {
            return [];
        }

        /** @var mixed $data */
        $data = require $file;

        if (!is_array($data)) {
            throw new DelegiriumError('RecordedProvider: файл записей должен возвращать массив.');
        }

        return array_map(static fn ($body): string => (string) $body, $data);
    }
}
