<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Security;

use AINewsAutomator\Security\Request\InputValidator;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    public function test_text_sanitizes_and_defaults(): void
    {
        $v = new InputValidator(['name' => '  hello  ']);
        $this->assertSame('hello', $v->text('name'));
        $this->assertSame('fallback', $v->text('missing', 'fallback'));
    }

    public function test_integer_coerces(): void
    {
        $v = new InputValidator(['n' => '42', 'bad' => 'abc']);
        $this->assertSame(42, $v->integer('n'));
        $this->assertSame(0, $v->integer('bad'));
        $this->assertSame(7, $v->integer('missing', 7));
    }

    public function test_boolean_parsing(): void
    {
        $v = new InputValidator(['t' => 'true', 'f' => 'false', 'one' => '1', 'zero' => '0']);
        $this->assertTrue($v->boolean('t'));
        $this->assertFalse($v->boolean('f'));
        $this->assertTrue($v->boolean('one'));
        $this->assertFalse($v->boolean('zero'));
        $this->assertTrue($v->boolean('missing', true));
    }

    public function test_enum_rejects_out_of_set(): void
    {
        $v = new InputValidator(['choice' => 'evil']);
        $this->assertSame('default', $v->enum('choice', ['a', 'b'], 'default'));

        $v2 = new InputValidator(['choice' => 'a']);
        $this->assertSame('a', $v2->enum('choice', ['a', 'b'], 'default'));
    }

    /**
     * Fuzz: arbitrary/malformed inputs must never throw — validators must
     * degrade to defaults, not error out on hostile input.
     *
     * @dataProvider fuzzProvider
     */
    public function test_getters_never_throw_on_malformed_input(mixed $value): void
    {
        $v = new InputValidator(['x' => $value]);

        // None of these should throw regardless of input shape.
        $v->text('x');
        $v->textarea('x');
        $v->integer('x');
        $v->boolean('x');
        $v->email('x');
        $v->url('x');
        $v->enum('x', ['a', 'b']);

        $this->assertTrue(true); // Reaching here means no exception was thrown.
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function fuzzProvider(): array
    {
        return [
            'array'        => [['nested' => 'array']],
            'null'         => [null],
            'int'          => [12345],
            'float'        => [3.14159],
            'bool'         => [true],
            'empty string' => [''],
            'binary'       => ["\x00\x01\x02\xff"],
            'long string'  => [str_repeat('A', 100000)],
            'html'         => ['<script>alert(1)</script>'],
            'sql-ish'      => ["'; DROP TABLE wp_posts; --"],
            'unicode'      => ['🔐🧪💥 مرحبا'],
            'newlines'     => ["line1\nline2\r\nline3"],
        ];
    }

    public function test_non_scalar_returns_default_not_error(): void
    {
        $v = new InputValidator(['x' => ['a' => 1]]);
        $this->assertSame('def', $v->text('x', 'def'));
        $this->assertSame(0, $v->integer('x'));
    }
}
