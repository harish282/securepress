<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Notifications;

/**
 * In-memory mailer for tests. Records every `send()` call as an associative array so
 * cases can assert on subject/body content without intercepting `wp_mail()` globally.
 */
final class ArrayMailer implements MailerInterface
{
    /** @var list<array{to: array<int, string>|string, subject: string, body: string, headers: array<string, string>}> */
    private array $sent = [];

    public bool $shouldFail = false;

    /**
     * @param array<int, string>|string $to
     * @param array<string, string> $headers
     */
    public function send(array|string $to, string $subject, string $body, array $headers = []): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->sent[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
        ];

        return true;
    }

    /**
     * @return list<array{to: array<int, string>|string, subject: string, body: string, headers: array<string, string>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
