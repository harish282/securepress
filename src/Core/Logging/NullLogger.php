<?php

declare(strict_types=1);

namespace SecurePress\Core\Logging;

final class NullLogger implements LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void
    {
        unset($level, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        unset($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        unset($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        unset($message, $context);
    }
}
