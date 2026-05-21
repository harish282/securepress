<?php

declare(strict_types=1);

namespace PressSentinel\Core\Logging;

interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}
