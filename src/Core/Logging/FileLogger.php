<?php

declare(strict_types=1);

namespace SecurePress\Core\Logging;

final class FileLogger implements LoggerInterface
{
    public function __construct(private readonly string $logFilePath)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $directory = dirname($this->logFilePath);
        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        $payload = [
            'timestamp' => gmdate('c'),
            'level' => strtolower($level),
            'message' => $message,
            'context' => $context,
        ];

        $line = wp_json_encode($payload);
        if (!is_string($line)) {
            return;
        }

        file_put_contents($this->logFilePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
}
