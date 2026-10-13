<?php

declare(strict_types=1);

namespace Lemonade\Cms\News\Tests\Unit\Editor;

use Lemonade\Cms\News\Editor\NewsEditorValidationSchema;
use Lemonade\Framework\Localization\TranslatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje frameworkovy validation contract create a update editoru Aktualit
 */
final class NewsEditorValidationSchemaTest extends TestCase
{
    /**
     * Create nevynucuje zadny formularovy vstup pred zalozenim draftu
     */
    public function testCreateDoesNotRequireFormFields(): void
    {
        $fields = $this->schema()->forCreate()->fields();

        self::assertSame([], $fields);
    }

    /**
     * Update schema pokryva jednu lokalizovanou mutation i globalni metadata
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
            'recommended',
            'show_author',
            'sharing_enabled',
            'show_published_at',
            'show_thumbnail',
            'show_reading_time',
            'show_tags',
            'summary',
            'content',
            'tags',
        ], array_keys($fields));
        self::assertSame('in_list', $fields['publication_state']->rules()[0]->name());
    }

    /**
     * Vytvori schemata se stabilnimi prekladovymi klici pro validacni zpravy
     */
    private function schema(): NewsEditorValidationSchema
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(static fn(string $key): string => $key);

        return new NewsEditorValidationSchema($translator);
    }
}
