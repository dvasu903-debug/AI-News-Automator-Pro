<?php

declare(strict_types=1);

namespace AINewsAutomator\Core;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use AINewsAutomator\Core\Config\PluginConfig;
use AINewsAutomator\Core\Contracts\ConfigRepositoryInterface;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\EventDispatcherInterface;
use AINewsAutomator\Core\Contracts\LoggerInterface;
use AINewsAutomator\Core\Events\EventDispatcher;
use AINewsAutomator\Core\Events\EventMetadataFactory;
use AINewsAutomator\Core\Logging\OptionBackedLogger;
use AINewsAutomator\Core\RestApi\RestApiRegistry;
use AINewsAutomator\Core\Settings\SettingsRegistry;
use AINewsAutomator\Core\Support\CorrelationContext;

/**
 * The Core module's service provider. Registers every foundational
 * binding later modules depend on, then boots the admin-facing registries.
 *
 * PluginConfig is NOT registered here — it's registered by PluginFactory
 * before the kernel boots, because the factory is what knows the plugin
 * file path. Core depends on it being already present.
 */
final class CoreServiceProvider extends AbstractServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // One correlation scope per request, shared by logger and events.
        $container->singleton(
            CorrelationContext::class,
            static fn (): CorrelationContext => new CorrelationContext()
        );

        // Expose the environment (derived from PluginConfig) as its own
        // binding so classes can depend on Environment directly.
        $container->singleton(
            Environment::class,
            static fn (ContainerInterface $c): Environment
                => $c->get(PluginConfig::class)->environment()
        );

        $container->singleton(
            LoggerInterface::class,
            static fn (ContainerInterface $c): LoggerInterface => new OptionBackedLogger(
                $c->get(CorrelationContext::class),
                $c->get(Environment::class)
            )
        );

        $container->singleton(
            ConfigRepositoryInterface::class,
            static function (): ConfigRepositoryInterface {
                $defaults = require __DIR__ . '/Config/config-defaults.php';
                return new OptionBackedConfigRepository($defaults);
            }
        );

        $container->singleton(
            EventDispatcherInterface::class,
            static fn (): EventDispatcherInterface => new EventDispatcher()
        );

        $container->singleton(
            EventMetadataFactory::class,
            static fn (ContainerInterface $c): EventMetadataFactory
                => new EventMetadataFactory($c->get(CorrelationContext::class))
        );

        $container->singleton(
            RestApiRegistry::class,
            static fn (): RestApiRegistry => new RestApiRegistry()
        );

        $container->singleton(
            SettingsRegistry::class,
            static fn (): SettingsRegistry => new SettingsRegistry()
        );
    }

    public function boot(ContainerInterface $container): void
    {
        $this->bootTextDomain($container);
        $this->bootTopLevelAdminMenu();
        $this->bootRestApi($container);
        $this->bootSettingsPages($container);
    }

    private function bootTextDomain(ContainerInterface $container): void
    {
        add_action('init', static function () use ($container): void {
            $config = $container->get(PluginConfig::class);
            load_plugin_textdomain(
                $config->textDomain(),
                false,
                dirname(plugin_basename($config->pluginFile())) . '/languages'
            );
        });
    }

    private function bootTopLevelAdminMenu(): void
    {
        add_action('admin_menu', static function (): void {
            add_menu_page(
                'AI News Automator',
                'AI News Automator',
                'manage_options',
                'ai-news-automator',
                static function (): void {
                    echo '<div class="wrap"><h1>AI News Automator</h1><p>' .
                        esc_html__('Select a section from the submenu.', 'ai-news-automator') .
                        '</p></div>';
                },
                'dashicons-admin-network',
                26
            );
        }, 9);
    }

    private function bootRestApi(ContainerInterface $container): void
    {
        add_action('rest_api_init', static function () use ($container): void {
            /** @var RestApiRegistry $registry */
            $registry = $container->get(RestApiRegistry::class);

            foreach ($registry->all() as $controllerClass) {
                $controller = $container->get($controllerClass);
                $controller->registerRoutes();
            }
        });
    }

    private function bootSettingsPages(ContainerInterface $container): void
    {
        add_action('admin_menu', static function () use ($container): void {
            /** @var SettingsRegistry $registry */
            $registry = $container->get(SettingsRegistry::class);

            foreach ($registry->all() as $pageClass) {
                $page = $container->get($pageClass);
                $page->registerMenu();
            }
        }, 10);

        add_action('admin_init', static function () use ($container): void {
            /** @var SettingsRegistry $registry */
            $registry = $container->get(SettingsRegistry::class);

            foreach ($registry->all() as $pageClass) {
                $page = $container->get($pageClass);
                $page->registerSettings();
            }
        });
    }
}
