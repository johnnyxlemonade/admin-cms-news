<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Migrations;

use Lemonade\Cms\Content\CmsContentSchema;
use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Creates News article roots and their localized translations
 */
final readonly class CreateNewsArticles implements MigrationInterface
{
    /**
     * Returns the stable News migration identifier
     */
    public static function identifier(): string
    {
        return '20261005130000_create_cms_news_articles';
    }

    /**
     * Creates News tables using the shared CMS content schema
     */
    public function up(Schema $schema): void
    {
        $schema->create('cms_news_article', static function (TableBlueprint $table): void {
            CmsContentSchema::root($table, 'cms_news_article');
        }, ifNotExists: true);
        $schema->create('cms_news_article_translation', static function (TableBlueprint $table): void {
            CmsContentSchema::translation($table, 'cms_news_article', 'article_id');
        }, ifNotExists: true);
        $schema->create('cms_news_article_translation_tag', static function (TableBlueprint $table): void {
            CmsContentSchema::tags($table, 'cms_news_article', 'cms_news_article_translation');
        }, ifNotExists: true);
    }
}
