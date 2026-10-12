<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

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
}
