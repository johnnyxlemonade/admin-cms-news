<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit;

use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Navigation\AdminNavigationGroup;
use Lemonade\Admin\Navigation\AdminNavigationItem;
use Lemonade\Cms\News\NewsModuleDefinition;
use Lemonade\Framework\View\View;
use Lemonade\Framework\View\ViewResourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Overuje markup aktivni News polozky v shared Admin sidebaru
 */
final class NewsSidebarRenderTest extends TestCase
{
    /**
     * Editace News otevre Content a oznaci Aktuality jako aktualni polozku
     */
    public function testItExpandsContentAndMarksNewsAsActive(): void
    {
        $definition = new NewsModuleDefinition();
        $metadata = $definition->adminMetadata();
        $item = new AdminNavigationItem(
            key: 'module:cms.news',
            order: $metadata->navigationOrder(),
            label: $metadata->nameKey(),
            labelKey: $metadata->nameKey(),
            route: 'admin.module.index',
            routeParameters: ['module' => $metadata->routeSegment()],
            routePrefix: false,
            icon: $metadata->icon(),
            moduleCode: $definition->code(),
            moduleNavigationGroup: $metadata->navigationGroup,
            moduleRouteSegment: $metadata->routeSegment(),
        );
        $navigation = [new AdminNavigationGroup(
            key: 'content',
            order: $metadata->navigationOrder(),
            code: 'content',
            label: 'Obsah',
            labelKey: 'admin.navigation.content',
            icon: AdminIcon::JournalText,
            items: [$item],
        )];

        $markup = $this->renderSidebar($navigation, $metadata);

        self::assertMatchesRegularExpression('#class="nav-group is-active-section is-expanded".*data-lemonade-navigation-group-key="content"#s', $markup);
        self::assertMatchesRegularExpression('#aria-expanded="true"[^>]*aria-controls="sidebar-group-content-panel"#', $markup);
        self::assertMatchesRegularExpression('#class="is-active"\\s+href="[^"]+"\\s+aria-current="page"\\s+data-lemonade-navigation-item-key="module:cms\.news"#s', $markup);
    }

    /**
     * Vykresluje skutecny shared sidebar partial s aktivnimi News metadata
     *
     * @param list<AdminNavigationGroup> $navigation
     */
    private function renderSidebar(array $navigation, \Lemonade\Admin\Module\AdminModuleMetadata $activeModule): string
    {
        $resources = new ViewResourceRegistry();
        $resources->register('admin', dirname(__DIR__, 2) . '/vendor/johnnyxlemonade/admin-platform/src/Resources/views');
        $view = new View(resources: $resources);
        $view->share('helpers', new class {
            /**
             * Vraci stabilni testovaci URL navigacni polozky
             *
             * @param array<string, scalar|null> $parameters
             */
            public function url(string $route, array $parameters = []): string
            {
                return '/' . $route . '/' . ($parameters['module'] ?? '');
            }

            /**
             * Vraci klic prekladu jako citelny testovaci popisek
             *
             * @param array<string, scalar|null> $replacements
             */
            public function lang(string $key, array $replacements = []): string
            {
                return $key;
            }
        });

        return $view->partial('admin::navigation.sidebar', [
            'navigation' => $navigation,
            'activeAdminModule' => $activeModule,
        ]);
    }
}
