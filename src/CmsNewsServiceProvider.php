<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\News\Actions\NewsActionRegistrar;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsEditor;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;

/**
 * Registruje News module contribution do sdilenych Admin a CMS registry
 */
final readonly class CmsNewsServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
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
                ModuleManifest::CODE,
            ),
        );
        $container->scoped(
            NewsActionRegistrar::SAVE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::save(
                $container->get(EditorDispatcher::class),
                ModuleManifest::CODE,
            ),
        );
    }

    /**
     * Pripoji metadata, prava a package resources modulu po container freeze
     */
    public function boot(ContainerInterface $container): void
    {
        $container->get(TranslationResourceRegistry::class)->register(
            __DIR__ . '/Resources/lang',
            ModuleManifest::CODE,
        );
        $container->get(ClientTranslationGroupRegistry::class)->register('news');
        $container->get(DataGridRegistry::class)->register(ModuleManifest::CODE, NewsDataGrid::class);
        $container->get(ModulePageRegistry::class)->registerIndex(ModuleManifest::CODE, NewsModulePageProvider::class);
        $container->get(ModulePageRegistry::class)->registerEditor(ModuleManifest::CODE, NewsModulePageProvider::class);
        $container->get(EditorRegistry::class)->register(ModuleManifest::CODE, NewsEditor::class);
        (new NewsActionRegistrar($container->get(ModuleActionRegistry::class)))->register();
    }
}
