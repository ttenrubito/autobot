<?php
$tests = [
    'admin',
    'Admin',
    'ADMIN',
    'admin มาตอบ',
    'Admin มาตอบ',
    '/admin',
    '/admin test',
    '#admin',
    '#admin here',
    'ราคาเท่าไร',
    'test admin',
];

echo "Testing admin command detection:\n\n";
foreach ($tests as $text) {
    $t = mb_strtolower(trim($text), 'UTF-8');
    $matched = preg_match('/^(?:\/admin|#admin|admin)(?:\s|$)/u', $t);
    echo ($matched ? '✅' : '❌') . ' "' . $text . '"' . "\n";
}
