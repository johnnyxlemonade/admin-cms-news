<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Models;

use Lemonade\Admin\DataGrid\Query\DataGridQuery;
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
            self::assertStringContainsString('LEFT JOIN cms_route AS route', $sql);
            self::assertStringContainsString('JOIN cms_news_article_translation AS t', $sql);
            self::assertStringContainsString('route.module_code = ?', $sql);
            self::assertStringContainsString('t.locale = ?', $sql);
            self::assertStringContainsString('route.path LIKE ?', $sql);
        }
        self::assertContains('en', $capturedBindings[0]);
        self::assertContains('en', $capturedBindings[1]);
        self::assertSame('cms.news', $capturedBindings[0][0]);
        self::assertSame('cms.news', $capturedBindings[1][0]);
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
        self::assertStringContainsString('LEFT JOIN cms_news_article_translation AS translation ON translation.locale = language.code', $capturedSql);
        self::assertStringContainsString('language.enabled = 1 OR translation.id IS NOT NULL', $capturedSql);
    }

    /**
     * Editor locale JOIN predava identitu aktuality jako binding
     */
    public function testEditorLocalesBindArticleIdentityInJoin(): void
    {
        $capturedSql = '';
        $capturedBindings = [];
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(static fn(string $identifier): string => $identifier);
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array $bindings = []) use (&$capturedSql, &$capturedBindings, $result): DatabaseResultInterface {
                $capturedSql = $sql;
                $capturedBindings = $bindings;

                return $result;
            },
        );

        (new NewsModel($driver))->editorLocales(37);

        self::assertStringContainsString('translation.article_id = ?', $capturedSql);
        self::assertSame([37], $capturedBindings);
    }
}
