<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

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
        self::assertSame('news', $definition->adminMetadata()->routeSegment());
        self::assertSame(['cms.news.view'], $definition->permissionDefinitions()[3]->requires());
    }
}
