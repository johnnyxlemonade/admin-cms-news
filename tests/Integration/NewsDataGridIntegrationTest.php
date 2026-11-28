<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\DataGrid\Query\QueryPage;
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\News\NewsData;
use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;
use Nyholm\Psr7\ServerRequest;

/**
 * Verifies News query and HTTP DataGrid behavior independently from the editor
 */
final class NewsDataGridIntegrationTest extends NewsIntegrationTestCase
{
    public function testSearchAndOwnerScopeSelectOnlyMatchingArticles(): void
    {
        $this->insertUser(9, 'owner@example.test', 'Foreign', 'Owner');
        $alpha = $this->createArticle(7, 7, 'Grid Alpha', 2, '2028-01-02T10:30', 'Alpha author');
        $bravo = $this->createArticle(9, 9, 'Grid Bravo', 1, null, 'Bravo author');
        $this->environment()->getLifecycle()->changeUrlSegment(7, $alpha, 'cs', 'searchable-canonical');

        self::assertSame([$alpha, $bravo], $this->ids($this->read(search: 'Grid')));
        self::assertSame([$alpha], $this->ids($this->read(search: 'Grid', ownerId: 7)));
        self::assertSame([$bravo], $this->ids($this->read(search: 'Grid', ownerId: 9)));
        self::assertSame([], $this->ids($this->read(search: 'Grid', ownerId: 11)));
        self::assertSame([$alpha], $this->ids($this->read(search: 'searchable-canonical')));
        self::assertSame([], $this->ids($this->read(search: 'no matching record')));
    }

    public function testPublicationAndLocaleFiltersRepresentStoredTranslations(): void
    {
        $this->insertUser(9, 'owner@example.test', 'Foreign', 'Owner');
        $published = $this->createArticle(7, 7, 'Filter Published', 2, '2028-01-02T10:30', null);
        $draft = $this->createArticle(9, 9, 'Filter Draft', 1, null, null);
        $this->environment()->getDatabase()->statement("INSERT INTO system_language VALUES ('de','German',0,0,3), ('fr','French',1,0,4)");
        $this->environment()->getDatabase()->table('cms_news_article_translation')->insert([
            'article_id' => $published,
            'locale' => 'de',
            'title' => 'Filter German',
            'publication_state' => 1,
        ]);

        self::assertSame([$published], $this->ids($this->read(search: 'Filter', filters: ['state' => 'published'])));
        self::assertSame([$draft], $this->ids($this->read(search: 'Filter', filters: ['state' => 'draft'])));
        self::assertSame([], $this->ids($this->read(search: 'Filter', filters: ['state' => 'new'])));
        self::assertSame([$published], $this->ids($this->read(search: 'Filter', filters: ['locale' => 'de'])));
        self::assertSame([], $this->ids($this->read(search: 'Filter', filters: ['locale' => 'fr'])));
    }

    public function testSortingFallbackAndPaginationStayDeterministic(): void
    {
        $this->insertUser(9, 'owner@example.test', 'Foreign', 'Owner');
        $alpha = $this->createArticle(7, 7, 'Sort Alpha', 2, '2028-01-02T10:30', null);
        $bravo = $this->createArticle(9, 9, 'Sort Bravo', 1, null, null);
        $this->environment()->getDatabase()->table('cms_news_article')->whereIn('id', [$alpha, $bravo])->set([
            'updated_at' => '2028-01-02 11:00:00',
        ])->update();

        self::assertSame([$alpha, $bravo], $this->ids($this->read(search: 'Sort', sort: 'title')));
        self::assertSame([$bravo, $alpha], $this->ids($this->read(
            search: 'Sort',
            sort: 'title',
            direction: 'desc',
        )));
        self::assertSame([$bravo, $alpha], $this->ids($this->read(search: 'Sort', sort: 'updatedAt')));
        self::assertSame([$alpha, $bravo], $this->ids($this->read(
            search: 'Sort',
            sort: 'untrusted SQL expression',
            direction: 'desc',
        )));

        $secondPage = $this->read(search: 'Sort', sort: 'title', page: 2, pageSize: 1);
        self::assertSame([$bravo], $this->ids($secondPage));
        self::assertSame(2, $secondPage->total());
        self::assertSame([], $this->ids($this->read(
            search: 'Sort',
            sort: 'title',
            page: 3,
            pageSize: 1,
        )));
    }

    public function testDeletedRowsExposeDeletionMetadataAndDisappearAfterRestore(): void
    {
        $this->insertUser(10, 'deleted@example.test', 'Deleted', 'Actor', false, '2026-01-01');
        $deleted = $this->createArticle(7, 7, 'Deleted projection', 2, '2028-01-02T10:30', null);
        $this->environment()->getLifecycle()->delete(10, $deleted);

        $deletedPage = $this->read(
            search: 'Deleted projection',
            filters: ['status' => 'deleted'],
            ownerId: 7,
        );
        self::assertSame([$deleted], $this->ids($deletedPage));
        self::assertSame('Deleted Actor', $deletedPage->items()[0]['deleted_by_name']);
        self::assertSame('deleted-projection', $deletedPage->items()[0]['canonical_path']);
        self::assertSame([], $this->ids($this->read(
            search: 'Deleted projection',
            filters: ['status' => 'deleted'],
            ownerId: 9,
        )));

        $this->environment()->getLifecycle()->restore(7, $deleted);
        self::assertNotNull($this->environment()->getReadModel()->findForEditor($deleted));
        self::assertSame(
            1,
            (int) ($this->environment()->getReadModel()->publication($deleted, 'cs')['publication_state'] ?? -1),
        );
        self::assertSame([], $this->ids($this->read(
            search: 'Deleted projection',
            filters: ['status' => 'deleted'],
        )));
    }

    public function testQueryProjectionKeepsReadModelActorFieldsAndNameFallbacks(): void
    {
        $this->insertUser(9, 'owner@example.test', 'New', 'Owner');
        $this->insertUser(11, 'email@example.test', '', '');
        $alpha = $this->createArticle(7, 9, 'Projection Alpha', 2, '2028-01-02T10:30', 'Domain Author');
        $bravo = $this->createArticle(9, 9, 'Projection Bravo', 1, null, null);
        $this->environment()->getDatabase()->table('cms_news_article')->whereIn('id', [$alpha, $bravo])->set([
            'updated_at' => '2028-01-02 11:00:00',
        ])->update();

        $article = $this->environment()->getReadModel()->findForEditor($alpha);
        self::assertNotNull($article);
        $firstRow = $this->read(search: 'Projection', sort: 'title')->items()[0];
        foreach (['owner_id', 'created_by', 'updated_by', 'created_by_name', 'updated_by_name', 'owner_name', 'created_at', 'updated_at', 'author_name'] as $field) {
            self::assertSame($article[$field], $firstRow[$field]);
        }

        $this->environment()->getDatabase()->table('cms_news_article')->where('id', $bravo)->set([
            'owner_id' => 11,
            'updated_by' => null,
        ])->update();
        $fallback = $this->read(search: 'Projection', sort: 'title')->items()[1];
        self::assertSame('email@example.test', $fallback['owner_name']);
        self::assertSame('—', $fallback['updated_by_name']);
    }

    public function testHttpProjectionIncludesPaginationAndOwnerColumn(): void
    {
        $this->insertUser(9, 'owner@example.test', 'Foreign', 'Owner');
        $this->createArticle(7, 7, 'HTTP Alpha', 2, '2028-01-02T10:30', null);
        $bravo = $this->createArticle(9, 9, 'HTTP Bravo', 1, null, null);

        $response = $this->environment()->gridResponse([
            'q' => 'HTTP',
            'sort' => 'title',
            'direction' => 'asc',
            'pageSize' => 1,
            'page' => 2,
        ]);
        self::assertSame([$bravo], array_map('intval', array_column($response['items'], 'id')));
        self::assertSame(
            ['enabled' => true, 'page' => 2, 'perPage' => 1, 'total' => 2, 'pages' => 2],
            $response['pagination'],
        );
        self::assertContains('owner', array_column($response['columns'], 'key'));
    }

    public function testHttpProjectionRepresentsInactiveLocaleAndDeletedMetadata(): void
    {
        $this->insertUser(10, 'deleted@example.test', 'Deleted', 'Actor', false, '2026-01-01');
        $this->environment()->getDatabase()->statement("INSERT INTO system_language VALUES ('de','German',0,0,3)");
        $alpha = $this->createArticle(7, 7, 'HTTP Active', 2, '2028-01-02T10:30', null);
        $deleted = $this->createArticle(7, 7, 'HTTP Deleted', 2, '2028-01-02T10:30', null);
        $this->environment()->getDatabase()->table('cms_news_article_translation')->insert([
            'article_id' => $alpha,
            'locale' => 'de',
            'title' => 'HTTP German',
            'publication_state' => 1,
        ]);
        $this->environment()->getLifecycle()->delete(10, $deleted);

        $inactive = $this->environment()->gridResponse(['locale' => 'de']);
        self::assertSame([$alpha], array_map('intval', array_column($inactive['items'], 'id')));
        $trash = $this->environment()->gridResponse(['q' => 'HTTP', 'status' => 'deleted']);
        self::assertSame([$deleted], array_map('intval', array_column($trash['items'], 'id')));
        self::assertSame('Deleted Actor', $trash['items'][0]['cells']['deletedAt'][0]['secondary']);
    }

    public function testHttpEndpointAppliesOwnScopeToActiveAndDeletedRows(): void
    {
        $this->insertUser(9, 'owner@example.test', 'Foreign', 'Owner');
        $owned = $this->createArticle(7, 7, 'Scope Owned', 1, null, null);
        $this->createArticle(9, 9, 'Scope Foreign', 1, null, null);
        $deletedOwned = $this->createArticle(7, 7, 'Scope Deleted', 1, null, null);
        $this->environment()->getLifecycle()->delete(7, $deletedOwned);
        $this->environment()->usePermissions(['cms.news.view_own', 'cms.news.edit_own']);

        $active = $this->environment()->gridResponse(['q' => 'Scope']);
        self::assertSame([$owned], array_map('intval', array_column($active['items'], 'id')));
        self::assertSame(1, $active['pagination']['total']);
        $deleted = $this->environment()->gridResponse(['q' => 'Scope', 'status' => 'deleted']);
        self::assertSame([$deletedOwned], array_map('intval', array_column($deleted['items'], 'id')));
    }

    public function testHttpEndpointRejectsMissingViewPermission(): void
    {
        $this->environment()->usePermissions([]);

        self::assertSame(
            403,
            $this->environment()->getGridController()->index('news', new ServerRequest('GET', '/'))->getStatusCode(),
        );
    }

    private function createArticle(
        int $ownerId,
        int $actorId,
        string $title,
        int $publicationState,
        ?string $publishedAt,
        ?string $authorName,
    ): int {
        $lifecycle = $this->environment()->getLifecycle();
        $articleId = $lifecycle->createDraft($ownerId, 'cs');
        $lifecycle->save($actorId, $articleId, CmsContentInput::fromEditor([
            'locale' => 'cs',
            'title' => $title,
            'publication_state' => (string) $publicationState,
            'published_at' => $publishedAt ?? '',
        ]), NewsData::fromEditor(['author_name' => $authorName]));

        return $articleId;
    }

    /**
     * @param array<string,string> $filters
     * @return QueryPage<array<string,mixed>>
     */
    private function read(
        string $search,
        array $filters = [],
        string $sort = 'title',
        string $direction = 'asc',
        int $page = 1,
        int $pageSize = 25,
        ?int $ownerId = null,
    ): QueryPage {
        return $this->environment()->getGrid()->listForDataGrid(
            new DataGridQuery($page, $pageSize, $sort, $direction, $search, $filters),
            'cs',
            $ownerId,
        );
    }

    /**
     * @param QueryPage<array<string,mixed>> $page
     * @return list<int>
     */
    private function ids(QueryPage $page): array
    {
        return array_map('intval', array_column($page->items(), 'id'));
    }

    private function insertUser(
        int $id,
        string $email,
        string $firstName,
        string $lastName,
        bool $active = true,
        ?string $deletedAt = null,
    ): void {
        $this->environment()->getDatabase()->table('system_user')->insert([
            'id' => $id,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'active' => $active ? 1 : 0,
            'deleted_at' => $deletedAt,
        ]);
    }
}
