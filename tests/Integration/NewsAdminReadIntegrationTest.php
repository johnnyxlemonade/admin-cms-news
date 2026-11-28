<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\News\NewsData;
use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;

/**
 * Verifies the shared Admin read model used by News editor consumers
 */
final class NewsAdminReadIntegrationTest extends NewsIntegrationTestCase
{
    public function testMissingAndDeletedArticlesHaveExplicitReadSemantics(): void
    {
        $lifecycle = $this->environment()->getLifecycle();
        $reader = $this->environment()->getReadModel();
        $articleId = $lifecycle->createDraft(7, 'cs');
        $lifecycle->save(
            7,
            $articleId,
            CmsContentInput::fromEditor(['locale' => 'cs', 'title' => 'Article to delete']),
            NewsData::fromEditor([]),
        );
        $lifecycle->delete(7, $articleId);

        self::assertFalse($reader->exists($articleId));
        self::assertFalse($reader->exists(999999));
        self::assertSame(7, $reader->ownerId($articleId));
        self::assertNull($reader->ownerId(999999));
        self::assertNull($reader->findForEditor($articleId));
        self::assertNull($reader->findForEditor(999999));
        self::assertNull($reader->metadata($articleId));
        self::assertNotNull($reader->translation($articleId, 'cs'));
        self::assertNull($reader->translation($articleId, 'fr'));
        self::assertNull($reader->publication($articleId, 'fr'));
    }

    public function testEditorProjectionCombinesRootTranslationRouteTagsAndActorNames(): void
    {
        $database = $this->environment()->getDatabase();
        $database->statement("INSERT INTO system_user VALUES (9, 'editor@example.test', 'New', 'Owner', 1, NULL)");
        $database->statement("INSERT INTO system_language VALUES ('de','German',0,0,3)");
        $lifecycle = $this->environment()->getLifecycle();
        $reader = $this->environment()->getReadModel();
        $articleId = $lifecycle->createDraft(7, 'cs');
        $domain = NewsData::fromEditor(['author_name' => 'Domain Author']);
        $lifecycle->save(9, $articleId, CmsContentInput::fromEditor([
            'locale' => 'cs',
            'title' => 'Read fixture Alpha',
            'recommended' => '1',
            'show_author' => '0',
            'sharing_enabled' => '0',
            'show_published_at' => '0',
            'show_thumbnail' => '0',
            'show_reading_time' => '0',
            'show_tags' => '0',
            'page_title' => 'SEO Alpha',
            'summary' => 'Summary Alpha',
            'content' => '<p>Content Alpha</p>',
            'meta_description' => 'Description Alpha',
            'tags' => ['Zulu', 'Alpha'],
            'publication_state' => '2',
            'published_at' => '2028-01-02T10:30',
        ]), $domain);
        $lifecycle->save(9, $articleId, CmsContentInput::fromEditor([
            'locale' => 'en',
            'title' => 'Read fixture English',
            'tags' => ['English tag'],
        ]), $domain);
        $lifecycle->changeUrlSegment(9, $articleId, 'cs', 'read-fixture-canonical');
        $database->table('cms_news_article_translation')->insert([
            'article_id' => $articleId,
            'locale' => 'de',
            'title' => 'Read fixture German',
            'publication_state' => 1,
        ]);
        $database->table('cms_news_article')->where('id', $articleId)->set([
            'updated_at' => '2028-01-02 11:00:00',
        ])->update();
        $czechTranslation = $reader->translation($articleId, 'cs');
        self::assertNotNull($czechTranslation);
        $database->table('cms_news_article_translation_tag')
            ->where('translation_id', $czechTranslation['id'])
            ->set(['sort_order' => 0])
            ->update();

        $loaded = $this->environment()->getEditor()->updateData('cms.news', $articleId);
        $article = $loaded->data()['article'];
        self::assertIsArray($article);
        self::assertSame($reader->findForEditor($articleId), $article);
        self::assertSame('Domain Author', $article['author_name']);
        self::assertSame('Test User', $article['created_by_name']);
        self::assertSame('New Owner', $article['updated_by_name']);
        self::assertSame('Test User', $article['owner_name']);
        self::assertSame('—', $article['deleted_by_name']);
        self::assertSame('2028-01-02 11:00:00', $article['updated_at']);
        self::assertSame(1, (int) $article['recommended']);
        foreach (['show_author', 'sharing_enabled', 'show_published_at', 'show_thumbnail', 'show_reading_time', 'show_tags'] as $field) {
            self::assertSame(0, (int) $article[$field]);
        }

        $translations = array_column($article['translations'], null, 'locale');
        self::assertSame(['cs', 'de', 'en'], array_keys($translations));
        self::assertSame(['Zulu', 'Alpha'], $translations['cs']['tags']);
        self::assertSame(['English tag'], $translations['en']['tags']);
        self::assertSame([], $translations['de']['tags']);
        self::assertSame('SEO Alpha', $translations['cs']['page_title']);
        self::assertSame('Summary Alpha', $translations['cs']['summary']);
        self::assertSame('<p>Content Alpha</p>', $translations['cs']['content']);
        self::assertSame('Description Alpha', $translations['cs']['meta_description']);
        self::assertSame('read-fixture-canonical', $translations['cs']['canonical_path']);
        self::assertNull($translations['de']['canonical_path']);
        self::assertSame(2, (int) ($reader->publication($articleId, 'cs')['publication_state'] ?? -1));
        self::assertSame(
            '2028-01-02 10:30:00',
            $reader->publication($articleId, 'cs')['published_at'] ?? null,
        );
        foreach ($reader->metadata($articleId) ?? [] as $column => $value) {
            self::assertSame($value, $article[$column]);
        }
    }

    public function testLocaleAndTagCatalogsExposeActiveAndInactiveTranslations(): void
    {
        $database = $this->environment()->getDatabase();
        $database->statement(
            'INSERT INTO system_language VALUES '
            . "('de','German',0,0,3), ('fr','French',1,0,4), ('it','Italian',0,0,5)",
        );
        $lifecycle = $this->environment()->getLifecycle();
        $reader = $this->environment()->getReadModel();
        $articleId = $lifecycle->createDraft(7, 'cs');
        $lifecycle->save(7, $articleId, CmsContentInput::fromEditor([
            'locale' => 'cs',
            'title' => 'Locale fixture',
            'tags' => ['Zulu', 'Alpha'],
        ]), NewsData::fromEditor([]));
        $lifecycle->save(7, $articleId, CmsContentInput::fromEditor([
            'locale' => 'en',
            'title' => 'English locale fixture',
            'tags' => ['English tag'],
        ]), NewsData::fromEditor([]));
        $database->table('cms_news_article_translation')->insert([
            'article_id' => $articleId,
            'locale' => 'de',
            'title' => 'German locale fixture',
            'publication_state' => 1,
        ]);

        $loaded = $this->environment()->getEditor()->updateData('cms.news', $articleId);
        $locales = $loaded->data()['locales'];

        self::assertSame(['cs', 'en', 'de', 'fr'], array_column($locales, 'code'));
        self::assertSame([1, 1, 0, 1], array_map('intval', array_column($locales, 'enabled')));
        self::assertSame([1, 1, 1, 0], array_map('intval', array_column($locales, 'has_translation')));
        self::assertSame(['cs', 'en', 'de', 'fr'], array_column($reader->dataGridLocales(), 'code'));
        self::assertSame(['cs', 'en', 'fr'], array_column($reader->enabledLocales(), 'code'));
        self::assertSame(['Alpha', 'Zulu'], $reader->tagNamesForLocale('cs'));
        self::assertSame(['English tag'], $reader->tagNamesForLocale('en'));
    }
}
