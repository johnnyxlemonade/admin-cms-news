<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration\Support;

use Lemonade\Admin\Action\ModuleActionDispatcher;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Auth\AuthenticationService;
use Lemonade\Admin\Auth\CurrentUserProvider;
use Lemonade\Admin\Auth\LocalAuthenticationProvider;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\Cms\Content\CmsContentDataGrid;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\DataGrid\Query\DataGridQueryValidator;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Editor\Lock\EditorLockManager;
use Lemonade\Admin\Editor\Lock\EditorLockOwnerReleaserInterface;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Http\AdminAuthorizationResponseHandler;
use Lemonade\Admin\Http\Controller\DataGridController;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\AdminUiLocale;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleAccessPolicy;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\ModuleProviderResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Admin\Platform\Migrations\CreateCoreFiles;
use Lemonade\Admin\Presentation\AdminFileUploadComponent;
use Lemonade\Admin\Presentation\AdminFileUsageRegistry;
use Lemonade\Admin\Routing\AdminRoutingConfiguration;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
use Lemonade\Cms\News\Editor\NewsEditor;
use Lemonade\Cms\News\Migrations\CreateNewsArticles;
use Lemonade\Cms\News\ModuleManifest;
use Lemonade\Cms\News\NewsData;
use Lemonade\Cms\News\NewsModulePageProvider;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Container\ScopeKind;
use Lemonade\Framework\Core\Http\ResponseBuilder;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use Lemonade\Framework\Http\Request\HttpRequestInspector;
use Lemonade\Framework\Http\Response\Responses;
use Lemonade\Framework\Image\Value\ImageDimensions;
use Lemonade\Framework\Image\Value\ImageFormat;
use Lemonade\Framework\Image\Value\ImageQuality;
use Lemonade\Framework\Image\Value\ImageVariantDefinition;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Security\Csrf\CsrfTokenManager;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Framework\Upload\Config\FileUploadProfileConfig;
use Lemonade\Framework\Upload\Config\ImageUploadProfileConfig;
use Lemonade\Framework\Upload\Config\UploadConfig;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
use Lemonade\Image\ImageUrlGenerator;
use Lemonade\Image\ImageVariantRegistry;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Owns isolated News integration infrastructure without creating domain fixtures
 */
final readonly class NewsIntegrationEnvironment
{
    private MysqlConnection $connection;

    private Database $database;

    private ContainerInterface $scope;

    private ModuleActionDispatcher $transport;

    private AuthorizationService $authorization;

    private EditorDispatcher $editor;

    private DataGridController $gridController;

    /**
     * Resets the guarded schema and constructs the real Admin transport
     */
    public function __construct()
    {
        $databaseName = getenv('NEWS_INTEGRATION_DATABASE');
        if (!is_string($databaseName) || preg_match('/^cms_news_test_[a-zA-Z0-9_]+$/D', $databaseName) !== 1) {
            throw new RuntimeException('Set NEWS_INTEGRATION_DATABASE to a dedicated cms_news_test_ database.');
        }

        $config = DatabaseConfig::fromArray([
            'driver' => 'mysql',
            'host' => getenv('NEWS_INTEGRATION_HOST') !== false ? getenv('NEWS_INTEGRATION_HOST') : 'db',
            'database' => $databaseName,
            'username' => 'db',
            'password' => 'db',
        ]);
        $connection = new MysqlConnection($config, new DatabaseInstrumentation());
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver(
            $connection,
            $config,
            $escaper,
            new IdentifierProtector($escaper),
        );
        $database = new Database($connection, $driver);

        $this->connection = $connection;
        $this->database = $database;

        $this->resetSchema();
        $this->migrate($config, $escaper, $driver);

        $translator = $this->createTranslator();
        $session = $this->createSession();

        $authentication = new AuthenticationService(
            new LocalAuthenticationProvider($database),
            $session,
            $database,
            new CsrfTokenManager($session),
            new AuditLogService($database),
        );
        $currentUser = new CurrentUserProvider($authentication, $database);
        $authorization = new AuthorizationService($currentUser, $database);
        $languages = new LanguageRegistry($database, new CacheManager(new ArrayCacheItemPool()));
        $events = new TransactionalEventProcessor(
            $database,
            new AuditLogService($database),
            new DomainEventDispatcher(new NullLogger()),
        );
        $runtime = new CmsAdminRuntime($events, $languages, new LocalUserDirectory($database));
        $routes = $this->createRouteReservations($database);
        $router = $this->createRouter();
        $urlGenerator = new UrlGenerator($router);

        $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $modules->register(new ModuleManifest());
        $moduleRoutes = new AdminModuleRouteResolver($modules);
        $grids = new DataGridRegistry();
        $editors = new EditorRegistry();
        $pages = new ModulePageRegistry();
        $actions = new ModuleActionRegistry();

        $container = new Container();
        foreach ([
            Database::class => $database,
            \Lemonade\Framework\Database\DatabaseDriverInterface::class => $driver,
            CmsAdminRuntime::class => $runtime,
            CmsRouteReservationService::class => $routes,
            LanguageRegistry::class => $languages,
            LocalActorGuard::class => new LocalActorGuard($currentUser),
            AuthorizationService::class => $authorization,
            AdminModuleRouteResolver::class => $moduleRoutes,
            UrlGenerator::class => $urlGenerator,
            TranslatorInterface::class => $translator,
        ] as $id => $service) {
            $container->instance($id, $service);
        }

        $provider = new CmsNewsServiceProvider();
        $provider->register($container);
        $editors->register(ModuleManifest::CODE, \Lemonade\Cms\News\Editor\NewsEditor::class);
        $pages->registerEditor(ModuleManifest::CODE, NewsModulePageProvider::class);
        CmsNewsServiceProvider::adminProvider()->registerActions($actions);
        $grids->register(ModuleManifest::CODE, NewsDataGrid::class);
        $container->instance(
            ModuleActionPresentationFactory::class,
            new ModuleActionPresentationFactory($actions, $moduleRoutes, $authorization, $translator, $urlGenerator),
        );
        $this->registerFileServices($container, $urlGenerator);

        $scope = $container->beginScope(ScopeKind::Request);
        $resolver = new ModuleProviderResolver($grids, $editors, $pages, $actions, $scope);
        $validation = new FormValidation($translator, new ValidationRuleResolver(new RuleRegistry(), $scope));
        $locks = new EditorLockManager(
            $database,
            $currentUser,
            new class implements EditorLockOwnerReleaserInterface {
                public function releaseOwnedByUser(int $userId): void {}
            },
        );
        $editor = new EditorDispatcher($modules, $resolver, $authorization, $validation, $locks);
        $container->instance(EditorDispatcher::class, $editor);

        $http = new Psr17Factory();
        $this->scope = $scope;
        $this->authorization = $authorization;
        $this->editor = $editor;
        $this->transport = new ModuleActionDispatcher(
            $moduleRoutes,
            new AdminModuleAccessPolicy($authorization, $currentUser),
            $resolver,
            $authorization,
            $locks,
            $pages,
        );
        $this->gridController = new DataGridController(
            $moduleRoutes,
            new AdminModuleAccessPolicy($authorization, $currentUser),
            $resolver,
            new DataGridQueryValidator(),
            $authorization,
            new AdminAuthorizationResponseHandler(
                new HttpRequestInspector(),
                new AdminRoutingConfiguration('/admin'),
            ),
            new AdminUiLocale($session, $translator),
            new Responses(new ResponseBuilder($http, $http)),
        );
    }

    public function getDatabase(): Database
    {
        return $this->database;
    }

    public function getTransport(): ModuleActionDispatcher
    {
        return $this->transport;
    }

    public function getEditor(): EditorDispatcher
    {
        return $this->editor;
    }

    public function getNewsEditor(): NewsEditor
    {
        $editor = $this->scope->get(NewsEditor::class);
        if (!$editor instanceof NewsEditor) {
            throw new RuntimeException('News editor is unavailable.');
        }

        return $editor;
    }

    public function getGridController(): DataGridController
    {
        return $this->gridController;
    }

    /**
     * @return CmsContentLifecycle<NewsData>
     */
    public function getLifecycle(): CmsContentLifecycle
    {
        $lifecycle = $this->scope->get('cms.news.lifecycle');
        if (!$lifecycle instanceof CmsContentLifecycle) {
            throw new RuntimeException('News lifecycle is unavailable.');
        }

        return $lifecycle;
    }

    public function getReadModel(): CmsContentReadModel
    {
        $reader = $this->scope->get('cms.news.read');
        if (!$reader instanceof CmsContentReadModel) {
            throw new RuntimeException('News read model is unavailable.');
        }

        return $reader;
    }

    public function getGrid(): CmsContentDataGrid
    {
        $grid = $this->scope->get('cms.news.grid_query');
        if (!$grid instanceof CmsContentDataGrid) {
            throw new RuntimeException('News grid query is unavailable.');
        }

        return $grid;
    }

    public function getPageProvider(): NewsModulePageProvider
    {
        return $this->scope->get(NewsModulePageProvider::class);
    }

    public function getUploads(): AdminFileUploadComponent
    {
        return $this->scope->get(AdminFileUploadComponent::class);
    }

    /**
     * Replaces super-admin access with the explicitly named role permissions
     *
     * @param list<string> $permissionCodes
     */
    public function usePermissions(array $permissionCodes): void
    {
        $this->database->statement('UPDATE system_role SET is_super_admin = 0 WHERE id = 1');
        $this->database->statement('DELETE FROM system_role_permission');
        $this->database->statement('DELETE FROM system_permission');

        foreach (array_values($permissionCodes) as $index => $code) {
            $permissionId = $index + 1;
            $this->database->statement(
                'INSERT INTO system_permission (id,code,name_key,module_code) VALUES (?,?,?,?)',
                [$permissionId, $code, $code, ModuleManifest::CODE],
            );
            $this->database->statement(
                'INSERT INTO system_role_permission (role_id,permission_id) VALUES (?,?)',
                [1, $permissionId],
            );
        }

        $this->authorization->invalidate();
    }

    /**
     * Returns the persisted News and audit state used by no-op and rollback proofs
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function contentSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'cms_news_article',
            'cms_news_article_translation',
            'cms_news_article_translation_tag',
            'cms_route',
            'system_audit_log',
        ] as $table) {
            $snapshot[$table] = $this->database->table($table)->orderBy('id')->getArray();
        }

        return $snapshot;
    }

    /**
     * Executes the registered HTTP DataGrid transport and decodes its JSON response
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function gridResponse(array $query): array
    {
        $response = $this->gridController->index(
            'news',
            (new ServerRequest('GET', '/'))->withQueryParams($query),
        );
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Grid request failed: ' . (string) $response->getBody());
        }

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Grid response must be a JSON object.');
        }

        return $data;
    }

    /**
     * Removes triggers, tables and committed data even after a failed scenario
     */
    public function close(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }

        $this->resetSchema();
        $this->connection->close();
    }

    private function migrate(
        DatabaseConfig $config,
        MysqlIdentifierEscaper $escaper,
        MysqlDatabaseDriver $driver,
    ): void {
        foreach ([
            'CREATE TABLE system_user (id BIGINT UNSIGNED PRIMARY KEY, email VARCHAR(255), first_name VARCHAR(255), last_name VARCHAR(255), active TINYINT, deleted_at DATETIME NULL)',
            'CREATE TABLE system_role (id BIGINT PRIMARY KEY, deleted_at DATETIME NULL, is_super_admin TINYINT)',
            'CREATE TABLE system_user_role (user_id BIGINT, role_id BIGINT)',
            'CREATE TABLE system_role_permission (role_id BIGINT, permission_id BIGINT)',
            'CREATE TABLE system_permission (id BIGINT PRIMARY KEY, code VARCHAR(255), name_key VARCHAR(255), module_code VARCHAR(255))',
            'CREATE TABLE system_user_permission (user_id BIGINT, permission_id BIGINT, effect VARCHAR(10))',
            'CREATE TABLE system_language (code VARCHAR(10) PRIMARY KEY, name VARCHAR(100), enabled TINYINT, is_default TINYINT, sort_order INT)',
            'CREATE TABLE system_audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, event_code VARCHAR(255), module_code VARCHAR(255), entity_type VARCHAR(255), entity_key VARCHAR(255), actor_type VARCHAR(30), actor_key VARCHAR(255), actor_user_id BIGINT UNSIGNED, payload TEXT, created_at DATETIME)',
            'CREATE TABLE cms_route (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(100), entity_id BIGINT UNSIGNED, locale VARCHAR(10), path VARCHAR(700), created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL, UNIQUE KEY uq_cms_route_target (module_code,entity_id,locale), UNIQUE KEY uq_cms_route_locale_path (locale,path))',
            'CREATE TABLE admin_editor_lock (resource_type VARCHAR(100), resource_id VARCHAR(100), owner_key VARCHAR(100), owner_user_id BIGINT, created_at DATETIME, updated_at DATETIME, UNIQUE KEY (resource_type,resource_id))',
        ] as $sql) {
            $this->database->statement($sql);
        }
        $this->database->statement("INSERT INTO system_user VALUES (7, 'test@example.test', 'Test', 'User', 1, NULL)");
        $this->database->statement('INSERT INTO system_role VALUES (1, NULL, 1)');
        $this->database->statement('INSERT INTO system_user_role VALUES (7, 1)');
        $this->database->statement("INSERT INTO system_language VALUES ('cs','Czech',1,1,1), ('en','English',1,0,2)");

        $schema = new Schema(
            new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)),
            $driver,
        );
        (new CreateNewsArticles())->up($schema);
        (new CreateCoreFiles())->up($schema);
    }

    private function createRouteReservations(Database $database): CmsRouteReservationService
    {
        $locales = new class implements PublicLocaleRegistryInterface {
            public function snapshot(): PublicLocaleSnapshot
            {
                return new PublicLocaleSnapshot('cs', ['cs', 'en'], ['cs', 'en']);
            }
        };
        $guard = new CmsReservedPathGuard(new Router(), new ServerRequest('GET', '/'), $locales);

        return new CmsRouteReservationService(
            new CmsRouteRepository($database),
            new CmsRouteCandidateResolver(),
            $guard,
        );
    }

    private function createRouter(): Router
    {
        $router = new Router();
        $target = ControllerAction::for(NewsModulePageProvider::class, 'editor');
        $router->getNamed('admin.cms.module.edit', '/admin/cms/{module}/{id}', $target);
        $router->postNamed('admin.cms.module.ajax.entity', '/admin/cms/{module}/{id}/ajax', $target);

        foreach ([
            'admin.cms.module.update' => '/admin/cms/{module}/{id}/save',
            'admin.cms.module.index' => '/admin/cms/{module}',
            'admin.file.chunk.start' => '/admin/files/{module}/{entity}/{usage}/start',
            'admin.file.chunk.append' => '/admin/files/{uploadId}/append',
            'admin.file.chunk.complete' => '/admin/files/{uploadId}/complete',
            'admin.file.chunk.abort' => '/admin/files/{uploadId}/abort',
            'admin.file.collection.index' => '/admin/files/{module}/{entity}/{usage}',
            'admin.file.collection.remove' => '/admin/files/{module}/{entity}/{usage}/{file}/remove',
            'admin.file.collection.reorder' => '/admin/files/{module}/{entity}/{usage}/reorder',
            'admin.file.collection.rename.modal' => '/admin/files/{module}/{entity}/{usage}/{file}/rename',
            'api.file.download' => '/api/file/download/{fileId}',
            'api.image.show' => '/images/{variant}/{fileId}',
        ] as $routeName => $path) {
            $router->getNamed($routeName, $path, $target);
        }

        return $router;
    }

    private function registerFileServices(Container $container, UrlGenerator $urls): void
    {
        $variants = new ImageVariantRegistry();
        foreach (['admin-datagrid', 'admin-thumb', 'admin-preview'] as $variant) {
            $variants->register(
                $variant,
                new ImageVariantDefinition(
                    new ImageDimensions(32, 32),
                    ImageFormat::Webp,
                    ImageQuality::fromInt(82),
                ),
            );
        }
        $container->instance(ImageUrlGenerator::class, new ImageUrlGenerator($urls, $variants));

        $usages = new AdminFileUsageRegistry();
        $usages->registerCapabilities(ModuleManifest::CODE, (new ModuleManifest())->fileCapabilities());
        $container->instance(AdminFileUsageRegistry::class, $usages);
        $container->instance(UploadConfig::class, new UploadConfig(
            ['admin-file' => new FileUploadProfileConfig('test', 1024, ['pdf'])],
            ['admin-image' => new ImageUploadProfileConfig('test', 1024, ['jpg'], true, null, null, null, null)],
        ));
    }

    private function createTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            private ?string $locale = 'en';

            public function setLocale(?string $locale): self
            {
                $this->locale = $locale;

                return $this;
            }

            public function locale(): ?string
            {
                return $this->locale;
            }

            public function get(string $key, array $replacements = [], ?string $locale = null): string
            {
                return $key;
            }

            public function group(string $group, ?string $locale = null): array
            {
                return [];
            }

            public function all(?string $locale = null): array
            {
                return [];
            }
        };
    }

    private function createSession(): SessionInterface
    {
        return new class implements SessionInterface {
            /**
             * @var array<string,mixed>
             */
            private array $values = ['admin.auth.user_id' => 7];

            private bool $started = false;

            public function start(): void
            {
                $this->started = true;
            }

            public function started(): bool
            {
                return $this->started;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function remove(string $key): void
            {
                unset($this->values[$key]);
            }

            public function clear(): void
            {
                $this->values = [];
            }

            public function destroy(): void
            {
                $this->clear();
                $this->started = false;
            }

            public function regenerate(bool $deleteOldSession = true): void {}
        };
    }

    private function resetSchema(): void
    {
        foreach ($this->database->select('SHOW TRIGGERS') as $trigger) {
            $name = (string) ($trigger['Trigger'] ?? '');
            if ($name !== '') {
                $this->database->statement('DROP TRIGGER `' . str_replace('`', '``', $name) . '`');
            }
        }

        $this->database->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->database->select('SHOW TABLES') as $row) {
                $table = (string) array_values($row)[0];
                $this->database->statement('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $this->database->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
