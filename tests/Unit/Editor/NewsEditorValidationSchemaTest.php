<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Editor;

use Lemonade\Cms\News\Editor\NewsEditorValidationSchema;
use Lemonade\Framework\Localization\TranslatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies create and update validation schemas for the News editor
 */
final class NewsEditorValidationSchemaTest extends TestCase
{
    /**
     * Verifies that draft creation requires no form fields
     */
    public function testCreateDoesNotRequireFormFields(): void
    {
        $fields = $this->schema()->forCreate()->fields();

        self::assertSame([], $fields);
    }

    /**
     * Verifies the flat localized update payload and root metadata
     */
    public function testUpdateDefinesTheFlatEditorPayload(): void
    {
        $fields = $this->schema()->forUpdate()->fields();

        self::assertSame([
            'locale',
            'title',
            'author_name',
            'page_title',
            'meta_description',
            'publication_state',
            'published_at',
            'summary',
            'content',
            'tags',
            'recommended',
            'show_author',
            'sharing_enabled',
            'show_published_at',
            'show_thumbnail',
            'show_reading_time',
            'show_tags',
        ], array_keys($fields));
        self::assertSame('in_list', $fields['publication_state']->rules()[0]->name());
        self::assertSame('1,2', $fields['publication_state']->rules()[0]->param());
    }

    /**
     * Creates schemas with stable validation translation keys
     */
    private function schema(): NewsEditorValidationSchema
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(static fn(string $key): string => $key);

        return new NewsEditorValidationSchema($translator);
    }
}
