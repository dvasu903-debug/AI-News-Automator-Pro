<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\Contracts;

/**
 * Contract every module's REST controller implements. Registered
 * controller classes are collected by Core\RestApi\RestApiRegistry and
 * resolved through the container (so a controller's constructor
 * dependencies are autowired) when WordPress fires rest_api_init.
 */
interface RestControllerInterface
{
    /**
     * Called during rest_api_init. Implementations call
     * register_rest_route() for each endpoint they own.
     */
    public function registerRoutes(): void;
}
