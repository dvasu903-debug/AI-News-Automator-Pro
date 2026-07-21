<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Settings;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;

/**
 * Base class for every module's settings page. A subclass supplies only
 * data — slug, titles, capability, and its SettingsSection/SettingsField
 * definitions — and inherits full WordPress Settings API wiring: menu
 * registration, form rendering, and sanitize-on-save, all field-type-aware
 * via SettingsField::sanitize().
 *
 * This is the direct fix for the old plugin's ANA_Settings god-class
 * (audit item A4/T5): each module now owns a small subclass describing
 * its own fields, with zero duplicated render/sanitize boilerplate.
 */
abstract class AbstractSettingsPage
{
    public function __construct(
        protected readonly ConfigRepositoryInterface $config,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /** Unique slug for this settings page, e.g. "ana-ai-settings". */
    abstract public function slug(): string;

    abstract public function pageTitle(): string;

    abstract public function menuTitle(): string;

    /**
     * @return list<SettingsSection>
     */
    abstract public function sections(): array;

    /**
     * Parent menu slug for add_submenu_page(). Return null to register
     * this as its own top-level menu page instead.
     */
    public function parentSlug(): ?string
    {
        return 'ai-news-automator';
    }

    public function capability(): string
    {
        return (string) $this->config->get('settings.default_capability', 'manage_options');
    }

    /**
     * The single wp_options key this page's field values are stored
     * under. Derived from the slug by default so subclasses don't need
     * to think about it, but overridable if a module wants a specific
     * option name (e.g. for backward compatibility with a prior version).
     */
    public function optionName(): string
    {
        return 'ana_' . str_replace('-', '_', $this->slug());
    }

    /**
     * Hook target for admin_menu.
     */
    final public function registerMenu(): void
    {
        $parent = $this->parentSlug();

        if ($parent === null) {
            add_menu_page(
                $this->pageTitle(),
                $this->menuTitle(),
                $this->capability(),
                $this->slug(),
                [$this, 'render']
            );
            return;
        }

        add_submenu_page(
            $parent,
            $this->pageTitle(),
            $this->menuTitle(),
            $this->capability(),
            $this->slug(),
            [$this, 'render']
        );
    }

    /**
     * Hook target for admin_init.
     */
    final public function registerSettings(): void
    {
        register_setting($this->slug() . '-group', $this->optionName(), [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);

        foreach ($this->sections() as $section) {
            add_settings_section(
                $section->key,
                $section->title,
                function () use ($section): void {
                    if ($section->description !== '') {
                        echo '<p>' . esc_html($section->description) . '</p>';
                    }
                },
                $this->slug()
            );

            foreach ($section->fields as $field) {
                add_settings_field(
                    $field->key,
                    $field->label,
                    fn (): string => $this->renderField($field),
                    $this->slug(),
                    $section->key
                );
            }
        }
    }

    final public function render(): void
    {
        if (!current_user_can($this->capability())) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->pageTitle()) . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields($this->slug() . '-group');
        do_settings_sections($this->slug());
        submit_button();
        echo '</form>';
        echo '</div>';

        $this->renderAfterForm();
    }

    /**
     * Extension point for a subclass that needs to render additional
     * content below the standard settings form — diagnostics, health
     * checks, metrics, audit trails, and the like (see
     * Security/AI/Research/Sources's settings pages). render() itself
     * is final and already owns the full page lifecycle (capability
     * check, form, sections); a subclass overrides this hook instead of
     * render(), and never needs to repeat the capability check or call
     * parent::render() — both already happened by the time this runs.
     * No-op by default.
     */
    protected function renderAfterForm(): void
    {
    }

    /**
     * WordPress sanitize_callback for register_setting(). Runs every
     * field's own SettingsField::sanitize() against the raw submitted
     * value, and logs that the settings page was updated — a lightweight
     * precursor to the full audit log Module 2 (Security) will add.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    final public function sanitize(array $input): array
    {
        $sanitized = [];

        foreach ($this->sections() as $section) {
            foreach ($section->fields as $field) {
                $sanitized[$field->key] = $field->sanitize($input[$field->key] ?? null);
            }
        }

        $this->logger->info('Settings page "{slug}" saved by user {user_id}.', [
            'slug'    => $this->slug(),
            'user_id' => get_current_user_id(),
        ]);

        return $sanitized;
    }

    /**
     * Reads the current stored value for a field, falling back to its
     * declared default if never saved.
     */
    public function get(string $fieldKey, mixed $default = null): mixed
    {
        $stored = get_option($this->optionName(), []);
        $stored = is_array($stored) ? $stored : [];

        if (array_key_exists($fieldKey, $stored)) {
            return $stored[$fieldKey];
        }

        foreach ($this->sections() as $section) {
            foreach ($section->fields as $field) {
                if ($field->key === $fieldKey) {
                    return $field->default ?? $default;
                }
            }
        }

        return $default;
    }

    private function renderField(SettingsField $field): string
    {
        $value = $this->get($field->key, $field->default);
        $name  = sprintf('%s[%s]', $this->optionName(), $field->key);
        $id    = sprintf('%s-%s', $this->slug(), $field->key);

        $html = match ($field->type) {
            SettingsField::TYPE_TEXT, SettingsField::TYPE_PASSWORD => sprintf(
                '<input type="%s" id="%s" name="%s" value="%s" class="regular-text" autocomplete="off" />',
                esc_attr($field->type),
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value)
            ),
            SettingsField::TYPE_TEXTAREA => sprintf(
                '<textarea id="%s" name="%s" rows="5" class="large-text">%s</textarea>',
                esc_attr($id),
                esc_attr($name),
                esc_textarea((string) $value)
            ),
            SettingsField::TYPE_NUMBER => sprintf(
                '<input type="number" id="%s" name="%s" value="%s" />',
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value)
            ),
            SettingsField::TYPE_CHECKBOX => sprintf(
                '<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
                esc_attr($id),
                esc_attr($name),
                checked((bool) $value, true, false),
                esc_html($field->description)
            ),
            SettingsField::TYPE_SELECT => $this->renderSelect($field, $name, $id, (string) $value),
        };

        if ($field->type !== SettingsField::TYPE_CHECKBOX && $field->description !== '') {
            $html .= sprintf('<p class="description">%s</p>', esc_html($field->description));
        }

        return $html;
    }

    private function renderSelect(SettingsField $field, string $name, string $id, string $current): string
    {
        $optionsHtml = '';

        foreach ($field->options as $optionValue => $optionLabel) {
            $optionsHtml .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string) $optionValue),
                selected($current, (string) $optionValue, false),
                esc_html($optionLabel)
            );
        }

        return sprintf('<select id="%s" name="%s">%s</select>', esc_attr($id), esc_attr($name), $optionsHtml);
    }
}
