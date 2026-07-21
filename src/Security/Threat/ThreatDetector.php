<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Threat;

use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Security\Events\PermissionDeniedEvent;
use AINewsAutomator\Security\Events\RateLimitExceededEvent;
use AINewsAutomator\Security\Events\SuspiciousRequestEvent;
use AINewsAutomator\Security\Events\ThreatDetectedEvent;

/**
 * Correlates security signals over a short window and raises a
 * ThreatDetectedEvent when a subject (IP or user) crosses a threshold.
 *
 * It subscribes to the Core event dispatcher for the low-level security
 * events (permission denials, suspicious requests, rate-limit hits), keeps
 * per-subject counters in transients (auto-expiring = the sliding-ish
 * window), and emits a single higher-level ThreatDetectedEvent once a
 * threshold trips. Monitoring (Module 14) subscribes to ThreatDetectedEvent
 * for alerting — ThreatDetector itself only detects, never notifies, keeping
 * detection and response decoupled.
 */
final class ThreatDetector
{
    private const PREFIX = 'ana_threat_';

    /**
     * Thresholds: [count, window-seconds] per threat type. Kept modest and
     * overridable via filter so a site can tune sensitivity.
     *
     * @var array<string, array{int, int}>
     */
    private array $thresholds = [
        'repeated_permission_denied' => [10, 300],
        'repeated_nonce_failure'     => [8, 300],
        'excessive_rate_limiting'    => [20, 300],
        'repeated_webhook_failure'   => [10, 600],
    ];

    public function __construct(
        private readonly EventDispatcherInterface $events,
        private readonly EventMetadataFactory $metadataFactory,
        private readonly LoggerInterface $logger,
    ) {
        $filtered = apply_filters('ai_news_automator_threat_thresholds', $this->thresholds);
        if (is_array($filtered)) {
            $this->thresholds = $filtered;
        }
    }

    /**
     * Registers listeners on the Core dispatcher. Called from the provider's
     * boot phase.
     */
    public function subscribe(): void
    {
        $this->events->addListener(
            PermissionDeniedEvent::class,
            fn (PermissionDeniedEvent $e) => $this->onPermissionDenied($e)
        );

        $this->events->addListener(
            SuspiciousRequestEvent::class,
            fn (SuspiciousRequestEvent $e) => $this->onSuspiciousRequest($e)
        );

        $this->events->addListener(
            RateLimitExceededEvent::class,
            fn (RateLimitExceededEvent $e) => $this->onRateLimitExceeded($e)
        );
    }

    private function onPermissionDenied(PermissionDeniedEvent $event): void
    {
        $subject = $event->ip . '|user:' . $event->userId;
        $this->tally('repeated_permission_denied', $subject, [
            'ability' => $event->ability,
            'user_id' => $event->userId,
        ]);
    }

    private function onSuspiciousRequest(SuspiciousRequestEvent $event): void
    {
        if ($event->kind === 'nonce_failure') {
            $this->tally('repeated_nonce_failure', $event->ip, ['detail' => $event->detail]);
        }
    }

    private function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        $this->tally('excessive_rate_limiting', $event->ip, ['key' => $event->key]);
    }

    /**
     * Public entry so non-event callers (e.g. a webhook endpoint) can report
     * a failure into the same detection machinery.
     *
     * @param array<string, mixed> $evidence
     */
    public function report(string $threatType, string $subject, array $evidence = []): void
    {
        $this->tally($threatType, $subject, $evidence);
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function tally(string $threatType, string $subject, array $evidence): void
    {
        if (!isset($this->thresholds[$threatType])) {
            return;
        }

        [$limit, $window] = $this->thresholds[$threatType];

        $key = self::PREFIX . md5($threatType . '|' . $subject);
        $count = (int) get_transient($key);
        $count++;
        set_transient($key, $count, $window);

        if ($count === $limit) {
            // Emit exactly once, at the threshold, to avoid event floods.
            $this->logger->warning('Threat detected: {type} from {subject} ({count} occurrences).', [
                'type'    => $threatType,
                'subject' => $subject,
                'count'   => $count,
            ]);

            $this->events->dispatch(new ThreatDetectedEvent(
                $this->metadataFactory->create('Security', ['threat_type' => $threatType]),
                threatType: $threatType,
                subject: $subject,
                count: $count,
                evidence: $evidence,
            ));
        }
    }
}
