<?php

declare(strict_types=1);

$requiredFiles = [
    __DIR__ . '/../db.php',
    __DIR__ . '/../core.php',
    __DIR__ . '/../admin_api.php',
    __DIR__ . '/../staff_api.php',
    __DIR__ . '/../customer_api.php',
    __DIR__ . '/../payments_api.php'
];

$failed = [];
foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $failed[] = 'Missing file: ' . $file;
    }
}

if ($failed !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failed) . PHP_EOL);
    exit(1);
}

echo "Smoke test passed: required files exist." . PHP_EOL;
