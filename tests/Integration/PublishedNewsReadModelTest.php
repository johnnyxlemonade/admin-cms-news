<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use InvalidArgumentException;
use Lemonade\Admin\File\PublishedMediaInterface;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\News\Public\PublishedNews;
use Lemonade\Cms\News\Tests\Integration\Support\PublishedNewsIntegrationEnvironment;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseMeasurements;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies public News selection, hydration and lightweight projections against MySQL
 */
final class PublishedNewsReadModelTest extends TestCase
{
    private ?PublishedNewsIntegrationEnvironment $environment = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = new PublishedNewsIntegrationEnvironment();
    }

    protected function tearDown(): void
    {
        if ($this->environment !== null) {
            $this->environment->close();
        }

        parent::tearDown();
    }

    public function testRecordHydratesLocalizedContentTagsAndOrderedMedia(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addLanguage('en', enabled: true, default: false, order: 2);
        $this->addRoot(1, authorName: 'Author 1', recommended: true);
        $this->addTranslation(
            articleId: 1,
            locale: 'cs',
            state: CmsPublicationState::Published,
            title: 'First',
            publishedAt: '2020-02-01 00:00:00',
            summary: 'Lead',
            content: '<p>Body</p>',
            pageTitle: 'SEO title',
            metaDescription: 'SEO description',
        );
        $this->addTranslation(1, 'en', CmsPublicationState::Published, 'English', '2020-02-01 00:00:00');
        $this->addRoute(1, 'cs', 'first');
        $this->addRoute(1, 'en', 'first');
        $translationId = $this->translationId(1, 'cs');
        $this->addTag($translationId, 'Community', order: 2);
        $this->addTag($translationId, 'City', order: 1);
        $this->addFile(10, 1, 'thumbnail', 'image', order: 0, assetId: 'asset-10', version: 'v1');
        $this->addFile(20, 1, 'gallery', 'image', order: 2, assetId: 'asset-20', version: 'v1');
        $this->addFile(21, 1, 'gallery', 'image', order: 1, assetId: 'asset-21', version: 'v1');
        $this->addFile(22, 1, 'gallery', 'image', order: 3);
        $this->addFile(30, 1, 'attachment', 'file', order: 2, storagePath: 'docs/30.pdf');
        $this->addFile(31, 1, 'attachment', 'file', order: 1, storagePath: 'docs/31.pdf');
        $reader = $this->getEnvironment()->createReader();

        $article = $reader->record(1, PublicLocaleResolution::route('cs', 'cs', 'first'));

        self::assertNotNull($article);
        self::assertInstanceOf(PublishedMediaInterface::class, $article);
        self::assertSame('/first', $article->getPublicUrl());
        self::assertSame('First', $article->getTitle());
        self::assertSame('Lead', $article->getSummary());
        self::assertSame('<p>Body</p>', $article->getContent());
        self::assertSame('SEO title', $article->getPageTitle());
        self::assertSame('SEO description', $article->getMetaDescription());
        self::assertSame('Author 1', $article->getAuthorName());
        self::assertSame(['City', 'Community'], $article->getTags());
        self::assertTrue($article->isRecommended());
        self::assertSame(10, $article->getThumbnail()?->id());
        self::assertSame([21, 20], array_map(static fn($file): int => $file->id(), $article->getGallery()));
        self::assertSame([31, 30], array_map(static fn($file): int => $file->id(), $article->getAttachments()));
        self::assertSame(
            '/en/first',
            $reader->record(1, PublicLocaleResolution::route('cs', 'en', 'first'))?->getPublicUrl(),
        );
    }

    public function testPublicSelectionAppliesEveryPublicationScopeCondition(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addLanguage('de', enabled: false, default: false, order: 2);

        $this->addRoot(3);
        $this->addTranslation(3, 'cs', CmsPublicationState::Draft, 'Draft', null);
        $this->addRoute(3, 'cs', 'draft');

        $this->addRoot(4);
        $this->addTranslation(4, 'cs', CmsPublicationState::Published, 'Future', '2999-01-01 00:00:00');
        $this->addRoute(4, 'cs', 'future');

        $this->addRoot(5, deletedAt: '2025-01-05 00:00:00');
        $this->addTranslation(5, 'cs', CmsPublicationState::Published, 'Deleted', '2020-01-01 00:00:00');
        $this->addRoute(5, 'cs', 'deleted');

        $this->addRoot(6);
        $this->addTranslation(6, 'cs', CmsPublicationState::Published, 'Missing route', '2020-01-01 00:00:00');

        $this->addRoot(7);
        $this->addTranslation(7, 'cs', CmsPublicationState::Published, 'Deleted route', '2020-01-01 00:00:00');
        $this->addRoute(7, 'cs', 'deleted-route', deletedAt: '2025-01-05 00:00:00');

        $this->addRoot(8);
        $this->addTranslation(8, 'de', CmsPublicationState::Published, 'Disabled language', '2020-01-01 00:00:00');
        $this->addRoute(8, 'de', 'disabled-language');

        $this->addRoot(9);
        $this->addTranslation(9, 'cs', CmsPublicationState::Published, ' ', '2020-01-01 00:00:00');
        $this->addRoute(9, 'cs', 'blank-title');

        $reader = $this->getEnvironment()->createReader();
        $czech = PublicLocaleResolution::route('cs', 'cs', 'publication-scope');
        $german = PublicLocaleResolution::route('cs', 'de', 'disabled-language');

        self::assertNull($reader->record(3, $czech), 'Draft translation must remain private');
        self::assertNull($reader->record(4, $czech), 'Future publication must remain private');
        self::assertNull($reader->record(5, $czech), 'Deleted root must remain private');
        self::assertNull($reader->record(6, $czech), 'Missing canonical route must remain private');
        self::assertNull($reader->record(7, $czech), 'Deleted canonical route must remain private');
        self::assertNull($reader->record(8, $german), 'Disabled locale must remain private');
        self::assertNull($reader->record(9, $czech), 'Blank title must remain private');
        self::assertFalse($reader->isPublicInAnyLocale(5));
    }

    public function testPublicInAnyLocaleRequiresAtLeastOneActiveCanonicalRoute(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addLanguage('en', enabled: true, default: false, order: 2);
        $this->addRoot(1);
        $this->addTranslation(1, 'cs', CmsPublicationState::Published, 'Czech', '2020-02-01 00:00:00');
        $this->addTranslation(1, 'en', CmsPublicationState::Published, 'English', '2020-02-01 00:00:00');
        $this->addRoute(1, 'cs', 'czech');
        $this->addRoute(1, 'en', 'english');
        $reader = $this->getEnvironment()->createReader();
        $database = $this->getEnvironment()->getDatabase();

        self::assertTrue($reader->isPublicInAnyLocale(1));

        $database->statement("UPDATE cms_route SET deleted_at = '2032-01-02 00:00:00' WHERE entity_id = 1 AND locale = 'cs'");
        self::assertTrue($reader->isPublicInAnyLocale(1));

        $database->statement("UPDATE cms_route SET deleted_at = '2032-01-02 00:00:00' WHERE entity_id = 1 AND locale = 'en'");
        self::assertFalse($reader->isPublicInAnyLocale(1));
    }

    public function testCollectionBatchesPageSelectionAndHydrationInFourQueries(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        [$page, $queryCount] = $this->getEnvironment()->getInstrumentation()->collect(
            static function (DatabaseMeasurements $measurements) use ($reader, $locale): array {
                $page = $reader->collection($locale, perPage: 1);

                return [$page, $measurements->queryCount()];
            },
        );

        self::assertSame(4, $queryCount);
        self::assertSame(2, $page->getTotal());
        self::assertSame([1], $this->ids($page->getItems()));
    }

    public function testCollectionPaginatesTheDefaultPublicationOrder(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        $firstPage = $reader->collection($locale, page: 1, perPage: 1);
        $secondPage = $reader->collection($locale, page: 2, perPage: 1);

        self::assertSame(2, $firstPage->getTotal());
        self::assertSame([1], $this->ids($firstPage->getItems()));
        self::assertSame([2], $this->ids($secondPage->getItems()));
    }

    public function testCollectionFiltersByTagAndRecommendation(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $this->addTag($this->translationId(1, 'cs'), 'Community', order: 1);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        self::assertSame([1], $this->ids($reader->collection($locale, tag: 'Community')->getItems()));
        self::assertSame([2], $this->ids($reader->collection($locale, recommended: false)->getItems()));
    }

    public function testCollectionCombinesSearchDateRangeAndExplicitSorting(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        $filtered = $reader->collection(
            $locale,
            search: 'First',
            publishedAt: ['from' => '2020-02-01', 'to' => '2020-02-01'],
            sortBy: 'title',
            sortDirection: 'asc',
        );

        self::assertSame(1, $filtered->getTotal());
        self::assertSame([1], $this->ids($filtered->getItems()));
        self::assertSame([2, 1], $this->ids($reader->collection($locale, sortDirection: 'asc')->getItems()));
    }

    public function testLatestAndOthersUsePublicationOrderAndExclusion(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        self::assertSame([1, 2], $this->ids($reader->latest($locale, 2)));
        self::assertSame([2], $this->ids($reader->others(1, $locale, limit: 1)));
    }

    public function testOthersApplyTagFilterBeforeLimit(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $this->addTag($this->translationId(1, 'cs'), 'Community', order: 1);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        self::assertSame([], $reader->others(1, $locale, limit: 1, tag: 'Community'));
    }

    public function testRecommendedReturnsOnlyRecommendedArticles(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: true);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        self::assertSame([1], $this->ids($reader->recommended($locale)));
    }

    public function testSitemapUsesOneLightweightQuery(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: false);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        [$entries, $queryCount] = $this->getEnvironment()->getInstrumentation()->collect(
            static function (DatabaseMeasurements $measurements) use ($reader, $locale): array {
                $entries = iterator_to_array($reader->sitemapEntries($locale));

                return [$entries, $measurements->queryCount()];
            },
        );

        self::assertSame(1, $queryCount);
        self::assertSame(
            ['/first', '/second'],
            array_map(static fn($entry): string => $entry->getPublicUrl(), $entries),
        );
    }

    public function testSitemapUsesLatestPublicTimestamp(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');
        $database = $this->getEnvironment()->getDatabase();

        self::assertSame(
            '2025-01-04',
            iterator_to_array($reader->sitemapEntries($locale))[0]->getUpdatedAt()->format('Y-m-d'),
        );

        $database->statement("UPDATE cms_news_article SET updated_at = '2030-01-01 00:00:00' WHERE id = 1");
        $database->statement("UPDATE cms_news_article_translation SET updated_at = '2031-01-01 00:00:00' WHERE article_id = 1 AND locale = 'cs'");
        $database->statement("UPDATE cms_route SET updated_at = '2032-01-01 00:00:00' WHERE entity_id = 1 AND locale = 'cs'");

        self::assertSame(
            '2032-01-01',
            iterator_to_array($reader->sitemapEntries($locale))[0]->getUpdatedAt()->format('Y-m-d'),
        );
    }

    public function testDeletedRouteDisappearsFromEveryPublicProjection(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: false);
        $this->addPublishedCzechArticle(2, 'Second', 'second', '2020-01-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader();
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        $this->getEnvironment()->getDatabase()->statement(
            "UPDATE cms_route SET deleted_at = '2032-01-02 00:00:00' WHERE entity_id = 1 AND locale = 'cs'",
        );

        self::assertNull($reader->record(1, $locale));
        self::assertSame(
            ['/second'],
            array_map(
                static fn($entry): string => $entry->getPublicUrl(),
                iterator_to_array($reader->sitemapEntries($locale)),
            ),
        );
        self::assertFalse($reader->isPublicInAnyLocale(1));
    }

    public function testUnavailableModuleShortCircuitsEveryPublicProjection(): void
    {
        $this->addLanguage('cs', enabled: true, default: true, order: 1);
        $this->addPublishedCzechArticle(1, 'First', 'first', '2020-02-01 00:00:00', recommended: false);
        $reader = $this->getEnvironment()->createReader(moduleEnabled: false);
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        self::assertNull($reader->record(1, $locale));
        self::assertSame([], $reader->collection($locale, sortBy: 'not-validated-while-unavailable')->getItems());
        self::assertSame([], iterator_to_array($reader->sitemapEntries($locale)));
        self::assertFalse($reader->isPublicInAnyLocale(1));
    }

    public function testCollectionRejectsInvalidPaginationBeforeModuleAvailability(): void
    {
        $reader = $this->getEnvironment()->createReader(moduleEnabled: false);
        $locale = PublicLocaleResolution::route('cs', 'cs', 'first');

        $this->expectException(InvalidArgumentException::class);
        $reader->collection($locale, perPage: 0);
    }

    private function addPublishedCzechArticle(
        int $articleId,
        string $title,
        string $path,
        string $publishedAt,
        bool $recommended,
    ): void {
        $this->addRoot($articleId, recommended: $recommended);
        $this->addTranslation(
            $articleId,
            'cs',
            CmsPublicationState::Published,
            $title,
            $publishedAt,
        );
        $this->addRoute($articleId, 'cs', $path);
    }

    private function addLanguage(string $code, bool $enabled, bool $default, int $order): void
    {
        $this->getEnvironment()->getDatabase()->statement(
            'INSERT INTO system_language VALUES (?,?,?,?)',
            [$code, $enabled ? 1 : 0, $order, $default ? 1 : 0],
        );
    }

    private function addRoot(
        int $articleId,
        ?string $authorName = null,
        bool $recommended = false,
        ?string $deletedAt = null,
    ): void {
        $this->getEnvironment()->getDatabase()->statement(
            'INSERT INTO cms_news_article (id,author_name,recommended,show_author,show_thumbnail,show_tags,created_at,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [
                $articleId,
                $authorName,
                $recommended ? 1 : 0,
                1,
                1,
                1,
                '2025-01-01 00:00:00',
                '2025-01-02 00:00:00',
                $deletedAt,
            ],
        );
    }

    private function addTranslation(
        int $articleId,
        string $locale,
        CmsPublicationState $state,
        string $title,
        ?string $publishedAt,
        ?string $summary = null,
        ?string $content = null,
        ?string $pageTitle = null,
        ?string $metaDescription = null,
    ): void {
        $this->getEnvironment()->getDatabase()->statement(
            'INSERT INTO cms_news_article_translation (article_id,locale,publication_state,title,summary,content,page_title,meta_description,published_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $articleId,
                $locale,
                $state->value,
                $title,
                $summary,
                $content,
                $pageTitle,
                $metaDescription,
                $publishedAt,
                '2025-01-01 00:00:00',
                '2025-01-03 00:00:00',
            ],
        );
    }

    private function addRoute(
        int $articleId,
        string $locale,
        string $path,
        ?string $deletedAt = null,
    ): void {
        $this->getEnvironment()->getDatabase()->statement(
            'INSERT INTO cms_route (module_code,entity_id,locale,path,created_at,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?)',
            ['cms.news', $articleId, $locale, $path, '2025-01-01 00:00:00', '2025-01-04 00:00:00', $deletedAt],
        );
    }

    private function addTag(int $translationId, string $name, int $order): void
    {
        $this->getEnvironment()->getDatabase()->statement(
            'INSERT INTO cms_news_article_translation_tag (translation_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)',
            [$translationId, $name, $order, '2025-01-01 00:00:00', '2025-01-01 00:00:00'],
        );
    }

    private function addFile(
        int $fileId,
        int $articleId,
        string $usage,
        string $kind,
        int $order,
        ?string $assetId = null,
        ?string $version = null,
        ?string $storagePath = null,
    ): void {
        $this->getEnvironment()->getDatabase()->statement(
            'INSERT INTO system_file (id,module_code,entity_id,`usage`,kind,media_category,asset_id,source_version,storage_path,original_filename,extension,mime_type,file_size,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $fileId,
                'cms.news',
                $articleId,
                $usage,
                $kind,
                $kind === 'image' ? 'image' : 'document',
                $assetId,
                $version,
                $storagePath,
                (string) $fileId,
                $kind === 'image' ? 'jpg' : 'pdf',
                $kind === 'image' ? 'image/jpeg' : 'application/pdf',
                123,
                $order,
                '2025-01-01 00:00:00',
                '2025-01-02 00:00:00',
            ],
        );
    }

    private function translationId(int $articleId, string $locale): int
    {
        $translation = $this->getEnvironment()->getDatabase()
            ->table('cms_news_article_translation')
            ->select(['id'])
            ->where('article_id', $articleId)
            ->where('locale', $locale)
            ->first();
        self::assertNotNull($translation);

        return (int) $translation['id'];
    }

    /**
     * @param list<PublishedNews> $articles
     * @return list<int>
     */
    private function ids(array $articles): array
    {
        return array_map(static fn(PublishedNews $article): int => $article->getId(), $articles);
    }

    private function getEnvironment(): PublishedNewsIntegrationEnvironment
    {
        return $this->environment ?? throw new LogicException('Integration environment is not initialized.');
    }
}
