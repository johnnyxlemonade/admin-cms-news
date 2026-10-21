<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Actions;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Cms\News\Actions\NewsActionRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Overuje explicitni registraci News actions a jejich permission hranice
 */
final class NewsActionRegistrarTest extends TestCase
{
    /**
     * Registruje URL akci oddelene od standardniho editoroveho save
     */
    public function testRegistersIndependentUrlChangeAction(): void
    {
        $actions = new ModuleActionRegistry();
        (new NewsActionRegistrar($actions))->register();

        self::assertTrue($actions->has('cms.news', 'change-url'));
        self::assertSame(
            'cms.news.edit_own',
            $actions->action('cms.news', 'change-url')['definition']->permission(),
        );
        self::assertTrue($actions->has('cms.news', 'assign-owner'));
    }
}
