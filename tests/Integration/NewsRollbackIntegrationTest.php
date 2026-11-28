<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;
use Throwable;

/**
 * Verifies that audit failures roll back the complete News mutation
 */
final class NewsRollbackIntegrationTest extends NewsIntegrationTestCase
{
    public function testAuditFailureRollsBackTranslationAndSharedArticleChanges(): void
    {
        $articleId = $this->createDraft();
        $transport = $this->environment()->getTransport();
        $database = $this->environment()->getDatabase();
        $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'en',
            'title' => 'Original title',
            'author_name' => 'Original author',
        ]]);
        $before = $this->environment()->contentSnapshot();

        $database->statement(
            'CREATE TRIGGER news_save_audit_failure BEFORE INSERT ON system_audit_log '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'",
        );
        try {
            try {
                $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
                    'locale' => 'en',
                    'title' => 'Changed title',
                    'author_name' => 'Changed author',
                ]]);
                self::fail('Audit failure must roll back the save.');
            } catch (Throwable $exception) {
                self::assertStringContainsString('audit failure', $exception->getMessage());
            }
        } finally {
            $database->statement('DROP TRIGGER IF EXISTS news_save_audit_failure');
        }

        self::assertSame($before, $this->environment()->contentSnapshot());
        self::assertSame(
            'Original title',
            ($database->table('cms_news_article_translation')
                ->where('article_id', $articleId)
                ->where('locale', 'en')
                ->first() ?? [])['title'] ?? null,
        );
        self::assertSame(
            'Original author',
            ($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['author_name'] ?? null,
        );
    }

    public function testAuditFailureRollsBackDeletionAndKeepsCanonicalRoute(): void
    {
        $articleId = $this->createDraft();
        $transport = $this->environment()->getTransport();
        $database = $this->environment()->getDatabase();
        $transport->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'cs',
            'title' => 'Rollback route',
        ]]);
        $transport->dispatch('news', $articleId, ['action' => 'change-url', 'payload' => [
            'locale' => 'cs',
            'url_segment' => 'rollback-canonical',
        ]]);
        $before = $this->environment()->contentSnapshot();

        $database->statement(
            'CREATE TRIGGER news_delete_audit_failure BEFORE INSERT ON system_audit_log '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'",
        );
        try {
            try {
                $transport->dispatch('news', $articleId, ['action' => 'delete', 'payload' => []]);
                self::fail('Audit failure must roll back the deletion.');
            } catch (Throwable $exception) {
                self::assertStringContainsString('audit failure', $exception->getMessage());
            }
        } finally {
            $database->statement('DROP TRIGGER IF EXISTS news_delete_audit_failure');
        }

        self::assertSame($before, $this->environment()->contentSnapshot());
        self::assertNull(
            ($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['deleted_at'] ?? null,
        );
        self::assertSame(
            'rollback-canonical',
            ($database->table('cms_route')
                ->where('entity_id', $articleId)
                ->where('locale', 'cs')
                ->first() ?? [])['path'] ?? null,
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
}
