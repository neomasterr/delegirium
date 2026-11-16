<?php

// Записанные ответы модели. Оффлайн-сборка читает их вместо сети.
// Файл перезаписывается командой: delegirium compile <file> --record

return [

    'Delegirium\\Examples\\Math::add' => <<<'PHP'
return $x + $y;
PHP,

    'Delegirium\\Examples\\Math::fib' => <<<'PHP'
if ($n < 0) {
    throw new \InvalidArgumentException('$n must be non-negative, got ' . $n);
}

if ($n < 2) {
    return $n;
}

$previous = 0;
$current = 1;

for ($i = 2; $i <= $n; $i++) {
    $next = $previous + $current;
    $previous = $current;
    $current = $next;
}

return $current;
PHP,

    'Delegirium\\Examples\\Math::divide' => <<<'PHP'
if ($b === 0.0) {
    throw new \DivisionByZeroError('Cannot divide by zero');
}

return $a / $b;
PHP,

    'Delegirium\\Examples\\Text\\reverse' => <<<'PHP'
if ($s === '') {
    return '';
}

$characters = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);

if ($characters === false) {
    return strrev($s);
}

return implode('', array_reverse($characters));
PHP,

    'Delegirium\\Examples\\Text\\slugify' => <<<'PHP'
$slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($s)));

return trim($slug ?? '', '-');
PHP,

    'Delegirium\\Examples\\Text\\wordCount' => <<<'PHP'
$trimmed = trim($s);

if ($trimmed === '') {
    return 0;
}

$words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);

return $words === false ? 0 : count($words);
PHP,

];
