<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

/**
 * Marker interface — certifies this connector autonomously follows
 * links rather than fetching one fixed URL. Connectors implementing
 * this MUST consult RobotsTxtCheckerInterface before fetching anything
 * beyond the initial seed URL — enforced by convention and code review,
 * not by the type system, the same way Security's modules document
 * required call patterns.
 */
interface CrawlConnectorInterface extends SourceConnectorInterface
{
}
