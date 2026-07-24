<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\Contracts\PublishingProfileValidatorInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\DuplicateNameException;
use AINewsAutomator\Publishing\Exceptions\DuplicateSlugException;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;
use AINewsAutomator\Publishing\Exceptions\PublishingConfigurationException;
use AINewsAutomator\Publishing\Services\PublishingProfileService;
use AINewsAutomator\Tests\Publishing\Fakes\InMemoryPublishingProfileRepository;
use PHPUnit\Framework\TestCase;

final class PublishingProfileServiceTest extends TestCase
{
    private InMemoryPublishingProfileRepository $repository;
    private PublishingProfileService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryPublishingProfileRepository();

        $passThrough = new class implements PublishingProfileValidatorInterface {
            public function validate(PublishingProfile $profile): void
            {
            }
        };

        $this->service = new PublishingProfileService($this->repository, $passThrough);
    }

    public function test_create_assigns_id_and_timestamps(): void
    {
        $created = $this->service->create($this->makeProfile('Alpha', 'alpha'));

        $this->assertSame(1, $created->id());
        $this->assertNotNull($created->createdAt());
        $this->assertNotNull($created->updatedAt());
    }

    public function test_create_with_default_flag_promotes_atomically(): void
    {
        $this->service->create($this->makeProfile('Alpha', 'alpha', true));
        $this->service->create($this->makeProfile('Beta', 'beta', true));

        $this->assertSame(1, $this->repository->defaultCount());
        $this->assertSame('beta', $this->service->requireDefault()->slug());
    }

    public function test_duplicate_slug_rejected(): void
    {
        $this->service->create($this->makeProfile('Alpha', 'shared'));

        $this->expectException(DuplicateSlugException::class);
        $this->service->create($this->makeProfile('Beta', 'shared'));
    }

    public function test_duplicate_name_rejected(): void
    {
        $this->service->create($this->makeProfile('Shared', 'alpha'));

        $this->expectException(DuplicateNameException::class);
        $this->service->create($this->makeProfile('Shared', 'beta'));
    }

    public function test_duplicate_exceptions_extend_profile_validation_exception(): void
    {
        $this->assertInstanceOf(ProfileValidationException::class, DuplicateSlugException::forSlug('x'));
        $this->assertInstanceOf(ProfileValidationException::class, DuplicateNameException::forName('x'));
    }

    public function test_update_allows_keeping_own_slug(): void
    {
        $created = $this->service->create($this->makeProfile('Alpha', 'alpha'));
        $updated = $this->service->update($created->withName('Alpha Renamed'));

        $this->assertSame('Alpha Renamed', $updated->name());
        $this->assertSame('alpha', $updated->slug());
    }

    public function test_update_cannot_hijack_default_flag(): void
    {
        $alpha = $this->service->create($this->makeProfile('Alpha', 'alpha', true));
        $beta  = $this->service->create($this->makeProfile('Beta', 'beta'));

        $this->service->update($beta->withDefault(true));

        $this->assertSame(1, $this->repository->defaultCount());
        $this->assertSame($alpha->id(), $this->service->requireDefault()->id());
    }

    public function test_update_nonexistent_profile_throws(): void
    {
        $ghost = $this->makeProfile('Ghost', 'ghost')->withId(999);

        $this->expectException(ProfileNotFoundException::class);
        $this->service->update($ghost);
    }

    public function test_delete_default_profile_rejected(): void
    {
        $created = $this->service->create($this->makeProfile('Alpha', 'alpha', true));

        $this->expectException(ProfileValidationException::class);
        $this->service->delete((int) $created->id());
    }

    public function test_delete_non_default_profile_succeeds(): void
    {
        $this->service->create($this->makeProfile('Alpha', 'alpha', true));
        $beta = $this->service->create($this->makeProfile('Beta', 'beta'));

        $this->assertTrue($this->service->delete((int) $beta->id()));
        $this->assertNull($this->service->getById((int) $beta->id()));
    }

    public function test_disabling_default_profile_rejected(): void
    {
        $created = $this->service->create($this->makeProfile('Alpha', 'alpha', true));

        $this->expectException(ProfileValidationException::class);
        $this->service->setEnabled((int) $created->id(), false);
    }

    public function test_disabling_non_default_profile_succeeds(): void
    {
        $this->service->create($this->makeProfile('Alpha', 'alpha', true));
        $beta = $this->service->create($this->makeProfile('Beta', 'beta'));

        $this->service->setEnabled((int) $beta->id(), false);

        $this->assertFalse($this->service->getById((int) $beta->id())->enabled());
        $this->assertCount(1, $this->service->listProfiles(true));
        $this->assertCount(2, $this->service->listProfiles(false));
    }

    public function test_mark_default_rejects_disabled_profile(): void
    {
        $alpha = $this->service->create($this->makeProfile('Alpha', 'alpha'));
        $this->service->setEnabled((int) $alpha->id(), false);

        $this->expectException(ProfileValidationException::class);
        $this->service->markDefault((int) $alpha->id());
    }

    public function test_mark_default_demotes_previous_default(): void
    {
        $alpha = $this->service->create($this->makeProfile('Alpha', 'alpha', true));
        $beta  = $this->service->create($this->makeProfile('Beta', 'beta'));

        $this->service->markDefault((int) $beta->id());

        $this->assertSame(1, $this->repository->defaultCount());
        $this->assertFalse($this->service->getById((int) $alpha->id())->isDefault());
        $this->assertTrue($this->service->getById((int) $beta->id())->isDefault());
    }

    public function test_sequential_competing_default_updates_keep_single_default(): void
    {
        $ids = [];

        foreach (['a', 'b', 'c', 'd'] as $suffix) {
            $profile = $this->service->create($this->makeProfile('P ' . $suffix, 'p-' . $suffix));
            $ids[]   = (int) $profile->id();
        }

        foreach ([0, 2, 1, 3, 0, 3] as $index) {
            $this->service->markDefault($ids[$index]);
            $this->assertSame(1, $this->repository->defaultCount());
        }

        $this->assertSame($ids[3], $this->service->requireDefault()->id());
    }

    public function test_require_default_throws_when_no_default_configured(): void
    {
        $this->service->create($this->makeProfile('Alpha', 'alpha'));

        $this->expectException(PublishingConfigurationException::class);
        $this->service->requireDefault();
    }

    public function test_mark_default_unknown_id_throws(): void
    {
        $this->expectException(ProfileNotFoundException::class);
        $this->service->markDefault(42);
    }

    private function makeProfile(string $name, string $slug, bool $default = false): PublishingProfile
    {
        return PublishingProfile::fromArray([
            'slug'         => $slug,
            'name'         => $name,
            'workflow_key' => 'news.default',
            'enabled'      => true,
            'is_default'   => $default,
            'config'       => ['schema_version' => 1],
        ]);
    }
}
