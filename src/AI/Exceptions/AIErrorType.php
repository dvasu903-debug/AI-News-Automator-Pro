<?php

declare(strict_types=1);

namespace AINewsAutomator\AI\Exceptions;

/**
 * Classifies why an AI provider call failed, so retry logic can
 * differentiate "try again" from "this will never succeed without human
 * intervention." Every AIException carries one of these — RetryExecutor
 * consults isRetryable() rather than retrying every failure indiscriminately.
 */
enum AIErrorType: string
{
    case Validation      = 'validation';       // bad request shape — non-retryable, fix the request
    case Authentication  = 'authentication';   // bad/expired API key — non-retryable, fix the credential
    case RateLimited     = 'rate_limited';     // 429 with retry-after — retryable with backoff
    case Quota           = 'quota';            // billing/quota exhausted — non-retryable within this window
    case ProviderOutage  = 'provider_outage';  // 5xx/timeout/connection failure — retryable
    case UnsupportedCapability = 'unsupported_capability'; // provider/model can't do this — non-retryable
    case Unknown         = 'unknown';          // unclassified — non-retryable by default (safe default)

    /**
     * Whether RetryExecutor should attempt this call again. Deliberately
     * conservative: only the two genuinely transient categories retry.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ProviderOutage => true,
            default => false,
        };
    }
}
