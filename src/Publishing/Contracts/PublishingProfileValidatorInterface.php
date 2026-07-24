<?php
/**
 * PublishingProfileValidatorInterface.
 *
 * Structural and domain validation gatekeeper. Deliberately
 * persistence-free: uniqueness checks require repository access and are
 * enforced by PublishingProfileService.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Contracts;

use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;

interface PublishingProfileValidatorInterface
{
    /**
     * @throws ProfileValidationException When one or more rules fail.
     */
    public function validate(PublishingProfile $profile): void;
}
