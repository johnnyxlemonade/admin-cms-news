<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Admin\Action\ModuleActionDispatcher;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Auth\AuthenticationService;
use Lemonade\Admin\Auth\CurrentUserProvider;
use Lemonade\Admin\Auth\LocalAuthenticationProvider;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Cms\Content\CmsAdminRuntime;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Editor\Lock\EditorLockManager;
use Lemonade\Admin\Editor\Lock\EditorLockOwnerReleaserInterface;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleAccessPolicy;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Module\ModuleProviderResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\News\CmsNewsServiceProvider;
use Lemonade\Cms\News\Migrations\CreateNewsArticles;
use Lemonade\Cms\News\ModuleManifest;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Container\ScopeKind;
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
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Observability\Benchmark\Benchmark;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Framework\Security\Csrf\CsrfTokenManager;
use Lemonade\Framework\Session\Contract\SessionInterface;
use Lemonade\Framework\Validation\FormValidation;
use Lemonade\Framework\Validation\Rule\RuleRegistry;
use Lemonade\Framework\Validation\ValidationRuleResolver;
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
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload])->successful());
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
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'save', 'payload' => $payload])->successful());

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

        self::assertTrue($transport->dispatch('news', $id, ['action' => 'delete', 'payload' => []])->successful());
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'restore', 'payload' => []])->successful());
        self::assertSame(1, (int) (($db->table('cms_news_article_translation')->where('article_id', $id)->where('locale', 'en')->first() ?? [])['publication_state'] ?? 0));
        $db->statement("INSERT INTO system_user VALUES (9, 'owner@example.test', 'New', 'Owner', 1, NULL)");
        self::assertTrue($transport->dispatch('news', $id, ['action' => 'assign-owner', 'payload' => ['owner_id' => 9]])->successful());
        self::assertSame(9, (int) (($db->table('cms_news_article')->where('id', $id)->first() ?? [])['owner_id'] ?? 0));
    }
}
