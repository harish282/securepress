<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

/**
 * Fluent builder for {@see AuditEvent} that finalizes by recording through an
 * {@see AuditLoggerInterface}.
 *
 * Used by the `AuditLog` facade — the builder lets call sites read top-to-bottom even
 * for events that need both actor and target metadata:
 *
 *     AuditLog::for($user)
 *         ->category(AuditEventCategory::WOOCOMMERCE)
 *         ->action('order.refunded')
 *         ->target('order', (string) $orderId)
 *         ->message('Issued partial refund')
 *         ->context(['amount' => 12.50, 'reason' => $reason])
 *         ->warning();
 *
 * Each method returns `$this`, so the builder is an API for one-shot use; do not retain
 * references after calling a recorder method (`record`, `info`, …).
 */
final class AuditEventBuilder
{
    private string $action = '';

    private string $category = AuditEventCategory::OTHER;

    private string $level = AuditEventLevel::INFO;

    private ?int $actorId = null;

    private ?string $actorName = null;

    private ?string $targetType = null;

    private ?string $targetId = null;

    private ?string $message = null;

    /** @var array<string, mixed> */
    private array $context = [];

    private ?int $occurredAt = null;

    private function __construct(private readonly AuditLoggerInterface $logger)
    {
    }

    public static function create(AuditLoggerInterface $logger): self
    {
        return new self($logger);
    }

    public function action(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function category(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function level(string $level): self
    {
        $this->level = $level;

        return $this;
    }

    /**
     * Accepts a `WP_User` object, a numeric ID, or `null` for system / anonymous events.
     */
    public function for(mixed $actor): self
    {
        if ($actor === null) {
            $this->actorId = null;
            $this->actorName = null;

            return $this;
        }

        if (is_object($actor)) {
            $id = isset($actor->ID) && is_numeric($actor->ID) ? (int) $actor->ID : null;
            $login = isset($actor->user_login) && is_string($actor->user_login) ? $actor->user_login : '';
            $display = isset($actor->display_name) && is_string($actor->display_name) ? $actor->display_name : '';
            $this->actorId = $id;
            $this->actorName = $display !== '' ? $display : ($login !== '' ? $login : null);

            return $this;
        }

        if (is_numeric($actor)) {
            $this->actorId = (int) $actor;
            $this->actorName = null;
        }

        return $this;
    }

    public function target(string $type, string $id): self
    {
        $this->targetType = $type;
        $this->targetId = $id;

        return $this;
    }

    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function context(array $context): self
    {
        $this->context = array_replace_recursive($this->context, $context);

        return $this;
    }

    public function occurredAt(int $timestamp): self
    {
        $this->occurredAt = $timestamp;

        return $this;
    }

    public function record(): ?AuditEvent
    {
        return $this->logger->record($this->build());
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(array $context = []): ?AuditEvent
    {
        return $this->level(AuditEventLevel::INFO)->context($context)->record();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(array $context = []): ?AuditEvent
    {
        return $this->level(AuditEventLevel::WARNING)->context($context)->record();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(array $context = []): ?AuditEvent
    {
        return $this->level(AuditEventLevel::CRITICAL)->context($context)->record();
    }

    private function build(): AuditEvent
    {
        if (trim($this->action) === '') {
            throw new \LogicException('AuditEventBuilder: action() is required before recording.');
        }

        $event = AuditEvent::make($this->action, $this->category, $this->level)
            ->withActor($this->actorId, $this->actorName);

        if ($this->targetType !== null && $this->targetId !== null) {
            $event = $event->withTarget($this->targetType, $this->targetId);
        }
        if ($this->message !== null) {
            $event = $event->withMessage($this->message);
        }
        if ($this->context !== []) {
            $event = $event->withContext($this->context);
        }
        if ($this->occurredAt !== null) {
            $event = $event->withOccurredAt($this->occurredAt);
        }

        return $event;
    }
}
