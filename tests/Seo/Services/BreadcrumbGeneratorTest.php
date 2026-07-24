<?php

declare(strict_types=1);

namespace AINewsAutomator\Tests\Seo\Services;

use AINewsAutomator\Seo\Services\BreadcrumbGenerator;
use PHPUnit\Framework\TestCase;

final class BreadcrumbGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ana_test_posts'] = [];
        $GLOBALS['__ana_test_permalinks'] = [];
        $GLOBALS['__ana_test_categories'] = [];
        $GLOBALS['__ana_test_bloginfo'] = ['name' => 'Test Site'];
    }

    public function test_home_crumb_is_always_first(): void
    {
        $generator = new BreadcrumbGenerator();

        $crumbs = $generator->generate(999);

        $this->assertSame('Test Site', $crumbs[0]['label']);
        $this->assertSame('https://example.test/', $crumbs[0]['url']);
    }

    public function test_includes_post_title_crumb(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'My Article'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/my-article';

        $crumbs = (new BreadcrumbGenerator())->generate(1);

        $last = $crumbs[count($crumbs) - 1];
        $this->assertSame('My Article', $last['label']);
        $this->assertSame('https://example.test/my-article', $last['url']);
    }

    public function test_includes_category_crumb_when_present(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'My Article'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/my-article';
        $GLOBALS['__ana_test_categories'][1] = [(object) ['term_id' => 7, 'name' => 'Technology']];

        $crumbs = (new BreadcrumbGenerator())->generate(1);

        $this->assertCount(3, $crumbs);
        $this->assertSame('Technology', $crumbs[1]['label']);
    }

    public function test_no_category_crumb_when_absent(): void
    {
        $GLOBALS['__ana_test_posts'][1] = ['post_title' => 'My Article'];
        $GLOBALS['__ana_test_permalinks'][1] = 'https://example.test/my-article';

        $crumbs = (new BreadcrumbGenerator())->generate(1);

        $this->assertCount(2, $crumbs);
    }
}
