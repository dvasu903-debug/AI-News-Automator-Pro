<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Publishing;

use AINewsAutomator\Publishing\DTO\PublishingProfile;
use AINewsAutomator\Publishing\Exceptions\PublishingConfigurationException;
use PHPUnit\Framework\TestCase;

final class PublishingProfileTest extends TestCase
{
    public function test_constructor_injects_schema_version_when_missing(): void
    {
        $profile = $this->makeProfile(['config' => ['note' => 'x']]);

        $this->assertSame(PublishingProfile::CONFIG_SCHEMA_VERSION, $profile->configSchemaVersion());
    }

    public function test_defaults_match_column_defaults(): void
    {
        $profile = PublishingProfile::fromArray([
            'slug'         => 'test-profile',
            'name'         => 'Test Profile',
            'workflow_key' => 'news.default',
        ]);

        $this->assertSame('news', $profile->vertical());
        $this->assertSame('manual', $profile->approvalMode());
        $this->assertTrue($profile->enabled());
        $this->assertFalse($profile->isDefault());
    }

    public function test_withers_return_new_instances_and_do_not_mutate_original(): void
    {
        $original = $this->makeProfile([]);
        $renamed  = $original->withName('Changed');

        $this->assertNotSame($original, $renamed);
        $this->assertSame('Test Profile', $original->name());
        $this->assertSame('Changed', $renamed->name());
        $this->assertFalse($original->isDefault());
        $this->assertTrue($original->withDefault(true)->isDefault());
        $this->assertFalse($original->isDefault(), 'withDefault must not mutate the original.');
    }

    public function test_to_array_from_array_round_trip(): void
    {
        $profile = $this->makeProfile([
            'vertical'      => 'tech',
            'approval_mode' => 'auto',
            'config'        => ['schema_version' => 1, 'note' => 'x'],
        ]);

        $rebuilt = PublishingProfile::fromArray($profile->toArray());

        $this->assertSame($profile->toArray(), $rebuilt->toArray());
    }

    public function test_from_array_accepts_json_string_config(): void
    {
        $profile = PublishingProfile::fromArray([
            'slug'         => 'test',
            'name'         => 'Test',
            'workflow_key' => 'news.default',
            'config'       => '{"schema_version":1,"note":"x"}',
        ]);

        $this->assertSame('x', $profile->configValue('note'));
    }

    public function test_from_array_rejects_malformed_json_config(): void
    {
        $this->expectException(PublishingConfigurationException::class);

        PublishingProfile::fromArray([
            'slug'         => 'test',
            'name'         => 'Test',
            'workflow_key' => 'news.default',
            'config'       => '{not json',
        ]);
    }

    public function test_from_array_rejects_missing_workflow_key(): void
    {
        $this->expectException(PublishingConfigurationException::class);

        PublishingProfile::fromArray(['slug' => 'test', 'name' => 'Test']);
    }

    public function test_config_value_dot_notation_and_default(): void
    {
        $profile = $this->makeProfile([
            'config' => ['seo' => ['title_template' => '%title%']],
        ]);

        $this->assertSame('%title%', $profile->configValue('seo.title_template'));
        $this->assertSame('fallback', $profile->configValue('seo.missing', 'fallback'));
        $this->assertNull($profile->configValue('absent.path'));
    }

    public function test_config_json_encodes(): void
    {
        $profile = $this->makeProfile(['config' => ['schema_version' => 1, 'note' => 'x']]);
        $decoded = json_decode($profile->configJson(), true);

        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame('x', $decoded['note']);
    }

    public function test_timestamp_parsing_from_mysql_format(): void
    {
        $profile = PublishingProfile::fromArray([
            'slug'         => 'test',
            'name'         => 'Test',
            'workflow_key' => 'news.default',
            'config'       => [],
            'created_at'   => '2026-07-22 10:00:00',
        ]);

        $this->assertNotNull($profile->createdAt());
        $this->assertSame('2026-07-22', $profile->createdAt()->format('Y-m-d'));
    }

    public function test_unparseable_timestamp_throws(): void
    {
        $this->expectException(PublishingConfigurationException::class);

        PublishingProfile::fromArray([
            'slug'         => 'test',
            'name'         => 'Test',
            'workflow_key' => 'news.default',
            'config'       => [],
            'created_at'   => 'not-a-date',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeProfile(array $overrides): PublishingProfile
    {
        return PublishingProfile::fromArray(array_merge([
            'slug'         => 'test-profile',
            'name'         => 'Test Profile',
            'workflow_key' => 'news.default',
            'enabled'      => true,
            'config'       => ['schema_version' => 1],
        ], $overrides));
    }
}
