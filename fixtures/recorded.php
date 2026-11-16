<?php

// Автоматически записанные ответы модели. Не редактируйте вручную.

return array (
  'Delegirium\\Examples\\Math::add' => 'return $x + $y;',
  'Delegirium\\Examples\\Math::divide' => 'if ($b === 0.0) {
    throw new \\DivisionByZeroError(\'Cannot divide by zero\');
}

return $a / $b;',
  'Delegirium\\Examples\\Math::fib' => 'if ($n < 0) {
    throw new \\InvalidArgumentException(\'$n must be non-negative, got \' . $n);
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

return $current;',
  'Delegirium\\Examples\\Text\\add_emoji_after_every_word' => 'if ($s === \'\') {
    return \'\';
}

$result = preg_replace(\'/[\\p{L}\\p{N}\\p{M}_]+/u\', \'$0😀\', $s);

return $result ?? $s;',
  'Delegirium\\Examples\\Text\\reverse' => 'if ($s === \'\') {
    return \'\';
}

$chars = preg_split(\'//u\', $s, -1, PREG_SPLIT_NO_EMPTY);

if ($chars === false) {
    return strrev($s);
}

return implode(\'\', array_reverse($chars));',
  'Delegirium\\Examples\\Text\\rhytm' => '$words = preg_split(\'/[^\\p{L}\\p{N}]+/u\', mb_strtolower($s, \'UTF-8\'), -1, PREG_SPLIT_NO_EMPTY);
if ($words === false) {
    $words = [];
}

$count = 0;
$n = count($words);

for ($i = 0; $i < $n - 1; $i++) {
    $a = $words[$i];
    if (mb_strlen($a, \'UTF-8\') < 2) {
        continue;
    }
    $endingA = mb_substr($a, -2, null, \'UTF-8\');

    for ($j = $i + 1; $j < $n; $j++) {
        $b = $words[$j];
        if (mb_strlen($b, \'UTF-8\') < 2) {
            continue;
        }
        if (mb_substr($b, -2, null, \'UTF-8\') === $endingA) {
            $count++;
        }
    }
}

return $count;',
  'Delegirium\\Examples\\Text\\squanch' => '$parts = preg_split(\'/(\\s+)/u\', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
if ($parts === false) {
    return $s;
}

$result = \'\';
foreach ($parts as $index => $part) {
    if ($index % 2 === 1 || $part === \'\') {
        $result .= $part;
        continue;
    }

    $chars = preg_split(\'//u\', $part, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        $result .= $part;
        continue;
    }

    $result .= implode(\'\', array_reverse($chars));
}

return $result;',
  'Delegirium\\Examples\\Text\\wordCount' => '$count = preg_match_all(\'/[\\p{L}\\p{N}]+/u\', $s, $matches);
if ($count === false) {
    $count = preg_match_all(\'/[A-Za-z0-9]+/\', $s, $matches);
}
return (int) $count;',
);
