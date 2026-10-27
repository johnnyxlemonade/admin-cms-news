<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

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
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\DataGrid\Query\DataGridQueryValidator;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
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
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\Content\CmsContentLifecycle;
use Lemonade\Cms\Content\CmsContentReadModel;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Cms\News\DataGrid\NewsDataGrid;
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
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use Lemonade\Framework\Http\Request\HttpRequestInspector;
use Lemonade\Framework\Http\Response\Responses;
use Lemonade\Framework\Image\Value\ImageDimensions;
use Lemonade\Framework\Image\Value\ImageFormat;
use Lemonade\Framework\Image\Value\ImageQuality;
use Lemonade\Framework\Image\Value\ImageVariantDefinition;
use Lemonade\Framework\Localization\Config\LocalizationConfig;
use Lemonade\Framework\Localization\Config\LocalizationUrlConfig;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Observability\Benchmark\Benchmark;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Overuje News pres registrovany Admin action a editor transport
 */
final class NewsAdminTransportTest extends TestCase
{
    public function testCreateSavePublishAndUnpublishThroughActions(): void
    {
        $name = getenv('NEWS_INTEGRATION_DATABASE');
        self::assertIsString($name);
        self::assertStringStartsWith('cms_news_test_', $name);
        $config = DatabaseConfig::fromArray(['driver' => 'mysql', 'host' => 'db', 'database' => $name, 'username' => 'db', 'password' => 'db']);
        $connection = new MysqlConnection($config, new Benchmark());
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver($connection, $config, $escaper, new IdentifierProtector($escaper));
        $db = new Database($connection, $driver);
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
            $db->statement($sql);
        }
        $db->statement("INSERT INTO system_user VALUES (7, 'test@example.test', 'Test', 'User', 1, NULL)");
        $db->statement('INSERT INTO system_role VALUES (1, NULL, 1)');
        $db->statement('INSERT INTO system_user_role VALUES (7, 1)');
        $db->statement("INSERT INTO system_language VALUES ('cs','Czech',1,1,1), ('en','English',1,0,2)");
        (new CreateNewsArticles())->up(new Schema(new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)), $driver));
        (new CreateCoreFiles())->up(new Schema(new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)), $driver));
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static fn(string $key): mixed => $key === 'admin.auth.user_id' ? 7 : null);
        $audit = new AuditLogService($db);
        $authentication = new AuthenticationService(new LocalAuthenticationProvider($db), $session, $db, new CsrfTokenManager($session), $audit);
        $user = new CurrentUserProvider($authentication, $db);
        $authorization = new AuthorizationService($user, $db);
        $locks = new EditorLockManager($db, $user, $this->createMock(EditorLockOwnerReleaserInterface::class));
        $languages = new LanguageRegistry($db, new CacheManager(new ArrayCacheItemPool()));
        $runtime = new CmsAdminRuntime(new TransactionalEventProcessor($db, $audit, new DomainEventDispatcher(new NullLogger())), $languages, new LocalUserDirectory($db));
        $localeRegistry = new class implements PublicLocaleRegistryInterface {
            public function snapshot(): PublicLocaleSnapshot
            {
                return new PublicLocaleSnapshot('cs', ['cs', 'en'], ['cs', 'en']);
            }
        };
        $router = new Router();
        $router->getNamed('admin.content.module.edit', '/admin/content/{module}/{id}', ControllerAction::for(self::class, 'testCreateSavePublishAndUnpublishThroughActions'));
        $router->postNamed('admin.content.module.ajax.entity', '/admin/content/{module}/{id}/ajax', ControllerAction::for(self::class, 'testCreateSavePublishAndUnpublishThroughActions'));
        foreach ([
            'admin.content.module.update' => '/admin/content/{module}/{id}/save',
            'admin.content.module.index' => '/admin/content/{module}',
            'admin.file.chunk.start' => '/admin/files/{module}/{entity}/{usage}/start',
            'admin.file.chunk.append' => '/admin/files/{uploadId}/append',
            'admin.file.chunk.complete' => '/admin/files/{uploadId}/complete',
            'admin.file.chunk.abort' => '/admin/files/{uploadId}/abort',
            'admin.file.collection.index' => '/admin/files/{module}/{entity}/{usage}',
            'admin.file.collection.remove' => '/admin/files/{module}/{entity}/{usage}/{file}/remove',
            'admin.file.collection.reorder' => '/admin/files/{module}/{entity}/{usage}/reorder',
            'admin.file.collection.rename.modal' => '/admin/files/{module}/{entity}/{usage}/{file}/rename',
            'api.file.download' => '/files/{file}',
            'api.image.show' => '/images/{module}/{variant}/{image}',
        ] as $routeName => $path) {
            $router->getNamed($routeName, $path, ControllerAction::for(self::class, 'testCreateSavePublishAndUnpublishThroughActions'));
        }
        $guard = new CmsReservedPathGuard(new Router(), new ServerRequest('GET', '/'), $localeRegistry);
        $routes = new CmsRouteReservationService(new CmsRouteRepository($db), new CmsRouteCandidateResolver(), $guard);
        $modules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $modules->register(new ModuleManifest());
        $moduleRoutes = new AdminModuleRouteResolver($modules);
        $grids = new DataGridRegistry();
        $editors = new EditorRegistry();
        $pages = new ModulePageRegistry();
        $actions = new ModuleActionRegistry();
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(static fn(string $key): string => $key);
        $container = new Container();
        foreach ([Database::class => $db, \Lemonade\Framework\Database\DatabaseDriverInterface::class => $driver, CmsAdminRuntime::class => $runtime, CmsRouteReservationService::class => $routes, LanguageRegistry::class => $languages, LocalActorGuard::class => new LocalActorGuard($user), AuthorizationService::class => $authorization, AdminModuleRouteResolver::class => $moduleRoutes, UrlGenerator::class => new UrlGenerator($router), TranslatorInterface::class => $translator] as $id => $service) {
            $container->instance($id, $service);
        }
        $provider = new CmsNewsServiceProvider();
        $provider->register($container);
        $editors->register('cms.news', \Lemonade\Cms\News\Editor\NewsEditor::class);
        $pages->registerEditor('cms.news', \Lemonade\Cms\News\NewsModulePageProvider::class);
        CmsNewsServiceProvider::adminProvider()->registerActions($actions);
        $grids->register('cms.news', NewsDataGrid::class);
        $container->instance(ModuleActionPresentationFactory::class, new ModuleActionPresentationFactory($actions, $moduleRoutes, $authorization, $translator, new UrlGenerator($router)));
        $variants = new ImageVariantRegistry();
        foreach (['datagrid', 'thumbnail', 'preview'] as $variant) {
            $variants->register($variant, new ImageVariantDefinition(new ImageDimensions(32, 32), ImageFormat::Webp, ImageQuality::fromInt(82)));
        }
        $container->instance(ImageUrlGenerator::class, new ImageUrlGenerator(new UrlGenerator($router), $variants));
        $usages = new AdminFileUsageRegistry();
        $usages->registerCapabilities('cms.news', (new ModuleManifest())->fileCapabilities());
        $container->instance(AdminFileUsageRegistry::class, $usages);
        $container->instance(UploadConfig::class, new UploadConfig(
            ['admin-file' => new FileUploadProfileConfig('test', 1024, ['pdf'])],
            ['admin-image' => new ImageUploadProfileConfig('test', 1024, ['jpg'], true, null, null, null, null)],
        ));
        $scope = $container->beginScope(ScopeKind::Request);
        $resolver = new ModuleProviderResolver($grids, $editors, $pages, $actions, $scope);
        $validation = new FormValidation($translator, new ValidationRuleResolver(new RuleRegistry(), $scope));
        $dispatcher = new EditorDispatcher($modules, $resolver, $authorization, $validation, $locks);
        $container->instance(EditorDispatcher::class, $dispatcher);
        $transport = new ModuleActionDispatcher($moduleRoutes, new AdminModuleAccessPolicy($authorization, $user), $resolver, $authorization, $locks, $pages);
        $created = $transport->dispatch('news', null, ['action' => 'create', 'payload' => []]);
        self::assertTrue($created->successful());
        $id = $created->data()['id'];
        $payload = ['locale' => 'cs', 'title' => 'První zpráva', 'tags' => 'Praha, Brno, Praha', 'publication_state' => '2', 'published_at' => '2027-01-22T12:30'];
        $saved = $transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload]);
        self::assertTrue($saved->successful());
        self::assertSame(['publication_state' => '2', 'published_at' => '2027-01-22T12:30'], $saved->data()['editorFields']);
        $row = $db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'cs')->first() ?? [];
        self::assertNotEmpty($row);
        self::assertSame('První zpráva', $row['title']);
        self::assertSame(2, (int) $row['publication_state']);
        self::assertSame('prvni-zprava', ($db->table('cms_route')->where('entity_id', $id)->first() ?? [])['path'] ?? null);
        $before = $db->table('system_audit_log')->getArray();
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload])->successful());
        self::assertCount(count($before), $db->table('system_audit_log')->getArray());
        $payload['publication_state'] = '1';
        $payload['published_at'] = '';
        $unpublished = $transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload]);
        self::assertTrue($unpublished->successful());
        self::assertSame(['publication_state' => '1', 'published_at' => ''], $unpublished->data()['editorFields']);
        self::assertNull(($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'cs')->first() ?? [])['published_at'] ?? null);
        self::assertSame(1, (int) (($db->table('cms_news_article_translation')->where('article_id', $id)->first() ?? [])['publication_state'] ?? 0));
        self::assertContains('cms.news.unpublished', array_column($db->table('system_audit_log')->getArray(), 'event_code'));
        $tags = $db->table('cms_news_article_translation_tag')->where('translation_id', $row['id'])->orderBy('sort_order', 'ASC')->getArray();
        self::assertSame(['Praha', 'Brno'], array_column($tags, 'name'));
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'change-url', 'payload' => ['locale' => 'cs', 'url_segment' => 'nova-cesta']])->successful());
        self::assertSame('nova-cesta', ($db->table('cms_route')->where('entity_id', $id)->where('locale', 'cs')->first() ?? [])['path'] ?? null);
        $english = ['locale' => 'en', 'title' => 'English news'];
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'save', 'payload' => $english])->successful());
        self::assertSame('English news', ($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'en')->first() ?? [])['title'] ?? null);
        $payload['publication_state'] = '2';
        $republished = $transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload]);
        self::assertTrue($republished->successful());
        $publishedAt = ($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'cs')->first() ?? [])['published_at'] ?? null;
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', (string) $publishedAt);
        self::assertSame(['publication_state' => '2', 'published_at' => str_replace(' ', 'T', substr((string) $publishedAt, 0, 16))], $republished->data()['editorFields']);

        $db->statement('UPDATE system_role SET is_super_admin = 0 WHERE id = 1');
        $db->statement("INSERT INTO system_permission VALUES (1,'cms.news.view_own','view','cms.news'), (2,'cms.news.edit_own','edit','cms.news')");
        $db->statement('INSERT INTO system_role_permission VALUES (1,1), (1,2)');
        $authorization->invalidate();
        try {
            $transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload]);
            self::fail('Publication without permission must be rejected.');
        } catch (\Lemonade\Admin\Action\Exception\ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
        }
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'save', 'payload' => $english])->successful());
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'save', 'payload' => ['locale' => 'cs', 'title' => 'Updated title']])->successful());
        self::assertSame(2, (int) (($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'cs')->first() ?? [])['publication_state'] ?? 0));
        self::assertSame('nova-cesta', ($db->table('cms_route')->where('entity_id', $id)->where('locale', 'cs')->first() ?? [])['path'] ?? null);
        $db->statement('UPDATE system_role SET is_super_admin = 1 WHERE id = 1');
        $authorization->invalidate();

        $db->statement("CREATE TRIGGER news_audit_failure BEFORE INSERT ON system_audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'");
        try {
            $transport->dispatch('news', $id, ['action' => 'save', 'payload' => ['locale' => 'en', 'title' => 'Changed title', 'author_name' => 'Changed']]);
            self::fail('Audit failure must roll back the mutation.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('audit failure', $exception->getMessage());
        }
        $db->statement('DROP TRIGGER news_audit_failure');
        self::assertSame('English news', ($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'en')->first() ?? [])['title'] ?? null);
        self::assertNull(($db->table('cms_news_article')->where('id', $id)->first() ?? [])['author_name'] ?? null);

        $db->statement("INSERT INTO system_user VALUES (9, 'owner@example.test', 'New', 'Owner', 1, NULL)");
        $invalidUrl = $transport->dispatch('news', $id, ['action' => 'change-url', 'payload' => ['locale' => []]]);
        self::assertSame(['url_segment' => 'admin.url_segment.invalid'], $invalidUrl->errors());
        $invalidOwner = $transport->dispatch('news', $id, ['action' => 'assign-owner', 'payload' => ['owner_id' => 0]]);
        self::assertSame(['owner_id' => 'news.validation.owner_invalid'], $invalidOwner->errors());
        foreach ([null, 999999] as $missingId) {
            try {
                $result = $transport->dispatch('news', $missingId, ['action' => 'delete', 'payload' => []]);
                self::assertFalse($result->successful());
            } catch (\Lemonade\Admin\Action\Exception\ModuleActionException $exception) {
                self::assertSame(403, $exception->status());
            }
        }
        try {
            $transport->dispatch('news', $id, ['action' => 'delete', 'payload' => 'invalid']);
            self::fail('Invalid transport payload must be rejected.');
        } catch (\Lemonade\Admin\Action\Exception\ModuleActionException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame(\Lemonade\Admin\Http\AdminErrorCode::VALIDATION_FAILED, $exception->error());
        }
        $url = $transport->dispatch('news', $id, ['action' => 'change-url', 'payload' => ['locale' => 'cs', 'url_segment' => 'finalni-cesta']]);
        self::assertSame('admin.url_segment.updated', $url->messageKey());
        self::assertSame(['urlSegment' => 'finalni-cesta'], $url->data());
        $db->statement("CREATE TRIGGER news_action_audit_failure BEFORE INSERT ON system_audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'");
        try {
            $transport->dispatch('news', $id, ['action' => 'delete', 'payload' => []]);
            self::fail('Audit failure must roll back deletion.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('audit failure', $exception->getMessage());
        }
        $db->statement('DROP TRIGGER news_action_audit_failure');
        self::assertNull(($db->table('cms_news_article')->where('id', $id)->first() ?? [])['deleted_at'] ?? null);
        self::assertSame('finalni-cesta', ($db->table('cms_route')->where('entity_id', $id)->where('locale', 'cs')->first() ?? [])['path'] ?? null);

        $db->statement('UPDATE system_role SET is_super_admin = 0 WHERE id = 1');
        $db->statement("INSERT INTO system_permission VALUES (3,'cms.news.delete_own','delete','cms.news'), (4,'cms.news.restore_own','restore','cms.news'), (5,'cms.news.assign_owner','assign','cms.news')");
        $db->statement('INSERT INTO system_role_permission VALUES (1,3), (1,4), (1,5)');
        $authorization->invalidate();
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'assign-owner', 'payload' => ['owner_id' => 7]])->successful());
        $deleted = $transport->dispatch('news', $id, ['action' => 'delete', 'payload' => []]);
        self::assertSame('news.actions.deleted', $deleted->messageKey());
        self::assertTrue($deleted->refreshGrid());
        $restored = $transport->dispatch('news', $id, ['action' => 'restore', 'payload' => []]);
        self::assertSame('news.actions.restored', $restored->messageKey());
        self::assertTrue($restored->refreshGrid());
        $db->statement('UPDATE cms_news_article SET owner_id = 9 WHERE id = ' . (int) $id);
        foreach (['change-url', 'delete', 'restore', 'assign-owner'] as $action) {
            try {
                $transport->dispatch('news', $id, ['action' => $action, 'payload' => []]);
                self::fail('Own permission must reject another owner.');
            } catch (\Lemonade\Admin\Action\Exception\ModuleActionException $exception) {
                self::assertSame(403, $exception->status());
            }
        }
        $db->statement("INSERT INTO system_permission VALUES (6,'cms.news.view','view','cms.news'), (7,'cms.news.edit','edit','cms.news'), (8,'cms.news.delete','delete','cms.news'), (9,'cms.news.restore','restore','cms.news')");
        $db->statement('INSERT INTO system_role_permission VALUES (1,6), (1,7), (1,8), (1,9)');
        $authorization->invalidate();
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'change-url', 'payload' => ['locale' => 'cs', 'url_segment' => 'globalni-cesta']])->successful());
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'delete', 'payload' => []])->successful());
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'restore', 'payload' => []])->successful());
        $db->statement('DELETE FROM system_role_permission WHERE permission_id = 5');
        $authorization->invalidate();
        try {
            $transport->dispatch('news', $id, ['action' => 'assign-owner', 'payload' => ['owner_id' => 7]]);
            self::fail('Assign owner requires its separate permission.');
        } catch (\Lemonade\Admin\Action\Exception\ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
            self::assertSame(\Lemonade\Admin\Http\AdminErrorCode::PERMISSION_DENIED, $exception->error());
        }
        $db->statement('INSERT INTO system_role_permission VALUES (1,5)');
        $authorization->invalidate();
        $assignedByGlobalView = $transport->dispatch('news', $id, ['action' => 'assign-owner', 'payload' => ['owner_id' => 7]]);
        self::assertSame('news.actions.owner_assigned', $assignedByGlobalView->messageKey());
        self::assertTrue($assignedByGlobalView->refreshPage());
        $db->statement('UPDATE cms_news_article SET owner_id = 7 WHERE id = ' . (int) $id);
        $db->statement('UPDATE system_role SET is_super_admin = 1 WHERE id = 1');
        $authorization->invalidate();

        self::assertTrue($transport->dispatch('news', $id, ['action' => 'delete', 'payload' => []])->successful());
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'restore', 'payload' => []])->successful());
        self::assertSame(1, (int) (($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'en')->first() ?? [])['publication_state'] ?? 0));
        $assigned = $transport->dispatch('news', $id, ['action' => 'assign-owner', 'payload' => ['owner_id' => 9]]);
        self::assertTrue($assigned->successful());
        self::assertSame('news.actions.owner_assigned', $assigned->messageKey());
        self::assertTrue($assigned->refreshPage());
        self::assertSame(9, (int) (($db->table('cms_news_article')->where('id', $id)->first() ?? [])['owner_id'] ?? 0));
        foreach (['deleted', 'restored', 'owner_transferred'] as $event) {
            self::assertContains('cms.news.' . $event, array_column($db->table('system_audit_log')->getArray(), 'event_code'));
        }

        $http = new Psr17Factory();
        $gridController = new DataGridController(
            $moduleRoutes,
            new AdminModuleAccessPolicy($authorization, $user),
            $resolver,
            new DataGridQueryValidator(),
            $authorization,
            new AdminAuthorizationResponseHandler(new HttpRequestInspector(), new AdminRoutingConfiguration('/admin')),
            new AdminUiLocale(new LocalizationConfig('cs', 'cs', ['cs', 'en'], new LocalizationUrlConfig(false, '', '', 'locale', false)), $session, $translator),
            new Responses(new ResponseBuilder($http, $http)),
        );
        $this->assertSharedReading($db, $scope, $dispatcher, $authorization, $gridController);
    }

    /**
     * Overuje sdilene cteni na skutecnem schematu modulu i v Admin transportu
     */
    private function assertSharedReading(Database $db, ContainerInterface $scope, EditorDispatcher $editor, AuthorizationService $authorization, DataGridController $gridController): void
    {
        $db->statement('UPDATE system_role SET is_super_admin = 1 WHERE id = 1');
        $authorization->invalidate();
        $db->statement("INSERT INTO system_user VALUES (10, 'deleted@example.test', 'Deleted', 'Actor', 0, '2026-01-01'), (11, 'email@example.test', '', '', 1, NULL)");
        $db->statement("INSERT INTO system_language VALUES ('de','German',0,0,3), ('fr','French',1,0,4), ('it','Italian',0,0,5)");

        /** @var CmsContentLifecycle<NewsData> $lifecycle */
        $lifecycle = $scope->get('cms.news.lifecycle');
        /** @var CmsContentReadModel $reader */
        $reader = $scope->get('cms.news.read');
        /** @var CmsContentDataGrid $grid */
        $grid = $scope->get('cms.news.grid_query');
        $domain = NewsData::fromEditor(['author_name' => 'Domain Author']);
        $first = $lifecycle->createDraft(7, 'cs');
        $second = $lifecycle->createDraft(9, 'cs');
        $deleted = $lifecycle->createDraft(7, 'cs');
        foreach ([$first => 'Alpha', $second => 'Bravo', $deleted => 'Charlie'] as $id => $title) {
            $lifecycle->save(9, $id, CmsContentInput::fromEditor([
                'locale' => 'cs', 'title' => 'Read fixture ' . $title,
                'recommended' => '1', 'show_author' => '0', 'sharing_enabled' => '0',
                'show_published_at' => '0', 'show_thumbnail' => '0', 'show_reading_time' => '0', 'show_tags' => '0',
                'page_title' => 'SEO ' . $title, 'summary' => 'Summary ' . $title,
                'content' => '<p>Content ' . $title . '</p>', 'meta_description' => 'Description ' . $title,
                'tags' => ['Zulu', 'Alpha'], 'publication_state' => $id === $second ? '1' : '2',
                'published_at' => $id === $second ? '' : '2028-01-02T10:30',
            ]), $domain);
        }
        $lifecycle->save(9, $first, CmsContentInput::fromEditor(['locale' => 'en', 'title' => 'Read fixture English', 'tags' => ['English tag'], 'recommended' => '1', 'show_author' => '0', 'sharing_enabled' => '0', 'show_published_at' => '0', 'show_thumbnail' => '0', 'show_reading_time' => '0', 'show_tags' => '0']), $domain);
        $lifecycle->changeUrlSegment(9, $first, 'cs', 'read-fixture-canonical');
        $db->table('cms_news_article_translation')->insert(['article_id' => $first, 'locale' => 'de', 'title' => 'Read fixture German', 'publication_state' => 1]);
        // Two equal positions exercise the existing tag-id tie breaker.
        $cs = $reader->translation($first, 'cs');
        self::assertNotNull($cs);
        $db->table('cms_news_article_translation_tag')->where('translation_id', $cs['id'])->set(['sort_order' => 0])->update();
        $lifecycle->delete(10, $deleted);
        $db->table('cms_news_article')->whereIn('id', [$first, $second])->set(['updated_at' => '2028-01-02 11:00:00'])->update();

        self::assertTrue($reader->exists($first));
        self::assertFalse($reader->exists($deleted));
        self::assertFalse($reader->exists(999999));
        self::assertSame(7, $reader->ownerId($deleted));
        self::assertNull($reader->ownerId(999999));
        self::assertNull($reader->findForEditor($deleted));
        self::assertNull($reader->findForEditor(999999));
        self::assertNull($reader->metadata($deleted));
        self::assertNull($reader->translation($first, 'fr'));
        self::assertNull($reader->publication($first, 'fr'));
        self::assertNotNull($reader->translation($deleted, 'cs'));
        self::assertSame('read-fixture-canonical', $cs['canonical_path']);
        self::assertSame(2, (int) ($reader->publication($first, 'cs')['publication_state'] ?? -1));
        self::assertSame('2028-01-02 10:30:00', $reader->publication($first, 'cs')['published_at'] ?? null);
        $loaded = $editor->updateData('cms.news', $first);
        $article = $loaded->data()['article'];
        self::assertSame($reader->findForEditor($first), $article);
        self::assertNotNull($article);
        self::assertSame('Domain Author', $article['author_name']);
        self::assertSame('Test User', $article['created_by_name']);
        self::assertSame('New Owner', $article['updated_by_name']);
        self::assertSame('Test User', $article['owner_name']);
        self::assertSame('—', $article['deleted_by_name']);
        self::assertSame('2028-01-02 11:00:00', $article['updated_at']);
        $translations = array_column($article['translations'], null, 'locale');
        self::assertSame(['cs', 'de', 'en'], array_keys($translations));
        self::assertSame(['Zulu', 'Alpha'], $translations['cs']['tags']);
        self::assertSame(['English tag'], $translations['en']['tags']);
        self::assertSame([], $translations['de']['tags']);
        self::assertSame('SEO Alpha', $translations['cs']['page_title']);
        self::assertSame('Summary Alpha', $translations['cs']['summary']);
        self::assertSame('<p>Content Alpha</p>', $translations['cs']['content']);
        self::assertSame('Description Alpha', $translations['cs']['meta_description']);
        self::assertSame('read-fixture-canonical', $translations['cs']['canonical_path']);
        self::assertNull($translations['de']['canonical_path']);
        self::assertSame(1, (int) $article['recommended']);
        foreach (['show_author', 'sharing_enabled', 'show_published_at', 'show_thumbnail', 'show_reading_time', 'show_tags'] as $field) {
            self::assertSame(0, (int) $article[$field]);
        }
        foreach ($reader->metadata($first) ?? [] as $column => $value) {
            self::assertSame($value, $article[$column]);
        }
        foreach (['thumbnail', 'gallery', 'attachment'] as $position => $usage) {
            $db->table('system_file')->insert([
                'id' => $position + 1, 'module_code' => 'cms.news', 'entity_id' => $first, 'usage' => $usage,
                'kind' => $usage === 'attachment' ? 'file' : 'image', 'original_filename' => $usage . '.jpg',
                'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100,
                'source_version' => $usage === 'attachment' ? null : 'v1',
            ]);
        }
        /** @var NewsModulePageProvider $pages */
        $pages = $scope->get(NewsModulePageProvider::class);
        $page = $pages->editor($loaded, [], [], 'cs', ['locale' => 'cs']);
        self::assertSame('admin::editor', $page->view());
        /** @var AdminEditorRenderContext $context */
        $context = $page->data()['adminEditorContext'];
        self::assertSame('Read fixture Alpha', $context->values()['title']);
        self::assertSame('read-fixture-canonical', $context->values()['url_segment']);
        self::assertSame(['Zulu', 'Alpha'], $context->values()['tags']);
        self::assertSame('Test User', $context->values()['created_by_name']);
        self::assertSame('Domain Author', $context->values()['author_name']);
        $germanPage = $pages->editor($loaded, [], [], 'cs', ['locale' => 'de']);
        /** @var AdminEditorRenderContext $germanContext */
        $germanContext = $germanPage->data()['adminEditorContext'];
        self::assertSame('Read fixture German', $germanContext->values()['title']);
        self::assertSame('', $germanContext->values()['url_segment']);
        /** @var AdminFileUploadComponent $uploads */
        $uploads = $scope->get(AdminFileUploadComponent::class);
        foreach (['thumbnail', 'gallery', 'attachment'] as $usage) {
            self::assertSame($usage . '.jpg', $uploads->collectionState('cms.news', $first, $usage)[0]['original_filename']);
        }
        $locales = $loaded->data()['locales'];
        self::assertSame(['cs', 'en', 'de', 'fr'], array_column($locales, 'code'));
        self::assertSame([1, 1, 0, 1], array_map('intval', array_column($locales, 'enabled')));
        self::assertSame([1, 1, 1, 0], array_map('intval', array_column($locales, 'has_translation')));
        self::assertSame(['cs', 'en', 'de', 'fr'], array_column($reader->dataGridLocales(), 'code'));
        self::assertSame(['cs', 'en', 'fr'], array_column($reader->enabledLocales(), 'code'));
        $tagNames = $reader->tagNamesForLocale('cs');
        self::assertSame(['Alpha', 'Zulu'], array_values(array_intersect($tagNames, ['Zulu', 'Alpha'])));
        self::assertContains('English tag', $reader->tagNamesForLocale('en'));

        $read = static fn(string $search = 'Read fixture', array $filters = [], string $sort = 'title', string $direction = 'asc', int $page = 1, int $size = 25, ?int $owner = null) => $grid->listForDataGrid(
            new DataGridQuery($page, $size, $sort, $direction, $search, $filters),
            'cs',
            $owner,
        );
        $ids = static fn($page): array => array_map('intval', array_column($page->items(), 'id'));
        self::assertSame([$first, $second], $ids($read()));
        self::assertSame([$first], $ids($read(owner: 7)));
        self::assertSame([$second], $ids($read(owner: 9)));
        self::assertSame([], $ids($read(owner: 11)));
        self::assertSame([$first], $ids($read('read-fixture-canonical')));
        self::assertSame([], $ids($read('no matching record')));
        self::assertSame([$first], $ids($read(filters: ['state' => 'published'])));
        self::assertSame([$second], $ids($read(filters: ['state' => 'draft'])));
        self::assertSame([], $ids($read(filters: ['state' => 'new'])));
        self::assertSame([$first], $ids($read(filters: ['locale' => 'de'])));
        self::assertSame([], $ids($read(filters: ['locale' => 'fr'])));
        self::assertSame([$second, $first], $ids($read(direction: 'desc')));
        self::assertSame([$second, $first], $ids($read(sort: 'updatedAt')));
        self::assertSame([$first, $second], $ids($read(sort: 'untrusted SQL expression', direction: 'desc')));
        self::assertSame([$second], $ids($read(page: 2, size: 1)));
        self::assertSame(2, $read(page: 2, size: 1)->total());
        self::assertSame([], $ids($read(page: 3, size: 1)));
        $deletedPage = $read(filters: ['status' => 'deleted'], owner: 7);
        self::assertSame([$deleted], $ids($deletedPage));
        self::assertSame('Deleted Actor', $deletedPage->items()[0]['deleted_by_name']);
        self::assertSame('read-fixture-charlie', $deletedPage->items()[0]['canonical_path']);
        self::assertSame([], $ids($read(filters: ['status' => 'deleted'], owner: 9)));
        $firstRow = $read()->items()[0];
        foreach (['owner_id', 'created_by', 'updated_by', 'created_by_name', 'updated_by_name', 'owner_name', 'created_at', 'updated_at', 'author_name'] as $field) {
            self::assertSame($article[$field], $firstRow[$field]);
        }
        $db->table('cms_news_article')->where('id', $second)->set(['owner_id' => 11, 'updated_by' => null])->update();
        $fallback = $read()->items()[1];
        self::assertSame('email@example.test', $fallback['owner_name']);
        self::assertSame('—', $fallback['updated_by_name']);
        $db->table('cms_news_article')->where('id', $second)->set(['owner_id' => 9])->update();

        $response = $this->gridResponse($gridController, ['q' => 'Read fixture', 'sort' => 'title', 'direction' => 'asc', 'pageSize' => 1, 'page' => 2]);
        self::assertSame([$second], array_map('intval', array_column($response['items'], 'id')));
        self::assertSame(['enabled' => true, 'page' => 2, 'perPage' => 1, 'total' => 2, 'pages' => 2], $response['pagination']);
        self::assertContains('owner', array_column($response['columns'], 'key'));
        $inactive = $this->gridResponse($gridController, ['locale' => 'de']);
        self::assertSame([$first], array_map('intval', array_column($inactive['items'], 'id')));
        $trash = $this->gridResponse($gridController, ['q' => 'Read fixture', 'status' => 'deleted']);
        self::assertSame([$deleted], array_map('intval', array_column($trash['items'], 'id')));
        self::assertSame('Deleted Actor', $trash['items'][0]['cells']['deletedAt'][0]['secondary']);

        $db->statement('UPDATE system_role SET is_super_admin = 0 WHERE id = 1');
        $db->statement('DELETE FROM system_role_permission');
        $db->statement("INSERT INTO system_permission VALUES (100,'cms.news.view_own','view','cms.news'), (101,'cms.news.edit_own','edit','cms.news')");
        $db->statement('INSERT INTO system_role_permission VALUES (1,100), (1,101)');
        $authorization->invalidate();
        $own = $this->gridResponse($gridController, ['q' => 'Read fixture']);
        self::assertSame([$first], array_map('intval', array_column($own['items'], 'id')));
        self::assertSame(1, $own['pagination']['total']);
        self::assertSame($first, (int) $editor->updateData('cms.news', $first)->data()['article']['id']);
        try {
            $editor->updateData('cms.news', $second);
            self::fail('Own editor permission must reject a foreign record.');
        } catch (\Lemonade\Admin\Editor\Exception\EditorCapabilityException $exception) {
            self::assertSame(403, $exception->status());
        }
        $ownTrash = $this->gridResponse($gridController, ['q' => 'Read fixture', 'status' => 'deleted']);
        self::assertSame([$deleted], array_map('intval', array_column($ownTrash['items'], 'id')));
        $db->statement('DELETE FROM system_role_permission');
        $authorization->invalidate();
        self::assertSame(403, $gridController->index('news', new ServerRequest('GET', '/'))->getStatusCode());
        $db->statement('UPDATE system_role SET is_super_admin = 1 WHERE id = 1');
        $authorization->invalidate();
        $lifecycle->restore(7, $deleted);
        self::assertNotNull($reader->findForEditor($deleted));
        self::assertSame(1, (int) ($reader->publication($deleted, 'cs')['publication_state'] ?? -1));
        self::assertSame([], $ids($read(filters: ['status' => 'deleted'])));
    }

    /**
     * Nacte skutecny JSON transport registrovaneho DataGridu
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function gridResponse(DataGridController $controller, array $query): array
    {
        $response = $controller->index('news', (new ServerRequest('GET', '/'))->withQueryParams($query));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
