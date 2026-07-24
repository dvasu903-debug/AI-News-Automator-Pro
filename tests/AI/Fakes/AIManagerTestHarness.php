<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\AI\Fakes;

use AINewsAutomator\AI\Cache\TransientResponseCache;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\AI\Registry\ProviderRegistry;
use AINewsAutomator\Core\Events\EventDispatcher;

/**
 * Bundles a built AIManager with the collaborators tests need to assert
 * against (which events fired, what was recorded, cache state) without
 * each test re-deriving them from the manager itself (which exposes none
 * of this — deliberately, since business code should never introspect it).
 */
final class AIManagerTestHarness
{
    public function __construct(
        public readonly AIManager $manager,
        public readonly ProviderRegistry $registry,
        public readonly EventDispatcher $events,
        public readonly FakeAiRequestRepository $requestRepository,
        public readonly FakeMetricsRepository $metrics,
        public readonly TransientResponseCache $cache,
    ) {
    }
}
