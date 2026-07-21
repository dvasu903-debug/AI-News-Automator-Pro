<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Audit;

/**
 * A single audit record. Carries every field required for a defensible
 * security trail: who (actor id + login), what (action), on what (target),
 * the correlation id tying it to a request/pipeline run, source IP and
 * user agent, the emitting module, a severity, a timestamp, and the
 * success/failure result.
 *
 * Immutable value object. Serializes to/from an array so the repository can
 * persist it to options today and a database table in Module 3 without the
 * entry shape changing.
 */
final class AuditEntry
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly int $actorId,
        public readonly string $actorLogin,
        public readonly string $action,
        public readonly string $target,
        public readonly string $correlationId,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $module,
        public readonly AuditSeverity $severity,
        public readonly AuditResult $result,
        public readonly int $timestamp,
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actor_id'       => $this->actorId,
            'actor_login'    => $this->actorLogin,
            'action'         => $this->action,
            'target'         => $this->target,
            'correlation_id' => $this->correlationId,
            'ip'             => $this->ip,
            'user_agent'     => $this->userAgent,
            'module'         => $this->module,
            'severity'       => $this->severity->value,
            'result'         => $this->result->value,
            'timestamp'      => $this->timestamp,
            'context'        => $this->context,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            actorId: (int) ($data['actor_id'] ?? 0),
            actorLogin: (string) ($data['actor_login'] ?? ''),
            action: (string) ($data['action'] ?? ''),
            target: (string) ($data['target'] ?? ''),
            correlationId: (string) ($data['correlation_id'] ?? ''),
            ip: (string) ($data['ip'] ?? ''),
            userAgent: (string) ($data['user_agent'] ?? ''),
            module: (string) ($data['module'] ?? ''),
            severity: AuditSeverity::tryFrom((string) ($data['severity'] ?? 'info')) ?? AuditSeverity::Info,
            result: AuditResult::tryFrom((string) ($data['result'] ?? 'success')) ?? AuditResult::Success,
            timestamp: (int) ($data['timestamp'] ?? 0),
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
        );
    }
}
