<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Cms\Content\CmsContentAdminProvider;
use Lemonade\Cms\Content\CmsContentDefinition;
use Lemonade\Cms\News\Editor\NewsEditor;
use Lemonade\Cms\News\Public\PublishedNewsReadModel;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;

/**
 * Connects News to the shared CMS Admin transport and public reader
 */
final readonly class CmsNewsServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    private CmsNewsServiceFactory $services;

    private CmsContentAdminProvider $admin;

    /**
     * Prepares cohesive service construction without resolving runtime dependencies
     */
    public function __construct()
    {
        $this->services = new CmsNewsServiceFactory();
        $this->admin = $this->services->makeAdminProvider();
    }

    /**
     * Registers News services before the container is frozen
     */
    public function register(ContainerBuilderInterface $container): void
    {
        $container->scoped(PublishedNewsReadModel::class, PublishedNewsReadModel::class);
        $container->scoped('cms.news.definition', $this->services->makeContentDefinition(...));
        $container->scoped('cms.news.content_model', $this->services->makeContentModel(...));
        $container->scoped('cms.news.lifecycle', $this->services->makeLifecycle(...));
        $container->scoped(NewsEditor::class, $this->services->makeEditor(...));

        $this->admin->register($container);
    }

    /**
     * Returns the News table and domain mapping definition
     */
    public static function contentDefinition(): CmsContentDefinition
    {
        return (new CmsNewsServiceFactory())->makeContentDefinition();
    }

    /**
     * Connects News resources and Admin contributions after registration
     */
    public function boot(ContainerInterface $container): void
    {
        $this->admin->boot($container);
    }

    /**
     * Creates the shared CMS Admin provider for News
     */
    public static function adminProvider(): CmsContentAdminProvider
    {
        return (new CmsNewsServiceFactory())->makeAdminProvider();
    }
}
