<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationEnvironment;
use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;

/**
 * Verifies isolated News draft, publication, translation and deletion lifecycles
 */
final class NewsLifecycleIntegrationTest extends NewsIntegrationTestCase
{
    public function testEmptyDraftAndRepeatedSaveRemainWithoutRouteOrDuplicateAudit(): void
    {
        $environment = $this->getEnvironment();
        $articleId = $this->createDraftAsAuthenticatedUser();
        $emptyDraft = ['locale' => 'cs', 'title' => ''];

        $firstSave = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => $emptyDraft],
        );
        self::assertTrue($firstSave->successful());
        self::assertSame([], $environment->getDatabase()->table('cms_route')->getArray());
        $afterFirstSave = $environment->contentSnapshot();

        $secondSave = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => $emptyDraft],
        );

        self::assertTrue($secondSave->successful());
        self::assertSame($afterFirstSave, $environment->contentSnapshot());
    }

    public function testPublishOrdinarySaveUnpublishAndRepublishPreserveCanonicalRoute(): void
    {
        $environment = $this->getEnvironment();
        $database = $environment->getDatabase();
        $articleId = $this->createDraftAsAuthenticatedUser();
        $published = [
            'locale' => 'cs',
            'title' => 'První zpráva',
            'publication_state' => '2',
            'published_at' => '2027-01-22T12:30',
        ];

        $publish = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => $published],
        );
        self::assertTrue($publish->successful());
        self::assertSame(
            ['publication_state' => '2', 'published_at' => '2027-01-22T12:30'],
            $publish->data()['editorFields'],
        );
        self::assertSame('prvni-zprava', $this->routePath($articleId, 'cs'));

        $auditCount = $database->table('system_audit_log')->countAllResults();
        $noOp = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => $published],
        );
        self::assertTrue($noOp->successful());
        self::assertSame($auditCount, $database->table('system_audit_log')->countAllResults());

        $ordinarySave = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => ['locale' => 'cs', 'title' => 'Upravený titulek']],
        );
        self::assertTrue($ordinarySave->successful());
        self::assertSame(2, $this->publicationState($articleId, 'cs'));
        self::assertSame('prvni-zprava', $this->routePath($articleId, 'cs'));

        $afterOrdinarySave = $environment->contentSnapshot();
        $repeatedOrdinarySave = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => ['locale' => 'cs', 'title' => 'Upravený titulek']],
        );
        self::assertTrue($repeatedOrdinarySave->successful());
        self::assertSame($afterOrdinarySave, $environment->contentSnapshot());

        $beforeNullPublication = $environment->contentSnapshot();
        $nullPublication = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => [
                'locale' => 'cs',
                'title' => 'Upravený titulek',
                'publication_state' => null,
            ]],
        );
        self::assertTrue($nullPublication->successful());
        self::assertSame($beforeNullPublication, $environment->contentSnapshot());

        $unpublish = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => [
                'locale' => 'cs',
                'title' => '',
                'publication_state' => '1',
                'published_at' => '',
            ]],
        );
        self::assertTrue($unpublish->successful());
        self::assertSame(['publication_state' => '1', 'published_at' => ''], $unpublish->data()['editorFields']);
        self::assertSame(1, $this->publicationState($articleId, 'cs'));
        self::assertNull($this->translation($articleId, 'cs')['published_at']);
        self::assertSame('prvni-zprava', $this->routePath($articleId, 'cs'));
        self::assertContains(
            'cms.news.unpublished',
            array_column($database->table('system_audit_log')->getArray(), 'event_code'),
        );

        $republish = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => [
                'locale' => 'cs',
                'title' => 'První zpráva',
                'publication_state' => '2',
                'published_at' => '',
            ]],
        );
        $publishedAt = (string) $this->translation($articleId, 'cs')['published_at'];

        self::assertTrue($republish->successful());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $publishedAt);
        self::assertSame(
            ['publication_state' => '2', 'published_at' => str_replace(' ', 'T', substr($publishedAt, 0, 16))],
            $republish->data()['editorFields'],
        );
        self::assertSame('prvni-zprava', $this->routePath($articleId, 'cs'));
    }

    public function testTranslationsKeepLocalizedTagsAndExplicitSharedAuthorData(): void
    {
        $environment = $this->getEnvironment();
        $database = $environment->getDatabase();
        $articleId = $this->createDraftAsAuthenticatedUser();

        $environment->getTransport()->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'cs',
            'title' => 'Česká zpráva',
            'author_name' => 'Shared Author',
            'tags' => 'Praha, Brno, Praha',
        ]]);
        $environment->getTransport()->dispatch('news', $articleId, ['action' => 'save', 'payload' => [
            'locale' => 'en',
            'title' => 'English news',
            'author_name' => 'Shared Author',
            'tags' => ['London', 'Archive'],
        ]]);

        self::assertSame(
            'Shared Author',
            ($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['author_name'] ?? null,
        );
        self::assertSame('Česká zpráva', $this->translation($articleId, 'cs')['title']);
        self::assertSame('English news', $this->translation($articleId, 'en')['title']);
        self::assertSame(['Praha', 'Brno'], $this->tags($articleId, 'cs'));
        self::assertSame(['London', 'Archive'], $this->tags($articleId, 'en'));
    }

    public function testChangeUrlUpdatesCanonicalRouteAndReturnsTransportProjection(): void
    {
        $environment = $this->getEnvironment();
        $articleId = $this->createDraftAsAuthenticatedUser();
        $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'save', 'payload' => ['locale' => 'cs', 'title' => 'Původní cesta']],
        );

        $result = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'change-url', 'payload' => ['locale' => 'cs', 'url_segment' => 'finalni-cesta']],
        );

        self::assertTrue($result->successful());
        self::assertSame('admin.url_segment.updated', $result->messageKey());
        self::assertSame(['urlSegment' => 'finalni-cesta'], $result->data());
        self::assertSame('finalni-cesta', $this->routePath($articleId, 'cs'));
    }

    public function testDeleteAndRestoreKeepCanonicalRoutesAndRestoreTranslationsAsDrafts(): void
    {
        $environment = $this->getEnvironment();
        $database = $environment->getDatabase();
        $articleId = $this->createDraftAsAuthenticatedUser();
        foreach ([
            ['locale' => 'cs', 'title' => 'Česká zpráva', 'publication_state' => '2'],
            ['locale' => 'en', 'title' => 'English news', 'publication_state' => '2'],
        ] as $payload) {
            $environment->getTransport()->dispatch(
                'news',
                $articleId,
                ['action' => 'save', 'payload' => $payload],
            );
        }
        $routesBeforeDelete = $database->table('cms_route')
            ->where('entity_id', $articleId)
            ->orderBy('locale')
            ->getArray();

        $deleted = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'delete', 'payload' => []],
        );
        self::assertSame('news.actions.deleted', $deleted->messageKey());
        self::assertTrue($deleted->refreshGrid());
        self::assertNotNull(
            ($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['deleted_at'] ?? null,
        );

        $restored = $environment->getTransport()->dispatch(
            'news',
            $articleId,
            ['action' => 'restore', 'payload' => []],
        );

        self::assertSame('news.actions.restored', $restored->messageKey());
        self::assertTrue($restored->refreshGrid());
        self::assertNull(
            ($database->table('cms_news_article')->where('id', $articleId)->first() ?? [])['deleted_at'] ?? null,
        );
        self::assertSame(1, $this->publicationState($articleId, 'cs'));
        self::assertSame(1, $this->publicationState($articleId, 'en'));
        self::assertSame(
            array_column($routesBeforeDelete, 'path', 'locale'),
            array_column(
                $database->table('cms_route')->where('entity_id', $articleId)->orderBy('locale')->getArray(),
                'path',
                'locale',
            ),
        );
        foreach (['cms.news.deleted', 'cms.news.restored'] as $eventCode) {
            self::assertContains(
                $eventCode,
                array_column($database->table('system_audit_log')->getArray(), 'event_code'),
            );
        }
    }

    private function createDraftAsAuthenticatedUser(): int
    {
        $created = $this->getEnvironment()->getTransport()->dispatch(
            'news',
            null,
            ['action' => 'create', 'payload' => []],
        );
        self::assertTrue($created->successful());

        return (int) $created->data()['id'];
    }

    /**
     * @return array<string,mixed>
     */
    private function translation(int $articleId, string $locale): array
    {
        $translation = $this->getEnvironment()->getDatabase()
            ->table('cms_news_article_translation')
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first();
        self::assertNotNull($translation);

        return $translation;
    }

    private function publicationState(int $articleId, string $locale): int
    {
        return (int) $this->translation($articleId, $locale)['publication_state'];
    }

    private function routePath(int $articleId, string $locale): ?string
    {
        $route = $this->getEnvironment()->getDatabase()
            ->table('cms_route')
            ->where('entity_id', $articleId)
            ->where('locale', $locale)
            ->first();

        return $route === null ? null : (string) $route['path'];
    }

    /**
     * @return list<string>
     */
    private function tags(int $articleId, string $locale): array
    {
        $translationId = (int) $this->translation($articleId, $locale)['id'];

        return array_map(
            'strval',
            array_column(
                $this->getEnvironment()->getDatabase()
                    ->table('cms_news_article_translation_tag')
                    ->where('translation_id', $translationId)
                    ->orderBy('sort_order')
                    ->getArray(),
                'name',
            ),
        );
    }

    private function getEnvironment(): NewsIntegrationEnvironment
    {
        return $this->environment();
    }
}
