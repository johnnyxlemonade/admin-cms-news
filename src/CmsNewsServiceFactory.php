<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\Cms\Content\CmsContentAdminProvider;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Cms\Content\CmsContentDefinition;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsDomainMapping;
use Lemonade\Cms\Content\Models\CmsContentModel;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsEditor;
use Lemonade\Cms\News\Editor\NewsEditorValidationSchema;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Support\Slug\Slugger;

/**
 * Constructs News persistence, lifecycle, editor and shared Admin composition
 *
 * @internal
 */
final readonly class CmsNewsServiceFactory
{
    public function makeContentDefinition(): CmsContentDefinition
    {
        return new CmsContentDefinition(
            moduleCode: ModuleManifest::CODE,
            entityType: 'cms_news_article',
            rootTable: 'cms_news_article',
            translationTable: 'cms_news_article_translation',
            translationForeignKey: 'article_id',
            tagTable: 'cms_news_article_translation_tag',
            translationUniqueConstraint: 'uq_cms_news_article_translation_locale',
            validationPrefix: 'news',
            dataClass: NewsData::class,
        );
    }

    public function makeContentModel(ContainerInterface $container): CmsContentModel
    {
        return new CmsContentModel(
            $container->get(Database::class),
            $container->get('cms.news.definition'),
        );
    }

    /**
     * @return CmsContentLifecycle<NewsData>
     */
    public function makeLifecycle(ContainerInterface $container): CmsContentLifecycle
    {
        return new CmsContentLifecycle(
            $container->get('cms.news.definition'),
            $container->get('cms.news.content_model'),
            new CmsDomainMapping(NewsData::class),
            $container->get(CmsRouteReservationService::class),
            $container->get(Slugger::class),
            $container->get(CmsAdminRuntime::class),
        );
    }

    public function makeEditor(ContainerInterface $container): NewsEditor
    {
        return new NewsEditor(
            $container->get('cms.news.read'),
            $container->get('cms.news.lifecycle'),
            $container->get(LanguageRegistry::class),
            $container->get(LocalActorGuard::class),
            $container->get(NewsEditorValidationSchema::class),
            $container->get(AdminModuleRouteResolver::class),
            $container->get(UrlGenerator::class),
            $container->get(AuthorizationService::class),
            $container->get(TranslatorInterface::class),
        );
    }

    public function makeAdminProvider(): CmsContentAdminProvider
    {
        return new CmsContentAdminProvider(
            moduleCode: ModuleManifest::CODE,
            translationGroup: 'news',
            translationPath: __DIR__ . '/Resources/lang',
            dataGridService: NewsDataGrid::class,
            pageService: NewsModulePageProvider::class,
            editorService: NewsEditor::class,
            lifecycleService: 'cms.news.lifecycle',
            contentDefinitionService: 'cms.news.definition',
        );
    }
}
