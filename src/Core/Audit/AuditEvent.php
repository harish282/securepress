<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

/**
 * Immutable value object representing a single audit log entry.
 *
 * Construct via the {@see make()} static factory or the fluent builder methods. Every "with"
 * method returns a new instance — instances are deeply immutable so they can be queued,
 * passed across boundaries, and compared by identity safely.
 *
 * All time values are UTC unix timestamps. The repository converts to/from `datetime` strings
 * at the storage boundary; consumers always work in epoch seconds.
 */
final class AuditEvent
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public readonly string $action,
        public readonly string $category,
        public readonly string $level,
        public readonly int $occurredAt,
        public readonly ?int $actorId,
        public readonly ?string $actorName,
        public readonly ?string $targetType,
        public readonly ?string $targetId,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $requestUri,
        public readonly ?string $message,
        public readonly array $context,
        public readonly ?int $id = null,
    ) {
    }

    public static function make(
        string $action,
        string $category = AuditEventCategory::OTHER,
        string $level = AuditEventLevel::INFO,
    ): self {
        $action = trim($action);
        if ($action === '') {
            throw new \InvalidArgumentException('Audit event action must not be empty.');
        }

        return new self(
            action: $action,
            category: AuditEventCategory::normalize($category),
            level: AuditEventLevel::normalize($level),
            occurredAt: time(),
            actorId: null,
            actorName: null,
            targetType: null,
            targetId: null,
            ip: null,
            userAgent: null,
            requestUri: null,
            message: null,
            context: [],
        );
    }

    /**
     * Hydrates an event from a stored row. Used by repositories.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $context = $row['context'] ?? [];
        if (is_string($context)) {
            $decoded = json_decode($context, true);
            $context = is_array($decoded) ? $decoded : [];
        }

        return new self(
            action: (string) ($row['action'] ?? ''),
            category: (string) ($row['category'] ?? AuditEventCategory::OTHER),
            level: (string) ($row['level'] ?? AuditEventLevel::INFO),
            occurredAt: self::toTimestamp($row['occurred_at'] ?? time()),
            actorId: isset($row['actor_id']) && $row['actor_id'] !== null && $row['actor_id'] !== ''
                ? (int) $row['actor_id']
                : null,
            actorName: self::nullableString($row['actor_name'] ?? null),
            targetType: self::nullableString($row['target_type'] ?? null),
            targetId: self::nullableString($row['target_id'] ?? null),
            ip: self::nullableString($row['ip'] ?? null),
            userAgent: self::nullableString($row['user_agent'] ?? null),
            requestUri: self::nullableString($row['request_uri'] ?? null),
            message: self::nullableString($row['message'] ?? null),
            context: is_array($context) ? $context : [],
            id: isset($row['id']) && $row['id'] !== null && $row['id'] !== ''
                ? (int) $row['id']
                : null,
        );
    }

    public function withLevel(string $level): self
    {
        return $this->copyWith(['level' => AuditEventLevel::normalize($level)]);
    }

    public function withCategory(string $category): self
    {
        return $this->copyWith(['category' => AuditEventCategory::normalize($category)]);
    }

    public function withActor(?int $id, ?string $name = null): self
    {
        return $this->copyWith(['actorId' => $id, 'actorName' => $name]);
    }

    public function withTarget(?string $type, ?string $id): self
    {
        return $this->copyWith(['targetType' => $type, 'targetId' => $id]);
    }

    public function withMessage(?string $message): self
    {
        return $this->copyWith(['message' => $message]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        return $this->copyWith(['context' => $context]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function mergeContext(array $context): self
    {
        return $this->copyWith(['context' => array_replace_recursive($this->context, $context)]);
    }

    public function withRequest(?string $ip, ?string $userAgent, ?string $requestUri): self
    {
        return $this->copyWith(['ip' => $ip, 'userAgent' => $userAgent, 'requestUri' => $requestUri]);
    }

    public function withOccurredAt(int $timestamp): self
    {
        return $this->copyWith(['occurredAt' => max(0, $timestamp)]);
    }

    public function withId(int $id): self
    {
        return $this->copyWith(['id' => $id]);
    }

    /**
     * Storage-ready row representation. Repositories serialize/escape further as needed.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => gmdate('Y-m-d H:i:s', $this->occurredAt),
            'level' => $this->level,
            'category' => $this->category,
            'action' => $this->action,
            'actor_id' => $this->actorId,
            'actor_name' => self::truncate($this->actorName, 120),
            'target_type' => self::truncate($this->targetType, 40),
            'target_id' => self::truncate($this->targetId, 120),
            'ip' => self::truncate($this->ip, 45),
            'user_agent' => self::truncate($this->userAgent, 255),
            'request_uri' => self::truncate($this->requestUri, 255),
            'message' => $this->message,
            'context' => $this->context === [] ? null : (string) json_encode($this->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyWith(array $overrides): self
    {
        $pick = fn (string $key, mixed $current): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $current;

        return new self(
            action: (string) $pick('action', $this->action),
            category: (string) $pick('category', $this->category),
            level: (string) $pick('level', $this->level),
            occurredAt: (int) $pick('occurredAt', $this->occurredAt),
            actorId: $pick('actorId', $this->actorId),
            actorName: $pick('actorName', $this->actorName),
            targetType: $pick('targetType', $this->targetType),
            targetId: $pick('targetId', $this->targetId),
            ip: $pick('ip', $this->ip),
            userAgent: $pick('userAgent', $this->userAgent),
            requestUri: $pick('requestUri', $this->requestUri),
            message: $pick('message', $this->message),
            context: (array) $pick('context', $this->context),
            id: $pick('id', $this->id),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            return null;
        }
        $str = (string) $value;

        return $str === '' ? null : $str;
    }

    private static function toTimestamp(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value . ' UTC');
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return time();
    }

    private static function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
