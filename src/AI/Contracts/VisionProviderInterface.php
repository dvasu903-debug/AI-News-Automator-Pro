<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Contracts;

/**
 * Marker interface — no new method. Certifies that this provider's
 * ChatProviderInterface::chat() accepts image ContentParts. Vision isn't
 * a separate API call for these vendors, it's multimodal input to the
 * same chat endpoint (see architecture decision §2.1/2.2 in the design doc).
 */
interface VisionProviderInterface extends AIProviderInterface
{
}
