<?php

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Harness.php';

$suites = array_slice($argv, 1) ?: ['unit', 'e2e'];
foreach ($suites as $suite) {
    $file = __DIR__ . '/' . $suite . '.php';
    if (!is_file($file)) {
        fwrite(STDERR, "Unknown suite: $suite\n");
        exit(2);
    }
    Harness::suite($suite);
    require $file;
}
exit(Harness::run());
