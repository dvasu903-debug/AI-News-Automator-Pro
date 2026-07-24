<?php
/**
 * PublishingProfileService.
 *
 * Business-correctness layer: Validator -> Service -> Repository.
 * Unchanged in architecture from r2; renamed isActive()/setActive() to
 * enabled()/setEnabled() to match the real `enabled` column, and
 * dropped all postType-related logic (no replacement needed at the
 * service layer — vertical/workflow_key/approval_mode are plain DTO
 * fields with no service-level policy of their own yet).
 *
 * Embedded policy decisions (unchanged from r2, still flagged for
 * product-owner confirmation):
 *   P1. The default profile cannot be deleted.
 *   P2. The default profile cannot be disabled.
 *   P3. Only an enabled profile can be marked default.
 *   P4. requireDefault() throws PublishingConfigurationException when
 *       no explicit default exists — no silent fallback.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Services;

use AINewsAutomator\Publishing\Contracts\PublishingProfileRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\PublishingProfileValidatorInterface;
use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\DuplicateNameException;
use AINewsAutomator\Publishing\Exceptions\DuplicateSlugException;
use AINewsAutomator\Publishing\Exceptions\ProfileNotFoundException;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;
use AINewsAutomator\Publishing\Exceptions\PublishingConfigurationException;

final class PublishingProfileService
{
    private PublishingProfileRepositoryInterface $repository;
    private PublishingProfileValidatorInterface $validator;

    public function __construct(
        PublishingProfileRepositoryInterface $repository,
        PublishingProfileValidatorInterface $validator
    ) {
        $this->repository = $repository;
        $this->validator  = $validator;
    }

    /**
     * @throws ProfileValidationException
     */
    public function create(PublishingProfile $profile): PublishingProfile
    {
        $this->validator->validate($profile);
        $this->assertUniqueness($profile, null);

        $wantsDefault = $profile->isDefault();

        $created = $this->repository->create($profile->withDefault(false));

        if ($wantsDefault) {
            $this->markDefault((int) $created->id());
            $created = $this->requireById((int) $created->id());
        }

        return $created;
    }

    /**
     * @throws ProfileValidationException
     * @throws ProfileNotFoundException
     */
    public function update(PublishingProfile $profile): PublishingProfile
    {
        $id = $profile->id();

        if (null === $id) {
            throw new ProfileValidationException(['Cannot update a profile without an id.']);
        }

        $existing = $this->requireById($id);

        $this->validator->validate($profile);
        $this->assertUniqueness($profile, $id);

        // Policy P2: the default profile must stay enabled.
        if ($existing->isDefault() && !$profile->enabled()) {
            throw new ProfileValidationException(
                ['The default profile cannot be disabled. Mark another profile as default first.']
            );
        }

        // is_default is single-writer via markDefault(); the repository
        // update() path never touches that column, and this preserves
        // the currently-persisted value in the returned DTO too.
        $updated = $this->repository->update($profile->withDefault($existing->isDefault()));

        return $updated;
    }

    /**
     * Policy P1: deleting the default profile is rejected.
     *
     * @throws ProfileNotFoundException
     * @throws ProfileValidationException
     */
    public function delete(int $profileId): bool
    {
        $existing = $this->requireById($profileId);

        if ($existing->isDefault()) {
            throw new ProfileValidationException(
                ['The default profile cannot be deleted. Mark another profile as default first.']
            );
        }

        return $this->repository->delete($profileId);
    }

    public function getById(int $profileId): ?PublishingProfile
    {
        return $this->repository->findById($profileId);
    }

    public function getBySlug(string $slug): ?PublishingProfile
    {
        return $this->repository->findBySlug($slug);
    }

    /**
     * @return PublishingProfile[]
     */
    public function listProfiles(bool $enabledOnly = false): array
    {
        return $this->repository->findAll($enabledOnly);
    }

    /**
     * Policy P3: only enabled profiles may become the default.
     *
     * @throws ProfileNotFoundException
     * @throws ProfileValidationException
     */
    public function markDefault(int $profileId): void
    {
        $profile = $this->requireById($profileId);

        if (!$profile->enabled()) {
            throw new ProfileValidationException(
                ['Only an enabled profile can be marked as default.']
            );
        }

        $this->repository->markDefault($profileId);
    }

    /**
     * Policy P2 enforcement for direct state changes.
     *
     * @throws ProfileNotFoundException
     * @throws ProfileValidationException
     */
    public function setEnabled(int $profileId, bool $enabled): void
    {
        $profile = $this->requireById($profileId);

        if (!$enabled && $profile->isDefault()) {
            throw new ProfileValidationException(
                ['The default profile cannot be disabled. Mark another profile as default first.']
            );
        }

        $this->repository->setEnabled($profileId, $enabled);
    }

    /**
     * Deterministic default resolution.
     *
     * @throws PublishingConfigurationException When no default exists.
     */
    public function requireDefault(): PublishingProfile
    {
        $default = $this->repository->findDefault();

        if (null === $default) {
            throw new PublishingConfigurationException(
                'No default publishing profile is configured. Mark a profile as default before running publishing workflows.'
            );
        }

        return $default;
    }

    /**
     * @throws ProfileNotFoundException
     */
    private function requireById(int $profileId): PublishingProfile
    {
        $profile = $this->repository->findById($profileId);

        if (null === $profile) {
            throw ProfileNotFoundException::forId($profileId);
        }

        return $profile;
    }

    /**
     * @throws DuplicateSlugException
     * @throws DuplicateNameException
     */
    private function assertUniqueness(PublishingProfile $profile, ?int $excludeId): void
    {
        if ($this->repository->existsWithSlug($profile->slug(), $excludeId)) {
            throw DuplicateSlugException::forSlug($profile->slug());
        }

        if ($this->repository->existsWithName($profile->name(), $excludeId)) {
            throw DuplicateNameException::forName($profile->name());
        }
    }
}
