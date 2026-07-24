<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Sources\Fakes;

use AINewsAutomator\Sources\Contracts\SourceConnectorInterface;
use AINewsAutomator\Sources\DTO\FetchResult;
use AINewsAutomator\Storage\Entities\SourceRecord;

final class FakeSourceConnector implements SourceConnectorInterface
{
    private ?FetchResult $result = null;

    public function __construct(private readonly string $connectorType = 'fake')
    {
    }

    public function type(): string
    {
        return $this->connectorType;
    }

    public function willReturn(FetchResult $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function fetch(SourceRecord $source): FetchResult
    {
        return $this->result ?? FetchResult::success([]);
    }
}
