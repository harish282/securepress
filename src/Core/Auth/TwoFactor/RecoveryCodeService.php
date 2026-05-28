<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\TwoFactor;

/**
 * Generates, hashes, and verifies single-use recovery codes.
 *
 * Recovery codes are the user's escape hatch when the primary 2FA factor is unavailable
 * (lost phone, broken authenticator, no email access). Design constraints:
 *
 *  - **Plain text shown once.** Codes are returned to the caller exactly once at
 *    generation time; only their hashes are persisted. Re-issuing requires regenerating
 *    the full set, which voids the old codes — that's the standard UX (GitHub, Google,
 *    Stripe all do the same).
 *  - **One-time use.** {@see consume()} both verifies and removes the matching hash from
 *    the persisted set. The caller persists the returned array.
 *  - **Hash format.** SHA-256 is used because these codes have ~50 bits of entropy already
 *    and we don't need bcrypt's slow-hash properties. SHA-256 is fast, deterministic, and
 *    plenty for "did the user type one of these codes" lookups.
 *
 * Default count of 8 codes balances "enough to print on a card and store in a wallet"
 * with "small enough to make brute force after partial DB compromise infeasible".
 */
final class RecoveryCodeService
{
    public const DEFAULT_COUNT = 8;

    public function __construct(private readonly int $count = self::DEFAULT_COUNT)
    {
    }

    /**
     * Generates a fresh batch.
     *
     * Returned: ['plain' => list<string>, 'hashes' => list<string>] — show `plain` to the
     * user once, persist `hashes`.
     *
     * @return array{plain: list<string>, hashes: list<string>}
     */
    public function generate(): array
    {
        $plain = [];
        $hashes = [];

        for ($i = 0; $i < $this->count; $i++) {
            $code = $this->randomCode();
            $plain[] = $code;
            $hashes[] = $this->hash($this->normalize($code));
        }

        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Verifies a user-supplied code and, on match, returns the new hash list with the
     * consumed entry removed. The caller is responsible for persisting that new list.
     *
     * Returns `null` when the code doesn't match any stored hash.
     *
     * @param list<string> $hashes
     * @return list<string>|null
     */
    public function consume(string $submitted, array $hashes): ?array
    {
        $needle = $this->hash($this->normalize($submitted));
        $remaining = [];
        $matched = false;

        foreach ($hashes as $hash) {
            if (!$matched && hash_equals($hash, $needle)) {
                $matched = true;
                continue;
            }
            $remaining[] = $hash;
        }

        return $matched ? $remaining : null;
    }

    private function randomCode(): string
    {
        // 10 hex chars (40 bits), grouped as XXXXX-XXXXX for readability.
        $bytes = random_bytes(5);
        $hex = strtolower(bin2hex($bytes));

        return substr($hex, 0, 5) . '-' . substr($hex, 5);
    }

    private function normalize(string $code): string
    {
        return strtolower(preg_replace('/[\s\-]+/', '', $code) ?? '');
    }

    private function hash(string $normalizedCode): string
    {
        return hash('sha256', $normalizedCode);
    }
}
