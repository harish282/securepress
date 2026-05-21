<?php

declare(strict_types=1);

namespace PressSentinel\Core\Logging;

/**
 * Append-only JSON-line logger that writes to a single file on disk.
 *
 * Design contracts:
 *
 *  - **Never let logging emit PHP warnings.** Production sites running with
 *    `display_errors=on` (legitimately or not) cannot tolerate a missing log
 *    directory turning into a public stack trace. All filesystem calls are
 *    error-silenced and their return values are inspected.
 *
 *  - **Self-heal on first use.** When the log directory or file doesn't exist
 *    we attempt to create them — `wp_mkdir_p()` when WordPress is loaded,
 *    native `mkdir()` otherwise (CLI, early activation, unit tests). The work
 *    is done lazily on the first `log()` call and cached for the rest of the
 *    request via {@see $ready}, so subsequent log lines pay only the
 *    `is_writable()` check.
 *
 *  - **Fail soft, surface clearly.** When we cannot write (no permission,
 *    read-only filesystem, parent directory blocked), the failure is recorded
 *    in {@see $lastError} and the logger silently becomes a no-op for the rest
 *    of the request. The plugin boot reads `lastError()` and renders a
 *    dismissible admin notice — operators are told exactly what to fix
 *    without a single warning ever reaching the page.
 *
 *  - **Defense in depth on first creation.** When we successfully create the
 *    log directory we drop a `.htaccess` (Apache deny) and an empty
 *    `index.html` next to the log file. Even installs that misconfigure the
 *    plugin under a publicly-readable path won't leak log lines.
 *
 * The class is intentionally final + dependency-free so it can be the *very*
 * first thing instantiated at boot, before the DI container has resolved
 * anything else.
 */
final class FileLogger implements LoggerInterface
{
    /**
     * @var bool|null Tri-state cache:
     *   - null  → not yet probed this request
     *   - true  → directory + file confirmed writable
     *   - false → bootstrap failed; subsequent log() calls become no-ops
     */
    private ?bool $ready = null;

    private ?string $lastError = null;

    public function __construct(private readonly string $logFilePath)
    {
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->ensureWritable()) {
            return;
        }

        $payload = [
            'timestamp' => gmdate('c'),
            'level' => strtolower($level),
            'message' => $message,
            'context' => $context,
        ];

        $line = $this->encode($payload);
        if ($line === null) {
            return;
        }

        // FILE_APPEND + LOCK_EX gives us atomic, append-only semantics that are
        // safe across concurrent PHP workers without us needing to manage a
        // lock file ourselves. The @ suppresses the rare race where the file
        // is removed (or the volume becomes read-only) between ensureWritable
        // and the write — recorded as lastError and we degrade gracefully.
        $bytes = @file_put_contents($this->logFilePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            $this->lastError = sprintf(
                'PressSentinel could not write to the log file at %s. Verify that the directory is writable by the web server (typically www-data or apache).',
                $this->logFilePath
            );
            $this->ready = false;
        }
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

    /**
     * The most recent bootstrap or write error, if any. Cleared automatically
     * once the logger recovers (e.g., the directory becomes writable on the
     * next request).
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function logFilePath(): string
    {
        return $this->logFilePath;
    }

    /**
     * Forces a re-check on the next call. Tests use this to flip writability
     * mid-run; production code never needs it.
     */
    public function reset(): void
    {
        $this->ready = null;
        $this->lastError = null;
    }

    /**
     * Lazy bootstrap. Creates the directory + file the first time the logger
     * is used, then short-circuits via {@see $ready} for the rest of the
     * request. Idempotent — safe to call repeatedly.
     */
    private function ensureWritable(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        $directory = dirname($this->logFilePath);

        // Step 1: directory.
        if (!is_dir($directory)) {
            $this->makeDirectory($directory);
        }
        if (!is_dir($directory)) {
            $this->lastError = sprintf(
                'PressSentinel could not create the log directory at %s. Please create it manually and chmod it so the web server can write to it (e.g. `chmod 755`).',
                $directory
            );

            return $this->ready = false;
        }

        // Step 2: file. `touch` is the cheapest way to create+stat an empty
        // file. The leading `@` is important because the directory could be
        // read-only — we want to fall through to the writability check below
        // and emit a clean lastError, not a PHP warning.
        if (!file_exists($this->logFilePath)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Log file bootstrap before WP_Filesystem is available.
            if (@touch($this->logFilePath) === false) {
                $this->lastError = sprintf(
                    'PressSentinel could not create the log file at %s. The directory exists but is not writable by the web server.',
                    $this->logFilePath
                );

                return $this->ready = false;
            }
            // Lock down permissions on the freshly-created file. Best-effort —
            // some hosts run PHP via FastCGI as a different user; the chmod
            // might be refused. We don't care: the file already exists at that
            // point and the worst case is "logs are 0664".
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort log file permissions after creation.
            @chmod($this->logFilePath, 0644);
            $this->dropProtectionFiles($directory);
        }

        // Step 3: writability. A file that exists but isn't writable means
        // either bad ownership or read-only mount — same operator action
        // regardless, so we render one consolidated message.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Log path writability probe for operator guidance.
        if (!is_writable($this->logFilePath)) {
            $this->lastError = sprintf(
                'PressSentinel log file at %s is not writable. Run `chmod 644 %s` (and `chown` to the web server user if needed).',
                $this->logFilePath,
                $this->logFilePath
            );

            return $this->ready = false;
        }

        return $this->ready = true;
    }

    /**
     * Creates the log directory. Prefers `wp_mkdir_p` because it knows about
     * the WordPress site permissions (`FS_CHMOD_DIR` etc.); falls back to
     * native `mkdir` for environments where WP isn't loaded (unit tests, CLI
     * bootstrap, very early activation paths).
     *
     * The `@` operator alone isn't enough on PHP 8+ once a strict error
     * handler is installed (PHPUnit, monolog-bridged setups), so we install a
     * tiny no-op handler around the mkdir call. The handler is restored in a
     * `finally` block so we never leak it back into the caller's stack.
     */
    private function makeDirectory(string $directory): void
    {
        $silent = static fn (): bool => true;
        set_error_handler($silent);
        try {
            if (\function_exists('wp_mkdir_p')) {
                \call_user_func('wp_mkdir_p', $directory);

                return;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback when wp_mkdir_p is unavailable (tests/CLI).
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                // Caller checks `is_dir()` after this returns; we don't need
                // to surface anything further — the failure path collapses
                // into a clean `lastError` message at that layer.
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Drops `.htaccess` and `index.html` into the log directory. Idempotent —
     * never overwrites existing files. Failures are silent: if we can't write
     * these, the main log write would have failed too and would have been
     * surfaced elsewhere.
     */
    private function dropProtectionFiles(string $directory): void
    {
        $htaccess = rtrim($directory, '/') . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# PressSentinel log directory — not web-accessible.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }

        $index = rtrim($directory, '/') . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): ?string
    {
        if (\function_exists('wp_json_encode')) {
            $encoded = \call_user_func('wp_json_encode', $payload);

            return is_string($encoded) ? $encoded : null;
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : null;
    }
}
