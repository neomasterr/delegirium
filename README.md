# Delegirium

Делегириум — PHP, в котором тело функции не пишут руками: вместо `{ ... }` стоит
маркер `ai;`, а операторы генерирует модель по контракту из сигнатуры и докблока.

```php
public function add($x, $y): int ai;
```

Компилятор `delegirium` превращает такой файл в обычный PHP:

```php
public function add($x, $y): int
{
    return $x + $y;
}
```

Всё остальное — namespace, классы, отступы, обычные функции — остаётся нетронутым.
На выходе валидный PHP, который можно запускать, линтовать, кешировать и деплоить
как обычно.

## Почему это отдельный язык, а не «PHP + LLM в рантайме»

Потому что генерация происходит **на этапе сборки**, а не в каждом запросе:

- **Кеш по отпечатку промпта.** Тело привязано к хешу сигнатуры + докблока + модели.
  Изменил контракт — тело перегенерируется; не изменил — берётся из кеша.
- **Валидация до исполнения.** Синтаксис, запрещённые вызовы, чистота `@pure`,
  наличие `return`. При провале модель получает текст ошибки и переписывает тело.
- **Воспроизводимость.** `--record` сохраняет ответы в `fixtures/`, и сборка
  перестаёт зависеть от сети и от дрейфа модели.

## Быстрый старт

Нужен только PHP >= 8.1, без composer.

```bash
php tests/run.php                   # тесты
php bin/delegirium demo             # оффлайн-демо на записанных ответах
php bin/delegirium compile examples/math.dlr --out=build/Math.php
php bin/delegirium compile examples/math.dlr --dry   # показать промпты
php bin/delegirium env              # какие .env и переменные видит компилятор
```

### Настройки через `.env`

`export` не обязателен: `bin/delegirium` сам читает `.env` в корне проекта.
Шаблон — [.env.example](.env.example):

```bash
cp .env.example .env      # и вписать ключ
php bin/delegirium env    # проверить, что подхватилось (секреты маскируются)
php bin/delegirium compile examples/math.dlr --regen
```

Приоритет: переменные, уже заданные в shell/CI, важнее `.env`. Другой файл
можно указать через `DELEGIRIUM_ENV_FILE` или явно из кода:
`Delegirium::loadEnvFile('.env.local')`.

С живой моделью (если не хотите `.env`):

```bash
export DELEGIRIUM_API_KEY=sk-...
export DELEGIRIUM_MODEL=gpt-4.1-mini
php bin/delegirium compile examples/math.dlr --regen --out=build/Math.php
php bin/delegirium compile examples/math.dlr --record   # записать ответы в fixtures
```

Любой OpenAI-совместимый endpoint подключается через `DELEGIRIUM_API_URL`
(локальный ollama/vLLM тоже подойдёт).

### OpenRouter

Поддерживается отдельный провайдер `openrouter` — протокол тот же, но модели
задаются как `vendor/model`, а по умолчанию используется русский прокси
`https://api.ru-openrouter.ru/v1`:

```bash
# либо экспорт, либо эти же строки в .env
export DELEGIRIUM_PROVIDER=openrouter
export DELEGIRIUM_OPENROUTER_KEY=sk-or-...
export DELEGIRIUM_OPENROUTER_URL=https://api.ru-openrouter.ru/v1
export DELEGIRIUM_OPENROUTER_MODEL=openai/gpt-4o-mini

php bin/delegirium compile examples/math.dlr --regen
```

На один запуск провайдера можно выбрать флагом: `--provider=openrouter`.
Если `DELEGIRIUM_PROVIDER` не задан, режим `auto` включает OpenRouter
при наличии `DELEGIRIUM_OPENROUTER_KEY` и отсутствии `DELEGIRIUM_API_KEY`.
Необязательный `DELEGIRIUM_OPENROUTER_REFERER` уходит в заголовок `HTTP-Referer`.

## Пример

`examples/math.dlr`:

```php
namespace Delegirium\Examples;

final class Math
{
    /**
     * Складывает два целых числа.
     *
     * @param int $x
     * @param int $y
     * @return int
     * @pure
     */
    public function add($x, $y): int ai;
}
```

`examples/demo.php`:

```php
$math = Delegirium::compileFile(__DIR__ . '/math.dlr');
$calculator = $math->instance('Delegirium\Examples\Math');

echo $calculator->add(2, 3); // 5
```

## Устройство

| Компонент | Роль |
|---|---|
| `src/Parser.php` | находит объявления с маркером `ai;` поверх лексера PHP |
| `src/Compiler.php` | генерирует тела, кеширует, вставляет, линтует файл целиком |
| `src/Validator.php` | проверяет тело до исполнения, формулирует обратную связь |
| `src/DotEnv.php` | загрузка `.env` без зависимостей (shell важнее файла) |
| `src/Provider/*` | `openai` и `openrouter` (живая модель), `recorded` (оффлайн-сборка) |
| `src/Cache.php` | кеш тел по отпечатку промпта |
| `src/CompiledUnit.php` | сохранение, загрузка и вызов скомпилированного кода |

## Ограничения наброска

- Нет своей грамматики: Delegirium — это PHP с одним дополнительным маркером.
- Нет песочницы исполнения: запреты валидатора статические, а не системные.
- Нет теорем: контракт держится на типах, `@pure` и ревью, а не на доказательствах.
- Один маркер на функцию; ленивая (рантайм) генерация описана в `SPEC.md`, но не реализована.

Подробности синтаксиса и семантики — в [SPEC.md](SPEC.md).
