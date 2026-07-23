<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Storage;

use AINewsAutomator\Storage\Exceptions\ValidationException;
use AINewsAutomator\Storage\Logging\LogLevelValidator;
use PHPUnit\Framework\TestCase;

final class LogLevelValidatorTest extends TestCase
{
    public function test_valid_levels_pass(): void
    {
        foreach (LogLevelValidator::VALID_LEVELS as $level) {
            $this->assertTrue(LogLevelValidator::isValid($level));
        }
    }

    public function test_invalid_level_fails(): void
    {
        $this->assertFalse(LogLevelValidator::isValid('trace'));
        $this->assertFalse(LogLevelValidator::isValid(''));
    }

    public function test_assert_valid_throws_for_bad_level(): void
    {
        $this->expectException(ValidationException::class);
        LogLevelValidator::assertValid('not-a-level');
    }

    public function test_assert_valid_does_not_throw_for_good_level(): void
    {
        LogLevelValidator::assertValid('warning');
        $this->assertTrue(true);
    }
}
