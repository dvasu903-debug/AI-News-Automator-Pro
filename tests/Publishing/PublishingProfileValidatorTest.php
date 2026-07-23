<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\ProfileValidationException;
use AINewsAutomator\Publishing\Validation\PublishingProfileValidator;
use PHPUnit\Framework\TestCase;

final class PublishingProfileValidatorTest extends TestCase
{
    private PublishingProfileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PublishingProfileValidator();
    }

    public function test_valid_profile_passes(): void
    {
        $this->validator->validate($this->makeProfile([]));

        $this->addToAssertionCount(1);
    }

    public function test_empty_name_fails(): void
    {
        $this->assertViolation(['name' => '   '], 'name must not be empty');
    }

    public function test_empty_slug_fails(): void
    {
        $this->assertViolation(['slug' => ''], 'slug must not be empty');
    }

    public function test_invalid_slug_format_fails(): void
    {
        $this->assertViolation(['slug' => 'Bad_Slug!'], 'is invalid');
    }

    public function test_reserved_slug_fails(): void
    {
        $this->assertViolation(['slug' => 'default'], 'is reserved');
    }

    public function test_empty_vertical_fails(): void
    {
        $this->assertViolation(['vertical' => ''], 'vertical must not be empty');
    }

    public function test_empty_workflow_key_fails(): void
    {
        $this->assertViolation(['workflow_key' => ''], 'workflow_key must not be empty');
    }

    public function test_empty_approval_mode_fails(): void
    {
        $this->assertViolation(['approval_mode' => ''], 'approval_mode must not be empty');
    }

    public function test_approval_mode_over_column_width_fails(): void
    {
        $this->assertViolation(['approval_mode' => str_repeat('x', 31)], 'exceeds 30 characters');
    }

    public function test_arbitrary_non_empty_approval_mode_passes(): void
    {
        // No fixed value list (owner constraint) — any non-empty string
        // within the column width is structurally valid, including
        // values not otherwise known to this module.
        $this->validator->validate($this->makeProfile(['approval_mode' => 'manual']));
        $this->validator->validate($this->makeProfile(['approval_mode' => 'auto']));
        $this->validator->validate($this->makeProfile(['approval_mode' => 'review_queue']));

        $this->addToAssertionCount(3);
    }

    public function test_unsupported_schema_version_fails(): void
    {
        $this->assertViolation(
            ['config' => ['schema_version' => 99]],
            'Unsupported config schema version'
        );
    }

    public function test_all_violations_are_collected_before_throwing(): void
    {
        try {
            $this->validator->validate($this->makeProfile([
                'name'          => '',
                'slug'          => 'Bad Slug',
                'workflow_key'  => '',
                'approval_mode' => '',
            ]));

            $this->fail('Expected ProfileValidationException.');
        } catch (ProfileValidationException $e) {
            $this->assertGreaterThanOrEqual(4, count($e->errors()));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function assertViolation(array $overrides, string $needle): void
    {
        try {
            $this->validator->validate($this->makeProfile($overrides));

            $this->fail('Expected ProfileValidationException.');
        } catch (ProfileValidationException $e) {
            $this->assertStringContainsString($needle, implode(' | ', $e->errors()));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeProfile(array $overrides): PublishingProfile
    {
        return PublishingProfile::fromArray(array_merge([
            'slug'          => 'tech-news-default',
            'name'          => 'Tech News Default',
            'vertical'      => 'tech',
            'workflow_key'  => 'tech.article.v1',
            'approval_mode' => 'manual',
            'enabled'       => true,
            'config'        => ['schema_version' => 1],
        ], $overrides));
    }
}
