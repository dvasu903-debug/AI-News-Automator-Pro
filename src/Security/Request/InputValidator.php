<?php

declare(strict_types=1);

namespace AINewsAutomator\Security\Request;

/**
 * Typed, sanitizing accessors over request input. Never trust superglobals
 * directly — read through here so every value is validated and sanitized at
 * the point of entry. Escaping is deliberately NOT done here: escaping must
 * happen at the point of output with the correct context (HTML, attribute,
 * URL, JS), so it lives at the output site via WordPress esc_* functions,
 * not in a one-size wrapper that would encourage escaping too early.
 *
 * All getters read from an injected array (defaulting to $_POST/$_GET/
 * $_REQUEST via the named constructors) so they're unit-testable without
 * touching real superglobals.
 */
final class InputValidator
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string, mixed>|null $source
     */
    public static function fromPost(?array $source = null): self
    {
        return new self($source ?? $_POST);
    }

    /**
     * @param array<string, mixed>|null $source
     */
    public static function fromGet(?array $source = null): self
    {
        return new self($source ?? $_GET);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function text(string $key, string $default = ''): string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->data[$key];

        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    public function textarea(string $key, string $default = ''): string
    {
        if (!$this->has($key) || !is_scalar($this->data[$key])) {
            return $default;
        }

        return sanitize_textarea_field((string) $this->data[$key]);
    }

    public function integer(string $key, int $default = 0): int
    {
        if (!$this->has($key) || !is_scalar($this->data[$key])) {
            return $default;
        }

        return (int) $this->data[$key];
    }

    public function boolean(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        return filter_var($this->data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function email(string $key, string $default = ''): string
    {
        if (!$this->has($key) || !is_scalar($this->data[$key])) {
            return $default;
        }

        $sanitized = sanitize_email((string) $this->data[$key]);

        return is_email($sanitized) ? $sanitized : $default;
    }

    public function url(string $key, string $default = ''): string
    {
        if (!$this->has($key) || !is_scalar($this->data[$key])) {
            return $default;
        }

        return esc_url_raw((string) $this->data[$key]);
    }

    /**
     * A value constrained to an allowlist; anything else returns the default.
     *
     * @param list<string> $allowed
     */
    public function enum(string $key, array $allowed, string $default = ''): string
    {
        $value = $this->text($key, $default);

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
