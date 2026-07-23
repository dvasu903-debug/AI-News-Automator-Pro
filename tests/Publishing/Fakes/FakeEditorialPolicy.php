<?php
/**
 * Shared test double for EditorialPolicyInterface, used by Action tests.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Publishing\Contracts\EditorialPolicyInterface;
use AINewsAutomator\Publishing\DTO\EditorialPolicyResult;
use AINewsAutomator\Publishing\DTO\PublishingProfile;

final class FakeEditorialPolicy implements EditorialPolicyInterface
{
    public ?EditorialPolicyResult $evaluateReturn = null;

    /** @var list<array{0: int, 1: PublishingProfile}> */
    public array $evaluateCalls = [];

    public function evaluate(int $postId, PublishingProfile $profile): EditorialPolicyResult
    {
        $this->evaluateCalls[] = [$postId, $profile];

        return $this->evaluateReturn ?? EditorialPolicyResult::passed();
    }
}
