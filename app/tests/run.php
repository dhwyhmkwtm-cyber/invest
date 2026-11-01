<?php
declare(strict_types=1);

// 簡易テストランナー: php tests/run.php [フィルタ]
require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$tests = [];
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $defs = require $file;
    foreach ($defs as $name => $fn) {
        $tests[basename($file, '.php') . ' :: ' . $name] = $fn;
    }
}
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $fn();
        $pass++;
        echo "  ✓ {$name}\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  ✗ {$name}\n      " . get_class($e) . ': ' . $e->getMessage() . "\n      at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
