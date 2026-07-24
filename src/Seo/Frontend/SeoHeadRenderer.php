<?php
/**
 * The ONLY class in this module that echoes anything. Hooked to
 * wp_head — the first public, anonymous-visitor-facing render path in
 * this codebase (see the module design doc's Security/Performance
 * sections). Every SeoTagData field is escaped here, at the exact
 * point of output, in the context it's actually used — never trusting
 * any upstream sanitization (ana_draft_seo's fields were sanitized once
 * already, for the HTML-body context, inside AiContentGenerator — that
 * does not make them safe for an attribute or a JSON string context).
 *
 * Contains no tag-construction logic of its own — MetaTagBuilder (via
 * SeoProviderInterface) is solely responsible for that split.
 *
 * @package AINewsAutomator\Seo
 */

declare(strict_types=1);

namespace AINewsAutomator\Seo\Frontend;

use AINewsAutomator\Seo\Contracts\SeoProviderInterface;
use AINewsAutomator\Seo\DTO\SeoTagData;

final class SeoHeadRenderer
{
    public function __construct(private readonly SeoProviderInterface $provider)
    {
    }

    /**
     * The wp_head-bound entry point — resolves the current singular
     * post from WordPress's own template globals.
     */
    public function render(): void
    {
        if (!is_singular()) {
            return;
        }

        $postId = get_the_ID();

        if (!is_int($postId) || $postId <= 0) {
            return;
        }

        $this->renderFor($postId);
    }

    /**
     * The testable entry point — takes an explicit post id rather than
     * reading WordPress's current-query globals.
     */
    public function renderFor(int $postId): void
    {
        $data = $this->provider->provide($postId);

        if (null === $data) {
            return;
        }

        echo '<link rel="canonical" href="' . esc_url($data->canonicalUrl) . "\" />\n";

        if (null !== $data->robotsDirectives && '' !== $data->robotsDirectives) {
            echo '<meta name="robots" content="' . esc_attr($data->robotsDirectives) . "\" />\n";
        }

        foreach ($data->openGraph as $property => $content) {
            echo '<meta property="' . esc_attr($property) . '" content="' . esc_attr($content) . "\" />\n";
        }

        foreach ($data->twitterCard as $name => $content) {
            echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($content) . "\" />\n";
        }

        if ([] !== $data->jsonLd) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- encodeJsonLd() below IS the escaping function for this context (wp_json_encode with JSON_HEX_TAG/JSON_HEX_AMP); PHPCS's sniff only recognizes calls to its own known esc_*/wp_json_encode allowlist directly inline, not through a private wrapper method — see that method's docblock and SeoHeadRendererTest's escaping-regression cases for the executed proof.
            echo '<script type="application/ld+json">' . $this->encodeJsonLd($data->jsonLd) . "</script>\n";
        }
    }

    /**
     * @param array<string, mixed> $jsonLd
     */
    private function encodeJsonLd(array $jsonLd): string
    {
        // JSON_HEX_TAG converts every "<"/">" to a \u escape — the
        // standard, robust technique for embedding JSON inside an HTML
        // <script> block, since it eliminates any "</script>"
        // tag-breakout risk regardless of what a title/description
        // string contains, rather than relying on slash-escaping alone.
        // JSON_HEX_AMP additionally neutralizes "&"-based entity tricks.
        $json = wp_json_encode($jsonLd, \JSON_HEX_TAG | \JSON_HEX_AMP);

        return false !== $json ? $json : '{}';
    }
}
