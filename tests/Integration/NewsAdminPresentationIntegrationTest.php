<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Integration;

use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\News\NewsData;
use Lemonade\Cms\News\Tests\Integration\Support\NewsIntegrationTestCase;

/**
 * Verifies News editor page projection and its file collection state
 */
final class NewsAdminPresentationIntegrationTest extends NewsIntegrationTestCase
{
    public function testEditorPageUsesRequestedLocaleAndExposesConfiguredFileCollections(): void
    {
        $database = $this->environment()->getDatabase();
        $database->statement("INSERT INTO system_language VALUES ('de','German',0,0,3)");
        $lifecycle = $this->environment()->getLifecycle();
        $articleId = $lifecycle->createDraft(7, 'cs');
        $lifecycle->save(7, $articleId, CmsContentInput::fromEditor([
            'locale' => 'cs',
            'title' => 'Presentation fixture',
            'tags' => ['Visible tag'],
        ]), NewsData::fromEditor(['author_name' => 'Visible author']));
        $lifecycle->changeUrlSegment(7, $articleId, 'cs', 'presentation-canonical');
        $database->table('cms_news_article_translation')->insert([
            'article_id' => $articleId,
            'locale' => 'de',
            'title' => 'Deutsche Darstellung',
            'publication_state' => 1,
        ]);
        foreach (['thumbnail', 'gallery', 'attachment'] as $position => $usage) {
            $database->table('system_file')->insert([
                'id' => $position + 1,
                'module_code' => 'cms.news',
                'entity_id' => $articleId,
                'usage' => $usage,
                'kind' => $usage === 'attachment' ? 'file' : 'image',
                'original_filename' => $usage . '.jpg',
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 100,
                'source_version' => $usage === 'attachment' ? null : 'v1',
            ]);
        }

        $loaded = $this->environment()->getEditor()->updateData('cms.news', $articleId);
        $czechPage = $this->environment()->getPageProvider()->editor(
            $loaded,
            [],
            [],
            'cs',
            ['locale' => 'cs'],
        );
        self::assertSame('admin::editor', $czechPage->view());
        $czechContext = $czechPage->data()['adminEditorContext'];
        self::assertInstanceOf(AdminEditorRenderContext::class, $czechContext);
        self::assertSame('Presentation fixture', $czechContext->values()['title']);
        self::assertSame('presentation-canonical', $czechContext->values()['url_segment']);
        self::assertSame(['Visible tag'], $czechContext->values()['tags']);
        self::assertSame('Test User', $czechContext->values()['created_by_name']);
        self::assertSame('Visible author', $czechContext->values()['author_name']);

        $germanPage = $this->environment()->getPageProvider()->editor(
            $loaded,
            [],
            [],
            'cs',
            ['locale' => 'de'],
        );
        $germanContext = $germanPage->data()['adminEditorContext'];
        self::assertInstanceOf(AdminEditorRenderContext::class, $germanContext);
        self::assertSame('Deutsche Darstellung', $germanContext->values()['title']);
        self::assertSame('', $germanContext->values()['url_segment']);

        foreach (['thumbnail', 'gallery', 'attachment'] as $usage) {
            self::assertSame(
                $usage . '.jpg',
                $this->environment()->getUploads()->collectionState('cms.news', $articleId, $usage)[0]['original_filename'],
            );
        }
    }
}
