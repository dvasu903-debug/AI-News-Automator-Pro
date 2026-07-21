<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Authorization;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Security\Audit\AuditLogger;
use AINewsAutomator\Security\Audit\AuditResult;
use AINewsAutomator\Security\Audit\AuditSeverity;
use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Security\Events\PermissionDeniedEvent;
use AINewsAutomator\Security\Exceptions\AuthorizationException;
use AINewsAutomator\Security\Metrics\SecurityMetrics;
use AINewsAutomator\Security\Request\RequestContext;

/**
 * The single authorization entry point for the whole plugin. Implements the
 * approved flow end-to-end:
 *
 *   Permission -> Policy (engine) -> Decision -> Audit -> Event
 *
 * Every module receives this via CapabilityGateInterface and calls allows()
 * or authorize() instead of touching current_user_can() — which is what
 * guarantees no business logic bypasses Security: there is one gate, it
 * always evaluates policies, always audits, always meters, and (on denial)
 * always emits an event ThreatDetector can act on.
 */
final class CapabilityGate implements CapabilityGateInterface
{
    public function __construct(
        private readonly PolicyEngine $engine,
        private readonly AuditLogger $audit,
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly SecurityMetricsInterface $metrics,
        private readonly RequestContext $request,
    ) {
    }

    public function allows(string $ability, array $context = []): bool
    {
        $authContext = new AuthorizationContext(
            userId: $this->request->currentUserId(),
            ip: $this->request->ip(),
            context: $context,
        );

        $result = $this->engine->evaluate($ability, $authContext);

        if ($result->allowed) {
            $this->metrics->increment(SecurityMetrics::SUCCESSFUL_VALIDATIONS);
            $this->audit->log(
                action: 'authorize',
                target: $ability,
                result: AuditResult::Success,
                severity: AuditSeverity::Info,
                context: ['reason' => $result->reasonSummary()],
            );

            return true;
        }

        // Denied: meter, audit, and emit an event for threat detection.
        $this->metrics->increment(SecurityMetrics::DENIED_REQUESTS);

        $this->audit->log(
            action: 'authorize',
            target: $ability,
            result: AuditResult::Failure,
            severity: AuditSeverity::Warning,
            context: ['reason' => $result->reasonSummary()],
        );

        $this->events->dispatch(new PermissionDeniedEvent(
            $this->metadataFactory->create('Security', ['ability' => $ability]),
            ability: $ability,
            userId: $authContext->userId,
            ip: $authContext->ip,
            reason: $result->reasonSummary(),
        ));

        return false;
    }

    public function authorize(string $ability, array $context = []): void
    {
        if (!$this->allows($ability, $context)) {
            throw AuthorizationException::forAbility($ability);
        }
    }
}
