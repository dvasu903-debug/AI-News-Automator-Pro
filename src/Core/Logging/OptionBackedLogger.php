<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Logging;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Support\CorrelationContext;

/**
 * Default LoggerInterface implementation, backed by a single rotating
 * wp_options entry.
 *
 * Every entry carries: timestamp, level, interpolated message, the raw
 * structured context array (preserved, not just interpolated into the
 * string, so downstream tooling can filter/aggregate on context fields),
 * and the current correlation ID. Debug-level entries are suppressed in
 * production to avoid noise and storage churn, while always being kept
 * in development.
 *
 * Still a fully-working implementation, and still explicitly the piece
 * Module 14 (Monitoring) will supersede with a DatabaseLogger for
 * queryable-at-volume logs — the swap is a one-line container rebinding
 * because everything depends on LoggerInterface, not this class.
 */
final class OptionBackedLogger implements LoggerInterface
{
    private const OPTION_KEY = 'ai_news_automator_log';
    private const MAX_ENTRIES = 200;

    public function __construct(
        private readonly CorrelationContext $correlation,
        private readonly Environment $environment,
    ) {
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency->value, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::Alert->value, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical->value, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error->value, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning->value, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::Notice->value, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info->value, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug->value, $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $logLevel = LogLevel::tryFrom($level);

        if ($logLevel === null) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid log level "%s". Must be one of: %s',
                $level,
                implode(', ', array_map(static fn (LogLevel $l): string => $l->value, LogLevel::cases()))
            ));
        }

        // Suppress debug noise in production, but never lose it in development.
        if ($logLevel === LogLevel::Debug && $this->environment->isProduction()) {
            return;
        }

        $entries = get_option(self::OPTION_KEY, []);

        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'time'           => current_time('mysql'),
            'level'          => $logLevel->value,
            'message'        => $this->interpolate($message, $context),
            'context'        => $context,
            'correlation_id' => $this->correlation->id(),
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        update_option(self::OPTION_KEY, $entries, false);

        if ($this->shouldMirrorToErrorLog($logLevel)) {
            error_log(sprintf(
                '[AI News Automator][%s][%s] %s',
                strtoupper($logLevel->value),
                $this->correlation->id(),
                $this->interpolate($message, $context)
            ));
        }
    }

    /**
     * @return list<array{time: string, level: string, message: string, context: array<string, mixed>, correlation_id: string}>
     */
    public function recent(int $limit = 50): array
    {
        $entries = get_option(self::OPTION_KEY, []);

        if (!is_array($entries)) {
            return [];
        }

        return array_slice(array_reverse($entries), 0, $limit);
    }

    private function shouldMirrorToErrorLog(LogLevel $level): bool
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }

        return $level->isAtLeastAsSevereAs(LogLevel::Error);
    }

    /**
     * PSR-3 interpolation: replace {placeholder} tokens with context values.
     */
    private function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            } elseif (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = wp_json_encode($value) ?: '[unserializable]';
            } else {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
