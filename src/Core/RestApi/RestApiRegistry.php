<?php

declare(strict_types=1);

namespace AINewsAutomator\Core\RestApi;

/**
 * Plain registry of REST controller class-strings, populated by
 * modules during their register() phase. Deliberately not using
 * container tagging (see ARCHITECTURE_PLAN.md §2.3) — tagging is
 * scoped to land with Module 5 (Sources), and a plain registry is the
 * simplest thing that works for the "collect many, resolve all"
 * pattern until then.
 */
final class RestApiRegistry
{
    /** @var list<class-string<\AINewsAutomator\Core\Contracts\RestControllerInterface>> */
    private array $controllers = [];

    /**
     * @param class-string<\AINewsAutomator\Core\Contracts\RestControllerInterface> $controllerClass
     */
    public function register(string $controllerClass): void
    {
        if (!in_array($controllerClass, $this->controllers, true)) {
            $this->controllers[] = $controllerClass;
        }
    }

    /**
     * @return list<class-string<\AINewsAutomator\Core\Contracts\RestControllerInterface>>
     */
    public function all(): array
    {
        return $this->controllers;
    }
}
