<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Settings;

/**
 * Immutable description of a single settings form field. Named
 * constructors cover the field types the plugin needs; each carries a
 * sensible default sanitizer so a module defining a settings page never
 * hand-writes sanitization logic for the common cases, only for
 * anything genuinely custom (via the $sanitizer override param).
 *
 * This directly replaces the old plugin's technical debt item T5/T7:
 * one monolithic settings class with inline sanitize logic and magic
 * string field names repeated across the render + sanitize methods.
 * Here, the field definition is the single source of truth for both.
 */
final class SettingsField
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_PASSWORD = 'password';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_NUMBER   = 'number';
    public const TYPE_SELECT   = 'select';

    /**
     * @param array<string, string> $options Value => label pairs. Only used for TYPE_SELECT.
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly string $description = '',
        public readonly mixed $default = null,
        public readonly array $options = [],
        private readonly ?\Closure $sanitizer = null,
    ) {
    }

    public static function text(string $key, string $label, string $description = '', string $default = ''): self
    {
        return new self($key, $label, self::TYPE_TEXT, $description, $default);
    }

    public static function password(string $key, string $label, string $description = ''): self
    {
        return new self($key, $label, self::TYPE_PASSWORD, $description, '');
    }

    public static function textarea(string $key, string $label, string $description = '', string $default = ''): self
    {
        return new self($key, $label, self::TYPE_TEXTAREA, $description, $default);
    }

    public static function checkbox(string $key, string $label, string $description = '', bool $default = false): self
    {
        return new self($key, $label, self::TYPE_CHECKBOX, $description, $default);
    }

    public static function number(string $key, string $label, string $description = '', int $default = 0): self
    {
        return new self($key, $label, self::TYPE_NUMBER, $description, $default);
    }

    /**
     * @param array<string, string> $options Value => label pairs.
     */
    public static function select(string $key, string $label, array $options, string $description = '', string $default = ''): self
    {
        return new self($key, $label, self::TYPE_SELECT, $description, $default, $options);
    }

    /**
     * Returns a copy of this field with a custom sanitizer, for the
     * rare case the built-in per-type sanitization isn't sufficient.
     */
    public function withSanitizer(\Closure $sanitizer): self
    {
        return new self($this->key, $this->label, $this->type, $this->description, $this->default, $this->options, $sanitizer);
    }

    public function sanitize(mixed $raw): mixed
    {
        if ($this->sanitizer !== null) {
            return ($this->sanitizer)($raw);
        }

        return match ($this->type) {
            self::TYPE_TEXT, self::TYPE_PASSWORD => sanitize_text_field((string) $raw),
            self::TYPE_TEXTAREA => sanitize_textarea_field((string) $raw),
            self::TYPE_NUMBER   => absint($raw),
            self::TYPE_CHECKBOX => !empty($raw),
            self::TYPE_SELECT   => $this->sanitizeSelect((string) $raw),
        };
    }

    private function sanitizeSelect(string $raw): string
    {
        if (array_key_exists($raw, $this->options)) {
            return $raw;
        }

        return is_string($this->default) ? $this->default : '';
    }
}
