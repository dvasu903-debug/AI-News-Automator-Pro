<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Audit;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Security\Contracts\AuditLoggerInterface;
use AINewsAutomator\Security\Contracts\AuditLogRepositoryInterface;
use AINewsAutomator\Security\Request\RequestContext;

/**
 * Records audit entries and mirrors them into the general log and the
 * security event stream.
 *
 * A convenience log() builds a complete AuditEntry from the current request
 * context, so callers supply only the security-relevant facts (action,
 * target, severity, result) and the actor/IP/UA/correlation-id are filled
 * in consistently — no caller hand-assembles those, which is what keeps
 * every entry uniformly populated.
 */
final class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly CorrelationContext $correlation,
        private readonly RequestContext $request,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        $this->repository->persist($entry);

        // Mirror into the general application log at a severity-appropriate level.
        $message = 'Audit: {action} on {target} by {actor} => {result}';
        $context = [
            'action' => $entry->action,
            'target' => $entry->target,
            'actor'  => $entry->actorLogin !== '' ? $entry->actorLogin : (string) $entry->actorId,
            'result' => $entry->result->value,
        ];

        match ($entry->severity) {
            AuditSeverity::Critical => $this->logger->critical($message, $context),
            AuditSeverity::Warning  => $this->logger->warning($message, $context),
            AuditSeverity::Info     => $this->logger->info($message, $context),
        };
    }

    /**
     * Builds and records an audit entry from the current request context.
     *
     * @param array<string, mixed> $context
     */
    public function log(
        string $action,
        string $target,
        AuditResult $result,
        AuditSeverity $severity = AuditSeverity::Info,
        string $module = 'Security',
        array $context = []
    ): AuditEntry {
        $entry = new AuditEntry(
            actorId: $this->request->currentUserId(),
            actorLogin: $this->request->currentUserLogin(),
            action: $action,
            target: $target,
            correlationId: $this->correlation->id(),
            ip: $this->request->ip(),
            userAgent: $this->request->userAgent(),
            module: $module,
            severity: $severity,
            result: $result,
            timestamp: time(),
            context: $context,
        );

        $this->record($entry);

        return $entry;
    }

    public function recent(int $limit = 50): array
    {
        return $this->repository->recent($limit);
    }
}
