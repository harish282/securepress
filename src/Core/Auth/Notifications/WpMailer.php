<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Notifications;

/**
 * `wp_mail`-backed implementation. Adds a `From: NiyiGuard <…>` header by default
 * so authentication emails are recognisable when they land in users' inboxes —
 * operators can override the From address through their site's mail SMTP plugin since
 * `wp_mail` ultimately respects the `wp_mail_from` and `wp_mail_from_name` filters.
 */
final class WpMailer implements MailerInterface
{
    /**
     * @param array<int, string>|string $to
     * @param array<string, string> $headers
     */
    public function send(array|string $to, string $subject, string $body, array $headers = []): bool
    {
        if (!\function_exists('wp_mail')) {
            return false;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        return (bool) \call_user_func('wp_mail', $to, $subject, $body, $headerLines);
    }
}
