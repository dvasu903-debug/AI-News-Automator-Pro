<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Settings;

/**
 * Plain registry of AbstractSettingsPage subclass names, populated by
 * modules during their register() phase. Same pattern as
 * RestApi\RestApiRegistry — see that class's docblock for why this is
 * a plain registry rather than container tagging for now.
 */
final class SettingsRegistry
{
    /** @var list<class-string<AbstractSettingsPage>> */
    private array $pages = [];

    /**
     * @param class-string<AbstractSettingsPage> $pageClass
     */
    public function register(string $pageClass): void
    {
        if (!in_array($pageClass, $this->pages, true)) {
            $this->pages[] = $pageClass;
        }
    }

    /**
     * @return list<class-string<AbstractSettingsPage>>
     */
    public function all(): array
    {
        return $this->pages;
    }
}
