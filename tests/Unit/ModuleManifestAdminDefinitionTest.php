<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Navigation\AdminNavigationItem;
use Lemonade\Cms\News\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Verifies stable Admin identity, permissions, audit and navigation metadata
 */
final class ModuleManifestAdminDefinitionTest extends TestCase
{
    /**
     * Verifies independent publication permission and canonical Admin metadata
     */
    public function testDefinesIndependentPublishPermissionAndCanonicalAdminSegment(): void
    {
        $definition = new ModuleManifest();

        self::assertSame('cms.news', $definition->code());
        self::assertSame(AdminNavigationGroup::Cms, $definition->adminMetadata()->navigationGroup());
        self::assertSame('news', $definition->adminMetadata()->routeSegment());
        self::assertSame('news.module.description', $definition->adminMetadata()->descriptionKey());
        self::assertSame('cms.news.view_own', $definition->adminMetadata()->navigationPermission());
        self::assertSame(
            ['cms.news.publish_own', 'cms.news.view'],
            $definition->permissionDefinitions()[6]->requires(),
        );
    }

    /**
     * Verifies the module description in both supported locales
     */
    public function testDefinesLocalizedModuleDescription(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $catalog = require dirname(__DIR__, 2) . '/src/Resources/lang/' . $locale . '/news.php';

            self::assertIsString($catalog['module']['description'] ?? null);
            self::assertNotSame('', $catalog['module']['description']);
        }
    }

    /**
     * Verifies presentations for every emitted News audit event
     */
    public function testItDeclaresAndRegistersPresentationsForAllEmittedNewsEvents(): void
    {
        $definition = new ModuleManifest();

        self::assertSame([
            'created',
            'updated',
            'translation_created',
            'translation_updated',
            'published',
            'unpublished',
            'deleted',
            'restored',
            'owner_transferred',
        ], array_map(static fn(\Lemonade\Admin\Audit\AuditEventDefinition $event): string => $event->suffix, $definition->auditEventDefinitions()));

        $registry = new AuditEventPresentationRegistry();
        $registry->registerModuleDefinition($definition);

        $module = $registry->modulePresentation('cms.news');
        $event = $registry->presentation('cms.news.published');

        self::assertNotNull($module);
        self::assertSame('news.module.name', $module->nameKey());
        self::assertSame(AdminIcon::Newspaper, $module->icon());
        self::assertNotNull($event);
        self::assertSame('news.audit.events.published', $event->translationKey());
        self::assertSame(AdminIcon::CheckCircle, $event->icon());
    }

    /**
     * Verifies navigation activity against resolved News metadata
     */
    public function testNavigationItemMatchesResolvedNewsMetadata(): void
    {
        $definition = new ModuleManifest();
        $registry = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $registry->register($definition);
        $metadata = (new AdminModuleRouteResolver($registry))
            ->metadata('news');
        $item = new AdminNavigationItem(
            key: 'module:' . $definition->code(),
            order: $metadata->navigationOrder(),
            label: $metadata->nameKey(),
            labelKey: $metadata->nameKey(),
            route: 'admin.cms.module.index',
            routeParameters: ['module' => $metadata->routeSegment()],
            routePrefix: false,
            icon: $metadata->icon(),
            moduleCode: $definition->code(),
            moduleNavigationGroup: $metadata->navigationGroup(),
            moduleRouteSegment: $metadata->routeSegment(),
        );

        self::assertTrue($item->isActiveFor($metadata));
    }
}
