<?php

declare(strict_types=1);

namespace AINewsAutomator\Sources\Validation;

use AINewsAutomator\Sources\Contracts\SourceConnectorRegistryInterface;
use AINewsAutomator\Sources\Contracts\SourceValidatorInterface;
use AINewsAutomator\Sources\DTO\NormalizedItem;
use AINewsAutomator\Sources\Exceptions\SourceValidationException;
use AINewsAutomator\Storage\Entities\SourceRecord;

final class SourceValidator implements SourceValidatorInterface
{
    public function __construct(private readonly SourceConnectorRegistryInterface $registry)
    {
    }

    public function validateSource(SourceRecord $source): void
    {
        $errors = [];

        if (trim($source->name) === '') {
            $errors['name'] = 'Name is required.';
        }

        if ($this->registry->forType($source->type) === null) {
            $errors['type'] = sprintf('No connector is registered for type "%s".', $source->type);
        }

        if (($source->config['url'] ?? $source->config['seed_url'] ?? null) === null) {
            $errors['config'] = 'Source config must include a "url" (or "seed_url" for web_crawler sources).';
        }

        if ($errors !== []) {
            throw new SourceValidationException($errors, sprintf('Source "%s" failed validation.', $source->name));
        }
    }

    public function validateItem(NormalizedItem $item): void
    {
        $errors = [];

        if (trim($item->url) === '') {
            $errors['url'] = 'Item URL is required.';
        } elseif (filter_var($item->url, FILTER_VALIDATE_URL) === false) {
            $errors['url'] = 'Item URL is not a valid URL.';
        }

        if ($errors !== []) {
            throw new SourceValidationException($errors, 'Discovered item failed validation.');
        }
    }
}
