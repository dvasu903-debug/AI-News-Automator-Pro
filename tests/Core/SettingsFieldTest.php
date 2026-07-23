<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Settings\SettingsField;
use PHPUnit\Framework\TestCase;

final class SettingsFieldTest extends TestCase
{
    public function test_text_field_strips_tags(): void
    {
        $field = SettingsField::text('name', 'Name');

        $this->assertSame('alert', $field->sanitize('<script>alert</script>'));
    }

    public function test_number_field_returns_absolute_integer(): void
    {
        $field = SettingsField::number('count', 'Count');

        $this->assertSame(5, $field->sanitize('-5'));
        $this->assertSame(5, $field->sanitize(5));
    }

    public function test_checkbox_field_coerces_to_bool(): void
    {
        $field = SettingsField::checkbox('enabled', 'Enabled');

        $this->assertTrue($field->sanitize('1'));
        $this->assertFalse($field->sanitize(null));
        $this->assertFalse($field->sanitize(''));
    }

    public function test_select_field_rejects_value_outside_options_and_falls_back_to_default(): void
    {
        $field = SettingsField::select('provider', 'Provider', ['claude' => 'Claude', 'openai' => 'OpenAI'], default: 'claude');

        $this->assertSame('openai', $field->sanitize('openai'));
        $this->assertSame('claude', $field->sanitize('not-a-real-option'));
    }

    public function test_with_sanitizer_overrides_default_behavior(): void
    {
        $field = SettingsField::text('slug', 'Slug')
            ->withSanitizer(fn (mixed $raw): string => strtoupper((string) $raw));

        $this->assertSame('HELLO', $field->sanitize('hello'));
    }

    public function test_with_sanitizer_returns_a_new_instance_and_does_not_mutate_original(): void
    {
        $original = SettingsField::text('slug', 'Slug');
        $modified = $original->withSanitizer(fn (mixed $raw): string => 'always-this');

        $this->assertSame('hello', $original->sanitize('hello'));
        $this->assertSame('always-this', $modified->sanitize('hello'));
    }
}
