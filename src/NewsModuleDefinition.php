<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Authorization\PermissionDefinition;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleMetadata;
use Lemonade\Admin\Module\Contract\AdminModuleDefinitionInterface;

/**
 * Deklaruje navigaci a prava administracniho managementu novinek
 */
final readonly class NewsModuleDefinition implements AdminModuleDefinitionInterface
{
    /**
     * Vrati stabilni kod News modulu
     */
    public function code(): string
    {
        return 'cms.news';
    }

    /**
     * Vrati metadata generic Admin module transportu
     */
    public function adminMetadata(): AdminModuleMetadata
    {
        return new AdminModuleMetadata(
            nameKey: 'news.module.name',
            icon: AdminIcon::JournalText,
            navigationGroup: 'content',
            navigationOrder: 10,
            destinationRoute: 'admin.module.index',
            routeSegment: 'news',
            destinationParameters: ['module' => 'news'],
            navigationPermission: 'cms.news.view',
        );
    }

    /**
     * Vrati prava vyzadovana pro skutecne News operace
     *
     * @return list<PermissionDefinition>
     */
    public function permissionDefinitions(): array
    {
        return [
            new PermissionDefinition('cms.news.view', $this->code(), 'news.permissions.view'),
            new PermissionDefinition('cms.news.create', $this->code(), 'news.permissions.create', requires: ['cms.news.view']),
            new PermissionDefinition('cms.news.edit', $this->code(), 'news.permissions.edit', requires: ['cms.news.view']),
            new PermissionDefinition('cms.news.publish', $this->code(), 'news.permissions.publish', requires: ['cms.news.view']),
            new PermissionDefinition('cms.news.delete', $this->code(), 'news.permissions.delete', requires: ['cms.news.view']),
            new PermissionDefinition('cms.news.restore', $this->code(), 'news.permissions.restore', requires: ['cms.news.view']),
        ];
    }
}
