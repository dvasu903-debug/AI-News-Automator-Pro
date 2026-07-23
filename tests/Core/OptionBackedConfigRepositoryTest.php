<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Core;

use AINewsAutomator\Core\Config\OptionBackedConfigRepository;
use PHPUnit\Framework\TestCase;

final class OptionBackedConfigRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_options'] = [];
    }

    public function test_get_returns_default_value(): void
    {
        $repo = new OptionBackedConfigRepository([
            'logging' => ['max_entries' => 200],
        ]);

        $this->assertSame(200, $repo->get('logging.max_entries'));
    }

    public function test_get_returns_fallback_for_unknown_key(): void
    {
        $repo = new OptionBackedConfigRepository([]);

        $this->assertSame('fallback', $repo->get('nothing.here', 'fallback'));
    }

    public function test_set_overrides_a_default_value(): void
    {
        $repo = new OptionBackedConfigRepository([
            'logging' => ['max_entries' => 200],
        ]);

        $repo->set('logging.max_entries', 500);

        $this->assertSame(500, $repo->get('logging.max_entries'));
    }

    public function test_set_persists_across_new_repository_instances(): void
    {
        $defaults = ['rest' => ['namespace' => 'default/v1']];

        $first = new OptionBackedConfigRepository($defaults);
        $first->set('rest.namespace', 'custom/v2');

        $second = new OptionBackedConfigRepository($defaults);

        $this->assertSame('custom/v2', $second->get('rest.namespace'));
    }

    public function test_set_does_not_mutate_the_default_for_unrelated_keys(): void
    {
        $defaults = [
            'a' => ['one' => 1, 'two' => 2],
        ];

        $repo = new OptionBackedConfigRepository($defaults);
        $repo->set('a.one', 999);

        $this->assertSame(999, $repo->get('a.one'));
        $this->assertSame(2, $repo->get('a.two'));
    }

    public function test_has_reflects_merged_state(): void
    {
        $repo = new OptionBackedConfigRepository(['x' => ['y' => 'z']]);

        $this->assertTrue($repo->has('x.y'));
        $this->assertFalse($repo->has('x.does-not-exist'));
    }

    public function test_all_returns_full_merged_tree(): void
    {
        $repo = new OptionBackedConfigRepository(['a' => 1, 'b' => 2]);
        $repo->set('c', 3);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $repo->all());
    }
}
