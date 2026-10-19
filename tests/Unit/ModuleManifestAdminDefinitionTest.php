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
 * Overuje stabilni Admin identitu a oddelene publish opravneni modulu
 */
final class ModuleManifestAdminDefinitionTest extends TestCase
{
    /**
     * Publish pravo nezavisi na edit opravneni a modul ma canonical segment
     */
    public function testDefinesIndependentPublishPermissionAndCanonicalAdminSegment(): void
    {
        $definition = new ModuleManifest();

        self::assertSame('cms.news', $definition->code());
        self::assertSame(AdminNavigationGroup::Content, $definition->adminMetadata()->navigationGroup);
        self::assertSame('news', $definition->adminMetadata()->routeSegment());
        self::assertSame('news.module.description', $definition->adminMetadata()->descriptionKey());
        self::assertSame(['cms.news.view'], $definition->permissionDefinitions()[3]->requires());
    }

    /**
     * Popis modulu je dostupny pro oba podporovane jazyky
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
     * Auditni udalosti News zustavaji registrovane pod canonical module kodem
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
        ], array_map(static fn(\Lemonade\Admin\Audit\AuditEventDefinition $event): string => $event->suffix, $definition->auditEventDefinitions()));

        $registry = new AuditEventPresentationRegistry();
        $registry->registerModuleDefinition($definition);

        $module = $registry->modulePresentation('cms.news');
        $event = $registry->presentation('cms.news.published');

        self::assertNotNull($module);
        self::assertSame('news.module.name', $module->nameKey());
        self::assertSame(AdminIcon::JournalText, $module->icon());
        self::assertNotNull($event);
        self::assertSame('news.audit.events.published', $event->translationKey());
        self::assertSame(AdminIcon::CheckCircle, $event->icon());
    }

    /**
     * Standardni News lifecycle zachovava aktivni polozku z resolved group a segmentu
     */
    public function testItKeepsNewsNavigationActiveAcrossTheStandardLifecycle(): void
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
            route: 'admin.content.module.index',
            routeParameters: ['module' => $metadata->routeSegment()],
            routePrefix: false,
            icon: $metadata->icon(),
            moduleCode: $definition->code(),
            moduleNavigationGroup: $metadata->navigationGroup,
            moduleRouteSegment: $metadata->routeSegment(),
        );

        foreach (['index', 'create', 'edit'] as $lifecycleAction) {
            self::assertTrue(
                $item->isActiveFor($metadata),
                sprintf('%s must keep cms.news active.', $lifecycleAction),
            );
        }
    }
}
