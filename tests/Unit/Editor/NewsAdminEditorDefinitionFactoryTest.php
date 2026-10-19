<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Editor\AdminEditor\Field\TextareaField;
use Lemonade\Admin\Editor\AdminEditor\Field\UrlSegmentField;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Cms\News\Editor\NewsAdminEditorDefinitionFactory;
use Lemonade\Cms\News\ModuleManifest;
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
     * Editor deklaruje rich text pouze pro content mutation
     */
    public function testCreatesSingleLocaleEditorWithRichTextContentField(): void
    {
        $router = new Router();
        $router->getNamed('admin.content.module.index', '/admin/content/{module}', ControllerAction::for('NewsController', 'index'));
        $router->postNamed('admin.content.module.update', '/admin/content/{module}/edit/{id}', ControllerAction::for('NewsController', 'update'));
        $router->postNamed('admin.content.module.ajax.entity', '/admin/content/{module}/ajax/{id}', ControllerAction::for('NewsController', 'save'));
        $factory = new NewsAdminEditorDefinitionFactory(new UrlGenerator($router), $this->routes());

        $editor = $factory->create(
            article: [
                'id' => 16,
                'translations' => [[
                    'locale' => 'en',
                    'publication_state' => 1,
                    'published_at' => null,
                    'title' => 'News',
                    'summary' => null,
                    'content' => '<p>Content</p>',
                    'page_title' => null,
                    'meta_description' => null,
                    'canonical_path' => 'stable-news',
                    'tags' => [],
                ]],
            ],
            contentLocale: 'en',
            canPublish: false,
            context: new AdminEditorHeaderContextDefinition('Content language', [
                new AdminEditorHeaderContextItem('English', '/admin/news/edit/16?locale=en', active: true),
            ]),
            tagOptions: ['Culture', 'Sport'],
        );

        $contentSection = array_values(array_filter($editor->tabs()[0]->blocks(), static fn(mixed $block): bool => $block instanceof SectionBlock && $block->id() === 'content-body'))[0];
        self::assertInstanceOf(SectionBlock::class, $contentSection);
        self::assertInstanceOf(FieldGroupBlock::class, $contentSection->blocks()[0]);
        $contentField = $contentSection->blocks()[0]->columns()[0]->field();
        self::assertInstanceOf(TextareaField::class, $contentField);
        self::assertTrue($contentField->isRichText());
        self::assertTrue($contentField->hasImageUpload());
        self::assertTrue($contentField->hasFileUpload());
        $seoSection = $editor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $seoSection);
        $seoFields = $seoSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $seoFields);
        $urlField = $seoFields->columns()[2]->field();
        self::assertInstanceOf(UrlSegmentField::class, $urlField);
        self::assertSame('change-url', $urlField->actionKey());
        self::assertFalse($urlField->autoGenerates());

        $draftEditor = $factory->create(
            article: [
                'id' => 17,
                'translations' => [['locale' => 'en', 'title' => 'New article', 'canonical_path' => null]],
            ],
            contentLocale: 'en',
            canPublish: false,
            tagOptions: [],
        );
        $draftSeo = $draftEditor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $draftSeo);
        $draftFields = $draftSeo->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $draftFields);
        $draftUrlField = $draftFields->columns()[2]->field();
        self::assertInstanceOf(UrlSegmentField::class, $draftUrlField);
        self::assertTrue($draftUrlField->autoGenerates());
    }

    /**
     * Vraci resolver s canonical News Admin segmentem
     */
    private function routes(): AdminModuleRouteResolver
    {
        $definition = new ModuleManifest();
        $adminModules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $adminModules->register($definition);

        return new AdminModuleRouteResolver($adminModules);
    }
}
