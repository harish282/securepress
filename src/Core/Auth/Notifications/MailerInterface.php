<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\Notifications;

interface MailerInterface
{
    /**
     * @param array<int, string>|string $to
     * @param array<string, string> $headers
     */
    public function send(array|string $to, string $subject, string $body, array $headers = []): bool;
}
