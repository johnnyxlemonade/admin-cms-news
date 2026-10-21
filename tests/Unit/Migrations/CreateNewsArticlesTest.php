<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Migrations;

use Lemonade\Cms\News\Migrations\CreateNewsArticles;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Overuje ownership sloupce v puvodnim fresh-install schema News
 */
final class CreateNewsArticlesTest extends TestCase
{
    public function testFreshInstallRootUsesNullableLocalUserForeignKeys(): void
    {
        $statements = [];
        $database = $this->createMock(DatabaseDriverInterface::class);
        $database->method('query')->willReturnCallback(
            static function (string $statement) use (&$statements): bool {
                if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`/', $statement, $matches) === 1) {
                    $statements[$matches[1]] = $statement;
                }

                return true;
            },
        );
        $configuration = DatabaseConfig::fromArray(['driver' => 'mysql']);
        $identifierEscaper = new MysqlIdentifierEscaper('');
        $schema = new Schema(
            new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($identifierEscaper), $configuration)),
            $database,
        );

        (new CreateNewsArticles())->up($schema);

        self::assertSame(
            ['cms_news_article', 'cms_news_article_translation', 'cms_news_article_translation_tag'],
            array_keys($statements),
        );
        $root = $statements['cms_news_article'];
        foreach (['created_by', 'updated_by', 'deleted_by', 'owner_id'] as $column) {
            self::assertStringContainsString(sprintf('`%s` BIGINT UNSIGNED NULL', $column), $root);
        }
        foreach (['created_by', 'updated_by', 'deleted_by', 'owner'] as $suffix) {
            self::assertStringContainsString('CONSTRAINT `fk_cms_news_article_' . $suffix . '`', $root);
        }
        self::assertSame(4, substr_count($root, 'ON UPDATE RESTRICT ON DELETE SET NULL'));
        self::assertStringContainsString('KEY `idx_cms_news_article_owner_deleted` (`owner_id`, `deleted_at`)', $root);
    }
}
