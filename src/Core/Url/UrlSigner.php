<?php

declare(strict_types=1);

namespace PressSentinel\Core\Url;

use Closure;

/**
 * HMAC-SHA256 URL signer.
 *
 * Produces tamper-evident URLs that bind a path + query parameters + optional expiry to a
 * server-side secret. Verification is stateless: any process that has the same secret can
 * validate a URL without consulting persistent storage.
 *
 * ## Canonical form
 *
 * Signing and verification operate on a canonical string built deterministically from the URL:
 *
 *     <normalized-path>?<sorted-query>
 *
 * Where:
 *  - the path is lowercased, repeated slashes collapsed, and a trailing slash stripped
 *    (except for the root path "/");
 *  - the query string is built from URL-decoded keys/values, sorted by key, joined with `&`;
 *  - the `signature` parameter itself is excluded from canonicalization;
 *  - the `expires` parameter, if present, IS included in the canonical form so attackers
 *    cannot extend a URL's lifetime by changing the timestamp.
 *
 * The host and scheme are intentionally omitted so the same signed URL works across HTTPS/HTTP
 * mirrors, CDN domains, and reverse-proxy environments. Callers that need scheme enforcement
 * should layer a separate middleware on top.
 *
 * ## Output
 *
 * `sign()` returns a path + query string (no scheme/host); callers prefix with `home_url()`
 * or similar before sending. Signatures are 64 hex characters (256 bits) compared via
 * {@see hash_equals()} for constant-time safety.
 */
final class UrlSigner
{
    public const SIGNATURE_PARAM = 'signature';

    public const EXPIRES_PARAM = 'expires';

    private const HASH_ALGO = 'sha256';

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Injectable for deterministic tests.
     */
    public function __construct(
        private readonly SecretProviderInterface $secretProvider,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Returns a signed `path?...&signature=...` string.
     *
     * @param array<string, scalar|null> $params Query parameters; non-scalars are rejected.
     * @param int|null $expiresIn Seconds until expiry (TTL from now). `null` means no expiry.
     */
    public function sign(string $path, array $params = [], ?int $expiresIn = null): string
    {
        $normalizedPath = $this->normalizePath($path);
        $sanitizedParams = $this->sanitizeParams($params);

        if (isset($sanitizedParams[self::SIGNATURE_PARAM])) {
            throw new SignedUrlException('Cannot pre-set the "signature" parameter on a URL being signed.');
        }

        if ($expiresIn !== null) {
            if ($expiresIn < 1) {
                throw new SignedUrlException('expiresIn must be a positive number of seconds.');
            }
            $sanitizedParams[self::EXPIRES_PARAM] = (string) (($this->clock)() + $expiresIn);
        }

        $canonical = $this->canonicalize($normalizedPath, $sanitizedParams);
        $signature = hash_hmac(self::HASH_ALGO, $canonical, $this->secretProvider->secret());

        $sanitizedParams[self::SIGNATURE_PARAM] = $signature;

        return $this->buildUrl($normalizedPath, $sanitizedParams);
    }

    /**
     * Verifies the signature and (if present) expiry of a URL.
     *
     * Accepts either a full URL (`https://example.com/path?...`) or a relative path with
     * query string (`/path?...`); only the path and query are inspected.
     */
    public function verify(string $url): SignedUrlResult
    {
        $parsed = $this->parseUrl($url);
        if ($parsed === null) {
            return SignedUrlResult::failure('malformed');
        }

        [$path, $params] = $parsed;
        $signature = $params[self::SIGNATURE_PARAM] ?? null;

        if (!is_string($signature) || $signature === '') {
            return SignedUrlResult::failure('missing-signature', $path, $this->stripInternal($params));
        }

        if (!ctype_xdigit($signature) || strlen($signature) !== 64) {
            return SignedUrlResult::failure('invalid-signature', $path, $this->stripInternal($params));
        }

        $paramsForCheck = $params;
        unset($paramsForCheck[self::SIGNATURE_PARAM]);

        $canonical = $this->canonicalize($path, $paramsForCheck);
        $expected = hash_hmac(self::HASH_ALGO, $canonical, $this->secretProvider->secret());

        if (!hash_equals($expected, $signature)) {
            return SignedUrlResult::failure('tampered', $path, $paramsForCheck);
        }

        $expiresAt = null;
        if (isset($paramsForCheck[self::EXPIRES_PARAM])) {
            $raw = $paramsForCheck[self::EXPIRES_PARAM];
            if (!is_string($raw) || !ctype_digit($raw)) {
                return SignedUrlResult::failure('tampered', $path, $paramsForCheck);
            }
            $expiresAt = (int) $raw;
            if ($expiresAt <= ($this->clock)()) {
                return SignedUrlResult::failure('expired', $path, $paramsForCheck, $expiresAt);
            }
        }

        return SignedUrlResult::success($path, $paramsForCheck, $expiresAt);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = strtolower($path);

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, string>
     */
    private function sanitizeParams(array $params): array
    {
        $sanitized = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new SignedUrlException('Signed URL parameters must use non-empty string keys.');
            }
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new SignedUrlException(sprintf('Signed URL parameter "%s" must be scalar.', $key));
            }
            $sanitized[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $sanitized;
    }

    /**
     * @param array<string, string> $params
     */
    private function canonicalize(string $path, array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        $query = implode('&', $pairs);

        return $query === '' ? $path : $path . '?' . $query;
    }

    /**
     * @param array<string, string> $params
     */
    private function buildUrl(string $path, array $params): string
    {
        if ($params === []) {
            return $path;
        }

        $signature = $params[self::SIGNATURE_PARAM] ?? null;
        unset($params[self::SIGNATURE_PARAM]);
        ksort($params);
        if ($signature !== null) {
            $params[self::SIGNATURE_PARAM] = $signature;
        }

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return $path . '?' . implode('&', $pairs);
    }

    /**
     * @return array{0: string, 1: array<string, string>}|null
     */
    private function parseUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path = $this->normalizePath($parts['path'] ?? '/');
        $params = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $parsed);
            foreach ($parsed as $key => $value) {
                if (!is_string($key) || $key === '' || !is_string($value)) {
                    continue;
                }
                $params[$key] = $value;
            }
        }

        return [$path, $params];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, string>
     */
    private function stripInternal(array $params): array
    {
        unset($params[self::SIGNATURE_PARAM]);

        return $params;
    }
}
