<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Migrations;

use Lemonade\Cms\Content\CmsContentSchema;
use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Vytvari oddelene tabulky aktualit a jejich lokalizovanych mutaci
 */
final readonly class CreateNewsArticles implements MigrationInterface
{
    /**
     * Vrati stabilni identifikator migrace aktualit
     */
    public static function identifier(): string
    {
        return '20261005130000_create_cms_news_articles';
    }

    /**
     * Vytvori spolecne CMS sloupce a domenove volby aktualit
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
