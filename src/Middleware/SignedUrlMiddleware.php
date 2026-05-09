<?php

declare(strict_types=1);

namespace SecurePress\Middleware;

use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\Url\NonceStoreInterface;
use SecurePress\Core\Url\SignedUrlResult;
use SecurePress\Core\Url\UrlSigner;

/**
 * Verifies that the current request URL carries a valid signature minted by {@see UrlSigner}.
 *
 * The middleware reads the URL from `$context['request']['url']` if present, otherwise from
 * `$_SERVER['REQUEST_URI']`. On failure the pipeline is short-circuited with a status that
 * matches the failure mode:
 *
 *  - `expired`         -> 410 Gone     (URL was valid; user should request a fresh link)
 *  - `tampered`        -> 403 Forbidden
 *  - `invalid-signature` / `missing-signature` / `malformed` -> 403 Forbidden
 *
 * ## One-time-use semantics
 *
 * URLs minted with {@see Security::signedUrl(..., oneTime: true)} carry an `n` (nonce) query
 * parameter. When the middleware is constructed with a {@see NonceStoreInterface}, it consumes
 * the nonce on successful verification — any subsequent request with the same nonce, even with
 * a still-valid signature, is rejected as `already-used` (403).
 *
 * URLs without an `n` parameter are treated as standard (replayable) signed URLs even when a
 * nonce store is configured, so a single middleware instance can serve both flows.
 *
 * If the middleware is constructed without a nonce store, all URLs are treated as standard.
 * One-time URLs become effectively replayable in that mode — register the store at the
 * boundary (DI binding or per-route wiring) for any flow that demands single-use guarantees.
 *
 * Use OTU mode for password resets, magic-link logins, email confirmations, and admin invites.
 */
final class SignedUrlMiddleware implements MiddlewareInterface
{
    public const NONCE_PARAM = 'n';

    public function __construct(
        private readonly UrlSigner $signer,
        private readonly ?NonceStoreInterface $nonceStore = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(array $context, callable $next): array
    {
        $url = $this->resolveUrl($context);
        if ($url === null) {
            return $this->reject($context, 'malformed');
        }

        $result = $this->signer->verify($url);

        if (!$result->valid) {
            return $this->reject($context, $result->reason, $result);
        }

        $nonce = $result->params[self::NONCE_PARAM] ?? null;
        $oneTime = is_string($nonce) && $nonce !== '';
        $consumed = false;

        if ($oneTime && $this->nonceStore !== null) {
            if (!$this->nonceStore->consume($nonce)) {
                return $this->reject($context, 'already-used', $result);
            }
            $consumed = true;
        }

        $context['signed_url'] = [
            'verified' => true,
            'reason' => 'valid',
            'path' => $result->path,
            'params' => $result->params,
            'expires_at' => $result->expiresAt,
            'one_time' => $oneTime,
            'consumed' => $consumed,
        ];

        return $next($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveUrl(array $context): ?string
    {
        $request = $context['request'] ?? null;
        if (is_array($request) && isset($request['url']) && is_string($request['url']) && $request['url'] !== '') {
            return $request['url'];
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? null;
        if (is_string($requestUri) && $requestUri !== '') {
            return $requestUri;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function reject(array $context, string $reason, ?SignedUrlResult $result = null): array
    {
        $logger = $this->logger ?? new NullLogger();
        $logger->warning('Signed URL verification failed.', [
            'reason' => $reason,
            'path' => $result?->path,
        ]);

        $context['signed_url'] = [
            'verified' => false,
            'reason' => $reason,
            'path' => $result?->path ?? '',
            'params' => $result?->params ?? [],
            'expires_at' => $result?->expiresAt,
        ];

        $context['response'] = [
            'status' => $reason === 'expired' ? 410 : 403,
            'message' => $this->messageFor($reason),
        ];
        $context['halted'] = true;

        return $context;
    }

    private function messageFor(string $reason): string
    {
        return match ($reason) {
            'expired' => 'This link has expired. Request a fresh one.',
            'already-used' => 'This link has already been used.',
            'missing-nonce' => 'Single-use signed URL is missing its nonce.',
            'missing-signature' => 'Signed URL is missing its signature.',
            'malformed' => 'Signed URL is malformed.',
            default => 'Invalid signed URL.',
        };
    }
}
