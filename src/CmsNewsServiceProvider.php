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
use Lemonade\Cms\News\Actions\NewsAssignOwnerAction;
use Lemonade\Cms\News\Actions\NewsChangeUrlAction;
use Lemonade\Cms\News\Actions\NewsDeleteAction;
use Lemonade\Cms\News\Actions\NewsRestoreAction;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsEditor;
use Lemonade\Cms\News\Editor\NewsEditorValidationSchema;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Support\Slug\Slugger;

/**
 * Pripojuje modul k existujicimu sdilenemu CMS Admin transportu
 */
final readonly class CmsNewsServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    /**
     * Registruje editorove action transporty modulu
     */
    public function register(ContainerBuilderInterface $container): void
    {
        $container->scoped('cms.news.lifecycle', static function (ContainerInterface $container): CmsContentLifecycle {
            $definition = new CmsContentDefinition(
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

            return new CmsContentLifecycle(
                $definition,
                new CmsContentModel($container->get(Database::class), $definition),
                new CmsDomainMapping(NewsData::class),
                $container->get(CmsRouteReservationService::class),
                $container->get(Slugger::class),
                $container->get(CmsAdminRuntime::class),
            );
        });
        $container->scoped(NewsEditor::class, static fn(ContainerInterface $container): NewsEditor => new NewsEditor(
            $container->get(NewsModel::class),
            $container->get('cms.news.lifecycle'),
            $container->get(LanguageRegistry::class),
            $container->get(LocalActorGuard::class),
            $container->get(NewsEditorValidationSchema::class),
            $container->get(AdminModuleRouteResolver::class),
            $container->get(UrlGenerator::class),
            $container->get(AuthorizationService::class),
        ));
        foreach ([NewsChangeUrlAction::class, NewsDeleteAction::class, NewsRestoreAction::class] as $action) {
            $container->scoped($action, static fn(ContainerInterface $container): object => new $action(
                $container->get('cms.news.lifecycle'),
                $container->get(LocalActorGuard::class),
                $container->get(NewsModel::class),
                $container->get(AuthorizationService::class),
            ));
        }
        $container->scoped(NewsAssignOwnerAction::class, static fn(ContainerInterface $container): NewsAssignOwnerAction => new NewsAssignOwnerAction(
            $container->get('cms.news.lifecycle'),
            $container->get(NewsModel::class),
            $container->get(LocalActorGuard::class),
            $container->get(AuthorizationService::class),
        ));
        self::adminProvider()->register($container);
    }

    /**
     * Pripoji preklady, grid, editor, stranky a akce modulu
     */
    public function boot(ContainerInterface $container): void
    {
        self::adminProvider()->boot($container);
    }

    /**
     * Nastavi stavajici presentation a action providery modulu
     */
    public static function adminProvider(): CmsContentAdminProvider
    {
        return new CmsContentAdminProvider(
            moduleCode: ModuleManifest::CODE,
            translationGroup: 'news',
            translationPath: __DIR__ . '/Resources/lang',
            dataGridService: NewsDataGrid::class,
            pageService: NewsModulePageProvider::class,
            editorService: NewsEditor::class,
            changeUrlService: NewsChangeUrlAction::class,
            deleteService: NewsDeleteAction::class,
            restoreService: NewsRestoreAction::class,
            assignOwnerService: NewsAssignOwnerAction::class,
        );
    }
}
