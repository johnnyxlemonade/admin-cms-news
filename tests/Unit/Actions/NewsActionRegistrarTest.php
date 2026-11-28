<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Actions;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies explicit News action registration and permission boundaries
 */
final class NewsActionRegistrarTest extends TestCase
{
    /**
     * Verifies URL and ownership actions independently from editor save
     */
    public function testRegistersIndependentUrlChangeAction(): void
    {
        $actions = new ModuleActionRegistry();
        CmsNewsServiceProvider::adminProvider()->registerActions($actions);

        self::assertTrue($actions->has('cms.news', 'change-url'));
        self::assertSame(
            'cms.news.edit_own',
            $actions->action('cms.news', 'change-url')['definition']->permission(),
        );
        self::assertTrue($actions->has('cms.news', 'assign-owner'));
    }
}
