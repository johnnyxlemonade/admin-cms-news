<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\DataGrid;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Presentation\AdminThumbnailComponent;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\News\Actions\NewsActionRegistrar;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Image\Value\ImageDimensions;
use Lemonade\Framework\Image\Value\ImageFormat;
use Lemonade\Framework\Image\Value\ImageQuality;
use Lemonade\Framework\Image\Value\ImageVariantDefinition;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Image\ImageUrlGenerator;
use Lemonade\Image\ImageVariantRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Overuje locale a publication projekci News DataGridu
 */
final class NewsDataGridTest extends TestCase
{
    /**
     * Prazdna existujici mutace pouziva shared placeholder a ulozeny stav
     */
    public function testExistingEmptyTranslationUsesSharedPlaceholderAndPersistedState(): void
    {
        $grid = $this->grid([
            $this->articleRow(7, 'en', null, CmsPublicationState::New),
        ]);

        $row = $grid->execute($this->query('en'))->items()[0]->toArray();

        self::assertSame('admin.datagrid.untitled', $this->cellValue($row, 'title'));
        self::assertSame('admin.status.new', $this->cellValue($row, 'state'));
    }

    /**
     * Draft i publikovana mutace zustavaji ve zvolenem locale viditelne
     */
    public function testDraftAndPublishedTranslationsKeepTheirPublicationState(): void
    {
        $grid = $this->grid([
            $this->articleRow(7, 'en', 'Draft', CmsPublicationState::Draft),
            $this->articleRow(8, 'en', 'Published', CmsPublicationState::Published),
        ]);

        $rows = array_map(
            static fn(DataGridRowDefinition $row): array => $row->toArray(),
            $grid->execute($this->query('en'))->items(),
        );

        self::assertSame('admin.status.draft', $this->cellValue($rows[0], 'state'));
        self::assertSame('admin.status.published', $this->cellValue($rows[1], 'state'));
    }

    /**
     * Explicitni locale filtr zachova existujici mutaci deaktivovaneho jazyka
     */
    public function testExistingTranslationRemainsVisibleForExplicitDisabledLocale(): void
    {
        $grid = $this->grid([
            $this->articleRow(7, 'de', 'Archiv', CmsPublicationState::Draft),
        ]);

        $row = $grid->execute($this->query('de'))->items()[0]->toArray();

        self::assertSame('Archiv', $this->cellValue($row, 'title'));
        self::assertSame('admin.status.draft', $this->cellValue($row, 'state'));
    }

    /**
     * Vytvori News grid nad DB-free projekci jedne zvolene lokalizace
     *
     * @param list<array<string,mixed>> $articles
     */
    private function grid(array $articles): NewsDataGrid
    {
        $count = $this->createMock(DatabaseResultInterface::class);
        $count->method('row_array')->willReturn(['numrows' => count($articles)]);
        $articleResult = $this->createMock(DatabaseResultInterface::class);
        $articleResult->method('result_array')->willReturn($articles);
        $fileResult = $this->createMock(DatabaseResultInterface::class);
        $fileResult->method('result_array')->willReturn([]);
        $results = [$count, $articleResult, $fileResult];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function () use (&$results): DatabaseResultInterface {
                $result = array_shift($results);
                if (!$result instanceof DatabaseResultInterface) {
                    throw new \LogicException('Unexpected NewsDataGrid query.');
                }

                return $result;
            },
        );
        $connection = $this->createMock(ConnectionInterface::class);
        $database = new Database($connection, $driver);
        $languages = new LanguageRegistry(
            $database,
            new CacheManager(new ArrayCacheItemPool()),
        );
        $authorization = new AuthorizationService(
            $this->createMock(CurrentPrincipalProviderInterface::class),
            $database,
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(
            static fn(string $key): string => $key,
        );
        $routes = new AdminModuleRouteResolver(
            new AdminModuleRegistry(new AdminNavigationGroupRegistry()),
        );
        $urls = new UrlGenerator(new Router());
        $actions = new ModuleActionRegistry();
        (new NewsActionRegistrar($actions))->register();
        $actionPresentation = new ModuleActionPresentationFactory(
            $actions,
            $routes,
            $authorization,
            $translator,
            $urls,
        );
        $variants = new ImageVariantRegistry();
        $variants->register(
            variant: 'datagrid',
            definition: new ImageVariantDefinition(
                dimensions: new ImageDimensions(32, 32),
                format: ImageFormat::Webp,
                quality: ImageQuality::fromInt(82),
            ),
        );

        return new NewsDataGrid(
            new NewsModel($driver),
            $authorization,
            $translator,
            $actionPresentation,
            $routes,
            $urls,
            new AdminFileModel($driver),
            new AdminThumbnailComponent(new ImageUrlGenerator($urls, $variants)),
            $languages,
        );
    }

    /**
     * Vytvori explicitni DataGrid dotaz pro content locale
     */
    private function query(string $locale): DataGridQuery
    {
        return new DataGridQuery(
            page: 1,
            pageSize: 25,
            sortKey: 'publishedAt',
            sortDirection: 'desc',
            search: '',
            filters: ['locale' => $locale],
        );
    }

    /**
     * Vytvori lokalizovany News radek pro DB-free DataGrid scenar
     *
     * @return array<string,mixed>
     */
    private function articleRow(
        int $articleId,
        string $locale,
        ?string $title,
        CmsPublicationState $state,
    ): array {
        return [
            'id' => $articleId,
            'publication_state' => $state->value,
            'published_at' => $state === CmsPublicationState::Published ? '2026-10-20 10:00:00' : null,
            'updated_at' => '2026-10-20 10:00:00',
            'deleted_at' => null,
            'locale' => $locale,
            'title' => $title,
            'canonical_path' => $title === null ? null : strtolower($title),
        ];
    }

    /**
     * Vrati textovou hodnotu serializovane typed bunky
     *
     * @param array<string,mixed> $row
     */
    private function cellValue(array $row, string $key): string
    {
        $cells = $row['cells'] ?? null;
        self::assertIsArray($cells);
        $cell = $cells[$key] ?? null;
        self::assertIsArray($cell);
        self::assertArrayHasKey(0, $cell);
        $presentation = $cell[0];
        self::assertIsArray($presentation);
        $value = $presentation['value'] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
