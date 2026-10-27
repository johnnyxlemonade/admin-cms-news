<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;
use Lemonade\Framework\Routing\RouteRegistrarInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje oddeleni administracniho News modulu od verejneho routingu hosta
 */
final class CmsNewsServiceProviderTest extends TestCase
{
    /**
     * Ověří napojení stávajících registrů i oddělená oprávnění akcí
     */
    public function testBootRegistersExistingAdminCapabilities(): void
    {
        $grid = new DataGridRegistry();
        $editors = new EditorRegistry();
        $pages = new ModulePageRegistry();
        $actions = new ModuleActionRegistry();
        $groups = new ClientTranslationGroupRegistry();
        $resources = new TranslationResourceRegistry();
        $services = [
            DataGridRegistry::class => $grid,
            EditorRegistry::class => $editors,
            ModulePageRegistry::class => $pages,
            ModuleActionRegistry::class => $actions,
            ClientTranslationGroupRegistry::class => $groups,
            TranslationResourceRegistry::class => $resources,
        ];
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static fn(string $id): object => $services[$id]);

        (new CmsNewsServiceProvider())->boot($container);

        self::assertSame(\Lemonade\Cms\News\DataGrid\NewsDataGrid::class, $grid->providerServiceId('cms.news'));
        self::assertSame(\Lemonade\Cms\News\Editor\NewsEditor::class, $editors->providerServiceId('cms.news'));
        self::assertSame(\Lemonade\Cms\News\NewsModulePageProvider::class, $pages->indexProviderServiceId('cms.news'));
        self::assertSame(\Lemonade\Cms\News\NewsModulePageProvider::class, $pages->editorProviderServiceId('cms.news'));
        self::assertTrue($groups->has('news'));
        foreach (['create', 'save', 'change-url', 'delete', 'restore', 'assign-owner'] as $action) {
            self::assertTrue($actions->has('cms.news', $action));
        }
        self::assertSame('cms.news.delete_own', $actions->action('cms.news', 'delete')['definition']->permission());
    }

    /**
     * Registruje pouze administracni sluzby bez verejnych rout
     */
    public function testItDoesNotActivatePublicNewsRouting(): void
    {
        $container = new Container();

        (new CmsNewsServiceProvider())->register($container);

        self::assertTrue($container->isBound('admin.cms.news.action.create'));
        self::assertSame([], iterator_to_array($container->tagged(RouteRegistrarInterface::class)));
    }
}
