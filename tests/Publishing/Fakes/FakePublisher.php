<?php
/**
 * Shared test double for PublisherInterface, used by Action tests.
 *
 * @package AINewsAutomator\Tests\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing\Fakes;

use AINewsAutomator\Publishing\Contracts\PublisherInterface;
use AINewsAutomator\Publishing\DTO\PublishResult;

final class FakePublisher implements PublisherInterface
{
    public ?PublishResult $publishReturn = null;
    public ?PublishResult $scheduleReturn = null;
    public ?PublishResult $unpublishReturn = null;
    public ?PublishResult $archiveReturn = null;

    /** @var list<int> */
    public array $publishCalls = [];
    /** @var list<array{0: int, 1: \DateTimeImmutable}> */
    public array $scheduleCalls = [];
    /** @var list<int> */
    public array $unpublishCalls = [];
    /** @var list<int> */
    public array $archiveCalls = [];

    public function publish(int $postId): PublishResult
    {
        $this->publishCalls[] = $postId;

        return $this->publishReturn ?? PublishResult::published($postId, new \DateTimeImmutable());
    }

    public function schedule(int $postId, \DateTimeImmutable $at): PublishResult
    {
        $this->scheduleCalls[] = [$postId, $at];

        return $this->scheduleReturn ?? PublishResult::scheduled($postId, $at);
    }

    public function unpublish(int $postId): PublishResult
    {
        $this->unpublishCalls[] = $postId;

        return $this->unpublishReturn ?? PublishResult::unpublished($postId);
    }

    public function archive(int $postId): PublishResult
    {
        $this->archiveCalls[] = $postId;

        return $this->archiveReturn ?? PublishResult::archived($postId);
    }
}
