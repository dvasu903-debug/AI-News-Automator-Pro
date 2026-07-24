<?php
/**
 * InMemoryPublishingProfileRepository.
 *
 * Fast, isolated double for PublishingProfileServiceTest's business-rule
 * tests — distinct from PublishingProfileRepositoryTest, which exercises
 * the real repository against the real Connection/TransactionManager
 * and tests/Storage/FakeWpdb.php. Both are legitimate, complementary
 * layers: this one proves the service's policies; the other proves the
 * repository's SQL is correct against the real Module 3 contracts.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use DateTimeImmutable;
use DateTimeZone;

final class InMemoryPublishingProfileRepository implements PublishingProfileRepositoryInterface
{
    /**
     * @var array<int, PublishingProfile>
     */
    private array $profiles = [];

    private int $nextId = 1;

    public function create(PublishingProfile $profile): PublishingProfile
    {
        $now   = $this->now();
        $saved = $profile->withId($this->nextId)->withTimestamps($now, $now);

        $this->profiles[$this->nextId] = $saved;
        ++$this->nextId;

        return $saved;
    }

    public function update(PublishingProfile $profile): PublishingProfile
    {
        $id = $profile->id();

        if (null === $id || !isset($this->profiles[$id])) {
            throw ProfileNotFoundException::forId((int) $id);
        }

        $saved                = $profile->withTimestamps($this->profiles[$id]->createdAt(), $this->now());
        $this->profiles[$id] = $saved;

        return $saved;
    }

    public function delete(int $profileId): bool
    {
        if (!isset($this->profiles[$profileId])) {
            return false;
        }

        unset($this->profiles[$profileId]);

        return true;
    }

    public function findById(int $profileId): ?PublishingProfile
    {
        return $this->profiles[$profileId] ?? null;
    }

    public function findBySlug(string $slug): ?PublishingProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->slug() === $slug) {
                return $profile;
            }
        }

        return null;
    }

    public function findAll(bool $enabledOnly = false): array
    {
        $all = array_values($this->profiles);

        if ($enabledOnly) {
            $all = array_values(array_filter($all, static fn (PublishingProfile $p): bool => $p->enabled()));
        }

        usort($all, static fn (PublishingProfile $a, PublishingProfile $b): int => strcmp($a->name(), $b->name()));

        return $all;
    }

    public function findDefault(): ?PublishingProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->isDefault()) {
                return $profile;
            }
        }

        return null;
    }

    public function markDefault(int $profileId): void
    {
        if (!isset($this->profiles[$profileId])) {
            throw ProfileNotFoundException::forId($profileId);
        }

        foreach ($this->profiles as $id => $profile) {
            if ($profile->isDefault() && $id !== $profileId) {
                $this->profiles[$id] = $profile->withDefault(false)->withTimestamps($profile->createdAt(), $this->now());
            }
        }

        $target                      = $this->profiles[$profileId];
        $this->profiles[$profileId] = $target->withDefault(true)->withTimestamps($target->createdAt(), $this->now());
    }

    public function setEnabled(int $profileId, bool $enabled): void
    {
        if (!isset($this->profiles[$profileId])) {
            throw ProfileNotFoundException::forId($profileId);
        }

        $profile                    = $this->profiles[$profileId];
        $this->profiles[$profileId] = $profile->withEnabled($enabled)->withTimestamps($profile->createdAt(), $this->now());
    }

    public function existsWithSlug(string $slug, ?int $excludeId = null): bool
    {
        foreach ($this->profiles as $id => $profile) {
            if ($profile->slug() === $slug && $id !== $excludeId) {
                return true;
            }
        }

        return false;
    }

    public function existsWithName(string $name, ?int $excludeId = null): bool
    {
        foreach ($this->profiles as $id => $profile) {
            if ($profile->name() === $name && $id !== $excludeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test helper: asserts the "exactly one default" invariant.
     */
    public function defaultCount(): int
    {
        $count = 0;

        foreach ($this->profiles as $profile) {
            if ($profile->isDefault()) {
                ++$count;
            }
        }

        return $count;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
