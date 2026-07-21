<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\RestApi;

use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\RestControllerInterface;

/**
 * Base class for every module's REST controller. Handles the
 * boilerplate every endpoint needs — namespace resolution, standardized
 * success/error response shapes, and a reusable capability-based
 * permission callback — so concrete controllers only implement
 * registerRoutes() and their own callback methods.
 *
 * Note: capability checks here use current_user_can() directly. Module
 * 2 (Security) will introduce a CapabilityGate with support for finer-
 * grained, filterable permission logic; when that lands, requireCapability()
 * will delegate to it instead, and no controller subclass will need to
 * change, since they only ever call requireCapability(), never
 * current_user_can() directly.
 */
abstract class AbstractRestController implements RestControllerInterface
{
    public function __construct(private readonly ConfigRepositoryInterface $config)
    {
    }

    /**
     * The REST namespace every route in this controller is registered
     * under, e.g. "ai-news-automator/v1". Sourced from config so it's
     * defined in exactly one place (config-defaults.php) rather than
     * repeated as a string literal in every controller.
     */
    protected function namespace(): string
    {
        return (string) $this->config->get('rest.namespace', 'ai-news-automator/v1');
    }

    /**
     * @param mixed $data
     */
    protected function success(mixed $data, int $status = 200): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], $status);
    }

    protected function error(string $code, string $message, int $status = 400): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * Returns a permission_callback closure that requires the current
     * user to hold the given WordPress capability.
     *
     * @return callable(): bool
     */
    protected function requireCapability(string $capability): callable
    {
        return static fn (): bool => current_user_can($capability);
    }
}
