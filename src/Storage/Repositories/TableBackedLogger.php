<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Repositories;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Storage\Contracts\LogRepositoryInterface;
use AINewsAutomator\Storage\Logging\LogLevelValidator;

/**
 * Implements Core's LoggerInterface (the frozen contract every class in
 * the plugin depends on for logging) by delegating persistence to
 * LogRepositoryInterface, i.e. `ana_logs`. StorageServiceProvider rebinds
 * Core\Contracts\LoggerInterface to this class — Core's CoreServiceProvider
 * file is never touched; the container simply resolves the interface to a
 * different concrete after Storage registers (see module README, §
 * rebinding mechanism).
 *
 * Preserves Module 1.1's behavior exactly: correlation ID on every entry,
 * debug suppressed in production, kept in development.
 */
final class TableBackedLogger implements LoggerInterface
{
    public function __construct(
        private readonly LogRepositoryInterface $repository,
        private readonly CorrelationContext $correlation,
        private readonly Environment $environment,
    ) {
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        LogLevelValidator::assertValid($level);

        if ($level === 'debug' && $this->environment->isProduction()) {
            return;
        }

        $this->repository->persist($level, $this->interpolate($message, $context), $context, $this->correlation->id());
    }

    /**
     * PSR-3 interpolation: replace {placeholder} tokens with context values.
     * Kept identical to Core's OptionBackedLogger behavior for continuity.
     *
     * @param array<string, mixed> $context
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
