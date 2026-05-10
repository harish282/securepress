<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\TwoFactor;

/**
 * RFC 6238 TOTP generator / verifier.
 *
 * Uses the standard 30-second time step, 6-digit codes, and HMAC-SHA1 — that combination
 * is what every mainstream authenticator app (Google Authenticator, Authy, 1Password,
 * Microsoft Authenticator, …) supports natively. Don't change these defaults without a
 * separate migration plan; existing enrolled secrets would stop validating.
 *
 * The verifier accepts a configurable +/- N-step skew window (default 1 step = ±30s) to
 * tolerate small clock drift between the server and the user's phone. Larger windows
 * weaken the brute-force margin proportionally — keep this small.
 */
final class TotpProvider
{
    public const DEFAULT_DIGITS = 6;
    public const DEFAULT_PERIOD = 30;
    public const DEFAULT_ALGORITHM = 'sha1';

    public function __construct(
        private readonly int $digits = self::DEFAULT_DIGITS,
        private readonly int $period = self::DEFAULT_PERIOD,
        private readonly string $algorithm = self::DEFAULT_ALGORITHM,
    ) {
    }

    public function generateSecret(): string
    {
        return Base32::randomSecret();
    }

    /**
     * Returns the current TOTP code for the given base32 secret.
     */
    public function code(string $base32Secret, ?int $time = null): string
    {
        return $this->codeAtCounter($base32Secret, $this->counterFor($time ?? time()));
    }

    /**
     * Verifies a user-supplied code against the secret, allowing +/- `$skew` time steps.
     *
     * `hash_equals` is used for the comparison so timing leaks don't help an attacker
     * narrow their guess.
     */
    public function verify(string $base32Secret, string $code, int $skew = 1, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (strlen($code) !== $this->digits || !ctype_digit($code)) {
            return false;
        }

        $now = $time ?? time();
        $center = $this->counterFor($now);

        for ($delta = -$skew; $delta <= $skew; $delta++) {
            $candidate = $this->codeAtCounter($base32Secret, $center + $delta);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds an `otpauth://` provisioning URI per the Google Authenticator key-uri spec.
     *
     * `$issuer` and `$account` are URL-encoded; the secret is included as the canonical
     * (unpadded, upper-case) base32 representation expected by every authenticator app.
     */
    public function provisioningUri(string $issuer, string $account, string $base32Secret): string
    {
        $issuerEncoded = rawurlencode($issuer);
        $accountEncoded = rawurlencode($account);
        $label = $issuerEncoded . ':' . $accountEncoded;

        $params = http_build_query([
            'secret' => preg_replace('/=+$/', '', strtoupper($base32Secret)) ?? $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);

        return sprintf('otpauth://totp/%s?%s', $label, $params);
    }

    private function counterFor(int $time): int
    {
        return intdiv($time, max(1, $this->period));
    }

    private function codeAtCounter(string $base32Secret, int $counter): string
    {
        $secret = Base32::decode($base32Secret);
        if ($secret === '') {
            return str_repeat('0', $this->digits);
        }

        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac($this->algorithm, $binCounter, $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $modulo = 10 ** $this->digits;

        return str_pad((string) ($value % $modulo), $this->digits, '0', STR_PAD_LEFT);
    }
}
