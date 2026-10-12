<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Cms\News\NewsModuleDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Overuje stabilni Admin identitu a oddelene publish opravneni modulu
 */
final class NewsModuleDefinitionTest extends TestCase
{
    /**
     * Publish pravo nezavisi na edit opravneni a modul ma canonical segment
     */
    public function testDefinesIndependentPublishPermissionAndCanonicalAdminSegment(): void
    {
        $definition = new NewsModuleDefinition();

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
        $definition = new NewsModuleDefinition();

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
}
