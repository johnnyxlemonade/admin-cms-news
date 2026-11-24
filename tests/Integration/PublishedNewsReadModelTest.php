<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use InvalidArgumentException;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Admin\File\PublishedMediaInterface;
use Lemonade\Admin\Platform\Migrations\CreateCoreFiles;
use Lemonade\Cms\News\Migrations\CreateNewsArticles;
use Lemonade\Cms\News\Public\PublishedNewsReadModel;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseMeasurements;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use PHPUnit\Framework\TestCase;

/**
 * Verifies published News, media and sitemap projections against MySQL
 */
final class PublishedNewsReadModelTest extends TestCase
{
    /**
     * Covers visibility, filtering, batched media and canonical sitemap dates
     */
    public function testPublishedNewsUsesOneVisibilityScope(): void
    {
        $name = getenv('NEWS_PUBLIC_INTEGRATION_DATABASE');
        self::assertIsString($name);
        self::assertStringStartsWith('cms_news_test_', $name);
        $config = DatabaseConfig::fromArray([
            'driver' => 'mysql',
            'host' => 'db',
            'database' => $name,
            'username' => 'db',
            'password' => 'db',
        ]);
        $instrumentation = new DatabaseInstrumentation();
        $connection = new MysqlConnection($config, $instrumentation);
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver($connection, $config, $escaper, new IdentifierProtector($escaper));
        $database = new Database($connection, $driver);
        $database->statement('CREATE TABLE system_user (id BIGINT UNSIGNED PRIMARY KEY)');
        $database->statement('CREATE TABLE system_language (code VARCHAR(10) PRIMARY KEY, enabled TINYINT, sort_order INT, is_default TINYINT)');
        $database->statement('CREATE TABLE cms_route (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(100), entity_id BIGINT UNSIGNED, locale VARCHAR(10), path VARCHAR(700), created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL, UNIQUE KEY uq_target (module_code,entity_id,locale))');
        $database->statement("INSERT INTO system_language VALUES ('cs',1,1,1),('en',1,2,0),('de',0,3,0)");
        $schema = new Schema(new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)), $driver);
        (new CreateNewsArticles())->up($schema);
        (new CreateCoreFiles())->up($schema);

        foreach (range(1, 9) as $id) {
            $database->statement(
                'INSERT INTO cms_news_article (id,author_name,recommended,show_author,show_thumbnail,show_tags,created_at,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$id, 'Author ' . $id, $id === 1 ? 1 : 0, 1, 1, 1, '2025-01-01 00:00:00', '2025-01-02 00:00:00', $id === 5 ? '2025-01-05 00:00:00' : null],
            );
        }
        foreach ([
            [1, 'cs', 2, 'First', 'Lead', '<p>Body</p>', 'SEO title', 'SEO description', '2020-02-01 00:00:00'],
            [1, 'en', 2, 'English', null, null, null, null, '2020-02-01 00:00:00'],
            [2, 'cs', 2, 'Second', null, null, null, null, '2020-01-01 00:00:00'],
            [3, 'cs', 1, 'Draft', null, null, null, null, null],
            [4, 'cs', 2, 'Future', null, null, null, null, '2999-01-01 00:00:00'],
            [5, 'cs', 2, 'Deleted', null, null, null, null, '2020-01-01 00:00:00'],
            [6, 'cs', 2, 'Missing route', null, null, null, null, '2020-01-01 00:00:00'],
            [7, 'cs', 2, 'Deleted route', null, null, null, null, '2020-01-01 00:00:00'],
            [8, 'de', 2, 'Disabled language', null, null, null, null, '2020-01-01 00:00:00'],
            [9, 'cs', 2, ' ', null, null, null, null, '2020-01-01 00:00:00'],
        ] as [$id, $language, $state, $title, $summary, $content, $pageTitle, $description, $publishedAt]) {
            $database->statement(
                'INSERT INTO cms_news_article_translation (article_id,locale,publication_state,title,summary,content,page_title,meta_description,published_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $language, $state, $title, $summary, $content, $pageTitle, $description, $publishedAt, '2025-01-01 00:00:00', '2025-01-03 00:00:00'],
            );
        }
        foreach ([
            [1, 'cs', 'first', null], [1, 'en', 'first', null],
            [2, 'cs', 'second', null], [3, 'cs', 'draft', null],
            [4, 'cs', 'future', null], [5, 'cs', 'deleted', null],
            [7, 'cs', 'deleted-route', '2025-01-05 00:00:00'],
            [8, 'de', 'disabled-language', null], [9, 'cs', 'blank-title', null],
        ] as [$id, $language, $path, $deletedAt]) {
            $database->statement(
                'INSERT INTO cms_route (module_code,entity_id,locale,path,created_at,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?)',
                ['cms.news', $id, $language, $path, '2025-01-01 00:00:00', '2025-01-04 00:00:00', $deletedAt],
            );
        }
        $translation = $database->table('cms_news_article_translation')
            ->select(['id'])
            ->where('article_id', 1)
            ->where('locale', 'cs')
            ->first();
        self::assertNotNull($translation);
        $translationId = (int) $translation['id'];
        foreach ([['Community', 2], ['City', 1]] as [$tag, $order]) {
            $database->statement(
                'INSERT INTO cms_news_article_translation_tag (translation_id,name,sort_order,created_at,updated_at) VALUES (?,?,?,?,?)',
                [$translationId, $tag, $order, '2025-01-01 00:00:00', '2025-01-01 00:00:00'],
            );
        }
        foreach ([
            [10, 'thumbnail', 'image', 0, 'asset-10', 'v1', null],
            [20, 'gallery', 'image', 2, 'asset-20', 'v1', null],
            [21, 'gallery', 'image', 1, 'asset-21', 'v1', null],
            [22, 'gallery', 'image', 3, null, null, null],
            [30, 'attachment', 'file', 2, null, null, 'docs/30.pdf'],
            [31, 'attachment', 'file', 1, null, null, 'docs/31.pdf'],
        ] as [$id, $usage, $kind, $order, $assetId, $version, $storagePath]) {
            $database->statement(
                'INSERT INTO system_file (id,module_code,entity_id,`usage`,kind,media_category,asset_id,source_version,storage_path,original_filename,extension,mime_type,file_size,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, 'cms.news', 1, $usage, $kind, $kind === 'image' ? 'image' : 'document', $assetId, $version, $storagePath, (string) $id, $kind === 'image' ? 'jpg' : 'pdf', $kind === 'image' ? 'image/jpeg' : 'application/pdf', 123, $order, '2025-01-01 00:00:00', '2025-01-02 00:00:00'],
            );
        }

        $modules = self::createStub(PublicModuleStateResolverInterface::class);
        $modules->method('isDiscoveredInstalledAndEnabled')->willReturn(true);
        $reader = new PublishedNewsReadModel($database, new FileReadModel($driver), new PublicCmsUrlBuilder(), $modules);
        $cs = PublicLocaleResolution::route('cs', 'cs', 'first');
        $en = PublicLocaleResolution::route('cs', 'en', 'first');
        $de = PublicLocaleResolution::route('cs', 'de', 'disabled-language');

        $first = $reader->record(1, $cs);
        self::assertNotNull($first);
        self::assertInstanceOf(PublishedMediaInterface::class, $first);
        self::assertSame('/first', $first->getPublicUrl());
        self::assertSame('First', $first->getTitle());
        self::assertSame('Lead', $first->getSummary());
        self::assertSame('<p>Body</p>', $first->getContent());
        self::assertSame('SEO title', $first->getPageTitle());
        self::assertSame('SEO description', $first->getMetaDescription());
        self::assertSame('Author 1', $first->getAuthorName());
        self::assertSame(['City', 'Community'], $first->getTags());
        self::assertTrue($first->isRecommended());
        self::assertSame(10, $first->getThumbnail()?->id());
        self::assertSame([21, 20], array_map(static fn($file): int => $file->id(), $first->getGallery()));
        self::assertSame([31, 30], array_map(static fn($file): int => $file->id(), $first->getAttachments()));
        self::assertSame('/en/first', $reader->record(1, $en)?->getPublicUrl());
        self::assertNull($reader->record(1, $de));
        foreach ([3, 4, 5, 6, 7, 8, 9] as $hiddenId) {
            self::assertNull($reader->record($hiddenId, $cs));
        }
        self::assertTrue($reader->isPublicInAnyLocale(1));
        self::assertFalse($reader->isPublicInAnyLocale(5));

        $collection = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($reader, $cs): array {
            $items = $reader->collection($cs, perPage: 1);
            self::assertSame(4, $measurements->queryCount());

            return [$items, $reader->collection($cs, page: 2, perPage: 1)];
        });
        self::assertSame(2, $collection[0]->getTotal());
        self::assertSame([1], array_map(static fn($item): int => $item->getId(), $collection[0]->getItems()));
        self::assertSame([2], array_map(static fn($item): int => $item->getId(), $collection[1]->getItems()));
        self::assertSame([1], array_map(static fn($item): int => $item->getId(), $reader->collection($cs, tag: 'Community')->getItems()));
        self::assertSame([2], array_map(static fn($item): int => $item->getId(), $reader->collection($cs, recommended: false)->getItems()));
        $filtered = $reader->collection(
            $cs,
            search: 'Lead',
            publishedAt: ['from' => '2020-02-01', 'to' => '2020-02-01'],
            sortBy: 'title',
            sortDirection: 'asc',
        );
        self::assertSame(1, $filtered->getTotal());
        self::assertSame([1], array_map(static fn($item): int => $item->getId(), $filtered->getItems()));
        self::assertSame([2, 1], array_map(static fn($item): int => $item->getId(), $reader->collection($cs, sortDirection: 'asc')->getItems()));
        self::assertSame([1, 2], array_map(static fn($item): int => $item->getId(), $reader->latest($cs, 2)));
        self::assertSame([2], array_map(static fn($item): int => $item->getId(), $reader->others(1, $cs, limit: 1)));
        self::assertSame([], $reader->others(1, $cs, limit: 1, tag: 'Community'));
        self::assertSame([1], array_map(static fn($item): int => $item->getId(), $reader->recommended($cs)));

        $entries = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($reader, $cs): array {
            $result = iterator_to_array($reader->sitemapEntries($cs));
            self::assertSame(1, $measurements->queryCount());

            return $result;
        });
        self::assertSame(['/first', '/second'], array_map(static fn($entry): string => $entry->getPublicUrl(), $entries));
        self::assertSame('2025-01-04', $entries[0]->getUpdatedAt()->format('Y-m-d'));
        $database->statement("UPDATE cms_news_article SET updated_at = '2030-01-01 00:00:00' WHERE id = 1");
        $database->statement("UPDATE cms_news_article_translation SET updated_at = '2031-01-01 00:00:00' WHERE article_id = 1 AND locale = 'cs'");
        $database->statement("UPDATE cms_route SET updated_at = '2032-01-01 00:00:00' WHERE entity_id = 1 AND locale = 'cs'");
        self::assertSame('2032-01-01', iterator_to_array($reader->sitemapEntries($cs))[0]->getUpdatedAt()->format('Y-m-d'));
        $database->statement("UPDATE cms_route SET deleted_at = '2032-01-02 00:00:00' WHERE entity_id = 1 AND locale = 'cs'");
        self::assertNull($reader->record(1, $cs));
        self::assertSame(['/second'], array_map(static fn($entry): string => $entry->getPublicUrl(), iterator_to_array($reader->sitemapEntries($cs))));
        self::assertTrue($reader->isPublicInAnyLocale(1));
        $database->statement("UPDATE cms_route SET deleted_at = '2032-01-02 00:00:00' WHERE entity_id = 1 AND locale = 'en'");
        self::assertFalse($reader->isPublicInAnyLocale(1));

        $disabledModules = self::createStub(PublicModuleStateResolverInterface::class);
        $disabledModules->method('isDiscoveredInstalledAndEnabled')->willReturn(false);
        $disabled = new PublishedNewsReadModel($database, new FileReadModel($driver), new PublicCmsUrlBuilder(), $disabledModules);
        self::assertNull($disabled->record(2, $cs));
        self::assertSame([], $disabled->collection($cs)->getItems());
        self::assertSame([], iterator_to_array($disabled->sitemapEntries($cs)));
        self::assertFalse($disabled->isPublicInAnyLocale(2));

        $this->expectException(InvalidArgumentException::class);
        $reader->collection($cs, perPage: 0);
    }
}
