<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Prompt;

use AINewsAutomator\AI\Exceptions\AIValidationException;

/**
 * Renders a PromptTemplate's text with variables substituted, validating
 * that every variable the template's schema declares as required is
 * actually supplied before rendering — catching a missing variable
 * before it silently becomes a literal "{{missing}}" in a live prompt.
 */
final class PromptRenderer
{
    /**
     * @param array<string, string> $variables
     *
     * @throws AIValidationException
     */
    public function render(PromptTemplate $template, array $variables): string
    {
        $required = $template->variablesSchema['required'] ?? [];
        $missing = array_diff((array) $required, array_keys($variables));

        if ($missing !== []) {
            $errors = [];
            foreach ($missing as $key) {
                $errors[(string) $key] = 'Required template variable not supplied.';
            }
            throw new AIValidationException($errors, sprintf('Missing variables for template "%s".', $template->name));
        }

        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        return strtr($template->templateText, $replacements);
    }
}
