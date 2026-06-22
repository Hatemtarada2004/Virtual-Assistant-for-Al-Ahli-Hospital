<?php

declare(strict_types=1);

$candidates = [
    __DIR__ . '/env.php',
    __DIR__ . '/env.local.php',
    __DIR__ . '/env.example.php',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $config = require $candidate;
        if (is_array($config)) {
            return $config;
        }
    }
}

throw new RuntimeException('No valid environment config file found.');
