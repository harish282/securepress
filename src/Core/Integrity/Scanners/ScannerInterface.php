<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Scanners;

use PressSentinel\Core\Integrity\Finding;

/**
 * Common contract for every integrity scanner.
 *
 * Scanners are stateless — given a root path, they produce a list of {@see Finding}s
 * and nothing else. Persistence, scheduling, and notification are out-of-scope here
 * (the {@see \PressSentinel\Core\Integrity\IntegrityService} orchestrates those concerns).
 *
 * Implementations:
 *  - {@see SuspiciousPhpScanner} — runs heuristics over PHP files.
 *  - {@see CoreFilesScanner}     — diffs core files against WP.org checksums.
 *  - {@see PluginManifestScanner} — diffs the live plugin tree against the stored baseline.
 *
 * The interface is intentionally narrow so it's easy to add new scanners (Themes,
 * Custom directories, MU-plugins, …) without touching the orchestrator.
 */
interface ScannerInterface
{
    public function name(): string;

    /**
     * @return list<Finding>
     */
    public function scan(): array;
}
