<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Sessions;

use NiyiGuard\Core\Logging\LoggerInterface;

/**
 * High-level orchestration for session tracking and revocation.
 *
 * The repository is the dumb data store; this class handles the policy questions:
 *  - assigning a fresh token on `track()` and computing the device fingerprint;
 *  - calling the matching {@see SessionDestroyerInterface} to also evict the session
 *    from WordPress's own session_tokens user_meta when {@see revoke()} runs;
 *  - returning sane defaults from `current()` when the request has no associated record.
 *
 * Session tokens use 32 bytes of randomness (hex-encoded) — overkill for this purpose
 * but cheap, and it means tokens can safely be exposed in admin URLs without leaking
 * meaningful structure.
 */
final class SessionService
{
    public function __construct(
        private readonly SessionRepositoryInterface $repository,
        private readonly SessionFingerprinter $fingerprinter,
        private readonly LoggerInterface $logger,
        private readonly ?SessionDestroyerInterface $destroyer = null,
    ) {
    }

    /**
     * Records a new session for the user. Returns the persisted record.
     */
    public function track(int $userId, ?string $ip, ?string $userAgent, ?string $label = null, ?int $now = null): SessionRecord
    {
        $now ??= time();
        $fingerprint = $this->fingerprinter->fingerprint($ip, $userAgent);
        $token = bin2hex(random_bytes(32));

        $record = new SessionRecord(
            null,
            $userId,
            $token,
            $fingerprint->hash,
            $ip,
            $userAgent,
            $now,
            $now,
            null,
            $label,
        );

        return $this->repository->create($record);
    }

    /**
     * @return list<SessionRecord>
     */
    public function listActive(int $userId): array
    {
        return $this->repository->findActiveForUser($userId);
    }

    public function revoke(int $userId, int $sessionId, ?int $now = null): bool
    {
        $session = $this->repository->findById($sessionId);
        if ($session === null || $session->userId !== $userId) {
            return false;
        }
        if (!$session->isActive()) {
            return true;
        }

        $now ??= time();
        $this->repository->revoke($sessionId, $now);

        if ($this->destroyer !== null) {
            try {
                $this->destroyer->destroyForToken($userId, $session->token);
            } catch (\Throwable $exception) {
                $this->logger->warning(sprintf(
                    'SessionService: destroyer threw for session #%d (user %d): %s',
                    $sessionId,
                    $userId,
                    $exception->getMessage()
                ));
            }
        }

        return true;
    }

    public function revokeAllExceptCurrent(int $userId, ?int $excludeSessionId, ?int $now = null): int
    {
        $now ??= time();
        $count = 0;
        foreach ($this->repository->findActiveForUser($userId) as $session) {
            if ($session->id === $excludeSessionId) {
                continue;
            }
            if ($this->revoke($userId, (int) $session->id, $now)) {
                $count++;
            }
        }

        return $count;
    }

    public function repository(): SessionRepositoryInterface
    {
        return $this->repository;
    }
}
