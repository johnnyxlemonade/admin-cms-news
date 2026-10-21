<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Migrations;

use Lemonade\Framework\Database\Migration\MigrationInterface;
use Lemonade\Framework\Database\Schema\Blueprint\TableBlueprint;
use Lemonade\Framework\Database\Schema\Schema;

/**
 * Vytvari stabilni clanky a jejich lokalizovane obsahove mutace
 */
final readonly class CreateNewsArticles implements MigrationInterface
{
    /**
     * Vrati stabilni identifikator News schema migrace
     */
    public static function identifier(): string
    {
        return '20261005130000_create_cms_news_articles';
    }

    /**
     * Vytvori root clanku a lokalizovane rows s databazovymi invarianty
     */
    public function up(Schema $schema): void
    {
        $schema->create('cms_news_article', static function (TableBlueprint $table): void {
            $table->id()->comment('Stabilni identifikator clanku pro lifecycle a budouci file usage');
            $table->unsignedBigInteger('created_by')->nullable()->comment('Puvodni lokalni tvurce clanku');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('Posledni lokalni uzivatel, ktery aggregate skutecne zmenil');
            $table->unsignedBigInteger('deleted_by')->nullable()->comment('Lokalni uzivatel, ktery provedl aktualni soft delete');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('Aktualni vlastnik celeho article aggregate');
            $table->string('author_name', 255)->nullable()->comment('Volitelne zobrazovane jmeno autora clanku');
            $table->boolean('recommended')->default(0)->comment('Urcuje doporuceni clanku v budoucim verejnem vystupu');
            $table->boolean('show_author')->default(1)->comment('Urcuje zobrazeni autora ve verejnem vystupu');
            $table->boolean('sharing_enabled')->default(1)->comment('Urcuje zobrazeni sdileni ve verejnem vystupu');
            $table->boolean('show_published_at')->default(1)->comment('Urcuje zobrazeni data publikace ve verejnem vystupu');
            $table->boolean('show_thumbnail')->default(1)->comment('Urcuje zobrazeni hlavniho obrazku ve verejnem vystupu');
            $table->boolean('show_reading_time')->default(1)->comment('Urcuje zobrazeni orientacni doby cteni ve verejnem vystupu');
            $table->boolean('show_tags')->default(1)->comment('Urcuje zobrazeni lokalizovanych stitku ve verejnem vystupu');
            $table->datetime('deleted_at')->nullable()->comment('Okamzik soft delete celeho article aggregate');
            $table->timestamps();
            $table->index(['deleted_at'], 'idx_cms_news_article_deleted');
            $table->index(['owner_id', 'deleted_at'], 'idx_cms_news_article_owner_deleted');
            $table->foreign('created_by', 'fk_cms_news_article_created_by')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('updated_by', 'fk_cms_news_article_updated_by')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('deleted_by', 'fk_cms_news_article_deleted_by')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->foreign('owner_id', 'fk_cms_news_article_owner')->references('id')->on('system_user')->nullOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        }, ifNotExists: true);

        $schema->create('cms_news_article_translation', static function (TableBlueprint $table): void {
            $table->id()->comment('Identifikator lokalizovane mutace clanku');
            $table->unsignedBigInteger('article_id')->comment('Stabilni vlastnik lokalizovaneho obsahu');
            $table->string('locale', 10)->comment('Canonical kod content locale');
            $table->string('title', 180)->nullable()->comment('Lokalizovany nadpis clanku; null u noveho draftu pred prvnim ulozenim');
            $table->string('page_title', 180)->nullable()->comment('Lokalizovany SEO titul stranky');
            $table->text('summary')->nullable()->comment('Lokalizovany perex clanku');
            $table->longText('content')->nullable()->comment('Lokalizovany obsah clanku');
            $table->string('meta_description', 255)->nullable()->comment('Lokalizovany SEO popis stranky');
            $table->tinyInteger('publication_state')->unsigned()->default(0)->comment('New, draft nebo published lifecycle lokalizovane mutace');
            $table->datetime('published_at')->nullable()->comment('Okamzik verejne viditelnosti publikovane lokalizovane mutace');
            $table->timestamps();
            $table->unique(['article_id', 'locale'], 'uq_cms_news_article_translation_locale');
            $table->index(['locale', 'title'], 'idx_cms_news_translation_title');
            $table->index(['publication_state', 'published_at'], 'idx_cms_news_translation_visibility');
            $table->foreign('article_id', 'fk_cms_news_translation_article')->references('id')->on('cms_news_article')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign('locale', 'fk_cms_news_translation_locale')->references('code')->on('system_language')->restrictOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        }, ifNotExists: true);

        $schema->create('cms_news_article_translation_tag', static function (TableBlueprint $table): void {
            $table->id()->comment('Identifikator stitku lokalizovane mutace');
            $table->unsignedBigInteger('translation_id')->comment('Lokalizovana mutace vlastnici stitek');
            $table->string('name', 255)->comment('Lokalizovany nazev stitku');
            $table->unsignedInteger('sort_order')->default(0)->comment('Poradi stitku v editoru');
            $table->timestamps();
            $table->unique(['translation_id', 'name'], 'uq_cms_news_translation_tag_name');
            $table->index(['translation_id', 'sort_order'], 'idx_cms_news_translation_tag_sort');
            $table->foreign('translation_id', 'fk_cms_news_translation_tag_translation')->references('id')->on('cms_news_article_translation')->cascadeOnDelete()->restrictOnUpdate();
            $table->engine('InnoDB');
            $table->charset('utf8mb4');
        }, ifNotExists: true);

    }
}
