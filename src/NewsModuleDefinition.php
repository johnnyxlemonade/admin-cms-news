<?php

declare(strict_types=1);

namespace Lemonade\Cms\News;

use Lemonade\Admin\Audit\AuditEventDefinition;
use Lemonade\Admin\Authorization\PermissionDefinition;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleMetadata;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Module\Contract\AdminModuleDefinitionInterface;
use Lemonade\Admin\Module\Contract\AdminModuleFileCapabilitiesInterface;
use Lemonade\Admin\Presentation\AdminFileCapabilities;
use Lemonade\Admin\Presentation\AdminFilePresentation;

/**
 * Deklaruje navigaci a prava administracniho managementu novinek
 */
final readonly class NewsModuleDefinition implements AdminModuleDefinitionInterface, AdminModuleFileCapabilitiesInterface
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
            navigationGroup: AdminNavigationGroup::Content,
            navigationOrder: 10,
            routeSegment: 'news',
            navigationPermission: 'cms.news.view',
            descriptionKey: 'news.module.description',
        );
    }

    /**
     * Vraci file capability News modulu
     */
    public function fileCapabilities(): AdminFileCapabilities
    {
        return new AdminFileCapabilities(
            thumbnail: AdminFilePresentation::Landscape,
            gallery: true,
            attachments: true,
            richTextImages: true,
            richTextFiles: true,
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

    /**
     * Deklaruje presentation udalosti skutecnych mutaci obsahu Aktualit
     *
     * @return list<AuditEventDefinition>
     */
    public function auditEventDefinitions(): array
    {
        return [
            new AuditEventDefinition('created', 'news.audit.events.created', AdminIcon::PlusLg),
            new AuditEventDefinition('updated', 'news.audit.events.updated', AdminIcon::PencilSquare),
            new AuditEventDefinition('translation_created', 'news.audit.events.translation_created', AdminIcon::PlusLg),
            new AuditEventDefinition('translation_updated', 'news.audit.events.translation_updated', AdminIcon::PencilSquare),
            new AuditEventDefinition('published', 'news.audit.events.published', AdminIcon::CheckCircle),
            new AuditEventDefinition('unpublished', 'news.audit.events.unpublished', AdminIcon::PauseCircle),
            new AuditEventDefinition('deleted', 'news.audit.events.deleted', AdminIcon::Trash3),
            new AuditEventDefinition('restored', 'news.audit.events.restored', AdminIcon::ArrowCounterclockwise),
        ];
    }
}
