<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderer;
use Lemonade\Admin\Editor\AdminEditor\SectionBlock;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Modules\Registry\ModuleRegistry;
use Lemonade\Admin\Navigation\AdminNavigationGroupDefinition;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
use Lemonade\Cms\News\NewsModuleDefinition;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Overuje editor Aktualit pro jednu jazykovou mutation
 */
final class NewsAdminEditorDefinitionFactoryTest extends TestCase
{
    /**
     * Editor vykresli jednu mutation bez vnorenych locale tabu a zachova rich text marker
     */
    public function testCreatesSingleLocaleEditorWithRichTextMarker(): void
    {
        $router = new Router();
        $router->getNamed('admin.cms.module.index', '/admin/cms/{module}', ControllerAction::for('NewsController', 'index'));
        $router->postNamed('admin.cms.module.update', '/admin/cms/{module}/edit/{id}', ControllerAction::for('NewsController', 'update'));
        $router->postNamed('admin.cms.module.ajax.entity', '/admin/cms/{module}/ajax/{id}', ControllerAction::for('NewsController', 'save'));
        $factory = new NewsAdminEditorDefinitionFactory(new UrlGenerator($router), $this->routes());

        $editor = $factory->create(
            article: [
                'id' => 16,
                'state' => 'draft',
                'published_at' => null,
                'translations' => [[
                    'locale' => 'en',
                    'title' => 'News',
                    'summary' => null,
                    'content' => '<p>Content</p>',
                    'page_title' => null,
                    'meta_description' => null,
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            mode: 'edit',
            canPublish: false,
            context: new AdminEditorHeaderContextDefinition('Content language', [
                new AdminEditorHeaderContextItem('English', '/admin/news/edit/16?locale=en', active: true),
            ]),
        );

        self::assertInstanceOf(SectionBlock::class, $editor->tabs()[0]->blocks()[0]);
        $html = (new AdminEditorRenderer())->render($editor, new AdminEditorRenderContext());

        self::assertStringContainsString('data-lemonade-rich-text', $html);
        self::assertStringContainsString('name="locale" type="hidden"', $html);
        self::assertStringNotContainsString('lm-editor-header-context', $html);
    }

    /**
     * Vraci resolver s canonical News Admin segmentem
     */
    private function routes(): AdminModuleRouteResolver
    {
        $groups = new AdminNavigationGroupRegistry();
        $groups->register(new AdminNavigationGroupDefinition('content', 'admin.navigation.content', 40, AdminIcon::JournalText));
        $definition = new NewsModuleDefinition();
        $adminModules = new AdminModuleRegistry($groups);
        $adminModules->register($definition);
        $modules = new ModuleRegistry();
        $modules->register($definition);

        return new AdminModuleRouteResolver($adminModules, $modules);
    }
}
