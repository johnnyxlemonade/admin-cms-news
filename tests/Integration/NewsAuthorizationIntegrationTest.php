<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Admin\Action\Exception\ModuleActionException;
use Lemonade\Admin\Editor\Exception\EditorCapabilityException;
use Lemonade\Admin\Http\AdminErrorCode;
use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;

/**
 * Verifies News own, global and action-specific authorization contracts
 */
final class NewsAuthorizationIntegrationTest extends NewsIntegrationTestCase
{
    public function testPublicationFieldsDeclareTheirSeparateTransportPermission(): void
    {
        $permissions = [];
        foreach ($this->environment()->getNewsEditor()->editorDefinition()->fields() as $field) {
            $permissions[$field->name()] = $field->permission();
        }

        self::assertSame('cms.news.publish_own', $permissions['publication_state']);
        self::assertSame('cms.news.publish_own', $permissions['published_at']);
        self::assertNull($permissions['title']);
        self::assertArrayNotHasKey('owner_id', $permissions);
        self::assertArrayNotHasKey('url_segment', $permissions);
    }

    public function testOwnEditAllowsContentChangesButNotPublicationChanges(): void
    {
        $articleId = $this->createDraft();
        $transport = $this->environment()->getTransport();
        $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'cs',
            'title' => 'Published own article',
            'publication_state' => '2',
        ]]);
        $transport->dispatch('news', $articleId, ['action' => 'change-url', 'payload' => [
            'locale' => 'cs',
            'url_segment' => 'preserved-own-route',
        ]]);
        $this->environment()->usePermissions(['cms.news.view_own', 'cms.news.edit_own']);

        try {
            $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
                'locale' => 'cs',
                'title' => 'Forbidden publication change',
                'publication_state' => '2',
                'published_at' => '2028-02-03T12:00',
            ]]);
            self::fail('Publication changes require their separate permission.');
        } catch (ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
        }

        self::assertTrue($transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'en',
            'title' => 'Owned English translation',
        ]])->successful());
        self::assertTrue($transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'cs',
            'title' => 'Updated own title',
        ]])->successful());
        self::assertSame(2, $this->publicationState($articleId, 'cs'));
        self::assertSame('preserved-own-route', $this->routePath($articleId, 'cs'));
    }

    public function testOwnDeleteAndRestoreApplyToAnOwnedArticle(): void
    {
        $articleId = $this->createDraft();
        $transport = $this->environment()->getTransport();
        $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'cs',
            'title' => 'Owned lifecycle',
        ]]);
        $this->environment()->usePermissions([
            'cms.news.delete_own',
            'cms.news.restore_own',
        ]);

        $deleted = $transport->dispatch('news', $articleId, ['action' => 'delete', 'payload' => []]);
        $restored = $transport->dispatch('news', $articleId, ['action' => 'restore', 'payload' => []]);

        self::assertSame('news.actions.deleted', $deleted->messageKey());
        self::assertTrue($deleted->refreshGrid());
        self::assertSame('news.actions.restored', $restored->messageKey());
        self::assertTrue($restored->refreshGrid());
    }

    public function testOwnPermissionsRejectForeignArticlesAcrossActionsAndEditor(): void
    {
        $articleId = $this->createDraft();
        $database = $this->environment()->getDatabase();
        $database->statement("INSERT INTO system_user VALUES (9, 'owner@example.test', 'Foreign', 'Owner', 1, NULL)");
        $database->table('cms_news_article')->where('id', $articleId)->set(['owner_id' => 9])->update();
        $this->environment()->usePermissions([
            'cms.news.view_own',
            'cms.news.edit_own',
            'cms.news.delete_own',
            'cms.news.restore_own',
            'cms.news.assign_owner',
        ]);

        foreach (['change-url', 'delete', 'restore', 'assign-owner'] as $action) {
            try {
                $this->environment()->getTransport()->dispatch('news', $articleId, [
                    'action' => $action,
                    'payload' => [],
                ]);
                self::fail(sprintf('Own permission must reject foreign %s.', $action));
            } catch (ModuleActionException $exception) {
                self::assertSame(403, $exception->status());
            }
        }

        try {
            $this->environment()->getEditor()->updateData('cms.news', $articleId);
            self::fail('Own editor permission must reject a foreign article.');
        } catch (EditorCapabilityException $exception) {
            self::assertSame(403, $exception->status());
        }
    }

    public function testGlobalPermissionsAllowLifecycleActionsOnAForeignArticle(): void
    {
        $articleId = $this->createDraft();
        $database = $this->environment()->getDatabase();
        $database->statement("INSERT INTO system_user VALUES (9, 'owner@example.test', 'Foreign', 'Owner', 1, NULL)");
        $database->table('cms_news_article')->where('id', $articleId)->set(['owner_id' => 9])->update();
        $this->environment()->usePermissions([
            'cms.news.view',
            'cms.news.edit',
            'cms.news.delete',
            'cms.news.restore',
        ]);

        self::assertTrue($this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'change-url',
            'payload' => ['locale' => 'cs', 'url_segment' => 'global-route'],
        ])->successful());
        self::assertTrue($this->environment()->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'delete', 'payload' => []],
        )->successful());
        self::assertTrue($this->environment()->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'restore', 'payload' => []],
        )->successful());
    }

    public function testAssignOwnerRequiresItsOwnPermissionAndReturnsItsProjection(): void
    {
        $articleId = $this->createDraft();
        $database = $this->environment()->getDatabase();
        $database->statement("INSERT INTO system_user VALUES (9, 'owner@example.test', 'New', 'Owner', 1, NULL)");
        $this->environment()->usePermissions(['cms.news.view', 'cms.news.edit']);

        try {
            $this->environment()->getTransport()->dispatch('news', $articleId, [
                'action' => 'assign-owner',
                'payload' => ['owner_id' => 9],
            ]);
            self::fail('Assign owner requires its separate permission.');
        } catch (ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
            self::assertSame(AdminErrorCode::PERMISSION_DENIED, $exception->error());
        }

        $this->environment()->usePermissions([
            'cms.news.view',
            'cms.news.edit',
            'cms.news.assign_owner',
        ]);
        $assigned = $this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'assign-owner',
            'payload' => ['owner_id' => 9],
        ]);

        self::assertTrue($assigned->successful());
        self::assertSame('news.actions.owner_assigned', $assigned->messageKey());
        self::assertTrue($assigned->refreshPage());
        self::assertSame(
            9,
            (int) (($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['owner_id'] ?? 0),
        );
        self::assertContains(
            'cms.news.owner_transferred',
            array_column($database->table('system_audit_log')->getArray(), 'event_code'),
        );
    }

    public function testAssigningAnOrphanRequiresGlobalViewPermission(): void
    {
        $articleId = $this->createDraft();
        $database = $this->environment()->getDatabase();
        $database->statement("INSERT INTO system_user VALUES (9, 'owner@example.test', 'New', 'Owner', 1, NULL)");
        $database->table('cms_news_article')->where('id', $articleId)->set(['owner_id' => null])->update();
        $this->environment()->usePermissions(['cms.news.view_own', 'cms.news.assign_owner']);

        try {
            $this->environment()->getTransport()->dispatch('news', $articleId, [
                'action' => 'assign-owner',
                'payload' => ['owner_id' => 9],
            ]);
            self::fail('Assigning an orphan requires global view permission.');
        } catch (ModuleActionException $exception) {
            self::assertSame(403, $exception->status());
        }

        $this->environment()->usePermissions(['cms.news.view', 'cms.news.assign_owner']);
        $assigned = $this->environment()->getTransport()->dispatch('news', $articleId, [
            'action' => 'assign-owner',
            'payload' => ['owner_id' => 9],
        ]);

        self::assertTrue($assigned->successful());
        self::assertSame(
            9,
            (int) (($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['owner_id'] ?? 0),
        );
    }

    private function createDraft(): int
    {
        $created = $this->environment()->getTransport()->dispatch(
            'news',
            null,
            ['action' => 'create', 'payload' => []],
        );
        self::assertTrue($created->successful());

        return (int) $created->data()['id'];
    }

    private function publicationState(int $articleId, string $locale): int
    {
        return (int) (($this->environment()->getDatabase()
            ->table('cms_news_article_translation')
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first() ?? [])['publication_state'] ?? 0);
    }

    private function routePath(int $articleId, string $locale): ?string
    {
        $route = $this->environment()->getDatabase()
            ->table('cms_route')
            ->where('entity_id', $articleId)
            ->where('locale', $locale)
            ->first();

        return $route === null ? null : (string) $route['path'];
    }
}
