<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Modules\Registry\ModuleRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\News\Actions\NewsActionRegistrar;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsEditor;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;
use Lemonade\Framework\View\ViewResourceRegistry;

/**
 * Registruje News module contribution do sdilenych Admin a CMS registry
 */
final class CmsNewsServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    /**
     * Registruje DI definitions modulu
     */
    public function register(ContainerBuilderInterface $container): void
    {
        $container->scoped(
            NewsActionRegistrar::CREATE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::create(
                $container->get(EditorDispatcher::class),
                'cms.news',
            ),
        );
        $container->scoped(
            NewsActionRegistrar::SAVE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::save(
                $container->get(EditorDispatcher::class),
                'cms.news',
            ),
        );
        $container->singleton(NewsActionRegistrar::class, NewsActionRegistrar::class);
    }

    /**
     * Pripoji metadata, prava, verejne handlery a package resources modulu po container freeze
     */
    public function boot(ContainerInterface $container): void
    {
        $definition = new NewsModuleDefinition();
        $container->get(TranslationResourceRegistry::class)->register(
            __DIR__ . '/Resources/lang',
            'cms.news',
        );
        $container->get(ViewResourceRegistry::class)->register(
            'cms-news',
            __DIR__ . '/Resources/views',
        );
        $container->get(ClientTranslationGroupRegistry::class)->register('news');
        $container->get(ModuleRegistry::class)->register($definition);
        $container->get(AdminModuleRegistry::class)->register($definition);
        $container->get(DataGridRegistry::class)->register($definition->code(), NewsDataGrid::class);
        $container->get(ModulePageRegistry::class)->registerIndex($definition->code(), NewsModulePageProvider::class);
        $container->get(ModulePageRegistry::class)->registerEditor($definition->code(), NewsModulePageProvider::class);
        $container->get(EditorRegistry::class)->register($definition->code(), NewsEditor::class);
        $container->get(PermissionCatalogRegistry::class)->register(...$definition->permissionDefinitions());
        $container->get(NewsActionRegistrar::class)->register();
        $container->get(AuditEventPresentationRegistry::class)->registerModuleDefinition($definition);
    }
}
