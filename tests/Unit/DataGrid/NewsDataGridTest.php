<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\DataGrid;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Auth\AuthenticatedUser;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\Cms\Content\CmsContentDataGrid;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Presentation\AdminThumbnailComponent;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\ModuleManifest;
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
use Lemonade\Framework\Routing\ControllerAction;
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
     * Auditni metadata oddeli datum a uzivatele pro smazany zaznam
     */
    public function testDeletedRowUsesStackedAuditMetadata(): void
    {
        $article = array_replace($this->articleRow(7, 'cs', 'Deleted', CmsPublicationState::Draft), [
            'created_at' => '2026-10-20 08:00:00',
            'created_by_name' => 'Creator',
            'updated_at' => '2026-10-21 09:00:00',
            'updated_by_name' => 'Editor',
            'deleted_at' => '2026-10-22 10:00:00',
            'deleted_by_name' => 'Deleter',
        ]);
        $grid = $this->grid([$article]);

        $row = $grid->execute($this->query('cs', 'deleted'))->items()[0]->toArray();

        self::assertSame(['value' => '2026-10-20 08:00:00', 'secondary' => 'Creator'], $this->stackedCell($row, 'createdAt'));
        self::assertSame(['value' => '2026-10-21 09:00:00', 'secondary' => 'Editor'], $this->stackedCell($row, 'updatedAt'));
        self::assertSame(['value' => '2026-10-22 10:00:00', 'secondary' => 'Deleter'], $this->stackedCell($row, 'deletedAt'));
    }

    /**
     * Obnoveny zaznam zobrazi obnovujiciho uzivatele a prazdne smazani
     */
    public function testRestoredRowUsesUpdatedMetadataAndDeletedFallback(): void
    {
        $article = array_replace($this->articleRow(7, 'cs', 'Restored', CmsPublicationState::Draft), [
            'created_at' => '2026-10-20 08:00:00',
            'created_by_name' => 'Creator',
            'updated_at' => '2026-10-22 11:00:00',
            'updated_by_name' => 'Restorer',
            'deleted_at' => null,
            'deleted_by_name' => null,
        ]);
        $grid = $this->grid([$article]);

        $row = $grid->execute($this->query('cs'))->items()[0]->toArray();

        self::assertSame(['value' => '2026-10-22 11:00:00', 'secondary' => 'Restorer'], $this->stackedCell($row, 'updatedAt'));
        self::assertSame(['value' => '—', 'secondary' => '—'], $this->stackedCell($row, 'deletedAt'));
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
     * Own-only News scope je soucasti count i data dotazu
     */
    public function testOwnershipScopeFiltersCountAndRowsInDatabaseQuery(): void
    {
        $queries = [];
        $count = $this->createMock(DatabaseResultInterface::class);
        $count->method('row_array')->willReturn(['numrows' => 0]);
        $articles = $this->createMock(DatabaseResultInterface::class);
        $articles->method('result_array')->willReturn([]);
        $results = [$count, $articles];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$queries, &$results): DatabaseResultInterface {
                $queries[] = $sql;
                $result = array_shift($results);
                if (!$result instanceof DatabaseResultInterface) {
                    throw new \LogicException('Unexpected News ownership query.');
                }

                return $result;
            },
        );

        (new CmsContentDataGrid(new Database($this->createMock(ConnectionInterface::class), $driver), CmsNewsServiceProvider::contentDefinition()))->listForDataGrid($this->query('cs'), 'cs', 42);

        self::assertCount(2, $queries);
        self::assertStringContainsString('a.owner_id = ?', $queries[0]);
        self::assertStringContainsString('a.owner_id = ?', $queries[1]);
    }

    /**
     * Globalni view nezmeni edit_own na pravo upravit cizi radek
     */
    public function testRowActionsRespectOwnerWithGlobalViewAndOwnEdit(): void
    {
        $grid = $this->grid([
            $this->articleRow(7, 'cs', 'Owned', CmsPublicationState::Draft, 0),
            $this->articleRow(8, 'cs', 'Foreign', CmsPublicationState::Draft, 9),
        ], ['cms.news.view', 'cms.news.edit_own']);

        $rows = array_map(
            static fn(DataGridRowDefinition $row): array => $row->toArray(),
            $grid->execute($this->query('cs'))->items(),
        );

        self::assertSame('edit', $rows[0]['actions'][0]['key']);
        self::assertSame([], $rows[1]['actions']);
    }

    /**
     * Vytvori News grid nad DB-free projekci jedne zvolene lokalizace
     *
     * @param list<array<string,mixed>> $articles
     * @param list<string> $permissions
     */
    private function grid(array $articles, array $permissions = []): NewsDataGrid
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
        $connection->method('select')->willReturnCallback(
            static function (string $sql) use ($permissions): array {
                if (!str_contains($sql, 'system_role_permission')) {
                    return [];
                }

                return array_map(
                    static fn(string $code): array => ['code' => $code, 'name_key' => $code, 'module_code' => 'cms.news'],
                    $permissions,
                );
            },
        );
        $database = new Database($connection, $driver);
        $languages = new LanguageRegistry(
            $database,
            new CacheManager(new ArrayCacheItemPool()),
        );
        $principal = $this->createMock(CurrentPrincipalProviderInterface::class);
        $principal->method('currentUser')->willReturn(new AuthenticatedUser(0, 'fixture@example.test'));
        $authorization = new AuthorizationService($principal, $database);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(
            static fn(string $key): string => $key,
        );
        $moduleRegistry = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $moduleRegistry->register(new ModuleManifest());
        $routes = new AdminModuleRouteResolver($moduleRegistry);
        $router = new Router();
        $router->getNamed(
            'admin.content.module.edit',
            '/admin/content/{module}/{id}/edit',
            ControllerAction::for('TestController', 'edit'),
        );
        $urls = new UrlGenerator($router);
        $actions = new ModuleActionRegistry();
        CmsNewsServiceProvider::adminProvider()->registerActions($actions);
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
            new CmsContentReadModel(new Database($this->createMock(ConnectionInterface::class), $driver), CmsNewsServiceProvider::contentDefinition()),
            $authorization,
            $translator,
            $actionPresentation,
            $routes,
            $urls,
            new AdminFileModel($driver, new FileReadModel($driver)),
            new AdminThumbnailComponent(new ImageUrlGenerator($urls, $variants)),
            $languages,
            new CmsContentDataGrid($database, CmsNewsServiceProvider::contentDefinition()),
        );
    }

    /**
     * Vytvori explicitni DataGrid dotaz pro content locale
     */
    private function query(string $locale, string $status = 'active'): DataGridQuery
    {
        return new DataGridQuery(
            page: 1,
            pageSize: 25,
            sortKey: 'publishedAt',
            sortDirection: 'desc',
            search: '',
            filters: ['locale' => $locale, 'status' => $status],
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
        int $ownerId = 0,
    ): array {
        return [
            'id' => $articleId,
            'owner_id' => $ownerId,
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

    /**
     * Vrati dva textove radky serializovane stacked bunky
     *
     * @param array<string,mixed> $row
     * @return array{value:string,secondary:string}
     */
    private function stackedCell(array $row, string $key): array
    {
        $cells = $row['cells'] ?? null;
        self::assertIsArray($cells);
        $cell = $cells[$key] ?? null;
        self::assertIsArray($cell);
        $presentation = $cell[0] ?? null;
        self::assertIsArray($presentation);
        self::assertSame('stacked', $presentation['type'] ?? null);
        self::assertIsString($presentation['value'] ?? null);
        self::assertIsString($presentation['secondary'] ?? null);

        return [
            'value' => $presentation['value'],
            'secondary' => $presentation['secondary'],
        ];
    }
}
