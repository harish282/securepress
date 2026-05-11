<?php

declare(strict_types=1);

namespace SecurePress\Sdk\Csrf;

use SecurePress\Core\Support\WpHelper;
use SecurePress\Middleware\CsrfProtectionMiddleware;

/**
 * Developer-facing wrapper around WordPress's nonce API.
 *
 * Why not call {@see wp_create_nonce()} directly from user code?
 *  - **Single naming convention.** Every nonce minted through this manager defaults to
 *    {@see CsrfProtectionMiddleware::DEFAULT_ACTION}, so tokens produced by application
 *    code automatically pass the bundled middleware.
 *  - **Per-action HTML helper.** {@see field()} produces a hidden `<input>` ready to drop
 *    into custom forms, with proper escaping baked in.
 *  - **Test seam.** Routes through {@see WpHelper} so the test suite can deterministically
 *    stub `wp_verify_nonce` / `wp_create_nonce` via {@see \SecurePress\Tests\Stubs\WpStubState}.
 *
 * Tokens are NOT one-use — that's a WordPress nonce property (12-/24-hour lifecycle ticks).
 * For single-use semantics use {@see \SecurePress\Facades\Security::signedUrl(...,
 * oneTime: true)} instead.
 */
final class CsrfTokenManager
{
    public function mint(string $action = CsrfProtectionMiddleware::DEFAULT_ACTION): string
    {
        return WpHelper::createNonce($action);
    }

    public function verify(string $token, string $action = CsrfProtectionMiddleware::DEFAULT_ACTION): bool
    {
        if ($token === '') {
            return false;
        }

        return WpHelper::verifyNonce($token, $action) > 0;
    }

    /**
     * Returns the lifecycle tick (1 = fresh, 2 = within grace window, 0 = invalid).
     *
     * Useful when callers need to *log* stale-but-accepted tokens or refresh forms when
     * the tick comes back as 2 — the boolean {@see verify()} loses that nuance.
     */
    public function tick(string $token, string $action = CsrfProtectionMiddleware::DEFAULT_ACTION): int
    {
        return WpHelper::verifyNonce($token, $action);
    }

    /**
     * Renders a hidden `<input type="hidden" name="_wpnonce" value="…">` ready to drop
     * into a form. The `name` matches what {@see CsrfProtectionMiddleware} looks for in
     * POST bodies, so it works out of the box with the bundled middleware.
     */
    public function field(string $action = CsrfProtectionMiddleware::DEFAULT_ACTION, string $name = '_wpnonce'): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s" />',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeAttribute($this->mint($action))
        );
    }
}
