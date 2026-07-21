<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Contracts;

use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Sources\Exceptions\SourceValidationException;
use AINewsAutomator\Storage\Entities\SourceRecord;

interface SourceValidatorInterface
{
    /**
     * @throws SourceValidationException
     */
    public function validateSource(SourceRecord $source): void;

    /**
     * @throws SourceValidationException
     */
    public function validateItem(NormalizedItem $item): void;
}
