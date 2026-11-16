<?php

declare(strict_types=1);

/**
 * Мини-демонстрация: компилируем .dlr и вызываем функции, у которых не было тел.
 *
 *   php examples/demo.php
 *
 * Без DELEGIRIUM_API_KEY тела берутся из fixtures/recorded.php (оффлайн).
 * С ключом — генерируются моделью и кешируются.
 */

require __DIR__ . '/../src/autoload.php';

use Delegirium\Delegirium;

$options = ['cache_dir' => __DIR__ . '/../.delegirium-cache'];

$math = Delegirium::compileFile(__DIR__ . '/math.dlr', $options);
/** @var object $calculator */
$calculator = $math->instance('Delegirium\\Examples\\Math');

echo "Math:\n";
printf("  add(2, 3)     = %d\n", $calculator->add(2, 3));
printf("  add(-10, 4)   = %d\n", $calculator->add(-10, 4));
printf("  fib(10)       = %d\n", $calculator->fib(10));
printf("  divide(7, 2)  = %s\n", (string) $calculator->divide(7.0, 2.0));

try {
    $calculator->fib(-1);
} catch (\InvalidArgumentException $error) {
    printf("  fib(-1)       -> %s: %s\n", $error::class, $error->getMessage());
}

$text = Delegirium::compileFile(__DIR__ . '/text.dlr', $options);

echo "\nText:\n";
printf("  reverse('Delegirium')          = %s\n", $text->invoke('Delegirium\\Examples\\Text\\reverse', ['Delegirium']));
printf("  reverse('привет')            = %s\n", $text->invoke('Delegirium\\Examples\\Text\\reverse', ['привет']));
printf("  slugify('Hello, Delegirium!')  = %s\n", $text->invoke('Delegirium\\Examples\\Text\\slugify', ['Hello, Delegirium!']));
printf("  wordCount('a b  c')          = %d\n", $text->invoke('Delegirium\\Examples\\Text\\wordCount', ['a b  c']));

echo "\n--- сгенерированный PHP для math.dlr ---\n\n";
echo $math->php();
