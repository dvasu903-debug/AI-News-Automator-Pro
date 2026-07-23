<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Config\Environment;
use AINewsAutomator\Core\Support\CorrelationContext;
use AINewsAutomator\Core\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function test_uuid_v4_has_correct_shape(): void
    {
        $uuid = Uuid::v4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function test_uuid_v4_is_unique_across_calls(): void
    {
        $this->assertNotSame(Uuid::v4(), Uuid::v4());
    }

    public function test_correlation_context_returns_stable_id_until_renewed(): void
    {
        $ctx = new CorrelationContext();
        $first = $ctx->id();

        $this->assertSame($first, $ctx->id());

        $renewed = $ctx->renew();
        $this->assertNotSame($first, $renewed);
        $this->assertSame($renewed, $ctx->id());
    }

    public function test_correlation_context_adopts_external_id(): void
    {
        $ctx = new CorrelationContext();
        $ctx->adopt('external-request-id');

        $this->assertSame('external-request-id', $ctx->id());
    }

    public function test_environment_detect_maps_wordpress_types(): void
    {
        $GLOBALS['__ana_test_env'] = 'development';
        $this->assertTrue(Environment::detect()->isDevelopment());

        $GLOBALS['__ana_test_env'] = 'production';
        $this->assertTrue(Environment::detect()->isProduction());

        // Unknown types fall back to production (the safe default).
        $GLOBALS['__ana_test_env'] = 'something-weird';
        $this->assertTrue(Environment::detect()->isProduction());

        unset($GLOBALS['__ana_test_env']);
    }
}
