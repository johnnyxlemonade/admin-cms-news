<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Modules\Registry\ModuleRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Admin\Presentation\AdminFileUploadPresentation;
use Lemonade\Admin\Presentation\AdminFileUsageDefinition;
use Lemonade\Admin\Presentation\AdminFileUsageRegistry;
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
    public function register(ContainerBuilderInterface $container): void {}

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
        $container->get(DataGridRegistry::class)->registerFactory(
            moduleCode: $definition->code(),
            provider: static fn(): NewsDataGrid => $container->get(NewsDataGrid::class),
        );
        $container->get(ModulePageRegistry::class)->registerIndexFactory(
            moduleCode: $definition->code(),
            provider: static fn(): NewsModulePageProvider => $container->get(NewsModulePageProvider::class),
        );
        $container->get(ModulePageRegistry::class)->registerEditorFactory(
            moduleCode: $definition->code(),
            provider: static fn(): NewsModulePageProvider => $container->get(NewsModulePageProvider::class),
        );
        $container->get(ModulePageRegistry::class)->registerModalEditorFactory(
            moduleCode: $definition->code(),
            provider: static fn(): NewsModulePageProvider => $container->get(NewsModulePageProvider::class),
        );
        $container->get(EditorRegistry::class)->registerFactory(
            moduleCode: $definition->code(),
            provider: static fn(): NewsEditor => $container->get(NewsEditor::class),
        );
        $container->get(PermissionCatalogRegistry::class)->register(...$definition->permissionDefinitions());
        $files = $container->get(AdminFileUsageRegistry::class);
        $files->register(new AdminFileUsageDefinition('cms.news', 'thumbnail', 'image', 'admin-image', false, false, AdminFileUploadPresentation::Landscape));
        $files->register(new AdminFileUsageDefinition('cms.news', 'gallery', 'image', 'admin-image', true, true));
        $files->register(new AdminFileUsageDefinition('cms.news', 'attachment', 'file', 'admin-file', true, true, imageProfile: 'admin-image'));
        $container->get(NewsActionRegistrar::class)->register();
        $container->get(AuditEventPresentationRegistry::class)->registerModuleDefinition($definition);
    }
}
