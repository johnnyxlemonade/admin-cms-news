<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Editor;

use Lemonade\Cms\News\Editor\NewsEditor;
use PHPUnit\Framework\TestCase;

/**
 * Drzi publish vstupy za samostatnou transportni permission hranici
 */
final class NewsEditorAuthorizationTest extends TestCase
{
    public function testEditingPermissionDoesNotAuthorizePublicationFields(): void
    {
        /** @var NewsEditor $editor */
        $editor = (new \ReflectionClass(NewsEditor::class))->newInstanceWithoutConstructor();
        $fields = [];
        foreach ($editor->editorDefinition()->fields() as $field) {
            $fields[$field->name()] = $field->permission();
        }

        self::assertSame('cms.news.publish', $fields['publication_state']);
        self::assertSame('cms.news.publish', $fields['published_at']);
        self::assertNull($fields['title']);
        self::assertArrayNotHasKey('url_segment', $fields);
    }
}
