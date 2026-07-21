<?php

declare(strict_types=1);

namespace AINewsAutomator\Security;

use AINewsAutomator\Core\AbstractServiceProvider;
use AINewsAutomator\Core\Container;
use AINewsAutomator\Core\Contracts\ContainerInterface;
use AINewsAutomator\Core\Contracts\SecretsProviderInterface;
use AINewsAutomator\Core\Contracts\ActivatableInterface;
use AINewsAutomator\Core\Settings\SettingsRegistry;
use AINewsAutomator\Security\Admin\SecuritySettingsPage;
use AINewsAutomator\Security\Audit\AuditLogger;
use AINewsAutomator\Security\Audit\OptionBackedAuditRepository;
use AINewsAutomator\Security\Authorization\CapabilityGate;
use AINewsAutomator\Security\Authorization\CapabilityInstaller;
use AINewsAutomator\Security\Authorization\DefaultCapabilityPolicy;
use AINewsAutomator\Security\Authorization\PolicyEngine;
use AINewsAutomator\Security\Contracts\AuditLoggerInterface;
use AINewsAutomator\Security\Contracts\AuditLogRepositoryInterface;
use AINewsAutomator\Security\Contracts\CapabilityGateInterface;
use AINewsAutomator\Security\Contracts\EncryptorInterface;
use AINewsAutomator\Security\Contracts\NonceManagerInterface;
use AINewsAutomator\Security\Contracts\PolicyInterface;
use AINewsAutomator\Security\Contracts\RateLimiterInterface;
use AINewsAutomator\Security\Contracts\RequestValidatorInterface;
use AINewsAutomator\Security\Contracts\SecurityMetricsInterface;
use AINewsAutomator\Security\Contracts\UrlGuardInterface;
use AINewsAutomator\Security\Contracts\WebhookSignatureVerifierInterface;
use AINewsAutomator\Security\Http\OutboundHttpValidator;
use AINewsAutomator\Security\Http\UrlGuard;
use AINewsAutomator\Security\Metrics\SecurityMetrics;
use AINewsAutomator\Security\RateLimit\TransientRateLimiter;
use AINewsAutomator\Security\Request\NonceManager;
use AINewsAutomator\Security\Request\RequestValidator;
use AINewsAutomator\Security\Rest\RestSecurityMiddleware;
use AINewsAutomator\Security\Secrets\CredentialVault;
use AINewsAutomator\Security\Secrets\KeyProvider;
use AINewsAutomator\Security\Secrets\SodiumEncryptor;
use AINewsAutomator\Security\Threat\ThreatDetector;
use AINewsAutomator\Security\Webhook\HmacWebhookSignatureVerifier;

/**
 * The Security module's single service provider. Binds every Security
 * interface to its concrete implementation (so all future modules consume
 * Security through interfaces), registers the default authorization policy
 * under the "security.policies" tag, wires the PolicyEngine to collect all
 * tagged policies, subscribes the ThreatDetector to the event stream, and
 * registers the security settings/diagnostics page. Implements
 * ActivatableInterface to install/remove custom capabilities.
 *
 * Manifest ordering: this provider must be registered immediately after
 * CoreServiceProvider so its capabilities and gate exist before any later
 * module needs them.
 */
final class SecurityServiceProvider extends AbstractServiceProvider implements ActivatableInterface
{
    public function register(ContainerInterface $container): void
    {
        $this->registerSecrets($container);
        $this->registerAudit($container);
        $this->registerMetrics($container);
        $this->registerAuthorization($container);
        $this->registerRequest($container);
        $this->registerHttp($container);
        $this->registerWebhooks($container);
        $this->registerThreatDetection($container);
    }

    private function registerSecrets(ContainerInterface $container): void
    {
        $container->singleton(KeyProvider::class, static fn (): KeyProvider => new KeyProvider('v1'));

        $container->singleton(
            EncryptorInterface::class,
            static fn (ContainerInterface $c): EncryptorInterface
                => new SodiumEncryptor($c->get(KeyProvider::class))
        );

        // CredentialVault fills Core's SecretsProviderInterface seam.
        $container->singleton(
            SecretsProviderInterface::class,
            static fn (ContainerInterface $c): SecretsProviderInterface => new CredentialVault(
                $c->get(EncryptorInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\LoggerInterface::class),
                $c->get(SecurityMetricsInterface::class)
            )
        );

        // Also expose the concrete vault for callers needing metadata methods.
        $container->singleton(
            CredentialVault::class,
            static fn (ContainerInterface $c): CredentialVault
                => $c->get(SecretsProviderInterface::class)
        );
    }

    private function registerAudit(ContainerInterface $container): void
    {
        $container->singleton(
            AuditLogRepositoryInterface::class,
            static fn (): AuditLogRepositoryInterface => new OptionBackedAuditRepository()
        );

        $container->singleton(
            AuditLoggerInterface::class,
            static fn (ContainerInterface $c): AuditLoggerInterface => new AuditLogger(
                $c->get(AuditLogRepositoryInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\LoggerInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class),
                $c->get(\AINewsAutomator\Core\Support\CorrelationContext::class),
                $c->get(\AINewsAutomator\Security\Request\RequestContext::class)
            )
        );

        // Concrete alias so classes needing the convenience log() method resolve it.
        $container->singleton(
            AuditLogger::class,
            static fn (ContainerInterface $c): AuditLogger => $c->get(AuditLoggerInterface::class)
        );
    }

    private function registerMetrics(ContainerInterface $container): void
    {
        $container->singleton(
            SecurityMetricsInterface::class,
            static fn (): SecurityMetricsInterface => new SecurityMetrics()
        );
    }

    private function registerAuthorization(ContainerInterface $container): void
    {
        // Register the default policy and tag it so the engine collects it.
        $container->singleton(DefaultCapabilityPolicy::class, static fn (): DefaultCapabilityPolicy => new DefaultCapabilityPolicy());
        $container->tag(DefaultCapabilityPolicy::class, 'security.policies');

        $container->singleton(
            PolicyEngine::class,
            static function (ContainerInterface $c): PolicyEngine {
                /** @var list<PolicyInterface> $policies */
                $policies = $c instanceof Container ? $c->tagged('security.policies') : [];
                return new PolicyEngine($policies);
            }
        );

        $container->singleton(
            CapabilityGateInterface::class,
            static fn (ContainerInterface $c): CapabilityGateInterface => new CapabilityGate(
                $c->get(PolicyEngine::class),
                $c->get(AuditLogger::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class),
                $c->get(SecurityMetricsInterface::class),
                $c->get(\AINewsAutomator\Security\Request\RequestContext::class)
            )
        );
    }

    private function registerRequest(ContainerInterface $container): void
    {
        $container->singleton(NonceManagerInterface::class, static fn (): NonceManagerInterface => new NonceManager());
        $container->singleton(RateLimiterInterface::class, static fn (): RateLimiterInterface => new TransientRateLimiter());

        $container->singleton(
            RequestValidatorInterface::class,
            static fn (ContainerInterface $c): RequestValidatorInterface => new RequestValidator(
                $c->get(NonceManagerInterface::class),
                $c->get(CapabilityGateInterface::class),
                $c->get(RateLimiterInterface::class),
                $c->get(SecurityMetricsInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class),
                $c->get(\AINewsAutomator\Security\Request\RequestContext::class)
            )
        );

        $container->singleton(
            RestSecurityMiddleware::class,
            static fn (ContainerInterface $c): RestSecurityMiddleware => new RestSecurityMiddleware(
                $c->get(CapabilityGateInterface::class),
                $c->get(NonceManagerInterface::class),
                $c->get(RateLimiterInterface::class),
                $c->get(SecurityMetricsInterface::class),
                $c->get(\AINewsAutomator\Security\Request\RequestContext::class)
            )
        );
    }

    private function registerHttp(ContainerInterface $container): void
    {
        $container->singleton(UrlGuardInterface::class, static fn (): UrlGuardInterface => new UrlGuard());

        $container->singleton(
            OutboundHttpValidator::class,
            static fn (ContainerInterface $c): OutboundHttpValidator => new OutboundHttpValidator(
                $c->get(UrlGuardInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\LoggerInterface::class)
            )
        );
    }

    private function registerWebhooks(ContainerInterface $container): void
    {
        // HMAC is the default binding; Ed25519 is available by its class name.
        $container->singleton(
            WebhookSignatureVerifierInterface::class,
            static fn (): WebhookSignatureVerifierInterface => new HmacWebhookSignatureVerifier()
        );
    }

    private function registerThreatDetection(ContainerInterface $container): void
    {
        $container->singleton(
            ThreatDetector::class,
            static fn (ContainerInterface $c): ThreatDetector => new ThreatDetector(
                $c->get(\AINewsAutomator\Core\Contracts\EventDispatcherInterface::class),
                $c->get(\AINewsAutomator\Core\Events\EventMetadataFactory::class),
                $c->get(\AINewsAutomator\Core\Contracts\LoggerInterface::class)
            )
        );

        // The security settings page binding (needs extra deps beyond the
        // parent's config+logger). Bound in register() so it exists before
        // Core's boot phase iterates the SettingsRegistry.
        $container->singleton(
            SecuritySettingsPage::class,
            static fn (ContainerInterface $c): SecuritySettingsPage => new SecuritySettingsPage(
                $c->get(\AINewsAutomator\Core\Contracts\ConfigRepositoryInterface::class),
                $c->get(\AINewsAutomator\Core\Contracts\LoggerInterface::class),
                new \AINewsAutomator\Security\Health\SecurityHealthCheck($c->get(EncryptorInterface::class)),
                $c->get(SecurityMetricsInterface::class),
                $c->get(AuditLogger::class)
            )
        );
    }

    public function boot(ContainerInterface $container): void
    {
        // Subscribe threat detection to the event stream.
        $container->get(ThreatDetector::class)->subscribe();

        // Register the security settings/diagnostics page with the registry
        // so Core's admin_menu/admin_init hooks render it.
        /** @var SettingsRegistry $settings */
        $settings = $container->get(SettingsRegistry::class);
        $settings->register(SecuritySettingsPage::class);
    }

    public function activate(): void
    {
        (new CapabilityInstaller())->install();
    }

    public function deactivate(): void
    {
        // Capabilities are intentionally left in place on deactivation
        // (reversible). They are only removed on full uninstall.
    }

    public function uninstall(): void
    {
        (new CapabilityInstaller())->uninstall();

        // Wipe secrets and audit log on uninstall.
        delete_option('ai_news_automator_secrets');
        delete_option('ai_news_automator_audit_log');
        delete_option('ai_news_automator_security_metrics');
    }
}
