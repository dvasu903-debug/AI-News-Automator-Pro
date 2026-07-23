<?php
/**
 * ContentGeneratorInterface implementation backed by AIManager. This is
 * the one place in Publishing where untrusted, provider-generated
 * output first enters the system — see ADR-0019 for the full trust-
 * boundary rationale. Two sanitization steps happen here, never
 * downstream:
 *
 *   1. wp_kses_post() on the AI-generated body, immediately after the
 *      provider response is decoded — before the string is returned to
 *      any caller.
 *   2. esc_html() on each citation's already-formatted text when the
 *      deterministic "Sources" section is appended — citations are
 *      never shown to the AI and never AI-generated, but they DO
 *      originate from externally-fetched source text (Research\Entities\
 *      Citation::citationText), so splicing them in unescaped would
 *      reopen an injection point immediately after closing the AI one.
 *
 * Residual risk (documented, not eliminated): wp_kses_post() stops
 * markup/XSS, not content-level prompt injection (e.g. persuasive but
 * misleading generated text). The existing human approval_gate before
 * publish is the mitigation for that — a reviewer sees a rendered
 * preview, not raw markup, before anything goes live.
 *
 * @package AINewsAutomator\Publishing
 */

declare(strict_types=1);

namespace AINewsAutomator\Publishing\Services;

use AINewsAutomator\AI\Contracts\PromptTemplateRepositoryInterface;
use AINewsAutomator\AI\DTO\ChatRequest;
use AINewsAutomator\AI\DTO\Message;
use AINewsAutomator\AI\Manager\AIManager;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Publishing\Contracts\ContentGeneratorInterface;
use AINewsAutomator\Publishing\DTO\GeneratedContent;
use AINewsAutomator\Publishing\Exceptions\ContentGenerationException;
use AINewsAutomator\Research\DTO\ClaimSummary;
use AINewsAutomator\Research\DTO\ResearchSummary;

final class AiContentGenerator implements ContentGeneratorInterface
{
    /**
     * No default-seeded template exists (approved Decision 4) — this
     * name is only a lookup key, not a fallback prompt.
     */
    private const TEMPLATE_NAME = 'publishing.article_generation';

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'body'  => ['type' => 'string'],
        ],
        'required' => ['title', 'body'],
    ];

    public function __construct(
        private readonly AIManager $ai,
        private readonly PromptTemplateRepositoryInterface $templates,
        private readonly ConfigRepositoryInterface $config,
    ) {
    }

    public function generate(ResearchSummary $summary): GeneratedContent
    {
        $template = $this->templates->getLatest(self::TEMPLATE_NAME);

        if (null === $template) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- self::TEMPLATE_NAME is a fixed, compile-time constant used to build an internal exception message only, never echoed as HTML.
            throw ContentGenerationException::noTemplateConfigured(self::TEMPLATE_NAME);
        }

        $request = new ChatRequest(
            messages: [
                Message::system($template->templateText),
                Message::user($this->buildBriefing($summary)),
            ],
            model: (string) $this->config->get('publishing.generation.model', 'claude-sonnet-5'),
            maxTokens: 4096,
            responseSchema: self::RESPONSE_SCHEMA,
            correlationId: $summary->correlationId,
        );

        // AIException propagates to the caller (GenerateAction) uncaught
        // — it carries its own retryability classification, which the
        // caller bridges into a WorkflowStepException. Swallowing it
        // here would lose that classification.
        $response = $this->ai->chat($request);

        $decoded = json_decode($response->content, true);

        if (
            !is_array($decoded)
            || !isset($decoded['title'], $decoded['body'])
            || !is_string($decoded['title'])
            || !is_string($decoded['body'])
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- self::TEMPLATE_NAME is a fixed, compile-time constant used to build an internal exception message only, never echoed as HTML.
            throw ContentGenerationException::malformedResponse(self::TEMPLATE_NAME);
        }

        $title = wp_strip_all_tags($decoded['title']);
        $body = wp_kses_post($decoded['body']) . $this->renderCitations($summary);

        return new GeneratedContent($title, $body);
    }

    /**
     * The AI only ever sees claim statements — never citationText, never
     * a URL (approved Decision 2). Citations are appended deterministically
     * afterward, in renderCitations().
     */
    private function buildBriefing(ResearchSummary $summary): string
    {
        $statements = array_map(
            static fn (ClaimSummary $claimSummary): string => $claimSummary->claim->statement,
            $summary->claims
        );

        return sprintf(
            "Topic: %s\n\nResearched claims to draw the article from:\n- %s",
            $summary->topic,
            implode("\n- ", $statements)
        );
    }

    private function renderCitations(ResearchSummary $summary): string
    {
        $items = [];

        foreach ($summary->claims as $claimSummary) {
            foreach ($claimSummary->citations as $citation) {
                $items[] = '<li>' . esc_html($citation->citationText) . '</li>';
            }
        }

        if ([] === $items) {
            return '';
        }

        return "\n<h2>Sources</h2>\n<ul>" . implode('', $items) . '</ul>';
    }
}
