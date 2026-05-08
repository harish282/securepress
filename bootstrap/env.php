<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$envFile = SECUREPRESS_PATH . '/.env';
if (!is_readable($envFile)) {
    return;
}

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    return;
}

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
    }

    $separatorPos = strpos($trimmed, '=');
    if ($separatorPos === false) {
        continue;
    }

    $name = trim(substr($trimmed, 0, $separatorPos));
    $value = trim(substr($trimmed, $separatorPos + 1));
    $value = trim($value, "\"'");

    if ($name === '') {
        continue;
    }

    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv($name . '=' . $value);
}
