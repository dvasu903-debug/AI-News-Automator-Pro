<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * PSR-3-shaped logging contract, defined locally for the same standalone
 * reason as ContainerInterface. Monitoring module will provide the
 * concrete implementation backed by a dedicated database table with
 * proper indexing and rotation, rather than the flat wp_options array
 * used in the previous version of this plugin.
 */
interface LoggerInterface
{
    public function emergency(string $message, array $context = []): void;

    public function alert(string $message, array $context = []): void;

    public function critical(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function notice(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function debug(string $message, array $context = []): void;

    /**
     * @param string $level One of: emergency, alert, critical, error, warning, notice, info, debug.
     */
    public function log(string $level, string $message, array $context = []): void;
}
