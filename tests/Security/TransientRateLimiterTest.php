<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\RateLimit\TransientRateLimiter;
use PHPUnit\Framework\TestCase;

final class TransientRateLimiterTest extends TestCase
{
    private TransientRateLimiter $limiter;

    protected function setUp(): void
    {
        $GLOBALS['__ana_test_transients'] = [];
        $this->limiter = new TransientRateLimiter();
    }

    public function test_allows_up_to_limit_then_blocks(): void
    {
        $key = 'action:user_1';

        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($this->limiter->hit($key, 3, 60)->allowed, "Hit {$i} should be allowed.");
        }

        // 4th hit exceeds the limit of 3.
        $this->assertFalse($this->limiter->hit($key, 3, 60)->allowed);
    }

    public function test_remaining_decrements(): void
    {
        $key = 'k';
        $this->assertSame(4, $this->limiter->hit($key, 5, 60)->remaining);
        $this->assertSame(3, $this->limiter->hit($key, 5, 60)->remaining);
    }

    public function test_reset_clears_counter(): void
    {
        $key = 'k';
        $this->limiter->hit($key, 1, 60);
        $this->assertFalse($this->limiter->hit($key, 1, 60)->allowed);

        $this->limiter->reset($key);
        $this->assertTrue($this->limiter->hit($key, 1, 60)->allowed);
    }

    public function test_separate_keys_have_separate_counters(): void
    {
        $this->limiter->hit('a', 1, 60);
        // 'b' is independent, so still allowed.
        $this->assertTrue($this->limiter->hit('b', 1, 60)->allowed);
    }

    public function test_check_does_not_consume(): void
    {
        $key = 'k';
        $this->limiter->check($key, 2, 60);
        $this->limiter->check($key, 2, 60);
        // check() didn't increment, so a real hit is still allowed.
        $this->assertTrue($this->limiter->hit($key, 2, 60)->allowed);
    }
}
