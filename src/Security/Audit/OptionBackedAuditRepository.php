<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Audit;

use AINewsAutomator\Security\Contracts\AuditLogRepositoryInterface;

/**
 * Option-backed audit store: a single rotating wp_options entry. Sufficient
 * for moderate volume and consistent with Core's logger approach. Module 3
 * (Storage) provides a table-backed implementation for query-at-volume;
 * because AuditLogger depends on the interface, that swap changes nothing
 * for callers.
 */
final class OptionBackedAuditRepository implements AuditLogRepositoryInterface
{
    private const OPTION_KEY = 'ai_news_automator_audit_log';
    private const MAX_ENTRIES = 500;

    public function persist(AuditEntry $entry): void
    {
        $entries = $this->rawEntries();
        $entries[] = $entry->toArray();

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION_KEY, $entries, false);
    }

    public function recent(int $limit): array
    {
        $entries = array_reverse($this->rawEntries());
        $entries = array_slice($entries, 0, max(0, $limit));

        return array_map(
            static fn (array $data): AuditEntry => AuditEntry::fromArray($data),
            $entries
        );
    }

    public function purge(): void
    {
        delete_option(self::OPTION_KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawEntries(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        /** @var list<array<string, mixed>> $filtered */
        $filtered = array_values(array_filter($stored, 'is_array'));

        return $filtered;
    }
}
