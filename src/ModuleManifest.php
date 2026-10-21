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
use Lemonade\Admin\Modules\Manifest\ModuleManifestInterface;
use Lemonade\Admin\Modules\Migration\ModuleMigrationManifestInterface;
use Lemonade\Admin\Presentation\AdminFileCapabilities;
use Lemonade\Admin\Presentation\AdminFilePresentation;
use Lemonade\Framework\Database\Migration\MigrationInterface;

/**
 * Deklaruje optional CMS News modul a jeho schema
 */
final readonly class ModuleManifest implements
    ModuleManifestInterface,
    ModuleMigrationManifestInterface,
    AdminModuleDefinitionInterface,
    AdminModuleFileCapabilitiesInterface
{
    public const string CODE = 'cms.news';

    /**
     * Vrati stabilni kod News modulu
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * Vrati prekladovy klic nazvu modulu
     */
    public function labelKey(): string
    {
        return 'news.module.name';
    }

    /**
     * Vrati provider runtime contributions modulu
     */
    public function runtimeProvider(): string
    {
        return CmsNewsServiceProvider::class;
    }

    /**
     * Vrati migrace aplikovane standardnim migration lifecycle
     *
     * @return list<class-string<MigrationInterface>>
     */
    public function migrations(): array
    {
        return [Migrations\CreateNewsArticles::class];
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
            navigationPermission: 'cms.news.view_own',
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
            new PermissionDefinition('cms.news.view_own', self::CODE, 'news.permissions.view_own'),
            new PermissionDefinition('cms.news.view', self::CODE, 'news.permissions.view', requires: ['cms.news.view_own']),
            new PermissionDefinition('cms.news.create', self::CODE, 'news.permissions.create', requires: ['cms.news.view_own']),
            new PermissionDefinition('cms.news.edit_own', self::CODE, 'news.permissions.edit_own', requires: ['cms.news.view_own']),
            new PermissionDefinition('cms.news.edit', self::CODE, 'news.permissions.edit', requires: ['cms.news.edit_own', 'cms.news.view']),
            new PermissionDefinition('cms.news.publish_own', self::CODE, 'news.permissions.publish_own', requires: ['cms.news.view_own']),
            new PermissionDefinition('cms.news.publish', self::CODE, 'news.permissions.publish', requires: ['cms.news.publish_own', 'cms.news.view']),
            new PermissionDefinition('cms.news.delete_own', self::CODE, 'news.permissions.delete_own', requires: ['cms.news.view_own']),
            new PermissionDefinition('cms.news.delete', self::CODE, 'news.permissions.delete', requires: ['cms.news.delete_own', 'cms.news.view']),
            new PermissionDefinition('cms.news.restore_own', self::CODE, 'news.permissions.restore_own', requires: ['cms.news.view_own']),
            new PermissionDefinition('cms.news.restore', self::CODE, 'news.permissions.restore', requires: ['cms.news.restore_own', 'cms.news.view']),
            new PermissionDefinition('cms.news.assign_owner', self::CODE, 'news.permissions.assign_owner', requires: ['cms.news.view_own']),
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
            new AuditEventDefinition('owner_transferred', 'news.audit.events.owner_transferred', AdminIcon::PersonGear),
        ];
    }
}
