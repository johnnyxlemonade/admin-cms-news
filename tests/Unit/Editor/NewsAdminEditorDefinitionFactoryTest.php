<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderContext;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorRenderer;
use Lemonade\Admin\Editor\AdminEditor\SectionBlock;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
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
        $router->getNamed('admin.module.index', '/admin/{module}', ControllerAction::for('NewsController', 'index'));
        $router->postNamed('admin.module.update', '/admin/{module}/edit/{id}', ControllerAction::for('NewsController', 'update'));
        $router->postNamed('admin.module.ajax.entity', '/admin/{module}/ajax/{id}', ControllerAction::for('NewsController', 'save'));
        $factory = new NewsAdminEditorDefinitionFactory(new UrlGenerator($router));

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
}
