<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Models;

use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\News\Models\NewsModel;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje per-locale persistence shared publication lifecycle Aktualit
 */
final class NewsPublicationContractTest extends TestCase
{
    /**
     * Nova minimalni mutace zacina stavem New
     */
    public function testCreateTranslationPersistsNewState(): void
    {
        $bindings = $this->captureBindings(function (NewsModel $news): void {
            $news->saveTranslation(7, 'cs', null, null, null, null, null, '2026-10-13 10:00:00');
        });

        self::assertContains(CmsPublicationState::New->value, $bindings);
    }

    /**
     * Nova mutace pouziva prosty insert bez duplicitniho canonical slugu
     */
    public function testCreateTranslationDoesNotUseDuplicateKeyUpdate(): void
    {
        $capturedSql = '';
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$capturedSql): bool {
                $capturedSql = $sql;

                return true;
            },
        );
        $driver->method('insert_id')->willReturn(12);

        $translationId = (new NewsModel($driver))->saveTranslation(
            7,
            'cs',
            'Test',
            null,
            null,
            null,
            null,
            '2026-10-13 10:00:00',
        );

        self::assertSame(12, $translationId);
        self::assertStringStartsWith('INSERT INTO cms_news_article_translation', $capturedSql);
        self::assertStringNotContainsString('ON DUPLICATE KEY UPDATE', $capturedSql);
        self::assertStringNotContainsString('slug', $capturedSql);
    }

    /**
     * Existujici mutaci aktualizuje jen podle jeji vlastni identity
     */
    public function testUpdateTranslationTargetsKnownIdentity(): void
    {
        $capturedSql = '';
        $capturedBindings = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array|false $bindings) use (&$capturedSql, &$capturedBindings): bool {
                $capturedSql = $sql;
                $capturedBindings = $bindings === false ? [] : array_values($bindings);

                return true;
            },
        );

        $translationId = (new NewsModel($driver))->saveTranslation(
            7,
            'cs',
            'Test',
            null,
            null,
            null,
            null,
            '2026-10-13 10:00:00',
            42,
        );

        self::assertSame(42, $translationId);
        self::assertStringStartsWith('UPDATE cms_news_article_translation', $capturedSql);
        self::assertStringContainsString('WHERE id = ? AND article_id = ? AND locale = ?', $capturedSql);
        self::assertSame([42, 7, 'cs'], array_slice($capturedBindings, -3));
    }

    /**
     * DataGrid zobrazuje a vyhledava canonical cestu z cms_route
     */
    public function testDataGridReadsCanonicalPathFromCmsRoute(): void
    {
        $capturedSql = [];
        $capturedBindings = [];
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('row_array')->willReturn(['numrows' => 1]);
        $result->method('result_array')->willReturn([[
            'id' => 7,
            'canonical_path' => 'test',
        ]]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array $bindings = []) use (&$capturedSql, &$capturedBindings, $result): DatabaseResultInterface {
                $capturedSql[] = $sql;
                $capturedBindings[] = $bindings;

                return $result;
            },
        );

        (new NewsModel($driver))->listForDataGrid(new DataGridQuery(
            page: 1,
            pageSize: 25,
            sortKey: 'title',
            sortDirection: 'asc',
            search: 'test',
            filters: ['locale' => 'en'],
        ), 'cs');

        self::assertCount(2, $capturedSql);
        foreach ($capturedSql as $sql) {
            self::assertStringContainsString('LEFT JOIN cms_route route', $sql);
            self::assertStringContainsString('JOIN cms_news_article_translation t', $sql);
            self::assertStringContainsString("route.module_code = 'cms.news'", $sql);
            self::assertStringContainsString('t.locale = ?', $sql);
            self::assertStringContainsString('route.path LIKE ?', $sql);
        }
        self::assertContains('en', $capturedBindings[0]);
        self::assertContains('en', $capturedBindings[1]);
        self::assertStringContainsString('route.path AS canonical_path', $capturedSql[1]);
    }

    /**
     * Locale filtr nabizi aktivni jazyky i deaktivovany jazyk s mutaci
     */
    public function testDataGridLocalesIncludeDisabledLanguageWithTranslation(): void
    {
        $capturedSql = '';
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([
            ['code' => 'cs', 'name' => 'Čeština'],
            ['code' => 'en', 'name' => 'English'],
        ]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$capturedSql, $result): DatabaseResultInterface {
                $capturedSql = $sql;

                return $result;
            },
        );

        $locales = (new NewsModel($driver))->dataGridLocales();

        self::assertSame(['cs', 'en'], array_column($locales, 'code'));
        self::assertStringContainsString('LEFT JOIN cms_news_article_translation translation', $capturedSql);
        self::assertStringContainsString('language.enabled = 1 OR translation.id IS NOT NULL', $capturedSql);
    }

    /**
     * Bezne ulozeni povysi jen nepublikovanou mutaci na Draft
     */
    public function testSavePromotesTranslationToDraft(): void
    {
        $bindings = $this->captureBindings(function (NewsModel $news): void {
            $news->promoteTranslationToDraft(7, 'cs', '2026-10-13 10:00:00');
        });

        self::assertContains(CmsPublicationState::Draft->value, $bindings);
        self::assertContains('cs', $bindings);
        self::assertContains(CmsPublicationState::Published->value, $bindings);
    }

    /**
     * Publish a unpublish zapisuji publication stav konkretni locale mutace
     */
    public function testPublicationTransitionsRemainScopedToLocale(): void
    {
        $published = $this->captureBindings(function (NewsModel $news): void {
            $news->savePublication(7, 'cs', CmsPublicationState::Published, '2026-10-13 10:00:00', '2026-10-13 10:00:00');
        });
        $draft = $this->captureBindings(function (NewsModel $news): void {
            $news->savePublication(7, 'en', CmsPublicationState::Draft, null, '2026-10-13 10:00:00');
        });

        self::assertContains(CmsPublicationState::Published->value, $published);
        self::assertContains('cs', $published);
        self::assertContains(CmsPublicationState::Draft->value, $draft);
        self::assertContains('en', $draft);
    }

    /**
     * Root metadata persistuji viditelnost lokalizovanych stitku
     */
    public function testCreateDraftPersistsShowTagsSetting(): void
    {
        $capturedSql = '';
        $capturedBindings = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array|false $bindings) use (&$capturedSql, &$capturedBindings): bool {
                $capturedSql = $sql;
                $capturedBindings = $bindings === false ? [] : array_values($bindings);

                return true;
            },
        );
        $driver->method('insert_id')->willReturn(1);

        (new NewsModel($driver))->createDraft(
            7,
            null,
            false,
            [
                'show_author' => true,
                'sharing_enabled' => true,
                'show_published_at' => true,
                'show_thumbnail' => true,
                'show_reading_time' => true,
                'show_tags' => false,
            ],
            '2026-10-13 10:00:00',
        );

        self::assertStringContainsString('show_tags', $capturedSql);
        self::assertSame([7, 7], array_slice($capturedBindings, 0, 2));
        self::assertSame(0, $capturedBindings[9]);
    }

    /**
     * Zachyti bind hodnoty jedne modelove mutace bez databazove infrastruktury
     *
     * @param callable(NewsModel):void $operation
     * @return list<mixed>
     */
    private function captureBindings(callable $operation): array
    {
        $capturedBindings = [];
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array|false $bindings) use (&$capturedBindings): bool {
                $capturedBindings = $bindings === false ? [] : array_values($bindings);

                return true;
            },
        );
        $driver->method('insert_id')->willReturn(1);

        $operation(new NewsModel($driver));

        return $capturedBindings;
    }
}
